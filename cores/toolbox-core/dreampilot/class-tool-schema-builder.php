<?php

/**
 * Tool Schema Builder - Generate OpenAI-format tool definitions from registry
 *
 * Reads the Toolbox Core registry and adapter interfaces to produce
 * tool definitions compatible with the OpenAI function-calling specification.
 * Maps tool call names back to adapter method invocations via factory().
 *
 * @package RawWire\Dashboard\Cores\ToolboxCore\DreamPilot
 * @since 1.0.25
 */

if (!defined('ABSPATH')) {
    exit;
}

class DreamPilot_Tool_Schema_Builder
{

    /**
     * Singleton instance
     * @var DreamPilot_Tool_Schema_Builder|null
     */
    private static $instance = null;

    /**
     * Cached tool definitions
     * @var array|null
     */
    private $tool_definitions = null;

    /**
     * Tool name → adapter mapping for execution routing
     * @var array
     */
    private $tool_map = [];

    /**
     * Get singleton instance
     *
     * @return DreamPilot_Tool_Schema_Builder
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
    private function __construct() {}

    /**
     * Build OpenAI-format tool definitions from the registry
     *
     * Each tool maps to an adapter method: {category}_{adapter}_{method}
     * Example: "generator_venice_ai_generate" → Venice adapter's generate()
     *
     * @param array $categories  Limit to specific categories (empty = all)
     * @return array  OpenAI-format tool definitions
     */
    public function build_tool_definitions($categories = [])
    {
        if ($this->tool_definitions !== null && empty($categories)) {
            return $this->tool_definitions;
        }

        $tools    = [];
        $this->tool_map = [];

        if (!class_exists('RawWire_Toolbox_Core')) {
            return $tools;
        }

        $registry = RawWire_Toolbox_Core::get_registry();
        if (empty($registry)) {
            return $tools;
        }

        foreach ($registry as $category_id => $category_data) {
            // Filter categories if specified
            if (!empty($categories) && !in_array($category_id, $categories, true)) {
                continue;
            }

            $adapters = $category_data['adapters'] ?? [];
            foreach ($adapters as $adapter_id => $adapter_def) {
                $adapter_tools = $this->build_adapter_tools($category_id, $adapter_id, $adapter_def);
                $tools = array_merge($tools, $adapter_tools);
            }
        }

        // Add DreamPilot built-in tools
        $builtin = $this->get_builtin_tools();
        $tools   = array_merge($tools, $builtin);

        // Allow extensions to add/modify tools
        $tools = apply_filters('dreampilot_tool_definitions', $tools);

        if (empty($categories)) {
            $this->tool_definitions = $tools;
        }

        return $tools;
    }

    /**
     * Build tool definitions for a specific adapter
     *
     * Maps interface methods to OpenAI function schemas based on category.
     *
     * @param string $category_id   Category key (scraper, generator, etc.)
     * @param string $adapter_id    Adapter key within category
     * @param array  $adapter_def   Adapter definition from registry
     * @return array  Tool definitions for this adapter
     */
    private function build_adapter_tools($category_id, $adapter_id, $adapter_def)
    {
        $tools = [];
        $label = $adapter_def['label'] ?? $adapter_id;
        $caps  = $adapter_def['capabilities'] ?? [];

        // Get category-specific method schemas
        $method_schemas = $this->get_category_methods($category_id, $caps);

        foreach ($method_schemas as $method => $schema) {
            $tool_name = "{$category_id}_{$adapter_id}_{$method}";

            // Store mapping for execution routing
            $this->tool_map[$tool_name] = [
                'category' => $category_id,
                'adapter'  => $adapter_id,
                'method'   => $method,
            ];

            $tools[] = [
                'type'     => 'function',
                'function' => [
                    'name'        => $tool_name,
                    'description' => sprintf('%s - %s (%s)', $label, $schema['description'], $adapter_def['tier'] ?? 'standard'),
                    'parameters'  => $schema['parameters'],
                ],
            ];
        }

        return $tools;
    }

