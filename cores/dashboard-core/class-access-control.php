<?php

/**
 * Access Control - Multi-tier permission system for Raw Wire Dashboard
 * 
 * Provides separation between developer/team controls and client controls.
 * Supports deployment modes, custom capabilities, and granular feature gating.
 *
 * @package RawWire\Dashboard\Cores\DashboardCore
 * @since 1.0.27
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class RawWire_Access_Control
 * 
 * Central authority for permission checks across Raw Wire Dashboard.
 */
class RawWire_Access_Control
{

    /**
     * Singleton instance
     * @var RawWire_Access_Control|null
     */
    private static $instance = null;

    /**
     * Access tiers
     */
    const TIER_DEVELOPER = 'developer';
    const TIER_ADMIN     = 'admin';      // Client site admin
    const TIER_EDITOR    = 'editor';     // Client content editor
    const TIER_VIEWER    = 'viewer';     // Read-only access

    /**
     * Deployment modes
     */
    const MODE_INTERNAL   = 'internal';    // Our own sites/testing
    const MODE_CLIENT     = 'client';      // Client deployment
    const MODE_DEMO       = 'demo';        // Demo/trial mode

    /**
     * Custom capabilities
     */
    const CAP_DEVELOPER    = 'rawwire_developer';      // Full access to all settings
    const CAP_MANAGE       = 'rawwire_manage';         // Manage workflows/scrapers
    const CAP_CONFIGURE    = 'rawwire_configure';      // Configure client-level settings
    const CAP_VIEW         = 'rawwire_view';           // View dashboard/reports
    const CAP_USE_AI       = 'rawwire_use_ai';         // Use AI features

    /**
     * Feature permissions map
     * Keys are feature identifiers, values are minimum required tier
     * 
     * @var array
     */
    private $feature_permissions = [];

    /**
     * Settings permissions - what each tier can access
     * 
     * @var array
     */
    private $settings_permissions = [];

    /**
     * Tool permissions - which tools each tier can use
     * 
     * @var array
     */
    private $tool_permissions = [];

    /**
     * Get singleton instance
     * 
     * @return RawWire_Access_Control
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor
     */
    private function __construct()
    {
        $this->init_permissions();

        add_action('init', [$this, 'register_capabilities']);
        add_filter('user_has_cap', [$this, 'filter_user_caps'], 10, 4);
    }

