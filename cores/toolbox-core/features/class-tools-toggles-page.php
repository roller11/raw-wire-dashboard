<?php

/**
 * Tools Toggles Page
 *
 * Renders the tool enable/disable toggle interface.
 *
 * @package Raw_Wire_Dashboard
 * @since 1.0.15
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class RW_Tools_Toggles_Page
 * 
 * Handles the Tools toggles page UI - simple on/off switches for each tool.
 * Detailed configuration is handled by AI Settings Panel.
 */
class RW_Tools_Toggles_Page
{
    /**
     * Singleton instance
     * @var RW_Tools_Toggles_Page|null
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return RW_Tools_Toggles_Page
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
        add_action('admin_init', [$this, 'register_settings']);
    }

    /**
     * Register settings
     */
    public function register_settings()
    {
        register_setting('rawwire_tool_toggles', 'rawwire_tool_toggles', [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_toggles'],
        ]);
    }

    /**
     * Sanitize toggle settings
     *
     * @param array $input Raw input
     * @return array Sanitized output
     */
    public function sanitize_toggles($input)
    {
        $clean = [];
        $tools = $this->get_available_tools();

        foreach (array_keys($tools) as $tool_id) {
            $clean[$tool_id] = !empty($input[$tool_id]) ? 1 : 0;
        }

        return $clean;
    }

    /**
     * Render the tools toggles page
     */
    public function render()
    {
        $tools = $this->get_available_tools();
        $tool_settings = get_option('rawwire_tool_toggles', []);

        echo '<div class="wrap rawwire-dashboard rawwire-tools-toggles-page">';
        echo '<div class="rawwire-hero">';
        echo '<div class="rawwire-hero-content">';
        echo '<span class="eyebrow">' . esc_html__('Configuration', 'raw-wire-dashboard') . '</span>';
        echo '<h1><span class="dashicons dashicons-admin-tools"></span> ' . esc_html__('Tools', 'raw-wire-dashboard') . '</h1>';
        echo '<p class="lede">' . esc_html__('Enable or disable individual tools. Configure detailed settings in AI Settings.', 'raw-wire-dashboard') . '</p>';
        echo '</div>';
        echo '<div class="rawwire-hero-actions"></div>';
        echo '</div>';

        $this->render_styles();

        echo '<form method="post" action="options.php" id="tools-toggles-form">';
        settings_fields('rawwire_tool_toggles');

        echo '<div class="rawwire-tools-grid">';

        foreach ($tools as $tool_id => $tool) {
            $enabled = isset($tool_settings[$tool_id]) ? (bool)$tool_settings[$tool_id] : ($tool['default'] ?? true);
            $this->render_tool_card($tool_id, $tool, $enabled);
        }

        echo '</div>'; // End grid

        echo '<p class="submit" style="margin-top: 20px;">';
        echo '<button type="submit" name="submit" class="button rawwire-btn-dark">' . esc_html__('Save Tool Settings', 'raw-wire-dashboard') . '</button>';
        echo '</p>';

        echo '</form>';
        echo '</div>';
    }

