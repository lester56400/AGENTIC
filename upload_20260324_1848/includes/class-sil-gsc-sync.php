<?php
if (!defined('ABSPATH')) {
    exit;
}

class Sil_Gsc_Sync
{
    private $property_url;
    private $client_id;
    private $client_secret;

    public function __construct()
    {
        $this->client_id = get_option('sil_gsc_client_id');
        $this->client_secret = get_option('sil_gsc_client_secret');
        $this->property_url = get_option('sil_gsc_property_url');

        // Register Cron Hook
        add_action('sil_monthly_index_check', [$this, 'cron_check_indexing_status']);
    }

    /**
     * Teste si la configuration GSC est valide et si l'API est accessible.
     */
    public function is_configured()
    {
        return !empty($this->client_id) && !empty($this->client_secret) && !empty($this->property_url);
    }

    /**
     * Vérifie le statut d'indexation via cron mensuel 
     * Batch de post pour ne pas bloquer
     */
    public function cron_check_indexing_status()
    {
        error_log('SIL Debug: Lancement du cron mensuel d\'indexation GSC');
        $posts = get_posts([
            'post_type' => ['post', 'page'],
            'post_status' => 'publish',
            'posts_per_page' => 100, // Limite pour batching
            'fields' => 'ids'
        ]);

        $this->check_indexing_status($posts);
    }

    /**
     * Vérifie le statut d'indexation d'une liste de posts
     * @param array $post_ids
     */
    public function check_indexing_status($post_ids = [])
    {
        if (empty($post_ids) || !$this->is_configured()) {
            return false;
        }

        try {
            $handler = new \Sil_Gsc_Handler();
            foreach ($post_ids as $post_id) {
                $url = get_permalink($post_id);
                if (!$url)
                    continue;

                $inspection = $handler->inspect_url($this->property_url, $url);

                // Resultat structuré par l'API URL Inspection Google
                if (isset($inspection['inspectionResult']['indexStatusResult']['coverageState'])) {
                    $status = $inspection['inspectionResult']['indexStatusResult']['coverageState'];
                    update_post_meta($post_id, '_sil_gsc_index_status', sanitize_text_field($status)); // [Security-Fix]
                }

                // Sleep pour eviter rate limit API Google Indexing
                sleep(1);
            }
        } catch (\Exception $e) {
            error_log('SIL Error (Indexing Status): ' . $e->getMessage());
        }
    }

