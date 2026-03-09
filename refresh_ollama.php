<?php
/**
 * Refresh Ollama Models Cache
 * Run: docker compose exec wordpress php /var/www/html/wp-content/plugins/raw-wire-dashboard/refresh_ollama.php
 */

require_once '/var/www/html/wp-load.php';

echo "=== REFRESHING OLLAMA MODELS ===\n\n";

// Clear existing cache
delete_transient('rawwire_ollama_models');
echo "1. Cleared rawwire_ollama_models transient\n";

// Fetch models from Ollama
$endpoint = 'http://ollama:11434';
echo "2. Fetching models from {$endpoint}/api/tags...\n";

$response = wp_remote_get($endpoint . '/api/tags', ['timeout' => 10]);

if (is_wp_error($response)) {
    echo "   ERROR: " . $response->get_error_message() . "\n";
    exit(1);
}

$body = json_decode(wp_remote_retrieve_body($response), true);
$models = [];

foreach ($body['models'] ?? [] as $model) {
    $models[] = [
        'model' => $model['name'],
        'name' => $model['name'],
        'family' => 'ollama',
        'features' => ['chat'],
        'tags' => ['local', 'free'],
    ];
}

// Cache for 5 minutes
set_transient('rawwire_ollama_models', $models, 300);
echo "3. Cached " . count($models) . " Ollama models:\n";
foreach ($models as $m) {
    echo "   - {$m['model']}\n";
}

echo "\n4. Verifying cache...\n";
$cached = get_transient('rawwire_ollama_models');
echo "   Cached models: " . count($cached) . "\n";

echo "\n=== DONE ===\n";
echo "Now refresh the AI Engine settings page in your browser.\n";
echo "If models still don't show, the issue is in AI Engine's model fetching.\n";
