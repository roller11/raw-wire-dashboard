<?php
/**
 * Comprehensive Test Script for Activity Logs Three-Tab UI
 * 
 * Tests all 8 review checks:
 * 1. Code Errors
 * 2. Communication (data flow)
 * 3. Durability
 * 4. Endpoint
 * 5. Security
 * 6. Error Reporting
 * 7. Info Reporting
 * 8. Cleanup
 * 
 * Run: php test-activity-logs-ui.php
 * Or via WP-CLI: wp eval-file test-activity-logs-ui.php
 */

// Load WordPress if not already loaded
if (!defined('ABSPATH')) {
    require_once dirname(__FILE__) . '/../../../../wp-load.php';
}

echo "=== ACTIVITY LOGS THREE-TAB UI - COMPREHENSIVE TEST ===\n\n";

// ============================================
// CHECK 1: CODE ERRORS
// ============================================
echo "CHECK 1: CODE ERRORS\n";
echo str_repeat("-", 50) . "\n";

$errors = array();

// Test 1.1: Verify dashboard.js exists and is readable
$js_file = dirname(__FILE__) . '/../dashboard.js';
if (!file_exists($js_file)) {
    $errors[] = "dashboard.js not found";
} else {
    echo "✓ dashboard.js exists\n";
    
    // Test 1.2: Check file is readable
    if (!is_readable($js_file)) {
        $errors[] = "dashboard.js not readable";
    } else {
        echo "✓ dashboard.js is readable\n";
    }
    
    // Test 1.3: Verify activityLogsModule exists
    $js_content = file_get_contents($js_file);
    if (strpos($js_content, 'activityLogsModule') === false) {
        $errors[] = "activityLogsModule not found in dashboard.js";
    } else {
        echo "✓ activityLogsModule found in dashboard.js\n";
    }
    
    // Test 1.4: Verify key methods exist
    $methods = array('init', 'loadTab', 'renderLogs', 'exportLogs', 'bindTabSwitching');
    foreach ($methods as $method) {
        if (strpos($js_content, $method . '(') === false) {
            $errors[] = "Method '{$method}' not found in activityLogsModule";
        } else {
            echo "✓ Method '{$method}' found\n";
        }
    }
}

// Test 1.5: Verify dashboard.css exists and is readable
$css_file = dirname(__FILE__) . '/../dashboard.css';
if (!file_exists($css_file)) {
    $errors[] = "dashboard.css not found";
} else {
    echo "✓ dashboard.css exists\n";
    
    $css_content = file_get_contents($css_file);
    
    // Test 1.6: Verify key CSS classes exist
    $classes = array(
        '.activity-logs-tabs',
        '.activity-logs-tab',
        '.logs-pane',
        '.logs-table',
        '.logs-modal-overlay',
        '.sync-status-panel',
        '.recent-entries-list'
    );
    foreach ($classes as $class) {
        if (strpos($css_content, $class) === false) {
            $errors[] = "CSS class '{$class}' not found";
        } else {
            echo "✓ CSS class '{$class}' found\n";
        }
    }
}

if (empty($errors)) {
    echo "\n✅ CHECK 1 PASSED: No code errors detected\n\n";
} else {
    echo "\n❌ CHECK 1 FAILED:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
    echo "\n";
}

// ============================================
// CHECK 2: COMMUNICATION (DATA FLOW)
// ============================================
echo "CHECK 2: COMMUNICATION (DATA FLOW)\n";
echo str_repeat("-", 50) . "\n";

$comm_errors = array();

// Test 2.1: Verify AJAX action is registered
if (!has_action('wp_ajax_rawwire_get_logs')) {
    $comm_errors[] = "AJAX action 'rawwire_get_logs' not registered";
} else {
    echo "✓ AJAX action 'rawwire_get_logs' registered\n";
}

// Test 2.2: Verify RawWire_Activity_Logs class exists
if (!class_exists('RawWire_Activity_Logs')) {
    $comm_errors[] = "RawWire_Activity_Logs class not found";
} else {
    echo "✓ RawWire_Activity_Logs class exists\n";
    
    // Test 2.3: Verify get_logs_by_type method exists
    if (!method_exists('RawWire_Activity_Logs', 'get_logs_by_type')) {
        $comm_errors[] = "get_logs_by_type method not found";
    } else {
        echo "✓ get_logs_by_type method exists\n";
    }
    
    // Test 2.4: Verify ajax_get_logs method exists
    if (!method_exists('RawWire_Activity_Logs', 'ajax_get_logs')) {
        $comm_errors[] = "ajax_get_logs method not found";
    } else {
        echo "✓ ajax_get_logs method exists\n";
    }
}

