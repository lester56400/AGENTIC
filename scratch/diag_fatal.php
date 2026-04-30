<?php
define('ABSPATH', dirname(__FILE__) . '/');
define('HOUR_IN_SECONDS', 3600);
function get_option($o, $d=false) { return $d; }
function update_option($o, $v) { return true; }
function set_transient($k, $v, $t) { return true; }
function get_post_meta($p, $k, $s) { return ''; }
function clean_post_cache($p) { return true; }

class WP_Error { public function __construct($c, $m) { $this->c=$c;$this->m=$m; } public function get_error_message(){return $this->m;} }
function is_wp_error($x) { return $x instanceof WP_Error; }

// We need to load the class but it has many dependencies.
// Instead, I'll just simulate the execution flow with the class content.

require_once 'includes/class-sil-semantic-silos.php';

// Mock DB
class MockDB {
    public $prefix = 'wp_';
    public $posts = 'wp_posts';
    public $postmeta = 'wp_postmeta';
    public function get_results($q, $m=null) { return []; }
    public function get_col($q) { return []; }
    public function query($q) { return true; }
}

global $wpdb;
$wpdb = new MockDB();

$sil = new SIL_Semantic_Silos();

echo "Testing rebuild_silos_step_init...\n";
try {
    $res = $sil->rebuild_silos_step_init(5);
    if (is_wp_error($res)) {
        echo "WP_Error: " . $res->get_error_message() . "\n";
    } else {
        print_r($res);
    }
} catch (Throwable $e) {
    echo "FATAL ERROR caught: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine() . "\n";
    echo $e->getTraceAsString() . "\n";
}
