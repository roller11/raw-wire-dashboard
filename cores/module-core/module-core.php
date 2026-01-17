<?php
/**
 * Module Core - module loading and validation
 * Path: cores/module-core/module-core.php
 * 
 * Provides template-driven module system where all behavior
 * is defined in module.json templates. The code remains static.
 * 
 * Supports:
 * - Module discovery and registration
 * - Template configuration loading
 * - Toolkit requirements (scraper, workflow, generator, poster)
 * - Dynamic settings UI generation
 */
if (!class_exists('RawWire_Module_Core')) {
    class RawWire_Module_Core {
        /**
         * Active module configuration
         * @var array|null
         */
        protected static $active_module = null;

        /**
         * Registered module instances
         * @var array<string, RawWire_Module_Interface>
         */
        protected static $modules = array();

        /**
         * In-memory template config cache
         * @var array|null
         */
        protected static $template_config = null;

        /**
         * Logger instance
         * @var RawWire_Logger|null
         */
        protected static $logger = null;

        /**
         * Initialize Module Core
         */
        public static function init() {
            // Initialize logger
            if (class_exists('RawWire_Logger')) {
                self::$logger = new RawWire_Logger();
            }

            add_action('init', array(__CLASS__, 'load_active_module'));
            // Discover modules early so they can register REST/AJAX
            add_action('init', array(__CLASS__, 'discover_modules'), 5);

            // Register AJAX handlers for module toolkit settings
            add_action('wp_ajax_rawwire_module_toolkit_save', array(__CLASS__, 'ajax_save_toolkit_settings'));
            add_action('wp_ajax_rawwire_module_requirements', array(__CLASS__, 'ajax_get_requirements'));
            add_action('wp_ajax_rawwire_module_toolkit_load', array(__CLASS__, 'ajax_load_toolkit_config'));
            add_action('wp_ajax_rawwire_module_toolkit_form', array(__CLASS__, 'ajax_get_adapter_form'));

            // Register REST routes
            add_action('rest_api_init', array(__CLASS__, 'register_rest_routes'));
        }

        /**
         * Log a message safely
         */
        protected static function log(string $message, string $level = 'info', array $context = array()) {
            if (self::$logger) {
                $context['component'] = 'module-core';
                if ($level === 'error') {
                    self::$logger->log_error($message, $context, 'error');
                } elseif ($level === 'warning') {
                    self::$logger->log_error($message, $context, 'warning');
                } else {
                    self::$logger->log($message, $level, $context);
                }
            }
        }

        /**
         * Load active module metadata
         */
        public static function load_active_module() {
            $module_name = get_option('rawwire_active_module', '');
            if (empty($module_name)) {
                return false;
            }

            $module_json = plugin_dir_path(__FILE__) . "../modules/{$module_name}/module.json";
            if (!file_exists($module_json)) {
                self::log('Module JSON not found', 'warning', array('module' => $module_name));
                return false;
            }

            $json = file_get_contents($module_json);
            $config = json_decode($json, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                self::log('Failed to parse module JSON', 'error', array(
                    'module' => $module_name,
                    'error' => json_last_error_msg(),
                ));
                return false;
            }

            if (is_array($config) && self::validate_module($config)) {
                self::$active_module = $config;
                self::log('Active module loaded', 'debug', array('module' => $module_name));
                return true;
            }

            return false;
        }

        /**
         * Discover modules by scanning the `modules/` directory and requiring their bootstrap files.
         * Modules are expected to register themselves by calling `RawWire_Module_Core::register_module()`.
         */
        public static function discover_modules() {
            // Use absolute path from plugin root
            $plugin_dir = dirname(dirname(dirname(__FILE__)));
            $modules_dir = $plugin_dir . '/modules';
            if (!is_dir($modules_dir)) {
                self::log('Modules directory not found', 'error', array('path' => $modules_dir));
                return;
            }

            $items = glob(rtrim($modules_dir, '/') . '/*/module.php');
            if (!$items || empty($items)) {
                self::log('No module.php files found', 'warning', array('pattern' => rtrim($modules_dir, '/') . '/*/module.php'));
                return;
            }

            self::log('Found module files', 'info', array('count' => count($items), 'files' => $items));

            foreach ($items as $file) {
                // Require module bootstrap - module should register itself
                try {
                    require_once $file;
                } catch (Exception $e) {
                    // swallow; module load failure should not break admin
                }
            }
        }

        /**
         * Register a module instance so the core can delegate calls.
         * @param string $slug
         * @param RawWire_Module_Interface $instance
         */
        public static function register_module($slug, $instance) {
            if (! is_string($slug) || empty($slug)) {
                return false;
            }
            self::$modules[$slug] = $instance;
            // allow modules to initialize themselves
            if (method_exists($instance, 'init')) {
                try { $instance->init(); } catch (Exception $e) {}
            }
            // Allow module to register REST routes - hook to rest_api_init
            if (method_exists($instance, 'register_rest_routes')) {
                add_action('rest_api_init', function() use ($instance) {
                    try { $instance->register_rest_routes(); } catch (Exception $e) {}
                });
            }
            if (method_exists($instance, 'register_ajax_handlers')) {
                try { $instance->register_ajax_handlers(); } catch (Exception $e) {}
            }
            return true;
        }

        /**
         * Get all registered modules
         * @return array<string, RawWire_Module_Interface>
         */
        public static function get_modules() {
            return self::$modules;
        }

        public static function get_active_module() {
            return self::$active_module;
        }

        /**
         * Get the template/module UI config used by the dashboard.
         *
         * NOW: Always loads from template file (news-aggregator.template.json).
         * Template is the source of truth for all features, sources, pages, and config.
         * Module is only a thin interpreter that displays template-driven options.
         *
         * @return array
         */
        public static function get_template_config() {
            if (is_array(self::$template_config)) {
                return self::$template_config;
            }

            // Load active template from Template Engine
            if (class_exists('RawWire_Template_Engine')) {
                $template = RawWire_Template_Engine::get_active_template();
                if (is_array($template) && !empty($template)) {
                    self::$template_config = $template;
                    return self::$template_config;
                }
            }

            // Fallback if template engine not available
            $fallback = self::get_fallback_template_config();
            self::$template_config = $fallback;
            return self::$template_config;
        }

        protected static function load_legacy_template_file() {
            // Legacy method - no longer used, template engine handles loading
            return null;
        }

        protected static function get_fallback_template_config() {
            // Minimal fallback - template should always be available
            return array(
                'name' => 'fallback',
                'theme' => array(
                    'accent' => '#0d9488',
                    'accentBold' => '#0f766e',
                    'surface' => '#0b1724',
                    'card' => '#0f1f33',
                    'muted' => '#8aa0b7',
                ),
                'columns' => array(),
                'badges' => array(),
                'filters' => array(
                    'sources' => array(),
                    'categories' => array(),
                    'statuses' => array('pending', 'approved', 'rejected'),
                ),
                'features' => array(),
                'sourceTypes' => array(),
                'pageDefinitions' => array(),
            );
        }

        /**
         * Get module toolkit requirements from template
         * 
         * @param string|null $module_slug
         * @return array
         */
        public static function get_requirements(string $module_slug = null) {
            if ($module_slug) {
                $module_json = plugin_dir_path(__FILE__) . "../modules/{$module_slug}/module.json";
                if (file_exists($module_json)) {
                    $config = json_decode(file_get_contents($module_json), true);
                    return $config['requirements'] ?? array();
                }
            }

            if (is_array(self::$active_module)) {
                return self::$active_module['requirements'] ?? array();
            }

            return array();
        }

        /**
         * Get default adapter for a requirement type
         * 
         * @param string|null $module_slug
         * @return array
         */
        public static function get_defaults(string $module_slug = null) {
            if ($module_slug) {
                $module_json = plugin_dir_path(__FILE__) . "../modules/{$module_slug}/module.json";
                if (file_exists($module_json)) {
                    $config = json_decode(file_get_contents($module_json), true);
                    return $config['defaults'] ?? array();
                }
            }

            if (is_array(self::$active_module)) {
                return self::$active_module['defaults'] ?? array();
            }

            return array();
        }

        /**
         * Render toolkit requirements settings form
         * 
         * @param string|null $module_slug
         * @return string HTML
         */
        public static function render_toolkit_settings(string $module_slug = null) {
            // Ensure Toolbox Core is loaded
            if (!class_exists('RawWire_Toolbox_Core')) {
                $toolbox_path = plugin_dir_path(__FILE__) . '../toolbox-core/toolbox-core.php';
                if (file_exists($toolbox_path)) {
                    require_once $toolbox_path;
                } else {
                    return '<div class="notice notice-error"><p>Toolbox Core not found.</p></div>';
                }
            }

            $requirements = self::get_requirements($module_slug);
            $defaults = self::get_defaults($module_slug);

            if (empty($requirements)) {
                return '<div class="notice notice-info"><p>This module has no toolkit requirements.</p></div>';
            }

            $categories = RawWire_Toolbox_Core::get_categories();

            $html = '<div class="rawwire-toolkit-settings">';
            $html .= '<h3>Toolkit Configuration</h3>';
            $html .= '<p class="description">Configure the tools required by this module. Select a provider tier and enter your credentials.</p>';

            foreach ($requirements as $req_key => $requirement) {
                $category = $requirement['type'] ?? '';
                $label = $requirement['label'] ?? ucfirst($category);

                if (empty($category) || !isset($categories[$category])) {
                    continue;
                }

                $category_def = $categories[$category];
                $adapters = $category_def['adapters'] ?? array();

                // Get saved configuration
                $saved_config = RawWire_Toolbox_Core::get_saved_config($category, $module_slug);
                $selected_adapter = $saved_config['adapter_id'] ?? ($defaults[$req_key] ?? '');

                $html .= '<div class="toolkit-requirement" data-requirement="' . esc_attr($req_key) . '" data-category="' . esc_attr($category) . '">';
                $html .= '<div class="requirement-header">';
                $html .= '<span class="dashicons ' . esc_attr($category_def['icon'] ?? 'dashicons-admin-generic') . '"></span>';
                $html .= '<h4>' . esc_html($label) . '</h4>';
                $html .= '<span class="requirement-category">' . esc_html($category_def['label'] ?? $category) . '</span>';
                $html .= '</div>';

                // Adapter selector
                $html .= '<div class="adapter-selector">';
                $html .= '<label>Select Provider:</label>';
                $html .= '<select class="adapter-select" data-category="' . esc_attr($category) . '">';

                // Group by tier
                $tiers = array('free' => array(), 'value' => array(), 'flagship' => array());
                foreach ($adapters as $adapter_id => $adapter_def) {
                    $tier = $adapter_def['tier'] ?? 'free';
                    $tiers[$tier][$adapter_id] = $adapter_def;
                }

                foreach (array('free' => 'Free Tier', 'value' => 'Value Tier', 'flagship' => 'Flagship Tier') as $tier => $tier_label) {
                    if (empty($tiers[$tier])) continue;

                    $html .= '<optgroup label="' . esc_attr($tier_label) . '">';
                    foreach ($tiers[$tier] as $adapter_id => $adapter_def) {
                        $selected = ($selected_adapter === $adapter_id) ? ' selected' : '';
                        $html .= '<option value="' . esc_attr($adapter_id) . '"' . $selected . '>';
                        $html .= esc_html($adapter_def['label'] ?? $adapter_id);
                        $html .= '</option>';
                    }
                    $html .= '</optgroup>';
                }

                $html .= '</select>';
                $html .= '</div>';

                // Config form container (populated via JS)
                $html .= '<div class="adapter-config-form">';
                if (!empty($selected_adapter)) {
                    $html .= RawWire_Toolbox_Core::render_adapter_form(
                        $category,
                        $selected_adapter,
                        $saved_config['settings'] ?? array()
                    );
                }
                $html .= '</div>';

                // Action buttons
                $html .= '<div class="requirement-actions">';
                $html .= '<button type="button" class="button test-adapter-btn" data-category="' . esc_attr($category) . '">Test Connection</button>';
                $html .= '<button type="button" class="button button-primary save-adapter-btn" data-category="' . esc_attr($category) . '">Save Configuration</button>';
                $html .= '<span class="adapter-status"></span>';
                $html .= '</div>';

                $html .= '</div>'; // .toolkit-requirement
            }

            $html .= '</div>'; // .rawwire-toolkit-settings

            // Add inline JS for form interactions
            $html .= self::render_toolkit_settings_js($module_slug);

            return $html;
        }

        /**
         * Render JavaScript for toolkit settings interactions
         */
        protected static function render_toolkit_settings_js(string $module_slug = null) {
            $nonce = wp_create_nonce('rawwire_nonce');
            $module_slug_js = esc_js($module_slug ?? '');

            return <<<JS
<script>
(function($) {
    'use strict';
    
    var moduleSlug = '{$module_slug_js}';
    var nonce = '{$nonce}';
    
    // Handle adapter selection change
    $('.adapter-select').on('change', function() {
        var \$container = $(this).closest('.toolkit-requirement');
        var category = $(this).data('category');
        var adapterId = $(this).val();
        var \$formContainer = \$container.find('.adapter-config-form');
        
        \$formContainer.html('<p class="loading">Loading configuration form...</p>');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'rawwire_toolkit_get_form',
                nonce: nonce,
                category: category,
                adapter_id: adapterId,
                module_slug: moduleSlug
            },
            success: function(response) {
                if (response.success) {
                    \$formContainer.html(response.data.html);
                } else {
                    \$formContainer.html('<p class="error">Failed to load form.</p>');
                }
            },
            error: function() {
                \$formContainer.html('<p class="error">Request failed.</p>');
            }
        });
    });
    
    // Handle test connection
    $('.test-adapter-btn').on('click', function() {
        var \$container = $(this).closest('.toolkit-requirement');
        var \$status = \$container.find('.adapter-status');
        var category = $(this).data('category');
        var adapterId = \$container.find('.adapter-select').val();
        var config = {};
        
        // Gather form values
        \$container.find('.adapter-config-form input, .adapter-config-form select, .adapter-config-form textarea').each(function() {
            var name = $(this).attr('name');
            if (name) {
                if ($(this).attr('type') === 'checkbox') {
                    config[name] = $(this).is(':checked') ? 1 : 0;
                } else {
                    config[name] = $(this).val();
                }
            }
        });
        
        \$status.html('<span class="spinner is-active"></span> Testing...');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'rawwire_toolkit_test',
                nonce: nonce,
                category: category,
                adapter_id: adapterId,
                config: config
            },
            success: function(response) {
                if (response.success) {
                    \$status.html('<span class="dashicons dashicons-yes-alt" style="color: green;"></span> ' + (response.data.message || 'Connected!'));
                } else {
                    \$status.html('<span class="dashicons dashicons-warning" style="color: red;"></span> ' + (response.data.message || 'Test failed'));
                }
            },
            error: function() {
                \$status.html('<span class="dashicons dashicons-warning" style="color: red;"></span> Request failed');
            }
        });
    });
    
    // Handle save configuration
    $('.save-adapter-btn').on('click', function() {
        var \$container = $(this).closest('.toolkit-requirement');
        var \$status = \$container.find('.adapter-status');
        var category = $(this).data('category');
        var adapterId = \$container.find('.adapter-select').val();
        var settings = {};
        
        // Gather form values
        \$container.find('.adapter-config-form input, .adapter-config-form select, .adapter-config-form textarea').each(function() {
            var name = $(this).attr('name');
            if (name) {
                if ($(this).attr('type') === 'checkbox') {
                    settings[name] = $(this).is(':checked') ? 1 : 0;
                } else {
                    settings[name] = $(this).val();
                }
            }
        });
        
        \$status.html('<span class="spinner is-active"></span> Saving...');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'rawwire_toolkit_save',
                nonce: nonce,
                category: category,
                adapter_id: adapterId,
                settings: settings,
                module_slug: moduleSlug
            },
            success: function(response) {
                if (response.success) {
                    \$status.html('<span class="dashicons dashicons-yes-alt" style="color: green;"></span> Saved!');
                    setTimeout(function() { \$status.html(''); }, 3000);
                } else {
                    \$status.html('<span class="dashicons dashicons-warning" style="color: red;"></span> ' + (response.data.message || 'Save failed'));
                }
            },
            error: function() {
                \$status.html('<span class="dashicons dashicons-warning" style="color: red;"></span> Request failed');
            }
        });
    });
})(jQuery);
</script>
<style>
.rawwire-toolkit-settings { max-width: 800px; }
.toolkit-requirement { background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; margin-bottom: 20px; }
.requirement-header { display: flex; align-items: center; gap: 10px; margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 10px; }
.requirement-header h4 { margin: 0; flex: 1; }
.requirement-category { background: #f0f0f1; padding: 2px 8px; border-radius: 3px; font-size: 12px; color: #50575e; }
.adapter-selector { margin-bottom: 15px; }
.adapter-selector label { display: block; font-weight: 600; margin-bottom: 5px; }
.adapter-selector select { width: 100%; max-width: 400px; }
.adapter-config-form { background: #f6f7f7; padding: 15px; border-radius: 4px; margin-bottom: 15px; }
.adapter-config-form .form-field { margin-bottom: 12px; }
.adapter-config-form label { display: block; font-weight: 500; margin-bottom: 4px; }
.adapter-config-form input[type="text"],
.adapter-config-form input[type="password"],
.adapter-config-form input[type="url"],
.adapter-config-form input[type="number"],
.adapter-config-form select,
.adapter-config-form textarea { width: 100%; max-width: 400px; }
.adapter-config-form .required { color: #d63638; }
.adapter-config-form .field-hint { display: block; font-size: 11px; color: #50575e; margin-top: 2px; }
.adapter-config-form .docs-link { margin-top: 10px; }
.adapter-config-form .docs-link a { color: #2271b1; text-decoration: none; }
.requirement-actions { display: flex; align-items: center; gap: 10px; }
.adapter-status { margin-left: 10px; }
.adapter-status .spinner { float: none; margin: 0; }
</style>
JS;
        }

        /**
         * AJAX handler: Save toolkit settings
         */
        public static function ajax_save_toolkit_settings() {
            check_ajax_referer('rawwire_nonce', 'nonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error(array('message' => 'Permission denied'));
            }

            $category = sanitize_key($_POST['category'] ?? '');
            $adapter_id = sanitize_key($_POST['adapter_id'] ?? '');
            $settings = isset($_POST['settings']) ? (array) $_POST['settings'] : array();
            $module_slug = isset($_POST['module_slug']) ? sanitize_key($_POST['module_slug']) : null;

            if (empty($category) || empty($adapter_id)) {
                wp_send_json_error(array('message' => 'Missing required parameters'));
            }

            if (!class_exists('RawWire_Toolbox_Core')) {
                wp_send_json_error(array('message' => 'Toolbox Core not available'));
            }

            $config = array(
                'adapter_id' => $adapter_id,
                'settings' => $settings,
            );

            $result = RawWire_Toolbox_Core::save_config($category, $config, $module_slug);

            if ($result) {
                self::log('Toolkit settings saved', 'info', array(
                    'category' => $category,
                    'adapter' => $adapter_id,
                    'module' => $module_slug,
                ));
                wp_send_json_success(array('message' => 'Configuration saved'));
            } else {
                wp_send_json_error(array('message' => 'Failed to save configuration'));
            }
        }

        /**
         * AJAX handler: Get module requirements
         */
        public static function ajax_get_requirements() {
            check_ajax_referer('rawwire_nonce', 'nonce');

            $module_slug = isset($_POST['module_slug']) ? sanitize_key($_POST['module_slug']) : null;

            wp_send_json_success(array(
                'requirements' => self::get_requirements($module_slug),
                'defaults' => self::get_defaults($module_slug),
            ));
        }

        /**
         * AJAX handler: Load toolkit configuration for modules page
         */
        public static function ajax_load_toolkit_config() {
            check_ajax_referer('rawwire_ajax_nonce', 'nonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error(array('message' => 'Permission denied'));
            }

            $module_slug = isset($_POST['module_slug']) ? sanitize_key($_POST['module_slug']) : null;

            if (empty($module_slug)) {
                wp_send_json_error(array('message' => 'Module slug required'));
            }

            $requirements = self::get_requirements($module_slug);
            if (empty($requirements)) {
                wp_send_json_error(array('message' => 'No toolkit requirements found for module'));
            }

            $html = self::render_toolkit_settings($module_slug, $requirements);

            wp_send_json_success(array('html' => $html));
        }

        /**
         * AJAX handler: Get adapter form for toolkit configuration
         */
        public static function ajax_get_adapter_form() {
            check_ajax_referer('rawwire_ajax_nonce', 'nonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error(array('message' => 'Permission denied'));
            }

            $module_slug = isset($_POST['module_slug']) ? sanitize_key($_POST['module_slug']) : null;
            $category = sanitize_key($_POST['category'] ?? '');
            $adapter = sanitize_key($_POST['adapter'] ?? '');

            if (empty($category) || empty($adapter)) {
                wp_send_json_error(array('message' => 'Category and adapter required'));
            }

            if (!class_exists('RawWire_Toolbox_Core')) {
                wp_send_json_error(array('message' => 'Toolbox Core not available'));
            }

            $form_html = RawWire_Toolbox_Core::render_adapter_form($category, $adapter, $module_slug);

            wp_send_json_success(array('html' => $form_html));
        }

        /**
         * Register REST API routes
         */
        public static function register_rest_routes() {
            register_rest_route('rawwire/v1', '/module/requirements', array(
                'methods' => 'GET',
                'callback' => array(__CLASS__, 'rest_get_requirements'),
                'permission_callback' => function() {
                    return current_user_can('manage_options');
                },
            ));

            register_rest_route('rawwire/v1', '/module/toolkit-html', array(
                'methods' => 'GET',
                'callback' => array(__CLASS__, 'rest_get_toolkit_html'),
                'permission_callback' => function() {
                    return current_user_can('manage_options');
                },
            ));
        }

        /**
         * REST: Get requirements
         */
        public static function rest_get_requirements($request) {
            $module_slug = $request->get_param('module_slug');

            return new WP_REST_Response(array(
                'requirements' => self::get_requirements($module_slug),
                'defaults' => self::get_defaults($module_slug),
            ), 200);
        }

        /**
         * REST: Get toolkit settings HTML
         */
        public static function rest_get_toolkit_html($request) {
            $module_slug = $request->get_param('module_slug');
            $html = self::render_toolkit_settings($module_slug);

            return new WP_REST_Response(array(
                'html' => $html,
            ), 200);
        }

        /**
         * Validate a module configuration
         * 
         * @param array $config Module configuration array
         * @return bool|WP_Error True if valid, WP_Error otherwise
         */
        public static function validate_module(array $config) {
            // Check required meta fields
            if (empty($config['meta']['name'])) {
                self::log('Module validation failed: missing meta.name', 'error', $config);
                return new WP_Error('invalid_module', 'Module configuration missing required meta.name field');
            }

            if (empty($config['meta']['version'])) {
                self::log('Module validation warning: missing meta.version', 'warning', $config);
                // Not fatal, but log warning
            }

            // Validate requirements if present
            if (!empty($config['requirements'])) {
                foreach ($config['requirements'] as $req_key => $requirement) {
                    if (empty($requirement['type'])) {
                        self::log('Module validation failed: requirement missing type', 'error', array(
                            'requirement_key' => $req_key,
                            'requirement' => $requirement,
                        ));
                        return new WP_Error(
                            'invalid_requirement',
                            sprintf('Requirement "%s" is missing required "type" field', $req_key)
                        );
                    }

                    // Verify the requirement type is a valid category
                    if (class_exists('RawWire_Toolbox_Core')) {
                        $categories = RawWire_Toolbox_Core::get_categories();
                        if (!isset($categories[$requirement['type']])) {
                            self::log('Module validation warning: unknown requirement type', 'warning', array(
                                'requirement_key' => $req_key,
                                'type' => $requirement['type'],
                                'available_types' => array_keys($categories),
                            ));
                        }
                    }
                }
            }

            // Validate defaults reference valid adapters
            if (!empty($config['defaults']) && class_exists('RawWire_Toolbox_Core')) {
                $categories = RawWire_Toolbox_Core::get_categories();
                foreach ($config['defaults'] as $req_key => $adapter_id) {
                    if (isset($config['requirements'][$req_key])) {
                        $category = $config['requirements'][$req_key]['type'];
                        if (isset($categories[$category])) {
                            $available_adapters = array_keys($categories[$category]['adapters'] ?? array());
                            if (!in_array($adapter_id, $available_adapters, true)) {
                                self::log('Module validation warning: default adapter not found', 'warning', array(
                                    'requirement_key' => $req_key,
                                    'adapter_id' => $adapter_id,
                                    'available' => $available_adapters,
                                ));
                            }
                        }
                    }
                }
            }

            self::log('Module validation passed', 'debug', array('name' => $config['meta']['name']));
            return true;
        }

        /**
         * Check if all required toolkit components are configured
         * 
         * @param string|null $module_slug
         * @return array Array of missing/unconfigured requirements
         */
        public static function check_requirements_configured(string $module_slug = null) {
            $requirements = self::get_requirements($module_slug);
            $missing = array();

            if (empty($requirements) || !class_exists('RawWire_Toolbox_Core')) {
                return $missing;
            }

            foreach ($requirements as $req_key => $requirement) {
                $category = $requirement['type'] ?? '';
                if (empty($category)) {
                    continue;
                }

                $config = RawWire_Toolbox_Core::get_saved_config($category, $module_slug);
                
                if (empty($config['adapter_id'])) {
                    $missing[] = array(
                        'key' => $req_key,
                        'type' => $category,
                        'label' => $requirement['label'] ?? ucfirst($category),
                        'reason' => 'No adapter selected',
                    );
                    continue;
                }

                // Optionally test the connection
                if (!empty($requirement['validate_on_load'])) {
                    $adapter = RawWire_Toolbox_Core::get_configured_adapter($category, $module_slug);
                    if (is_wp_error($adapter)) {
                        $missing[] = array(
                            'key' => $req_key,
                            'type' => $category,
                            'label' => $requirement['label'] ?? ucfirst($category),
                            'reason' => $adapter->get_error_message(),
                        );
                    }
                }
            }

            return $missing;
        }

        /**
         * Render a notice if requirements are not configured
         * 
         * @param string|null $module_slug
         * @return string HTML notice or empty string
         */
        public static function render_requirements_notice(string $module_slug = null) {
            $missing = self::check_requirements_configured($module_slug);

            if (empty($missing)) {
                return '';
            }

            $html = '<div class="notice notice-warning rawwire-requirements-notice">';
            $html .= '<p><strong>⚠️ Module Configuration Required</strong></p>';
            $html .= '<p>The following toolkit components need to be configured:</p>';
            $html .= '<ul>';

            foreach ($missing as $item) {
                $html .= '<li><strong>' . esc_html($item['label']) . '</strong>: ' . esc_html($item['reason']) . '</li>';
            }

            $html .= '</ul>';
            $html .= '<p><a href="#toolkit-settings" class="button button-primary">Configure Toolkit</a></p>';
            $html .= '</div>';

            return $html;
        }
    }
}

// Bootstrap
RawWire_Module_Core::init();
