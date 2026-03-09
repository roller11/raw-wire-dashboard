<?php

/**
 * Create WP All Import configuration for LA Building Permits
 * 
 * Run via WP-CLI: wp eval-file scripts/create-wpai-import.php
 * Or via browser: Load as include from admin
 */

if (!defined('ABSPATH')) {
    // Allow running from WP-CLI
    if (php_sapi_name() === 'cli' && isset($_SERVER['argv'])) {
        // Find wp-load.php
        $wp_load_candidates = [
            '/var/www/html/wordpress/wp-load.php',
            dirname(__DIR__, 4) . '/wp-load.php',
        ];
        foreach ($wp_load_candidates as $path) {
            if (file_exists($path)) {
                require_once $path;
                break;
            }
        }
    }
    if (!defined('ABSPATH')) {
        die("WordPress not loaded.\n");
    }
}

global $wpdb;

// Source URL - LA Building Permits, valuation >= $100K, last 3 months, limit 500
// Note: valuation is stored as text, so we need ::number cast for numeric comparison
$source_url = 'https://data.lacity.org/resource/pi9x-tg5x.json?' . http_build_query([
    '$where' => "valuation::number >= 100000 AND issue_date >= '" . date('Y-m-d', strtotime('-3 months')) . "'",
    '$limit' => 500,
    '$order' => 'issue_date DESC',
]);

// WP All Import options - field mappings
$options = [
    // Import type
    'type' => 'url',
    'custom_type' => 'rw_lead',
    'taxonomy_type' => '',
    'wizard_type' => 'new',

    // Feed settings
    'feed_type' => 'xml',
    'encoding' => 'UTF-8',
    'delimiter' => ',',
    'root_element' => 'row',

    // Post settings
    'title' => '{permit_nbr[1]} - {primary_address[1]}',
    'content' => '{work_desc[1]}',
    'post_status' => 'publish',
    'post_author' => get_current_user_id() ?: 1,
    'featured_image' => '',
    'categories' => '',
    'tags' => '',

    // Duplicate detection
    'duplicate_checking' => 'auto',
    'duplicate_indicator' => 'custom_field',
    'duplicate_indicator_custom_field' => 'permit_number',
    'is_keep_former_posts' => 'yes',
    'is_update_missing_cf' => 0,
    'is_keep_status' => 0,
    'is_keep_title' => 0,
    'is_keep_author' => 1,
    'is_keep_excerpt' => 0,
    'is_keep_dates' => 0,
    'is_keep_content' => 0,
    'is_keep_categories' => 0,
    'is_keep_attachments' => 0,
    'is_keep_images' => 1,

    // Update settings
    'update_all_data' => 'no',
    'is_update_status' => 0,
    'is_update_title' => 0,
    'is_update_author' => 0,
    'is_update_slug' => 0,
    'is_update_content' => 0,
    'is_update_dates' => 0,
    'is_update_excerpt' => 0,
    'is_update_categories' => 0,
    'is_update_attachments' => 0,
    'is_update_images' => 0,
    'is_update_custom_fields' => 1,
    'update_custom_fields_logic' => 'only',

    // Speed settings
    'is_fast_mode' => 1,
    'chuncking' => 1,
    'records_per_request' => 20,
    'create_chunks' => 0,

    // Custom field mappings (ACF)
    'custom_fields' => [
        // === PERMIT DETAILS ===
        ['name' => 'permit_number', 'value' => '{permit_nbr[1]}'],
        ['name' => 'permit_type', 'value' => '{permit_type[1]}'],
        ['name' => 'permit_sub_type', 'value' => '{permit_sub_type[1]}'],
        ['name' => 'permit_status', 'value' => '{status_desc[1]}'],
        ['name' => 'issue_date', 'value' => '{issue_date[1]}'],
        ['name' => 'valuation', 'value' => '{valuation[1]}'],
        ['name' => 'work_description', 'value' => '{work_desc[1]}'],
        ['name' => 'use_description', 'value' => '{use_desc[1]}'],

        // === PROPERTY DETAILS ===
        ['name' => 'address', 'value' => '{primary_address[1]}'],
        ['name' => 'city', 'value' => 'Los Angeles'],
        ['name' => 'zip_code', 'value' => '{zip_code[1]}'],
        ['name' => 'council_district', 'value' => '{cd[1]}'],
        ['name' => 'latitude', 'value' => '{lat[1]}'],
        ['name' => 'longitude', 'value' => '{lon[1]}'],

        // === LEAD STATUS ===
        ['name' => 'lead_status', 'value' => 'new'],
        ['name' => 'lead_score', 'value' => '0'],

        // === IMPORT METADATA ===
        ['name' => 'import_source', 'value' => 'socrata_la_building_permits'],
        ['name' => 'socrata_record_id', 'value' => '{permit_nbr[1]}'],
        ['name' => 'imported_at', 'value' => date('Y-m-d H:i:s')],
        ['name' => 'import_batch_id', 'value' => '[import_id]'],

        // === OWNER (from Socrata - typically blank, filled by investigation) ===
        ['name' => 'owner_name', 'value' => ''],
        ['name' => 'owner_company', 'value' => ''],
        ['name' => 'owner_phone', 'value' => ''],
        ['name' => 'owner_email', 'value' => ''],
        ['name' => 'owner_address', 'value' => ''],
        ['name' => 'owner_ai_investigation', 'value' => ''],

        // === MANAGER (for investigation) ===
        ['name' => 'manager_name', 'value' => ''],
        ['name' => 'manager_company', 'value' => ''],
        ['name' => 'manager_phone', 'value' => ''],
        ['name' => 'manager_email', 'value' => ''],
        ['name' => 'manager_address', 'value' => ''],
        ['name' => 'manager_ai_investigation', 'value' => ''],

        // === CONTRACTOR (from Socrata if available) ===
        ['name' => 'contractor_name', 'value' => ''],
        ['name' => 'contractor_company', 'value' => ''],
        ['name' => 'contractor_license', 'value' => ''],
        ['name' => 'contractor_phone', 'value' => ''],
        ['name' => 'contractor_email', 'value' => ''],
        ['name' => 'contractor_address', 'value' => ''],
        ['name' => 'contractor_ai_investigation', 'value' => ''],

        // === RAW DATA ===
        ['name' => 'raw_json', 'value' => '{.}'],
    ],

    // Fields to update on re-import (exclude investigation fields)
    'custom_fields_list' => [
        'permit_number',
        'permit_type',
        'permit_sub_type',
        'permit_status',
        'issue_date',
        'valuation',
        'work_description',
        'use_description',
        'address',
        'zip_code',
        'council_district',
        'latitude',
        'longitude',
    ],
];

