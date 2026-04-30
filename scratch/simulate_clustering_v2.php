<?php
define('ABSPATH', './');
define('HOUR_IN_SECONDS', 3600);

// Mock class to test the math
class SIL_Math_Test {
    public function cosine_similarity($v1, $v2) {
        $dot = 0; $n1 = 0; $n2 = 0;
        foreach($v1 as $i => $x) {
            $dot += $x * $v2[$i];
            $n1 += $x * $x;
            $n2 += $v2[$i] * $v2[$i];
        }
        $norm = sqrt($n1) * sqrt($n2);
        return $norm > 0 ? $dot / $norm : 0.0;
    }

    public function compute_membership($emb, $centroids, $k, $m, $niche_diameter) {
        $u   = array_fill( 0, $k, 0.0 );
        $eps = 1e-10;
        if ( $niche_diameter < 0.005 ) $niche_diameter = 0.05;

        $distances = [];
        for ( $i = 0; $i < $k; $i++ ) {
            $d = 1.0 - $this->cosine_similarity( $emb, $centroids[ $i ] );
            $distances[ $i ] = max( $eps, $d / $niche_diameter );
        }

        for ( $i = 0; $i < $k; $i++ ) {
            $sum = 0.0;
            $d_i = $distances[ $i ];
            for ( $j = 0; $j < $k; $j++ ) {
                $d_j = $distances[ $j ];
                $ratio = $d_i / $d_j;
                $sum += pow( $ratio, 2 / ( $m - 1 ) );
            }
            $u[ $i ] = 1.0 / $sum;
        }
        return $u;
    }

    public function compute_centroids($embeddings, $post_ids, $U, $k, $m) {
        $dim       = count( reset( $embeddings ) );
        $centroids = array_fill( 0, $k, array_fill( 0, $dim, 0.0 ) );
        $weights   = array_fill( 0, $k, 0.0 );

        foreach ( $post_ids as $pid ) {
            $vec = $embeddings[ $pid ];
            for ( $c = 0; $c < $k; $c++ ) {
                if ( ! isset( $U[ $pid ][ $c ] ) ) continue;
                $u_m         = pow( $U[ $pid ][ $c ], $m );
                $weights[ $c ] += $u_m;
                for ( $d = 0; $d < $dim; $d++ ) {
                    $centroids[ $c ][ $d ] += $u_m * $vec[ $d ];
                }
            }
        }

        for ( $c = 0; $c < $k; $c++ ) {
            $sum_u = $weights[ $c ];
            if ( $sum_u > 0 ) {
                $centroids[ $c ] = array_map( fn( $v ) => $v / $sum_u, $centroids[ $c ] );
            } else {
                $centroids[ $c ] = array_fill( 0, $dim, 0.0 );
            }
        }
        return $centroids;
    }
}

$test = new SIL_Math_Test();
$n = 60;
$k = 5;
$m = 1.2;
$niche_diameter = 0.02;

mt_srand(42);
$embeddings = [];
$base = array_fill(0, 50, 0.0);
$base[0] = 1.0;
for ($i=0; $i<$n; $i++) {
    $vec = $base;
    for ($d=0; $d<50; $d++) $vec[$d] += (mt_rand()/mt_getrandmax() - 0.5) * 0.1;
    $embeddings[$i] = $vec;
}
$post_ids = array_keys($embeddings);

// Random Init
$U = [];
foreach($post_ids as $pid) {
    $row = array_fill(0, $k, 0.0);
    $sum = 0;
    for($c=0; $c<$k; $c++) { $row[$c] = mt_rand()/mt_getrandmax(); $sum += $row[$c]; }
    for($c=0; $c<$k; $c++) $row[$c] /= $sum;
    $U[$pid] = $row;
}

echo "Starting simulation...\n";
for ($iter=0; $iter<50; $iter++) {
    $centroids = $test->compute_centroids($embeddings, $post_ids, $U, $k, $m);
    
    $U_new = [];
    foreach($embeddings as $pid => $emb) {
        $U_new[$pid] = $test->compute_membership($emb, $centroids, $k, $m, $niche_diameter);
    }
    
    $U = $U_new;
}

$counts = array_fill(0, $k, 0);
foreach($U as $pid => $scores) {
    $max_s = max($scores);
    $idx = array_search($max_s, $scores);
    $counts[$idx]++;
}

echo "Final distribution:\n";
print_r($counts);
