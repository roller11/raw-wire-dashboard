<?php
/**
 * Template Engine - Template loading, rendering, and management
 * Path: cores/template-engine/template-engine.php
 *
 * Handles all template operations:
 * - Loading template JSON files
 * - Managing template variants
 * - Rendering panels and pages dynamically
 * - Template switching with data backup
 * - CSS generation from template config
 */

if (!class_exists('RawWire_Template_Engine')) {
    class RawWire_Template_Engine {

        /**
         * Loaded template configuration
         * @var array|null
         */
        protected static $template = null;

        /**
         * Current variant
         * @var string
         */
        protected static $current_variant = 'default';

        /**
         * Template directory path
         * @var string
         */
        protected static $template_dir = null;

        /**
         * Logger instance
         * @var RawWire_Logger|null
         */
        protected static $logger = null;

        /**
         * Initialize the Template Engine
         */
        public static function init() {
            self::$template_dir = dirname(dirname(dirname(__FILE__))) . '/templates';

            // Initialize logger
            if (class_exists('RawWire_Logger')) {
                self::$logger = new RawWire_Logger();
            }

            // Load active template
            add_action('init', array(__CLASS__, 'load_active_template'), 6);

            // Register AJAX handlers
            add_action('wp_ajax_rawwire_template_switch', array(__CLASS__, 'ajax_switch_template'));
            add_action('wp_ajax_rawwire_template_list', array(__CLASS__, 'ajax_list_templates'));
            add_action('wp_ajax_rawwire_template_export_data', array(__CLASS__, 'ajax_export_data'));
            add_action('wp_ajax_rawwire_template_variant', array(__CLASS__, 'ajax_switch_variant'));
            add_action('wp_ajax_rawwire_template_save_settings', array(__CLASS__, 'ajax_save_settings'));

            // Register REST routes
            add_action('rest_api_init', array(__CLASS__, 'register_rest_routes'));

            // Enqueue template CSS
            add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_template_css'));
        }

        /**
         * Log a message safely
         */
        protected static function log(string $message, string $level = 'info', array $context = array()) {
            if (self::$logger) {
                $context['component'] = 'template-engine';
                if ($level === 'error') {
                    self::$logger->log_error($message, $context, 'error');
                } else {
                    self::$logger->log($message, $level, $context);
                }
            }
        }

        /**
         * Load the active template
         */
        public static function load_active_template() {
            $template_id = get_option('rawwire_active_template', 'news-aggregator');
            self::$current_variant = get_option('rawwire_template_variant', 'default');

            $template_path = self::$template_dir . '/' . $template_id . '.template.json';

            if (!file_exists($template_path)) {
                self::log('Template file not found', 'warning', array('path' => $template_path));
                // Try default template
                $template_path = self::$template_dir . '/news-aggregator.template.json';
            }

            if (!file_exists($template_path)) {
                self::log('No template files found', 'error');
                return false;
            }

            $json = file_get_contents($template_path);
            $template = json_decode($json, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                self::log('Failed to parse template JSON', 'error', array(
                    'error' => json_last_error_msg(),
                ));
                return false;
            }

            self::$template = $template;
            self::log('Template loaded', 'debug', array('template' => $template_id));

            // Ensure database tables exist
            self::ensure_tables();

            return true;
        }

        /**
         * Get the loaded template
         * @return array|null
         */
        public static function get_template() {
            return self::$template;
        }

        /**
         * Get the active template (alias for get_template)
         * @return array|null
         */
        public static function get_active_template() {
            return self::$template;
        }

        /**
         * Get current variant
         * @return string
         */
        public static function get_variant() {
            return self::$current_variant;
        }

        /**
         * Get template meta
         * @return array
         */
        public static function get_meta() {
            return self::$template['meta'] ?? array();
        }

        /**
         * Get all pages defined in template
         * @return array
         */
        public static function get_pages() {
            return self::$template['pages'] ?? array();
        }

        /**
         * Get a specific page configuration
         * @param string $page_id
         * @return array|null
         */
        public static function get_page($page_id) {
            return self::$template['pages'][$page_id] ?? null;
        }

        /**
         * Get all panels defined in template
         * @return array
         */
        public static function get_panels() {
            return self::$template['panels'] ?? array();
        }

        /**
         * Get a specific panel configuration
         * @param string $panel_id
         * @return array|null
         */
        public static function get_panel($panel_id) {
            return self::$template['panels'][$panel_id] ?? null;
        }

        /**
         * Get CSS configuration with variant applied
         * @return array
         */
        public static function get_css() {
            $css = self::$template['css'] ?? array();
            $global = $css['global'] ?? array();

            // Apply variant overrides
            if (self::$current_variant !== 'default') {
                $variant_css = $css['variants'][self::$current_variant] ?? array();
                $global = self::array_merge_recursive_distinct($global, $variant_css);
            }

            return $global;
        }

        /**
         * Get toolbox configuration
         * @return array
         */
        public static function get_toolbox() {
            return self::$template['toolbox'] ?? array();
        }

        /**
         * Get sources configuration
         * @return array
         */
        public static function get_sources() {
            return self::$template['sources'] ?? array();
        }

        /**
         * Get workflow configuration
         * @return array
         */
        public static function get_workflow() {
            return self::$template['workflow'] ?? array();
        }

        /**
         * Get data/table configuration
         * @return array
         */
        public static function get_data_config() {
            return self::$template['data'] ?? array();
        }

        /**
         * Generate CSS string from template configuration
         * @return string
         */
        public static function generate_css() {
            $css_config = self::get_css();
            $css_rules = array();

            // Root CSS variables
            $css_vars = array();

            // Colors
            if (isset($css_config['colors'])) {
                foreach ($css_config['colors'] as $name => $value) {
                    $css_vars[] = sprintf('--rawwire-%s: %s;', self::camel_to_kebab($name), $value);
                }
            }

            // Spacing
            if (isset($css_config['spacing'])) {
                foreach ($css_config['spacing'] as $name => $value) {
                    $css_vars[] = sprintf('--rawwire-spacing-%s: %s;', $name, $value);
                }
            }

            // Border radius
            if (isset($css_config['borderRadius'])) {
                foreach ($css_config['borderRadius'] as $name => $value) {
                    $css_vars[] = sprintf('--rawwire-radius-%s: %s;', $name, $value);
                }
            }

            // Shadows
            if (isset($css_config['shadows'])) {
                foreach ($css_config['shadows'] as $name => $value) {
                    $css_vars[] = sprintf('--rawwire-shadow-%s: %s;', $name, $value);
                }
            }

            // Font settings
            if (isset($css_config['fontFamily'])) {
                $css_vars[] = sprintf('--rawwire-font-family: %s;', $css_config['fontFamily']);
            }
            if (isset($css_config['fontSize'])) {
                $css_vars[] = sprintf('--rawwire-font-size: %s;', $css_config['fontSize']);
            }
            if (isset($css_config['lineHeight'])) {
                $css_vars[] = sprintf('--rawwire-line-height: %s;', $css_config['lineHeight']);
            }

            // Build root rule
            $css_rules[] = ':root {' . PHP_EOL . '  ' . implode(PHP_EOL . '  ', $css_vars) . PHP_EOL . '}';

            // Base styles
            $css_rules[] = '
.rawwire-dashboard {
    font-family: var(--rawwire-font-family);
    font-size: var(--rawwire-font-size);
    line-height: var(--rawwire-line-height);
    background: var(--rawwire-background);
    color: var(--rawwire-text);
    padding: var(--rawwire-spacing-lg);
}

.rawwire-panel {
    background: var(--rawwire-card);
    border: 1px solid var(--rawwire-border);
    border-radius: var(--rawwire-radius-lg);
    padding: var(--rawwire-spacing-md);
    margin-bottom: var(--rawwire-spacing-md);
    box-shadow: var(--rawwire-shadow-sm);
}

.rawwire-panel-header {
    display: flex;
    align-items: center;
    gap: var(--rawwire-spacing-sm);
    margin-bottom: var(--rawwire-spacing-md);
    padding-bottom: var(--rawwire-spacing-sm);
    border-bottom: 1px solid var(--rawwire-border);
}

.rawwire-panel-header h3 {
    margin: 0;
    font-size: 1.1em;
    color: var(--rawwire-text);
}

.rawwire-panel-body {
    color: var(--rawwire-text-muted);
}

.rawwire-btn {
    display: inline-flex;
    align-items: center;
    gap: var(--rawwire-spacing-xs);
    padding: var(--rawwire-spacing-sm) var(--rawwire-spacing-md);
    border-radius: var(--rawwire-radius-md);
    border: none;
    cursor: pointer;
    font-size: 0.9em;
    transition: all 0.2s ease;
}

.rawwire-btn-primary {
    background: var(--rawwire-primary);
    color: white;
}

.rawwire-btn-primary:hover {
    background: var(--rawwire-primary-hover);
}

.rawwire-btn-secondary {
    background: var(--rawwire-surface);
    color: var(--rawwire-text);
    border: 1px solid var(--rawwire-border);
}

.rawwire-btn-success {
    background: var(--rawwire-success);
    color: white;
}

.rawwire-btn-danger {
    background: var(--rawwire-danger);
    color: white;
}

.rawwire-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: var(--rawwire-radius-full);
    font-size: 0.75em;
    font-weight: 500;
}

.rawwire-badge-pending { background: var(--rawwire-warning); color: #000; }
.rawwire-badge-approved { background: var(--rawwire-success); color: white; }
.rawwire-badge-published { background: var(--rawwire-primary); color: white; }
.rawwire-badge-rejected { background: var(--rawwire-danger); color: white; }

.rawwire-grid {
    display: grid;
    gap: var(--rawwire-spacing-md);
}

.rawwire-grid-3col {
    grid-template-columns: repeat(3, 1fr);
}

.rawwire-grid-4col {
    grid-template-columns: repeat(4, 1fr);
}

.rawwire-stat-card {
    background: var(--rawwire-surface);
    border-radius: var(--rawwire-radius-md);
    padding: var(--rawwire-spacing-md);
    text-align: center;
}

.rawwire-stat-card h3 {
    font-size: 2em;
    margin: 0 0 var(--rawwire-spacing-xs);
    color: var(--rawwire-accent);
}

.rawwire-stat-card p {
    margin: 0;
    color: var(--rawwire-text-muted);
    font-size: 0.85em;
}

.rawwire-card {
    background: var(--rawwire-card);
    border: 1px solid var(--rawwire-border);
    border-radius: var(--rawwire-radius-lg);
    padding: var(--rawwire-spacing-md);
    margin-bottom: var(--rawwire-spacing-md);
}

.rawwire-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: var(--rawwire-spacing-sm);
    color: var(--rawwire-text-muted);
    font-size: 0.8em;
}

.rawwire-card-title {
    font-size: 1.1em;
    font-weight: 600;
    margin-bottom: var(--rawwire-spacing-sm);
    color: var(--rawwire-text);
}

.rawwire-card-body {
    color: var(--rawwire-text-muted);
    font-size: 0.9em;
    line-height: 1.6;
}

.rawwire-card-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: var(--rawwire-spacing-md);
    padding-top: var(--rawwire-spacing-sm);
    border-top: 1px solid var(--rawwire-border);
    font-size: 0.8em;
    color: var(--rawwire-text-muted);
}

.rawwire-card-actions {
    display: flex;
    gap: var(--rawwire-spacing-sm);
}

.rawwire-toggle {
    display: flex;
    align-items: center;
    gap: var(--rawwire-spacing-sm);
}

.rawwire-toggle input[type="checkbox"] {
    width: 40px;
    height: 20px;
    appearance: none;
    background: var(--rawwire-border);
    border-radius: var(--rawwire-radius-full);
    position: relative;
    cursor: pointer;
    transition: background 0.2s;
}

.rawwire-toggle input[type="checkbox"]::before {
    content: "";
    position: absolute;
    width: 16px;
    height: 16px;
    background: white;
    border-radius: 50%;
    top: 2px;
    left: 2px;
    transition: transform 0.2s;
}

.rawwire-toggle input[type="checkbox"]:checked {
    background: var(--rawwire-primary);
}

.rawwire-toggle input[type="checkbox"]:checked::before {
    transform: translateX(20px);
}

.rawwire-table {
    width: 100%;
    border-collapse: collapse;
}

.rawwire-table th,
.rawwire-table td {
    padding: var(--rawwire-spacing-sm) var(--rawwire-spacing-md);
    text-align: left;
    border-bottom: 1px solid var(--rawwire-border);
}

.rawwire-table th {
    color: var(--rawwire-text-muted);
    font-weight: 500;
    font-size: 0.85em;
}

.rawwire-table tr:hover {
    background: var(--rawwire-surface);
}

.rawwire-score-bar {
    width: 60px;
    height: 6px;
    background: var(--rawwire-border);
    border-radius: var(--rawwire-radius-full);
    overflow: hidden;
}

.rawwire-score-bar-fill {
    height: 100%;
    background: var(--rawwire-success);
    transition: width 0.3s;
}

.rawwire-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.7);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 100000;
}

