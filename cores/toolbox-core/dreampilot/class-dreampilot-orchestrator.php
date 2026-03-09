<?php

/**
 * DreamPilot Orchestrator - The resident AI agent
 *
 * Central agent that receives user messages, selects the appropriate AI provider,
 * builds context-rich system prompts, discovers available tools from the registry,
 * and executes tool-use loops to accomplish tasks on behalf of the user.
 *
 * Architecture:
 *   User → Orchestrator → Provider Manager → AI API
 *                       → Tool Schema Builder → Toolbox Core → Adapters
 *                       → Conversation Manager → History + Memory
 *                       → Instinct → Context Engine
 *
 * @package RawWire\Dashboard\Cores\ToolboxCore\DreamPilot
 * @since 1.0.25
 */

if (!defined('ABSPATH')) {
    exit;
}

class DreamPilot_Orchestrator
{

    /**
     * DreamPilot version
     */
    const VERSION = '1.0.0';

    /**
     * Singleton instance
     * @var DreamPilot_Orchestrator|null
     */
    private static $instance = null;

    /**
     * Sub-component instances
     */
    private $provider_manager;
    private $tool_builder;
    private $conversation;

    /**
     * Get singleton instance
     *
     * @return DreamPilot_Orchestrator
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor - wire up sub-components and hooks
     */
    private function __construct()
    {
        // Load sub-components
        $this->load_components();

        // Initialize instances
        $this->provider_manager = DreamPilot_AI_Provider_Manager::get_instance();
        $this->tool_builder     = DreamPilot_Tool_Schema_Builder::get_instance();
        $this->conversation     = DreamPilot_Conversation_Manager::get_instance();

        // Register AJAX endpoints
        add_action('wp_ajax_dreampilot_chat', [$this, 'ajax_handle_message']);
        add_action('wp_ajax_dreampilot_clear_history', [$this, 'ajax_clear_history']);
        add_action('wp_ajax_dreampilot_get_status', [$this, 'ajax_get_status']);
    }

    /**
     * Load DreamPilot component files
     */
    private function load_components()
    {
        $dir = dirname(__FILE__) . '/';

        $files = [
            'class-ai-provider-manager.php',
            'class-tool-schema-builder.php',
            'class-dreampilot-conversation.php',
        ];

        foreach ($files as $file) {
            $path = $dir . $file;
            if (file_exists($path)) {
                require_once $path;
            }
        }
    }

    /**
     * Process a user message - the core agent loop
     *
     * Flow:
     * 1. Build system prompt (Instinct + AI Memory + base instructions + page context)
     * 2. Build tool definitions from registry
     * 3. Assemble conversation messages (system + history + current)
     * 4. Call AI provider with tools if supported, else plain chat
     * 5. Save history and extract memories
     * 6. Return response
     *
     * @param string $message     User's message
     * @param array  $options     Options: session_id, temperature, max_tokens, categories
     * @return array{success: bool, reply: string, model: string, provider: string, tool_calls: array, usage: array}
     */
    public function process_message($message, $options = [])
    {
        if (empty(trim($message))) {
            return [
                'success' => false,
                'error'   => 'Message cannot be empty.',
            ];
        }

        $session_id = $options['session_id'] ?? '';

        // 1. Build system prompt
        $system_prompt = $this->build_system_prompt($message);
        $system_prompt = apply_filters('dreampilot_system_prompt', $system_prompt, $message, $options);

        // 2. Build tool definitions
        $categories = $options['categories'] ?? [];
        $tools      = $this->tool_builder->build_tool_definitions($categories);

        // 3. Build messages array
        $messages = $this->conversation->build_messages($system_prompt, $message, $session_id);

        // 4. Send to AI provider
        $tool_executor = [$this->tool_builder, 'execute_tool'];

        if ($this->provider_manager->supports_tools() && !empty($tools)) {
            $result = $this->provider_manager->chat_with_tools(
                $messages,
                $tools,
                $tool_executor,
                [
                    'temperature' => $options['temperature'] ?? 0.7,
                    'max_tokens'  => $options['max_tokens'] ?? 2048,
                ]
            );
        } else {
            $result = $this->provider_manager->chat($system_prompt, $message, [
                'history'     => $this->conversation->get_history($session_id),
                'temperature' => $options['temperature'] ?? 0.7,
                'max_tokens'  => $options['max_tokens'] ?? 2048,
            ]);
        }

        // 5. Handle result
        if (empty($result['success'])) {
            return [
                'success' => false,
                'error'   => $result['error'] ?? 'AI provider returned an error.',
            ];
        }

        $reply = $result['content'] ?? '';

        // 6. Save conversation history
        $this->conversation->append_message('user', $message, $session_id);
        $this->conversation->append_message('assistant', $reply, $session_id);

        // 7. Extract memories from the exchange
        $this->conversation->extract_memories($message, $reply);

        // 8. Fire action for extensions
        do_action('dreampilot_message_processed', $message, $reply, $result, $options);

        return [
            'success'    => true,
            'reply'      => $reply,
            'model'      => $result['model'] ?? '',
            'provider'   => $result['provider'] ?? '',
            'tool_calls' => $result['tool_calls'] ?? [],
            'usage'      => $result['usage'] ?? [],
        ];
    }

