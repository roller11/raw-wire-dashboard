<?php
$file = '/var/www/html/wp-content/plugins/raw-wire-dashboard/templates/news-aggregator.template.json';
$content = file_get_contents($file);
$data = json_decode($content, true);

if (json_last_error() === JSON_ERROR_NONE) {
    echo "✓ JSON is VALID\n";
    echo "Template name: " . ($data['templateName'] ?? 'Unknown') . "\n";
    echo "Number of panels: " . count($data['panels'] ?? []) . "\n";
} else {
    echo "✗ JSON ERROR: " . json_last_error_msg() . "\n";
}