.rawwire-modal {
    background: var(--rawwire-card);
    border-radius: var(--rawwire-radius-lg);
    padding: var(--rawwire-spacing-lg);
    max-width: 600px;
    width: 90%;
    max-height: 80vh;
    overflow-y: auto;
    box-shadow: var(--rawwire-shadow-lg);
}

.rawwire-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: var(--rawwire-spacing-md);
}

.rawwire-modal-header h2 {
    margin: 0;
    color: var(--rawwire-text);
}

.rawwire-modal-close {
    background: none;
    border: none;
    color: var(--rawwire-text-muted);
    font-size: 1.5em;
    cursor: pointer;
}

.rawwire-form-group {
    margin-bottom: var(--rawwire-spacing-md);
}

.rawwire-form-group label {
    display: block;
    margin-bottom: var(--rawwire-spacing-xs);
    color: var(--rawwire-text);
    font-weight: 500;
}

.rawwire-form-group input,
.rawwire-form-group select,
.rawwire-form-group textarea {
    width: 100%;
    padding: var(--rawwire-spacing-sm);
    background: var(--rawwire-surface);
    border: 1px solid var(--rawwire-border);
    border-radius: var(--rawwire-radius-md);
    color: var(--rawwire-text);
    font-size: 0.9em;
}

.rawwire-form-group input:focus,
.rawwire-form-group select:focus,
.rawwire-form-group textarea:focus {
    outline: none;
    border-color: var(--rawwire-primary);
}