    /**
     * Build the system prompt with all context layers
     *
     * Layers (in order):
     * 1. Instinct context (mandatory + relevant segments from Context Engine)
     * 2. AI Memory (persistent user facts, preferences, context)
     * 3. Base instructions (DreamPilot persona + capabilities)
     * 4. Page context (current admin screen info)
     *
     * @param string $user_message  User's message (for context-aware retrieval)
     * @return string  Complete system prompt
     */
    public function build_system_prompt($user_message = '')
    {
        $parts = [];

        // Layer 1: Instinct context
        $instinct_context = $this->get_instinct_context($user_message);
        if ($instinct_context) {
            $parts[] = $instinct_context;
        }

        // Layer 2: AI Memory
        $memory_context = $this->get_memory_context();
        if ($memory_context) {
            $parts[] = $memory_context;
        }

        // Layer 3: Base instructions
        $parts[] = $this->get_base_instructions();

        // Layer 4: Page context
        $page_context = $this->get_page_context();
        if ($page_context) {
            $parts[] = $page_context;
        }

        return implode("\n\n---\n\n", $parts);
    }

    /**
     * Get Instinct context for the current query
     *
     * @param string $user_message  Message to use for context retrieval
     * @return string  Formatted Instinct context or empty string
     */
    private function get_instinct_context($user_message)
    {
        $settings = get_option('rawwire_instinct_settings', []);

        if (empty($settings['enabled'])) {
            return '';
        }

        if (!class_exists('RawWire_Adapter_Context_Instinct')) {
            $adapter_file = dirname(__FILE__) . '/../adapters/context/class-context-instinct.php';
            if (file_exists($adapter_file)) {
                require_once $adapter_file;
            } else {
                return '';
            }
        }

        try {
            $adapter = new RawWire_Adapter_Context_Instinct([
                'host' => $settings['host'] ?? '127.0.0.1',
                'port' => $settings['port'] ?? 8080,
            ]);

            if (!$adapter->is_available()) {
                return '';
            }

            $result = $adapter->query($user_message, [
                'max_tokens'        => $settings['max_tokens'] ?? 2000,
                'min_importance'    => $settings['min_importance'] ?? 40,
                'include_mandatory' => true,
            ]);

            if (empty($result['segments'])) {
                return '';
            }

            $mandatory_count = 0;
            $relevant_count  = 0;
            $context_text    = '';

            foreach ($result['segments'] as $segment) {
                $score = $segment['importance_score'] ?? 0;
                if ($score >= 100) {
                    $mandatory_count++;
                } else {
                    $relevant_count++;
                }
                $context_text .= "- [{$score}] " . ($segment['content'] ?? '') . "\n";
            }

            $header = "[INSTINCT CONTEXT: {$mandatory_count} mandatory, {$relevant_count} relevant";
            if (!empty($result['total_tokens'])) {
                $header .= ", ~{$result['total_tokens']} tokens";
            }
            $header .= "]";

            return "{$header}\n{$context_text}";
        } catch (Exception $e) {
            return '';
        }
    }

    /**
     * Get AI memory context
     *
     * @return string  Formatted memory context or empty string
     */
    private function get_memory_context()
    {
        if (!class_exists('RawWire_AI_Memory')) {
            return '';
        }

        try {
            $memory   = RawWire_AI_Memory::get_instance();
            $memories = $memory->get_memories(10);

            if (empty($memories)) {
                return '';
            }

            $grouped = [];
            foreach ($memories as $mem) {
                $type = $mem['type'] ?? 'context';
                $grouped[$type][] = $mem['content'] ?? '';
            }

            $text = "[USER MEMORY]\n";
            foreach ($grouped as $type => $items) {
                $text .= strtoupper($type) . ":\n";
                foreach ($items as $item) {
                    $text .= "- {$item}\n";
                }
            }

            return $text;
        } catch (Exception $e) {
            return '';
        }
    }

