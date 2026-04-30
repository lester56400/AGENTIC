<?php
/**
 * SIL_Semantic_Silos
 *
 * Constructs semantic silos using Fuzzy C-Means clustering on OpenAI embeddings.
 * Replaces Infomap as silo definer; Infomap remains useful for measuring link quality.
 *
 * Key concepts:
 * - Hard assignment (k-means): each article belongs to exactly one cluster
 * - Soft/fuzzy assignment (Fuzzy C-Means): each article has a membership score per silo
 * - "Bridge" article: score in secondary silo > SIL_BRIDGE_THRESHOLD (0.30)
 * - m (fuzziness exponent): m=2 is standard; higher = softer boundaries
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SIL_Semantic_Silos {

    const DEFAULT_K          = 6;
    const DEFAULT_M          = 1.2;   // Fuzziness exponent (Sharper clusters)
    const MAX_ITER           = 300;
    const CONVERGENCE_EPS    = 1e-5;
    const BRIDGE_THRESHOLD   = 0.20;  // Secondary silo score to flag as bridge

    private $wpdb;
    private $table_embeddings;
    private $table_membership;
    private $table_centroids;

    public function __construct() {
        global $wpdb;
        $this->wpdb             = $wpdb;
        $this->table_embeddings = $wpdb->prefix . 'sil_embeddings';
        $this->table_membership = $wpdb->prefix . 'sil_silo_membership';
        $this->table_centroids  = $wpdb->prefix . 'sil_centroids';
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * [PIPELINE] Étape 1 : Initialisation du calcul
     */
    public function rebuild_silos_step_init( int $k = self::DEFAULT_K ) {
        try {
            mt_srand( 42 ); // Reproductibilité
            $embeddings = $this->load_embeddings();
            $n = count( $embeddings );

            // [PHASE3] Plancher de Granularité Adaptatif (HCU compliance)
            // Cible : 12 articles par silo. Minimum 3 silos.
            $k_min = (int) max( 3, ceil( $n / 12 ) );
            
            if ( $k < $k_min ) {
                error_log( "[SIL] Granularity Floor: k=$k below k_min=$k_min. Forcing k=$k_min." );
                $k = $k_min;
            }

            if ( $n < $k ) {
                $k = max( 1, $n );
            }

            // [PHASE2] Calcul du gamma adaptatif pour le noyau RBF Gaussien
            $gamma = SIL_Math_Evaluator::calculate_niche_gamma( $embeddings );
            error_log( "[SIL] Kernel gamma = $gamma (n=$n, k=$k)" );

            $post_ids = array_keys( $embeddings );
            
            // Nettoyage préventif
            if ( ! empty( $post_ids ) ) {
                $this->wpdb->query( "DELETE FROM {$this->table_membership} WHERE post_id NOT IN (" . implode( ',', $post_ids ) . ")" );
            }

            // Récupération de l'ambition pour adapter la rigidité (Fuzziness m)
            $ambition = get_option('sil_silo_ambition', 'balanced');
            $m = self::DEFAULT_M;
            if ( $ambition === 'granular' ) {
                $m = 1.05; // Très rigide, force la création de micro-silos étanches (empêche le collapse)
            } elseif ( $ambition === 'conservative' ) {
                $m = 1.25; // Très souple, favorise la fusion en méga-piliers
            } else {
                $m = 1.15; // Équilibré
            }

            // Initialisation via K-Means++ (Dispersion maximale)
            $U = $this->init_membership_kmeans_plus_plus( $embeddings, $k, $m, $gamma );

            // Pré-chargement GSC (gardé pour les signatures finales, mais retiré du cycle itératif)
            $gsc_data = [];
            foreach ( $post_ids as $pid ) {
                $raw = get_post_meta( $pid, '_sil_gsc_data', true );
                $data = is_array( $raw ) ? $raw : json_decode( $raw, true );
                $queries = [];
                if ( isset( $data['top_queries'] ) && is_array( $data['top_queries'] ) ) {
                    foreach ( array_slice( $data['top_queries'], 0, 5 ) as $q ) {
                        $queries[] = strtolower( $q['query'] ?? '' );
                    }
                }
                $gsc_data[ $pid ] = array_filter( $queries );
                clean_post_cache( $pid ); // Libération immédiate pour la RAM
            }

            $state = [
                'k'         => $k,
                'm'         => $m,
                'n'         => $n,
                'gamma'     => $gamma,
                'post_ids'  => $post_ids,
                'U'         => $U,
                'gsc_data'  => $gsc_data,
                'iter'      => 0,
                'converged' => false
            ];

            set_transient( 'sil_rebuild_state', $state, HOUR_IN_SECONDS );
            return [ 'status' => 'initialized', 'n' => $n ];
        } catch ( Throwable $e ) {
            return new WP_Error( 'sil_init_failed', $e->getMessage() . " (File: " . basename($e->getFile()) . " L" . $e->getLine() . ")" );
        }
    }

    /**
     * [PIPELINE] Étape 2 : Exécution d'un lot d'itérations (ex: 20)
     */
    public function rebuild_silos_step_iterate( int $batch_size = 20 ) {
        try {
            $state = get_transient( 'sil_rebuild_state' );
            if ( ! $state ) return new WP_Error( 'sil_state_lost', 'État perdu.' );

            $embeddings = $this->load_embeddings();
            $k          = $state['k'];
            $m          = $state['m'];
            $n          = $state['n'];
            $gamma      = $state['gamma'] ?? 1.0;
            $post_ids   = $state['post_ids'];
            $U          = $state['U'];
            $gsc_data   = $state['gsc_data'];
            $iter_done  = 0;

            @ini_set( 'memory_limit', '512M' );

            for ( $i = 0; $i < $batch_size; $i++ ) {
                $state['iter']++;
                if ( $state['iter'] >= self::MAX_ITER ) {
                    $state['converged'] = true;
                    break;
                }

                // ÉQUILIBRAGE (V2.8.4) : Calcul des poids pour favoriser les petits silos
                $weights = [];
                $target_size = $n / $k;
                $silo_sizes = array_fill(0, $k, 0);
                foreach ($U as $scores) {
                    foreach ($scores as $c => $score) {
                        $silo_sizes[$c] += $score;
                    }
                }
                for ($c = 0; $c < $k; $c++) {
                    // Boost agressif (0.75x distance) si le silo est rachitique (< 5 articles)
                    // Boost modéré (0.90x distance) si le silo est sous-peuplé (< 50% de la cible)
                    if ($silo_sizes[$c] < 5) {
                        $weights[$c] = 0.75;
                    } elseif ($silo_sizes[$c] < $target_size * 0.5) {
                        $weights[$c] = 0.90;
                    } else {
                        $weights[$c] = 1.0;
                    }
                }

                $centroids = $this->compute_centroids( $embeddings, $post_ids, $U, $k, $m, $gamma );

                // RÉ-ANIMATION (V2.8.7) : Téléportation des silos morts vers les zones denses
                // On le fait toutes les 40 itérations pour laisser le temps au système de réagir
                if ( $state['iter'] % 40 === 0 ) {
                    $this->teleport_stuck_centroids( $centroids, $silo_sizes, $k );
                }

                $U_new = $this->compute_membership_matrix( $embeddings, $centroids, $k, $m, $gamma, $weights );

                unset( $cluster_signatures );

                $delta = $this->calculate_delta( $U, $U_new );
                $state['last_delta'] = $delta;

                if ( $delta < 0.001 ) {
                    $U = $U_new;
                    $state['converged'] = true;
                    break;
                }

                $U = $U_new;
                $iter_done++;
            }

            $state['U'] = $U;
            set_transient( 'sil_rebuild_state', $state, HOUR_IN_SECONDS );

            return [
                'status'    => $state['converged'] ? 'converged' : 'iterating',
                'iteration' => $state['iter'],
                'max_iter'  => self::MAX_ITER
            ];
        } catch ( Throwable $e ) {
            return new WP_Error( 'sil_iterate_failed', $e->getMessage() . " (File: " . basename($e->getFile()) . " L" . $e->getLine() . ")" );
        }
    }

    /**
     * [PIPELINE] Étape 3 : Finalisation et sauvegarde
     */
    public function rebuild_silos_step_finalize() {
        try {
            $state = get_transient( 'sil_rebuild_state' );
            if ( ! $state ) return new WP_Error( 'sil_state_lost', 'État perdu.' );

            $post_ids = $state['post_ids'];
            $U        = $state['U'];
            $k        = $state['k'];
            $m        = isset( $state['m'] ) ? $state['m'] : self::DEFAULT_M;

            $membership = [];
            foreach ( $post_ids as $pid ) {
                $scores = [];
                if ( isset( $U[ $pid ] ) ) {
                    for ( $c = 0; $c < $k; $c++ ) {
                        $scores[ $c ] = round( $U[ $pid ][ $c ], 4 );
                    }
                } else {
                    $scores = array_fill( 0, $k, 0.0 );
                }
                $membership[ $pid ] = $scores;
            }

            // --- AUTO-HEAL: Suppression des centroids vides (phagocytés par l'IA) ---
            $active_centroids = [];
            foreach ( $membership as $pid => $scores ) {
                $max_c = 0; $max_val = -1;
                foreach ( $scores as $c => $val ) {
                    if ( $val > $max_val ) { $max_val = $val; $max_c = $c; }
                }
                $active_centroids[ $max_c ] = true;
            }
            
            if ( count( $active_centroids ) > 0 && count( $active_centroids ) < $k ) {
                ksort( $active_centroids );
                $active_keys = array_keys( $active_centroids );
                $new_k = count( $active_keys );
                $new_membership = [];
                $new_U = [];
                foreach ( $post_ids as $pid ) {
                    if ( ! isset( $membership[ $pid ] ) ) continue;
                    $new_scores = [];
                    foreach ( $active_keys as $old_c ) {
                        $new_scores[] = $membership[ $pid ][ $old_c ];
                    }
                    $sum = array_sum( $new_scores );
                    if ( $sum > 0 ) {
                        foreach ( $new_scores as &$score ) $score = round( $score / $sum, 4 );
                    }
                    $new_membership[ $pid ] = $new_scores;
                    $new_U[ $pid ] = $new_scores;
                }
                $membership = $new_membership;
                $U = $new_U;
                $k = $new_k;
                error_log( "[SIL] Auto-heal: Matrix shrunk from " . count( $active_centroids ) . " active centroids. New K = $k" );
            }
            // ----------------------------------------------------------------------

            $embeddings = $this->load_embeddings();
            $gamma = $state['gamma'] ?? SIL_Math_Evaluator::calculate_niche_gamma( $embeddings );
            $final_centroids = $this->compute_centroids( $embeddings, $post_ids, $U, $k, $m, $gamma );
            $this->save_centroids( $final_centroids );

            // Identification des Medoïdes (Pivots les plus centraux)
            $medoids = $this->elect_medoids( $embeddings, $final_centroids );
            
            // Si aucune ancre n'est définie, on suggère/verrouille les medoïdes actuels
            $existing_anchors = get_option( 'sil_silo_anchors', [] );
            if ( empty( $existing_anchors ) || count( $existing_anchors ) !== $k ) {
                update_option( 'sil_silo_anchors', $medoids );
            }

            $stats = $this->persist_membership( $membership, $k );

            // SAUVEGARDE DES STATS D'ITERATION (V2.9.0)
            update_option( 'sil_last_iteration_stats', [
                'iter'  => $state['iter'] ?? 'N/A',
                'delta' => $state['last_delta'] ?? 'N/A',
                'm'     => $m,
                'time'  => current_time( 'mysql' )
            ] );

            delete_transient( 'sil_rebuild_state' );

            return $stats;
        } catch ( Throwable $e ) {
            return new WP_Error( 'sil_finalize_failed', $e->getMessage() . " (File: " . basename($e->getFile()) . " L" . $e->getLine() . ")" );
        }
    }

    /**
     * Full rebuild (LEGACY compat)
     */
    public function rebuild_silos( int $k = self::DEFAULT_K ) {
        $this->rebuild_silos_step_init( $k );
        while ( true ) {
            $res = $this->rebuild_silos_step_iterate( 50 );
            if ( is_wp_error( $res ) || $res['status'] === 'converged' ) break;
        }
        return $this->rebuild_silos_step_finalize();
    }

    /**
     * Get the primary silo ID for a post.
     *
     * @param int $post_id
     * @return int|null Silo ID (1-based) or null if not clustered.
     */
    public function get_primary_silo( int $post_id ): ?int {
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT silo_id FROM {$this->table_membership} WHERE post_id = %d AND is_primary = 1 LIMIT 1",
            $post_id
        ) );
        return $row ? (int) $row->silo_id : null;
    }

    /**
     * Get all memberships for a post (for bridge detection).
     *
     * @param int $post_id
     * @return array [ silo_id => score, ... ] ordered by score desc.
     */
    public function get_memberships( int $post_id ): array {
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT silo_id, score FROM {$this->table_membership} WHERE post_id = %d ORDER BY score DESC",
            $post_id
        ) );
        $out = [];
        foreach ( $rows as $row ) {
            $out[ (int) $row->silo_id ] = (float) $row->score;
        }
        return $out;
    }

    /**
     * Get all post IDs that belong to a given silo (primary or bridge).
     *
     * @param int   $silo_id
     * @param bool  $primary_only Only return primary members.
     * @return int[]
     */
    public function get_silo_members( int $silo_id, bool $primary_only = false ): array {
        $where = $primary_only ? 'AND is_primary = 1' : '';
        return array_map( 'intval', $this->wpdb->get_col( $this->wpdb->prepare(
            "SELECT post_id FROM {$this->table_membership} WHERE silo_id = %d {$where} ORDER BY score DESC",
            $silo_id
        ) ) );
    }

    /**
     * Returns human-readable labels per silo based on most common category.
     * [V20.1] Identifie le Pivot mathématique comme étant l'article le plus proche du barycentre.
     */
    public function get_silo_labels(): array {
        $labels = [];
        $centroids = $this->get_silo_centroids();
        $k = count($centroids);
        if ($k === 0) return [];
        
        $embeddings = $this->load_embeddings();

        foreach ($centroids as $sid => $centroid_vec) {
            // Identifier le pivot : d'abord chercher un Cornerstone manuel
            $best_sim = -1.0;
            $pivot_id = null;
            
            $member_ids = $this->get_silo_members($sid, true);
            
            // 1. Chercher un Cornerstone
            foreach ($member_ids as $pid) {
                if (get_post_meta($pid, '_sil_is_cornerstone', true) === '1') {
                    $pivot_id = $pid;
                    break;
                }
            }
            
            // 2. Fallback : l'article dont l'embedding est le plus proche du centroïde
            if (!$pivot_id) {
                $best_sim = -1.0;
                foreach ($member_ids as $pid) {
                    if (!isset($embeddings[$pid])) continue;
                    $sim = $this->cosine_similarity($embeddings[$pid], $centroid_vec);
                    if ($sim > $best_sim) {
                        $best_sim = $sim;
                        $pivot_id = $pid;
                    }
                }
            }
            
            if (!$pivot_id) {
                $labels[$sid] = "Silo $sid";
                continue;
            }

            $gsc_raw = get_post_meta($pivot_id, '_sil_gsc_data', true);
            $gsc = is_array($gsc_raw) ? $gsc_raw : json_decode($gsc_raw, true);
            
            $rows = $gsc['top_queries'] ?? ($gsc ?: []);
            $keywords = [];

            if (is_array($rows)) {
                foreach (array_slice($rows, 0, 3) as $r) {
                    $raw_kw = $r['query'] ?? ($r['keys'][0] ?? '');
                    if ($raw_kw) {
                        $kw = preg_replace_callback('/(?:\\\\+)?u([0-9a-fA-F]{4})/', function ($match) {
                            return mb_convert_encoding(pack('H*', $match[1]), 'UTF-8', 'UCS-2BE');
                        }, $raw_kw);
                        $keywords[] = wp_specialchars_decode($kw, ENT_QUOTES);
                    }
                }
            }

            if (!empty($keywords)) {
                $labels[$sid] = sprintf("[%d] %s", $sid, implode(', ', $keywords));
            } else {
                $title = get_the_title($pivot_id);
                $labels[$sid] = sprintf("[%d] %s", $sid, wp_trim_words($title, 4));
            }
        }
        return $labels;
    }

    /**
     * Sauvegarde les centroïdes pour le Warm Start.
     * TRUNCATE d'abord pour supprimer les fantômes de silos purgés par l'Auto-Heal.
     */
    private function save_centroids( array $centroids ) {
        $this->wpdb->query( "TRUNCATE TABLE {$this->table_centroids}" );
        foreach ( $centroids as $idx => $vec ) {
            $this->wpdb->replace(
                $this->table_centroids,
                [
                    'silo_id' => $idx + 1,
                    'vector'  => json_encode( $vec ),
                ],
                [ '%d', '%s' ]
            );
        }
    }

    /**
     * Charge les centroïdes pour le Warm Start.
     */
    private function load_centroids(): array {
        $rows = $this->wpdb->get_results( "SELECT silo_id, vector FROM {$this->table_centroids} ORDER BY silo_id ASC", ARRAY_A );
        $out = [];
        foreach ( $rows as $row ) {
            $vec = json_decode( $row['vector'], true );
            if ( is_array( $vec ) ) {
                $out[ (int) $row['silo_id'] ] = $vec;
            }
        }
        return $out;
    }

    /**
     * Recomputes and returns centroids for all existing silos based on current membership.
     * Useful for detecting intruders or calculating inter-silo distances.
     *
     * @return array [ silo_id => float[] ]
     */
    public function get_silo_centroids(): array {
        $embeddings = $this->load_embeddings();
        if (empty($embeddings)) return [];
        
        $k = (int) $this->wpdb->get_var("SELECT COUNT(DISTINCT silo_id) FROM {$this->table_membership}");
        if ($k === 0) return [];

        // 1. Reconstruct U matrix from database
        $post_ids = [];
        $U = [];
        $pid_to_idx = [];
        
        $rows = $this->wpdb->get_results("SELECT post_id, silo_id, score FROM {$this->table_membership}", ARRAY_A);
        foreach ($rows as $row) {
            $pid = (int)$row['post_id'];
            if (!isset($pid_to_idx[$pid])) {
                $pid_to_idx[$pid] = count($post_ids);
                $post_ids[] = $pid;
            }
            $U[$pid_to_idx[$pid]][(int)$row['silo_id'] - 1] = (float)$row['score'];
        }
        
        // 2. Call internal compute_centroids
        $gamma = SIL_Math_Evaluator::calculate_niche_gamma( $embeddings );
        $raw_centroids = $this->compute_centroids($embeddings, $post_ids, $U, $k, self::DEFAULT_M, $gamma);
        
        // 3. Convert to 1-based associative array
        $centroids = [];
        foreach ($raw_centroids as $idx => $vec) {
            $centroids[$idx + 1] = $vec;
        }
        
        return $centroids;
    }

    /**
     * Compute a distance matrix between all silos based on their centroids.
     */
    public function get_silo_distance_matrix(): array {
        $centroids = $this->get_silo_centroids();
        $k = count($centroids);
        if ($k < 2) return [];

        // Compute Distance Matrix (Cosine Distance)
        $matrix = [];
        foreach ($centroids as $i => $vec_i) {
            foreach ($centroids as $j => $vec_j) {
                if ($i === $j) {
                    $matrix[$i][$j] = 0;
                } else {
                    $sim = $this->cosine_similarity($vec_i, $vec_j);
                    $matrix[$i][$j] = round(1 - $sim, 4);
                }
            }
        }
        return $matrix;
    }

    /**
     * Check if the membership table has been populated.
     */
    public function is_populated(): bool {
        $count = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_membership}" );
        return $count > 0;
    }

    /**
     * Calcule le nombre optimal de silos (k) recommandé via la Méthode du Coude (Elbow Method).
     * 
     * @param string $mode 'conservative' (0.8x), 'balanced' (1.0x), 'granular' (1.3x)
     */
    public function calculate_recommended_k( string $mode = 'balanced' ): int {
        $embeddings = $this->load_embeddings();
        return $this->calculate_optimal_k($embeddings, $mode);
    }

    /**
     * Calcule K via l'Inertie intra-cluster (Elbow Method).
     * V2.9.0 : Multi-run averaging (3 seeds) pour stabiliser l'inertie sur les niches denses.
     */
    // [PHASE3:MODIFIED] Respecte désormais le plancher k_min (12 articles/silo).
    public function calculate_optimal_k( array $embeddings, string $mode = 'balanced' ): int {
        $n = count($embeddings);
        if ($n < 3) return max(1, $n);

        // [PHASE1:STUB] Retourne DEFAULT_K en attendant le noyau mathématique Phase 2.
        // [PHASE3:UPDATE] Respecte le plancher k_min.
        $k_min = (int) max( 3, ceil( $n / 12 ) );
        $k_target = max( $k_min, self::DEFAULT_K );

        error_log("[SIL] calculate_optimal_k(): PHASE3 STUB — returning max(k_min=$k_min, DEFAULT_K=" . self::DEFAULT_K . ") = $k_target");
        return $k_target;
    }

    /**
     * Analyse la santé de l'architecture actuelle.
     * Détecte les silos "obèses" et suggère des actions.
     */
    public function get_architecture_health(): array {
        $stats = $this->wpdb->get_results(
            "SELECT silo_id, COUNT(*) as total FROM {$this->table_membership} WHERE is_primary = 1 GROUP BY silo_id",
            ARRAY_A
        );

        $alerts = [];
        $k_current = count($stats);
        $total_articles = 0;
        foreach ($stats as $s) {
            $total_articles += (int)$s['total'];
        }

        // Seuil dynamique de saturation : Moyenne * 2 (Au lieu de la limite statique de 15)
        $avg_articles = $k_current > 0 ? ($total_articles / $k_current) : 0;
        $saturation_threshold = max( 15, round($avg_articles * 2) ); // Au moins 15 pour déclencher l'alarme

        foreach ($stats as $s) {
            if ((int)$s['total'] > $saturation_threshold) {
                $alerts[] = [
                    'type'    => 'saturation',
                    'silo_id' => $s['silo_id'],
                    'count'   => $s['total'],
                    'message' => sprintf("Le Silo %d sature (%d articles, limite dynamique %d). Risque de dilution sémantique.", $s['silo_id'], $s['total'], $saturation_threshold)
                ];
            }
        }

        $k_rec = $this->calculate_recommended_k( get_option('sil_silo_ambition', 'balanced') );

        return [
            'k_current'      => $k_current,
            'k_recommended'  => $k_rec,
            'total_articles' => $total_articles,
            'alerts'         => $alerts,
            'status'         => empty($alerts) ? 'healthy' : 'warning'
        ];
    }

    // =========================================================================
    // FUZZY C-MEANS
    // =========================================================================

    /**
     * Fuzzy C-Means on a set of embeddings using cosine distance.
     *
     * @param array $embeddings [ post_id => float[] ]
     * @param int   $k          Number of clusters.
     * @param float $m          Fuzziness exponent (>1).
     * @return array            Membership matrix [ post_id => [ silo_id (1-based) => score ] ]
     */
    public function fuzzy_cmeans( array $embeddings, int $k, float $m = self::DEFAULT_M ): array {
        $post_ids = array_keys( $embeddings );
        $n        = count( $post_ids );

        // Tenter d'augmenter la limite mémoire pour les gros calculs
        @ini_set( 'memory_limit', '512M' );

        // [PHASE2] Calcul du gamma RBF
        $gamma = SIL_Math_Evaluator::calculate_niche_gamma( $embeddings );

        // 1. Initialize membership matrix via K-Means++ for maximum dispersion
        $U = $this->init_membership_kmeans_plus_plus( $embeddings, $k, $m, $gamma );

        // --- OPTIMISATION MÉMOIRE : Pré-charger les données GSC une seule fois pour tout le calcul ---
        $gsc_data = [];
        foreach ( $post_ids as $pid ) {
            $raw = get_post_meta( $pid, '_sil_gsc_data', true );
            $data = is_array( $raw ) ? $raw : json_decode( $raw, true );
            $queries = [];
            if ( isset( $data['top_queries'] ) && is_array( $data['top_queries'] ) ) {
                foreach ( array_slice( $data['top_queries'], 0, 5 ) as $q ) {
                    $queries[] = strtolower( $q['query'] ?? '' );
                }
            }
            $gsc_data[ $pid ] = array_filter( $queries );
        }

        // 2. Iterative updates
        for ( $iter = 0; $iter < self::MAX_ITER; $iter++ ) {
            // 2a. Compute centroids (kernel-weighted)
            $centroids = $this->compute_centroids( $embeddings, $post_ids, $U, $k, $m, $gamma );

            // 2b. OPTIMISATION : Identifier les signatures de clusters une fois par itération globale
            $cluster_signatures = $this->get_cluster_signatures( $post_ids, $U, $k, $gsc_data );

            // 2c. Compute new membership (kernel distance)
            $U_new = $this->compute_membership_matrix( $embeddings, $centroids, $k, $m, $gamma );

            // Nettoyage immédiat des centroïdes pour libérer de la mémoire
            unset( $centroids );

            // 2d. Check convergence
            if ( $this->has_converged( $U, $U_new, $n, $k ) ) {
                $U = $U_new;
                break;
            }
            $U = $U_new;
            unset( $cluster_signatures );
        }

        // 3. Build result [ post_id => [ silo_id => score ] ]
        $result = [];
        foreach ( $post_ids as $i => $pid ) {
            $scores = [];
            for ( $c = 0; $c < $k; $c++ ) {
                $scores[ $c + 1 ] = round( $U[ $i ][ $c ], 4 ); // silo_id is 1-based
            }
            arsort( $scores );
            $result[ $pid ] = $scores;
        }

        return $result;
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Load all post embeddings from the database.
     *
     * @return array [ post_id => float[] ]
     */
    public function load_embeddings(): array {
        // Identification des pages exclues
        $excluded_manual = $this->wpdb->get_col( "SELECT post_id FROM {$this->wpdb->postmeta} WHERE meta_key = '_sil_exclude_from_mapping' AND meta_value = '1'" );
        $system_pages = [ (int)get_option('page_on_front'), (int)get_option('page_for_posts') ];
        $all_excluded = array_merge( array_map('intval', $excluded_manual), $system_pages );

        // NOUVEAU (V2.8.3) : Jointure avec wp_posts pour ne garder que les contenus PUBLIÉS et LIVE
        // Cela évite que les recommandations (k) soient basées sur des brouillons ou des pages supprimées.
        $rows = $this->wpdb->get_results(
            "SELECT e.post_id, e.embedding 
             FROM {$this->table_embeddings} e
             INNER JOIN {$this->wpdb->posts} p ON e.post_id = p.ID
             WHERE p.post_status = 'publish' 
             AND p.post_type IN ('post', 'page')",
            ARRAY_A
        );

        $out = [];
        foreach ( $rows as $row ) {
            $pid = (int) $row['post_id'];
            if ( in_array($pid, $all_excluded) ) continue;

            $vec = json_decode( $row['embedding'], true );
            if ( is_array( $vec ) && count( $vec ) > 0 ) {
                $out[ $pid ] = array_map( 'floatval', $vec );
            }
        }
        return $out;
    }

    /**
     * Extract K-Means++ seeds logic for reuse
     */
    private function get_kmeans_plus_plus_seeds( array $embeddings, int $k ): array {
        $post_ids = array_keys( $embeddings );
        $n = count( $post_ids );
        $seeds = [];
        
        // 1. Pick first seed randomly
        $first_idx = mt_rand( 0, $n - 1 );
        $seeds[] = $embeddings[ $post_ids[ $first_idx ] ];

        // 2. Pick remaining k-1 seeds using D(x)^2 probability / Maximin
        for ( $c = 1; $c < $k; $c++ ) {
            $distances = [];
            foreach ( $post_ids as $pid ) {
                $min_dist = 1.0;
                foreach ( $seeds as $seed ) {
                    $dist = 1.0 - $this->cosine_similarity( $embeddings[ $pid ], $seed );
                    if ( $dist < $min_dist ) $min_dist = $dist;
                }
                $distances[ $pid ] = $min_dist;
            }
            arsort( $distances );
            reset( $distances );
            $furthest_pid = key( $distances );
            $seeds[] = $embeddings[ $furthest_pid ];
        }
        return $seeds;
    }

    /**
     * K-Means++ style initialization for membership matrix.
     * Picks k seeds as far apart as possible to ensure diverse silos.
     */
    /**
     * K-Means++ initialization with RBF kernel distance.
     * [PHASE2] Added $gamma for kernel-based membership.
     */
    private function init_membership_kmeans_plus_plus( array $embeddings, int $k, float $m = self::DEFAULT_M, float $gamma = 1.0 ): array {
        $post_ids = array_keys( $embeddings );
        $n = count( $post_ids );
        if ($n < $k) return $this->init_membership( $post_ids, $k );

        $seeds = $this->get_kmeans_plus_plus_seeds($embeddings, $k);

        return $this->compute_membership_matrix( $embeddings, $seeds, $k, $m, $gamma );
    }

    /**
     * Elect the most representative article (medoid) for each centroid.
     */
    private function elect_medoids( array $embeddings, array $centroids ): array {
        $medoids = [];
        foreach ( $centroids as $c_idx => $center ) {
            $best_pid = 0;
            $max_sim  = -1.0;
            foreach ( $embeddings as $pid => $vec ) {
                $sim = $this->cosine_similarity( $vec, $center );
                if ( $sim > $max_sim ) {
                    $max_sim  = $sim;
                    $best_pid = $pid;
                }
            }
            $medoids[ $c_idx + 1 ] = $best_pid;
        }
        return $medoids;
    }

    /**
     * Initialize membership matrix with uniform random values, normalized per row.
     */
    private function init_membership( array $post_ids, int $k ): array {
        $U = [];
        foreach ( $post_ids as $pid ) {
            $row = [];
            $sum = 0.0;
            for ( $c = 0; $c < $k; $c++ ) {
                $row[ $c ] = mt_rand( 1, 100 );
                $sum      += $row[ $c ];
            }
            for ( $c = 0; $c < $k; $c++ ) {
                $row[ $c ] /= $sum;
            }
            $U[ $pid ] = $row;
        }
        return $U;
    }

    /**
     * Compute fuzzy centroids with RBF kernel weighting.
     * [PHASE2] Weight = u_ic^m * K(x_i, v_c) — anchors centroids on local density.
     * Centroid is L2-normalized after computation.
     */
    private function compute_centroids( array $embeddings, array $post_ids, array $U, int $k, float $m, float $gamma = 1.0 ): array {
        $dim       = count( reset( $embeddings ) );
        $centroids = array_fill( 0, $k, array_fill( 0, $dim, 0.0 ) );
        $weights   = array_fill( 0, $k, 0.0 );

        // Pré-calcul des centroïdes provisoires pour le kernel (premier passage)
        // On utilise les centroïdes FCM classiques comme base initiale pour K(x_i, v_c)
        $prev_centroids = array_fill( 0, $k, array_fill( 0, $dim, 0.0 ) );
        $prev_weights   = array_fill( 0, $k, 0.0 );
        foreach ( $post_ids as $pid ) {
            $vec = $embeddings[ $pid ];
            for ( $c = 0; $c < $k; $c++ ) {
                if ( ! isset( $U[ $pid ][ $c ] ) ) continue;
                $u_m = pow( $U[ $pid ][ $c ], $m );
                $prev_weights[ $c ] += $u_m;
                for ( $d = 0; $d < $dim; $d++ ) {
                    $prev_centroids[ $c ][ $d ] += $u_m * $vec[ $d ];
                }
            }
        }
        for ( $c = 0; $c < $k; $c++ ) {
            if ( $prev_weights[ $c ] > 1e-9 ) {
                $prev_centroids[ $c ] = array_map( fn( $v ) => $v / $prev_weights[ $c ], $prev_centroids[ $c ] );
            }
        }

        // Second passage : pondération par le noyau K(x_i, v_c)
        foreach ( $post_ids as $pid ) {
            $vec = $embeddings[ $pid ];
            for ( $c = 0; $c < $k; $c++ ) {
                if ( ! isset( $U[ $pid ][ $c ] ) ) continue;
                $cos_sim = SIL_Math_Evaluator::calculate_similarity( $vec, $prev_centroids[ $c ] );
                $kernel  = SIL_Math_Evaluator::calculate_kernel_value( $cos_sim, $gamma );
                $u_m     = pow( $U[ $pid ][ $c ], $m );
                $w       = $u_m * $kernel;
                $weights[ $c ] += $w;
                for ( $d = 0; $d < $dim; $d++ ) {
                    $centroids[ $c ][ $d ] += $w * $vec[ $d ];
                }
            }
        }

        for ( $c = 0; $c < $k; $c++ ) {
            $sum_u = $weights[ $c ];
            if ( $sum_u > 1e-9 ) {
                $centroids[ $c ] = array_map( fn( $v ) => $v / $sum_u, $centroids[ $c ] );
                // [PHASE2] L2-normalisation du centroïde
                $centroids[ $c ] = $this->normalize( $centroids[ $c ] );
            } else {
                // RANIMATION : Si un silo est vide, on le ré-initialise sur un article aléatoire
                $random_pid = $post_ids[ mt_rand( 0, count( $post_ids ) - 1 ) ];
                $centroids[ $c ] = $embeddings[ $random_pid ];
            }
        }

        return $centroids;
    }




    /**
     * Compute membership matrix for all articles.
     * [PHASE2] Added $gamma for RBF kernel distance.
     */
    private function compute_membership_matrix( array $embeddings, array $centroids, int $k, float $m, float $gamma = 1.0, array $weights = [] ): array {
        $U = [];
        foreach ( $embeddings as $pid => $emb ) {
            $U[ $pid ] = $this->compute_membership( $emb, $centroids, $k, $m, $gamma, $weights );
        }
        return $U;
    }

    /**
     * Compute membership vector using RBF kernel distance.
     * [PHASE2] Distance = 1 - K(x_i, v_j) instead of raw cosine distance.
     * The Gaussian kernel naturally amplifies small differences in dense niches.
     */
    private function compute_membership( array $emb, array $centroids, int $k, float $m, float $gamma = 1.0, array $weights = [] ): array {
        $u   = array_fill( 0, $k, 0.0 );
        $eps = 1e-10;

        // 1. Calcul des distances noyau RBF
        $distances = [];
        for ( $i = 0; $i < $k; $i++ ) {
            $cos_sim = SIL_Math_Evaluator::calculate_similarity( $emb, $centroids[ $i ] );
            $kernel  = SIL_Math_Evaluator::calculate_kernel_value( $cos_sim, $gamma );
            // Distance noyau : 1 - K(x, v) ∈ [0, 1)
            $d = max( $eps, 1.0 - $kernel );

            // ÉQUILIBRAGE (Balancing) : On réduit la distance pour les petits silos
            if ( isset($weights[$i]) ) {
                $d *= $weights[$i];
            }

            $distances[ $i ] = $d;
        }

        // 2. Calcul des scores d'appartenance FCM
        for ( $i = 0; $i < $k; $i++ ) {
            $sum = 0.0;
            $d_i = $distances[ $i ];
            for ( $j = 0; $j < $k; $j++ ) {
                $d_j = $distances[ $j ];
                $ratio = $d_i / $d_j;
                $sum += pow( $ratio, 2 / ( $m - 1 ) );
            }
            $u[ $i ] = 1.0 / $sum;
        }

        return $u;
    }

    // [PHASE1:REMOVED] calculate_niche_diameter() — Niche Stretching manuel.
    // Calculait le diamètre moyen de la niche (sample cosine distance).
    // Sera remplacé par un stretching adaptatif dans SIL_Math_Evaluator (Phase 2).
    // private function calculate_niche_diameter( array $embeddings ): float { ... }

    /**
     * Check convergence: max absolute change in U < epsilon.
     */
    private function has_converged( array $U, array $U_new, int $n, int $k ): bool {
        foreach ( $U as $pid => $scores ) {
            if ( ! isset( $U_new[ $pid ] ) ) continue;
            for ( $c = 0; $c < $k; $c++ ) {
                if ( abs( $scores[ $c ] - $U_new[ $pid ][ $c ] ) > self::CONVERGENCE_EPS ) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Persist membership matrix to database.
     *
     * @return array { articles_processed, silos_count, bridges_count }
     */
    private function persist_membership( array $membership, int $k ): array {
        // Clear previous results
        $this->wpdb->query( "TRUNCATE TABLE {$this->table_membership}" );

        $bridges_count = 0;
        $primary_assignments = [];
        $silo_membership_counts = array_fill( 1, $k, 0 );

        foreach ( $membership as $post_id => $scores ) {
            // Find the cluster with the maximum score (0-indexed in $scores)
            $max_val = -1.0;
            $best_c  = 0;
            foreach ( $scores as $c => $score ) {
                if ( $score > $max_val ) {
                    $max_val = $score;
                    $best_c  = $c;
                }
            }
            // Map to 1-indexed silo ID
            $primary_id = $best_c + 1;
            $primary_assignments[ $post_id ] = $primary_id;
            $silo_membership_counts[ $primary_id ]++;
        }

        // L'Auto-Heal a déjà garanti que $silo_membership_counts n'a pas de zéros.

        $charset_collate = $this->wpdb->get_charset_collate();

        foreach ( $membership as $post_id => $scores ) {
            $primary_id = $primary_assignments[ $post_id ];
            $secondary_score = 0.0;
            
            // On cherche le meilleur score qui n'est pas le nouveau primary_id
            foreach ( $scores as $c => $score ) {
                $sid = $c + 1; // Map to 1-indexed
                if ( $sid !== (int)$primary_id && $score > $secondary_score ) {
                    $secondary_score = $score;
                }
            }
            
            $is_bridge = $secondary_score >= self::BRIDGE_THRESHOLD ? 1 : 0;
            if ( $is_bridge ) $bridges_count++;

            foreach ( $scores as $c => $score ) {
                $silo_id = $c + 1; // Map to 1-indexed
                $is_primary = ( (int)$silo_id === (int)$primary_id ) ? 1 : 0;
                $this->wpdb->replace(
                    $this->table_membership,
                    [
                        'post_id'    => (int) $post_id,
                        'silo_id'    => (int) $silo_id,
                        'score'      => (float) $score,
                        'is_primary' => $is_primary,
                        'is_bridge'  => $is_bridge,
                    ],
                    [ '%d', '%d', '%f', '%d', '%d' ]
                );
            }
        }

        // Update last rebuild timestamp for diagnostics
        update_option( 'sil_last_semantic_rebuild', current_time( 'mysql' ) );

        // Invalidate graph cache after rebuild (v13_0 is the current version used in audit)
        delete_transient( 'sil_graph_cache_v13_0' );
        delete_transient( 'sil_graph_cache_v12_0' );
        delete_transient( 'sil_graph_cache' ); 

        return [
            'articles_processed' => count( $membership ),
            'silos_count'        => $k,
            'bridges_count'      => $bridges_count,
        ];
    }

    /**
     * Cosine similarity between two vectors.
     * [PHASE2] Delegates to SIL_Math_Evaluator for centralized math.
     */
    private function cosine_similarity( array $a, array $b ): float {
        return SIL_Math_Evaluator::calculate_similarity( $a, $b );
    }

    /**
     * Normalize a vector to unit length.
     */
    private function normalize( array $vec ): array {
        $norm = 0.0;
        foreach ( $vec as $v ) $norm += $v * $v;
        $norm = sqrt( $norm );
        if ( $norm == 0.0 ) return $vec;
        return array_map( fn( $v ) => $v / $norm, $vec );
    }
    /**
     * Calcule la signature thématique (requêtes GSC) de chaque cluster.
     * Optimisé pour éviter array_merge dans des boucles imbriquées massives.
     */
    private function get_cluster_signatures( array $post_ids, array $U, int $k, array $gsc_data ): array {
        if ( empty( $U ) ) return [];

        $signatures = [];
        for ( $c = 0; $c < $k; $c++ ) {
            $c_queries = [];
            foreach ( $post_ids as $idx => $pid ) {
                // Si l'article est membre de ce cluster (via l'index de score max)
                if ( isset( $U[ $idx ] ) && $this->find_max_membership_index( $U[ $idx ] ) === $c ) {
                    if ( ! empty( $gsc_data[ $pid ] ) ) {
                        // Utilisation de array_push avec déballage (...) pour éviter les copies d'array_merge
                        array_push( $c_queries, ...$gsc_data[ $pid ] );
                    }
                }
            }
            $signatures[ $c ] = array_unique( $c_queries );
            unset( $c_queries );
        }
        return $signatures;
    }

    private function find_max_membership_index( array $memberships ): int {
        $max_val = -1.0;
        $primary = 0;
        foreach ( $memberships as $c => $score ) {
            if ( $score > $max_val ) {
                $max_val = $score;
                $primary = $c;
            }
        }
        return $primary;
    }

    /**
     * Calcule la différence moyenne entre deux matrices d'appartenance.
     */
    private function calculate_delta( $U1, $U2 ) {
        $total_diff = 0;
        $count = 0;
        foreach ($U1 as $pid => $scores1) {
            if (!isset($U2[$pid])) continue;
            foreach ($scores1 as $c => $s1) {
                $total_diff += abs($s1 - $U2[$pid][$c]);
                $count++;
            }
        }
        return $count > 0 ? $total_diff / $count : 1.0;
    }

    /**
     * Téléporte les centroïdes des silos "morts" vers le centre du plus gros silo.
     * Force la division (splitting) des clusters dominants.
     */
    private function teleport_stuck_centroids( &$centroids, $silo_sizes, $k ) {
        $stuck_ids = [];
        $max_size = 0;
        $max_id = 0;
        
        foreach ($silo_sizes as $id => $size) {
            if ($size < 4) $stuck_ids[] = $id; // Seuil de mort : < 4 articles
            if ($size > $max_size) {
                $max_size = $size;
                $max_id = $id;
            }
        }

        // Si aucun silo n'est mort ou si le plus gros est trop petit, on ne fait rien
        if (empty($stuck_ids) || $max_size < 8) return;

        foreach ($stuck_ids as $stuck_id) {
            if (!isset($centroids[$max_id])) continue;
            
            // On clone le centre du plus gros
            $new_seed = $centroids[$max_id];
            
            // On ajoute une perturbation sémantique aléatoire (2-5%) pour briser la symétrie
            $perturbation = (mt_rand(20, 50) / 1000.0);
            foreach ($new_seed as &$val) {
                $val += (mt_rand(0, 1) ? $perturbation : -$perturbation);
            }
            
            $centroids[$stuck_id] = $new_seed;
        }
    }
}
