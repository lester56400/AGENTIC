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
	public static function is_noindexed( $post_id ) {
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
}
