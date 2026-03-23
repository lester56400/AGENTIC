<?php
/**
 * Smart Internal Links AJAX Handler
 *
 * @package SmartInternalLinks
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class SIL_Ajax_Handler
 *
 * Handles AJAX requests for the Smart Internal Links plugin.
 */
class SIL_Ajax_Handler
{

    /**
     * Main plugin instance.
     *
     * @var SmartInternalLinks
     */
    private $main;

    /**
     * Constructor.
     *
     * @param SmartInternalLinks $main The main plugin instance.
     */
    public function __construct($main)
    {
        $this->main = $main;
    }

    /**
     * Register AJAX hooks.
     */
    public function init($handler)
    {
        add_action( 'wp_ajax_sil_index_embeddings_batch', [ $handler, 'sil_index_embeddings_batch' ] );
        add_action( 'wp_ajax_sil_get_indexing_status', [ $handler, 'sil_get_indexing_status' ] );
        add_action('wp_ajax_sil_rebuild_semantic_silos', [$handler, 'sil_rebuild_semantic_silos']);
        add_action('wp_ajax_sil_get_missing_inlinks',    [$handler, 'sil_get_missing_inlinks']);
        add_action('wp_ajax_sil_seal_reciprocal_link',  [$handler, 'sil_seal_reciprocal_link']);
        add_action( 'wp_ajax_sil_run_semantic_audit', [ $handler, 'sil_run_semantic_audit' ] );
        add_action( 'wp_ajax_sil_run_system_diagnostic', [ $handler, 'sil_run_system_diagnostic' ] );
        add_action( 'wp_ajax_sil_run_deep_unit_tests', [ $handler, 'sil_run_deep_unit_tests' ] );
    }

    /**
     * AJAX: Save settings (Cornerstone status)
     * Maps to requested sil_save_settings
     */
    public function sil_save_settings()
    {
        check_ajax_referer('sil_cornerstone_save', 'security');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Accès refusé');
        }

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $is_cornerstone = isset($_POST['is_cornerstone']) ? sanitize_text_field($_POST['is_cornerstone']) : '0';

