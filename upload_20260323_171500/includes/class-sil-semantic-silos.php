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
    const DEFAULT_M          = 2.0;   // Fuzziness exponent
    const MAX_ITER           = 150;
    const CONVERGENCE_EPS    = 1e-5;
    const BRIDGE_THRESHOLD   = 0.20;  // Secondary silo score to flag as bridge

    private $wpdb;
    private $table_embeddings;
    private $table_membership;

    public function __construct() {
        global $wpdb;
        $this->wpdb             = $wpdb;
        $this->table_embeddings = $wpdb->prefix . 'sil_embeddings';
        $this->table_membership = $wpdb->prefix . 'sil_silo_membership';
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Full rebuild: load embeddings → Fuzzy C-Means → persist membership.
     *
     * @param int $k Number of silos (clusters).
     * @return array { articles_processed, silos_count, bridges_count } or WP_Error.
     */
    public function rebuild_silos( int $k = self::DEFAULT_K ) {
        $embeddings = $this->load_embeddings();

        if ( count( $embeddings ) < $k ) {
            return new WP_Error(
                'sil_not_enough_articles',
                sprintf( 'Pas assez d\'articles indexés (%d) pour %d silos.', count( $embeddings ), $k )
            );
        }

        $membership = $this->fuzzy_cmeans( $embeddings, $k );
        $stats      = $this->persist_membership( $membership, $k );

        return $stats;
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
     *
     * @return array [ silo_id => label ]
     */
    public function get_silo_labels(): array {
        $labels = [];
        $silo_ids = $this->wpdb->get_col("SELECT DISTINCT silo_id FROM {$this->table_membership} ORDER BY silo_id ASC");
        
        foreach ($silo_ids as $sid) {
            $sid = (int)$sid;
            // The first member in the silo is the most representative (Pivot)
            $member_ids = $this->get_silo_members($sid, true);
            
            if (empty($member_ids)) {
                $labels[$sid] = "Silo $sid";
                continue;
            }

            $pivot_id = $member_ids[0];
            $gsc_raw = get_post_meta($pivot_id, '_sil_gsc_data', true);
            
            // Handle both JSON and Serialized data (SEO-safe parsing)
            $gsc = is_array($gsc_raw) ? $gsc_raw : json_decode($gsc_raw, true);
            if (empty($gsc) && is_string($gsc_raw) && $gsc_raw) {
                $gsc = function_exists('maybe_unserialize') ? maybe_unserialize($gsc_raw) : unserialize($gsc_raw);
            }

            $rows = $gsc['top_queries'] ?? ($gsc ?: []);
            $keywords = [];

            if (is_array($rows)) {
                foreach (array_slice($rows, 0, 3) as $r) {
                    $kw = $r['query'] ?? ($r['keys'][0] ?? '');
                    if ($kw) $keywords[] = $kw;
                }
            }

            if (!empty($keywords)) {
                // Return " [ID] Keyword1, Keyword2, Keyword3"
                $labels[$sid] = sprintf("[%d] %s", $sid, implode(', ', $keywords));
            } else {
                // Fallback: [ID] + First 4 words of Pivot Title
                $title = get_the_title($pivot_id);
                $labels[$sid] = sprintf("[%d] %s", $sid, wp_trim_words($title, 4));
            }
        }
        return $labels;
    }

    /**
     * Compute a distance matrix between all silos based on their centroids.
     */
    public function get_silo_distance_matrix(): array {
        $embeddings = $this->load_embeddings();
        if (empty($embeddings)) return [];
        
        $k = (int) $this->wpdb->get_var("SELECT COUNT(DISTINCT silo_id) FROM {$this->table_membership}");
        if ($k < 2) return [];

        // 1. Calculate Centroids
        $U = [];
        $post_ids = [];
        $rows = $this->wpdb->get_results("SELECT post_id, silo_id, score FROM {$this->table_membership}", ARRAY_A);
        $pid_to_idx = [];
        foreach($rows as $row) {
            $pid = (int)$row['post_id'];
            if (!isset($pid_to_idx[$pid])) {
                $pid_to_idx[$pid] = count($post_ids);
                $post_ids[] = $pid;
            }
            $U[$pid_to_idx[$pid]][(int)$row['silo_id'] - 1] = (float)$row['score'];
        }
        
        $centroids = $this->compute_centroids($embeddings, $post_ids, $U, $k, 2.0);
        
        // 2. Compute Distance Matrix (Cosine Distance)
        $matrix = [];
        for($i=0; $i<$k; $i++) {
            for($j=0; $j<$k; $j++) {
                if ($i === $j) {
                    $matrix[$i+1][$j+1] = 0;
                } else {
                    $sim = $this->cosine_similarity($centroids[$i], $centroids[$j]);
                    $matrix[$i+1][$j+1] = round(1 - $sim, 4);
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

    // =========================================================================
    // FUZZY C-MEANS
    // =========================================================================

    /**
     * Fuzzy C-Means on a set of embeddings using cosine distance.
     *
     * @param array $embeddings [ post_id => float[] ]
     * @param int   $k          Number of clusters.
     * @param float $m          Fuzziness exponent (>1, typically 2).
     * @return array            Membership matrix [ post_id => [ silo_id (1-based) => score ] ]
     */
    public function fuzzy_cmeans( array $embeddings, int $k, float $m = self::DEFAULT_M ): array {
        $post_ids = array_keys( $embeddings );
        $n        = count( $post_ids );

        // 1. Initialize membership matrix randomly then normalize
        $U = $this->init_membership( $n, $k );

        // 2. Iterative updates
        for ( $iter = 0; $iter < self::MAX_ITER; $iter++ ) {
            // 2a. Compute centroids (as weighted mean of embeddings)
            $centroids = $this->compute_centroids( $embeddings, $post_ids, $U, $k, $m );

            // 2b. Compute new membership
            $U_new = $this->compute_membership( $embeddings, $post_ids, $centroids, $k, $m );

            // 2c. Check convergence
            if ( $this->has_converged( $U, $U_new, $n, $k ) ) {
                $U = $U_new;
                break;
            }
            $U = $U_new;
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
        $rows = $this->wpdb->get_results(
            "SELECT post_id, embedding FROM {$this->table_embeddings}",
            ARRAY_A
        );

        $out = [];
        foreach ( $rows as $row ) {
            $vec = json_decode( $row['embedding'], true );
            if ( is_array( $vec ) && count( $vec ) > 0 ) {
                $out[ (int) $row['post_id'] ] = array_map( 'floatval', $vec );
            }
        }
        return $out;
    }

    /**
     * Initialize membership matrix with uniform random values, normalized per row.
     */
    private function init_membership( int $n, int $k ): array {
        $U = [];
        for ( $i = 0; $i < $n; $i++ ) {
            $row = [];
            $sum = 0.0;
            for ( $c = 0; $c < $k; $c++ ) {
                $row[ $c ] = mt_rand( 1, 100 );
                $sum      += $row[ $c ];
            }
            for ( $c = 0; $c < $k; $c++ ) {
                $row[ $c ] /= $sum;
            }
            $U[ $i ] = $row;
        }
        return $U;
    }

    /**
     * Compute fuzzy centroids as the weighted mean of all embeddings.
     * Centroid_c = sum( u_ic^m * x_i ) / sum( u_ic^m )
     */
    private function compute_centroids( array $embeddings, array $post_ids, array $U, int $k, float $m ): array {
        $dim       = count( reset( $embeddings ) );
        $centroids = array_fill( 0, $k, array_fill( 0, $dim, 0.0 ) );
        $weights   = array_fill( 0, $k, 0.0 );

        foreach ( $post_ids as $i => $pid ) {
            $vec = $embeddings[ $pid ];
            for ( $c = 0; $c < $k; $c++ ) {
                $u_m         = pow( $U[ $i ][ $c ], $m );
                $weights[ $c ] += $u_m;
                for ( $d = 0; $d < $dim; $d++ ) {
                    $centroids[ $c ][ $d ] += $u_m * $vec[ $d ];
                }
            }
        }

        for ( $c = 0; $c < $k; $c++ ) {
            if ( $weights[ $c ] > 0 ) {
                for ( $d = 0; $d < $dim; $d++ ) {
                    $centroids[ $c ][ $d ] /= $weights[ $c ];
                }
                // Normalize centroid to unit vector (for cosine distance)
                $centroids[ $c ] = $this->normalize( $centroids[ $c ] );
            }
        }

        return $centroids;
    }

    /**
     * Compute new membership matrix from distances to centroids.
     * u_ic = 1 / sum_j( (d_ic / d_ij)^(2/(m-1)) )
     * Using cosine distance: d = 1 - cosine_similarity
     */
    private function compute_membership( array $embeddings, array $post_ids, array $centroids, int $k, float $m ): array {
        $exp = 2.0 / ( $m - 1 );
        $U   = [];

        foreach ( $post_ids as $i => $pid ) {
            $vec       = $this->normalize( $embeddings[ $pid ] );
            $distances = [];

            for ( $c = 0; $c < $k; $c++ ) {
                $sim          = $this->cosine_similarity( $vec, $centroids[ $c ] );
                $distances[ $c ] = max( 1e-10, 1.0 - $sim ); // cosine distance, avoid division by zero
            }

            $row = [];
            for ( $c = 0; $c < $k; $c++ ) {
                $sum = 0.0;
                foreach ( $distances as $j => $d_j ) {
                    $sum += pow( $distances[ $c ] / $d_j, $exp );
                }
                $row[ $c ] = 1.0 / $sum;
            }
            $U[ $i ] = $row;
        }

        return $U;
    }

    /**
     * Check convergence: max absolute change in U < epsilon.
     */
    private function has_converged( array $U, array $U_new, int $n, int $k ): bool {
        for ( $i = 0; $i < $n; $i++ ) {
            for ( $c = 0; $c < $k; $c++ ) {
                if ( abs( $U[ $i ][ $c ] - $U_new[ $i ][ $c ] ) > self::CONVERGENCE_EPS ) {
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
        $charset_collate = $this->wpdb->get_charset_collate();

        foreach ( $membership as $post_id => $scores ) {
            // scores is already sorted desc by silo_id score
            $silo_ids    = array_keys( $scores );
            $primary_id  = $silo_ids[0];
            $secondary_score = isset( $silo_ids[1] ) ? $scores[ $silo_ids[1] ] : 0.0;
            $is_bridge   = $secondary_score >= self::BRIDGE_THRESHOLD ? 1 : 0;

            if ( $is_bridge ) $bridges_count++;

            foreach ( $scores as $silo_id => $score ) {
                $is_primary = ( $silo_id === $primary_id ) ? 1 : 0;
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

        // Invalidate graph cache after rebuild
        delete_transient( 'sil_graph_cache' );

        return [
            'articles_processed' => count( $membership ),
            'silos_count'        => $k,
            'bridges_count'      => $bridges_count,
        ];
    }

    /**
     * Cosine similarity between two vectors.
     */
    private function cosine_similarity( array $a, array $b ): float {
        $dot = $norm_a = $norm_b = 0.0;
        $len = min( count( $a ), count( $b ) );
        for ( $i = 0; $i < $len; $i++ ) {
            $dot    += $a[ $i ] * $b[ $i ];
            $norm_a += $a[ $i ] * $a[ $i ];
            $norm_b += $b[ $i ] * $b[ $i ];
        }
        if ( $norm_a == 0.0 || $norm_b == 0.0 ) return 0.0;
        return $dot / ( sqrt( $norm_a ) * sqrt( $norm_b ) );
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
}
