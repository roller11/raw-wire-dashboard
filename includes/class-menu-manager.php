<?php

/**
 * @ai-context Search Instinct MCP for "Menu Manager Function Map v8" before modifying this file.
 */

/**
 * ╔═══════════════════════════════════════════════════════════════════════════╗
 * ║                                                                           ║
 * ║  ███╗   ███╗███████╗███╗   ██╗██╗   ██╗                                   ║
 * ║  ████╗ ████║██╔════╝████╗  ██║██║   ██║                                   ║
 * ║  ██╔████╔██║█████╗  ██╔██╗ ██║██║   ██║                                   ║
 * ║  ██║╚██╔╝██║██╔══╝  ██║╚██╗██║██║   ██║                                   ║
 * ║  ██║ ╚═╝ ██║███████╗██║ ╚████║╚██████╔╝                                   ║
 * ║  ╚═╝     ╚═╝╚══════╝╚═╝  ╚═══╝ ╚═════╝                                    ║
 * ║                                                                           ║
 * ║  ███╗   ███╗ █████╗ ███╗   ██╗ █████╗  ██████╗ ███████╗██████╗           ║
 * ║  ████╗ ████║██╔══██╗████╗  ██║██╔══██╗██╔════╝ ██╔════╝██╔══██╗          ║
 * ║  ██╔████╔██║███████║██╔██╗ ██║███████║██║  ███╗█████╗  ██████╔╝          ║
 * ║  ██║╚██╔╝██║██╔══██║██║╚██╗██║██╔══██║██║   ██║██╔══╝  ██╔══██╗          ║
 * ║  ██║ ╚═╝ ██║██║  ██║██║ ╚████║██║  ██║╚██████╔╝███████╗██║  ██║          ║
 * ║  ╚═╝     ╚═╝╚═╝  ╚═╝╚═╝  ╚═══╝╚═╝  ╚═╝ ╚═════╝ ╚══════╝╚═╝  ╚═╝          ║
 * ║                                                                           ║
 * ╠═══════════════════════════════════════════════════════════════════════════╣
 * ║                                                                           ║
 * ║   ⚠️  STOP! SUBSYSTEM #2: MENU & NAVIGATION  ⚠️                           ║
 * ║                                                                           ║
 * ║   📚 Full Documentation: docs/SUBSYSTEM_AUDIT.md (Section 2)             ║
 * ║                                                                           ║
 * ║   This class manages the ENTIRE menu/subpage structure for Raw-Wire.      ║
 * ║                                                                           ║
 * ║   ARCHITECTURAL RULES:                                                    ║
 * ║                                                                           ║
 * ║   1. Module Core HARDWIRES the menu structure                             ║
 * ║   2. Tools REGISTER their tabs (not separate submenus)                    ║
 * ║   3. Templates ACTIVATE tools, which shows their tabs                     ║
 * ║   4. Workflows from templates register to the Workflows page              ║
 * ║                                                                           ║
 * ║   MENU STRUCTURE (hardwired):                                             ║
 * ║   ┌─────────────────────────────────────────────────────────────────┐     ║
 * ║   │ Raw Wire (Dashboard)        ← Always visible                    │     ║
 * ║   │ ├── Templates               ← Always visible (load templates)  │     ║
 * ║   │ ├── Tools                   ← Multi-tab (tabs from tools)      │     ║
 * ║   │ ├── Workflows               ← Multi-tab (tabs from template)   │     ║
 * ║   │ └── Settings                ← General + Developer Tools        │     ║
 * ║   └─────────────────────────────────────────────────────────────────┘     ║
 * ║                                                                           ║
 * ║   DEFAULT STATE (no template loaded):                                     ║
 * ║   • Dashboard shows blank/minimal UI                                      ║
 * ║   • Only Templates submenu visible (to load a template)                   ║
 * ║   • Settings visible for developers                                       ║
 * ║                                                                           ║
 * ║   WITH TEMPLATE LOADED:                                                   ║
 * ║   • Template activates permitted tools                                    ║
 * ║   • Tools register their tabs to the Tools page                           ║
 * ║   • Workflows from template appear as tabs                                ║
 * ║   • Panels configured by template                                         ║
 * ║                                                                           ║
 * ║   DO NOT:                                                                 ║
 * ║   ✗ Create separate submenus for each tool                               ║
 * ║   ✗ Let templates define menu structure                                  ║
 * ║   ✗ Bypass this manager for menu registration                            ║
 * ║                                                                           ║
 * ╚═══════════════════════════════════════════════════════════════════════════╝
 *
 * @package RawWire_Dashboard
 * @subpackage Core
 */

if (!defined('ABSPATH')) {
    exit;
}

class RawWire_Menu_Manager
{

    /**
     * Main menu slug
     */
    const MENU_SLUG = 'raw-wire-dashboard';

    /**
     * Registered tool tabs
     * Tools register their tabs here when activated
     */
    private static $tool_tabs = array();

    /**
     * Registered workflow tabs
     * Workflows from templates register here
     */
    private static $workflow_tabs = array();

    /**
     * Active template config (cached)
     */
    private static $active_template = null;

    /**
     * ╔═══════════════════════════════════════════════════════════════════════╗
     * ║  INITIALIZATION                                                       ║
     * ║                                                                       ║
     * ║  STOP! This is the ONLY place menus should be registered.             ║
     * ║  Tools and workflows register TABS, not submenus.                     ║
     * ╚═══════════════════════════════════════════════════════════════════════╝
     */
    public static function init()
    {
        // Register menus at priority 5 (before other plugins)
        add_action('admin_menu', array(__CLASS__, 'register_menus'), 5);

        // Allow tools to register their tabs
        add_action('rawwire_register_tool_tabs', array(__CLASS__, 'collect_tool_tabs'), 10);

        // Allow workflows to register their tabs
        add_action('rawwire_register_workflow_tabs', array(__CLASS__, 'collect_workflow_tabs'), 10);
    }

