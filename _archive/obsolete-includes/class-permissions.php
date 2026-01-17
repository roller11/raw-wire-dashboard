<?php
/**
 * Permissions and Capability System
 *
 * Provides fine-grained, role-based access control for all plugin features.
 * Enables client-specific permission configurations for SaaS deployments.
 *
 * @package    RawWire_Dashboard
 * @subpackage RawWire_Dashboard/includes
 * @since      1.0.12
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * RawWire_Permissions Class
 *
 * Manages custom capabilities for plugin features and enforces
 * permission checks across AJAX, REST, and admin contexts.
 *
 * @since 1.0.12
 */
class RawWire_Permissions {

    /**
     * Custom capabilities for the plugin
     *
     * @var array
     */
    private static $capabilities = array(
        'rawwire_view_dashboard' => 'View Raw-Wire dashboard',
        'rawwire_manage_modules' => 'Enable and disable feature modules',
        'rawwire_edit_config' => 'Edit module configuration',
        'rawwire_view_logs' => 'View activity logs',
        'rawwire_clear_logs' => 'Clear activity logs',
        'rawwire_manage_templates' => 'Edit client templates',
        'rawwire_approve_content' => 'Approve/reject content',
        'rawwire_manage_api_keys' => 'Generate and revoke API keys',
    );

    /**
     * Initialize the permission system
     *
     * @since  1.0.12
     * @return void
     */
    public static function init() {
        // Register capabilities on plugin activation
        add_action('admin_init', array(__CLASS__, 'register_capabilities'));

        // Add permission checks to AJAX/REST handlers
        add_action('init', array(__CLASS__, 'setup_permission_filters'), 1);
    }

    /**
     * Register custom capabilities with WordPress roles
     *
     * @since  1.0.12
     * @return void
     */
    public static function register_capabilities() {
        // Only register once
        if (get_option('rawwire_capabilities_registered')) {
            return;
        }

        // Grant all capabilities to administrators
        $admin_role = get_role('administrator');
        if ($admin_role) {
            foreach (self::$capabilities as $cap => $label) {
                $admin_role->add_cap($cap);
            }
        }

        // Grant view-only capabilities to editors
        $editor_role = get_role('editor');
        if ($editor_role) {
            $editor_role->add_cap('rawwire_view_dashboard');
            $editor_role->add_cap('rawwire_view_logs');
        }

        // Mark as registered
        update_option('rawwire_capabilities_registered', true);
    }

    /**
     * Setup permission filter hooks
     *
     * @since  1.0.12
     * @return void
     */
    public static function setup_permission_filters() {
        // Allow modules to register custom capability checks
        do_action('rawwire_register_permissions');
    }

    /**
     * Check if user has capability
     *
     * @since  1.0.12
     * @param  string $capability Capability to check
     * @param  int    $user_id User ID (null for current user)
     * @return bool True if user has capability
     */
    public static function check($capability, $user_id = null) {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }

        // Administrators always have access
        if (user_can($user_id, 'manage_options')) {
            return true;
        }

        // Check custom capability
        return user_can($user_id, $capability);
    }

    /**
     * Require capability or die with error
     *
     * For use in AJAX handlers - sends JSON error and exits.
     *
     * @since  1.0.12
     * @param  string $capability Required capability
     * @return void
     */
    public static function require_capability($capability) {
        if (!self::check($capability)) {
            wp_send_json_error(array(
                'message' => __('You do not have permission to perform this action.', 'rawwire-dashboard'),
                'code' => 'insufficient_permissions',
                'required_capability' => $capability,
            ), 403);
            exit;
        }
    }

    /**
     * REST API permission callback
     *
     * Returns WP_Error instead of dying (for REST endpoints).
     *
     * @since  1.0.12
     * @param  string $capability Required capability
     * @return true|WP_Error True if permitted, WP_Error otherwise
     */
    public static function rest_permission_check($capability) {
        if (!self::check($capability)) {
            return new WP_Error(
                'rest_forbidden',
                __('You do not have permission to access this endpoint.', 'rawwire-dashboard'),
                array('status' => 403, 'required_capability' => $capability)
            );
        }

        return true;
    }

    /**
     * Get all registered capabilities
     *
     * @since  1.0.12
     * @return array Capabilities with labels
     */
    public static function get_capabilities() {
        return self::$capabilities;
    }

    /**
     * Grant capability to role
     *
     * @since  1.0.12
     * @param  string $role Role name
     * @param  string $capability Capability to grant
     * @return bool True on success
     */
    public static function grant_capability($role, $capability) {
        $role_obj = get_role($role);

        if (!$role_obj) {
            return false;
        }

        if (!isset(self::$capabilities[$capability])) {
            return false;
        }

        $role_obj->add_cap($capability);
        return true;
    }

    /**
     * Revoke capability from role
     *
     * @since  1.0.12
     * @param  string $role Role name
     * @param  string $capability Capability to revoke
     * @return bool True on success
     */
    public static function revoke_capability($role, $capability) {
        $role_obj = get_role($role);

        if (!$role_obj) {
            return false;
        }

        $role_obj->remove_cap($capability);
        return true;
    }

    /**
     * Get user's granted capabilities
     *
     * @since  1.0.12
     * @param  int $user_id User ID
     * @return array List of capabilities user has
     */
    public static function get_user_capabilities($user_id = null) {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }

        $granted = array();

        foreach (self::$capabilities as $cap => $label) {
            if (self::check($cap, $user_id)) {
                $granted[$cap] = $label;
            }
        }

        return $granted;
    }
}
