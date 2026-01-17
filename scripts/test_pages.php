<?php
/**
 * Quick test script for pageDefinitions - simulates admin page load
 */
// Load WordPress
define('WP_ADMIN', true);
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

// Set current user to admin (user ID 1)
wp_set_current_user(1);
echo "Current user can manage_options: " . (current_user_can('manage_options') ? 'YES' : 'NO') . "\n\n";

// Simulate admin_menu hook
do_action('admin_menu');

$t = RawWire_Template_Engine::get_active_template();
if (!$t) {
    echo "No template loaded!\n";
    exit(1);
}

echo "Page Definitions:\n";
echo "=================\n";
foreach ($t['pageDefinitions'] ?? [] as $key => $page) {
    $slug = $page['slug'] ?? 'N/A';
    $enabled = isset($page['enabled']) && $page['enabled'] ? 'true' : 'false';
    echo "  - $key: slug=$slug, enabled=$enabled\n";
}
echo "\n";

// Check registered submenus
global $submenu;
echo "Registered submenus for 'raw-wire-dashboard':\n";
echo "=============================================\n";
if (isset($submenu['raw-wire-dashboard'])) {
    foreach ($submenu['raw-wire-dashboard'] as $item) {
        echo "  - " . $item[0] . " (slug: " . $item[2] . ")\n";
    }
} else {
    echo "  No submenus found!\n";
}
echo "\n";
