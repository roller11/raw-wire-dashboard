<?php

/**
 * Venice Chat Handler
 *
 * Manages chat conversations powered by Venice.ai without requiring AI Engine.
 * Handles: message history, memory injection, tool execution, and response
 * parsing. This class is the backend logic; the Dashboard Chat Panel is the UI.
 *
 * @package    RawWire\Dashboard\Cores\ToolboxCore\Features
 * @since      1.0.24
 */

if (!defined('ABSPATH')) {
    exit;
}

class RawWire_Venice_Chat_Handler
{

    /**
     * Singleton instance
     * @var RawWire_Venice_Chat_Handler|null
     */
    private static $instance = null;

    /**
     * Venice adapter instance (lazy-loaded)
     * @var RawWire_Adapter_Generator_Venice|null
     */
    private $adapter = null;

    /**
     * Maximum conversation messages to keep in history
     * @var int
     */
    const MAX_HISTORY = 40;

    /**
     * Transient TTL for conversation history (8 hours)
     * @var int
     */
    const HISTORY_TTL = 28800;

    /**
     * Get singleton instance
     *
     * @return RawWire_Venice_Chat_Handler
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
        add_action('wp_ajax_rawwire_venice_chat', array($this, 'ajax_handle_message'));
        add_action('wp_ajax_rawwire_venice_clear', array($this, 'ajax_clear_history'));
    }

    // -------------------------------------------------------------------------
    // Adapter Access
    // -------------------------------------------------------------------------

    /**
     * Get or create the Venice adapter instance
     *
     * Loads API key from the centralized Key Manager, falls back to
     * wp_options rawwire_venice_settings.
     *
     * @return RawWire_Adapter_Generator_Venice|WP_Error
     */
    public function get_adapter()
    {
        if ($this->adapter !== null) {
            return $this->adapter;
        }

        // Try Key Manager first (encrypted storage)
        $api_key = '';
        if (class_exists('RawWire_Key_Manager')) {
            $km = RawWire_Key_Manager::get_instance();
            $api_key = $km->get_key('venice_ai_api_key');
        }

        // Fall back to settings option
        if (empty($api_key)) {
            $settings = get_option('rawwire_venice_settings', array());
            $api_key  = $settings['api_key'] ?? '';
        }

        if (empty($api_key)) {
            return new WP_Error(
                'no_api_key',
                'Venice.ai API key not configured. Go to Raw Wire > Tools > AI Settings to add your key.'
            );
        }

        // Load saved model/settings preferences
        $settings = get_option('rawwire_venice_settings', array());

        $config = array(
            'api_key'                    => $api_key,
            'model'                      => $settings['model'] ?? 'zai-org-glm-4.7',
            'max_tokens'                 => intval($settings['max_tokens'] ?? 4096),
            'temperature'                => floatval($settings['temperature'] ?? 0.7),
            'enable_web_search'          => 'off',   // NEVER use Venice web search
            'enable_web_scraping'        => false,    // NEVER use Venice web scraping
            'enable_web_citations'       => false,    // NEVER use Venice web citations
            'strip_thinking_response'    => $settings['strip_thinking_response'] ?? true,
            'include_venice_system_prompt' => false, // We provide our own
        );

        // Use Toolbox Core factory if available, otherwise instantiate directly
        if (class_exists('RawWire_Toolbox_Core')) {
            $adapter = RawWire_Toolbox_Core::factory('generator', 'venice_ai', $config);
        } else {
            // Direct instantiation fallback
            $class_file = dirname(__DIR__) . '/adapters/generators/class-generator-venice.php';
            if (!class_exists('RawWire_Adapter_Generator_Venice') && file_exists($class_file)) {
                require_once dirname(__DIR__) . '/adapters/class-adapter-base.php';
                require_once dirname(__DIR__) . '/interfaces/interface-adapter.php';
                require_once dirname(__DIR__) . '/interfaces/interface-generator.php';
                require_once $class_file;
            }
            $adapter = new RawWire_Adapter_Generator_Venice($config);
        }

        if (is_wp_error($adapter)) {
            return $adapter;
        }

        $this->adapter = $adapter;
        return $this->adapter;
    }

    /**
     * Check if Venice.ai is properly configured and available
     *
     * @return bool
     */
    public function is_available()
    {
        $adapter = $this->get_adapter();
        return !is_wp_error($adapter);
    }