    /**
     * Initialize permission matrices
     */
    private function init_permissions()
    {
        // Feature permissions - minimum tier required
        $this->feature_permissions = [
            // Developer-only features
            'ai_model_selection'     => self::TIER_DEVELOPER,
            'ai_provider_config'     => self::TIER_DEVELOPER,
            'mcp_server_config'      => self::TIER_DEVELOPER,
            'debug_logging'          => self::TIER_DEVELOPER,
            'adapter_management'     => self::TIER_DEVELOPER,
            'tool_toggle'            => self::TIER_DEVELOPER,
            'system_diagnostics'     => self::TIER_DEVELOPER,
            'deployment_settings'    => self::TIER_DEVELOPER,
            'template_management'    => self::TIER_DEVELOPER,

            // Admin features (client site admin)
            'api_key_config'         => self::TIER_ADMIN,  // Client provides their own keys
            'workflow_management'    => self::TIER_ADMIN,
            'scraper_management'     => self::TIER_ADMIN,
            'schedule_management'    => self::TIER_ADMIN,
            'notification_settings'  => self::TIER_ADMIN,
            'content_preferences'    => self::TIER_ADMIN,
            'user_management'        => self::TIER_ADMIN,

            // Editor features
            'workflow_trigger'       => self::TIER_EDITOR,
            'content_generation'     => self::TIER_EDITOR,
            'content_scoring'        => self::TIER_EDITOR,
            'data_export'            => self::TIER_EDITOR,

            // Viewer features
            'dashboard_view'         => self::TIER_VIEWER,
            'reports_view'           => self::TIER_VIEWER,
            'activity_view'          => self::TIER_VIEWER,
        ];

        // Settings page sections and their required tier
        $this->settings_permissions = [
            // Developer Settings (Settings page)
            'section_deployment'     => self::TIER_DEVELOPER,
            'section_ai_engine'      => self::TIER_DEVELOPER,
            'section_mcp'            => self::TIER_DEVELOPER,
            'section_debug'          => self::TIER_DEVELOPER,
            'section_tools'          => self::TIER_DEVELOPER,
            'section_adapters'       => self::TIER_DEVELOPER,

            // Client Settings (rendered in dashboard or client settings page)
            'section_api_keys'       => self::TIER_ADMIN,
            'section_workflows'      => self::TIER_ADMIN,
            'section_scrapers'       => self::TIER_ADMIN,
            'section_schedules'      => self::TIER_ADMIN,
            'section_notifications'  => self::TIER_ADMIN,
            'section_content'        => self::TIER_ADMIN,
        ];

        // Tool permissions - which tools require which tier
        $this->tool_permissions = [
            // Developer-only tools
            'wp_system_info'         => self::TIER_DEVELOPER,
            'wp_plugins'             => self::TIER_DEVELOPER,
            'wp_error_log'           => self::TIER_DEVELOPER,
            'wp_database'            => self::TIER_DEVELOPER,
            'wp_health_check'        => self::TIER_DEVELOPER,
            'wp_cron'                => self::TIER_DEVELOPER,
            'wp_options'             => self::TIER_DEVELOPER,
            'wp_transients'          => self::TIER_DEVELOPER,
            'wp_hooks'               => self::TIER_DEVELOPER,
            'wp_memory'              => self::TIER_DEVELOPER,

            // Admin tools
            'scraper_list_sources'   => self::TIER_ADMIN,
            'scraper_run'            => self::TIER_ADMIN,
            'scraper_add_source'     => self::TIER_ADMIN,
            'tool_execute'           => self::TIER_ADMIN,
            'tool_schedule'          => self::TIER_ADMIN,
            'workflow_create'        => self::TIER_ADMIN,
            'workflow_trigger'       => self::TIER_ADMIN,

            // Editor tools
            'content_score'          => self::TIER_EDITOR,
            'content_generate'       => self::TIER_EDITOR,
            'content_summarize'      => self::TIER_EDITOR,
            'data_query'             => self::TIER_EDITOR,

            // Viewer tools
            'tools_list'             => self::TIER_VIEWER,
            'stats_get'              => self::TIER_VIEWER,
        ];

        // Allow filtering of permissions
        $this->feature_permissions = apply_filters('rawwire_feature_permissions', $this->feature_permissions);
        $this->settings_permissions = apply_filters('rawwire_settings_permissions', $this->settings_permissions);
        $this->tool_permissions = apply_filters('rawwire_tool_permissions', $this->tool_permissions);
    }

    /**
     * Register custom capabilities on role
     */
    public function register_capabilities()
    {
        // Only run once
        if (get_option('rawwire_caps_registered', false)) {
            return;
        }

        $admin = get_role('administrator');
        if ($admin) {
            $admin->add_cap(self::CAP_MANAGE);
            $admin->add_cap(self::CAP_CONFIGURE);
            $admin->add_cap(self::CAP_VIEW);
            $admin->add_cap(self::CAP_USE_AI);

            // Developer cap only if deployment mode is internal
            if ($this->get_deployment_mode() === self::MODE_INTERNAL) {
                $admin->add_cap(self::CAP_DEVELOPER);
            }
        }

        $editor = get_role('editor');
        if ($editor) {
            $editor->add_cap(self::CAP_VIEW);
            $editor->add_cap(self::CAP_USE_AI);
        }

        update_option('rawwire_caps_registered', true);
    }

