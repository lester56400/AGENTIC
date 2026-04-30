<?php
/**
 * SIL_Math_Evaluator
 *
 * Pure mathematical library for spatial calculations.
 * Decoupled from WordPress — no DB access, no WP functions.
 *
 * Provides:
 * - Cosine similarity
 * - Adaptive RBF kernel gamma (niche-aware)
 * - Gaussian kernel transformation
 *
 * @since Phase 2 (Gaussian RBF Kernel)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SIL_Math_Evaluator {

    /**
     * Cosine similarity between two vectors.
     *
     * @param float[] $vec_a First vector.
     * @param float[] $vec_b Second vector.
     * @return float Similarity in [-1, 1], typically [0, 1] for embeddings.
     */
    public static function calculate_similarity( array $vec_a, array $vec_b ): float {
        $dot = $norm_a = $norm_b = 0.0;
        $len = min( count( $vec_a ), count( $vec_b ) );
        for ( $i = 0; $i < $len; $i++ ) {
            $dot    += $vec_a[ $i ] * $vec_b[ $i ];
            $norm_a += $vec_a[ $i ] * $vec_a[ $i ];
            $norm_b += $vec_b[ $i ] * $vec_b[ $i ];
        }
        if ( $norm_a == 0.0 || $norm_b == 0.0 ) return 0.0;
        return $dot / ( sqrt( $norm_a ) * sqrt( $norm_b ) );
    }

    /**
     * Compute the adaptive gamma parameter for the RBF kernel.
     *
     * Algorithm:
     * 1. Compute the global barycenter (mean of all embeddings)
     * 2. Compute the mean squared cosine distance to the barycenter
     * 3. gamma = 0.5 / variance
     *
     * A higher gamma means the kernel is more "peaky" (sharper separation).
     * A lower gamma means the kernel is smoother (softer clusters).
     * Ultra-niche sites (low variance) → high gamma → better K separation.
     *
     * @param array $embeddings [ post_id => float[] ]
     * @return float Gamma parameter (always > 0).
     */
    public static function calculate_niche_gamma( array $embeddings ): float {
        $n = count( $embeddings );
        if ( $n < 2 ) return 1.0; // Fallback: neutral gamma

        $vectors = array_values( $embeddings );
        $dim = count( $vectors[0] );

        // 1. Compute global barycenter
        $barycenter = array_fill( 0, $dim, 0.0 );
        foreach ( $vectors as $vec ) {
            for ( $i = 0; $i < $dim; $i++ ) {
                $barycenter[ $i ] += $vec[ $i ];
            }
        }
        for ( $i = 0; $i < $dim; $i++ ) {
            $barycenter[ $i ] /= $n;
        }

        // 2. Compute mean squared cosine distance to barycenter
        $total_sq_dist = 0.0;
        foreach ( $vectors as $vec ) {
            $cos_sim = self::calculate_similarity( $vec, $barycenter );
            $dist = 1.0 - $cos_sim; // cosine distance
            $total_sq_dist += ( $dist * $dist );
        }
        $variance = $total_sq_dist / $n;

        // 3. gamma = 0.5 / variance (with safety floor)
        if ( $variance < 1e-8 ) {
            return 500.0; // Extreme niche: nearly identical embeddings
        }

        return 0.5 / $variance;
    }

    /**
     * Gaussian RBF kernel value.
     *
     * Converts cosine similarity to a kernel value in (0, 1].
     * The formula uses the relation: ||a-b||² = 2(1 - cos_sim) for unit vectors.
     *
     * K(a, b) = exp( -gamma * 2 * (1 - cos_sim) )
     *
     * Properties:
     * - cos_sim = 1.0 (identical) → K = 1.0
     * - cos_sim = 0.0 (orthogonal) → K = exp(-2*gamma) ≈ 0
     * - Higher gamma → sharper falloff → better cluster separation
     *
     * @param float $cos_sim Cosine similarity between two vectors.
     * @param float $gamma   RBF bandwidth parameter.
     * @return float Kernel value in (0, 1].
     */
    public static function calculate_kernel_value( float $cos_sim, float $gamma ): float {
        return exp( -$gamma * 2.0 * ( 1.0 - $cos_sim ) );
    }

    /**
     * Xie-Beni (XB) Index for fuzzy clustering quality.
     * [PHASE3] Uses RBF Kernel distance for consistency with the clustering engine.
     * 
     * XB = Compactness / Separation
     * 
     * Compactness: Weighted sum of squared kernel distances between articles and centroids.
     * Separation: N * minimum squared kernel distance between any two centroids.
     * 
     * Lower XB value = Better clustering.
     *
     * @param array $embeddings [ post_id => float[] ]
     * @param array $centroids  [ float[] ]
     * @param array $u_matrix   [ post_id => [ silo_idx => score ] ]
     * @param float $gamma      RBF bandwidth parameter.
     * @return float XB index value.
     */
    public static function evaluate_xie_beni( array $embeddings, array $centroids, array $u_matrix, float $gamma ): float {
        $n = count( $embeddings );
        $k = count( $centroids );
        if ( $n === 0 || $k < 2 ) return 999.0;

        $compactness = 0.0;
        $post_ids = array_keys( $embeddings );

        // 1. Compute Compactness (Numerator)
        foreach ( $post_ids as $pid ) {
            $x = $embeddings[ $pid ];
            $u_i = $u_matrix[ $pid ] ?? [];
            for ( $j = 0; $j < $k; $j++ ) {
                $u_ij = (float) ( $u_i[ $j ] ?? 0.0 );
                if ( $u_ij < 1e-4 ) continue;
                
                $cos_sim = self::calculate_similarity( $x, $centroids[ $j ] );
                $kernel = self::calculate_kernel_value( $cos_sim, $gamma );
                $dist_kernel = 1.0 - $kernel;
                
                $compactness += ( $u_ij * $u_ij ) * ( $dist_kernel * $dist_kernel );
            }
        }

        // 2. Compute Separation (Denominator)
        $min_dist_sq = PHP_FLOAT_MAX;
        for ( $j = 0; $j < $k; $j++ ) {
            for ( $l = $j + 1; $l < $k; $l++ ) {
                $cos_sim = self::calculate_similarity( $centroids[ $j ], $centroids[ $l ] );
                $kernel = self::calculate_kernel_value( $cos_sim, $gamma );
                $dist_kernel = 1.0 - $kernel;
                $dist_sq = $dist_kernel * $dist_kernel;
                if ( $dist_sq < $min_dist_sq ) {
                    $min_dist_sq = $dist_sq;
                }
            }
        }

        if ( $min_dist_sq < 1e-10 ) $min_dist_sq = 1e-10;
        $separation = $n * $min_dist_sq;

        return $compactness / $separation;
    }
}
