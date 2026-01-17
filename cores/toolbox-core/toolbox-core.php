<?php
/**
 * Toolbox Core - adapter registry and orchestrator hooks
 * Path: cores/toolbox-core/toolbox-core.php
 * 
 * Provides a bulletproof, template-driven adapter system for:
 * - Data Collection (Scrapers)
 * - Workflow Orchestration
 * - Content Generation (AI)
 * - Content Publishing (Posters)
 * 
 * All adapter behavior is configured via registry.json and user settings.
 * The code remains static; only configuration changes.
 */
if (!class_exists('RawWire_Toolbox_Core')) {
    class RawWire_Toolbox_Core {
        /**
         * Instantiated adapter instances
         * @var array
         */
        protected static $adapters = array();

        /**
         * Registry data loaded from registry.json
         * @var array|null
         */
        protected static $registry = null;

        /**
         * Adapter class mapping
         * @var array
         */
        protected static $class_map = array(
            // Scrapers
            'RawWire_Adapter_Scraper_Native' => 'adapters/scrapers/class-scraper-native.php',
            'RawWire_Adapter_Scraper_API' => 'adapters/scrapers/class-scraper-api.php',
            'RawWire_Adapter_Scraper_BrightData' => 'adapters/scrapers/class-scraper-brightdata.php',
            // Workflows
            'RawWire_Adapter_Workflow_Internal' => 'adapters/workflows/class-workflow-internal.php',
            'RawWire_Adapter_Workflow_N8n' => 'adapters/workflows/class-workflow-n8n.php',
            'RawWire_Adapter_Workflow_Make' => 'adapters/workflows/class-workflow-make.php',
            // Generators (real AI only - removed mock generator)
            'RawWire_Adapter_Generator_OpenAI' => 'adapters/generators/class-generator-openai.php',
            'RawWire_Adapter_Generator_Anthropic' => 'adapters/generators/class-generator-anthropic.php',
            // Posters
            'RawWire_Poster_WordPress' => 'adapters/posters/class-poster-wordpress.php',
            'RawWire_Poster_Twitter' => 'adapters/posters/class-poster-twitter.php',
            'RawWire_Poster_Discord' => 'adapters/posters/class-poster-discord.php',
        );

        /**
         * Logger instance
         * @var RawWire_Logger|null
         */
        protected static $logger = null;

        /**
         * Initialize the Toolbox Core
         */
        public static function init() {
            // Load interfaces first
            require_once __DIR__ . '/interfaces/interface-adapter.php';
            require_once __DIR__ . '/interfaces/interface-scraper.php';
            require_once __DIR__ . '/interfaces/interface-workflow.php';
            require_once __DIR__ . '/interfaces/interface-generator.php';
            require_once __DIR__ . '/interfaces/interface-poster.php';

            // Load base adapter class
            require_once __DIR__ . '/adapters/class-adapter-base.php';
            
            // Load centralized key manager (early, before adapters need keys)
            require_once __DIR__ . '/class-key-manager.php';

            // Initialize logger
            if (class_exists('RawWire_Logger')) {
                self::$logger = new RawWire_Logger();
            }

            // Load registry on init
            add_action('init', array(__CLASS__, 'load_registry'), 5);

            // Register REST routes for toolkit management
            add_action('rest_api_init', array(__CLASS__, 'register_rest_routes'));

            // Register AJAX handlers
            add_action('wp_ajax_rawwire_toolkit_test', array(__CLASS__, 'ajax_test_adapter'));
            add_action('wp_ajax_rawwire_toolkit_save', array(__CLASS__, 'ajax_save_config'));
            add_action('wp_ajax_rawwire_toolkit_get_form', array(__CLASS__, 'ajax_get_form'));
        }

        /**
         * Log a message safely
         */
        protected static function log(string $message, string $level = 'info', array $context = array()) {
            if (self::$logger) {
                $context['component'] = 'toolbox-core';
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
         * Load the adapter registry from JSON
         */
        public static function load_registry() {
            if (self::$registry !== null) {
                return self::$registry;
            }

            $registry_path = __DIR__ . '/registry.json';

            if (!file_exists($registry_path)) {
                self::log('Registry file not found', 'error', array('path' => $registry_path));
                self::$registry = array('categories' => array());
                return self::$registry;
            }

            $json = file_get_contents($registry_path);
            $data = json_decode($json, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                self::log('Failed to parse registry JSON', 'error', array(
                    'error' => json_last_error_msg(),
                ));
                self::$registry = array('categories' => array());
                return self::$registry;
            }

            self::$registry = $data;
            self::log('Registry loaded', 'debug', array(
                'categories' => array_keys($data['categories'] ?? array()),
            ));

            return self::$registry;
        }

        /**
         * Get the full registry
         * 
         * @return array
         */
        public static function get_registry() {
            if (self::$registry === null) {
                self::load_registry();
            }
            return self::$registry;
        }

        /**
         * Get all categories
         * 
         * @return array
         */
        public static function get_categories() {
            $registry = self::get_registry();
            return $registry['categories'] ?? array();
        }

        /**
         * Get adapters for a specific category
         * 
         * @param string $category
         * @return array
         */
        public static function get_category_adapters(string $category) {
            $categories = self::get_categories();
            return $categories[$category]['adapters'] ?? array();
        }

        /**
         * Get adapter definition from registry
         * 
         * @param string $category
         * @param string $adapter_id
         * @return array|null
         */
        public static function get_adapter_definition(string $category, string $adapter_id) {
            $adapters = self::get_category_adapters($category);
            return $adapters[$adapter_id] ?? null;
        }

        /**
         * Factory method to create an adapter instance
         * 
         * @param string $category Category (scraper, workflow, generator, poster)
         * @param string $adapter_id Adapter ID from registry
         * @param array $config Configuration (credentials, options)
         * @return object|WP_Error Adapter instance or error
         */
        public static function factory(string $category, string $adapter_id, array $config = array()) {
            // Get adapter definition from registry
            $definition = self::get_adapter_definition($category, $adapter_id);

            if (!$definition) {
                self::log('Adapter not found in registry', 'error', array(
                    'category' => $category,
                    'adapter_id' => $adapter_id,
                ));
                return new WP_Error(
                    'adapter_not_found',
                    sprintf('Adapter "%s" not found in category "%s"', $adapter_id, $category)
                );
            }

            $class_name = $definition['class'] ?? '';

            if (empty($class_name)) {
                return new WP_Error('invalid_adapter', 'Adapter class not defined');
            }

            // Load the class file if not already loaded
            if (!class_exists($class_name)) {
                $class_file = self::$class_map[$class_name] ?? null;

                if (!$class_file) {
                    self::log('Adapter class file not mapped', 'error', array('class' => $class_name));
                    return new WP_Error('class_not_found', "Class file for $class_name not found");
                }

                $full_path = __DIR__ . '/' . $class_file;

                if (!file_exists($full_path)) {
                    self::log('Adapter class file missing', 'error', array('path' => $full_path));
                    return new WP_Error('file_not_found', "Adapter file not found: $class_file");
                }

                require_once $full_path;
            }

            // Merge user config with defaults from registry
            $merged_config = self::merge_config_with_defaults($definition, $config);

            try {
                $instance = new $class_name($merged_config);

                // Validate configuration
                $validation = $instance->validate_config();
                if (is_wp_error($validation)) {
                    self::log('Adapter config validation failed', 'warning', array(
                        'adapter' => $adapter_id,
                        'error' => $validation->get_error_message(),
                    ));
                    // Return instance anyway - let caller decide how to handle
                }

                self::log('Adapter instantiated', 'debug', array(
                    'category' => $category,
                    'adapter' => $adapter_id,
                ));

                return $instance;

            } catch (Exception $e) {
                self::log('Failed to instantiate adapter', 'error', array(
                    'class' => $class_name,
                    'error' => $e->getMessage(),
                ));
                return new WP_Error('instantiation_failed', $e->getMessage());
            }
        }

        /**
         * Merge user config with defaults from registry definition
         */
        protected static function merge_config_with_defaults(array $definition, array $config) {
            $defaults = array();

            foreach ($definition['config_fields'] ?? array() as $field) {
                $key = $field['key'] ?? '';
                if (!empty($key) && isset($field['default'])) {
                    $defaults[$key] = $field['default'];
                }
            }

            return array_merge($defaults, $config);
        }

        /**
         * Get a configured adapter from saved settings
         * 
         * @param string $category
         * @param string|null $module_slug Optional module context
         * @return object|WP_Error
         */
        public static function get_configured_adapter(string $category, string $module_slug = null) {
            $cache_key = $category . '_' . ($module_slug ?? 'global');

            // Return cached instance if available
            if (isset(self::$adapters[$cache_key])) {
                return self::$adapters[$cache_key];
            }

            // Get saved configuration
            $config = self::get_saved_config($category, $module_slug);

            if (empty($config['adapter_id'])) {
                // Return default (free tier) adapter
                $adapters = self::get_category_adapters($category);
                $default_id = null;

                foreach ($adapters as $id => $def) {
                    if (($def['tier'] ?? '') === 'free') {
                        $default_id = $id;
                        break;
                    }
                }

                if (!$default_id) {
                    $default_id = array_key_first($adapters);
                }

                if (!$default_id) {
                    return new WP_Error('no_adapters', "No adapters available for category: $category");
                }

                self::log('Using default adapter', 'debug', array(
                    'category' => $category,
                    'adapter' => $default_id,
                ));

                $config['adapter_id'] = $default_id;
            }

            $instance = self::factory($category, $config['adapter_id'], $config['settings'] ?? array());

            if (!is_wp_error($instance)) {
                self::$adapters[$cache_key] = $instance;
            }

            return $instance;
        }

        /**
         * Get saved adapter configuration
         */
        public static function get_saved_config(string $category, string $module_slug = null) {
            $option_key = 'rawwire_toolkit_' . $category;

            if ($module_slug) {
                $option_key .= '_' . sanitize_key($module_slug);
            }

            $config = get_option($option_key, array());

            return is_array($config) ? $config : array();
        }

        /**
         * Save adapter configuration
         */
        public static function save_config(string $category, array $config, string $module_slug = null) {
            $option_key = 'rawwire_toolkit_' . $category;

            if ($module_slug) {
                $option_key .= '_' . sanitize_key($module_slug);
            }

            // Sanitize sensitive fields
            if (!empty($config['settings'])) {
                foreach ($config['settings'] as $key => $value) {
                    if (is_string($value)) {
                        $config['settings'][$key] = sanitize_text_field($value);
                    }
                }
            }

            $result = update_option($option_key, $config);

            // Clear cached adapter
            $cache_key = $category . '_' . ($module_slug ?? 'global');
            unset(self::$adapters[$cache_key]);

            self::log('Adapter config saved', 'info', array(
                'category' => $category,
                'adapter' => $config['adapter_id'] ?? 'unknown',
            ));

            return $result;
        }

        /**
         * Get form fields for an adapter
         * 
         * @param string $category
         * @param string $adapter_id
         * @return array
         */
        public static function get_adapter_form_fields(string $category, string $adapter_id) {
            $definition = self::get_adapter_definition($category, $adapter_id);

            if (!$definition) {
                return array();
            }

            return $definition['config_fields'] ?? array();
        }

        /**
         * Render HTML form for adapter configuration
         */
        public static function render_adapter_form(string $category, string $adapter_id, array $saved_values = array()) {
            $fields = self::get_adapter_form_fields($category, $adapter_id);
            $definition = self::get_adapter_definition($category, $adapter_id);

            if (empty($fields)) {
                return '<p class="description">This adapter requires no configuration.</p>';
            }

            $html = '<div class="rawwire-adapter-form" data-category="' . esc_attr($category) . '" data-adapter="' . esc_attr($adapter_id) . '">';

            if (!empty($definition['description'])) {
                $html .= '<p class="adapter-description">' . esc_html($definition['description']) . '</p>';
            }

            foreach ($fields as $field) {
                $key = $field['key'] ?? '';
                $label = $field['label'] ?? ucfirst(str_replace('_', ' ', $key));
                $type = $field['type'] ?? 'text';
                $required = !empty($field['required']);
                $value = $saved_values[$key] ?? ($field['default'] ?? '');
                $placeholder = $field['placeholder'] ?? '';

                $field_id = 'rawwire_' . $category . '_' . $adapter_id . '_' . $key;

                $html .= '<div class="form-field">';
                $html .= '<label for="' . esc_attr($field_id) . '">' . esc_html($label);
                if ($required) {
                    $html .= ' <span class="required">*</span>';
                }
                $html .= '</label>';

                switch ($type) {
                    case 'select':
                        $html .= '<select id="' . esc_attr($field_id) . '" name="' . esc_attr($key) . '"' . ($required ? ' required' : '') . '>';
                        foreach ($field['options'] ?? array() as $option) {
                            $selected = ($value === $option) ? ' selected' : '';
                            $html .= '<option value="' . esc_attr($option) . '"' . $selected . '>' . esc_html(ucfirst($option)) . '</option>';
                        }
                        $html .= '</select>';
                        break;

                    case 'checkbox':
                        $checked = !empty($value) ? ' checked' : '';
                        $html .= '<input type="checkbox" id="' . esc_attr($field_id) . '" name="' . esc_attr($key) . '" value="1"' . $checked . '>';
                        break;

                    case 'number':
                        $min = isset($field['min']) ? ' min="' . esc_attr($field['min']) . '"' : '';
                        $max = isset($field['max']) ? ' max="' . esc_attr($field['max']) . '"' : '';
                        $step = isset($field['step']) ? ' step="' . esc_attr($field['step']) . '"' : '';
                        $html .= '<input type="number" id="' . esc_attr($field_id) . '" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '"' . $min . $max . $step . ($required ? ' required' : '') . '>';
                        break;

                    case 'password':
                        // Don't show actual password value
                        $display_value = !empty($value) ? '••••••••' : '';
                        $html .= '<input type="password" id="' . esc_attr($field_id) . '" name="' . esc_attr($key) . '" placeholder="' . esc_attr($placeholder) . '"' . ($required ? ' required' : '') . ' autocomplete="off">';
                        if (!empty($value)) {
                            $html .= '<span class="field-hint">Currently configured</span>';
                        }
                        break;

                    case 'url':
                        $html .= '<input type="url" id="' . esc_attr($field_id) . '" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" placeholder="' . esc_attr($placeholder) . '"' . ($required ? ' required' : '') . '>';
                        break;

                    case 'textarea':
                        $html .= '<textarea id="' . esc_attr($field_id) . '" name="' . esc_attr($key) . '" placeholder="' . esc_attr($placeholder) . '"' . ($required ? ' required' : '') . '>' . esc_textarea($value) . '</textarea>';
                        break;

                    default: // text
                        $html .= '<input type="text" id="' . esc_attr($field_id) . '" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" placeholder="' . esc_attr($placeholder) . '"' . ($required ? ' required' : '') . '>';
                }

                $html .= '</div>';
            }

            // Documentation link
            if (!empty($definition['docs_url'])) {
                $html .= '<p class="docs-link"><a href="' . esc_url($definition['docs_url']) . '" target="_blank" rel="noopener">View documentation →</a></p>';
            }

            $html .= '</div>';

            return $html;
        }

        /**
         * Test an adapter connection
         */
        public static function test_adapter(string $category, string $adapter_id, array $config) {
            $instance = self::factory($category, $adapter_id, $config);

            if (is_wp_error($instance)) {
                return array(
                    'success' => false,
                    'message' => $instance->get_error_message(),
                );
            }

            try {
                $result = $instance->test_connection();
                self::log('Adapter test completed', 'info', array(
                    'category' => $category,
                    'adapter' => $adapter_id,
                    'success' => $result['success'] ?? false,
                ));
                return $result;
            } catch (Exception $e) {
                self::log('Adapter test failed with exception', 'error', array(
                    'adapter' => $adapter_id,
                    'error' => $e->getMessage(),
                ));
                return array(
                    'success' => false,
                    'message' => 'Test failed: ' . $e->getMessage(),
                );
            }
        }

        /**
         * Register REST API routes
         */
        public static function register_rest_routes() {
            register_rest_route('rawwire/v1', '/toolkit/registry', array(
                'methods' => 'GET',
                'callback' => array(__CLASS__, 'rest_get_registry'),
                'permission_callback' => function() {
                    return current_user_can('manage_options');
                },
            ));

            register_rest_route('rawwire/v1', '/toolkit/test', array(
                'methods' => 'POST',
                'callback' => array(__CLASS__, 'rest_test_adapter'),
                'permission_callback' => function() {
                    return current_user_can('manage_options');
                },
            ));

            register_rest_route('rawwire/v1', '/toolkit/config', array(
                'methods' => array('GET', 'POST'),
                'callback' => array(__CLASS__, 'rest_handle_config'),
                'permission_callback' => function() {
                    return current_user_can('manage_options');
                },
            ));
        }

        /**
         * REST: Get registry
         */
        public static function rest_get_registry($request) {
            return new WP_REST_Response(self::get_registry(), 200);
        }

        /**
         * REST: Test adapter
         */
        public static function rest_test_adapter($request) {
            $category = sanitize_key($request->get_param('category'));
            $adapter_id = sanitize_key($request->get_param('adapter_id'));
            $config = $request->get_param('config') ?? array();

            if (empty($category) || empty($adapter_id)) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'Missing category or adapter_id',
                ), 400);
            }

            $result = self::test_adapter($category, $adapter_id, $config);

            return new WP_REST_Response($result, $result['success'] ? 200 : 400);
        }

        /**
         * REST: Handle config get/save
         */
        public static function rest_handle_config($request) {
            $category = sanitize_key($request->get_param('category'));
            $module_slug = $request->get_param('module_slug');

            if (empty($category)) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'Missing category',
                ), 400);
            }

            if ($request->get_method() === 'GET') {
                return new WP_REST_Response(array(
                    'success' => true,
                    'config' => self::get_saved_config($category, $module_slug),
                ), 200);
            }

            // POST - save config
            $config = array(
                'adapter_id' => sanitize_key($request->get_param('adapter_id')),
                'settings' => $request->get_param('settings') ?? array(),
            );

            $result = self::save_config($category, $config, $module_slug);

            return new WP_REST_Response(array(
                'success' => $result,
                'message' => $result ? 'Configuration saved' : 'Failed to save configuration',
            ), $result ? 200 : 500);
        }

        /**
         * AJAX: Test adapter
         */
        public static function ajax_test_adapter() {
            check_ajax_referer('rawwire_nonce', 'nonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error(array('message' => 'Permission denied'));
            }

            $category = sanitize_key($_POST['category'] ?? '');
            $adapter_id = sanitize_key($_POST['adapter_id'] ?? '');
            $config = isset($_POST['config']) ? (array) $_POST['config'] : array();

            if (empty($category) || empty($adapter_id)) {
                wp_send_json_error(array('message' => 'Missing parameters'));
            }

            $result = self::test_adapter($category, $adapter_id, $config);

            if ($result['success']) {
                wp_send_json_success($result);
            } else {
                wp_send_json_error($result);
            }
        }

        /**
         * AJAX: Save config
         */
        public static function ajax_save_config() {
            check_ajax_referer('rawwire_nonce', 'nonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error(array('message' => 'Permission denied'));
            }

            $category = sanitize_key($_POST['category'] ?? '');
            $adapter_id = sanitize_key($_POST['adapter_id'] ?? '');
            $settings = isset($_POST['settings']) ? (array) $_POST['settings'] : array();
            $module_slug = isset($_POST['module_slug']) ? sanitize_key($_POST['module_slug']) : null;

            if (empty($category) || empty($adapter_id)) {
                wp_send_json_error(array('message' => 'Missing parameters'));
            }

            $config = array(
                'adapter_id' => $adapter_id,
                'settings' => $settings,
            );

            $result = self::save_config($category, $config, $module_slug);

            if ($result) {
                wp_send_json_success(array('message' => 'Configuration saved'));
            } else {
                wp_send_json_error(array('message' => 'Failed to save configuration'));
            }
        }

        /**
         * AJAX: Get adapter form
         */
        public static function ajax_get_form() {
            check_ajax_referer('rawwire_nonce', 'nonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error(array('message' => 'Permission denied'));
            }

            $category = sanitize_key($_POST['category'] ?? '');
            $adapter_id = sanitize_key($_POST['adapter_id'] ?? '');
            $module_slug = isset($_POST['module_slug']) ? sanitize_key($_POST['module_slug']) : null;

            if (empty($category) || empty($adapter_id)) {
                wp_send_json_error(array('message' => 'Missing parameters'));
            }

            $saved_config = self::get_saved_config($category, $module_slug);
            $saved_values = $saved_config['settings'] ?? array();

            $html = self::render_adapter_form($category, $adapter_id, $saved_values);
            $definition = self::get_adapter_definition($category, $adapter_id);

            wp_send_json_success(array(
                'html' => $html,
                'definition' => $definition,
            ));
        }

        /**
         * Execute a workflow plan using configured adapters
         * 
         * @param array $plan Workflow plan
         * @param callable|null $on_update Progress callback
         * @return array Execution result
         */
        public static function execute_plan(array $plan, callable $on_update = null) {
            $execution_id = wp_generate_uuid4();
            $results = array();

            self::log('Plan execution started', 'info', array(
                'execution_id' => $execution_id,
                'steps' => count($plan['steps'] ?? array()),
            ));

            if (empty($plan['steps'])) {
                return array(
                    'success' => false,
                    'execution_id' => $execution_id,
                    'error' => 'No steps defined in plan',
                );
            }

            $context = array('data' => $plan['initial_data'] ?? array());

            foreach ($plan['steps'] as $index => $step) {
                $step_id = $step['id'] ?? "step_$index";

                // Notify progress
                if (is_callable($on_update)) {
                    call_user_func($on_update, array(
                        'execution_id' => $execution_id,
                        'step' => $step_id,
                        'index' => $index,
                        'total' => count($plan['steps']),
                        'status' => 'running',
                    ));
                }

                $step_result = self::execute_step($step, $context);
                $results[$step_id] = $step_result;

                if (!$step_result['success'] && ($step['critical'] ?? true)) {
                    self::log('Critical step failed', 'error', array(
                        'step' => $step_id,
                        'error' => $step_result['error'] ?? 'Unknown error',
                    ));

                    return array(
                        'success' => false,
                        'execution_id' => $execution_id,
                        'failed_step' => $step_id,
                        'error' => $step_result['error'] ?? 'Step failed',
                        'results' => $results,
                    );
                }

                // Pass result to next step
                $context['previous'] = $step_result['data'] ?? null;
                $context[$step_id] = $step_result['data'] ?? null;
            }

            self::log('Plan execution completed', 'info', array('execution_id' => $execution_id));

            return array(
                'success' => true,
                'execution_id' => $execution_id,
                'results' => $results,
                'context' => $context,
            );
        }

        /**
         * Execute a single plan step
         */
        protected static function execute_step(array $step, array $context) {
            $category = $step['adapter_category'] ?? '';
            $action = $step['action'] ?? '';

            if (empty($category) || empty($action)) {
                return array(
                    'success' => false,
                    'error' => 'Step missing adapter_category or action',
                );
            }

            // Get configured adapter for the category
            $adapter = self::get_configured_adapter($category);

            if (is_wp_error($adapter)) {
                return array(
                    'success' => false,
                    'error' => $adapter->get_error_message(),
                );
            }

            // Check if adapter has the requested method
            if (!method_exists($adapter, $action)) {
                return array(
                    'success' => false,
                    'error' => "Adapter does not support action: $action",
                );
            }

            // Interpolate step params with context
            $params = self::interpolate_params($step['params'] ?? array(), $context);

            try {
                $result = call_user_func(array($adapter, $action), ...$params);
                return is_array($result) ? $result : array('success' => true, 'data' => $result);
            } catch (Exception $e) {
                return array(
                    'success' => false,
                    'error' => $e->getMessage(),
                );
            }
        }

        /**
         * Interpolate template variables in params
         */
        protected static function interpolate_params(array $params, array $context) {
            $result = array();

            foreach ($params as $value) {
                if (is_string($value)) {
                    // Replace {{variable}} with context values
                    $value = preg_replace_callback('/\{\{([^}]+)\}\}/', function($matches) use ($context) {
                        $key = trim($matches[1]);
                        return self::get_nested_value($context, $key) ?? '';
                    }, $value);
                } elseif (is_array($value)) {
                    $value = self::interpolate_params($value, $context);
                }
                $result[] = $value;
            }

            return $result;
        }

        /**
         * Get nested value from array
         */
        protected static function get_nested_value($data, string $path) {
            $keys = explode('.', $path);
            foreach ($keys as $key) {
                if (!is_array($data) || !isset($data[$key])) {
                    return null;
                }
                $data = $data[$key];
            }
            return $data;
        }

        /**
         * Get adapter by name (legacy compatibility)
         */
        public static function get_adapter($name) {
            return self::$adapters[$name] ?? null;
        }

        /**
         * Register adapter instance manually (legacy compatibility)
         */
        public static function register_adapter_instance($name, $instance) {
            self::$adapters[$name] = $instance;
        }
    }
}

// Bootstrap
RawWire_Toolbox_Core::init();
