<?php

/**
 * Plugin Name: RawWire Dashboard
 * Plugin URI: https://github.com/raw-wire-dao-llc/raw-wire-core
 * Description: Production-ready control panel for Raw-Wire automation with GitHub API integration, modular search logic, and comprehensive data management
 * Version: 1.0.24
 * Author: Raw-Wire DAO LLC
 * Author URI: https://github.com/raw-wire-dao-llc
 * Text Domain: raw-wire-dashboard
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stub Logger Class (Temporary)
 * 
 * The full logging system has been archived for rebuild.
 * This stub prevents fatal errors from calls to RawWire_Logger.
 * Logs are written to error_log() for now.
 * 
 * @see _archive/obsolete-logging/
 */
if (!class_exists('RawWire_Logger')) {
    class RawWire_Logger {
        const EMERGENCY = 'emergency';
        const ALERT     = 'alert';
        const CRITICAL  = 'critical';
        const ERROR     = 'error';
        const WARNING   = 'warning';
        const NOTICE    = 'notice';
        const INFO      = 'info';
        const DEBUG     = 'debug';

        public static function log($message, $level = self::INFO, $context = array()) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $ctx = !empty($context) ? ' | ' . wp_json_encode($context) : '';
                error_log("RawWire [{$level}]: {$message}{$ctx}");
            }
        }
        public static function info($message, $context = array()) { self::log($message, self::INFO, $context); }
        public static function error($message, $context = array()) { self::log($message, self::ERROR, $context); }
        public static function warning($message, $context = array()) { self::log($message, self::WARNING, $context); }
        public static function debug($message, $context = array()) { self::log($message, self::DEBUG, $context); }
        public static function log_activity($message, $type = 'activity', $details = array(), $severity = 'info') {
            self::log($message, $severity, array_merge($details, array('type' => $type)));
        }
        public static function log_error($message, $details = array(), $severity = 'error') {
            self::log($message, $severity, $details);
        }
    }
}

/**
 * RawWire Dashboard Main Plugin Class
 *
 * @since 1.0.18
 */
class RawWire_Dashboard {

    /**
     * Single instance of the plugin
     *
     * @var RawWire_Dashboard
     */
    private static $instance = null;

    /**
     * Plugin version
     */
    const VERSION = '1.0.25';

    /**
     * Get single instance of the plugin
     *
     * @return RawWire_Dashboard
     */
    /**
     * Get single instance of the plugin
     *
     * @return RawWire_Dashboard
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
        $this->includes();
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('wp_ajax_rawwire_save_template', array($this, 'ajax_save_template'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }

    /**
     * Include required files
     */
    private function includes() {
        // Core classes
        // NOTE: class-logger.php archived - will be rebuilt from scratch
        require_once plugin_dir_path(__FILE__) . 'rest-api.php';
        require_once plugin_dir_path(__FILE__) . 'includes/class-admin.php';

        // Integrations (AI Engine extensions)
        require_once plugin_dir_path(__FILE__) . 'includes/integrations/class-groq-engine.php';

        // Centralized Key Manager (must load early, before adapters need keys)
        require_once plugin_dir_path(__FILE__) . 'cores/toolbox-core/class-key-manager.php';

        // Scraper and AI classes
        require_once plugin_dir_path(__FILE__) . 'cores/toolbox-core/adapters/scrapers/class-scraper-github.php';
        require_once plugin_dir_path(__FILE__) . 'cores/toolbox-core/adapters/scrapers/class-scraper-native.php';
        require_once plugin_dir_path(__FILE__) . 'cores/toolbox-core/adapters/scrapers/class-scraper-ai.php';
        require_once plugin_dir_path(__FILE__) . 'cores/toolbox-core/adapters/generators/class-generator-ollama.php';
        require_once plugin_dir_path(__FILE__) . 'includes/class-ai-content-analyzer.php';

        // Tool Registry & Base (Action Scheduler orchestration)
        require_once plugin_dir_path(__FILE__) . 'cores/toolbox-core/class-tool-base.php';
        require_once plugin_dir_path(__FILE__) . 'cores/toolbox-core/class-tool-registry.php';

        // AI Adapter & MCP Server (AI Engine integration)
        require_once plugin_dir_path(__FILE__) . 'cores/toolbox-core/class-ai-adapter.php';
        require_once plugin_dir_path(__FILE__) . 'cores/toolbox-core/class-mcp-server.php';

        // Scraper Settings Feature (Toolkit)
        require_once plugin_dir_path(__FILE__) . 'cores/toolbox-core/features/class-scraper-settings.php';
        require_once plugin_dir_path(__FILE__) . 'cores/toolbox-core/features/class-scraper-settings-panel.php';
        require_once plugin_dir_path(__FILE__) . 'cores/toolbox-core/features/class-ai-settings-panel.php';
        require_once plugin_dir_path(__FILE__) . 'cores/toolbox-core/features/class-ai-scraper-panel.php';
        require_once plugin_dir_path(__FILE__) . 'cores/toolbox-core/features/class-chatbot-context.php';
        require_once plugin_dir_path(__FILE__) . 'cores/toolbox-core/features/class-workflow-db-panel.php';
        
        // Initialize scraper settings toolkit
        RawWire_Scraper_Settings::get_instance();
        RawWire_Scraper_Settings_Panel::get_instance();
        RawWire_AI_Settings_Panel::get_instance();
        RawWire_AI_Scraper_Panel::get_instance();
        RawWire_Chatbot_Context::get_instance();
        RawWire_Workflow_DB_Panel::get_instance();

        // Bootstrap (menu registration, template-driven pages)
        require_once plugin_dir_path(__FILE__) . 'includes/bootstrap.php';
        RawWire_Bootstrap::init();

        // Module core system
        require_once plugin_dir_path(__FILE__) . 'cores/module-core/module-core.php';

        // Template engine system
        require_once plugin_dir_path(__FILE__) . 'cores/template-engine/template-engine.php';
        require_once plugin_dir_path(__FILE__) . 'cores/template-engine/panel-renderer.php';
        require_once plugin_dir_path(__FILE__) . 'cores/template-engine/page-renderer.php';
        require_once plugin_dir_path(__FILE__) . 'cores/template-engine/workflow-handlers.php';
        require_once plugin_dir_path(__FILE__) . 'cores/ai-discovery/ai-discovery.php';

        // Initialize module core to discover modules
        RawWire_Module_Core::init();

        // Initialize template engine
        RawWire_Template_Engine::init();

        // Initialize AI Discovery
        RawWire_AI_Discovery::init();

        // Instantiate admin class to register AJAX handlers
        new RawWire_Admin();
    }

