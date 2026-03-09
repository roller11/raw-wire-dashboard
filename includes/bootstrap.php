<?php

/**
 * RawWire Bootstrap - Initialization and Asset Loading
 * 
 * ╔═══════════════════════════════════════════════════════════════════════════╗
 * ║  STOP! BOOTSTRAP - SUBSYSTEM #1: BOOTSTRAP & LIFECYCLE                    ║
 * ╠═══════════════════════════════════════════════════════════════════════════╣
 * ║                                                                           ║
 * ║  📚 Full Documentation: docs/SUBSYSTEM_AUDIT.md (Section 1)              ║
 * ║                                                                           ║
 * ║  This file handles ONLY:                                                  ║
 * ║  • REST API initialization                                                ║
 * ║  • Asset (CSS/JS) enqueueing                                              ║
 * ║  • Activity logs initialization                                           ║
 * ║                                                                           ║
 * ║  MENU REGISTRATION IS NOT HERE!                                           ║
 * ║  All menu/submenu registration is in: class-menu-manager.php              ║
 * ║                                                                           ║
 * ║  ARCHITECTURE OVERVIEW:                                                   ║
 * ║  ┌─────────────────────────────────────────────────────────────────────┐  ║
 * ║  │ class-menu-manager.php                                              │  ║
 * ║  │ • Registers hardwired menu: Dashboard, Templates, Tools,            │  ║
 * ║  │   Workflows, Settings                                               │  ║
 * ║  │ • Tools register TABS (not submenus) via register_tool_tab()        │  ║
 * ║  │ • Workflows register TABS via register_workflow_tab()               │  ║
 * ║  └─────────────────────────────────────────────────────────────────────┘  ║
 * ║  ┌─────────────────────────────────────────────────────────────────────┐  ║
 * ║  │ bootstrap.php (THIS FILE)                                           │  ║
 * ║  │ • Loads Menu Manager                                                │  ║
 * ║  │ • REST API setup                                                    │  ║
 * ║  │ • CSS/JS assets                                                     │  ║
 * ║  └─────────────────────────────────────────────────────────────────────┘  ║
 * ║  ┌─────────────────────────────────────────────────────────────────────┐  ║
 * ║  │ modules/core/module.php                                             │  ║
 * ║  │ • Panel Registry (all panel definitions)                            │  ║
 * ║  │ • Panel Renderers (all rendering logic)                             │  ║
 * ║  └─────────────────────────────────────────────────────────────────────┘  ║
 * ║                                                                           ║
 * ║  DO NOT add add_menu_page() or add_submenu_page() calls here!             ║
 * ╚═══════════════════════════════════════════════════════════════════════════╝
 * 
 * @package RawWire_Dashboard
 */

if (!defined("ABSPATH")) {
    exit;
}

// Include required classes (optional)
$activity_logs = plugin_dir_path(__FILE__) . 'class-activity-logs.php';
if (file_exists($activity_logs)) {
    require_once $activity_logs;
}

// Load Developer Authentication
$dev_auth = plugin_dir_path(__FILE__) . 'class-dev-auth.php';
if (file_exists($dev_auth)) {
    require_once $dev_auth;
}

// Load Config Authority (signed configuration system)
$config_authority = plugin_dir_path(__FILE__) . 'class-config-authority.php';
if (file_exists($config_authority)) {
    require_once $config_authority;
}

// Load Investigation Display template functions
$investigation_template = plugin_dir_path(__FILE__) . '../templates/investigation-display.php';
if (file_exists($investigation_template)) {
    require_once $investigation_template;
}

class RawWire_Bootstrap
{
    private const ASSET_VERSION = '1.0.15';

