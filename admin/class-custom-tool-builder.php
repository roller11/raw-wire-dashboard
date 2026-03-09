<?php
/**
 * ╔═══════════════════════════════════════════════════════════════════════════╗
 * ║                                                                           ║
 * ║   ██████╗██╗   ██╗███████╗████████╗ ██████╗ ███╗   ███╗                   ║
 * ║  ██╔════╝██║   ██║██╔════╝╚════██╔══╝██╔═══██╗████╗ ████║                   ║
 * ║  ██║     ██║   ██║███████╗   ██║   ██║   ██║██╔████╔██║                   ║
 * ║  ██║     ██║   ██║╚════██║   ██║   ██║   ██║██║╚██╔╝██║                   ║
 * ║  ╚██████╗╚██████╔╝███████║   ██║   ╚██████╔╝██║ ╚═╝ ██║                   ║
 * ║   ╚═════╝ ╚═════╝ ╚══════╝   ╚═╝    ╚═════╝ ╚═╝     ╚═╝                   ║
 * ║                                                                           ║
 * ║  ████████╗ ██████╗  ██████╗ ██╗         ██████╗ ██╗   ██╗██╗██╗          ║
 * ║  ╚══██╔══╝██╔═══██╗██╔═══██╗██║         ██╔══██╗██║   ██║██║██║          ║
 * ║     ██║   ██║   ██║██║   ██║██║         ██████╔╝██║   ██║██║██║          ║
 * ║     ██║   ██║   ██║██║   ██║██║         ██╔══██╗██║   ██║██║██║          ║
 * ║     ██║   ╚██████╔╝╚██████╔╝███████╗    ██████╔╝╚██████╔╝██║███████╗     ║
 * ║     ╚═╝    ╚═════╝  ╚═════╝ ╚══════╝    ╚═════╝  ╚═════╝ ╚═╝╚══════╝     ║
 * ║                                                                           ║
 * ╠═══════════════════════════════════════════════════════════════════════════╣
 * ║                                                                           ║
 * ║   ⚠️  STOP! READ THIS ARCHITECTURE GUIDE BEFORE MODIFYING  ⚠️             ║
 * ║                                                                           ║
 * ║   This Custom Tool Builder follows strict architectural rules:            ║
 * ║                                                                           ║
 * ║   1. Tools are REGISTERED here but EXECUTED by the Toolkit layer          ║
 * ║   2. Tool definitions are stored in database (wp_options)                 ║
 * ║   3. Tools expose functions to Module Core (not bypass it)                ║
 * ║   4. Functions within tools follow the same execution pipeline            ║
 * ║                                                                           ║
 * ║   WHAT THIS CLASS DOES:                                                   ║
 * ║   • Provides UI for developers to define new tools                        ║
 * ║   • Validates tool definitions for architecture compliance                ║
 * ║   • Stores definitions in database                                        ║
 * ║   • Tools become available in the Toolkit Status panel                    ║
 * ║                                                                           ║
 * ║   WHAT THIS CLASS DOES NOT DO:                                            ║
 * ║   • Does NOT execute arbitrary PHP code                                   ║
 * ║   • Does NOT bypass the Module Core → Toolkit → Template hierarchy        ║
 * ║   • Does NOT allow unsafe operations                                      ║
 * ║                                                                           ║
 * ╚═══════════════════════════════════════════════════════════════════════════╝
 *
 * @package RawWire_Dashboard
 * @subpackage Admin
 */

if (!defined('ABSPATH')) {
    exit;
}

class RawWire_Custom_Tool_Builder {

    /**
     * Option key for storing custom tool definitions
     */
    const TOOLS_OPTION_KEY = 'rawwire_custom_tools';
    const FUNCTIONS_OPTION_KEY = 'rawwire_custom_functions';

    /**
     * Available function types (what the function does)
     * 
     * STOP! These function types define WHAT a function can do.
     * They are safe, pre-defined operations - not arbitrary code execution.
     */
    private static $function_types = array(
        'http_request' => array(
            'label'       => 'HTTP Request',
            'description' => 'Make an HTTP request to an external API',
            'fields'      => array('url', 'method', 'headers', 'body_template', 'response_mapping'),
        ),
        'database_query' => array(
            'label'       => 'Database Query',
            'description' => 'Execute a read-only database query',
            'fields'      => array('query_template', 'params', 'output_format'),
        ),
        'database_insert' => array(
            'label'       => 'Database Insert',
            'description' => 'Insert data into an allowed table',
            'fields'      => array('table', 'columns_mapping'),
        ),
        'database_update' => array(
            'label'       => 'Database Update',
            'description' => 'Update data in an allowed table',
            'fields'      => array('table', 'columns_mapping', 'where_template'),
        ),
        'wp_option_read' => array(
            'label'       => 'Read WP Option',
            'description' => 'Read value(s) from WordPress options',
            'fields'      => array('option_keys'),
        ),
        'wp_option_write' => array(
            'label'       => 'Write WP Option',
            'description' => 'Write value(s) to WordPress options',
            'fields'      => array('option_key', 'value_template'),
        ),
        'post_create' => array(
            'label'       => 'Create Post',
            'description' => 'Create a new WordPress post/page/CPT',
            'fields'      => array('post_type', 'field_mapping', 'default_status'),
        ),
        'post_update' => array(
            'label'       => 'Update Post',
            'description' => 'Update an existing WordPress post',
            'fields'      => array('post_type', 'field_mapping', 'identifier_field'),
        ),
        'send_email' => array(
            'label'       => 'Send Email',
            'description' => 'Send an email notification',
            'fields'      => array('to_template', 'subject_template', 'body_template'),
        ),
        'webhook_trigger' => array(
            'label'       => 'Trigger Webhook',
            'description' => 'Send data to a webhook endpoint',
            'fields'      => array('url', 'method', 'payload_template'),
        ),
        'data_transform' => array(
            'label'       => 'Transform Data',
            'description' => 'Transform data using a mapping template',
            'fields'      => array('input_mapping', 'output_mapping', 'operations'),
        ),
    );