    /**
     * ╔═══════════════════════════════════════════════════════════════════════╗
     * ║  REGISTER MENUS - HARDWIRED STRUCTURE                                 ║
     * ║                                                                       ║
     * ║  STOP! This defines the ENTIRE menu structure.                        ║
     * ║  Do not add submenus elsewhere - register TABS instead.               ║
     * ║                                                                       ║
     * ║  PRODUCTION VIEW (always visible, 3 pages):                            ║
     * ║  - Dashboard (main page - overview + template content)                ║
     * ║  - Soothsayer (intelligence action center)                            ║
     * ║  - User Options (end-user preferences and settings)                   ║
     * ║                                                                       ║
     * ║  DEVELOPER VIEW (unlocked via Developer button + password):           ║
     * ║  - AI Settings, Tools, Lead Generator, Approvals, Leads, Setup       ║
     * ║  - Templates, AI Agents, Options                                      ║
     * ╚═══════════════════════════════════════════════════════════════════════╝
     */
    public static function register_menus()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Load active template to determine what's enabled
        self::$active_template = self::get_active_template();
        $has_template = !empty(self::$active_template);

        // Check developer mode status
        $dev_mode = class_exists('RawWire_Dev_Auth') ? RawWire_Dev_Auth::is_dev_mode_active() : false;

        // Fire action for tools/workflows to register their tabs BEFORE we build menus
        do_action('rawwire_register_tool_tabs');
        do_action('rawwire_register_workflow_tabs');

        // ═══════════════════════════════════════════════════════════════════
        // MAIN MENU - Raw Wire Dashboard
        // ═══════════════════════════════════════════════════════════════════
        add_menu_page(
            __('Raw-Wire', 'raw-wire-dashboard'),
            __('Raw-Wire', 'raw-wire-dashboard'),
            'manage_options',
            self::MENU_SLUG,
            array(__CLASS__, 'render_dashboard_page'),
            'dashicons-chart-line',
            26
        );

        // Remove duplicate "Raw-Wire" submenu item that WordPress auto-creates
        remove_submenu_page(self::MENU_SLUG, self::MENU_SLUG);

        // ═══════════════════════════════════════════════════════════════════
        // PRODUCTION PAGES — Always visible (3 end-user pages)
        // These are the ONLY pages the end user interacts with.
        // ═══════════════════════════════════════════════════════════════════

        // 1. Dashboard — Main overview page
        add_submenu_page(
            self::MENU_SLUG,
            __('Dashboard', 'raw-wire-dashboard'),
            __('Dashboard', 'raw-wire-dashboard'),
            'manage_options',
            self::MENU_SLUG,
            array(__CLASS__, 'render_dashboard_page')
        );

        // 2. Soothsayer — Intelligence Action Center
        add_submenu_page(
            self::MENU_SLUG,
            __('Soothsayer', 'raw-wire-dashboard'),
            __('Soothsayer', 'raw-wire-dashboard'),
            'manage_options',
            'rawwire-soothsayer',
            array(__CLASS__, 'render_soothsayer_page')
        );

        // 3. User Options — End-user preferences and settings
        add_submenu_page(
            self::MENU_SLUG,
            __('User Options', 'raw-wire-dashboard'),
            __('User Options', 'raw-wire-dashboard'),
            'manage_options',
            'rawwire-user-options',
            array(__CLASS__, 'render_user_options_page')
        );

