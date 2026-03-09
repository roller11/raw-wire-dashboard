<?php

/**
 * ╔═══════════════════════════════════════════════════════════════════════════╗
 * ║  RAW-WIRE SETTINGS PAGE                                                   ║
 * ║                                                                           ║
 * ║  STOP! This page includes DEVELOPER TOOLS for creating custom panels     ║
 * ║  and tools. These builders enforce architectural compliance:              ║
 * ║                                                                           ║
 * ║  • Custom Panels: Define data source, Module Core handles rendering       ║
 * ║  • Custom Tools: Define functions using safe templates, no raw PHP        ║
 * ║  • Templates: Can only toggle/style, never render                         ║
 * ╚═══════════════════════════════════════════════════════════════════════════╝
 */

if (!defined('ABSPATH')) {
    exit;
}

// Load custom builders
require_once plugin_dir_path(__FILE__) . 'class-custom-panel-builder.php';
require_once plugin_dir_path(__FILE__) . 'class-custom-tool-builder.php';

class RawWire_Settings_Page
{

    public function render()
    {
        $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';

        echo '<div class="wrap rawwire-dashboard rawwire-settings">';
        echo '<div class="rawwire-hero">';
        echo '<div class="rawwire-hero-content">';
        echo '<span class="eyebrow">' . esc_html__('Configuration', 'raw-wire-dashboard') . '</span>';
        echo '<h1><span class="dashicons dashicons-admin-generic"></span> ' . esc_html__('Raw-Wire Settings', 'raw-wire-dashboard') . '</h1>';
        echo '<p class="lede">' . esc_html__('Configure your automation preferences and integrations.', 'raw-wire-dashboard') . '</p>';
        echo '</div><div class="rawwire-hero-actions"></div></div>';

        $this->render_settings_styles();

        // Tab navigation
        $tabs = array(
            'general'   => array('label' => 'General Settings', 'icon' => 'dashicons-admin-settings'),
            'developers' => array('label' => 'Developer Tools', 'icon' => 'dashicons-editor-code'),
        );

        echo '<nav class="nav-tab-wrapper rawwire-tabs" style="margin-bottom: 20px;">';
        foreach ($tabs as $tab_id => $tab) {
            $active = ($current_tab === $tab_id) ? 'nav-tab-active' : '';
            $url = add_query_arg('tab', $tab_id, admin_url('admin.php?page=rawwire-options'));
            echo '<a href="' . esc_url($url) . '" class="nav-tab ' . esc_attr($active) . '">';
            echo '<span class="dashicons ' . esc_attr($tab['icon']) . '" style="margin-right: 5px;"></span>';
            echo esc_html($tab['label']);
            echo '</a>';
        }
        echo '</nav>';

        // Render current tab
        switch ($current_tab) {
            case 'developers':
                $this->render_developers_tab();
                break;
            default:
                $this->render_general_tab();
                break;
        }

        echo '</div>';
    }

    /**
     * Render General Settings Tab
     */
    private function render_general_tab()
    {
        // Fetch module-provided panels (prefer core)
        $panels = array();
        if (class_exists('RawWire_Module_Core')) {
            $mods = RawWire_Module_Core::get_modules();
            if (!empty($mods) && isset($mods['core']) && method_exists($mods['core'], 'get_admin_panels')) {
                $all_panels = $mods['core']->get_admin_panels();
                // Filter for settings panels
                foreach ($all_panels as $key => $panel) {
                    if (isset($panel['role']) && $panel['role'] === 'settings') {
                        $panels[$key] = $panel;
                    }
                }
            }
        }

        echo '<div class="rawwire-panels">';
        if (empty($panels)) {
            echo '<p>' . esc_html__('No settings panels available.', 'raw-wire-dashboard') . '</p>';
        } else {
            foreach ($panels as $key => $p) {
                $panel_id = isset($p['panel_id']) ? $p['panel_id'] : 'panel-settings-' . esc_attr($key);
                $title = isset($p['title']) ? $p['title'] : ucfirst($key);
                $desc = isset($p['description']) ? $p['description'] : '';
                $module = isset($p['module']) ? $p['module'] : '';
                $action = isset($p['action']) ? $p['action'] : '';

                echo '<div class="rawwire-settings-card" id="' . esc_attr($panel_id) . '">';
                echo '<div class="rawwire-settings-card-header"><h3>' . esc_html($title) . '</h3></div>';
                echo '<div class="rawwire-settings-card-body">';
                if (!empty($desc)) echo '<p class="rawwire-settings-muted">' . esc_html($desc) . '</p>';
                echo '<div class="rawwire-settings-card-content" data-module="' . esc_attr($module) . '" data-action="' . esc_attr($action) . '">';
                echo esc_html__('Loading...', 'raw-wire-dashboard');
                echo '</div></div></div>';
            }
        }
        echo '</div>';
    }

