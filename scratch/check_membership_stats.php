<?php
// Find wp-load.php
$path = dirname(__FILE__);
while ($path != '/' && $path != 'C:\\') {
    if (file_exists($path . '/wp-load.php')) {
        require_once $path . '/wp-load.php';
        break;
    }
    $path = dirname($path);
}

if (!defined('ABSPATH')) {
    echo "WP not loaded.\n";
    exit;
}

global $wpdb;
$table = $wpdb->prefix . 'sil_membership';

echo "Database: " . DB_NAME . "\n";
echo "Table: " . $table . "\n";

$rows = $wpdb->get_results("SELECT silo_id, COUNT(*) as count, AVG(score) as avg_score FROM $table GROUP BY silo_id ORDER BY silo_id ASC", ARRAY_A);

if (empty($rows)) {
    echo "No membership data found.\n";
} else {
    foreach ($rows as $row) {
        echo "Silo {$row['silo_id']}: {$row['count']} articles (Avg score: " . round($row['avg_score'], 4) . ")\n";
    }
}

// Check niche diameter
$diameter = get_option('sil_niche_diameter');
echo "Niche Diameter: " . $diameter . "\n";
