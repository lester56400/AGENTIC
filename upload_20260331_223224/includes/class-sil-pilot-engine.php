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
        global $wpdb;
        $post_types = $this->main->post_types;

        // 1. Exclude front/blog
        $exclude_ids = array_filter([(int)get_option('page_on_front'), (int)get_option('page_for_posts')]);
        $exclude_sql = !empty($exclude_ids) ? "AND p.ID NOT IN (" . implode(',', $exclude_ids) . ")" : "";

        // 2. Fetch candidate posts (more than needed to allow filtering)
        $candidates_to_fetch = max(100, $limit * 20);
        $results = $wpdb->get_results(
            "SELECT p.ID, p.post_title FROM $wpdb->posts p
             WHERE p.post_type IN ('" . implode("','", array_map('esc_sql', $post_types)) . "') 
             AND p.post_status = 'publish' 
             $exclude_sql
             ORDER BY p.ID DESC
             LIMIT $candidates_to_fetch"
        );

        $orphans = [];
        foreach ($results as $post) {
            // Noindex filter
            if ($this->is_noindexed($post->ID)) continue;

            // Real orphan check: use SAME logic as Cartographie (scan real HTML content)
            $real_backlinks = $this->main->count_backlinks($post->ID);
            if ($real_backlinks > 0) continue; // Has real incoming links → not an orphan

            $gsc_data = get_post_meta($post->ID, '_sil_gsc_data', true);
            if (is_string($gsc_data)) {
                $gsc_data = json_decode($gsc_data, true);
            }
            $impressions = isset($gsc_data['stats']['impressions']) ? (int)$gsc_data['stats']['impressions'] : 0;
            $orphans[] = [
                'id' => $post->ID,
                'title' => $post->post_title,
                'impressions' => $impressions
            ];

            // Early exit if we have enough
            if (count($orphans) >= $limit * 3) break;
        }

        usort($orphans, function($a, $b) { return $b['impressions'] - $a['impressions']; });
        return array_slice($orphans, 0, $limit);
    }

    /**
     * Get Strike Distance boosters (GSC 6-15).
     * Filters out 301'd or trashed posts.
     */
    public function get_gsc_boosters($limit = 5) {
        global $wpdb;
        $metas = $wpdb->get_results("SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key='_sil_gsc_data' AND meta_value != '' LIMIT 200");
        $boosters = [];

        foreach ($metas as $m) {
            // Skip posts that are no longer published (trashed, drafted, or 301'd)
            $post_status = get_post_status($m->post_id);
            if ($post_status !== 'publish') continue;

            $data = is_string($m->meta_value) ? json_decode($m->meta_value, true) : $m->meta_value;
            if (empty($data) || !isset($data['top_queries']) || !is_array($data['top_queries'])) continue;

            foreach ($data['top_queries'] as $q) {
                if (!isset($q['position']) || !isset($q['query'])) continue;
                $pos = floatval($q['position']);
                if ($pos >= 6 && $pos <= 15) {
                    $boosters[] = [
                        'post_id' => $m->post_id,
                        'title' => get_the_title($m->post_id),
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
            'logic_test' => [
                'orphan_query' => 'En attente...',
                'booster_query' => 'En attente...'
            ],
            'ajax_routes' => [
                'sil_get_pilotage_actions' => has_action('wp_ajax_sil_get_pilotage_actions') ? '✅ Enregistré' : '❌ MANQUANT',
                'sil_get_pilotage_diagnostics' => has_action('wp_ajax_sil_get_pilotage_diagnostics') ? '✅ Enregistré' : '❌ MANQUANT',
                'sil_get_action_logs' => has_action('wp_ajax_sil_get_action_logs') ? '✅ Enregistré' : '❌ MANQUANT'
            ]
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

        error_log('SIL Pilot Diagnostic: Completed successfully');

        return $diagnosis;
    }
}
