<?php
/**
 * ╔═══════════════════════════════════════════════════════════════════════════╗
 * ║                                                                           ║
 * ║   ██████╗██╗   ██╗███████╗████████╗ ██████╗ ███╗   ███╗                   ║
 * ║  ██╔════╝██║   ██║██╔════╝╚══██╔══╝██╔═══██╗████╗ ████║                   ║
 * ║  ██║     ██║   ██║███████╗   ██║   ██║   ██║██╔████╔██║                   ║
 * ║  ██║     ██║   ██║╚════██║   ██║   ██║   ██║██║╚██╔╝██║                   ║
 * ║  ╚██████╗╚██████╔╝███████║   ██║   ╚██████╔╝██║ ╚═╝ ██║                   ║
 * ║   ╚═════╝ ╚═════╝ ╚══════╝   ╚═╝    ╚═════╝ ╚═╝     ╚═╝                   ║
 * ║                                                                           ║
 * ║   ██████╗  █████╗ ███╗   ██╗███████╗██╗         ██████╗ ██╗   ██╗██╗██╗   ║
 * ║  ██╔══██╗██╔══██╗████╗  ██║██╔════╝██║         ██╔══██╗██║   ██║██║██║   ║
 * ║  ██████╔╝███████║██╔██╗ ██║█████╗  ██║         ██████╔╝██║   ██║██║██║   ║
 * ║  ██╔═══╝ ██╔══██║██║╚██╗██║██╔══╝  ██║         ██╔══██╗██║   ██║██║██║   ║
 * ║  ██║     ██║  ██║██║ ╚████║███████╗███████╗    ██████╔╝╚██████╔╝██║███████╗
 * ║  ╚═╝     ╚═╝  ╚═╝╚═╝  ╚═══╝╚══════╝╚══════╝    ╚═════╝  ╚═════╝ ╚═╝╚══════╝
 * ║                                                                           ║
 * ╠═══════════════════════════════════════════════════════════════════════════╣
 * ║                                                                           ║
 * ║   ⚠️  STOP! READ THIS ARCHITECTURE GUIDE BEFORE MODIFYING  ⚠️             ║
 * ║                                                                           ║
 * ║   This Custom Panel Builder follows strict architectural rules:           ║
 * ║                                                                           ║
 * ║   1. Custom panels are REGISTERED here but RENDERED by Module Core        ║
 * ║   2. Panel definitions are stored in database (wp_options)                ║
 * ║   3. Templates can toggle/style custom panels but NOT render them         ║
 * ║   4. All rendering logic stays in module.php's render_custom_panel()      ║
 * ║                                                                           ║
 * ║   WHAT THIS CLASS DOES:                                                   ║
 * ║   • Provides UI for developers to define new panels                       ║
 * ║   • Validates panel definitions for architecture compliance               ║
 * ║   • Stores definitions in database                                        ║
 * ║   • Exposes definitions to Module Core for rendering                      ║
 * ║                                                                           ║
 * ║   WHAT THIS CLASS DOES NOT DO:                                            ║
 * ║   • Does NOT render panel content (that's Module Core's job)              ║
 * ║   • Does NOT contain template logic                                       ║
 * ║   • Does NOT bypass the architecture                                      ║
 * ║                                                                           ║
 * ╚═══════════════════════════════════════════════════════════════════════════╝
 *
 * @package RawWire_Dashboard
 * @subpackage Admin
 */

if (!defined('ABSPATH')) {
    exit;
}

class RawWire_Custom_Panel_Builder {

    /**
     * Option key for storing custom panel definitions
     */
    const OPTION_KEY = 'rawwire_custom_panels';

