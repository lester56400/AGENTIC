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

		// Yoast SEO
		$yoast_noindex = get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
		if ( $yoast_noindex == 1 || $yoast_noindex === '1' ) {
			return true;
		}

		// Rank Math
		$rankmath_robots = get_post_meta( $post_id, 'rank_math_robots', true );
		if ( is_array( $rankmath_robots ) && in_array( 'noindex', $rankmath_robots ) ) {
			return true;
		}
		if ( is_string( $rankmath_robots ) && strpos( $rankmath_robots, 'noindex' ) !== false ) {
			return true;
		}

		// SEOPress
		$seopress_noindex = get_post_meta( $post_id, '_seopress_robots_index', true );
		if ( $seopress_noindex === 'yes' ) {
			return true;
		}

		// All in One SEO Pack
		$aioseo_noindex = get_post_meta( $post_id, '_aioseop_noindex', true );
		if ( $aioseo_noindex === 'on' ) {
			return true;
		}

		return false;
	}
}
