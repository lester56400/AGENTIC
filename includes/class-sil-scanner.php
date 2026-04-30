<?php
/**
 * SIL_Scanner
 * Scans post content for internal links and updates the database.
 * 
 * @package SmartInternalLinks
 */

if (!defined('ABSPATH')) {
    exit;
}

class SIL_Scanner
{
    /**
     * Main plugin instance.
     *
     * @var SmartInternalLinks
     */
    private $main;

    /**
     * Constructor.
     *
     * @param SmartInternalLinks $main The main plugin instance.
     */
    public function __construct($main)
    {
        $this->main = $main;
    }

    /**
     * Scan a post for internal links and update the sil_links table.
     *
     * @param int $post_id The ID of the post to scan.
     * @return int Number of internal links found.
     */
    public function scan_post_links($post_id)
    {
        $post = get_post($post_id);
        if (!$post) {
            return 0;
        }

        $content = $post->post_content;
        $site_url = home_url();
        $site_host = parse_url($site_url, PHP_URL_HOST);

        // Regular expression to find internal links
        // Matches both full URLs and relative URLs that belong to this site.
        preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER);

        global $wpdb;
        $table_links = $wpdb->prefix . 'sil_links';

        // 1. Clear existing links for this source
        $wpdb->delete($table_links, ['source_id' => $post_id], ['%d']);

        $count = 0;
        foreach ($matches as $match) {
            $url = $match[1];
            $anchor = wp_strip_all_tags($match[2]);

            // Skip anchors, mailto, tel, etc.
            if (preg_match('/^(#|mailto:|tel:|javascript:)/i', $url)) {
                continue;
            }

            // Normalize relative URLs
            if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
                $url = untrailingslashit($site_url) . $url;
            }

            $url_host = parse_url($url, PHP_URL_HOST);
            
            // Check if it's an internal link
            if (empty($url_host) || strtolower($url_host) === strtolower($site_host)) {
                $target_id = url_to_postid($url);
                
                // If we found a valid target post ID
                if ($target_id) {
                    $wpdb->insert(
                        $table_links,
                        [
                            'source_id' => $post_id,
                            'target_id' => $target_id,
                            'target_url' => esc_url_raw($url),
                            'anchor' => sanitize_text_field($anchor),
                            'status' => 'valid'
                        ],
                        ['%d', '%d', '%s', '%s', '%s']
                    );
                    $count++;
                }
            }
        }

        return $count;
    }
}
