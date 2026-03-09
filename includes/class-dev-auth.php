<?php

/**
 * Developer Authentication Handler
 * Path: includes/class-dev-auth.php
 *
 * Manages developer mode access for the Raw-Wire Dashboard.
 * Developer mode unlocks the builder toolbar and developer submenu pages.
 *
 * Client-facing dashboard always shows: header, template placeholders, event log.
 * Developer mode additionally shows: Templates, AI Agents, Tools, Options submenus
 * and the builder toolbar above the dashboard header.
 *
 * Authentication uses a dedicated dev password stored as a hash in wp_options.
 * Dev sessions are tracked via a per-user transient with configurable TTL.
 *
 * @package RawWire_Dashboard
 * @subpackage Core
 * @since 1.0.30
 */

if (!defined('ABSPATH')) {
    exit;
}

class RawWire_Dev_Auth
{

    /**
     * Option key for the hashed developer password
     * @var string
     */
    const OPTION_KEY = 'rawwire_dev_password_hash';

    /**
     * Transient prefix for dev sessions (appended with user ID)
     * @var string
     */
    const TRANSIENT_PREFIX = 'rawwire_dev_session_';

    /**
     * Default session TTL in seconds (8 hours)
     * @var int
     */
    const SESSION_TTL = 28800;

    /**
     * Default developer credentials (change on first use)
     * @var string
     */
    const DEFAULT_USERNAME = 'developer';
    const DEFAULT_PASSWORD = 'rawwire_dev_2026';

    /**
     * Initialize AJAX handlers
     */
    public static function init()
    {
        add_action('wp_ajax_rawwire_dev_login', array(__CLASS__, 'ajax_dev_login'));
        add_action('wp_ajax_rawwire_dev_logout', array(__CLASS__, 'ajax_dev_logout'));
        add_action('wp_ajax_rawwire_dev_change_password', array(__CLASS__, 'ajax_dev_change_password'));
        add_action('wp_ajax_rawwire_save_builder_template', array(__CLASS__, 'ajax_save_builder_template'));
    }

    /**
     * Check if current user has an active developer session
     *
     * @return bool
     */
    public static function is_dev_mode_active()
    {
        if (!is_user_logged_in()) {
            return false;
        }

        $user_id = get_current_user_id();
        $session = get_transient(self::TRANSIENT_PREFIX . $user_id);

        return !empty($session) && $session === 'active';
    }

    /**
     * Verify developer credentials
     *
     * @param string $username
     * @param string $password
     * @return bool
     */
    public static function verify_credentials($username, $password)
    {
        // Username must match
        if ($username !== self::DEFAULT_USERNAME) {
            return false;
        }

        // Check stored hash
        $stored_hash = get_option(self::OPTION_KEY, '');

        if (empty($stored_hash)) {
            // No password set yet - accept default and store hash
            if ($password === self::DEFAULT_PASSWORD) {
                update_option(self::OPTION_KEY, wp_hash_password($password));
                return true;
            }
            return false;
        }

        // Verify against stored hash
        return wp_check_password($password, $stored_hash);
    }

    /**
     * Activate developer session for current user
     *
     * @return bool
     */
    public static function activate_session()
    {
        if (!is_user_logged_in()) {
            return false;
        }

        $user_id = get_current_user_id();
        return set_transient(
            self::TRANSIENT_PREFIX . $user_id,
            'active',
            self::SESSION_TTL
        );
    }

    /**
     * Deactivate developer session for current user
     *
     * @return bool
     */
    public static function deactivate_session()
    {
        if (!is_user_logged_in()) {
            return false;
        }

        $user_id = get_current_user_id();
        return delete_transient(self::TRANSIENT_PREFIX . $user_id);
    }