    /**
     * Filter user capabilities for dynamic checks
     * 
     * @param array   $allcaps All capabilities
     * @param array   $caps    Required caps
     * @param array   $args    Arguments
     * @param WP_User $user    User object
     * @return array
     */
    public function filter_user_caps($allcaps, $caps, $args, $user)
    {
        // Grant developer cap to super admins in internal mode
        if ($this->get_deployment_mode() === self::MODE_INTERNAL) {
            if (isset($allcaps['manage_network']) || isset($allcaps['manage_options'])) {
                $allcaps[self::CAP_DEVELOPER] = true;
            }
        }

        // Check for developer override (useful for support access)
        if ($this->has_developer_override($user->ID)) {
            $allcaps[self::CAP_DEVELOPER] = true;
        }

        return $allcaps;
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Get current deployment mode
     * 
     * @return string
     */
    public function get_deployment_mode()
    {
        // Check for constant override first
        if (defined('RAWWIRE_DEPLOYMENT_MODE')) {
            return RAWWIRE_DEPLOYMENT_MODE;
        }

        return get_option('rawwire_deployment_mode', self::MODE_CLIENT);
    }

    /**
     * Set deployment mode
     * 
     * @param string $mode
     * @return bool
     */
    public function set_deployment_mode($mode)
    {
        if (!in_array($mode, [self::MODE_INTERNAL, self::MODE_CLIENT, self::MODE_DEMO])) {
            return false;
        }

        update_option('rawwire_deployment_mode', $mode);

        // Re-register capabilities
        delete_option('rawwire_caps_registered');
        $this->register_capabilities();

        return true;
    }

    /**
     * Check if current user is developer tier
     * 
     * @return bool
     */
    public function is_developer()
    {
        return current_user_can(self::CAP_DEVELOPER) ||
            ($this->get_deployment_mode() === self::MODE_INTERNAL && current_user_can('manage_options'));
    }

    /**
     * Check if current user is admin tier (or higher)
     * 
     * @return bool
     */
    public function is_admin()
    {
        return $this->is_developer() || current_user_can('manage_options');
    }

    /**
     * Check if current user is editor tier (or higher)
     * 
     * @return bool
     */
    public function is_editor()
    {
        return $this->is_admin() || current_user_can('edit_others_posts');
    }

    /**
     * Check if current user is viewer tier (or higher)
     * 
     * @return bool
     */
    public function is_viewer()
    {
        return $this->is_editor() || current_user_can(self::CAP_VIEW);
    }

    /**
     * Get user's access tier
     * 
     * @param int|null $user_id
     * @return string
     */
    public function get_user_tier($user_id = null)
    {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }

        if (
            user_can($user_id, self::CAP_DEVELOPER) ||
            ($this->get_deployment_mode() === self::MODE_INTERNAL && user_can($user_id, 'manage_options'))
        ) {
            return self::TIER_DEVELOPER;
        }

        if (user_can($user_id, 'manage_options')) {
            return self::TIER_ADMIN;
        }

        if (user_can($user_id, 'edit_others_posts')) {
            return self::TIER_EDITOR;
        }

        if (user_can($user_id, self::CAP_VIEW)) {
            return self::TIER_VIEWER;
        }

        return '';
    }

    /**
     * Check if user can access a feature
     * 
     * @param string   $feature
     * @param int|null $user_id
     * @return bool
     */
    public function can_access_feature($feature, $user_id = null)
    {
        if (!isset($this->feature_permissions[$feature])) {
            // Unknown features require developer access by default
            return $this->is_developer();
        }

        $required_tier = $this->feature_permissions[$feature];
        $user_tier = $this->get_user_tier($user_id);

        return $this->tier_meets_requirement($user_tier, $required_tier);
    }

    /**
     * Check if user can access a settings section
     * 
     * @param string   $section
     * @param int|null $user_id
     * @return bool
     */
    public function can_access_settings($section, $user_id = null)
    {
        if (!isset($this->settings_permissions[$section])) {
            return $this->is_developer();
        }

        $required_tier = $this->settings_permissions[$section];
        $user_tier = $this->get_user_tier($user_id);

        return $this->tier_meets_requirement($user_tier, $required_tier);
    }

    /**
     * Check if user can use a tool
     * 
     * @param string   $tool_name
     * @param int|null $user_id
     * @return bool
     */
    public function can_use_tool($tool_name, $user_id = null)
    {
        // Normalize tool name (strip rawwire_ prefix if present)
        $tool_key = preg_replace('/^rawwire_/', '', $tool_name);

        if (!isset($this->tool_permissions[$tool_key])) {
            // Unknown tools require admin access by default
            return $this->is_admin();
        }

        $required_tier = $this->tool_permissions[$tool_key];
        $user_tier = $this->get_user_tier($user_id);

        return $this->tier_meets_requirement($user_tier, $required_tier);
    }

    /**
     * Get all features accessible by current user
     * 
     * @return array
     */
    public function get_accessible_features()
    {
        $accessible = [];
        $user_tier = $this->get_user_tier();

        foreach ($this->feature_permissions as $feature => $required_tier) {
            if ($this->tier_meets_requirement($user_tier, $required_tier)) {
                $accessible[] = $feature;
            }
        }

        return $accessible;
    }