    /**
     * Get the currently configured model label for display
     *
     * @return string
     */
    public function get_model_label()
    {
        $settings = get_option('rawwire_venice_settings', array());
        $model = $settings['model'] ?? 'zai-org-glm-4.7';

        // Ensure Venice adapter class is loaded
        if (!class_exists('RawWire_Adapter_Generator_Venice')) {
            $class_file = dirname(__DIR__) . '/adapters/generators/class-generator-venice.php';
            if (file_exists($class_file)) {
                require_once dirname(__DIR__) . '/adapters/class-adapter-base.php';
                require_once dirname(__DIR__) . '/interfaces/interface-adapter.php';
                require_once dirname(__DIR__) . '/interfaces/interface-generator.php';
                require_once $class_file;
            }
        }

        if (class_exists('RawWire_Adapter_Generator_Venice')) {
            $models = RawWire_Adapter_Generator_Venice::get_available_models();
            if (isset($models[$model])) {
                return $models[$model]['label'];
            }
        }

        return $model;
    }

    // -------------------------------------------------------------------------
    // Conversation History (per-user transients)
    // -------------------------------------------------------------------------

    /**
     * Get the transient key for a user's chat history
     *
     * @param int $user_id
     * @return string
     */
    private function history_key(int $user_id)
    {
        return 'rawwire_venice_chat_' . $user_id;
    }

    /**
     * Load conversation history for the current user
     *
     * @return array Messages array (role/content pairs)
     */
    public function get_history()
    {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return array();
        }

