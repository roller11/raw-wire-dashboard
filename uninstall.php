<?php
/**
 * Uninstall RawWire Dashboard
 *
 * @since 1.0.18
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete plugin options
delete_option('rawwire_version');
delete_option('rawwire_last_sync');
delete_option('rawwire_api_key');
delete_option('rawwire_log_level');

// Delete plugin tables
global $wpdb;

$table_name = $wpdb->prefix . 'rawwire_content';
$wpdb->query("DROP TABLE IF EXISTS $table_name");

$table_name = $wpdb->prefix . 'rawwire_logs';
$wpdb->query("DROP TABLE IF EXISTS $table_name");

// Clear any cached data
wp_cache_flush();

// Clear scheduled events
wp_clear_scheduled_hook('rawwire_sync_data');