    /**
     * Get all tools accessible by current user
     * 
     * @return array
     */
    public function get_accessible_tools()
    {
        $accessible = [];
        $user_tier = $this->get_user_tier();

        foreach ($this->tool_permissions as $tool => $required_tier) {
            if ($this->tier_meets_requirement($user_tier, $required_tier)) {
                $accessible[] = $tool;
            }
        }

        return $accessible;
    }

    /**
     * Get settings sections accessible by current user
     * 
     * @return array
     */
    public function get_accessible_settings()
    {
        $accessible = [];
        $user_tier = $this->get_user_tier();

        foreach ($this->settings_permissions as $section => $required_tier) {
            if ($this->tier_meets_requirement($user_tier, $required_tier)) {
                $accessible[] = $section;
            }
        }

        return $accessible;
    }

    // =========================================================================
    // DEVELOPER OVERRIDE
    // =========================================================================

    /**
     * Check if user has developer override
     * 
     * @param int $user_id
     * @return bool
     */
    private function has_developer_override($user_id)
    {
        // Check user meta for support override
        $override = get_user_meta($user_id, '_rawwire_developer_override', true);

        if (!$override) {
            return false;
        }

        // Check if override is still valid (expires after 24 hours)
        if (is_array($override) && isset($override['expires'])) {
            if (time() > $override['expires']) {
                delete_user_meta($user_id, '_rawwire_developer_override');
                return false;
            }
            return true;
        }

        return false;
    }

    /**
     * Grant developer override to user (for support access)
     * 
     * @param int $user_id
     * @param int $duration_hours
     * @return bool
     */
    public function grant_developer_override($user_id, $duration_hours = 24)
    {
        // Only developers can grant overrides
        if (!$this->is_developer()) {
            return false;
        }

        update_user_meta($user_id, '_rawwire_developer_override', [
            'granted_by' => get_current_user_id(),
            'granted_at' => time(),
            'expires'    => time() + ($duration_hours * HOUR_IN_SECONDS),
        ]);

        return true;
    }

    /**
     * Revoke developer override from user
     * 
     * @param int $user_id
     * @return bool
     */
    public function revoke_developer_override($user_id)
    {
        return delete_user_meta($user_id, '_rawwire_developer_override');
    }

    // =========================================================================
    // MCP TOOL PERMISSIONS
    // =========================================================================

    /**
     * Check if current user can use a specific MCP tool
     * 
     * Integrates with Tool Toggle Manager for enable/disable state
     * and applies tier-based restrictions.
     * 
     * @param string $tool_name MCP tool name (e.g., 'wp_plugin_manage')
     * @return bool
     */
    public function can_use_mcp_tool($tool_name)
    {
        // Developers can always use enabled tools
        if ($this->is_developer()) {
            // Still check if tool is enabled
            if (function_exists('rawwire_tools')) {
                return rawwire_tools()->is_tool_enabled($this->mcp_tool_to_toggle($tool_name));
            }
            return true;
        }

        // Map MCP tool name to toggle ID and check permissions
        $toggle_id = $this->mcp_tool_to_toggle($tool_name);

        // Check tool toggle state
        if (function_exists('rawwire_tools')) {
            $tool_manager = rawwire_tools();

            if (!$tool_manager->is_tool_enabled($toggle_id)) {
                return false;
            }

            // Get tool definition for tier requirement
            $definition = $tool_manager->get_tool_definition($toggle_id);
            if ($definition) {
                $required_tier = $definition['tier'] ?? self::TIER_ADMIN;
                return $this->tier_meets_requirement($this->get_user_tier(), $required_tier);
            }
        }

        // Fall back to tool permissions array
        $tool_tier = $this->tool_permissions[$tool_name] ?? self::TIER_DEVELOPER;
        return $this->tier_meets_requirement($this->get_user_tier(), $tool_tier);
    }

