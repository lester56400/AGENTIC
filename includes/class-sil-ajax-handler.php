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
    public function init()
    {
        // --- Core Actions ---
        add_action('wp_ajax_sil_generate_links', [$this->main, 'ajax_generate_links']);
        add_action('wp_ajax_sil_apply_selected_links', [$this, 'sil_insert_internal_link']);
        add_action('wp_ajax_sil_regenerate_embeddings', [$this->main, 'ajax_regenerate_embeddings']);
        add_action('wp_ajax_sil_get_stats', [$this->main, 'ajax_get_stats']);
        add_action('wp_ajax_sil_reset_no_match', [$this->main, 'ajax_reset_no_match']);
        add_action('wp_ajax_sil_check_links_health', [$this->main, 'ajax_check_links_health']);
        add_action('wp_ajax_sil_refresh_index_status', [$this->main, 'ajax_refresh_index_status']);
        
        // --- Graph & Visualisation ---
        add_action('wp_ajax_sil_gsc_oauth_callback', [$this, 'handle_gsc_oauth_callback']);
        add_action('wp_ajax_sil_gsc_oauth_redirect', [$this, 'handle_gsc_oauth_redirect']);
        add_action('wp_ajax_sil_get_graph_data', [$this, 'sil_get_graph_data']);
        add_action('wp_ajax_sil_get_node_details', [$this, 'sil_get_node_details']);
        add_action('wp_ajax_sil_get_edge_context', [$this, 'sil_get_edge_context']);
        add_action('wp_ajax_sil_toggle_edge_nofollow', [$this, 'sil_toggle_edge_nofollow']);
        add_action('wp_ajax_sil_delete_edge_link', [$this, 'sil_delete_edge_link']);
        add_action('wp_ajax_sil_remove_link', [$this, 'sil_delete_edge_link']); // Alias
        add_action('wp_ajax_sil_remove_internal_link', [$this, 'sil_remove_internal_link']);
        add_action('wp_ajax_sil_add_internal_link_from_map', [$this, 'sil_add_internal_link_from_map']);
        
        // --- GSC & Pilotage ---
        add_action('wp_ajax_sil_force_gsc_sync_batch', [$this, 'sil_process_batch']);
        add_action('wp_ajax_sil_get_all_ids_for_gsc_sync', [$this->main, 'ajax_get_all_ids_for_gsc_sync']);
        add_action('wp_ajax_sil_get_pilotage_actions', [$this, 'sil_get_pilotage_actions']);
        add_action('wp_ajax_sil_log_manual_action', [$this, 'sil_log_manual_action']);
        add_action('wp_ajax_sil_get_action_logs', [$this, 'sil_get_action_logs']);
        add_action('wp_ajax_sil_get_orphan_adoption_info', [$this, 'sil_get_orphan_adoption_info']);
        add_action('wp_ajax_sil_adopt_orphan', [$this, 'sil_adopt_orphan']);
        
        // --- AI & Semantic ---
        add_action('wp_ajax_sil_ai_seo_rewrite', [$this, 'sil_ai_seo_rewrite']);
        add_action('wp_ajax_sil_generate_bridge_prompt', [$this, 'sil_generate_bridge_prompt']);
        add_action('wp_ajax_sil_apply_anchor_context', [$this, 'sil_apply_anchor_context']);
        add_action('wp_ajax_sil_get_missing_inlinks', [$this, 'sil_get_missing_inlinks']);
        add_action('wp_ajax_sil_rebuild_semantic_silos', [$this, 'sil_rebuild_semantic_silos']);
        add_action('wp_ajax_sil_seal_reciprocal_link', [$this, 'sil_seal_reciprocal_link']);
        add_action('wp_ajax_sil_run_semantic_audit', [$this, 'sil_run_semantic_audit']);
        
        // --- Settings & Metadata ---
        add_action('wp_ajax_sil_save_cornerstone', [$this, 'sil_save_settings']);
        add_action('wp_ajax_sil_update_seo_meta', [$this, 'sil_update_seo_meta']);
        add_action('wp_ajax_sil_search_posts_for_link', [$this, 'sil_search_posts_for_link']);
        add_action('wp_ajax_sil_save_checklist_item', [$this->main, 'ajax_save_checklist_item']);
        
        // --- System & Tests ---
        add_action('wp_ajax_sil_index_embeddings_batch', [$this, 'sil_index_embeddings_batch']);
        add_action('wp_ajax_sil_get_indexing_status', [$this, 'sil_get_indexing_status']);
        add_action('wp_ajax_sil_run_system_diagnostic', [$this, 'sil_run_system_diagnostic']);
        add_action('wp_ajax_sil_run_deep_unit_tests', [$this, 'sil_run_deep_unit_tests']);
        
        // --- V17 Arsenal ---
        add_action('wp_ajax_sil_v17_expert_action', [$this, 'sil_v17_expert_action']);

        // --- Tracking ---
        add_action('wp_ajax_sil_track_click', [$this, 'sil_track_click']);
        add_action('wp_ajax_nopriv_sil_track_click', [$this, 'sil_track_click']);

        // --- GSC Analysis (Content Gap) ---
        add_action('wp_ajax_sil_get_content_gap_data', [$this, 'sil_get_content_gap_data']);
    }

    /**
     * AJAX: Save settings (Cornerstone status)
     * Maps to requested sil_save_settings
     */
    public function sil_save_settings()
    {
        check_ajax_referer('sil_nonce', 'nonce');

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
        check_ajax_referer('sil_nonce', 'nonce');
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

            // ROI Logging v2.5
            if (class_exists('SIL_Action_Logger')) {
                SIL_Action_Logger::log_action('adopt', $candidate_id, $post_id, ['anchor' => $anchor]);
            }
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
        check_ajax_referer('sil_nonce', 'nonce');
        
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
            if (!empty($cluster_posts)) {
                $post_ids = array_column($cluster_posts, 'post_id');
                $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
                $meta_results = $wpdb->get_results($wpdb->prepare(
                    "SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_sil_embedding' AND post_id IN ($placeholders)",
                    ...$post_ids
                ));
                foreach ($meta_results as $mr) {
                    $e = maybe_unserialize($mr->meta_value);
                    if ($e && is_array($e)) $cluster_embs[] = $e;
                }
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
                if (!empty($candidates_raw)) {
                    $c_ids = array_column($candidates_raw, 'post_id');
                    $placeholders = implode(',', array_fill(0, count($c_ids), '%d'));
                    $meta_results = $wpdb->get_results($wpdb->prepare(
                        "SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_sil_embedding' AND post_id IN ($placeholders)",
                        ...$c_ids
                    ));
                    foreach ($meta_results as $mr) {
                        $ce = maybe_unserialize($mr->meta_value);
                        if ($ce && is_array($ce)) $candidates[$mr->post_id] = $ce;
                    }
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
            'edit_url' => admin_url( 'post.php?post=' . intval($post_id) . '&action=edit' ),
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
     * AJAX: Hybrid Search (Keyword + Semantic) for Direct Linking.
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

        global $wpdb;
        $post_types = $this->main->get_post_types();
        $post_types_sql = "'" . implode("','", array_map('esc_sql', $post_types)) . "'";

        // --- 1. Keyword Search (SQL Multi-Term) - High Priority ---
        $words = explode(' ', $search);
        $words = array_filter($words, function($w) { return strlen($w) >= 2; });

        if (empty($words)) {
            $words = [$search];
        }

        $sql = "SELECT ID, post_title FROM $wpdb->posts WHERE post_type IN ($post_types_sql) AND post_status = 'publish'";
        foreach ($words as $word) {
            $sql .= $wpdb->prepare(" AND post_title LIKE %s", '%' . $wpdb->esc_like($word) . '%');
        }
        $sql .= " LIMIT 20";

        $keyword_results = $wpdb->get_results($sql);

        $hybrid_results = [];
        $seen_ids = [];

        foreach ($keyword_results as $p) {
            $hybrid_results[$p->ID] = [
                'id' => $p->ID,
                'title' => wp_specialchars_decode($p->post_title, ENT_QUOTES),
                'confidence' => 100,
                'match_type' => 'keyword'
            ];
            $seen_ids[] = $p->ID;
        }

        // --- 2. Semantic Search (Vector) - AI Discovery ---
        $api_key = get_option('sil_openai_api_key');
        if (!empty($api_key) && strlen($search) >= 3) {
            $query_embeddings = \SIL_Centrality_Engine::batch_get_embeddings([$search], $api_key);
            if (!empty($query_embeddings)) {
                $query_vec = $query_embeddings[0];
                
                require_once SIL_PLUGIN_DIR . 'includes/class-sil-semantic-silos.php';
                $silo_engine = new \Sil_Semantic_Silos();
                $all_embeddings = $silo_engine->load_embeddings();

                foreach ($all_embeddings as $post_id => $vec) {
                    if (in_array($post_id, $seen_ids)) continue;

                    $similarity = \SIL_Centrality_Engine::get_representativeness_score($query_vec, $vec);
                    if ($similarity >= 0.85) {
                        $hybrid_results[$post_id] = [
                            'id' => $post_id,
                            'title' => wp_specialchars_decode(get_the_title($post_id), ENT_QUOTES),
                            'confidence' => round($similarity * 100, 1),
                            'match_type' => 'semantic'
                        ];
                    }
                }
            }
        }

        if (empty($hybrid_results)) {
            wp_send_json_success([]);
        }

        // Sort by confidence
        uasort($hybrid_results, function($a, $b) { 
            return $b['confidence'] <=> $a['confidence']; 
        });

        $candidate_ids = array_keys($hybrid_results);
        $candidate_ids = array_slice($candidate_ids, 0, 15);

        // 4. Decoration (GSC and URL)
        $gsc_performance = \SIL_SEO_Utils::get_bulk_gsc_performance($candidate_ids);
        $megaphone_threshold = \SIL_SEO_Utils::get_megaphone_threshold();

        $results = [];
        foreach ($candidate_ids as $pid) {
            $data = $hybrid_results[$pid];
            $perf = $gsc_performance[$pid] ?? ['impressions' => 0];
            $imps = intval($perf['impressions']);

            $results[] = [
                'id' => $pid,
                'title' => $data['title'],
                'confidence' => $data['confidence'],
                'match_type' => $data['match_type'],
                'is_megaphone' => $imps >= $megaphone_threshold,
                'impressions' => $imps,
                'url' => get_permalink($pid)
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

        // ROI Logging — Actions from Cartographie now appear in Journal
        if (class_exists('SIL_Action_Logger')) {
            SIL_Action_Logger::log_action('link_map', $source_id, $target_id, ['anchor' => $anchor]);
        }

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

    /**    /**
     * AJAX: Generate Bridge Prompt
     */
    public function sil_generate_bridge_prompt() {
        check_ajax_referer( 'sil_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Accès restreint aux administrateurs.' );
        }

        $source_id   = intval( $_POST['source_id'] );
        $target_id   = intval( $_POST['target_id'] );
        $p_index     = isset($_POST['p_index']) ? intval($_POST['p_index']) : -1;
        $anchor_text = sanitize_text_field( $_POST['anchor_text'] ?? $_POST['anchor'] ?? '' );
        $note        = sanitize_textarea_field( $_POST['note'] ?? '' );

        $source_post = get_post( $source_id );
        if ( ! $source_post ) {
            wp_send_json_error( 'Source introuvable' );
        }

        $target_post = get_post( $target_id );
        if ( ! $target_post ) {
            wp_send_json_error( 'Cible introuvable' );
        }

        $target_url   = get_permalink( $target_id );
        $target_title = $target_post->post_title;
        $source_title = $source_post->post_title;

        // --- GESTION DE L'ANCRE ---
        if ( empty( $anchor_text ) ) {
            // Fallback : 4 premiers mots du titre cible
            $words = explode( ' ', $target_title );
            $anchor_text = implode( ' ', array_slice( $words, 0, 4 ) );
        }

        $content = $source_post->post_content;
        
        // Nettoyage HTML pour le prompt (Gutenberg blocks comments etc)
        $clean_content = preg_replace('/<!--(.|\s)*?-->/', '', $content);
        
        preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $clean_content, $matches);
        
        if (empty($matches[0])) {
            $paragraphs = array("<p>" . wp_strip_all_tags($clean_content) . "</p>");
            $total_p = 1;
        } else {
            $paragraphs = $matches[0];
            $total_p = count($paragraphs);
        }

        // Si p_index est -1, choisir un index au milieu
        if ($p_index === -1) {
            $p_index = ($total_p > 2) ? rand(1, $total_p - 2) : 0;
        }

        // --- NETTOYAGE SÉLECTIF DU PARAGRAPHE CIBLE ---
        // Conserver uniquement les balises de formatage : <strong>, <em>, <b>, <i>, <br>
        $selected_html = $paragraphs[$p_index];
        $selected_clean = strip_tags($selected_html, '<strong><em><b><i><br>');

        // --- EXTRACTION CONTEXTE (500 chars avant/après) ---
        // On se base sur le texte brut pour le contexte
        $all_text = wp_strip_all_tags($clean_content);
        $para_text = wp_strip_all_tags($selected_html);
        
        $para_pos = strpos($all_text, $para_text);
        if ($para_pos === false) {
            // Fallback si le texte brut ne correspond pas exactement (rare)
            $prev_context = ($p_index > 0) ? wp_strip_all_tags($paragraphs[$p_index - 1]) : '';
            $next_context = ($p_index < $total_p - 1) ? wp_strip_all_tags($paragraphs[$p_index + 1]) : '';
        } else {
            $start = max(0, $para_pos - 500);
            $prev_context = trim(substr($all_text, $start, $para_pos - $start));
            
            $next_start = $para_pos + strlen($para_text);
            $next_context = trim(substr($all_text, $next_start, 500));
        }
        
        $link_html = "<a href=\"{$target_url}\">{$anchor_text}</a>";

        // Prompt riche
        $user_prompt = get_option( 'sil_openai_bridge_prompt' );
        if ( empty( $user_prompt ) ) {
            $user_prompt = "Tu es un expert SEO en maillage interne. Ta mission est d'insérer un lien interne de façon fluide et naturelle.";
        }

        // Substitution des variables
        $vars = [
            '{{anchor}}'       => $anchor_text,
            '{{target_url}}'   => $target_url,
            '{{url}}'          => $target_url,
            '{{link}}'         => $link_html,
            '{{target_title}}' => $target_title,
            '{{source_title}}' => $source_title,
            '{{note}}'         => $note
        ];
        $user_prompt = str_replace( array_keys( $vars ), array_values( $vars ), $user_prompt );

        $prompt = "=== MISSION SEO ===\n{$user_prompt}\n\n";
        
        if (!empty($note)) {
            $prompt .= "📝 NOTE CONTEXTUELLE : {$note}\n\n";
        }

        $prompt .= "🎯 LIEN À INSÉRER :\n`{$link_html}`\n\n";
        
        $prompt .= "=== CONTEXTE DE L'ARTICLE (500 chars) ===\n";
        $prompt .= "PRÉCÉDENT : ...{$prev_context}\n\n";
        $prompt .= "📍 PARAGRAPHE CIBLE À MODIFIER :\n{$selected_clean}\n\n";
        $prompt .= "SUIVANT : {$next_context}...\n\n";

        $prompt .= "=== RÈGLES STRICTES ===\n";
        $prompt .= "1. Renvoye uniquement le paragraphe modifié (bloc <p>...</p>).\n";
        $prompt .= "2. Intègre le lien `{$link_html}` de manière ultra-naturelle dans le flux.\n";
        $prompt .= "3. Préserve les balises existantes dans le paragraphe cible (" . esc_html('<strong>, <em>, etc.') . ").\n";
        $prompt .= "4. Propose 3 VARIANTES distinctes.\n";

        wp_send_json_success([
            'prompt'        => $prompt,
            'selected_html' => $selected_clean, // On renvoie la version nettoyée sélectivement
            'original'      => $selected_html,  // Version brute pour le remplacement final
            'p_index'       => $p_index,
            'total_p'       => $total_p,
            'source_id'     => $source_id,
            'target_id'     => $target_id,
            'source_title'  => $source_title,
            'target_title'  => $target_title,
            'anchor'        => $anchor_text,
            'anchor_text'   => $anchor_text
        ]);
    }

    /**
     * AJAX: Endpoint de Sauvegarde Finale (AJAX)
     */
    public function sil_apply_anchor_context()
    {
        check_ajax_referer('sil_admin_nonce', 'nonce');
        if (!current_user_can('manage_options'))
            wp_send_json_error('Accès restreint aux administrateurs.');

        $post_id = intval($_POST['source_id']);
        $target_id = intval($_POST['target_id']);
        $original_text = wp_unslash($_POST['original_text']);
        $final_text = wp_unslash($_POST['final_text']);

        // Validation HTML Syntax (on the raw output from IA, before Gutenberg wrapping)
        $text_for_validation = str_replace(["\r\n", "\r", "\n"], ' ', $final_text);
        $text_for_validation = preg_replace('/<\/p>\s*<p[^>]*>/i', '<br />', $text_for_validation);
        $inner_content_raw = preg_replace('/^<p[^>]*>|<\/p>$/i', '', trim($text_for_validation));
        
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $html_wrapper = '<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>' . $inner_content_raw . '</body></html>';
        $dom->loadHTML($html_wrapper, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $critical_errors = array_filter(libxml_get_errors(), function($e) { return $e->level === LIBXML_ERR_FATAL; });
        libxml_clear_errors();
        
        if (!empty($critical_errors)) {
            wp_send_json_error("Syntaxe HTML invalide renvoyée par l'IA.");
            return;
        }

        if (strpos($final_text, '<a') === false) {
            wp_send_json_error("Le lien HTML <a> a été perdu durant la réécriture.");
            return;
        }


        if (!current_user_can('unfiltered_html')) {
            $final_text = wp_kses_post($final_text);
            $inner_content_raw = wp_kses_post($inner_content_raw);
        }

        $post = get_post($post_id);
        $haystack = $post->post_content;
        $needle = trim($original_text);

        if (empty($needle)) {
            wp_send_json_error("Erreur critique de sécurité : Le texte original fourni est vide. L'insertion a été annulée pour éviter d'écraser le début de votre article.");
            return;
        }

        $new_content = $haystack;
        $pos = strpos($haystack, $needle);
        $found_needle = false;
        
        // Surgical replacement (Limit 1)
        if ($pos !== false) {
             $found_needle = $needle;
        } else {
             // Fallback robust matching (Entités, espaces, quotes)
            $needle_clean = html_entity_decode($needle, ENT_QUOTES, 'UTF-8');
            $haystack_decoded = html_entity_decode($haystack, ENT_QUOTES, 'UTF-8');
            
            $pos_decoded = mb_strpos($haystack_decoded, $needle_clean, 0, 'UTF-8');
            if ($pos_decoded !== false) {
                $regex_needle = preg_quote($needle_clean, '/');
                $regex_needle = str_replace(["'", '"'], ["(?:'|&rsquo;|&apos;|&#039;)", '(?:"|&quot;|&#034;)'], $regex_needle);
                // We need the ACTUAL matched string in haystack to check its surroundings.
                if (preg_match('/' . $regex_needle . '/us', $haystack, $matches, PREG_OFFSET_CAPTURE)) {
                    $found_needle = $matches[0][0];
                    $pos = $matches[0][1];
                }
            }
        }

        if ($found_needle === false) {
             wp_send_json_error('Le paragraphe original n\'a pas pu être localisé dans le contenu (possible divergence de formatage ou entités HTML). Le lien n\'a pas été inséré.');
             return;
        }
        
        // Now check if the needle is already wrapped in a Gutenberg paragraph block block.
        // We look backwards from $pos to see if there's an opening tag before another closing tag.
        $text_before = substr($haystack, 0, $pos);
        $last_wp_open = strrpos($text_before, '<!-- wp:paragraph -->');
        $last_wp_close = strrpos($text_before, '<!-- /wp:paragraph -->');
        
        $is_wrapped = false;
        $replace_start = $pos;
        $replace_length = strlen($found_needle);
        
        if ($last_wp_open !== false && ($last_wp_close === false || $last_wp_open > $last_wp_close)) {
             // It's inside a block! Let's find the closing tag.
             $text_after = substr($haystack, $pos + strlen($found_needle));
             $next_wp_close = strpos($text_after, '<!-- /wp:paragraph -->');
             if ($next_wp_close !== false) {
                 $is_wrapped = true;
                 $replace_start = $last_wp_open;
                 $replace_length = ($pos + strlen($found_needle) + $next_wp_close + strlen('<!-- /wp:paragraph -->')) - $last_wp_open;
             }
        }

        // Prepare the final replacement string.
        $replacement = "<!-- wp:paragraph -->\n<p>" . $inner_content_raw . "</p>\n<!-- /wp:paragraph -->";
        
        $new_content = substr_replace($haystack, $replacement, $replace_start, $replace_length);


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

        // ROI Logging v2.5
        if (class_exists('SIL_Action_Logger')) {
            SIL_Action_Logger::log_action('bridge', $post_id, $target_id, ['anchor' => $anchor]);
        }
        if (method_exists($this->main, 'clear_graph_cache')) {
            $this->main->clear_graph_cache();
        }

        // Mise à jour de l'incubateur : on passe la tâche en 'completed'
        $wpdb->update(
            $wpdb->prefix . 'sil_scheduled_links',
            ['status' => 'completed'],
            ['source_id' => $post_id, 'target_id' => $target_id, 'status' => 'pending'],
            ['%s'],
            ['%d', '%d', '%s']
        );

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
        $post_status = isset($_POST['post_status']) && is_array($_POST['post_status']) 
            ? array_map('sanitize_text_field', $_POST['post_status']) 
            : ['publish'];

        $args = [
            'post_type' => $this->main->get_post_types(),
            'post_status' => $post_status,
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


    public function sil_get_content_gap_data() {
        check_ajax_referer( 'sil_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Accès refusé' );
        }
        
        set_time_limit(120);
        global $wpdb;

        $min_imp = isset($_POST['min_impressions']) ? intval($_POST['min_impressions']) : 50;
        
        // --- OPTIMIZATION (v2.5.5) ---
        // 1. Single JOIN query to fetch Title + GSC Data for published posts only
        $results_sql = $wpdb->get_results("
            SELECT p.ID, p.post_title, pm.meta_value 
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_status = 'publish' 
              AND p.post_type = 'post'
              AND pm.meta_key = '_sil_gsc_data'
        ");

        if (empty($results_sql)) {
            wp_send_json_success(['striking' => [], 'consolidation' => [], 'true_gap' => []]);
            return;
        }

        // 2. Build HashSet of lowercase titles for O(1) lookup
        $titles_hashset = [];
        $post_titles_map = [];
        foreach($results_sql as $row) {
            $t = mb_strtolower(wp_strip_all_tags(html_entity_decode($row->post_title)), 'UTF-8');
            $titles_hashset[$t] = true;
            $post_titles_map[$row->ID] = $row->post_title;
        }
        
        $keywords_map = [];
        require_once SIL_PLUGIN_DIR . 'includes/class-sil-seo-utils.php';

        foreach ($results_sql as $m) {
            $data = json_decode($m->meta_value, true);
            if (!$data || !isset($data['top_queries']) || !is_array($data['top_queries'])) continue;

            $post_v17 = \SIL_SEO_Utils::calculate_v17_indicators($m->ID, $data);
            $p_title = $m->post_title;
            $p_url = get_permalink($m->ID);
            $p_path = str_replace(home_url(), '', $p_url);

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

                // 3. Fast O(1) HashSet check
                if (isset($titles_hashset[$kw_lower])) continue;

                if (!isset($keywords_map[$kw_lower])) {
                    $keywords_map[$kw_lower] = [
                        'kw' => $kw,
                        'max_imp' => 0,
                        'best_pos' => 999,
                        'urls' => []
                    ];
                }
                $keywords_map[$kw_lower]['urls'][] = [
                    'id'    => $m->ID,
                    'title' => $p_title,
                    'url'   => $p_url,
                    'path'  => $p_path,
                    'pos'   => round($pos, 1),
                    'edit'  => admin_url( 'post.php?post=' . intval($m->ID) . '&action=edit' ),
                    'is_decay_critical' => $post_v17['is_decay_critical'],
                    'is_new_content'    => $post_v17['is_new_content']
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

            // 2. Check GSC Data (Refined to avoid false positives)
            $tokens = get_option('sil_gsc_oauth_tokens');
            $gsc_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sil_gsc_data");
            
            $gsc_status = '❌';
            $gsc_desc = "Non connectée";
            
            if ($tokens) {
                if ($gsc_count > 0) {
                    $gsc_status = '✅';
                    $gsc_desc = "$gsc_count articles synchronisés.";
                } else {
                    $gsc_status = '⚖️';
                    $gsc_desc = "Connecté, importation en attente.";
                }
            }

            $report['gsc'] = [
                'status' => $gsc_status,
                'label'  => "Données Search Console",
                'desc'   => $gsc_desc
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

            global $wpdb;
            $results = $wpdb->get_results("
                SELECT p.ID, 
                       m1.meta_value as cluster_id, 
                       m2.meta_value as embedding
                FROM $wpdb->posts p
                LEFT JOIN $wpdb->postmeta m1 ON p.ID = m1.post_id AND m1.meta_key = '_sil_cluster_id'
                LEFT JOIN $wpdb->postmeta m2 ON p.ID = m2.post_id AND m2.meta_key = '_sil_embedding'
                WHERE p.post_type = 'post' AND p.post_status = 'publish'
            ");

            $clusters = []; $post_data = [];
            foreach ($results as $r) {
                $cid = $r->cluster_id ?: '1';
                $emb = maybe_unserialize($r->embedding);
                if ($emb && is_array($emb)) {
                    $clusters[$cid][] = $emb;
                    $post_data[$r->ID] = ['cid' => $cid, 'emb' => $emb];
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

    
    /**
     * AJAX: Handle GSC OAuth Redirect (Starts flow)
     */
    public function handle_gsc_oauth_redirect() {
        check_ajax_referer('sil_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_die('Accès refusé');
        }

        require_once SIL_PLUGIN_DIR . 'includes/class-sil-gsc-handler.php';
        $handler = new Sil_Gsc_Handler();
        $auth_url = $handler->get_auth_url();

        if ($auth_url) {
            wp_redirect($auth_url);
            exit;
        } else {
            wp_die('Impossible de générer l\'URL d\'authentification.');
        }
    }

    /**
     * AJAX: Handle GSC OAuth Callback (Success redirect from Google)
     */
    public function handle_gsc_oauth_callback() {
        if (!current_user_can('manage_options')) {
            wp_die('Accès refusé');
        }

        if (!isset($_GET['code'])) {
            wp_die('Code d\'autorisation manquant Google Search Console.');
        }

        require_once SIL_PLUGIN_DIR . 'includes/class-sil-gsc-handler.php';
        
        try {
            $handler = new Sil_Gsc_Handler();
            $tokens = $handler->fetch_access_token_with_auth_code(sanitize_text_field($_GET['code']));
            
            if ($tokens && isset($tokens['access_token'])) {
                update_option('sil_gsc_oauth_tokens', $tokens);
                wp_redirect(admin_url('admin.php?page=sil-gsc-settings&gsc_auth=success'));
                exit;
            } else {
                wp_die("Erreur lors de la récupération des jetons d'accès.");
            }
        } catch (Exception $e) {
            wp_die('Erreur OAuth : ' . esc_html($e->getMessage()));
        }
    }

    public function sil_run_deep_unit_tests() {
        check_ajax_referer('sil_nonce', 'nonce');
        $results = [];

        // 1. Math Test (Standard)
        $sim = $this->calculate_cosine_similarity([1,0],[1,0]);
        $results[] = [
            'name' => 'Précision Sémantique (Math)', 
            'status' => ($sim == 1.0),
            'val' => 'Cosinus 1:1 = ' . round($sim, 2) . ' (Normal)'
        ];

        // 2. GSC Decoding Test
        $complex_raw = 'H\u00f4tel &amp; Caf\u00e9';
        $complex_dec = html_entity_decode(preg_replace_callback('/(?:\\\\+)?u([0-9a-fA-F]{4})/', function($m){
            return mb_convert_encoding(pack('H*',$m[1]),'UTF-8','UCS-2BE');
        }, $complex_raw), ENT_QUOTES);
        $results[] = [
            'name' => 'Décodage GSC (UTF-8)',
            'status' => ($complex_dec === 'Hôtel & Café'),
            'val' => 'Input: ' . $complex_raw . ' -> OK'
        ];

        // --- ENVIRONMENT & DATA QUALITY TESTS (v2.5.9) ---

        // 3. Memory Limit Audit
        $mem_limit = ini_get('memory_limit');
        $mem_bytes = wp_convert_hr_to_bytes($mem_limit);
        $mem_ok = $mem_bytes >= 256 * 1024 * 1024;
        $results[] = [
            'name' => 'Allocation Mémoire PHP',
            'status' => $mem_ok ? 'success' : 'warning',
            'val' => "Limite actuelle : $mem_limit (" . ($mem_ok ? 'Suffisant' : 'Risque de crash sur gros scans') . ")"
        ];

        // 4. Execution Time Audit
        $max_time = ini_get('max_execution_time');
        $time_ok = $max_time >= 60 || $max_time == 0;
        $results[] = [
            'name' => 'Timeout Exécution',
            'status' => $time_ok ? 'success' : 'warning',
            'val' => "Limite : {$max_time}s (" . ($time_ok ? 'Sûr' : 'Trop court pour de gros sites') . ")"
        ];

        global $wpdb;

        // 5. Database Performance (Index Audit)
        $links_table = $wpdb->prefix . 'sil_links';
        $membr_table = $wpdb->prefix . 'sil_silo_membership';
        
        $has_links_index = $wpdb->get_results("SHOW INDEX FROM $links_table WHERE Column_name = 'source_id'");
        $has_membr_index = $wpdb->get_results("SHOW INDEX FROM $membr_table WHERE Column_name = 'post_id'");
        
        $all_ok = !empty($has_links_index) && !empty($has_membr_index);
        
        $results[] = [
            'name' => 'Indexation SQL Pilotage',
            'status' => $all_ok,
            'val' => $all_ok ? 'Index actifs sur sil_links et sil_silo_membership' : 'Index MANQUANTS sur les tables critiques'
        ];

        // 5b. Embeddings Table Check
        $emb_table = $wpdb->prefix . 'sil_embeddings';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$emb_table'") === $emb_table;
        $results[] = [
            'name' => 'Table des Embeddings',
            'status' => $table_exists,
            'val' => $table_exists ? 'Table sil_embeddings opérationnelle' : 'Table sil_embeddings MANQUANTE'
        ];

        // 6. Content Quality Sample
        $sample_posts = $wpdb->get_results("SELECT ID, post_content FROM $wpdb->posts WHERE post_type = 'post' AND post_status = 'publish' LIMIT 3");
        $content_ok = true;
        $content_msg = "Contenu lisible détecté";
        foreach ($sample_posts as $post) {
            $stripped = wp_strip_all_tags(strip_shortcodes($post->post_content));
            if (strlen(trim($stripped)) < 50) {
                $content_ok = false;
                $content_msg = "Attention : ID {$post->ID} est quasiment vide (IA aveugle)";
                break;
            }
        }
        $results[] = [
            'name' => 'Qualité Sémantique Articles',
            'status' => $content_ok ? 'success' : 'warning',
            'val' => $content_msg
        ];

        // 7. GSC Integrity (Refined v2.6.1)
        $tokens = get_option('sil_gsc_oauth_tokens');
        $gsc_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sil_gsc_data");
        
        $gsc_status = ($gsc_count > 0);
        $gsc_val = $gsc_status ? "Données GSC valides ($gsc_count lignes)" : "Données GSC absentes ou invalides";
        
        if (!$tokens) {
             $gsc_status = 'warning';
             $gsc_val = "Google Search Console non connectée";
        } elseif ($gsc_count == 0) {
             $gsc_status = 'warning'; // Still warning because features won't work, but better message
             $gsc_val = "Connecté à l'API Google, mais aucun scan GSC n'a été effectué";
        }

        $results[] = [
            'name' => 'Intégrité Données GSC',
            'status' => $gsc_status,
            'val' => $gsc_val
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
            $target_slug    = get_post_field('post_name', $target_id);
            $already_linked = [];

            // Regex robuste pour détecter les liens existants vers la cible 
            // (gère les URLs absolues, relatives, avec ou sans slash final)
            $link_regex = '/<a[^>]+href=["\']([^"\']*(?:\/|(?<=\w))' . preg_quote($target_slug, '/') . '(?:\/|["\']))[^"\']*["\']/i';

            foreach ( $silo_members as $member_id ) {
                $content = get_post_field('post_content', $member_id);
                if ( ! $content ) continue;

                // Check with robust regex
                if ( preg_match($link_regex, $content) ) {
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
                // No embedding: return candidates (Top 3 only)
                $suggestions = [];
                $limited_candidates = array_slice($candidates, 0, 3);
                foreach ( $limited_candidates as $cid ) {
                    $memberships = $silos->get_memberships($cid);
                    $suggestions[] = [
                        'id'         => $cid,
                        'title'      => get_the_title($cid),
                        'edit_url'   => admin_url( 'post.php?post=' . intval($cid) . '&action=edit' ),
                        'similarity' => null,
                        'silo_score' => $memberships[$primary_silo] ?? 0,
                    ];
                }
                wp_send_json_success(['suggestions' => $suggestions, 'silo_id' => $primary_silo, 'message' => 'Suggestions basées sur le silo (Top 3)']);
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
            $top = array_slice($scored, 0, 3, true);

            // 6. Build result
            $suggestions = [];
            foreach ( $top as $cid => $sim ) {
                $memberships = $silos->get_memberships($cid);
                $suggestions[] = [
                    'id'         => $cid,
                    'title'      => get_the_title($cid),
                    'edit_url'   => admin_url( 'post.php?post=' . intval($cid) . '&action=edit' ),
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

    /**
     * AJAX: Get context for a specific link (edge) for the sidebar.
     */
    public function sil_get_edge_context() {
        check_ajax_referer('sil_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission refusée');
        }

        $source_id = isset($_POST['source_id']) ? intval($_POST['source_id']) : 0;
        $target_id = isset($_POST['target_id']) ? intval($_POST['target_id']) : 0;

        if (!$source_id || !$target_id) {
            wp_send_json_error('IDs manquants');
        }

        $source_post = get_post($source_id);
        $target_url = get_permalink($target_id);

        if (!$source_post || !$target_url) {
            wp_send_json_error('Source ou cible introuvable');
        }

        $content = $source_post->post_content;
        $escaped_url = preg_quote($target_url, '/');
        $relative_url = wp_make_link_relative($target_url);
        $escaped_relative_url = preg_quote($relative_url, '/');

        // Pattern to find the link, anchor, and attributes
        $pattern = '/<a([^>]+)href=["\'](' . $escaped_url . '|' . $escaped_relative_url . ')["\']([^>]*)>(.*?)<\/a>/is';
        
        // Wait, the context extraction logic from sil_get_node_details is better
        preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE);

        if (empty($matches)) {
             wp_send_json_error('Lien non trouvé dans le contenu');
        }

        $full_tag = $matches[0][0];
        $anchor = wp_strip_all_tags($matches[4][0]);
        $offset = $matches[0][1];
        $attr_before = $matches[1][0];
        $attr_after = $matches[3][0];

        $is_nofollow = (strpos($attr_before . $attr_after, 'nofollow') !== false);

        // Capture context
        $context_prev = strip_tags(substr($content, max(0, $offset - 100), min($offset, 100)));
        $context_next = strip_tags(substr($content, $offset + strlen($full_tag), 100));

        // Semantic leak detection & Permeability
        $silos = $this->main->semantic_silos;
        $s_silo = $silos->get_primary_silo($source_id);
        $t_silo = $silos->get_primary_silo($target_id);
        
        $is_leak = ($s_silo !== $t_silo);
        $silo_labels = $silos->get_silo_labels();
        $leak_threshold = (int) get_option('sil_target_permeability', 20);
        $leak_percent = 0;

        if ($is_leak && $s_silo) {
            global $wpdb;
            $table_membership = $wpdb->prefix . 'sil_silo_membership';
            $table_links = $wpdb->prefix . 'sil_links';
            
            // 1. Get all members of source silo
            $silo_members = $silos->get_silo_members($s_silo, true);
            if (!empty($silo_members)) {
                $placeholders = implode(',', array_fill(0, count($silo_members), '%d'));
                
                // 2. Count total outgoing links from these members
                $total_outgoing = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $table_links WHERE source_id IN ($placeholders)",
                    ...$silo_members
                ));

                // 3. Count inter-silo links from these members
                if ($total_outgoing > 0) {
                    $inter_links = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(l.id) 
                         FROM $table_links l
                         JOIN $table_membership m ON l.target_id = m.post_id
                         WHERE l.source_id IN ($placeholders) 
                         AND m.silo_id != %d 
                         AND m.is_primary = 1",
                        ...array_merge($silo_members, [$s_silo])
                    ));
                    $leak_percent = round(($inter_links / $total_outgoing) * 100);
                }
            }
        }

        wp_send_json_success([
            'anchor' => $anchor,
            'context_prev' => '...' . $context_prev,
            'context_next' => $context_next . '...',
            'is_nofollow' => $is_nofollow,
            'is_leak' => $is_leak,
            'leak_percent' => $leak_percent,
            'leak_threshold' => $leak_threshold,
            'source_silo_id' => $s_silo,
            'target_silo_id' => $t_silo,
            'source_silo_label' => $silo_labels[$s_silo] ?? "Silo $s_silo",
            'target_silo_label' => $silo_labels[$t_silo] ?? "Silo $t_silo",
            'source_title' => get_the_title($source_id),
            'target_title' => get_the_title($target_id),
            'target_url' => $target_url,
            'source_edit_url' => admin_url( 'post.php?post=' . intval($source_id) . '&action=edit' ),
            'target_edit_url' => admin_url( 'post.php?post=' . intval($target_id) . '&action=edit' )
        ]);
    }

    /**
     * AJAX: Toggle nofollow attribute on a link.
     */
    public function sil_toggle_edge_nofollow() {
        check_ajax_referer('sil_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission refusée');
        }

        $source_id = intval($_POST['source_id']);
        $target_url = isset($_POST['target_url']) ? esc_url_raw($_POST['target_url']) : '';
        $set_nofollow = $_POST['nofollow'] === 'true';

        $source_post = get_post($source_id);
        if (!$source_post || !$target_url) {
            wp_send_json_error('Données invalides');
        }

        $content = $source_post->post_content;
        $escaped_url = preg_quote($target_url, '/');
        $relative_url = wp_make_link_relative($target_url);
        $pattern = '/<a([^>]+)href=["\'](' . $escaped_url . '|' . preg_quote($relative_url, '/') . ')["\']([^>]*)>(.*?)<\/a>/is';

        $new_content = preg_replace_callback($pattern, function($m) use ($set_nofollow) {
            $attr = $m[1] . ' ' . $m[3];
            $anchor = $m[4];
            $url = $m[2];

            // Clean up existing rel attribute carefully
            $rel = '';
            if (preg_match('/rel=["\']([^"\']*)["\']/i', $attr, $rel_matches)) {
                $rel = $rel_matches[1];
                $attr = str_replace($rel_matches[0], '', $attr);
            }

            $rel_parts = array_filter(explode(' ', $rel));
            if ($set_nofollow) {
                if (!in_array('nofollow', $rel_parts)) $rel_parts[] = 'nofollow';
            } else {
                $rel_parts = array_diff($rel_parts, ['nofollow']);
            }

            if (!empty($rel_parts)) {
                $attr .= ' rel="' . esc_attr(implode(' ', $rel_parts)) . '"';
            }

            return sprintf('<a %s href="%s">%s</a>', trim($attr), $url, $anchor);
        }, $content);

        if ($new_content !== $content) {
            wp_update_post(['ID' => $source_id, 'post_content' => $new_content]);
            wp_send_json_success('Attribut mis à jour');
        } else {
            wp_send_json_error('Modification impossible');
        }
    }

    /**
     * AJAX: Get info for orphan adoption.
     */
    public function sil_get_orphan_adoption_info() {
        check_ajax_referer('sil_nonce', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Permission refusée');

        $post_id = intval($_POST['post_id']);
        if (!$post_id) wp_send_json_error('ID manquant');

        $cluster_analysis = new SIL_Cluster_Analysis();
        $megaphone_id = $cluster_analysis->get_megaphone_for_post($post_id);

        if (!$megaphone_id) {
            wp_send_json_error('Aucun Mégaphone trouvé pour ce silo.');
        }

        $orphan_title    = wp_kses_decode_entities(get_the_title($post_id));
        $megaphone_title = wp_kses_decode_entities(get_the_title($megaphone_id));

        // --- Anchors Suggestions ---
        $anchors = [];
        $anchors[] = $orphan_title; // Option 1: Title

        // Option 2: GSC Top Query
        $gsc_data = get_post_meta($post_id, '_sil_gsc_data', true);
        if ($gsc_data) {
            $gsc = is_array($gsc_data) ? $gsc_data : json_decode($gsc_data, true);
            if (empty($gsc) && is_string($gsc_data)) {
                 $gsc = function_exists('maybe_unserialize') ? maybe_unserialize($gsc_data) : unserialize($gsc_data);
            }
            $queries = $gsc['top_queries'] ?? (is_array($gsc) ? $gsc : []);
            if (!empty($queries) && is_array($queries)) {
                $first = reset($queries);
                $kw = $first['query'] ?? ($first['keys'][0] ?? '');
                if ($kw) $anchors[] = $kw;
            }
        }

        // Option 3: AI Suggestion
        $api_key = get_option('sil_openai_api_key');
        if ($api_key) {
            $prompt = "Suggère une ancre de lien naturelle et SEO (maximum 4 mots) pour un lien menant vers l'article intitulé : \"$orphan_title\". Le lien sera inséré dans un article sur la thématique sémantique similaire. Répond uniquement par l'ancre, rien d'autre.";
            
            $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
                'timeout' => 15,
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type'  => 'application/json'
                ],
                'body' => json_encode([
                    'model' => 'gpt-4o-mini',
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                    'temperature' => 0.7
                ])
            ]);

            if (!is_wp_error($response)) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                $ai_anchor = $body['choices'][0]['message']['content'] ?? '';
                if ($ai_anchor) $anchors[] = trim($ai_anchor, '" ');
            }
        }

        wp_send_json_success([
            'megaphone_id' => $megaphone_id,
            'megaphone_title' => $megaphone_title,
            'anchors' => array_values(array_unique(array_map('wp_kses_decode_entities', array_filter($anchors))))
        ]);
    }

    /**
     * AJAX: Actually adopt the orphan (insert link).
     */
    public function sil_adopt_orphan() {
        check_ajax_referer('sil_nonce', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Permission refusée');

        $orphan_id = intval($_POST['orphan_id']);
        $megaphone_id = intval($_POST['megaphone_id']);
        $anchor = sanitize_text_field($_POST['anchor']);

        if (!$orphan_id || !$megaphone_id || !$anchor) {
            wp_send_json_error('Données manquantes');
        }

        $target_url = get_permalink($orphan_id);
        
        // Mimic the structure expected by sil_insert_internal_link
        $_POST['post_id'] = $orphan_id;
        $_POST['links'] = json_encode([
            [
                'target_id' => $megaphone_id,
                'target_url' => $target_url,
                'anchor' => $anchor,
                'paragraph_index' => -1
            ]
        ]);

        return $this->sil_insert_internal_link();
    }
    /**
     * AJAX: Get prioritized actions for Pilotage Hub.
     */
    public function sil_get_pilotage_actions() {
        check_ajax_referer('sil_nonce', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Accès refusé');

        ob_start();
        try {
            $orphans = $this->main->pilot_engine->get_high_potential_orphans(5);
            $boosters = $this->main->pilot_engine->get_gsc_boosters(5);

            if (ob_get_length()) ob_clean();
            wp_send_json_success([
                'orphans'  => $orphans,
                'boosters' => $boosters
            ]);
        } catch (Exception $e) {
            if (ob_get_length()) ob_clean();
            wp_send_json_error('Exécution Pilot Engine échouée: ' . $e->getMessage());
        } catch (Error $e) {
            if (ob_get_length()) ob_clean();
            wp_send_json_error('Erreur Critique Pilot Engine: ' . $e->getMessage());
        }
    }

    /**
     * AJAX: Silent Diagnostics for Pilotage Hub.
     */
    public function sil_get_pilotage_diagnostics() {
        check_ajax_referer('sil_nonce', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Accès refusé');

        ob_start();
        try {
            $diagnosis = $this->main->pilot_engine->self_diagnose();
            if (ob_get_length()) ob_clean();
            wp_send_json_success($diagnosis);
        } catch (Exception $e) {
            if (ob_get_length()) ob_clean();
            wp_send_json_error('Diagnostic échoué: ' . $e->getMessage());
        } catch (Error $e) {
            if (ob_get_length()) ob_clean();
            wp_send_json_error('Erreur Critique Diagnostic: ' . $e->getMessage());
        }
    }

    /**
     * AJAX: Log a manual SEO action (Rewrite, Deepsearch, etc.)
     */
    public function sil_log_manual_action() {
        check_ajax_referer('sil_nonce', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Accès refusé');

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $type = isset($_POST['action_type']) ? sanitize_text_field($_POST['action_type']) : '';
        $note = isset($_POST['note']) ? sanitize_textarea_field($_POST['note']) : '';

        if (!$post_id || !$type) {
            wp_send_json_error('Données manquantes (Post ID ou Type)');
        }

        if (class_exists('SIL_Action_Logger')) {
            $log_id = SIL_Action_Logger::log_action($type, $post_id, null, ['note' => $note]);
            if ($log_id) {
                wp_send_json_success(['message' => 'Action journalisée avec succès', 'log_id' => $log_id]);
            }
        }

        wp_send_json_error('Erreur lors de la journalisation');
    }

    /**
     * AJAX: Get recent action logs for the UI.
     */
    public function sil_get_action_logs() {
        check_ajax_referer('sil_nonce', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Accès refusé');

        // Robustness: ensure table exists (v2.5.1 hotfix)
        $this->main->db_manager->check_and_install_tables();

        if (class_exists('SIL_Action_Logger')) {
            $logs = SIL_Action_Logger::get_recent_actions(20);
            
            // Enrich logs with titles and human readable data (safe check for PHP 8+)
            if (is_array($logs)) {
                foreach ($logs as &$log) {
                    $log->source_title = $log->post_id_source ? get_the_title($log->post_id_source) : '';
                    $log->target_title = $log->post_id_target ? get_the_title($log->post_id_target) : '';
                    $log->human_time = human_time_diff(strtotime($log->timestamp), current_time('timestamp', true)) . ' (il y a)';
                    $log->initial_stats = json_decode($log->initial_stats, true);
                    $log->expected_gain = json_decode($log->expected_gain, true);
                }
            } else {
                $logs = []; // Avoid returning null to success
            }

            wp_send_json_success($logs);
        }

        wp_send_json_error('Module de logging introuvable');
    }

    // --- Incubateur de liens (v2.6) ---

    /**
     * AJAX: Programme un pont sémantique
     */
    public function sil_schedule_link() {
        check_ajax_referer('sil_nonce', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Accès refusé');

        $source_id = isset($_POST['source_id']) ? intval($_POST['source_id']) : 0;
        $target_id = isset($_POST['target_id']) ? intval($_POST['target_id']) : 0;
        $anchor = isset($_POST['anchor']) ? sanitize_text_field($_POST['anchor']) : '';
        $note = isset($_POST['note']) ? sanitize_textarea_field(wp_unslash($_POST['note'])) : null;

        if (!$source_id || !$target_id || empty($anchor)) {
            wp_send_json_error('Données incomplètes.');
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'sil_scheduled_links';

        $inserted = $wpdb->insert(
            $table_name,
            [
                'source_id' => $source_id,
                'target_id' => $target_id,
                'anchor' => $anchor,
                'note' => $note,
                'status' => 'pending',
                'created_at' => current_time('mysql')
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s']
        );

        if ($inserted) {
            wp_send_json_success(['message' => 'Lien programmé !']);
        } else {
            wp_send_json_error('Erreur SQL lors de la programmation.');
        }
    }

    /**
     * AJAX: Récupère la file d'attente des ponts
     */
    public function sil_get_scheduled_links() {
        check_ajax_referer('sil_nonce', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Accès refusé');

        global $wpdb;
        $sql = "
            SELECT l.id, l.source_id, l.target_id, l.anchor, l.note, l.status, l.created_at,
                   ps.post_title AS source_title,
                   pt.post_title AS target_title,
                   pt.post_status AS target_status
            FROM {$wpdb->prefix}sil_scheduled_links l
            LEFT JOIN {$wpdb->prefix}posts ps ON l.source_id = ps.ID
            LEFT JOIN {$wpdb->prefix}posts pt ON l.target_id = pt.ID
            ORDER BY l.created_at DESC
        ";

        $results = $wpdb->get_results($sql, ARRAY_A);
        
        if (!empty($results)) {
            foreach ($results as &$row) {
                if (!empty($row['note'])) {
                    $row['note'] = stripslashes($row['note']);
                }
            }
        }

        if ($wpdb->last_error) {
           wp_send_json_success([]); 
        }

        wp_send_json_success($results ?: []);
    }

    /**
     * AJAX: Supprime une programmation
     */
    public function sil_delete_scheduled_link() {
        check_ajax_referer('sil_nonce', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Accès refusé');

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if (!$id) wp_send_json_error('ID invalide');

        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'sil_scheduled_links', ['id' => $id], ['%d']);

        wp_send_json_success(['message' => 'Lien supprimé']);
    }

    /**
     * AJAX: Marque une programmation comme terminée
     */
    public function sil_complete_scheduled_link() {
        check_ajax_referer('sil_nonce', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Accès refusé');

        $source_id = isset($_POST['source_id']) ? intval($_POST['source_id']) : 0;
        $target_id = isset($_POST['target_id']) ? intval($_POST['target_id']) : 0;

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'sil_scheduled_links',
            ['status' => 'completed'],
            ['source_id' => $source_id, 'target_id' => $target_id, 'status' => 'pending'],
            ['%s'],
            ['%d', '%d', '%s']
        );

        wp_send_json_success(['message' => 'Statut mis à jour']);
    }

    /**
     * AJAX: V17 Expert Arsenal Actions
     * Handles specialized SEO tasks like Title rewrite or Anchor invention.
     */
    public function sil_v17_expert_action()
    {
        check_ajax_referer('sil_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission insuffisante.');
        }

        $type = isset($_POST['v17_type']) ? sanitize_text_field($_POST['v17_type']) : '';
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

        if (!$post_id) {
            wp_send_json_error('ID de post manquant.');
        }

        switch ($type) {
            case 'generate_seo':
                $opportunity = SIL_SEO_Utils::get_best_gsc_opportunity($post_id);
                if ($opportunity) {
                    wp_send_json_success([
                        'message' => 'Opportunité identifiée : "' . $opportunity['query'] . '"',
                        'query' => $opportunity['query'],
                        'impressions' => $opportunity['impressions'],
                        'suggested_title' => $opportunity['query'] . ' - ' . get_the_title($post_id)
                    ]);
                } else {
                    wp_send_json_error('Aucune opportunité SEO majeure (CTR > 1.5% partout).');
                }
                break;

            case 'find_anchor':
                $anchors = SIL_SEO_Utils::get_long_tail_semantic_keywords($post_id);
                if (!empty($anchors)) {
                    wp_send_json_success([
                        'anchors' => $anchors,
                        'message' => 'Mots-clés longue traîne extraits (3+ mots).'
                    ]);
                } else {
                    wp_send_json_error('Aucun mot-clé longue traîne (3+ mots) trouvé pour cet article.');
                }
                break;

            case 'get_wand_anchor':
                $gsc_raw = get_post_meta($post_id, '_sil_gsc_data', true);
                $gsc = json_decode($gsc_raw, true);
                $best_query = '';

                if ($gsc && isset($gsc['top_queries'][0])) {
                    $row = $gsc['top_queries'][0];
                    $best_queryArray = isset($row['query']) ? $row['query'] : (isset($row['keys'][0]) ? $row['keys'][0] : '');
                    $best_query = is_array($best_queryArray) ? implode(' ', $best_queryArray) : $best_queryArray;
                }

                if ($best_query) {
                    wp_send_json_success(['anchor' => $best_query]);
                } else {
                    wp_send_json_error('Aucun mot-clé GSC trouvé pour la cible.');
                }
                break;

            default:
                wp_send_json_error('Type d\'action V17 inconnu : ' . $type);
                break;
        }
    }
}