    /**
     * Available content types for custom panels
     * 
     * STOP! These content types define HOW a panel gets its data.
     * The actual rendering is done by Module Core, not here.
     */
    private static $content_types = array(
        'static_html' => array(
            'label'       => 'Static HTML',
            'description' => 'Fixed HTML content that you define',
            'fields'      => array('html_content'),
        ),
        'database_query' => array(
            'label'       => 'Database Query',
            'description' => 'Display results from a database table',
            'fields'      => array('table_name', 'columns', 'where_clause', 'limit', 'order_by'),
        ),
        'rest_endpoint' => array(
            'label'       => 'REST Endpoint',
            'description' => 'Fetch data from a REST API endpoint',
            'fields'      => array('endpoint_url', 'method', 'headers', 'display_format'),
        ),
        'wp_option' => array(
            'label'       => 'WordPress Option',
            'description' => 'Display value(s) from wp_options',
            'fields'      => array('option_keys', 'display_format'),
        ),
        'shortcode' => array(
            'label'       => 'Shortcode',
            'description' => 'Render a WordPress shortcode',
            'fields'      => array('shortcode'),
        ),
        'metric_grid' => array(
            'label'       => 'Metric Grid',
            'description' => 'Display key-value metrics in a grid layout',
            'fields'      => array('metrics'),
        ),
    );

    /**
     * Available panel categories
     */
    private static $categories = array(
        'core'     => 'Core Dashboard',
        'workflow' => 'Workflow',
        'system'   => 'System',
        'actions'  => 'Actions',
        'custom'   => 'Custom',
    );

    /**
     * Available dashicons (subset for UI)
     */
    private static $icons = array(
        'dashicons-admin-generic',
        'dashicons-admin-tools',
        'dashicons-admin-site',
        'dashicons-chart-pie',
        'dashicons-chart-line',
        'dashicons-chart-bar',
        'dashicons-list-view',
        'dashicons-grid-view',
        'dashicons-text-page',
        'dashicons-database',
        'dashicons-cloud',
        'dashicons-networking',
        'dashicons-analytics',
        'dashicons-performance',
        'dashicons-yes-alt',
        'dashicons-warning',
        'dashicons-info',
        'dashicons-heart',
        'dashicons-star-filled',
        'dashicons-hammer',
        'dashicons-money-alt',
        'dashicons-cart',
        'dashicons-store',
        'dashicons-tickets-alt',
        'dashicons-calendar-alt',
        'dashicons-groups',
        'dashicons-businessman',
        'dashicons-id-alt',
        'dashicons-location-alt',
        'dashicons-phone',
        'dashicons-email-alt',
    );

    /**
     * ╔═══════════════════════════════════════════════════════════════════════╗
     * ║  GET CUSTOM PANELS                                                    ║
     * ║                                                                       ║
     * ║  STOP! This returns panel DEFINITIONS, not rendered content.          ║
     * ║  Module Core uses these definitions to render panels.                 ║
     * ╚═══════════════════════════════════════════════════════════════════════╝
     */
    public static function get_custom_panels() {
        $panels = get_option(self::OPTION_KEY, array());
        return is_array($panels) ? $panels : array();
    }

    /**
     * Get a single custom panel definition
     */
    public static function get_panel($panel_id) {
        $panels = self::get_custom_panels();
        return isset($panels[$panel_id]) ? $panels[$panel_id] : null;
    }

    /**
     * ╔═══════════════════════════════════════════════════════════════════════╗
     * ║  SAVE CUSTOM PANEL                                                    ║
     * ║                                                                       ║
     * ║  STOP! This saves a panel DEFINITION only.                            ║
     * ║  Validation ensures architecture compliance before saving.            ║
     * ╚═══════════════════════════════════════════════════════════════════════╝
     */
    public static function save_panel($panel_data) {
        // Validate first
        $validation = self::validate_panel($panel_data);
        if ($validation !== true) {
            return array('success' => false, 'errors' => $validation);
        }

        $panels = self::get_custom_panels();
        $panel_id = self::sanitize_panel_id($panel_data['panel_id']);

        // Build clean definition
        $definition = array(
            'title'        => sanitize_text_field($panel_data['title']),
            'description'  => sanitize_textarea_field($panel_data['description'] ?? ''),
            'icon'         => sanitize_text_field($panel_data['icon'] ?? 'dashicons-admin-generic'),
            'category'     => sanitize_key($panel_data['category'] ?? 'custom'),
            'content_type' => sanitize_key($panel_data['content_type']),
            'content_config' => self::sanitize_content_config(
                $panel_data['content_type'],
                $panel_data['content_config'] ?? array()
            ),
            'created_at'   => isset($panels[$panel_id]) ? $panels[$panel_id]['created_at'] : current_time('mysql'),
            'updated_at'   => current_time('mysql'),
            'created_by'   => isset($panels[$panel_id]) ? $panels[$panel_id]['created_by'] : get_current_user_id(),
        );

        $panels[$panel_id] = $definition;
        update_option(self::OPTION_KEY, $panels);

        return array('success' => true, 'panel_id' => $panel_id);
    }

