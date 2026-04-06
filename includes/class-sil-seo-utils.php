<?php
/**
 * Smart Internal Links SEO Utilities
 *
 * @package SmartInternalLinks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SIL_SEO_Utils
 *
 * Provides utility methods for SEO-related tasks, such as noindex detection.
 */
class SIL_SEO_Utils {

	/**
	 * Checks if a post is marked "noindex" by major SEO plugins.
	 *
	 * @param int $post_id The ID of the post to check.
	 * @return bool True if the post is noindexed, false otherwise.
	 */
	public static function is_noindexed( int $post_id ): bool {
		$post_id = intval( $post_id );

		// 1. Yoast SEO
		$yoast_noindex = get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
		if ( $yoast_noindex == 1 || $yoast_noindex === '1' ) return true;

		// 2. Rank Math (Individual Post Meta)
		// Check both underscored and non-underscored keys as both appear in the wild
		$rankmath_keys = array( 'rank_math_robots', '_rank_math_robots' );
		foreach ( $rankmath_keys as $key ) {
			$robots = get_post_meta( $post_id, $key, true );
			if ( ! empty( $robots ) ) {
				if ( is_array( $robots ) && in_array( 'noindex', $robots ) ) return true;
				if ( is_string( $robots ) && ( strpos( $robots, 'noindex' ) !== false || strpos( $robots, 'i:0;s:7:"noindex"' ) !== false ) ) return true;
			}
		}

		// Rank Math specific index status
		if ( get_post_meta( $post_id, '_rank_math_index_status', true ) === 'noindex' ) return true;

		// 3. Rank Math (Global Fallback)
		$post_type = get_post_type( $post_id );
		$rm_options = get_option( 'rank-math-options-titles' );
		if ( is_array( $rm_options ) && isset( $rm_options["pt_{$post_type}_custom_robots"] ) && $rm_options["pt_{$post_type}_custom_robots"] === 'on' ) {
			$global_robots = $rm_options["pt_{$post_type}_robots"] ?? [];
			if ( is_array( $global_robots ) && in_array( 'noindex', $global_robots ) ) return true;
		}

		// 4. SEOPress
		$seopress_noindex = get_post_meta( $post_id, '_seopress_robots_index', true );
		if ( $seopress_noindex === 'yes' ) return true;

		// 5. All in One SEO Pack
		$aioseo_noindex = get_post_meta( $post_id, '_aioseop_noindex', true );
		if ( $aioseo_noindex === 'on' ) return true;

		// 6. Genesis
		if ( get_post_meta($post_id, '_genesis_noindex', true) === '1' ) return true;

		return false;
	}

