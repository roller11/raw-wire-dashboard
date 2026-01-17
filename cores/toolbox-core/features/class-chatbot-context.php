<?php
/**
 * Admin Chatbot Context Provider
 * 
 * Integrates Raw Wire Dashboard context with AI Engine chatbots.
 * Provides dynamic context about current admin page, data, and capabilities.
 *
 * @package RawWire\Dashboard\Cores\ToolboxCore\Features
 * @since 1.0.21
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class RawWire_Chatbot_Context
 * 
 * Injects Raw Wire context into AI Engine chatbot conversations
 * for admin pages, making the chatbot aware of current state.
 */
class RawWire_Chatbot_Context {

    /**
     * Singleton instance
     * @var RawWire_Chatbot_Context|null
     */
    private static $instance = null;

    /**
     * Context cache
     * @var array
     */
    private $context_cache = [];

    /**
     * Get singleton instance
     * 
     * @return RawWire_Chatbot_Context
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
        // Hook into AI Engine query modification
        add_filter('mwai_ai_query', [$this, 'inject_context'], 10, 2);
        
        // Hook into chatbot params for admin pages
        add_filter('mwai_chatbot_params', [$this, 'modify_chatbot_params'], 10, 2);
        
        // Add context via instructions override
        add_filter('mwai_ai_context', [$this, 'add_dynamic_context'], 10, 2);
        
        // Register AJAX for fetching live context
        add_action('wp_ajax_rawwire_get_chatbot_context', [$this, 'ajax_get_context']);
        
        // Enqueue context script on admin pages
        add_action('admin_enqueue_scripts', [$this, 'enqueue_context_script']);
    }

    /**
     * Inject Raw Wire context into AI queries
     * 
     * @param object $query The AI Engine query object
     * @param array  $params Query parameters
     * @return object
     */
    public function inject_context($query, $params = []) {
        // Only inject on admin pages
        if (!is_admin()) {
            return $query;
        }

        // Check if this is a Raw Wire related query
        $message = method_exists($query, 'get_message') ? $query->get_message() : '';
        
        // Always inject for admin chatbots, or if message mentions rawwire/dashboard
        $should_inject = true; // Inject on all admin chatbot queries
        
        if ($should_inject && method_exists($query, 'set_instructions')) {
            $current_instructions = method_exists($query, 'get_instructions') 
                ? $query->get_instructions() 
                : '';
            
            $context = $this->build_context();
            
            // Prepend context to instructions
            $enhanced_instructions = $context . "\n\n" . $current_instructions;
            $query->set_instructions($enhanced_instructions);
        }

        return $query;
    }

    /**
     * Modify chatbot parameters for admin pages
     * 
     * @param array  $params Chatbot parameters
     * @param string $bot_id Bot identifier
     * @return array
     */
    public function modify_chatbot_params($params, $bot_id = '') {
        if (!is_admin()) {
            return $params;
        }

        // Add context to system message if not already set
        if (empty($params['instructions']) || empty($params['context'])) {
            $context = $this->build_context();
            
            if (empty($params['instructions'])) {
                $params['instructions'] = $context;
            } else {
                $params['instructions'] = $context . "\n\n" . $params['instructions'];
            }
        }

        return $params;
    }

    /**
     * Add dynamic context based on current page/state
     * 
     * @param string $context Current context
     * @param array  $params  Parameters
     * @return string
     */
    public function add_dynamic_context($context, $params = []) {
        if (!is_admin()) {
            return $context;
        }

        $dynamic = $this->get_dynamic_state();
        
        return $context . "\n\nCurrent State:\n" . $dynamic;
    }

    /**
     * Build the full Raw Wire context
     * 
     * @return string
     */
    public function build_context() {
        // Use cache if available and recent
        $cache_key = 'rawwire_context_' . get_current_user_id();
        if (isset($this->context_cache[$cache_key])) {
            return $this->context_cache[$cache_key];
        }

        $context = $this->get_base_context();
        $context .= "\n\n" . $this->get_capabilities_context();
        $context .= "\n\n" . $this->get_current_page_context();
        $context .= "\n\n" . $this->get_data_context();

        $this->context_cache[$cache_key] = $context;
        
        return $context;
    }