    /**
     * Available tool categories
     */
    private static $tool_categories = array(
        'data'       => 'Data Processing',
        'integration'=> 'External Integration',
        'automation' => 'Automation',
        'content'    => 'Content Management',
        'utility'    => 'Utility',
        'custom'     => 'Custom',
    );

    /**
     * Available trigger types (when function runs)
     */
    private static $trigger_types = array(
        'manual'    => 'Manual (Button Click)',
        'schedule'  => 'Scheduled (Cron)',
        'hook'      => 'WordPress Hook',
        'rest'      => 'REST API Endpoint',
        'ajax'      => 'AJAX Request',
    );

    /**
     * ╔═══════════════════════════════════════════════════════════════════════╗
     * ║  GET CUSTOM TOOLS                                                     ║
     * ║                                                                       ║
     * ║  STOP! Returns tool DEFINITIONS, not execution results.               ║
     * ║  Toolkit layer uses these to provide tool functionality.              ║
     * ╚═══════════════════════════════════════════════════════════════════════╝
     */
    public static function get_custom_tools() {
        $tools = get_option(self::TOOLS_OPTION_KEY, array());
        return is_array($tools) ? $tools : array();
    }

    public static function get_tool($tool_id) {
        $tools = self::get_custom_tools();
        return isset($tools[$tool_id]) ? $tools[$tool_id] : null;
    }

    /**
     * Get custom functions for a tool
     */
    public static function get_tool_functions($tool_id) {
        $all_functions = get_option(self::FUNCTIONS_OPTION_KEY, array());
        $tool_functions = array();
        
        foreach ($all_functions as $func_id => $func) {
            if (isset($func['tool_id']) && $func['tool_id'] === $tool_id) {
                $tool_functions[$func_id] = $func;
            }
        }
        
        return $tool_functions;
    }

    /**
     * Get all custom functions
     */
    public static function get_all_functions() {
        $functions = get_option(self::FUNCTIONS_OPTION_KEY, array());
        return is_array($functions) ? $functions : array();
    }

    /**
     * ╔═══════════════════════════════════════════════════════════════════════╗
     * ║  SAVE CUSTOM TOOL                                                     ║
     * ║                                                                       ║
     * ║  STOP! Saves a tool DEFINITION only.                                  ║
     * ║  Validation ensures architecture compliance before saving.            ║
     * ╚═══════════════════════════════════════════════════════════════════════╝
     */
    public static function save_tool($tool_data) {
        $validation = self::validate_tool($tool_data);
        if ($validation !== true) {
            return array('success' => false, 'errors' => $validation);
        }

        $tools = self::get_custom_tools();
        $tool_id = self::sanitize_tool_id($tool_data['tool_id']);

        $definition = array(
            'name'        => sanitize_text_field($tool_data['name']),
            'description' => sanitize_textarea_field($tool_data['description'] ?? ''),
            'category'    => sanitize_key($tool_data['category'] ?? 'custom'),
            'icon'        => sanitize_text_field($tool_data['icon'] ?? 'dashicons-admin-tools'),
            'enabled'     => !empty($tool_data['enabled']),
            'created_at'  => isset($tools[$tool_id]) ? $tools[$tool_id]['created_at'] : current_time('mysql'),
            'updated_at'  => current_time('mysql'),
            'created_by'  => isset($tools[$tool_id]) ? $tools[$tool_id]['created_by'] : get_current_user_id(),
        );

        $tools[$tool_id] = $definition;
        update_option(self::TOOLS_OPTION_KEY, $tools);

        return array('success' => true, 'tool_id' => $tool_id);
    }

