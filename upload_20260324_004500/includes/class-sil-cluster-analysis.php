<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SIL_Cluster_Analysis {
    public function __construct($api_key = null, $model = null) {}

    public function get_graph_data($force_refresh = false) {
        $cache_key = 'sil_graph_cache_v8_3';
        if (!$force_refresh) {
            $cached = get_transient($cache_key);
            if ($cached !== false) {
                return $cached;
            }
        }

        $posts = get_posts(['post_type' => ['post', 'page'], 'post_status' => 'publish', 'posts_per_page' => -1]);
        
        // --- NOUVEAU: Filtrage SEO (noindex) ---
        if (get_option('sil_exclude_noindex') === '1') {
            $posts = array_filter($posts, function($post) {
                return !SIL_SEO_Utils::is_noindexed($post->ID);
            });
            $posts = array_values($posts); // Re-index array
        }

        $edges_data = $this->get_edges($posts);
        
        // --- NOUVEAU: Batch fetch categories ---
        $cat_batch = [];
        foreach ($posts as $p) {
            $post_cats = get_the_category($p->ID);
            if (!empty($post_cats)) $cat_batch[$p->ID] = $post_cats;
        }
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
            $cats = $cat_batch[$post->ID] ?? [];
            $cat_id = ( !empty($cats) && isset($cats[0]) && is_object($cats[0]) && isset($cats[0]->term_id) ) ? (string)($cats[0]->term_id + 1000) : '0';
            $cluster_id = $clusters_map[$post_id] ?? $cat_id;
            $post_cluster_map[$post_id] = $cluster_id;
            $cluster_sizes[$cluster_id] = ($cluster_sizes[$cluster_id] ?? 0) + 1;
        }


        $cluster_inter_edges = [];
        $cluster_intra_edges = [];
        $post_inter_edges = [];
        $post_intra_edges = [];
        $post_edges = []; // Track where each post sends its links (to which cluster)
        $post_to_node_edges = []; // Track specific node-to-node links

        foreach ($edges_data as $edge) {
            $s = (string)$edge['data']['source'];
            $t = (string)$edge['data']['target'];
            $post_to_node_edges[$s][$t] = true;
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
        $cluster_cornerstone_map = [];

        // --- PRE-PASS: Batch fetch Embeddings and Cornerstone status ---
        $post_ids_arr = wp_list_pluck($posts, 'ID');
        $all_meta = $wpdb->get_results("SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ('_sil_is_cornerstone', '_sil_embedding', '_sil_gsc_data') AND post_id IN (" . implode(',', $post_ids_arr) . ")", ARRAY_A);
        
        $meta_batch = [];
        foreach ($all_meta as $m) {
            $meta_batch[$m['post_id']][$m['meta_key']] = $m['meta_value'];
        }

        foreach ( $posts as $post ) {
            if ( ! is_object($post) || ! isset($post->ID) ) continue;
            $post_id = (string)$post->ID;
            $cluster_id = $post_cluster_map[$post_id] ?? '0';
            
            $is_strategic = ($meta_batch[$post->ID]['_sil_is_cornerstone'] ?? '0') === '1';
            if ($is_strategic && $cluster_id !== '0') {
                $cluster_cornerstone_map[$cluster_id] = $post_id;
            }
        }

        foreach ( $posts as $post ) {
            if ( ! is_object($post) || ! isset($post->ID) ) continue;
            $post_id = (string)$post->ID;
            $cluster_id = $post_cluster_map[$post_id] ?? '0';
            
            $unique_clusters[$cluster_id] = true;
            $cats = $cat_batch[$post->ID] ?? [];
            if (!empty($cats) && is_object($cats[0]) && isset($cats[0]->name)) { $cluster_cats[$cluster_id][] = $cats[0]->name; }

            // Capturer le premier titre pour le nommage de secours du Silo
            if (!isset($silo_titles[$cluster_id])) {
                $silo_titles[$cluster_id] = get_the_title($post->ID);
            }

            $in = $in_degrees[$post_id] ?? 0;
            $out = $out_degrees[$post_id] ?? 0;
            $tags = [];
            if ($in === 0) $tags[] = 'orphan';
            if ($in > 3 && $out === 0) $tags[] = 'siphon';
            
            $p_inter = $post_inter_edges[$post_id] ?? 0;
            $p_intra = $post_intra_edges[$post_id] ?? 0;
            $is_intruder_bool = ($p_inter > 0 && $p_intra === 0);

            $is_strategic = ($meta_batch[$post->ID]['_sil_is_cornerstone'] ?? '0') === '1';

            $gsc_data_raw = $meta_batch[$post->ID]['_sil_gsc_data'] ?? '';
            $gsc_data = is_array($gsc_data_raw) ? $gsc_data_raw : json_decode($gsc_data_raw, true);
            if (empty($gsc_data) && is_string($gsc_data_raw) && $gsc_data_raw) {
                $gsc_data = function_exists('maybe_unserialize') ? maybe_unserialize($gsc_data_raw) : unserialize($gsc_data_raw);
            }
            
            $emb_raw = $meta_batch[$post->ID]['_sil_embedding'] ?? '';
            $emb = is_array($emb_raw) ? $emb_raw : json_decode($emb_raw, true);

            $is_cannibalized = false;

            // --- Cannibalization Check ---
            $top_query = '';
            if (is_array($gsc_data)) {
                $rows = $gsc_data['top_queries'] ?? ($gsc_data ?: []);
                if (is_array($rows) && isset($rows[0])) {
                    $top_query = $rows[0]['query'] ?? ($rows[0]['keys'][0] ?? '');
                }
            }
            if ($top_query && $cluster_id !== '0') {
                if (!isset($cluster_top_queries[$cluster_id][$top_query])) {
                    $cluster_top_queries[$cluster_id][$top_query] = $post_id;
                } else {
                    $is_cannibalized = true;
                }
            }

            // NEW (V9): Semantic Cannibalization (Vector-based)
            $vector_cannibalization = false;
            if (!empty($emb) && isset($cluster_embeddings[$cluster_id])) {
                foreach ($cluster_embeddings[$cluster_id] as $other_pid => $other_emb) {
                    if ($other_pid == $post_id) continue;
                    $sim = SIL_Centrality_Engine::get_representativeness_score($emb, $other_emb);
                    if ($sim > 0.92) {
                        $vector_cannibalization = true;
                        break;
                    }
                }
            }

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
            
            $imp = 0;
            if (is_array($gsc_data)) {
                $rows = isset($gsc_data['top_queries']) ? $gsc_data['top_queries'] : $gsc_data;
                if (is_array($rows)) {
                    foreach($rows as $r) $imp += intval($r['impressions'] ?? 0);
                }
            }

            $clicks = 0; $pos_avg = 0; $clicks_delta = 0; $clicks_delta_pct = 0; $yield_delta_pct = 0; $imp_delta = 0; $pos_delta = 0;
            if (is_array($gsc_data)) {
                $clicks = intval($gsc_data['clicks'] ?? 0);
                $pos_avg = floatval($gsc_data['position'] ?? 0);
                $stats = $gsc_data['stats_comparison'] ?? [];
                $clicks_delta = intval($stats['clicks_delta'] ?? 0);
                $clicks_delta_pct = floatval($stats['clicks_delta_percent'] ?? 0);
                $yield_delta_pct = floatval($stats['yield_delta_percent'] ?? 0);
                $imp_delta = intval($stats['impressions_delta'] ?? 0);
                $pos_delta = floatval($stats['position_delta'] ?? 0);
            }

            $ctr = ($imp > 0) ? round(($clicks / $imp) * 100, 2) : 0;

            $sil_pagerank = $in * 10 + $imp;
            $max_raw_pr = max($max_raw_pr ?? 1, $sil_pagerank);

            // GSC Trends & Decay Critical (V8.1)
            $trend = $clicks_delta_pct;
            $is_decay_critical = false;
            $post_modified_time = strtotime($post->post_modified);
            $six_months_ago = strtotime('-6 months');
            if ($post_modified_time < $six_months_ago && $yield_delta_pct <= -15 && $pos_delta <= -2) {
                $is_decay_critical = true;
            }

            // Keyword Gaps (Opportunités au sein de la page)
            $page_gaps = [];
            if (isset($rows) && is_array($rows)) {
                foreach(array_slice($rows, 0, 3) as $r) {
                    $kw = $r['query'] ?? ($r['keys'][0] ?? '');
                    $pos = floatval($r['position'] ?? 0);
                    if ($pos > 10 && $pos < 30) $page_gaps[] = $kw; // Striking Distance
                }
            }

            $nodes_data[] = [
                'data' => [
                    'id' => $post_id, 'parent' => 'silo_'.$cluster_id, 'cluster_id' => $cluster_id,
                    'label' => get_the_title($post->ID), 'title' => get_the_title($post->ID), 'url' => get_permalink($post->ID),
                    'in_degree' => $in, 'out_degree' => $out,
                    'post_date' => $post->post_date, 'post_modified' => $post->post_modified,
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
                    'cornerstone_id' => $cluster_cornerstone_map[$cluster_id] ?? '',
                    'has_reciprocal_link' => ( isset($cluster_cornerstone_map[$cluster_id]) && isset($post_to_node_edges[$post_id][$cluster_cornerstone_map[$cluster_id]]) ) ? 'true' : 'false',
                    'is_missing_reciprocity' => ( isset($cluster_cornerstone_map[$cluster_id]) && !isset($post_to_node_edges[$post_id][$cluster_cornerstone_map[$cluster_id]]) && $post_id !== $cluster_cornerstone_map[$cluster_id] ) ? 'true' : 'false',
                    'gsc_trend' => $trend,
                    'is_decaying' => $trend < -15 ? 'true' : 'false',
                    'is_decay_critical' => $is_decay_critical ? 'true' : 'false',
                    'gsc_clicks' => $clicks,
                    'gsc_ctr' => $ctr,
                    'gsc_position' => $pos_avg,
                    'gsc_clicks_delta' => $clicks_delta,
                    'gsc_clicks_delta_percent' => $clicks_delta_pct,
                    'gsc_yield_delta_percent' => $yield_delta_pct,
                    'gsc_impressions_delta' => $imp_delta,
                    'gsc_position_delta' => $pos_delta,
                    'striking_distance_keywords' => $page_gaps,
                    'cannibalization_risk' => ($is_cannibalized || $vector_cannibalization) ? 'true' : 'false',
                    'is_semantic_duplicate' => $vector_cannibalization ? 'true' : 'false',
                    'main_query' => $top_query,
                ],
                'raw_pr' => $sil_pagerank
            ];

        }

        // --- SECOND PASS: Normalize PageRank 0-100 ---
        $max_raw_pr = 1;
        foreach($nodes_data as $n) { if ($n['raw_pr'] > $max_raw_pr) $max_raw_pr = $n['raw_pr']; }
        $safe_max_pr = max(1, $max_raw_pr);
        foreach($nodes_data as &$n) {
            $n['data']['sil_pagerank'] = round(($n['raw_pr'] / $safe_max_pr) * 100);
            unset($n['raw_pr']);
        }

        // 4. Création des Halos avec Nommage Garanti
        $silo_engine = new SIL_Semantic_Silos();
        $silo_labels = $silo_engine->get_silo_labels();

        foreach ( array_keys($unique_clusters) as $cid ) {
            $raw_sid = (int)$cid - 9000;
            $halo_label = $silo_labels[$raw_sid] ?? "Silo $cid";

            if ($raw_sid <= 0) {
                 // Fallback for non-semantic clusters (Infomap or Categories)
                 if (isset($cluster_cats[$cid]) && !empty($cluster_cats[$cid])) {
                    $counts = array_count_values($cluster_cats[$cid]);
                    arsort($counts);
                    $dominant_cat = array_key_first($counts);
                    $halo_label = "Thématique : " . $dominant_cat;
                } elseif (isset($silo_titles[$cid])) {
                    $short_title = wp_trim_words($silo_titles[$cid], 4, '...');
                    $halo_label = "Sujet : " . $short_title;
                }
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

        // --- THIRD PASS: Global Metadata & Opportunities ---
        $true_gaps = [];
        $all_queries = [];
        $post_titles = []; 
        $post_urls = [];
        $leak_details = [];
        // Note: $unique_clusters, $silo_titles, $cluster_cornerstone_map, $cluster_permeabilities, $distance_matrix are preserved from previous steps
        foreach ($nodes_data as $node) {
            $pid_raw = $node['data']['id'];
            if (strpos($pid_raw, 'silo_') === 0) continue; // Skip silo parent nodes

            $pid = (int)$pid_raw;
            $post_titles[$pid] = $node['data']['label'] ?? ""; 
            $post_urls[$pid] = $node['data']['url'] ?? "";

            $cid = (string)($node['data']['cluster_id'] ?? '0');
            if ($cid !== '0') {
                $unique_clusters[$cid] = true;
                if (!isset($silo_titles[$cid])) {
                    $silo_titles[$cid] = $node['data']['silo_label'] ?? "Silo $cid";
                }
            }

            if (($node['data']['is_strategic'] ?? 'false') === 'true' && $cid !== '0') {
                 $cluster_cornerstone_map[$cid] = $pid;
            }
            
            $gsc_raw = $meta_batch[$pid]['_sil_gsc_data'] ?? '';
            $gsc = is_array($gsc_raw) ? $gsc_raw : json_decode($gsc_raw, true);
            if (empty($gsc) && is_string($gsc_raw) && $gsc_raw) {
                $gsc = function_exists('maybe_unserialize') ? maybe_unserialize($gsc_raw) : unserialize($gsc_raw);
            }

            $rows = $gsc['top_queries'] ?? ($gsc ?: []);
            if (is_array($rows)) {
                foreach($rows as $r) {
                    $raw_q = $r['query'] ?? ($r['keys'][0] ?? '');
                    if (!$raw_q) continue;

                    // Decode potential literal Unicode escape sequences (\u00e0 -> à)
                    $q = preg_replace_callback('/(?:\\\\+)?u([0-9a-fA-F]{4})/', function ($match) {
                        return mb_convert_encoding(pack('H*', $match[1]), 'UTF-8', 'UCS-2BE');
                    }, $raw_q);
                    $q = wp_specialchars_decode($q, ENT_QUOTES);

                    $all_queries[$q] = [
                        'query' => $q,
                        'impressions' => ($all_queries[$q]['impressions'] ?? 0) + intval($r['impressions'] ?? 0),
                        'position' => min($all_queries[$q]['position'] ?? 100, floatval($r['position'] ?? 100))
                    ];
                }
            }
        }
        // Filter True Gaps: High impressions but poor average position (> 15)
        usort($all_queries, function($a, $b) { return $b['impressions'] <=> $a['impressions']; });
        $all_queries_filtered = [];
        foreach(array_slice($all_queries, 0, 15) as $q) {
            if ($q['position'] > 15) $all_queries_filtered[] = $q;
        }

        $silo_engine = new SIL_Semantic_Silos();
        $distance_matrix = $silo_engine->get_silo_distance_matrix();

        // --- NEW: Semantic Content Gaps (Embeddings) ---
        $semantic_gaps = [];
        $embeddings = $silo_engine->load_embeddings();
        
        if (!empty($embeddings) && !empty($distance_matrix)) {
            // 1. Core Gaps (Hollow Silos)
            foreach ($unique_clusters as $cid => $exists) {
                if ($cid === '0') continue;
                $members = $silo_engine->get_silo_members(intval($cid), true);
                if (empty($members)) continue;

                // Calculate local barycenter
                $cluster_embs = [];
                foreach ($members as $pid) {
                    if (isset($embeddings[$pid])) $cluster_embs[] = $embeddings[$pid];
                }
                if (empty($cluster_embs)) continue;

                $barycenter = SIL_Centrality_Engine::calculate_barycenter($cluster_embs);
                
                // Find closest post to barycenter
                $max_sim = 0;
                $closest_id = 0;
                foreach ($members as $pid) {
                    if (!isset($embeddings[$pid])) continue;
                    $sim = SIL_Centrality_Engine::get_representativeness_score($embeddings[$pid], $barycenter);
                    if ($sim > $max_sim) {
                        $max_sim = $sim;
                        $closest_id = $pid;
                    }
                }

                // Important: Store for Global Analysis (True Gaps)
                $barycenters[$cid] = $barycenter;
                $pivots[$cid] = $closest_id;

                // If the "best" post is too far from the center (> 0.40 distance), the silo is hollow
                if ($max_sim < 0.60) {
                    // Extract lexical field from top 3 central posts
                    $lexical = [];
                    foreach(array_slice($members, 0, 3) as $mpid) {
                        $m_gsc_raw = $meta_batch[$mpid]['_sil_gsc_data'] ?? '';
                        $m_gsc = is_array($m_gsc_raw) ? $m_gsc_raw : json_decode($m_gsc_raw, true);
                        if (empty($m_gsc) && is_string($m_gsc_raw) && $m_gsc_raw) {
                             $m_gsc = function_exists('maybe_unserialize') ? maybe_unserialize($m_gsc_raw) : unserialize($m_gsc_raw);
                        }

                        $m_rows = $m_gsc['top_queries'] ?? ($m_gsc ?: []);
                        if (is_array($m_rows)) {
                            foreach(array_slice($m_rows, 0, 5) as $mr) {
                                $lexical[] = $mr['query'] ?? ($mr['keys'][0] ?? '');
                            }
                        }
                    }

                    $semantic_gaps[] = [
                        'type' => 'hollow_cluster',
                        'silo_id' => $cid,
                        'silo_label' => $silo_titles[$cid] ?? "Silo $cid",
                        'distance_to_core' => round(1 - $max_sim, 3),
                        'suggested_title' => "Pilier : " . ($silo_titles[$cid] ?? "Thématique $cid"),
                        'lexical_field' => implode(', ', array_unique(array_filter($lexical))),
                        'linking_strategy' => [
                            'receives_from' => array_map(function($pid) use ($post_titles, $post_urls) {
                                return ['id' => $pid, 'title' => $post_titles[$pid] ?? "Post $pid", 'url' => $post_urls[$pid] ?? "", 'suggested_anchor' => 'Maillage Pilier'];
                            }, array_slice($members, 0, 3))
                        ]
                    ];
                }
            }

            // 2. Bridge Gaps (Missing link between close silos)
            foreach ($distance_matrix as $s1 => $targets) {
                foreach ($targets as $s2 => $dist) {
                    if ($s1 >= $s2) continue; // Only check half matrix
                    if ($dist < 0.40) {
                        // Check if any edge exists between these two silos using our pre-built matrix
                        $has_bridge = isset($cluster_edges[$s1][$s2]) || isset($cluster_edges[$s2][$s1]);

                        if (!$has_bridge) {
                            $bridge_lexical = [];
                            // Prends les top requêtes des pivots de S1 et S2
                            foreach ([$s1, $s2] as $target_silo) {
                                $p_id = $pivots[$target_silo] ?? null;
                                if ($p_id) {
                                    $p_gsc_raw = $meta_batch[$p_id]['_sil_gsc_data'] ?? '';
                                    $p_gsc = json_decode($p_gsc_raw, true) ?: (function_exists('maybe_unserialize') ? maybe_unserialize($p_gsc_raw) : []);
                                    $p_rows = $p_gsc['top_queries'] ?? ($p_gsc ?: []);
                                    if (is_array($p_rows)) {
                                        foreach(array_slice($p_rows, 0, 3) as $pr) $bridge_lexical[] = $pr['query'] ?? '';
                                    }
                                }
                            }

                            $semantic_gaps[] = [
                                'type' => 'missing_bridge',
                                'silo_a' => $s1,
                                'silo_b' => $s2,
                                'silo_a_label' => $silo_titles[$s1] ?? "Silo $s1",
                                'silo_b_label' => $silo_titles[$s2] ?? "Silo $s2",
                                'distance' => $dist,
                                'suggested_title' => "Pont : " . ($silo_titles[$s1] ?? "Silo $s1") . " & " . ($silo_titles[$s2] ?? "Silo $s2"),
                                'lexical_bridge' => implode(', ', array_unique(array_filter($bridge_lexical))),
                                'linking_strategy' => [
                                    'source_url' => $post_urls[$pivots[$s1]] ?? "",
                                    'target_url' => $post_urls[$pivots[$s2]] ?? "",
                                    'source_title' => $post_titles[$pivots[$s1]] ?? "Silo $s1",
                                    'target_title' => $post_titles[$pivots[$s2]] ?? "Silo $s2",
                                ]
                            ];
                        }
                    }
                }
            }
        }

        // --- FOURTH PASS: Barycenters, Final Scores & Pivots (V8.1) ---
        $cluster_embeddings = [];
        foreach ($nodes_data as $n) {
            $pid = $n['data']['id'];
            if (isset($n['data']['is_silo_parent'])) continue;
            $cid = $n['data']['cluster_id'];
            
            $emb_raw = $meta_batch[$pid]['_sil_embedding'] ?? '';
            $emb = is_array($emb_raw) ? $emb_raw : json_decode($emb_raw, true);
            if (!empty($emb)) $cluster_embeddings[$cid][] = $emb;
        }

        $barycenters = [];
        foreach ($cluster_embeddings as $cid => $embs) {
            $barycenters[$cid] = SIL_Centrality_Engine::calculate_barycenter($embs);
        }

        $pivots = [];
        $best_scores = [];
        foreach ($nodes_data as &$n) {
            if (isset($n['data']['is_silo_parent'])) continue;
            $pid = $n['data']['id'];
            $cid = $n['data']['cluster_id'];
            
            $emb_raw = $meta_batch[$pid]['_sil_embedding'] ?? '';
            $emb = is_array($emb_raw) ? $emb_raw : json_decode($emb_raw, true);
            
            $semantic_score = SIL_Centrality_Engine::get_representativeness_score($emb, $barycenters[$cid] ?? null);
            $gsc_power_score = SIL_Centrality_Engine::get_gsc_power_score($n['data']['gsc_impressions'], $n['data']['gsc_position'] ?? 0);
            $connectivity_score = min(1.0, $n['data']['in_degree'] / 20.0);
            
            $final_score = SIL_Centrality_Engine::compute_final_score($semantic_score, $gsc_power_score, $connectivity_score);
            $n['data']['sil_pagerank'] = $final_score;
            $n['data']['is_pivot'] = false;

            if (!isset($best_scores[$cid]) || $final_score > $best_scores[$cid]) {
                $best_scores[$cid] = $final_score;
                $pivots[$cid] = $pid;
            }
        }
        foreach ($nodes_data as &$n) {
            if ($n['data']['id'] === ($pivots[$n['data']['cluster_id']] ?? null)) {
                $n['data']['is_pivot'] = true;
            }
        }
        unset($n);

        // --- NEW: Actionable Leak Mapping (Source -> Target culprits) ---
        $post_to_cluster = [];
        $post_to_title = [];
        foreach ($nodes_data as $n) {
            if (isset($n['data']['is_silo_parent'])) continue;
            $post_to_cluster[(string)$n['data']['id']] = (string)$n['data']['cluster_id'];
            $post_to_title[(string)$n['data']['id']] = $n['data']['title'];
        }

        $leak_details = [];
        foreach ($edges_data as $e) {
            $src = (string)$e['data']['source'];
            $tgt = (string)$e['data']['target'];
            $src_cid = $post_to_cluster[$src] ?? null;
            $tgt_cid = $post_to_cluster[$tgt] ?? null;

            if ($src_cid && $tgt_cid && $src_cid !== $tgt_cid) {
                if (!isset($leak_details[$src_cid])) $leak_details[$src_cid] = [];
                if (count($leak_details[$src_cid]) < 5) { // Limite à 5 fuites par silo pour le GEM
                    $leak_details[$src_cid][] = [
                        'source_id' => $src,
                        'source_title' => $post_to_title[$src] ?? '?',
                        'target_id' => $tgt,
                        'target_title' => $post_to_title[$tgt] ?? '?',
                        'target_cluster' => $tgt_cid
                    ];
                }
            }
        }

        // --- FIFTH PASS: Final Integrity Check (Defeat "nonexistant source/target" crash) ---
        $valid_node_ids = [];
        foreach ($nodes_data as $node) {
            $valid_node_ids[(string)$node['data']['id']] = true;
        }

        // --- SIXTH PASS: Semantic Anti-Cannibalization (Embeddings) ---
        $api_key = get_option('sil_openai_api_key');
        if (!empty($api_key) && (!empty($all_queries_filtered) || !empty($semantic_gaps))) {
            $strings_to_embed = [];
            foreach ($all_queries_filtered as $q) $strings_to_embed[] = $q['query'];
            foreach ($semantic_gaps as $g) $strings_to_embed[] = $g['suggested_title'];

            $new_embeddings = SIL_Centrality_Engine::batch_get_embeddings($strings_to_embed, $api_key);

            if (!empty($new_embeddings)) {
                $emb_idx = 0;
                
                // 1. Process True Gaps
                foreach ($all_queries_filtered as &$q) {
                    $q_emb = $new_embeddings[$emb_idx++] ?? null;
                    if (!$q_emb) continue;

                    // Find best cluster for this query
                    $best_cid = '0'; $best_cluster_sim = 0;
                    foreach ($barycenters as $cid => $bar) {
                        $sim = SIL_Centrality_Engine::get_representativeness_score($q_emb, $bar);
                        if ($sim > $best_cluster_sim) {
                            $best_cluster_sim = $sim;
                            $best_cid = $cid;
                        }
                    }
                    $q['suggested_silo'] = $best_cid;
                    $q['silo_label'] = $silo_titles[$best_cid] ?? "Silo $best_cid";

                    // Check for cannibalization in that cluster
                    $best_match_id = 0; $best_match_sim = 0;
                    foreach ($nodes_data as $node) {
                        if (isset($node['data']['is_silo_parent'])) continue;
                        if ((string)$node['data']['cluster_id'] !== (string)$best_cid) continue;

                        $pid = $node['data']['id'];
                        $target_emb = $embeddings[$pid] ?? null;
                        if (!$target_emb) continue;

                        $sim = SIL_Centrality_Engine::get_representativeness_score($q_emb, $target_emb);
                        if ($sim > $best_match_sim) {
                            $best_match_sim = $sim;
                            $best_match_id = $pid;
                        }
                    }

                    if ($best_match_sim > 0.85) {
                        $q['recommendation'] = 'UPDATE';
                        $q['target_id'] = $best_match_id;
                        $q['target_title'] = $post_titles[$best_match_id] ?? "Post $best_match_id";
                        $q['similarity'] = round($best_match_sim, 3);
                    } else {
                        $q['recommendation'] = 'CREATE';
                    }

                    // Enrich with Lexical Field for the user
                    $lex_pid = ($q['recommendation'] === 'UPDATE') ? $best_match_id : ($pivots[$best_cid] ?? 0);
                    if ($lex_pid) {
                        $l_gsc_raw = $meta_batch[$lex_pid]['_sil_gsc_data'] ?? '';
                        $l_gsc = is_array($l_gsc_raw) ? $l_gsc_raw : json_decode($l_gsc_raw, true);
                        if (empty($l_gsc) && is_string($l_gsc_raw) && $l_gsc_raw) {
                             $l_gsc = function_exists('maybe_unserialize') ? maybe_unserialize($l_gsc_raw) : unserialize($l_gsc_raw);
                        }
                        $l_rows = $l_gsc['top_queries'] ?? ($l_gsc ?: []);
                        $lexical = [];
                        if (is_array($l_rows)) {
                            foreach(array_slice($l_rows, 0, 8) as $lr) $lexical[] = $lr['query'] ?? ($lr['keys'][0] ?? '');
                        }
                        $q['lexical_field'] = implode(', ', array_unique(array_filter($lexical)));

                        // Suggested Internal Linking (Source/Target)
                        $source_id = $cluster_cornerstone_map[$best_cid] ?? $lex_pid;
                        if ($source_id) {
                            $q['linking_strategy'] = [
                                'source_id' => $source_id,
                                'source_title' => $post_titles[$source_id] ?? "Source $source_id",
                                'source_url' => $post_urls[$source_id] ?? ""
                            ];
                        }
                    }
                }
                unset($q);

                // 2. Process Semantic Gaps
                foreach ($semantic_gaps as &$g) {
                    $g_emb = $new_embeddings[$emb_idx++] ?? null;
                    if (!$g_emb) continue;

                    $cid = $g['silo_id'] ?? '0';
                    $best_match_id = 0; $best_match_sim = 0;
                    
                    if ($cid !== '0') {
                        foreach ($nodes_data as $node) {
                            if (isset($node['data']['is_silo_parent'])) continue;
                            if ((string)$node['data']['cluster_id'] !== (string)$cid) continue;
                            
                            $pid = $node['data']['id'];
                            $target_emb = $embeddings[$pid] ?? null;
                            if (!$target_emb) continue;

                            $sim = SIL_Centrality_Engine::get_representativeness_score($g_emb, $target_emb);
                            if ($sim > $best_match_sim) {
                                $best_match_sim = $sim;
                                $best_match_id = $pid;
                            }
                        }
                    }

                    if ($best_match_sim > 0.85) {
                        $g['recommendation'] = 'UPDATE';
                        $g['target_id'] = $best_match_id;
                        $g['target_title'] = $post_titles[$best_match_id] ?? "Post $best_match_id";
                        $g['similarity'] = round($best_match_sim, 3);
                    } else {
                        $g['recommendation'] = 'CREATE';
                    }
                }
                unset($g);
            }
        }

        // Filter edges to ensure both source and target exist
        $final_edges = [];
        foreach ($edges_data as $edge) {
            $s = (string)$edge['data']['source'];
            $t = (string)$edge['data']['target'];
            if (isset($valid_node_ids[$s]) && isset($valid_node_ids[$t])) {
                $final_edges[] = $edge;
            }
        }

        $final_data = [
            'nodes' => $nodes_data,
            'edges' => $final_edges,
            'opportunities' => [
                'true_gaps' => $all_queries_filtered,
                'semantic_gaps' => $semantic_gaps,
            ],
            'stats_summary' => [
                'total_nodes' => count($posts),
                'total_edges' => count($final_edges),
                'pruned_edges' => count($edges_data) - count($final_edges),
                'silo_health' => $cluster_permeabilities,
                'leak_details' => $leak_details,
            ],
            'metadata' => [
                'generated_at' => current_time('mysql'),
                'silo_distances' => $distance_matrix,
                'audit_version' => '2026.V16.2'
            ]
        ];

        set_transient('sil_graph_cache_v8_4', $final_data, 3 * HOUR_IN_SECONDS);
        return $final_data;
    }


    private function get_edges($posts) {
        global $wpdb;
        $edges = array();
        $post_ids = wp_list_pluck($posts, 'ID');
        $site_host = parse_url(home_url(), PHP_URL_HOST);

        // --- NOUVEAU: Map de Permaliens pour performance (remplace url_to_postid) ---
        $url_map = [];
        foreach ($posts as $p) {
            $parsed_p = parse_url(get_permalink($p->ID));
            $path = isset($parsed_p['path']) ? trim($parsed_p['path'], '/') : '';
            if ($path) $url_map[$path] = $p->ID;
            // Support aussi le slug direct
            $url_map[$p->post_name] = $p->ID;
        }

        // --- NOUVEAU: Batch fetch GSC metrics pour les poids ---
        $gsc_batch = [];
        $meta_rows = $wpdb->get_results("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_sil_gsc_data' AND post_id IN (" . implode(',', $post_ids) . ")", ARRAY_A);
        foreach ($meta_rows as $row) {
            $gsc_batch[$row['post_id']] = json_decode($row['meta_value'], true) ?: unserialize($row['meta_value']);
        }

        foreach ($posts as $post) {
            // Utiliser le contenu brut pour la rapidité, do_shortcode est trop lent
            $content = $post->post_content; 
            preg_match_all('/<a[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $content, $matches);

            if (!empty($matches[1])) {
                foreach ($matches[1] as $index => $url) {
                    $parsed = parse_url($url);
                    $path = isset($parsed['path']) ? trim($parsed['path'], '/') : '';
                    if (!$path && isset($url_map[$url])) $path = $url; // Support slug direct

                    $target_id = $url_map[$path] ?? 0;

                    if ($target_id && in_array($target_id, $post_ids) && (int)$target_id !== (int)$post->ID) {
                        $gsc_data = $gsc_batch[$target_id] ?? [];
                        $impressions = 0; $clicks = 0;
                        if (is_array($gsc_data)) {
                            // On prend les stats globales si dispos
                            $impressions = intval($gsc_data['impressions'] ?? 0);
                            $clicks = intval($gsc_data['clicks'] ?? 0);
                            
                            // Sinon on somme le top (compatibilité ancien format)
                            if ($impressions === 0) {
                                $rows = $gsc_data['top_queries'] ?? $gsc_data;
                                if (is_array($rows)) {
                                    foreach (array_slice($rows, 0, 5) as $row) {
                                        $impressions += intval($row['impressions'] ?? 0);
                                        $clicks += intval($row['clicks'] ?? 0);
                                    }
                                }
                            }
                        }

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

        $unique_edges = array();
        foreach ($edges as $edge) {
            $id = $edge['data']['id'];
            if (!isset($unique_edges[$id])) $unique_edges[$id] = $edge;
        }
        return array_values($unique_edges);
    }
}