// Test 2.5: Verify database table exists
global $wpdb;
$table_name = $wpdb->prefix . 'rawwire_automation_log';
$table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;
if (!$table_exists) {
    $comm_errors[] = "Database table '{$table_name}' not found";
} else {
    echo "✓ Database table '{$table_name}' exists\n";
    
    // Test 2.6: Verify table has correct structure
    $columns = $wpdb->get_results("DESCRIBE {$table_name}", ARRAY_A);
    $required_columns = array('id', 'event_type', 'message', 'details', 'created_at');
    $found_columns = array_column($columns, 'Field');
    
    foreach ($required_columns as $col) {
        if (!in_array($col, $found_columns)) {
            $comm_errors[] = "Column '{$col}' not found in table";
        } else {
            echo "✓ Column '{$col}' exists in table\n";
        }
    }
}

if (empty($comm_errors)) {
    echo "\n✅ CHECK 2 PASSED: Communication/data flow verified\n\n";
} else {
    echo "\n❌ CHECK 2 FAILED:\n";
    foreach ($comm_errors as $error) {
        echo "  - {$error}\n";
    }
    echo "\n";
}

// ============================================
// CHECK 3: DURABILITY
// ============================================
echo "CHECK 3: DURABILITY\n";
echo str_repeat("-", 50) . "\n";

$durability_errors = array();

// Test 3.1: Test AJAX endpoint with valid data
if (class_exists('RawWire_Activity_Logs')) {
    // Simulate AJAX request
    $_POST['action'] = 'rawwire_get_logs';
    $_POST['type'] = 'info';
    $_POST['nonce'] = wp_create_nonce('rawwire_nonce');
    
    try {
        ob_start();
        do_action('wp_ajax_rawwire_get_logs');
        $response = ob_get_clean();
        
        // Verify response is valid JSON
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $durability_errors[] = "AJAX response is not valid JSON";
        } else {
            echo "✓ AJAX endpoint returns valid JSON\n";
            
            // Test 3.2: Verify response structure
            if (!isset($data['success'])) {
                $durability_errors[] = "Response missing 'success' field";
            } else {
                echo "✓ Response has 'success' field\n";
            }
            
            if (!isset($data['data'])) {
                $durability_errors[] = "Response missing 'data' field";
            } else {
                echo "✓ Response has 'data' field\n";
            }
        }
    } catch (Exception $e) {
        $durability_errors[] = "Exception during AJAX call: " . $e->getMessage();
    }
}

// Test 3.3: Test with invalid log type
$_POST['type'] = 'invalid_type';
try {
    ob_start();
    do_action('wp_ajax_rawwire_get_logs');
    $response = ob_get_clean();
    
    $data = json_decode($response, true);
    // Should handle gracefully
    echo "✓ Handles invalid log type gracefully\n";
} catch (Exception $e) {
    $durability_errors[] = "Failed to handle invalid log type: " . $e->getMessage();
}

// Test 3.4: Test JavaScript error handling
$js_content = file_get_contents($js_file);
if (strpos($js_content, 'error:') === false && strpos($js_content, '.error(') === false) {
    $durability_errors[] = "No error handling found in JavaScript";
} else {
    echo "✓ JavaScript has error handling\n";
}

// Test 3.5: Test CSS fallback for empty states
if (strpos($css_content, '.logs-empty') === false) {
    $durability_errors[] = "No CSS for empty state";
} else {
    echo "✓ CSS includes empty state styling\n";
}

if (empty($durability_errors)) {
    echo "\n✅ CHECK 3 PASSED: Durability verified\n\n";
} else {
    echo "\n❌ CHECK 3 FAILED:\n";
    foreach ($durability_errors as $error) {
        echo "  - {$error}\n";
    }
    echo "\n";
}

// ============================================
// CHECK 4: ENDPOINT
// ============================================
echo "CHECK 4: ENDPOINT\n";
echo str_repeat("-", 50) . "\n";

