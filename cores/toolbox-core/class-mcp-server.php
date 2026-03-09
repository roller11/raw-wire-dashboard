<?php

/**
 * MCP Server - Model Context Protocol server for Raw Wire Dashboard
 * 
 * Exposes Raw Wire tools to AI agents (ChatGPT, Claude, etc.) via MCP protocol.
 * This allows AI agents to execute automation tools, manage scrapers,
 * and interact with the entire Raw Wire ecosystem.
 *
 * @package RawWire\Dashboard\Cores\ToolboxCore
 * @since 1.0.15
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class RawWire_MCP_Server
 * 
 * Implements Model Context Protocol server that integrates with AI Engine
 * to expose Raw Wire functionality to AI agents.
 */
class RawWire_MCP_Server
{

    /**
     * Singleton instance
     * @var RawWire_MCP_Server|null
     */
    private static $instance = null;

    /**
     * Tool Registry reference
     * @var RawWire_Tool_Registry|null
     */
    private $tool_registry = null;

    /**
     * MCP Server name
     */
    const SERVER_NAME = 'raw-wire-dashboard';

    /**
     * MCP Server version
     */
    const SERVER_VERSION = '1.0.0';

    /**
     * Registered MCP tools
     * @var array
     */
    private $mcp_tools = [];

    /**
     * Get singleton instance
     * 
     * @return RawWire_MCP_Server
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
        add_action('init', [$this, 'init']);
        add_action('plugins_loaded', [$this, 'late_init'], 25);
    }

    /**
     * Initialize MCP Server
     */
    public function init()
    {
        // Register MCP tools with AI Engine (for function calling in chatbots)
        add_filter('mwai_functions_list', [$this, 'register_mcp_functions']);

        // Handle MCP function calls
        add_filter('mwai_functions_execute', [$this, 'execute_mcp_function'], 10, 3);

        // Register with AI Engine's MCP module (for external MCP clients like Claude Desktop)
        add_filter('mwai_mcp_tools', [$this, 'register_mcp_protocol_tools']);
        add_filter('mwai_mcp_callback', [$this, 'handle_mcp_protocol_call'], 10, 4);

        // Register REST endpoints for external MCP clients
        add_action('rest_api_init', [$this, 'register_rest_endpoints']);

        // Initialize default tools
        $this->register_default_tools();
    }

    /**
     * Late initialization after Tool Registry is available
     */
    public function late_init()
    {
        if (class_exists('RawWire_Tool_Registry')) {
            $this->tool_registry = RawWire_Tool_Registry::get_instance();
        }
    }