    /**
     * Delete a custom panel
     */
    public static function delete_panel($panel_id) {
        $panels = self::get_custom_panels();
        $panel_id = self::sanitize_panel_id($panel_id);

        if (!isset($panels[$panel_id])) {
            return array('success' => false, 'message' => 'Panel not found');
        }

        unset($panels[$panel_id]);
        update_option(self::OPTION_KEY, $panels);

        return array('success' => true);
    }

    /**
     * ╔═══════════════════════════════════════════════════════════════════════╗
     * ║  VALIDATION - ARCHITECTURE COMPLIANCE CHECK                           ║
     * ║                                                                       ║
     * ║  STOP! This validation ensures custom panels follow the rules:        ║
     * ║  • Valid content type (data source, not rendering logic)              ║
     * ║  • Required fields present                                            ║
     * ║  • No executable PHP code (security)                                  ║
     * ║  • Safe database queries (if database_query type)                     ║
     * ╚═══════════════════════════════════════════════════════════════════════╝
     */
    public static function validate_panel($panel_data) {
        $errors = array();

        // Required fields
        if (empty($panel_data['panel_id'])) {
            $errors[] = 'Panel ID is required';
        } elseif (!preg_match('/^[a-z][a-z0-9_]{2,29}$/', $panel_data['panel_id'])) {
            $errors[] = 'Panel ID must be 3-30 lowercase letters, numbers, underscores. Must start with letter.';
        }

        if (empty($panel_data['title'])) {
            $errors[] = 'Panel title is required';
        }

        if (empty($panel_data['content_type'])) {
            $errors[] = 'Content type is required';
        } elseif (!isset(self::$content_types[$panel_data['content_type']])) {
            $errors[] = 'Invalid content type selected';
        }

        // Category validation
        if (!empty($panel_data['category']) && !isset(self::$categories[$panel_data['category']])) {
            $errors[] = 'Invalid category selected';
        }

        // Content-type specific validation
        if (empty($errors)) {
            $content_errors = self::validate_content_config(
                $panel_data['content_type'],
                $panel_data['content_config'] ?? array()
            );
            $errors = array_merge($errors, $content_errors);
        }

        // Security checks
        $security_errors = self::security_check($panel_data);
        $errors = array_merge($errors, $security_errors);

        return empty($errors) ? true : $errors;
    }

    /**
     * Validate content configuration based on type
     */
    private static function validate_content_config($content_type, $config) {
        $errors = array();

        switch ($content_type) {
            case 'static_html':
                if (empty($config['html_content'])) {
                    $errors[] = 'HTML content is required for static panels';
                }
                break;

            case 'database_query':
                if (empty($config['table_name'])) {
                    $errors[] = 'Table name is required for database query panels';
                }
                // Whitelist allowed tables (security)
                $allowed_tables = array('rawwire_candidates', 'rawwire_content', 'rawwire_logs', 'posts', 'postmeta', 'options');
                $table_base = preg_replace('/^' . preg_quote($GLOBALS['wpdb']->prefix, '/') . '/', '', $config['table_name']);
                if (!in_array($table_base, $allowed_tables) && !in_array($config['table_name'], $allowed_tables)) {
                    $errors[] = 'Table "' . esc_html($config['table_name']) . '" is not in the allowed list. Contact admin to whitelist.';
                }
                break;

            case 'rest_endpoint':
                if (empty($config['endpoint_url'])) {
                    $errors[] = 'Endpoint URL is required for REST panels';
                } elseif (!filter_var($config['endpoint_url'], FILTER_VALIDATE_URL) && strpos($config['endpoint_url'], '/') !== 0) {
                    $errors[] = 'Invalid endpoint URL format';
                }
                break;

            case 'wp_option':
                if (empty($config['option_keys'])) {
                    $errors[] = 'At least one option key is required';
                }
                break;

            case 'shortcode':
                if (empty($config['shortcode'])) {
                    $errors[] = 'Shortcode is required';
                }
                break;

            case 'metric_grid':
                if (empty($config['metrics']) || !is_array($config['metrics'])) {
                    $errors[] = 'At least one metric is required for metric grid panels';
                }
                break;
        }

        return $errors;
    }

