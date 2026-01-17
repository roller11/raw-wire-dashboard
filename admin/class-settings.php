<?php
if (!defined('ABSPATH')) { exit; }

class RawWire_Settings_Page {
    public function render() {
        echo '<div class="wrap rawwire-dashboard rawwire-settings">';
        echo '<div class="rawwire-hero">';
        echo '<div class="rawwire-hero-content">';
        echo '<span class="eyebrow">' . esc_html__('Configuration', 'raw-wire-dashboard') . '</span>';
        echo '<h1><span class="dashicons dashicons-admin-generic"></span> ' . esc_html__('Raw-Wire Settings', 'raw-wire-dashboard') . '</h1>';
        echo '<p class="lede">' . esc_html__('Configure your automation preferences and integrations.', 'raw-wire-dashboard') . '</p>';
        echo '</div><div class="rawwire-hero-actions"></div></div>';

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

                echo '<div class="panel" id="' . esc_attr($panel_id) . '">';
                echo '<div class="panel-header"><h3>' . esc_html($title) . '</h3></div>';
                echo '<div class="panel-body">';
                if (!empty($desc)) echo '<p class="muted">' . esc_html($desc) . '</p>';
                echo '<div class="panel-body-content" data-module="' . esc_attr($module) . '" data-action="' . esc_attr($action) . '">';
                echo esc_html__('Loading...', 'raw-wire-dashboard');
                echo '</div></div></div>';
            }
        }
        echo '</div>'; // panels

        echo '</div>';
    }
}
