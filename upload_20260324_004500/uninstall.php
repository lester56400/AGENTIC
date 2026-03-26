<?php
/**
 * Smart Internal Links Uninstall
 *
 * This file is called when the plugin is deleted via the WordPress admin.
 * It ensures all custom tables, options, transients, and cron jobs are removed.
 *
 * @package SmartInternalLinks
 */

// If uninstall not called from WordPress, exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

/**
 * 1. Clean up Custom Tables
 */
$tables = [
    $wpdb->prefix . 'sil_embeddings',
    $wpdb->prefix . 'sil_gsc_data',
    $wpdb->prefix . 'sil_links',
];

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS $table");
}

/**
 * 2. Clean up Options
 */
$options = [
    'sil_openai_api_key',
    'sil_openai_model',
    'sil_openai_custom_model',
    'sil_auto_link',
    'sil_max_links',
    'sil_similarity_threshold',
    'sil_link_scope',
    'sil_exclude_external',
    'sil_exclude_noindex',
    'sil_gsc_property_url',
    'sil_gsc_client_id',
    'sil_gsc_client_secret',
    'sil_gsc_oauth_tokens',
    'sil_gsc_last_sync',
    'sil_gsc_auto_sync',
    'sil_options', // Legacy or explicitly requested option
];

foreach ($options as $option) {
    delete_option($option);
    // Also delete site-wide options if it was a multisite installation
    delete_site_option($option);
}

/**
 * 3. Clean up Transients
 */
delete_transient('sil_debug_logs');

/**
 * 4. Clean up Scheduled Tasks (Cron)
 */
wp_clear_scheduled_hook('sil_gsc_daily_sync');
