<?php

/**
 * Tool Toggle Manager - Developer controls for enabling/disabling toolkit features
 * 
 * Provides UI and logic for toggling tools on/off.
 * When a tool is enabled, its client-facing controls become available.
 * 
 * @package RawWire\Dashboard\Cores\ToolboxCore
 * @since 1.0.27
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class RawWire_Tool_Toggle_Manager
 */
class RawWire_Tool_Toggle_Manager
{

    /**
     * Singleton instance
     * @var RawWire_Tool_Toggle_Manager|null
     */
    private static $instance = null;

    /**
     * Option name for tool states
     */
    const OPTION_KEY = 'rawwire_tool_states';

    /**
     * Tool definitions with metadata
     * @var array
     */
    private $tool_definitions = [];

    /**
     * Get singleton instance
     * 
     * @return RawWire_Tool_Toggle_Manager
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
        $this->init_tool_definitions();

        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_ajax_rawwire_toggle_tool', [$this, 'ajax_toggle_tool']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_toggle_assets']);
    }

    /**
     * Initialize tool definitions
     * 
     * Each tool has:
     * - id: Unique identifier
     * - name: Display name
     * - description: What it does
     * - category: Tool category
     * - default: Default enabled state
     * - client_ui: Whether it has client-facing UI
     * - client_location: Where client UI renders (dashboard, settings, submenu)
     * - dependencies: Other tools this requires
     * - tier: Minimum tier that can use this when enabled
     */
    private function init_tool_definitions()
    {
        $this->tool_definitions = [
            // =====================================================================
            // SCRAPER TOOLS
            // =====================================================================
            'scraper_rss' => [
                'id'              => 'scraper_rss',
                'name'            => __('RSS Scraper', 'raw-wire-dashboard'),
                'description'     => __('Collect content from RSS feeds', 'raw-wire-dashboard'),
                'category'        => 'scraper',
                'default'         => true,
                'client_ui'       => true,
                'client_location' => 'dashboard',
                'dependencies'    => [],
                'tier'            => 'admin',
            ],
            'scraper_api' => [
                'id'              => 'scraper_api',
                'name'            => __('API Scraper', 'raw-wire-dashboard'),
                'description'     => __('Collect data from REST APIs', 'raw-wire-dashboard'),
                'category'        => 'scraper',
                'default'         => true,
                'client_ui'       => true,
                'client_location' => 'dashboard',
                'dependencies'    => [],
                'tier'            => 'admin',
            ],
            'scraper_html' => [
                'id'              => 'scraper_html',
                'name'            => __('HTML Scraper', 'raw-wire-dashboard'),
                'description'     => __('Scrape content from web pages', 'raw-wire-dashboard'),
                'category'        => 'scraper',
                'default'         => false,
                'client_ui'       => true,
                'client_location' => 'dashboard',
                'dependencies'    => [],
                'tier'            => 'admin',
            ],
            'scraper_brightdata' => [
                'id'              => 'scraper_brightdata',
                'name'            => __('BrightData Scraper', 'raw-wire-dashboard'),
                'description'     => __('Enterprise scraping via BrightData proxy', 'raw-wire-dashboard'),
                'category'        => 'scraper',
                'default'         => false,
                'client_ui'       => true,
                'client_location' => 'settings',
                'dependencies'    => [],
                'tier'            => 'admin',
            ],

            // =====================================================================
            // AI TOOLS
            // =====================================================================
            'ai_scoring' => [
                'id'              => 'ai_scoring',
                'name'            => __('AI Content Scoring', 'raw-wire-dashboard'),
                'description'     => __('Score content relevance and quality with AI', 'raw-wire-dashboard'),
                'category'        => 'ai',
                'default'         => true,
                'client_ui'       => true,
                'client_location' => 'dashboard',
                'dependencies'    => ['ai_engine'],
                'tier'            => 'editor',
            ],
            'ai_generation' => [
                'id'              => 'ai_generation',
                'name'            => __('AI Content Generation', 'raw-wire-dashboard'),
                'description'     => __('Generate articles and content with AI', 'raw-wire-dashboard'),
                'category'        => 'ai',
                'default'         => true,
                'client_ui'       => true,
                'client_location' => 'dashboard',
                'dependencies'    => ['ai_engine'],
                'tier'            => 'editor',
            ],
            'ai_summarization' => [
                'id'              => 'ai_summarization',
                'name'            => __('AI Summarization', 'raw-wire-dashboard'),
                'description'     => __('Summarize long content automatically', 'raw-wire-dashboard'),
                'category'        => 'ai',
                'default'         => true,
                'client_ui'       => true,
                'client_location' => 'dashboard',
                'dependencies'    => ['ai_engine'],
                'tier'            => 'editor',
            ],
            'ai_chat' => [
                'id'              => 'ai_chat',
                'name'            => __('AI Chat Assistant', 'raw-wire-dashboard'),
                'description'     => __('Embedded AI chat for dashboard assistance', 'raw-wire-dashboard'),
                'category'        => 'ai',
                'default'         => true,
                'client_ui'       => true,
                'client_location' => 'global',
                'dependencies'    => ['ai_engine'],
                'tier'            => 'viewer',
            ],

            // =====================================================================
            // PUBLISHER TOOLS
            // =====================================================================
            'poster_wordpress' => [
                'id'              => 'poster_wordpress',
                'name'            => __('WordPress Publisher', 'raw-wire-dashboard'),
                'description'     => __('Publish content to WordPress posts/pages', 'raw-wire-dashboard'),
                'category'        => 'publisher',
                'default'         => true,
                'client_ui'       => false,  // UI not yet implemented
                'client_location' => null,
                'dependencies'    => [],
                'tier'            => 'editor',
            ],
            'poster_discord' => [
                'id'              => 'poster_discord',
                'name'            => __('Discord Publisher', 'raw-wire-dashboard'),
                'description'     => __('Post content to Discord via webhooks', 'raw-wire-dashboard'),
                'category'        => 'publisher',
                'default'         => false,
                'client_ui'       => false,  // UI not yet implemented
                'client_location' => null,
                'dependencies'    => [],
                'tier'            => 'admin',
            ],

            // =====================================================================
            // WORKFLOW TOOLS
            // =====================================================================
            'workflow_engine' => [
                'id'              => 'workflow_engine',
                'name'            => __('Workflow Engine', 'raw-wire-dashboard'),
                'description'     => __('Automated multi-step workflows', 'raw-wire-dashboard'),
                'category'        => 'utility',
                'default'         => true,
                'client_ui'       => true,
                'client_location' => 'submenu',
                'dependencies'    => [],
                'tier'            => 'admin',
                // Dashboard stat card bindings for this tool
                'stats' => [
                    [
                        'id'     => 'sources_count',
                        'label'  => 'Sources',
                        'source' => 'db:sources:count',
                        'format' => 'number',
                        'icon'   => 'dashicons-admin-site',
                    ],
                    [
                        'id'     => 'candidates_count',
                        'label'  => 'Candidates',
                        'source' => 'db:candidates:count',
                        'format' => 'number',
                        'icon'   => 'dashicons-download',
                    ],
                    [
                        'id'     => 'content_count',
                        'label'  => 'Content',
                        'source' => 'db:content:count',
                        'format' => 'number',
                        'icon'   => 'dashicons-edit',
                    ],
                    [
                        'id'     => 'published_count',
                        'label'  => 'Published',
                        'source' => 'db:published:count',
                        'format' => 'number',
                        'icon'   => 'dashicons-yes-alt',
                        'highlight' => 'success',
                    ],
                ],
                // Automation workflow config for progress bar
                'automations' => [],
            ],
            'workflow_scheduler' => [
                'id'              => 'workflow_scheduler',
                'name'            => __('Workflow Scheduler', 'raw-wire-dashboard'),
                'description'     => __('Schedule workflows to run automatically', 'raw-wire-dashboard'),
                'category'        => 'utility',
                'default'         => true,
                'client_ui'       => false,  // UI not yet implemented
                'client_location' => null,
                'dependencies'    => ['workflow_engine'],
                'tier'            => 'admin',
            ],

            // =====================================================================
            // LEAD GENERATION TOOLS
            // =====================================================================
            'lead_generator' => [
                'id'              => 'lead_generator',
                'name'            => __('Lead Generator', 'raw-wire-dashboard'),
                'description'     => __('Socrata permit pipeline for lead generation and enrichment', 'raw-wire-dashboard'),
                'category'        => 'utility',
                'default'         => true,
                'client_ui'       => true,
                'client_location' => 'submenu',
                'dependencies'    => [],
                'tier'            => 'admin',
                // Dashboard stat card bindings for this tool
                'stats' => [
                    [
                        'id'     => 'lead_sources_count',
                        'label'  => 'Sources',
                        'source' => 'db:lead_sources:count',
                        'format' => 'number',
                        'icon'   => 'dashicons-database',
                    ],
                    [
                        'id'     => 'lead_candidates_count',
                        'label'  => 'Candidates',
                        'source' => 'db:lead_candidates:count',
                        'format' => 'number',
                        'icon'   => 'dashicons-filter',
                    ],
                    [
                        'id'     => 'lead_content_count',
                        'label'  => 'Leads',
                        'source' => 'db:lead_content:count',
                        'format' => 'number',
                        'icon'   => 'dashicons-portfolio',
                        'highlight' => 'success',
                    ],
                    [
                        'id'     => 'lead_archive_count',
                        'label'  => 'Archived',
                        'source' => 'db:lead_archive:count',
                        'format' => 'number',
                        'icon'   => 'dashicons-archive',
                    ],
                ],
                // Automation workflow config for progress bar
                'automations' => [
                    [
                        'id'          => 'records_collection',
                        'label'       => 'Records Collection',
                        'description' => 'Import building permit records from Socrata',
                        'action'      => 'rawwire_lead_import',
                        'mode'        => 'collection',
                        'steps'       => [
                            ['id' => 'connect', 'label' => 'Connecting', 'icon' => 'dashicons-admin-site-alt3'],
                            ['id' => 'fetch', 'label' => 'Fetching', 'icon' => 'dashicons-download'],
                            ['id' => 'score', 'label' => 'Scoring', 'icon' => 'dashicons-chart-bar'],
                            ['id' => 'investigate', 'label' => 'Investigating', 'icon' => 'dashicons-search'],
                            ['id' => 'complete', 'label' => 'Complete', 'icon' => 'dashicons-yes-alt'],
                        ],
                    ],
                ],
            ],

            // =====================================================================
            // DIAGNOSTIC TOOLS (Developer only, no client UI)
            // =====================================================================
            'diagnostics_system' => [
                'id'              => 'diagnostics_system',
                'name'            => __('System Diagnostics', 'raw-wire-dashboard'),
                'description'     => __('WP system info, memory, PHP version checks', 'raw-wire-dashboard'),
                'category'        => 'diagnostic',
                'default'         => true,
                'client_ui'       => false,
                'client_location' => null,
                'dependencies'    => [],
                'tier'            => 'developer',
            ],
            'diagnostics_plugins' => [
                'id'              => 'diagnostics_plugins',
                'name'            => __('Plugin Diagnostics', 'raw-wire-dashboard'),
                'description'     => __('Plugin list and status checks', 'raw-wire-dashboard'),
                'category'        => 'diagnostic',
                'default'         => true,
                'client_ui'       => false,
                'client_location' => null,
                'dependencies'    => [],
                'tier'            => 'developer',
            ],
            'diagnostics_database' => [
                'id'              => 'diagnostics_database',
                'name'            => __('Database Diagnostics', 'raw-wire-dashboard'),
                'description'     => __('Database health, table sizes, optimization', 'raw-wire-dashboard'),
                'category'        => 'diagnostic',
                'default'         => true,
                'client_ui'       => false,
                'client_location' => null,
                'dependencies'    => [],
                'tier'            => 'developer',
            ],
            'diagnostics_logs' => [
                'id'              => 'diagnostics_logs',
                'name'            => __('Error Log Access', 'raw-wire-dashboard'),
                'description'     => __('View and search PHP error logs', 'raw-wire-dashboard'),
                'category'        => 'diagnostic',
                'default'         => true,
                'client_ui'       => false,
                'client_location' => null,
                'dependencies'    => [],
                'tier'            => 'developer',
            ],
            'diagnostics_cron' => [
                'id'              => 'diagnostics_cron',
                'name'            => __('Cron Management', 'raw-wire-dashboard'),
                'description'     => __('View and manage scheduled tasks', 'raw-wire-dashboard'),
                'category'        => 'diagnostic',
                'default'         => true,
                'client_ui'       => false,
                'client_location' => null,
                'dependencies'    => [],
                'tier'            => 'developer',
            ],
            'diagnostics_options' => [
                'id'              => 'diagnostics_options',
                'name'            => __('Options Inspector', 'raw-wire-dashboard'),
                'description'     => __('Search and inspect WordPress options', 'raw-wire-dashboard'),
                'category'        => 'diagnostic',
                'default'         => true,
                'client_ui'       => false,
                'client_location' => null,
                'dependencies'    => [],
                'tier'            => 'developer',
            ],

            // =====================================================================
            // AGENTIC ACTION TOOLS (Developer only - dangerous operations)
            // =====================================================================
            'action_plugin_manage' => [
                'id'              => 'action_plugin_manage',
                'name'            => __('Plugin Management', 'raw-wire-dashboard'),
                'description'     => __('Activate/deactivate plugins via AI agent', 'raw-wire-dashboard'),
                'category'        => 'agentic',
                'default'         => true,
                'client_ui'       => false,
                'client_location' => null,
                'dependencies'    => ['mcp_server'],
                'tier'            => 'developer',
                'dangerous'       => true,
            ],
            'action_option_update' => [
                'id'              => 'action_option_update',
                'name'            => __('Option Updates', 'raw-wire-dashboard'),
                'description'     => __('Update/delete WP options via AI agent', 'raw-wire-dashboard'),
                'category'        => 'agentic',
                'default'         => true,
                'client_ui'       => false,
                'client_location' => null,
                'dependencies'    => ['mcp_server'],
                'tier'            => 'developer',
                'dangerous'       => true,
            ],
            'action_theme_manage' => [
                'id'              => 'action_theme_manage',
                'name'            => __('Theme Management', 'raw-wire-dashboard'),
                'description'     => __('List/switch themes via AI agent', 'raw-wire-dashboard'),
                'category'        => 'agentic',
                'default'         => true,
                'client_ui'       => false,
                'client_location' => null,
                'dependencies'    => ['mcp_server'],
                'tier'            => 'developer',
                'dangerous'       => true,
            ],
            'action_cache_clear' => [
                'id'              => 'action_cache_clear',
                'name'            => __('Cache Clearing', 'raw-wire-dashboard'),
                'description'     => __('Clear various caches via AI agent', 'raw-wire-dashboard'),
                'category'        => 'agentic',
                'default'         => true,
                'client_ui'       => false,
                'client_location' => null,
                'dependencies'    => ['mcp_server'],
                'tier'            => 'developer',
                'dangerous'       => false,
            ],
            'action_file_edit' => [
                'id'              => 'action_file_edit',
                'name'            => __('File Editing', 'raw-wire-dashboard'),
                'description'     => __('Read/write theme/plugin files via AI agent', 'raw-wire-dashboard'),
                'category'        => 'agentic',
                'default'         => false,  // Disabled by default - very dangerous
                'client_ui'       => false,
                'client_location' => null,
                'dependencies'    => ['mcp_server'],
                'tier'            => 'developer',
                'dangerous'       => true,
            ],
            'action_db_repair' => [
                'id'              => 'action_db_repair',
                'name'            => __('Database Repair', 'raw-wire-dashboard'),
                'description'     => __('Repair orphaned records and corrupt options', 'raw-wire-dashboard'),
                'category'        => 'agentic',
                'default'         => true,
                'client_ui'       => false,
                'client_location' => null,
                'dependencies'    => ['mcp_server'],
                'tier'            => 'developer',
                'dangerous'       => true,
            ],
            'action_safe_mode' => [
                'id'              => 'action_safe_mode',
                'name'            => __('Safe Mode Toggle', 'raw-wire-dashboard'),
                'description'     => __('Enable/disable safe mode for troubleshooting', 'raw-wire-dashboard'),
                'category'        => 'agentic',
                'default'         => true,
                'client_ui'       => false,
                'client_location' => null,
                'dependencies'    => ['mcp_server'],
                'tier'            => 'developer',
                'dangerous'       => true,
            ],

            // =====================================================================
            // INTEGRATION REQUIREMENTS
            // =====================================================================
            'ai_engine' => [
                'id'              => 'ai_engine',
                'name'            => __('AI Engine Integration', 'raw-wire-dashboard'),
                'description'     => __('Core AI Engine Pro integration (required for AI features)', 'raw-wire-dashboard'),
                'category'        => 'integration',
                'default'         => true,
                'client_ui'       => false,
                'client_location' => null,
                'dependencies'    => [],
                'tier'            => 'admin',
                'external'        => true,  // Requires external plugin
            ],
            'mcp_server' => [
                'id'              => 'mcp_server',
                'name'            => __('MCP Server', 'raw-wire-dashboard'),
                'description'     => __('Model Context Protocol server for AI agents', 'raw-wire-dashboard'),
                'category'        => 'integration',
                'default'         => true,
                'client_ui'       => false,
                'client_location' => null,
                'dependencies'    => ['ai_engine'],
                'tier'            => 'developer',
            ],
        ];

        // Allow templates to add/modify tool definitions
        $this->tool_definitions = apply_filters('rawwire_tool_definitions', $this->tool_definitions);
    }

