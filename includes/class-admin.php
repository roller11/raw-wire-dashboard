<?php

/**
 * Admin class for RawWire Dashboard
 *
 * Handles admin initialization, settings registration, and the generic
 * AJAX module dispatcher.  Individual action handlers live in the
 * registered modules (see modules/core/module.php).
 *
 * @since 1.0.18
 * @since 1.0.26 Removed 14 dead ajax_* methods superseded by core module.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * RawWire Admin Class
 */
class RawWire_Admin
{

    /**
     * Constructor
     */
    public function __construct()
    {
        add_action('admin_init', array($this, 'admin_init'));
        // Generic module AJAX dispatcher — routes to registered modules
        add_action('wp_ajax_rawwire_module_action', array($this, 'ajax_module_action'));
    }

    /**
     * Admin initialization
     */
    public function admin_init()
    {
        $this->register_settings();
    }

    /**
     * Register plugin settings
     */
    private function register_settings()
    {
        register_setting('rawwire_settings', 'rawwire_api_key');
        register_setting('rawwire_settings', 'rawwire_log_level');
    }

    /**
     * Generic AJAX dispatcher that routes module actions to registered modules.
     *
     * JS calls:  moduleAjax('core', 'get_stats', data)
     * This dispatcher finds the 'core' module instance and calls
     * $module->handle_ajax('get_stats', data).
     */
    public function ajax_module_action()
    {
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