    /**
     * Save a function within a tool
     */
    public static function save_function($function_data) {
        $validation = self::validate_function($function_data);
        if ($validation !== true) {
            return array('success' => false, 'errors' => $validation);
        }

        $functions = self::get_all_functions();
        $func_id = self::sanitize_function_id($function_data['function_id']);

        $definition = array(
            'tool_id'       => sanitize_key($function_data['tool_id']),
            'name'          => sanitize_text_field($function_data['name']),
            'description'   => sanitize_textarea_field($function_data['description'] ?? ''),
            'function_type' => sanitize_key($function_data['function_type']),
            'trigger_type'  => sanitize_key($function_data['trigger_type'] ?? 'manual'),
            'config'        => self::sanitize_function_config(
                $function_data['function_type'],
                $function_data['config'] ?? array()
            ),
            'enabled'       => !empty($function_data['enabled']),
            'created_at'    => isset($functions[$func_id]) ? $functions[$func_id]['created_at'] : current_time('mysql'),
            'updated_at'    => current_time('mysql'),
        );

        // Handle trigger-specific config
        if ($definition['trigger_type'] === 'schedule') {
            $definition['schedule'] = sanitize_text_field($function_data['schedule'] ?? 'hourly');
        } elseif ($definition['trigger_type'] === 'hook') {
            $definition['hook_name'] = sanitize_key($function_data['hook_name'] ?? '');
            $definition['hook_priority'] = intval($function_data['hook_priority'] ?? 10);
        }

        $functions[$func_id] = $definition;
        update_option(self::FUNCTIONS_OPTION_KEY, $functions);

        return array('success' => true, 'function_id' => $func_id);
    }

    /**
     * Delete a custom tool (and its functions)
     */
    public static function delete_tool($tool_id) {
        $tools = self::get_custom_tools();
        $tool_id = self::sanitize_tool_id($tool_id);

        if (!isset($tools[$tool_id])) {
            return array('success' => false, 'message' => 'Tool not found');
        }

        // Remove tool
        unset($tools[$tool_id]);
        update_option(self::TOOLS_OPTION_KEY, $tools);

        // Remove associated functions
        $functions = self::get_all_functions();
        foreach ($functions as $func_id => $func) {
            if (isset($func['tool_id']) && $func['tool_id'] === $tool_id) {
                unset($functions[$func_id]);
            }
        }
        update_option(self::FUNCTIONS_OPTION_KEY, $functions);

        return array('success' => true);
    }

    /**
     * Delete a function
     */
    public static function delete_function($function_id) {
        $functions = self::get_all_functions();
        $function_id = self::sanitize_function_id($function_id);

        if (!isset($functions[$function_id])) {
            return array('success' => false, 'message' => 'Function not found');
        }

        unset($functions[$function_id]);
        update_option(self::FUNCTIONS_OPTION_KEY, $functions);

        return array('success' => true);
    }

    /**
     * ╔═══════════════════════════════════════════════════════════════════════╗
     * ║  TOOL VALIDATION - ARCHITECTURE COMPLIANCE CHECK                      ║
     * ╚═══════════════════════════════════════════════════════════════════════╝
     */
    private static function validate_tool($tool_data) {
        $errors = array();

        if (empty($tool_data['tool_id'])) {
            $errors[] = 'Tool ID is required';
        } elseif (!preg_match('/^[a-z][a-z0-9_]{2,29}$/', $tool_data['tool_id'])) {
            $errors[] = 'Tool ID must be 3-30 lowercase letters, numbers, underscores. Must start with letter.';
        }

        if (empty($tool_data['name'])) {
            $errors[] = 'Tool name is required';
        }

        if (!empty($tool_data['category']) && !isset(self::$tool_categories[$tool_data['category']])) {
            $errors[] = 'Invalid category selected';
        }

        return empty($errors) ? true : $errors;
    }

    /**
     * ╔═══════════════════════════════════════════════════════════════════════╗
     * ║  FUNCTION VALIDATION - SECURITY & ARCHITECTURE COMPLIANCE             ║
     * ║                                                                       ║
     * ║  STOP! Functions must use pre-defined types only.                     ║
     * ║  No arbitrary code execution is allowed.                              ║
     * ╚═══════════════════════════════════════════════════════════════════════╝
     */
    private static function validate_function($function_data) {
        $errors = array();

        if (empty($function_data['function_id'])) {
            $errors[] = 'Function ID is required';
        } elseif (!preg_match('/^[a-z][a-z0-9_]{2,49}$/', $function_data['function_id'])) {
            $errors[] = 'Function ID must be 3-50 lowercase letters, numbers, underscores.';
        }

        if (empty($function_data['tool_id'])) {
            $errors[] = 'Tool ID is required';
        }

        if (empty($function_data['name'])) {
            $errors[] = 'Function name is required';
        }

        if (empty($function_data['function_type'])) {
            $errors[] = 'Function type is required';
        } elseif (!isset(self::$function_types[$function_data['function_type']])) {
            $errors[] = 'Invalid function type selected';
        }

        if (!empty($function_data['trigger_type']) && !isset(self::$trigger_types[$function_data['trigger_type']])) {
            $errors[] = 'Invalid trigger type selected';
        }

        // Type-specific validation
        if (empty($errors) && !empty($function_data['function_type'])) {
            $type_errors = self::validate_function_config(
                $function_data['function_type'],
                $function_data['config'] ?? array()
            );
            $errors = array_merge($errors, $type_errors);
        }

        // Security checks
        $security_errors = self::security_check_function($function_data);
        $errors = array_merge($errors, $security_errors);

        return empty($errors) ? true : $errors;
    }

