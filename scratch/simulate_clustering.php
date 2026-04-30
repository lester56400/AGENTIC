<?php
require_once 'includes/class-sil-semantic-silos.php';

// Mock DB and WP
class MockDB { public $prefix='wp_'; public $posts='wp_posts'; public $postmeta='wp_postmeta'; public function get_results($q,$m=null){return [];} public function get_col($q){return [];} public function query($q){return true;} }
function get_option($o,$d=false){ if($o==='sil_niche_diameter') return 0.02; return $d; }
function update_option($o,$v){ return true; }
function set_transient($k,$v,$t){ return true; }
function get_post_meta($p,$k,$s){ return ''; }
function clean_post_cache($p){ return true; }
define('ABSPATH', './');

global $wpdb; $wpdb = new MockDB();

class SIL_Sim extends SIL_Semantic_Silos {
    public function simulate($n, $k) {
        mt_srand(42);
        // Generate n very close vectors (dim 1536)
        $embeddings = [];
        $base = array_fill(0, 1536, 0.0);
        $base[0] = 1.0; // Point de base
        
        for ($i=0; $i<$n; $i++) {
            $vec = $base;
            // Add tiny noise (distance ~ 0.01)
            for ($d=0; $d<50; $d++) { // Only noise in first 50 dims for speed
                $vec[$d] += (mt_rand()/mt_getrandmax() - 0.5) * 0.1;
            }
            $embeddings[$i] = $vec;
        }

        echo "Simulating $n articles into $k silos...\n";
        
        // Step 1: Init
        // We bypass the actual DB calls
        $post_ids = array_keys($embeddings);
        $U = $this->init_membership_kmeans_plus_plus($embeddings, $k);
        
        // Step 2: Iterate
        for ($iter=0; $iter<100; $iter++) {
            $centroids = $this->compute_centroids($embeddings, $post_ids, $U, $k, 1.2);
            $U_new = $this->compute_membership_matrix($embeddings, $centroids, $k, 1.2);
            
            if ($this->has_converged($U, $U_new, $n, $k)) {
                echo "Converged at iteration $iter\n";
                break;
            }
            $U = $U_new;
            if ($iter % 10 === 0) echo "Iter $iter...\n";
        }

        // Analysis
        $counts = array_fill(0, $k, 0);
        foreach ($U as $pid => $scores) {
            $max_score = -1;
            $max_silo = 0;
            foreach($scores as $s => $val) {
                if ($val > $max_score) {
                    $max_score = $val;
                    $max_silo = $s;
                }
            }
            $counts[$max_silo]++;
        }

        echo "Silo distribution:\n";
        foreach($counts as $s => $c) echo "Silo $s: $c articles\n";
    }
}

$sim = new SIL_Sim();
$sim->simulate(60, 5);