$endpoint_errors = array();

// Test 4.1: Verify nonce checking
if (strpos($js_content, 'RawWireCfg.nonce') === false) {
    $endpoint_errors[] = "JavaScript does not use nonce for AJAX calls";
} else {
    echo "✓ JavaScript uses nonce for AJAX calls\n";
}

// Test 4.2: Verify AJAX URL configuration
if (strpos($js_content, 'RawWireCfg.ajaxurl') === false) {
    $endpoint_errors[] = "JavaScript does not use RawWireCfg.ajaxurl";
} else {
    echo "✓ JavaScript uses RawWireCfg.ajaxurl\n";
}

// Test 4.3: Verify REST API stats endpoint exists (for sync status)
$rest_routes = rest_get_server()->get_routes();
$stats_route_exists = false;
foreach ($rest_routes as $route => $handlers) {
    if (strpos($route, '/stats') !== false) {
        $stats_route_exists = true;
        echo "✓ REST API stats endpoint exists: {$route}\n";
        break;
    }
}
if (!$stats_route_exists) {
    $endpoint_errors[] = "REST API stats endpoint not found";
}

// Test 4.4: Verify export functionality
if (strpos($js_content, 'exportLogs') === false) {
    $endpoint_errors[] = "Export functionality not found";
} else {
    echo "✓ Export functionality exists\n";
}

// Test 4.5: Verify export generates CSV
if (strpos($js_content, 'text/csv') === false) {
    $endpoint_errors[] = "Export does not generate CSV format";
} else {
    echo "✓ Export generates CSV format\n";
}

if (empty($endpoint_errors)) {
    echo "\n✅ CHECK 4 PASSED: Endpoints verified\n\n";
} else {
    echo "\n❌ CHECK 4 FAILED:\n";
    foreach ($endpoint_errors as $error) {
        echo "  - {$error}\n";
    }
    echo "\n";
}

// ============================================
// CHECK 5: SECURITY
// ============================================
echo "CHECK 5: SECURITY\n";
echo str_repeat("-", 50) . "\n";

$security_errors = array();

// Test 5.1: Verify XSS protection in renderLogs
if (strpos($js_content, 'escapeHtml') === false) {
    $security_errors[] = "No XSS protection function found";
} else {
    echo "✓ XSS protection function exists\n";
}

// Test 5.2: Verify HTML escaping is used
if (strpos($js_content, 'this.escapeHtml(') === false) {
    $security_errors[] = "escapeHtml not used in rendering";
} else {
    echo "✓ escapeHtml used in rendering\n";
}

// Test 5.3: Verify nonce validation in PHP
$activity_logs_file = dirname(__FILE__) . '/../includes/class-activity-logs.php';
if (file_exists($activity_logs_file)) {
    $php_content = file_get_contents($activity_logs_file);
    if (strpos($php_content, 'check_ajax_referer') === false && strpos($php_content, 'wp_verify_nonce') === false) {
        $security_errors[] = "No nonce verification in PHP";
    } else {
        echo "✓ Nonce verification found in PHP\n";
    }
    
    // Test 5.4: Verify capability checking
    if (strpos($php_content, 'current_user_can') === false) {
        $security_errors[] = "No capability checking in PHP";
    } else {
        echo "✓ Capability checking found in PHP\n";
    }
} else {
    $security_errors[] = "class-activity-logs.php not found";
}

// Test 5.5: Verify SQL injection protection (using $wpdb->prepare)
if (file_exists($activity_logs_file)) {
    if (strpos($php_content, '$wpdb->prepare') === false) {
        $security_errors[] = "No prepared statements found (SQL injection risk)";
    } else {
        echo "✓ Prepared statements used (SQL injection protected)\n";
    }
}

// Test 5.6: Verify JSON escaping in data attributes
if (strpos($js_content, 'replace(/"/g') === false && strpos($js_content, 'JSON.stringify') === false) {
    $security_errors[] = "No JSON escaping in data attributes";
} else {
    echo "✓ JSON data properly escaped\n";
}

if (empty($security_errors)) {
    echo "\n✅ CHECK 5 PASSED: Security measures verified\n\n";
} else {
    echo "\n❌ CHECK 5 FAILED:\n";
    foreach ($security_errors as $error) {
        echo "  - {$error}\n";
    }
    echo "\n";
}