    /**
     * Get base system context
     * 
     * @return string
     */
    private function get_base_context() {
        return <<<CONTEXT
You are the Raw Wire Dashboard AI Assistant, integrated into a WordPress automation toolkit.

## Your Role
You help administrators manage content automation workflows including:
- **Scrapers**: Collect content from RSS feeds, APIs, and web pages
- **Scorers**: Analyze and rank content by relevance, quality, and criteria
- **Generators**: Create new content using AI models
- **Publishers**: Post content to WordPress, social media, etc.
- **Workflows**: Chain tools together for automated pipelines

## Important Guidelines
1. You have access to Raw Wire tools via MCP (function calling)
2. You can execute scrapers, run analyses, and manage workflows
3. Always confirm destructive actions before executing
4. Provide specific, actionable advice based on dashboard data
5. Reference actual statistics and content when available

## Available Tools (via MCP)
- rawwire_scraper_list_sources: List configured scrapers
- rawwire_scraper_run: Execute a scraper
- rawwire_scraper_add_source: Add new scraper source
- rawwire_content_score: Analyze content quality
- rawwire_content_generate: Generate articles
- rawwire_content_summarize: Summarize content
- rawwire_tools_list: List all automation tools
- rawwire_tool_execute: Run any registered tool
- rawwire_tool_schedule: Schedule tool execution
- rawwire_data_query: Query stored data
- rawwire_stats_get: Get analytics
- rawwire_workflow_create: Create automation workflows
CONTEXT;
    }

    /**
     * Get capabilities context based on what's enabled
     * 
     * @return string
     */
    private function get_capabilities_context() {
        $ai = function_exists('rawwire_ai') ? rawwire_ai() : null;
        $ai_status = $ai ? $ai->get_status() : ['available' => false];
        
        $capabilities = [];
        
        // AI Engine status
        if ($ai_status['available']) {
            $capabilities[] = "✅ AI Engine is active (v{$ai_status['version']})";
            if ($ai_status['pro']) {
                $capabilities[] = "✅ AI Engine Pro features available (embeddings, forms, MCP)";
            }
            $env_count = count($ai_status['environments'] ?? []);
            $capabilities[] = "✅ {$env_count} AI environment(s) configured";
        } else {
            $capabilities[] = "⚠️ AI Engine not installed - AI features limited";
        }

        // Tool Registry status
        if (class_exists('RawWire_Tool_Registry')) {
            $registry = RawWire_Tool_Registry::get_instance();
            $tools = $registry->get_tools();
            $capabilities[] = "✅ Tool Registry active with " . count($tools) . " tools";
        }

        // Action Scheduler status
        if (function_exists('as_schedule_single_action')) {
            $capabilities[] = "✅ Action Scheduler available for async tasks";
        }

        // Scraper sources
        $sources = class_exists('RawWire_Scraper_Settings') 
            ? RawWire_Scraper_Settings::get_sources() 
            : [];
        $capabilities[] = count($sources) . " scraper source(s) configured";

        return "## Current Capabilities\n" . implode("\n", $capabilities);
    }

    /**
     * Get context about the current admin page
     * 
     * @return string
     */
    private function get_current_page_context() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
        $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : '';
        
        $context = "## Current Location\n";
        
        if ($screen) {
            $context .= "Screen: {$screen->id}\n";
        }
        
        if ($page) {
            $context .= "Page: {$page}\n";
            
            // Add page-specific context
            switch ($page) {
                case 'raw-wire-dashboard':
                    $context .= "User is on the main Raw Wire Dashboard\n";
                    $context .= "They can view stats, recent content, and quick actions\n";
                    break;
                    
                case 'raw-wire-settings':
                    $context .= "User is on the Settings page\n";
                    if ($tab === 'scraper' || $tab === 'scrapers') {
                        $context .= "Currently viewing Scraper Settings - help with source configuration\n";
                    } elseif ($tab === 'ai') {
                        $context .= "Currently viewing AI Settings - help with AI Engine configuration\n";
                    }
                    break;
                    
                case 'raw-wire-tools':
                    $context .= "User is on the Tools page\n";
                    $context .= "They can manage and execute automation tools\n";
                    break;
            }
        }
        
        if ($tab) {
            $context .= "Tab: {$tab}\n";
        }