        $history = get_transient($this->history_key($user_id));
        return is_array($history) ? $history : array();
    }

    /**
     * Save conversation history
     *
     * @param array $messages
     */
    public function save_history(array $messages)
    {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return;
        }

        // Trim to max length, keeping the system message
        if (count($messages) > self::MAX_HISTORY) {
            $messages = array_slice($messages, -self::MAX_HISTORY);
        }

        set_transient($this->history_key($user_id), $messages, self::HISTORY_TTL);
    }

    /**
     * Clear conversation history for the current user
     */
    public function clear_history()
    {
        $user_id = get_current_user_id();
        if ($user_id) {
            delete_transient($this->history_key($user_id));
        }
    }

    // -------------------------------------------------------------------------
    // System Prompt
    // -------------------------------------------------------------------------

    /**
     * Build the system prompt with memory injection
     *
     * @param string $user_message Optional user message for context-aware retrieval
     * @return string
     */
    public function build_system_prompt(string $user_message = '')
    {
        $prompt = $this->get_base_instructions();

        // Inject Instinct context (priority-based memory)
        $instinct_context = $this->get_instinct_context($user_message);
        if (!empty($instinct_context)) {
            $prompt = $instinct_context . "\n\n" . $prompt;
        }

        // Inject AI memories if available
        $memory_block = $this->get_memory_context();
        if (!empty($memory_block)) {
            $prompt = $memory_block . "\n\n" . $prompt;
        }

        // Inject current page context
        $page_context = $this->get_page_context();
        if (!empty($page_context)) {
            $prompt .= "\n\n## Current Page Context:\n" . $page_context;
        }

        return $prompt;
    }

    /**
     * Get Instinct context for injection
     *
     * Retrieves priority-based context from the Instinct context engine.
     * Includes mandatory items (score >= 95) and relevant items.
     *
     * @param string $user_message Optional user message for context-aware retrieval
     * @return string
     */
    private function get_instinct_context(string $user_message = '')
    {
        $settings = get_option('rawwire_instinct_settings', array());

        // Check if Instinct is enabled
        if (empty($settings['enabled'])) {
            return '';
        }

        // Load Instinct adapter
        if (!class_exists('RawWire_Adapter_Context_Instinct')) {
            $adapter_path = dirname(__FILE__) . '/../adapters/context/class-context-instinct.php';
            if (file_exists($adapter_path)) {
                require_once $adapter_path;
            } else {
                return '';
            }
        }

        try {
            $adapter = new RawWire_Adapter_Context_Instinct(array(
                'host' => $settings['host'] ?? '127.0.0.1',
                'port' => $settings['port'] ?? 8080,
            ));

            // Check if service is available
            if (!$adapter->is_available()) {
                return '';
            }

            // Get context based on user message (or fallback to generic query)
            $query = !empty($user_message)
                ? $user_message
                : 'wordpress dashboard conversation context';
            $options = array(
                'max_tokens' => $settings['max_tokens'] ?? 8000,
                'min_importance' => $settings['min_importance'] ?? 30,
                'include_mandatory' => $settings['include_mandatory'] ?? true,
            );

            $result = $adapter->get_context($query, $options);

            if (!$result['success'] || empty($result['context'])) {
                return '';
            }

            // Format context for system prompt
            $context_header = "## Instinct Context (Priority Memory System)\n";
            $context_header .= "The following context has been retrieved from your priority memory store.\n";
            $context_header .= sprintf(
                "Mandatory items: %d | Relevant items: %d | Tokens: ~%d\n\n",
                $result['mandatory_count'] ?? 0,
                $result['relevant_count'] ?? 0,
                $result['total_tokens'] ?? 0
            );

            return $context_header . $result['context'];
        } catch (Exception $e) {
            error_log('Instinct context error: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Get base system instructions
     *
     * @return string
     */
    private function get_base_instructions()
    {
        return <<<'INSTRUCTIONS'
You are the Raw Wire Dashboard AI Assistant, powered by Venice.ai privacy-first models.
Zero data retention - conversations are never stored on external servers.

## ABOUT RAW WIRE:

Raw Wire is a WordPress-based business automation and content aggregation platform.

### What Raw Wire Does:
- **News Aggregation**: Scrapes and curates content from RSS feeds and web sources
- **AI-Powered Content**: Summarizes, rewrites, and generates content
- **Multi-Channel Publishing**: Posts to WordPress, Discord, social media via adapters
- **Automation Workflows**: Schedules and automates content pipelines
- **Dashboard**: Central control panel for all operations

### Components:
- **Raw Wire Dashboard** - Main WordPress plugin (admin interface)
- **Toolbox Core** - Adapters for scrapers, posters, AI, and integrations
- **Template Engine** - Enables different operational modes
- **Venice.ai** - Privacy-first AI backend (this conversation)

### The Developer:
Raw Wire is developed by a solo developer who goes by "boss" or "roller".
You are their AI assistant helping build and manage this platform.

## Your Memory System:

You have PERSISTENT MEMORY across conversations. Information about the user is
automatically saved and retrieved. If you see "## User Memory" above, those are
things you remember.

### When to Store Memories:
- User shares personal/professional information
- User expresses a preference or workflow
- User mentions an ongoing project or task
- User corrects you about something

## Your Personality:
- Professional but friendly
- Proactive - suggest actions and improvements
- Concise but thorough when explaining
- Always confirm before destructive actions
- Reference things you remember about the user naturally

## Important Guidelines:
1. If asked about your AI model, say you are powered by Venice.ai privacy models
2. Provide clear feedback on actions taken
3. Suggest next steps after completing tasks
4. Ask clarifying questions when requests are ambiguous
INSTRUCTIONS;
    }

    /**
     * Get formatted memory context for injection
     *
     * @return string
     */
    private function get_memory_context()
    {
        if (!class_exists('RawWire_AI_Memory')) {
            return '';
        }

        $memory = RawWire_AI_Memory::get_instance();
        $user_id = get_current_user_id();

        if (!$user_id) {
            return '';
        }

        // Get high-importance memories
        $important = $memory->get_memories($user_id, array(
            'limit'   => 10,
            'orderby' => 'importance',
            'order'   => 'DESC',
        ));

        if (empty($important)) {
            return '';
        }

        $lines = array("## User Memory (Things you remember about this user):\n");

        $by_type = array();
        foreach ($important as $mem) {
            $type = $mem->memory_type;
            if (!isset($by_type[$type])) {
                $by_type[$type] = array();
            }
            $by_type[$type][] = $mem->content;
        }

        $labels = array(
            'fact'       => 'Known Facts',
            'preference' => 'User Preferences',
            'context'    => 'Important Context',
            'task'       => 'Ongoing Tasks',
            'entity'     => 'Known Entities',
        );

        foreach ($by_type as $type => $contents) {
            $label = $labels[$type] ?? ucfirst($type);
            $lines[] = "### {$label}:";
            foreach ($contents as $content) {
                $lines[] = "- {$content}";
            }
            $lines[] = "";
        }

        return implode("\n", $lines);
    }

    /**
     * Get current admin page context
     *
     * @return string
     */
    private function get_page_context()
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) {
            return '';
        }

        $parts = array();
        $parts[] = "Page: {$screen->id}";

        if (!empty($screen->post_type)) {
            $parts[] = "Post Type: {$screen->post_type}";
        }

        return implode("\n", $parts);
    }

    // -------------------------------------------------------------------------
    // Chat Execution
    // -------------------------------------------------------------------------

    /**
     * Send a message and get a response
     *
     * Handles full conversation flow: loads history, injects system prompt,
     * sends to Venice, processes tool calls, stores response in history,
     * and extracts memories.
     *
     * @param string $message User message
     * @param array  $options {web_search, tools, ...}
     * @return array{success: bool, reply?: string, error?: string, model?: string}
     */
    public function send_message(string $message, array $options = array())
    {
        $adapter = $this->get_adapter();
        if (is_wp_error($adapter)) {
            error_log('Venice Chat: Adapter error - ' . $adapter->get_error_message());
            return array(
                'success' => false,
                'error'   => $adapter->get_error_message(),
            );
        }

        // Build conversation
        $history = $this->get_history();
        $system_prompt = $this->build_system_prompt($message);

        // Add current user message to history
        $history[] = array('role' => 'user', 'content' => $message);

        // Prepare options
        $chat_options = array(
            'history' => $history,
        );

        // Venice web search is permanently disabled — do not forward web_search options

        // Send to Venice
        error_log('Venice Chat: Sending message to Venice API');
        $result = $adapter->chat($system_prompt, $message, $chat_options);

        if (!$result['success']) {
            error_log('Venice Chat: API error - ' . ($result['error'] ?? 'Unknown error'));
            return array(
                'success' => false,
                'error'   => $result['error'] ?? 'Venice.ai request failed',
            );
        }

        $reply = $result['content'] ?? '';

        // Save assistant reply to history
        $history[] = array('role' => 'assistant', 'content' => $reply);
        $this->save_history($history);

        // Extract memories from the conversation asynchronously
        $this->extract_memories_from_message($message);

        $response = array(
            'success' => true,
            'reply'   => $reply,
            'model'   => $result['model'] ?? '',
            'usage'   => $result['usage'] ?? array(),
        );

        // Include citations if web search was used
        if (!empty($result['citations'])) {
            $response['citations'] = $result['citations'];
        }

        return $response;
    }

    // -------------------------------------------------------------------------
    // Memory Extraction
    // -------------------------------------------------------------------------

    /**
     * Extract and store memories from user message
     *
     * @param string $message
     */
    private function extract_memories_from_message(string $message)
    {
        if (!class_exists('RawWire_AI_Memory')) {
            return;
        }

        $memory  = RawWire_AI_Memory::get_instance();
        $user_id = get_current_user_id();
        if (!$user_id) {
            return;
        }

        // Pattern-based extraction (same patterns as AI Memory class)
        $patterns = array(
            '/(?:my name is|i\'?m called|call me)\s+([a-z]+)/i'
            => array('fact', 'User\'s name is: %s', 9),
            '/i (?:prefer|like|want|need)\s+(.+?)(?:\.|$)/i'
            => array('preference', 'User prefers: %s', 6),
            '/(?:remember that|don\'?t forget|keep in mind)\s+(.+?)(?:\.|$)/i'
            => array('fact', '%s', 8),
            '/i (?:work at|work for|am employed by)\s+(.+?)(?:\.|$)/i'
            => array('fact', 'User works at: %s', 7),
            '/i\'?m a(?:n)?\s+(developer|designer|manager|admin|owner|ceo|cto|engineer|marketer|writer|analyst)/i'
            => array('fact', 'User is a: %s', 7),
        );

        foreach ($patterns as $pattern => $config) {
            if (preg_match($pattern, $message, $matches)) {
                list($type, $template, $importance) = $config;
                $content = sprintf($template, trim($matches[1]));
                $memory->store($content, $type, $importance, array('source' => 'venice_chat'), $user_id);
            }
        }
    }

    // -------------------------------------------------------------------------
    // AJAX Handlers
    // -------------------------------------------------------------------------

    /**
     * AJAX: Handle incoming chat message
     */
    public function ajax_handle_message()
    {
        error_log('Venice Chat: ajax_handle_message called');

        check_ajax_referer('rawwire_venice_chat_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            error_log('Venice Chat: User unauthorized');
            wp_send_json_error(array('message' => 'Unauthorized'), 403);
        }

        $message = sanitize_textarea_field(wp_unslash($_POST['message'] ?? ''));
        if (empty($message)) {
            error_log('Venice Chat: Empty message');
            wp_send_json_error(array('message' => 'Message is required'));
        }

        error_log('Venice Chat: Processing message: ' . substr($message, 0, 50));

        // Venice web search is permanently disabled
        $options = array();

        $result = $this->send_message($message, $options);

        if ($result['success']) {
            error_log('Venice Chat: Success');
            wp_send_json_success(array(
                'reply'     => $result['reply'],
                'model'     => $result['model'] ?? '',
                'citations' => $result['citations'] ?? array(),
            ));
        } else {
            error_log('Venice Chat: Error - ' . ($result['error'] ?? 'unknown'));
            wp_send_json_error(array('message' => $result['error']));
        }
    }

    /**
     * AJAX: Clear conversation history
     */
    public function ajax_clear_history()
    {
        check_ajax_referer('rawwire_venice_chat_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => 'Unauthorized'), 403);
        }

        $this->clear_history();
        wp_send_json_success(array('message' => 'Conversation cleared'));
    }

    /**
     * Test Venice.ai connection
     *
     * @return array{success: bool, message: string, details?: array}
     */
    public function test_connection()
    {
        $adapter = $this->get_adapter();
        if (is_wp_error($adapter)) {
            return array(
                'success' => false,
                'message' => $adapter->get_error_message(),
            );
        }

        return $adapter->test_connection();
    }
}

// Initialize on plugins_loaded to ensure dependencies are available
add_action('plugins_loaded', function () {
    RawWire_Venice_Chat_Handler::get_instance();
}, 15);