    /**
     * Get DreamPilot base instructions
     *
     * Defines the agent persona, capabilities, and behavioral rules.
     *
     * @return string  Base instruction block
     */
    private function get_base_instructions()
    {
        $site_name = get_bloginfo('name');

        $instructions = <<<INSTRUCT
[DREAMPILOT AGENT INSTRUCTIONS]

You are DreamPilot, an AI assistant built into the Raw-Wire Dashboard for "{$site_name}".
Your role is to help business owners accomplish their goals using natural language.

CORE CAPABILITIES:
- Content Generation: Create blog posts, social media content, email campaigns, and marketing copy
- Data Collection: Scrape websites, gather leads, research competitors
- Publishing: Post content to WordPress, social media platforms, and other channels
- Workflow Management: Trigger and monitor automated workflows
- Knowledge Search: Query the Instinct memory system for stored context and past decisions
- Self-Troubleshooting: Diagnose issues with the dashboard, settings, and integrations

BEHAVIORAL RULES:
1. Be concise and action-oriented. Business owners value their time.
2. When multiple tools could accomplish a task, explain your approach before executing.
3. Always confirm before publishing content or sending communications externally.
4. If a tool fails, explain what went wrong and suggest alternatives.
5. Remember user preferences and business context across conversations.
6. Proactively suggest automation opportunities when you notice repetitive tasks.
7. For operations that cost money (API calls, ads), state the estimated cost first.
8. Keep technical jargon to a minimum. Explain in business terms.

TOOL USAGE:
- You have access to tools listed in this conversation. Use them to take real actions.
- Always use the most cost-effective tool tier unless the user requests otherwise.
- When scraping data, respect rate limits and robots.txt.
- When posting content, default to 'draft' status unless the user explicitly says to publish.

SAFETY:
- Never share API keys, passwords, or credentials in conversation.
- Always sanitize user-provided URLs and data before passing to tools.
- Confirm destructive actions (delete, overwrite) before executing.
INSTRUCT;

        return apply_filters('dreampilot_base_instructions', $instructions);
    }

    /**
     * Get current page context
     *
     * @return string  Page context or empty string
     */
    private function get_page_context()
    {
        if (!function_exists('get_current_screen')) {
            return '';
        }

        $screen = get_current_screen();
        if (!$screen) {
            return '';
        }

        $context = "[CURRENT PAGE]\n";
        $context .= "Screen: {$screen->id}\n";

        if ($screen->post_type) {
            $context .= "Post Type: {$screen->post_type}\n";
        }
        if ($screen->taxonomy) {
            $context .= "Taxonomy: {$screen->taxonomy}\n";
        }

        return $context;
    }

    /**
     * Check if DreamPilot is available and configured
     *
     * @return bool
     */
    public function is_available()
    {
        $provider = $this->provider_manager->get_provider();
        return !is_wp_error($provider);
    }

    /**
     * Get agent status information
     *
     * @return array  Status details
     */
    public function get_status()
    {
        return [
            'version'   => self::VERSION,
            'available' => $this->is_available(),
            'provider'  => $this->provider_manager->get_provider_info(),
            'tools'     => count($this->tool_builder->build_tool_definitions()),
            'sessions'  => $this->conversation->get_active_sessions(),
        ];
    }

    // ────────────────────────────────────────────────────────────
    // AJAX Handlers
    // ────────────────────────────────────────────────────────────

    /**
     * AJAX: Handle incoming chat message
     */
    public function ajax_handle_message()
    {
        check_ajax_referer('dreampilot_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $message    = sanitize_textarea_field($_POST['message'] ?? '');
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');

        if (empty($message)) {
            wp_send_json_error(['message' => 'Message is required']);
        }

        $result = $this->process_message($message, [
            'session_id' => $session_id,
        ]);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX: Clear conversation history
     */
    public function ajax_clear_history()
    {
        check_ajax_referer('dreampilot_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        $this->conversation->clear_history($session_id);

        wp_send_json_success(['message' => 'Conversation cleared']);
    }

    /**
     * AJAX: Get DreamPilot status
     */
    public function ajax_get_status()
    {
        check_ajax_referer('dreampilot_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        wp_send_json_success($this->get_status());
    }

    // ────────────────────────────────────────────────────────────
    // Sub-component accessors
    // ────────────────────────────────────────────────────────────

    /**
     * Get the provider manager
     *
     * @return DreamPilot_AI_Provider_Manager
     */
    public function get_provider_manager()
    {
        return $this->provider_manager;
    }

    /**
     * Get the tool schema builder
     *
     * @return DreamPilot_Tool_Schema_Builder
     */
    public function get_tool_builder()
    {
        return $this->tool_builder;
    }

    /**
     * Get the conversation manager
     *
     * @return DreamPilot_Conversation_Manager
     */
    public function get_conversation_manager()
    {
        return $this->conversation;
    }
}

/**
 * Global accessor for DreamPilot Orchestrator
 *
 * Usage: dreampilot()->process_message('Hello');
 *
 * @return DreamPilot_Orchestrator
 */
function dreampilot()
{
    return DreamPilot_Orchestrator::get_instance();
}