    /**
     * ╔═══════════════════════════════════════════════════════════════════════╗
     * ║  DEVELOPER TOOLS TAB                                                  ║
     * ║                                                                       ║
     * ║  STOP! This tab is for DEVELOPERS ONLY.                               ║
     * ║  Custom panels and tools created here follow strict architecture:     ║
     * ║                                                                       ║
     * ║  • Panel Builder: Define panels via UI, Module Core renders them      ║
     * ║  • Tool Builder: Define tools/functions via UI, Toolkit executes      ║
     * ║  • NO arbitrary PHP code execution                                    ║
     * ║  • All rendering stays in Module Core                                 ║
     * ╚═══════════════════════════════════════════════════════════════════════╝
     */
    private function render_developers_tab()
    {
        // Check capability
        if (!current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>You do not have permission to access Developer Tools.</p></div>';
            return;
        }

        $subtab = isset($_GET['subtab']) ? sanitize_key($_GET['subtab']) : 'panels';

        // Architecture documentation banner
        echo '<div class="rawwire-arch-banner">';
        echo '<h3><span class="dashicons dashicons-info-outline"></span> Architecture Guidelines</h3>';
        echo '<div class="rawwire-arch-grid">';

        echo '<div class="rawwire-arch-card">';
        echo '<h4>Custom Panels</h4>';
        echo '<ul>';
        echo '<li>Define <strong>what data</strong> to show</li>';
        echo '<li>Choose from safe content types</li>';
        echo '<li>Module Core handles ALL rendering</li>';
        echo '<li>Templates can only toggle visibility</li>';
        echo '</ul>';
        echo '</div>';

        echo '<div class="rawwire-arch-card">';
        echo '<h4>Custom Tools</h4>';
        echo '<ul>';
        echo '<li>Define <strong>actions</strong> to perform</li>';
        echo '<li>Use pre-defined function types</li>';
        echo '<li>Toolkit layer executes functions</li>';
        echo '<li>No arbitrary PHP code allowed</li>';
        echo '</ul>';
        echo '</div>';

        echo '<div class="rawwire-arch-card rawwire-arch-card-warn">';
        echo '<h4>DO NOT</h4>';
        echo '<ul>';
        echo '<li>Put rendering logic in templates</li>';
        echo '<li>Bypass Module Core</li>';
        echo '<li>Use eval/exec/shell commands</li>';
        echo '<li>Inject raw PHP or JavaScript</li>';
        echo '</ul>';
        echo '</div>';

        echo '</div>';
        echo '</div>';

        // Subtab navigation
        $subtabs = array(
            'panels' => 'Custom Panels',
            'tools'  => 'Custom Tools',
        );

        echo '<ul class="subsubsub" style="margin-bottom: 15px;">';
        $i = 0;
        foreach ($subtabs as $id => $label) {
            $active = ($subtab === $id) ? 'current' : '';
            $url = add_query_arg(array('tab' => 'developers', 'subtab' => $id), admin_url('admin.php?page=rawwire-options'));
            echo '<li><a href="' . esc_url($url) . '" class="' . esc_attr($active) . '">' . esc_html($label) . '</a>';
            if (++$i < count($subtabs)) echo ' | ';
            echo '</li>';
        }
        echo '</ul>';
        echo '<div style="clear: both;"></div>';

        // Render subtab content
        echo '<div class="rawwire-developer-content" style="margin-top: 20px;">';
        switch ($subtab) {
            case 'tools':
                echo RawWire_Custom_Tool_Builder::render_builder_ui();
                break;
            default:
                echo RawWire_Custom_Panel_Builder::render_builder_ui();
                break;
        }
        echo '</div>';
    }