    /**
     * Plugin initialization
     */
    public function init() {
        // Load text domain
        load_plugin_textdomain('raw-wire-dashboard', false, dirname(plugin_basename(__FILE__)) . '/languages');

        // Initialize components
        $this->init_database();

        // Ensure Ollama host option exists for local dev (host-mapped port 8001)
        if (get_option('rawwire_ollama_host') === false) {
            add_option('rawwire_ollama_host', 'http://127.0.0.1:8001');
        }

        // Ensure scoring batch size option exists (default 10)
        if (get_option('rawwire_scoring_batch_size') === false) {
            add_option('rawwire_scoring_batch_size', 10);
        }
        // Ensure auto-approve threshold option exists (default 0 = disabled)
        if (get_option('rawwire_auto_approve_threshold') === false) {
            add_option('rawwire_auto_approve_threshold', 0);
        }
    }

    /**
     * Initialize database
     */
    private function init_database() {
        // Database initialization will be handled here
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        // NOTE: Main menu page is already registered by RawWire_Bootstrap::register_menu()
        // Only register submenu pages here to avoid duplication

        // Add direct Edit Template GUI page
        add_submenu_page(
            'raw-wire-dashboard',
            __('Edit Template', 'raw-wire-dashboard'),
            __('Edit Template', 'raw-wire-dashboard'),
            'manage_options',
            'raw-wire-edit-template',
            array($this, 'admin_edit_template_page')
        );
        // Add submenus: Settings and Approvals
        add_submenu_page(
            'raw-wire-dashboard',
            __('Settings', 'raw-wire-dashboard'),
            __('Settings', 'raw-wire-dashboard'),
            'manage_options',
            'raw-wire-settings',
            array($this, 'admin_settings_page')
        );

        add_submenu_page(
            'raw-wire-dashboard',
            __('Approvals', 'raw-wire-dashboard'),
            __('Approvals', 'raw-wire-dashboard'),
            'manage_options',
            'raw-wire-approvals',
            array($this, 'admin_approvals_page')
        );

        add_submenu_page(
            'raw-wire-dashboard',
            __('Release', 'raw-wire-dashboard'),
            __('Release', 'raw-wire-dashboard'),
            'manage_options',
            'raw-wire-release',
            array($this, 'admin_release_page')
        );

        add_submenu_page(
            'raw-wire-dashboard',
            __('Templates', 'raw-wire-dashboard'),
            __('Templates', 'raw-wire-dashboard'),
            'manage_options',
            'raw-wire-templates',
            array($this, 'admin_templates_page')
        );
    }

    /**
     * Admin callback for the Edit Template GUI page
     */
    public function admin_edit_template_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Load active template JSON for editing
        $template = null;
        if (class_exists('RawWire_Template_Engine') && method_exists('RawWire_Template_Engine', 'get_active_template')) {
            $template = RawWire_Template_Engine::get_active_template();
        }

        $template_json = $template ? json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '';
        ?>
        <div class="wrap rawwire-dashboard">
            <div class="rawwire-hero">
                <div class="rawwire-hero-content">
                    <span class="eyebrow"><?php _e('Configuration', 'raw-wire-dashboard'); ?></span>
                    <h1>
                        <span class="dashicons dashicons-edit"></span>
                        <?php _e('Edit Active Template', 'raw-wire-dashboard'); ?>
                    </h1>
                    <p class="lede"><?php _e('Customize features, pages, and source types for your active template.', 'raw-wire-dashboard'); ?></p>
                </div>
                <div class="rawwire-hero-actions"></div>
            </div>

            <form id="rawwire-template-editor" method="post" action="<?php echo admin_url('admin-ajax.php'); ?>">
                <?php wp_nonce_field('rawwire_template_builder', 'nonce'); ?>
                <input type="hidden" name="action" value="rawwire_save_template" />

