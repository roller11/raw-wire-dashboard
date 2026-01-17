<?php
/**
 * Test template engine loading
 */
require_once '/var/www/html/wp-load.php';

echo "Testing Template Engine...\n";
echo "==========================\n\n";

// Force load the template
RawWire_Template_Engine::load_active_template();

// Get the template using the static method
$template = RawWire_Template_Engine::get_template();

if ($template) {
    echo "✓ Template loaded successfully!\n\n";
    echo "Template Details:\n";
    echo "  Name: " . ($template['templateName'] ?? 'Unknown') . "\n";
    echo "  Version: " . ($template['version'] ?? 'Unknown') . "\n";
    echo "  Panels: " . count($template['panels'] ?? []) . "\n";
    echo "  Features: " . count($template['features'] ?? []) . "\n";
    echo "  Pages: " . count($template['pageDefinitions'] ?? []) . "\n";
    echo "\nPanel IDs:\n";
    foreach (array_keys($template['panels'] ?? []) as $panel_id) {
        echo "  - $panel_id\n";
    }
} else {
    echo "✗ No template loaded!\n";
    echo "\nThis could mean:\n";
    echo "  - Template file not found\n";
    echo "  - JSON syntax error\n";
    echo "  - Template engine not initialized\n";
}
