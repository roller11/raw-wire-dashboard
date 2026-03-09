<?php

/**
 * Clear all lead generator database records.
 *
 * Usage (WP-CLI):
 *   wp eval-file scripts/clear_lead_data.php
 *
 * Usage (browser, if ABSPATH is correct):
 *   php scripts/clear_lead_data.php
 *
 * Truncates the four lead-generator tables and resets related options/transients.
 */

// Allow running via WP-CLI (wp eval-file) or standalone with wp-load.
if (!function_exists('add_action')) {
    // Standalone - load WordPress.
    if (!defined('ABSPATH')) {
        define('ABSPATH', '/var/www/html/wordpress/');
    }
    require_once ABSPATH . 'wp-load.php';
}

global $wpdb;

// Lead-generator tables (match class-lead-generator.php $tables).
$tables = array(
    'rawwire_lead_sources',
    'rawwire_lead_candidates',
    'rawwire_lead_archive',
    'rawwire_lead_content',
);

echo "=== Raw Wire Lead Generator - Clear All Data ===\n\n";

foreach ($tables as $table_name) {
    $full_table = $wpdb->prefix . $table_name;

    // Verify table exists before truncating.
    $exists = $wpdb->get_var(
        $wpdb->prepare("SHOW TABLES LIKE %s", $full_table)
    );

    if ($exists) {
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$full_table}`");
        $wpdb->query("TRUNCATE TABLE `{$full_table}`");
        echo "Truncated {$full_table} ({$count} rows removed)\n";
    } else {
        echo "Skipped {$full_table} (table does not exist)\n";
    }
}

// Also clear the older workflow tables if they exist.
$legacy_tables = array(
    'rawwire_candidates',
    'rawwire_approvals',
    'rawwire_content',
    'rawwire_releases',
    'rawwire_published',
    'rawwire_archives',
);

echo "\n--- Legacy workflow tables ---\n";
foreach ($legacy_tables as $table_name) {
    $full_table = $wpdb->prefix . $table_name;
    $exists = $wpdb->get_var(
        $wpdb->prepare("SHOW TABLES LIKE %s", $full_table)
    );

    if ($exists) {
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$full_table}`");
        $wpdb->query("TRUNCATE TABLE `{$full_table}`");
        echo "Truncated {$full_table} ({$count} rows removed)\n";
    } else {
        echo "Skipped {$full_table} (not present)\n";
    }
}

// Reset related options and transients.
echo "\n--- Clearing options & transients ---\n";

$options_to_clear = array(
    'rawwire_last_batch_time',
    'rawwire_last_batch_ids',
    'rawwire_last_import_count',
    'rawwire_last_import_skipped',
    'rawwire_records_collection_step',
);

foreach ($options_to_clear as $option) {
    delete_option($option);
    echo "Deleted option: {$option}\n";
}

// Clear any running-workflow transients.
delete_transient('rawwire_running_workflow');
delete_transient('rawwire_workflow_progress');
echo "Deleted transients: rawwire_running_workflow, rawwire_workflow_progress\n";

echo "\n=== Cleanup complete ===\n";