                <?php if (!empty($template) && is_array($template)): ?>
                    <div style="display:flex;gap:20px;align-items:flex-start;">
                        <div style="flex:1;min-width:260px;">
                            <h2><?php _e('Features', 'raw-wire-dashboard'); ?></h2>
                            <div id="rawwire-features-list">
                                <?php foreach ($template['features'] ?? array() as $fid => $fmeta): ?>
                                    <?php $fid_attr = esc_attr($fid); $checked = !empty($fmeta['default']) ? 'checked' : ''; ?>
                                    <label style="display:block;margin-bottom:6px;">
                                        <input type="checkbox" data-feature-id="<?php echo $fid_attr; ?>" <?php echo $checked; ?> />
                                        <?php echo esc_html($fmeta['label'] ?? $fid); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div style="flex:1;min-width:260px;">
                            <h2><?php _e('Pages', 'raw-wire-dashboard'); ?></h2>
                            <div id="rawwire-pages-list">
                                <?php foreach ($template['pageDefinitions'] ?? array() as $pid => $pmeta): ?>
                                    <?php $pid_attr = esc_attr($pid); $pchecked = !empty($pmeta['enabled']) ? 'checked' : ''; ?>
                                    <label style="display:block;margin-bottom:6px;">
                                        <input type="checkbox" data-page-id="<?php echo $pid_attr; ?>" <?php echo $pchecked; ?> />
                                        <?php echo esc_html($pmeta['label'] ?? $pid); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div style="flex:1;min-width:260px;">
                            <h2><?php _e('Source Types', 'raw-wire-dashboard'); ?></h2>
                            <div id="rawwire-sources-list">
                                <?php foreach ($template['sourceTypes'] ?? array() as $sid => $smeta): ?>
                                    <?php $sid_attr = esc_attr($sid); $schecked = !empty($smeta['enabled']) ? 'checked' : ''; ?>
                                    <label style="display:block;margin-bottom:6px;">
                                        <input type="checkbox" data-source-id="<?php echo $sid_attr; ?>" <?php echo $schecked; ?> />
                                        <?php echo esc_html($smeta['label'] ?? $sid); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <p style="margin-top:16px;">
                        <button id="rawwire-save-gui" type="button" class="button button-primary"><?php _e('Save Template (GUI)', 'raw-wire-dashboard'); ?></button>
                        <a href="<?php echo admin_url('admin.php?page=raw-wire-templates'); ?>" class="button button-secondary"><?php _e('Back to Templates', 'raw-wire-dashboard'); ?></a>
                    </p>

                    <h3 style="margin-top:18px;">Advanced / Raw JSON</h3>
                    <p><?php _e('You can edit the raw JSON below if needed. GUI changes will update this field when saving.', 'raw-wire-dashboard'); ?></p>
                    <textarea id="rawwire-template-json" name="template" rows="16" class="widefat" style="font-family: monospace;"><?php echo esc_textarea($template_json); ?></textarea>

                <?php else: ?>
                    <p><?php _e('No active template found. Use the Templates page to activate one.', 'raw-wire-dashboard'); ?></p>
                <?php endif; ?>
            </form>