    /**
     * Register settings
     */
    public function register_settings()
    {
        register_setting('rawwire_developer_settings', self::OPTION_KEY, [
            'sanitize_callback' => [$this, 'sanitize_tool_states'],
            'default'           => $this->get_default_states(),
        ]);
    }

    /**
     * Sanitize tool states
     * 
     * @param array $input
     * @return array
     */
    public function sanitize_tool_states($input)
    {
        $sanitized = [];

        foreach ($this->tool_definitions as $id => $def) {
            $sanitized[$id] = isset($input[$id]) ? (bool) $input[$id] : false;
        }

        return $sanitized;
    }

    /**
     * Get default tool states
     * 
     * @return array
     */
    public function get_default_states()
    {
        $defaults = [];

        foreach ($this->tool_definitions as $id => $def) {
            $defaults[$id] = $def['default'] ?? false;
        }

        return $defaults;
    }

    /**
     * Get current tool states
     * 
     * @return array
     */
    public function get_tool_states()
    {
        $states = get_option(self::OPTION_KEY, []);

        // Merge with defaults for any new tools
        return wp_parse_args($states, $this->get_default_states());
    }

    /**
     * Check if a tool is enabled
     * 
     * @param string $tool_id
     * @return bool
     */
    public function is_tool_enabled($tool_id)
    {
        // Check dependencies first
        if (!$this->check_dependencies($tool_id)) {
            return false;
        }

        // Check external requirements
        if (!$this->check_external_requirements($tool_id)) {
            return false;
        }

        $states = $this->get_tool_states();
        return !empty($states[$tool_id]);
    }

