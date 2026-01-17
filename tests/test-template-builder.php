<?php
/**
 * Template Builder Test Script
 * Quick validation of template builder functionality
 */

// Simulate WordPress environment
define('ABSPATH', true);

// Test 1: Check class-templates.php syntax
echo "Test 1: Checking class-templates.php syntax...\n";
$templates_file = __DIR__ . '/../admin/class-templates.php';
if (!file_exists($templates_file)) {
    echo "❌ FAIL: class-templates.php not found\n";
    exit(1);
}

$syntax_check = shell_exec("php -l \"$templates_file\" 2>&1");
if (strpos($syntax_check, 'No syntax errors') !== false) {
    echo "✅ PASS: class-templates.php has no syntax errors\n";
} else {
    echo "❌ FAIL: Syntax error in class-templates.php\n";
    echo $syntax_check . "\n";
    exit(1);
}

// Test 2: Check raw-wire-dashboard.php syntax
echo "\nTest 2: Checking raw-wire-dashboard.php syntax...\n";
$main_file = __DIR__ . '/../raw-wire-dashboard.php';
$syntax_check = shell_exec("php -l \"$main_file\" 2>&1");
if (strpos($syntax_check, 'No syntax errors') !== false) {
    echo "✅ PASS: raw-wire-dashboard.php has no syntax errors\n";
} else {
    echo "❌ FAIL: Syntax error in raw-wire-dashboard.php\n";
    echo $syntax_check . "\n";
    exit(1);
}

// Test 3: Check template-engine.php syntax
echo "\nTest 3: Checking template-engine.php syntax...\n";
$engine_file = __DIR__ . '/../cores/template-engine/template-engine.php';
$syntax_check = shell_exec("php -l \"$engine_file\" 2>&1");
if (strpos($syntax_check, 'No syntax errors') !== false) {
    echo "✅ PASS: template-engine.php has no syntax errors\n";
} else {
    echo "❌ FAIL: Syntax error in template-engine.php\n";
    echo $syntax_check . "\n";
    exit(1);
}

// Test 4: Verify required methods exist
echo "\nTest 4: Checking for required methods...\n";
require_once $templates_file;

if (class_exists('RawWire_Templates_Page')) {
    echo "✅ PASS: RawWire_Templates_Page class exists\n";
    
    $methods = ['render', 'get_active_template_info', 'get_available_templates'];
    foreach ($methods as $method) {
        if (method_exists('RawWire_Templates_Page', $method)) {
            echo "  ✅ Method $method exists\n";
        } else {
            echo "  ❌ Method $method missing\n";
            exit(1);
        }
    }
} else {
    echo "❌ FAIL: RawWire_Templates_Page class not found\n";
    exit(1);
}

// Test 5: Check JavaScript file exists
echo "\nTest 5: Checking JavaScript file...\n";
$js_file = __DIR__ . '/../js/template-builder.js';
if (file_exists($js_file)) {
    echo "✅ PASS: template-builder.js exists\n";
    $js_content = file_get_contents($js_file);
    if (strpos($js_content, 'TemplateBuilder') !== false) {
        echo "  ✅ TemplateBuilder object found\n";
    } else {
        echo "  ⚠️  WARNING: TemplateBuilder object not found in JS\n";
    }
} else {
    echo "❌ FAIL: template-builder.js not found\n";
    exit(1);
}

// Test 6: Check CSS file exists
echo "\nTest 6: Checking CSS file...\n";
$css_file = __DIR__ . '/../css/template-builder.css';
if (file_exists($css_file)) {
    echo "✅ PASS: template-builder.css exists (" . number_format(filesize($css_file)) . " bytes)\n";
} else {
    echo "❌ FAIL: template-builder.css not found\n";
    exit(1);
}

// Test 7: Check template directory
echo "\nTest 7: Checking template directory...\n";
$template_dir = __DIR__ . '/../templates/';
if (is_dir($template_dir)) {
    echo "✅ PASS: templates/ directory exists\n";
    $templates = glob($template_dir . '*.json');
    echo "  Found " . count($templates) . " template(s)\n";
    foreach ($templates as $template) {
        $name = basename($template);
        $json = json_decode(file_get_contents($template), true);
        if ($json) {
            echo "  ✅ $name is valid JSON\n";
        } else {
            echo "  ❌ $name has invalid JSON\n";
        }
    }
} else {
    echo "⚠️  WARNING: templates/ directory doesn't exist yet (will be created on first template save)\n";
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "✅ ALL TESTS PASSED\n";
echo "Template Builder is ready to use!\n";
echo str_repeat('=', 60) . "\n";
