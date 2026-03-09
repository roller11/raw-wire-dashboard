<?php

/**
 * Dashboard Chat Panel
 * 
 * Embeds Venice.ai-powered chatbot into Raw Wire Dashboard.
 * Privacy-first: zero data retention on external servers.
 * Supports tool calling, web search, and persistent memory.
 *
 * @package RawWire\Dashboard\Cores\ToolboxCore\Features
 * @since 1.0.22
 * @since 1.0.24 Migrated from AI Engine to Venice.ai direct integration
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class RawWire_Dashboard_Chat_Panel
 * 
 * Provides the embedded AI assistant for the Raw Wire Dashboard.
 */
class RawWire_Dashboard_Chat_Panel
{

    /**
     * Singleton instance
     * @var RawWire_Dashboard_Chat_Panel|null
     */
    private static $instance = null;

    /**
     * Default chatbot ID
     * @var string
     */
    private $chatbot_id = 'rawwire-assistant';

    /**
     * Get singleton instance
     * 
     * @return RawWire_Dashboard_Chat_Panel
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
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        // Render chat toggle + panel in WP admin footer (available site-wide)
        add_action('admin_footer', [$this, 'render_chat_toggle'], 5);
        add_action('admin_footer', [$this, 'render_chat_panel']);

        // Also support Raw Wire custom hooks for sidebar toggle
        add_action('rawwire_dashboard_sidebar', [$this, 'render_chat_toggle'], 20);
    }

    /**
     * Get the Venice chat handler instance
     *
     * @return RawWire_Venice_Chat_Handler
     */
    private function get_chat_handler()
    {
        if (class_exists('RawWire_Venice_Chat_Handler')) {
            return RawWire_Venice_Chat_Handler::get_instance();
        }
        return null;
    }

    /**
     * Check if Venice.ai is configured and available
     *
     * @return bool
     */
    private function is_venice_available()
    {
        $handler = $this->get_chat_handler();
        return $handler && $handler->is_available();
    }

    /**
     * Get display label for the current Venice.ai model
     * 
     * @return string
     */
    private function get_chatbot_model_label()
    {
        $handler = $this->get_chat_handler();
        if ($handler) {
            return $handler->get_model_label();
        }
        return 'Venice.ai';
    }

    // System instructions are now managed by RawWire_Venice_Chat_Handler

    /**
     * Enqueue chat panel assets
     * 
     * @param string $hook Current admin page hook
     */
    public function enqueue_assets($hook)
    {
        // Load on ALL admin pages for site-wide assistance
        // User must have at least edit_posts capability
        if (!current_user_can('edit_posts')) {
            return;
        }

        wp_enqueue_style(
            'rawwire-chat-panel',
            plugins_url('assets/css/chat-panel.css', dirname(__FILE__)),
            [],
            '1.0.0'
        );

        wp_enqueue_script(
            'rawwire-chat-panel',
            plugins_url('assets/js/chat-panel.js', dirname(__FILE__)),
            ['jquery'],
            '1.0.0',
            true
        );

        wp_localize_script('rawwire-chat-panel', 'rawwireChatConfig', [
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('rawwire_venice_chat_nonce'),
            'botId'     => $this->chatbot_id,
            'action'    => 'rawwire_venice_chat',
            'clearAction' => 'rawwire_venice_clear',
            'provider'  => 'venice',
            'model'     => $this->get_chatbot_model_label(),
        ]);
    }

    /**
     * Check if chat panel should be visible on current page
     * 
     * @return bool
     */
    private function should_show_chat()
    {
        // Always show if setting is 'everywhere'
        $visibility = get_option('rawwire_chat_visibility', 'rawwire_only');

        if ($visibility === 'everywhere') {
            return true;
        }

        if ($visibility === 'disabled') {
            return false;
        }

        // Default: only on Raw Wire pages
        $screen = get_current_screen();
        if (!$screen) {
            return false;
        }

        // Show on Raw Wire dashboard pages
        $rawwire_screens = [
            'toplevel_page_raw-wire-dashboard',
            'raw-wire_page_',
            'rawwire',
        ];

        foreach ($rawwire_screens as $prefix) {
            if (strpos($screen->id, $prefix) !== false) {
                return true;
            }
        }

        // Also show if screen base contains 'rawwire'
        if (strpos($screen->base, 'rawwire') !== false) {
            return true;
        }

        return false;
    }