    /**
     * Enable a tool
     * 
     * @param string $tool_id
     * @return bool|WP_Error
     */
    public function enable_tool($tool_id)
    {
        if (!isset($this->tool_definitions[$tool_id])) {
            return new WP_Error('invalid_tool', __('Unknown tool ID', 'raw-wire-dashboard'));
        }

        // Check dependencies
        $missing = $this->get_missing_dependencies($tool_id);
        if (!empty($missing)) {
            return new WP_Error(
                'missing_dependencies',
                sprintf(__('Missing dependencies: %s', 'raw-wire-dashboard'), implode(', ', $missing))
            );
        }

        // Check external requirements
        if (!$this->check_external_requirements($tool_id)) {
            return new WP_Error(
                'external_requirement',
                __('External plugin requirement not met', 'raw-wire-dashboard')
            );
        }

        $states = $this->get_tool_states();
        $states[$tool_id] = true;
        update_option(self::OPTION_KEY, $states);

        do_action('rawwire_tool_enabled', $tool_id);

        return true;
    }

    /**
     * Disable a tool
     * 
     * @param string $tool_id
     * @return bool|WP_Error
     */
    public function disable_tool($tool_id)
    {
        if (!isset($this->tool_definitions[$tool_id])) {
            return new WP_Error('invalid_tool', __('Unknown tool ID', 'raw-wire-dashboard'));
        }

        // Check for dependents
        $dependents = $this->get_tool_dependents($tool_id);
        $enabled_dependents = [];

        foreach ($dependents as $dep_id) {
            if ($this->is_tool_enabled($dep_id)) {
                $enabled_dependents[] = $this->tool_definitions[$dep_id]['name'];
            }
        }

        if (!empty($enabled_dependents)) {
            return new WP_Error(
                'has_dependents',
                sprintf(
                    __('Cannot disable: required by %s', 'raw-wire-dashboard'),
                    implode(', ', $enabled_dependents)
                )
            );
        }

        $states = $this->get_tool_states();
        $states[$tool_id] = false;
        update_option(self::OPTION_KEY, $states);

        do_action('rawwire_tool_disabled', $tool_id);

        return true;
    }

