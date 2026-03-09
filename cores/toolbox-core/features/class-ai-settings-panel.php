<?php

/**
 * @ai-context Search Instinct MCP for "AI Settings Panel Function Map v4" before modifying this file.
 */

/**
 * AI Settings Panel - Configuration UI for AI provider settings
 * 
 * Provides admin interface for configuring provider credentials,
 * model defaults, and provider endpoints.
 *
 * ╔═══════════════════════════════════════════════════════════════════════════╗
 * ║ STOP! THIS TOOL REGISTERS AS A TAB, NOT A SUBMENU                         ║
 * ╠═══════════════════════════════════════════════════════════════════════════╣
 * ║ Menu Architecture (see class-menu-manager.php):                           ║
 * ║                                                                           ║
 * ║   Raw Wire (Dashboard)                                                    ║
 * ║   ├── Templates        ← Always visible                                   ║
 * ║   ├── Tools            ← Multi-tab page (THIS TOOL registers here)        ║
 * ║   ├── Workflows        ← Multi-tab page                                   ║
 * ║   └── Settings                                                            ║
 * ║                                                                           ║
 * ║ Tools register TABS via RawWire_Menu_Manager::register_tool_tab()         ║
 * ║ DO NOT use add_submenu_page() - that creates separate menu items!         ║
 * ║                                                                           ║
 * ║ This tool:                                                                ║
 * ║   - ID: ai_settings                                                       ║
 * ║   - Feature: ai_settings                                                  ║
 * ║   - Shows as tab in Tools page when feature is enabled                    ║
 * ╚═══════════════════════════════════════════════════════════════════════════╝
 *
 * @package RawWire\Dashboard\Cores\ToolboxCore\Features
 * @since 1.0.15
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class RawWire_AI_Settings_Panel
 */
class RawWire_AI_Settings_Panel
{

    /**
     * Tool ID for tab registration
     */
    const TOOL_ID = 'ai_settings';

    /**
     * Required feature for this tool
     */
    const REQUIRED_FEATURE = 'ai_settings';

    /**
     * Singleton instance
     * @var RawWire_AI_Settings_Panel|null
     */
    private static $instance = null;

