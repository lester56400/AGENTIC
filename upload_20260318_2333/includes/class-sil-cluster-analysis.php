<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SIL_Cluster_Analysis {
    public function __construct($api_key = null, $model = null) {}

    public function get_graph_data() {
        delete_transient( 'sil_graph_cache' );

        $posts = get_posts(['post_type' => ['post', 'page'], 'post_status' => 'publish', 'posts_per_page' => -1]);
        $edges_data = $this->get_edges($posts);
        
        $api_url = get_option( 'sil_infomap_api_url' );
        $infomap_map = []; // Infomap API fallback clusters
        $semantic_map = []; // Semantic silo primary assignments
        $bridge_map = [];   // Bridge posts (belong to 2 silos)

        // --- Priority 1: Semantic silo membership (Fuzzy C-Means) ---
        global $wpdb;
        $membership_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sil_silo_membership" );

        if ( $membership_count > 0 ) {
            $rows = $wpdb->get_results(
                "SELECT post_id, silo_id, is_primary, is_bridge FROM {$wpdb->prefix}sil_silo_membership WHERE is_primary = 1",
                ARRAY_A
            );
            foreach ( $rows as $row ) {
                $pid = (string) $row['post_id'];
                // Offset silo_id by 9000 to avoid collision with Infomap cluster IDs
                $semantic_map[$pid] = (string) ( (int) $row['silo_id'] + 9000 );
                $bridge_map[$pid]   = (bool) $row['is_bridge'];
            }
        }

        // --- Priority 2: Infomap API (only if semantic map is empty) ---
        if ( empty( $semantic_map ) && !empty( $api_url ) ) {
            $response = wp_remote_post( $api_url, array(
                'headers' => array( 'Content-Type' => 'application/json' ),
                'body'    => wp_json_encode( array( 'edges' => $edges_data ) ),
                'timeout' => 90
            ) );
            if ( !is_wp_error( $response ) ) {
                $body = wp_remote_retrieve_body( $response );
                $infomap_map = json_decode( $body, true ) ?: [];
            }
        }

        $clusters_map = !empty( $semantic_map ) ? $semantic_map : $infomap_map;

        $in_degrees = []; $out_degrees = [];
        foreach ( $edges_data as $edge ) {
            $s = (string)$edge['data']['source']; $t = (string)$edge['data']['target'];
            $out_degrees[$s] = ($out_degrees[$s] ?? 0) + 1;
            $in_degrees[$t] = ($in_degrees[$t] ?? 0) + 1;
        }

        $nodes_data = []; $unique_clusters = []; $cluster_cats = [];
        $cluster_edges = []; // Keep track of edges between clusters
        $cluster_sizes = []; // Keep track of nodes per cluster
        $cluster_pageranks = [];
        $post_cluster_map = [];

        // Prérépartition pour les attributs de cluster
        foreach ( $posts as $post ) {
            if ( ! is_object($post) || ! isset($post->ID) ) continue;
            $post_id = (string)$post->ID;
            $cats = get_the_category($post->ID);
            $cat_id = ( !empty($cats) && isset($cats[0]) && is_object($cats[0]) && isset($cats[0]->term_id) ) ? (string)($cats[0]->term_id + 1000) : '0';
            $cluster_id = $clusters_map[$post_id] ?? $cat_id;
            $post_cluster_map[$post_id] = $cluster_id;
            $cluster_sizes[$cluster_id] = ($cluster_sizes[$cluster_id] ?? 0) + 1;
        }


        $cluster_inter_edges = [];
        $cluster_intra_edges = [];
        $post_inter_edges = [];
        $post_intra_edges = [];
        $post_edges = []; // Track where each post sends its links

        foreach ($edges_data as $edge) {
            $s = (string)$edge['data']['source'];
            $t = (string)$edge['data']['target'];
            $s_cluster = $post_cluster_map[$s] ?? '0';
            $t_cluster = $post_cluster_map[$t] ?? '0';

            if ($s_cluster !== $t_cluster) {
                $cluster_inter_edges[$s_cluster] = ($cluster_inter_edges[$s_cluster] ?? 0) + 1;
                $post_inter_edges[$s] = ($post_inter_edges[$s] ?? 0) + 1;
                
                // Track node-level leaks
                if (!isset($post_edges[$s][$t_cluster])) $post_edges[$s][$t_cluster] = 0;
                $post_edges[$s][$t_cluster]++;

                // Calculer vers quel cluster le jus fuit le plus (semantic_target)
                if (!isset($cluster_edges[$s_cluster][$t_cluster])) {
                     $cluster_edges[$s_cluster][$t_cluster] = 0;
                }
                $cluster_edges[$s_cluster][$t_cluster]++;
            } else {
                $cluster_intra_edges[$s_cluster] = ($cluster_intra_edges[$s_cluster] ?? 0) + 1;
                $post_intra_edges[$s] = ($post_intra_edges[$s] ?? 0) + 1;
            }
        }

        $cluster_permeabilities = [];
        $cluster_semantic_targets = [];

        foreach (array_keys($cluster_sizes) as $cid) {
            $inter = $cluster_inter_edges[$cid] ?? 0;
            $intra = $cluster_intra_edges[$cid] ?? 0;
            $total_cluster_edges = $inter + $intra;
            
            // Perméabilité = % de liens qui sortent du cluster
            $cluster_permeabilities[$cid] = $total_cluster_edges > 0 ? round(($inter / $total_cluster_edges) * 100) : 0;
            
            // Trouver la cible sémantique principale des fuites
            $semantic_target = null;
            if (isset($cluster_edges[$cid]) && !empty($cluster_edges[$cid])) {
                $max_leaks = 0;
                foreach($cluster_edges[$cid] as $target_cid => $leak_count) {
                    if ($leak_count > $max_leaks) {
                        $max_leaks = $leak_count;
                        $semantic_target = $target_cid;
                    }
                }
            }
            $cluster_semantic_targets[$cid] = $semantic_target;
        }

        $unique_clusters = [];
        $cluster_cats = [];
        $silo_titles = [];

        foreach ( $posts as $post ) {
            if ( ! is_object($post) || ! isset($post->ID) ) continue;
            $post_id = (string)$post->ID;
            $cluster_id = $post_cluster_map[$post_id] ?? '0';
            
            $unique_clusters[$cluster_id] = true;
            $cats = get_the_category($post->ID);
            if (!empty($cats) && is_object($cats[0]) && isset($cats[0]->name)) { $cluster_cats[$cluster_id][] = $cats[0]->name; }

            // Capturer le premier titre pour le nommage de secours du Silo
            if (!isset($silo_titles[$cluster_id])) {
                $silo_titles[$cluster_id] = get_the_title($post->ID);
            }

            $in = $in_degrees[$post_id] ?? 0;
            $out = $out_degrees[$post_id] ?? 0;
            $tags = [];
            if ($in === 0) $tags[] = 'orphan';
            if ($in > 5 && $out === 0) $tags[] = 'siphon';
            
            $p_inter = $post_inter_edges[$post_id] ?? 0;
            $p_intra = $post_intra_edges[$post_id] ?? 0;
            $is_intruder_bool = ($p_inter > 0 && $p_intra === 0);

            $node_semantic_target = null;
            if ($is_intruder_bool && isset($post_edges[$post_id])) {
                $max_leaks = 0;
                foreach($post_edges[$post_id] as $t_cid => $count) {
                    if ($count > $max_leaks) {
                        $max_leaks = $count;
                        $node_semantic_target = $t_cid;
                    }
                }
            }

            $is_strategic = get_post_meta($post->ID, '_sil_is_cornerstone', true) === '1';

            $gsc_data_raw = get_post_meta($post->ID, '_sil_gsc_data', true);
            $gsc_data = is_array($gsc_data_raw) ? $gsc_data_raw : json_decode($gsc_data_raw, true);
            if (empty($gsc_data) && is_string($gsc_data_raw)) {
                $gsc_data = function_exists('maybe_unserialize') ? maybe_unserialize($gsc_data_raw) : unserialize($gsc_data_raw);
            }
            
            $imp = 0;
            if (is_array($gsc_data)) {
                $rows = isset($gsc_data['top_queries']) ? $gsc_data['top_queries'] : $gsc_data;
                if (is_array($rows)) {
                    foreach($rows as $r) $imp += intval($r['impressions'] ?? 0);
                }
            }

            $sil_pagerank = $in * 10 + $imp; // Fallback score

            $nodes_data[] = [
                'data' => [
                    'id' => $post_id, 'parent' => 'silo_'.$cluster_id, 'cluster_id' => $cluster_id,
                    'label' => get_the_title($post->ID), 'in_degree' => $in, 'out_degree' => $out,
                    'tags' => $tags, 'gsc_impressions' => $imp,
                    'cluster_permeability' => $cluster_permeabilities[$cluster_id],
                    'semantic_target' => $cluster_semantic_targets[$cluster_id],
                    'node_semantic_target' => $node_semantic_target,
                    'sil_pagerank' => $sil_pagerank,
                    'is_strategic' => $is_strategic ? 'true' : 'false',
                    'is_orphan' => in_array('orphan', $tags) ? 'true' : 'false',
                    'is_siphon' => in_array('siphon', $tags) ? 'true' : 'false',
                    'is_intruder' => $is_intruder_bool ? 'true' : 'false',
                    'is_bridge' => ($bridge_map[$post_id] ?? false) ? 'true' : 'false',
                    'is_semantic_silo' => !empty($semantic_map) ? 'true' : 'false',
                ]
            ];

        }

        // 4. Création des Halos avec Nommage Garanti
        foreach ( array_keys($unique_clusters) as $cid ) {
            $halo_label = "Silo $cid";

            if (isset($cluster_cats[$cid]) && !empty($cluster_cats[$cid])) {
                // Si on a des catégories, on prend la dominante
                $counts = array_count_values($cluster_cats[$cid]);
                arsort($counts);
                $dominant_cat = array_key_first($counts);
                $halo_label = "Thématique : " . $dominant_cat;
            } elseif (isset($silo_titles[$cid])) {
                // Si on a PAS de catégories (ex: 9001), on utilise le titre du premier article
                $short_title = wp_trim_words($silo_titles[$cid], 4, '...');
                $halo_label = "Sujet : " . $short_title;
            }

            $nodes_data[] = [
                'data' => [
                    'id'             => 'silo_' . $cid,
                    'label'          => $halo_label,
                    'is_silo_parent' => "true",
                    'cluster_id'     => (string) $cid
                ]
            ];
        }

        return ['nodes' => $nodes_data, 'edges' => $edges_data];
    }

    private function get_edges($posts) {
        $edges = array();
        $post_ids = wp_list_pluck($posts, 'ID');
        $site_host = parse_url(home_url(), PHP_URL_HOST);

        foreach ($posts as $post) {
            $content = do_shortcode($post->post_content); 
            // Regex améliorée : capture l'URL ET l'ancre (texte du lien)
            preg_match_all('/<a[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $content, $matches);

            if (!empty($matches[1])) {
                foreach ($matches[1] as $index => $url) {
                    $anchor = wp_strip_all_tags($matches[2][$index]);
                    $parsed = parse_url($url);
                    $is_internal = false;

                    if (isset($parsed['host']) && strtolower($parsed['host']) === strtolower($site_host)) {
                        $is_internal = true;
                    } elseif (!isset($parsed['host']) && isset($parsed['path']) && strpos($parsed['path'], '/') === 0) {
                        $is_internal = true;
                    }

                    if ($is_internal) {
                        $target_id = url_to_postid($url);
                        
                        if (!$target_id && isset($parsed['path'])) {
                            $slug = basename(trim($parsed['path'], '/'));
                            global $wpdb;
                            $target_id = (int) $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_status = 'publish' LIMIT 1", $slug));
                        }

                        // Contrôle strict : on ne crée le lien que si la cible existe dans notre sélection
                        if ($target_id && in_array($target_id, $post_ids) && $target_id != $post->ID) {
                            
                            $gsc_data = get_post_meta($target_id, '_sil_gsc_data', true);
                            $impressions = 0; $clicks = 0;
                            if (is_array($gsc_data)) {
                                foreach (array_slice($gsc_data, 0, 5) as $row) {
                                    $impressions += intval($row['impressions'] ?? 0);
                                    $clicks += intval($row['clicks'] ?? 0);
                                }
                            }

                            // --- CORRECTION LOGARITHMIQUE ---
                            $raw_weight = ($clicks * 2) + $impressions;
                            $weight = round(log($raw_weight + 2) * 10); 

                            $edges[] = array(
                                'data' => array(
                                    'source' => (string) $post->ID,
                                    'target' => (string) $target_id,
                                    'weight' => max(1, $weight),
                                    'id'     => $post->ID . '-' . $target_id
                                )
                            );
                        }
                    }
                }
            }
        }

        $unique_edges = array();
        foreach ($edges as $edge) {
            $id = $edge['data']['id'];
            if (!isset($unique_edges[$id])) $unique_edges[$id] = $edge;
        }
        return array_values($unique_edges);
    }
}
