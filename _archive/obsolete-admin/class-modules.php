<?php
if (!defined('ABSPATH')) { exit; }

class RawWire_Modules_Page {
    public function render() {
        // Handle module activation/deactivation
        if (isset($_POST['rawwire_module_action']) && wp_verify_nonce($_POST['rawwire_module_nonce'], 'rawwire_module_action')) {
            $this->handle_module_action();
        }

        $active_module = get_option('rawwire_active_module', '');
        $modules = $this->get_available_modules();

        echo '<div class="wrap rawwire-modules">';
        echo '<h1>' . esc_html__('Raw-Wire Modules', 'raw-wire-dashboard') . '</h1>';

        echo '<div class="rawwire-module-manager">';

        // Active Module Status
        echo '<div class="module-status-card">';
        echo '<h3>' . esc_html__('Active Module', 'raw-wire-dashboard') . '</h3>';
        if (!empty($active_module)) {
            echo '<p class="active-module">' . esc_html__('Currently Active:', 'raw-wire-dashboard') . ' <strong>' . esc_html($active_module) . '</strong></p>';
            echo '<form method="post" style="display: inline;">';
            wp_nonce_field('rawwire_module_action', 'rawwire_module_nonce');
            echo '<input type="hidden" name="rawwire_module_action" value="deactivate">';
            echo '<input type="hidden" name="module_slug" value="' . esc_attr($active_module) . '">';
            echo '<button type="submit" class="button button-secondary">' . esc_html__('Deactivate', 'raw-wire-dashboard') . '</button>';
            echo '</form>';
        } else {
            echo '<p class="no-active-module">' . esc_html__('No module is currently active.', 'raw-wire-dashboard') . '</p>';
        }
        echo '</div>';

        // Available Modules
        echo '<div class="available-modules">';
        echo '<h3>' . esc_html__('Available Modules', 'raw-wire-dashboard') . '</h3>';

        if (empty($modules)) {
            echo '<p>' . esc_html__('No modules found.', 'raw-wire-dashboard') . '</p>';
        } else {
            echo '<div class="modules-grid">';
            foreach ($modules as $module_slug => $module_info) {
                $is_active = ($active_module === $module_slug);
                $has_requirements = !empty($module_info['requirements']);

                echo '<div class="module-card ' . ($is_active ? 'active' : '') . '">';
                echo '<div class="module-header">';
                echo '<h4>' . esc_html($module_info['name']) . '</h4>';
                if ($is_active) {
                    echo '<span class="module-status active">' . esc_html__('Active', 'raw-wire-dashboard') . '</span>';
                }
                echo '</div>';

                echo '<div class="module-meta">';
                echo '<p class="module-description">' . esc_html($module_info['description']) . '</p>';
                echo '<p class="module-version">v' . esc_html($module_info['version']) . '</p>';
                echo '</div>';

                if ($has_requirements) {
                    echo '<div class="module-requirements">';
                    echo '<h5>' . esc_html__('Toolkit Requirements', 'raw-wire-dashboard') . '</h5>';
                    echo '<ul>';
                    foreach ($module_info['requirements'] as $req) {
                        echo '<li>' . esc_html($req) . '</li>';
                    }
                    echo '</ul>';
                    echo '</div>';
                }

                echo '<div class="module-actions">';
                if ($is_active) {
                    // Show toolkit configuration for active module only if it has requirements
                    if (!empty($module_info['requirements'])) {
                        echo '<button type="button" class="button button-primary configure-toolkit" data-module="' . esc_attr($module_slug) . '">' . esc_html__('Configure Toolkit', 'raw-wire-dashboard') . '</button>';
                    } else {
                        echo '<span class="module-no-config">' . esc_html__('No toolkit configuration needed', 'raw-wire-dashboard') . '</span>';
                    }
                } else {
                    echo '<form method="post" style="display: inline;">';
                    wp_nonce_field('rawwire_module_action', 'rawwire_module_nonce');
                    echo '<input type="hidden" name="rawwire_module_action" value="activate">';
                    echo '<input type="hidden" name="module_slug" value="' . esc_attr($module_slug) . '">';
                    echo '<button type="submit" class="button button-primary">' . esc_html__('Activate', 'raw-wire-dashboard') . '</button>';
                    echo '</form>';
                }
                echo '</div>';

                echo '</div>'; // module-card
            }
            echo '</div>'; // modules-grid
        }

        echo '</div>'; // available-modules
        echo '</div>'; // rawwire-module-manager

        // Toolkit Configuration Modal
        echo '<div id="toolkit-modal" class="rawwire-modal" style="display: none;">';
        echo '<div class="modal-content">';
        echo '<div class="modal-header">';
        echo '<h3>' . esc_html__('Toolkit Configuration', 'raw-wire-dashboard') . '</h3>';
        echo '<button type="button" class="modal-close">&times;</button>';
        echo '</div>';
        echo '<div class="modal-body" id="toolkit-config-content">';
        echo '<p>' . esc_html__('Loading configuration...', 'raw-wire-dashboard') . '</p>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        echo '</div>'; // wrap
    }

    private function get_available_modules() {
        $modules = array();

        // Get modules that have been discovered and registered by module-core
        if (class_exists('RawWire_Module_Core')) {
            $registered_modules = RawWire_Module_Core::get_modules();
            foreach ($registered_modules as $slug => $module_instance) {
                if (method_exists($module_instance, 'get_metadata')) {
                    $meta = $module_instance->get_metadata();
                    $modules[$slug] = array(
                        'name' => $meta['name'] ?? ucfirst(str_replace('-', ' ', $slug)),
                        'description' => $meta['description'] ?? 'Module description not available',
                        'version' => $meta['version'] ?? '1.0.0',
                        'requirements' => array()
                    );

                    // Try to get requirements from module.json if it exists
                    $module_dir = plugin_dir_path(dirname(__FILE__)) . 'modules/' . $slug;
                    $module_json = $module_dir . '/module.json';
                    if (file_exists($module_json)) {
                        $config = json_decode(file_get_contents($module_json), true);
                        if ($config && isset($config['requirements'])) {
                            $modules[$slug]['requirements'] = $this->extract_requirements($config);
                        }
                    }
                }
            }
        }

        return $modules;
    }

    private function extract_requirements($config) {
        $requirements = array();

        if (isset($config['requirements']) && is_array($config['requirements'])) {
            foreach ($config['requirements'] as $key => $req) {
                $label = isset($req['label']) ? $req['label'] : ucfirst(str_replace('_', ' ', $key));
                $type = isset($req['type']) ? $req['type'] : 'unknown';
                $requirements[] = $label . ' (' . $type . ')';
            }
        }

        return $requirements;
    }

    private function handle_module_action() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $action = sanitize_text_field($_POST['rawwire_module_action']);
        $module_slug = sanitize_key($_POST['module_slug']);

        if ($action === 'activate') {
            update_option('rawwire_active_module', $module_slug);
            add_settings_error('rawwire_modules', 'module_activated', sprintf(__('Module "%s" activated successfully.', 'raw-wire-dashboard'), $module_slug), 'updated');
        } elseif ($action === 'deactivate') {
            delete_option('rawwire_active_module');
            add_settings_error('rawwire_modules', 'module_deactivated', sprintf(__('Module "%s" deactivated successfully.', 'raw-wire-dashboard'), $module_slug), 'updated');
        }
    }
}