    /**
     * Check tool dependencies
     * 
     * @param string $tool_id
     * @return bool
     */
    private function check_dependencies($tool_id)
    {
        $def = $this->tool_definitions[$tool_id] ?? null;
        if (!$def || empty($def['dependencies'])) {
            return true;
        }

        foreach ($def['dependencies'] as $dep_id) {
            $states = $this->get_tool_states();
            if (empty($states[$dep_id])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get missing dependencies for a tool
     * 
     * @param string $tool_id
     * @return array
     */
    private function get_missing_dependencies($tool_id)
    {
        $def = $this->tool_definitions[$tool_id] ?? null;
        if (!$def || empty($def['dependencies'])) {
            return [];
        }

        $missing = [];
        $states = $this->get_tool_states();

        foreach ($def['dependencies'] as $dep_id) {
            if (empty($states[$dep_id])) {
                $missing[] = $this->tool_definitions[$dep_id]['name'] ?? $dep_id;
            }
        }

        return $missing;
    }

    /**
     * Get tools that depend on a given tool
     * 
     * @param string $tool_id
     * @return array
     */
    private function get_tool_dependents($tool_id)
    {
        $dependents = [];

        foreach ($this->tool_definitions as $id => $def) {
            if (!empty($def['dependencies']) && in_array($tool_id, $def['dependencies'])) {
                $dependents[] = $id;
            }
        }

        return $dependents;
    }

    /**
     * Check external requirements
     * 
     * @param string $tool_id
     * @return bool
     */
    private function check_external_requirements($tool_id)
    {
        $def = $this->tool_definitions[$tool_id] ?? null;

        if (!$def || empty($def['external'])) {
            return true;
        }

        // Check specific external requirements
        switch ($tool_id) {
            case 'ai_engine':
                return class_exists('Meow_MWAI_Core');

            default:
                return true;
        }
    }

    /**
     * Get all tool definitions
     * 
     * @return array
     */
    public function get_tool_definitions()
    {
        return $this->tool_definitions;
    }

    /**
     * Get tool definition by ID
     * 
     * @param string $tool_id
     * @return array|null
     */
    public function get_tool_definition($tool_id)
    {
        return $this->tool_definitions[$tool_id] ?? null;
    }

    /**
     * Get tools by category
     * 
     * @param string $category
     * @return array
     */
    public function get_tools_by_category($category)
    {
        return array_filter($this->tool_definitions, function ($def) use ($category) {
            return ($def['category'] ?? '') === $category;
        });
    }

    /**
     * Get enabled tools with client UI
     * 
     * @return array
     */
    public function get_enabled_client_tools()
    {
        $tools = [];

        foreach ($this->tool_definitions as $id => $def) {
            if ($this->is_tool_enabled($id) && !empty($def['client_ui'])) {
                $tools[$id] = $def;
            }
        }

        return $tools;
    }

    /**
     * Get enabled tools by client location
     * 
     * @param string $location dashboard|settings|submenu|global
     * @return array
     */
    public function get_tools_by_location($location)
    {
        $tools = [];

        foreach ($this->tool_definitions as $id => $def) {
            if (
                $this->is_tool_enabled($id) &&
                !empty($def['client_ui']) &&
                ($def['client_location'] ?? '') === $location
            ) {
                $tools[$id] = $def;
            }
        }

        return $tools;
    }

    /**
     * Get stats configuration for a specific enabled tool
     * 
     * @param string $tool_id Tool identifier
     * @return array Stats metrics array, empty if tool not enabled or no stats
     */
    public function get_tool_stats($tool_id)
    {
        if (!$this->is_tool_enabled($tool_id)) {
            return [];
        }

        $def = $this->tool_definitions[$tool_id] ?? null;
        return $def['stats'] ?? [];
    }

    /**
     * Get automation workflows for a specific enabled tool
     * 
     * @param string $tool_id Tool identifier
     * @return array Automations array, empty if tool not enabled or no automations
     */
    public function get_tool_automations($tool_id)
    {
        if (!$this->is_tool_enabled($tool_id)) {
            return [];
        }

        $def = $this->tool_definitions[$tool_id] ?? null;
        return $def['automations'] ?? [];
    }

    /**
     * Get all automations from all enabled tools (for dropdown population)
     * 
     * @return array All automations from enabled tools with tool context
     */
    public function get_all_enabled_automations()
    {
        $all_automations = [];

        foreach ($this->tool_definitions as $id => $def) {
            if (!$this->is_tool_enabled($id)) {
                continue;
            }

            $tool_automations = $def['automations'] ?? [];
            foreach ($tool_automations as $automation) {
                // Add tool context to each automation
                $automation['_tool_id'] = $id;
                $automation['_tool_name'] = $def['name'] ?? $id;
                $all_automations[] = $automation;
            }
        }

        return $all_automations;
    }

    /**
     * Get stats from all enabled tools (for stat cards)
     * 
     * @return array Stats grouped by tool_id
     */
    public function get_all_enabled_stats()
    {
        $all_stats = [];

        foreach ($this->tool_definitions as $id => $def) {
            if (!$this->is_tool_enabled($id) || empty($def['stats'])) {
                continue;
            }

            $all_stats[$id] = [
                'tool_id'   => $id,
                'tool_name' => $def['name'] ?? $id,
                'metrics'   => $def['stats'],
            ];
        }

        return $all_stats;
    }

    /**
     * AJAX handler for toggling tools
     */
    public function ajax_toggle_tool()
    {
        check_ajax_referer('rawwire_toggle_tool', 'nonce');

        // Require developer access
        if (!function_exists('rawwire_access') || !rawwire_access()->is_developer()) {
            wp_send_json_error(['message' => __('Developer access required', 'raw-wire-dashboard')]);
        }

        $tool_id = sanitize_text_field($_POST['tool_id'] ?? '');
        $enable = !empty($_POST['enable']);

        if (empty($tool_id)) {
            wp_send_json_error(['message' => __('Tool ID required', 'raw-wire-dashboard')]);
        }

        $result = $enable ? $this->enable_tool($tool_id) : $this->disable_tool($tool_id);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => $enable
                ? __('Tool enabled', 'raw-wire-dashboard')
                : __('Tool disabled', 'raw-wire-dashboard'),
            'tool_id' => $tool_id,
            'enabled' => $enable,
        ]);
    }

    /**
     * Enqueue toggle page assets (JS + inline CSS)
     */
    public function enqueue_toggle_assets($hook)
    {
        // Only load on the Tools page
        if (strpos($hook, 'rawwire-tools') === false) {
            return;
        }

        $plugin_url = plugin_dir_url(dirname(__FILE__));

        wp_enqueue_script(
            'rawwire-tool-toggles',
            $plugin_url . '../assets/js/tool-toggles.js',
            ['jquery'],
            '1.0.27',
            true
        );

        wp_localize_script('rawwire-tool-toggles', 'RawWireToolToggles', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('rawwire_toggle_tool'),
        ]);

        // Inline CSS for toggle switches and table styling
        wp_add_inline_style('rawwire-dashboard', $this->get_toggle_css());
    }