    /**
     * Validate function config based on type
     */
    private static function validate_function_config($type, $config) {
        $errors = array();

        switch ($type) {
            case 'http_request':
            case 'webhook_trigger':
                if (empty($config['url'])) {
                    $errors[] = 'URL is required';
                } elseif (!filter_var($config['url'], FILTER_VALIDATE_URL)) {
                    // Check if it's a template with placeholders
                    if (strpos($config['url'], '{{') === false) {
                        $errors[] = 'Invalid URL format';
                    }
                }
                break;

            case 'database_query':
                if (empty($config['query_template'])) {
                    $errors[] = 'Query template is required';
                }
                // Only allow SELECT queries
                $query_upper = strtoupper(trim($config['query_template'] ?? ''));
                if (strpos($query_upper, 'SELECT') !== 0) {
                    $errors[] = 'Only SELECT queries are allowed for database_query type';
                }
                break;

            case 'database_insert':
            case 'database_update':
                if (empty($config['table'])) {
                    $errors[] = 'Table name is required';
                }
                // Whitelist tables
                $allowed_tables = array('rawwire_candidates', 'rawwire_content', 'rawwire_logs');
                if (!in_array($config['table'], $allowed_tables)) {
                    $errors[] = 'Table "' . esc_html($config['table']) . '" is not allowed';
                }
                break;

            case 'post_create':
            case 'post_update':
                if (empty($config['post_type'])) {
                    $errors[] = 'Post type is required';
                }
                break;

            case 'send_email':
                if (empty($config['to_template'])) {
                    $errors[] = 'Recipient (to) is required';
                }
                if (empty($config['subject_template'])) {
                    $errors[] = 'Subject is required';
                }
                break;
        }

        return $errors;
    }

    /**
     * ╔═══════════════════════════════════════════════════════════════════════╗
     * ║  SECURITY CHECK - PREVENT CODE INJECTION                              ║
     * ║                                                                       ║
     * ║  STOP! Functions must NOT contain executable code.                    ║
     * ║  All operations use safe, pre-defined templates.                      ║
     * ╚═══════════════════════════════════════════════════════════════════════╝
     */
    private static function security_check_function($function_data) {
        $errors = array();
        
        $dangerous_patterns = array(
            '/<\s*\?php/i'       => 'PHP tags are not allowed',
            '/\beval\s*\(/i'     => 'eval() is not allowed',
            '/\bexec\s*\(/i'     => 'exec() is not allowed',
            '/\bsystem\s*\(/i'   => 'system() is not allowed',
            '/\bpassthru\s*\(/i' => 'passthru() is not allowed',
            '/\bshell_exec/i'    => 'shell_exec() is not allowed',
            '/\bproc_open/i'     => 'proc_open() is not allowed',
            '/\bpopen\s*\(/i'    => 'popen() is not allowed',
            '/\bcurl_exec/i'     => 'curl_exec() is not allowed directly',
            '/\bfile_get_contents\s*\(/i' => 'file_get_contents() is not allowed directly',
            '/DROP\s+TABLE/i'    => 'DROP TABLE is not allowed',
            '/TRUNCATE/i'        => 'TRUNCATE is not allowed',
            '/DELETE\s+FROM/i'   => 'DELETE FROM is not allowed (use safe wrappers)',
            '/ALTER\s+TABLE/i'   => 'ALTER TABLE is not allowed',
        );

        $json_data = json_encode($function_data);
        foreach ($dangerous_patterns as $pattern => $message) {
            if (preg_match($pattern, $json_data)) {
                $errors[] = 'Security violation: ' . $message;
            }
        }

        return $errors;
    }

    /**
     * Sanitize tool ID
     */
    private static function sanitize_tool_id($id) {
        $id = strtolower(trim($id));
        $id = preg_replace('/[^a-z0-9_]/', '_', $id);
        $id = preg_replace('/_+/', '_', $id);
        $id = trim($id, '_');
        
        if (strpos($id, 'tool_') !== 0) {
            $id = 'tool_' . $id;
        }
        
        return $id;
    }

    /**
     * Sanitize function ID
     */
    private static function sanitize_function_id($id) {
        $id = strtolower(trim($id));
        $id = preg_replace('/[^a-z0-9_]/', '_', $id);
        $id = preg_replace('/_+/', '_', $id);
        $id = trim($id, '_');
        
        if (strpos($id, 'func_') !== 0) {
            $id = 'func_' . $id;
        }
        
        return $id;
    }