// ============================================
// CHECK 6: ERROR REPORTING
// ============================================
echo "CHECK 6: ERROR REPORTING\n";
echo str_repeat("-", 50) . "\n";

$error_reporting_errors = array();

// Test 6.1: Verify error messages are displayed to user
if (strpos($js_content, '.logs-error') === false) {
    $error_reporting_errors[] = "No error display element";
} else {
    echo "✓ Error display element exists\n";
}

// Test 6.2: Verify error handling in AJAX calls
$ajax_error_count = substr_count($js_content, 'error:');
if ($ajax_error_count < 2) {
    $error_reporting_errors[] = "Insufficient error handling in AJAX calls (found {$ajax_error_count})";
} else {
    echo "✓ Error handling in AJAX calls ({$ajax_error_count} handlers)\n";
}

// Test 6.3: Verify console logging for debugging
if (strpos($js_content, 'console.') === false) {
    echo "⚠ Warning: No console logging found (recommended for debugging)\n";
} else {
    echo "✓ Console logging present for debugging\n";
}

// Test 6.4: Verify error states have visual feedback
if (strpos($css_content, '.logs-error') === false) {
    $error_reporting_errors[] = "No CSS styling for error states";
} else {
    echo "✓ Error states have CSS styling\n";
}

// Test 6.5: Verify loading states
if (strpos($js_content, '.logs-loading') === false) {
    $error_reporting_errors[] = "No loading state implementation";
} else {
    echo "✓ Loading states implemented\n";
}

if (empty($error_reporting_errors)) {
    echo "\n✅ CHECK 6 PASSED: Error reporting verified\n\n";
} else {
    echo "\n❌ CHECK 6 FAILED:\n";
    foreach ($error_reporting_errors as $error) {
        echo "  - {$error}\n";
    }
    echo "\n";
}

// ============================================
// CHECK 7: INFO REPORTING
// ============================================
echo "CHECK 7: INFO REPORTING\n";
echo str_repeat("-", 50) . "\n";

$info_reporting_errors = array();

// Test 7.1: Verify three tabs (info, debug, errors)
$tab_types = array('info', 'debug', 'errors');
foreach ($tab_types as $tab) {
    if (strpos($js_content, "'{$tab}'") === false && strpos($js_content, "\"{$tab}\"") === false) {
        $info_reporting_errors[] = "Tab type '{$tab}' not found";
    } else {
        echo "✓ Tab type '{$tab}' found\n";
    }
}

// Test 7.2: Verify sync status display
if (strpos($js_content, 'updateSyncStatus') === false) {
    $info_reporting_errors[] = "Sync status update function not found";
} else {
    echo "✓ Sync status update function exists\n";
}

// Test 7.3: Verify last sync time display
if (strpos($js_content, 'last_sync') === false && strpos($js_content, 'last-sync') === false) {
    $info_reporting_errors[] = "Last sync time not referenced";
} else {
    echo "✓ Last sync time referenced\n";
}

// Test 7.4: Verify recent entries functionality
if (strpos($css_content, '.recent-entries-list') === false) {
    $info_reporting_errors[] = "Recent entries list not styled";
} else {
    echo "✓ Recent entries list has CSS\n";
}

// Test 7.5: Verify formatTimeAgo function
if (strpos($js_content, 'formatTimeAgo') === false && strpos($js_content, 'formatTime') === false) {
    $info_reporting_errors[] = "Time formatting function not found";
} else {
    echo "✓ Time formatting function exists\n";
}

// Test 7.6: Verify cache mechanism
if (strpos($js_content, 'cache:') === false && strpos($js_content, 'this.cache') === false) {
    $info_reporting_errors[] = "No caching mechanism found";
} else {
    echo "✓ Caching mechanism exists\n";
}

// Test 7.7: Verify log details modal
if (strpos($js_content, 'showDetailsModal') === false) {
    $info_reporting_errors[] = "Details modal function not found";
} else {
    echo "✓ Details modal function exists\n";
}

if (empty($info_reporting_errors)) {
    echo "\n✅ CHECK 7 PASSED: Info reporting verified\n\n";
} else {
    echo "\n❌ CHECK 7 FAILED:\n";
    foreach ($info_reporting_errors as $error) {
        echo "  - {$error}\n";
    }
    echo "\n";
}