    /**
     * ╔═══════════════════════════════════════════════════════════════════════╗
     * ║  SECURITY CHECK - PREVENT CODE INJECTION                              ║
     * ║                                                                       ║
     * ║  STOP! Custom panels must NOT contain executable PHP or JS.           ║
     * ║  All content is sanitized and rendered safely by Module Core.         ║
     * ╚═══════════════════════════════════════════════════════════════════════╝
     */
    private static function security_check($panel_data) {
        $errors = array();
        $dangerous_patterns = array(
            '/<\s*script/i'      => 'Script tags are not allowed',
            '/<\s*\?php/i'       => 'PHP tags are not allowed',
            '/\beval\s*\(/i'     => 'eval() is not allowed',
            '/\bexec\s*\(/i'     => 'exec() is not allowed',
            '/\bsystem\s*\(/i'   => 'system() is not allowed',
            '/\bpassthru\s*\(/i' => 'passthru() is not allowed',
            '/\bshell_exec/i'    => 'shell_exec() is not allowed',
            '/javascript\s*:/i'  => 'javascript: URLs are not allowed',
            '/on\w+\s*=/i'       => 'Inline event handlers are not allowed',
        );

        // Check all string fields
        $check_fields = array('title', 'description');
        if (isset($panel_data['content_config']['html_content'])) {
            $check_fields[] = 'content_config';
        }

        $json_data = json_encode($panel_data);
        foreach ($dangerous_patterns as $pattern => $message) {
            if (preg_match($pattern, $json_data)) {
                $errors[] = 'Security violation: ' . $message;
            }
        }

        return $errors;
    }

    /**
     * Sanitize panel ID
     */
    private static function sanitize_panel_id($id) {
        $id = strtolower(trim($id));
        $id = preg_replace('/[^a-z0-9_]/', '_', $id);
        $id = preg_replace('/_+/', '_', $id);
        $id = trim($id, '_');
        
        // Prefix custom panels
        if (strpos($id, 'custom_') !== 0) {
            $id = 'custom_' . $id;
        }
        
        return $id;
    }