    /**
     * Render chat toggle button in sidebar
     */
    public function render_chat_toggle()
    {
        if (!current_user_can('edit_posts')) {
            return;
        }

        // Check tool toggle state
        if (function_exists('rawwire_tools') && !rawwire_tools()->is_tool_enabled('ai_chat')) {
            return;
        }

        if (!$this->should_show_chat()) {
            return;
        }
?>
        <button type="button" id="rawwire-chat-toggle" class="rawwire-chat-toggle" title="AI Assistant">
            <span class="dashicons dashicons-format-chat"></span>
            <span class="rawwire-chat-toggle-text">AI Assistant</span>
        </button>
    <?php
    }

    /**
     * Render the chat panel
     */
    public function render_chat_panel()
    {
        // Check user capability
        if (!current_user_can('edit_posts')) {
            return;
        }

        // Check tool toggle state
        if (function_exists('rawwire_tools') && !rawwire_tools()->is_tool_enabled('ai_chat')) {
            return;
        }

        // Check visibility setting
        if (!$this->should_show_chat()) {
            return;
        }

        if (!$this->is_venice_available()) {
            $this->render_venice_setup_notice();
            return;
        }
    ?>
        <div id="rawwire-chat-panel" class="rawwire-chat-panel rawwire-chat-panel--collapsed">
            <div class="rawwire-chat-panel__header">
                <div class="rawwire-chat-panel__title">
                    <span class="dashicons dashicons-format-chat"></span>
                    <span>AI Assistant</span>
                    <span class="rawwire-chat-panel__model" title="Powered by Venice.ai - Privacy First">
                        <?php echo esc_html($this->get_chatbot_model_label()); ?>
                    </span>
                </div>
                <div class="rawwire-chat-panel__controls">
                    <button type="button" class="rawwire-chat-panel__btn" id="rawwire-chat-clear" title="Clear conversation">
                        <span class="dashicons dashicons-trash"></span>
                    </button>
                    <button type="button" class="rawwire-chat-panel__btn" id="rawwire-chat-minimize" title="Minimize">
                        <span class="dashicons dashicons-minus"></span>
                    </button>
                    <button type="button" class="rawwire-chat-panel__btn" id="rawwire-chat-close" title="Close">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>
                </div>
            </div>

            <div class="rawwire-chat-panel__body">
                <div class="rawwire-chat-panel__messages" id="rawwire-chat-messages">
                    <div class="rawwire-chat-message rawwire-chat-message--ai">
                        <div class="rawwire-chat-message__content">
                            Hello! I'm your Raw Wire Dashboard assistant. I can help you manage scrapers,
                            generate content, analyze data, and automate workflows. What would you like to do?
                        </div>
                    </div>
                </div>
            </div>

            <div class="rawwire-chat-panel__footer">
                <div class="rawwire-chat-panel__input-wrap">
                    <textarea
                        id="rawwire-chat-input"
                        class="rawwire-chat-panel__input"
                        placeholder="Ask me anything..."
                        rows="1"></textarea>
                    <button type="button" id="rawwire-chat-send" class="rawwire-chat-panel__send">
                        <span class="dashicons dashicons-arrow-right-alt"></span>
                    </button>
                </div>
                <div class="rawwire-chat-panel__status">
                    <span id="rawwire-chat-status"></span>
                </div>
            </div>
        </div>

    <?php
    }

    /**
     * Render notice when Venice.ai is not configured
     */
    private function render_venice_setup_notice()
    {
    ?>
        <div id="rawwire-chat-panel" class="rawwire-chat-panel rawwire-chat-panel--collapsed rawwire-chat-panel--disabled">
            <div class="rawwire-chat-panel__header">
                <div class="rawwire-chat-panel__title">
                    <span class="dashicons dashicons-format-chat"></span>
                    <span>AI Assistant</span>
                </div>
            </div>
            <div class="rawwire-chat-panel__body">
                <div class="rawwire-chat-panel__notice">
                    <p><strong>Venice.ai API Key Required</strong></p>
                    <p>Add your Venice.ai API key in Raw Wire &gt; Tools &gt; AI Settings to enable the privacy-first AI assistant.</p>
                    <p><a href="https://venice.ai/settings/api" target="_blank" rel="noopener">Get a Venice.ai API key</a></p>
                </div>
            </div>
        </div>
<?php
    }

    // AJAX handling is now managed by RawWire_Venice_Chat_Handler
    // See class-venice-chat-handler.php for ajax_handle_message()
}

// Initialize
RawWire_Dashboard_Chat_Panel::get_instance();
