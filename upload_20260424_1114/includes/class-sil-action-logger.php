<?php
/**
 * SIL_Action_Logger
 * Moteur de capture de ROI et journalisation des actions SEO. v2.5
 */

if (!defined('ABSPATH')) {
    exit;
}

class SIL_Action_Logger {

    /**
     * Capture un instantané des statistiques GSC et de l'autorité interne d'un article.
     * 
     * @param int $post_id
     * @return array
     */
    public static function capture_post_snapshot($post_id) {
        global $wpdb;
        
        // 1. Stats GSC
        $gsc_data = get_post_meta($post_id, '_sil_gsc_data', true);
        if (is_string($gsc_data)) {
            $gsc_data = json_decode($gsc_data, true);
        }

        // 2. Membre de Silo (Primary)
        $silo_info = $wpdb->get_row($wpdb->prepare(
            "SELECT silo_id, score FROM {$wpdb->prefix}sil_silo_membership WHERE post_id = %d AND is_primary = 1",
            $post_id
        ));

        // 3. Centralité (Pagerank interne approximatif via nombre de liens entrants)
        $inbound_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sil_links WHERE target_id = %d",
            $post_id
        ));

        return [
            'gsc' => [
                'clicks'      => isset($gsc_data['stats']['clicks']) ? (int)$gsc_data['stats']['clicks'] : 0,
                'impressions' => isset($gsc_data['stats']['impressions']) ? (int)$gsc_data['stats']['impressions'] : 0,
                'position'    => isset($gsc_data['stats']['position']) ? (float)$gsc_data['stats']['position'] : 0,
            ],
            'structure' => [
                'silo_id'       => $silo_info ? (int)$silo_info->silo_id : null,
                'silo_score'    => $silo_info ? (float)$silo_info->score : 0,
                'inbound_links' => $inbound_count,
            ],
            'timestamp' => current_time('mysql')
        ];
    }

    /**
     * Enregistre une action dans le journal.
     * 
     * @param string $type ('adopt', 'bridge', 'rewrite', 'seal')
     * @param int|null $source_id
     * @param int|null $target_id
     * @param array $meta Données additionnelles (ex: hypothèse de gain)
     * @return int|false ID de l'entrée de log
     */
    public static function log_action($type, $source_id = null, $target_id = null, $meta = []) {
        global $wpdb;

        $target_to_snapshot = $target_id ? $target_id : $source_id;
        if (!$target_to_snapshot) return false;

        $initial_stats = self::capture_post_snapshot($target_to_snapshot);

        $result = $wpdb->insert(
            $wpdb->prefix . 'sil_action_log',
            [
                'action_type'    => $type,
                'post_id_source' => $source_id,
                'post_id_target' => $target_id,
                'initial_stats'  => json_encode($initial_stats),
                'expected_gain'  => json_encode($meta),
                'status'         => 'incubation',
                'timestamp'      => current_time('mysql')
            ],
            ['%s', '%d', '%d', '%s', '%s', '%s', '%s']
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Récupère les dernières actions logguées.
     */
    public static function get_recent_actions($limit = 10) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sil_action_log ORDER BY timestamp DESC LIMIT %d",
            $limit
        ));
    }
}