        return $context;
    }

    /**
     * Get context about current data state
     * 
     * @return string
     */
    private function get_data_context() {
        global $wpdb;
        
        $context = "## Data State\n";
        
        // Get content stats
        $table = $wpdb->prefix . 'rawwire_content';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            $total = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
            $pending = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'pending'");
            $approved = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'approved'");
            
            $context .= "Content items: {$total} total, {$pending} pending, {$approved} approved\n";
        }
        
        // Get recent activity
        $stats = get_option('rawwire_dashboard_stats', []);
        $today = date('Y-m-d');
        if (isset($stats[$today])) {
            $context .= "Today's activity:\n";
            $context .= "- Scraper runs: " . ($stats[$today]['scraper_runs'] ?? 0) . "\n";
            $context .= "- AI queries: " . ($stats[$today]['ai_queries'] ?? 0) . "\n";
            $context .= "- Content generated: " . ($stats[$today]['content_generated'] ?? 0) . "\n";
        }
        
        // Get scraper sources summary
        $sources = class_exists('RawWire_Scraper_Settings') 
            ? RawWire_Scraper_Settings::get_sources() 
            : [];
        if (!empty($sources)) {
            $context .= "\nConfigured Scrapers:\n";
            foreach (array_slice($sources, 0, 5) as $source) {
                $status = !empty($source['enabled']) ? 'enabled' : ($source['status'] ?? 'disabled');
                $type = $source['type'] ?? 'unknown';
                $name = $source['name'] ?? 'Unnamed';
                $context .= "- {$name} ({$type}) - {$status}\n";
            }
            if (count($sources) > 5) {
                $context .= "- ... and " . (count($sources) - 5) . " more\n";
            }
        }

        return $context;
    }

    /**
     * Get dynamic state for real-time updates
     * 
     * @return string
     */
    private function get_dynamic_state() {
        $state = [];
        
        // Check for running tasks
        if (function_exists('as_get_scheduled_actions')) {
            $pending = as_get_scheduled_actions([
                'status' => ActionScheduler_Store::STATUS_PENDING,
                'group' => 'rawwire',
                'per_page' => 10,
            ]);
            
            if (!empty($pending)) {
                $state[] = count($pending) . " scheduled tasks pending";
            }
        }
        
        // Recent errors
        $recent_errors = get_option('rawwire_recent_errors', []);
        if (!empty($recent_errors)) {
            $state[] = count($recent_errors) . " recent errors to review";
        }

        return !empty($state) ? implode("\n", $state) : "No special conditions";
    }

    /**
     * AJAX handler for fetching live context
     */
    public function ajax_get_context() {
        check_ajax_referer('rawwire_chatbot_context', '_wpnonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        wp_send_json_success([
            'context' => $this->build_context(),
            'dynamic' => $this->get_dynamic_state(),
        ]);
    }

    /**
     * Enqueue context script for admin pages
     * 
     * @param string $hook Current admin page hook
     */
    public function enqueue_context_script($hook) {
        // Only on Raw Wire pages
        if (strpos($hook, 'raw-wire') === false && strpos($hook, 'rawwire') === false) {
            return;
        }

        wp_add_inline_script('mwai-chatbot', $this->get_context_injection_script(), 'before');
    }

    /**
     * Get JavaScript for context injection
     * 
     * @return string
     */
    private function get_context_injection_script() {
        $nonce = wp_create_nonce('rawwire_chatbot_context');
        
        return <<<JS
// Raw Wire Chatbot Context Injection
(function() {
    if (typeof window.mwaiChatbot === 'undefined') {
        // Wait for chatbot to initialize
        document.addEventListener('mwai-chatbot-loaded', function() {
            injectRawWireContext();
        });
    } else {
        injectRawWireContext();
    }
    
    function injectRawWireContext() {
        // Add context to all chat messages
        if (window.mwaiChatbot) {
            const originalSend = window.mwaiChatbot.sendMessage;
            window.mwaiChatbot.sendMessage = function(message, options) {
                // Context is already injected via PHP filters
                // This is for any additional client-side context
                return originalSend.call(this, message, options);
            };
        }
    }
})();
JS;
    }

    /**
     * Get context summary for display
     * 
     * @return array
     */
    public function get_context_summary() {
        $sources = class_exists('RawWire_Scraper_Settings') 
            ? RawWire_Scraper_Settings::get_sources() 
            : [];
        
        return [
            'ai_available' => function_exists('rawwire_ai') && rawwire_ai()->is_available(),
            'tools_count' => class_exists('RawWire_Tool_Registry') ? count(RawWire_Tool_Registry::get_instance()->get_all()) : 0,
            'sources_count' => count($sources),
        ];
    }
}

// Initialize
RawWire_Chatbot_Context::get_instance();
