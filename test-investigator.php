<?php

/**
 * Quick test script for Party Investigator
 * Access at: http://localhost/wordpress/wp-content/plugins/raw-wire-dashboard/test-investigator.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Bootstrap WordPress
$wp_load_paths = [
    dirname(__FILE__) . '/../../../wp-load.php',
    '/var/www/html/wordpress/wp-load.php',
];

foreach ($wp_load_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        break;
    }
}

if (!defined('ABSPATH')) {
    die('WordPress not loaded');
}

header('Content-Type: application/json');

// Get action
$action = $_GET['action'] ?? 'settings';

switch ($action) {
    case 'settings':
        // Show current settings
        $settings = get_option('rawwire_party_investigator_settings', []);
        $ai_settings = get_option('rawwire_ai_adapter_settings', []);
        $mwai_chatbots = get_option('mwai_chatbots', []);

        echo json_encode([
            'party_investigator' => $settings,
            'ai_adapter' => $ai_settings,
            'chatbots' => $mwai_chatbots,
        ], JSON_PRETTY_PRINT);
        break;

    case 'reset':
        // Reset investigation status for a source
        $source_id = (int)($_GET['id'] ?? 38);
        global $wpdb;
        $wpdb->update(
            "{$wpdb->prefix}rawwire_lead_sources",
            ['parties_investigated_at' => null],
            ['id' => $source_id]
        );
        echo json_encode(['reset' => true, 'source_id' => $source_id]);
        break;

    case 'investigate':
        // Run investigation on Swinerton (ID 38)
        $source_id = (int)($_GET['id'] ?? 38);
        echo "DEBUG: Starting investigation for source_id=$source_id\n";
        flush();

        global $wpdb;
        $source = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rawwire_lead_sources WHERE id = %d",
            $source_id
        ), ARRAY_A);

        echo "DEBUG: Source query done\n";
        flush();

        if (!$source) {
            echo json_encode(['error' => 'Source not found']);
            exit;
        }

        echo "DEBUG: Found source: " . $source['contractor_name'] . "\n";
        flush();

        if (!class_exists('RawWire_Party_Investigator')) {
            echo json_encode(['error' => 'Party Investigator not loaded']);
            exit;
        }

        echo "DEBUG: Class exists, getting instance\n";
        flush();

        $investigator = RawWire_Party_Investigator::get_instance();

        echo "DEBUG: Got instance, calling investigate_source\n";
        flush();

        // Force re-investigation
        $force = isset($_GET['force']);
        try {
            $result = $investigator->investigate_source_parties($source_id, $force);
        } catch (Throwable $e) {
            echo json_encode(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            exit;
        }

        echo "DEBUG: Investigation complete\n";
        flush();

        echo json_encode([
            'source' => [
                'id' => $source['id'],
                'contractor_name' => $source['contractor_name'],
            ],
            'result' => $result,
        ], JSON_PRETTY_PRINT);
        break;

    case 'find_swinerton':
        // Find Swinerton record
        global $wpdb;
        $results = $wpdb->get_results(
            "SELECT id, contractor_name, parties_investigated_at 
             FROM {$wpdb->prefix}rawwire_lead_sources 
             WHERE contractor_name LIKE '%Swinerton%'
             LIMIT 5",
            ARRAY_A
        );
        echo json_encode($results, JSON_PRETTY_PRINT);
        break;

    default:
        echo json_encode(['error' => 'Unknown action', 'actions' => ['settings', 'investigate', 'find_swinerton']]);
}
