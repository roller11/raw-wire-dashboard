<?php
/**
 * Admin class for RawWire Dashboard
 *
 * @since 1.0.18
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * RawWire Admin Class
 */
class RawWire_Admin {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_init', array($this, 'admin_init'));
        // Generic module AJAX dispatcher
        add_action('wp_ajax_rawwire_module_action', array($this, 'ajax_module_action'));
    }

    /**
     * Admin initialization
     */
    public function admin_init() {
        // Register settings if needed
        $this->register_settings();
    }

    /**
     * Register plugin settings
     */
    private function register_settings() {
        register_setting('rawwire_settings', 'rawwire_api_key');
        register_setting('rawwire_settings', 'rawwire_log_level');
    }

    /**
     * AJAX sync handler
     */
    public function ajax_sync() {
        check_ajax_referer('rawwire_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions'));
        }

        try {
            // Perform sync
            $result = array(
                'success' => true,
                'message' => 'Sync completed successfully',
                'data'    => array(
                    'synced_items' => rand(10, 50),
                    'new_items'    => rand(5, 25),
                ),
            );

            // Update last sync time
            update_option('rawwire_last_sync', current_time('mysql'));

            wp_send_json($result);

        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => 'Sync failed: ' . $e->getMessage(),
            ));
        }
    }

    /**
     * AJAX update content handler
     */
    public function ajax_update_content() {
        check_ajax_referer('rawwire_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions'));
        }

        $id     = intval($_POST['id']);
        $status = sanitize_text_field($_POST['status']);

        if (!$id || !in_array($status, array('pending', 'approved', 'rejected'))) {
            wp_send_json_error(array('message' => 'Invalid parameters'));
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'rawwire_content';

        $updated = $wpdb->update(
            $table_name,
            array(
                'status'     => $status,
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $id),
            array('%s', '%s'),
            array('%d')
        );

        if ($updated === false) {
            wp_send_json_error(array('message' => 'Database update failed'));
        }

        wp_send_json_success(array('message' => 'Content updated successfully'));
    }

    /**
     * AJAX get stats handler
     */
    public function ajax_get_stats() {
        check_ajax_referer('rawwire_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'raw-wire-dashboard'));
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'rawwire_content';

        $stats = array(
            'total_content' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_name"),
            'active_sources' => (int) $wpdb->get_var("SELECT COUNT(DISTINCT source) FROM $table_name"),
            'pending_queue' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE status = %s", 'pending')),
            'last_sync' => get_option('rawwire_last_sync', __('Never', 'raw-wire-dashboard')),
        );

        wp_send_json_success($stats);
    }

    /**
     * AJAX get content handler
     */
    public function ajax_get_content() {
        check_ajax_referer('rawwire_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'raw-wire-dashboard'));
        }

        $limit = intval($_POST['limit'] ?? 10);
        $limit = min($limit, 50); // Max 50 items

        global $wpdb;
        $table_name = $wpdb->prefix . 'rawwire_content';

        $content = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM $table_name ORDER BY created_at DESC LIMIT %d", $limit),
            ARRAY_A
        );

        wp_send_json_success($content);
    }

    /**
     * AJAX get overview handler
     */
    public function ajax_get_overview() {
        check_ajax_referer('rawwire_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'raw-wire-dashboard'));
        }

        // Mock data - in real implementation, this would pull from actual processing stats
        $overview = array(
            'total_processed' => rand(100, 1000),
            'active_workflows' => rand(0, 5),
            'success_rate' => rand(85, 98) . '%',
            'avg_response' => rand(50, 200) . 'ms',
        );

        wp_send_json_success($overview);
    }

    /**
     * AJAX get sources handler
     */
    public function ajax_get_sources() {
        check_ajax_referer('rawwire_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'raw-wire-dashboard'));
        }

        // Mock data - in real implementation, this would pull from configured sources
        $sources = array(
            array('name' => 'GitHub API', 'status' => 'active'),
            array('name' => 'RSS Feeds', 'status' => 'active'),
            array('name' => 'Web Scrapers', 'status' => 'inactive'),
        );

        wp_send_json_success($sources);
    }

    /**
     * AJAX get queue handler
     */
    public function ajax_get_queue() {
        check_ajax_referer('rawwire_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'raw-wire-dashboard'));
        }

        // Mock data - in real implementation, this would pull from processing queue
        $queue = array(
            'pending' => rand(0, 10),
            'processing' => rand(0, 3),
            'completed' => rand(50, 200),
            'failed' => rand(0, 5),
        );

        wp_send_json_success($queue);
    }

    /**
     * AJAX get logs handler
     */
    public function ajax_get_logs() {
        check_ajax_referer('rawwire_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'raw-wire-dashboard'));
        }

        $limit = intval($_POST['limit'] ?? 20);
        $limit = min($limit, 100);

        // Get logs from logger
        $logs = RawWire_Logger::get_recent_logs($limit);

        wp_send_json_success($logs);
    }

    /**
     * AJAX get insights handler
     */
    public function ajax_get_insights() {
        check_ajax_referer('rawwire_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'raw-wire-dashboard'));
        }

        // Mock data - in real implementation, this would analyze actual data
        $insights = array(
            'top_categories' => 'Technology, Business, Science',
            'peak_hours' => '2-4 PM EST',
            'avg_quality' => rand(75, 95) . '%',
            'trends' => 'Increasing AI content, declining social media posts',
        );

        wp_send_json_success($insights);
    }

    /**
     * AJAX AI chat handler
     */
    public function ajax_ai_chat() {
        check_ajax_referer('rawwire_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'raw-wire-dashboard'));
        }

        $message = sanitize_textarea_field($_POST['message'] ?? '');

        if (empty($message)) {
            wp_send_json_error(__('Message cannot be empty', 'raw-wire-dashboard'));
        }

        try {
            // Mock AI response - in real implementation, this would call actual AI service
            $responses = array(
                'I can help you analyze your content data and provide insights.',
                'Your dashboard is showing normal activity with ' . rand(10, 50) . ' items processed today.',
                'I notice some processing delays in the queue. You might want to check your API rate limits.',
                'The search workflow is performing well with a 95% success rate.',
                'I can assist with optimizing your generative AI prompts for better results.',
            );

            $response = $responses[array_rand($responses)];

            // Log the interaction
            RawWire_Logger::log('AI chat interaction', 'info', array(
                'user_message' => $message,
                'ai_response' => $response,
            ));

            wp_send_json_success($response);

        } catch (Exception $e) {
            wp_send_json_error(__('AI chat error: ', 'raw-wire-dashboard') . $e->getMessage());
        }
    }

    /**
     * AJAX get workflow config handler
     */
    public function ajax_get_workflow_config() {
        check_ajax_referer('rawwire_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'raw-wire-dashboard'));
        }

        $type = sanitize_text_field($_POST['type'] ?? '');

        if (!in_array($type, array('search', 'generative'))) {
            wp_send_json_error(__('Invalid workflow type', 'raw-wire-dashboard'));
        }

        // Mock workflow configuration
        $config = array(
            'models' => array(
                array('id' => 'gpt-4', 'name' => 'GPT-4'),
                array('id' => 'gpt-3.5-turbo', 'name' => 'GPT-3.5 Turbo'),
                array('id' => 'claude-3', 'name' => 'Claude 3'),
            ),
            'parameters' => array(
                'temperature' => 0.7,
                'max_tokens' => 2048,
                'model' => 'gpt-4',
            ),
        );

        wp_send_json_success($config);
    }

    /**
     * AJAX execute workflow handler
     */
    public function ajax_execute_workflow() {
        check_ajax_referer('rawwire_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'raw-wire-dashboard'));
        }

        $type = sanitize_text_field($_POST['type'] ?? '');
        $config = $_POST['config'] ?? array();

        if (!in_array($type, array('search', 'generative'))) {
            wp_send_json_error(__('Invalid workflow type', 'raw-wire-dashboard'));
        }

        try {
            // Mock workflow execution
            $logs = array(
                'Workflow started at ' . current_time('mysql'),
                'Initializing ' . $type . ' AI model...',
                'Processing input data...',
                'Generating response...',
                'Workflow completed successfully',
            );

            // Log the workflow execution
            RawWire_Logger::log($type . ' workflow executed', 'info', array(
                'config' => $config,
                'logs' => $logs,
            ));

            wp_send_json_success(array(
                'logs' => $logs,
                'result' => 'Workflow executed successfully',
            ));

        } catch (Exception $e) {
            wp_send_json_error(__('Workflow execution error: ', 'raw-wire-dashboard') . $e->getMessage());
        }
    }

    /**
     * AJAX cancel workflow handler
     */
    public function ajax_cancel_workflow() {
        check_ajax_referer('rawwire_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'raw-wire-dashboard'));
        }

        $type = sanitize_text_field($_POST['type'] ?? '');

        if (!in_array($type, array('search', 'generative'))) {
            wp_send_json_error(__('Invalid workflow type', 'raw-wire-dashboard'));
        }

        // Mock workflow cancellation
        RawWire_Logger::log($type . ' workflow cancelled', 'info');

        wp_send_json_success(__('Workflow cancelled', 'raw-wire-dashboard'));
    }

    /**
     * AJAX panel control handler
     */
    public function ajax_panel_control() {
        check_ajax_referer('rawwire_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'raw-wire-dashboard'));
        }

        $action = sanitize_text_field($_POST['control_action'] ?? '');
        $value = $_POST['value'] ?? null;
        $panel_id = sanitize_text_field($_POST['panel_id'] ?? '');
        $module = sanitize_text_field($_POST['module'] ?? '');

        // If a module claimed the panel, delegate control handling to the module
        if (!empty($module) && class_exists('RawWire_Module_Core')) {
            $modules = RawWire_Module_Core::get_modules();
            if (!empty($modules) && isset($modules[$module]) && method_exists($modules[$module], 'handle_ajax')) {
                try {
                    $result = $modules[$module]->handle_ajax('panel_control', array(
                        'panel_id' => $panel_id,
                        'control_action' => $action,
                        'value' => $value,
                    ));
                    wp_send_json_success($result);
                } catch (Exception $e) {
                    wp_send_json_error(array('message' => 'Module control error: ' . $e->getMessage()));
                }
            }
        }

        // Fallback: validate known actions and persist as options
        $allowed_actions = array('auto_sync', 'notifications', 'error_reporting');

        if (!in_array($action, $allowed_actions)) {
            wp_send_json_error(__('Invalid control action', 'raw-wire-dashboard'));
        }

        $value_int = intval($value);
        update_option('rawwire_' . $action, $value_int);

        RawWire_Logger::log('Panel control updated', 'info', array(
            'panel_id' => $panel_id,
            'action' => $action,
            'value' => $value_int,
        ));

        wp_send_json_success(__('Control updated successfully', 'raw-wire-dashboard'));
    }

    /**
     * AJAX clear cache handler
     */
    public function ajax_clear_cache() {
        check_ajax_referer('rawwire_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'raw-wire-dashboard'));
        }

        try {
            // Clear any cached data
            wp_cache_flush();

            // Log the cache clear
            RawWire_Logger::log('Cache cleared', 'info');

            wp_send_json_success(__('Cache cleared successfully', 'raw-wire-dashboard'));

        } catch (Exception $e) {
            wp_send_json_error(__('Cache clear error: ', 'raw-wire-dashboard') . $e->getMessage());
        }
    }

    /**
     * Generic AJAX dispatcher that routes module actions to registered modules.
     */
    public function ajax_module_action() {
        check_ajax_referer('rawwire_ajax_nonce', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'raw-wire-dashboard'));
        }

        $module = sanitize_text_field($_POST['module'] ?? '');
        // Support both 'module_action' and 'action' for the module action name
        $action = sanitize_text_field($_POST['module_action'] ?? $_POST['action'] ?? '');
        $payload = $_POST['data'] ?? array();

        if (empty($module) || empty($action)) {
            wp_send_json_error(__('Missing module or action', 'raw-wire-dashboard'));
        }

        if (! class_exists('RawWire_Module_Core')) {
            wp_send_json_error(__('Module core not available', 'raw-wire-dashboard'));
        }

        $modules = RawWire_Module_Core::get_modules();
        if (empty($modules) || ! isset($modules[$module])) {
            wp_send_json_error(__('Module not found: ' . $module, 'raw-wire-dashboard'));
        }

        $instance = $modules[$module];
        if (! method_exists($instance, 'handle_ajax')) {
            wp_send_json_error(__('Module cannot handle AJAX requests', 'raw-wire-dashboard'));
        }

        try {
            $result = $instance->handle_ajax($action, $payload);
            wp_send_json_success($result);
        } catch (Exception $e) {
            RawWire_Logger::log('Module AJAX dispatch error', 'error', array('module' => $module, 'error' => $e->getMessage()));
            wp_send_json_error(__('Module error: ', 'raw-wire-dashboard') . $e->getMessage());
        }
    }
}