    /**
     * Get singleton instance
     * 
     * @return RawWire_AI_Settings_Panel
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
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_rawwire_ai_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_rawwire_venice_test_connection', [$this, 'ajax_venice_test_connection']);
        add_action('wp_ajax_rawwire_refresh_venice_models', [$this, 'ajax_refresh_venice_models']);
        add_action('wp_ajax_rawwire_refresh_ollama_models', [$this, 'ajax_refresh_ollama_models']);
        add_action('wp_ajax_rawwire_instinct_test_connection', [$this, 'ajax_instinct_test_connection']);
        add_action('wp_ajax_rawwire_instinct_get_stats', [$this, 'ajax_instinct_get_stats']);
        add_action('wp_ajax_rawwire_ai_get_status', [$this, 'ajax_get_status']);
        add_action('wp_ajax_rawwire_ai_get_models', [$this, 'ajax_get_models']);
        add_action('wp_ajax_rawwire_mcp_list_tools', [$this, 'ajax_list_mcp_tools']);
        add_action('wp_ajax_rawwire_openclaw_test_connection', [$this, 'ajax_openclaw_test_connection']);
        add_action('wp_ajax_rawwire_openclaw_refresh_models', [$this, 'ajax_openclaw_refresh_models']);
        add_action('wp_ajax_rawwire_refresh_perplexity_models', [$this, 'ajax_refresh_perplexity_models']);
        add_action('wp_ajax_rawwire_refresh_openai_models', [$this, 'ajax_refresh_openai_models']);
    }

    /**
     * Enqueue assets for AI settings page
     * 
     * @param string $hook Current page hook
     */
    public function enqueue_assets($hook)
    {
        if (strpos($hook, 'rawwire-ai-settings') === false && strpos($hook, 'raw-wire-settings') === false) {
            return;
        }

        wp_enqueue_style('rawwire-admin');
        wp_enqueue_script('rawwire-admin');

        wp_localize_script('rawwire-admin', 'rawwire_ai_settings', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rawwire_ai_settings_nonce'),
        ]);
    }

    /**
     * Render AI settings panel inside template pages
     *
     * @param array $panel
     * @param array $context
     */
    public static function render_template_panel($panel = [], $context = [])
    {
        self::get_instance()->render_page();
    }

    /**
     * Get available tabs for AI Settings
     * 
     * @return array
     */
    public function get_tabs()
    {
        return array(
            'venice' => array(
                'label' => __('Venice.ai', 'raw-wire-dashboard'),
                'icon'  => 'dashicons-shield',
            ),
            'perplexity' => array(
                'label' => __('Perplexity', 'raw-wire-dashboard'),
                'icon'  => 'dashicons-admin-site-alt3',
            ),
            'openai' => array(
                'label' => __('OpenAI', 'raw-wire-dashboard'),
                'icon'  => 'dashicons-cloud',
            ),
        );
    }

    /**
     * Render the admin page wrapper with tabbed navigation
     */
    public function render_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $tabs = $this->get_tabs();
        $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'venice';

        // Validate tab
        if (!isset($tabs[$current_tab])) {
            $current_tab = 'venice';
        }

        echo '<div class="wrap rawwire-dashboard rawwire-ai-settings-page">';
        echo '<div class="rawwire-hero">';
        echo '<div class="rawwire-hero-content">';
        echo '<span class="eyebrow">' . esc_html__('Configuration', 'raw-wire-dashboard') . '</span>';
        echo '<h1><span class="dashicons dashicons-admin-generic"></span> ' . esc_html__('AI Settings', 'raw-wire-dashboard') . '</h1>';
        echo '<p class="lede">' . esc_html__('Configure AI provider credentials, endpoints, and model defaults.', 'raw-wire-dashboard') . '</p>';
        echo '</div><div class="rawwire-hero-actions"><div class="rawwire-ai-orbit" aria-hidden="true"><span></span><span></span><span></span></div></div></div>';

        $this->render_page_chrome_styles();

        // Tab navigation
        echo '<nav class="nav-tab-wrapper rawwire-tabs" style="margin-bottom: 20px;">';
        foreach ($tabs as $tab_id => $tab) {
            $active = ($current_tab === $tab_id) ? 'nav-tab-active' : '';
            $url = add_query_arg('tab', $tab_id, admin_url('admin.php?page=rawwire-ai-settings'));
            echo '<a href="' . esc_url($url) . '" class="nav-tab ' . esc_attr($active) . '">';
            if (!empty($tab['icon'])) {
                echo '<span class="dashicons ' . esc_attr($tab['icon']) . '" style="margin-right: 5px;"></span>';
            }
            echo esc_html($tab['label']);
            echo '</a>';
        }
        echo '</nav>';

        // Tab content
        echo '<div class="rawwire-tab-content">';
        $this->render_tab_content($current_tab);
        echo '</div>';

        echo '</div>';
    }

    /**
     * Render content for specific tab
     * 
     * @param string $tab Tab ID
     */
    public function render_tab_content($tab)
    {
        switch ($tab) {
            case 'venice':
                $this->render_venice_tab();
                break;
            case 'perplexity':
                $this->render_perplexity_tab();
                break;
            case 'openai':
                $this->render_openai_tab();
                break;
            default:
                $this->render_venice_tab();
        }
    }

    /**
     * Shared chrome for the AI Settings page.
     */
    private function render_page_chrome_styles()
    {
?>
        <style>
            .rawwire-ai-settings-page .rawwire-hero {
                position: relative;
                overflow: hidden;
                background: linear-gradient(135deg, #0d1117 0%, #161b22 45%, #111827 100%);
                border: 1px solid rgba(244, 180, 26, 0.14);
                border-radius: 18px;
                padding: 26px 28px;
                margin-bottom: 18px;
                color: #f8fafc;
            }

            .rawwire-ai-settings-page .rawwire-hero::before {
                content: '';
                position: absolute;
                inset: 0;
                background-image:
                    linear-gradient(rgba(244, 180, 26, 0.08) 1px, transparent 1px),
                    linear-gradient(90deg, rgba(244, 180, 26, 0.08) 1px, transparent 1px);
                background-size: 34px 34px;
                opacity: 0.55;
                animation: rwAiGridPulse 6s ease-in-out infinite;
                pointer-events: none;
            }

            .rawwire-ai-settings-page .rawwire-hero-content,
            .rawwire-ai-settings-page .rawwire-hero-actions {
                position: relative;
                z-index: 1;
            }

            .rawwire-ai-settings-page .rawwire-hero h1 {
                display: flex;
                align-items: center;
                gap: 10px;
                margin: 6px 0 8px;
                color: #f8fafc;
            }

            .rawwire-ai-settings-page .rawwire-hero .lede,
            .rawwire-ai-settings-page .rawwire-hero .eyebrow {
                color: rgba(248, 250, 252, 0.78);
            }

            .rawwire-ai-settings-page .rawwire-tabs {
                display: flex;
                gap: 8px;
                flex-wrap: wrap;
                border-bottom: none;
                padding: 0;
                background: transparent;
            }

            .rawwire-ai-settings-page .rawwire-tabs .nav-tab {
                margin-left: 0;
                border: 1px solid #d0d7de;
                border-radius: 999px;
                background: #ffffff;
                color: #334155;
                padding: 10px 16px;
                display: inline-flex;
                align-items: center;
                gap: 8px;
                box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
            }

            .rawwire-ai-settings-page .rawwire-tabs .nav-tab.nav-tab-active {
                background: #111827;
                color: #f4b41a;
                border-color: #111827;
            }

            .rawwire-ai-orbit {
                position: relative;
                width: 76px;
                height: 76px;
                border: 1px solid rgba(244, 180, 26, 0.18);
                border-radius: 50%;
            }

            .rawwire-ai-orbit::before,
            .rawwire-ai-orbit::after {
                content: '';
                position: absolute;
                inset: 10px;
                border: 1px solid rgba(244, 180, 26, 0.12);
                border-radius: 50%;
            }

            .rawwire-ai-orbit::after {
                inset: 22px;
            }

            .rawwire-ai-orbit span {
                position: absolute;
                top: 50%;
                left: 50%;
                width: 10px;
                height: 10px;
                margin: -5px 0 0 -5px;
                border-radius: 50%;
                background: #f4b41a;
                box-shadow: 0 0 20px rgba(244, 180, 26, 0.45);
                transform-origin: 0 0;
            }

            .rawwire-ai-orbit span:nth-child(1) {
                animation: rwAiOrbitOne 4.5s linear infinite;
            }

            .rawwire-ai-orbit span:nth-child(2) {
                width: 8px;
                height: 8px;
                margin: -4px 0 0 -4px;
                background: #60a5fa;
                animation: rwAiOrbitTwo 6s linear infinite;
            }

            .rawwire-ai-orbit span:nth-child(3) {
                width: 6px;
                height: 6px;
                margin: -3px 0 0 -3px;
                background: #34d399;
                animation: rwAiOrbitThree 3.8s linear infinite reverse;
            }

            @keyframes rwAiGridPulse {

                0%,
                100% {
                    opacity: 0.35;
                }

                50% {
                    opacity: 0.7;
                }
            }

            @keyframes rwAiOrbitOne {
                from {
                    transform: rotate(0deg) translateX(38px);
                }

                to {
                    transform: rotate(360deg) translateX(38px);
                }
            }

            @keyframes rwAiOrbitTwo {
                from {
                    transform: rotate(0deg) translateX(26px);
                }

                to {
                    transform: rotate(360deg) translateX(26px);
                }
            }

            @keyframes rwAiOrbitThree {
                from {
                    transform: rotate(0deg) translateX(14px);
                }

                to {
                    transform: rotate(360deg) translateX(14px);
                }
            }

            .rawwire-investigation-subtabs {
                display: flex;
                gap: 10px;
                flex-wrap: wrap;
                margin-bottom: 18px;
            }

            .rawwire-investigation-subtabs a {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 10px 14px;
                border-radius: 999px;
                border: 1px solid #d0d7de;
                background: #fff;
                color: #334155;
                text-decoration: none;
                font-weight: 600;
            }

            .rawwire-investigation-subtabs a.is-active {
                background: #111827;
                border-color: #111827;
                color: #f4b41a;
            }

            .rawwire-pipeline-card-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 16px;
                margin-bottom: 18px;
            }

            .rawwire-pipeline-card {
                border: 1px solid #d0d7de;
                border-radius: 16px;
                padding: 18px;
                background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
                box-shadow: 0 12px 30px rgba(15, 23, 42, 0.05);
            }

            .rawwire-pipeline-card.is-active {
                border-color: #111827;
                box-shadow: 0 18px 40px rgba(17, 24, 39, 0.12);
            }

            .rawwire-pipeline-card h3 {
                margin-top: 0;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 10px;
            }

            .rawwire-pipeline-pill {
                display: inline-flex;
                align-items: center;
                padding: 4px 10px;
                border-radius: 999px;
                background: #e2e8f0;
                color: #334155;
                font-size: 11px;
                font-weight: 700;
                letter-spacing: 0.04em;
                text-transform: uppercase;
            }

            .rawwire-pipeline-pill.is-live {
                background: rgba(244, 180, 26, 0.18);
                color: #8a5a00;
            }

            .rawwire-provider-callout {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                gap: 14px;
                margin-top: 18px;
            }

            .rawwire-provider-metric {
                padding: 14px 16px;
                border-radius: 14px;
                background: #0f172a;
                color: #e2e8f0;
            }

            .rawwire-provider-metric strong {
                display: block;
                font-size: 20px;
                color: #f8fafc;
            }
        </style>
    <?php
    }

    /**
     * Render Venice.ai tab content
     */
    private function render_venice_tab()
    {
        $venice_settings = get_option('rawwire_venice_settings', $this->get_default_venice_settings());
    ?>
        <div class="rawwire-ai-settings-panel">
            <div class="rawwire-settings-section active" style="border-left: 4px solid var(--rw-brand-gold, #f4b41a);">
                <h2 style="margin-top: 0; display: flex; align-items: center; gap: 8px;">
                    <span class="dashicons dashicons-shield" style="color: var(--rw-brand-gold, #f4b41a);"></span>
                    <?php esc_html_e('Venice.ai (Privacy-First LLM)', 'raw-wire-dashboard'); ?>
                </h2>
                <p class="description" style="margin-bottom: 16px;">
                    <?php esc_html_e('Venice.ai provides zero-data-retention AI models. Your conversations are never stored or used for training.', 'raw-wire-dashboard'); ?>
                    <a href="https://venice.ai/settings/api" target="_blank" rel="noopener"><?php esc_html_e('Get API Key', 'raw-wire-dashboard'); ?></a>
                </p>

                <form method="post" action="options.php" id="venice-settings-form">
                    <?php settings_fields('rawwire_venice_group'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="venice_api_key"><?php esc_html_e('API Key', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <input type="password" name="rawwire_venice_settings[api_key]" id="venice_api_key"
                                    value="<?php echo esc_attr($venice_settings['api_key'] ?? ''); ?>"
                                    class="regular-text" autocomplete="off">
                                <button type="button" class="rawwire-key-toggle" id="toggle-venice-key" title="<?php esc_attr_e('Toggle visibility', 'raw-wire-dashboard'); ?>">
                                    <span class="dashicons dashicons-visibility"></span>
                                </button>
                                <?php if (!empty($venice_settings['api_key'])): ?>
                                    <span class="dashicons dashicons-yes-alt" style="color: var(--rw-brand-gold, #f4b41a); margin-left: 8px;" title="<?php esc_attr_e('API Key configured', 'raw-wire-dashboard'); ?>"></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="venice_model"><?php esc_html_e('Model', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <?php
                                if (!class_exists('RawWire_Adapter_Generator_Venice')) {
                                    require_once dirname(__FILE__) . '/../adapters/generators/class-generator-venice.php';
                                }
                                $venice_models = RawWire_Adapter_Generator_Venice::get_available_models();
                                $current_model = $venice_settings['model'] ?? 'llama-3.3-70b';
                                $budget_models = array_filter($venice_models, fn($m) => ($m['tier'] ?? '') === 'budget');
                                $mid_models = array_filter($venice_models, fn($m) => ($m['tier'] ?? '') === 'mid');
                                $premium_models = array_filter($venice_models, fn($m) => ($m['tier'] ?? '') === 'premium');
                                ?>
                                <select name="rawwire_venice_settings[model]" id="venice_model" style="min-width: 450px;">
                                    <optgroup label="💵 Budget (< $1/M tokens)">
                                        <?php foreach ($budget_models as $model_id => $model):
                                            $badges = [];
                                            if (!empty($model['tools'])) $badges[] = '🔧';
                                            if (!empty($model['vision'])) $badges[] = '👁️';
                                            if (!empty($model['reason'])) $badges[] = '🧠';
                                            $ctx = number_format($model['context'] / 1000) . 'K';
                                            $badge_str = !empty($badges) ? ' ' . implode('', $badges) : '';
                                            $cost = $model['cost'] ?? '';
                                        ?>
                                            <option value="<?php echo esc_attr($model_id); ?>" <?php selected($current_model, $model_id); ?>>
                                                <?php echo esc_html($model['label'] . ' (' . $ctx . ')' . $badge_str . ' — ' . $cost); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <optgroup label="💰 Mid-Tier ($1-3/M tokens)">
                                        <?php foreach ($mid_models as $model_id => $model):
                                            $badges = [];
                                            if (!empty($model['tools'])) $badges[] = '🔧';
                                            if (!empty($model['vision'])) $badges[] = '👁️';
                                            if (!empty($model['reason'])) $badges[] = '🧠';
                                            $ctx = number_format($model['context'] / 1000) . 'K';
                                            $badge_str = !empty($badges) ? ' ' . implode('', $badges) : '';
                                            $cost = $model['cost'] ?? '';
                                        ?>
                                            <option value="<?php echo esc_attr($model_id); ?>" <?php selected($current_model, $model_id); ?>>
                                                <?php echo esc_html($model['label'] . ' (' . $ctx . ')' . $badge_str . ' — ' . $cost); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <optgroup label="💎 Premium (> $3/M tokens)">
                                        <?php foreach ($premium_models as $model_id => $model):
                                            $badges = [];
                                            if (!empty($model['tools'])) $badges[] = '🔧';
                                            if (!empty($model['vision'])) $badges[] = '👁️';
                                            if (!empty($model['reason'])) $badges[] = '🧠';
                                            $ctx = number_format($model['context'] / 1000) . 'K';
                                            $badge_str = !empty($badges) ? ' ' . implode('', $badges) : '';
                                            $cost = $model['cost'] ?? '';
                                        ?>
                                            <option value="<?php echo esc_attr($model_id); ?>" <?php selected($current_model, $model_id); ?>>
                                                <?php echo esc_html($model['label'] . ' (' . $ctx . ')' . $badge_str . ' — ' . $cost); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                </select>
                                <button type="button" id="refresh_venice_models" class="button button-secondary" style="margin-left: 10px;">
                                    <?php esc_html_e('🔄 Refresh Models', 'raw-wire-dashboard'); ?>
                                </button>
                                <span id="refresh_models_status" style="margin-left: 10px; display: none;"></span>
                                <p class="description" style="margin-top: 8px;">
                                    <?php esc_html_e('🔧 = Tools  👁️ = Vision  🧠 = Reasoning | Prices per 1M tokens', 'raw-wire-dashboard'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="venice_temperature"><?php esc_html_e('Temperature', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <input type="range" name="rawwire_venice_settings[temperature]" id="venice_temperature"
                                    value="<?php echo esc_attr($venice_settings['temperature'] ?? 0.7); ?>"
                                    min="0" max="2" step="0.1" style="width: 200px;">
                                <span id="venice_temp_value"><?php echo esc_html($venice_settings['temperature'] ?? 0.7); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="venice_top_p"><?php esc_html_e('Top P', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <input type="range" name="rawwire_venice_settings[top_p]" id="venice_top_p"
                                    value="<?php echo esc_attr($venice_settings['top_p'] ?? 0.9); ?>"
                                    min="0" max="1" step="0.05" style="width: 200px;">
                                <span id="venice_top_p_value"><?php echo esc_html($venice_settings['top_p'] ?? 0.9); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="venice_reasoning_effort"><?php esc_html_e('Reasoning Effort', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <select name="rawwire_venice_settings[reasoning_effort]" id="venice_reasoning_effort">
                                    <option value="off" <?php selected(($venice_settings['reasoning_effort'] ?? 'off'), 'off'); ?>><?php esc_html_e('Off', 'raw-wire-dashboard'); ?></option>
                                    <option value="minimal" <?php selected(($venice_settings['reasoning_effort'] ?? 'off'), 'minimal'); ?>><?php esc_html_e('Minimal', 'raw-wire-dashboard'); ?></option>
                                    <option value="low" <?php selected(($venice_settings['reasoning_effort'] ?? 'off'), 'low'); ?>><?php esc_html_e('Low', 'raw-wire-dashboard'); ?></option>
                                    <option value="medium" <?php selected(($venice_settings['reasoning_effort'] ?? 'off'), 'medium'); ?>><?php esc_html_e('Medium', 'raw-wire-dashboard'); ?></option>
                                    <option value="high" <?php selected(($venice_settings['reasoning_effort'] ?? 'off'), 'high'); ?>><?php esc_html_e('High', 'raw-wire-dashboard'); ?></option>
                                    <option value="xhigh" <?php selected(($venice_settings['reasoning_effort'] ?? 'off'), 'xhigh'); ?>><?php esc_html_e('XHigh', 'raw-wire-dashboard'); ?></option>
                                    <option value="max" <?php selected(($venice_settings['reasoning_effort'] ?? 'off'), 'max'); ?>><?php esc_html_e('Max', 'raw-wire-dashboard'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Web Features', 'raw-wire-dashboard'); ?></th>
                            <td>
                                <label style="display:block; margin-bottom: 8px;">
                                    <?php esc_html_e('Search Mode', 'raw-wire-dashboard'); ?>
                                    <select name="rawwire_venice_settings[enable_web_search]" style="margin-left: 10px;">
                                        <option value="off" <?php selected(($venice_settings['enable_web_search'] ?? 'off'), 'off'); ?>><?php esc_html_e('Off', 'raw-wire-dashboard'); ?></option>
                                        <option value="auto" <?php selected(($venice_settings['enable_web_search'] ?? 'off'), 'auto'); ?>><?php esc_html_e('Auto', 'raw-wire-dashboard'); ?></option>
                                    </select>
                                </label>
                                <label style="display:block; margin-bottom: 8px;">
                                    <input type="checkbox" name="rawwire_venice_settings[enable_web_scraping]" value="1" <?php checked(!empty($venice_settings['enable_web_scraping'])); ?>>
                                    <?php esc_html_e('Allow web scraping', 'raw-wire-dashboard'); ?>
                                </label>
                                <label style="display:block; margin-bottom: 8px;">
                                    <input type="checkbox" name="rawwire_venice_settings[enable_web_citations]" value="1" <?php checked(!empty($venice_settings['enable_web_citations'])); ?>>
                                    <?php esc_html_e('Return web citations', 'raw-wire-dashboard'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Tool Availability', 'raw-wire-dashboard'); ?></th>
                            <td>
                                <label style="display:block; margin-bottom: 8px;">
                                    <input type="checkbox" name="rawwire_venice_settings[allow_tool_calls]" value="1" <?php checked(!empty($venice_settings['allow_tool_calls'])); ?>>
                                    <?php esc_html_e('Allow tool calls on tool-capable requests', 'raw-wire-dashboard'); ?>
                                </label>
                                <label style="display:block; margin-bottom: 8px;">
                                    <input type="checkbox" name="rawwire_venice_settings[parallel_tool_calls]" value="1" <?php checked(!empty($venice_settings['parallel_tool_calls'])); ?>>
                                    <?php esc_html_e('Allow parallel tool calls', 'raw-wire-dashboard'); ?>
                                </label>
                                <label style="display:block; margin-bottom: 8px;">
                                    <input type="checkbox" name="rawwire_venice_settings[allow_mcp_tools]" value="1" <?php checked(!empty($venice_settings['allow_mcp_tools'])); ?>>
                                    <?php esc_html_e('Expose MCP tools when supplied by the caller', 'raw-wire-dashboard'); ?>
                                </label>
                                <label style="display:block; margin-bottom: 8px;">
                                    <input type="checkbox" name="rawwire_venice_settings[allow_openclaw_tools]" value="1" <?php checked(!empty($venice_settings['allow_openclaw_tools'])); ?>>
                                    <?php esc_html_e('Expose OpenClaw/browser tools when supplied by the caller', 'raw-wire-dashboard'); ?>
                                </label>
                                <label style="display:block; margin-bottom: 8px;">
                                    <input type="checkbox" name="rawwire_venice_settings[disable_thinking]" value="1" <?php checked(!empty($venice_settings['disable_thinking'])); ?>>
                                    <?php esc_html_e('Disable thinking output on supported models', 'raw-wire-dashboard'); ?>
                                </label>
                                <label style="display:block; margin-bottom: 8px;">
                                    <input type="checkbox" name="rawwire_venice_settings[include_venice_system_prompt]" value="1" <?php checked(!empty($venice_settings['include_venice_system_prompt'])); ?>>
                                    <?php esc_html_e('Include Venice system prompt in requests', 'raw-wire-dashboard'); ?>
                                </label>
                                <p class="description"><?php esc_html_e('These options only affect Venice requests that already pass tools or Venice-specific parameters.', 'raw-wire-dashboard'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" class="button rawwire-btn-dark"><?php esc_html_e('Save Venice Settings', 'raw-wire-dashboard'); ?></button>
                        <button type="button" class="button rawwire-btn-dark-secondary" id="test-venice-connection"><?php esc_html_e('Test Connection', 'raw-wire-dashboard'); ?></button>
                    </p>
                </form>
                <div id="venice-test-result" class="notice" style="display: none;"></div>
            </div>
        </div>
    <?php
        $this->render_venice_tab_styles();
    }

    /**
     * Render Venice tab styles
     */
    private function render_venice_tab_styles()
    {
    ?>
        <style>
            .rawwire-ai-settings-panel {
                max-width: 900px;
            }

            .rawwire-settings-section {
                background: var(--rw-bg-surface, #fff);
                border: 1px solid var(--rw-border-default, #ccd0d4);
                border-radius: 8px;
                padding: 20px;
                margin-bottom: 20px;
            }

            .rawwire-settings-section h2,
            .rawwire-settings-section h3 {
                margin-top: 0;
                color: var(--rw-fg-default, #1d2327);
            }

            /* Eye toggle for API key - minimal gold icon, no button chrome */
            .rawwire-key-toggle {
                background: none !important;
                border: none !important;
                box-shadow: none !important;
                padding: 4px 6px !important;
                margin-left: 4px;
                cursor: pointer;
                vertical-align: middle;
                display: inline-flex;
                align-items: center;
                line-height: 1;
            }

            .rawwire-key-toggle .dashicons {
                color: var(--rw-brand-gold, #f4b41a);
                font-size: 18px;
                width: 18px;
                height: 18px;
            }

            .rawwire-key-toggle:hover .dashicons {
                color: var(--rw-brand-gold-light, #ffd54f);
            }

            .rawwire-key-toggle:focus {
                outline: none !important;
            }

            /* Dark muted action buttons */
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
        </style>
        <script>
            jQuery(document).ready(function($) {
                // Toggle API key visibility
                $('#toggle-venice-key').on('click', function() {
                    var input = $('#venice_api_key');
                    var icon = $(this).find('.dashicons');
                    if (input.attr('type') === 'password') {
                        input.attr('type', 'text');
                        icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
                    } else {
                        input.attr('type', 'password');
                        icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
                    }
                });
                // Temperature slider
                $('#venice_temperature').on('input', function() {
                    $('#venice_temp_value').text($(this).val());
                });
                $('#venice_top_p').on('input', function() {
                    $('#venice_top_p_value').text($(this).val());
                });
                // Test Venice connection
                $('#test-venice-connection').on('click', function() {
                    var $btn = $(this);
                    var $result = $('#venice-test-result');
                    $btn.prop('disabled', true).text('Testing...');
                    $.post(ajaxurl, {
                        action: 'rawwire_venice_test_connection',
                        nonce: '<?php echo wp_create_nonce('rawwire_ai_settings_nonce'); ?>'
                    }, function(response) {
                        $result.show().removeClass('notice-success notice-error')
                            .addClass(response.success ? 'notice-success' : 'notice-error')
                            .html('<p>' + (response.data?.message || response.data || 'Unknown response') + '</p>');
                    }).always(function() {
                        $btn.prop('disabled', false).text('Test Connection');
                    });
                });
                // Refresh models
                $('#refresh_venice_models').on('click', function() {
                    var $btn = $(this);
                    var $status = $('#refresh_models_status');
                    $btn.prop('disabled', true);
                    $status.show().text('Refreshing...');
                    $.post(ajaxurl, {
                        action: 'rawwire_refresh_venice_models',
                        nonce: '<?php echo wp_create_nonce('rawwire_ai_settings_nonce'); ?>'
                    }, function(response) {
                        if (response.success) {
                            $status.text('✓ Models refreshed! Reloading...');
                            setTimeout(function() {
                                location.reload();
                            }, 1000);
                        } else {
                            $status.text('✗ ' + (response.data || 'Failed'));
                        }
                    }).always(function() {
                        $btn.prop('disabled', false);
                    });
                });
            });
        </script>
    <?php
    }

    /**
     * Render Instinct Context Engine tab content
     */
    private function render_instinct_tab()
    {
        $instinct_settings = get_option('rawwire_instinct_settings', [
            'enabled' => false,
            'host' => '127.0.0.1',
            'port' => 8080,
            'auto_inject' => true,
            'min_importance' => 30,
            'max_tokens' => 8000,
        ]);
    ?>
        <div class="rawwire-ai-settings-panel">
            <div class="rawwire-settings-section" style="border-left: 4px solid #8b5cf6;">
                <h2 style="margin-top: 0; display: flex; align-items: center; gap: 8px;">
                    <span class="dashicons dashicons-lightbulb" style="color: #8b5cf6;"></span>
                    <?php esc_html_e('Instinct Context Engine', 'raw-wire-dashboard'); ?>
                    <span class="badge badge-beta" style="font-size: 11px; vertical-align: middle; margin-left: 8px; background: #8b5cf6; color: white; padding: 2px 8px; border-radius: 4px;">BETA</span>
                </h2>
                <p class="description" style="margin-bottom: 16px;">
                    <?php esc_html_e('Instinct provides priority-based context injection for AI conversations. Memories are scored 0-100 and injected based on relevance.', 'raw-wire-dashboard'); ?>
                    <a href="https://github.com/roller11/context-engine" target="_blank" rel="noopener"><?php esc_html_e('Learn More', 'raw-wire-dashboard'); ?></a>
                </p>

                <form method="post" action="options.php" id="instinct-settings-form">
                    <?php settings_fields('rawwire_instinct_group'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Enable Instinct', 'raw-wire-dashboard'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="rawwire_instinct_settings[enabled]" value="1"
                                        <?php checked($instinct_settings['enabled'] ?? false); ?>>
                                    <?php esc_html_e('Inject context from Instinct memory store', 'raw-wire-dashboard'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="instinct_host"><?php esc_html_e('Service Host', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <input type="text" name="rawwire_instinct_settings[host]" id="instinct_host"
                                    value="<?php echo esc_attr($instinct_settings['host'] ?? '127.0.0.1'); ?>"
                                    class="regular-text" placeholder="127.0.0.1">
                                <span style="margin: 0 8px;">:</span>
                                <input type="number" name="rawwire_instinct_settings[port]" id="instinct_port"
                                    value="<?php echo esc_attr($instinct_settings['port'] ?? 8080); ?>"
                                    class="small-text" min="1" max="65535" placeholder="8080">
                                <p class="description">
                                    <?php esc_html_e('Instinct API endpoint. Start the service with: python -m instinct.api', 'raw-wire-dashboard'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Context Injection', 'raw-wire-dashboard'); ?></th>
                            <td>
                                <fieldset>
                                    <label style="display: block; margin-bottom: 8px;">
                                        <input type="checkbox" name="rawwire_instinct_settings[auto_inject]" value="1"
                                            <?php checked($instinct_settings['auto_inject'] ?? true); ?>>
                                        <?php esc_html_e('Auto-inject relevant context into chat prompts', 'raw-wire-dashboard'); ?>
                                    </label>
                                    <label style="display: block; margin-bottom: 8px;">
                                        <input type="checkbox" name="rawwire_instinct_settings[include_mandatory]" value="1"
                                            <?php checked($instinct_settings['include_mandatory'] ?? true); ?>>
                                        <?php esc_html_e('Always include mandatory context (score ≥ 95)', 'raw-wire-dashboard'); ?>
                                    </label>
                                </fieldset>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="instinct_min_importance"><?php esc_html_e('Min Importance', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <input type="range" name="rawwire_instinct_settings[min_importance]" id="instinct_min_importance"
                                    value="<?php echo esc_attr($instinct_settings['min_importance'] ?? 30); ?>"
                                    min="0" max="100" step="5" style="width: 200px;">
                                <span id="instinct_min_importance_value"><?php echo esc_html($instinct_settings['min_importance'] ?? 30); ?></span>
                                <p class="description"><?php esc_html_e('Minimum importance score (0-100) for context to be included', 'raw-wire-dashboard'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="instinct_max_tokens"><?php esc_html_e('Max Context Tokens', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <input type="number" name="rawwire_instinct_settings[max_tokens]" id="instinct_max_tokens"
                                    value="<?php echo esc_attr($instinct_settings['max_tokens'] ?? 8000); ?>"
                                    min="100" max="100000" step="100" class="regular-text">
                                <p class="description"><?php esc_html_e('Maximum tokens of context to inject per request', 'raw-wire-dashboard'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" class="button rawwire-btn-dark"><?php esc_html_e('Save Instinct Settings', 'raw-wire-dashboard'); ?></button>
                        <button type="button" class="button rawwire-btn-dark-secondary" id="test-instinct-connection"><?php esc_html_e('Test Connection', 'raw-wire-dashboard'); ?></button>
                    </p>
                </form>
                <div id="instinct-test-result" class="notice" style="display: none;"></div>

                <!-- Stats -->
                <div id="instinct-stats" class="rawwire-stats-card" style="display: none; margin-top: 16px; padding: 16px; background: #f9f9f9; border-radius: 8px;">
                    <h4 style="margin-top: 0;"><?php esc_html_e('Memory Store Stats', 'raw-wire-dashboard'); ?></h4>
                    <div class="stats-grid" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px;">
                        <div><strong id="stat-total">-</strong><br><small><?php esc_html_e('Total', 'raw-wire-dashboard'); ?></small></div>
                        <div><strong id="stat-mandatory">-</strong><br><small><?php esc_html_e('Mandatory', 'raw-wire-dashboard'); ?></small></div>
                        <div><strong id="stat-high">-</strong><br><small><?php esc_html_e('High Priority', 'raw-wire-dashboard'); ?></small></div>
                        <div><strong id="stat-avg">-</strong><br><small><?php esc_html_e('Avg Score', 'raw-wire-dashboard'); ?></small></div>
                    </div>
                </div>
            </div>
        </div>
    <?php
        $this->render_instinct_tab_scripts();
    }

    /**
     * Render Investigation hub tab.
     */
    private function render_investigation_tab()
    {
        $settings = get_option('rawwire_party_investigator_settings', $this->get_default_party_investigator_settings());
        $pipeline_mode = $settings['pipeline_mode'] ?? 'veniceclaw';
        $subtab = isset($_GET['investigation_subtab']) ? sanitize_key($_GET['investigation_subtab']) : 'perplexity_direct';
        if (!in_array($subtab, ['perplexity_direct', 'veniceclaw'], true)) {
            $subtab = 'perplexity_direct';
        }

        $subtabs = [
            'perplexity_direct' => ['label' => __('Perplexity Direct', 'raw-wire-dashboard'), 'icon' => 'dashicons-admin-site-alt3'],
            'veniceclaw' => ['label' => __('VeniceClaw', 'raw-wire-dashboard'), 'icon' => 'dashicons-networking'],
        ];

        $base_url = admin_url('admin.php?page=rawwire-ai-settings&tab=investigation');
    ?>
        <div class="rawwire-ai-settings-panel">
            <div class="rawwire-settings-section active" style="border-left: 4px solid var(--rw-brand-gold, #f4b41a);">
                <h2 style="margin-top: 0; display: flex; align-items: center; gap: 8px;">
                    <span class="dashicons dashicons-search" style="color: var(--rw-brand-gold, #f4b41a);"></span>
                    <?php esc_html_e('Investigation Pipelines', 'raw-wire-dashboard'); ?>
                </h2>
                <p class="description" style="margin-bottom: 16px;">
                    <?php esc_html_e('Choose the active research lane here. Provider and server configuration lives in the dedicated tabs; workflow behavior stays on the Lead Generator page.', 'raw-wire-dashboard'); ?>
                </p>

                <div class="rawwire-investigation-subtabs">
                    <?php foreach ($subtabs as $subtab_id => $meta): ?>
                        <a href="<?php echo esc_url(add_query_arg('investigation_subtab', $subtab_id, $base_url)); ?>" class="<?php echo $subtab === $subtab_id ? 'is-active' : ''; ?>">
                            <span class="dashicons <?php echo esc_attr($meta['icon']); ?>"></span>
                            <?php echo esc_html($meta['label']); ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <form method="post" action="options.php">
                    <?php settings_fields('rawwire_pi_group'); ?>
                    <div class="rawwire-pipeline-card-grid">
                        <label class="rawwire-pipeline-card <?php echo $pipeline_mode === 'perplexity_direct' ? 'is-active' : ''; ?>">
                            <h3>
                                <span><?php esc_html_e('Perplexity Direct', 'raw-wire-dashboard'); ?></span>
                                <span class="rawwire-pipeline-pill <?php echo $pipeline_mode === 'perplexity_direct' ? 'is-live' : ''; ?>"><?php echo $pipeline_mode === 'perplexity_direct' ? esc_html__('Active', 'raw-wire-dashboard') : esc_html__('Available', 'raw-wire-dashboard'); ?></span>
                            </h3>
                            <p><?php esc_html_e('Native Perplexity research with citation-aware dossier generation and a lighter evidence gate.', 'raw-wire-dashboard'); ?></p>
                            <p><label><input type="radio" name="rawwire_party_investigator_settings[pipeline_mode]" value="perplexity_direct" <?php checked($pipeline_mode, 'perplexity_direct'); ?>> <?php esc_html_e('Select this pipeline', 'raw-wire-dashboard'); ?></label></p>
                        </label>

                        <label class="rawwire-pipeline-card <?php echo $pipeline_mode === 'veniceclaw' ? 'is-active' : ''; ?>">
                            <h3>
                                <span><?php esc_html_e('VeniceClaw', 'raw-wire-dashboard'); ?></span>
                                <span class="rawwire-pipeline-pill <?php echo $pipeline_mode === 'veniceclaw' ? 'is-live' : ''; ?>"><?php echo $pipeline_mode === 'veniceclaw' ? esc_html__('Active', 'raw-wire-dashboard') : esc_html__('Available', 'raw-wire-dashboard'); ?></span>
                            </h3>
                            <p><?php esc_html_e('OpenClaw browser-agent runtime backed by the Venice lane for multi-pass, browser-verified investigation.', 'raw-wire-dashboard'); ?></p>
                            <p><label><input type="radio" name="rawwire_party_investigator_settings[pipeline_mode]" value="veniceclaw" <?php checked($pipeline_mode, 'veniceclaw'); ?>> <?php esc_html_e('Select this pipeline', 'raw-wire-dashboard'); ?></label></p>
                        </label>
                    </div>

                    <p class="submit" style="margin-bottom: 0;">
                        <button type="submit" class="button rawwire-btn-dark"><?php esc_html_e('Save Active Pipeline', 'raw-wire-dashboard'); ?></button>
                    </p>
                </form>

                <?php if ($subtab === 'perplexity_direct'): ?>
                    <div class="rawwire-provider-callout">
                        <div class="rawwire-provider-metric">
                            <span><?php esc_html_e('Pipeline Focus', 'raw-wire-dashboard'); ?></span>
                            <strong><?php esc_html_e('Native Research', 'raw-wire-dashboard'); ?></strong>
                        </div>
                        <div class="rawwire-provider-metric">
                            <span><?php esc_html_e('Configuration Home', 'raw-wire-dashboard'); ?></span>
                            <strong><?php esc_html_e('Perplexity Tab', 'raw-wire-dashboard'); ?></strong>
                        </div>
                        <div class="rawwire-provider-metric">
                            <span><?php esc_html_e('Best For', 'raw-wire-dashboard'); ?></span>
                            <strong><?php esc_html_e('Fast dossiers', 'raw-wire-dashboard'); ?></strong>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="rawwire-provider-callout">
                        <div class="rawwire-provider-metric">
                            <span><?php esc_html_e('Pipeline Focus', 'raw-wire-dashboard'); ?></span>
                            <strong><?php esc_html_e('Browser Agent', 'raw-wire-dashboard'); ?></strong>
                        </div>
                        <div class="rawwire-provider-metric">
                            <span><?php esc_html_e('Configuration Home', 'raw-wire-dashboard'); ?></span>
                            <strong><?php esc_html_e('OpenClaw Tab', 'raw-wire-dashboard'); ?></strong>
                        </div>
                        <div class="rawwire-provider-metric">
                            <span><?php esc_html_e('Best For', 'raw-wire-dashboard'); ?></span>
                            <strong><?php esc_html_e('Deep evidence', 'raw-wire-dashboard'); ?></strong>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php
    }

    /**
     * Render Perplexity provider tab.
     */
    private function render_perplexity_tab()
    {
        $settings = get_option('rawwire_perplexity_settings', $this->get_default_perplexity_settings());
        $cached_models = get_transient('rawwire_perplexity_models');
    ?>
        <div class="rawwire-ai-settings-panel">
            <div class="rawwire-settings-section active" style="border-left: 4px solid #14b8a6;">
                <h2 style="margin-top: 0; display: flex; align-items: center; gap: 8px;">
                    <span class="dashicons dashicons-admin-site-alt3" style="color: #14b8a6;"></span>
                    <?php esc_html_e('Perplexity Direct', 'raw-wire-dashboard'); ?>
                </h2>
                <p class="description" style="margin-bottom: 16px;">
                    <?php esc_html_e('Configure the direct Perplexity dossier lane used by the investigation pipeline when Perplexity Direct is active.', 'raw-wire-dashboard'); ?>
                </p>

                <form method="post" action="options.php">
                    <?php settings_fields('rawwire_perplexity_group'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="rawwire_perplexity_api_key"><?php esc_html_e('API Key', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <input type="password" name="rawwire_perplexity_settings[api_key]" id="rawwire_perplexity_api_key" value="<?php echo esc_attr($settings['api_key'] ?? ''); ?>" class="regular-text" autocomplete="off">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rawwire_perplexity_base_url"><?php esc_html_e('Base URL', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <input type="url" name="rawwire_perplexity_settings[base_url]" id="rawwire_perplexity_base_url" value="<?php echo esc_attr($settings['base_url'] ?? 'https://api.perplexity.ai'); ?>" class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rawwire_perplexity_model"><?php esc_html_e('Model', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <?php if (!empty($cached_models) && is_array($cached_models)): ?>
                                    <select name="rawwire_perplexity_settings[model]" id="rawwire_perplexity_model" style="min-width: 320px;">
                                        <?php foreach ($cached_models as $model_id => $model_data): ?>
                                            <option value="<?php echo esc_attr($model_id); ?>" <?php selected(($settings['model'] ?? 'sonar'), $model_id); ?>>
                                                <?php echo esc_html(is_array($model_data) ? ($model_data['id'] ?? $model_id) : $model_id); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <input type="text" name="rawwire_perplexity_settings[model]" id="rawwire_perplexity_model" value="<?php echo esc_attr($settings['model'] ?? 'sonar'); ?>" class="regular-text">
                                <?php endif; ?>
                                <button type="button" id="refresh_perplexity_models" class="button button-secondary" style="margin-left: 10px;">
                                    <?php esc_html_e('Refresh Models', 'raw-wire-dashboard'); ?>
                                </button>
                                <span id="refresh_perplexity_models_status" style="margin-left: 10px; display: none;"></span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rawwire_perplexity_temperature"><?php esc_html_e('Temperature', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <input type="range" name="rawwire_perplexity_settings[temperature]" id="rawwire_perplexity_temperature" value="<?php echo esc_attr($settings['temperature'] ?? 0.2); ?>" min="0" max="1" step="0.05" style="width: 220px;">
                                <span id="rawwire_perplexity_temperature_value"><?php echo esc_html($settings['temperature'] ?? 0.2); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rawwire_perplexity_max_tokens"><?php esc_html_e('Max Tokens', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <input type="number" name="rawwire_perplexity_settings[max_tokens]" id="rawwire_perplexity_max_tokens" value="<?php echo esc_attr($settings['max_tokens'] ?? 8000); ?>" min="1000" max="128000" step="500" class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rawwire_perplexity_top_p"><?php esc_html_e('Top P', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <input type="range" name="rawwire_perplexity_settings[top_p]" id="rawwire_perplexity_top_p" value="<?php echo esc_attr($settings['top_p'] ?? 0.9); ?>" min="0" max="1" step="0.05" style="width: 220px;">
                                <span id="rawwire_perplexity_top_p_value"><?php echo esc_html($settings['top_p'] ?? 0.9); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rawwire_perplexity_reasoning_effort"><?php esc_html_e('Reasoning Effort', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <select name="rawwire_perplexity_settings[reasoning_effort]" id="rawwire_perplexity_reasoning_effort">
                                    <option value="off" <?php selected(($settings['reasoning_effort'] ?? 'off'), 'off'); ?>><?php esc_html_e('Off', 'raw-wire-dashboard'); ?></option>
                                    <option value="minimal" <?php selected(($settings['reasoning_effort'] ?? 'off'), 'minimal'); ?>><?php esc_html_e('Minimal', 'raw-wire-dashboard'); ?></option>
                                    <option value="low" <?php selected(($settings['reasoning_effort'] ?? 'off'), 'low'); ?>><?php esc_html_e('Low', 'raw-wire-dashboard'); ?></option>
                                    <option value="medium" <?php selected(($settings['reasoning_effort'] ?? 'off'), 'medium'); ?>><?php esc_html_e('Medium', 'raw-wire-dashboard'); ?></option>
                                    <option value="high" <?php selected(($settings['reasoning_effort'] ?? 'off'), 'high'); ?>><?php esc_html_e('High', 'raw-wire-dashboard'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rawwire_perplexity_search_mode"><?php esc_html_e('Search Mode', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <select name="rawwire_perplexity_settings[search_mode]" id="rawwire_perplexity_search_mode">
                                    <option value="web" <?php selected(($settings['search_mode'] ?? 'web'), 'web'); ?>><?php esc_html_e('Web', 'raw-wire-dashboard'); ?></option>
                                    <option value="academic" <?php selected(($settings['search_mode'] ?? 'web'), 'academic'); ?>><?php esc_html_e('Academic', 'raw-wire-dashboard'); ?></option>
                                    <option value="sec" <?php selected(($settings['search_mode'] ?? 'web'), 'sec'); ?>><?php esc_html_e('SEC', 'raw-wire-dashboard'); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e('Applies to Perplexity native search when direct research is enabled.', 'raw-wire-dashboard'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Search Features', 'raw-wire-dashboard'); ?></th>
                            <td>
                                <label style="display:block; margin-bottom: 8px;">
                                    <input type="checkbox" name="rawwire_perplexity_settings[disable_search]" value="1" <?php checked(!empty($settings['disable_search'])); ?>>
                                    <?php esc_html_e('Disable native web search', 'raw-wire-dashboard'); ?>
                                </label>
                                <label style="display:block; margin-bottom: 8px;">
                                    <input type="checkbox" name="rawwire_perplexity_settings[enable_search_classifier]" value="1" <?php checked(!empty($settings['enable_search_classifier'])); ?>>
                                    <?php esc_html_e('Auto-detect when search is needed', 'raw-wire-dashboard'); ?>
                                </label>
                                <label style="display:block; margin-bottom: 8px;">
                                    <input type="checkbox" name="rawwire_perplexity_settings[return_related_questions]" value="1" <?php checked(!empty($settings['return_related_questions'])); ?>>
                                    <?php esc_html_e('Return related follow-up questions', 'raw-wire-dashboard'); ?>
                                </label>
                                <label style="display:block; margin-bottom: 8px;">
                                    <input type="checkbox" name="rawwire_perplexity_settings[return_images]" value="1" <?php checked(!empty($settings['return_images'])); ?>>
                                    <?php esc_html_e('Return image results', 'raw-wire-dashboard'); ?>
                                </label>
                            </td>
                        </tr>
                    </table>
                    <p class="submit"><button type="submit" class="button rawwire-btn-dark"><?php esc_html_e('Save Perplexity Settings', 'raw-wire-dashboard'); ?></button></p>
                </form>
            </div>
        </div>
        <script>
            jQuery(function($) {
                $('#rawwire_perplexity_temperature').on('input', function() {
                    $('#rawwire_perplexity_temperature_value').text($(this).val());
                });
                $('#rawwire_perplexity_top_p').on('input', function() {
                    $('#rawwire_perplexity_top_p_value').text($(this).val());
                });
                $('#refresh_perplexity_models').on('click', function() {
                    var $btn = $(this);
                    var $status = $('#refresh_perplexity_models_status');
                    $btn.prop('disabled', true);
                    $status.show().text('Refreshing...');

                    $.post(ajaxurl, {
                        action: 'rawwire_refresh_perplexity_models',
                        nonce: '<?php echo wp_create_nonce('rawwire_ai_settings_nonce'); ?>'
                    }, function(response) {
                        if (response.success) {
                            $status.text('Models refreshed. Reloading...');
                            setTimeout(function() {
                                location.reload();
                            }, 800);
                        } else {
                            $status.text((response && response.data && response.data.message) ? response.data.message : 'Failed');
                        }
                    }).fail(function(xhr) {
                        var message = 'Request failed';
                        if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                            message = xhr.responseJSON.data.message;
                        } else if (xhr && xhr.status) {
                            message = 'Request failed (' + xhr.status + ')';
                        }
                        $status.text(message);
                    }).always(function() {
                        $btn.prop('disabled', false);
                    });
                });
            });
        </script>
    <?php
    }

    /**
     * Render OpenAI provider tab.
     */
    private function render_openai_tab()
    {
        $settings = get_option('rawwire_openai_settings', $this->get_default_openai_settings());
        $cached_models = get_transient('rawwire_openai_models');
    ?>
        <div class="rawwire-ai-settings-panel">
            <div class="rawwire-settings-section active" style="border-left: 4px solid #3b82f6;">
                <h2 style="margin-top: 0; display: flex; align-items: center; gap: 8px;">
                    <span class="dashicons dashicons-cloud" style="color: #3b82f6;"></span>
                    <?php esc_html_e('OpenAI Provider', 'raw-wire-dashboard'); ?>
                </h2>
                <p class="description" style="margin-bottom: 16px;">
                    <?php esc_html_e('Manage the OpenAI provider endpoint, API key, model default, and model behavior settings.', 'raw-wire-dashboard'); ?>
                </p>

                <form method="post" action="options.php">
                    <?php settings_fields('rawwire_openai_group'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="rawwire_openai_base_url"><?php esc_html_e('Base URL', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <input type="url" name="rawwire_openai_settings[base_url]" id="rawwire_openai_base_url" value="<?php echo esc_attr($settings['base_url'] ?? 'https://api.openai.com/v1'); ?>" class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rawwire_openai_openclaw_api_key"><?php esc_html_e('API Key', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <input type="password" name="rawwire_openai_settings[openclaw_api_key]" id="rawwire_openai_openclaw_api_key" value="<?php echo esc_attr($settings['openclaw_api_key'] ?? ''); ?>" class="regular-text" autocomplete="off">
                                <p class="description"><?php esc_html_e('Stored as the OpenAI provider credential for current OpenAI-compatible requests.', 'raw-wire-dashboard'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rawwire_openai_model"><?php esc_html_e('Model', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <?php if (!empty($cached_models) && is_array($cached_models)): ?>
                                    <select name="rawwire_openai_settings[model]" id="rawwire_openai_model" style="min-width: 320px;">
                                        <?php foreach ($cached_models as $model_id => $model_data): ?>
                                            <option value="<?php echo esc_attr($model_id); ?>" <?php selected(($settings['model'] ?? 'gpt-4o-mini'), $model_id); ?>>
                                                <?php echo esc_html(is_array($model_data) ? ($model_data['id'] ?? $model_id) : $model_id); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <input type="text" name="rawwire_openai_settings[model]" id="rawwire_openai_model" value="<?php echo esc_attr($settings['model'] ?? 'gpt-4o-mini'); ?>" class="regular-text">
                                <?php endif; ?>
                                <button type="button" id="refresh_openai_models" class="button button-secondary" style="margin-left: 10px;">
                                    <?php esc_html_e('Refresh Models', 'raw-wire-dashboard'); ?>
                                </button>
                                <span id="refresh_openai_models_status" style="margin-left: 10px; display: none;"></span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rawwire_openai_temperature"><?php esc_html_e('Temperature', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <input type="range" name="rawwire_openai_settings[temperature]" id="rawwire_openai_temperature" value="<?php echo esc_attr($settings['temperature'] ?? 0.3); ?>" min="0" max="1" step="0.05" style="width: 220px;">
                                <span id="rawwire_openai_temperature_value"><?php echo esc_html($settings['temperature'] ?? 0.3); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rawwire_openai_max_tokens"><?php esc_html_e('Max Tokens', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <input type="number" name="rawwire_openai_settings[max_tokens]" id="rawwire_openai_max_tokens" value="<?php echo esc_attr($settings['max_tokens'] ?? 4000); ?>" min="100" max="128000" step="100" class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rawwire_openai_top_p"><?php esc_html_e('Top P', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <input type="range" name="rawwire_openai_settings[top_p]" id="rawwire_openai_top_p" value="<?php echo esc_attr($settings['top_p'] ?? 1.0); ?>" min="0" max="1" step="0.05" style="width: 220px;">
                                <span id="rawwire_openai_top_p_value"><?php echo esc_html($settings['top_p'] ?? 1.0); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rawwire_openai_reasoning_effort"><?php esc_html_e('Reasoning Effort', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <select name="rawwire_openai_settings[reasoning_effort]" id="rawwire_openai_reasoning_effort">
                                    <option value="off" <?php selected(($settings['reasoning_effort'] ?? 'off'), 'off'); ?>><?php esc_html_e('Off', 'raw-wire-dashboard'); ?></option>
                                    <option value="minimal" <?php selected(($settings['reasoning_effort'] ?? 'off'), 'minimal'); ?>><?php esc_html_e('Minimal', 'raw-wire-dashboard'); ?></option>
                                    <option value="low" <?php selected(($settings['reasoning_effort'] ?? 'off'), 'low'); ?>><?php esc_html_e('Low', 'raw-wire-dashboard'); ?></option>
                                    <option value="medium" <?php selected(($settings['reasoning_effort'] ?? 'off'), 'medium'); ?>><?php esc_html_e('Medium', 'raw-wire-dashboard'); ?></option>
                                    <option value="high" <?php selected(($settings['reasoning_effort'] ?? 'off'), 'high'); ?>><?php esc_html_e('High', 'raw-wire-dashboard'); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e('Saved for OpenAI-compatible runtimes that expose reasoning controls. Unsupported endpoints will ignore it.', 'raw-wire-dashboard'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rawwire_openai_tool_choice"><?php esc_html_e('Tool Choice', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <select name="rawwire_openai_settings[tool_choice]" id="rawwire_openai_tool_choice">
                                    <option value="auto" <?php selected(($settings['tool_choice'] ?? 'auto'), 'auto'); ?>><?php esc_html_e('Auto', 'raw-wire-dashboard'); ?></option>
                                    <option value="required" <?php selected(($settings['tool_choice'] ?? 'auto'), 'required'); ?>><?php esc_html_e('Required', 'raw-wire-dashboard'); ?></option>
                                    <option value="none" <?php selected(($settings['tool_choice'] ?? 'auto'), 'none'); ?>><?php esc_html_e('None', 'raw-wire-dashboard'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Tool Availability', 'raw-wire-dashboard'); ?></th>
                            <td>
                                <label style="display:block; margin-bottom: 8px;">
                                    <input type="checkbox" name="rawwire_openai_settings[allow_tool_calls]" value="1" <?php checked(!empty($settings['allow_tool_calls'])); ?>>
                                    <?php esc_html_e('Allow tool calls on tool-capable requests', 'raw-wire-dashboard'); ?>
                                </label>
                                <label style="display:block; margin-bottom: 8px;">
                                    <input type="checkbox" name="rawwire_openai_settings[parallel_tool_calls]" value="1" <?php checked(!empty($settings['parallel_tool_calls'])); ?>>
                                    <?php esc_html_e('Allow parallel tool calls', 'raw-wire-dashboard'); ?>
                                </label>
                                <label style="display:block; margin-bottom: 8px;">
                                    <input type="checkbox" name="rawwire_openai_settings[allow_mcp_tools]" value="1" <?php checked(!empty($settings['allow_mcp_tools'])); ?>>
                                    <?php esc_html_e('Expose MCP tools when supplied by the caller', 'raw-wire-dashboard'); ?>
                                </label>
                                <label style="display:block; margin-bottom: 8px;">
                                    <input type="checkbox" name="rawwire_openai_settings[allow_openclaw_tools]" value="1" <?php checked(!empty($settings['allow_openclaw_tools'])); ?>>
                                    <?php esc_html_e('Expose OpenClaw/browser tools when supplied by the caller', 'raw-wire-dashboard'); ?>
                                </label>
                            </td>
                        </tr>
                    </table>
                    <p class="submit"><button type="submit" class="button rawwire-btn-dark"><?php esc_html_e('Save OpenAI Settings', 'raw-wire-dashboard'); ?></button></p>
                </form>
            </div>
        </div>
        <script>
            jQuery(function($) {
                $('#rawwire_openai_temperature').on('input', function() {
                    $('#rawwire_openai_temperature_value').text($(this).val());
                });
                $('#rawwire_openai_top_p').on('input', function() {
                    $('#rawwire_openai_top_p_value').text($(this).val());
                });
                $('#refresh_openai_models').on('click', function() {
                    var $btn = $(this);
                    var $status = $('#refresh_openai_models_status');
                    $btn.prop('disabled', true);
                    $status.show().text('Refreshing...');

                    $.post(ajaxurl, {
                        action: 'rawwire_refresh_openai_models',
                        nonce: '<?php echo wp_create_nonce('rawwire_ai_settings_nonce'); ?>'
                    }, function(response) {
                        if (response.success) {
                            $status.text('Models refreshed. Reloading...');
                            setTimeout(function() {
                                location.reload();
                            }, 800);
                        } else {
                            $status.text((response && response.data && response.data.message) ? response.data.message : 'Failed');
                        }
                    }).fail(function(xhr) {
                        var message = 'Request failed';
                        if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                            message = xhr.responseJSON.data.message;
                        } else if (xhr && xhr.status) {
                            message = 'Request failed (' + xhr.status + ')';
                        }
                        $status.text(message);
                    }).always(function() {
                        $btn.prop('disabled', false);
                    });
                });
            });
        </script>
    <?php
    }

    /**
     * Render Instinct tab scripts
     */
    private function render_instinct_tab_scripts()
    {
    ?>
        <style>
            .rawwire-ai-settings-panel {
                max-width: 900px;
            }

            .rawwire-settings-section {
                background: var(--rw-bg-surface, #fff);
                border: 1px solid var(--rw-border-default, #ccd0d4);
                border-radius: 8px;
                padding: 20px;
                margin-bottom: 20px;
            }
        </style>
        <script>
            jQuery(document).ready(function($) {
                // Importance slider
                $('#instinct_min_importance').on('input', function() {
                    $('#instinct_min_importance_value').text($(this).val());
                });
                // Test Instinct connection
                $('#test-instinct-connection').on('click', function() {
                    var $btn = $(this);
                    var $result = $('#instinct-test-result');
                    var $stats = $('#instinct-stats');
                    var host = $('#instinct_host').val() || '127.0.0.1';
                    var port = $('#instinct_port').val() || 8080;
                    $btn.prop('disabled', true).text('Testing...');
                    $.ajax({
                        url: 'http://' + host + ':' + port + '/health',
                        method: 'GET',
                        timeout: 5000,
                        success: function(response) {
                            $result.show().removeClass('notice-error').addClass('notice-success')
                                .html('<p>✓ Connected to Instinct API</p>');
                            // Try to get stats
                            $.ajax({
                                url: 'http://' + host + ':' + port + '/stats',
                                method: 'GET',
                                success: function(stats) {
                                    $stats.show();
                                    $('#stat-total').text(stats.total_memories || 0);
                                    $('#stat-mandatory').text(stats.mandatory || 0);
                                    $('#stat-high').text(stats.high_priority || 0);
                                    $('#stat-avg').text((stats.avg_score || 0).toFixed(1));
                                }
                            });
                        },
                        error: function() {
                            $result.show().removeClass('notice-success').addClass('notice-error')
                                .html('<p>✗ Cannot connect to Instinct at ' + host + ':' + port + '</p>');
                            $stats.hide();
                        }
                    }).always(function() {
                        $btn.prop('disabled', false).text('Test Connection');
                    });
                });
            });
        </script>
    <?php
    }

    /**
     * Render Engine Extensions tab content
     */
    private function render_engine_tab()
    {
        $ai = rawwire_ai();
        $status = $ai->get_status();
        $settings = get_option('rawwire_ai_adapter_settings', []);
        $engine_extensions = get_option('rawwire_engine_extensions', $this->get_default_engine_extensions());
        $models = [];
        if (!empty($settings['default_env_id'])) {
            $models = $ai->get_models_for_env($settings['default_env_id']);
        }
    ?>
        <div class="rawwire-ai-settings-panel">
            <!-- AI Engine Status -->
            <div class="rawwire-ai-status-card">
                <h3><?php esc_html_e('AI Engine Status', 'raw-wire-dashboard'); ?></h3>
                <div class="status-grid">
                    <div class="status-item">
                        <span class="status-label"><?php esc_html_e('Status', 'raw-wire-dashboard'); ?></span>
                        <span class="status-value <?php echo $status['available'] ? 'status-active' : 'status-inactive'; ?>">
                            <?php echo $status['available']
                                ? '<span class="dashicons dashicons-yes-alt"></span> ' . esc_html__('Active', 'raw-wire-dashboard')
                                : '<span class="dashicons dashicons-warning"></span> ' . esc_html__('Not Installed', 'raw-wire-dashboard');
                            ?>
                        </span>
                    </div>
                    <?php if ($status['available']): ?>
                        <div class="status-item">
                            <span class="status-label"><?php esc_html_e('Version', 'raw-wire-dashboard'); ?></span>
                            <span class="status-value"><?php echo esc_html($status['version']); ?></span>
                        </div>
                        <div class="status-item">
                            <span class="status-label"><?php esc_html_e('Environments', 'raw-wire-dashboard'); ?></span>
                            <span class="status-value"><?php echo count($status['environments']); ?> configured</span>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if (!$status['available']): ?>
                    <div class="install-notice">
                        <p><?php echo wp_kses_post(rawwire_ai()->get_unavailable_message()); ?></p>
                        <a href="<?php echo esc_url(admin_url('plugin-install.php?s=ai+engine&tab=search&type=term')); ?>" class="button rawwire-btn-dark">
                            <?php esc_html_e('Install AI Engine', 'raw-wire-dashboard'); ?>
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($status['available']): ?>
                <!-- Custom Engine Extensions -->
                <div class="rawwire-settings-section">
                    <h3><?php esc_html_e('Custom Engine Extensions', 'raw-wire-dashboard'); ?></h3>
                    <p class="description"><?php esc_html_e('Raw Wire extends AI Engine with additional providers via filter hooks.', 'raw-wire-dashboard'); ?></p>

                    <form method="post" action="options.php" id="engine-extensions-form">
                        <?php settings_fields('rawwire_engine_group'); ?>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php esc_html_e('Ollama (Local LLM)', 'raw-wire-dashboard'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="rawwire_engine_extensions[ollama_enabled]" value="1"
                                            <?php checked($engine_extensions['ollama_enabled'] ?? true); ?>>
                                        <?php esc_html_e('Enable Ollama engine extension', 'raw-wire-dashboard'); ?>
                                    </label>
                                    <p class="description"><?php esc_html_e('Adds Ollama as an AI Engine provider for local models.', 'raw-wire-dashboard'); ?></p>
                                </td>
                            </tr>
                            <tr class="ollama-option" <?php echo empty($engine_extensions['ollama_enabled']) ? 'style="display:none;"' : ''; ?>>
                                <th scope="row"><label for="ollama_endpoint"><?php esc_html_e('Ollama Endpoint', 'raw-wire-dashboard'); ?></label></th>
                                <td>
                                    <input type="url" name="rawwire_engine_extensions[ollama_endpoint]" id="ollama_endpoint"
                                        value="<?php echo esc_attr($engine_extensions['ollama_endpoint'] ?? 'http://ollama:11434'); ?>"
                                        class="regular-text" placeholder="http://ollama:11434">
                                </td>
                            </tr>
                            <tr class="ollama-option" <?php echo empty($engine_extensions['ollama_enabled']) ? 'style="display:none;"' : ''; ?>>
                                <th scope="row"><?php esc_html_e('Dynamic Models', 'raw-wire-dashboard'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="rawwire_engine_extensions[ollama_dynamic_models]" value="1"
                                            <?php checked($engine_extensions['ollama_dynamic_models'] ?? true); ?>>
                                        <?php esc_html_e('Auto-fetch available models from Ollama', 'raw-wire-dashboard'); ?>
                                    </label>
                                    <button type="button" class="button button-small" id="refresh-ollama-models" style="margin-left: 10px;">
                                        <?php esc_html_e('Refresh Now', 'raw-wire-dashboard'); ?>
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Groq (Fast Inference)', 'raw-wire-dashboard'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="rawwire_engine_extensions[groq_enabled]" value="1"
                                            <?php checked($engine_extensions['groq_enabled'] ?? true); ?>>
                                        <?php esc_html_e('Enable Groq engine extension', 'raw-wire-dashboard'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Chatbot Auto-Sync', 'raw-wire-dashboard'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="rawwire_engine_extensions[chatbot_auto_sync]" value="1"
                                            <?php checked($engine_extensions['chatbot_auto_sync'] ?? false); ?>>
                                        <?php esc_html_e('Auto-sync Raw Wire Assistant chatbot settings', 'raw-wire-dashboard'); ?>
                                    </label>
                                </td>
                            </tr>
                        </table>
                        <p class="submit">
                            <button type="submit" class="button rawwire-btn-dark"><?php esc_html_e('Save Engine Extensions', 'raw-wire-dashboard'); ?></button>
                        </p>
                    </form>
                </div>

                <!-- Quick Test -->
                <div class="rawwire-settings-section">
                    <h3><?php esc_html_e('Quick AI Test', 'raw-wire-dashboard'); ?></h3>
                    <div class="ai-test-panel">
                        <textarea id="ai-test-prompt" rows="3" placeholder="<?php esc_attr_e('Enter a test prompt...', 'raw-wire-dashboard'); ?>" style="width: 100%;"></textarea>
                        <div class="test-actions" style="margin: 10px 0;">
                            <button type="button" class="button" id="run-ai-test"><?php esc_html_e('Send Test Query', 'raw-wire-dashboard'); ?></button>
                        </div>
                        <div id="ai-test-output" class="ai-output-box" style="display: none; background: #f6f7f7; padding: 15px; border-radius: 4px; white-space: pre-wrap; font-family: monospace;"></div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php
        $this->render_engine_tab_styles();
    }

    /**
     * Render Engine tab styles
     */
    private function render_engine_tab_styles()
    {
    ?>
        <style>
            .rawwire-ai-settings-panel {
                max-width: 900px;
            }

            .rawwire-ai-status-card {
                background: var(--rw-bg-surface, #fff);
                border: 1px solid var(--rw-border-default, #ccd0d4);
                border-radius: 8px;
                padding: 20px;
                margin-bottom: 20px;
            }

            .rawwire-ai-status-card h3 {
                margin-top: 0;
                border-bottom: 1px solid #eee;
                padding-bottom: 10px;
            }

            .status-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 15px;
            }

            .status-item {
                display: flex;
                flex-direction: column;
            }

            .status-label {
                font-weight: 600;
                color: #666;
                font-size: 12px;
                text-transform: uppercase;
                margin-bottom: 5px;
            }

            .status-active {
                color: var(--rw-brand-gold, #f4b41a);
            }

            .status-inactive {
                color: #dc3232;
            }

            .install-notice {
                margin-top: 20px;
                padding: 15px;
                background: #fff8e5;
                border-left: 4px solid #ffb900;
            }

            .rawwire-settings-section {
                background: var(--rw-bg-surface, #fff);
                border: 1px solid var(--rw-border-default, #ccd0d4);
                border-radius: 8px;
                padding: 20px;
                margin-bottom: 20px;
            }
        </style>
        <script>
            jQuery(document).ready(function($) {
                // Toggle Ollama options
                $('input[name="rawwire_engine_extensions[ollama_enabled]"]').on('change', function() {
                    $('.ollama-option').toggle($(this).is(':checked'));
                });
                // Refresh Ollama models
                $('#refresh-ollama-models').on('click', function() {
                    var $btn = $(this);
                    $btn.prop('disabled', true).text('Refreshing...');
                    $.post(ajaxurl, {
                        action: 'rawwire_refresh_ollama_models',
                        nonce: '<?php echo wp_create_nonce('rawwire_ai_settings_nonce'); ?>'
                    }, function(response) {
                        alert(response.success ? 'Models refreshed!' : 'Failed to refresh');
                    }).always(function() {
                        $btn.prop('disabled', false).text('Refresh Now');
                    });
                });
                // Quick AI test
                $('#run-ai-test').on('click', function() {
                    var $btn = $(this);
                    var $output = $('#ai-test-output');
                    var prompt = $('#ai-test-prompt').val();
                    if (!prompt) return;
                    $btn.prop('disabled', true).text('Sending...');
                    $output.show().text('Processing...');
                    $.post(ajaxurl, {
                        action: 'rawwire_ai_test_connection',
                        prompt: prompt,
                        nonce: '<?php echo wp_create_nonce('rawwire_ai_settings_nonce'); ?>'
                    }, function(response) {
                        $output.text(response.success ? response.data : 'Error: ' + (response.data || 'Unknown'));
                    }).always(function() {
                        $btn.prop('disabled', false).text('Send Test Query');
                    });
                });
            });
        </script>
    <?php
    }

    /**
     * Render OpenClaw Gateway tab content
     */
    private function render_openclaw_tab()
    {
        $oc_settings = get_option('rawwire_openclaw_settings', $this->get_default_openclaw_settings());
        $venice_settings = get_option('rawwire_venice_settings', []);
        $venice_key_set = !empty($venice_settings['api_key']);
        $provider = $oc_settings['provider'] ?? 'venice';
        $provider_label = [
            'venice' => 'Venice.ai',
            'openai' => 'OpenAI-Compatible',
            'ollama' => 'Ollama',
        ][$provider] ?? 'Venice.ai';
        $active_model = trim((string) ($oc_settings['model'] ?? ''));
        if ($provider === 'openai' && !empty($oc_settings['openai_model'])) {
            $active_model = (string) $oc_settings['openai_model'];
        }
        if ($provider === 'ollama' && !empty($oc_settings['ollama_model'])) {
            $active_model = (string) $oc_settings['ollama_model'];
        }
        if ($active_model === '') {
            $active_model = $venice_settings['model'] ?? 'olafangensan-glm-4.7-flash-heretic';
        }
        $last_prompt = get_option('rawwire_openclaw_last_prompt', []);
        $is_venice_direct = empty($oc_settings['host']) || $oc_settings['host'] === 'https://api.venice.ai/api/v1' || $oc_settings['host'] === 'http://localhost:18789';
    ?>
        <div class="rawwire-ai-settings-panel">

            <!-- Connection Mode Banner -->
            <div class="rawwire-settings-section active" style="border-left: 4px solid <?php echo $venice_key_set ? '#28a745' : '#dc3545'; ?>; padding: 16px 20px;">
                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span class="dashicons dashicons-<?php echo $venice_key_set ? 'yes-alt' : 'warning'; ?>"
                            style="color: <?php echo $venice_key_set ? '#28a745' : '#dc3545'; ?>; font-size: 28px; width: 28px; height: 28px;"></span>
                        <div>
                            <strong style="font-size: 14px;">
                                <?php if ($venice_key_set): ?>
                                    <?php esc_html_e('Venice Direct Mode — Connected', 'raw-wire-dashboard'); ?>
                                <?php else: ?>
                                    <?php esc_html_e('Venice API Key Missing', 'raw-wire-dashboard'); ?>
                                <?php endif; ?>
                            </strong>
                            <p class="description" style="margin: 2px 0 0;">
                                <?php if ($venice_key_set): ?>
                                    <?php esc_html_e('Routing through Venice.ai API with native web search + scraping. No Docker gateway needed.', 'raw-wire-dashboard'); ?>
                                <?php else: ?>
                                    <?php esc_html_e('Set your Venice API key on the Venice tab first. OpenClaw requires a valid API key.', 'raw-wire-dashboard'); ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span class="dashicons dashicons-cloud" style="color: var(--rw-brand-gold, #f4b41a);"></span>
                        <code style="font-size: 12px; background: #f0f0f0; padding: 4px 8px; border-radius: 4px;">
                            <?php echo esc_html($provider_label . ' / ' . $active_model); ?>
                        </code>
                    </div>
                </div>
            </div>

            <!-- Main Settings -->
            <div class="rawwire-settings-section active" style="border-left: 4px solid var(--rw-brand-gold, #f4b41a);">
                <h2 style="margin-top: 0; display: flex; align-items: center; gap: 8px;">
                    <span class="dashicons dashicons-networking" style="color: var(--rw-brand-gold, #f4b41a);"></span>
                    <?php esc_html_e('OpenClaw — Venice AI Gateway', 'raw-wire-dashboard'); ?>
                </h2>
                <p class="description" style="margin-bottom: 16px;">
                    <?php esc_html_e('OpenClaw routes AI requests through Venice.ai with real web browsing capabilities. These settings control the model, token limits, and web search behavior used by Party Investigator analysis.', 'raw-wire-dashboard'); ?>
                </p>

                <form method="post" action="options.php" id="openclaw-settings-form">
                    <?php settings_fields('rawwire_openclaw_group'); ?>

                    <!-- Connection Settings -->
                    <h3 style="display: flex; align-items: center; gap: 6px; margin-bottom: 4px;">
                        <span class="dashicons dashicons-admin-network" style="font-size: 18px; width: 18px; height: 18px;"></span>
                        <?php esc_html_e('Connection', 'raw-wire-dashboard'); ?>
                    </h3>
                    <table class="form-table" style="margin-top: 0;">
                        <tr>
                            <th scope="row"><label for="openclaw_enabled"><?php esc_html_e('Enable OpenClaw', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="rawwire_openclaw_settings[enabled]" id="openclaw_enabled"
                                        value="1" <?php checked(!isset($oc_settings['enabled']) || !empty($oc_settings['enabled'])); ?>>
                                    <?php esc_html_e('Use OpenClaw for party investigation analysis', 'raw-wire-dashboard'); ?>
                                </label>
                                <p class="description"><?php esc_html_e('When disabled, investigations will use the fallback analysis (basic extraction, no AI).', 'raw-wire-dashboard'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="openclaw_host"><?php esc_html_e('API Endpoint', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <input type="text" name="rawwire_openclaw_settings[host]" id="openclaw_host"
                                    value="<?php echo esc_attr($oc_settings['host'] ?? 'https://api.venice.ai/api/v1'); ?>"
                                    class="regular-text" placeholder="https://api.venice.ai/api/v1">
                                <p class="description"><?php esc_html_e('Venice.ai direct: https://api.venice.ai/api/v1 — Docker gateway: http://localhost:18789', 'raw-wire-dashboard'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="openclaw_auth_token"><?php esc_html_e('Auth Token Override', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <input type="password" name="rawwire_openclaw_settings[auth_token]" id="openclaw_auth_token"
                                    value="<?php echo esc_attr($oc_settings['auth_token'] ?? ''); ?>"
                                    class="regular-text" autocomplete="off" placeholder="<?php esc_attr_e('Leave blank to use Venice API key', 'raw-wire-dashboard'); ?>">
                                <button type="button" class="rawwire-key-toggle" id="toggle-openclaw-token" title="<?php esc_attr_e('Toggle visibility', 'raw-wire-dashboard'); ?>">
                                    <span class="dashicons dashicons-visibility"></span>
                                </button>
                                <?php if (!empty($oc_settings['auth_token'])): ?>
                                    <span class="dashicons dashicons-yes-alt" style="color: var(--rw-brand-gold, #f4b41a); margin-left: 8px;"></span>
                                <?php elseif ($venice_key_set): ?>
                                    <span style="color: #6c757d; margin-left: 8px; font-size: 12px;"><?php esc_html_e('→ Using Venice API key', 'raw-wire-dashboard'); ?></span>
                                <?php endif; ?>
                                <p class="description"><?php esc_html_e('Only needed if using Docker gateway. Venice direct mode uses your Venice API key automatically.', 'raw-wire-dashboard'); ?></p>
                            </td>
                        </tr>
                    </table>

                    <!-- Model Settings -->
                    <h3 style="display: flex; align-items: center; gap: 6px; margin-top: 24px; margin-bottom: 4px;">
                        <span class="dashicons dashicons-superhero-alt" style="font-size: 18px; width: 18px; height: 18px;"></span>
                        <?php esc_html_e('Model Configuration', 'raw-wire-dashboard'); ?>
                    </h3>
                    <table class="form-table" style="margin-top: 0;">
                        <tr>
                            <th scope="row"><label for="openclaw_provider"><?php esc_html_e('Model Provider', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <select name="rawwire_openclaw_settings[provider]" id="openclaw_provider">
                                    <option value="venice" <?php selected($provider, 'venice'); ?>><?php esc_html_e('Venice.ai', 'raw-wire-dashboard'); ?></option>
                                    <option value="openai" <?php selected($provider, 'openai'); ?>><?php esc_html_e('OpenAI-Compatible API', 'raw-wire-dashboard'); ?></option>
                                    <option value="ollama" <?php selected($provider, 'ollama'); ?>><?php esc_html_e('Ollama (Local)', 'raw-wire-dashboard'); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e('Choose where OpenClaw gets its model from. This controls endpoint/auth/model fields below.', 'raw-wire-dashboard'); ?></p>
                            </td>
                        </tr>
                        <tr class="openclaw-provider-row openclaw-provider-venice" style="<?php echo $provider !== 'venice' ? 'display:none;' : ''; ?>">
                            <th scope="row"><label for="openclaw_model"><?php esc_html_e('Venice Model', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <?php
                                $current_model = $oc_settings['model'] ?? '';
                                $venice_model = get_option('rawwire_venice_settings', [])['model'] ?? 'zai-org-glm-4.7-flash';
                                $cached_models = get_transient('rawwire_openclaw_models');
                                ?>
                                <?php if (!empty($cached_models) && is_array($cached_models)): ?>
                                    <select name="rawwire_openclaw_settings[model]" id="openclaw_model" style="min-width: 350px;" class="openclaw-provider-field openclaw-provider-venice">
                                        <option value="" <?php selected($current_model, ''); ?>>
                                            <?php printf(esc_html__('Use Venice Model (%s)', 'raw-wire-dashboard'), esc_html($venice_model)); ?>
                                        </option>
                                        <optgroup label="<?php esc_attr_e('Override with specific model', 'raw-wire-dashboard'); ?>">
                                            <?php foreach ($cached_models as $model_id => $model_data): ?>
                                                <option value="<?php echo esc_attr($model_id); ?>" <?php selected($current_model, $model_id); ?>>
                                                    <?php echo esc_html(is_array($model_data) ? ($model_data['label'] ?? $model_id) : $model_id); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    </select>
                                <?php else: ?>
                                    <input type="text" name="rawwire_openclaw_settings[model]" id="openclaw_model"
                                        value="<?php echo esc_attr($current_model); ?>"
                                        class="regular-text openclaw-provider-field openclaw-provider-venice" placeholder="<?php echo esc_attr__('Leave empty to use Venice model', 'raw-wire-dashboard'); ?>">
                                <?php endif; ?>
                                <button type="button" id="refresh_openclaw_models" class="button button-secondary" style="margin-left: 10px;">
                                    <?php esc_html_e('Refresh Models', 'raw-wire-dashboard'); ?>
                                </button>
                                <span id="refresh_openclaw_models_status" style="margin-left: 10px; display: none;"></span>
                                <p class="description"><?php esc_html_e('Used when provider is Venice.ai. Leave empty to inherit the Venice tab model.', 'raw-wire-dashboard'); ?></p>
                            </td>
                        </tr>
                        <tr class="openclaw-provider-row openclaw-provider-openai" style="<?php echo $provider !== 'openai' ? 'display:none;' : ''; ?>">
                            <th scope="row"><label for="openclaw_openai_base_url"><?php esc_html_e('OpenAI Endpoint', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <input type="text" name="rawwire_openclaw_settings[openai_base_url]" id="openclaw_openai_base_url"
                                    value="<?php echo esc_attr($oc_settings['openai_base_url'] ?? 'https://api.openai.com/v1'); ?>"
                                    class="regular-text" placeholder="https://api.openai.com/v1">
                                <p class="description"><?php esc_html_e('Any OpenAI SDK-compatible endpoint (must expose /chat/completions and /models).', 'raw-wire-dashboard'); ?></p>
                            </td>
                        </tr>
                        <tr class="openclaw-provider-row openclaw-provider-openai" style="<?php echo $provider !== 'openai' ? 'display:none;' : ''; ?>">
                            <th scope="row"><label for="openclaw_openai_api_key"><?php esc_html_e('OpenAI API Key', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <input type="password" name="rawwire_openclaw_settings[openai_api_key]" id="openclaw_openai_api_key"
                                    value="<?php echo esc_attr($oc_settings['openai_api_key'] ?? ''); ?>"
                                    class="regular-text" autocomplete="off" placeholder="sk-...">
                                <button type="button" class="rawwire-key-toggle" id="toggle-openai-token" title="<?php esc_attr_e('Toggle visibility', 'raw-wire-dashboard'); ?>">
                                    <span class="dashicons dashicons-visibility"></span>
                                </button>
                                <p class="description"><?php esc_html_e('Used only when provider is OpenAI-Compatible.', 'raw-wire-dashboard'); ?></p>
                            </td>
                        </tr>
                        <tr class="openclaw-provider-row openclaw-provider-openai" style="<?php echo $provider !== 'openai' ? 'display:none;' : ''; ?>">
                            <th scope="row"><label for="openclaw_openai_model"><?php esc_html_e('OpenAI Model', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <input type="text" name="rawwire_openclaw_settings[openai_model]" id="openclaw_openai_model"
                                    value="<?php echo esc_attr($oc_settings['openai_model'] ?? 'gpt-4o-mini'); ?>"
                                    class="regular-text" placeholder="gpt-4o-mini">
                            </td>
                        </tr>
                        <tr class="openclaw-provider-row openclaw-provider-ollama" style="<?php echo $provider !== 'ollama' ? 'display:none;' : ''; ?>">
                            <th scope="row"><label for="openclaw_ollama_base_url"><?php esc_html_e('Ollama Endpoint', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <input type="text" name="rawwire_openclaw_settings[ollama_base_url]" id="openclaw_ollama_base_url"
                                    value="<?php echo esc_attr($oc_settings['ollama_base_url'] ?? 'http://127.0.0.1:11434/v1'); ?>"
                                    class="regular-text" placeholder="http://127.0.0.1:11434/v1">
                                <p class="description"><?php esc_html_e('Use Ollama OpenAI-compatible endpoint, usually http://127.0.0.1:11434/v1.', 'raw-wire-dashboard'); ?></p>
                            </td>
                        </tr>
                        <tr class="openclaw-provider-row openclaw-provider-ollama" style="<?php echo $provider !== 'ollama' ? 'display:none;' : ''; ?>">
                            <th scope="row"><label for="openclaw_ollama_model"><?php esc_html_e('Ollama Model', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <input type="text" name="rawwire_openclaw_settings[ollama_model]" id="openclaw_ollama_model"
                                    value="<?php echo esc_attr($oc_settings['ollama_model'] ?? 'qwen2.5:14b'); ?>"
                                    class="regular-text" placeholder="qwen2.5:14b">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="openclaw_gateway_token"><?php esc_html_e('Gateway Auth Token', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <input type="password" name="rawwire_openclaw_settings[gateway_auth_token]" id="openclaw_gateway_token"
                                    value="<?php echo esc_attr($oc_settings['gateway_auth_token'] ?? ($oc_settings['gateway_token'] ?? 'rawwire-local-dev-2025')); ?>"
                                    class="regular-text" autocomplete="off" placeholder="rawwire-local-dev-2025">
                                <input type="hidden" name="rawwire_openclaw_settings[gateway_token]" value="<?php echo esc_attr($oc_settings['gateway_auth_token'] ?? ($oc_settings['gateway_token'] ?? 'rawwire-local-dev-2025')); ?>">
                                <button type="button" class="rawwire-key-toggle" id="toggle-openclaw-gateway-token" title="<?php esc_attr_e('Toggle visibility', 'raw-wire-dashboard'); ?>">
                                    <span class="dashicons dashicons-visibility"></span>
                                </button>
                                <p class="description"><?php esc_html_e('Token written to gateway.auth.token for browser tools. Required when gateway auth is enforced.', 'raw-wire-dashboard'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="openclaw_max_tokens"><?php esc_html_e('Max Tokens (General)', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <input type="number" name="rawwire_openclaw_settings[max_tokens]" id="openclaw_max_tokens"
                                    value="<?php echo esc_attr($oc_settings['max_tokens'] ?? 4000); ?>"
                                    min="100" max="128000" step="100" style="width: 120px;">
                                <p class="description"><?php esc_html_e('Default token limit for general OpenClaw requests (search, research).', 'raw-wire-dashboard'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="openclaw_temperature"><?php esc_html_e('Temperature', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <input type="range" name="rawwire_openclaw_settings[temperature]" id="openclaw_temperature"
                                    value="<?php echo esc_attr($oc_settings['temperature'] ?? 0.3); ?>"
                                    min="0" max="2" step="0.05" style="width: 200px;">
                                <span id="openclaw_temp_value" style="font-weight: bold; margin-left: 6px;"><?php echo esc_html($oc_settings['temperature'] ?? 0.3); ?></span>
                                <p class="description"><?php esc_html_e('Lower = more focused/deterministic. Higher = more creative. Recommended: 0.2-0.4 for analysis.', 'raw-wire-dashboard'); ?></p>
                            </td>
                        </tr>
                    </table>

                    <!-- Venice Web Features (DISABLED) -->
                    <h3 style="display: flex; align-items: center; gap: 6px; margin-top: 24px; margin-bottom: 4px;">
                        <span class="dashicons dashicons-admin-site-alt3" style="font-size: 18px; width: 18px; height: 18px;"></span>
                        <?php esc_html_e('Venice Web Features', 'raw-wire-dashboard'); ?>
                    </h3>
                    <p class="description" style="margin-bottom: 10px; color: #b32d2e;">
                        <span class="dashicons dashicons-dismiss" style="font-size: 16px; width: 16px; height: 16px; vertical-align: text-bottom;"></span>
                        <?php esc_html_e('Venice web search, scraping, and citations are permanently disabled. Investigations use OpenClaw browser tools for web access instead.', 'raw-wire-dashboard'); ?>
                    </p>
                    <input type="hidden" name="rawwire_openclaw_settings[enable_web_search]" value="0">
                    <input type="hidden" name="rawwire_openclaw_settings[enable_web_scraping]" value="0">
                    <input type="hidden" name="rawwire_openclaw_settings[enable_web_citations]" value="0">

                    <!-- Analysis Parameters -->
                    <h3 style="display: flex; align-items: center; gap: 6px; margin-top: 24px; margin-bottom: 4px;">
                        <span class="dashicons dashicons-analytics" style="font-size: 18px; width: 18px; height: 18px;"></span>
                        <?php esc_html_e('Investigation Analysis', 'raw-wire-dashboard'); ?>
                    </h3>
                    <p class="description" style="margin-bottom: 10px;">
                        <?php esc_html_e('These settings control the AI analysis step that runs AFTER party discovery/search. This is where raw search data gets turned into structured intelligence.', 'raw-wire-dashboard'); ?>
                    </p>
                    <table class="form-table" style="margin-top: 0;">
                        <tr>
                            <th scope="row"><label for="openclaw_analysis_max_tokens"><?php esc_html_e('Analysis Max Tokens', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <input type="number" name="rawwire_openclaw_settings[analysis_max_tokens]" id="openclaw_analysis_max_tokens"
                                    value="<?php echo esc_attr($oc_settings['analysis_max_tokens'] ?? 8000); ?>"
                                    min="1000" max="128000" step="500" style="width: 120px;">
                                <p class="description"><?php esc_html_e('Token budget for the analysis prompt. Higher = more detailed profiles but slower & more expensive. Minimum 4000 recommended.', 'raw-wire-dashboard'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="openclaw_analysis_temperature"><?php esc_html_e('Analysis Temperature', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <input type="range" name="rawwire_openclaw_settings[analysis_temperature]" id="openclaw_analysis_temperature"
                                    value="<?php echo esc_attr($oc_settings['analysis_temperature'] ?? 0.3); ?>"
                                    min="0" max="1" step="0.05" style="width: 200px;">
                                <span id="openclaw_analysis_temp_value" style="font-weight: bold; margin-left: 6px;"><?php echo esc_html($oc_settings['analysis_temperature'] ?? 0.3); ?></span>
                                <p class="description"><?php esc_html_e('Keep low (0.1-0.3) for factual JSON extraction. Higher values may hallucinate contacts.', 'raw-wire-dashboard'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="openclaw_request_timeout"><?php esc_html_e('Request Timeout', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <input type="number" name="rawwire_openclaw_settings[request_timeout]" id="openclaw_request_timeout"
                                    value="<?php echo esc_attr($oc_settings['request_timeout'] ?? 120); ?>"
                                    min="30" max="600" step="10" style="width: 100px;">
                                <span style="margin-left: 4px;"><?php esc_html_e('seconds', 'raw-wire-dashboard'); ?></span>
                                <p class="description"><?php esc_html_e('How long to wait for each API call. Deep research with web scraping can take 30-90s.', 'raw-wire-dashboard'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="openclaw_max_retries"><?php esc_html_e('Max Retries', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <select name="rawwire_openclaw_settings[max_retries]" id="openclaw_max_retries">
                                    <option value="1" <?php selected($oc_settings['max_retries'] ?? 2, 1); ?>>1 (fast, no retry)</option>
                                    <option value="2" <?php selected($oc_settings['max_retries'] ?? 2, 2); ?>>2 (default)</option>
                                    <option value="3" <?php selected($oc_settings['max_retries'] ?? 2, 3); ?>>3 (resilient)</option>
                                </select>
                                <p class="description"><?php esc_html_e('Retry attempts on empty/failed responses. Uses exponential backoff (1s, 2s, 4s).', 'raw-wire-dashboard'); ?></p>
                            </td>
                        </tr>
                        <!-- OpenClaw CLI Paths (for agent browser tools) -->
                        <tr>
                            <th scope="row" colspan="2" style="padding-top: 20px;">
                                <h4 style="margin: 0; display: flex; align-items: center; gap: 8px;">
                                    <span class="dashicons dashicons-admin-tools" style="color: #dc3545;"></span>
                                    <?php esc_html_e('CLI Paths (Advanced)', 'raw-wire-dashboard'); ?>
                                </h4>
                                <p class="description" style="font-weight: normal;"><?php esc_html_e('Required for OpenClaw agent browser tools. Set to your OpenClaw CLI installation path.', 'raw-wire-dashboard'); ?></p>
                            </th>
                        </tr>
                        <tr>
                            <th scope="row"><label for="openclaw_path"><?php esc_html_e('OpenClaw Binary', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <input type="text" name="rawwire_openclaw_settings[openclaw_path]" id="openclaw_path"
                                    value="<?php echo esc_attr($oc_settings['openclaw_path'] ?? '/home/ractal1/.nvm/versions/node/v22.22.0/bin/openclaw'); ?>"
                                    class="regular-text code" style="width: 100%;">
                                <p class="description"><?php esc_html_e('Full path to the openclaw binary. Find with: which openclaw', 'raw-wire-dashboard'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="node_bin_path"><?php esc_html_e('Node.js bin', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <input type="text" name="rawwire_openclaw_settings[node_bin_path]" id="node_bin_path"
                                    value="<?php echo esc_attr($oc_settings['node_bin_path'] ?? '/home/ractal1/.nvm/versions/node/v22.22.0/bin'); ?>"
                                    class="regular-text code" style="width: 100%;">
                                <p class="description"><?php esc_html_e('Node.js bin directory. Added to PATH for subprocess spawning.', 'raw-wire-dashboard'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="openclaw_home"><?php esc_html_e('OpenClaw HOME Directory', 'raw-wire-dashboard'); ?></label></th>
                            <td>
                                <input type="text" name="rawwire_openclaw_settings[openclaw_home]" id="openclaw_home"
                                    value="<?php echo esc_attr($oc_settings['openclaw_home'] ?? '/tmp/openclaw-home'); ?>"
                                    class="regular-text code" style="width: 100%;" placeholder="/tmp/openclaw-home">
                                <p class="description"><?php esc_html_e('Writable HOME used to provision ~/.openclaw runtime files for agent/browser sessions.', 'raw-wire-dashboard'); ?></p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit" style="display: flex; gap: 8px; align-items: center;">
                        <button type="submit" class="button rawwire-btn-dark"><?php esc_html_e('Save OpenClaw Settings', 'raw-wire-dashboard'); ?></button>
                        <button type="button" class="button rawwire-btn-dark-secondary" id="test-openclaw-connection"><?php esc_html_e('Test Connection', 'raw-wire-dashboard'); ?></button>
                    </p>
                </form>
                <div id="openclaw-test-result" class="notice" style="display: none;"></div>
            </div>

            <div class="rawwire-settings-section" style="border-left: 4px solid #6c757d;">
                <h3 style="margin-top: 0; display: flex; align-items: center; gap: 8px;">
                    <span class="dashicons dashicons-edit-page"></span>
                    <?php esc_html_e('Current Prompt Output', 'raw-wire-dashboard'); ?>
                </h3>
                <?php if (!empty($last_prompt['prompt'])): ?>
                    <p class="description" style="margin-top: 0;">
                        <?php
                        $prompt_type = $last_prompt['type'] ?? 'agent_research';
                        $updated_at = $last_prompt['updated_at'] ?? '';
                        echo esc_html(sprintf('Latest prompt type: %s%s', $prompt_type, $updated_at ? ' | ' . $updated_at : ''));
                        ?>
                    </p>
                    <textarea readonly rows="16" style="width: 100%; font-family: monospace;"><?php echo esc_textarea($last_prompt['prompt']); ?></textarea>
                <?php else: ?>
                    <p class="description"><?php esc_html_e('No prompt captured yet. Run a party investigation once, then reload this tab.', 'raw-wire-dashboard'); ?></p>
                <?php endif; ?>
            </div>

            <!-- Quick Reference -->
            <div class="rawwire-settings-section" style="margin-top: 20px; border-left: 4px solid #6c757d;">
                <h3 style="margin-top: 0; display: flex; align-items: center; gap: 8px;">
                    <span class="dashicons dashicons-info-outline" style="color: #6c757d;"></span>
                    <?php esc_html_e('Pipeline Flow', 'raw-wire-dashboard'); ?>
                </h3>
                <div style="font-family: monospace; font-size: 12px; color: #555; line-height: 1.8; background: #f8f9fa; padding: 12px 16px; border-radius: 6px;">
                    <?php esc_html_e('Permit → Extract Parties → Search (OpenClaw web search) → Analyze (OpenClaw AI) → Structured JSON Profile', 'raw-wire-dashboard'); ?><br>
                    <span style="color: #999;"><?php esc_html_e('Web Features affect BOTH search & analyze steps. Analysis tokens/temp only affect the analysis step.', 'raw-wire-dashboard'); ?></span>
                </div>
            </div>
        </div>
    <?php
        $this->render_openclaw_tab_styles();
    }

    /**
     * Render OpenClaw tab styles and scripts
     */
    private function render_openclaw_tab_styles()
    {
    ?>
        <style>
            .rawwire-ai-settings-panel {
                max-width: 900px;
            }

            .rawwire-settings-section {
                background: var(--rw-bg-surface, #fff);
                border: 1px solid var(--rw-border-default, #ccd0d4);
                border-radius: 8px;
                padding: 20px;
                margin-bottom: 20px;
            }

            .rawwire-settings-section h2,
            .rawwire-settings-section h3 {
                margin-top: 0;
                color: var(--rw-fg-default, #1d2327);
            }

            /* ── Kill all WP blue on the OpenClaw page ── */

            /* Model badge in connection banner */
            .rawwire-ai-settings-panel code {
                color: #1d2327 !important;
                background: #f0f0f0 !important;
            }

            /* Form inputs: text, number, password, select */
            .rawwire-ai-settings-panel input[type="text"],
            .rawwire-ai-settings-panel input[type="number"],
            .rawwire-ai-settings-panel input[type="password"],
            .rawwire-ai-settings-panel input[type="url"],
            .rawwire-ai-settings-panel select {
                border-color: var(--rw-border-default, #8c8f94) !important;
            }

            .rawwire-ai-settings-panel input[type="text"]:focus,
            .rawwire-ai-settings-panel input[type="number"]:focus,
            .rawwire-ai-settings-panel input[type="password"]:focus,
            .rawwire-ai-settings-panel input[type="url"]:focus,
            .rawwire-ai-settings-panel select:focus {
                border-color: var(--rw-brand-gold, #f4b41a) !important;
                box-shadow: 0 0 0 1px var(--rw-brand-gold, #f4b41a) !important;
                outline: none !important;
            }

            /* Checkboxes */
            .rawwire-ai-settings-panel input[type="checkbox"]:checked {
                background-color: var(--rw-brand-gold, #f4b41a) !important;
                border-color: var(--rw-brand-gold, #f4b41a) !important;
            }

            .rawwire-ai-settings-panel input[type="checkbox"]:focus {
                border-color: var(--rw-brand-gold, #f4b41a) !important;
                box-shadow: 0 0 0 1px var(--rw-brand-gold, #f4b41a) !important;
                outline: none !important;
            }

            /* Range slider accent */
            .rawwire-ai-settings-panel input[type="range"] {
                accent-color: var(--rw-brand-gold, #f4b41a);
            }

            /* Buttons — primary (Save) */
            .rawwire-ai-settings-panel .button.rawwire-btn-dark {
                background: #1d2327 !important;
                color: #fff !important;
                border-color: #1d2327 !important;
            }

            .rawwire-ai-settings-panel .button.rawwire-btn-dark:hover {
                background: #2c3338 !important;
                border-color: #2c3338 !important;
            }

            .rawwire-ai-settings-panel .button.rawwire-btn-dark:focus {
                box-shadow: 0 0 0 1px #1d2327 !important;
                outline: none !important;
            }

            /* Buttons — secondary (Test, Refresh) */
            .rawwire-ai-settings-panel .button.rawwire-btn-dark-secondary,
            .rawwire-ai-settings-panel .button.button-secondary {
                background: #f0f0f1 !important;
                color: #1d2327 !important;
                border-color: #8c8f94 !important;
            }

            .rawwire-ai-settings-panel .button.rawwire-btn-dark-secondary:hover,
            .rawwire-ai-settings-panel .button.button-secondary:hover {
                background: #e0e0e0 !important;
                border-color: #1d2327 !important;
                color: #1d2327 !important;
            }

            .rawwire-ai-settings-panel .button.rawwire-btn-dark-secondary:focus,
            .rawwire-ai-settings-panel .button.button-secondary:focus {
                box-shadow: 0 0 0 1px #1d2327 !important;
                outline: none !important;
            }

            /* Links */
            .rawwire-ai-settings-panel a {
                color: var(--rw-brand-gold, #f4b41a);
            }

            .rawwire-ai-settings-panel a:hover {
                color: var(--rw-brand-gold-dark, #c49000);
            }

            /* Key toggle button */
            .rawwire-ai-settings-panel .rawwire-key-toggle {
                background: transparent !important;
                border: none !important;
                box-shadow: none !important;
                color: #8c8f94 !important;
                cursor: pointer;
            }

            .rawwire-ai-settings-panel .rawwire-key-toggle:hover {
                color: #1d2327 !important;
            }

            .rawwire-ai-settings-panel .rawwire-key-toggle:focus {
                box-shadow: none !important;
                outline: none !important;
            }
        </style>
        <script>
            jQuery(document).ready(function($) {
                        // Toggle auth token visibility
                        $('#toggle-openclaw-token').on('click', function() {
                            var input = $('#openclaw_auth_token');
                            var icon = $(this).find('.dashicons');
                            if (input.attr('type') === 'password') {
                                input.attr('type', 'text');
                                icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
                            } else {
                                input.attr('type', 'password');
                                icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
                            }
                        });
                        $('#toggle-openclaw-gateway-token').on('click', function() {
                            var input = $('#openclaw_gateway_token');
                            var icon = $(this).find('.dashicons');
                            if (input.attr('type') === 'password') {
                                input.attr('type', 'text');
                                icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
                            } else {
                                input.attr('type', 'password');
                                icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
                            }
                        });
                        $('#toggle-openai-token').on('click', function() {
                            var input = $('#openclaw_openai_api_key');
                            var icon = $(this).find('.dashicons');
                            if (input.attr('type') === 'password') {
                                input.attr('type', 'text');
                                icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
                            } else {
                                input.attr('type', 'password');
                                icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
                            }
                        });

                        function toggleProviderFields() {
                            var provider = $('#openclaw_provider').val() || 'venice';
                            $('.openclaw-provider-row').hide();
                            $('.openclaw-provider-' + provider).show();
                        }
                        $('#openclaw_provider').on('change', toggleProviderFields);
                        toggleProviderFields();
                        // Temperature sliders
                        $('#openclaw_temperature').on('input', function() {
                            $('#openclaw_temp_value').text($(this).val());
                        });
                        $('#openclaw_analysis_temperature').on('input', function() {
                            $('#openclaw_analysis_temp_value').text($(this).val());
                        });
                        // Test OpenClaw connection
                        $('#test-openclaw-connection').on('click', function() {
                            var $btn = $(this);
                            var $result = $('#openclaw-test-result');
                            $btn.prop('disabled', true).text('Testing...');
                            $.post(ajaxurl, {
                                action: 'rawwire_openclaw_test_connection',
                                nonce: '<?php echo wp_create_nonce('rawwire_ai_settings_nonce'); ?>'
                            }, function(response) {
                                $result.show().removeClass('notice-success notice-error')
                                    .addClass(response.success ? 'notice-success' : 'notice-error')
                                    .html('<p>' + (response.data?.message || response.data || 'Unknown response') + '</p>');
                            }).always(function() {
                                $btn.prop('disabled', false).text('Test Connection');
                            });
                        });
                        // Refresh OpenClaw models
                        $('#refresh_openclaw_models').on('click', function() {
                                    var $btn = $(this);
                                    var $status = $('#refresh_openclaw_models_status');
                                    $btn.prop('disabled', true);
                                    $status.show().text('Refreshing...');
                                    $.post(ajaxurl, {
                                        action: 'rawwire_openclaw_refresh_models',
                                        nonce: '<?php echo wp_create_nonce('rawwire_ai_settings_nonce'); ?>'
                                    }, function(response) {
                                        if (response.success) {
                                            $status.text('Models refreshed! Reloading...');
                                            setTimeout(function() {
                                                location.reload();
                                            }, 1000);
                                        } else {
                                            $status.text('Failed: ' + (response.data?.message || response.data || 'Unknown'));
                                        }
                                    }).always(function() {
                                            $btn.prop('disabled', false);
                                            'provider' => 'venice',
                                            'host' => 'https://api.venice.ai/api/v1',
                                            'auth_token' => '',
                                            'model' => 'olafangensan-glm-4.7-flash-heretic',
                                            'openai_base_url' => 'https://api.openai.com/v1',
                                            'openai_api_key' => '',
                                            'openai_model' => 'gpt-4o-mini',
                                            'ollama_base_url' => 'http://127.0.0.1:11434/v1',
                                            'ollama_model' => 'qwen2.5:14b',
                                            'gateway_token' => 'rawwire-local-dev-2025',
                                            'gateway_auth_token' => 'rawwire-local-dev-2025',
                                            'max_tokens' => 4000,
                                            'temperature' => 0.3,
                                            'enable_web_search' => false, // NEVER use Venice web search
                                            'enable_web_scraping' => false, // NEVER use Venice web scraping
                                            'enable_web_citations' => false, // NEVER use Venice web citations
                                            'analysis_max_tokens' => 8000,
                                            'analysis_temperature' => 0.3,
                                            'request_timeout' => 120,
                                            'max_retries' => 2,
                                            // OpenClaw CLI paths (needed for agent browser tools)
                                            'openclaw_path' => '/home/ractal1/.nvm/versions/node/v22.22.0/bin/openclaw',
                                            'node_bin_path' => '/home/ractal1/.nvm/versions/node/v22.22.0/bin',
                                            'openclaw_home' => '/tmp/openclaw-home',
                                        ];
                                    }

                                    /**
                                     * Render MCP Server tab content
                                     */
                                    private

                                    function render_mcp_tab() {
                                        $mcp_settings = get_option('rawwire_mcp_settings', []); ?
                                        >
                                        <
                                        div class = "rawwire-ai-settings-panel" >
                                        <
                                        div class = "rawwire-settings-section"
                                        style = "border-left: 4px solid #f59e0b;" >
                                            <
                                            h2 style = "margin-top: 0; display: flex; align-items: center; gap: 8px;" >
                                            <
                                            span class = "dashicons dashicons-networking"
                                        style = "color: #f59e0b;" > < /span>
                                        <?php esc_html_e('MCP Server (Model Context Protocol)', 'raw-wire-dashboard'); ?>
                                            <
                                            /h2> <
                                        p class = "description"
                                        style = "margin-bottom: 16px;" >
                                            <?php esc_html_e('Enable MCP to allow AI agents (ChatGPT, Claude) to execute Raw Wire tools directly.', 'raw-wire-dashboard'); ?> <
                                            /p>

                                            <
                                            form method = "post"
                                        action = "options.php"
                                        id = "mcp-settings-form" >
                                            <?php settings_fields('rawwire_mcp_group'); ?> <
                                            table class = "form-table" >
                                            <
                                            tr >
                                            <
                                            th scope = "row" > <?php esc_html_e('MCP Server', 'raw-wire-dashboard'); ?> < /th> <
                                        td >
                                            <
                                            label >
                                            <
                                            input type = "checkbox"
                                        name = "rawwire_mcp_settings[enabled]"
                                        value = "1"
                                        <?php checked($mcp_settings['enabled'] ?? true); ?> >
                                            <?php esc_html_e('Enable MCP Server', 'raw-wire-dashboard'); ?> <
                                            /label> <
                                        p class = "description" > <?php esc_html_e('When enabled, AI agents can call Raw Wire functions through AI Engine.', 'raw-wire-dashboard'); ?> < /p> < /
                                        td > <
                                            /tr> <
                                        tr >
                                            <
                                            th scope = "row" > <?php esc_html_e('Security', 'raw-wire-dashboard'); ?> < /th> <
                                        td >
                                            <
                                            label >
                                            <
                                            input type = "checkbox"
                                        name = "rawwire_mcp_settings[require_auth]"
                                        value = "1"
                                        <?php checked($mcp_settings['require_auth'] ?? true); ?> >
                                            <?php esc_html_e('Require authentication for MCP calls', 'raw-wire-dashboard'); ?> <
                                            /label> < /
                                        td > <
                                            /tr> < /
                                        table > <
                                            p class = "submit" >
                                            <
                                            button type = "submit"
                                        class = "button rawwire-btn-dark" > <?php esc_html_e('Save MCP Settings', 'raw-wire-dashboard'); ?> < /button> < /
                                        p > <
                                            /form>

                                            <
                                            !--MCP Tools List-- >
                                            <
                                            div class = "mcp-tools-section"
                                        style = "margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee;" >
                                            <
                                            h4 > <?php esc_html_e('Registered MCP Tools', 'raw-wire-dashboard'); ?> < /h4> <
                                        p class = "description" > <?php esc_html_e('These tools are available to AI agents:', 'raw-wire-dashboard'); ?> < /p> <
                                        div id = "mcp-tools-list" >
                                            <
                                            p class = "loading" > <?php esc_html_e('Loading tools...', 'raw-wire-dashboard'); ?> < /p> < /
                                        div > <
                                            /div> < /
                                        div > <
                                            /div>
                                    <?php
                                    $this->render_mcp_tab_scripts();
                                }

                                /**
                                 * Render MCP tab scripts
                                 */
                                private function render_mcp_tab_scripts()
                                {
                                    ?>
                                            <
                                            style >
                                            .rawwire - ai - settings - panel {
                                                max - width: 900 px;
                                            }

                                            .rawwire - settings - section {
                                                background: var (--rw - bg - surface, #fff);
                                                border: 1 px solid
                                                var (--rw - border -
                                                    default, #ccd0d4);
                                                border - radius: 8 px;
                                                padding: 20 px;
                                                margin - bottom: 20 px;
                                            }

                                            .mcp - tool - item {
                                                background: #f6f7f7;
                                                padding: 10 px 15 px;
                                                margin: 5 px 0;
                                                border - radius: 4 px;
                                            }

                                            .mcp - tool - item.tool - name {
                                                font - weight: 600;
                                                color: #0073aa;
            }

            .mcp-tool-item .tool-desc {
                color: # 666;
                                                font - size: 13 px;
                                                margin - top: 5 px;
                                            } <
                                            /style> <
                                        script >
                                            jQuery(document).ready(function($) {
                                                // Load MCP tools list
                                                $.post(ajaxurl, {
                                                    action: 'rawwire_mcp_list_tools',
                                                    nonce: '<?php echo wp_create_nonce('rawwire_ai_settings_nonce'); ?>'
                                                }, function(response) {
                                                    var $list = $('#mcp-tools-list');
                                                    if (response.success && response.data && response.data.length) {
                                                        var html = '';
                                                        response.data.forEach(function(tool) {
                                                            html += '<div class="mcp-tool-item">';
                                                            html += '<div class="tool-name">' + tool.name + '</div>';
                                                            html += '<div class="tool-desc">' + (tool.description || '') + '</div>';
                                                            html += '</div>';
                                                        });
                                                        $list.html(html);
                                                    } else {
                                                        $list.html('<p>No MCP tools registered.</p>');
                                                    }
                                                });
                                            });
        </script>
    <?php
                                }

                                /**
                                 * Get default Party Investigator settings
                                 */
                                public function get_default_party_investigator_settings()
                                {
                                    $prompt_defaults = class_exists('RawWire_Party_Investigator')
                                        ? RawWire_Party_Investigator::get_default_perplexity_prompt_templates()
                                        : [
                                            'pass_1' => '',
                                            'pass_2' => '',
                                            'pass_3' => '',
                                        ];

                                    return [
                                        'enabled'              => false,
                                        'pipeline_mode'        => 'veniceclaw',
                                        'brave_api_key'        => '',
                                        'search_depth'         => 'standard',
                                        'auto_investigate'     => true,
                                        'max_searches_per_party' => 3,
                                        'cache_hours'          => 24,
                                        'reinvestigation_cooldown_minutes' => 1,
                                        'search_provider'      => 'openclaw',
                                        'openclaw_auth_token'  => 'rawwire-local-dev-2025',
                                        'investigation_model'  => '',
                                        'deep_research'        => true,
                                        'perplexity_pass_count' => 2,
                                        'perplexity_preset' => 'pro-search',
                                        'perplexity_max_steps' => '',
                                        'perplexity_search_mode' => 'web',
                                        'perplexity_top_p' => '',
                                        'perplexity_reasoning_effort' => '',
                                        'perplexity_return_images' => false,
                                        'perplexity_return_related_questions' => false,
                                        'perplexity_enable_search_classifier' => true,
                                        'perplexity_disable_search' => false,
                                        'perplexity_strip_thinking_response' => true,
                                        'perplexity_model_pass_1' => '',
                                        'perplexity_model_pass_2' => '',
                                        'perplexity_model_pass_3' => '',
                                        'perplexity_prompt_pass_1' => $prompt_defaults['pass_1'],
                                        'perplexity_prompt_pass_2' => $prompt_defaults['pass_2'],
                                        'perplexity_prompt_pass_3' => $prompt_defaults['pass_3'],
                                    ];
                                }

                                /**
                                 * Render Party Investigator tab content
                                 * Public so it can be called from Lead Generator panels.
                                 */
                                public function render_party_investigator_tab()
                                {
                                    $settings = wp_parse_args(get_option('rawwire_party_investigator_settings', []), $this->get_default_party_investigator_settings());
                                    $subtab = isset($_GET['investigation_subtab']) ? sanitize_key($_GET['investigation_subtab']) : 'perplexity';
                                    if (!in_array($subtab, ['perplexity', 'veniceclaw', 'ai_engine'], true)) {
                                        $subtab = 'perplexity';
                                    }

                                    $subtabs = [
                                        'perplexity' => ['label' => __('Perplexity', 'raw-wire-dashboard'), 'icon' => 'dashicons-admin-site-alt3'],
                                        'veniceclaw' => ['label' => __('Veniceclaw', 'raw-wire-dashboard'), 'icon' => 'dashicons-networking'],
                                        'ai_engine' => ['label' => __('AI Engine', 'raw-wire-dashboard'), 'icon' => 'dashicons-admin-generic'],
                                    ];

                                    $base_url = admin_url('admin.php?page=rawwire-lead-generator&tab=investigation');
                                    $perplexity_settings = wp_parse_args(get_option('rawwire_perplexity_settings', []), $this->get_default_perplexity_settings());
                                    $cached_perplexity_models = get_transient('rawwire_perplexity_models');
                                    $provider_default_perplexity_model = (string) ($perplexity_settings['model'] ?? 'sonar');
                                    $venice_settings = wp_parse_args(get_option('rawwire_venice_settings', []), $this->get_default_venice_settings());
                                    $openai_settings = wp_parse_args(get_option('rawwire_openai_settings', []), $this->get_default_openai_settings());
    ?>
        <div class="rawwire-ai-settings-panel">
            <div class="rawwire-settings-section active" style="border-left: 4px solid var(--rw-brand-gold, #f4b41a);">
                <h2 style="margin-top: 0; display: flex; align-items: center; gap: 8px;">
                    <span class="dashicons dashicons-search" style="color: var(--rw-brand-gold, #f4b41a);"></span>
                    <?php esc_html_e('Party Investigator (Decision Maker Research)', 'raw-wire-dashboard'); ?>
                </h2>
                <p class="description" style="margin-bottom: 16px;">
                    <?php esc_html_e('These are workflow controls for when investigations run and how aggressive the workflow should be. Provider credentials and model defaults now live in AI Settings.', 'raw-wire-dashboard'); ?>
                </p>

                <div class="rawwire-investigation-subtabs">
                    <?php foreach ($subtabs as $subtab_id => $meta): ?>
                        <a href="<?php echo esc_url(add_query_arg('investigation_subtab', $subtab_id, $base_url)); ?>" class="<?php echo $subtab === $subtab_id ? 'is-active' : ''; ?>">
                            <span class="dashicons <?php echo esc_attr($meta['icon']); ?>"></span>
                            <?php echo esc_html($meta['label']); ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <?php if ($subtab === 'perplexity'): ?>
                    <form method="post" action="options.php" id="party-investigator-settings-form">
                        <?php settings_fields('rawwire_pi_group'); ?>
                        <input type="hidden" name="rawwire_party_investigator_settings[pipeline_mode]" value="perplexity_direct">

                        <div class="rawwire-provider-callout" style="margin-bottom: 18px;">
                            <div class="rawwire-provider-metric">
                                <span><?php esc_html_e('Active Workflow', 'raw-wire-dashboard'); ?></span>
                                <strong><?php esc_html_e('Perplexity Direct', 'raw-wire-dashboard'); ?></strong>
                            </div>
                            <div class="rawwire-provider-metric">
                                <span><?php esc_html_e('Selected Model', 'raw-wire-dashboard'); ?></span>
                                <strong><?php echo esc_html($perplexity_settings['model'] ?? 'sonar'); ?></strong>
                            </div>
                            <div class="rawwire-provider-metric">
                                <span><?php esc_html_e('Provider Home', 'raw-wire-dashboard'); ?></span>
                                <strong><?php esc_html_e('AI Settings -> Perplexity', 'raw-wire-dashboard'); ?></strong>
                            </div>
                        </div>

                        <div class="rawwire-settings-section active" style="margin-bottom: 18px; border-left: 4px solid #14b8a6;">
                            <h3 style="margin-top: 0;"><?php esc_html_e('Shared Workflow Settings', 'raw-wire-dashboard'); ?></h3>
                            <table class="form-table">
                                <tr>
                                    <th scope="row"><label for="pi_enabled"><?php esc_html_e('Enable Investigations', 'raw-wire-dashboard'); ?></label></th>
                                    <td>
                                        <input type="hidden" name="rawwire_party_investigator_settings[enabled]" value="0">
                                        <label>
                                            <input type="checkbox" name="rawwire_party_investigator_settings[enabled]" id="pi_enabled" value="1" <?php checked(!empty($settings['enabled'])); ?>>
                                            <?php esc_html_e('Enable party investigations on promoted candidates', 'raw-wire-dashboard'); ?>
                                        </label>
                                        <p class="description"><?php esc_html_e('When enabled, promoted candidates will be investigated for stakeholder information after scoring and promotion.', 'raw-wire-dashboard'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="pi_auto_investigate"><?php esc_html_e('Auto-Investigate', 'raw-wire-dashboard'); ?></label></th>
                                    <td>
                                        <input type="hidden" name="rawwire_party_investigator_settings[auto_investigate]" value="0">
                                        <label>
                                            <input type="checkbox" name="rawwire_party_investigator_settings[auto_investigate]" id="pi_auto_investigate" value="1" <?php checked(!empty($settings['auto_investigate'])); ?>>
                                            <?php esc_html_e('Automatically investigate after permit insert', 'raw-wire-dashboard'); ?>
                                        </label>
                                        <p class="description"><?php esc_html_e('Schedule investigation job automatically when new permits are scraped.', 'raw-wire-dashboard'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="pi_search_depth"><?php esc_html_e('Search Depth', 'raw-wire-dashboard'); ?></label></th>
                                    <td>
                                        <select name="rawwire_party_investigator_settings[search_depth]" id="pi_search_depth">
                                            <option value="basic" <?php selected($settings['search_depth'] ?? 'standard', 'basic'); ?>><?php esc_html_e('Basic (1 search per party)', 'raw-wire-dashboard'); ?></option>
                                            <option value="standard" <?php selected($settings['search_depth'] ?? 'standard', 'standard'); ?>><?php esc_html_e('Standard (3 searches per party)', 'raw-wire-dashboard'); ?></option>
                                            <option value="deep" <?php selected($settings['search_depth'] ?? 'standard', 'deep'); ?>><?php esc_html_e('Deep (5+ searches per party)', 'raw-wire-dashboard'); ?></option>
                                        </select>
                                        <p class="description"><?php esc_html_e('More searches = better data but uses more API quota.', 'raw-wire-dashboard'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="pi_max_searches"><?php esc_html_e('Max Searches Per Party', 'raw-wire-dashboard'); ?></label></th>
                                    <td>
                                        <input type="number" name="rawwire_party_investigator_settings[max_searches_per_party]" id="pi_max_searches" value="<?php echo esc_attr($settings['max_searches_per_party'] ?? 3); ?>" min="1" max="10" step="1" style="width: 80px;">
                                        <p class="description"><?php esc_html_e('Limit research actions per individual party, regardless of the active investigation pipeline.', 'raw-wire-dashboard'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="pi_cache_hours"><?php esc_html_e('Cache Duration', 'raw-wire-dashboard'); ?></label></th>
                                    <td>
                                        <input type="number" name="rawwire_party_investigator_settings[cache_hours]" id="pi_cache_hours" value="<?php echo esc_attr($settings['cache_hours'] ?? 24); ?>" min="1" max="168" step="1" style="width: 80px;"> <?php esc_html_e('hours', 'raw-wire-dashboard'); ?>
                                        <p class="description"><?php esc_html_e('Cache investigation outputs to avoid redundant agent runs.', 'raw-wire-dashboard'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="pi_reinvestigation_cooldown_minutes"><?php esc_html_e('Re-investigation Cooldown', 'raw-wire-dashboard'); ?></label></th>
                                    <td>
                                        <input type="number" name="rawwire_party_investigator_settings[reinvestigation_cooldown_minutes]" id="pi_reinvestigation_cooldown_minutes" value="<?php echo esc_attr($settings['reinvestigation_cooldown_minutes'] ?? 1); ?>" min="0" max="1440" step="1" style="width: 80px;"> <?php esc_html_e('minutes', 'raw-wire-dashboard'); ?>
                                        <p class="description"><?php esc_html_e('Minimum wait before re-running investigation on the same source. Set to 0 to disable cooldown.', 'raw-wire-dashboard'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="pi_deep_research"><?php esc_html_e('Deep Research Escalation', 'raw-wire-dashboard'); ?></label></th>
                                    <td>
                                        <input type="hidden" name="rawwire_party_investigator_settings[deep_research]" value="0">
                                        <label>
                                            <input type="checkbox" name="rawwire_party_investigator_settings[deep_research]" id="pi_deep_research" value="1" <?php checked(!empty($settings['deep_research'])); ?>>
                                            <?php esc_html_e('Allow aggressive follow-up research when the first pass is too thin', 'raw-wire-dashboard'); ?>
                                        </label>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <div class="rawwire-settings-section active" style="margin-bottom: 18px; border-left: 4px solid #14b8a6;">
                            <h3 style="margin-top: 0;"><?php esc_html_e('Perplexity Workflow And Search Settings', 'raw-wire-dashboard'); ?></h3>
                            <table class="form-table">
                                <tr>
                                    <th scope="row"><label for="pi_perplexity_pass_count"><?php esc_html_e('Number Of Passes', 'raw-wire-dashboard'); ?></label></th>
                                    <td>
                                        <select name="rawwire_party_investigator_settings[perplexity_pass_count]" id="pi_perplexity_pass_count">
                                            <option value="1" <?php selected((int) ($settings['perplexity_pass_count'] ?? 2), 1); ?>>1</option>
                                            <option value="2" <?php selected((int) ($settings['perplexity_pass_count'] ?? 2), 2); ?>>2</option>
                                            <option value="3" <?php selected((int) ($settings['perplexity_pass_count'] ?? 2), 3); ?>>3</option>
                                        </select>
                                        <p class="description"><?php esc_html_e('Pass 1 runs native research, pass 2 runs the refinement prompt, and pass 3 enables gap-fill retry.', 'raw-wire-dashboard'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="pi_perplexity_preset"><?php esc_html_e('Research Preset', 'raw-wire-dashboard'); ?></label></th>
                                    <td>
                                        <select name="rawwire_party_investigator_settings[perplexity_preset]" id="pi_perplexity_preset">
                                            <option value="" <?php selected((string) ($settings['perplexity_preset'] ?? 'pro-search'), ''); ?>><?php esc_html_e('No preset (use explicit model/tools)', 'raw-wire-dashboard'); ?></option>
                                            <option value="fast-search" <?php selected((string) ($settings['perplexity_preset'] ?? 'pro-search'), 'fast-search'); ?>><?php esc_html_e('Fast Search', 'raw-wire-dashboard'); ?></option>
                                            <option value="pro-search" <?php selected((string) ($settings['perplexity_preset'] ?? 'pro-search'), 'pro-search'); ?>><?php esc_html_e('Pro Search', 'raw-wire-dashboard'); ?></option>
                                            <option value="deep-research" <?php selected((string) ($settings['perplexity_preset'] ?? 'pro-search'), 'deep-research'); ?>><?php esc_html_e('Deep Research', 'raw-wire-dashboard'); ?></option>
                                            <option value="advanced-deep-research" <?php selected((string) ($settings['perplexity_preset'] ?? 'pro-search'), 'advanced-deep-research'); ?>><?php esc_html_e('Advanced Deep Research', 'raw-wire-dashboard'); ?></option>
                                        </select>
                                        <p class="description"><?php esc_html_e('Perplexity Responses API preset for the direct investigation lane. Presets carry optimized tools, system guidance, and default model behavior.', 'raw-wire-dashboard'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="pi_perplexity_max_steps"><?php esc_html_e('Max Steps', 'raw-wire-dashboard'); ?></label></th>
                                    <td>
                                        <input type="number" name="rawwire_party_investigator_settings[perplexity_max_steps]" id="pi_perplexity_max_steps" value="<?php echo esc_attr($settings['perplexity_max_steps'] ?? ''); ?>" min="1" max="10" step="1" class="small-text" placeholder="<?php esc_attr_e('Preset default', 'raw-wire-dashboard'); ?>">
                                        <p class="description"><?php esc_html_e('Optional override for the Responses API research loop depth. Leave blank to use the selected preset default.', 'raw-wire-dashboard'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="pi_perplexity_search_mode"><?php esc_html_e('Search Mode', 'raw-wire-dashboard'); ?></label></th>
                                    <td>
                                        <select name="rawwire_party_investigator_settings[perplexity_search_mode]" id="pi_perplexity_search_mode">
                                            <option value="web" <?php selected($settings['perplexity_search_mode'] ?? 'web', 'web'); ?>><?php esc_html_e('Web', 'raw-wire-dashboard'); ?></option>
                                            <option value="academic" <?php selected($settings['perplexity_search_mode'] ?? 'web', 'academic'); ?>><?php esc_html_e('Academic', 'raw-wire-dashboard'); ?></option>
                                            <option value="sec" <?php selected($settings['perplexity_search_mode'] ?? 'web', 'sec'); ?>><?php esc_html_e('SEC', 'raw-wire-dashboard'); ?></option>
                                        </select>
                                        <p class="description"><?php esc_html_e('Investigation-specific search mode override for Perplexity direct research.', 'raw-wire-dashboard'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="pi_perplexity_reasoning_effort"><?php esc_html_e('Reasoning Effort', 'raw-wire-dashboard'); ?></label></th>
                                    <td>
                                        <select name="rawwire_party_investigator_settings[perplexity_reasoning_effort]" id="pi_perplexity_reasoning_effort">
                                            <option value="" <?php selected((string) ($settings['perplexity_reasoning_effort'] ?? ''), ''); ?>><?php echo esc_html(sprintf(__('Use AI Settings default (%s)', 'raw-wire-dashboard'), (string) ($perplexity_settings['reasoning_effort'] ?? 'off'))); ?></option>
                                            <option value="off" <?php selected((string) ($settings['perplexity_reasoning_effort'] ?? ''), 'off'); ?>><?php esc_html_e('Off', 'raw-wire-dashboard'); ?></option>
                                            <option value="minimal" <?php selected((string) ($settings['perplexity_reasoning_effort'] ?? ''), 'minimal'); ?>><?php esc_html_e('Minimal', 'raw-wire-dashboard'); ?></option>
                                            <option value="low" <?php selected((string) ($settings['perplexity_reasoning_effort'] ?? ''), 'low'); ?>><?php esc_html_e('Low', 'raw-wire-dashboard'); ?></option>
                                            <option value="medium" <?php selected((string) ($settings['perplexity_reasoning_effort'] ?? ''), 'medium'); ?>><?php esc_html_e('Medium', 'raw-wire-dashboard'); ?></option>
                                            <option value="high" <?php selected((string) ($settings['perplexity_reasoning_effort'] ?? ''), 'high'); ?>><?php esc_html_e('High', 'raw-wire-dashboard'); ?></option>
                                        </select>
                                        <p class="description"><?php esc_html_e('Optional Lead Generator override for Perplexity reasoning effort. Leave on AI Settings default unless this workflow needs different behavior.', 'raw-wire-dashboard'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="pi_perplexity_top_p"><?php esc_html_e('Top P', 'raw-wire-dashboard'); ?></label></th>
                                    <td>
                                        <input type="number" name="rawwire_party_investigator_settings[perplexity_top_p]" id="pi_perplexity_top_p" value="<?php echo esc_attr($settings['perplexity_top_p'] ?? ''); ?>" min="0" max="1" step="0.05" class="small-text" placeholder="<?php echo esc_attr((string) ($perplexity_settings['top_p'] ?? 0.9)); ?>">
                                        <p class="description"><?php esc_html_e('Optional Lead Generator override for Perplexity top_p. Leave blank to inherit the AI Settings provider default.', 'raw-wire-dashboard'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Search Output Options', 'raw-wire-dashboard'); ?></th>
                                    <td>
                                        <input type="hidden" name="rawwire_party_investigator_settings[perplexity_return_images]" value="0">
                                        <label style="display:block; margin-bottom:6px;"><input type="checkbox" name="rawwire_party_investigator_settings[perplexity_return_images]" value="1" <?php checked(!empty($settings['perplexity_return_images'])); ?>> <?php esc_html_e('Return image results', 'raw-wire-dashboard'); ?></label>
                                        <input type="hidden" name="rawwire_party_investigator_settings[perplexity_return_related_questions]" value="0">
                                        <label style="display:block; margin-bottom:6px;"><input type="checkbox" name="rawwire_party_investigator_settings[perplexity_return_related_questions]" value="1" <?php checked(!empty($settings['perplexity_return_related_questions'])); ?>> <?php esc_html_e('Return related questions', 'raw-wire-dashboard'); ?></label>
                                        <input type="hidden" name="rawwire_party_investigator_settings[perplexity_enable_search_classifier]" value="0">
                                        <label style="display:block; margin-bottom:6px;"><input type="checkbox" name="rawwire_party_investigator_settings[perplexity_enable_search_classifier]" value="1" <?php checked(!empty($settings['perplexity_enable_search_classifier'])); ?>> <?php esc_html_e('Enable search classifier', 'raw-wire-dashboard'); ?></label>
                                        <input type="hidden" name="rawwire_party_investigator_settings[perplexity_disable_search]" value="0">
                                        <label style="display:block; margin-bottom:6px;"><input type="checkbox" name="rawwire_party_investigator_settings[perplexity_disable_search]" value="1" <?php checked(!empty($settings['perplexity_disable_search'])); ?>> <?php esc_html_e('Disable live search', 'raw-wire-dashboard'); ?></label>
                                        <input type="hidden" name="rawwire_party_investigator_settings[perplexity_strip_thinking_response]" value="0">
                                        <label style="display:block;"><input type="checkbox" name="rawwire_party_investigator_settings[perplexity_strip_thinking_response]" value="1" <?php checked(!empty($settings['perplexity_strip_thinking_response'])); ?>> <?php esc_html_e('Strip thinking tags before validation', 'raw-wire-dashboard'); ?></label>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <div class="rawwire-settings-section active" style="margin-bottom: 18px; border-left: 4px solid #14b8a6;">
                            <h3 style="margin-top: 0;"><?php esc_html_e('Selected Model', 'raw-wire-dashboard'); ?></h3>
                            <p style="font-size: 15px; font-weight: 600; margin-bottom: 4px;"><?php echo esc_html($perplexity_settings['model'] ?? 'sonar'); ?></p>
                            <p class="description"><?php esc_html_e('Model selection stays in AI Settings so the provider tab remains the single source of truth for credentials and model defaults.', 'raw-wire-dashboard'); ?></p>
                        </div>

                        <div class="rawwire-settings-section active" style="border-left: 4px solid #14b8a6;">
                            <h3 style="margin-top: 0;"><?php esc_html_e('Per-Pass Settings', 'raw-wire-dashboard'); ?></h3>
                            <p class="description"><?php esc_html_e('Use the pass count above to control which passes are active. Each pass can use its own model override and prompt template. Leave the model override empty to inherit the AI Settings default.', 'raw-wire-dashboard'); ?></p>
                            <div class="rawwire-prompt-editor pi-perplexity-pass" data-pass="1" style="margin-bottom: 16px;">
                                <label for="pi_perplexity_model_pass_1" style="display:block; font-weight:600; margin-bottom:6px;"><?php esc_html_e('Pass 1 Model', 'raw-wire-dashboard'); ?></label>
                                <?php if (!empty($cached_perplexity_models) && is_array($cached_perplexity_models)): ?>
                                    <select name="rawwire_party_investigator_settings[perplexity_model_pass_1]" id="pi_perplexity_model_pass_1" class="regular-text" style="margin-bottom: 10px; min-width: 320px;">
                                        <option value=""><?php echo esc_html(sprintf(__('Use AI Settings default (%s)', 'raw-wire-dashboard'), $provider_default_perplexity_model)); ?></option>
                                        <?php foreach ($cached_perplexity_models as $model_id => $model_data): ?>
                                            <option value="<?php echo esc_attr($model_id); ?>" <?php selected((string) ($settings['perplexity_model_pass_1'] ?? ''), (string) $model_id); ?>>
                                                <?php echo esc_html(is_array($model_data) ? ($model_data['id'] ?? $model_id) : $model_id); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <input type="text" name="rawwire_party_investigator_settings[perplexity_model_pass_1]" id="pi_perplexity_model_pass_1" value="<?php echo esc_attr($settings['perplexity_model_pass_1'] ?? ''); ?>" class="regular-text" style="margin-bottom: 10px;" placeholder="<?php echo esc_attr($provider_default_perplexity_model); ?>">
                                <?php endif; ?>
                                <label for="pi_perplexity_prompt_pass_1" style="display:block; font-weight:600; margin-bottom:6px;"><?php esc_html_e('Pass 1 Prompt', 'raw-wire-dashboard'); ?></label>
                                <textarea name="rawwire_party_investigator_settings[perplexity_prompt_pass_1]" id="pi_perplexity_prompt_pass_1" rows="10" class="large-text code"><?php echo esc_textarea($settings['perplexity_prompt_pass_1'] ?? ''); ?></textarea>
                            </div>
                            <div class="rawwire-prompt-editor pi-perplexity-pass" data-pass="2" style="margin-bottom: 16px;">
                                <label for="pi_perplexity_model_pass_2" style="display:block; font-weight:600; margin-bottom:6px;"><?php esc_html_e('Pass 2 Model', 'raw-wire-dashboard'); ?></label>
                                <?php if (!empty($cached_perplexity_models) && is_array($cached_perplexity_models)): ?>
                                    <select name="rawwire_party_investigator_settings[perplexity_model_pass_2]" id="pi_perplexity_model_pass_2" class="regular-text" style="margin-bottom: 10px; min-width: 320px;">
                                        <option value=""><?php echo esc_html(sprintf(__('Use AI Settings default (%s)', 'raw-wire-dashboard'), $provider_default_perplexity_model)); ?></option>
                                        <?php foreach ($cached_perplexity_models as $model_id => $model_data): ?>
                                            <option value="<?php echo esc_attr($model_id); ?>" <?php selected((string) ($settings['perplexity_model_pass_2'] ?? ''), (string) $model_id); ?>>
                                                <?php echo esc_html(is_array($model_data) ? ($model_data['id'] ?? $model_id) : $model_id); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <input type="text" name="rawwire_party_investigator_settings[perplexity_model_pass_2]" id="pi_perplexity_model_pass_2" value="<?php echo esc_attr($settings['perplexity_model_pass_2'] ?? ''); ?>" class="regular-text" style="margin-bottom: 10px;" placeholder="<?php echo esc_attr($provider_default_perplexity_model); ?>">
                                <?php endif; ?>
                                <label for="pi_perplexity_prompt_pass_2" style="display:block; font-weight:600; margin-bottom:6px;"><?php esc_html_e('Pass 2 Prompt', 'raw-wire-dashboard'); ?></label>
                                <textarea name="rawwire_party_investigator_settings[perplexity_prompt_pass_2]" id="pi_perplexity_prompt_pass_2" rows="10" class="large-text code"><?php echo esc_textarea($settings['perplexity_prompt_pass_2'] ?? ''); ?></textarea>
                            </div>
                            <div class="rawwire-prompt-editor pi-perplexity-pass" data-pass="3">
                                <label for="pi_perplexity_model_pass_3" style="display:block; font-weight:600; margin-bottom:6px;"><?php esc_html_e('Pass 3 Model', 'raw-wire-dashboard'); ?></label>
                                <?php if (!empty($cached_perplexity_models) && is_array($cached_perplexity_models)): ?>
                                    <select name="rawwire_party_investigator_settings[perplexity_model_pass_3]" id="pi_perplexity_model_pass_3" class="regular-text" style="margin-bottom: 10px; min-width: 320px;">
                                        <option value=""><?php echo esc_html(sprintf(__('Use AI Settings default (%s)', 'raw-wire-dashboard'), $provider_default_perplexity_model)); ?></option>
                                        <?php foreach ($cached_perplexity_models as $model_id => $model_data): ?>
                                            <option value="<?php echo esc_attr($model_id); ?>" <?php selected((string) ($settings['perplexity_model_pass_3'] ?? ''), (string) $model_id); ?>>
                                                <?php echo esc_html(is_array($model_data) ? ($model_data['id'] ?? $model_id) : $model_id); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <input type="text" name="rawwire_party_investigator_settings[perplexity_model_pass_3]" id="pi_perplexity_model_pass_3" value="<?php echo esc_attr($settings['perplexity_model_pass_3'] ?? ''); ?>" class="regular-text" style="margin-bottom: 10px;" placeholder="<?php echo esc_attr($provider_default_perplexity_model); ?>">
                                <?php endif; ?>
                                <label for="pi_perplexity_prompt_pass_3" style="display:block; font-weight:600; margin-bottom:6px;"><?php esc_html_e('Pass 3 Prompt', 'raw-wire-dashboard'); ?></label>
                                <textarea name="rawwire_party_investigator_settings[perplexity_prompt_pass_3]" id="pi_perplexity_prompt_pass_3" rows="10" class="large-text code"><?php echo esc_textarea($settings['perplexity_prompt_pass_3'] ?? ''); ?></textarea>
                                <p class="description"><?php esc_html_e('Available placeholders: [BASE_PROMPT], [PARTY_NAME], [PERMIT_NUMBER], [LICENSE_NUMBER], [INVESTIGATION_TARGET], [RESEARCH_TEXT], [EVIDENCE_GAPS].', 'raw-wire-dashboard'); ?></p>
                            </div>
                        </div>

                        <p class="submit">
                            <button type="submit" class="button rawwire-btn-dark"><?php esc_html_e('Save Perplexity Investigation Settings', 'raw-wire-dashboard'); ?></button>
                        </p>
                    </form>
                <?php elseif ($subtab === 'veniceclaw'): ?>
                    <div class="rawwire-provider-callout" style="margin-bottom: 18px;">
                        <div class="rawwire-provider-metric">
                            <span><?php esc_html_e('Active Model', 'raw-wire-dashboard'); ?></span>
                            <strong><?php echo esc_html($venice_settings['model'] ?? 'zai-org-glm-4.7-flash'); ?></strong>
                        </div>
                        <div class="rawwire-provider-metric">
                            <span><?php esc_html_e('Pipeline Mode', 'raw-wire-dashboard'); ?></span>
                            <strong><?php echo esc_html(($settings['pipeline_mode'] ?? 'veniceclaw') === 'veniceclaw' ? __('Veniceclaw', 'raw-wire-dashboard') : __('Inactive', 'raw-wire-dashboard')); ?></strong>
                        </div>
                        <div class="rawwire-provider-metric">
                            <span><?php esc_html_e('Configuration Home', 'raw-wire-dashboard'); ?></span>
                            <strong><?php esc_html_e('AI Settings -> Venice.ai / OpenClaw', 'raw-wire-dashboard'); ?></strong>
                        </div>
                    </div>
                    <p class="description"><?php esc_html_e('This tab is reserved for Veniceclaw-specific workflow controls. The active browser-agent runtime continues to use the Lead Generator shared workflow settings and the provider defaults configured in AI Settings.', 'raw-wire-dashboard'); ?></p>
                <?php else: ?>
                    <div class="rawwire-provider-callout" style="margin-bottom: 18px;">
                        <div class="rawwire-provider-metric">
                            <span><?php esc_html_e('Selected Model', 'raw-wire-dashboard'); ?></span>
                            <strong><?php echo esc_html($openai_settings['model'] ?? 'gpt-4o-mini'); ?></strong>
                        </div>
                        <div class="rawwire-provider-metric">
                            <span><?php esc_html_e('Current Runtime', 'raw-wire-dashboard'); ?></span>
                            <strong><?php esc_html_e('Provider-backed AI Engine', 'raw-wire-dashboard'); ?></strong>
                        </div>
                        <div class="rawwire-provider-metric">
                            <span><?php esc_html_e('Configuration Home', 'raw-wire-dashboard'); ?></span>
                            <strong><?php esc_html_e('AI Settings -> OpenAI', 'raw-wire-dashboard'); ?></strong>
                        </div>
                    </div>
                    <p class="description"><?php esc_html_e('This tab is staged for future AI Engine-specific workflow controls. Model and provider defaults stay in AI Settings until the Lead Generator runtime adds a dedicated AI Engine lane.', 'raw-wire-dashboard'); ?></p>
                <?php endif; ?>

                <p class="description" style="margin-top: 14px;">
                    <?php esc_html_e('Need to change provider keys or model defaults? Use AI Settings. Need to change the active investigation pipeline? Stay here in Lead Generator.', 'raw-wire-dashboard'); ?>
                </p>
            </div>

            <!-- Investigation Stats -->
            <div class="rawwire-settings-section active" style="margin-top: 20px; border-left: 4px solid #6c757d;">
                <h3 style="margin-top: 0; display: flex; align-items: center; gap: 8px;">
                    <span class="dashicons dashicons-chart-bar"></span>
                    <?php esc_html_e('Investigation Statistics', 'raw-wire-dashboard'); ?>
                </h3>
                <?php
                                    global $wpdb;
                                    $table = $wpdb->prefix . 'rawwire_lead_sources';

                                    $stats = $wpdb->get_row("
                    SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN investigation_status = 'completed' THEN 1 ELSE 0 END) as completed,
                        SUM(CASE WHEN investigation_status = 'pending' THEN 1 ELSE 0 END) as pending,
                        SUM(CASE WHEN investigation_status = 'failed' THEN 1 ELSE 0 END) as failed,
                        SUM(CASE WHEN party_profiles IS NOT NULL AND party_profiles != '' THEN 1 ELSE 0 END) as with_profiles
                    FROM {$table}
                ");
                ?>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px;">
                    <div class="stat-card" style="background: #f8f9fa; padding: 16px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 24px; font-weight: bold; color: var(--rw-brand-gold, #f4b41a);"><?php echo esc_html($stats->completed ?? 0); ?></div>
                        <div style="font-size: 12px; color: #6c757d;"><?php esc_html_e('Investigations Completed', 'raw-wire-dashboard'); ?></div>
                    </div>
                    <div class="stat-card" style="background: #f8f9fa; padding: 16px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 24px; font-weight: bold; color: #17a2b8;"><?php echo esc_html($stats->pending ?? 0); ?></div>
                        <div style="font-size: 12px; color: #6c757d;"><?php esc_html_e('Pending', 'raw-wire-dashboard'); ?></div>
                    </div>
                    <div class="stat-card" style="background: #f8f9fa; padding: 16px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 24px; font-weight: bold; color: #28a745;"><?php echo esc_html($stats->with_profiles ?? 0); ?></div>
                        <div style="font-size: 12px; color: #6c757d;"><?php esc_html_e('With Party Profiles', 'raw-wire-dashboard'); ?></div>
                    </div>
                    <div class="stat-card" style="background: #f8f9fa; padding: 16px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 24px; font-weight: bold; color: #dc3545;"><?php echo esc_html($stats->failed ?? 0); ?></div>
                        <div style="font-size: 12px; color: #6c757d;"><?php esc_html_e('Failed', 'raw-wire-dashboard'); ?></div>
                    </div>
                </div>
            </div>
        </div>
    <?php
                                    $this->render_party_investigator_tab_scripts();
                                }

                                /**
                                 * Render Party Investigator tab scripts
                                 */
                                private function render_party_investigator_tab_scripts()
                                {
    ?>
        <script>
            jQuery(document).ready(function($) {
                function syncPerplexityPassEditors() {
                    var passCount = parseInt($('#pi_perplexity_pass_count').val() || '1', 10);
                    $('.pi-perplexity-pass').each(function() {
                        var pass = parseInt($(this).data('pass'), 10);
                        $(this).toggle(pass <= passCount);
                    });
                }

                $('#pi_perplexity_pass_count').on('change', syncPerplexityPassEditors);
                syncPerplexityPassEditors();
            });
        </script>
<?php
                                }

                                /**
                                 * Sanitize Party Investigator settings
                                 */
                                public function sanitize_party_investigator_settings($input)
                                {
                                    $existing = wp_parse_args(get_option('rawwire_party_investigator_settings', []), $this->get_default_party_investigator_settings());
                                    $sanitized = [];
                                    $sanitized['enabled'] = array_key_exists('enabled', $input) ? !empty($input['enabled']) : !empty($existing['enabled']);
                                    $sanitized['pipeline_mode'] = in_array($input['pipeline_mode'] ?? ($existing['pipeline_mode'] ?? 'veniceclaw'), ['perplexity_direct', 'veniceclaw'], true)
                                        ? ($input['pipeline_mode'] ?? $existing['pipeline_mode'])
                                        : 'veniceclaw';
                                    // Lane A only: retire Brave key from active settings.
                                    $sanitized['brave_api_key'] = '';
                                    $sanitized['search_depth'] = in_array($input['search_depth'] ?? ($existing['search_depth'] ?? 'standard'), ['basic', 'standard', 'deep'])
                                        ? ($input['search_depth'] ?? $existing['search_depth'])
                                        : 'standard';
                                    $sanitized['auto_investigate'] = array_key_exists('auto_investigate', $input) ? !empty($input['auto_investigate']) : !empty($existing['auto_investigate']);
                                    $sanitized['max_searches_per_party'] = absint($input['max_searches_per_party'] ?? ($existing['max_searches_per_party'] ?? 3));
                                    $sanitized['cache_hours'] = absint($input['cache_hours'] ?? ($existing['cache_hours'] ?? 24));
                                    $sanitized['reinvestigation_cooldown_minutes'] = absint($input['reinvestigation_cooldown_minutes'] ?? ($existing['reinvestigation_cooldown_minutes'] ?? 1));

                                    // Lane A only: force OpenClaw as the investigation provider.
                                    $sanitized['search_provider'] = 'openclaw';
                                    $sanitized['openclaw_auth_token'] = sanitize_text_field($input['openclaw_auth_token'] ?? ($existing['openclaw_auth_token'] ?? ''));
                                    $sanitized['investigation_model'] = '';
                                    $sanitized['deep_research'] = array_key_exists('deep_research', $input) ? !empty($input['deep_research']) : !empty($existing['deep_research']);
                                    $sanitized['perplexity_pass_count'] = array_key_exists('perplexity_pass_count', $input)
                                        ? min(3, max(1, absint($input['perplexity_pass_count'] ?? 2)))
                                        : min(3, max(1, absint($existing['perplexity_pass_count'] ?? 2)));
                                    $perplexity_preset = (string) ($input['perplexity_preset'] ?? ($existing['perplexity_preset'] ?? 'pro-search'));
                                    $sanitized['perplexity_preset'] = in_array($perplexity_preset, ['', 'fast-search', 'pro-search', 'deep-research', 'advanced-deep-research'], true)
                                        ? $perplexity_preset
                                        : 'pro-search';
                                    $perplexity_max_steps = $input['perplexity_max_steps'] ?? ($existing['perplexity_max_steps'] ?? '');
                                    $sanitized['perplexity_max_steps'] = $perplexity_max_steps === '' || $perplexity_max_steps === null
                                        ? ''
                                        : min(10, max(1, absint($perplexity_max_steps)));
                                    $sanitized['perplexity_search_mode'] = in_array($input['perplexity_search_mode'] ?? ($existing['perplexity_search_mode'] ?? 'web'), ['web', 'academic', 'sec'], true)
                                        ? ($input['perplexity_search_mode'] ?? $existing['perplexity_search_mode'])
                                        : 'web';
                                    $reasoning_effort = (string) ($input['perplexity_reasoning_effort'] ?? ($existing['perplexity_reasoning_effort'] ?? ''));
                                    $sanitized['perplexity_reasoning_effort'] = in_array($reasoning_effort, ['', 'off', 'minimal', 'low', 'medium', 'high'], true)
                                        ? $reasoning_effort
                                        : '';
                                    $top_p = $input['perplexity_top_p'] ?? ($existing['perplexity_top_p'] ?? '');
                                    $sanitized['perplexity_top_p'] = $top_p === '' || $top_p === null
                                        ? ''
                                        : max(0, min(1, (float) $top_p));
                                    $sanitized['perplexity_return_images'] = array_key_exists('perplexity_return_images', $input) ? !empty($input['perplexity_return_images']) : !empty($existing['perplexity_return_images']);
                                    $sanitized['perplexity_return_related_questions'] = array_key_exists('perplexity_return_related_questions', $input) ? !empty($input['perplexity_return_related_questions']) : !empty($existing['perplexity_return_related_questions']);
                                    $sanitized['perplexity_enable_search_classifier'] = array_key_exists('perplexity_enable_search_classifier', $input) ? !empty($input['perplexity_enable_search_classifier']) : !empty($existing['perplexity_enable_search_classifier']);
                                    $sanitized['perplexity_disable_search'] = array_key_exists('perplexity_disable_search', $input) ? !empty($input['perplexity_disable_search']) : !empty($existing['perplexity_disable_search']);
                                    $sanitized['perplexity_strip_thinking_response'] = array_key_exists('perplexity_strip_thinking_response', $input) ? !empty($input['perplexity_strip_thinking_response']) : !empty($existing['perplexity_strip_thinking_response']);
                                    $sanitized['perplexity_model_pass_1'] = sanitize_text_field($input['perplexity_model_pass_1'] ?? ($existing['perplexity_model_pass_1'] ?? ''));
                                    $sanitized['perplexity_model_pass_2'] = sanitize_text_field($input['perplexity_model_pass_2'] ?? ($existing['perplexity_model_pass_2'] ?? ''));
                                    $sanitized['perplexity_model_pass_3'] = sanitize_text_field($input['perplexity_model_pass_3'] ?? ($existing['perplexity_model_pass_3'] ?? ''));
                                    $sanitized['perplexity_prompt_pass_1'] = sanitize_textarea_field($input['perplexity_prompt_pass_1'] ?? ($existing['perplexity_prompt_pass_1'] ?? ''));
                                    $sanitized['perplexity_prompt_pass_2'] = sanitize_textarea_field($input['perplexity_prompt_pass_2'] ?? ($existing['perplexity_prompt_pass_2'] ?? ''));
                                    $sanitized['perplexity_prompt_pass_3'] = sanitize_textarea_field($input['perplexity_prompt_pass_3'] ?? ($existing['perplexity_prompt_pass_3'] ?? ''));

                                    // Clamp values
                                    $sanitized['max_searches_per_party'] = max(1, min(10, $sanitized['max_searches_per_party']));
                                    $sanitized['cache_hours'] = max(1, min(168, $sanitized['cache_hours']));
                                    $sanitized['reinvestigation_cooldown_minutes'] = min(1440, $sanitized['reinvestigation_cooldown_minutes']);

                                    return $sanitized;
                                }

                                /**
                                 * Register settings
                                 */
                                public function register_settings()
                                {
                                    // Each option gets its own settings group so saving one tab
                                    // does not wipe the others (WP options.php processes every option
                                    // in a group, setting missing ones to null).
                                    register_setting('rawwire_ai_adapter_group', 'rawwire_ai_adapter_settings', [
                                        'sanitize_callback' => [$this, 'sanitize_settings'],
                                    ]);

                                    register_setting('rawwire_mcp_group', 'rawwire_mcp_settings', [
                                        'sanitize_callback' => [$this, 'sanitize_mcp_settings'],
                                    ]);

                                    register_setting('rawwire_engine_group', 'rawwire_engine_extensions', [
                                        'sanitize_callback' => [$this, 'sanitize_engine_extensions'],
                                    ]);

                                    register_setting('rawwire_perplexity_group', 'rawwire_perplexity_settings', [
                                        'sanitize_callback' => [$this, 'sanitize_perplexity_settings'],
                                    ]);

                                    register_setting('rawwire_openai_group', 'rawwire_openai_settings', [
                                        'sanitize_callback' => [$this, 'sanitize_openai_settings'],
                                    ]);

                                    // Venice.ai settings
                                    register_setting('rawwire_venice_group', 'rawwire_venice_settings', [
                                        'sanitize_callback' => [$this, 'sanitize_venice_settings'],
                                    ]);

                                    // Instinct Context Engine settings
                                    register_setting('rawwire_instinct_group', 'rawwire_instinct_settings', [
                                        'sanitize_callback' => [$this, 'sanitize_instinct_settings'],
                                    ]);

                                    // OpenClaw Gateway settings
                                    register_setting('rawwire_openclaw_group', 'rawwire_openclaw_settings', [
                                        'sanitize_callback' => [$this, 'sanitize_openclaw_settings'],
                                    ]);

                                    // Party Investigator settings
                                    register_setting('rawwire_pi_group', 'rawwire_party_investigator_settings', [
                                        'sanitize_callback' => [$this, 'sanitize_party_investigator_settings'],
                                    ]);
                                }

                                /**
                                 * Sanitize Instinct settings
                                 * 
                                 * @param array $input Raw input
                                 * @return array Sanitized settings
                                 */
                                public function sanitize_instinct_settings($input)
                                {
                                    $sanitized = [];

                                    $sanitized['enabled'] = !empty($input['enabled']);
                                    $sanitized['host'] = sanitize_text_field($input['host'] ?? '127.0.0.1');
                                    $sanitized['port'] = absint($input['port'] ?? 8080);
                                    $sanitized['auto_inject'] = !empty($input['auto_inject']);
                                    $sanitized['include_mandatory'] = !empty($input['include_mandatory']);
                                    $sanitized['min_importance'] = min(100, max(0, absint($input['min_importance'] ?? 30)));
                                    $sanitized['max_tokens'] = min(100000, max(100, absint($input['max_tokens'] ?? 8000)));

                                    // Clear cache when settings change
                                    delete_transient('rawwire_instinct_status');

                                    return $sanitized;
                                }

                                /**
                                 * Sanitize Venice.ai settings
                                 * 
                                 * @param array $input Raw input
                                 * @return array Sanitized settings
                                 */
                                public function sanitize_venice_settings($input)
                                {
                                    $existing = wp_parse_args(get_option('rawwire_venice_settings', []), $this->get_default_venice_settings());
                                    $sanitized = [];

                                    $sanitized['api_key'] = sanitize_text_field($input['api_key'] ?? '');
                                    $sanitized['model'] = sanitize_text_field($input['model'] ?? 'zai-org-glm-4.7');
                                    $sanitized['max_tokens'] = absint($input['max_tokens'] ?? 4096);
                                    $sanitized['temperature'] = floatval($input['temperature'] ?? 0.7);
                                    $sanitized['top_p'] = max(0, min(1, (float) ($input['top_p'] ?? 0.9)));
                                    $sanitized['enable_web_search'] = in_array(($input['enable_web_search'] ?? 'off'), ['off', 'auto'], true)
                                        ? $input['enable_web_search']
                                        : 'off';
                                    $sanitized['enable_web_scraping'] = !empty($input['enable_web_scraping']);
                                    $sanitized['enable_web_citations'] = !empty($input['enable_web_citations']);
                                    $sanitized['reasoning_effort'] = in_array(($input['reasoning_effort'] ?? 'off'), ['off', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'], true)
                                        ? $input['reasoning_effort']
                                        : 'off';
                                    $sanitized['disable_thinking'] = !empty($input['disable_thinking']);
                                    $sanitized['parallel_tool_calls'] = !empty($input['parallel_tool_calls']);
                                    $sanitized['allow_tool_calls'] = !empty($input['allow_tool_calls']);
                                    $sanitized['allow_mcp_tools'] = !empty($input['allow_mcp_tools']);
                                    $sanitized['allow_openclaw_tools'] = !empty($input['allow_openclaw_tools']);
                                    $sanitized['include_venice_system_prompt'] = !empty($input['include_venice_system_prompt']);
                                    $sanitized['strip_thinking_response'] = array_key_exists('strip_thinking_response', $input)
                                        ? !empty($input['strip_thinking_response'])
                                        : !empty($existing['strip_thinking_response']);

                                    return $sanitized;
                                }

                                /**
                                 * Get default Venice.ai settings
                                 * 
                                 * @return array
                                 */
                                private function get_default_venice_settings()
                                {
                                    return [
                                        'api_key' => '',
                                        'model' => 'zai-org-glm-4.7-flash',
                                        'max_tokens' => 4096,
                                        'temperature' => 0.7,
                                        'top_p' => 0.9,
                                        'enable_web_search' => 'off',
                                        'enable_web_scraping' => false,
                                        'enable_web_citations' => false,
                                        'reasoning_effort' => 'off',
                                        'disable_thinking' => false,
                                        'parallel_tool_calls' => true,
                                        'allow_tool_calls' => true,
                                        'allow_mcp_tools' => true,
                                        'allow_openclaw_tools' => true,
                                        'include_venice_system_prompt' => false,
                                        'strip_thinking_response' => true,
                                    ];
                                }

                                private function get_default_perplexity_settings()
                                {
                                    return [
                                        'api_key' => '',
                                        'base_url' => 'https://api.perplexity.ai',
                                        'model' => 'sonar',
                                        'temperature' => 0.2,
                                        'max_tokens' => 8000,
                                        'top_p' => 0.9,
                                        'reasoning_effort' => 'off',
                                        'search_mode' => 'web',
                                        'return_images' => false,
                                        'return_related_questions' => false,
                                        'enable_search_classifier' => true,
                                        'disable_search' => false,
                                        'max_passes' => 2,
                                        'strip_thinking_response' => true,
                                    ];
                                }

                                public function sanitize_perplexity_settings($input)
                                {
                                    $existing = wp_parse_args(get_option('rawwire_perplexity_settings', []), $this->get_default_perplexity_settings());
                                    $sanitized = [];
                                    $sanitized['api_key'] = sanitize_text_field($input['api_key'] ?? '');
                                    $sanitized['base_url'] = esc_url_raw($input['base_url'] ?? 'https://api.perplexity.ai');
                                    $sanitized['model'] = sanitize_text_field($input['model'] ?? 'sonar');
                                    $sanitized['temperature'] = max(0, min(1, (float) ($input['temperature'] ?? 0.2)));
                                    $sanitized['max_tokens'] = min(128000, max(1000, absint($input['max_tokens'] ?? 8000)));
                                    $sanitized['top_p'] = max(0, min(1, (float) ($input['top_p'] ?? 0.9)));
                                    $sanitized['reasoning_effort'] = in_array(($input['reasoning_effort'] ?? 'off'), ['off', 'minimal', 'low', 'medium', 'high'], true)
                                        ? $input['reasoning_effort']
                                        : 'off';
                                    $sanitized['search_mode'] = in_array(($input['search_mode'] ?? 'web'), ['web', 'academic', 'sec'], true)
                                        ? $input['search_mode']
                                        : 'web';
                                    $sanitized['return_images'] = !empty($input['return_images']);
                                    $sanitized['return_related_questions'] = !empty($input['return_related_questions']);
                                    $sanitized['enable_search_classifier'] = !empty($input['enable_search_classifier']);
                                    $sanitized['disable_search'] = !empty($input['disable_search']);
                                    $sanitized['max_passes'] = array_key_exists('max_passes', $input)
                                        ? min(2, max(1, absint($input['max_passes'] ?? 2)))
                                        : min(2, max(1, absint($existing['max_passes'] ?? 2)));
                                    $sanitized['strip_thinking_response'] = array_key_exists('strip_thinking_response', $input)
                                        ? !empty($input['strip_thinking_response'])
                                        : !empty($existing['strip_thinking_response']);
                                    return $sanitized;
                                }

                                private function get_default_openai_settings()
                                {
                                    return [
                                        'base_url' => 'https://api.openai.com/v1',
                                        'openclaw_api_key' => '',
                                        'model' => 'gpt-4o-mini',
                                        'temperature' => 0.3,
                                        'max_tokens' => 4000,
                                        'top_p' => 1.0,
                                        'reasoning_effort' => 'off',
                                        'tool_choice' => 'auto',
                                        'parallel_tool_calls' => true,
                                        'allow_tool_calls' => true,
                                        'allow_mcp_tools' => true,
                                        'allow_openclaw_tools' => true,
                                    ];
                                }

                                public function sanitize_openai_settings($input)
                                {
                                    $sanitized = [];
                                    $sanitized['base_url'] = esc_url_raw($input['base_url'] ?? 'https://api.openai.com/v1');
                                    $sanitized['openclaw_api_key'] = sanitize_text_field($input['openclaw_api_key'] ?? '');
                                    $sanitized['model'] = sanitize_text_field($input['model'] ?? 'gpt-4o-mini');
                                    $sanitized['temperature'] = max(0, min(1, (float) ($input['temperature'] ?? 0.3)));
                                    $sanitized['max_tokens'] = min(128000, max(100, absint($input['max_tokens'] ?? 4000)));
                                    $sanitized['top_p'] = max(0, min(1, (float) ($input['top_p'] ?? 1.0)));
                                    $sanitized['reasoning_effort'] = in_array($input['reasoning_effort'] ?? 'off', ['off', 'minimal', 'low', 'medium', 'high'], true)
                                        ? $input['reasoning_effort']
                                        : 'off';
                                    $sanitized['tool_choice'] = in_array($input['tool_choice'] ?? 'auto', ['auto', 'required', 'none'], true)
                                        ? $input['tool_choice']
                                        : 'auto';
                                    $sanitized['parallel_tool_calls'] = !empty($input['parallel_tool_calls']);
                                    $sanitized['allow_tool_calls'] = !empty($input['allow_tool_calls']);
                                    $sanitized['allow_mcp_tools'] = !empty($input['allow_mcp_tools']);
                                    $sanitized['allow_openclaw_tools'] = !empty($input['allow_openclaw_tools']);
                                    return $sanitized;
                                }

                                /**
                                 * Sanitize OpenClaw settings
                                 *
                                 * @param array $input Raw input
                                 * @return array Sanitized settings
                                 */
                                public function sanitize_openclaw_settings($input)
                                {
                                    $sanitized = [];

                                    $sanitized['enabled'] = !empty($input['enabled']);
                                    $sanitized['provider'] = in_array($input['provider'] ?? 'venice', ['venice', 'openai', 'ollama'], true)
                                        ? $input['provider']
                                        : 'venice';
                                    $sanitized['host'] = esc_url_raw($input['host'] ?? 'https://api.venice.ai/api/v1');
                                    $sanitized['auth_token'] = sanitize_text_field($input['auth_token'] ?? '');
                                    $sanitized['model'] = sanitize_text_field($input['model'] ?? ''); // Empty = fall back to Venice model
                                    $sanitized['openai_base_url'] = esc_url_raw($input['openai_base_url'] ?? 'https://api.openai.com/v1');
                                    $sanitized['openai_api_key'] = sanitize_text_field($input['openai_api_key'] ?? '');
                                    $sanitized['openai_model'] = sanitize_text_field($input['openai_model'] ?? 'gpt-4o-mini');
                                    $sanitized['ollama_base_url'] = esc_url_raw($input['ollama_base_url'] ?? 'http://127.0.0.1:11434/v1');
                                    $sanitized['ollama_model'] = sanitize_text_field($input['ollama_model'] ?? 'qwen2.5:14b');
                                    $gateway_token = sanitize_text_field($input['gateway_auth_token'] ?? ($input['gateway_token'] ?? 'rawwire-local-dev-2025'));
                                    $sanitized['gateway_auth_token'] = $gateway_token;
                                    $sanitized['gateway_token'] = $gateway_token; // Backward-compatible alias
                                    $sanitized['max_tokens'] = min(128000, max(100, absint($input['max_tokens'] ?? 4000)));
                                    $sanitized['temperature'] = max(0, min(2, floatval($input['temperature'] ?? 0.3)));
                                    $sanitized['openclaw_home'] = sanitize_text_field($input['openclaw_home'] ?? '/tmp/openclaw-home');

                                    // Venice web features
                                    $sanitized['enable_web_search'] = !empty($input['enable_web_search']);
                                    $sanitized['enable_web_scraping'] = !empty($input['enable_web_scraping']);
                                    $sanitized['enable_web_citations'] = !empty($input['enable_web_citations']);

                                    // Analysis-specific parameters
                                    $sanitized['analysis_max_tokens'] = min(128000, max(1000, absint($input['analysis_max_tokens'] ?? 8000)));
                                    $sanitized['analysis_temperature'] = max(0, min(1, floatval($input['analysis_temperature'] ?? 0.3)));
                                    $sanitized['request_timeout'] = min(600, max(30, absint($input['request_timeout'] ?? 120)));
                                    $sanitized['max_retries'] = min(3, max(1, absint($input['max_retries'] ?? 2)));

                                    // Clear model cache when settings change
                                    delete_transient('rawwire_openclaw_models');
                                    delete_transient('rw_openclaw_health');

                                    return $sanitized;
                                }

                                /**
                                 * Sanitize AI adapter settings
                                 */
                                public function sanitize_settings($input)
                                {
                                    $sanitized = [];

                                    $sanitized['default_env_id'] = sanitize_text_field($input['default_env_id'] ?? '');
                                    $sanitized['default_model'] = sanitize_text_field($input['default_model'] ?? '');
                                    $sanitized['cache_ttl'] = absint($input['cache_ttl'] ?? 3600);
                                    $sanitized['fallback_enabled'] = !empty($input['fallback_enabled']);
                                    $sanitized['logging_enabled'] = !empty($input['logging_enabled']);

                                    return $sanitized;
                                }

                                /**
                                 * Sanitize MCP settings
                                 */
                                public function sanitize_mcp_settings($input)
                                {
                                    $sanitized = [];

                                    $sanitized['enabled'] = !empty($input['enabled']);
                                    $sanitized['require_auth'] = !empty($input['require_auth']);
                                    $sanitized['allowed_tools'] = array_map('sanitize_text_field', $input['allowed_tools'] ?? []);

                                    return $sanitized;
                                }

                                /**
                                 * Sanitize engine extension settings
                                 * 
                                 * These settings control Raw Wire's custom engine integrations that extend
                                 * AI Engine without modifying AI Engine files directly.
                                 */
                                public function sanitize_engine_extensions($input)
                                {
                                    $sanitized = [];

                                    // Ollama engine extension
                                    $sanitized['ollama_enabled'] = !empty($input['ollama_enabled']);
                                    $sanitized['ollama_endpoint'] = esc_url_raw($input['ollama_endpoint'] ?? 'http://ollama:11434');
                                    $sanitized['ollama_dynamic_models'] = !empty($input['ollama_dynamic_models']);

                                    // Groq engine extension
                                    $sanitized['groq_enabled'] = !empty($input['groq_enabled']);

                                    // Chatbot auto-sync control
                                    $sanitized['chatbot_auto_sync'] = !empty($input['chatbot_auto_sync']);

                                    // Apply chatbot auto-sync setting
                                    update_option('rawwire_chatbot_auto_sync', $sanitized['chatbot_auto_sync']);

                                    // Clear Ollama model cache when settings change
                                    if ($sanitized['ollama_enabled']) {
                                        delete_transient('rawwire_ollama_models');
                                    }

                                    return $sanitized;
                                }

                                /**
                                 * Get default engine extension settings
                                 * 
                                 * @return array
                                 */
                                private function get_default_engine_extensions()
                                {
                                    return [
                                        'ollama_enabled' => true,
                                        'ollama_endpoint' => 'http://ollama:11434',
                                        'ollama_dynamic_models' => true,
                                        'groq_enabled' => true,
                                        'chatbot_auto_sync' => false, // Disabled by default for full manual control
                                    ];
                                }

                                /**
                                 * Render AI settings panel
                                 * 
                                 * @deprecated Use render_page() instead - legacy method redirects to tabbed interface
                                 */
                                public function render()
                                {
                                    $this->render_page();
                                }

                                /**
                                 * AJAX: Test AI connection
                                 */
                                public function ajax_test_connection()
                                {
                                    check_ajax_referer('rawwire_ai_settings_nonce', 'nonce');

                                    if (!current_user_can('manage_options')) {
                                        wp_send_json_error(['message' => 'Permission denied']);
                                    }

                                    try {
                                        $ai = rawwire_ai();

                                        if (!$ai->is_available()) {
                                            wp_send_json_error(['message' => 'AI Engine is not installed or activated.']);
                                        }

                                        // Get current provider info for debugging
                                        $status = $ai->get_status();
                                        $provider = $status['default_env']['type'] ?? 'unknown';

                                        $prompt = isset($_POST['prompt']) ? sanitize_textarea_field($_POST['prompt']) : 'Say "Hello from Raw Wire!" in exactly 5 words.';

                                        // Set execution time limit for long AI calls
                                        if (!ini_get('safe_mode')) {
                                            set_time_limit(60);
                                        }

                                        $result = $ai->text_query($prompt);

                                        if (is_wp_error($result)) {
                                            wp_send_json_error([
                                                'message' => 'AI Error: ' . $result->get_error_message(),
                                                'provider' => $provider
                                            ]);
                                        }

                                        if (empty($result)) {
                                            wp_send_json_error([
                                                'message' => 'AI returned empty response. Check your API key and model settings.',
                                                'provider' => $provider
                                            ]);
                                        }

                                        wp_send_json_success([
                                            'message' => 'Connection successful! (' . ucfirst($provider) . ')',
                                            'result'  => $result,
                                            'provider' => $provider
                                        ]);
                                    } catch (Exception $e) {
                                        wp_send_json_error([
                                            'message' => 'Exception: ' . $e->getMessage()
                                        ]);
                                    }
                                }

                                /**
                                 * AJAX: Test Venice.ai connection
                                 */
                                public function ajax_venice_test_connection()
                                {
                                    check_ajax_referer('rawwire_ai_settings_nonce', 'nonce');

                                    if (!current_user_can('manage_options')) {
                                        wp_send_json_error(['message' => 'Permission denied']);
                                    }

                                    $settings = get_option('rawwire_venice_settings', $this->get_default_venice_settings());

                                    if (empty($settings['api_key'])) {
                                        wp_send_json_error(['message' => 'No API key configured. Please save your Venice.ai API key first.']);
                                    }

                                    try {
                                        // Create Venice adapter to test connection  
                                        if (!class_exists('RawWire_Adapter_Generator_Venice')) {
                                            require_once dirname(__FILE__) . '/../adapters/generators/class-generator-venice.php';
                                        }

                                        $adapter = new RawWire_Adapter_Generator_Venice([
                                            'api_key' => $settings['api_key'],
                                            'model' => $settings['model'] ?? 'zai-org-glm-4.7',
                                        ]);

                                        $result = $adapter->test_connection();

                                        if (is_array($result) && !empty($result['success'])) {
                                            wp_send_json_success([
                                                'message' => $result['message'] ?? 'Venice.ai connection successful!',
                                                'model' => $settings['model'] ?? 'zai-org-glm-4.7',
                                                'details' => $result['details'] ?? [],
                                            ]);
                                        } else {
                                            $error_msg = is_array($result) ? ($result['message'] ?? 'Connection test failed.') : 'Connection test failed. Check your API key.';
                                            wp_send_json_error(['message' => $error_msg]);
                                        }
                                    } catch (Exception $e) {
                                        wp_send_json_error([
                                            'message' => 'Exception: ' . $e->getMessage()
                                        ]);
                                    }
                                }

                                /**
                                 * AJAX: Refresh Venice models from API
                                 */
                                public function ajax_refresh_venice_models()
                                {
                                    check_ajax_referer('rawwire_ai_settings_nonce', 'nonce');

                                    if (!current_user_can('manage_options')) {
                                        wp_send_json_error(['message' => 'Permission denied']);
                                    }

                                    $settings = get_option('rawwire_venice_settings', array());

                                    if (empty($settings['api_key'])) {
                                        wp_send_json_error(['message' => 'No API key configured. Please save your Venice.ai API key first.']);
                                    }

                                    try {
                                        // Load Venice adapter
                                        if (!class_exists('RawWire_Adapter_Generator_Venice')) {
                                            require_once dirname(__FILE__) . '/../adapters/generators/class-generator-venice.php';
                                        }

                                        // Force refresh models from API
                                        $models = RawWire_Adapter_Generator_Venice::fetch_models_from_api(true);

                                        if ($models === false) {
                                            wp_send_json_error(['message' => 'Failed to fetch models from Venice API. Check your API key.']);
                                        }

                                        wp_send_json_success([
                                            'message' => sprintf('%d models loaded from Venice API', count($models)),
                                            'count' => count($models),
                                        ]);
                                    } catch (Exception $e) {
                                        wp_send_json_error(['message' => 'Exception: ' . $e->getMessage()]);
                                    }
                                }

                                /**
                                 * AJAX: Refresh Perplexity models from API
                                 */
                                public function ajax_refresh_perplexity_models()
                                {
                                    check_ajax_referer('rawwire_ai_settings_nonce', 'nonce');

                                    if (!current_user_can('manage_options')) {
                                        wp_send_json_error(['message' => 'Permission denied']);
                                    }

                                    $settings = get_option('rawwire_perplexity_settings', $this->get_default_perplexity_settings());
                                    $api_key = (string) ($settings['api_key'] ?? '');
                                    $base_url = (string) ($settings['base_url'] ?? 'https://api.perplexity.ai');

                                    if ($api_key === '') {
                                        wp_send_json_error(['message' => 'No API key configured. Save your Perplexity API key first.']);
                                    }

                                    try {
                                        $models = $this->fetch_openai_compatible_models($base_url, $api_key, 'rawwire_perplexity_models');
                                        wp_send_json_success([
                                            'message' => sprintf('%d models available', count($models)),
                                            'count' => count($models),
                                        ]);
                                    } catch (Exception $e) {
                                        wp_send_json_error(['message' => 'Exception: ' . $e->getMessage()]);
                                    }
                                }

                                /**
                                 * AJAX: Refresh OpenAI models from API
                                 */
                                public function ajax_refresh_openai_models()
                                {
                                    check_ajax_referer('rawwire_ai_settings_nonce', 'nonce');

                                    if (!current_user_can('manage_options')) {
                                        wp_send_json_error(['message' => 'Permission denied']);
                                    }

                                    $settings = get_option('rawwire_openai_settings', $this->get_default_openai_settings());
                                    $api_key = (string) ($settings['openclaw_api_key'] ?? '');
                                    $base_url = (string) ($settings['base_url'] ?? 'https://api.openai.com/v1');

                                    if ($api_key === '') {
                                        wp_send_json_error(['message' => 'No API key configured. Save your API key first.']);
                                    }

                                    try {
                                        $models = $this->fetch_openai_compatible_models($base_url, $api_key, 'rawwire_openai_models');
                                        wp_send_json_success([
                                            'message' => sprintf('%d models available', count($models)),
                                            'count' => count($models),
                                        ]);
                                    } catch (Exception $e) {
                                        wp_send_json_error(['message' => 'Exception: ' . $e->getMessage()]);
                                    }
                                }

                                /**
                                 * AJAX: Refresh Ollama models
                                 */
                                public function ajax_refresh_ollama_models()
                                {
                                    check_ajax_referer('rawwire_ai_settings_nonce', 'nonce');

                                    if (!current_user_can('manage_options')) {
                                        wp_send_json_error(['message' => 'Permission denied']);
                                    }

                                    $settings = get_option('rawwire_engine_extensions', []);

                                    if (empty($settings['ollama_enabled'])) {
                                        wp_send_json_error(['message' => 'Ollama is not enabled. Enable it in Engine Extensions first.']);
                                    }

                                    try {
                                        if (!class_exists('RawWire_Adapter_Generator_Ollama')) {
                                            require_once dirname(__FILE__) . '/../adapters/generators/class-generator-ollama.php';
                                        }

                                        $ollama = new RawWire_Adapter_Generator_Ollama();
                                        $models = $ollama->list_models();

                                        if (empty($models)) {
                                            wp_send_json_error(['message' => 'No models found. Ensure Ollama is running.']);
                                        }

                                        // Cache the model list
                                        update_option('rawwire_ollama_models_cache', $models);

                                        wp_send_json_success([
                                            'message' => sprintf('%d models found on Ollama', count($models)),
                                            'count'   => count($models),
                                        ]);
                                    } catch (Exception $e) {
                                        wp_send_json_error(['message' => 'Exception: ' . $e->getMessage()]);
                                    }
                                }

                                /**
                                 * AJAX: Test Instinct connection
                                 */
                                public function ajax_instinct_test_connection()
                                {
                                    check_ajax_referer('rawwire_ai_settings_nonce', 'nonce');

                                    if (!current_user_can('manage_options')) {
                                        wp_send_json_error(['message' => 'Permission denied']);
                                    }

                                    $settings = get_option('rawwire_instinct_settings', array(
                                        'host' => '127.0.0.1',
                                        'port' => 8080,
                                    ));

                                    try {
                                        // Load Instinct adapter
                                        if (!class_exists('RawWire_Adapter_Context_Instinct')) {
                                            require_once dirname(__FILE__) . '/../adapters/context/class-context-instinct.php';
                                        }

                                        $adapter = new RawWire_Adapter_Context_Instinct(array(
                                            'host' => $settings['host'] ?? '127.0.0.1',
                                            'port' => $settings['port'] ?? 8080,
                                        ));

                                        $result = $adapter->test_connection();

                                        if (!empty($result['success'])) {
                                            wp_send_json_success([
                                                'message' => $result['message'] ?? 'Instinct service connected',
                                                'details' => $result['details'] ?? [],
                                            ]);
                                        } else {
                                            wp_send_json_error([
                                                'message' => $result['message'] ?? 'Connection failed. Is the Instinct service running?',
                                            ]);
                                        }
                                    } catch (Exception $e) {
                                        wp_send_json_error(['message' => 'Exception: ' . $e->getMessage()]);
                                    }
                                }

                                /**
                                 * AJAX: Get Instinct stats
                                 */
                                public function ajax_instinct_get_stats()
                                {
                                    check_ajax_referer('rawwire_ai_settings_nonce', 'nonce');

                                    if (!current_user_can('manage_options')) {
                                        wp_send_json_error(['message' => 'Permission denied']);
                                    }

                                    $settings = get_option('rawwire_instinct_settings', array(
                                        'host' => '127.0.0.1',
                                        'port' => 8080,
                                    ));

                                    try {
                                        if (!class_exists('RawWire_Adapter_Context_Instinct')) {
                                            require_once dirname(__FILE__) . '/../adapters/context/class-context-instinct.php';
                                        }

                                        $adapter = new RawWire_Adapter_Context_Instinct(array(
                                            'host' => $settings['host'] ?? '127.0.0.1',
                                            'port' => $settings['port'] ?? 8080,
                                        ));

                                        $result = $adapter->get_stats();

                                        if (!empty($result['success'])) {
                                            wp_send_json_success($result['stats']);
                                        } else {
                                            wp_send_json_error([
                                                'message' => $result['error'] ?? 'Failed to get stats',
                                            ]);
                                        }
                                    } catch (Exception $e) {
                                        wp_send_json_error(['message' => 'Exception: ' . $e->getMessage()]);
                                    }
                                }

                                /**
                                 * AJAX: Get AI status
                                 */
                                public function ajax_get_status()
                                {
                                    check_ajax_referer('rawwire_ai_settings_nonce', 'nonce');

                                    if (!current_user_can('manage_options')) {
                                        wp_send_json_error(['message' => 'Permission denied']);
                                    }

                                    $ai = rawwire_ai();
                                    wp_send_json_success($ai->get_status());
                                }

                                /**
                                 * AJAX: Get models for a given environment
                                 */
                                public function ajax_get_models()
                                {
                                    check_ajax_referer('rawwire_ai_settings_nonce', 'nonce');

                                    if (!current_user_can('manage_options')) {
                                        wp_send_json_error(['message' => 'Permission denied']);
                                    }

                                    $env_id = sanitize_text_field($_POST['envId'] ?? '');
                                    if (empty($env_id)) {
                                        wp_send_json_success(['models' => []]);
                                    }

                                    $ai = rawwire_ai();
                                    $models = $ai->get_models_for_env($env_id);

                                    wp_send_json_success(['models' => $models]);
                                }

                                /**
                                 * AJAX: List MCP tools
                                 */
                                public function ajax_list_mcp_tools()
                                {
                                    check_ajax_referer('rawwire_ai_settings_nonce', 'nonce');

                                    if (!current_user_can('manage_options')) {
                                        wp_send_json_error(['message' => 'Permission denied']);
                                    }

                                    $mcp = RawWire_MCP_Server::get_instance();
                                    $tools = $mcp->get_tools();

                                    $tool_list = [];
                                    foreach ($tools as $name => $tool) {
                                        $tool_list[] = [
                                            'name'        => $name,
                                            'description' => $tool['description'] ?? '',
                                        ];
                                    }

                                    wp_send_json_success(['tools' => $tool_list]);
                                }

                                /**
                                 * AJAX: Test OpenClaw / Venice connection
                                 *
                                 * Uses RawWire_OpenClaw_Adapter which auto-resolves auth from
                                 * OpenClaw settings → Venice API key (no explicit token required
                                 * when running in Venice direct mode).
                                 */
                                public function ajax_openclaw_test_connection()
                                {
                                    check_ajax_referer('rawwire_ai_settings_nonce', 'nonce');

                                    if (!current_user_can('manage_options')) {
                                        wp_send_json_error(['message' => 'Permission denied']);
                                    }

                                    $settings = get_option('rawwire_openclaw_settings', $this->get_default_openclaw_settings());

                                    try {
                                        if (!class_exists('RawWire_OpenClaw_Adapter')) {
                                            require_once dirname(__FILE__) . '/../../lead-generator/class-openclaw-adapter.php';
                                        }

                                        $adapter = new RawWire_OpenClaw_Adapter();

                                        // Clear cached health so we get a fresh check
                                        delete_transient('rw_openclaw_health');

                                        if ($adapter->is_available()) {
                                            $host = $settings['host'] ?: 'https://api.venice.ai/api/v1';
                                            $provider = $settings['provider'] ?? 'venice';
                                            if ($provider === 'openai') {
                                                $mode = 'OpenAI-Compatible';
                                                $host = $settings['openai_base_url'] ?? $host;
                                            } elseif ($provider === 'ollama') {
                                                $mode = 'Ollama';
                                                $host = $settings['ollama_base_url'] ?? $host;
                                            } else {
                                                $mode = (empty($settings['host']) || strpos($settings['host'], 'venice.ai') !== false)
                                                    ? 'Venice Direct' : 'Gateway';
                                            }

                                            wp_send_json_success([
                                                'message' => "Connected — {$mode} mode",
                                                'model'   => $settings['model'] ?? 'olafangensan-glm-4.7-flash-heretic',
                                                'details' => [
                                                    'mode' => $mode,
                                                    'host' => $host,
                                                ],
                                            ]);
                                        } else {
                                            // Determine why
                                            $venice_settings = get_option('rawwire_venice_settings', []);
                                            $provider = $settings['provider'] ?? 'venice';
                                            if ($provider === 'openai') {
                                                $has_auth = !empty($settings['openai_api_key']);
                                            } elseif ($provider === 'ollama') {
                                                $has_auth = true;
                                            } else {
                                                $has_auth = !empty($settings['auth_token']) || !empty($venice_settings['api_key']);
                                            }

                                            if (!$has_auth) {
                                                wp_send_json_error(['message' => 'No API key found. Set an auth token above, or configure a Venice API key on the Venice tab.']);
                                            } else {
                                                wp_send_json_error(['message' => 'Connection failed. Check the host URL and ensure the API key is valid.']);
                                            }
                                        }
                                    } catch (Exception $e) {
                                        wp_send_json_error(['message' => 'Exception: ' . $e->getMessage()]);
                                    }
                                }

                                /**
                                 * AJAX: Refresh OpenClaw / Venice model list
                                 *
                                 * Queries the /models endpoint using the same auth resolution
                                 * as RawWire_OpenClaw_Adapter (OpenClaw token → Venice API key).
                                 */
                                public function ajax_openclaw_refresh_models()
                                {
                                    check_ajax_referer('rawwire_ai_settings_nonce', 'nonce');

                                    if (!current_user_can('manage_options')) {
                                        wp_send_json_error(['message' => 'Permission denied']);
                                    }

                                    $settings = get_option('rawwire_openclaw_settings', $this->get_default_openclaw_settings());

                                    try {
                                        $provider = $settings['provider'] ?? 'venice';
                                        if ($provider === 'openai') {
                                            $host = $settings['openai_base_url'] ?? 'https://api.openai.com/v1';
                                            $auth_token = $settings['openai_api_key'] ?? '';
                                        } elseif ($provider === 'ollama') {
                                            $host = $settings['ollama_base_url'] ?? 'http://127.0.0.1:11434/v1';
                                            $auth_token = '';
                                        } else {
                                            $host = $settings['host'] ?: 'https://api.venice.ai/api/v1';
                                            $auth_token = $settings['auth_token'] ?? '';
                                        }
                                        $host = rtrim($host, '/');

                                        // Resolve auth (provider-aware)
                                        if ($provider === 'venice' && empty($auth_token)) {
                                            $venice_settings = get_option('rawwire_venice_settings', []);
                                            $auth_token = $venice_settings['api_key'] ?? '';
                                        }

                                        if ($provider !== 'ollama' && empty($auth_token)) {
                                            wp_send_json_error(['message' => 'No API key. Set an auth token above, or configure a Venice API key on the Venice tab.']);
                                            return;
                                        }

                                        // Clear model cache
                                        delete_transient('rawwire_openclaw_models');

                                        $request_args = [
                                            'headers' => [
                                                'Accept' => 'application/json',
                                            ],
                                            'timeout' => 15,
                                        ];
                                        if (!empty($auth_token)) {
                                            $request_args['headers']['Authorization'] = 'Bearer ' . $auth_token;
                                        }
                                        $response = wp_remote_get($host . '/models', $request_args);

                                        if (is_wp_error($response)) {
                                            wp_send_json_error(['message' => 'Connection failed: ' . $response->get_error_message()]);
                                            return;
                                        }

                                        $code = wp_remote_retrieve_response_code($response);
                                        if ($code !== 200) {
                                            wp_send_json_error(['message' => "API returned HTTP {$code}. Check host and credentials."]);
                                            return;
                                        }

                                        $body   = json_decode(wp_remote_retrieve_body($response), true);
                                        $models = $body['data'] ?? [];

                                        if (empty($models)) {
                                            wp_send_json_error(['message' => 'No models returned from API.']);
                                            return;
                                        }

                                        $model_ids = array_column($models, 'id');

                                        wp_send_json_success([
                                            'message' => sprintf('%d models available', count($model_ids)),
                                            'count'   => count($model_ids),
                                        ]);
                                    } catch (Exception $e) {
                                        wp_send_json_error(['message' => 'Exception: ' . $e->getMessage()]);
                                    }
                                }

                                /**
                                 * Query an OpenAI-compatible /models endpoint and cache the result.
                                 *
                                 * @param string $base_url
                                 * @param string $api_key
                                 * @param string $transient_key
                                 * @return array
                                 */
                                private function fetch_openai_compatible_models($base_url, $api_key, $transient_key)
                                {
                                    $base_url = rtrim((string) $base_url, '/');
                                    delete_transient($transient_key);

                                    $request_args = [
                                        'headers' => [
                                            'Accept' => 'application/json',
                                            'Authorization' => 'Bearer ' . $api_key,
                                        ],
                                        'timeout' => 15,
                                    ];
                                    $attempts = [];

                                    foreach ($this->get_openai_compatible_model_endpoints($base_url) as $models_url) {
                                        $response = wp_remote_get($models_url, $request_args);
                                        if (is_wp_error($response)) {
                                            $attempts[] = 'Connection failed for ' . $models_url . ': ' . $response->get_error_message();
                                            continue;
                                        }

                                        $code = wp_remote_retrieve_response_code($response);
                                        if ($code !== 200) {
                                            $attempts[] = "{$models_url} returned HTTP {$code}";
                                            continue;
                                        }

                                        $body = json_decode(wp_remote_retrieve_body($response), true);
                                        if (!is_array($body)) {
                                            $attempts[] = 'Invalid JSON returned from ' . $models_url;
                                            continue;
                                        }

                                        $normalized = $this->normalize_openai_compatible_models($body);
                                        if (!empty($normalized)) {
                                            set_transient($transient_key, $normalized, 6 * HOUR_IN_SECONDS);
                                            return $normalized;
                                        }

                                        $attempts[] = 'No usable model identifiers returned from ' . $models_url;
                                    }

                                    throw new Exception(implode(' | ', array_slice($attempts, 0, 3)) ?: 'No models returned from API.');
                                }

                                /**
                                 * Build candidate /models endpoints for providers that may or may not include /v1 in the base URL.
                                 *
                                 * @param string $base_url
                                 * @return array
                                 */
                                private function get_openai_compatible_model_endpoints($base_url)
                                {
                                    $endpoints = [$base_url . '/models'];
                                    if (!preg_match('#/v\d+$#', $base_url)) {
                                        $endpoints[] = $base_url . '/v1/models';
                                    }

                                    return array_values(array_unique($endpoints));
                                }

                                /**
                                 * Normalize several common /models response shapes into an id-keyed array.
                                 *
                                 * @param array $body
                                 * @return array
                                 */
                                private function normalize_openai_compatible_models(array $body)
                                {
                                    $models = [];
                                    if (!empty($body['data']) && is_array($body['data'])) {
                                        $models = $body['data'];
                                    } elseif (!empty($body['models']) && is_array($body['models'])) {
                                        $models = $body['models'];
                                    } elseif (array_is_list($body)) {
                                        $models = $body;
                                    }

                                    $normalized = [];
                                    foreach ($models as $model) {
                                        if (is_string($model) && $model !== '') {
                                            $normalized[$model] = ['id' => $model];
                                            continue;
                                        }

                                        if (is_array($model) && !empty($model['id'])) {
                                            $normalized[(string) $model['id']] = $model;
                                        }
                                    }

                                    return $normalized;
                                }
                            }

                            // Initialize
                            RawWire_AI_Settings_Panel::get_instance();