        if ($post_id) {
            if ($is_cornerstone === '1') {
                update_post_meta($post_id, '_sil_is_cornerstone', '1');
            } else {
                delete_post_meta($post_id, '_sil_is_cornerstone');
            }
            wp_send_json_success();
        }
        wp_send_json_error('Post ID manquant');
    }

    /**
     * AJAX: Get graph data
     */
    public function sil_get_graph_data()
    {
        check_ajax_referer('sil_nonce', 'nonce');
        if (!current_user_can('edit_posts'))
            wp_send_json_error('Permission refusée');

        @set_time_limit(120);

        $force = isset($_POST['force_refresh']) && $_POST['force_refresh'] === 'true';



        try {
            $result = $this->main->get_rendered_graph_data($force);

            wp_send_json_success($result);
        } catch (Throwable $e) {
            $err_msg = sprintf(
                "SIL Error: %s in %s on line %d\nTrace: %s",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString()
            );
            error_log($err_msg);
            
            wp_send_json_error([
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }

    /**
     * AJAX: Process batch (GSC sync)
     */
    public function sil_process_batch()
    {
        check_ajax_referer('sil_nonce', '_ajax_nonce');
        set_time_limit(0); 
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission refusée');
        }

        $post_ids = isset($_POST['post_ids']) ? array_map('intval', $_POST['post_ids']) : [];

        if (empty($post_ids)) {
            wp_send_json_error('Aucun ID fourni');
        }

        try {
            require_once SIL_PLUGIN_DIR . 'includes/class-sil-gsc-sync.php';
            $gsc_sync = new \Sil_Gsc_Sync();

            if (!$gsc_sync->is_configured()) {
                wp_send_json_error('GSC non configuré (Client ID/Secret manquant)');
            }

            // Pour tracker juste ce lot, on va lire l'ancien compteur
            $old_count = (int) get_transient('sil_last_sync_keyw_count') ?: 0;

            $result = $gsc_sync->sync_data($post_ids);

            if (is_wp_error($result)) {
                /** @var \WP_Error $result */
                wp_send_json_error($result->get_error_message());
            }

            // Calcul du nombre de mots-clés ajoutés lors de cette execution
            $new_count = (int) get_transient('sil_last_sync_keyw_count') ?: 0;
            $batch_keywords_saved = max(0, $new_count - $old_count);

            wp_send_json_success([
                'message' => count($post_ids) . ' pages traitées',
                'keywords_saved' => $batch_keywords_saved
            ]);
        } catch (Throwable $e) {
            error_log("SIL Batch Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            wp_send_json_error([
                'message' => 'Erreur lors du traitement du lot : ' . $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }

    /**
     * AJAX: Insert internal link (apply selected links)
     */
    public function sil_insert_internal_link()
    {
        check_ajax_referer('sil_nonce', 'nonce');
        if (!current_user_can('edit_posts'))
            wp_send_json_error('Permission refusée');

        $post_id = intval($_POST['post_id']);
        if (!current_user_can('edit_post', $post_id))
            wp_send_json_error('Permission refusée');

        $links = json_decode(stripslashes($_POST['links']), true);
        if (!is_array($links) || empty($links))
            wp_send_json(['success' => false, 'message' => 'Aucun lien']);

        $target_post = get_post($post_id);
        $target_excerpt = wp_trim_words(wp_strip_all_tags($target_post->post_content), 30);
        $target_title = $target_post->post_title;
        $site_host = parse_url(home_url(), PHP_URL_HOST);
        $count = 0;

        foreach ($links as $idx => $link) {
            if (!isset($link['target_url']) || !isset($link['anchor']) || !isset($link['target_id']))
                continue;

            $url = esc_url_raw($link['target_url']);
            $anchor = sanitize_text_field($link['anchor']);
            $candidate_id = intval($link['target_id']);

            if (parse_url($url, PHP_URL_HOST) !== $site_host)
                continue;

            $candidate_post = get_post($candidate_id);
            if (!$candidate_post)
                continue;

            $candidate_content = $candidate_post->post_content;

            if ($this->main->link_already_exists($candidate_content, $url))
                continue;

            $candidate_paragraphs = $this->main->get_available_paragraphs($candidate_content);
            $para_idx = isset($link['paragraph_index']) ? intval($link['paragraph_index']) : -1;
            $para = null;

            if ($para_idx > -1) {
                foreach ($candidate_paragraphs as $p) {
                    if ($p['index'] == $para_idx) {
                        $para = $p;
                        break;
                    }
                }
            } else {
                foreach ($candidate_paragraphs as $p) {
                    if (strpos(strtolower($p['text']), strtolower($anchor)) !== false) {
                        $para = $p;
                        break;
                    }
                }
            }

            if (!$para)
                continue;

            $old = $para['content'];
            $target_excerpt = wp_trim_words(wp_strip_all_tags($target_post->post_content), 30);

            // Log du lien dans la table sil_links
            global $wpdb;
            $wpdb->insert(
                $wpdb->prefix . 'sil_links',
                [
                    'source_id' => $candidate_id,
                    'target_id' => $post_id,
                    'target_url' => $url,
                    'anchor' => $anchor
                ],
                ['%d', '%d', '%s', '%s']
            );
            $link_id = $wpdb->insert_id;

            $new = $this->main->insert_link_in_paragraph($old, $anchor, $url, $target_title, $target_excerpt, $link_id);

            if ($new !== $old && strpos($new, $url) !== false) {
                $candidate_content = str_replace($old, $new, $candidate_content);
                wp_update_post(['ID' => $candidate_id, 'post_content' => $candidate_content]);
                $count++;
            }
        }


        wp_send_json(['success' => true, 'count' => $count, 'message' => $count . ' lien(s) inséré(s)']);
    }

    /**
     * AJAX: Delete edge link
     */
    public function sil_delete_edge_link()
    {
        check_ajax_referer('sil_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission refusée');
        }

        $source_id = intval($_POST['source_id']);
        $target_id = intval($_POST['target_id']);

        if (!$source_id || !$target_id) {
            wp_send_json_error('IDs manquants');
        }

        $source_post = get_post($source_id);
        $target_url = get_permalink($target_id);

        if (!$source_post || !$target_url) {
            wp_send_json_error('Post source ou cible introuvable');
        }

        $content = $source_post->post_content;

        $escaped_url = preg_quote($target_url, '/');
        $pattern = '/<a[^>]+href=["\'](' . $escaped_url . '|' . wp_make_link_relative($target_url) . ')["\'][^>]*>(.*?)<\/a>/i';

        $new_content = preg_replace($pattern, '$2', $content);

        if ($new_content !== $content) {
            wp_update_post([
                'ID' => $source_id,
                'post_content' => $new_content
            ]);

            $this->main->clear_graph_cache();

            wp_send_json_success('Lien supprimé de la base de données');
        } else {
            wp_send_json_error('Lien non trouvé dans le contenu textuel du post source');
        }
    }

    /**
     * AJAX: Remove internal link (disassociation)
     * Retire le lien HTML du contenu et supprime l'entrée en base.
     */
    public function sil_remove_internal_link()
    {
        check_ajax_referer('sil_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission refusée');
        }

        $source_id = isset($_POST['source_id']) ? intval($_POST['source_id']) : 0;
        $target_url = isset($_POST['target_url']) ? esc_url_raw($_POST['target_url']) : '';

        if (!$source_id || !$target_url) {
            wp_send_json_error('Paramètres manquants');
        }

        $content = get_post_field('post_content', $source_id);
        if (!$content) {
            wp_send_json_error('Contenu introuvable');
        }

        // RegEx pour trouver le lien et le remplacer par son texte brut
        $escaped_url = preg_quote($target_url, '/');
        $relative_url = wp_make_link_relative($target_url);
        $escaped_relative_url = preg_quote($relative_url, '/');

        $pattern = '/<a[^>]+href=["\'](' . $escaped_url . '|' . $escaped_relative_url . ')["\'][^>]*>(.*?)<\/a>/i';
        $new_content = preg_replace($pattern, '$2', $content);

        if ($new_content !== $content) {
            wp_update_post([
                'ID' => $source_id,
                'post_content' => $new_content
            ]);

            // Suppression de l'entrée en base (table sil_links)
            global $wpdb;
            $wpdb->delete(
                $wpdb->prefix . 'sil_links',
                [
                    'source_id' => $source_id,
                    'target_url' => $target_url
                ],
                ['%d', '%s']
            );

            $this->main->clear_graph_cache();

            wp_send_json_success(['message' => 'Lien retiré avec succès']);
        } else {
            wp_send_json_error('Lien non trouvé dans le contenu');
        }
    }

    /**
     * AJAX: Track click on a link
     */
    public function sil_track_click()
    {
        // On ne vérifie pas le nonce si on utilise sendBeacon (qui ne peut pas envoyer headers personnalisés facilement sans config complexe)
        // Mais ici on l'envoie dans FormData, donc on peut vérifier.
        // check_ajax_referer('sil_tracking_nonce', 'nonce'); // Désactivé si sendBeacon pose souci, mais testons avec.

        $link_id = isset($_POST['link_id']) ? intval($_POST['link_id']) : 0;

        if ($link_id) {
            global $wpdb;
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}sil_links SET click_count = click_count + 1 WHERE id = %d",
                $link_id
            ));
            wp_send_json_success();
        }
        wp_send_json_error('Link ID manquant');
    }

    /**
     * AJAX: Export link opportunities to CSV
     */
    public function sil_export_opportunities_csv()
    {
        if (!current_user_can('edit_posts')) {
            wp_die('Accès refusé');
        }

        // Pour éviter les timeouts sur les gros sites
        @set_time_limit(300);
        ignore_user_abort(true);

        $filename = 'sil-opportunites-' . date('Y-m-d') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');
        // BOM for Excel compatibility
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Headers
        fputcsv($output, [
            'URL Source',
            'URL Cible',
            'Ancre suggérée',
            'Type',
            'Score'
        ], ';');

        global $wpdb;
        $post_types = $this->main->get_post_types();
        $post_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM $wpdb->posts WHERE post_type IN (" . implode(',', array_fill(0, count($post_types), '%s')) . ") AND post_status = 'publish' ORDER BY post_date DESC",
            ...$post_types
        ));

        foreach ($post_ids as $post_id) {
            $analysis = $this->main->insert_internal_links($post_id, true);

            if (!empty($analysis['links'])) {
                foreach ($analysis['links'] as $link) {
                    fputcsv($output, [
                        get_permalink($link['target_id']), // Source (celui qui recevra le lien dans son contenu)
                        $link['target_url'],            // Cible (le post_id initial passé à insert_internal_links)
                        $link['anchor'],
                        'Sil_Link',
                        number_format($link['similarity'] * 100, 2) . '%'
                    ], ';');
                }
            }
        }

        fclose($output);
        exit;
    }



    /**
     * AJAX: Get node details (stats & URLs)
     */
    public function sil_get_node_details()
    {
        check_ajax_referer('sil_nonce', 'nonce');
        global $wpdb;
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission refusée');
        }

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        if (!$post_id) {
            wp_send_json_error('ID manquant');
        }

        $incoming = $this->main->count_backlinks($post_id);
        $outgoing = $this->main->count_internal_links($post_id);

        // Fetch detailed outgoing links for Sidebar UI
        $outgoing_links = [];
        $post = get_post($post_id);
        if ($post) {
            $content = $post->post_content;
            $site_host = parse_url(home_url(), PHP_URL_HOST);
            preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $content, $matches, PREG_OFFSET_CAPTURE);
            if (!empty($matches[0])) {
                foreach ($matches[1] as $index => $match_url) {
                    $url = $match_url[0];
                    if (preg_match('/^(#|mailto:|tel:|javascript:)/i', $url)) continue;

                    $full_tag = $matches[0][$index][0];
                    $anchor = wp_strip_all_tags($matches[2][$index][0]);
                    $offset = $matches[0][$index][1];

                    $url_host = parse_url($url, PHP_URL_HOST);
                    $is_external = !empty($url_host) && strtolower($url_host) !== strtolower($site_host);
                    
                    $target_post_id = $is_external ? 0 : url_to_postid($url);
                    $type = 'internal';
                    if ($is_external) {
                        $type = 'external';
                    } elseif (!$target_post_id) {
                        $type = 'broken';
                    }

                    // Capture context around the link
                    $context_prev = strip_tags(substr($content, max(0, $offset - 45), min($offset, 45)));
                    $context_next = strip_tags(substr($content, $offset + strlen($full_tag), 45));

                    $outgoing_links[] = [
                        'url' => $url,
                        'anchor' => $anchor,
                        'title' => $target_post_id ? get_the_title($target_post_id) : ($is_external ? $url_host : 'Page inconnue'),
                        'target_id' => $target_post_id,
                        'type' => $type,
                        'context_prev' => '...' . $context_prev,
                        'context_next' => $context_next . '...'
                    ];
                }
            }
        }

        // SEO Metadata (RankMath support)
        $seo_title = get_post_meta($post_id, 'rank_math_title', true);
        if (empty($seo_title)) {
            $seo_title = $post ? $post->post_title : '';
        }
        $seo_meta = get_post_meta($post_id, 'rank_math_description', true);

        // --- BMAD 1-E : Proactive Semantic Recommendations ---
        $recommendations = [];
        
        // Fetch is_intruder status from cluster analysis (not just post meta)
        $graph_data = $this->main->get_rendered_graph_data();
        $is_intruder = false;
        if (isset($graph_data['nodes'])) {
            foreach ($graph_data['nodes'] as $node) {
                if (isset($node['data']['id']) && (string)$node['data']['id'] === (string)$post_id) {
                    $is_intruder = ($node['data']['is_intruder'] === 'true');
                    break;
                }
            }
        }
        
        $recommended_silo_id = null;
        $recommended_silo_name = '';
        $closest_content_id = null;
        $closest_content_title = '';

        $embedding = get_post_meta($post_id, '_sil_embedding', true);
        $cluster_id = get_post_meta($post_id, '_sil_cluster_id', true);
        
        $proximity = 1.0;

        if ($embedding && is_array($embedding) && $cluster_id) {
            global $wpdb;
            
            // Calculate cluster barycenter to verify representativeness
            $cluster_posts = $wpdb->get_results($wpdb->prepare(
                "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_sil_cluster_id' AND meta_value = %s",
                $cluster_id
            ));
            
            $cluster_embs = [];
            foreach ($cluster_posts as $cp) {
                $e = get_post_meta($cp->post_id, '_sil_embedding', true);
                if ($e && is_array($e)) $cluster_embs[] = $e;
            }

            if (!empty($cluster_embs)) {
                $barycenter = SIL_Centrality_Engine::calculate_barycenter($cluster_embs);
                $proximity = SIL_Centrality_Engine::get_representativeness_score($embedding, $barycenter);
            }

            // If proximity is low (< 0.3) OR explicitly marked as intruder, calculate recommendations
            if ($is_intruder || $proximity < 0.3) {
                $is_intruder = true; 
                
                // Find top 3 candidates outside the current silo (limit 150 for perf)
                $candidates_raw = $wpdb->get_results($wpdb->prepare(
                    "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_sil_cluster_id' AND meta_value != %s LIMIT 150",
                    $cluster_id
                ));

                $candidates = [];
                foreach ($candidates_raw as $c) {
                    $ce = get_post_meta($c->post_id, '_sil_embedding', true);
                    if ($ce && is_array($ce)) $candidates[$c->post_id] = $ce;
                }

                if (!empty($candidates)) {
                    $top_recos = SIL_Centrality_Engine::get_top_recommendations($embedding, $candidates, 3);
                    $count = 0;
                    foreach ($top_recos as $reco_id => $score) {
                        $reco_title = get_the_title($reco_id);
                        $recommendations[] = [
                            'id' => $reco_id,
                            'title' => $reco_title,
                            'score' => round($score * 100, 1)
                        ];
                        
                        if ($count === 0) {
                            $closest_content_id = $reco_id;
                            $closest_content_title = $reco_title;
                            $recommended_silo_id = get_post_meta($reco_id, '_sil_cluster_id', true);
                            
                            // Determine Silo name from common category
                            $recommended_silo_name = "Silo " . $recommended_silo_id;
                            $silo_posts = $wpdb->get_results($wpdb->prepare(
                                "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_sil_cluster_id' AND meta_value = %s LIMIT 20",
                                $recommended_silo_id
                            ));
                            $cats = [];
                            foreach($silo_posts as $sp) {
                                $p_cats = get_the_category($sp->post_id);
                                if (!empty($p_cats)) $cats[] = $p_cats[0]->name;
                            }
                            if (!empty($cats)) {
                                $counts = array_count_values($cats);
                                arsort($counts);
                                $recommended_silo_name = array_key_first($counts);
                            }
                        }
                        $count++;
                    }
                }
            }
        }

        // --- NEW: Semantic Silo Membership Data ---
        $membership_data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sil_silo_membership WHERE post_id = %d AND is_primary = 1",
            $post_id
        ), ARRAY_A);

        $secondary_data = null;
        if ($membership_data && $membership_data['is_bridge']) {
            $secondary_data = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}sil_silo_membership WHERE post_id = %d AND is_primary = 0 ORDER BY score DESC LIMIT 1",
                $post_id
            ), ARRAY_A);
        }

        wp_send_json_success([
            'id' => $post_id,
            'incoming' => $incoming,
            'outgoing' => $outgoing,
            'outgoing_links' => $outgoing_links,
            'seo_title' => $seo_title,
            'seo_meta' => $seo_meta,
            'edit_url' => get_edit_post_link($post_id),
            'view_url' => get_permalink($post_id),
            'is_intruder' => $is_intruder,
            'proximity' => round($proximity, 3),
            'semantic_membership' => $membership_data,
            'secondary_membership' => $secondary_data,
            'semantic_recommendations' => $recommendations,
            'recommended_silo_id' => $recommended_silo_id,
            'recommended_silo_name' => $recommended_silo_name,
            'closest_content_id' => $closest_content_id,
            'closest_content_title' => $closest_content_title
        ]);

    }

    /**
     * AJAX: Sauvegarde RankMath SEO Meta
     */
    public function sil_update_seo_meta()
    {
        check_ajax_referer('sil_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission refusée');
        }

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $new_title = isset($_POST['new_title']) ? sanitize_text_field($_POST['new_title']) : '';
        $new_meta = isset($_POST['new_meta']) ? sanitize_text_field($_POST['new_meta']) : '';

        if (!$post_id) {
            wp_send_json_error('ID manquant');
        }

        // Mise à jour du titre natif (H1)
        wp_update_post([
            'ID' => $post_id,
            'post_title' => $new_title
        ]);

        // Mise à jour des métadonnées RankMath
        update_post_meta($post_id, 'rank_math_title', $new_title);
        update_post_meta($post_id, 'rank_math_description', $new_meta);
        
        update_post_meta( $post_id, '_sil_last_seo_update', current_time( 'mysql' ) );

        wp_send_json_success(['message' => 'SEO RankMath mis à jour !']);
    }

    /**
     * AJAX: Search posts for linking (Autocomplete)
     */
    public function sil_search_posts_for_link()
    {
        check_ajax_referer('sil_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission refusée');
        }

        $search = isset($_POST['s']) ? sanitize_text_field($_POST['s']) : '';
        if (empty($search)) {
            wp_send_json_success([]);
        }

        $post_types = $this->main->get_post_types();

        $args = [
            'post_type' => $post_types,
            'post_status' => 'publish',
            's' => $search,
            'posts_per_page' => 10,
            'fields' => 'ids'
        ];

        $query = new WP_Query($args);
        $posts = $query->posts;

        $results = [];
        foreach ($posts as $post_id) {
            $gsc_data_json = get_post_meta($post_id, '_sil_gsc_data', true);
            $keywords = [];

            if ($gsc_data_json) {
                $gsc_data = is_array($gsc_data_json) ? $gsc_data_json : json_decode($gsc_data_json, true);
                if (empty($gsc_data) && is_string($gsc_data_json)) {
                    $gsc_data = function_exists('maybe_unserialize') ? maybe_unserialize($gsc_data_json) : unserialize($gsc_data_json);
                }

                // Extraction robuste des mots-clés
                $raw_queries = [];
                if (isset($gsc_data['top_queries'])) {
                    $raw_queries = $gsc_data['top_queries'];
                } elseif (is_array($gsc_data)) {
                    $raw_queries = $gsc_data;
                }

                if (!empty($raw_queries) && is_array($raw_queries)) {
                    // On prend les 5 premiers mots-clés qui ont du potentiel
                    foreach (array_slice($raw_queries, 0, 5) as $row) {
                        $kw = '';
                        if (isset($row['query'])) {
                            $kw = $row['query'];
                        } elseif (isset($row['keys']) && is_array($row['keys']) && isset($row['keys'][0])) {
                            $kw = $row['keys'][0];
                        }

                        if (!empty($kw)) {
                            // Décodage Unicode blindé (\u00e9 -> é)
                            $kw = preg_replace_callback('/(?:\\\\+)?u([0-9a-fA-F]{4})/', function ($match) {
                                return mb_convert_encoding(pack('H*', $match[1]), 'UTF-8', 'UCS-2BE');
                            }, $kw);
                            // Décodage HTML (&rsquo; -> ')
                            $keywords[] = wp_specialchars_decode($kw, ENT_QUOTES);
                        }
                    }
                }
            }

            // Nettoyage du titre avant de l'ajouter
            $clean_title = wp_specialchars_decode(get_the_title($post_id), ENT_QUOTES);
            $keywords[] = $clean_title;

            $results[] = [
                'id' => $post_id,
                'title' => $clean_title,
                'keywords' => array_values(array_unique(array_filter($keywords))) // Réindexe le tableau
            ];
        }

        wp_send_json_success($results);
    }

    /**
     * AJAX: Add internal link from map
     */
    public function sil_add_internal_link_from_map()
    {
        check_ajax_referer('sil_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission refusée');
        }

        $source_id = isset($_POST['source_id']) ? intval($_POST['source_id']) : 0;
        $target_id = isset($_POST['target_id']) ? intval($_POST['target_id']) : 0;
        $anchor = isset($_POST['anchor_text']) ? sanitize_text_field($_POST['anchor_text']) : '';

        if (!$source_id || !$target_id || !$anchor) {
            wp_send_json_error('Paramètres manquants');
        }

        $source_post = get_post($source_id);
        if (!$source_post) {
            wp_send_json_error('Source introuvable');
        }

        $target_url = get_permalink($target_id);
        if (!$target_url) {
            wp_send_json_error('Cible introuvable');
        }

        $content = $source_post->post_content;

        // Pattern qui cherche le texte uniquement s'il n'est pas déjà dans un lien <a> ou un titre <hX>
        $quoted_anchor = preg_quote($anchor, '/');
        $pattern = '/(?<!<a[^>]*>)(?<!<h[1-6][^>]*>)\b' . $quoted_anchor . '\b(?![^<]*<\/a>)(?![^<]*<\/h[1-6]>)/i';

        $new_content = preg_replace($pattern, '<a href="' . esc_url($target_url) . '">' . $anchor . '</a>', $content, 1, $count);

        if ($count === 0) {
            wp_send_json_error("Le texte d'ancre '" . $anchor . "' n'a pas été trouvé dans cet article. Essayez une autre suggestion.");
        }
        wp_update_post([
            'ID' => $source_id,
            'post_content' => $new_content
        ]);

        // Log in wp_sil_links
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'sil_links',
            [
                'source_id' => $source_id,
                'target_id' => $target_id,
                'target_url' => $target_url,
                'anchor' => $anchor
            ],
            ['%d', '%d', '%s', '%s']
        );

        $this->main->clear_graph_cache();
        wp_send_json_success('Lien ajouté avec succès');
    }

    /**
     * AJAX: Seal reciprocal link (1-click action)
     * Automatically finds best anchor and inserts link to Cornerstone.
     */
    public function sil_seal_reciprocal_link()
    {
        check_ajax_referer('sil_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission refusée');
        }

        $source_id = isset($_POST['source_id']) ? intval($_POST['source_id']) : 0;
        $target_id = isset($_POST['target_id']) ? intval($_POST['target_id']) : 0;

        if (!$source_id || !$target_id) {
            wp_send_json_error('Paramètres manquants');
        }

        $source_post = get_post($source_id);
        $target_post = get_post($target_id);

        if (!$source_post || !$target_post) {
            wp_send_json_error('Source ou Cible introuvable');
        }

        $target_url = get_permalink($target_id);
        $content = $source_post->post_content;

        // 1. Get Anchors (GSC > Title)
        $anchors = [];
        $gsc_data_json = get_post_meta($target_id, '_sil_gsc_data', true);
        if ($gsc_data_json) {
            $gsc_data = is_array($gsc_data_json) ? $gsc_data_json : json_decode($gsc_data_json, true);
            $rows = isset($gsc_data['top_queries']) ? $gsc_data['top_queries'] : $gsc_data;
            if (is_array($rows)) {
                foreach (array_slice($rows, 0, 5) as $r) {
                    $kw = isset($r['query']) ? $r['query'] : (isset($r['keys'][0]) ? $r['keys'][0] : '');
                    if ($kw) $anchors[] = $kw;
                }
            }
        }
        $anchors[] = $target_post->post_title;

        // 2. Try to inject on existing anchor
        $sealed = false;
        foreach ($anchors as $anchor) {
            $quoted_anchor = preg_quote($anchor, '/');
            $pattern = '/(?<!<a[^>]*>)(?<!<h[1-6][^>]*>)\b' . $quoted_anchor . '\b(?![^<]*<\/a>)(?![^<]*<\/h[1-6]>)/i';
            $new_content = preg_replace($pattern, '<a href="' . esc_url($target_url) . '">' . $anchor . '</a>', $content, 1, $count);
            
            if ($count > 0) {
                wp_update_post(['ID' => $source_id, 'post_content' => $new_content]);
                $sealed = true;
                break;
            }
        }

        // 3. Fallback: Append at end of first paragraph
        if (!$sealed) {
            $paragraphs = preg_split('/(\r\n|\n|\r)/', $content);
            $anchor = $anchors[0]; // Use best anchor
            foreach ($paragraphs as &$p) {
                if (trim($p) && strpos($p, '<p') === 0) {
                    $p = preg_replace('/<\/p>/', ' <a href="' . esc_url($target_url) . '">' . esc_html($anchor) . '</a>.</p>', $p, 1, $c);
                    if ($c > 0) {
                        $sealed = true;
                        break;
                    }
                }
            }
            if ($sealed) {
                wp_update_post(['ID' => $source_id, 'post_content' => implode("\n", $paragraphs)]);
            } else {
                // Last resort append
                $new_content = $content . "\n\n<p>En savoir plus sur : <a href=\"" . esc_url($target_url) . "\">" . esc_html($anchor) . "</a>.</p>";
                wp_update_post(['ID' => $source_id, 'post_content' => $new_content]);
                $sealed = true;
            }
        }

        // Log in sil_links
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'sil_links',
            [
                'source_id' => $source_id,
                'target_id' => $target_id,
                'target_url' => $target_url,
                'anchor' => $anchors[0]
            ],
            ['%d', '%d', '%s', '%s']
        );

        $this->main->clear_graph_cache();
        wp_send_json_success('Silo scellé avec succès !');
    }

    /**
     * AJAX: Endpoint de Génération de Prompt (AJAX)
     */
    public function sil_generate_bridge_prompt()
    {
        check_ajax_referer('sil_nonce', 'nonce');
        if (!current_user_can('edit_posts'))
            wp_send_json_error('Accès refusé.');

        $source_id = intval($_POST['source_id']);
        $target_id = intval($_POST['target_id']);
        $anchor_text = sanitize_text_field($_POST['anchor_text']);

        $post = get_post($source_id);
        $target_url = get_permalink($target_id);
        $content = $post->post_content;

        // 1. Découpage en paragraphes
        preg_match_all('/<p[^>]*>.*?<\/p>/is', $content, $matches, PREG_OFFSET_CAPTURE);
        if (empty($matches[0])) {
            wp_send_json_error("Aucun paragraphe standard (<p>) trouvé dans cet article.");
        }

        // 2. Préparation des mots du titre cible pour le scoring sémantique
        $target_title = wp_strip_all_tags(get_the_title($target_id));
        preg_match_all('/\b\w{4,}\b/u', mb_strtolower($target_title, 'UTF-8'), $title_words);
        $target_words = array_diff($title_words[0], ['pour', 'avec', 'dans', 'nous', 'vous', 'sont', 'être', 'plus', 'cette', 'tout']);

        // 3. Scoring des paragraphes
        $best_index = -1;
        $max_score = -1;
        foreach ($matches[0] as $index => $match) {
            $p_text = mb_strtolower(wp_strip_all_tags($match[0]), 'UTF-8');
            $score = 0;
            foreach ($target_words as $word) {
                if (mb_strpos($p_text, $word, 0, 'UTF-8') !== false)
                    $score++;
            }
            if ($score > $max_score) {
                $max_score = $score;
                $best_index = $index;
            }
        }
        // Si 0 score (aucun lien sémantique évident), on prend le dernier paragraphe (conclusion)
        if ($best_index === -1 || $max_score === 0) {
            $best_index = count($matches[0]) - 1;
        }

        // 4. Extraction du contexte (L'environnement HTML exact du paragraphe ciblé)
        $start_index = max(0, $best_index - 1);
        $end_index = min(count($matches[0]) - 1, $best_index + 1);

        $start_pos = $matches[0][$start_index][1];
        $end_pos = $matches[0][$end_index][1] + strlen($matches[0][$end_index][0]);

        $full_context_html = substr($content, $start_pos, $end_pos - $start_pos);

        // 5. Construction du Prompt Ultime (100% Dynamique)
        $ai_instructions = get_option('sil_openai_bridge_prompt');
        
        // Fallback minimal de sécurité si le champ est vide
        if (empty(trim($ai_instructions))) {
            $ai_instructions = "Insère ce lien naturellement et donne 3 propositions avec le code HTML.";
        }
        
        $link_html = "<a href='" . esc_url($target_url) . "'>" . esc_html($anchor_text) . "</a>";

        $final_prompt = "Voici une mission d'optimisation de maillage interne.\n\n";
        $final_prompt .= "🎯 LIEN À INSÉRER EXACTEMENT :\n`" . $link_html . "`\n\n";
        $final_prompt .= "🔍 TEXTE SOURCE (HTML) :\n" . $full_context_html . "\n\n";
        $final_prompt .= "📝 INSTRUCTIONS ET RÈGLES DE RÉDACTION :\n" . $ai_instructions;

        wp_send_json_success([
            'prompt' => $final_prompt,
            'original' => $full_context_html, // On renvoie bien le bloc complet pour le str_replace final
            'source_id' => $source_id,
            'target_id' => $target_id
        ]);
    }

    /**
     * AJAX: Endpoint de Sauvegarde Finale (AJAX)
     */
    public function sil_apply_anchor_context()
    {
        check_ajax_referer('sil_nonce', 'nonce');
        if (!current_user_can('edit_posts'))
            wp_send_json_error('Accès refusé.');

        $post_id = intval($_POST['source_id']);
        $target_id = intval($_POST['target_id']);
        $original_text = wp_unslash($_POST['original_text']);
        $final_text = wp_kses_post(wp_unslash($_POST['final_text']));

        $post = get_post($post_id);
        $new_content = str_replace($original_text, $final_text, $post->post_content);

        if ($new_content === $post->post_content) {
            wp_send_json_error('Le paragraphe original n\'a pas pu être localisé (possible divergence HTML). Le lien n\'a pas été inséré.');
            return;
        }

        wp_update_post(['ID' => $post_id, 'post_content' => $new_content]);

        // Optionnel : Ajouter le lien dans ta table personnalisée $wpdb->insert(...)
        global $wpdb;
        $target_url = get_permalink($target_id);
        $anchor = '';
        if (preg_match('/<a[^>]*>(.*?)<\/a>/i', $final_text, $match)) {
            $anchor = wp_strip_all_tags($match[1]);
        }
        $wpdb->insert(
            $wpdb->prefix . 'sil_links',
            [
                'source_id' => $post_id,
                'target_id' => $target_id,
                'target_url' => $target_url,
                'anchor' => $anchor
            ],
            ['%d', '%d', '%s', '%s']
        );
        if (method_exists($this->main, 'clear_graph_cache')) {
            $this->main->clear_graph_cache();
        }

        wp_send_json_success('Pont sémantique enregistré avec succès !');
    }

    /**
     * AJAX: Search posts (for link suggestions)
     */
    public function sil_search_posts()
    {
        check_ajax_referer('sil_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission refusée');
        }

        $search = isset($_POST['q']) ? sanitize_text_field($_POST['q']) : '';
        $args = [
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 10,
            's' => $search
        ];

        $posts = get_posts($args);
        $results = [];

        foreach ($posts as $p) {
            if (!is_object($p)) continue;
            $post_id = $p->ID;
            $gsc_data = get_post_meta($post_id, '_sil_gsc_data', true);
            $keywords = [];

            if ($gsc_data && is_array($gsc_data) && isset($gsc_data['top_queries'])) {
                foreach (array_slice($gsc_data['top_queries'], 0, 5) as $q) {
                    $keywords[] = isset($q['query']) ? $q['query'] : (isset($q['keys'][0]) ? $q['keys'][0] : '');
                }
            }

            // Nettoyage du titre avant de l'ajouter
            $clean_title = wp_specialchars_decode(get_the_title($post_id), ENT_QUOTES);
            $keywords[] = $clean_title;

            $results[] = [
                'id' => $post_id,
                'title' => $clean_title,
                'keywords' => array_values(array_unique(array_filter($keywords))) // Réindexe le tableau
            ];
        }

        wp_send_json_success($results);
    }


    /**
     * AJAX: Generate SEO Meta (Title & Description) using AI
     */
    public function sil_generate_seo_meta() {
        check_ajax_referer('sil_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Accès refusé');
        }

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        if (!$post_id) {
            wp_send_json_error('ID de post manquant');
        }

        $api_key = get_option('sil_openai_api_key');
        $model = get_option('sil_openai_model', 'gpt-4o');
        if ($model === 'custom') {
            $model = get_option('sil_openai_custom_model');
        }

        if (empty($api_key)) {
            wp_send_json_error('Clé API OpenAI manquante.');
        }

        $post_content = get_post_field('post_content', $post_id);
        if (empty($post_content)) {
            wp_send_json_error('Contenu de l\'article vide.');
        }

        $clean_content = wp_strip_all_tags($post_content);

        $base_prompt = get_option('sil_openai_seo_prompt');
        if (empty($base_prompt)) {
            $base_prompt = "Tu es un copywriter SEO expert. Ton objectif est d'optimiser le taux de clic (CTR) dans les résultats de recherche Google. Rédige un Titre SEO très accrocheur (maximum 60 caractères) et une Meta Description incitative (maximum 160 caractères).";
        }

        $system_prompt = $base_prompt . " Ton format de réponse DOIT être un objet JSON strict contenant exactement deux clés : 'title' et 'meta'.";

        $user_prompt = "Contenu de l'article :\n\n" . substr($clean_content, 0, 8000); 

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'timeout' => 45,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'model' => $model,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => $system_prompt],
                    ['role' => 'user', 'content' => $user_prompt]
                ]
            ])
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error('Erreur de connexion à OpenAI : ' . $response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($status_code !== 200) {
            $error_msg = isset($data['error']['message']) ? $data['error']['message'] : 'Erreur OpenAI (Code ' . $status_code . ')';
            wp_send_json_error($error_msg);
        }

        if (empty($data['choices'][0]['message']['content'])) {
            wp_send_json_error('Réponse vide de OpenAI.');
        }

        $result_json = json_decode($data['choices'][0]['message']['content'], true);

        if (!isset($result_json['title']) || !isset($result_json['meta'])) {
            wp_send_json_error('Format JSON invalide.');
        }

        // --- TEST MARKER ---
        wp_send_json_success([
            'title' => $result_json['title'],
            'meta' => $result_json['meta']
        ]);
    }

    /**
     * AJAX: Invention de lien par IA avec fallback
     */
    public function sil_invent_anchor_and_link()
    {
        check_ajax_referer('sil_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permissions insuffisantes.');
        }

        $source_id = isset($_POST['source_id']) ? intval($_POST['source_id']) : 0;
        $target_id = isset($_POST['target_id']) ? intval($_POST['target_id']) : 0;

        if (!$source_id || !$target_id) {
            wp_send_json_error('Paramètres manquants');
        }

        $api_key = get_option('sil_openai_api_key');
        $model = get_option('sil_openai_model', 'gpt-4o');
        if ($model === 'custom') {
            $model = get_option('sil_openai_custom_model');
        }

        if (empty($api_key)) {
            wp_send_json_error('Clé API OpenAI manquante dans les réglages');
        }

        $target_url = get_permalink($target_id);
        $target_title = get_the_title($target_id);
        $target_content = get_post_field('post_content', $target_id);

        $target_text = mb_substr(wp_strip_all_tags($target_content), 0, 3000);
        $source_content = get_post_field('post_content', $source_id);

        if (empty($source_content)) {
            wp_send_json_error('L\'article source est vide.');
        }

        $system_prompt = "Tu es un expert SEO en maillage interne. Ta mission est de créer un lien naturel entre deux articles.\n\n";
        $system_prompt .= "ARTICLE CIBLE :\nTitre: {$target_title}\nSujet: {$target_text}\n\n";
        $system_prompt .= "ARTICLE SOURCE (HTML) :\nTrouve le paragraphe le plus pertinent et insère exactement: <a href=\"{$target_url}\">Ton ancre optimisée</a>.\n";
        $system_prompt .= "Réponds en JSON avec 'original' (paragraphe exact) et 'rewritten' (modifié).";

        $user_prompt = "Voici le HTML source :\n\n" . $source_content;

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'timeout' => 45,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'model' => $model,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => $system_prompt],
                    ['role' => 'user', 'content' => $user_prompt]
                ]
            ])
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        $result_json = json_decode($data['choices'][0]['message']['content'], true);

        if (!isset($result_json['original']) || !isset($result_json['rewritten'])) {
            wp_send_json_error('L\'IA a échoué à formater le lien.');
        }

        $original = $result_json['original'];
        $rewritten = $result_json['rewritten'];

        if (strpos($source_content, $original) === false) {
             wp_send_json_error('L\'IA a altéré le texte original, remplacement impossible.');
        }

        $new_content = str_replace($original, $rewritten, $source_content);
        wp_update_post(['ID' => $source_id, 'post_content' => $new_content]);

        global $wpdb;
        preg_match('/<a[^>]*>(.*?)<\/a>/i', $rewritten, $matches);
        $anchor = isset($matches[1]) ? wp_strip_all_tags($matches[1]) : 'IA';

        $wpdb->insert($wpdb->prefix . 'sil_links', [
            'source_id' => $source_id,
            'target_id' => $target_id,
            'target_url' => $target_url,
            'anchor' => $anchor
        ], ['%d', '%d', '%s', '%s']);

        if (method_exists($this->main, 'clear_graph_cache')) {
            $this->main->clear_graph_cache();
        }

        wp_send_json_success('Lien inventé et inséré !');
    }

    /**
     * AJAX: Get Content Gap data with Deep Scan Intelligence 2026
     */
    public function sil_get_content_gap_data() {
        check_ajax_referer( 'sil_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Accès refusé' );
        }
        
        set_time_limit(120);
        global $wpdb;

        $min_imp = isset($_POST['min_impressions']) ? intval($_POST['min_impressions']) : 50;
        
        $all_posts = $wpdb->get_results("SELECT ID, post_title FROM $wpdb->posts WHERE post_status='publish' AND post_type='post'");
        $existing_titles = [];
        foreach($all_posts as $p) {
            $existing_titles[$p->ID] = mb_strtolower(wp_strip_all_tags(html_entity_decode($p->post_title)), 'UTF-8');
        }
        
        $metas = $wpdb->get_results("SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key='_sil_gsc_data'");
        
        $keywords_map = [];

        foreach ($metas as $m) {
            $data = json_decode($m->meta_value, true);
            if (!$data || !isset($data['top_queries']) || !is_array($data['top_queries'])) continue;

            foreach ($data['top_queries'] as $row) {
                $raw_kw = isset($row['query']) ? $row['query'] : (isset($row['keys'][0]) ? $row['keys'][0] : '');
                $kw = preg_replace_callback('/(?:\\\\+)?u([0-9a-fA-F]{4})/', function ($match) {
                    return mb_convert_encoding(pack('H*', $match[1]), 'UTF-8', 'UCS-2BE');
                }, $raw_kw);
                $kw = wp_specialchars_decode($kw, ENT_QUOTES);
                $kw_lower = mb_strtolower(trim($kw), 'UTF-8');
                if (empty($kw_lower) || strlen($kw_lower) < 5) continue;
                $pos = floatval($row['position']);
                $imp = intval($row['impressions']);
                if ($imp < $min_imp) continue;
                $already_in_title = false;
                foreach ($existing_titles as $t) {
                    if (strpos($t, $kw_lower) !== false) { $already_in_title = true; break; }
                }
                if ($already_in_title) continue;
                if (!isset($keywords_map[$kw_lower])) {
                    $keywords_map[$kw_lower] = [
                        'kw' => $kw,
                        'max_imp' => 0,
                        'best_pos' => 999,
                        'urls' => []
                    ];
                }
                $keywords_map[$kw_lower]['urls'][] = [
                    'id'    => $m->post_id,
                    'title' => get_the_title($m->post_id),
                    'url'   => get_permalink($m->post_id),
                    'path'  => str_replace(home_url(), '', get_permalink($m->post_id)),
                    'pos'   => round($pos, 1),
                    'edit'  => get_edit_post_link($m->post_id, 'raw')
                ];
                if ($imp > $keywords_map[$kw_lower]['max_imp']) $keywords_map[$kw_lower]['max_imp'] = $imp;
                if ($pos < $keywords_map[$kw_lower]['best_pos']) $keywords_map[$kw_lower]['best_pos'] = $pos;
            }
        }

        $results = ['striking' => [], 'consolidation' => [], 'true_gap' => []];
        foreach ($keywords_map as $item) {
            $pos = $item['best_pos'];
            $entry = [
                'kw' => $item['kw'],
                'imp' => $item['max_imp'],
                'pos' => $pos,
                'urls' => $item['urls']
            ];
            if ($pos >= 6 && $pos <= 15) {
                $results['striking'][] = $entry;
            } elseif ($pos > 15 && $pos <= 35) {
                $results['consolidation'][] = $entry;
            } elseif ($pos > 40) {
                $results['true_gap'][] = $entry;
            }
        }
        foreach($results as &$list) {
            usort($list, function($a, $b) { return $b['imp'] - $a['imp']; });
            $list = array_slice($list, 0, 20);
        }

        $results['silotage'] = [];
        $target_permeability = floatval(get_option('sil_target_permeability', 20));
        
        $graph_data = $this->main->get_rendered_graph_data();
        
        if (!empty($graph_data) && isset($graph_data['nodes'])) {
            $clusters_details = [];
            foreach ($graph_data['nodes'] as $node) {
                $cid = isset($node['cluster_id']) ? $node['cluster_id'] : '1';
                if (!isset($clusters_details[$cid])) {
                    $clusters_details[$cid] = [
                        'id' => $cid,
                        'permeability' => isset($node['cluster_permeability']) ? floatval($node['cluster_permeability']) : 0,
                        'target_auto' => isset($node['semantic_target']) && $node['semantic_target'] ? $node['semantic_target'] : null,
                        'posts' => []
                    ];
                }
                $clusters_details[$cid]['posts'][] = [
                    'id' => $node['id'],
                    'title' => get_the_title($node['id']),
                    'url' => isset($node['url']) ? $node['url'] : get_permalink($node['id']),
                    'pagerank' => isset($node['sil_pagerank']) ? $node['sil_pagerank'] : 0,
                    'is_strategic' => isset($node['is_strategic']) && $node['is_strategic'] === 'true'
                ];
            }
            foreach ($clusters_details as $cid => $cdata) {
                if ($cdata['permeability'] < $target_permeability && $cdata['target_auto']) {
                    $best_post = null;
                    usort($cdata['posts'], function($a, $b) { return $b['pagerank'] - $a['pagerank']; });
                    foreach ($cdata['posts'] as $p) {
                        if (!$p['is_strategic']) { $best_post = $p; break; }
                    }
                    if (!$best_post && count($cdata['posts']) > 0) {
                        $best_post = $cdata['posts'][0];
                    }
                    if ($best_post) {
                        $target_cluster_id = $cdata['target_auto'];
                        $target_post = null;
                        if (isset($clusters_details[$target_cluster_id])) {
                            $target_posts = $clusters_details[$target_cluster_id]['posts'];
                            usort($target_posts, function($a, $b) { return $b['pagerank'] - $a['pagerank']; });
                            $target_post = count($target_posts) > 0 ? $target_posts[0] : null;
                        }
                        if ($target_post) {
                            $results['silotage'][] = [
                                'cluster_id' => $cid,
                                'permeability' => $cdata['permeability'],
                                'target_permeability' => $target_permeability,
                                'target_cluster' => $target_cluster_id,
                                'source_post' => $best_post,
                                'target_post' => $target_post
                            ];
                        }
                    }
                }
            }
        }

        wp_send_json_success($results);
    }

    /**
     * Calcule le hash du contenu pour détecter les changements réels
     */
    private function generate_content_hash( $post_id ) {
        $post = get_post( $post_id );
        // On combine Titre + Contenu pour le hash
        return md5( $post->post_title . $post->post_content );
    }


    public function sil_run_system_diagnostic() {
        check_ajax_referer('sil_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Accès refusé.');

        try {
            global $wpdb;
            $report = [];

            // 1. Check Embeddings
            $total_posts = (int) $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_status='publish' AND post_type IN ('post', 'page')");
            $indexed_posts = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->main->table_name}");
            $report['embeddings'] = [
                'status' => ($indexed_posts >= $total_posts && $total_posts > 0) ? '✅' : '⚠️',
                'label'  => "Couverture Sémantique",
                'desc'   => "$indexed_posts / $total_posts articles indexés."
            ];

            // 2. Check GSC Data
            $gsc_table = $wpdb->prefix . 'sil_gsc_data';
            $gsc_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $gsc_table");
            $report['gsc'] = [
                'status' => ($gsc_count > 0) ? '✅' : '❌',
                'label'  => "Données Search Console",
                'desc'   => "$gsc_count articles avec métriques GSC actives."
            ];

            // 3. Check Topology (Links)
            $links = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sil_links");
            $report['topology'] = [
                'status' => ($links > $total_posts) ? '✅' : '⚖️',
                'label'  => "Densité Topologique",
                'desc'   => "$links liens internes suivis."
            ];

            wp_send_json_success($report);
        } catch (Throwable $e) {
            error_log("SIL Diagnostic Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            wp_send_json_error([
                'message' => 'Erreur lors du diagnostic système : ' . $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }


    // --- HELPERS MATHÉMATIQUES (TRI-FORCE) ---
    private function calculate_similarity_internal($v1, $v2) {
        if (!$v1 || !$v2 || count($v1) !== count($v2)) return 0;
        $dot = 0; $n1 = 0; $n2 = 0;
        for ($i=0; $i<count($v1); $i++) {
            $dot += $v1[$i] * $v2[$i];
            $n1 += $v1[$i] ** 2;
            $n2 += $v2[$i] ** 2;
        }
        return ($n1 && $n2) ? $dot / (sqrt($n1) * sqrt($n2)) : 0;
    }

    private function calculate_barycenter_internal($embs) {
        if (empty($embs)) return [];
        $count = count($embs);
        $dim = count($embs[0]);
        $bary = array_fill(0, $dim, 0);
        foreach ($embs as $e) {
            for ($i=0; $i<$dim; $i++) $bary[$i] += $e[$i];
        }
        return array_map(function($v) use ($count) { return $v / $count; }, $bary);
    }

    // --- ENDPOINTS TRI-FORCE ---
    public function sil_get_indexing_status() {
        check_ajax_referer( 'sil_nonce', 'nonce' );
        global $wpdb;

        $post_types = $this->main->get_post_types();
        $placeholders = implode(',', array_fill(0, count($post_types), '%s'));

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $wpdb->posts WHERE post_status='publish' AND post_type IN ($placeholders)",
            ...$post_types
        ));

        // Use the custom embeddings table instead of postmeta
        $table_embeddings = $this->main->table_name;
        $indexed = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_embeddings");

        wp_send_json_success([ 'total' => $total, 'indexed' => $indexed ]);
    }

    public function sil_index_embeddings_batch() {
        try {
            check_ajax_referer( 'sil_nonce', 'nonce' );
            global $wpdb;

            $api_key = get_option('sil_openai_api_key');
            if ( empty($api_key) ) wp_send_json_error('Clé OpenAI manquante.');

            $post_types = $this->main->get_post_types();
            $table_embeddings = $this->main->table_name;

            // Query posts that are NOT in the embeddings table
            $posts = get_posts([
                'post_type' => $post_types,
                'post_status' => 'publish',
                'posts_per_page' => 3,
                'fields' => 'ids'
            ]);

            // Manual filter because SQL JOIN is cleaner but get_posts is safer for WP hooks
            $indexed_ids = $wpdb->get_col("SELECT post_id FROM $table_embeddings");
            $to_index_ids = array_diff($posts, $indexed_ids);

            if ( empty($to_index_ids) ) wp_send_json_success(['processed' => 0, 'finished' => true]);

            // Process only a small batch
            $batch = array_slice($to_index_ids, 0, 3);
            foreach ($batch as $post_id) {
                $post = get_post($post_id);
                /** @var WP_Post $post */
                if ( ! ( $post instanceof WP_Post ) ) continue;

                $text = $post->post_title . "\n\n" . wp_strip_all_tags($post->post_content);
                $response = wp_remote_post('https://api.openai.com/v1/embeddings', [
                    'timeout' => 30,
                    'headers' => [ 'Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json' ],
                    'body' => json_encode(['model' => 'text-embedding-3-small', 'input' => mb_substr($text, 0, 6000)])
                ]);

                if ( ! is_wp_error($response) ) {
                    $body = json_decode(wp_remote_retrieve_body($response), true);
                    if ( isset($body['data'][0]['embedding']) ) {
                        update_post_meta($post->ID, '_sil_embedding', $body['data'][0]['embedding']);
                    }
                }
            }
            wp_send_json_success(['processed' => count($posts), 'finished' => false]);
        } catch (Throwable $e) {
            error_log("SIL Index Batch Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            wp_send_json_error([
                'message' => 'Erreur lors de l\'indexation par lot : ' . $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }

    private function calculate_cosine_similarity($v1, $v2) {
        $dot = 0; $n1 = 0; $n2 = 0;
        if (!is_array($v1) || !is_array($v2) || count($v1) !== count($v2)) return 0;
        foreach($v1 as $i => $v) { $dot += $v*$v2[$i]; $n1 += $v*$v; $n2 += $v2[$i]*$v2[$i]; }
        return ($n1 && $n2) ? $dot / (sqrt($n1)*sqrt($n2)) : 0;
    }

    private function calculate_barycenter($embs) {
        if (empty($embs)) return null;
        $dim = count($embs[0]); $count = count($embs); $bar = array_fill(0, $dim, 0);
        foreach($embs as $e) { foreach($e as $i => $v) $bar[$i] += $v; }
        foreach($bar as $i => $v) $bar[$i] = $v / $count;
        return $bar;
    }

    public function sil_run_semantic_audit() {
        try {
            check_ajax_referer('sil_nonce', 'nonce');
            set_time_limit(120);

            $posts = get_posts(['post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => -1]);
            $clusters = []; $post_data = [];

            foreach ($posts as $p) {
                /** @var WP_Post $p */
                if ( ! ( $p instanceof WP_Post ) ) continue;

                $cid = get_post_meta($p->ID, '_sil_cluster_id', true) ?: '1';
                $emb = get_post_meta($p->ID, '_sil_embedding', true);
                if ($emb && is_array($emb)) {
                    $clusters[$cid][] = $emb;
                    $post_data[$p->ID] = ['cid' => $cid, 'emb' => $emb];
                }
            }
            if (empty($clusters)) wp_send_json_error('Veuillez lancer l\'indexation sémantique.');

            $barycenters = [];
            foreach ($clusters as $cid => $embs) { $barycenters[$cid] = $this->calculate_barycenter($embs); }

            $intruders_count = 0;
            foreach ($post_data as $pid => $data) {
                $score = $this->calculate_cosine_similarity($data['emb'], $barycenters[$data['cid']]);
                update_post_meta($pid, '_sil_semantic_score', round($score, 4));

                $best_match = $data['cid']; $best_score = $score;
                foreach($barycenters as $cid => $bar) {
                    $s = $this->calculate_cosine_similarity($data['emb'], $bar);
                    if($s > $best_score + 0.05) { $best_match = $cid; $best_score = $s; } 
                }

                if($best_match != $data['cid']) {
                    update_post_meta($pid, '_sil_ideal_silo', $best_match);
                    $intruders_count++;
                } else {
                    delete_post_meta($pid, '_sil_ideal_silo');
                }
            }
            wp_send_json_success(['message' => "$intruders_count intrus détectés."]);
        } catch (Throwable $e) {
            error_log("SIL Audit Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            wp_send_json_error([
                'message' => 'Erreur lors de l\'audit sémantique : ' . $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }

    public function sil_run_deep_unit_tests() {
        check_ajax_referer('sil_nonce', 'nonce');
        $results = [];
        // Math Test
        $sim = $this->calculate_cosine_similarity([1,0],[1,0]);
        $results[] = [
            'name' => 'Calcul Cosinus', 
            'status' => ($sim == 1.0),
            'val' => 'Similarité 1:1 = ' . round($sim, 2)
        ];
        // GSC Test
        $raw = 'caf\u00e9';
        $dec = preg_replace_callback('/(?:\\\\+)?u([0-9a-fA-F]{4})/', function($m){
            return mb_convert_encoding(pack('H*',$m[1]),'UTF-8','UCS-2BE');
        }, $raw);
        $results[] = [
            'name' => 'Décodage GSC', 
            'status' => ($dec === 'café'),
            'val' => 'Input: ' . $raw . ' -> Output: ' . $dec
        ];
        
        wp_send_json_success($results);
    }

    /**
     * AJAX: Rebuild semantic silos using Fuzzy C-Means on OpenAI embeddings.
     * Requires manage_options capability.
     */
    public function sil_rebuild_semantic_silos() {
        check_ajax_referer('sil_nonce', 'nonce');
        if ( ! current_user_can('manage_options') ) {
            wp_send_json_error('Permission refusée');
        }

        try {
            @set_time_limit(300);

            $k = (int) get_option('sil_semantic_k', 6);
            if ( $k < 2 ) $k = 2;
            if ( $k > 20 ) $k = 20;

            $silos  = $this->main->semantic_silos;
            $result = $silos->rebuild_silos($k);

            if ( is_wp_error($result) ) {
                /** @var \WP_Error $result */
                wp_send_json_error($result->get_error_message());
            }

            // Invalidate graph cache to reflect new silos
            if (method_exists($this->main, 'clear_graph_cache')) {
                $this->main->clear_graph_cache();
            }

            wp_send_json_success([
                'message'           => sprintf(
                    '%d contenus traités en %d silos sémantiques. %d ponts détectés.',
                    $result['articles_processed'],
                    $result['silos_count'],
                    $result['bridges_count']
                ),
                'count'              => $result['articles_processed'], // Alias for admin.js
                'bridges'            => $result['bridges_count'],      // Alias for admin.js
                'articles_processed' => $result['articles_processed'],
                'silos_count'        => $result['silos_count'],
                'bridges_count'      => $result['bridges_count'],
            ]);
        } catch (Throwable $e) {
            error_log("SIL Rebuild Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            wp_send_json_error([
                'message' => 'Erreur lors de la reconstruction des silos : ' . $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }

    /**
     * AJAX: Get articles in the same semantic silo that do NOT currently link to target post.
     * Returns top suggestions sorted by cosine similarity descending.
     */
    public function sil_get_missing_inlinks() {
        check_ajax_referer('sil_nonce', 'nonce');
        if ( ! current_user_can('edit_posts') ) {
            wp_send_json_error('Permission refusée');
        }

        try {
            $target_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
            if ( ! $target_id ) {
                wp_send_json_error('ID manquant');
            }

            global $wpdb;
            $silos = $this->main->semantic_silos;

            // 1. Get target post's primary silo
            $primary_silo = $silos->get_primary_silo($target_id);
            if ( ! $primary_silo ) {
                wp_send_json_error('Cet article n\'a pas encore été assigné à un silo sémantique. Recalculez les silos d\'abord.');
            }

            // 2. Get all posts in the same silo (primary + bridges)
            $silo_members = $silos->get_silo_members($primary_silo, false);
            $silo_members = array_diff($silo_members, [$target_id]); // exclude self

            if ( empty($silo_members) ) {
                wp_send_json_success(['suggestions' => [], 'message' => 'Aucun autre article dans ce silo.']);
            }

            // 3. Detect which members already link to target_id
            $target_url     = get_permalink($target_id);
            $target_slug    = get_post_field('post_name', $target_id);
            $already_linked = [];

            foreach ( $silo_members as $member_id ) {
                $content = get_post_field('post_content', $member_id);
                if ( ! $content ) continue;
                // Check for link to target URL or slug
                if ( strpos($content, $target_url) !== false || strpos($content, '/' . $target_slug . '/') !== false ) {
                    $already_linked[] = $member_id;
                }
            }

            $candidates = array_diff($silo_members, $already_linked);

            if ( empty($candidates) ) {
                wp_send_json_success(['suggestions' => [], 'message' => 'Tous les articles du silo pointent déjà vers cet article. 🎉']);
            }

            // 4. Load target embedding
            $target_emb_raw = $wpdb->get_var($wpdb->prepare(
                "SELECT embedding FROM {$wpdb->prefix}sil_embeddings WHERE post_id = %d",
                $target_id
            ));

            if ( ! $target_emb_raw ) {
                // No embedding: return candidates sorted by silo score only
                $suggestions = [];
                foreach ( array_slice($candidates, 0, 10) as $cid ) {
                    $memberships = $silos->get_memberships($cid);
                    $suggestions[] = [
                        'id'         => $cid,
                        'title'      => get_the_title($cid),
                        'edit_url'   => get_edit_post_link($cid),
                        'similarity' => null,
                        'silo_score' => $memberships[$primary_silo] ?? 0,
                    ];
                }
                wp_send_json_success(['suggestions' => $suggestions, 'silo_id' => $primary_silo]);
            }

            $decoded_target = json_decode($target_emb_raw, true);
            if ( ! is_array($decoded_target) ) {
                wp_send_json_success(['suggestions' => [], 'message' => 'L\'analyse nécessite une regénération des tenseurs pour cet article.', 'silo_id' => $primary_silo]);
            }
            $target_vec = array_map('floatval', $decoded_target);

            // 5. Score all candidates by cosine similarity
            $scored = [];
            $chunk_size = 50;
            $candidate_chunks = array_chunk(array_values($candidates), $chunk_size);

            foreach ( $candidate_chunks as $chunk ) {
                $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT post_id, embedding FROM {$wpdb->prefix}sil_embeddings WHERE post_id IN ($placeholders)",
                    ...$chunk
                ));
                foreach ( $rows as $row ) {
                    $decoded_vec = json_decode($row->embedding, true);
                    if ( ! is_array($decoded_vec) ) continue;
                    $vec   = array_map('floatval', $decoded_vec);
                    $sim   = $this->calculate_cosine_similarity($target_vec, $vec);
                    $scored[(int) $row->post_id] = $sim;
                }
            }

            // Posts without embeddings get score 0
            foreach ( $candidates as $cid ) {
                if ( ! isset($scored[$cid]) ) $scored[$cid] = 0.0;
            }

            arsort($scored);
            $top = array_slice($scored, 0, 10, true);

            // 6. Build result
            $suggestions = [];
            foreach ( $top as $cid => $sim ) {
                $memberships = $silos->get_memberships($cid);
                $suggestions[] = [
                    'id'         => $cid,
                    'title'      => get_the_title($cid),
                    'edit_url'   => get_edit_post_link($cid),
                    'view_url'   => get_permalink($cid),
                    'similarity' => round($sim * 100, 1),
                    'silo_score' => round(($memberships[$primary_silo] ?? 0) * 100, 1),
                    'is_bridge'  => isset($memberships[$primary_silo]) && count($memberships) > 1
                                    && array_sum($memberships) - ($memberships[$primary_silo] ?? 0) >= 0.30,
                ];
            }

            wp_send_json_success([
                'suggestions' => $suggestions,
                'silo_id'     => $primary_silo,
                'silo_label'  => $silos->get_silo_labels()[$primary_silo] ?? 'Silo ' . $primary_silo,
            ]);
        } catch (Throwable $e) {
            error_log("SIL Inlinks Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            wp_send_json_error([
                'message' => 'Erreur lors de la recherche de maillage : ' . $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }
}