    /**
     * Get CSS for the toggle UI
     */
    private function get_toggle_css()
    {
        return '
            .rawwire-tool-toggles { max-width: 1100px; }
            .rawwire-tool-category { margin-bottom: 28px; }
            .rawwire-tool-category h4 {
                color: #f4b41a; font-size: 1.1em; margin: 0 0 10px;
                padding-bottom: 6px; border-bottom: 1px solid #2a2b30;
            }
            .rawwire-tool-toggles table.widefat {
                background: #18191c; border: 1px solid #2a2b30;
                border-collapse: collapse;
            }
            .rawwire-tool-toggles table.widefat th {
                background: #101114; color: #9ca3af; font-size: 0.82em;
                text-transform: uppercase; letter-spacing: 0.04em;
                padding: 10px 14px; border-bottom: 1px solid #2a2b30;
            }
            .rawwire-tool-toggles table.widefat td {
                padding: 12px 14px; color: #d1d5db;
                border-bottom: 1px solid #1e1f23; vertical-align: middle;
            }
            .rawwire-tool-toggles tr.disabled td { opacity: 0.6; }
            .rawwire-tool-toggles tr.deps-missing td { opacity: 0.4; }
            .rawwire-tool-toggles .deps { color: #6b7280; font-size: 0.85em; }
            .rawwire-tool-toggles .status-active {
                color: #10b981; font-weight: 600; font-size: 0.88em;
            }
            .rawwire-tool-toggles .status-inactive {
                color: #6b7280; font-size: 0.88em;
            }
            .rawwire-tool-toggles .status-warning {
                color: #f59e0b; font-size: 0.88em;
            }
            /* Toggle switch */
            .rawwire-toggle { position: relative; display: inline-block; width: 42px; height: 22px; }
            .rawwire-toggle input { opacity: 0; width: 0; height: 0; }
            .rawwire-toggle .slider {
                position: absolute; cursor: pointer; inset: 0;
                background: #374151; border-radius: 22px; transition: 0.25s;
            }
            .rawwire-toggle .slider::before {
                content: ""; position: absolute; height: 16px; width: 16px;
                left: 3px; bottom: 3px; background: #fff;
                border-radius: 50%; transition: 0.25s;
            }
            .rawwire-toggle input:checked + .slider { background: #f4b41a; }
            .rawwire-toggle input:checked + .slider::before { transform: translateX(20px); }
            .rawwire-toggle input:disabled + .slider { opacity: 0.4; cursor: not-allowed; }
        ';
    }

    /**
     * Render tool toggle UI for developer settings
     */
    public function render_tool_toggles()
    {
        $states = $this->get_tool_states();
        $categories = [
            'scraper'     => __('Scraper Tools', 'raw-wire-dashboard'),
            'ai'          => __('AI Tools', 'raw-wire-dashboard'),
            'publisher'   => __('Publisher Tools', 'raw-wire-dashboard'),
            'utility'     => __('Utility Tools', 'raw-wire-dashboard'),
            'diagnostic'  => __('Diagnostic Tools', 'raw-wire-dashboard'),
            'agentic'     => __('Agentic Actions', 'raw-wire-dashboard'),
            'integration' => __('Integrations', 'raw-wire-dashboard'),
        ];

?>
        <div class="rawwire-tool-toggles">
            <?php foreach ($categories as $cat_key => $cat_label): ?>
                <?php $cat_tools = $this->get_tools_by_category($cat_key); ?>
                <?php if (empty($cat_tools)) continue; ?>

                <div class="rawwire-tool-category">
                    <h4><?php echo esc_html($cat_label); ?></h4>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Tool', 'raw-wire-dashboard'); ?></th>
                                <th><?php esc_html_e('Description', 'raw-wire-dashboard'); ?></th>
                                <th><?php esc_html_e('Client Access', 'raw-wire-dashboard'); ?></th>
                                <th><?php esc_html_e('Status', 'raw-wire-dashboard'); ?></th>
                                <th><?php esc_html_e('Enabled', 'raw-wire-dashboard'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cat_tools as $id => $def): ?>
                                <?php
                                $enabled = !empty($states[$id]);
                                $deps_met = $this->check_dependencies($id);
                                $ext_met = $this->check_external_requirements($id);
                                $can_toggle = $deps_met || !$enabled;
                                ?>
                                <tr class="<?php echo $enabled ? 'enabled' : 'disabled'; ?> <?php echo !$deps_met ? 'deps-missing' : ''; ?>">
                                    <td>
                                        <strong><?php echo esc_html($def['name']); ?></strong>
                                        <?php if (!empty($def['dependencies'])): ?>
                                            <br><small class="deps">
                                                <?php esc_html_e('Requires:', 'raw-wire-dashboard'); ?>
                                                <?php echo esc_html(implode(', ', array_map(function ($d) {
                                                    return $this->tool_definitions[$d]['name'] ?? $d;
                                                }, $def['dependencies']))); ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html($def['description']); ?></td>
                                    <td>
                                        <?php if (!empty($def['client_ui'])): ?>
                                            <span class="dashicons dashicons-visibility" title="<?php esc_attr_e('Has client UI', 'raw-wire-dashboard'); ?>"></span>
                                            <?php echo esc_html(ucfirst($def['client_location'] ?? 'dashboard')); ?>
                                        <?php else: ?>
                                            <span class="dashicons dashicons-hidden" title="<?php esc_attr_e('Developer only', 'raw-wire-dashboard'); ?>"></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$ext_met): ?>
                                            <span class="status-warning"><?php esc_html_e('Plugin Required', 'raw-wire-dashboard'); ?></span>
                                        <?php elseif (!$deps_met): ?>
                                            <span class="status-warning"><?php esc_html_e('Dependencies Missing', 'raw-wire-dashboard'); ?></span>
                                        <?php elseif ($enabled): ?>
                                            <span class="status-active"><?php esc_html_e('Active', 'raw-wire-dashboard'); ?></span>
                                        <?php else: ?>
                                            <span class="status-inactive"><?php esc_html_e('Inactive', 'raw-wire-dashboard'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <label class="rawwire-toggle">
                                            <input type="checkbox"
                                                name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($id); ?>]"
                                                value="1"
                                                data-tool-id="<?php echo esc_attr($id); ?>"
                                                <?php checked($enabled); ?>
                                                <?php disabled(!$can_toggle || !$ext_met); ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        </div>
<?php
    }
}

/**
 * Helper function
 * 
 * @return RawWire_Tool_Toggle_Manager
 */
function rawwire_tools()
{
    return RawWire_Tool_Toggle_Manager::get_instance();
}
