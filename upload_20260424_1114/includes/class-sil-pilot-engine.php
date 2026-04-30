<?php
/**
 * Smart Internal Links - Pilot Engine (Atomic Logic)
 * v2.5.3 - Observability & Diagnostic Focus
 */

if (!defined('ABSPATH')) exit;

class SIL_Pilot_Engine {

    private $main;

    public function __construct($main) {
        $this->main = $main;
    }

    /**
     * Fallback sécurisé pour mb_strpos() si l'extension mbstring est désactivée.
     */
    public static function mb_strpos_safe($haystack, $needle, $offset = 0, $encoding = 'UTF-8') {
        if (!is_string($haystack) || !is_string($needle) || empty($needle)) {
            return false;
        }
        if (function_exists('mb_strpos')) {
            return mb_strpos($haystack, $needle, $offset, $encoding);
        }
        return strpos($haystack, $needle, $offset);
    }

    /**
     * Calcule le quota dynamique de liens pour une cible.
     */
    public function get_target_quota($post_id) {
        $total_pages = get_transient('sil_total_pages_count');
        if ($total_pages === false) {
            global $wpdb;
            $total_pages = (int) $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type IN ('post', 'page') AND post_status = 'publish'");
            set_transient('sil_total_pages_count', $total_pages, HOUR_IN_SECONDS);
        }
        
        $ratio = floatval(get_option('sil_elite_ratio', 10)) / 100;
        $multiplier = 1.0;
        
        // Support both pillar and cornerstone meta
        if (get_post_meta($post_id, '_sil_is_pillar', true) === '1' || get_post_meta($post_id, '_sil_is_cornerstone', true) === '1') {
            $multiplier = floatval(get_option('sil_pillar_multiplier', 2.0));
        }
        
        $quota = max(3, round($total_pages * $ratio * $multiplier));
        return $quota;
    }

    /**
     * Vérifie si le verrou de Drip-Feed est actif pour une cible.
     */
    public function is_drip_feed_locked($post_id) {
        $last_boost = (int) get_post_meta($post_id, '_sil_last_boost_timestamp', true);
        if (!$last_boost) return false;
        
        $days_limit = (int) get_option('sil_drip_feed_days', 14);
        $seconds_limit = $days_limit * 86400;
        
        $elapsed = time() - $last_boost;
        return $elapsed < $seconds_limit;
    }

    /**
     * Decode unicode-escaped GSC query strings (u00e9 → é).
     * Shared decoder to avoid regression across modules.
     */
    private function decode_gsc_query($raw) {
        if (!is_string($raw)) return $raw;
        // Decode \uXXXX sequences (with or without backslash prefix)
        $decoded = preg_replace_callback('/(?:\\\\+)?u([0-9a-fA-F]{4})/', function ($match) {
            return mb_convert_encoding(pack('H*', $match[1]), 'UTF-8', 'UCS-2BE');
        }, $raw);
        // Decode HTML entities (&rsquo; → ')
        return wp_specialchars_decode($decoded, ENT_QUOTES);
    }

    /**
     * Check if a post is marked noindex.
     * Delegates to the central SIL_SEO_Utils for consistency with Cartographie.
     */
    private function is_noindexed($post_id) {
        if (class_exists('SIL_SEO_Utils')) {
            return SIL_SEO_Utils::is_noindexed($post_id);
        }
        return false;
    }