    /**
     * Register default MCP tools
     */
    private function register_default_tools()
    {
        // =====================================================================
        // SCRAPER TOOLS
        // =====================================================================

        $this->register_tool([
            'name' => 'rawwire_scraper_list_sources',
            'description' => 'List all configured scraper sources in Raw Wire Dashboard. Returns source configurations including URL, type, and settings.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'status' => [
                        'type' => 'string',
                        'description' => 'Filter by status: active, paused, or all',
                        'enum' => ['active', 'paused', 'all'],
                    ],
                ],
            ],
            'callback' => [$this, 'handle_scraper_list_sources'],
        ]);

        $this->register_tool([
            'name' => 'rawwire_scraper_run',
            'description' => 'Run a scraper to collect data from a configured source. Returns the number of records collected.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'source_id' => [
                        'type' => 'string',
                        'description' => 'The ID of the source to scrape',
                        'required' => true,
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of records to collect',
                    ],
                ],
                'required' => ['source_id'],
            ],
            'callback' => [$this, 'handle_scraper_run'],
        ]);

        $this->register_tool([
            'name' => 'rawwire_scraper_add_source',
            'description' => 'Add a new scraper source to Raw Wire Dashboard. Configure URL, type (RSS, API, HTML), authentication, and output settings.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'description' => 'Human-readable name for the source',
                        'required' => true,
                    ],
                    'type' => [
                        'type' => 'string',
                        'description' => 'Source type',
                        'enum' => ['rss', 'api', 'html', 'json', 'xml'],
                        'required' => true,
                    ],
                    'url' => [
                        'type' => 'string',
                        'description' => 'URL or API endpoint to scrape',
                        'required' => true,
                    ],
                    'auth_type' => [
                        'type' => 'string',
                        'description' => 'Authentication type',
                        'enum' => ['none', 'api_key', 'bearer', 'basic', 'oauth'],
                    ],
                    'auth_credentials' => [
                        'type' => 'string',
                        'description' => 'Authentication credentials (API key, token, etc.)',
                    ],
                    'output_table' => [
                        'type' => 'string',
                        'description' => 'Database table name for storing results',
                    ],
                    'columns' => [
                        'type' => 'string',
                        'description' => 'Comma-separated list of column names to extract',
                    ],
                ],
                'required' => ['name', 'type', 'url'],
            ],
            'callback' => [$this, 'handle_scraper_add_source'],
        ]);

        // =====================================================================
        // CONTENT TOOLS
        // =====================================================================

        $this->register_tool([
            'name' => 'rawwire_content_score',
            'description' => 'Score content for relevance, quality, SEO, and readability. Returns detailed scores and suggestions for improvement.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'content' => [
                        'type' => 'string',
                        'description' => 'The content to analyze and score',
                        'required' => true,
                    ],
                    'criteria' => [
                        'type' => 'array',
                        'description' => 'Additional scoring criteria beyond defaults',
                        'items' => ['type' => 'string'],
                    ],
                ],
                'required' => ['content'],
            ],
            'callback' => [$this, 'handle_content_score'],
        ]);

        $this->register_tool([
            'name' => 'rawwire_content_generate',
            'description' => 'Generate article or content from a topic. Returns formatted content with headings and structure.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'topic' => [
                        'type' => 'string',
                        'description' => 'The topic or subject for the content',
                        'required' => true,
                    ],
                    'word_count' => [
                        'type' => 'integer',
                        'description' => 'Target word count (default: 800)',
                    ],
                    'tone' => [
                        'type' => 'string',
                        'description' => 'Writing tone',
                        'enum' => ['professional', 'casual', 'formal', 'friendly', 'authoritative'],
                    ],
                    'keywords' => [
                        'type' => 'array',
                        'description' => 'Keywords to include naturally',
                        'items' => ['type' => 'string'],
                    ],
                    'format' => [
                        'type' => 'string',
                        'description' => 'Content format',
                        'enum' => ['blog post', 'article', 'guide', 'tutorial', 'news'],
                    ],
                ],
                'required' => ['topic'],
            ],
            'callback' => [$this, 'handle_content_generate'],
        ]);

        $this->register_tool([
            'name' => 'rawwire_content_summarize',
            'description' => 'Summarize content to a specified length while maintaining key points.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'content' => [
                        'type' => 'string',
                        'description' => 'Content to summarize',
                        'required' => true,
                    ],
                    'length' => [
                        'type' => 'integer',
                        'description' => 'Target summary length in words (default: 150)',
                    ],
                ],
                'required' => ['content'],
            ],
            'callback' => [$this, 'handle_content_summarize'],
        ]);

        // =====================================================================
        // TOOL MANAGEMENT
        // =====================================================================

        $this->register_tool([
            'name' => 'rawwire_tools_list',
            'description' => 'List all available automation tools in Raw Wire Dashboard with their status and descriptions.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'category' => [
                        'type' => 'string',
                        'description' => 'Filter by category',
                        'enum' => ['scraper', 'scorer', 'generator', 'publisher', 'utility', 'ai', 'all'],
                    ],
                ],
            ],
            'callback' => [$this, 'handle_tools_list'],
        ]);

        $this->register_tool([
            'name' => 'rawwire_tool_execute',
            'description' => 'Execute a specific Raw Wire automation tool with given parameters.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'tool_id' => [
                        'type' => 'string',
                        'description' => 'The ID of the tool to execute',
                        'required' => true,
                    ],
                    'params' => [
                        'type' => 'object',
                        'description' => 'Parameters to pass to the tool',
                    ],
                    'async' => [
                        'type' => 'boolean',
                        'description' => 'Run asynchronously via Action Scheduler (default: false)',
                    ],
                ],
                'required' => ['tool_id'],
            ],
            'callback' => [$this, 'handle_tool_execute'],
        ]);

        $this->register_tool([
            'name' => 'rawwire_tool_schedule',
            'description' => 'Schedule a tool to run at a specific time or on a recurring schedule.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'tool_id' => [
                        'type' => 'string',
                        'description' => 'The ID of the tool to schedule',
                        'required' => true,
                    ],
                    'schedule_type' => [
                        'type' => 'string',
                        'description' => 'Type of schedule',
                        'enum' => ['once', 'hourly', 'daily', 'weekly'],
                        'required' => true,
                    ],
                    'start_time' => [
                        'type' => 'string',
                        'description' => 'When to start (ISO 8601 format or relative like "+1 hour")',
                    ],
                    'params' => [
                        'type' => 'object',
                        'description' => 'Parameters to pass to the tool',
                    ],
                ],
                'required' => ['tool_id', 'schedule_type'],
            ],
            'callback' => [$this, 'handle_tool_schedule'],
        ]);

        // =====================================================================
        // DATA & ANALYTICS
        // =====================================================================

        $this->register_tool([
            'name' => 'rawwire_data_query',
            'description' => 'Query scraped data from Raw Wire storage tables. Returns matching records.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'table' => [
                        'type' => 'string',
                        'description' => 'Table name to query',
                        'required' => true,
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum records to return (default: 20)',
                    ],
                    'offset' => [
                        'type' => 'integer',
                        'description' => 'Records to skip for pagination',
                    ],
                    'order_by' => [
                        'type' => 'string',
                        'description' => 'Column to sort by',
                    ],
                    'order' => [
                        'type' => 'string',
                        'description' => 'Sort direction',
                        'enum' => ['ASC', 'DESC'],
                    ],
                ],
                'required' => ['table'],
            ],
            'callback' => [$this, 'handle_data_query'],
        ]);

        $this->register_tool([
            'name' => 'rawwire_stats_get',
            'description' => 'Get statistics and analytics for Raw Wire Dashboard including scraper runs, content generated, and tool usage.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'period' => [
                        'type' => 'string',
                        'description' => 'Time period for stats',
                        'enum' => ['today', 'week', 'month', 'all'],
                    ],
                ],
            ],
            'callback' => [$this, 'handle_stats_get'],
        ]);

        // =====================================================================
        // WORKFLOW TOOLS
        // =====================================================================

        $this->register_tool([
            'name' => 'rawwire_workflow_create',
            'description' => 'Create an automation workflow that chains multiple tools together. For example: scrape -> score -> generate -> publish.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'description' => 'Workflow name',
                        'required' => true,
                    ],
                    'description' => [
                        'type' => 'string',
                        'description' => 'What this workflow does',
                    ],
                    'steps' => [
                        'type' => 'array',
                        'description' => 'Array of workflow steps',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'tool_id' => ['type' => 'string'],
                                'params' => ['type' => 'object'],
                                'condition' => ['type' => 'string'],
                            ],
                        ],
                        'required' => true,
                    ],
                    'trigger' => [
                        'type' => 'string',
                        'description' => 'When to run: manual, scheduled, or on_event',
                        'enum' => ['manual', 'scheduled', 'on_event'],
                    ],
                ],
                'required' => ['name', 'steps'],
            ],
            'callback' => [$this, 'handle_workflow_create'],
        ]);

        // =====================================================================
        // WORDPRESS DEBUGGING & DIAGNOSTICS TOOLS
        // =====================================================================

        $this->register_tool([
            'name' => 'wp_debug_system_info',
            'description' => 'Get WordPress system information including PHP version, memory limits, WordPress version, active theme, and server details.',
            'parameters' => [
                'type' => 'object',
                'properties' => [],
            ],
            'callback' => [$this, 'handle_wp_system_info'],
        ]);

        $this->register_tool([
            'name' => 'wp_debug_plugins',
            'description' => 'List all plugins with their status (active/inactive), version, and check for potential conflicts.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'status' => [
                        'type' => 'string',
                        'description' => 'Filter by status',
                        'enum' => ['all', 'active', 'inactive', 'must-use', 'dropins'],
                    ],
                ],
            ],
            'callback' => [$this, 'handle_wp_plugins'],
        ]);

        $this->register_tool([
            'name' => 'wp_debug_error_log',
            'description' => 'Read the WordPress debug.log or PHP error log. Returns recent errors and warnings.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'lines' => [
                        'type' => 'integer',
                        'description' => 'Number of recent lines to retrieve (default: 100, max: 500)',
                    ],
                    'filter' => [
                        'type' => 'string',
                        'description' => 'Filter by error type: fatal, warning, notice, deprecated, or all',
                        'enum' => ['all', 'fatal', 'warning', 'notice', 'deprecated'],
                    ],
                ],
            ],
            'callback' => [$this, 'handle_wp_error_log'],
        ]);

        $this->register_tool([
            'name' => 'wp_debug_database',
            'description' => 'Get database information including table sizes, total size, and status. Can also run optimization.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'description' => 'Action to perform',
                        'enum' => ['info', 'table_sizes', 'optimize', 'check_tables'],
                    ],
                ],
            ],
            'callback' => [$this, 'handle_wp_database'],
        ]);

        $this->register_tool([
            'name' => 'wp_debug_health_check',
            'description' => 'Run WordPress Site Health diagnostics and return issues found.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'category' => [
                        'type' => 'string',
                        'description' => 'Health check category',
                        'enum' => ['all', 'critical', 'recommended', 'good'],
                    ],
                ],
            ],
            'callback' => [$this, 'handle_wp_health_check'],
        ]);

        $this->register_tool([
            'name' => 'wp_debug_cron',
            'description' => 'List scheduled cron jobs, check for stuck jobs, and optionally run specific events.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'description' => 'Action to perform',
                        'enum' => ['list', 'check_stuck', 'run_event'],
                    ],
                    'event' => [
                        'type' => 'string',
                        'description' => 'Event hook name (for run_event action)',
                    ],
                ],
            ],
            'callback' => [$this, 'handle_wp_cron'],
        ]);

        $this->register_tool([
            'name' => 'wp_debug_options',
            'description' => 'Search and read WordPress options. Useful for debugging settings and configuration.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'search' => [
                        'type' => 'string',
                        'description' => 'Search term to find options (searches option_name)',
                    ],
                    'option' => [
                        'type' => 'string',
                        'description' => 'Specific option name to retrieve',
                    ],
                ],
            ],
            'callback' => [$this, 'handle_wp_options'],
        ]);

        $this->register_tool([
            'name' => 'wp_debug_transients',
            'description' => 'List, search, or clear transients. Helpful for debugging caching issues.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'description' => 'Action to perform',
                        'enum' => ['list', 'search', 'clear_expired', 'delete'],
                    ],
                    'search' => [
                        'type' => 'string',
                        'description' => 'Search term for transient names',
                    ],
                    'name' => [
                        'type' => 'string',
                        'description' => 'Transient name to delete (for delete action)',
                    ],
                ],
            ],
            'callback' => [$this, 'handle_wp_transients'],
        ]);

        $this->register_tool([
            'name' => 'wp_debug_hooks',
            'description' => 'List active hooks/filters with their callbacks. Useful for debugging conflicts.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'hook' => [
                        'type' => 'string',
                        'description' => 'Specific hook name to inspect',
                    ],
                    'search' => [
                        'type' => 'string',
                        'description' => 'Search for hooks containing this string',
                    ],
                    'type' => [
                        'type' => 'string',
                        'description' => 'Filter type',
                        'enum' => ['all', 'actions', 'filters'],
                    ],
                ],
            ],
            'callback' => [$this, 'handle_wp_hooks'],
        ]);

        $this->register_tool([
            'name' => 'wp_debug_memory',
            'description' => 'Get current memory usage and performance metrics.',
            'parameters' => [
                'type' => 'object',
                'properties' => [],
            ],
            'callback' => [$this, 'handle_wp_memory'],
        ]);

        // =====================================================================
        // WORDPRESS ACTION TOOLS (Write Operations)
        // =====================================================================

        $this->register_tool([
            'name' => 'wp_plugin_manage',
            'description' => 'Manage WordPress plugins - activate, deactivate, or check status. Use with caution as this modifies the site.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'description' => 'Action to perform: activate, deactivate, or status',
                        'enum' => ['activate', 'deactivate', 'status'],
                    ],
                    'plugin' => [
                        'type' => 'string',
                        'description' => 'Plugin path (e.g., "akismet/akismet.php") or plugin slug',
                    ],
                ],
                'required' => ['action', 'plugin'],
            ],
            'callback' => [$this, 'handle_wp_plugin_manage'],
        ]);

        $this->register_tool([
            'name' => 'wp_option_update',
            'description' => 'Update or delete a WordPress option. Use carefully - incorrect options can break the site.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'description' => 'Action: update or delete',
                        'enum' => ['update', 'delete'],
                    ],
                    'option' => [
                        'type' => 'string',
                        'description' => 'The option name to modify',
                    ],
                    'value' => [
                        'type' => 'string',
                        'description' => 'The new value (for update action). Use JSON for arrays/objects.',
                    ],
                    'autoload' => [
                        'type' => 'string',
                        'description' => 'Whether to autoload the option',
                        'enum' => ['yes', 'no'],
                    ],
                ],
                'required' => ['action', 'option'],
            ],
            'callback' => [$this, 'handle_wp_option_update'],
        ]);

        $this->register_tool([
            'name' => 'wp_theme_manage',
            'description' => 'Manage WordPress themes - switch active theme, get theme info, or list available themes.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'description' => 'Action: list, info, or switch',
                        'enum' => ['list', 'info', 'switch'],
                    ],
                    'theme' => [
                        'type' => 'string',
                        'description' => 'Theme slug (required for info and switch actions)',
                    ],
                ],
                'required' => ['action'],
            ],
            'callback' => [$this, 'handle_wp_theme_manage'],
        ]);

        $this->register_tool([
            'name' => 'wp_cache_clear',
            'description' => 'Clear various WordPress caches including object cache, transients, and rewrite rules.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'cache_type' => [
                        'type' => 'string',
                        'description' => 'Type of cache to clear',
                        'enum' => ['all', 'object', 'transients', 'rewrite', 'post'],
                    ],
                    'post_id' => [
                        'type' => 'integer',
                        'description' => 'Post ID (for post cache clearing)',
                    ],
                ],
                'required' => ['cache_type'],
            ],
            'callback' => [$this, 'handle_wp_cache_clear'],
        ]);

        $this->register_tool([
            'name' => 'wp_file_edit',
            'description' => 'Read or modify theme/plugin files. DANGEROUS: Only use when specifically requested for troubleshooting.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'description' => 'Action: read, write, or backup',
                        'enum' => ['read', 'write', 'backup'],
                    ],
                    'file_path' => [
                        'type' => 'string',
                        'description' => 'Path relative to wp-content (e.g., "themes/twentytwenty/style.css")',
                    ],
                    'content' => [
                        'type' => 'string',
                        'description' => 'New file content (for write action)',
                    ],
                    'create_backup' => [
                        'type' => 'boolean',
                        'description' => 'Create a backup before writing',
                    ],
                ],
                'required' => ['action', 'file_path'],
            ],
            'callback' => [$this, 'handle_wp_file_edit'],
        ]);

        // =====================================================================
        // WORKFLOW MANAGEMENT TOOLS
        // =====================================================================

        $this->register_tool([
            'name' => 'rawwire_workflow_list',
            'description' => 'List all configured Raw Wire workflows with their status, triggers, and last run info.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'status' => [
                        'type' => 'string',
                        'description' => 'Filter by status',
                        'enum' => ['all', 'active', 'paused', 'draft'],
                    ],
                ],
            ],
            'callback' => [$this, 'handle_workflow_list'],
        ]);

        $this->register_tool([
            'name' => 'rawwire_workflow_trigger',
            'description' => 'Trigger execution of a configured workflow. Returns execution status and results.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'workflow_id' => [
                        'type' => 'string',
                        'description' => 'ID of the workflow to trigger',
                    ],
                    'params' => [
                        'type' => 'string',
                        'description' => 'JSON-encoded parameters to pass to the workflow',
                    ],
                    'async' => [
                        'type' => 'boolean',
                        'description' => 'Run workflow asynchronously (returns immediately)',
                    ],
                ],
                'required' => ['workflow_id'],
            ],
            'callback' => [$this, 'handle_workflow_trigger'],
        ]);

        $this->register_tool([
            'name' => 'rawwire_workflow_delete',
            'description' => 'Delete a workflow by ID.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'workflow_id' => [
                        'type' => 'string',
                        'description' => 'ID of the workflow to delete',
                    ],
                ],
                'required' => ['workflow_id'],
            ],
            'callback' => [$this, 'handle_workflow_delete'],
        ]);

        // =====================================================================
        // DIAGNOSTIC & REPAIR TOOLS
        // =====================================================================

        $this->register_tool([
            'name' => 'rawwire_repair_database',
            'description' => 'Repair common database issues - orphaned records, corrupt options, broken serialization.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'check' => [
                        'type' => 'string',
                        'description' => 'Type of check/repair to perform',
                        'enum' => ['orphaned_postmeta', 'orphaned_usermeta', 'corrupt_options', 'autoload_size', 'all'],
                    ],
                    'dry_run' => [
                        'type' => 'boolean',
                        'description' => 'Preview changes without actually making them',
                    ],
                ],
                'required' => ['check'],
            ],
            'callback' => [$this, 'handle_repair_database'],
        ]);

        $this->register_tool([
            'name' => 'rawwire_safe_mode',
            'description' => 'Toggle safe mode - disables all plugins except core WordPress for troubleshooting.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'description' => 'Enable or disable safe mode',
                        'enum' => ['enable', 'disable', 'status'],
                    ],
                    'keep_plugins' => [
                        'type' => 'string',
                        'description' => 'Comma-separated list of plugin slugs to keep active',
                    ],
                ],
                'required' => ['action'],
            ],
            'callback' => [$this, 'handle_safe_mode'],
        ]);
    }

    /**
     * Register a tool with the MCP server
     * 
     * @param array $tool Tool configuration
     */
    public function register_tool($tool)
    {
        if (empty($tool['name']) || empty($tool['callback'])) {
            return false;
        }

        $this->mcp_tools[$tool['name']] = $tool;
        return true;
    }

    /**
     * Get all registered MCP tools
     * 
     * @return array
     */
    public function get_tools()
    {
        return $this->mcp_tools;
    }

    /**
     * Register MCP functions with AI Engine
     * 
     * @param array $functions Existing functions
     * @return array
     */
    public function register_mcp_functions($functions)
    {
        // Check if AI Engine's function class exists
        if (!class_exists('Meow_MWAI_Query_Function') || !class_exists('Meow_MWAI_Query_Parameter')) {
            return $functions;
        }

        foreach ($this->mcp_tools as $tool_name => $tool) {
            try {
                // Convert our parameter format to Meow_MWAI_Query_Parameter objects
                $parameters = [];
                $param_config = $tool['parameters'] ?? [];
                $properties = $param_config['properties'] ?? [];
                $required_list = $param_config['required'] ?? [];

                foreach ($properties as $param_name => $param_def) {
                    // Get the type, default to 'string'
                    $type = $param_def['type'] ?? 'string';

                    // Map our types to AI Engine types
                    if (!in_array($type, ['string', 'number', 'integer', 'boolean', 'array', 'object'])) {
                        $type = 'string';
                    }

                    // Check if this parameter is required
                    $is_required = in_array($param_name, $required_list) ||
                        (!empty($param_def['required']) && $param_def['required'] === true);

                    // Create the parameter object
                    $parameters[] = new Meow_MWAI_Query_Parameter(
                        $param_name,
                        $param_def['description'] ?? '',
                        $type,
                        $is_required,
                        $param_def['default'] ?? null
                    );
                }

                // Create a proper Meow_MWAI_Query_Function object
                $func = new Meow_MWAI_Query_Function(
                    $tool_name,
                    $tool['description'] ?? '',
                    $parameters
                );
                $functions[] = $func;
            } catch (Exception $e) {
                // Skip this tool if it can't be registered
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("RawWire MCP: Failed to register function {$tool_name}: " . $e->getMessage());
                }
            }
        }

        return $functions;
    }

    /**
     * Execute MCP function when called by AI
     * 
     * @param mixed  $result   Current result
     * @param string $func_name Function name
     * @param array  $args     Function arguments
     * @return mixed
     */
    public function execute_mcp_function($result, $func_name, $args)
    {
        if (!isset($this->mcp_tools[$func_name])) {
            return $result;
        }

        // Check access control permissions
        if (function_exists('rawwire_access')) {
            $access = rawwire_access();

            if (!$access->can_use_mcp_tool($func_name)) {
                return [
                    'error'   => true,
                    'message' => 'Access denied: Insufficient permissions to use tool: ' . $func_name,
                    'tier'    => $access->get_user_tier(),
                ];
            }
        }

        // Check tool toggle state
        if (function_exists('rawwire_tools')) {
            $tools = rawwire_tools();
            $toggle_mapping = $this->get_toggle_mapping();
            $toggle_id = $toggle_mapping[$func_name] ?? null;

            if ($toggle_id && !$tools->is_tool_enabled($toggle_id)) {
                return [
                    'error'   => true,
                    'message' => 'Tool is disabled: ' . $func_name,
                    'toggle'  => $toggle_id,
                ];
            }
        }

        $tool = $this->mcp_tools[$func_name];

        if (is_callable($tool['callback'])) {
            try {
                return call_user_func($tool['callback'], $args);
            } catch (Exception $e) {
                return [
                    'error' => true,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $result;
    }

    /**
     * Get mapping of MCP tool names to toggle IDs
     * 
     * @return array
     */
    private function get_toggle_mapping()
    {
        return [
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
        ];
    }

    /**
     * Register REST API endpoints
     */
    public function register_rest_endpoints()
    {
        register_rest_route('rawwire/v1', '/mcp/tools', [
            'methods'             => 'GET',
            'callback'            => [$this, 'rest_list_tools'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route('rawwire/v1', '/mcp/execute', [
            'methods'             => 'POST',
            'callback'            => [$this, 'rest_execute_tool'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route('rawwire/v1', '/mcp/schema', [
            'methods'             => 'GET',
            'callback'            => [$this, 'rest_get_schema'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Check REST API permission
     * 
     * @return bool
     */
    public function check_permission()
    {
        return current_user_can('manage_options');
    }

    /**
     * REST: List available tools
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function rest_list_tools($request)
    {
        $tools = [];

        foreach ($this->mcp_tools as $name => $tool) {
            $tools[] = [
                'name'        => $name,
                'description' => $tool['description'] ?? '',
                'parameters'  => $tool['parameters'] ?? [],
            ];
        }

        return new WP_REST_Response([
            'server'  => self::SERVER_NAME,
            'version' => self::SERVER_VERSION,
            'tools'   => $tools,
        ]);
    }

    /**
     * REST: Execute a tool
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function rest_execute_tool($request)
    {
        $tool_name = $request->get_param('tool');
        $params = $request->get_param('params') ?? [];

        if (!isset($this->mcp_tools[$tool_name])) {
            return new WP_REST_Response([
                'error'   => true,
                'message' => 'Unknown tool: ' . $tool_name,
            ], 404);
        }

        $result = $this->execute_mcp_function(null, $tool_name, $params);

        return new WP_REST_Response([
            'success' => true,
            'tool'    => $tool_name,
            'result'  => $result,
        ]);
    }

    /**
     * REST: Get MCP schema
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function rest_get_schema($request)
    {
        return new WP_REST_Response([
            'jsonrpc' => '2.0',
            'name'    => self::SERVER_NAME,
            'version' => self::SERVER_VERSION,
            'capabilities' => [
                'tools' => true,
                'resources' => false,
                'prompts' => false,
            ],
        ]);
    }

    // =========================================================================
    // TOOL HANDLERS
    // =========================================================================

    /**
     * Handle: List scraper sources
     */
    public function handle_scraper_list_sources($args)
    {
        $status = $args['status'] ?? 'all';

        // Use central getter for fresh data
        $sources = class_exists('RawWire_Scraper_Settings')
            ? RawWire_Scraper_Settings::get_sources()
            : [];

        if ($status !== 'all') {
            $sources = array_filter($sources, function ($source) use ($status) {
                // Check enabled field (new) or legacy status field
                if ($status === 'active' || $status === 'enabled') {
                    return !empty($source['enabled']) || ($source['status'] ?? '') === 'active';
                }
                return (($source['status'] ?? 'active') === $status) || (empty($source['enabled']) && $status === 'disabled');
            });
        }

        return [
            'count'   => count($sources),
            'sources' => array_values($sources),
        ];
    }

    /**
     * Handle: Run scraper
     */
    public function handle_scraper_run($args)
    {
        $source_id = $args['source_id'] ?? '';
        $limit = $args['limit'] ?? 100;

        if (empty($source_id)) {
            return ['error' => true, 'message' => 'source_id is required'];
        }

        // Get source config using central getter
        $sources = class_exists('RawWire_Scraper_Settings')
            ? RawWire_Scraper_Settings::get_sources()
            : [];
        $source = $sources[$source_id] ?? null;

        if (!$source) {
            return ['error' => true, 'message' => 'Source not found: ' . $source_id];
        }

        // Schedule via Tool Registry if available
        if ($this->tool_registry) {
            $result = $this->tool_registry->schedule('scraper_' . $source['type'], [
                'source_id' => $source_id,
                'limit'     => $limit,
            ]);

            return [
                'success'   => true,
                'message'   => 'Scraper scheduled',
                'action_id' => $result,
            ];
        }

        return ['error' => true, 'message' => 'Tool Registry not available'];
    }

    /**
     * Handle: Add scraper source
     */
    public function handle_scraper_add_source($args)
    {
        $required = ['name', 'type', 'url'];
        foreach ($required as $field) {
            if (empty($args[$field])) {
                return ['error' => true, 'message' => "Missing required field: $field"];
            }
        }

        // Use RawWire_Scraper_Settings for consistent source management
        if (class_exists('RawWire_Scraper_Settings')) {
            $source = [
                'name'             => sanitize_text_field($args['name']),
                'type'             => sanitize_text_field($args['type']),
                'address'          => esc_url_raw($args['url']),
                'url'              => esc_url_raw($args['url']),
                'auth_type'        => $args['auth_type'] ?? 'none',
                'auth_key'         => $args['auth_credentials'] ?? '',
                'output_table'     => sanitize_text_field($args['output_table'] ?? 'candidates'),
                'columns'          => sanitize_text_field($args['columns'] ?? 'title, summary, source_url'),
                'enabled'          => true,
            ];

            $source_id = RawWire_Scraper_Settings::save_source($source);

            return [
                'success'   => true,
                'source_id' => $source_id,
                'source'    => $source,
            ];
        }

        // Fallback: direct DB insert (legacy)
        global $wpdb;
        $source_id = sanitize_title($args['name']) . '_' . time();

        $source = [
            'id'               => $source_id,
            'name'             => sanitize_text_field($args['name']),
            'type'             => sanitize_text_field($args['type']),
            'address'          => esc_url_raw($args['url']),
            'url'              => esc_url_raw($args['url']),
            'auth_type'        => $args['auth_type'] ?? 'none',
            'auth_key'         => $args['auth_credentials'] ?? '',
            'output_table'     => sanitize_text_field($args['output_table'] ?? 'candidates'),
            'columns'          => sanitize_text_field($args['columns'] ?? 'title, summary, source_url'),
            'enabled'          => true,
            'created_at'       => current_time('mysql'),
        ];

        // Direct DB access - same as RawWire_Scraper_Settings::save_source()
        $option_name = 'rawwire_scraper_sources';
        $row = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            $option_name
        ));
        $sources = $row ? maybe_unserialize($row) : [];
        if (!is_array($sources)) $sources = [];

        $sources[$source_id] = $source;
        $serialized = maybe_serialize($sources);

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s",
            $option_name
        ));

        if ($exists) {
            $wpdb->update($wpdb->options, ['option_value' => $serialized], ['option_name' => $option_name]);
        } else {
            $wpdb->insert($wpdb->options, ['option_name' => $option_name, 'option_value' => $serialized, 'autoload' => 'yes']);
        }

        return [
            'success'   => true,
            'source_id' => $source_id,
            'source'    => $source,
        ];
    }

    /**
     * Handle: Score content
     */
    public function handle_content_score($args)
    {
        $content = $args['content'] ?? '';
        $criteria = $args['criteria'] ?? [];

        if (empty($content)) {
            return ['error' => true, 'message' => 'content is required'];
        }

        $ai = rawwire_ai();
        if (!$ai->is_available()) {
            return ['error' => true, 'message' => $ai->get_unavailable_message()];
        }

        $result = $ai->score_content($content, $criteria);

        if (is_wp_error($result)) {
            return ['error' => true, 'message' => $result->get_error_message()];
        }

        return $result;
    }

    /**
     * Handle: Generate content
     */
    public function handle_content_generate($args)
    {
        $topic = $args['topic'] ?? '';

        if (empty($topic)) {
            return ['error' => true, 'message' => 'topic is required'];
        }

        $ai = rawwire_ai();
        if (!$ai->is_available()) {
            return ['error' => true, 'message' => $ai->get_unavailable_message()];
        }

        $options = [
            'word_count' => $args['word_count'] ?? 800,
            'tone'       => $args['tone'] ?? 'professional',
            'keywords'   => $args['keywords'] ?? [],
            'format'     => $args['format'] ?? 'blog post',
        ];

        $result = $ai->generate_article($topic, $options);

        if (is_wp_error($result)) {
            return ['error' => true, 'message' => $result->get_error_message()];
        }

        return [
            'success' => true,
            'content' => $result,
            'word_count' => str_word_count(strip_tags($result)),
        ];
    }

    /**
     * Handle: Summarize content
     */
    public function handle_content_summarize($args)
    {
        $content = $args['content'] ?? '';
        $length = $args['length'] ?? 150;

        if (empty($content)) {
            return ['error' => true, 'message' => 'content is required'];
        }

        $ai = rawwire_ai();
        if (!$ai->is_available()) {
            return ['error' => true, 'message' => $ai->get_unavailable_message()];
        }

        $result = $ai->summarize($content, $length);

        if (is_wp_error($result)) {
            return ['error' => true, 'message' => $result->get_error_message()];
        }

        return [
            'success' => true,
            'summary' => $result,
        ];
    }

    /**
     * Handle: List tools
     */
    public function handle_tools_list($args)
    {
        $category = $args['category'] ?? 'all';

        if (!$this->tool_registry) {
            return ['error' => true, 'message' => 'Tool Registry not available'];
        }

        $tools = $this->tool_registry->get_all();

        if ($category !== 'all') {
            $tools = array_filter($tools, function ($tool) use ($category) {
                return ($tool['category'] ?? '') === $category;
            });
        }

        return [
            'count' => count($tools),
            'tools' => array_values($tools),
        ];
    }

    /**
     * Handle: Execute tool
     */
    public function handle_tool_execute($args)
    {
        $tool_id = $args['tool_id'] ?? '';
        $params = $args['params'] ?? [];
        $async = $args['async'] ?? false;

        if (empty($tool_id)) {
            return ['error' => true, 'message' => 'tool_id is required'];
        }

        if (!$this->tool_registry) {
            return ['error' => true, 'message' => 'Tool Registry not available'];
        }

        if ($async) {
            $action_id = $this->tool_registry->schedule($tool_id, $params);
            return [
                'success'   => true,
                'message'   => 'Tool scheduled for async execution',
                'action_id' => $action_id,
            ];
        }

        $result = $this->tool_registry->run($tool_id, $params);

        if (is_wp_error($result)) {
            return ['error' => true, 'message' => $result->get_error_message()];
        }

        return [
            'success' => true,
            'result'  => $result,
        ];
    }

    /**
     * Handle: Schedule tool
     */
    public function handle_tool_schedule($args)
    {
        $tool_id = $args['tool_id'] ?? '';
        $schedule_type = $args['schedule_type'] ?? 'once';
        $start_time = $args['start_time'] ?? null;
        $params = $args['params'] ?? [];

        if (empty($tool_id)) {
            return ['error' => true, 'message' => 'tool_id is required'];
        }

        if (!$this->tool_registry) {
            return ['error' => true, 'message' => 'Tool Registry not available'];
        }

        // Parse start time
        $timestamp = $start_time ? strtotime($start_time) : time();

        // Calculate interval for recurring schedules
        $interval = 0;
        switch ($schedule_type) {
            case 'hourly':
                $interval = HOUR_IN_SECONDS;
                break;
            case 'daily':
                $interval = DAY_IN_SECONDS;
                break;
            case 'weekly':
                $interval = WEEK_IN_SECONDS;
                break;
        }

        if ($interval > 0) {
            $action_id = $this->tool_registry->schedule_recurring($tool_id, $params, $timestamp, $interval);
        } else {
            $action_id = $this->tool_registry->schedule($tool_id, $params, $timestamp);
        }

        return [
            'success'       => true,
            'action_id'     => $action_id,
            'schedule_type' => $schedule_type,
            'next_run'      => date('Y-m-d H:i:s', $timestamp),
        ];
    }

    /**
     * Handle: Query data
     */
    public function handle_data_query($args)
    {
        global $wpdb;

        $table = $args['table'] ?? '';
        $limit = min($args['limit'] ?? 20, 100);
        $offset = $args['offset'] ?? 0;
        $order_by = $args['order_by'] ?? 'id';
        $order = strtoupper($args['order'] ?? 'DESC');

        if (empty($table)) {
            return ['error' => true, 'message' => 'table is required'];
        }

        // Sanitize table name - only allow rawwire_ prefixed tables
        if (strpos($table, 'rawwire_') !== 0) {
            $table = 'rawwire_' . $table;
        }

        $full_table = $wpdb->prefix . $table;

        // Check if table exists
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $full_table)) !== $full_table) {
            return ['error' => true, 'message' => 'Table not found: ' . $table];
        }

        // Query data
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$full_table}` ORDER BY `{$order_by}` {$order} LIMIT %d OFFSET %d",
                $limit,
                $offset
            ),
            ARRAY_A
        );

        // Get total count
        $total = $wpdb->get_var("SELECT COUNT(*) FROM `{$full_table}`");

        return [
            'success' => true,
            'table'   => $table,
            'total'   => (int) $total,
            'count'   => count($results),
            'offset'  => $offset,
            'data'    => $results,
        ];
    }

    /**
     * Handle: Get stats
     */
    public function handle_stats_get($args)
    {
        $period = $args['period'] ?? 'week';

        $stats = [
            'period'           => $period,
            'scraper_runs'     => 0,
            'content_generated' => 0,
            'tools_executed'   => 0,
            'ai_queries'       => 0,
        ];

        // Get from stored stats
        $stored = get_option('rawwire_dashboard_stats', []);

        // Calculate based on period
        $cutoff = strtotime('-1 ' . $period);

        foreach ($stored as $date => $day_stats) {
            if (strtotime($date) >= $cutoff) {
                $stats['scraper_runs'] += $day_stats['scraper_runs'] ?? 0;
                $stats['content_generated'] += $day_stats['content_generated'] ?? 0;
                $stats['tools_executed'] += $day_stats['tools_executed'] ?? 0;
                $stats['ai_queries'] += $day_stats['ai_queries'] ?? 0;
            }
        }

        // Add AI Engine status
        $ai = rawwire_ai();
        $stats['ai_engine'] = $ai->get_status();

        return $stats;
    }

    /**
     * Handle: Create workflow
     */
    public function handle_workflow_create($args)
    {
        $name = $args['name'] ?? '';
        $steps = $args['steps'] ?? [];

        if (empty($name) || empty($steps)) {
            return ['error' => true, 'message' => 'name and steps are required'];
        }

        $workflow_id = sanitize_title($name) . '_' . time();

        $workflow = [
            'id'          => $workflow_id,
            'name'        => sanitize_text_field($name),
            'description' => sanitize_text_field($args['description'] ?? ''),
            'steps'       => $steps,
            'trigger'     => $args['trigger'] ?? 'manual',
            'status'      => 'active',
            'created'     => current_time('mysql'),
            'runs'        => 0,
        ];

        $workflows = get_option('rawwire_workflows', []);
        $workflows[$workflow_id] = $workflow;
        update_option('rawwire_workflows', $workflows);

        return [
            'success'     => true,
            'workflow_id' => $workflow_id,
            'workflow'    => $workflow,
        ];
    }

    // =========================================================================
    // MCP PROTOCOL INTEGRATION (for Claude Desktop, etc.)
    // =========================================================================

    /**
     * Register tools with AI Engine's MCP module
     * 
     * This makes Raw Wire tools available to external MCP clients
     * like Claude Desktop, OpenAI Agents, etc.
     *
     * @param array $tools Existing MCP tools
     * @return array Modified tools array
     */
    public function register_mcp_protocol_tools($tools)
    {
        foreach ($this->mcp_tools as $name => $tool) {
            $tools[$name] = [
                'name'        => $name,
                'description' => $tool['description'],
                'inputSchema' => $tool['parameters'] ?? [
                    'type' => 'object',
                    'properties' => [],
                ],
            ];
        }

        return $tools;
    }

    /**
     * Handle MCP protocol tool calls
     *
     * @param mixed  $result   Current result (null if not handled)
     * @param string $tool     Tool name being called
     * @param array  $args     Arguments passed to the tool
     * @param string $id       Request ID
     * @return mixed Result or null to pass to next handler
     */
    public function handle_mcp_protocol_call($result, $tool, $args, $id)
    {
        // Only handle our tools
        if (!isset($this->mcp_tools[$tool])) {
            return $result;
        }

        // Execute via our callback
        $tool_def = $this->mcp_tools[$tool];

        if (is_callable($tool_def['callback'])) {
            try {
                $output = call_user_func($tool_def['callback'], $args);

                // Format result for MCP protocol
                return [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => is_string($output) ? $output : wp_json_encode($output, JSON_PRETTY_PRINT),
                        ]
                    ],
                    'isError' => isset($output['error']) && $output['error'],
                ];
            } catch (Exception $e) {
                return [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'Error: ' . $e->getMessage(),
                        ]
                    ],
                    'isError' => true,
                ];
            }
        }

        return $result;
    }

    // =========================================================================
    // WORDPRESS DEBUGGING HANDLERS
    // =========================================================================

    /**
     * Handle: Get WordPress system info
     */
    public function handle_wp_system_info($args)
    {
        global $wpdb, $wp_version;

        $theme = wp_get_theme();

        return [
            'wordpress' => [
                'version'       => $wp_version,
                'multisite'     => is_multisite(),
                'site_url'      => site_url(),
                'home_url'      => home_url(),
                'admin_email'   => get_option('admin_email'),
                'timezone'      => wp_timezone_string(),
                'debug_mode'    => defined('WP_DEBUG') && WP_DEBUG,
                'debug_log'     => defined('WP_DEBUG_LOG') && WP_DEBUG_LOG,
            ],
            'php' => [
                'version'         => phpversion(),
                'memory_limit'    => ini_get('memory_limit'),
                'max_execution'   => ini_get('max_execution_time'),
                'upload_max'      => ini_get('upload_max_filesize'),
                'post_max'        => ini_get('post_max_size'),
                'extensions'      => get_loaded_extensions(),
            ],
            'server' => [
                'software'  => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
                'php_sapi'  => php_sapi_name(),
                'os'        => PHP_OS,
            ],
            'database' => [
                'version'   => $wpdb->db_version(),
                'prefix'    => $wpdb->prefix,
                'charset'   => $wpdb->charset,
            ],
            'theme' => [
                'name'      => $theme->get('Name'),
                'version'   => $theme->get('Version'),
                'parent'    => $theme->parent() ? $theme->parent()->get('Name') : null,
            ],
            'constants' => [
                'WP_DEBUG'        => defined('WP_DEBUG') ? WP_DEBUG : false,
                'WP_DEBUG_LOG'    => defined('WP_DEBUG_LOG') ? WP_DEBUG_LOG : false,
                'SCRIPT_DEBUG'    => defined('SCRIPT_DEBUG') ? SCRIPT_DEBUG : false,
                'WP_CACHE'        => defined('WP_CACHE') ? WP_CACHE : false,
                'DISABLE_WP_CRON' => defined('DISABLE_WP_CRON') ? DISABLE_WP_CRON : false,
            ],
        ];
    }

    /**
     * Handle: List plugins
     */
    public function handle_wp_plugins($args)
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $status = $args['status'] ?? 'all';
        $all_plugins = get_plugins();
        $active_plugins = get_option('active_plugins', []);
        $mu_plugins = get_mu_plugins();

        $plugins = [];

        foreach ($all_plugins as $path => $plugin) {
            $is_active = in_array($path, $active_plugins);

            if ($status === 'active' && !$is_active) continue;
            if ($status === 'inactive' && $is_active) continue;

            $plugins[] = [
                'name'        => $plugin['Name'],
                'version'     => $plugin['Version'],
                'author'      => $plugin['Author'],
                'path'        => $path,
                'active'      => $is_active,
                'update'      => $this->plugin_has_update($path),
            ];
        }

        if ($status === 'all' || $status === 'must-use') {
            foreach ($mu_plugins as $path => $plugin) {
                $plugins[] = [
                    'name'    => $plugin['Name'],
                    'version' => $plugin['Version'],
                    'path'    => $path,
                    'active'  => true,
                    'mu'      => true,
                ];
            }
        }

        return [
            'total'   => count($plugins),
            'active'  => count($active_plugins),
            'plugins' => $plugins,
        ];
    }

    /**
     * Check if plugin has update available
     */
    private function plugin_has_update($path)
    {
        $update_plugins = get_site_transient('update_plugins');
        return isset($update_plugins->response[$path]);
    }

    /**
     * Handle: Read error log
     */
    public function handle_wp_error_log($args)
    {
        $lines = min($args['lines'] ?? 100, 500);
        $filter = $args['filter'] ?? 'all';

        // Find debug.log
        $log_file = WP_CONTENT_DIR . '/debug.log';

        if (!file_exists($log_file)) {
            return [
                'error'   => true,
                'message' => 'debug.log not found. Enable WP_DEBUG_LOG in wp-config.php',
                'path'    => $log_file,
            ];
        }

        // Read last N lines
        $file = new SplFileObject($log_file, 'r');
        $file->seek(PHP_INT_MAX);
        $total_lines = $file->key();

        $start = max(0, $total_lines - $lines);
        $log_entries = [];

        $file->seek($start);
        while (!$file->eof()) {
            $line = $file->current();
            $file->next();

            if (empty(trim($line))) continue;

            // Parse and filter
            $entry = $this->parse_log_line($line);

            if ($filter !== 'all') {
                if ($filter === 'fatal' && stripos($line, 'fatal') === false) continue;
                if ($filter === 'warning' && stripos($line, 'warning') === false) continue;
                if ($filter === 'notice' && stripos($line, 'notice') === false) continue;
                if ($filter === 'deprecated' && stripos($line, 'deprecated') === false) continue;
            }

            $log_entries[] = $entry;
        }

        return [
            'file'    => $log_file,
            'size'    => filesize($log_file),
            'lines'   => count($log_entries),
            'entries' => array_slice($log_entries, -$lines),
        ];
    }

    /**
     * Parse a log line into structured data
     */
    private function parse_log_line($line)
    {
        // Try to extract timestamp and level
        if (preg_match('/^\[(\d{2}-\w{3}-\d{4} \d{2}:\d{2}:\d{2}[^\]]*)\]\s*(.*)$/i', $line, $matches)) {
            return [
                'timestamp' => $matches[1],
                'message'   => $matches[2],
            ];
        }
        return ['message' => trim($line)];
    }

    /**
     * Handle: Database info
     */
    public function handle_wp_database($args)
    {
        global $wpdb;

        $action = $args['action'] ?? 'info';

        switch ($action) {
            case 'table_sizes':
                $tables = $wpdb->get_results("
                    SELECT 
                        table_name AS 'table',
                        ROUND(data_length / 1024 / 1024, 2) AS data_mb,
                        ROUND(index_length / 1024 / 1024, 2) AS index_mb,
                        table_rows AS rows
                    FROM information_schema.tables
                    WHERE table_schema = DATABASE()
                    ORDER BY (data_length + index_length) DESC
                ");
                return ['tables' => $tables];

            case 'optimize':
                $tables = $wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}%'");
                $optimized = [];
                foreach ($tables as $table) {
                    $wpdb->query("OPTIMIZE TABLE `{$table}`");
                    $optimized[] = $table;
                }
                return ['optimized' => $optimized, 'count' => count($optimized)];

            case 'check_tables':
                $tables = $wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}%'");
                $results = [];
                foreach ($tables as $table) {
                    $check = $wpdb->get_row("CHECK TABLE `{$table}`");
                    $results[] = [
                        'table'  => $table,
                        'status' => $check->Msg_text ?? 'Unknown',
                    ];
                }
                return ['tables' => $results];

            default: // info
                $size = $wpdb->get_var("
                    SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2)
                    FROM information_schema.tables
                    WHERE table_schema = DATABASE()
                ");
                $tables = $wpdb->get_var("
                    SELECT COUNT(*)
                    FROM information_schema.tables
                    WHERE table_schema = DATABASE()
                ");
                return [
                    'version'     => $wpdb->db_version(),
                    'total_size'  => $size . ' MB',
                    'table_count' => $tables,
                    'prefix'      => $wpdb->prefix,
                    'last_error'  => $wpdb->last_error ?: null,
                ];
        }
    }

    /**
     * Handle: Site Health check
     */
    public function handle_wp_health_check($args)
    {
        if (!class_exists('WP_Site_Health')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
        }

        $category = $args['category'] ?? 'all';
        $health = WP_Site_Health::get_instance();

        $tests = WP_Site_Health::get_tests();
        $results = [];

        // Run direct tests
        foreach ($tests['direct'] as $test_name => $test) {
            if (!is_callable($test['test'])) continue;

            try {
                $result = call_user_func($test['test']);

                if ($category !== 'all' && $result['status'] !== $category) {
                    continue;
                }

                $results[] = [
                    'test'   => $test_name,
                    'label'  => $result['label'] ?? $test_name,
                    'status' => $result['status'],
                    'badge'  => $result['badge']['label'] ?? '',
                ];
            } catch (Exception $e) {
                // Skip failed tests
            }
        }

        // Count by status
        $counts = ['good' => 0, 'recommended' => 0, 'critical' => 0];
        foreach ($results as $r) {
            if (isset($counts[$r['status']])) {
                $counts[$r['status']]++;
            }
        }

        return [
            'summary' => $counts,
            'tests'   => $results,
        ];
    }

    /**
     * Handle: Cron jobs
     */
    public function handle_wp_cron($args)
    {
        $action = $args['action'] ?? 'list';

        switch ($action) {
            case 'check_stuck':
                $last_run = get_option('rawwire_last_cron_run', 0);
                $current = time();
                $cron_running = defined('DOING_CRON') && DOING_CRON;

                return [
                    'cron_disabled'  => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
                    'last_run'       => $last_run ? date('Y-m-d H:i:s', $last_run) : 'Never tracked',
                    'currently_running' => $cron_running,
                    'next_scheduled' => wp_next_scheduled('wp_version_check')
                        ? date('Y-m-d H:i:s', wp_next_scheduled('wp_version_check'))
                        : 'None',
                ];

            case 'run_event':
                $event = $args['event'] ?? '';
                if (empty($event)) {
                    return ['error' => true, 'message' => 'Event hook name required'];
                }
                $timestamp = wp_next_scheduled($event);
                if (!$timestamp) {
                    return ['error' => true, 'message' => 'Event not found: ' . $event];
                }
                spawn_cron($timestamp);
                return ['success' => true, 'message' => 'Triggered: ' . $event];

            default: // list
                $cron = _get_cron_array();
                $events = [];

                foreach ($cron as $timestamp => $hooks) {
                    foreach ($hooks as $hook => $schedules) {
                        foreach ($schedules as $key => $data) {
                            $events[] = [
                                'hook'      => $hook,
                                'next_run'  => date('Y-m-d H:i:s', $timestamp),
                                'schedule'  => $data['schedule'] ?: 'single',
                                'args'      => $data['args'],
                            ];
                        }
                    }
                }

                // Sort by timestamp
                usort($events, fn($a, $b) => strtotime($a['next_run']) - strtotime($b['next_run']));

                return [
                    'total'  => count($events),
                    'events' => array_slice($events, 0, 50),
                ];
        }
    }

    /**
     * Handle: Options search
     */
    public function handle_wp_options($args)
    {
        global $wpdb;

        $search = $args['search'] ?? '';
        $option = $args['option'] ?? '';

        if (!empty($option)) {
            $value = get_option($option, null);
            return [
                'option' => $option,
                'value'  => $value,
                'exists' => $value !== null,
            ];
        }

        if (!empty($search)) {
            $results = $wpdb->get_results($wpdb->prepare("
                SELECT option_name, LENGTH(option_value) as size
                FROM {$wpdb->options}
                WHERE option_name LIKE %s
                ORDER BY option_name
                LIMIT 50
            ", '%' . $wpdb->esc_like($search) . '%'));

            return [
                'search'  => $search,
                'count'   => count($results),
                'options' => $results,
            ];
        }

        return ['error' => true, 'message' => 'Provide search term or option name'];
    }

    /**
     * Handle: Transients
     */
    public function handle_wp_transients($args)
    {
        global $wpdb;

        $action = $args['action'] ?? 'list';

        switch ($action) {
            case 'clear_expired':
                $deleted = $wpdb->query("
                    DELETE FROM {$wpdb->options}
                    WHERE option_name LIKE '_transient_timeout_%'
                    AND option_value < UNIX_TIMESTAMP()
                ");
                $wpdb->query("
                    DELETE FROM {$wpdb->options}
                    WHERE option_name LIKE '_transient_%'
                    AND option_name NOT LIKE '_transient_timeout_%'
                    AND option_name NOT IN (
                        SELECT REPLACE(option_name, '_transient_timeout_', '_transient_')
                        FROM {$wpdb->options}
                        WHERE option_name LIKE '_transient_timeout_%'
                    )
                ");
                return ['cleared' => $deleted];

            case 'delete':
                $name = $args['name'] ?? '';
                if (empty($name)) {
                    return ['error' => true, 'message' => 'Transient name required'];
                }
                delete_transient($name);
                return ['deleted' => $name];

            case 'search':
                $search = $args['search'] ?? '';
                $results = $wpdb->get_results($wpdb->prepare("
                    SELECT option_name, LENGTH(option_value) as size
                    FROM {$wpdb->options}
                    WHERE option_name LIKE %s
                    LIMIT 50
                ", '%_transient_%' . $wpdb->esc_like($search) . '%'));
                return ['transients' => $results];

            default: // list
                $transients = $wpdb->get_results("
                    SELECT option_name, LENGTH(option_value) as size
                    FROM {$wpdb->options}
                    WHERE option_name LIKE '_transient_%'
                    AND option_name NOT LIKE '_transient_timeout_%'
                    ORDER BY size DESC
                    LIMIT 50
                ");
                $total = $wpdb->get_var("
                    SELECT COUNT(*) FROM {$wpdb->options}
                    WHERE option_name LIKE '_transient_%'
                    AND option_name NOT LIKE '_transient_timeout_%'
                ");
                return [
                    'total'      => $total,
                    'transients' => $transients,
                ];
        }
    }

    /**
     * Handle: Hooks inspection
     */
    public function handle_wp_hooks($args)
    {
        global $wp_filter;

        $hook = $args['hook'] ?? '';
        $search = $args['search'] ?? '';
        $type = $args['type'] ?? 'all';

        if (!empty($hook)) {
            if (!isset($wp_filter[$hook])) {
                return ['error' => true, 'message' => 'Hook not found: ' . $hook];
            }

            $callbacks = [];
            foreach ($wp_filter[$hook]->callbacks as $priority => $funcs) {
                foreach ($funcs as $func) {
                    $callbacks[] = [
                        'priority' => $priority,
                        'function' => $this->get_callback_name($func['function']),
                        'args'     => $func['accepted_args'],
                    ];
                }
            }

            return [
                'hook'      => $hook,
                'callbacks' => $callbacks,
            ];
        }

        // Search or list hooks
        $hooks = [];
        foreach (array_keys($wp_filter) as $name) {
            if (!empty($search) && stripos($name, $search) === false) {
                continue;
            }

            $count = 0;
            foreach ($wp_filter[$name]->callbacks as $funcs) {
                $count += count($funcs);
            }

            $hooks[] = [
                'hook'           => $name,
                'callback_count' => $count,
            ];
        }

        // Sort by callback count
        usort($hooks, fn($a, $b) => $b['callback_count'] - $a['callback_count']);

        return [
            'total' => count($hooks),
            'hooks' => array_slice($hooks, 0, 50),
        ];
    }

    /**
     * Get human-readable callback name
     */
    private function get_callback_name($callback)
    {
        if (is_string($callback)) {
            return $callback;
        }
        if (is_array($callback)) {
            if (is_object($callback[0])) {
                return get_class($callback[0]) . '->' . $callback[1];
            }
            return $callback[0] . '::' . $callback[1];
        }
        if ($callback instanceof Closure) {
            return 'Closure';
        }
        return 'Unknown';
    }

    /**
     * Handle: Memory usage
     */
    public function handle_wp_memory($args)
    {
        $memory_limit = ini_get('memory_limit');
        $memory_used = memory_get_usage(true);
        $peak_memory = memory_get_peak_usage(true);

        // Parse limit to bytes
        $limit_bytes = wp_convert_hr_to_bytes($memory_limit);

        return [
            'memory_limit'   => $memory_limit,
            'memory_used'    => size_format($memory_used),
            'memory_peak'    => size_format($peak_memory),
            'usage_percent'  => round(($memory_used / $limit_bytes) * 100, 1) . '%',
            'queries'        => get_num_queries(),
            'query_time'     => timer_stop(0, 3) . 's',
            'object_cache'   => wp_using_ext_object_cache() ? 'External' : 'WordPress Default',
        ];
    }

    // =========================================================================
    // WORDPRESS ACTION HANDLERS (Write Operations)
    // =========================================================================

    /**
     * Handle: Plugin management (activate/deactivate)
     */
    public function handle_wp_plugin_manage($args)
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $action = $args['action'] ?? 'status';
        $plugin = $args['plugin'] ?? '';

        if (empty($plugin)) {
            return ['error' => true, 'message' => 'Plugin path or slug required'];
        }

        // Try to resolve plugin slug to full path
        $all_plugins = get_plugins();
        $plugin_path = null;

        foreach ($all_plugins as $path => $data) {
            if ($path === $plugin || strpos($path, $plugin . '/') === 0 || $data['TextDomain'] === $plugin) {
                $plugin_path = $path;
                break;
            }
        }

        if (!$plugin_path) {
            return ['error' => true, 'message' => 'Plugin not found: ' . $plugin];
        }

        $is_active = is_plugin_active($plugin_path);
        $plugin_data = $all_plugins[$plugin_path];

        switch ($action) {
            case 'activate':
                if ($is_active) {
                    return [
                        'success' => true,
                        'message' => 'Plugin already active',
                        'plugin'  => $plugin_data['Name'],
                    ];
                }

                $result = activate_plugin($plugin_path);
                if (is_wp_error($result)) {
                    return [
                        'error'   => true,
                        'message' => $result->get_error_message(),
                        'plugin'  => $plugin_data['Name'],
                    ];
                }

                return [
                    'success' => true,
                    'message' => 'Plugin activated successfully',
                    'plugin'  => $plugin_data['Name'],
                    'version' => $plugin_data['Version'],
                ];

            case 'deactivate':
                if (!$is_active) {
                    return [
                        'success' => true,
                        'message' => 'Plugin already inactive',
                        'plugin'  => $plugin_data['Name'],
                    ];
                }

                deactivate_plugins($plugin_path);

                return [
                    'success' => true,
                    'message' => 'Plugin deactivated successfully',
                    'plugin'  => $plugin_data['Name'],
                ];

            default: // status
                return [
                    'plugin'      => $plugin_data['Name'],
                    'version'     => $plugin_data['Version'],
                    'author'      => $plugin_data['Author'],
                    'path'        => $plugin_path,
                    'active'      => $is_active,
                    'network'     => is_plugin_active_for_network($plugin_path),
                    'description' => $plugin_data['Description'],
                ];
        }
    }

    /**
     * Handle: Option update/delete
     */
    public function handle_wp_option_update($args)
    {
        $action = $args['action'] ?? 'update';
        $option = $args['option'] ?? '';
        $value = $args['value'] ?? '';
        $autoload = $args['autoload'] ?? 'yes';

        if (empty($option)) {
            return ['error' => true, 'message' => 'Option name required'];
        }

        // Security: Block modification of critical options
        $protected = ['siteurl', 'home', 'admin_email', 'users_can_register', 'default_role'];
        if (in_array($option, $protected)) {
            return ['error' => true, 'message' => 'Cannot modify protected option: ' . $option];
        }

        $old_value = get_option($option);

        switch ($action) {
            case 'update':
                // Try to decode JSON values
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE && $decoded !== null) {
                    $value = $decoded;
                }

                $result = update_option($option, $value, $autoload === 'yes');

                return [
                    'success'   => $result,
                    'option'    => $option,
                    'old_value' => is_array($old_value) ? wp_json_encode($old_value) : $old_value,
                    'new_value' => is_array($value) ? wp_json_encode($value) : $value,
                    'autoload'  => $autoload,
                ];

            case 'delete':
                $result = delete_option($option);

                return [
                    'success'   => $result,
                    'option'    => $option,
                    'deleted'   => $result,
                    'old_value' => is_array($old_value) ? wp_json_encode($old_value) : $old_value,
                ];

            default:
                return ['error' => true, 'message' => 'Invalid action: ' . $action];
        }
    }

    /**
     * Handle: Theme management
     */
    public function handle_wp_theme_manage($args)
    {
        $action = $args['action'] ?? 'list';
        $theme_slug = $args['theme'] ?? '';

        switch ($action) {
            case 'list':
                $themes = wp_get_themes();
                $active = get_stylesheet();
                $result = [];

                foreach ($themes as $slug => $theme) {
                    $result[] = [
                        'slug'    => $slug,
                        'name'    => $theme->get('Name'),
                        'version' => $theme->get('Version'),
                        'author'  => $theme->get('Author'),
                        'active'  => $slug === $active,
                        'parent'  => $theme->parent() ? $theme->parent()->get('Name') : null,
                    ];
                }

                return [
                    'active_theme' => $active,
                    'total'        => count($result),
                    'themes'       => $result,
                ];

            case 'info':
                if (empty($theme_slug)) {
                    return ['error' => true, 'message' => 'Theme slug required'];
                }

                $theme = wp_get_theme($theme_slug);
                if (!$theme->exists()) {
                    return ['error' => true, 'message' => 'Theme not found: ' . $theme_slug];
                }

                return [
                    'slug'        => $theme_slug,
                    'name'        => $theme->get('Name'),
                    'version'     => $theme->get('Version'),
                    'author'      => $theme->get('Author'),
                    'description' => $theme->get('Description'),
                    'template'    => $theme->get_template(),
                    'stylesheet'  => $theme->get_stylesheet(),
                    'screenshot'  => $theme->get_screenshot(),
                    'tags'        => $theme->get('Tags'),
                    'active'      => $theme_slug === get_stylesheet(),
                ];

            case 'switch':
                if (empty($theme_slug)) {
                    return ['error' => true, 'message' => 'Theme slug required'];
                }

                $theme = wp_get_theme($theme_slug);
                if (!$theme->exists()) {
                    return ['error' => true, 'message' => 'Theme not found: ' . $theme_slug];
                }

                $old_theme = get_stylesheet();
                switch_theme($theme_slug);

                return [
                    'success'    => true,
                    'old_theme'  => $old_theme,
                    'new_theme'  => $theme_slug,
                    'theme_name' => $theme->get('Name'),
                ];

            default:
                return ['error' => true, 'message' => 'Invalid action: ' . $action];
        }
    }

    /**
     * Handle: Cache clearing
     */
    public function handle_wp_cache_clear($args)
    {
        $cache_type = $args['cache_type'] ?? 'all';
        $post_id = $args['post_id'] ?? 0;
        $cleared = [];

        if ($cache_type === 'all' || $cache_type === 'object') {
            wp_cache_flush();
            $cleared[] = 'object_cache';
        }

        if ($cache_type === 'all' || $cache_type === 'transients') {
            global $wpdb;
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'");
            $cleared[] = 'transients';
        }

        if ($cache_type === 'all' || $cache_type === 'rewrite') {
            flush_rewrite_rules();
            $cleared[] = 'rewrite_rules';
        }

        if ($cache_type === 'post' && $post_id > 0) {
            clean_post_cache($post_id);
            $cleared[] = 'post_' . $post_id;
        }

        // Try to clear popular caching plugin caches
        if ($cache_type === 'all') {
            // WP Super Cache
            if (function_exists('wp_cache_clear_cache')) {
                wp_cache_clear_cache();
                $cleared[] = 'wp_super_cache';
            }
            // W3 Total Cache
            if (function_exists('w3tc_flush_all')) {
                w3tc_flush_all();
                $cleared[] = 'w3_total_cache';
            }
            // LiteSpeed Cache
            if (class_exists('LiteSpeed_Cache_API') && method_exists('LiteSpeed_Cache_API', 'purge_all')) {
                LiteSpeed_Cache_API::purge_all();
                $cleared[] = 'litespeed_cache';
            }
            // WP Rocket
            if (function_exists('rocket_clean_domain')) {
                rocket_clean_domain();
                $cleared[] = 'wp_rocket';
            }
        }

        return [
            'success' => true,
            'cleared' => $cleared,
            'message' => 'Cleared: ' . implode(', ', $cleared),
        ];
    }

    /**
     * Handle: File editing (read/write/backup)
     */
    public function handle_wp_file_edit($args)
    {
        $action = $args['action'] ?? 'read';
        $file_path = $args['file_path'] ?? '';
        $content = $args['content'] ?? '';
        $create_backup = $args['create_backup'] ?? true;

        if (empty($file_path)) {
            return ['error' => true, 'message' => 'File path required'];
        }

        // Security: Only allow files within wp-content
        $full_path = WP_CONTENT_DIR . '/' . ltrim($file_path, '/');
        $real_path = realpath(dirname($full_path));

        if ($real_path === false || strpos($real_path, realpath(WP_CONTENT_DIR)) !== 0) {
            return ['error' => true, 'message' => 'Access denied: File must be within wp-content'];
        }

        // Block sensitive files
        $blocked_patterns = ['.htaccess', 'wp-config', '.env', 'debug.log'];
        foreach ($blocked_patterns as $pattern) {
            if (stripos($file_path, $pattern) !== false) {
                return ['error' => true, 'message' => 'Access denied: Cannot modify ' . $pattern];
            }
        }

        switch ($action) {
            case 'read':
                if (!file_exists($full_path)) {
                    return ['error' => true, 'message' => 'File not found: ' . $file_path];
                }

                $content = file_get_contents($full_path);
                $size = filesize($full_path);

                // Limit content size for safety
                if ($size > 100000) {
                    return [
                        'error'   => true,
                        'message' => 'File too large to read (' . size_format($size) . '). Max 100KB.',
                        'size'    => $size,
                    ];
                }

                return [
                    'success' => true,
                    'path'    => $file_path,
                    'size'    => $size,
                    'content' => $content,
                    'lines'   => substr_count($content, "\n") + 1,
                ];

            case 'backup':
                if (!file_exists($full_path)) {
                    return ['error' => true, 'message' => 'File not found: ' . $file_path];
                }

                $backup_path = $full_path . '.bak.' . date('Y-m-d-His');
                $result = copy($full_path, $backup_path);

                return [
                    'success'     => $result,
                    'original'    => $file_path,
                    'backup_path' => str_replace(WP_CONTENT_DIR . '/', '', $backup_path),
                ];

            case 'write':
                if (empty($content)) {
                    return ['error' => true, 'message' => 'Content required for write action'];
                }

                // Create backup if requested
                if ($create_backup && file_exists($full_path)) {
                    $backup_path = $full_path . '.bak.' . date('Y-m-d-His');
                    copy($full_path, $backup_path);
                }

                // Ensure directory exists
                $dir = dirname($full_path);
                if (!is_dir($dir)) {
                    wp_mkdir_p($dir);
                }

                $result = file_put_contents($full_path, $content);

                return [
                    'success' => $result !== false,
                    'path'    => $file_path,
                    'bytes'   => $result,
                    'backup'  => $create_backup ? 'created' : 'skipped',
                ];

            default:
                return ['error' => true, 'message' => 'Invalid action: ' . $action];
        }
    }

    // =========================================================================
    // WORKFLOW MANAGEMENT HANDLERS
    // =========================================================================

    /**
     * Handle: List workflows
     */
    public function handle_workflow_list($args)
    {
        $status = $args['status'] ?? 'all';
        $workflows = get_option('rawwire_workflows', []);
        $result = [];

        foreach ($workflows as $id => $workflow) {
            $wf_status = $workflow['status'] ?? 'active';

            if ($status !== 'all' && $wf_status !== $status) {
                continue;
            }

            $result[] = [
                'id'          => $id,
                'name'        => $workflow['name'] ?? 'Unnamed',
                'description' => $workflow['description'] ?? '',
                'status'      => $wf_status,
                'triggers'    => $workflow['triggers'] ?? [],
                'step_count'  => count($workflow['steps'] ?? []),
                'created'     => $workflow['created'] ?? null,
                'last_run'    => $workflow['last_run'] ?? null,
                'run_count'   => $workflow['run_count'] ?? 0,
            ];
        }

        return [
            'total'     => count($result),
            'workflows' => $result,
        ];
    }

    /**
     * Handle: Trigger workflow execution
     */
    public function handle_workflow_trigger($args)
    {
        $workflow_id = $args['workflow_id'] ?? '';
        $params_json = $args['params'] ?? '{}';
        $async = $args['async'] ?? false;

        if (empty($workflow_id)) {
            return ['error' => true, 'message' => 'Workflow ID required'];
        }

        $workflows = get_option('rawwire_workflows', []);

        if (!isset($workflows[$workflow_id])) {
            return ['error' => true, 'message' => 'Workflow not found: ' . $workflow_id];
        }

        $workflow = $workflows[$workflow_id];

        if (($workflow['status'] ?? 'active') !== 'active') {
            return ['error' => true, 'message' => 'Workflow is not active'];
        }

        // Parse parameters
        $params = json_decode($params_json, true) ?: [];

        // Execute workflow
        $start_time = microtime(true);
        $results = [];
        $success = true;

        foreach ($workflow['steps'] ?? [] as $step_index => $step) {
            $step_result = $this->execute_workflow_step($step, $params, $results);
            $results['step_' . $step_index] = $step_result;

            if (isset($step_result['error']) && $step_result['error']) {
                $success = false;
                if (!($step['continue_on_error'] ?? false)) {
                    break;
                }
            }
        }

        $execution_time = round((microtime(true) - $start_time) * 1000, 2);

        // Update workflow stats
        $workflows[$workflow_id]['last_run'] = current_time('mysql');
        $workflows[$workflow_id]['run_count'] = ($workflow['run_count'] ?? 0) + 1;
        update_option('rawwire_workflows', $workflows);

        return [
            'success'        => $success,
            'workflow_id'    => $workflow_id,
            'workflow_name'  => $workflow['name'] ?? 'Unnamed',
            'steps_executed' => count($results),
            'execution_ms'   => $execution_time,
            'results'        => $results,
        ];
    }

    /**
     * Execute a single workflow step
     */
    private function execute_workflow_step($step, $params, $previous_results)
    {
        $action = $step['action'] ?? '';
        $step_params = $step['params'] ?? [];

        // Merge with provided params and previous results
        $step_params = array_merge($step_params, $params);

        switch ($action) {
            case 'run_tool':
                $tool_name = $step_params['tool'] ?? '';
                if (isset($this->mcp_tools[$tool_name])) {
                    return call_user_func($this->mcp_tools[$tool_name]['callback'], $step_params);
                }
                return ['error' => true, 'message' => 'Tool not found: ' . $tool_name];

            case 'webhook':
                $url = $step_params['url'] ?? '';
                $method = $step_params['method'] ?? 'POST';
                $body = $step_params['body'] ?? [];

                $response = wp_remote_request($url, [
                    'method'  => $method,
                    'body'    => $body,
                    'timeout' => 30,
                ]);

                if (is_wp_error($response)) {
                    return ['error' => true, 'message' => $response->get_error_message()];
                }

                return [
                    'success' => true,
                    'status'  => wp_remote_retrieve_response_code($response),
                    'body'    => wp_remote_retrieve_body($response),
                ];

            case 'delay':
                $seconds = min($step_params['seconds'] ?? 1, 30);
                sleep($seconds);
                return ['success' => true, 'delayed' => $seconds . 's'];

            case 'condition':
                // Simple condition evaluation
                $field = $step_params['field'] ?? '';
                $operator = $step_params['operator'] ?? '==';
                $value = $step_params['value'] ?? '';
                $actual = $previous_results[$field] ?? null;

                $passed = match ($operator) {
                    '==' => $actual == $value,
                    '!=' => $actual != $value,
                    '>'  => $actual > $value,
                    '<'  => $actual < $value,
                    'contains' => str_contains((string)$actual, (string)$value),
                    default => false,
                };

                return ['success' => true, 'condition_passed' => $passed];

            default:
                return ['error' => true, 'message' => 'Unknown action: ' . $action];
        }
    }

    /**
     * Handle: Delete workflow
     */
    public function handle_workflow_delete($args)
    {
        $workflow_id = $args['workflow_id'] ?? '';

        if (empty($workflow_id)) {
            return ['error' => true, 'message' => 'Workflow ID required'];
        }

        $workflows = get_option('rawwire_workflows', []);

        if (!isset($workflows[$workflow_id])) {
            return ['error' => true, 'message' => 'Workflow not found: ' . $workflow_id];
        }

        $workflow_name = $workflows[$workflow_id]['name'] ?? 'Unnamed';
        unset($workflows[$workflow_id]);
        update_option('rawwire_workflows', $workflows);

        return [
            'success'     => true,
            'workflow_id' => $workflow_id,
            'name'        => $workflow_name,
            'message'     => 'Workflow deleted',
        ];
    }

    // =========================================================================
    // DIAGNOSTIC & REPAIR HANDLERS
    // =========================================================================

    /**
     * Handle: Database repair
     */
    public function handle_repair_database($args)
    {
        global $wpdb;

        $check = $args['check'] ?? 'all';
        $dry_run = $args['dry_run'] ?? true;
        $results = [];

        // Orphaned postmeta
        if ($check === 'all' || $check === 'orphaned_postmeta') {
            $orphaned = $wpdb->get_var("
                SELECT COUNT(*) FROM {$wpdb->postmeta} pm
                LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                WHERE p.ID IS NULL
            ");

            $results['orphaned_postmeta'] = [
                'found' => (int)$orphaned,
                'action' => $dry_run ? 'would_delete' : 'deleted',
            ];

            if (!$dry_run && $orphaned > 0) {
                $wpdb->query("
                    DELETE pm FROM {$wpdb->postmeta} pm
                    LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                    WHERE p.ID IS NULL
                ");
            }
        }

        // Orphaned usermeta
        if ($check === 'all' || $check === 'orphaned_usermeta') {
            $orphaned = $wpdb->get_var("
                SELECT COUNT(*) FROM {$wpdb->usermeta} um
                LEFT JOIN {$wpdb->users} u ON um.user_id = u.ID
                WHERE u.ID IS NULL
            ");

            $results['orphaned_usermeta'] = [
                'found' => (int)$orphaned,
                'action' => $dry_run ? 'would_delete' : 'deleted',
            ];

            if (!$dry_run && $orphaned > 0) {
                $wpdb->query("
                    DELETE um FROM {$wpdb->usermeta} um
                    LEFT JOIN {$wpdb->users} u ON um.user_id = u.ID
                    WHERE u.ID IS NULL
                ");
            }
        }

        // Corrupt serialized options
        if ($check === 'all' || $check === 'corrupt_options') {
            $all_options = $wpdb->get_results("
                SELECT option_name, option_value FROM {$wpdb->options}
                WHERE option_value LIKE 'a:%' OR option_value LIKE 'O:%'
                LIMIT 500
            ");

            $corrupt = [];
            foreach ($all_options as $opt) {
                $test = @unserialize($opt->option_value);
                if ($test === false && $opt->option_value !== 'b:0;') {
                    $corrupt[] = $opt->option_name;
                }
            }

            $results['corrupt_options'] = [
                'found'   => count($corrupt),
                'options' => array_slice($corrupt, 0, 20),
                'action'  => 'listed_only',
            ];
        }

        // Autoload size check
        if ($check === 'all' || $check === 'autoload_size') {
            $autoload_size = $wpdb->get_var("
                SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options}
                WHERE autoload = 'yes'
            ");

            $large_autoload = $wpdb->get_results("
                SELECT option_name, LENGTH(option_value) as size
                FROM {$wpdb->options}
                WHERE autoload = 'yes'
                ORDER BY size DESC
                LIMIT 10
            ");

            $results['autoload_size'] = [
                'total_bytes' => (int)$autoload_size,
                'total_human' => size_format($autoload_size),
                'largest'     => $large_autoload,
                'warning'     => $autoload_size > 1000000 ? 'Autoload exceeds 1MB - may slow page loads' : null,
            ];
        }

        return [
            'dry_run' => $dry_run,
            'checks'  => $results,
            'note'    => $dry_run ? 'Run with dry_run=false to apply changes' : 'Changes applied',
        ];
    }

    /**
     * Handle: Safe mode toggle
     */
    public function handle_safe_mode($args)
    {
        $action = $args['action'] ?? 'status';
        $keep_plugins = $args['keep_plugins'] ?? '';

        $safe_mode_option = 'rawwire_safe_mode_backup';

        switch ($action) {
            case 'status':
                $backup = get_option($safe_mode_option, null);
                return [
                    'safe_mode_active' => $backup !== null,
                    'backed_up_plugins' => $backup ? count($backup) : 0,
                ];

            case 'enable':
                // Check if already in safe mode
                if (get_option($safe_mode_option)) {
                    return ['error' => true, 'message' => 'Safe mode already active'];
                }

                // Get current active plugins
                $active_plugins = get_option('active_plugins', []);

                // Store backup
                update_option($safe_mode_option, $active_plugins);

                // Parse plugins to keep
                $keep = array_filter(array_map('trim', explode(',', $keep_plugins)));

                // Always keep raw-wire-dashboard
                $keep[] = 'raw-wire-dashboard';

                // Filter to only keep specified plugins
                $new_active = [];
                foreach ($active_plugins as $plugin) {
                    foreach ($keep as $keep_slug) {
                        if (strpos($plugin, $keep_slug) !== false) {
                            $new_active[] = $plugin;
                            break;
                        }
                    }
                }

                update_option('active_plugins', $new_active);

                return [
                    'success'         => true,
                    'message'         => 'Safe mode enabled',
                    'plugins_disabled' => count($active_plugins) - count($new_active),
                    'plugins_active'  => count($new_active),
                ];

            case 'disable':
                $backup = get_option($safe_mode_option);

                if (!$backup) {
                    return ['error' => true, 'message' => 'Safe mode not active (no backup found)'];
                }

                // Restore plugins
                update_option('active_plugins', $backup);
                delete_option($safe_mode_option);

                return [
                    'success'          => true,
                    'message'          => 'Safe mode disabled, plugins restored',
                    'plugins_restored' => count($backup),
                ];

            default:
                return ['error' => true, 'message' => 'Invalid action: ' . $action];
        }
    }
}

// Initialize MCP Server (only when enabled in Tool Toggle Manager)
if (function_exists('rawwire_tools') && rawwire_tools()->is_tool_enabled('mcp_server')) {
    RawWire_MCP_Server::get_instance();
}
