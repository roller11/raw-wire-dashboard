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
class RawWire_MCP_Server {

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
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor
     */
    private function __construct() {
        add_action('init', [$this, 'init']);
        add_action('plugins_loaded', [$this, 'late_init'], 25);
    }

    /**
     * Initialize MCP Server
     */
    public function init() {
        // Register MCP tools with AI Engine
        add_filter('mwai_functions_list', [$this, 'register_mcp_functions']);
        
        // Handle MCP function calls
        add_filter('mwai_functions_execute', [$this, 'execute_mcp_function'], 10, 3);
        
        // Register REST endpoints for external MCP clients
        add_action('rest_api_init', [$this, 'register_rest_endpoints']);
        
        // Initialize default tools
        $this->register_default_tools();
    }

    /**
     * Late initialization after Tool Registry is available
     */
    public function late_init() {
        if (class_exists('RawWire_Tool_Registry')) {
            $this->tool_registry = RawWire_Tool_Registry::get_instance();
        }
    }

    /**
     * Register default MCP tools
     */
    private function register_default_tools() {
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
    }

    /**
     * Register a tool with the MCP server
     * 
     * @param array $tool Tool configuration
     */
    public function register_tool($tool) {
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
    public function get_tools() {
        return $this->mcp_tools;
    }

    /**
     * Register MCP functions with AI Engine
     * 
     * @param array $functions Existing functions
     * @return array
     */
    public function register_mcp_functions($functions) {
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
    public function execute_mcp_function($result, $func_name, $args) {
        if (!isset($this->mcp_tools[$func_name])) {
            return $result;
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
     * Register REST API endpoints
     */
    public function register_rest_endpoints() {
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
    public function check_permission() {
        return current_user_can('manage_options');
    }

    /**
     * REST: List available tools
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function rest_list_tools($request) {
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
    public function rest_execute_tool($request) {
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
    public function rest_get_schema($request) {
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
    public function handle_scraper_list_sources($args) {
        $status = $args['status'] ?? 'all';
        
        // Use central getter for fresh data
        $sources = class_exists('RawWire_Scraper_Settings') 
            ? RawWire_Scraper_Settings::get_sources() 
            : [];

        if ($status !== 'all') {
            $sources = array_filter($sources, function($source) use ($status) {
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
    public function handle_scraper_run($args) {
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
    public function handle_scraper_add_source($args) {
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
    public function handle_content_score($args) {
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
    public function handle_content_generate($args) {
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
    public function handle_content_summarize($args) {
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
    public function handle_tools_list($args) {
        $category = $args['category'] ?? 'all';

        if (!$this->tool_registry) {
            return ['error' => true, 'message' => 'Tool Registry not available'];
        }

        $tools = $this->tool_registry->get_all();

        if ($category !== 'all') {
            $tools = array_filter($tools, function($tool) use ($category) {
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
    public function handle_tool_execute($args) {
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
    public function handle_tool_schedule($args) {
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
    public function handle_data_query($args) {
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
    public function handle_stats_get($args) {
        $period = $args['period'] ?? 'week';

        $stats = [
            'period'           => $period,
            'scraper_runs'     => 0,
            'content_generated'=> 0,
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
    public function handle_workflow_create($args) {
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
}

// Initialize MCP Server
RawWire_MCP_Server::get_instance();
