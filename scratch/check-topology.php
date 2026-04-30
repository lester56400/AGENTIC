<?php
require_once('wp-load.php');
global $wpdb;

$intruders = get_posts([
    'post_type' => ['post', 'page'],
    'meta_key' => '_sil_ideal_silo',
    'fields' => 'ids',
    'posts_per_page' => -1
]);

echo "=== DIAGNOSTIC SIL ===\n";
echo "Intrus détectés : " . count($intruders) . "\n";

$siphons_query = "
    SELECT p.ID FROM {$wpdb->posts} p
    INNER JOIN {$wpdb->postmeta} pm_in ON p.ID = pm_in.post_id AND pm_in.meta_key = '_sil_in_links_count'
    INNER JOIN {$wpdb->postmeta} pm_out ON p.ID = pm_out.post_id AND pm_out.meta_key = '_sil_out_links_count'
    WHERE pm_in.meta_value > 3 AND pm_out.meta_value = 0
";
// Note: The logic for Siphons might depend on how current values are stored.
// Let's just check the counts from the links table instead.

$links_table = $wpdb->prefix . 'sil_links';
$siphons = $wpdb->get_results("
    SELECT source_id, COUNT(*) as out_count 
    FROM $links_table 
    GROUP BY source_id 
    HAVING out_count = 0
");
// This is not perfect as it only counts links in our table.

echo "Silos (Clusters) : " . $wpdb->get_var("SELECT COUNT(DISTINCT silo_id) FROM {$wpdb->prefix}sil_silo_membership") . "\n";

foreach($intruders as $id) {
    echo "- Intrus [ID:$id] : " . get_the_title($id) . " -> Ideal: " . get_post_meta($id, '_sil_ideal_silo', true) . "\n";
}
