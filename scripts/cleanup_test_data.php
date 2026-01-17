<?php
/**
 * Cleanup test data inserted by batch_test.php
 * Updated to use workflow tables (candidates, approvals, content, etc.)
 */
if (!defined('ABSPATH')) define('ABSPATH','/var/www/html/');
require_once ABSPATH . 'wp-load.php';

global $wpdb;

// Workflow tables
$candidates_table = $wpdb->prefix . 'rawwire_candidates';
$approvals_table = $wpdb->prefix . 'rawwire_approvals';
$content_table = $wpdb->prefix . 'rawwire_content';
$releases_table = $wpdb->prefix . 'rawwire_releases';
$published_table = $wpdb->prefix . 'rawwire_published';
$archives_table = $wpdb->prefix . 'rawwire_archives';

// Legacy tables (if they still exist)
$findings_table = $wpdb->prefix . 'rawwire_findings';
$queue_table = $wpdb->prefix . 'rawwire_queue';

// Delete content rows inserted by unit tests
$deleted_content = $wpdb->query($wpdb->prepare("DELETE FROM {$content_table} WHERE source = %s", 'unit_test'));
echo "Deleted content rows (source=unit_test): " . intval($deleted_content) . "\n";

// Delete approvals created by auto_batch or with Batch Test Item title
$deleted_approvals = $wpdb->query($wpdb->prepare("DELETE FROM {$approvals_table} WHERE (source = %s OR title LIKE %s) AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)", 'auto_batch', 'Batch Test Item %'));
echo "Deleted approvals (auto_batch or Batch Test Item): " . intval($deleted_approvals) . "\n";

// Delete candidates from test runs
$deleted_candidates = $wpdb->query($wpdb->prepare("DELETE FROM {$candidates_table} WHERE (source = %s OR title LIKE %s) AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)", 'unit_test', 'Batch Test Item %'));
echo "Deleted candidates (unit_test or Batch Test Item): " . intval($deleted_candidates) . "\n";

// Legacy cleanup (if tables exist)
if ($wpdb->get_var("SHOW TABLES LIKE '{$findings_table}'")) {
    $deleted_findings = $wpdb->query($wpdb->prepare("DELETE FROM {$findings_table} WHERE (source_id = %s OR title LIKE %s) AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)", 'auto_batch', 'Batch Test Item %'));
    echo "Deleted findings (legacy): " . intval($deleted_findings) . "\n";
}

if ($wpdb->get_var("SHOW TABLES LIKE '{$queue_table}'")) {
    $wpdb->query("DELETE FROM {$queue_table} WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)");
    echo "Cleaned legacy queue entries.\n";
}

// Reset last batch pointers
update_option('rawwire_last_batch_time', 0);
update_option('rawwire_last_batch_ids', wp_json_encode(array()));
echo "Reset rawwire_last_batch_time and rawwire_last_batch_ids.\n";

echo "Cleanup complete.\n";

return 0;