    public static function init(): void
    {
        /**
         * ╔═══════════════════════════════════════════════════════════════════╗
         * ║  STOP! Menu registration is now handled by RawWire_Menu_Manager   ║
         * ║  Do NOT register menus here - use the Menu Manager instead.       ║
         * ║  This bootstrap only handles REST API and asset loading.          ║
         * ╚═══════════════════════════════════════════════════════════════════╝
         */

        // Load Menu Manager (handles all menu registration)
        require_once plugin_dir_path(__FILE__) . 'class-menu-manager.php';
        RawWire_Menu_Manager::init();

        // Initialize Developer Auth AJAX handlers
        if (class_exists('RawWire_Dev_Auth')) {
            RawWire_Dev_Auth::init();
        }

        // Load Soothsayer v2 for AJAX handlers (must be loaded early)
        $soothsayer_v2 = plugin_dir_path(__FILE__) . '../admin/class-soothsayer-v2.php';
        if (file_exists($soothsayer_v2)) {
            require_once $soothsayer_v2;
            RawWire_Soothsayer_V2::get_instance();
        }

        // Tag body on Raw Wire admin pages so CSS can target the WP shell
        add_filter('admin_body_class', [__CLASS__, 'add_body_class']);

        add_action("rest_api_init", [__CLASS__, "maybe_register_rest"]);

        // Initialize activity logs if available
        if (class_exists('RawWire_Activity_Logs') && method_exists('RawWire_Activity_Logs', 'init')) {
            RawWire_Activity_Logs::init();
        }
    }

    /**
     * Add body class on Raw Wire admin pages
     */
    public static function add_body_class(string $classes): string
    {
        $screen = get_current_screen();
        if ($screen && (
            strpos($screen->id, 'raw-wire') !== false ||
            strpos($screen->id, 'rawwire') !== false
        )) {
            $classes .= ' rawwire-admin-page';
        }
        return $classes;
    }

    public static function enqueue_assets($hook): void
    {
        // Only load dashboard assets on Raw-Wire admin pages
        if (strpos((string)$hook, "raw-wire") === false && strpos((string)$hook, "rawwire") === false) {
            return;
        }

        $base = plugin_dir_url(__FILE__);
        wp_enqueue_style("rawwire-dashboard", $base . "../dashboard.css", [], self::ASSET_VERSION);
        wp_enqueue_script("rawwire-dashboard", $base . "../dashboard.js", ["jquery"], self::ASSET_VERSION, true);

        // Dashboard controller - mode switching, workflow launch, dynamic stats
        wp_enqueue_script("rawwire-dashboard-controller", $base . "../js/dashboard-controller.js", ["jquery", "rawwire-dashboard"], self::ASSET_VERSION, true);

        $inline_theme_css = self::build_inline_theme_css();
        if (!empty($inline_theme_css)) {
            wp_add_inline_style('rawwire-dashboard', $inline_theme_css);
        }

        // Activity logs assets - style only, script is handled by RawWire_Activity_Logs class
        wp_enqueue_style("rawwire-activity-logs", $base . "../css/activity-logs.css", [], "1.0.11");
        // NOTE: Do NOT enqueue the activity-logs.js script here - it's handled by the class-activity-logs.php enqueue_scripts method

        // Party Investigation display assets
        wp_enqueue_style("rawwire-investigation", $base . "../css/investigation-display.css", [], "1.0.31");
        wp_enqueue_script("rawwire-investigation", $base . "../js/investigation-display.js", [], "1.0.31", true);

        $has_api_key = !empty(get_option('rawwire_api_key_hash', '')) || !empty(get_option('rawwire_api_key', ''));
        $template_name = 'raw-wire-default';
        if (class_exists('RawWire_Module_Core') && method_exists('RawWire_Module_Core', 'get_template_config')) {
            $cfg = RawWire_Module_Core::get_template_config();
            if (is_array($cfg) && !empty($cfg['name'])) {
                $template_name = (string) $cfg['name'];
            }
        }

        // Check dev mode status
        $dev_mode = class_exists('RawWire_Dev_Auth') ? RawWire_Dev_Auth::is_dev_mode_active() : false;

        wp_localize_script("rawwire-dashboard", "RawWireCfg", [
            "nonce" => wp_create_nonce("wp_rest"),
            "adminNonce" => wp_create_nonce("rawwire_admin_nonce"),
            "rest" => esc_url_raw(rest_url("rawwire/v1")),
            "ajaxurl" => admin_url('admin-ajax.php'),
            "hasApiKey" => (bool) $has_api_key,
            "template" => $template_name,
            "devMode" => $dev_mode,
            "userCaps" => [
                "manage_options" => current_user_can("manage_options"),
                "edit_posts" => current_user_can("edit_posts"),
            ],
        ]);

        // Dashboard controller localization - mode switching, workflow launch
        wp_localize_script("rawwire-dashboard-controller", "RawWireDashboardCtrl", [
            "ajaxurl" => admin_url('admin-ajax.php'),
            "nonce"   => wp_create_nonce("rawwire_dashboard_ctrl"),
        ]);

        // Activity logs are localized by the Activity Logs class to avoid duplicate globals
        // See: includes/class-activity-logs.php (it calls wp_localize_script for `rawwire-activity-logs`).
    }
    public static function render_dashboard($context = array()): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Use template-based rendering if available (new system)
        if (class_exists('RawWire_Page_Renderer') && class_exists('RawWire_Template_Engine')) {
            $template = RawWire_Template_Engine::get_active_template();

            if ($template) {
                // Render with template engine — forward context flags
                echo RawWire_Page_Renderer::render_dashboard($context);
                return;
            }
        }