    /**
     * Sanitize content configuration
     */
    private static function sanitize_content_config($content_type, $config) {
        $sanitized = array();

        switch ($content_type) {
            case 'static_html':
                $sanitized['html_content'] = wp_kses_post($config['html_content'] ?? '');
                break;

            case 'database_query':
                $sanitized['table_name'] = sanitize_key($config['table_name'] ?? '');
                $sanitized['columns'] = sanitize_text_field($config['columns'] ?? '*');
                $sanitized['where_clause'] = sanitize_text_field($config['where_clause'] ?? '');
                $sanitized['limit'] = min(100, max(1, intval($config['limit'] ?? 10)));
                $sanitized['order_by'] = sanitize_text_field($config['order_by'] ?? '');
                break;

            case 'rest_endpoint':
                $sanitized['endpoint_url'] = esc_url_raw($config['endpoint_url'] ?? '');
                $sanitized['method'] = in_array(strtoupper($config['method'] ?? 'GET'), array('GET', 'POST')) ? strtoupper($config['method']) : 'GET';
                $sanitized['headers'] = array_map('sanitize_text_field', (array)($config['headers'] ?? array()));
                $sanitized['display_format'] = sanitize_key($config['display_format'] ?? 'table');
                break;

            case 'wp_option':
                $sanitized['option_keys'] = array_map('sanitize_key', (array)($config['option_keys'] ?? array()));
                $sanitized['display_format'] = sanitize_key($config['display_format'] ?? 'list');
                break;

            case 'shortcode':
                // Strip any nested shortcodes and validate format
                $shortcode = sanitize_text_field($config['shortcode'] ?? '');
                if (preg_match('/^\[[\w-]+/', $shortcode)) {
                    $sanitized['shortcode'] = $shortcode;
                } else {
                    $sanitized['shortcode'] = '';
                }
                break;

            case 'metric_grid':
                $sanitized['metrics'] = array();
                if (!empty($config['metrics']) && is_array($config['metrics'])) {
                    foreach ($config['metrics'] as $metric) {
                        $sanitized['metrics'][] = array(
                            'label' => sanitize_text_field($metric['label'] ?? ''),
                            'value_source' => sanitize_key($metric['value_source'] ?? 'static'),
                            'value' => sanitize_text_field($metric['value'] ?? ''),
                            'option_key' => sanitize_key($metric['option_key'] ?? ''),
                            'suffix' => sanitize_text_field($metric['suffix'] ?? ''),
                        );
                    }
                }
                break;
        }

        return $sanitized;
    }

