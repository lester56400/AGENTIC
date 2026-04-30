<?php
require __DIR__ . '/../wp-load.php';
global $wpdb;
$count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sil_embeddings");
echo "TOTAL_EMBEDDINGS: " . $count . "\n";

$excluded_manual = $wpdb->get_col("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sil_exclude_from_mapping' AND meta_value = '1'");
echo "EXCLUDED_MANUAL_COUNT: " . count($excluded_manual) . "\n";

$n_after_filter = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sil_embeddings WHERE post_id NOT IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sil_exclude_from_mapping' AND meta_value = '1')");
echo "N_AFTER_FILTER: " . $n_after_filter . "\n";