    /**
     * Map MCP tool name to toggle manager ID
     * 
     * @param string $tool_name
     * @return string
     */
    private function mcp_tool_to_toggle($tool_name)
    {
        // Mapping of MCP tool names to toggle IDs
        $mapping = [
            // Diagnostic tools
            'wp_debug_system_info'  => 'diagnostics_system',
            'wp_debug_plugins'      => 'diagnostics_plugins',
            'wp_debug_error_log'    => 'diagnostics_logs',
            'wp_debug_database'     => 'diagnostics_database',
            'wp_debug_health_check' => 'diagnostics_system',
            'wp_debug_cron'         => 'diagnostics_cron',
            'wp_debug_options'      => 'diagnostics_options',
            'wp_debug_transients'   => 'diagnostics_options',
            'wp_debug_hooks'        => 'diagnostics_system',
            'wp_debug_memory'       => 'diagnostics_system',

            // Action tools
            'wp_plugin_manage'      => 'action_plugin_manage',
            'wp_option_update'      => 'action_option_update',
            'wp_theme_manage'       => 'action_theme_manage',
            'wp_cache_clear'        => 'action_cache_clear',
            'wp_file_edit'          => 'action_file_edit',

            // Repair tools
            'rawwire_repair_database' => 'action_db_repair',
            'rawwire_safe_mode'       => 'action_safe_mode',

            // Workflow tools
            'rawwire_workflow_create'  => 'workflow_engine',
            'rawwire_workflow_list'    => 'workflow_engine',
            'rawwire_workflow_trigger' => 'workflow_engine',
            'rawwire_workflow_delete'  => 'workflow_engine',

            // Scraper tools
            'rawwire_scraper_list_sources' => 'scraper_rss',
            'rawwire_scraper_run'          => 'scraper_rss',
            'rawwire_scraper_add_source'   => 'scraper_rss',

            // AI tools
            'rawwire_content_score'    => 'ai_scoring',
            'rawwire_content_generate' => 'ai_generation',
            'rawwire_content_summarize' => 'ai_summarization',

            // MCP server
            'mcp_server'               => 'mcp_server',
        ];

        return $mapping[$tool_name] ?? $tool_name;
    }

    /**
     * Get list of allowed MCP tools for current user
     * 
     * @return array
     */
    public function get_allowed_mcp_tools()
    {
        $allowed = [];

        if (!function_exists('rawwire_tools')) {
            return $allowed;
        }

        $tool_manager = rawwire_tools();
        $all_tools = $tool_manager->get_enabled_tools();

        foreach ($all_tools as $toggle_id) {
            $definition = $tool_manager->get_tool_definition($toggle_id);
            if (!$definition) continue;

            $required_tier = $definition['tier'] ?? self::TIER_ADMIN;

            if ($this->tier_meets_requirement($this->get_user_tier(), $required_tier)) {
                $allowed[] = $toggle_id;
            }
        }

        return $allowed;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Check if user tier meets requirement
     * 
     * @param string $user_tier
     * @param string $required_tier
     * @return bool
     */
    private function tier_meets_requirement($user_tier, $required_tier)
    {
        $tier_hierarchy = [
            self::TIER_DEVELOPER => 4,
            self::TIER_ADMIN     => 3,
            self::TIER_EDITOR    => 2,
            self::TIER_VIEWER    => 1,
            ''                   => 0,
        ];

        $user_level = $tier_hierarchy[$user_tier] ?? 0;
        $required_level = $tier_hierarchy[$required_tier] ?? 4;

        return $user_level >= $required_level;
    }

    /**
     * Get tier label
     * 
     * @param string $tier
     * @return string
     */
    public function get_tier_label($tier)
    {
        $labels = [
            self::TIER_DEVELOPER => __('Developer', 'raw-wire-dashboard'),
            self::TIER_ADMIN     => __('Administrator', 'raw-wire-dashboard'),
            self::TIER_EDITOR    => __('Editor', 'raw-wire-dashboard'),
            self::TIER_VIEWER    => __('Viewer', 'raw-wire-dashboard'),
        ];

        return $labels[$tier] ?? $tier;
    }

    /**
     * Get deployment mode label
     * 
     * @param string $mode
     * @return string
     */
    public function get_mode_label($mode)
    {
        $labels = [
            self::MODE_INTERNAL => __('Internal (Development)', 'raw-wire-dashboard'),
            self::MODE_CLIENT   => __('Client Deployment', 'raw-wire-dashboard'),
            self::MODE_DEMO     => __('Demo Mode', 'raw-wire-dashboard'),
        ];

        return $labels[$mode] ?? $mode;
    }
}

/**
 * Helper function to get access control instance
 * 
 * @return RawWire_Access_Control
 */
function rawwire_access()
{
    return RawWire_Access_Control::get_instance();
}