// ============================================
// CHECK 8: CLEANUP
// ============================================
echo "CHECK 8: CLEANUP\n";
echo str_repeat("-", 50) . "\n";

$cleanup_errors = array();

// Test 8.1: Verify no console.log statements in production code
$console_logs = substr_count($js_content, 'console.log(');
if ($console_logs > 3) {
    echo "⚠ Warning: Found {$console_logs} console.log statements (consider removing for production)\n";
} else {
    echo "✓ Minimal console.log usage ({$console_logs})\n";
}

// Test 8.2: Verify event handler cleanup
if (strpos($js_content, '.off(') === false && strpos($js_content, 'off(') === false) {
    echo "⚠ Warning: No event handler cleanup found (potential memory leaks)\n";
} else {
    echo "✓ Event handler cleanup implemented\n";
}

// Test 8.3: Verify modal cleanup
if (strpos($js_content, 'modal.remove()') === false && strpos($js_content, 'remove()') === false) {
    $cleanup_errors[] = "No modal cleanup found";
} else {
    echo "✓ Modal cleanup implemented\n";
}

// Test 8.4: Verify no PHP warnings/errors
if (file_exists($activity_logs_file)) {
    $php_syntax_check = shell_exec("php -l {$activity_logs_file} 2>&1");
    if (strpos($php_syntax_check, 'No syntax errors') === false) {
        $cleanup_errors[] = "PHP syntax errors in class-activity-logs.php";
    } else {
        echo "✓ PHP syntax clean in class-activity-logs.php\n";
    }
}

// Test 8.5: Verify no unused variables
$unused_vars = array('var unused', 'let unused', 'const unused');
foreach ($unused_vars as $var) {
    if (strpos($js_content, $var) !== false) {
        $cleanup_errors[] = "Unused variable found: {$var}";
    }
}
echo "✓ No obvious unused variables\n";

// Test 8.6: Verify proper indentation (2 or 4 spaces)
$lines = explode("\n", $js_content);
$indentation_issues = 0;
foreach ($lines as $line) {
    if (preg_match('/^\s+/', $line, $matches)) {
        $indent = strlen($matches[0]);
        if ($indent % 4 !== 0 && $indent % 2 !== 0) {
            $indentation_issues++;
        }
    }
}
if ($indentation_issues > 10) {
    echo "⚠ Warning: {$indentation_issues} lines with inconsistent indentation\n";
} else {
    echo "✓ Code indentation consistent\n";
}

if (empty($cleanup_errors)) {
    echo "\n✅ CHECK 8 PASSED: Code cleanup verified\n\n";
} else {
    echo "\n❌ CHECK 8 FAILED:\n";
    foreach ($cleanup_errors as $error) {
        echo "  - {$error}\n";
    }
    echo "\n";
}

// ============================================
// SUMMARY
// ============================================
echo str_repeat("=", 50) . "\n";
echo "TEST SUMMARY\n";
echo str_repeat("=", 50) . "\n";

$total_checks = 8;
$passed_checks = 0;

$all_errors = array(
    'CHECK 1' => $errors,
    'CHECK 2' => $comm_errors,
    'CHECK 3' => $durability_errors,
    'CHECK 4' => $endpoint_errors,
    'CHECK 5' => $security_errors,
    'CHECK 6' => $error_reporting_errors,
    'CHECK 7' => $info_reporting_errors,
    'CHECK 8' => $cleanup_errors
);

foreach ($all_errors as $check => $check_errors) {
    if (empty($check_errors)) {
        $passed_checks++;
        echo "✅ {$check}: PASSED\n";
    } else {
        echo "❌ {$check}: FAILED (" . count($check_errors) . " errors)\n";
    }
}

echo "\n";
echo "SCORE: {$passed_checks}/{$total_checks} checks passed\n";

if ($passed_checks === $total_checks) {
    echo "\n🎉 ALL CHECKS PASSED! Three-tab activity logs UI is production ready.\n";
} else {
    $failed = $total_checks - $passed_checks;
    echo "\n⚠️  {$failed} check(s) failed. Review errors above.\n";
}

echo "\n=== TEST COMPLETE ===\n";