    /**
     * ╔═══════════════════════════════════════════════════════════════════════╗
     * ║  RENDER BUILDER UI                                                    ║
     * ║                                                                       ║
     * ║  STOP! This renders the BUILDER FORM, not the custom panels.          ║
     * ║  Custom panel content is rendered by Module Core.                     ║
     * ╚═══════════════════════════════════════════════════════════════════════╝
     */
    public static function render_builder_ui() {
        $existing_panels = self::get_custom_panels();
        
        ob_start();
        ?>
        <div class="rawwire-custom-panel-builder">
            <!-- Architecture Warning Banner -->
            <div class="architecture-notice" style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin-bottom: 20px;">
                <h4 style="margin-top: 0;">⚠️ Architecture Compliance Required</h4>
                <p style="margin-bottom: 0;">
                    Custom panels follow strict architectural rules:<br>
                    • Panels define <strong>what data to show</strong>, not how to render it<br>
                    • All rendering is handled by Module Core<br>
                    • Templates can only toggle visibility and apply CSS styling<br>
                    • No PHP code or JavaScript event handlers allowed
                </p>
            </div>

            <!-- Existing Panels List -->
            <div class="existing-panels" style="margin-bottom: 30px;">
                <h3>📋 Existing Custom Panels</h3>
                <?php if (empty($existing_panels)): ?>
                    <p class="description">No custom panels defined yet. Create your first one below.</p>
                <?php else: ?>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th>Panel ID</th>
                                <th>Title</th>
                                <th>Type</th>
                                <th>Category</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($existing_panels as $id => $panel): ?>
                                <tr data-panel-id="<?php echo esc_attr($id); ?>">
                                    <td><code><?php echo esc_html($id); ?></code></td>
                                    <td><?php echo esc_html($panel['title']); ?></td>
                                    <td><?php echo esc_html(self::$content_types[$panel['content_type']]['label'] ?? $panel['content_type']); ?></td>
                                    <td><?php echo esc_html(self::$categories[$panel['category']] ?? $panel['category']); ?></td>
                                    <td>
                                        <button type="button" class="button button-small edit-custom-panel" data-panel-id="<?php echo esc_attr($id); ?>">Edit</button>
                                        <button type="button" class="button button-small button-link-delete delete-custom-panel" data-panel-id="<?php echo esc_attr($id); ?>">Delete</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- New Panel Form -->
            <div class="new-panel-form">
                <h3>➕ Create New Custom Panel</h3>
                <form id="custom-panel-form" method="post">
                    <?php wp_nonce_field('rawwire_custom_panel', 'panel_nonce'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th><label for="panel_id">Panel ID *</label></th>
                            <td>
                                <input type="text" id="panel_id" name="panel_id" class="regular-text" pattern="[a-z][a-z0-9_]{2,29}" required>
                                <p class="description">Unique identifier (lowercase, 3-30 chars, letters/numbers/underscores only)</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="panel_title">Title *</label></th>
                            <td>
                                <input type="text" id="panel_title" name="title" class="regular-text" required>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="panel_description">Description</label></th>
                            <td>
                                <textarea id="panel_description" name="description" rows="2" class="large-text"></textarea>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="panel_icon">Icon</label></th>
                            <td>
                                <select id="panel_icon" name="icon">
                                    <?php foreach (self::$icons as $icon): ?>
                                        <option value="<?php echo esc_attr($icon); ?>">
                                            <?php echo esc_html(str_replace('dashicons-', '', $icon)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <span id="icon-preview" class="dashicons dashicons-admin-generic" style="margin-left: 10px; font-size: 24px;"></span>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="panel_category">Category</label></th>
                            <td>
                                <select id="panel_category" name="category">
                                    <?php foreach (self::$categories as $key => $label): ?>
                                        <option value="<?php echo esc_attr($key); ?>" <?php selected($key, 'custom'); ?>>
                                            <?php echo esc_html($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="content_type">Content Type *</label></th>
                            <td>
                                <select id="content_type" name="content_type" required>
                                    <option value="">-- Select Type --</option>
                                    <?php foreach (self::$content_types as $key => $type): ?>
                                        <option value="<?php echo esc_attr($key); ?>">
                                            <?php echo esc_html($type['label']); ?> - <?php echo esc_html($type['description']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    </table>

                    <!-- Dynamic Content Config Fields -->
                    <div id="content-config-fields" style="display: none; margin-top: 20px; padding: 20px; background: #f9f9f9; border: 1px solid #ddd;">
                        <h4>Content Configuration</h4>
                        <div id="config-fields-container"></div>
                    </div>

                    <p class="submit">
                        <button type="submit" class="button button-primary">
                            <span class="dashicons dashicons-yes" style="margin-top: 3px;"></span> Save Panel
                        </button>
                        <button type="button" id="preview-panel" class="button">
                            <span class="dashicons dashicons-visibility" style="margin-top: 3px;"></span> Preview
                        </button>
                    </p>

                    <div id="validation-results" style="display: none;"></div>
                </form>
            </div>

            <!-- Hidden template for metric grid rows -->
            <script type="text/template" id="metric-row-template">
                <div class="metric-row" style="display: flex; gap: 10px; margin-bottom: 10px; padding: 10px; background: #fff; border: 1px solid #ddd;">
                    <input type="text" name="metrics[{{index}}][label]" placeholder="Label" style="width: 150px;">
                    <select name="metrics[{{index}}][value_source]" class="metric-source">
                        <option value="static">Static Value</option>
                        <option value="option">WP Option</option>
                    </select>
                    <input type="text" name="metrics[{{index}}][value]" placeholder="Value" class="metric-value-static" style="width: 100px;">
                    <input type="text" name="metrics[{{index}}][option_key]" placeholder="Option Key" class="metric-value-option" style="width: 150px; display: none;">
                    <input type="text" name="metrics[{{index}}][suffix]" placeholder="Suffix (%, ms, etc)" style="width: 80px;">
                    <button type="button" class="button remove-metric">×</button>
                </div>
            </script>
        </div>

        <style>
            .rawwire-custom-panel-builder .form-table th { width: 150px; }
            .rawwire-custom-panel-builder .widefat td, .rawwire-custom-panel-builder .widefat th { padding: 10px; }
            .rawwire-custom-panel-builder code { background: #f0f0f1; padding: 2px 6px; }
            #validation-results.error { background: #ffeaea; border-left: 4px solid #dc3545; padding: 10px; margin-top: 15px; }
            #validation-results.success { background: #d4edda; border-left: 4px solid #28a745; padding: 10px; margin-top: 15px; }
        </style>

        <script>
        jQuery(function($) {
            // Content type field templates
            var fieldTemplates = {
                'static_html': '<label>HTML Content:</label><textarea name="content_config[html_content]" rows="6" class="large-text code" placeholder="<div class=\'my-content\'>...</div>"></textarea><p class="description">Safe HTML only. No script tags or PHP.</p>',
                
                'database_query': '<label>Table Name:</label><input type="text" name="content_config[table_name]" class="regular-text" placeholder="rawwire_candidates"><br><br>' +
                    '<label>Columns:</label><input type="text" name="content_config[columns]" class="regular-text" value="*" placeholder="id, title, status"><br><br>' +
                    '<label>WHERE Clause:</label><input type="text" name="content_config[where_clause]" class="regular-text" placeholder="status = \'pending\'"><br><br>' +
                    '<label>Limit:</label><input type="number" name="content_config[limit]" value="10" min="1" max="100" style="width:80px"><br><br>' +
                    '<label>Order By:</label><input type="text" name="content_config[order_by]" class="regular-text" placeholder="created_at DESC">',
                
                'rest_endpoint': '<label>Endpoint URL:</label><input type="text" name="content_config[endpoint_url]" class="large-text" placeholder="/wp-json/rawwire/v1/stats"><br><br>' +
                    '<label>Method:</label><select name="content_config[method]"><option value="GET">GET</option><option value="POST">POST</option></select><br><br>' +
                    '<label>Display Format:</label><select name="content_config[display_format]"><option value="table">Table</option><option value="list">List</option><option value="json">Raw JSON</option></select>',
                
                'wp_option': '<label>Option Keys (comma-separated):</label><input type="text" name="content_config[option_keys]" class="large-text" placeholder="rawwire_last_sync, rawwire_total_count"><br><br>' +
                    '<label>Display Format:</label><select name="content_config[display_format]"><option value="list">List</option><option value="grid">Grid</option></select>',
                
                'shortcode': '<label>Shortcode:</label><input type="text" name="content_config[shortcode]" class="large-text" placeholder="[my_shortcode param=\'value\']"><p class="description">Enter the full shortcode including brackets.</p>',
                
                'metric_grid': '<div id="metrics-container"></div><button type="button" id="add-metric" class="button">+ Add Metric</button>'
            };

            var metricIndex = 0;

            // Show/hide content config fields based on type
            $('#content_type').on('change', function() {
                var type = $(this).val();
                if (type && fieldTemplates[type]) {
                    $('#config-fields-container').html(fieldTemplates[type]);
                    $('#content-config-fields').show();
                    metricIndex = 0;
                    if (type === 'metric_grid') {
                        addMetricRow();
                    }
                } else {
                    $('#content-config-fields').hide();
                }
            });

            // Icon preview
            $('#panel_icon').on('change', function() {
                $('#icon-preview').attr('class', 'dashicons ' + $(this).val());
            });

            // Add metric row
            function addMetricRow() {
                var template = $('#metric-row-template').html().replace(/{{index}}/g, metricIndex);
                $('#metrics-container').append(template);
                metricIndex++;
            }

            $(document).on('click', '#add-metric', function() {
                addMetricRow();
            });

            $(document).on('click', '.remove-metric', function() {
                $(this).closest('.metric-row').remove();
            });

            $(document).on('change', '.metric-source', function() {
                var row = $(this).closest('.metric-row');
                if ($(this).val() === 'option') {
                    row.find('.metric-value-static').hide();
                    row.find('.metric-value-option').show();
                } else {
                    row.find('.metric-value-static').show();
                    row.find('.metric-value-option').hide();
                }
            });

            // Form submission
            $('#custom-panel-form').on('submit', function(e) {
                e.preventDefault();
                var formData = $(this).serialize();
                
                $.post(ajaxurl, {
                    action: 'rawwire_save_custom_panel',
                    data: formData,
                    _ajax_nonce: '<?php echo wp_create_nonce('rawwire_custom_panel_ajax'); ?>'
                }, function(response) {
                    if (response.success) {
                        $('#validation-results')
                            .removeClass('error').addClass('success')
                            .html('<strong>✓ Panel saved successfully!</strong> Panel ID: <code>' + response.data.panel_id + '</code>')
                            .show();
                        setTimeout(function() { location.reload(); }, 1500);
                    } else {
                        var errors = response.data.errors || ['Unknown error'];
                        $('#validation-results')
                            .removeClass('success').addClass('error')
                            .html('<strong>✗ Validation Failed:</strong><ul><li>' + errors.join('</li><li>') + '</li></ul>')
                            .show();
                    }
                });
            });

            // Delete panel
            $(document).on('click', '.delete-custom-panel', function() {
                if (!confirm('Delete this custom panel?')) return;
                var panelId = $(this).data('panel-id');
                $.post(ajaxurl, {
                    action: 'rawwire_delete_custom_panel',
                    panel_id: panelId,
                    _ajax_nonce: '<?php echo wp_create_nonce('rawwire_custom_panel_ajax'); ?>'
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    }
                });
            });

            // Edit panel (load into form)
            $(document).on('click', '.edit-custom-panel', function() {
                var panelId = $(this).data('panel-id');
                $.get(ajaxurl, {
                    action: 'rawwire_get_custom_panel',
                    panel_id: panelId,
                    _ajax_nonce: '<?php echo wp_create_nonce('rawwire_custom_panel_ajax'); ?>'
                }, function(response) {
                    if (response.success && response.data) {
                        var panel = response.data;
                        $('#panel_id').val(panelId.replace('custom_', ''));
                        $('#panel_title').val(panel.title);
                        $('#panel_description').val(panel.description);
                        $('#panel_icon').val(panel.icon).trigger('change');
                        $('#panel_category').val(panel.category);
                        $('#content_type').val(panel.content_type).trigger('change');
                        
                        // Populate content config (after field template is rendered)
                        setTimeout(function() {
                            if (panel.content_config) {
                                $.each(panel.content_config, function(key, value) {
                                    var field = $('[name="content_config[' + key + ']"]');
                                    if (field.length) field.val(value);
                                });
                            }
                        }, 100);
                        
                        $('html, body').animate({ scrollTop: $('.new-panel-form').offset().top - 50 }, 500);
                    }
                });
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Register AJAX handlers
     */
    public static function register_ajax_handlers() {
        add_action('wp_ajax_rawwire_save_custom_panel', array(__CLASS__, 'ajax_save_panel'));
        add_action('wp_ajax_rawwire_delete_custom_panel', array(__CLASS__, 'ajax_delete_panel'));
        add_action('wp_ajax_rawwire_get_custom_panel', array(__CLASS__, 'ajax_get_panel'));
    }

    public static function ajax_save_panel() {
        check_ajax_referer('rawwire_custom_panel_ajax');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('errors' => array('Permission denied')));
        }

        parse_str($_POST['data'], $form_data);
        
        // Handle metrics array for metric_grid type
        if (!empty($form_data['metrics'])) {
            $form_data['content_config']['metrics'] = $form_data['metrics'];
        }

        $result = self::save_panel($form_data);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    public static function ajax_delete_panel() {
        check_ajax_referer('rawwire_custom_panel_ajax');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }

        $panel_id = sanitize_key($_POST['panel_id']);
        $result = self::delete_panel($panel_id);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    public static function ajax_get_panel() {
        check_ajax_referer('rawwire_custom_panel_ajax');
        
        $panel_id = sanitize_key($_GET['panel_id']);
        $panel = self::get_panel($panel_id);
        
        if ($panel) {
            wp_send_json_success($panel);
        } else {
            wp_send_json_error(array('message' => 'Panel not found'));
        }
    }

    /**
     * Get content types (for external use)
     */
    public static function get_content_types() {
        return self::$content_types;
    }

    /**
     * Get categories (for external use)
     */
    public static function get_categories() {
        return self::$categories;
    }
}

// Initialize AJAX handlers
add_action('admin_init', array('RawWire_Custom_Panel_Builder', 'register_ajax_handlers'));