    /**
     * Get method schemas for a category based on its interface
     *
     * @param string $category_id  Category key
     * @param array  $capabilities Adapter capabilities
     * @return array  Method name => schema mapping
     */
    private function get_category_methods($category_id, $capabilities = [])
    {
        switch ($category_id) {
            case 'scraper':
                return [
                    'scrape' => [
                        'description' => 'Scrape content from a URL',
                        'parameters'  => [
                            'type'       => 'object',
                            'properties' => [
                                'url' => [
                                    'type'        => 'string',
                                    'description' => 'The URL to scrape',
                                ],
                                'selectors' => [
                                    'type'        => 'array',
                                    'items'       => ['type' => 'string'],
                                    'description' => 'CSS selectors to extract specific elements',
                                ],
                            ],
                            'required' => ['url'],
                        ],
                    ],
                ];

            case 'generator':
                $methods = [
                    'generate' => [
                        'description' => 'Generate content from a prompt',
                        'parameters'  => [
                            'type'       => 'object',
                            'properties' => [
                                'prompt' => [
                                    'type'        => 'string',
                                    'description' => 'The generation prompt',
                                ],
                                'max_tokens' => [
                                    'type'        => 'integer',
                                    'description' => 'Maximum tokens in response',
                                ],
                            ],
                            'required' => ['prompt'],
                        ],
                    ],
                    'summarize' => [
                        'description' => 'Summarize content',
                        'parameters'  => [
                            'type'       => 'object',
                            'properties' => [
                                'content' => [
                                    'type'        => 'string',
                                    'description' => 'The content to summarize',
                                ],
                                'max_length' => [
                                    'type'        => 'integer',
                                    'description' => 'Target summary length in words',
                                ],
                            ],
                            'required' => ['content'],
                        ],
                    ],
                ];
                return $methods;

            case 'poster':
                return [
                    'publish' => [
                        'description' => 'Publish content to a platform',
                        'parameters'  => [
                            'type'       => 'object',
                            'properties' => [
                                'title' => [
                                    'type'        => 'string',
                                    'description' => 'Content title',
                                ],
                                'content' => [
                                    'type'        => 'string',
                                    'description' => 'Content body (HTML or plain text)',
                                ],
                                'status' => [
                                    'type'        => 'string',
                                    'enum'        => ['draft', 'publish', 'scheduled'],
                                    'description' => 'Publication status',
                                ],
                            ],
                            'required' => ['title', 'content'],
                        ],
                    ],
                    'schedule' => [
                        'description' => 'Schedule content for future publication',
                        'parameters'  => [
                            'type'       => 'object',
                            'properties' => [
                                'title' => [
                                    'type'        => 'string',
                                    'description' => 'Content title',
                                ],
                                'content' => [
                                    'type'        => 'string',
                                    'description' => 'Content body',
                                ],
                                'datetime' => [
                                    'type'        => 'string',
                                    'description' => 'ISO 8601 datetime for publication',
                                ],
                            ],
                            'required' => ['title', 'content', 'datetime'],
                        ],
                    ],
                ];

            case 'workflow':
                return [
                    'trigger' => [
                        'description' => 'Trigger a workflow execution',
                        'parameters'  => [
                            'type'       => 'object',
                            'properties' => [
                                'workflow_id' => [
                                    'type'        => 'string',
                                    'description' => 'Workflow identifier or webhook URL',
                                ],
                                'payload' => [
                                    'type'        => 'object',
                                    'description' => 'Data to pass to the workflow',
                                ],
                            ],
                            'required' => ['workflow_id'],
                        ],
                    ],
                ];

            default:
                return [];
        }
    }