            <script>
                (function(){
                    const templateObj = <?php echo $template ? json_encode($template, JSON_UNESCAPED_SLASHES) : 'null'; ?>;
                    if (!templateObj) { return; }

                    // Initialize UI state from template defaults
                    document.querySelectorAll('[data-feature-id]').forEach(function(ck){
                        const id = ck.getAttribute('data-feature-id');
                        const def = (templateObj.features && templateObj.features[id] && templateObj.features[id].default) ? true : false;
                        // If template has explicit enabled flag stored, prefer it (backwards compat)
                        if (templateObj.features && templateObj.features[id] && typeof templateObj.features[id].enabled !== 'undefined') {
                            ck.checked = !!templateObj.features[id].enabled;
                        } else {
                            ck.checked = !!def;
                        }
                    });

                    document.querySelectorAll('[data-page-id]').forEach(function(ck){
                        const id = ck.getAttribute('data-page-id');
                        ck.checked = !!(templateObj.pageDefinitions && templateObj.pageDefinitions[id] && templateObj.pageDefinitions[id].enabled);
                    });

                    document.querySelectorAll('[data-source-id]').forEach(function(ck){
                        const id = ck.getAttribute('data-source-id');
                        ck.checked = !!(templateObj.sourceTypes && templateObj.sourceTypes[id] && templateObj.sourceTypes[id].enabled);
                    });

                    // Save handler: merge UI back into template JSON and submit via AJAX
                    document.getElementById('rawwire-save-gui').addEventListener('click', function(e){
                        e.preventDefault();
                        
                        // Update features
                        document.querySelectorAll('[data-feature-id]').forEach(function(ck){
                            const id = ck.getAttribute('data-feature-id');
                            if (!templateObj.features) templateObj.features = {};
                            if (!templateObj.features[id]) templateObj.features[id] = {};
                            templateObj.features[id].enabled = !!ck.checked;
                        });
                        // Update pages
                        document.querySelectorAll('[data-page-id]').forEach(function(ck){
                            const id = ck.getAttribute('data-page-id');
                            if (!templateObj.pageDefinitions) templateObj.pageDefinitions = {};
                            if (!templateObj.pageDefinitions[id]) templateObj.pageDefinitions[id] = {};
                            templateObj.pageDefinitions[id].enabled = !!ck.checked;
                        });
                        // Update sources
                        document.querySelectorAll('[data-source-id]').forEach(function(ck){
                            const id = ck.getAttribute('data-source-id');
                            if (!templateObj.sourceTypes) templateObj.sourceTypes = {};
                            if (!templateObj.sourceTypes[id]) templateObj.sourceTypes[id] = {};
                            templateObj.sourceTypes[id].enabled = !!ck.checked;
                        });

                        const jsonStr = JSON.stringify(templateObj, null, 2);
                        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({
                                action: 'rawwire_save_template',
                                nonce: document.querySelector('[name="nonce"]').value,
                                template: jsonStr
                            })
                        })
                        .then(function(res) { return res.json(); })
                        .then(function(data) {
                            if (data.success) {
                                alert('Template saved successfully!');
                                window.location.reload();
                            } else {
                                alert('Error: ' + (data.data ? data.data.message : 'Unknown error'));
                            }
                        })
                        .catch(function(err) {
                            console.error('AJAX error:', err);
                            alert('Error saving template: ' + err);
                        });
                    });
                })();
            </script>
        </div>
        <?php
    }

    /**
     * Settings page callback
     */
    public function admin_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Use template-based rendering if available
        if (class_exists('RawWire_Page_Renderer')) {
            echo RawWire_Page_Renderer::render_settings();
        } else {
            require_once plugin_dir_path(__FILE__) . 'admin/class-settings.php';
            $page = new RawWire_Settings_Page();
            $page->render();
        }
    }

    /**
     * Approvals page callback
     */
    public function admin_approvals_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Use template-based rendering if available
        if (class_exists('RawWire_Page_Renderer')) {
            echo RawWire_Page_Renderer::render_approvals();
        } else {
            require_once plugin_dir_path(__FILE__) . 'admin/class-approvals.php';
            $page = new RawWire_Approvals_Page();
            $page->render();
        }
    }

    /**
     * Release page callback
     */
    public function admin_release_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Use template-based rendering
        if (class_exists('RawWire_Page_Renderer')) {
            echo RawWire_Page_Renderer::render_release();
        } else {
            echo '<div class="wrap"><h1>Release Queue</h1><p>Template engine not available.</p></div>';
        }
    }

    /**
     * Templates page callback
     */
    public function admin_templates_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        require_once plugin_dir_path(__FILE__) . 'admin/class-templates.php';
        $page = new RawWire_Templates_Page();
        $page->render();
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        error_log("RawWire: enqueue_admin_assets called with hook: " . $hook);
        
        // Allow all RawWire admin pages
        $allowed_hooks = array(
            'toplevel_page_raw-wire-dashboard',
            'raw-wire_page_raw-wire-settings',
            'raw-wire_page_raw-wire-approvals',
            'raw-wire_page_raw-wire-templates',
            'raw-wire_page_raw-wire-release',
            'raw-wire_page_raw-wire-edit-template',
            'raw-wire_page_rawwire-ai-scraper',
            'raw-wire_page_rawwire-ai-settings',
            'raw-wire_page_rawwire-workflow-db',
            'raw-wire_page_rawwire-scraper-settings',
        );
        
        if (!in_array($hook, $allowed_hooks)) {
            error_log("RawWire: Hook '" . $hook . "' not in allowed list, returning early");
            return;
        }

        error_log("RawWire: Hook '" . $hook . "' is allowed, continuing with enqueue");

        // Original admin styles
        wp_enqueue_style(
            'rawwire-admin',
            plugin_dir_url(__FILE__) . 'assets/css/admin.css',
            array(),
            self::VERSION
        );

        // Template system styles
        wp_enqueue_style(
            'rawwire-template-system',
            plugin_dir_url(__FILE__) . 'css/template-system.css',
            array('rawwire-admin'),
            self::VERSION
        );

        // RawWire Design System - Professional UI with light/dark modes
        wp_enqueue_style(
            'rawwire-design-system',
            plugin_dir_url(__FILE__) . 'css/rawwire-design-system.css',
            array('rawwire-admin', 'rawwire-template-system'),
            self::VERSION
        );

        // Template-generated dynamic CSS
        if (class_exists('RawWire_Template_Engine')) {
            $template_css = RawWire_Template_Engine::generate_css();
            wp_add_inline_style('rawwire-template-system', $template_css);
        }

        // Original admin script
        wp_enqueue_script(
            'rawwire-admin',
            plugin_dir_url(__FILE__) . 'assets/js/admin.js',
            array('jquery'),
            self::VERSION,
            true
        );

        // Template system script
        wp_enqueue_script(
            'rawwire-template-system',
            plugin_dir_url(__FILE__) . 'js/template-system.js',
            array('jquery', 'rawwire-admin'),
            self::VERSION,
            true
        );

        // Theme controller for light/dark mode
        wp_enqueue_script(
            'rawwire-theme-controller',
            plugin_dir_url(__FILE__) . 'js/theme-controller.js',
            array(),
            self::VERSION,
            true
        );

        wp_localize_script('rawwire-admin', 'rawwire_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rawwire_ajax_nonce'),
        ));

        wp_localize_script('rawwire-template-system', 'rawwire_admin', array(
            'nonce' => wp_create_nonce('rawwire_template_nonce'),
            'edit_url' => admin_url('admin.php?page=raw-wire-edit'),
        ));

        // Template builder assets (only on templates page)
        if ($hook === 'raw-wire_page_raw-wire-templates') {
            wp_enqueue_style(
                'rawwire-template-builder',
                plugin_dir_url(__FILE__) . 'css/template-builder.css',
                array('rawwire-admin'),
                self::VERSION
            );

            wp_enqueue_script('jquery-ui-draggable');
            wp_enqueue_script('jquery-ui-sortable');

            wp_enqueue_script(
                'rawwire-template-builder',
                plugin_dir_url(__FILE__) . 'js/template-builder.js',
                array('jquery', 'jquery-ui-draggable', 'jquery-ui-sortable'),
                self::VERSION,
                true
            );

            wp_localize_script('rawwire-template-builder', 'rawwireTemplateBuilder', array(
                'nonce' => wp_create_nonce('rawwire_template_builder'),
                'ajaxurl' => admin_url('admin-ajax.php'),
                'templatesUrl' => admin_url('admin.php?page=raw-wire-templates')
            ));
        }

        if ('toplevel_page_raw-wire-dashboard' === $hook) {
            error_log("RawWire: Enqueueing assets for hook: " . $hook);
            if (class_exists('RawWire_Bootstrap') && method_exists('RawWire_Bootstrap', 'enqueue_assets')) {
                RawWire_Bootstrap::enqueue_assets($hook);
            }
            // NOTE: RawWire_Activity_Logs archived - will be rebuilt from scratch
        } else {
            error_log("RawWire: Hook mismatch - expected 'toplevel_page_raw-wire-dashboard', got '" . $hook . "'");
            // TEMPORARILY FORCE ENQUEUE FOR DEBUGGING
            if (strpos($hook, 'raw-wire') !== false || strpos($hook, 'rawwire') !== false) {
                error_log("RawWire: Forcing enqueue for debugging on hook: " . $hook);
                if (class_exists('RawWire_Bootstrap') && method_exists('RawWire_Bootstrap', 'enqueue_assets')) {
                    RawWire_Bootstrap::enqueue_assets($hook);
                }
            }
        }
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        // Initialize the singleton - it will register routes via rest_api_init action
        RawWire_REST_API::get_instance();
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Create legacy tables (for backwards compatibility)
        $this->create_tables();

        // Create all 5 workflow tables via Migration Service
        require_once plugin_dir_path(__FILE__) . 'services/class-migration-service.php';
        \RawWire\Dashboard\Services\Migration_Service::run_migrations();

        // Set default options
        add_option('rawwire_version', self::VERSION);
        add_option('rawwire_last_sync', __('Never', 'raw-wire-dashboard'));

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook('rawwire_sync_data');

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Fetch data from configured sources
     * Called by REST API /fetch-data endpoint
     * 
     * Scrapers pull from multiple sources, AI scores all items,
     * returns top 5 from each source to approvals.
     * 
     * @return array Status and results
     */
    public function fetch_github_data() {
        global $wpdb;
        
        // Track stats
        $stats = array(
            'success' => false,
            'total_scraped' => 0,
            'total_stored' => 0,
            'sources' => array(),
            'errors' => array()
        );

        try {
            // Initialize scrapers
            $native_scraper = new RawWire_Adapter_Scraper_Native(array());
            
            // Configure multiple data sources (not scrapers)
            // TODO: Load from database or configuration
            $data_sources = array(
                array(
                    'name' => 'Federal Register - Rules',
                    'url' => 'https://www.federalregister.gov/documents/search?conditions%5Btype%5D%5B%5D=RULE',
                    'scraper' => $native_scraper
                ),
                array(
                    'name' => 'Federal Register - Notices',
                    'url' => 'https://www.federalregister.gov/documents/search?conditions%5Btype%5D%5B%5D=NOTICE',
                    'scraper' => $native_scraper
                ),
                array(
                    'name' => 'White House Press Briefings',
                    'url' => 'https://www.whitehouse.gov/briefing-room/press-briefings/',
                    'scraper' => $native_scraper
                ),
                array(
                    'name' => 'White House Statements',
                    'url' => 'https://www.whitehouse.gov/briefing-room/statements-releases/',
                    'scraper' => $native_scraper
                ),
                array(
                    'name' => 'FDA News & Events',
                    'url' => 'https://www.fda.gov/news-events/newsroom/press-announcements',
                    'scraper' => $native_scraper
                ),
                array(
                    'name' => 'EPA News Releases',
                    'url' => 'https://www.epa.gov/newsreleases',
                    'scraper' => $native_scraper
                ),
                array(
                    'name' => 'DOJ Press Releases',
                    'url' => 'https://www.justice.gov/news',
                    'scraper' => $native_scraper
                ),
                array(
                    'name' => 'SEC Press Releases',
                    'url' => 'https://www.sec.gov/news/pressreleases',
                    'scraper' => $native_scraper
                ),
            );

            $all_items_by_source = array();

            // Scrape each source
            foreach ($data_sources as $source) {
                try {
                    $result = $source['scraper']->scrape($source['url']);

                    if (!empty($result['success']) && !empty($result['html'])) {
                        $items = self::extract_items_from_html($result['html'], $source['url']);

                        if (!empty($items)) {
                            $stats['total_scraped'] += count($items);
                            // Tag with source name
                            foreach ($items as &$it) { $it['source'] = $source['name']; }
                            $all_items_by_source[$source['name']] = $items;
                            RawWire_Logger::info("Scraped " . count($items) . " items from {$source['name']}");
                        } else {
                            $stats['errors'][] = "Parsed 0 items from {$source['name']}";
                        }
                    } else {
                        $stats['errors'][] = "Failed scraping {$source['name']}: " . ($result['error'] ?? 'Unknown error');
                    }
                } catch (Exception $e) {
                    $stats['errors'][] = "Error scraping {$source['name']}: " . $e->getMessage();
                }
            }

            // Process each source separately for AI analysis and top 5 selection
            if (class_exists('RawWire_AI_Content_Analyzer')) {
                try {
                    $analyzer = new RawWire_AI_Content_Analyzer();
                    // Ollama health check (log-only)
                    if (class_exists('RawWire_Adapter_Generator_Ollama')) {
                        $ollama = new RawWire_Adapter_Generator_Ollama(array());
                        $health = $ollama->test_connection();
                        if (empty($health['success'])) {
                            RawWire_Logger::warning('Ollama not reachable, using fallback scoring', array('message' => $health['message'] ?? 'unknown'));
                        }
                    }
                    
                    // Load keyword filter settings
                    $filter_settings = get_option('rawwire_filter_settings', array(
                        'keywords' => '',
                        'enabled' => false
                    ));
                    
                    foreach ($all_items_by_source as $source_name => $items) {
                        // Initialize source stats
                        $source_stats = array(
                            'scraped' => count($items),
                            'duplicates' => 0,
                            'stored' => 0,
                            'scores' => array(),
                            'top5_scores' => array()
                        );
                        
                        // Apply keyword filter if enabled
                        $items_to_analyze = $items;
                        if (!empty($filter_settings['enabled']) && !empty($filter_settings['keywords'])) {
                            $keywords = array_map('trim', explode(',', $filter_settings['keywords']));
                            $items_to_analyze = $analyzer->quick_filter($items, $keywords);
                            RawWire_Logger::info("Filtered {$source_name} from " . count($items) . " to " . count($items_to_analyze) . " items using keywords");
                        }
                        
                        // AI analyzes ALL items from this source (or filtered subset)
                        $analyzed = $analyzer->analyze_batch($items_to_analyze, 5); // Returns top 5
                        
                        // Calculate average score of all analyzed
                        foreach ($analyzed as $analyzed_item) {
                            $source_stats['scores'][] = $analyzed_item['score'];
                            $source_stats['top5_scores'][] = $analyzed_item['score'];
                        }
                        
                        // Store only top 5 from this source
                        // Check optional columns once per source
                        $table = $wpdb->prefix . 'rawwire_content';
                        $has_url = false;
                        $has_relevance = false;

                        foreach ($analyzed as $analyzed_item) {
                            $item = $analyzed_item['original'];
                            
                            // Deduplication: Check if title already exists
                            $existing = $wpdb->get_var($wpdb->prepare(
                                "SELECT id FROM {$table} WHERE title = %s",
                                $item['title']
                            ));
                            
                            if ($existing) {
                                $source_stats['duplicates']++;
                                RawWire_Logger::debug("Skipping duplicate: {$item['title']}");
                                continue;
                            }
                            
                            // Build insert dynamically based on schema
                            $data = array(
                                'title' => sanitize_text_field($item['title'] ?? 'Untitled'),
                                'content' => wp_kses_post($item['content'] ?? ''),
                                'status' => 'pending',
                                'source' => $source_name,
                                'created_at' => current_time('mysql'),
                                'updated_at' => current_time('mysql')
                            );
                            $format = array('%s','%s','%s','%s','%s','%s');
                            if ($has_url) { $data['url'] = esc_url_raw($item['link'] ?? ''); $format[] = '%s'; }
                            if ($has_relevance) { $data['relevance'] = floatval($analyzed_item['score'] ?? 0); $format[] = '%f'; }

                            $inserted = $wpdb->insert($table, $data, $format);
                            
                            if ($inserted) {
                                $source_stats['stored']++;
                                $stats['total_stored']++;
                                
                                // Log the AI score for reference
                                RawWire_Logger::info("Stored item with score {$analyzed_item['score']}", array(
                                    'item_id' => $wpdb->insert_id,
                                    'title' => $item['title'],
                                    'source' => $source_name,
                                    'ai_scores' => $analyzed_item['scores'],
                                    'reasoning' => $analyzed_item['reasoning']
                                ));
                            }
                        }
                        
                        // Calculate averages
                        $source_stats['avg_score'] = !empty($source_stats['scores']) 
                            ? round(array_sum($source_stats['scores']) / count($source_stats['scores']), 1)
                            : 0;
                        $source_stats['avg_top5_score'] = !empty($source_stats['top5_scores'])
                            ? round(array_sum($source_stats['top5_scores']) / count($source_stats['top5_scores']), 1)
                            : 0;
                        
                        // Store detailed stats in option for dashboard display
                        $option_key = 'rawwire_source_stats_' . sanitize_title($source_name);
                        update_option($option_key, $source_stats);
                        
                        // Track per-source stats in response
                        $stats['sources'][$source_name] = $source_stats;
                    }
                } catch (Exception $e) {
                    // If AI fails, don't store anything - we need scoring
                    $stats['errors'][] = "AI analysis failed: " . $e->getMessage();
                    RawWire_Logger::error('AI analysis required but failed', array('error' => $e->getMessage()));
                }
            } else {
                $stats['errors'][] = "AI Content Analyzer not available - cannot score items";
            }

            $stats['success'] = true;
            $stats['message'] = "Synced {$stats['total_stored']} items (top 5 per source) from " . count($stats['sources']) . " sources";

            // After storing items, attempt batch scoring if enough unscored items exist
            try {
                $this->process_scoring_batch();
            } catch (Exception $e) {
                RawWire_Logger::warning('Batch scoring failed: ' . $e->getMessage());
            }

        } catch (Exception $e) {
            $stats['success'] = false;
            $stats['message'] = 'Sync failed: ' . $e->getMessage();
            $stats['errors'][] = $e->getMessage();
        }

        // Log the sync operation
        if (class_exists('RawWire_Logger')) {
            RawWire_Logger::log(
                $stats['success'] ? RawWire_Logger::INFO : RawWire_Logger::ERROR,
                $stats['message'],
                array('stats' => $stats)
            );
        }

        return $stats;
    }

    /**
     * Minimal generic HTML parser to extract items (title, content preview, link)
     */
    private static function extract_items_from_html(string $html, string $base_url) : array {
        $items = array();
        if (empty($html)) return $items;

        // Prefer DOM parsing if ext-dom is available
        if (class_exists('DOMDocument') && class_exists('DOMXPath')) {
            libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            $xp = new DOMXPath($dom);

            $queries = array(
                '//h2//a[@href]','//h3//a[@href]',
                "//*[@class and contains(concat(' ', normalize-space(@class), ' '), ' entry-title ')]//a[@href]",
                "//a[contains(@href,'federalregister.gov/documents')]",
                "//a[@href]"
            );

            $seen = array();
            foreach ($queries as $q) {
                $nodes = $xp->query($q);
                if (!$nodes || !$nodes->length) continue;
                foreach ($nodes as $a) {
                    $href = trim($a->getAttribute('href'));
                    $title = trim($a->textContent);
                    if (strlen($title) < 20) continue;
                    if (stripos($href, 'javascript:') === 0) continue;
                    $url = self::to_absolute_url($href, $base_url);
                    $key = md5($url . '|' . strtolower($title));
                    if (isset($seen[$key])) continue;

                    $summary = '';
                    $p = self::find_nearby_paragraph($a);
                    if ($p) { $summary = trim($p->textContent); }

                    $items[] = array(
                        'title' => self::clean_text($title),
                        'content' => self::clean_text(substr($summary, 0, 500)),
                        'link' => esc_url_raw($url),
                    );
                    $seen[$key] = true;
                    if (count($items) >= 50) break 2;
                }
            }
            return $items;
        }

        // Fallback: regex link extraction if DOM is unavailable
        if (preg_match_all('/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $m, PREG_SET_ORDER)) {
            $seen = array();
            foreach ($m as $match) {
                $href = trim(html_entity_decode($match[1]));
                $title = trim(strip_tags($match[2]));
                if (strlen($title) < 20) continue;
                if (stripos($href, 'javascript:') === 0) continue;
                $url = self::to_absolute_url($href, $base_url);
                $key = md5($url . '|' . strtolower($title));
                if (isset($seen[$key])) continue;
                $items[] = array(
                    'title' => self::clean_text($title),
                    'content' => '',
                    'link' => esc_url_raw($url),
                );
                $seen[$key] = true;
                if (count($items) >= 50) break;
            }
        }
        return $items;
    }

    private static function to_absolute_url(string $href, string $base) : string {
        if (parse_url($href, PHP_URL_SCHEME)) return $href;
        // Handle protocol-relative
        if (strpos($href, '//') === 0) {
            $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
            return $scheme . ':' . $href;
        }
        // Build relative
        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $path = isset($parts['path']) ? rtrim(dirname($parts['path']), '/') : '';
        if (strpos($href, '/') === 0) {
            return $scheme . '://' . $host . $href;
        }
        return $scheme . '://' . $host . ($path ? $path . '/' : '/') . $href;
    }

    private static function find_nearby_paragraph($a = null) {
        if (!$a) return null;
        // Next siblings
        $n = $a->parentNode;
        for ($i=0; $n && $i<6; $i++) {
            $n = $n->nextSibling;
            if ($n && strtolower($n->nodeName) === 'p' && trim($n->textContent) !== '') return $n;
        }
        // Parent then next sibling
        $p = $a->parentNode ? $a->parentNode->parentNode : null;
        if ($p) {
            $n = $p->nextSibling;
            for ($i=0; $n && $i<4; $i++) {
                if ($n && strtolower($n->nodeName) === 'p' && trim($n->textContent) !== '') return $n;
                $n = $n->nextSibling;
            }
        }
        return null;
    }

    private static function clean_text(string $t) : string { return trim(preg_replace('/\s+/',' ', $t)); }

    /**
     * Create database tables
     */
    private function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // Content table (enhanced for AI Scraper)
        $table_name = $wpdb->prefix . 'rawwire_content';

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            external_id varchar(100) DEFAULT NULL,
            title text NOT NULL,
            summary text,
            content longtext NOT NULL,
            source varchar(50) NOT NULL,
            source_url varchar(500) DEFAULT NULL,
            category varchar(50) DEFAULT 'document',
            status varchar(20) NOT NULL DEFAULT 'pending',
            relevance_score DECIMAL(5,2) DEFAULT 0,
            ai_score DECIMAL(5,2) DEFAULT NULL,
            ai_analysis JSON DEFAULT NULL,
            ai_discovered TINYINT(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY external_source (external_id, source),
            INDEX status_index (status),
            INDEX source_index (source),
            INDEX relevance_score_idx (relevance_score),
            INDEX category_idx (category)
        ) $charset_collate;";

        dbDelta($sql);

        // Logs table
        $logs_table = $wpdb->prefix . 'rawwire_logs';

        $sql_logs = "CREATE TABLE $logs_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            level varchar(20) NOT NULL DEFAULT 'info',
            message text NOT NULL,
            context longtext,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX level_index (level),
            INDEX timestamp_index (timestamp)
        ) $charset_collate;";

        dbDelta($sql_logs);
    }

    /**
     * Process a batch of unscored content items with AI scoring.
     * - Ensures ai_score and ai_discovered columns exist
     * - Runs AI analysis on oldest unscored items when count >= batch size
     * - Updates content rows with `ai_score` and `ai_analysis`
     * - Inserts top results into `rawwire_approvals` for the approvals page
     */
    public function process_scoring_batch() {
        global $wpdb;

        $table = $wpdb->prefix . 'rawwire_content';
        $batch_size = intval(get_option('rawwire_scoring_batch_size', 10));

        // Ensure columns exist (add if missing)
        $cols = $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);
        $col_names = array_map(function($c){ return $c['Field']; }, $cols);
        if (!in_array('ai_score', $col_names)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN ai_score DECIMAL(5,2) NULL");
        }
        if (!in_array('ai_analysis', $col_names)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN ai_analysis JSON NULL");
        }
        if (!in_array('ai_discovered', $col_names)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN ai_discovered TINYINT(1) DEFAULT 0");
        }

        // Count unscored items
        $pending_count = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE ai_discovered = 0 OR ai_discovered IS NULL"));
        if ($pending_count < $batch_size) {
            RawWire_Logger::info("Pending items ({$pending_count}) below batch size ({$batch_size}); skipping batch scoring");
            return false;
        }

        // Fetch oldest pending items up to batch_size
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE ai_discovered = 0 OR ai_discovered IS NULL ORDER BY created_at ASC LIMIT %d", $batch_size), ARRAY_A);
        if (empty($rows)) return false;

        // Prepare items for analyzer
        $items = array_map(function($r){
            return array('title' => $r['title'], 'content' => $r['content'], 'source' => $r['source'], 'id' => $r['id']);
        }, $rows);

        // Run analyzer
        $analyzer = new RawWire_AI_Content_Analyzer();
        $scored = $analyzer->analyze_batch($items, $batch_size);

        if (empty($scored)) {
            RawWire_Logger::warning('Batch analyzer returned no scored items');
            return false;
        }

        $inserted_approvals = array();
        $approvals_table = $wpdb->prefix . 'rawwire_approvals';

        foreach ($scored as $s) {
            $orig = $s['original'];
            $content_id = $orig['id'] ?? null;
            $score = floatval($s['score']);

            // Update content row with ai_score, ai_analysis, ai_discovered
            $wpdb->update($table, array(
                'ai_score' => $score,
                'ai_analysis' => wp_json_encode(array('scores' => $s['scores'], 'reasoning' => $s['reasoning'] ?? '', 'highlights' => $s['highlights'] ?? [])),
                'ai_discovered' => 1
            ), array('id' => $content_id), array('%f','%s','%d'), array('%d'));

            // Insert into approvals table for approval queue
            $inserted = $wpdb->insert($approvals_table, array(
                'source' => 'auto_batch',
                'title' => $orig['title'],
                'excerpt' => wp_trim_words($orig['content'], 40),
                'content' => $orig['content'],
                'url' => '',
                'score' => $score,
                'status' => 'pending',
                'category' => 'ai_scored',
                'metadata' => wp_json_encode(array(
                    'scores' => $s['scores'], 
                    'reasoning' => $s['reasoning'] ?? '',
                    'content_id' => $content_id,
                    'is_public_domain' => 1
                )),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ));

            if ($inserted) {
                $inserted_approvals[] = $wpdb->insert_id;
            }
        }

        // Fire a hook so the acceptance page can refresh or react
        if (!empty($inserted_approvals)) {
            // Store a small pointer so front-end can detect new batches
            update_option('rawwire_last_batch_time', time());
            update_option('rawwire_last_batch_ids', wp_json_encode($inserted_approvals));

            do_action('rawwire_after_batch_scoring', $inserted_approvals);

            // Optionally auto-move high-scoring items to content stage
            $threshold = floatval(get_option('rawwire_auto_approve_threshold', 0));
            $approved_ids = array();
            if ($threshold > 0) {
                $content_table = $wpdb->prefix . 'rawwire_content';
                foreach ($inserted_approvals as $idx => $aid) {
                    $score = floatval($scored[$idx]['score'] ?? 0);
                    if ($score >= $threshold) {
                        // Mark approval as approved
                        $wpdb->update($approvals_table, array('status' => 'approved', 'updated_at' => current_time('mysql')), array('id' => $aid), array('%s','%s'), array('%d'));

                        // Move to content table for generation
                        $approval = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$approvals_table} WHERE id = %d", $aid), ARRAY_A);
                        if ($approval) {
                            $wpdb->insert($content_table, array(
                                'source' => $approval['source'] ?? 'auto_batch',
                                'title' => $approval['title'],
                                'content' => $approval['content'],
                                'excerpt' => $approval['excerpt'] ?? '',
                                'url' => $approval['url'] ?? '',
                                'score' => $approval['score'] ?? 0,
                                'status' => 'pending',
                                'category' => $approval['category'] ?? 'ai_scored',
                                'metadata' => $approval['metadata'] ?? null,
                                'created_at' => current_time('mysql'),
                                'updated_at' => current_time('mysql')
                            ));
                        }

                        $approved_ids[] = $aid;
                    }
                }

                if (!empty($approved_ids)) {
                    do_action('rawwire_after_batch_auto_approve', $approved_ids);
                    RawWire_Logger::info('Auto-approved batch items', array('count' => count($approved_ids), 'ids' => $approved_ids));
                }
            }

            RawWire_Logger::info('Batch scoring completed', array('count' => count($inserted_approvals), 'ids' => $inserted_approvals));
        }

        return $inserted_approvals;
    }

    /**
     * AJAX handler for saving templates
     */
    public function ajax_save_template() {
        check_ajax_referer('rawwire_template_builder', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }

        $template_json = isset($_POST['template']) ? $_POST['template'] : '';
        
        if (empty($template_json)) {
            wp_send_json_error(array('message' => 'No template data provided'));
        }

        $template = json_decode(stripslashes($template_json), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(array('message' => 'Invalid JSON: ' . json_last_error_msg()));
        }

        // Validate required fields
        if (empty($template['meta']['name']) || empty($template['meta']['id'])) {
            wp_send_json_error(array('message' => 'Template name and ID are required'));
        }

        // Save template file
        $templates_dir = plugin_dir_path(__FILE__) . 'templates/';
        if (!file_exists($templates_dir)) {
            mkdir($templates_dir, 0755, true);
        }

        $filename = sanitize_file_name($template['meta']['id']) . '.template.json';
        $filepath = $templates_dir . $filename;

        $result = file_put_contents($filepath, json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($result === false) {
            wp_send_json_error(array('message' => 'Failed to save template file'));
        }

        // Set as active template
        update_option('rawwire_active_template', $template['meta']['id']);

        wp_send_json_success(array(
            'message' => 'Template saved successfully',
            'template_id' => $template['meta']['id'],
            'redirect' => admin_url('admin.php?page=raw-wire-dashboard')
        ));
    }
}

/**
 * Initialize the plugin
 */
function rawwire_dashboard() {
    return RawWire_Dashboard::get_instance();
}

// Start the plugin
rawwire_dashboard();