    /**
     * Render a single tool card
     *
     * @param string $tool_id Tool identifier
     * @param array  $tool    Tool definition
     * @param bool   $enabled Whether tool is enabled
     */
    private function render_tool_card($tool_id, $tool, $enabled)
    {
        $checked = $enabled ? 'checked' : '';
        $status_class = $enabled ? 'status-active' : 'status-inactive';
        $status_text = $enabled ? __('Active', 'raw-wire-dashboard') : __('Inactive', 'raw-wire-dashboard');

        echo '<div class="rawwire-tool-card">';
        echo '<div class="rawwire-tool-card-header">';
        echo '<div>';
        echo '<span class="dashicons ' . esc_attr($tool['icon']) . ' rawwire-tool-icon"></span>';
        echo '<h3 class="rawwire-tool-title">' . esc_html($tool['label']) . '</h3>';
        echo '</div>';
        echo '<label class="rawwire-toggle">';
        echo '<input type="checkbox" name="rawwire_tool_toggles[' . esc_attr($tool_id) . ']" value="1" ' . $checked . '>';
        echo '<span class="rawwire-toggle-track"><span class="rawwire-toggle-thumb"></span></span>';
        echo '</label>';
        echo '</div>';
        echo '<p class="rawwire-tool-desc">' . esc_html($tool['description']) . '</p>';
        echo '<div class="rawwire-tool-footer">';
        echo '<span class="rawwire-tool-status ' . esc_attr($status_class) . '">' . esc_html($status_text) . '</span>';
        if (!empty($tool['settings_url'])) {
            echo '<a href="' . esc_url($tool['settings_url']) . '" class="button rawwire-btn-dark-secondary rawwire-btn-small">' . esc_html__('Configure', 'raw-wire-dashboard') . '</a>';
        }
        echo '</div>';
        echo '</div>';
    }

    /**
     * Render page-specific styles
     */
    private function render_styles()
    {
?>
        <style>
            /* Tools grid */
            .rawwire-tools-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
                gap: 20px;
                margin-top: 20px;
            }

