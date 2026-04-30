<?php
/**
 * Test Script : Vérifie le calcul du K optimal et la mécanique Anti-Collapse.
 * 
 * Usage (depuis la racine WordPress) :
 *   php test_k.php
 * 
 * Usage (depuis le dossier plugin, hors WordPress) :
 *   php test_k.php --standalone
 */

// --- STANDALONE MODE (pas besoin de WordPress) ---
if (in_array('--standalone', $argv ?? [])) {
    echo "=== MODE STANDALONE (Simulation) ===\n\n";
    
    // Simuler 51 embeddings de dimension 4 (niche dense)
    mt_srand(42);
    $embeddings = [];
    $base = [0.8, 0.6, 0.3, 0.1]; // Centre "café" générique
    
    for ($i = 1; $i <= 51; $i++) {
        $emb = [];
        foreach ($base as $v) {
            // Faible variance = site niché (±3% du centre)
            $emb[] = $v + (mt_rand(-30, 30) / 1000.0);
        }
        $norm = sqrt(array_sum(array_map(fn($x) => $x*$x, $emb)));
        $embeddings[$i] = array_map(fn($x) => $x / $norm, $emb);
    }
    
    echo "Nombre d'embeddings : " . count($embeddings) . "\n";
    echo "Dimension : " . count(reset($embeddings)) . "\n";
    
    // Calculer la variance inter-embeddings
    $dists = [];
    $keys = array_keys($embeddings);
    for ($i = 0; $i < min(20, count($keys)); $i++) {
        for ($j = $i + 1; $j < min(20, count($keys)); $j++) {
            $dot = $na = $nb = 0;
            $a = $embeddings[$keys[$i]];
            $b = $embeddings[$keys[$j]];
            for ($d = 0; $d < count($a); $d++) {
                $dot += $a[$d] * $b[$d];
                $na += $a[$d] * $a[$d];
                $nb += $b[$d] * $b[$d];
            }
            $sim = $dot / (sqrt($na) * sqrt($nb));
            $dists[] = 1.0 - $sim;
        }
    }
    $avg_dist = array_sum($dists) / count($dists);
    echo "Niche Diameter (avg cosine distance) : " . round($avg_dist, 6) . "\n";
    echo "Min dist : " . round(min($dists), 6) . "\n";
    echo "Max dist : " . round(max($dists), 6) . "\n\n";
    
    // Simuler l'Elbow Method
    $n = count($embeddings);
    $gutman = (int) round(sqrt($n / 2));
    echo "Gutman Rule : sqrt($n / 2) = $gutman\n\n";
    
    foreach (['balanced', 'granular', 'conservative'] as $mode) {
        $k_min = 2;
        if ($mode === 'granular') {
            $k_max = min($n - 1, max(5, $gutman + 4));
        } else {
            $k_max = min($n - 1, max(3, $gutman + 2));
        }
        
        echo "--- Mode: $mode (k_range: $k_min..$k_max) ---\n";
        
        $inertias = [];
        for ($k = $k_min; $k <= $k_max; $k++) {
            // Simulation simplifiée : inertie décroissante avec bruit
            mt_srand(42);
            $inertia = 0.0;
            // Simple : assigner chaque point au seed le plus proche
            // Seed = premiers $k embeddings (simulation)
            $seed_keys = array_slice($keys, 0, $k);
            foreach ($embeddings as $emb) {
                $min_d = 1.0;
                foreach ($seed_keys as $sk) {
                    $dot = $na = $nb = 0;
                    $s = $embeddings[$sk];
                    for ($d = 0; $d < count($emb); $d++) {
                        $dot += $emb[$d] * $s[$d];
                        $na += $emb[$d] * $emb[$d];
                        $nb += $s[$d] * $s[$d];
                    }
                    $dist = 1.0 - ($dot / (sqrt($na) * sqrt($nb)));
                    if ($dist < $min_d) $min_d = $dist;
                }
                $inertia += ($min_d * $min_d);
            }
            $inertias[$k] = $inertia;
        }
        
        // Elbow
        $p1_y = $inertias[$k_min];
        $p2_y = $inertias[$k_max];
        $a = $p1_y - $p2_y;
        $b = $k_max - $k_min;
        $c = $k_min * $p2_y - $k_max * $p1_y;
        $den = sqrt($a*$a + $b*$b);
        if ($den < 1e-9) $den = 1e-9;
        
        $max_dist = -1; $optimal_k = $gutman;
        for ($k = $k_min; $k <= $k_max; $k++) {
            $dist = abs($a * $k + $b * $inertias[$k] + $c) / $den;
            
            if ($mode === 'granular') {
                $dist *= (1.0 + (pow($k, 1.5) * 0.15));
            } elseif ($mode === 'conservative') {
                $dist *= (1.0 - ($k * 0.08));
            }
            
            echo "  k=$k  inertia=" . round($inertias[$k], 6) . "  score=" . round($dist, 6) . "\n";
            
            if ($dist > $max_dist) {
                $max_dist = $dist;
                $optimal_k = $k;
            }
        }
        echo "  >> OPTIMAL K = $optimal_k\n\n";
    }
    
    // Test Fuzziness mapping
    echo "=== FUZZINESS MAPPING ===\n";
    foreach (['conservative' => 1.25, 'balanced' => 1.15, 'granular' => 1.05] as $mode => $m) {
        $exp = 2 / ($m - 1);
        echo "  $mode : m=$m, exponent=2/(m-1)=" . round($exp, 2) . "\n";
    }
    echo "\n  Plus l'exposant est élevé, plus les frontières sont DURES.\n";
    echo "  granular (m=1.05, exp=40.0) vs balanced (m=1.15, exp=13.3) = 3x plus strict.\n";
    
    exit(0);
}

// --- WORDPRESS MODE ---
$wp_load = null;
$search_paths = [
    dirname(__FILE__) . '/wp-load.php',
    dirname(__FILE__) . '/../wp-load.php',
    dirname(__FILE__) . '/../../wp-load.php',
    dirname(__FILE__) . '/../../../wp-load.php',
];
foreach ($search_paths as $path) {
    if (file_exists($path)) { $wp_load = $path; break; }
}
if (!$wp_load) {
    echo "❌ Impossible de trouver wp-load.php.\n";
    echo "   Utilisez --standalone pour tester sans WordPress.\n";
    exit(1);
}

require $wp_load;

$silos = new SIL_Semantic_Silos();
$embeddings = $silos->load_embeddings();
echo "Total Embeddings: " . count($embeddings) . "\n\n";

foreach (['conservative', 'balanced', 'granular'] as $mode) {
    $k = $silos->calculate_recommended_k($mode);
    echo "Recommended K ($mode): $k\n";
}

echo "\nVérifiez le fichier debug.log pour les scores détaillés de l'Elbow Method.\n";