// Serialize options
$options_serialized = maybe_serialize($options);

// Insert into wp_pmxi_imports
$table = $wpdb->prefix . 'pmxi_imports';

$import_data = [
    'parent_import_id' => 0,
    'name' => 'LA Building Permits > $100K (Auto-configured)',
    'friendly_name' => 'LA Building Permits > $100K (Auto-configured)',
    'type' => 'url',
    'feed_type' => 'xml',
    'path' => $source_url,
    'xpath' => '/root/row',
    'options' => $options_serialized,
    'registered_on' => current_time('mysql'),
    'root_element' => 'row',
    'processing' => 0,
    'executing' => 0,
    'triggered' => 0,
    'queue_chunk_number' => 0,
    'count' => 0,
    'imported' => 0,
    'created' => 0,
    'updated' => 0,
    'skipped' => 0,
    'deleted' => 0,
    'changed_missing' => 0,
    'canceled' => 0,
    'failed' => 0,
    'iteration' => 0,
    'is_preview' => 0,
];

$result = $wpdb->insert($table, $import_data);

if ($result === false) {
    echo "ERROR: Failed to create import - " . $wpdb->last_error . "\n";
    exit(1);
}

$import_id = $wpdb->insert_id;
$edit_url = admin_url('admin.php?page=pmxi-admin-manage&id=' . $import_id . '&action=edit');

echo "SUCCESS: Created import ID {$import_id}\n";
echo "Source URL: {$source_url}\n";
echo "Edit URL: {$edit_url}\n";
echo "\nField mappings configured:\n";
echo "- Permit details (number, type, sub_type, status, issue_date, valuation, work_desc)\n";
echo "- Property details (address, city, zip, council_district, lat/lon)\n";
echo "- Lead status (new, score 0)\n";
echo "- Owner investigation fields (name, company, phone, email, address, ai_investigation)\n";
echo "- Manager investigation fields (name, company, phone, email, address, ai_investigation)\n";
echo "- Contractor investigation fields (name, company, license, phone, email, address, ai_investigation)\n";
echo "- Import metadata (source, batch_id, imported_at, raw_json)\n";
