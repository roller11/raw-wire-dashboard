<?php
// Test script to load sample module and report registered modules.
// This script stubs minimal WP functions if not present to avoid fatals when including plugin code.

// Provide minimal stubs expected by plugin files
if (!defined('ABSPATH')) {
    define('ABSPATH', rtrim(__DIR__, '/') . '/');
}
if (!function_exists('add_action')) {
    function add_action($a, $b, $c = null) { return true; }
}
if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path($file) { return rtrim(dirname($file), '/') . '/'; }
}
if (!function_exists('get_option')) {
    function get_option($k, $d = null) { return $d; }
}
if (!function_exists('update_option')) {
    function update_option($k, $v) { return true; }
}
if (!function_exists('current_time')) {
    function current_time($f = 'mysql') { return date('c'); }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($v) { return is_scalar($v) ? trim((string)$v) : ''; }
}
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($v) { return is_scalar($v) ? trim((string)$v) : ''; }
}

// Ensure module core is available
require_once __DIR__ . '/../cores/module-core/module-core.php';

// Load sample module
require_once __DIR__ . '/../modules/sample/module.php';

// Inspect registered modules
$mods = RawWire_Module_Core::get_modules();
echo "Registered modules count: " . count($mods) . "\n";
foreach ($mods as $slug => $inst) {
    echo "Module: $slug\n";
    if (method_exists($inst, 'get_metadata')) {
        $meta = $inst->get_metadata();
        echo "  Name: " . ($meta['name'] ?? '(unknown)') . "\n";
    }
    if (method_exists($inst, 'get_admin_panels')) {
        $panels = $inst->get_admin_panels();
        echo "  Panels: " . implode(', ', array_map(function($p){ return $p['panel_id'] ?? ''; }, $panels)) . "\n";
    }
}

// Try to fetch panel via module dispatcher (simulate REST request)
if (isset($mods['sample'])) {
    $resp = $mods['sample']->handle_ajax('panel_settings', array());
    echo "\nSample panel_settings response keys: " . implode(', ', array_keys((array)$resp)) . "\n";
}