    /**
     * AJAX: Developer login
     */
    public static function ajax_dev_login()
    {
        check_ajax_referer('rawwire_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $username = sanitize_text_field($_POST['dev_username'] ?? '');
        $password = $_POST['dev_password'] ?? '';

        if (empty($username) || empty($password)) {
            wp_send_json_error(array('message' => 'Username and password required'));
        }

        if (!self::verify_credentials($username, $password)) {
            wp_send_json_error(array('message' => 'Invalid developer credentials'));
        }

        self::activate_session();

        wp_send_json_success(array(
            'message' => 'Developer mode activated',
            'devMode' => true,
        ));
    }

    /**
     * AJAX: Developer logout
     */
    public static function ajax_dev_logout()
    {
        check_ajax_referer('rawwire_admin_nonce', 'nonce');

        self::deactivate_session();

        wp_send_json_success(array(
            'message' => 'Developer mode deactivated',
            'devMode' => false,
        ));
    }

    /**
     * AJAX: Change developer password
     */
    public static function ajax_dev_change_password()
    {
        check_ajax_referer('rawwire_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';

        if (empty($current_password) || empty($new_password)) {
            wp_send_json_error(array('message' => 'Both passwords required'));
        }

        if (strlen($new_password) < 8) {
            wp_send_json_error(array('message' => 'Password must be at least 8 characters'));
        }

        // Verify current password
        $stored_hash = get_option(self::OPTION_KEY, '');
        if (!empty($stored_hash) && !wp_check_password($current_password, $stored_hash)) {
            wp_send_json_error(array('message' => 'Current password incorrect'));
        }

        // Store new hash
        update_option(self::OPTION_KEY, wp_hash_password($new_password));

        wp_send_json_success(array('message' => 'Password updated'));
    }

    /**
     * AJAX: Save builder layout as a template JSON file
     *
     * Takes the serialized layout from the builder UI and converts it
     * into a valid template JSON file saved to the templates/ directory.
     *
     * Layout structure from JS:
     * { rows: [ { id, columns, type, slots: [ { id, type, item } ] } ] }
     *
     * Generated template structure:
     * { meta, css, pages: { dashboard: { panels: [...] } }, panels: { ... } }
     */
    public static function ajax_save_builder_template()
    {
        check_ajax_referer('rawwire_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        if (!self::is_dev_mode_active()) {
            wp_send_json_error('Developer mode required');
        }

        $template_name = sanitize_text_field($_POST['template_name'] ?? '');
        $template_id = sanitize_title($_POST['template_id'] ?? '');
        $template_desc = sanitize_textarea_field($_POST['template_desc'] ?? '');
        $layout_json = $_POST['layout'] ?? '';

        if (empty($template_name)) {
            wp_send_json_error('Template name is required');
        }

        if (empty($template_id)) {
            $template_id = sanitize_title($template_name);
        }

        $layout = json_decode(stripslashes($layout_json), true);
        if (!is_array($layout) || empty($layout['rows'])) {
            wp_send_json_error('Invalid layout data');
        }

        // Build template from layout
        $template = self::build_template_from_layout($template_id, $template_name, $template_desc, $layout);

        // Save to templates directory
        $templates_dir = plugin_dir_path(__DIR__) . 'templates/';
        if (!is_dir($templates_dir)) {
            wp_mkdir_p($templates_dir);
        }

        $file_path = $templates_dir . $template_id . '.template.json';
        $result = file_put_contents(
            $file_path,
            wp_json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        if ($result === false) {
            wp_send_json_error('Failed to write template file');
        }

        wp_send_json_success(array(
            'message' => 'Template saved',
            'template_id' => $template_id,
            'file' => $template_id . '.template.json',
        ));
    }

    /**
     * Convert builder layout into a complete template JSON structure
     *
     * @param string $id       Template slug
     * @param string $name     Template display name
     * @param string $desc     Template description
     * @param array  $layout   Builder layout { rows: [ ... ] }
     * @return array Complete template array
     */
    private static function build_template_from_layout($id, $name, $desc, $layout)
    {
        // Start with the current active template as a base (inherit CSS, toolbox, etc.)
        $base = array();
        if (class_exists('RawWire_Template_Engine')) {
            $active = RawWire_Template_Engine::get_template();
            if (is_array($active)) {
                // Inherit everything except meta, pages, and panels
                $base = $active;
            }
        }

        // Build meta
        $template = $base;
        $template['meta'] = array(
            'id' => $id,
            'name' => $name,
            'version' => '1.0.0',
            'description' => $desc,
            'author' => wp_get_current_user()->display_name,
            'icon' => 'dashicons-layout',
        );

        // Build panels and page layout from rows
        $panels = array();
        $page_panels = array();

        foreach ($layout['rows'] as $row_index => $row) {
            $row_id = 'builder_row_' . ($row_index + 1);
            $row_type = $row['type'] ?? 'standard';
            $columns = intval($row['columns'] ?? 2);
            $child_panel_ids = array();

            foreach ($row['slots'] as $slot_index => $slot) {
                $slot_panel_id = $row_id . '_slot_' . ($slot_index + 1);
                $slot_type = $slot['type'] ?? '';
                $slot_item = $slot['item'] ?? '';

                if (empty($slot_type) || empty($slot_item)) {
                    // Empty slot - create a spacer panel
                    $panels[$slot_panel_id] = array(
                        'id' => $slot_panel_id,
                        'type' => 'custom',
                        'title' => 'Empty',
                        'icon' => 'dashicons-minus',
                        'description' => 'Empty slot placeholder',
                        'customRenderer' => 'spacer',
                    );
                } else {
                    // Assigned slot - create panel stub
                    $panels[$slot_panel_id] = self::create_panel_stub($slot_panel_id, $slot_type, $slot_item, $row_type);
                }

                $child_panel_ids[] = $slot_panel_id;
            }

            // Create a row panel that contains the slots
            $panels[$row_id] = array(
                'id' => $row_id,
                'type' => 'row',
                'title' => 'Row ' . ($row_index + 1),
                'panels' => $child_panel_ids,
                'css' => array(
                    'gridTemplateColumns' => 'repeat(' . $columns . ', 1fr)',
                    'gap' => 'var(--rw-space-4)',
                    'display' => 'grid',
                ),
            );

            $page_panels[] = $row_id;
        }

        $template['panels'] = $panels;
        $template['pages'] = array(
            'dashboard' => array(
                'id' => 'dashboard',
                'title' => $name,
                'description' => $desc,
                'slug' => 'raw-wire-dashboard',
                'icon' => 'dashicons-layout',
                'isMain' => true,
                'layout' => 'dashboard-fixed',
                'panels' => $page_panels,
            ),
        );

        return $template;
    }

    /**
     * Create a panel stub based on slot type and item
     *
     * @param string $panel_id Unique panel identifier
     * @param string $type     tool, workflow, info, or info-only
     * @param string $item     Item identifier
     * @param string $row_type standard or info-only
     * @return array Panel definition
     */
    private static function create_panel_stub($panel_id, $type, $item, $row_type)
    {
        $panel = array(
            'id' => $panel_id,
            'type' => 'custom',
            'icon' => 'dashicons-admin-generic',
            'description' => '',
        );

        switch ($type) {
            case 'tool':
                $panel['type'] = 'custom';
                $panel['title'] = ucfirst(str_replace(array('-', '_'), ' ', $item));
                $panel['icon'] = 'dashicons-admin-tools';
                $panel['customRenderer'] = 'tool';
                $panel['config'] = array('tool_id' => $item);
                break;

            case 'workflow':
                $panel['type'] = 'progress';
                $panel['title'] = ucfirst(str_replace(array('-', '_'), ' ', $item));
                $panel['icon'] = 'dashicons-randomize';
                $panel['config'] = array('workflow_id' => $item);
                break;

            case 'info':
                $panel['type'] = $item; // status, log, control, data, custom
                $panel['title'] = ucfirst($item) . ' Panel';
                $panel['icon'] = 'dashicons-info-outline';
                break;

            case 'info-only':
                switch ($item) {
                    case 'card':
                        $panel['type'] = 'custom';
                        $panel['title'] = 'Info Card';
                        $panel['icon'] = 'dashicons-id-alt';
                        $panel['customRenderer'] = 'info_card';
                        break;
                    case 'button':
                        $panel['type'] = 'control';
                        $panel['title'] = 'Action';
                        $panel['icon'] = 'dashicons-button';
                        break;
                    case 'spacer':
                        $panel['type'] = 'custom';
                        $panel['title'] = '';
                        $panel['icon'] = 'dashicons-minus';
                        $panel['customRenderer'] = 'spacer';
                        break;
                    case 'stat':
                        $panel['type'] = 'status';
                        $panel['title'] = 'Stat';
                        $panel['icon'] = 'dashicons-performance';
                        $panel['compact'] = true;
                        break;
                    case 'notice':
                        $panel['type'] = 'custom';
                        $panel['title'] = 'Notice';
                        $panel['icon'] = 'dashicons-megaphone';
                        $panel['customRenderer'] = 'notice';
                        break;
                }
                break;
        }

        return $panel;
    }
}