    /**
     * Render scoped styles for the settings page
     */
    private function render_settings_styles()
    {
?>
        <style>
            /* General tab - settings cards */
            .rawwire-settings-card {
                background: var(--rw-bg-surface, #18191c);
                border: 1px solid var(--rw-border-default, #2a2a2e);
                border-radius: 8px;
                margin-bottom: 16px;
                overflow: hidden;
            }

            .rawwire-settings-card-header {
                padding: 16px 20px 0;
            }

            .rawwire-settings-card-header h3 {
                margin: 0;
                color: var(--rw-fg-default, #e4e4e7);
            }

            .rawwire-settings-card-body {
                padding: 12px 20px 20px;
            }

            .rawwire-settings-muted {
                color: var(--rw-fg-muted, #9ca3af);
                margin-top: 4px;
            }

            .rawwire-settings-card-content {
                color: var(--rw-fg-default, #e4e4e7);
            }

            /* Developer tab - architecture banner */
            .rawwire-arch-banner {
                background: var(--rw-bg-surface, #18191c);
                border: 1px solid var(--rw-border-default, #2a2a2e);
                border-left: 4px solid var(--rw-brand-gold, #f4b41a);
                padding: 20px;
                margin-bottom: 25px;
                border-radius: 8px;
            }

            .rawwire-arch-banner h3 {
                margin-top: 0;
                color: var(--rw-brand-gold, #f4b41a);
            }

            .rawwire-arch-grid {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 20px;
            }

            .rawwire-arch-card {
                background: var(--rw-bg-elevated, #1f2023);
                padding: 15px;
                border-radius: 6px;
                border: 1px solid var(--rw-border-default, #2a2a2e);
            }

            .rawwire-arch-card h4 {
                margin-top: 0;
                color: var(--rw-fg-default, #e4e4e7);
            }

            .rawwire-arch-card ul {
                margin-bottom: 0;
                padding-left: 20px;
                color: var(--rw-fg-muted, #9ca3af);
            }

            .rawwire-arch-card li {
                color: var(--rw-fg-muted, #9ca3af);
            }

            .rawwire-arch-card strong {
                color: var(--rw-fg-default, #e4e4e7);
            }

            .rawwire-arch-card-warn ul,
            .rawwire-arch-card-warn li {
                color: #ef4444;
            }

            /* Developer tab content area */
            .rawwire-developer-content {
                color: var(--rw-fg-default, #e4e4e7);
            }

            /* Light-mode overrides */
            [data-theme="light"] .rawwire-settings-card,
            .rawwire-dashboard:not([data-theme="dark"]) .rawwire-settings-card {
                background: #fff;
                border-color: #ddd;
            }

            [data-theme="light"] .rawwire-settings-card-header h3,
            .rawwire-dashboard:not([data-theme="dark"]) .rawwire-settings-card-header h3 {
                color: #1d2327;
            }

            [data-theme="light"] .rawwire-settings-muted,
            .rawwire-dashboard:not([data-theme="dark"]) .rawwire-settings-muted {
                color: #666;
            }

            [data-theme="light"] .rawwire-arch-banner,
            .rawwire-dashboard:not([data-theme="dark"]) .rawwire-arch-banner {
                background: #fefcf3;
                border-color: #e5d9b8;
            }

            [data-theme="light"] .rawwire-arch-card,
            .rawwire-dashboard:not([data-theme="dark"]) .rawwire-arch-card {
                background: #fff;
                border-color: #ddd;
            }

            [data-theme="light"] .rawwire-arch-card h4,
            .rawwire-dashboard:not([data-theme="dark"]) .rawwire-arch-card h4 {
                color: #1d2327;
            }

            [data-theme="light"] .rawwire-arch-card ul,
            [data-theme="light"] .rawwire-arch-card li,
            .rawwire-dashboard:not([data-theme="dark"]) .rawwire-arch-card ul,
            .rawwire-dashboard:not([data-theme="dark"]) .rawwire-arch-card li {
                color: #555;
            }
        </style>
<?php
    }
}