    /**
     * Sanitize function configuration
     */
    private static function sanitize_function_config($type, $config) {
        $sanitized = array();

        switch ($type) {
            case 'http_request':
            case 'webhook_trigger':
                $sanitized['url'] = esc_url_raw($config['url'] ?? '');
                $sanitized['method'] = in_array(strtoupper($config['method'] ?? 'GET'), array('GET', 'POST', 'PUT', 'PATCH', 'DELETE')) 
                    ? strtoupper($config['method']) : 'GET';
                $sanitized['headers'] = array_map('sanitize_text_field', (array)($config['headers'] ?? array()));
                $sanitized['body_template'] = sanitize_textarea_field($config['body_template'] ?? '');
                $sanitized['payload_template'] = sanitize_textarea_field($config['payload_template'] ?? '');
                $sanitized['response_mapping'] = sanitize_textarea_field($config['response_mapping'] ?? '');
                break;

            case 'database_query':
                $sanitized['query_template'] = sanitize_textarea_field($config['query_template'] ?? '');
                $sanitized['params'] = array_map('sanitize_text_field', (array)($config['params'] ?? array()));
                $sanitized['output_format'] = sanitize_key($config['output_format'] ?? 'array');
                break;

            case 'database_insert':
            case 'database_update':
                $sanitized['table'] = sanitize_key($config['table'] ?? '');
                $sanitized['columns_mapping'] = array_map('sanitize_text_field', (array)($config['columns_mapping'] ?? array()));
                $sanitized['where_template'] = sanitize_text_field($config['where_template'] ?? '');
                break;

            case 'wp_option_read':
                $sanitized['option_keys'] = array_map('sanitize_key', (array)($config['option_keys'] ?? array()));
                break;

            case 'wp_option_write':
                $sanitized['option_key'] = sanitize_key($config['option_key'] ?? '');
                $sanitized['value_template'] = sanitize_textarea_field($config['value_template'] ?? '');
                break;

            case 'post_create':
            case 'post_update':
                $sanitized['post_type'] = sanitize_key($config['post_type'] ?? 'post');
                $sanitized['field_mapping'] = array_map('sanitize_text_field', (array)($config['field_mapping'] ?? array()));
                $sanitized['default_status'] = sanitize_key($config['default_status'] ?? 'draft');
                $sanitized['identifier_field'] = sanitize_key($config['identifier_field'] ?? 'id');
                break;

            case 'send_email':
                $sanitized['to_template'] = sanitize_text_field($config['to_template'] ?? '');
                $sanitized['subject_template'] = sanitize_text_field($config['subject_template'] ?? '');
                $sanitized['body_template'] = wp_kses_post($config['body_template'] ?? '');
                break;

            case 'data_transform':
                $sanitized['input_mapping'] = sanitize_textarea_field($config['input_mapping'] ?? '');
                $sanitized['output_mapping'] = sanitize_textarea_field($config['output_mapping'] ?? '');
                $sanitized['operations'] = array_map('sanitize_text_field', (array)($config['operations'] ?? array()));
                break;
        }

        return $sanitized;
    }