        // Fallback to legacy dashboard-template.php
        global $wpdb;
        $table = $wpdb->prefix . 'rawwire_content';
        $template_config = array();

        // Get stats
        $stats = [
            'total_issues' => $wpdb->get_var("SELECT COUNT(*) FROM {$table}") ?: 0,
            'pending_issues' => $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s", 'pending')) ?: 0,
            'approved_issues' => $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s", 'approved')) ?: 0,
            'total_results' => $wpdb->get_var("SELECT COUNT(*) FROM {$table}") ?: 0,
            'last_sync' => get_option('rawwire_last_sync', 'Never'),
        ];

        // Get recent issues
        $recent_issues = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 20", ARRAY_A);
        $findings = self::prepare_findings($recent_issues, $template_config);

        // Activity logs stats
        $activity_stats = [
            'total_info' => 0,
            'total_errors' => 0,
            'last_activity' => 'Never'
        ];

        $ui_metrics = self::summarize_findings($findings);

        $template_file = plugin_dir_path(__FILE__) . "../dashboard-template.php";
        if (file_exists($template_file)) {
            include $template_file;
        } else {
            // Show helpful fallback instead of just "Template missing"
            self::render_fallback_dashboard();
        }
    }

    /**
     * Render fallback dashboard when no template system is active
     */
    private static function render_fallback_dashboard(): void
    {
?>
        <div class="wrap rawwire-fallback-dashboard">
            <h1><?php _e('Raw-Wire Dashboard', 'raw-wire-dashboard'); ?></h1>
            <div class="notice notice-warning">
                <p><strong><?php _e('Template Not Loaded', 'raw-wire-dashboard'); ?></strong></p>
                <p><?php _e('The dashboard template system is not active. This could be because:', 'raw-wire-dashboard'); ?></p>
                <ul style="list-style: disc; margin-left: 20px;">
                    <li><?php _e('No template file exists in the templates/ directory', 'raw-wire-dashboard'); ?></li>
                    <li><?php _e('The template engine failed to initialize', 'raw-wire-dashboard'); ?></li>
                </ul>
                <p>
                    <a href="<?php echo admin_url('admin.php?page=rawwire-templates'); ?>" class="button button-primary">
                        <?php _e('Manage Templates', 'raw-wire-dashboard'); ?>
                    </a>
                </p>
            </div>
        </div>
<?php
    }

    public static function maybe_register_rest(): void
    {
        // REST API registration is handled by main plugin file
        // This method kept for backward compatibility
        do_action('rawwire_bootstrap_rest_init');
    }

    private static function load_template_config(): array
    {
        // Extraction #1 (MC): template config loading is now owned by Module Core.
        // This method remains for backward compatibility and delegates.
        if (class_exists('RawWire_Module_Core') && method_exists('RawWire_Module_Core', 'get_template_config')) {
            $config = RawWire_Module_Core::get_template_config();
            if (is_array($config)) {
                return $config;
            }
        }

        // Absolute fallback (should rarely be hit).
        return array(
            'name' => 'fallback',
            'theme' => array(
                'accent' => '#0d9488',
                'accentBold' => '#0f766e',
                'surface' => '#0b1724',
                'card' => '#0f1f33',
                'muted' => '#8aa0b7',
            ),
            'columns' => array(),
            'badges' => array(),
            'filters' => array(),
        );
    }

    /**
     * Build per-template theme variable overrides, scoped to the dashboard container.
     *
     * This is the main styling modularity mechanism: modules/templates can provide
     * a theme block in the template config and it will be reflected in CSS vars
     * without changing the dashboard markup.
     */
    private static function build_inline_theme_css(): string
    {
        $template_name = 'raw-wire-default';
        $theme = array();

        if (class_exists('RawWire_Module_Core') && method_exists('RawWire_Module_Core', 'get_template_config')) {
            $cfg = RawWire_Module_Core::get_template_config();
            if (is_array($cfg)) {
                if (!empty($cfg['name'])) {
                    $template_name = (string) $cfg['name'];
                }
                if (!empty($cfg['theme']) && is_array($cfg['theme'])) {
                    $theme = $cfg['theme'];
                }
            }
        }

        $vars = array(
            '--rw-accent' => $theme['accent'] ?? null,
            '--rw-accent-strong' => $theme['accentBold'] ?? null,
            '--rw-surface' => $theme['surface'] ?? null,
            '--rw-card' => $theme['card'] ?? null,
            '--rw-muted' => $theme['muted'] ?? null,
        );

        $pairs = array();
        foreach ($vars as $name => $value) {
            // Sanitize color values to prevent CSS injection
            if (class_exists('RawWire_Validator') && method_exists('RawWire_Validator', 'sanitize_css_color')) {
                $sanitized = RawWire_Validator::sanitize_css_color($value);
            } else {
                // Fallback basic hex-only sanitizer: accept only #hex (3-8 chars)
                if (is_string($value) && preg_match('/^#([A-Fa-f0-9]{3,8})$/', trim($value))) {
                    $sanitized = strtolower(trim($value));
                } else {
                    $sanitized = false;
                }
            }

            if ($sanitized === false) {
                continue;
            }
            $pairs[] = $name . ':' . $sanitized;
        }

        if (empty($pairs)) {
            return '';
        }

        // Scope to dashboard wrapper AND template identifier.
        return '.wrap.rawwire-dashboard[data-rawwire-template="' . esc_attr($template_name) . '"]{' . implode(';', $pairs) . ';}';
    }

    private static function prepare_findings(array $issues, array $template): array
    {
        $defaults = [
            'parameters' => [
                'novelty',
                'regulatory-impact',
                'market-sentiment',
                'technical-signal',
                'risk-profile'
            ]
        ];
        $prepared = [];
        foreach ($issues as $index => $issue) {
            $source_data = self::decode_json($issue['source_data'] ?? '');
            $score = self::normalize_score($issue['relevance'] ?? null);
            $published_at = $issue['published_at'] ?? $issue['created_at'] ?? null;
            $freshness_seconds = $published_at ? abs(time() - strtotime($published_at)) : null;
            $prepared[] = [
                'id' => (int)($issue['id'] ?? 0),
                'issue_number' => $issue['issue_number'] ?? null,
                'title' => $issue['title'] ?? 'Untitled finding',
                'summary' => $source_data['summary'] ?? ($issue['notes'] ?? ($issue['content'] ?? '')),
                'source' => $source_data['source'] ?? self::infer_source($issue),
                'source_name' => $source_data['source_name'] ?? self::infer_source_name($issue),
                'category' => $issue['category'] ?? ($source_data['category'] ?? 'uncategorized'),
                'score' => $score,
                'confidence' => isset($source_data['confidence']) ? floatval($source_data['confidence']) : 0.72,
                'rank' => $index + 1,
                'status' => $issue['status'] ?? 'pending',
                'state' => $issue['state'] ?? 'open',
                'freshness' => $freshness_seconds,
                'freshness_label' => self::format_freshness($published_at),
                'tags' => $source_data['tags'] ?? self::derive_tags($issue),
                'parameters' => $source_data['parameters'] ?? $defaults['parameters'],
                'rationale' => $source_data['rationale'] ?? '',
                'link' => $issue['url'] ?? ($source_data['link'] ?? ''),
                'updated_at' => $issue['updated_at'] ?? $issue['created_at'] ?? '',
                'raw' => $issue,
                'template' => $template['name'] ?? 'raw-wire-default'
            ];
        }
        return $prepared;
    }

    private static function summarize_findings(array $findings): array
    {
        if (empty($findings)) {
            return [
                'total' => 0,
                'pending' => 0,
                'approved' => 0,
                'fresh_24h' => 0,
                'avg_score' => 0,
            ];
        }
        $pending = 0;
        $approved = 0;
        $fresh24 = 0;
        $scoreSum = 0;
        $scored = 0;
        foreach ($findings as $f) {
            if (($f['status'] ?? '') === 'pending') {
                $pending++;
            }
            if (($f['status'] ?? '') === 'approved') {
                $approved++;
            }
            if (!empty($f['freshness']) && $f['freshness'] <= 86400) {
                $fresh24++;
            }
            if (isset($f['score']) && is_numeric($f['score'])) {
                $scoreSum += $f['score'];
                $scored++;
            }
        }
        return [
            'total' => count($findings),
            'pending' => $pending,
            'approved' => $approved,
            'fresh_24h' => $fresh24,
            'avg_score' => $scored ? round($scoreSum / $scored, 1) : 0,
        ];
    }

    private static function decode_json($raw): array
    {
        if (empty($raw)) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function format_freshness($date): string
    {
        if (!$date) {
            return 'Unknown';
        }
        $timestamp = strtotime($date);
        if (!$timestamp) {
            return 'Unknown';
        }
        if (function_exists('human_time_diff')) {
            return human_time_diff($timestamp, time()) . ' ago';
        }
        $hours = floor((time() - $timestamp) / 3600);
        return $hours . 'h ago';
    }

    private static function normalize_score($raw): float
    {
        if (!is_numeric($raw)) {
            return 0;
        }
        $value = floatval($raw);
        if ($value <= 1) {
            return round($value * 100, 1);
        }
        if ($value > 100) {
            return round(min($value, 100), 1);
        }
        return round($value, 1);
    }

    private static function infer_source(array $issue): string
    {
        if (!empty($issue['state'])) {
            return 'github';
        }
        return 'unknown';
    }

    private static function infer_source_name(array $issue): string
    {
        if (!empty($issue['url'])) {
            $host = wp_parse_url($issue['url'], PHP_URL_HOST);
            return $host ?: 'Source';
        }
        return 'Source';
    }

    private static function derive_tags(array $issue): array
    {
        $tags = [];
        if (!empty($issue['category'])) {
            $tags[] = $issue['category'];
        }
        if (!empty($issue['state'])) {
            $tags[] = $issue['state'];
        }
        return array_values(array_unique($tags));
    }
}

// Bootstrap::init() is now called by RawWire_Init_Controller in Phase 6
// Removed standalone init call to prevent double initialization