	/**
	 * Calculates the dynamic threshold for "Mégaphone" status.
	 * Based on the 90th percentile of site impressions, with a safety floor of 500.
	 * 
	 * @return int The calculated threshold.
	 */
	public static function get_megaphone_threshold(): int {
		$cached = get_transient( 'sil_megaphone_threshold' );
		if ( $cached !== false ) {
			return intval( $cached );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'sil_gsc_data';

		// Retrieve all impression counts to calculate distribution
		$impressions = $wpdb->get_col( "SELECT impressions FROM $table WHERE post_id > 0 ORDER BY impressions ASC" );

		if ( empty( $impressions ) ) {
			return 500; // Absolute floor if no data
		}

		$count = count( $impressions );
		$index = floor( $count * 0.9 ); // 90th percentile
		$p90   = intval( $impressions[$index] );

		// Final threshold: Must be in Top 10% AND at least 500
		$threshold = max( $p90, 500 );

		set_transient( 'sil_megaphone_threshold', $threshold, HOUR_IN_SECONDS );

		return $threshold;
	}

	/**
	 * Retrieve GSC performance data for a batch of post IDs in a single query.
	 * 
	 * @param array $post_ids List of post IDs to fetch data for.
	 * @return array Associative array [post_id => data].
	 */
	public static function get_bulk_gsc_performance( array $post_ids ): array {
		if ( empty( $post_ids ) ) {
			return [];
		}

		global $wpdb;
		$table = $wpdb->prefix . 'sil_gsc_data';
		$ids = implode( ',', array_map( 'intval', $post_ids ) );

		$results = $wpdb->get_results( "SELECT post_id, impressions, clicks, position, clicks_delta_percent FROM $table WHERE post_id IN ($ids)", ARRAY_A );

		$performance = [];
		foreach ( $results as $row ) {
			$performance[ intval( $row['post_id'] ) ] = $row;
		}

		return $performance;
	}

	/**
	 * Calculate Reciprocal Rank Fusion (RRF) score.
	 * 
	 * @param int|null $semantic_rank Rank in semantic results (1-indexed).
	 * @param int|null $gsc_rank Rank in GSC results (1-indexed).
	 * @param int $k Constant (default 60).
	 * @return float Calculated RRF score.
	 */
	public static function calculate_rrf_score( ?int $semantic_rank, ?int $gsc_rank, int $k = 60 ): float {
		$score = 0;
		if ( $semantic_rank !== null ) {
			$score += 1 / ( $k + intval( $semantic_rank ) );
		}
		if ( $gsc_rank !== null ) {
			$score += 1 / ( $k + intval( $gsc_rank ) );
		}
		return $score;
	}

	/**
	 * Calculate V17 indicators for a given post and its GSC data.
	 * 
	 * @param int $post_id The post ID.
	 * @param array $gsc_data GSC performance data.
	 * @return array Associative array of boolean indicators.
	 */
	public static function calculate_v17_indicators( int $post_id, array $gsc_data ): array {
		$indicators = [
			'is_decay_critical'    => false,
			'cannibalization_risk' => false,
			'is_new_content'       => false
		];

		// 1. Cold Start / New Content Check
		$post = get_post( $post_id );
		if ( $post ) {
			$publish_date = strtotime( $post->post_date );
			$days_old     = ( time() - $publish_date ) / DAY_IN_SECONDS;
			$impressions  = isset( $gsc_data['impressions'] ) ? intval( $gsc_data['impressions'] ) : 0;

			if ( $days_old < 30 || $impressions < 100 ) {
				$indicators['is_new_content'] = true;
			}
		}

		// 2. Decay Check (> 50% drop)
		if ( isset( $gsc_data['clicks_delta_percent'] ) && floatval( $gsc_data['clicks_delta_percent'] ) < -50 ) {
			$indicators['is_decay_critical'] = true;
		}

		// 3. Cannibalization Risk (Placeholder: requires cross-reference in cluster context)
		// This will be flagged during the main hybrid search loop if cluster similarity is available.

		return $indicators;
	}

	/**
	 * Extracts long-tail keywords (3+ words) from GSC data for a specific post.
	 * 
	 * @param int $post_id The post ID.
	 * @return array List of top 3 long-tail keywords.
	 */
	public static function get_long_tail_semantic_keywords( $post_id ) {
		$gsc_raw = get_post_meta( $post_id, '_sil_gsc_data', true );
		$gsc = json_decode( $gsc_raw, true );
		if ( ! $gsc || ! isset( $gsc['top_queries'] ) ) {
			return [];
		}

		$long_tails = [];
		foreach ( $gsc['top_queries'] as $row ) {
			$query = isset( $row['query'] ) ? $row['query'] : ( isset( $row['keys'][0] ) ? $row['keys'][0] : '' );
			if ( empty( $query ) ) continue;

			// Filter: 3 words or more (count spaces)
			if ( str_word_count( $query ) >= 3 ) {
				$long_tails[] = $query;
			}
		}

		return array_slice( array_unique( $long_tails ), 0, 3 );
	}

	/**
	 * Identifies the best GSC opportunity (High impressions, Low CTR < 1.5%).
	 * 
	 * @param int $post_id The post ID.
	 * @return array|null The query data or null.
	 */
	public static function get_best_gsc_opportunity( $post_id ) {
		$gsc_raw = get_post_meta( $post_id, '_sil_gsc_data', true );
		$gsc = json_decode( $gsc_raw, true );
		if ( ! $gsc || ! isset( $gsc['top_queries'] ) ) {
			return null;
		}

		$best_opp = null;
		$max_imp = 0;

		foreach ( $gsc['top_queries'] as $row ) {
			$imp = intval( $row['impressions'] ?? 0 );
			$clicks = intval( $row['clicks'] ?? 0 );
			$ctr = ( $imp > 0 ) ? ( $clicks / $imp ) * 100 : 0;

			if ( $ctr < 1.5 && $imp > $max_imp ) {
				$max_imp = $imp;
				$best_opp = $row;
			}
		}

		return $best_opp;
	}

	/**
	 * Generates anchor templates for context-aware suggestions.
	 * 
	 * @param int $source_id Source post ID.
	 * @param int $target_id Target post ID.
	 * @return array List of template-based phrases.
	 */
	public static function get_anchor_templates( $source_id, $target_id ) {
		return [
			"Découvrez [Ancre]",
			"En savoir plus sur [Ancre]",
			"Consultez notre guide : [Ancre]",
			"Optimisez votre [Ancre]",
			"Tout savoir sur [Ancre]"
		];
	}
}
