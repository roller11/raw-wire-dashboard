<?php
if (!defined("ABSPATH")) { exit; }

// Include required classes (optional)
$activity_logs = plugin_dir_path(__FILE__) . 'class-activity-logs.php';
if (file_exists($activity_logs)) {
    require_once $activity_logs;
}

class RawWire_Bootstrap {
    private const ASSET_VERSION = '1.0.15';

    public static function init() : void {
        add_action("admin_menu", [__CLASS__, "register_menu"], 5); // Priority 5 - run before submenus
        add_action("admin_menu", [__CLASS__, "register_template_pages"], 6); // Priority 6 - after main menu
        add_action("rest_api_init", [__CLASS__, "maybe_register_rest"]);

        // Initialize activity logs if available
        if (class_exists('RawWire_Activity_Logs') && method_exists('RawWire_Activity_Logs', 'init')) {
            RawWire_Activity_Logs::init();
        }
    }
    public static function register_menu() : void {
        if (!current_user_can("manage_options")) { return; }
        add_menu_page("Raw-Wire","Raw-Wire","manage_options","raw-wire-dashboard",[__CLASS__,"render_dashboard"],"dashicons-chart-line",26);
    }

    /**
     * Register dynamic pages from template pageDefinitions
     */
    public static function register_template_pages() : void {
        if (!current_user_can("manage_options")) { return; }

        // Load active template
        if (!class_exists('RawWire_Template_Engine') || !method_exists('RawWire_Template_Engine', 'get_active_template')) {
            return;
        }

        $template = RawWire_Template_Engine::get_active_template();
        if (empty($template) || !is_array($template)) {
            return;
        }

        $pages = $template['pageDefinitions'] ?? array();
        if (empty($pages) || !is_array($pages)) {
            return;
        }

        // Register each enabled page from template
        foreach ($pages as $page_key => $page_def) {
            // Skip if not enabled or hardcoded (already registered elsewhere)
            if (empty($page_def['enabled']) || in_array($page_def['slug'] ?? '', ['raw-wire-dashboard', 'raw-wire-settings', 'raw-wire-approvals', 'raw-wire-release', 'raw-wire-templates', 'raw-wire-edit-template'])) {
                continue;
            }

            $slug = $page_def['slug'] ?? 'raw-wire-' . $page_key;
            $label = $page_def['label'] ?? ucfirst(str_replace('-', ' ', $page_key));

            // Register as submenu if enabled
            add_submenu_page(
                'raw-wire-dashboard',
                $label,
                $label,
                'manage_options',
                $slug,
                function() use ($page_key) {
                    if (class_exists('RawWire_Page_Renderer')) {
                        echo RawWire_Page_Renderer::render($page_key);
                    } else {
                        echo '<div class="wrap"><h1>' . esc_html($page_key) . '</h1><p>Template renderer not available.</p></div>';
                    }
                }
            );
        }
    }
    public static function enqueue_assets($hook) : void {
        error_log("RawWire Bootstrap: enqueue_assets called with hook: " . $hook);
        
        // TEMPORARILY DISABLE HOOK CHECK FOR DEBUGGING
        // Check if we're on any Raw-Wire admin page
        // if (strpos((string)$hook, "raw-wire") === false && strpos((string)$hook, "rawwire") === false) {
        //     error_log("RawWire Bootstrap: Hook doesn't contain raw-wire or rawwire, skipping");
        //     return;
        // }
        
        $base = plugin_dir_url(__FILE__);
        wp_enqueue_style("rawwire-dashboard", $base . "../dashboard.css", [], self::ASSET_VERSION);
        wp_enqueue_script("rawwire-dashboard", $base . "../dashboard.js", ["jquery"], self::ASSET_VERSION, true);

        $inline_theme_css = self::build_inline_theme_css();
        if (!empty($inline_theme_css)) {
            wp_add_inline_style('rawwire-dashboard', $inline_theme_css);
        }
        
        // Activity logs assets - style only, script is handled by RawWire_Activity_Logs class
        wp_enqueue_style("rawwire-activity-logs", $base . "../css/activity-logs.css", [], "1.0.11");
        // NOTE: Do NOT enqueue the activity-logs.js script here - it's handled by the class-activity-logs.php enqueue_scripts method
        
        $has_api_key = !empty(get_option('rawwire_api_key_hash', '')) || !empty(get_option('rawwire_api_key', ''));
        $template_name = 'raw-wire-default';
        if (class_exists('RawWire_Module_Core') && method_exists('RawWire_Module_Core', 'get_template_config')) {
            $cfg = RawWire_Module_Core::get_template_config();
            if (is_array($cfg) && !empty($cfg['name'])) {
                $template_name = (string) $cfg['name'];
            }
        }

        wp_localize_script("rawwire-dashboard", "RawWireCfg", [
            "nonce" => wp_create_nonce("wp_rest"),
            "rest" => esc_url_raw(rest_url("rawwire/v1")),
            "ajaxurl" => admin_url('admin-ajax.php'),
            "hasApiKey" => (bool) $has_api_key,
            "template" => $template_name,
            "userCaps" => [
                "manage_options" => current_user_can("manage_options"),
                "edit_posts" => current_user_can("edit_posts"),
            ],
        ]);
        
        // Activity logs are localized by the Activity Logs class to avoid duplicate globals
        // See: includes/class-activity-logs.php (it calls wp_localize_script for `rawwire-activity-logs`).
    }
    public static function render_dashboard() : void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Use template-based rendering if available (new system)
        if (class_exists('RawWire_Page_Renderer') && class_exists('RawWire_Template_Engine')) {
            $template = RawWire_Template_Engine::get_active_template();
            
            if ($template) {
                // Render with template engine
                echo RawWire_Page_Renderer::render_dashboard();
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
    private static function render_fallback_dashboard() : void {
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
                    <a href="<?php echo admin_url('admin.php?page=raw-wire-templates'); ?>" class="button button-primary">
                        <?php _e('Manage Templates', 'raw-wire-dashboard'); ?>
                    </a>
                </p>
            </div>
        </div>
        <?php
    }

    public static function maybe_register_rest() : void {
        // REST API registration is handled by main plugin file
        // This method kept for backward compatibility
        do_action('rawwire_bootstrap_rest_init');
    }

    private static function load_template_config() : array {
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
    private static function build_inline_theme_css() : string {
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

    private static function prepare_findings(array $issues, array $template) : array {
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

    private static function summarize_findings(array $findings) : array {
        if (empty($findings)) {
            return [
                'total' => 0,
                'pending' => 0,
                'approved' => 0,
                'fresh_24h' => 0,
                'avg_score' => 0,
            ];
        }
        $pending = 0; $approved = 0; $fresh24 = 0; $scoreSum = 0; $scored = 0;
        foreach ($findings as $f) {
            if (($f['status'] ?? '') === 'pending') { $pending++; }
            if (($f['status'] ?? '') === 'approved') { $approved++; }
            if (!empty($f['freshness']) && $f['freshness'] <= 86400) { $fresh24++; }
            if (isset($f['score']) && is_numeric($f['score'])) { $scoreSum += $f['score']; $scored++; }
        }
        return [
            'total' => count($findings),
            'pending' => $pending,
            'approved' => $approved,
            'fresh_24h' => $fresh24,
            'avg_score' => $scored ? round($scoreSum / $scored, 1) : 0,
        ];
    }

    private static function decode_json($raw) : array {
        if (empty($raw)) { return []; }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function format_freshness($date) : string {
        if (!$date) { return 'Unknown'; }
        $timestamp = strtotime($date);
        if (!$timestamp) { return 'Unknown'; }
        if (function_exists('human_time_diff')) {
            return human_time_diff($timestamp, time()) . ' ago';
        }
        $hours = floor((time() - $timestamp) / 3600);
        return $hours . 'h ago';
    }

    private static function normalize_score($raw) : float {
        if (!is_numeric($raw)) { return 0; }
        $value = floatval($raw);
        if ($value <= 1) { return round($value * 100, 1); }
        if ($value > 100) { return round(min($value, 100), 1); }
        return round($value, 1);
    }

    private static function infer_source(array $issue) : string {
        if (!empty($issue['state'])) { return 'github'; }
        return 'unknown';
    }

    private static function infer_source_name(array $issue) : string {
        if (!empty($issue['url'])) {
            $host = wp_parse_url($issue['url'], PHP_URL_HOST);
            return $host ?: 'Source';
        }
        return 'Source';
    }

    private static function derive_tags(array $issue) : array {
        $tags = [];
        if (!empty($issue['category'])) { $tags[] = $issue['category']; }
        if (!empty($issue['state'])) { $tags[] = $issue['state']; }
        return array_values(array_unique($tags));
    }
}

// Bootstrap::init() is now called by RawWire_Init_Controller in Phase 6
// Removed standalone init call to prevent double initialization