            /* Tool card – dark surface */
            .rawwire-tool-card {
                background: var(--rw-bg-surface, #18191c);
                border: 1px solid var(--rw-border-default, #2a2a2e);
                border-radius: 8px;
                padding: 20px;
            }

            .rawwire-tool-card-header {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                margin-bottom: 12px;
            }

            .rawwire-tool-icon {
                font-size: 24px;
                color: var(--rw-brand-gold, #f4b41a);
            }

            .rawwire-tool-title {
                margin: 8px 0 4px 0;
                color: var(--rw-fg-default, #e4e4e7);
            }

            .rawwire-tool-desc {
                margin: 0;
                color: var(--rw-fg-muted, #9ca3af);
            }

            .rawwire-tool-footer {
                margin-top: 12px;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }

            /* Status badge */
            .rawwire-tool-status {
                font-size: 12px;
                padding: 2px 8px;
                border-radius: 4px;
            }

            .rawwire-tool-status.status-active {
                background: rgba(244, 180, 26, 0.12);
                color: var(--rw-brand-gold, #f4b41a);
            }

            .rawwire-tool-status.status-inactive {
                background: rgba(255, 255, 255, 0.05);
                color: var(--rw-fg-muted, #9ca3af);
            }

            /* Dark action buttons (shared with AI Settings) */
            .rawwire-btn-dark {
                background: #2a2a2a !important;
                border-color: #3a3a3a !important;
                color: var(--rw-brand-gold, #f4b41a) !important;
                text-shadow: none !important;
            }

            .rawwire-btn-dark:hover,
            .rawwire-btn-dark:focus {
                background: #1e1e1e !important;
                border-color: var(--rw-brand-gold, #f4b41a) !important;
                color: var(--rw-brand-gold-light, #ffd54f) !important;
            }

            .rawwire-btn-dark-secondary {
                background: #333 !important;
                border-color: #444 !important;
                color: #ccc !important;
                text-shadow: none !important;
            }

            .rawwire-btn-dark-secondary:hover,
            .rawwire-btn-dark-secondary:focus {
                background: #2a2a2a !important;
                border-color: #555 !important;
                color: #eee !important;
            }

            .rawwire-btn-small {
                padding: 0 8px !important;
                font-size: 12px !important;
                line-height: 24px !important;
                min-height: 24px !important;
            }

            /* Light-mode overrides */
            [data-theme="light"] .rawwire-tool-card,
            .rawwire-dashboard:not([data-theme="dark"]) .rawwire-tool-card {
                background: #fff;
                border-color: #ddd;
            }

            [data-theme="light"] .rawwire-tool-title,
            .rawwire-dashboard:not([data-theme="dark"]) .rawwire-tool-title {
                color: #1d2327;
            }

            [data-theme="light"] .rawwire-tool-desc,
            .rawwire-dashboard:not([data-theme="dark"]) .rawwire-tool-desc {
                color: #666;
            }

            [data-theme="light"] .rawwire-tool-status.status-inactive,
            .rawwire-dashboard:not([data-theme="dark"]) .rawwire-tool-status.status-inactive {
                color: #666;
            }
        </style>
<?php
    }

    /**
     * Get available tools for toggles page
     *
     * @return array Tool definitions
     */
    public function get_available_tools()
    {
        return [
            'venice' => [
                'label'       => __('Venice.ai', 'raw-wire-dashboard'),
                'description' => __('Privacy-first LLM provider for AI chat and generation.', 'raw-wire-dashboard'),
                'icon'        => 'dashicons-shield',
                'default'     => true,
                'settings_url' => admin_url('admin.php?page=rawwire-ai-settings&tab=venice'),
            ],
            'instinct' => [
                'label'       => __('Instinct Context Engine', 'raw-wire-dashboard'),
                'description' => __('Priority-based context injection for AI conversations.', 'raw-wire-dashboard'),
                'icon'        => 'dashicons-lightbulb',
                'default'     => false,
                'settings_url' => admin_url('admin.php?page=rawwire-ai-settings&tab=instinct'),
            ],
            'ai_scraper' => [
                'label'       => __('AI Scraper', 'raw-wire-dashboard'),
                'description' => __('AI-powered content extraction and analysis.', 'raw-wire-dashboard'),
                'icon'        => 'dashicons-search',
                'default'     => false,
                'settings_url' => admin_url('admin.php?page=rawwire-ai-settings&tab=engine'),
            ],
            'ollama' => [
                'label'       => __('Ollama', 'raw-wire-dashboard'),
                'description' => __('Local LLM server for self-hosted AI models.', 'raw-wire-dashboard'),
                'icon'        => 'dashicons-database',
                'default'     => false,
                'settings_url' => admin_url('admin.php?page=rawwire-ai-settings&tab=engine'),
            ],
            'groq' => [
                'label'       => __('Groq', 'raw-wire-dashboard'),
                'description' => __('Fast inference engine for rapid AI responses.', 'raw-wire-dashboard'),
                'icon'        => 'dashicons-performance',
                'default'     => false,
                'settings_url' => admin_url('admin.php?page=rawwire-ai-settings&tab=engine'),
            ],
            'mcp_server' => [
                'label'       => __('MCP Server', 'raw-wire-dashboard'),
                'description' => __('Model Context Protocol server for tool integration.', 'raw-wire-dashboard'),
                'icon'        => 'dashicons-rest-api',
                'default'     => false,
                'settings_url' => admin_url('admin.php?page=rawwire-ai-settings&tab=mcp'),
            ],
            'openclaw' => [
                'label'       => __('OpenClaw Gateway', 'raw-wire-dashboard'),
                'description' => __('Local OpenAI-compatible AI proxy via OpenClaw.', 'raw-wire-dashboard'),
                'icon'        => 'dashicons-networking',
                'default'     => false,
                'settings_url' => admin_url('admin.php?page=rawwire-ai-settings&tab=openclaw'),
            ],
        ];
    }

    /**
     * Check if a specific tool is enabled
     *
     * @param string $tool_id Tool identifier
     * @return bool Whether tool is enabled
     */
    public function is_tool_enabled($tool_id)
    {
        $tools = $this->get_available_tools();
        $settings = get_option('rawwire_tool_toggles', []);

        if (!isset($tools[$tool_id])) {
            return false;
        }

        return isset($settings[$tool_id])
            ? (bool)$settings[$tool_id]
            : ($tools[$tool_id]['default'] ?? false);
    }
}

/**
 * Helper function to get tools toggles instance
 *
 * @return RW_Tools_Toggles_Page
 */
function rawwire_tools_toggles()
{
    return RW_Tools_Toggles_Page::get_instance();
}