        // ═══════════════════════════════════════════════════════════════════
        // DEVELOPER PAGES — Only visible when dev mode is active
        // Unlocked via Developer button + password (RawWire_Dev_Auth)
        // ═══════════════════════════════════════════════════════════════════
        if ($dev_mode) {

            // AI Settings — Venice, Instinct, MCP, Engine, OpenClaw, Party Investigator
            add_submenu_page(
                self::MENU_SLUG,
                __('AI Settings', 'raw-wire-dashboard'),
                __('AI Settings', 'raw-wire-dashboard'),
                'manage_options',
                'rawwire-ai-settings',
                array(__CLASS__, 'render_ai_settings_page')
            );

            // Tools — Simple toggles to enable/disable
            add_submenu_page(
                self::MENU_SLUG,
                __('Tools', 'raw-wire-dashboard'),
                __('Tools', 'raw-wire-dashboard'),
                'manage_options',
                'rawwire-tools',
                array(__CLASS__, 'render_tools_toggles_page')
            );

            // Lead Generator pipeline pages
            add_submenu_page(
                self::MENU_SLUG,
                __('Lead Generator', 'raw-wire-dashboard'),
                __('Lead Generator', 'raw-wire-dashboard'),
                'manage_options',
                'rawwire-lead-sources',
                array(__CLASS__, 'render_lead_sources_page')
            );

            add_submenu_page(
                self::MENU_SLUG,
                __('Approvals', 'raw-wire-dashboard'),
                __('Approvals', 'raw-wire-dashboard'),
                'manage_options',
                'rawwire-lead-approvals',
                array(__CLASS__, 'render_lead_approvals_page')
            );

            add_submenu_page(
                self::MENU_SLUG,
                __('Leads', 'raw-wire-dashboard'),
                __('Leads', 'raw-wire-dashboard'),
                'manage_options',
                'rawwire-lead-completed',
                array(__CLASS__, 'render_lead_completed_page')
            );

            // Setup — Integration configuration
            add_submenu_page(
                self::MENU_SLUG,
                __('Setup', 'raw-wire-dashboard'),
                __('Setup', 'raw-wire-dashboard'),
                'manage_options',
                'rawwire-setup',
                array(__CLASS__, 'render_setup_page')
            );

            // Templates
            add_submenu_page(
                self::MENU_SLUG,
                __('Templates', 'raw-wire-dashboard'),
                __('Templates', 'raw-wire-dashboard'),
                'manage_options',
                'rawwire-templates',
                array(__CLASS__, 'render_templates_page')
            );

            // AI Agents
            add_submenu_page(
                self::MENU_SLUG,
                __('AI Agents', 'raw-wire-dashboard'),
                __('AI Agents', 'raw-wire-dashboard'),
                'manage_options',
                'rawwire-ai-agents',
                array(__CLASS__, 'render_ai_agents_page')
            );

            // Options (developer settings)
            add_submenu_page(
                self::MENU_SLUG,
                __('Options', 'raw-wire-dashboard'),
                __('Options', 'raw-wire-dashboard'),
                'manage_options',
                'rawwire-options',
                array(__CLASS__, 'render_options_page')
            );
        }
    }

    /**
     * ╔═══════════════════════════════════════════════════════════════════════╗
     * ║  TOOL TAB REGISTRATION                                                ║
     * ║                                                                       ║
     * ║  STOP! Tools call this to register their tab in the Tools page.       ║
     * ║  Do NOT create separate submenus for tools.                           ║
     * ╚═══════════════════════════════════════════════════════════════════════╝
     */
    public static function register_tool_tab($tool_id, $config)
    {
        // Check if tool is activated by template
        if (!self::is_tool_activated($tool_id)) {
            return false;
        }

        // Support both 'callback' and 'render_callback' keys
        if (isset($config['render_callback']) && !isset($config['callback'])) {
            $config['callback'] = $config['render_callback'];
        }

        self::$tool_tabs[$tool_id] = wp_parse_args($config, array(
            'label'       => ucfirst($tool_id),
            'icon'        => 'dashicons-admin-tools',
            'priority'    => 50,
            'callback'    => null,
            'description' => '',
        ));

        return true;
    }

    /**
     * Register a workflow tab
     */
    public static function register_workflow_tab($workflow_id, $config)
    {
        self::$workflow_tabs[$workflow_id] = wp_parse_args($config, array(
            'label'       => ucfirst($workflow_id),
            'icon'        => 'dashicons-randomize',
            'priority'    => 50,
            'callback'    => null,
            'description' => '',
        ));

        return true;
    }

    /**
     * Get registered tool tabs
     */
    public static function get_tool_tabs()
    {
        // Sort by priority
        uasort(self::$tool_tabs, function ($a, $b) {
            return ($a['priority'] ?? 50) - ($b['priority'] ?? 50);
        });
        return self::$tool_tabs;
    }

    /**
     * Get registered workflow tabs
     */
    public static function get_workflow_tabs()
    {
        uasort(self::$workflow_tabs, function ($a, $b) {
            return ($a['priority'] ?? 50) - ($b['priority'] ?? 50);
        });
        return self::$workflow_tabs;
    }

    /**
     * ╔═══════════════════════════════════════════════════════════════════════╗
     * ║  CHECK IF TOOL IS ACTIVATED                                           ║
     * ║                                                                       ║
     * ║  STOP! Tools are activated by templates via authorization config      ║
     * ║  AND must be authorized by the Config Authority (license/tier).       ║
     * ║  A tool with no template = not activated (unless it's a core tool).   ║
     * ║                                                                       ║
     * ║  Authorization checks:                                                ║
     * ║  1. Template authorization (which tools the template enables)         ║
     * ║  2. License authorization (user tier has access to the feature)       ║
     * ╚═══════════════════════════════════════════════════════════════════════╝
     */
    public static function is_tool_activated($tool_id)
    {
        // Core tools are always available regardless of template
        $core_tools = array('core', 'ai_settings', 'scraper_settings');
        if (in_array($tool_id, $core_tools)) {
            return true;
        }

        // No template = only core tools
        if (empty(self::$active_template)) {
            return false;
        }

        // Check template authorization
        $auth = self::$active_template['authorization'] ?? array();
        $tools = $auth['tools'] ?? array();

        // If tools array is empty, assume all tools allowed (backward compat)
        $template_allows = empty($tools) || in_array($tool_id, $tools) || isset($tools[$tool_id]);

        if (!$template_allows) {
            return false;
        }

        // Check license/tier authorization via Config Authority
        if (class_exists('RawWire_Config_Authority')) {
            $authority = RawWire_Config_Authority::get_instance();

            // Map tool IDs to features
            $tool_features = array(
                'ai_settings'  => 'ai_settings',
                'ai_scraper'   => 'ai_scraper',
                'workflow_db'  => 'workflow_db',
                'custom_panel' => 'custom_panels',
                'custom_tool'  => 'custom_tools',
            );

            $feature = $tool_features[$tool_id] ?? $tool_id;

            if (!$authority->is_feature_authorized($feature)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if a feature is authorized (combines template + license checks)
     * 
     * @param string $feature_id
     * @return bool
     */
    public static function is_feature_authorized($feature_id)
    {
        // Check Config Authority if available
        if (class_exists('RawWire_Config_Authority')) {
            return RawWire_Config_Authority::get_instance()->is_feature_authorized($feature_id);
        }

        // Fallback: check template features
        if (class_exists('RawWire_Template_Engine')) {
            return RawWire_Template_Engine::is_feature_enabled($feature_id);
        }

        // Default allow if no authorization system available
        return true;
    }

    /**
     * Get active template (cached)
     */
    private static function get_active_template()
    {
        if (class_exists('RawWire_Template_Engine') && method_exists('RawWire_Template_Engine', 'get_active_template')) {
            return RawWire_Template_Engine::get_active_template();
        }
        return null;
    }

    /**
     * Collect tool tabs from registered tools
     */
    public static function collect_tool_tabs()
    {
        // Custom tools from the Custom Tool Builder
        if (class_exists('RawWire_Custom_Tool_Builder')) {
            $custom_tools = RawWire_Custom_Tool_Builder::get_custom_tools();
            foreach ($custom_tools as $tool_id => $tool) {
                if (!empty($tool['enabled'])) {
                    self::register_tool_tab($tool_id, array(
                        'label'       => $tool['name'],
                        'icon'        => $tool['icon'] ?? 'dashicons-admin-tools',
                        'description' => $tool['description'] ?? '',
                        'callback'    => function () use ($tool_id) {
                            self::render_custom_tool_tab($tool_id);
                        },
                    ));
                }
            }
        }

        // Let other tools register via filter
        $external_tabs = apply_filters('rawwire_tool_tabs', array());
        foreach ($external_tabs as $tool_id => $config) {
            self::register_tool_tab($tool_id, $config);
        }
    }

    /**
     * Collect workflow tabs from active template
     */
    public static function collect_workflow_tabs()
    {
        if (empty(self::$active_template)) {
            return;
        }

        $workflows = self::$active_template['workflows'] ?? array();
        foreach ($workflows as $wf_id => $workflow) {
            if (!empty($workflow['enabled'])) {
                self::register_workflow_tab($wf_id, array(
                    'label'       => $workflow['name'] ?? ucfirst($wf_id),
                    'icon'        => $workflow['icon'] ?? 'dashicons-randomize',
                    'description' => $workflow['description'] ?? '',
                    'callback'    => function () use ($wf_id, $workflow) {
                        self::render_workflow_tab($wf_id, $workflow);
                    },
                ));
            }
        }
    }


    /* ═══════════════════════════════════════════════════════════════════════
     * 
     *  ██████╗  █████╗  ██████╗ ███████╗    ██████╗ ███████╗███╗   ██╗
     *  ██╔══██╗██╔══██╗██╔════╝ ██╔════╝    ██╔══██╗██╔════╝████╗  ██║
     *  ██████╔╝███████║██║  ███╗█████╗      ██████╔╝█████╗  ██╔██╗ ██║
     *  ██╔═══╝ ██╔══██║██║   ██║██╔══╝      ██╔══██╗██╔══╝  ██║╚██╗██║
     *  ██║     ██║  ██║╚██████╔╝███████╗    ██║  ██║███████╗██║ ╚████║
     *  ╚═╝     ╚═╝  ╚═╝ ╚═════╝ ╚══════╝    ╚═╝  ╚═╝╚══════╝╚═╝  ╚═══╝
     *                                                                 
     *  STOP! Page renderers below. These render the container pages.
     *  Actual content comes from Module Core panels and tool callbacks.
     * 
     * ═══════════════════════════════════════════════════════════════════════ */

    /**
     * Render Dashboard Page
     * Wrapped in Soothsayer dark theme with hero header.
     * The soothsayer wrapper provides:
     *   1. Developer toggle bar (at the top, correct DOM position)
     *   2. Soothsayer hero header (with theme toggle target)
     *   3. Dashboard content via page-renderer (hero + dev bar skipped
     *      via context flags so they are NOT rendered at all)
     *
     * No elements are hidden via CSS — sections are conditionally
     * rendered by page-renderer based on context flags.
     */
    public static function render_dashboard_page()
    {
        // Enqueue shared dark theme CSS
        $plugin_url = plugin_dir_url(__FILE__) . '../';
        $version = defined('RAWWIRE_DASHBOARD_VERSION') ? RAWWIRE_DASHBOARD_VERSION : '1.0.0';
        wp_enqueue_style('rawwire-soothsayer-v2', $plugin_url . 'css/soothsayer-v2.css', array(), $version);

        // Check developer mode for the toggle bar
        $dev_mode = class_exists('RawWire_Dev_Auth') ? RawWire_Dev_Auth::is_dev_mode_active() : false;
?>
        <?php
        // Get dashboard mode early for the wrapper attribute
        $current_mode_attr = get_user_meta(get_current_user_id(), 'rawwire_dashboard_mode', true);
        if (empty($current_mode_attr)) {
            $current_mode_attr = 'collection';
        }
        ?>
        <div class="soothsayer-app soothsayer-dashboard-wrap" data-dashboard-mode="<?php echo esc_attr($current_mode_attr); ?>">

            <!-- Developer Toggle Bar (rendered here for correct DOM order) -->
            <div class="rawwire-builder-toggle-bar soothsayer-dev-bar">
                <button type="button" class="rawwire-builder-toggle" id="rawwire-builder-toggle">
                    <span class="dashicons dashicons-lock"></span>
                    <span class="btn-text">Developer</span>
                    <?php if ($dev_mode): ?>
                        <span class="rawwire-dev-badge">ON</span>
                    <?php endif; ?>
                    <span class="dashicons dashicons-arrow-down-alt2 toggle-arrow"></span>
                </button>
                <?php if ($dev_mode): ?>
                    <button type="button" class="rawwire-dev-logout-btn" id="rawwire-dev-logout" title="Lock developer mode">
                        <span class="dashicons dashicons-dismiss"></span>
                        Lock
                    </button>
                <?php endif; ?>
            </div>

            <!-- Hero Header -->
            <div class="soothsayer-hero">
                <div class="soothsayer-hero-content">
                    <div class="soothsayer-hero-icon">
                        <span class="dashicons dashicons-dashboard"></span>
                        <div class="soothsayer-icon-pulse"></div>
                    </div>
                    <h1>DASHBOARD</h1>
                    <p class="soothsayer-hero-subtitle">Command Center</p>
                </div>
                <div class="soothsayer-hero-actions">
                    <!-- Theme toggle injected here by theme-controller.js -->
                </div>
                <div class="soothsayer-hero-particles" aria-hidden="true">
                    <?php for ($i = 0; $i < 20; $i++): ?>
                        <div class="particle particle-<?php echo $i; ?>"></div>
                    <?php endfor; ?>
                </div>
            </div>

            <!-- Dashboard Mode Switch (Collection / Information) -->
            <?php
            // Determine active dashboard mode from user meta or default
            $current_mode = get_user_meta(get_current_user_id(), 'rawwire_dashboard_mode', true);
            if (empty($current_mode)) {
                $current_mode = 'collection';
            }
            ?>
            <div class="soothsayer-mode-switch" data-active-mode="<?php echo esc_attr($current_mode); ?>">
                <button type="button"
                    class="soothsayer-mode-btn <?php echo $current_mode === 'collection' ? 'active' : ''; ?>"
                    data-mode="collection"
                    title="Collection workflows - import and gather data">
                    <span class="dashicons dashicons-database-import"></span>
                    <span class="mode-label">Collection</span>
                </button>
                <button type="button"
                    class="soothsayer-mode-btn <?php echo $current_mode === 'information' ? 'active' : ''; ?>"
                    data-mode="information"
                    title="Information overview - view and analyze data">
                    <span class="dashicons dashicons-chart-area"></span>
                    <span class="mode-label">Information</span>
                </button>
                <div class="soothsayer-mode-indicator"></div>
            </div>

            <!-- Dashboard Content -->
            <!-- Page-renderer outputs: login modal, builder toolbar, panels, modals -->
            <!-- Dev bar and hero are skipped via context flags (not rendered at all) -->
            <div class="soothsayer-content" style="padding: 24px;">
                <?php
                if (class_exists('RawWire_Bootstrap') && method_exists('RawWire_Bootstrap', 'render_dashboard')) {
                    RawWire_Bootstrap::render_dashboard([
                        'skip_hero'       => true,
                        'skip_dev_bar'    => true,
                        'dashboard_mode'  => $current_mode,
                    ]);
                } else {
                    echo '<div class="wrap"><h1>Raw-Wire Dashboard</h1><p>Dashboard renderer not available.</p></div>';
                }
                ?>
            </div>
        </div>

        <style>
            /* -- Soothsayer Dashboard Layout -- */

            /* Dev bar styling (dark theme, sits at natural DOM position) */
            .soothsayer-dashboard-wrap .soothsayer-dev-bar {
                background: linear-gradient(135deg, #0d1117 0%, #161b22 100%);
                border-bottom: 1px solid rgba(244, 180, 26, 0.2);
                padding: 6px 24px;
                display: flex;
                align-items: center;
                gap: 8px;
            }

            .soothsayer-dashboard-wrap .soothsayer-dev-bar .rawwire-builder-toggle {
                background: transparent;
                border: 1px solid rgba(255, 255, 255, 0.15);
                color: #c9d1d9;
                cursor: pointer;
                padding: 4px 12px;
                border-radius: 6px;
                font-size: 13px;
                display: flex;
                align-items: center;
                gap: 6px;
                transition: all 0.2s ease;
            }

            .soothsayer-dashboard-wrap .soothsayer-dev-bar .rawwire-builder-toggle:hover {
                border-color: rgba(244, 180, 26, 0.4);
                color: #f4b41a;
            }

            .soothsayer-dashboard-wrap .soothsayer-dev-bar .rawwire-dev-badge {
                background: rgba(244, 180, 26, 0.2);
                color: #f4b41a;
                font-size: 10px;
                font-weight: 700;
                padding: 1px 6px;
                border-radius: 4px;
                text-transform: uppercase;
            }

            .soothsayer-dashboard-wrap .soothsayer-dev-bar .rawwire-dev-logout-btn {
                background: transparent;
                border: 1px solid rgba(255, 255, 255, 0.1);
                color: #8b949e;
                cursor: pointer;
                padding: 4px 10px;
                border-radius: 6px;
                font-size: 12px;
                display: flex;
                align-items: center;
                gap: 4px;
                transition: all 0.2s ease;
            }

            .soothsayer-dashboard-wrap .soothsayer-dev-bar .rawwire-dev-logout-btn:hover {
                border-color: rgba(248, 81, 73, 0.4);
                color: #f85149;
            }

            /* Builder toolbar -- dark theme to match soothsayer */
            .soothsayer-dashboard-wrap .rawwire-builder-toolbar {
                background: linear-gradient(135deg, #161b22, #1a2332) !important;
                border: 1px solid rgba(244, 180, 26, 0.15);
                margin-top: 0;
            }

            /* Theme toggle styling in hero */
            .soothsayer-hero-actions .rawwire-theme-toggle {
                background: rgba(255, 255, 255, 0.08);
                border-radius: 20px;
                padding: 2px;
                display: flex;
                gap: 2px;
            }

            .soothsayer-hero-actions .rawwire-theme-toggle button {
                width: 28px;
                height: 28px;
                display: flex;
                align-items: center;
                justify-content: center;
                background: transparent;
                border: none;
                border-radius: 50%;
                color: rgba(255, 255, 255, 0.5);
                cursor: pointer;
                transition: all 0.2s ease;
            }

            .soothsayer-hero-actions .rawwire-theme-toggle button:hover {
                color: #fff;
            }

            .soothsayer-hero-actions .rawwire-theme-toggle button.active {
                background: rgba(244, 180, 26, 0.3);
                color: #f4b41a;
            }

            /* -- Dashboard Mode Switch -- */
            .soothsayer-mode-switch {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 4px;
                padding: 8px 24px;
                background: linear-gradient(180deg, rgba(10, 10, 10, 0.95) 0%, rgba(17, 17, 17, 0.9) 100%);
                border-bottom: 1px solid rgba(244, 180, 26, 0.1);
                position: relative;
            }

            .soothsayer-mode-btn {
                display: flex;
                align-items: center;
                gap: 6px;
                padding: 8px 20px;
                background: transparent;
                border: 1px solid rgba(255, 255, 255, 0.08);
                border-radius: 8px;
                color: rgba(255, 255, 255, 0.45);
                cursor: pointer;
                font-size: 13px;
                font-weight: 500;
                letter-spacing: 0.5px;
                transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                position: relative;
                z-index: 1;
            }

            .soothsayer-mode-btn:hover {
                color: rgba(255, 255, 255, 0.7);
                border-color: rgba(244, 180, 26, 0.2);
            }

            .soothsayer-mode-btn.active {
                background: rgba(244, 180, 26, 0.12);
                border-color: rgba(244, 180, 26, 0.4);
                color: #f4b41a;
                text-shadow: 0 0 12px rgba(244, 180, 26, 0.3);
            }

            .soothsayer-mode-btn .dashicons {
                font-size: 16px;
                width: 16px;
                height: 16px;
                line-height: 16px;
            }

            .soothsayer-mode-btn .mode-label {
                text-transform: uppercase;
                font-size: 11px;
                letter-spacing: 1.5px;
            }

            /* Mode-dependent panel visibility */
            .soothsayer-dashboard-wrap[data-dashboard-mode="information"] .rawwire-workflow-bar,
            .soothsayer-dashboard-wrap[data-dashboard-mode="information"] .rawwire-panel-progress {
                display: none;
            }

            /* Override nested .rawwire-dashboard container margins */
            .soothsayer-dashboard-wrap .rawwire-dashboard {
                margin: 0;
                padding: 0;
            }

            .soothsayer-dashboard-wrap .wrap.rawwire-dashboard {
                margin: 0;
            }
        </style>
    <?php
    }

    /**
     * Render Templates Page
     */
    public static function render_templates_page()
    {
        if (class_exists('RawWire_Templates_Page')) {
            $page = new RawWire_Templates_Page();
            $page->render();
        } else {
            require_once plugin_dir_path(__FILE__) . '../admin/class-templates.php';
            if (class_exists('RawWire_Templates_Page')) {
                $page = new RawWire_Templates_Page();
                $page->render();
            } else {
                echo '<div class="wrap"><h1>Templates</h1><p>Templates manager not available.</p></div>';
            }
        }
    }

    /**
     * Render Settings Page
     * @deprecated Use render_options_page() - kept for backward compatibility
     */
    public static function render_settings_page()
    {
        self::render_options_page();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // LEAD GENERATOR RENDER METHODS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Render Lead Sources / Pipeline page
     * Template-driven with panel provider, fallback to legacy admin class
     */
    public static function render_lead_sources_page()
    {
        // Template-driven rendering via panel provider
        if (class_exists('RawWire_Page_Renderer') && class_exists('RawWire_Lead_Generator_Panels')) {
            echo RawWire_Page_Renderer::render_lead_sources();
            return;
        }

        // Panel classes not loaded - show diagnostic
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('Lead Generator panels failed to load. Ensure the lead-generator core module is active.', 'raw-wire-dashboard');
        echo '</p></div>';
    }

    /**
     * Render Lead Approvals page
     * Template-driven with panel provider, fallback to legacy admin class
     */
    public static function render_lead_approvals_page()
    {
        // Template-driven rendering (preferred)
        if (class_exists('RawWire_Page_Renderer') && class_exists('RawWire_Lead_Generator_Panels')) {
            echo RawWire_Page_Renderer::render_lead_approvals();
            return;
        }

        // Fallback to legacy admin class
        if (!class_exists('RawWire_Lead_Approvals')) {
            require_once plugin_dir_path(__FILE__) . '../admin/class-lead-approvals.php';
        }
        $page = new RawWire_Lead_Approvals();
        $page->render();
    }

    /**
     * Render Completed Leads page
     * Template-driven with panel provider, fallback to legacy admin class
     */
    public static function render_lead_completed_page()
    {
        // Template-driven rendering (preferred)
        if (class_exists('RawWire_Page_Renderer') && class_exists('RawWire_Lead_Generator_Panels')) {
            echo RawWire_Page_Renderer::render_lead_completed();
            return;
        }

        // Fallback to legacy admin class
        if (!class_exists('RawWire_Lead_Completed')) {
            require_once plugin_dir_path(__FILE__) . '../admin/class-lead-completed.php';
        }
        $page = new RawWire_Lead_Completed();
        $page->render();
    }

    /**
     * ╔═══════════════════════════════════════════════════════════════════════╗
     * ║  RENDER SOOTHSAYER PAGE - Intelligence War Room                       ║
     * ║                                                                       ║
     * ║  High-tech intelligence display with:                                 ║
     * ║  - Animated workflow visualization                                    ║
     * ║  - Real-time data streaming display                                   ║
     * ║  - Action buttons for key intelligence                                ║
     * ╚═══════════════════════════════════════════════════════════════════════╝
     */
    public static function render_soothsayer_page()
    {
        // Load Soothsayer v2 (Intelligence Action Center)
        if (!class_exists('RawWire_Soothsayer_V2')) {
            require_once plugin_dir_path(__FILE__) . '../admin/class-soothsayer-v2.php';
        }
        if (class_exists('RawWire_Soothsayer_V2')) {
            RawWire_Soothsayer_V2::get_instance()->render();
        } else {
            echo '<div class="wrap rawwire-dashboard"><h1>Soothsayer</h1><p>Intelligence module not available.</p></div>';
        }
    }

    /**
     * ╔═══════════════════════════════════════════════════════════════════════╗
     * ║  RENDER USER OPTIONS PAGE - End-User Preferences                      ║
     * ║                                                                       ║
     * ║  Production page for end-user configuration:                          ║
     * ║  - Display preferences                                               ║
     * ║  - Notification settings                                              ║
     * ║  - Account preferences                                               ║
     * ╚═══════════════════════════════════════════════════════════════════════╝
     */
    public static function render_user_options_page()
    {
        if (!class_exists('RawWire_User_Options')) {
            require_once plugin_dir_path(__FILE__) . '../admin/class-user-options.php';
        }
        if (class_exists('RawWire_User_Options')) {
            RawWire_User_Options::get_instance()->render();
        } else {
            echo '<div class="wrap rawwire-dashboard"><h1>User Options</h1><p>User options module not available.</p></div>';
        }
    }

    /**
     * ╔═══════════════════════════════════════════════════════════════════════╗
     * ║  RENDER SETUP PAGE - Integration Configuration                        ║
     * ║                                                                       ║
     * ║  Configuration forms for:                                             ║
     * ║  - OpenClaw gateway connection                                        ║
     * ║  - Calendar integration                                               ║
     * ║  - Email client setup                                                 ║
     * ║  - Other integration settings                                         ║
     * ╚═══════════════════════════════════════════════════════════════════════╝
     */
    public static function render_setup_page()
    {
        if (!class_exists('RawWire_Setup_Page')) {
            require_once plugin_dir_path(__FILE__) . '../admin/class-setup.php';
        }
        if (class_exists('RawWire_Setup_Page')) {
            $page = new RawWire_Setup_Page();
            $page->render();
        } else {
            echo '<div class="wrap rawwire-dashboard"><h1>Setup</h1><p>Setup module not available.</p></div>';
        }
    }

    /**
     * Render Options Page (Developer Settings)
     */
    public static function render_options_page()
    {
        if (class_exists('RawWire_Settings_Page')) {
            $page = new RawWire_Settings_Page();
            $page->render();
        } else {
            require_once plugin_dir_path(__FILE__) . '../admin/class-settings.php';
            if (class_exists('RawWire_Settings_Page')) {
                $page = new RawWire_Settings_Page();
                $page->render();
            } else {
                echo '<div class="wrap rawwire-dashboard"><h1>Options</h1><p>Options page not available.</p></div>';
            }
        }
    }

    /**
     * Render AI Agents Page (Developer)
     */
    public static function render_ai_agents_page()
    {
    ?>
        <div class="wrap rawwire-dashboard rawwire-ai-agents-page">
            <div class="rawwire-hero">
                <div class="rawwire-hero-content">
                    <span class="eyebrow"><?php echo esc_html__('Developer', 'raw-wire-dashboard'); ?></span>
                    <h1>
                        <span class="dashicons dashicons-superhero-alt"></span>
                        <?php echo esc_html__('AI Agents', 'raw-wire-dashboard'); ?>
                    </h1>
                    <p class="lede"><?php echo esc_html__('Configure and manage AI agent integrations.', 'raw-wire-dashboard'); ?></p>
                </div>
                <div class="rawwire-hero-actions"></div>
            </div>

            <div class="rawwire-section">
                <div class="rawwire-placeholder-card">
                    <span class="dashicons dashicons-superhero-alt" style="font-size: 48px; width: 48px; height: 48px; color: var(--rw-brand-blue, #3b82f6); opacity: 0.5;"></span>
                    <h3><?php echo esc_html__('AI Agent Configuration', 'raw-wire-dashboard'); ?></h3>
                    <p><?php echo esc_html__('Agent configuration interface coming soon. This page will allow you to manage AI agent connections, prompt templates, and agent behavior settings.', 'raw-wire-dashboard'); ?></p>
                </div>
            </div>
        </div>
<?php
    }

    /**
     * ╔═══════════════════════════════════════════════════════════════════════╗
     * ║  RENDER AI SETTINGS PAGE - TABBED INTERFACE                           ║
     * ║                                                                       ║
     * ║  Routes to AI Settings Panel with provider tabs:                      ║
     * ║  Venice | Instinct | Engine Extensions | MCP Server                   ║
     * ╚═══════════════════════════════════════════════════════════════════════╝
     */
    public static function render_ai_settings_page()
    {
        // Route to AI Settings Panel which handles tabbed rendering
        if (class_exists('RawWire_AI_Settings_Panel')) {
            RawWire_AI_Settings_Panel::get_instance()->render_page();
        } else {
            echo '<div class="wrap"><p>' . esc_html__('AI Settings Panel not available.', 'raw-wire-dashboard') . '</p></div>';
        }
    }

    /**
     * ╔═══════════════════════════════════════════════════════════════════════╗
     * ║  RENDER TOOLS TOGGLES PAGE - SIMPLE ON/OFF                            ║
     * ║                                                                       ║
     * ║  Simple toggles to enable/disable entire tools.                       ║
     * ║  Detailed configuration is in AI Settings page.                       ║
     * ║                                                                       ║
     * ║  Implementation moved to: class-tools-toggles-page.php                ║
     * ╚═══════════════════════════════════════════════════════════════════════╝
     */
    public static function render_tools_toggles_page()
    {
        if (!function_exists('rawwire_tools') || !rawwire_tools()) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Tool Toggle Manager not loaded.', 'raw-wire-dashboard') . '</p></div>';
            return;
        }

        echo '<div class="wrap rawwire-wrap">';
        echo '<div class="rawwire-page-header" style="margin-bottom: 24px;">';
        echo '<h1 style="color: #f4b41a; font-size: 1.6em; margin: 0;">' . esc_html__('Tool Toggles', 'raw-wire-dashboard') . '</h1>';
        echo '<p style="color: #9ca3af; margin: 8px 0 0;">' . esc_html__('Enable or disable individual tools. Disabled tools are completely inactive — no UI, no processing, no overhead.', 'raw-wire-dashboard') . '</p>';
        echo '</div>';

        rawwire_tools()->render_tool_toggles();

        echo '</div>';
    }

    /**
     * Get available tools for toggles page
     * 
     * @return array
     */
    public static function get_available_tools()
    {
        if (!function_exists('rawwire_tools') || !rawwire_tools()) {
            return [];
        }
        return rawwire_tools()->get_tool_definitions();
    }

    /**
     * ╔═══════════════════════════════════════════════════════════════════════╗
     * ║  RENDER TOOLS PAGE - MULTI-TAB (LEGACY - kept for backward compat)    ║
     * ║                                                                       ║
     * ║  STOP! This renders the CONTAINER with tabs.                          ║
     * ║  Each tool provides its own tab content via callback.                 ║
     * ╚═══════════════════════════════════════════════════════════════════════╝
     */
    public static function render_tools_page()
    {
        $tabs = self::get_tool_tabs();
        $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : '';

        // Default to first tab
        if (empty($current_tab) && !empty($tabs)) {
            $current_tab = array_key_first($tabs);
        }

        echo '<div class="wrap rawwire-dashboard rawwire-tools-page">';
        echo '<div class="rawwire-hero">';
        echo '<div class="rawwire-hero-content">';
        echo '<span class="eyebrow">' . esc_html__('Configuration', 'raw-wire-dashboard') . '</span>';
        echo '<h1><span class="dashicons dashicons-admin-tools"></span> ' . esc_html__('Tools', 'raw-wire-dashboard') . '</h1>';
        echo '<p class="lede">' . esc_html__('Configure and manage your activated tools.', 'raw-wire-dashboard') . '</p>';
        echo '</div>';
        // Hero actions area for dark mode toggle
        echo '<div class="rawwire-hero-actions"></div>';
        echo '</div>';

        // Tab navigation
        if (!empty($tabs)) {
            echo '<nav class="nav-tab-wrapper rawwire-tabs">';
            foreach ($tabs as $tab_id => $tab) {
                $active = ($current_tab === $tab_id) ? 'nav-tab-active' : '';
                $url = add_query_arg('tab', $tab_id, admin_url('admin.php?page=rawwire-tools'));
                echo '<a href="' . esc_url($url) . '" class="nav-tab ' . esc_attr($active) . '">';
                if (!empty($tab['icon'])) {
                    echo '<span class="dashicons ' . esc_attr($tab['icon']) . '" style="margin-right: 5px;"></span>';
                }
                echo esc_html($tab['label']);
                echo '</a>';
            }
            echo '</nav>';

            // Tab content
            echo '<div class="rawwire-tab-content" style="margin-top: 20px;">';
            if (isset($tabs[$current_tab]) && is_callable($tabs[$current_tab]['callback'])) {
                call_user_func($tabs[$current_tab]['callback']);
            } else {
                echo '<p>' . esc_html__('Select a tool tab above to configure.', 'raw-wire-dashboard') . '</p>';
            }
            echo '</div>';
        } else {
            echo '<p>' . esc_html__('No tools are currently activated. Load a template to activate tools.', 'raw-wire-dashboard') . '</p>';
        }

        echo '</div>';
    }

    /**
     * ╔═══════════════════════════════════════════════════════════════════════╗
     * ║  RENDER WORKFLOWS PAGE - MULTI-TAB                                    ║
     * ║                                                                       ║
     * ║  STOP! This renders the CONTAINER with workflow tabs.                 ║
     * ║  Workflow tabs come from the active template's workflow config.       ║
     * ╚═══════════════════════════════════════════════════════════════════════╝
     */
    public static function render_workflows_page()
    {
        $tabs = self::get_workflow_tabs();
        $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : '';

        if (empty($current_tab) && !empty($tabs)) {
            $current_tab = array_key_first($tabs);
        }

        echo '<div class="wrap rawwire-dashboard rawwire-workflows-page">';
        echo '<div class="rawwire-hero">';
        echo '<div class="rawwire-hero-content">';
        echo '<span class="eyebrow">' . esc_html__('Automation', 'raw-wire-dashboard') . '</span>';
        echo '<h1><span class="dashicons dashicons-randomize"></span> ' . esc_html__('Workflows', 'raw-wire-dashboard') . '</h1>';
        echo '<p class="lede">' . esc_html__('Manage your content processing workflows.', 'raw-wire-dashboard') . '</p>';
        echo '</div></div>';

        if (!empty($tabs)) {
            echo '<nav class="nav-tab-wrapper rawwire-tabs">';
            foreach ($tabs as $tab_id => $tab) {
                $active = ($current_tab === $tab_id) ? 'nav-tab-active' : '';
                $url = add_query_arg('tab', $tab_id, admin_url('admin.php?page=rawwire-workflows'));
                echo '<a href="' . esc_url($url) . '" class="nav-tab ' . esc_attr($active) . '">';
                if (!empty($tab['icon'])) {
                    echo '<span class="dashicons ' . esc_attr($tab['icon']) . '" style="margin-right: 5px;"></span>';
                }
                echo esc_html($tab['label']);
                echo '</a>';
            }
            echo '</nav>';

            echo '<div class="rawwire-tab-content" style="margin-top: 20px;">';
            if (isset($tabs[$current_tab]) && is_callable($tabs[$current_tab]['callback'])) {
                call_user_func($tabs[$current_tab]['callback']);
            } else {
                echo '<p>' . esc_html__('Select a workflow tab above.', 'raw-wire-dashboard') . '</p>';
            }
            echo '</div>';
        } else {
            echo '<p>' . esc_html__('No workflows defined in the current template.', 'raw-wire-dashboard') . '</p>';
        }

        echo '</div>';
    }

    /**
     * Render a custom tool tab content
     */
    private static function render_custom_tool_tab($tool_id)
    {
        if (!class_exists('RawWire_Custom_Tool_Builder')) {
            echo '<p>Custom tool builder not available.</p>';
            return;
        }

        $tool = RawWire_Custom_Tool_Builder::get_tool($tool_id);
        if (!$tool) {
            echo '<p>Tool not found.</p>';
            return;
        }

        $functions = RawWire_Custom_Tool_Builder::get_tool_functions($tool_id);

        echo '<div class="custom-tool-config">';
        echo '<h3>' . esc_html($tool['name']) . '</h3>';
        if (!empty($tool['description'])) {
            echo '<p class="description">' . esc_html($tool['description']) . '</p>';
        }

        if (empty($functions)) {
            echo '<p>No functions defined for this tool.</p>';
        } else {
            echo '<h4>Functions</h4>';
            echo '<table class="widefat">';
            echo '<thead><tr><th>Function</th><th>Type</th><th>Trigger</th><th>Actions</th></tr></thead>';
            echo '<tbody>';
            foreach ($functions as $func_id => $func) {
                $type_label = RawWire_Custom_Tool_Builder::get_function_types()[$func['function_type']]['label'] ?? $func['function_type'];
                echo '<tr>';
                echo '<td><strong>' . esc_html($func['name']) . '</strong><br><small>' . esc_html($func['description'] ?? '') . '</small></td>';
                echo '<td>' . esc_html($type_label) . '</td>';
                echo '<td>' . esc_html(ucfirst($func['trigger_type'] ?? 'manual')) . '</td>';
                echo '<td>';
                if ($func['trigger_type'] === 'manual') {
                    echo '<button class="button rawwire-execute-function" data-function-id="' . esc_attr($func_id) . '">Execute</button>';
                } else {
                    echo '<span class="description">Auto-triggered</span>';
                }
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '</div>';
    }

    /**
     * Render a workflow tab content
     */
    private static function render_workflow_tab($workflow_id, $workflow)
    {
        echo '<div class="workflow-config">';
        echo '<h3>' . esc_html($workflow['name'] ?? $workflow_id) . '</h3>';
        if (!empty($workflow['description'])) {
            echo '<p class="description">' . esc_html($workflow['description']) . '</p>';
        }

        // Show workflow steps if defined
        $steps = $workflow['steps'] ?? array();
        if (!empty($steps)) {
            echo '<h4>Workflow Steps</h4>';
            echo '<ol class="workflow-steps">';
            foreach ($steps as $step) {
                echo '<li>';
                echo '<strong>' . esc_html($step['name'] ?? 'Step') . '</strong>';
                if (!empty($step['description'])) {
                    echo ' - ' . esc_html($step['description']);
                }
                echo '</li>';
            }
            echo '</ol>';
        }

        // Workflow controls
        echo '<div class="workflow-controls" style="margin-top: 20px;">';
        echo '<button class="button button-primary rawwire-run-workflow" data-workflow-id="' . esc_attr($workflow_id) . '">Run Workflow</button>';
        echo '<button class="button rawwire-workflow-settings" data-workflow-id="' . esc_attr($workflow_id) . '">Settings</button>';
        echo '</div>';

        echo '</div>';
    }
}

// Initialize
add_action('plugins_loaded', array('RawWire_Menu_Manager', 'init'), 5);