    /**
     * Get DreamPilot built-in tools (self-troubleshooting, system info, etc.)
     *
     * @return array  Built-in tool definitions
     */
    private function get_builtin_tools()
    {
        $tools = [
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'dreampilot_get_system_status',
                    'description' => 'Get system status including active adapters, settings, and health checks',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'component' => [
                                'type'        => 'string',
                                'enum'        => ['all', 'providers', 'tools', 'instinct', 'settings'],
                                'description' => 'Which component to check',
                            ],
                        ],
                        'required' => [],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'dreampilot_search_knowledge',
                    'description' => 'Search the knowledge base and Instinct memory for relevant information',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'query' => [
                                'type'        => 'string',
                                'description' => 'Search query',
                            ],
                            'max_results' => [
                                'type'        => 'integer',
                                'description' => 'Maximum results to return',
                            ],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'dreampilot_save_memory',
                    'description' => 'Save important information to persistent memory for future reference',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'content' => [
                                'type'        => 'string',
                                'description' => 'Information to remember',
                            ],
                            'type' => [
                                'type'        => 'string',
                                'enum'        => ['fact', 'preference', 'task', 'context'],
                                'description' => 'Type of memory',
                            ],
                            'importance' => [
                                'type'        => 'integer',
                                'description' => 'Importance score 1-10',
                            ],
                        ],
                        'required' => ['content'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'dreampilot_list_capabilities',
                    'description' => 'List all available tools and capabilities the user can ask about',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'category' => [
                                'type'        => 'string',
                                'description' => 'Filter by category (scraper, generator, poster, workflow)',
                            ],
                        ],
                        'required' => [],
                    ],
                ],
            ],
        ];

        return apply_filters('dreampilot_builtin_tools', $tools);
    }

    /**
     * Execute a tool call by routing to the correct adapter/method
     *
     * This is the universal tool executor callback for chat_with_tools().
     *
     * @param string $tool_name  Tool name from the AI response
     * @param array  $args       Arguments from the AI response
     * @return string  JSON-encoded result for the AI to process
     */
    public function execute_tool($tool_name, $args)
    {
        // Handle DreamPilot built-in tools
        if (strpos($tool_name, 'dreampilot_') === 0) {
            return $this->execute_builtin_tool($tool_name, $args);
        }

        // Look up adapter mapping
        if (!isset($this->tool_map[$tool_name])) {
            return wp_json_encode([
                'error' => "Unknown tool: {$tool_name}",
            ]);
        }

        $mapping  = $this->tool_map[$tool_name];
        $category = $mapping['category'];
        $adapter  = $mapping['adapter'];
        $method   = $mapping['method'];

        // Instantiate adapter via factory
        if (!class_exists('RawWire_Toolbox_Core')) {
            return wp_json_encode([
                'error' => 'Toolbox Core not available',
            ]);
        }

        $instance = RawWire_Toolbox_Core::factory($category, $adapter);
        if (is_wp_error($instance)) {
            return wp_json_encode([
                'error' => $instance->get_error_message(),
            ]);
        }

        // Check method exists
        if (!method_exists($instance, $method)) {
            return wp_json_encode([
                'error' => "Method {$method} not found on adapter {$adapter}",
            ]);
        }

        try {
            // Call the adapter method with arguments
            $result = $this->invoke_adapter_method($instance, $method, $args);
            return wp_json_encode($result);
        } catch (Exception $e) {
            return wp_json_encode([
                'error' => 'Tool execution failed: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Invoke an adapter method with the correct argument mapping
     *
     * @param object $instance  Adapter instance
     * @param string $method    Method name
     * @param array  $args      Arguments from AI
     * @return array  Method result
     */
    private function invoke_adapter_method($instance, $method, $args)
    {
        switch ($method) {
            case 'scrape':
                $url       = $args['url'] ?? '';
                $selectors = $args['selectors'] ?? [];
                $result    = $instance->scrape($url, ['selectors' => $selectors]);
                break;

            case 'generate':
                $prompt  = $args['prompt'] ?? '';
                $options = array_diff_key($args, ['prompt' => 1]);
                $result  = $instance->generate($prompt, $options);
                break;

            case 'summarize':
                $content = $args['content'] ?? '';
                $options = array_diff_key($args, ['content' => 1]);
                $result  = $instance->summarize($content, $options);
                break;

            case 'publish':
                $content = [
                    'title'   => $args['title'] ?? '',
                    'content' => $args['content'] ?? '',
                    'status'  => $args['status'] ?? 'draft',
                ];
                $result = $instance->publish($content);
                break;

            case 'schedule':
                $content = [
                    'title'   => $args['title'] ?? '',
                    'content' => $args['content'] ?? '',
                ];
                $result = $instance->schedule($content, $args['datetime'] ?? '');
                break;

            case 'trigger':
                $payload = $args['payload'] ?? [];
                $result  = $instance->trigger($payload, $args);
                break;

            default:
                // Generic invocation for unknown methods
                $result = call_user_func([$instance, $method], $args);
                break;
        }

        // Ensure WP_Error is converted to array
        if (is_wp_error($result)) {
            return ['error' => $result->get_error_message()];
        }

        return $result;
    }

    /**
     * Execute a DreamPilot built-in tool
     *
     * @param string $tool_name  Built-in tool name
     * @param array  $args       Arguments
     * @return string  JSON-encoded result
     */
    private function execute_builtin_tool($tool_name, $args)
    {
        switch ($tool_name) {
            case 'dreampilot_get_system_status':
                return wp_json_encode($this->builtin_get_system_status($args));

            case 'dreampilot_search_knowledge':
                return wp_json_encode($this->builtin_search_knowledge($args));

            case 'dreampilot_save_memory':
                return wp_json_encode($this->builtin_save_memory($args));

            case 'dreampilot_list_capabilities':
                return wp_json_encode($this->builtin_list_capabilities($args));

            default:
                // Allow extensions to handle custom built-in tools
                $result = apply_filters('dreampilot_execute_builtin_tool', null, $tool_name, $args);
                if ($result !== null) {
                    return wp_json_encode($result);
                }
                return wp_json_encode(['error' => "Unknown built-in tool: {$tool_name}"]);
        }
    }

    /**
     * Built-in: Get system status
     */
    private function builtin_get_system_status($args)
    {
        $component = $args['component'] ?? 'all';
        $status    = [];

        if (in_array($component, ['all', 'providers'], true)) {
            $pm = DreamPilot_AI_Provider_Manager::get_instance();
            $status['providers'] = [
                'active'    => $pm->get_provider_info(),
                'available' => $pm->get_available_providers(),
            ];
        }

        if (in_array($component, ['all', 'tools'], true) && class_exists('RawWire_Toolbox_Core')) {
            $registry = RawWire_Toolbox_Core::get_registry();
            $status['tools'] = [];
            foreach ($registry as $cat_id => $cat_data) {
                $status['tools'][$cat_id] = [
                    'label'    => $cat_data['label'] ?? $cat_id,
                    'adapters' => count($cat_data['adapters'] ?? []),
                ];
            }
        }

        if (in_array($component, ['all', 'instinct'], true)) {
            $instinct_settings = get_option('rawwire_instinct_settings', []);
            $status['instinct'] = [
                'enabled' => !empty($instinct_settings['enabled']),
                'host'    => $instinct_settings['host'] ?? '127.0.0.1',
                'port'    => $instinct_settings['port'] ?? 8080,
            ];
        }

        return $status;
    }

    /**
     * Built-in: Search knowledge base
     */
    private function builtin_search_knowledge($args)
    {
        $query       = $args['query'] ?? '';
        $max_results = $args['max_results'] ?? 5;

        if (empty($query)) {
            return ['error' => 'Query is required'];
        }

        // Try Instinct context adapter
        if (class_exists('RawWire_Adapter_Context_Instinct')) {
            try {
                $settings = get_option('rawwire_instinct_settings', []);
                $adapter  = new RawWire_Adapter_Context_Instinct([
                    'host' => $settings['host'] ?? '127.0.0.1',
                    'port' => $settings['port'] ?? 8080,
                ]);

                if ($adapter->is_available()) {
                    $result = $adapter->query($query, [
                        'max_results'       => $max_results,
                        'include_mandatory' => false,
                    ]);
                    return $result;
                }
            } catch (Exception $e) {
                // Fall through to AI memory
            }
        }

        // Fallback to AI Memory
        if (class_exists('RawWire_AI_Memory')) {
            $memory   = RawWire_AI_Memory::get_instance();
            $memories = $memory->search($query, $max_results);
            return ['results' => $memories, 'source' => 'ai_memory'];
        }

        return ['results' => [], 'message' => 'No knowledge sources available'];
    }

    /**
     * Built-in: Save to memory
     */
    private function builtin_save_memory($args)
    {
        $content    = $args['content'] ?? '';
        $type       = $args['type'] ?? 'context';
        $importance = intval($args['importance'] ?? 5);

        if (empty($content)) {
            return ['error' => 'Content is required'];
        }

        if (class_exists('RawWire_AI_Memory')) {
            $memory = RawWire_AI_Memory::get_instance();
            $id     = $memory->save($content, $type, $importance);
            return ['success' => true, 'memory_id' => $id];
        }

        return ['error' => 'AI Memory not available'];
    }

    /**
     * Built-in: List capabilities
     */
    private function builtin_list_capabilities($args)
    {
        $category = $args['category'] ?? '';
        $result   = [];

        if (!class_exists('RawWire_Toolbox_Core')) {
            return ['error' => 'Toolbox Core not available'];
        }

        $registry = RawWire_Toolbox_Core::get_registry();

        foreach ($registry as $cat_id => $cat_data) {
            if ($category && $cat_id !== $category) {
                continue;
            }

            $cat_info = [
                'label'    => $cat_data['label'] ?? $cat_id,
                'adapters' => [],
            ];

            foreach (($cat_data['adapters'] ?? []) as $adapter_id => $adapter_def) {
                $cat_info['adapters'][] = [
                    'id'           => $adapter_id,
                    'label'        => $adapter_def['label'] ?? $adapter_id,
                    'tier'         => $adapter_def['tier'] ?? 'standard',
                    'capabilities' => $adapter_def['capabilities'] ?? [],
                ];
            }

            $result[$cat_id] = $cat_info;
        }

        return $result;
    }

    /**
     * Get the tool map (for debugging/testing)
     *
     * @return array  Tool name => adapter mapping
     */
    public function get_tool_map()
    {
        return $this->tool_map;
    }

    /**
     * Reset cached definitions (useful after registry changes)
     */
    public function reset()
    {
        $this->tool_definitions = null;
        $this->tool_map         = [];
    }
}