    /**
     * Get orphans prioritizing high impressions contents (GSC data).
     */
    public function get_high_potential_orphans($limit = 5) {
        // BMAD Correction: Increase resources for safety, though query is now fast
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        // HYBRID SYNC (Option C): Refresh stale content in background
        $this->refresh_stale_content_links(10); 

        global $wpdb;
        $post_types = ($this->main && !empty($this->main->post_types)) ? $this->main->post_types : ['post', 'page'];
        $table_links = $wpdb->prefix . 'sil_links';
        $table_gsc   = $wpdb->prefix . 'sil_gsc_data';

        // 1. Exclude front/blog
        $exclude_ids = array_filter([(int)get_option('page_on_front'), (int)get_option('page_for_posts')]);
        $exclude_sql = !empty($exclude_ids) ? "AND p.ID NOT IN (" . implode(',', $exclude_ids) . ")" : "";

        // 2. Optimized JOIN Query: Find published posts with ZERO incoming links in sil_links
        // BMAD Phase 0.5: Exclude recent posts (< 60 days) to allow natural discovery.
        $grace_period_orphans = date('Y-m-d H:i:s', strtotime('-60 days'));
        
        $candidates_to_fetch = max(100, $limit * 10);
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, p.post_title, IFNULL(g.impressions, 0) as impressions, pm.meta_value as index_status
                 FROM $wpdb->posts p
                 LEFT JOIN $table_links l ON p.ID = l.target_id AND l.status = 'valid'
                 LEFT JOIN $table_gsc g ON p.ID = g.post_id
                 LEFT JOIN $wpdb->postmeta pm ON p.ID = pm.post_id AND pm.meta_key = '_sil_gsc_index_status'
                 WHERE p.post_type IN ('" . implode("','", array_map('esc_sql', $post_types)) . "') 
                 AND p.post_status = 'publish' 
                 $exclude_sql
                 AND p.post_date < %s
                 AND l.target_id IS NULL
                 ORDER BY 
                    CASE 
                        WHEN LOWER(pm.meta_value) NOT LIKE '%%indexed%%' OR pm.meta_value IS NULL THEN 1 
                        ELSE 2 
                    END ASC,
                    impressions DESC, p.ID DESC
                 LIMIT %d",
                 $grace_period_orphans,
                 $candidates_to_fetch
            )
        );

        if (empty($results)) return [];

        $orphans = [];
        foreach ($results as $post) {
            // Noindex filter (Still check metadata for noindex as it's not in the main query)
            if ($this->is_noindexed($post->ID)) continue;

            $is_indexed = !empty($post->index_status) && stripos($post->index_status, 'indexed') !== false;

            $orphans[] = [
                'id' => (int)$post->ID,
                'title' => $post->post_title,
                'impressions' => (int)$post->impressions,
                'is_urgent_index' => !$is_indexed
            ];

            if (count($orphans) >= $limit) break;
        }

        return $orphans;
    }

    /**
     * HYBRID SYNC: Scans a small batch of posts that have changed since last scan.
     * Compares MD5 of post_content with stored hash in sil_embeddings.
     */
    public function refresh_stale_content_links($batch_size = 10) {
        global $wpdb;
        $table_embeddings = $wpdb->prefix . 'sil_embeddings';
        $post_types = ($this->main && !empty($this->main->post_types)) ? $this->main->post_types : ['post', 'page'];
        $types_sql = "'" . implode("','", array_map('esc_sql', $post_types)) . "'";

        // Find posts where content has changed (hash mismatch)
        // We join with embeddings to get the previous hash
        $stale_posts = $wpdb->get_results("
            SELECT p.ID, p.post_content, e.content_hash as old_hash
            FROM $wpdb->posts p
            INNER JOIN $table_embeddings e ON p.ID = e.post_id
            WHERE p.post_type IN ($types_sql) 
            AND p.post_status = 'publish'
            LIMIT 50
        ");

        if (empty($stale_posts)) return;

        $processed = 0;
        foreach ($stale_posts as $post) {
            $new_hash = md5($post->post_content);
            if ($new_hash !== $post->old_hash) {
                // Content changed! Update links for this post.
                $this->main->scanner->scan_post_links($post->ID);
                
                // Update hash in embeddings table to avoid re-scanning
                $wpdb->update(
                    $table_embeddings,
                    ['content_hash' => $new_hash],
                    ['post_id' => $post->ID]
                );

                $processed++;
                if ($processed >= $batch_size) break;
            }
        }
    }

    /**
     * Get Strike Distance boosters (GSC 6-15).
     * Filters out 301'd or trashed posts.
     */
    public function get_gsc_boosters($limit = 5) {
        global $wpdb;

        // BMAD Phase 0.5: Exclude recent posts (< 60 days)
        $grace_period_striking = date('Y-m-d H:i:s', strtotime('-60 days'));

        // Optimized query: get GSC data and Title in one go, filtering by status directly in SQL
        $metas = $wpdb->get_results($wpdb->prepare("
            SELECT pm.post_id, pm.meta_value, p.post_title, p.post_status 
            FROM $wpdb->postmeta pm
            INNER JOIN $wpdb->posts p ON pm.post_id = p.ID
            WHERE pm.meta_key = '_sil_gsc_data' 
            AND pm.meta_value != ''
            AND p.post_status = 'publish'
            AND p.post_date < %s
            LIMIT 200
        ", $grace_period_striking));
        
        $boosters = [];
        foreach ($metas as $m) {
            $data = is_string($m->meta_value) ? json_decode($m->meta_value, true) : $m->meta_value;
            if (empty($data) || !isset($data['top_queries']) || !is_array($data['top_queries'])) continue;

            foreach ($data['top_queries'] as $q) {
                if (!isset($q['position']) || !isset($q['query'])) continue;
                $pos = floatval($q['position']);
                if ($pos >= 6 && $pos <= 15) {
                    $boosters[] = [
                        'post_id' => $m->post_id,
                        'title' => $m->post_title,
                        'kw' => $this->decode_gsc_query($q['query']),
                        'pos' => round($pos, 1),
                        'impressions' => isset($q['impressions']) ? (int)$q['impressions'] : 0
                    ];
                }
            }
        }
        usort($boosters, function($a, $b) { return ($b['impressions'] ?? 0) - ($a['impressions'] ?? 0); });
        return array_slice($boosters, 0, $limit);
    }

    /**
     * Self-Diagnose system health.
     * Produces an exhaustive report for the Diagnostic tab.
     */
    public function self_diagnose() {
        global $wpdb;

        error_log('SIL Pilot Diagnostic: Starting self_diagnose()');

        $diagnosis = [
            'timestamp' => current_time('mysql'),
            'env' => [
                'php' => PHP_VERSION,
                'wp' => get_bloginfo('version'),
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time')
            ],
            'database' => [
                'sil_links' => $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}sil_links'") ? '✅ OK' : '❌ MANQUANT',
                'sil_action_log' => $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}sil_action_log'") ? '✅ OK' : '❌ MANQUANT',
                'sil_embeddings' => $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}sil_embeddings'") ? '✅ OK' : '❌ MANQUANT',
                'sil_silo_membership' => $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}sil_silo_membership'") ? '✅ OK' : '❌ MANQUANT'
            ],
            'counts' => [
                'total_links' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sil_links"),
                'total_embeddings' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->main->table_name}"),
                'total_logs' => $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}sil_action_log'")
                    ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sil_action_log")
                    : 0
            ],
            'html_integrity' => $this->check_html_integrity(50), // Scan up to 50 posts at a time per diagnostic run
            'logic_test' => [
                'orphan_query' => 'En attente...',
                'booster_query' => 'En attente...',
                'brother_search' => 'En attente...'
            ],
            'ajax_routes' => [
                'sil_get_pilotage_actions' => has_action('wp_ajax_sil_get_pilotage_actions') ? '✅ Enregistré' : '❌ MANQUANT',
                'sil_get_pilotage_diagnostics' => has_action('wp_ajax_sil_get_pilotage_diagnostics') ? '✅ Enregistré' : '❌ MANQUANT',
                'sil_get_action_logs' => has_action('wp_ajax_sil_get_action_logs') ? '✅ Enregistré' : '❌ MANQUANT'
            ],
            'recent_errors' => $this->get_recent_php_errors(10)
        ];

        // Test Orphan Query
        try {
            $orphans = $this->get_high_potential_orphans(1);
            $diagnosis['logic_test']['orphan_query'] = '✅ OK (' . count($orphans) . ' trouvé)';
            error_log('SIL Pilot Diagnostic: orphan_query OK — ' . count($orphans) . ' result(s)');
        } catch (\Exception $e) {
            $diagnosis['logic_test']['orphan_query'] = '❌ ERREUR: ' . $e->getMessage();
            error_log('SIL Pilot Diagnostic: orphan_query FAILED — ' . $e->getMessage());
        }

        // Test Booster Query
        try {
            $boosters = $this->get_gsc_boosters(1);
            $diagnosis['logic_test']['booster_query'] = '✅ OK (' . count($boosters) . ' trouvé)';
            error_log('SIL Pilot Diagnostic: booster_query OK — ' . count($boosters) . ' result(s)');
        } catch (\Exception $e) {
            $diagnosis['logic_test']['booster_query'] = '❌ ERREUR: ' . $e->getMessage();
            error_log('SIL Pilot Diagnostic: booster_query FAILED — ' . $e->getMessage());
        }
        
        // Test Brother Search
        try {
            $orphans = $this->get_high_potential_orphans(1);
            if (!empty($orphans)) {
                $orphan_id = $orphans[0]['id'];
                $analysis = new SIL_Cluster_Analysis();
                $brother_id = $analysis->get_closest_brother_for_post($orphan_id);
                $diagnosis['logic_test']['brother_search'] = '✅ OK (ID: ' . ($brother_id ?: 'Aucun frère') . ')';
            } else {
                $diagnosis['logic_test']['brother_search'] = '🟡 SKIPPED (Aucun orphelin pour le test)';
            }
        } catch (\Throwable $e) {
            $diagnosis['logic_test']['brother_search'] = '❌ ERREUR: ' . $e->getMessage();
            error_log('SIL Pilot Diagnostic: brother_search FAILED — ' . $e->getMessage());
        }

        error_log('SIL Pilot Diagnostic: Completed successfully');

        return $diagnosis;
    }

    /**
     * Détecte les risques de cannibalisation (Duel de Pages) via une approche hybride.
     * v2.6 - Basé sur Similarité Sémantique + Overlap GSC.
     */
    public function detect_cannibalization_risks() {
        global $wpdb;
        $max_sim = floatval(get_option('sil_similarity_max', 0.92));
        $overlap_threshold = intval(get_option('sil_gsc_overlap_threshold', 3));
        
        $table_embeddings = $wpdb->prefix . 'sil_embeddings';
        $table_gsc = $wpdb->prefix . 'sil_gsc_data';
        $current_types = ($this->main && !empty($this->main->post_types)) ? $this->main->post_types : ['post', 'page'];
        $post_types = "'" . implode("','", array_map('esc_sql', $current_types)) . "'";

        // 1. Récupérer les articles avec leurs embeddings et données GSC
        $posts = $wpdb->get_results("
            SELECT e.post_id, e.embedding, g.top_queries, p.post_title
            FROM $table_embeddings e
            LEFT JOIN $table_gsc g ON e.post_id = g.post_id
            INNER JOIN $wpdb->posts p ON e.post_id = p.ID
            WHERE p.post_status = 'publish' AND p.post_type IN ($post_types)
        ");

        if (count($posts) < 2) return [];

        @set_time_limit(300); // Protection contre les timeouts (5 minutes)

        $conflicts = [];
        $count = count($posts);

        // Pre-décodage pour optimiser la boucle O(n²)
        $decoded_posts = [];
        foreach ($posts as $p) {
            $decoded_posts[] = [
                'id' => $p->post_id,
                'title' => $p->post_title,
                'emb' => json_decode($p->embedding, true),
                'queries' => json_decode($p->top_queries, true) ?: []
            ];
        }

        // 2. Comparaison croisée optimisée
        for ($i = 0; $i < $count; $i++) {
            $p1 = $decoded_posts[$i];
            if (empty($p1['emb'])) continue;
            
            $keywords1 = array_column($p1['queries'], 'query');

            for ($j = $i + 1; $j < $count; $j++) {
                $p2 = $decoded_posts[$j];
                if (empty($p2['emb'])) continue;
                
                // --- CHECK 1 : Sémantique ---
                $similarity = $this->main->cosine_similarity($p1['emb'], $p2['emb']);

                // --- CHECK 2 : GSC Overlap ---
                $keywords2 = array_column($p2['queries'], 'query');
                $common_keywords = array_intersect($keywords1, $keywords2);
                $overlap_count = count($common_keywords);

                // --- HYBRID DECISION (v2.6.2 Refined) ---
                $is_cannibal = false;
                $reason = '';
                $urgency = 'medium';

                if ($similarity >= $max_sim) {
                    $is_cannibal = true;
                    $reason = 'Near-Duplicate Sémantique (IA)';
                    $urgency = 'high';
                } elseif ($overlap_count >= 2 && $similarity >= 0.85) {
                    $is_cannibal = true;
                    $reason = 'Conflit GSC & Thématique';
                } elseif ($overlap_count === 1 && $similarity >= 0.90) {
                    $is_cannibal = true;
                    $reason = 'Risque de Cannibalisation Focalisée';
                }

                if ($is_cannibal) {
                    $conflicts[] = [
                        'source_id' => $p1['id'],
                        'source_title' => $p1['title'],
                        'target_id' => $p2['id'],
                        'target_title' => $p2['title'],
                        'similarity' => round($similarity, 3),
                        'overlap' => $overlap_count,
                        'common_kws' => implode(', ', $common_keywords),
                        'reason' => $reason,
                        'urgency' => $urgency
                    ];

                    // Journaliser l'alerte (avec protection contre les doublons récents)
                    if (class_exists('SIL_Action_Logger')) {
                        // On ne loggue que si un log similaire n'a pas été fait aujourd'hui
                        $lock_key = 'sil_log_lock_' . md5($p1['id'] . $p2['id'] . $reason);
                        if (get_transient($lock_key) === false) {
                            SIL_Action_Logger::log_action(
                                'cannibalization_alert',
                                $p1['id'],
                                $p2['id'],
                                ['similarity' => $similarity, 'overlap' => $overlap_count, 'keywords' => $common_keywords],
                                ['reason' => $reason, 'urgency' => $urgency],
                                'alert'
                            );
                            set_transient($lock_key, 'locked', DAY_IN_SECONDS);
                        }
                    }
                }
            }
        }

        update_option('sil_cannibalization_alerts_count', count($conflicts));
        return $conflicts;
    }

    /**
     * Reads the last N lines of the PHP error log if possible.
     */
    private function get_recent_php_errors($count = 10) {
        $log_file = WP_CONTENT_DIR . '/debug.log';
        if (!file_exists($log_file) || !is_readable($log_file)) {
            return ["Log inaccessible ou vide (WP_DEBUG_LOG doit être actif)."];
        }

        $lines = [];
        $file = fopen($log_file, 'r');
        if (!$file) return ["Impossible d'ouvrir le fichier de log."];

        fseek($file, 0, SEEK_END);
        $pos = ftell($file);
        $buffer = "";
        
        while ($pos > 0 && count($lines) < $count) {
            $pos--;
            fseek($file, $pos);
            $char = fgetc($file);
            if ($char === "\n") {
                if (trim($buffer)) $lines[] = trim($buffer);
                $buffer = "";
            } else {
                $buffer = $char . $buffer;
            }
        }
        if (trim($buffer)) $lines[] = trim($buffer);
        fclose($file);

        return array_reverse($lines);
    }

    /**
     * Checks database for defective Gutenberg HTML (empty paragraphs from old AI bug).
     * Uses MD5 hashing to skip unchanged posts, reducing server load.
     */
    public function check_html_integrity($limit = 50) {
        global $wpdb;
        $current_types = ($this->main && !empty($this->main->post_types)) ? $this->main->post_types : ['post', 'page'];
        $post_types = "'" . implode("','", array_map('esc_sql', $current_types)) . "'";

        // One-time auto-purge for the broken v1 hash bug (forces a true rescan for everyone)
        if (!get_option('sil_integrity_hash_purged_v2')) {
            $wpdb->query("DELETE FROM $wpdb->postmeta WHERE meta_key = '_sil_html_integrity_hash'");
            update_option('sil_integrity_hash_purged_v2', true, false);
        }

        // Get posts that either have NO integrity hash, or their last modified date moved
        $posts_to_scan = $wpdb->get_results("
            SELECT p.ID, p.post_content
            FROM $wpdb->posts p
            LEFT JOIN $wpdb->postmeta pm ON p.ID = pm.post_id AND pm.meta_key = '_sil_html_integrity_hash'
            WHERE p.post_type IN ($post_types)
            AND p.post_status = 'publish'
            AND (pm.meta_value IS NULL OR pm.meta_value != MD5(p.post_content))
            LIMIT " . (int)$limit . "
        ");

        $corrupted_count = 0;
        $clean_count = 0;

        foreach ($posts_to_scan as $post) {
            $current_hash = md5($post->post_content);
            $content = $post->post_content;
            $is_corrupted = false;

            $error_type = '';
            // Definition of defect: 
            // 1. A wp:paragraph comment exists, but it is empty/malformed
            // 2. A nested wp:paragraph block (Double encapsulation bug)
            if (strpos($content, '<!-- wp:paragraph') !== false) {
                // BUG 2: Nested blocks (Double Encapsulation) - Most common and dangerous
                // We look for an opening tag followed by another opening tag BEFORE the closing tag.
                if (preg_match('/<!-- wp:paragraph(?: [^>]*)?-->\s*<!-- wp:paragraph/is', $content, $m)) {
                    $is_corrupted = true;
                    $error_type = 'Bloc imbriqué (Double encapsulation)';
                }
                // BUG 1: Empty blocks or malformed paragraph tags
                elseif (preg_match_all('/<!-- wp:paragraph(?: [^>]*)?-->(.*?)<!-- \/wp:paragraph -->/is', $content, $matches)) {
                    foreach ($matches[1] as $idx => $inner) {
                        $clean_inner = trim(wp_strip_all_tags($inner));
                        
                        // Scenario A: Totally empty after strip_tags (ignoring whitespace)
                        // Scenario B: No <p> tag but has some content
                        // Scenario C: Contains <p></p> but no real text
                        if (empty($clean_inner)) {
                            $is_corrupted = true;
                            $error_type = 'Paragraphe vide (Contenu manquant)';
                            $problem_part = $matches[0][$idx];
                            $block_index = $idx;
                            break;
                        }
                        
                        // If it has content but no <p> tag (Classic Editor content inside Gutenberg comment - invalid)
                        if (strpos($inner, '<p') === false && !empty($clean_inner)) {
                            $is_corrupted = true;
                            $error_type = 'Bloc Gutenberg mal formé (Pas de balise <p>)';
                            $problem_part = $matches[0][$idx];
                            $block_index = $idx;
                            break;
                        }
                    }
                }
            }

            if ($is_corrupted) {
                update_post_meta($post->ID, '_sil_html_corrupted', 'yes');
                update_post_meta($post->ID, '_sil_html_error_type', $error_type);
                
                // Final context capture: Get 60 chars before and 60 chars after the problematic block
                $problem_part = $problem_part ?: (isset($m[0]) ? $m[0] : (isset($matches[0][0]) ? $matches[0][0] : ''));
                $pos = self::mb_strpos_safe($content, $problem_part);
                
                if ($pos !== false) {
                    $start = max(0, $pos - 80);
                    $context = mb_substr($content, $start, mb_strlen($problem_part) + 160, 'UTF-8');
                    $snippet = ($start > 0 ? '...' : '') . $context . (mb_strlen($content) > ($start + mb_strlen($context)) ? '...' : '');
                } else {
                    // Fallback using the calculated snippet if positional context fails
                    $snippet = $this->extract_paragraph_snippet($content, $block_index, $error_type === 'Bloc imbriqué (Double encapsulation)');
                }
                
                if (empty(trim(wp_strip_all_tags($snippet)))) {
                    $snippet = mb_substr(wp_strip_all_tags($problem_part), 0, 150, 'UTF-8') ?: "Contenu non-segmenté (Détails: " . mb_substr($problem_part, 0, 50) . ")";
                }

                update_post_meta($post->ID, '_sil_html_error_snippet', $snippet);
                $corrupted_count++;
            } else {
                delete_post_meta($post->ID, '_sil_html_corrupted');
                delete_post_meta($post->ID, '_sil_html_error_type');
                delete_post_meta($post->ID, '_sil_html_error_snippet');
                $clean_count++;
            }

            // Save the new hash to avoid rescanning
            update_post_meta($post->ID, '_sil_html_integrity_hash', $current_hash);
        }

        // Count TOTAL corrupted posts in the entire DB
        $total_corrupted = (int) $wpdb->get_var("
            SELECT COUNT(*) FROM $wpdb->postmeta 
            WHERE meta_key = '_sil_html_corrupted' AND meta_value = 'yes'
        ");

        return [
            'scanned' => count($posts_to_scan),
            'corrupted_found_now' => $corrupted_count,
            'clean_found_now' => $clean_count,
            'total_corrupted' => $total_corrupted
        ];
    }

    /**
     * Identifie les silos avec un taux de fuite sémantique élevé.
     * Une fuite = un lien pointant vers un article d'un silo différent.
     */
    public function get_silo_leaks() {
        global $wpdb;
        $table_links = $wpdb->prefix . 'sil_links';
        $table_membership = $wpdb->prefix . 'sil_silo_membership';

        // 1. Décompte des liens par silo source (Inter-silo vs Intra-silo)
        $results = $wpdb->get_results("
            SELECT 
                m1.silo_id as source_silo,
                COUNT(l.id) as total_links,
                SUM(CASE WHEN m1.silo_id != m2.silo_id THEN 1 ELSE 0 END) as leaked_links
            FROM $table_links l
            INNER JOIN $table_membership m1 ON l.source_id = m1.post_id AND m1.is_primary = 1
            INNER JOIN $table_membership m2 ON l.target_id = m2.post_id AND m2.is_primary = 1
            WHERE l.status = 'valid'
            GROUP BY m1.silo_id
        ");

        if (empty($results)) return [];

        $silo_manager = $this->main->semantic_silos;
        $labels = $silo_manager->get_silo_labels();
        $leaks = [];

        foreach ($results as $r) {
            $ratio = ($r->total_links > 0) ? ($r->leaked_links / $r->total_links) * 100 : 0;
            if ($ratio > 15) { // Seuil de fuite à 15%
                $leaks[] = [
                    'silo_id' => (int)$r->source_silo,
                    'label' => $labels[$r->source_silo] ?? "Silo " . $r->source_silo,
                    'ratio' => round($ratio, 1),
                    'count' => (int)$r->leaked_links
                ];
            }
        }

        usort($leaks, function($a, $b) { return $b['ratio'] - $a['ratio']; });
        return $leaks;
    }

    /**
     * Identifie les articles en "Content Decay" (forte visibilité, faible engagement).
     */
    public function get_content_decay($limit = 5) {
        global $wpdb;
        $table_gsc = $wpdb->prefix . 'sil_gsc_data';
        
        // BMAD Phase 0.5: Exclude recent posts (< 90 days)
        $grace_period_decay = date('Y-m-d H:i:s', strtotime('-90 days'));

        $results = $wpdb->get_results($wpdb->prepare("
            SELECT g.post_id, p.post_title, g.impressions, g.clicks
            FROM $table_gsc g
            INNER JOIN $wpdb->posts p ON g.post_id = p.ID
            WHERE p.post_status = 'publish'
            AND p.post_modified < %s
            AND g.impressions > 300
            ORDER BY (g.clicks / g.impressions) ASC, g.impressions DESC
            LIMIT %d
        ", $grace_period_decay, (int)$limit));

        $decay = [];
        foreach ($results as $r) {
            $ctr = ($r->impressions > 0) ? ($r->clicks / $r->impressions) * 100 : 0;
            if ($ctr < 1.5) { // Heuristique : moins de 1.5% de CTR sur des contenus vus
                $decay[] = [
                    'id' => (int)$r->post_id,
                    'title' => $r->post_title,
                    'impressions' => (int)$r->impressions,
                    'ctr' => round($ctr, 2)
                ];
            }
        }

        return $decay;
    }

    /**
     * Extrait un snippet contextuel à partir de l'index du bloc fautif
     */
    private function extract_paragraph_snippet($html, $index, $is_nested = false) {
        if ($index < 0) {
            return mb_substr(wp_strip_all_tags($html), 0, 150, 'UTF-8');
        }

        // Pour les blocs imbriqués, on cherche l'endroit du crime via regex
        if ($is_nested) {
            if (preg_match('/<!-- wp:paragraph(?: [^>]*)?-->\s*<!-- wp:paragraph/is', $html, $m, PREG_OFFSET_CAPTURE)) {
                $pos = $m[0][1];
                $start = max(0, $pos - 40);
                return '...' . mb_substr($html, $start, 120, 'UTF-8') . '...';
            }
        }

        // Pour les paragraphes vides/mal formés
        if (preg_match_all('/<!-- wp:paragraph(?: [^>]*)?-->(.*?)<!-- \/wp:paragraph -->/is', $html, $matches)) {
            if (isset($matches[0][$index])) {
                $block = $matches[0][$index];
                $inner = $matches[1][$index];
                $clean_inner = trim(wp_strip_all_tags($inner));
                
                if (empty($clean_inner)) {
                    return "Bloc vide identifié à l'index $index : " . mb_substr($block, 0, 100, 'UTF-8') . '...';
                }
                return mb_substr($clean_inner, 0, 200, 'UTF-8');
            }
        }

        return '';
    }


    /**
     * Identifie les articles Siphons (reçoivent du jus mais n'en renvoient pas vers leur silo).
     * Phase 0: Stabilisation Topologique.
     */
    public function get_siphons($limit = 5) {
        global $wpdb;
        $table_links = $wpdb->prefix . 'sil_links';
        $post_types = ($this->main && !empty($this->main->post_types)) ? $this->main->post_types : ['post', 'page'];
        $types_sql = "'" . implode("','", array_map('esc_sql', $post_types)) . "'";

        // Un Siphon est un article qui :
        // 1. Reçoit au moins un lien (target_id)
        // 2. N'envoie aucun lien (source_id)
        // 3. Est publié
        $results = $wpdb->get_results("
            SELECT p.ID, p.post_title, 
                   (SELECT COUNT(*) FROM $table_links l1 WHERE l1.target_id = p.ID AND l1.status = 'valid') as in_count
            FROM $wpdb->posts p
            LEFT JOIN $table_links l2 ON p.ID = l2.source_id AND l2.status = 'valid'
            WHERE p.post_type IN ($types_sql)
            AND p.post_status = 'publish'
            AND l2.source_id IS NULL -- Pas de liens sortants
            HAVING in_count > 0 -- Reçoit des liens
            ORDER BY in_count DESC
            LIMIT " . (int)$limit
        );

        $siphons = [];
        foreach ($results as $r) {
            $siphons[] = [
                'id' => (int)$r->ID,
                'title' => $r->post_title,
                'in_count' => (int)$r->in_count
            ];
        }

        return $siphons;
    }

    /**
     * Identifie les Intrus (pages mal maillées sémantiquement).
     * Phase 0: Stabilisation Topologique.
     */
    public function get_intruders($limit = 5) {
        global $wpdb;
        $table_membership = $wpdb->prefix . 'sil_silo_membership';
        $table_embeddings = $wpdb->prefix . 'sil_embeddings';
        $post_types = ($this->main && !empty($this->main->post_types)) ? $this->main->post_types : ['post', 'page'];
        $types_sql = "'" . implode("','", array_map('esc_sql', $post_types)) . "'";

        // 1. Charger les labels des silos
        $silo_engine = $this->main->semantic_silos;
        $silo_labels = $silo_engine->get_silo_labels();

        // 2. Récupérer les articles avec leur silo actuel et leur embedding
        $posts = $wpdb->get_results("
            SELECT m.post_id, m.silo_id, e.embedding, p.post_title
            FROM $table_membership m
            INNER JOIN $table_embeddings e ON m.post_id = e.post_id
            INNER JOIN $wpdb->posts p ON m.post_id = p.ID
            WHERE p.post_status = 'publish' AND p.post_type IN ($types_sql)
            AND m.is_primary = 1
        ");

        if (empty($posts)) return [];

        // 3. Charger les centroïdes des silos
        $centroids = $silo_engine->get_silo_centroids();
        if (empty($centroids)) return [];

        $intruders = [];
        foreach ($posts as $p) {
            $emb = json_decode($p->embedding, true);
            if (empty($emb)) continue;

            $current_silo = (int)$p->silo_id;
            $best_silo = $current_silo;
            $best_sim = -1;
            
            // Calculer la similarité avec tous les silos
            $current_sim = 0;
            foreach ($centroids as $silo_id => $centroid) {
                $sim = $this->main->cosine_similarity($emb, $centroid);
                
                if ($silo_id === $current_silo) {
                    $current_sim = $sim;
                }

                if ($sim > $best_sim) {
                    $best_sim = $sim;
                    $best_silo = $silo_id;
                }
            }

            // Si le silo idéal est différent du silo actuel
            if ($best_silo !== $current_silo) {
                // Vérifier si un lien existe déjà vers le silo cible
                $has_bridge = (bool) $wpdb->get_var($wpdb->prepare("
                    SELECT l.id 
                    FROM {$wpdb->prefix}sil_links l
                    INNER JOIN $table_membership m ON l.target_id = m.post_id
                    WHERE l.source_id = %d 
                    AND m.silo_id = %d 
                    AND l.status = 'valid'
                    LIMIT 1
                ", $p->post_id, $best_silo));

                $intruders[] = [
                    'id' => (int)$p->post_id,
                    'title' => $p->post_title,
                    'current_silo_id' => $current_silo,
                    'current_silo_label' => $silo_labels[$current_silo] ?? "Silo $current_silo",
                    'ideal_silo_id' => $best_silo,
                    'ideal_silo_label' => $silo_labels[$best_silo] ?? "Silo $best_silo",
                    'similarity' => round($best_sim, 3),
                    'current_similarity' => round($current_sim, 3),
                    'has_bridge_to_target' => $has_bridge
                ];
            }
        }

        // Trier par similarité (les plus "égarés")
        usort($intruders, function($a, $b) { return $b['similarity'] <=> $a['similarity']; });

        return array_slice($intruders, 0, $limit);
    }

    /**
     * Find the best potential link sources for a target post using semantic proximity.
     * v2.6 - Targeted Semantic Discovery
     */
    public function find_best_sources_for_target($target_id, $limit = 5) {
        global $wpdb;
        $table_embeddings = $wpdb->prefix . 'sil_embeddings';
        $table_links = $wpdb->prefix . 'sil_links';
        $table_membership = $wpdb->prefix . 'sil_silo_membership';

        // ELITE CHECK: Drip-Feed Lock
        if ($this->is_drip_feed_locked($target_id)) {
            return ['error' => 'drip_feed_locked', 'message' => 'Cette cible est en période de refroidissement (Drip-Feed).'];
        }

        // 1. Get Target Context (Embedding + Silo)
        $target_data = $wpdb->get_row($wpdb->prepare("
            SELECT e.embedding, m.silo_id 
            FROM $table_embeddings e
            LEFT JOIN $table_membership m ON e.post_id = m.post_id AND m.is_primary = 1
            WHERE e.post_id = %d", $target_id));

        if (!$target_data || !$target_data->embedding) return [];
        $target_emb = json_decode($target_data->embedding, true);
        $target_silo_id = (int)$target_data->silo_id;

        // 2. Get All Other Published Posts with Embeddings and Silo Info
        $candidates = $wpdb->get_results("
            SELECT e.post_id, e.embedding, p.post_title, m.silo_id
            FROM $table_embeddings e
            INNER JOIN $wpdb->posts p ON e.post_id = p.ID
            LEFT JOIN $table_membership m ON e.post_id = m.post_id AND m.is_primary = 1
            WHERE e.post_id != " . (int)$target_id . "
            AND p.post_status = 'publish'
        ");

        // 3. Filter out existing and planned linkers
        // A. Real links (Manual or Auto)
        $existing_sources = $wpdb->get_col($wpdb->prepare("SELECT source_id FROM $table_links WHERE target_id = %d", $target_id));
        
        // B. Scheduled links (Incubator)
        $table_scheduled = $wpdb->prefix . 'sil_scheduled_links';
        $scheduled_sources = $wpdb->get_col($wpdb->prepare("SELECT source_id FROM $table_scheduled WHERE target_id = %d", $target_id));
        
        // C. Reciprocal links (Avoid loops: Target -> Source exists)
        $reciprocal_targets = $wpdb->get_col($wpdb->prepare("SELECT target_id FROM $table_links WHERE source_id = %d", $target_id));

        $scored = [];
        foreach ($candidates as $c) {
            $cid = (int)$c->post_id;
            
            // Exclusion logic
            if (in_array($cid, $existing_sources)) continue;
            if (in_array($cid, $scheduled_sources)) continue;
            if (in_array($cid, $reciprocal_targets)) continue; // Option C: No reciprocal loops

            $emb = json_decode($c->embedding, true);
            if (empty($emb)) continue;

            $sim = $this->main->cosine_similarity($target_emb, $emb);
            $scored[] = [
                'id' => $cid,
                'title' => $c->post_title,
                'similarity' => round($sim * 100, 1),
                'is_same_silo' => ((int)$c->silo_id === $target_silo_id && $target_silo_id > 0)
            ];
        }

        // 4. Sort and Limit
        usort($scored, function($a, $b) { return $b['similarity'] <=> $a['similarity']; });
        return array_slice($scored, 0, $limit);
    }

    /**
     * Phase 0 Gate Status — Vérifie si toutes les conditions de déblocage sont remplies.
     * Retourne les compteurs d'anomalies et le statut du verrou.
     */
    public function check_gate_status() {
        $intruders = $this->get_intruders(999);
        $siphons   = $this->get_siphons(999);
        $orphans   = $this->get_high_potential_orphans(999);

        $intruders_count = count($intruders);
        $siphons_count   = count($siphons);
        $orphans_urgent  = count(array_filter($orphans, function($o) {
            return !empty($o['is_urgent_index']) && $o['is_urgent_index'] === true;
        }));

        $gate_unlocked = ($intruders_count === 0 && $siphons_count === 0 && $orphans_urgent === 0);

        return [
            'intruders_count' => $intruders_count,
            'siphons_count'   => $siphons_count,
            'orphans_urgent'  => $orphans_urgent,
            'gate_unlocked'   => $gate_unlocked
        ];
    }
}
