<?php
if (!defined('ABSPATH')) { exit; }

class RawWire_Approvals_Page {
    public function render() {
        echo '<div class="wrap rawwire-dashboard rawwire-approvals">';
        echo '<div class="rawwire-hero">';
        echo '<div class="rawwire-hero-content">';
        echo '<span class="eyebrow">' . esc_html__('Workflow', 'raw-wire-dashboard') . '</span>';
        echo '<h1><span class="dashicons dashicons-yes-alt"></span> ' . esc_html__('Content Approvals', 'raw-wire-dashboard') . '</h1>';
        echo '<p class="lede">' . esc_html__('Review and approve AI-generated content before publishing.', 'raw-wire-dashboard') . '</p>';
        echo '</div><div class="rawwire-hero-actions"></div></div>';

        // Attempt to render module-provided approvals panels
        $panels = array();
        if (class_exists('RawWire_Module_Core')) {
            $mods = RawWire_Module_Core::get_modules();
            if (!empty($mods) && isset($mods['core']) && method_exists($mods['core'], 'get_admin_panels')) {
                $panels = $mods['core']->get_admin_panels();
            }
        }

        // Look for panels marked for approvals (role => 'approvals')
        $found = false;
        echo '<div class="rawwire-panels">';
        foreach ($panels as $key => $p) {
            if (isset($p['role']) && $p['role'] === 'approvals') {
                $found = true;
                $panel_id = isset($p['panel_id']) ? $p['panel_id'] : 'panel-approvals-' . esc_attr($key);
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

        if (! $found) {
            // Minimal approvals UI fallback
            echo '<div class="panel" id="approvals-list-panel">';
            echo '<div class="panel-header"><h3>' . esc_html__('Approvals Queue', 'raw-wire-dashboard') . '</h3></div>';
            echo '<div class="panel-body">';
            echo '<p class="muted">' . esc_html__('Manage content approvals.', 'raw-wire-dashboard') . '</p>';
            echo '<table class="widefat rawwire-approvals-table"><thead><tr><th>Title</th><th>Source</th><th>Created</th><th>Status</th><th>Actions</th></tr></thead><tbody>'; 
            echo '<tr><td colspan="5">' . esc_html__('Loading approvals...', 'raw-wire-dashboard') . '</td></tr>';
            echo '</tbody></table>';
            echo '</div></div>';
        }

        echo '</div>'; // panels

        echo '</div>'; // wrap
    }
}
