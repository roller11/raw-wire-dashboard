#!/usr/bin/env php
<?php
/**
 * Standalone CSS Sanitizer Demo
 * 
 * Demonstrates the CSS sanitizer blocking malicious inputs
 * Run: php test-css-sanitizer-standalone.php
 */

// Load WordPress test environment
$_tests_dir = getenv('WP_TESTS_DIR');
if (!$_tests_dir) {
    $_tests_dir = '/tmp/wordpress-tests-lib';
}

// Minimal bootstrap for validator class
define('ABSPATH', '/tmp/wordpress/');
require_once __DIR__ . '/includes/class-validator.php';

echo "\n╔══════════════════════════════════════════════════════════════╗\n";
echo "║        CSS Sanitizer Security Demo (v1.0.13)                ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

// Test cases
$tests = array(
    // Valid colors
    array('input' => '#0d9488', 'expected' => 'PASS', 'desc' => 'Valid hex color'),
    array('input' => 'rgb(255,0,0)', 'expected' => 'PASS', 'desc' => 'Valid RGB color'),
    array('input' => 'rgba(13,148,136,0.8)', 'expected' => 'PASS', 'desc' => 'Valid RGBA color'),
    array('input' => 'hsl(174,79%,32%)', 'expected' => 'PASS', 'desc' => 'Valid HSL color'),
    array('input' => 'transparent', 'expected' => 'PASS', 'desc' => 'Valid named color'),
    
    // CSS injection attempts (should all be BLOCKED)
    array('input' => 'url(javascript:alert(1))', 'expected' => 'BLOCK', 'desc' => 'JavaScript URL injection'),
    array('input' => '#fff;background:url(evil.com)', 'expected' => 'BLOCK', 'desc' => 'Multiple properties injection'),
    array('input' => '@import url(evil.css)', 'expected' => 'BLOCK', 'desc' => '@import directive injection'),
    array('input' => 'expression(alert(1))', 'expected' => 'BLOCK', 'desc' => 'IE expression() injection'),
    array('input' => '<script>alert(1)</script>', 'expected' => 'BLOCK', 'desc' => 'HTML tag injection'),
    array('input' => 'behavior:url(evil.htc)', 'expected' => 'BLOCK', 'desc' => 'IE behavior injection'),
    array('input' => '#fff\\;color:red', 'expected' => 'BLOCK', 'desc' => 'Backslash escape attempt'),
);

$pass_count = 0;
$fail_count = 0;

foreach ($tests as $test) {
    $result = RawWire_Validator::sanitize_css_color($test['input']);
    $actual = ($result === false) ? 'BLOCK' : 'PASS';
    $success = ($actual === $test['expected']);
    
    $status = $success ? '✓' : '✗';
    $color = $success ? "\033[32m" : "\033[31m"; // Green or Red
    $reset = "\033[0m";
    
    if ($success) {
        $pass_count++;
    } else {
        $fail_count++;
    }
    
    printf("%s%s%s %-50s [Expected: %s, Got: %s]\n", 
        $color, $status, $reset,
        substr($test['desc'], 0, 50),
        $test['expected'],
        $actual
    );
    
    if ($test['expected'] === 'PASS') {
        printf("     Input:  %s\n", $test['input']);
        printf("     Output: %s\n\n", $result !== false ? $result : '(blocked)');
    } else {
        printf("     Blocked: %s\n\n", $test['input']);
    }
}

echo "\n╔══════════════════════════════════════════════════════════════╗\n";
echo "║                      RESULTS                                 ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

printf("Total Tests:  %d\n", count($tests));
printf("Passed:       \033[32m%d\033[0m\n", $pass_count);
printf("Failed:       \033[31m%d\033[0m\n\n", $fail_count);

if ($fail_count === 0) {
    echo "\033[32m✓ ALL SECURITY TESTS PASSED\033[0m\n";
    echo "The CSS sanitizer successfully blocked all injection attempts.\n\n";
    exit(0);
} else {
    echo "\033[31m✗ SOME TESTS FAILED\033[0m\n";
    echo "Review the sanitizer implementation.\n\n";
    exit(1);
}