    /**
     * Récupère et met à jour les données GSC (Tâche Cron ou Manuelle batchée)
     * @param array $post_ids Optionnel : Liste d'IDs à synchroniser. Si vide, on synchronise tout.
     */
    public function sync_data($post_ids = [])
    {
        error_log('SIL Debug: Lancement du calcul GSC');
        set_time_limit(300);

        if (!$this->is_configured()) {
            error_log('SIL Debug: GSC non configuré, annulation de sync_data');
            return false;
        }

        try {
            $handler = new \Sil_Gsc_Handler();

            // Paramétrage de la période : 3 derniers mois (90 jours) en Glissement Annuel
            $endDate = date('Y-m-d', strtotime('-2 days')); // GSC a souvent 2 jours de délai
            $startDate = date('Y-m-d', strtotime('-92 days'));

            $request_body = [
                'startDate' => $startDate,
                'endDate' => $endDate,
                'dimensions' => ['page', 'query'],
                'rowLimit' => 5000
            ];

            // Configuration pour la période précédente (YoY: Year-over-Year)
            // Les mêmes 90 jours de l'année précédente
            $prevEndDate = date('Y-m-d', strtotime('-367 days'));
            $prevStartDate = date('Y-m-d', strtotime('-457 days'));

            $prev_request_body = [
                'startDate' => $prevStartDate,
                'endDate' => $prevEndDate,
                'dimensions' => ['page', 'query'],
                'rowLimit' => 5000
            ];

            // Filter specific URLs if post IDs are provided (Batching)
            if (!empty($post_ids)) {
                $urls = [];
                foreach ($post_ids as $pid) {
                    $permalink = get_permalink($pid);
                    if ($permalink) {
                        $urls[] = preg_quote(untrailingslashit($permalink), '/');
                        $urls[] = preg_quote(trailingslashit($permalink), '/');
                    }
                }

                if (!empty($urls)) {
                    // Create a regex to match exact URLs in the batch
                    $regex = '^(' . implode('|', $urls) . ')$';

                    $dimensionFilter = [
                        'dimensionGroups' => [
                            [
                                'groupType' => 'and',
                                'filters' => [
                                    [
                                        'dimension' => 'page',
                                        'operator' => 'includingRegex',
                                        'expression' => $regex
                                    ]
                                ]
                            ]
                        ]
                    ];

                    $request_body['dimensionFilterGroups'] = $dimensionFilter['dimensionGroups'];
                    $prev_request_body['dimensionFilterGroups'] = $dimensionFilter['dimensionGroups'];
                }
            }

            $response = $handler->query_search_analytics($this->property_url, $request_body);
            $rows = $response['rows'] ?? [];

            $prevResponse = $handler->query_search_analytics($this->property_url, $prev_request_body);
            $prevRows = $prevResponse['rows'] ?? [];

            if (empty($rows)) {
                return true; // Rien à synchroniser
            }

            // Agrégation des données par URL (période actuelle)
            $pages_data = [];

            foreach ($rows as $row) {
                $keys = $row['keys'] ?? [];
                if (count($keys) < 2)
                    continue;

                $url = $keys[0];
                $query = $keys[1];

                if (!isset($pages_data[$url])) {
                    $pages_data[$url] = [
                        'clicks' => 0,
                        'impressions' => 0,
                        'position_sum' => 0,
                        'position_weight' => 0,
                        'queries' => []
                    ];
                }

                $clicks = $row['clicks'] ?? 0;
                $impressions = $row['impressions'] ?? 0;
                $position = $row['position'] ?? 0;

                $pages_data[$url]['clicks'] += $clicks;
                $pages_data[$url]['impressions'] += $impressions;
                $pages_data[$url]['position_sum'] += ($position * $impressions); // Moyenne pondérée
                $pages_data[$url]['position_weight'] += $impressions;

                $pages_data[$url]['queries'][] = [
                    'query' => sanitize_text_field($query), // [Security-Fix]
                    'clicks' => $clicks,
                    'impressions' => $impressions,
                    'position' => $position
                ];
            }

            // Agrégation des données par URL (période précédente)
            $prev_pages_data = [];
            if (!empty($prevRows)) {
                foreach ($prevRows as $row) {
                    $keys = $row['keys'] ?? [];
                    if (count($keys) < 2)
                        continue;

                    $url = $keys[0];
                    if (!isset($prev_pages_data[$url])) {
                        $prev_pages_data[$url] = [
                            'clicks' => 0,
                            'impressions' => 0,
                            'position_sum' => 0,
                            'position_weight' => 0
                        ];
                    }

                    $impressions = $row['impressions'] ?? 0;
                    $clicks = $row['clicks'] ?? 0;
                    $position = $row['position'] ?? 0;

                    $prev_pages_data[$url]['clicks'] += $clicks;
                    $prev_pages_data[$url]['impressions'] += $impressions;
                    $prev_pages_data[$url]['position_sum'] += ($position * $impressions);
                    $prev_pages_data[$url]['position_weight'] += $impressions;
                }
            }

            global $wpdb;
            $table_name = $wpdb->prefix . 'sil_gsc_data';

            // Identifier les post_ids existants pour lier les URLs
            // GSC URLs include trailing slashes, WP permalinks might vary, but we'll try exact match or url_to_postid

            foreach ($pages_data as $url => $data) {
                // Trier les requêtes par clics puis impressions
                usort($data['queries'], function ($a, $b) {
                    if ($a['clicks'] == $b['clicks']) {
                        return $b['impressions'] <=> $a['impressions'];
                    }
                    return $b['clicks'] <=> $a['clicks'];
                });

                $top_queries = array_slice($data['queries'], 0, 5); // Conserver le top 5

                $avg_position = $data['position_weight'] > 0 ? ($data['position_sum'] / $data['position_weight']) : 0;

                // Calcul des deltas
                $clicks_delta = 0;
                $clicks_delta_percent = 0;
                $yield_delta_percent = 0;
                $impressions_delta = 0;
                $position_delta = 0;

                // Rendement = Clics / Impressions
                $yield = $data['impressions'] > 0 ? ($data['clicks'] / $data['impressions']) : 0;

                if (isset($prev_pages_data[$url])) {
                    $prev = $prev_pages_data[$url];
                    $prev_avg_position = $prev['position_weight'] > 0 ? ($prev['position_sum'] / $prev['position_weight']) : 0;
                    $prev_yield = $prev['impressions'] > 0 ? ($prev['clicks'] / $prev['impressions']) : 0;

                    $clicks_delta = $data['clicks'] - $prev['clicks'];
                    $clicks_delta_percent = $prev['clicks'] > 0 ? (($clicks_delta / $prev['clicks']) * 100) : 0;

                    if ($prev_yield > 0) {
                        $yield_delta_percent = (($yield - $prev_yield) / $prev_yield) * 100;
                    }

                    $impressions_delta = $data['impressions'] - $prev['impressions'];
                    // Si la position baisse (ex: 2 -> 5), delta est négatif (-3)
                    // Si la position monte (ex: 5 -> 2), delta est positif (3)
                    $position_delta = $prev_avg_position - $avg_position;
                }

                // Essayer de trouver le post_id
                $post_id = url_to_postid($url);
                if (!$post_id) {
                    // Parfois l'URL GSC a ou n'a pas de trailing slash
                    $alt_url = rtrim($url, '/') === $url ? $url . '/' : rtrim($url, '/');
                    $post_id = url_to_postid($alt_url);
                }

                $wpdb->replace(
                    $table_name,
                    [
                        'post_id' => $post_id ? $post_id : 0,
                        'url' => esc_url_raw($url),
                        'clicks' => intval($data['clicks']),
                        'impressions' => intval($data['impressions']),
                        'position' => round($avg_position, 2),
                        'clicks_delta' => intval($clicks_delta),
                        'clicks_delta_percent' => round($clicks_delta_percent, 2),
                        'yield_delta_percent' => round($yield_delta_percent, 2),
                        'impressions_delta' => intval($impressions_delta),
                        'position_delta' => round($position_delta, 2),
                        'top_queries' => wp_json_encode($top_queries),
                        'last_updated' => current_time('mysql')
                    ],
                    ['%d', '%s', '%d', '%d', '%f', '%d', '%f', '%f', '%d', '%f', '%s', '%s']
                );

                if ($post_id) {
                    $meta_data = [
                        'clicks' => intval($data['clicks']),
                        'impressions' => intval($data['impressions']),
                        'position' => round($avg_position, 2),
                        'clicks_delta' => intval($clicks_delta),
                        'clicks_delta_percent' => round($clicks_delta_percent, 2),
                        'yield_delta_percent' => round($yield_delta_percent, 2),
                        'impressions_delta' => intval($impressions_delta),
                        'position_delta' => round($position_delta, 2),
                        'top_queries' => $top_queries
                    ];
                    update_post_meta($post_id, '_sil_gsc_data', wp_json_encode($meta_data));

                    // Fetch the URL Indexing Status via Inspection API
                    try {
                        if (!class_exists('Sil_Gsc_Handler')) {
                            require_once SIL_PLUGIN_DIR . 'includes/class-sil-gsc-handler.php';
                        }
                        $handler = new Sil_Gsc_Handler();

                        // Fix for undefined $site_url: Home URL is the property used in Search Console
                        $site_url = home_url('/');

                        $inspection_data = $handler->inspect_url($site_url, $url);
                        if (isset($inspection_data['inspectionResult']['indexStatusResult']['coverageState'])) {
                            update_post_meta($post_id, '_sil_gsc_index_status', sanitize_text_field($inspection_data['inspectionResult']['indexStatusResult']['coverageState'])); // [Security-Fix]
                        }
                    } catch (Exception $e) {
                        error_log('SIL URL Inspection Error for ' . $url . ': ' . $e->getMessage());
                    }

                    // Increment keywords saved counter
                    $current_saved = get_transient('sil_last_sync_keyw_count') ?: 0;
                    set_transient('sil_last_sync_keyw_count', $current_saved + count($top_queries), HOUR_IN_SECONDS);
                }
            }

            update_option('sil_gsc_last_sync', current_time('mysql'));
            return true;

        } catch (Exception $e) {
            error_log('SIL GSC API Error: ' . $e->getMessage());
            return new WP_Error('api_error', $e->getMessage());
        }
    }
}
