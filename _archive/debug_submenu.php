<?php
require_once '/var/www/html/wp-load.php';

// Dump submenu for raw-wire-dashboard
global $submenu;
// Impersonate an admin user when running via CLI so capability checks pass
if (function_exists('wp_set_current_user')) {
    // Try user ID 1 (typical first admin); if not present this will be harmless
    wp_set_current_user(1);
}
// Run admin hooks to populate menu structures when invoked via CLI
do_action('admin_init');
do_action('admin_menu');
echo "==SUBMENU DEBUG==\n";
if (isset($submenu['raw-wire-dashboard'])) {
    foreach ($submenu['raw-wire-dashboard'] as $item) {
        // item: [0]=title, [1]=cap, [2]=menu_slug, [3]=page]
        $title = isset($item[0]) ? $item[0] : '';
        $cap = isset($item[1]) ? $item[1] : '';
        $slug = isset($item[2]) ? $item[2] : '';
        echo "- Title: $title | Cap: $cap | Slug: $slug\n";
    }
} else {
    echo "NO_SUBMENU\n";
}

// Current user info
$current_user = wp_get_current_user();
echo "==CURRENT USER==\n";
echo "ID: " . intval($current_user->ID) . "\n";
echo "user_login: " . $current_user->user_login . "\n";
echo "roles: " . implode(',', $current_user->roles) . "\n";
echo "current_user_can(manage_options): " . (current_user_can('manage_options') ? 'YES' : 'NO') . "\n";

// Active template info
if (class_exists('RawWire_Template_Engine')) {
    $t = RawWire_Template_Engine::get_active_template();
    echo "==ACTIVE TEMPLATE==\n";
    echo isset($t['meta']['id']) ? $t['meta']['id'] . "\n" : "(none)\n";
} else {
    echo "Template engine not loaded\n";
}

?>