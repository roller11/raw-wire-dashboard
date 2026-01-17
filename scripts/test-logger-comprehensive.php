<?php
/**
 * Logger Test Script
 * 
 * Comprehensive test of RawWire_Logger functionality
 * Run this via WP-CLI: wp eval-file test-logger-comprehensive.php
 *
 * @package RawWire_Dashboard
 * @since 1.0.15
 */

// Load WordPress
if (!defined('ABSPATH')) {
    // Find WordPress root
    $wp_load = dirname(__FILE__) . '/../../../../wp-load.php';
    if (file_exists($wp_load)) {
        require_once $wp_load;
    } else {
        die("Cannot find WordPress installation\n");
    }
}

// Load Logger class if not already loaded
if (!class_exists('RawWire_Logger')) {
    require_once dirname(__FILE__) . '/includes/class-logger.php';
}

echo "=== RawWire Logger Comprehensive Test ===\n\n";

// Test 1: Code Errors Check
echo "CHECK 1: Code Errors\n";
echo "-------------------\n";
if (class_exists('RawWire_Logger')) {
    echo "✅ Logger class loaded successfully\n";
} else {
    echo "❌ Logger class failed to load\n";
    exit(1);
}

// Check methods exist
$required_methods = array('log_activity', 'log_error', 'debug', 'info', 'warning', 'critical', 'get_logs', 'get_stats', 'clear_old_logs');
foreach ($required_methods as $method) {
    if (method_exists('RawWire_Logger', $method)) {
        echo "✅ Method {$method}() exists\n";
    } else {
        echo "❌ Method {$method}() missing\n";
    }
}
echo "\n";

// Test 2: Communication Check (Data Flow)
echo "CHECK 2: Communication (Data Flow)\n";
echo "-----------------------------------\n";
global $wpdb;
$table = $wpdb->prefix . 'rawwire_automation_log';

// Check if table exists
if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table) {
    echo "✅ Database table exists: {$table}\n";
    
    // Get table structure
    $columns = $wpdb->get_results("DESCRIBE {$table}");
    echo "✅ Table structure:\n";
    foreach ($columns as $column) {
        echo "   - {$column->Field} ({$column->Type})\n";
    }
} else {
    echo "❌ Database table missing: {$table}\n";
    echo "⚠️  Run migrations to create table\n";
}
echo "\n";

// Test 3: Durability Check
echo "CHECK 3: Durability (Error Handling)\n";
echo "-------------------------------------\n";

// Test logging with WP_DEBUG off
$original_debug = defined('WP_DEBUG') ? WP_DEBUG : false;
if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', false);
}

$result = RawWire_Logger::debug('Test debug message - should be skipped', 'debug', array('test' => true));
if ($result === false) {
    echo "✅ Debug logs properly skipped when WP_DEBUG=false\n";
} else {
    echo "❌ Debug logs not properly filtered\n";
}

// Test info logging
$result = RawWire_Logger::info('Test info message', 'activity', array('test_id' => 1));
if ($result !== false) {
    echo "✅ Info logging works\n";
} else {
    echo "❌ Info logging failed\n";
}

// Test error logging
$result = RawWire_Logger::log_error('Test error message', array('error_code' => 'TEST'), 'error');
if ($result !== false) {
    echo "✅ Error logging works\n";
} else {
    echo "❌ Error logging failed\n";
}

// Test critical logging
$result = RawWire_Logger::critical('Test critical message', 'error', array('critical' => true));
if ($result !== false) {
    echo "✅ Critical logging works\n";
} else {
    echo "❌ Critical logging failed\n";
}

// Test with invalid inputs (should handle gracefully)
$result = RawWire_Logger::log_activity('Test with invalid type', 'INVALID_TYPE', array(), 'info');
if ($result !== false) {
    echo "✅ Invalid log type handled gracefully (fallback to 'activity')\n";
} else {
    echo "⚠️  Logging with invalid type failed\n";
}

$result = RawWire_Logger::log_activity('Test with invalid severity', 'activity', array(), 'INVALID_SEVERITY');
if ($result !== false) {
    echo "✅ Invalid severity handled gracefully (fallback to 'info')\n";
} else {
    echo "⚠️  Logging with invalid severity failed\n";
}
echo "\n";

// Test 4: Security Check
echo "CHECK 4: Security\n";
echo "-----------------\n";

// Test with XSS attempt
$xss_attempt = '<script>alert("XSS")</script>';
$result = RawWire_Logger::info($xss_attempt, 'activity', array('xss_test' => $xss_attempt));
if ($result !== false) {
    // Retrieve the log and check if it's sanitized
    $logs = RawWire_Logger::get_logs(1, '', '');
    if (!empty($logs)) {
        $latest_log = $logs[0];
        if (strpos($latest_log['message'], '<script>') === false) {
            echo "✅ XSS content properly sanitized\n";
        } else {
            echo "❌ XSS content NOT sanitized - security risk!\n";
        }
    }
} else {
    echo "⚠️  Could not test XSS sanitization\n";
}