    /**
     * ╔═══════════════════════════════════════════════════════════════════════╗
     * ║  RENDER BUILDER UI                                                    ║
     * ║                                                                       ║
     * ║  STOP! This renders the BUILDER FORMS, not tool execution.            ║
     * ║  Tool execution happens via the Toolkit layer.                        ║
     * ╚═══════════════════════════════════════════════════════════════════════╝
     */
    public static function render_builder_ui() {
        $existing_tools = self::get_custom_tools();
        $all_functions = self::get_all_functions();
        
        ob_start();
        ?>
        <div class="rawwire-custom-tool-builder">
            <!-- Architecture Warning Banner -->
            <div class="architecture-notice" style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin-bottom: 20px;">
                <h4 style="margin-top: 0;">⚠️ Architecture Compliance Required</h4>
                <p style="margin-bottom: 0;">
                    Custom tools follow strict architectural rules:<br>
                    • Tools define <strong>actions</strong> using pre-defined function types<br>
                    • No arbitrary PHP code execution - use safe templates<br>
                    • Tools integrate with the Toolkit layer, not bypass Module Core<br>
                    • Functions use placeholders like <code>{{field_name}}</code> for dynamic values
                </p>
            </div>

            <!-- Existing Tools List -->
            <div class="existing-tools" style="margin-bottom: 30px;">
                <h3>🔧 Existing Custom Tools</h3>
                <?php if (empty($existing_tools)): ?>
                    <p class="description">No custom tools defined yet. Create your first one below.</p>
                <?php else: ?>
                    <?php foreach ($existing_tools as $tool_id => $tool): ?>
                        <div class="tool-card" style="border: 1px solid #ddd; padding: 15px; margin-bottom: 15px; background: #fff;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <h4 style="margin: 0;">
                                        <span class="dashicons <?php echo esc_attr($tool['icon']); ?>"></span>
                                        <?php echo esc_html($tool['name']); ?>
                                        <code style="font-size: 12px; margin-left: 10px;"><?php echo esc_html($tool_id); ?></code>
                                        <?php if ($tool['enabled']): ?>
                                            <span style="background: #d4edda; color: #155724; padding: 2px 8px; font-size: 11px; border-radius: 3px; margin-left: 10px;">Enabled</span>
                                        <?php else: ?>
                                            <span style="background: #f8d7da; color: #721c24; padding: 2px 8px; font-size: 11px; border-radius: 3px; margin-left: 10px;">Disabled</span>
                                        <?php endif; ?>
                                    </h4>
                                    <p class="description" style="margin: 5px 0 0;"><?php echo esc_html($tool['description']); ?></p>
                                </div>
                                <div>
                                    <button type="button" class="button button-small toggle-functions" data-tool-id="<?php echo esc_attr($tool_id); ?>">Functions ▼</button>
                                    <button type="button" class="button button-small edit-custom-tool" data-tool-id="<?php echo esc_attr($tool_id); ?>">Edit</button>
                                    <button type="button" class="button button-small button-link-delete delete-custom-tool" data-tool-id="<?php echo esc_attr($tool_id); ?>">Delete</button>
                                </div>
                            </div>
                            
                            <!-- Tool Functions -->
                            <div class="tool-functions" id="functions-<?php echo esc_attr($tool_id); ?>" style="display: none; margin-top: 15px; padding-top: 15px; border-top: 1px solid #eee;">
                                <h5>Functions</h5>
                                <?php
                                $tool_funcs = self::get_tool_functions($tool_id);
                                if (empty($tool_funcs)): ?>
                                    <p class="description">No functions defined for this tool.</p>
                                <?php else: ?>
                                    <table class="widefat" style="margin-bottom: 10px;">
                                        <thead>
                                            <tr><th>Function ID</th><th>Name</th><th>Type</th><th>Trigger</th><th>Actions</th></tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($tool_funcs as $func_id => $func): ?>
                                                <tr>
                                                    <td><code><?php echo esc_html($func_id); ?></code></td>
                                                    <td><?php echo esc_html($func['name']); ?></td>
                                                    <td><?php echo esc_html(self::$function_types[$func['function_type']]['label'] ?? $func['function_type']); ?></td>
                                                    <td><?php echo esc_html(self::$trigger_types[$func['trigger_type']] ?? $func['trigger_type']); ?></td>
                                                    <td>
                                                        <button type="button" class="button button-small delete-custom-function" data-function-id="<?php echo esc_attr($func_id); ?>">Delete</button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                                <button type="button" class="button add-function-btn" data-tool-id="<?php echo esc_attr($tool_id); ?>">+ Add Function</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- New Tool Form -->
            <div class="new-tool-form" style="background: #f9f9f9; padding: 20px; border: 1px solid #ddd;">
                <h3>➕ Create New Custom Tool</h3>
                <form id="custom-tool-form" method="post">
                    <?php wp_nonce_field('rawwire_custom_tool', 'tool_nonce'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th><label for="tool_id">Tool ID *</label></th>
                            <td>
                                <input type="text" id="tool_id" name="tool_id" class="regular-text" pattern="[a-z][a-z0-9_]{2,29}" required>
                                <p class="description">Unique identifier (lowercase, 3-30 chars)</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="tool_name">Name *</label></th>
                            <td><input type="text" id="tool_name" name="name" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th><label for="tool_description">Description</label></th>
                            <td><textarea id="tool_description" name="description" rows="2" class="large-text"></textarea></td>
                        </tr>
                        <tr>
                            <th><label for="tool_category">Category</label></th>
                            <td>
                                <select id="tool_category" name="category">
                                    <?php foreach (self::$tool_categories as $key => $label): ?>
                                        <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="tool_enabled">Enabled</label></th>
                            <td><input type="checkbox" id="tool_enabled" name="enabled" value="1" checked></td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" class="button button-primary">Save Tool</button>
                    </p>
                    <div id="tool-validation-results" style="display: none;"></div>
                </form>
            </div>

            <!-- Add Function Modal (hidden by default) -->
            <div id="function-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999;">
                <div style="background: #fff; max-width: 700px; margin: 50px auto; padding: 20px; max-height: 80vh; overflow-y: auto;">
                    <h3>Add Function to Tool: <span id="modal-tool-name"></span></h3>
                    <form id="custom-function-form">
                        <input type="hidden" id="func_tool_id" name="tool_id">
                        <?php wp_nonce_field('rawwire_custom_function', 'function_nonce'); ?>
                        
                        <table class="form-table">
                            <tr>
                                <th><label for="function_id">Function ID *</label></th>
                                <td><input type="text" id="function_id" name="function_id" class="regular-text" required></td>
                            </tr>
                            <tr>
                                <th><label for="func_name">Name *</label></th>
                                <td><input type="text" id="func_name" name="name" class="regular-text" required></td>
                            </tr>
                            <tr>
                                <th><label for="func_description">Description</label></th>
                                <td><textarea id="func_description" name="description" rows="2" class="large-text"></textarea></td>
                            </tr>
                            <tr>
                                <th><label for="function_type">Function Type *</label></th>
                                <td>
                                    <select id="function_type" name="function_type" required>
                                        <option value="">-- Select Type --</option>
                                        <?php foreach (self::$function_types as $key => $type): ?>
                                            <option value="<?php echo esc_attr($key); ?>">
                                                <?php echo esc_html($type['label']); ?> - <?php echo esc_html($type['description']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="trigger_type">Trigger</label></th>
                                <td>
                                    <select id="trigger_type" name="trigger_type">
                                        <?php foreach (self::$trigger_types as $key => $label): ?>
                                            <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="func_enabled">Enabled</label></th>
                                <td><input type="checkbox" id="func_enabled" name="enabled" value="1" checked></td>
                            </tr>
                        </table>

                        <div id="function-config-fields" style="display: none; margin-top: 15px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd;">
                            <h4>Function Configuration</h4>
                            <div id="func-config-container"></div>
                        </div>

                        <p style="margin-top: 20px;">
                            <button type="submit" class="button button-primary">Save Function</button>
                            <button type="button" class="button close-modal">Cancel</button>
                        </p>
                        <div id="function-validation-results" style="display: none;"></div>
                    </form>
                </div>
            </div>
        </div>

        <script>
        jQuery(function($) {
            // Function config templates
            var funcConfigTemplates = {
                'http_request': '<label>URL:</label><input type="text" name="config[url]" class="large-text" placeholder="https://api.example.com/{{endpoint}}"><br><br>' +
                    '<label>Method:</label><select name="config[method]"><option value="GET">GET</option><option value="POST">POST</option><option value="PUT">PUT</option><option value="DELETE">DELETE</option></select><br><br>' +
                    '<label>Body Template (JSON):</label><textarea name="config[body_template]" rows="3" class="large-text code" placeholder=\'{"key": "{{value}}"}\'></textarea>',
                
                'database_query': '<label>SELECT Query Template:</label><textarea name="config[query_template]" rows="3" class="large-text code" placeholder="SELECT * FROM wp_rawwire_candidates WHERE status = %s LIMIT %d"></textarea><br><br>' +
                    '<label>Output Format:</label><select name="config[output_format]"><option value="array">Array</option><option value="object">Object</option><option value="json">JSON</option></select>',
                
                'database_insert': '<label>Table:</label><select name="config[table]"><option value="rawwire_candidates">rawwire_candidates</option><option value="rawwire_content">rawwire_content</option><option value="rawwire_logs">rawwire_logs</option></select><br><br>' +
                    '<label>Columns Mapping (JSON):</label><textarea name="config[columns_mapping]" rows="3" class="large-text code" placeholder=\'{"title": "{{input_title}}", "status": "pending"}\'></textarea>',
                
                'database_update': '<label>Table:</label><select name="config[table]"><option value="rawwire_candidates">rawwire_candidates</option><option value="rawwire_content">rawwire_content</option></select><br><br>' +
                    '<label>Columns Mapping:</label><textarea name="config[columns_mapping]" rows="3" class="large-text code"></textarea><br><br>' +
                    '<label>WHERE Template:</label><input type="text" name="config[where_template]" class="large-text" placeholder="id = {{record_id}}">',
                
                'wp_option_read': '<label>Option Keys (comma-separated):</label><input type="text" name="config[option_keys]" class="large-text" placeholder="my_option_1, my_option_2">',
                
                'wp_option_write': '<label>Option Key:</label><input type="text" name="config[option_key]" class="regular-text"><br><br>' +
                    '<label>Value Template:</label><input type="text" name="config[value_template]" class="large-text" placeholder="{{new_value}}">',
                
                'post_create': '<label>Post Type:</label><input type="text" name="config[post_type]" class="regular-text" value="post"><br><br>' +
                    '<label>Field Mapping (JSON):</label><textarea name="config[field_mapping]" rows="3" class="large-text code" placeholder=\'{"post_title": "{{title}}", "post_content": "{{content}}"}\'></textarea><br><br>' +
                    '<label>Default Status:</label><select name="config[default_status]"><option value="draft">Draft</option><option value="pending">Pending</option><option value="publish">Published</option></select>',
                
                'post_update': '<label>Post Type:</label><input type="text" name="config[post_type]" class="regular-text" value="post"><br><br>' +
                    '<label>Field Mapping:</label><textarea name="config[field_mapping]" rows="3" class="large-text code"></textarea><br><br>' +
                    '<label>Identifier Field:</label><input type="text" name="config[identifier_field]" class="regular-text" value="ID">',
                
                'send_email': '<label>To:</label><input type="text" name="config[to_template]" class="large-text" placeholder="{{recipient_email}}"><br><br>' +
                    '<label>Subject:</label><input type="text" name="config[subject_template]" class="large-text" placeholder="Notification: {{event}}"><br><br>' +
                    '<label>Body:</label><textarea name="config[body_template]" rows="4" class="large-text"></textarea>',
                
                'webhook_trigger': '<label>Webhook URL:</label><input type="text" name="config[url]" class="large-text" placeholder="https://webhook.site/..."><br><br>' +
                    '<label>Method:</label><select name="config[method]"><option value="POST">POST</option><option value="GET">GET</option></select><br><br>' +
                    '<label>Payload Template:</label><textarea name="config[payload_template]" rows="3" class="large-text code"></textarea>',
                
                'data_transform': '<label>Input Mapping:</label><textarea name="config[input_mapping]" rows="2" class="large-text code"></textarea><br><br>' +
                    '<label>Output Mapping:</label><textarea name="config[output_mapping]" rows="2" class="large-text code"></textarea>'
            };

            // Toggle functions visibility
            $(document).on('click', '.toggle-functions', function() {
                var toolId = $(this).data('tool-id');
                $('#functions-' + toolId).slideToggle();
            });

            // Open function modal
            $(document).on('click', '.add-function-btn', function() {
                var toolId = $(this).data('tool-id');
                $('#func_tool_id').val(toolId);
                $('#modal-tool-name').text(toolId);
                $('#function-modal').show();
            });

            // Close modal
            $(document).on('click', '.close-modal', function() {
                $('#function-modal').hide();
            });

            // Show function config fields
            $('#function_type').on('change', function() {
                var type = $(this).val();
                if (type && funcConfigTemplates[type]) {
                    $('#func-config-container').html(funcConfigTemplates[type]);
                    $('#function-config-fields').show();
                } else {
                    $('#function-config-fields').hide();
                }
            });

            // Save tool
            $('#custom-tool-form').on('submit', function(e) {
                e.preventDefault();
                $.post(ajaxurl, {
                    action: 'rawwire_save_custom_tool',
                    data: $(this).serialize(),
                    _ajax_nonce: '<?php echo wp_create_nonce('rawwire_custom_tool_ajax'); ?>'
                }, function(response) {
                    if (response.success) {
                        $('#tool-validation-results').removeClass('error').addClass('success')
                            .html('<strong>✓ Tool saved!</strong>').show();
                        setTimeout(function() { location.reload(); }, 1000);
                    } else {
                        var errors = response.data.errors || ['Unknown error'];
                        $('#tool-validation-results').removeClass('success').addClass('error')
                            .html('<strong>✗ Error:</strong> ' + errors.join(', ')).show();
                    }
                });
            });

            // Save function
            $('#custom-function-form').on('submit', function(e) {
                e.preventDefault();
                $.post(ajaxurl, {
                    action: 'rawwire_save_custom_function',
                    data: $(this).serialize(),
                    _ajax_nonce: '<?php echo wp_create_nonce('rawwire_custom_function_ajax'); ?>'
                }, function(response) {
                    if (response.success) {
                        $('#function-modal').hide();
                        location.reload();
                    } else {
                        var errors = response.data.errors || ['Unknown error'];
                        $('#function-validation-results').removeClass('success').addClass('error')
                            .html('<strong>✗ Error:</strong> ' + errors.join(', ')).show();
                    }
                });
            });

            // Delete tool
            $(document).on('click', '.delete-custom-tool', function() {
                if (!confirm('Delete this tool and all its functions?')) return;
                $.post(ajaxurl, {
                    action: 'rawwire_delete_custom_tool',
                    tool_id: $(this).data('tool-id'),
                    _ajax_nonce: '<?php echo wp_create_nonce('rawwire_custom_tool_ajax'); ?>'
                }, function() { location.reload(); });
            });

            // Delete function
            $(document).on('click', '.delete-custom-function', function() {
                if (!confirm('Delete this function?')) return;
                $.post(ajaxurl, {
                    action: 'rawwire_delete_custom_function',
                    function_id: $(this).data('function-id'),
                    _ajax_nonce: '<?php echo wp_create_nonce('rawwire_custom_function_ajax'); ?>'
                }, function() { location.reload(); });
            });
        });
        </script>

        <style>
            #tool-validation-results.error, #function-validation-results.error { background: #ffeaea; border-left: 4px solid #dc3545; padding: 10px; margin-top: 10px; }
            #tool-validation-results.success, #function-validation-results.success { background: #d4edda; border-left: 4px solid #28a745; padding: 10px; margin-top: 10px; }
        </style>
        <?php
        return ob_get_clean();
    }

    /**
     * Register AJAX handlers
     */
    public static function register_ajax_handlers() {
        add_action('wp_ajax_rawwire_save_custom_tool', array(__CLASS__, 'ajax_save_tool'));
        add_action('wp_ajax_rawwire_delete_custom_tool', array(__CLASS__, 'ajax_delete_tool'));
        add_action('wp_ajax_rawwire_save_custom_function', array(__CLASS__, 'ajax_save_function'));
        add_action('wp_ajax_rawwire_delete_custom_function', array(__CLASS__, 'ajax_delete_function'));
    }

    public static function ajax_save_tool() {
        check_ajax_referer('rawwire_custom_tool_ajax');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('errors' => array('Permission denied')));
        }
        parse_str($_POST['data'], $form_data);
        $result = self::save_tool($form_data);
        $result['success'] ? wp_send_json_success($result) : wp_send_json_error($result);
    }

    public static function ajax_delete_tool() {
        check_ajax_referer('rawwire_custom_tool_ajax');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        $result = self::delete_tool(sanitize_key($_POST['tool_id']));
        $result['success'] ? wp_send_json_success($result) : wp_send_json_error($result);
    }

    public static function ajax_save_function() {
        check_ajax_referer('rawwire_custom_function_ajax');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('errors' => array('Permission denied')));
        }
        parse_str($_POST['data'], $form_data);
        $result = self::save_function($form_data);
        $result['success'] ? wp_send_json_success($result) : wp_send_json_error($result);
    }

    public static function ajax_delete_function() {
        check_ajax_referer('rawwire_custom_function_ajax');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        $result = self::delete_function(sanitize_key($_POST['function_id']));
        $result['success'] ? wp_send_json_success($result) : wp_send_json_error($result);
    }

    /**
     * Get function types (for external use)
     */
    public static function get_function_types() {
        return self::$function_types;
    }
}

// Initialize AJAX handlers
add_action('admin_init', array('RawWire_Custom_Tool_Builder', 'register_ajax_handlers'));