.rawwire-form-description {
    font-size: 0.8em;
    color: var(--rawwire-text-muted);
    margin-top: var(--rawwire-spacing-xs);
}
';

            // Add panel-specific CSS from template
            foreach (self::get_panels() as $panel_id => $panel) {
                if (isset($panel['css']) && is_array($panel['css'])) {
                    $panel_css = array();
                    foreach ($panel['css'] as $prop => $value) {
                        $panel_css[] = self::camel_to_kebab($prop) . ': ' . $value . ';';
                    }
                    if (!empty($panel_css)) {
                        $css_rules[] = sprintf(
                            '.rawwire-panel[data-panel="%s"] { %s }',
                            esc_attr($panel_id),
                            implode(' ', $panel_css)
                        );
                    }
                }
            }

            return implode(PHP_EOL . PHP_EOL, $css_rules);
        }

        /**
         * Enqueue template CSS
         */
        public static function enqueue_template_css($hook) {
            if (strpos($hook, 'raw-wire') === false) {
                return;
            }

            // Generate inline CSS
            $css = self::generate_css();

            wp_register_style('rawwire-template', false);
            wp_enqueue_style('rawwire-template');
            wp_add_inline_style('rawwire-template', $css);
        }

        /**
         * List available templates
         * @return array
         */
        public static function list_templates() {
            $templates = array();

            if (!is_dir(self::$template_dir)) {
                return $templates;
            }

            $files = glob(self::$template_dir . '/*.template.json');

            foreach ($files as $file) {
                $json = file_get_contents($file);
                $template = json_decode($json, true);

                if ($template && isset($template['meta'])) {
                    $templates[] = array(
                        'id' => $template['meta']['id'] ?? basename($file, '.template.json'),
                        'name' => $template['meta']['name'] ?? 'Unknown',
                        'version' => $template['meta']['version'] ?? '0.0.0',
                        'description' => $template['meta']['description'] ?? '',
                        'icon' => $template['meta']['icon'] ?? 'dashicons-admin-generic',
                        'variants' => $template['meta']['variants'] ?? array('default'),
                        'file' => basename($file),
                    );
                }
            }

            return $templates;
        }

        /**
         * Switch to a different template
         * @param string $template_id
         * @param bool $backup_data Whether to backup current data before switching
         * @return bool|WP_Error
         */
        public static function switch_template($template_id, $backup_data = true) {
            $template_path = self::$template_dir . '/' . $template_id . '.template.json';

            if (!file_exists($template_path)) {
                return new WP_Error('template_not_found', 'Template file not found');
            }

            // Backup current data if requested
            if ($backup_data) {
                self::export_template_data();
            }

            // Update active template
            update_option('rawwire_active_template', $template_id);
            update_option('rawwire_template_variant', 'default');

            // Clear cached template
            self::$template = null;

            // Reload template
            self::load_active_template();

            self::log('Template switched', 'info', array('template' => $template_id));

            return true;
        }

        /**
         * Export template data to a downloadable format
         * @return array
         */
        public static function export_template_data() {
            global $wpdb;

            $data_config = self::get_data_config();
            $export_data = array(
                'template_id' => self::$template['meta']['id'] ?? 'unknown',
                'exported_at' => current_time('mysql'),
                'tables' => array(),
            );

            if (isset($data_config['tables'])) {
                foreach ($data_config['tables'] as $table_name => $config) {
                    $full_table_name = $wpdb->prefix . 'rawwire_' . $table_name;

                    if ($wpdb->get_var("SHOW TABLES LIKE '$full_table_name'") === $full_table_name) {
                        $rows = $wpdb->get_results("SELECT * FROM $full_table_name", ARRAY_A);
                        $export_data['tables'][$table_name] = $rows;
                    }
                }
            }

            // Also export template settings
            $export_data['settings'] = get_option('rawwire_template_settings_' . ($self::$template['meta']['id'] ?? 'unknown'), array());

            return $export_data;
        }

        /**
         * Ensure database tables exist
         */
        public static function ensure_tables() {
            global $wpdb;

            $data_config = self::get_data_config();

            if (!isset($data_config['tables'])) {
                return;
            }

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            $charset_collate = $wpdb->get_charset_collate();

            foreach ($data_config['tables'] as $table_name => $config) {
                $full_table_name = $wpdb->prefix . 'rawwire_' . $table_name;
                $columns = array();

                foreach ($config['schema'] as $col_name => $col_def) {
                    $columns[] = self::parse_column_definition($col_name, $col_def);
                }

                $sql = "CREATE TABLE $full_table_name (" . PHP_EOL;
                $sql .= implode(',' . PHP_EOL, $columns);
                $sql .= PHP_EOL . ") $charset_collate;";

                dbDelta($sql);
            }
        }

        /**
         * Parse a column definition from template format to SQL
         */
        protected static function parse_column_definition($name, $definition) {
            $parts = explode(':', $definition);
            $type = $parts[0];
            $modifiers = array_slice($parts, 1);

            // Map types
            $type_map = array(
                'bigint' => 'BIGINT(20)',
                'int' => 'INT(11)',
                'varchar' => 'VARCHAR(255)',
                'text' => 'TEXT',
                'longtext' => 'LONGTEXT',
                'datetime' => 'DATETIME',
                'decimal' => 'DECIMAL(10,2)',
            );

            // Handle varchar with length
            if (preg_match('/varchar\((\d+)\)/', $type, $matches)) {
                $sql_type = 'VARCHAR(' . $matches[1] . ')';
            } elseif (preg_match('/decimal\((\d+),(\d+)\)/', $type, $matches)) {
                $sql_type = 'DECIMAL(' . $matches[1] . ',' . $matches[2] . ')';
            } else {
                $sql_type = $type_map[$type] ?? 'VARCHAR(255)';
            }

            $sql = "`$name` $sql_type";

            // Process modifiers
            foreach ($modifiers as $mod) {
                if ($mod === 'primary') {
                    $sql .= ' NOT NULL AUTO_INCREMENT PRIMARY KEY';
                } elseif ($mod === 'index') {
                    // Handle separately
                } elseif (strpos($mod, 'default=') === 0) {
                    $default = substr($mod, 8);
                    if ($default === 'CURRENT_TIMESTAMP') {
                        $sql .= ' DEFAULT CURRENT_TIMESTAMP';
                    } else {
                        $sql .= " DEFAULT '$default'";
                    }
                }
            }

            return $sql;
        }

        /**
         * AJAX: Switch template
         */
        public static function ajax_switch_template() {
            check_ajax_referer('rawwire_admin_nonce', 'nonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error('Unauthorized');
            }

            $template_id = sanitize_text_field($_POST['template_id'] ?? '');
            $backup = isset($_POST['backup']) && $_POST['backup'] === 'true';

            if (empty($template_id)) {
                wp_send_json_error('Template ID required');
            }

            $result = self::switch_template($template_id, $backup);

            if (is_wp_error($result)) {
                wp_send_json_error($result->get_error_message());
            }

            wp_send_json_success(array(
                'message' => 'Template switched successfully',
                'template' => self::get_meta(),
            ));
        }

        /**
         * AJAX: List available templates
         */
        public static function ajax_list_templates() {
            check_ajax_referer('rawwire_admin_nonce', 'nonce');

            $templates = self::list_templates();
            $active = get_option('rawwire_active_template', 'news-aggregator');

            wp_send_json_success(array(
                'templates' => $templates,
                'active' => $active,
            ));
        }

        /**
         * AJAX: Export template data
         */
        public static function ajax_export_data() {
            check_ajax_referer('rawwire_admin_nonce', 'nonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error('Unauthorized');
            }

            $data = self::export_template_data();
            $format = sanitize_text_field($_POST['format'] ?? 'json');

            if ($format === 'csv') {
                // Convert to CSV format
                // Implementation left for specific needs
            }

            wp_send_json_success(array(
                'data' => $data,
                'format' => $format,
            ));
        }

        /**
         * AJAX: Switch variant
         */
        public static function ajax_switch_variant() {
            check_ajax_referer('rawwire_admin_nonce', 'nonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error('Unauthorized');
            }

            $variant = sanitize_text_field($_POST['variant'] ?? 'default');
            $meta = self::get_meta();
            $valid_variants = $meta['variants'] ?? array('default');

            if (!in_array($variant, $valid_variants)) {
                wp_send_json_error('Invalid variant');
            }

            update_option('rawwire_template_variant', $variant);
            self::$current_variant = $variant;

            wp_send_json_success(array(
                'variant' => $variant,
                'css' => self::generate_css(),
            ));
        }

        /**
         * AJAX: Save template settings
         */
        public static function ajax_save_settings() {
            check_ajax_referer('rawwire_admin_nonce', 'nonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error('Unauthorized');
            }

            $settings = isset($_POST['settings']) ? json_decode(stripslashes($_POST['settings']), true) : array();
            $template_id = self::$template['meta']['id'] ?? 'unknown';

            update_option('rawwire_template_settings_' . $template_id, $settings);

            wp_send_json_success(array(
                'message' => 'Settings saved',
            ));
        }

        /**
         * Register REST routes
         */
        public static function register_rest_routes() {
            register_rest_route('rawwire/v1', '/template', array(
                'methods' => 'GET',
                'callback' => array(__CLASS__, 'rest_get_template'),
                'permission_callback' => function() {
                    return current_user_can('manage_options');
                },
            ));

            register_rest_route('rawwire/v1', '/template/panels', array(
                'methods' => 'GET',
                'callback' => array(__CLASS__, 'rest_get_panels'),
                'permission_callback' => function() {
                    return current_user_can('manage_options');
                },
            ));
        }

        /**
         * REST: Get template configuration
         */
        public static function rest_get_template(WP_REST_Request $request) {
            return rest_ensure_response(array(
                'meta' => self::get_meta(),
                'pages' => self::get_pages(),
                'toolbox' => self::get_toolbox(),
                'sources' => self::get_sources(),
                'variant' => self::$current_variant,
            ));
        }

        /**
         * REST: Get panels configuration
         */
        public static function rest_get_panels(WP_REST_Request $request) {
            $page_id = $request->get_param('page');
            $panels = self::get_panels();

            if ($page_id) {
                $page = self::get_page($page_id);
                if ($page && isset($page['panels'])) {
                    $filtered = array();
                    foreach ($page['panels'] as $panel_id) {
                        if (isset($panels[$panel_id])) {
                            $filtered[$panel_id] = $panels[$panel_id];
                        }
                    }
                    return rest_ensure_response($filtered);
                }
            }

            return rest_ensure_response($panels);
        }

        /**
         * Helper: Convert camelCase to kebab-case
         */
        protected static function camel_to_kebab($str) {
            return strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $str));
        }

        /**
         * Helper: Deep merge arrays (distinct)
         */
        protected static function array_merge_recursive_distinct(array $array1, array $array2) {
            $merged = $array1;

            foreach ($array2 as $key => $value) {
                if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                    $merged[$key] = self::array_merge_recursive_distinct($merged[$key], $value);
                } else {
                    $merged[$key] = $value;
                }
            }

            return $merged;
        }
    }
}