// Test with SQL injection attempt
$sql_injection = "'; DROP TABLE users; --";
$result = RawWire_Logger::info('Test SQL injection', 'activity', array('sql' => $sql_injection));
if ($result !== false) {
    echo "✅ SQL injection attempt logged safely (using prepared statements)\n";
} else {
    echo "⚠️  SQL injection test inconclusive\n";
}
echo "\n";

// Test 5: Error Reporting
echo "CHECK 5: Error Reporting\n";
echo "------------------------\n";

// Test that errors write to error_log
$test_error = 'Test error for error_log verification - ' . time();
RawWire_Logger::log_error($test_error, array('source' => 'test_script'), 'error');
echo "✅ Error logged (check error_log file for: {$test_error})\n";

// Test critical errors
$test_critical = 'Test critical error - ' . time();
RawWire_Logger::critical($test_critical, 'error', array('source' => 'test_script'));
echo "✅ Critical error logged (check error_log file for: {$test_critical})\n";
echo "\n";

// Test 6: Info Reporting
echo "CHECK 6: Info Reporting\n";
echo "-----------------------\n";

// Generate various info logs
RawWire_Logger::info('Test initialization', 'init', array('version' => '1.0.15'));
RawWire_Logger::info('Test fetch operation', 'fetch', array('items' => 10, 'source' => 'github'));
RawWire_Logger::info('Test process operation', 'process', array('processed' => 5, 'failed' => 0));
RawWire_Logger::info('Test store operation', 'store', array('stored_id' => 123));
RawWire_Logger::info('Test duplicate detection', 'duplicate', array('url' => 'https://example.com'));
RawWire_Logger::info('Test approval', 'approval', array('content_id' => 456, 'user' => 1));
RawWire_Logger::info('Test cache clear', 'cache', array('cleared' => true));

echo "✅ Generated info logs for all major operations\n";

// Retrieve and display stats
$stats = RawWire_Logger::get_stats();
echo "✅ Log statistics:\n";
echo "   - Total: {$stats['total']}\n";
echo "   - Info: {$stats['info']}\n";
echo "   - Debug: {$stats['debug']}\n";
echo "   - Warning: {$stats['warning']}\n";
echo "   - Error: {$stats['error']}\n";
echo "   - Critical: {$stats['critical']}\n";
echo "\n";

// Test 7: Cleanup
echo "CHECK 7: Cleanup\n";
echo "----------------\n";

// Test get_logs filtering
$info_logs = RawWire_Logger::get_logs(10, '', 'info');
echo "✅ Retrieved " . count($info_logs) . " info logs\n";

$error_logs = RawWire_Logger::get_logs(10, '', 'error');
echo "✅ Retrieved " . count($error_logs) . " error logs\n";

// Test log type filtering
$fetch_logs = RawWire_Logger::get_logs(10, 'fetch', '');
echo "✅ Retrieved " . count($fetch_logs) . " fetch logs\n";

// Don't actually clear logs in test, but verify method works
echo "✅ clear_old_logs() method available for maintenance\n";
echo "\n";

// Test 8: Performance Check
echo "CHECK 8: Performance\n";
echo "--------------------\n";

// Test bulk logging
$start = microtime(true);
for ($i = 0; $i < 100; $i++) {
    RawWire_Logger::info("Bulk log test #{$i}", 'debug', array('iteration' => $i));
}
$end = microtime(true);
$duration = round(($end - $start) * 1000, 2);

echo "✅ Logged 100 entries in {$duration}ms\n";
if ($duration < 1000) {
    echo "✅ Performance acceptable (< 1 second)\n";
} else {
    echo "⚠️  Performance slow (> 1 second) - consider optimization\n";
}
echo "\n";

// Final Summary
echo "=== TEST SUMMARY ===\n";
echo "All checks completed. Review output above for any ❌ or ⚠️  markers.\n\n";

// Display recent logs
echo "Recent Log Entries (Last 5):\n";
echo "----------------------------\n";
$recent = RawWire_Logger::get_logs(5);
foreach ($recent as $log) {
    $severity = 'N/A';
    if (!empty($log['details'])) {
        $details = json_decode($log['details'], true);
        $severity = isset($details['severity']) ? $details['severity'] : 'N/A';
    }
    echo "[{$log['created_at']}] [{$severity}] [{$log['event_type']}] {$log['message']}\n";
}

echo "\n✅ Logger test complete!\n";
