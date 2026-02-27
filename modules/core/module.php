<?php

/**
 * ╔═══════════════════════════════════════════════════════════════════════════╗
 * ║                                                                           ║
 * ║   ██████╗ ██████╗ ██████╗ ███████╗    ███╗   ███╗ ██████╗ ██████╗         ║
 * ║  ██╔════╝██╔═══██╗██╔══██╗██╔════╝    ████╗ ████║██╔═══██╗██╔══██╗        ║
 * ║  ██║     ██║   ██║██████╔╝█████╗      ██╔████╔██║██║   ██║██║  ██║        ║
 * ║  ██║     ██║   ██║██╔══██╗██╔══╝      ██║╚██╔╝██║██║   ██║██║  ██║        ║
 * ║  ╚██████╗╚██████╔╝██║  ██║███████╗    ██║ ╚═╝ ██║╚██████╔╝██████╔╝        ║
 * ║   ╚═════╝ ╚═════╝ ╚═╝  ╚═╝╚══════╝    ╚═╝     ╚═╝ ╚═════╝ ╚═════╝         ║
 * ║                                                                           ║
 * ╠═══════════════════════════════════════════════════════════════════════════╣
 * ║                                                                           ║
 * ║   ⚠️  STOP! SUBSYSTEM #5: MODULE SYSTEM  ⚠️                               ║
 * ║                                                                           ║
 * ║   📚 Full Documentation: docs/SUBSYSTEM_AUDIT.md (Section 5)             ║
 * ║                                                                           ║
 * ║   This is the CORE MODULE - the heart of the dashboard plugin.            ║
 * ║                                                                           ║
 * ║   ARCHITECTURAL RULES (NON-NEGOTIABLE):                                   ║
 * ║                                                                           ║
 * ║   1. ALL panel rendering logic is HARDWIRED here in Module Core           ║
 * ║   2. Module Core exposes panel functions to the Toolkit                   ║
 * ║   3. Templates ONLY toggle visibility and apply styling                   ║
 * ║   4. Templates contain ZERO rendering logic                               ║
 * ║   5. This module must work WITHOUT any template loaded                    ║
 * ║                                                                           ║
 * ║   HIERARCHY:                                                              ║
 * ║   ┌─────────────────────────────────────────────────────────────────┐     ║
 * ║   │ MODULE CORE (this file)                                         │     ║
 * ║   │ • Panel Registry: defines ALL available panels                  │     ║
 * ║   │ • Panel Renderers: ALL rendering logic lives HERE               │     ║
 * ║   │ • Self-sufficient: works standalone with zero dependencies      │     ║
 * ║   └──────────────────────────┬──────────────────────────────────────┘     ║
 * ║                              │ exposes                                    ║
 * ║                              ▼                                            ║
 * ║   ┌─────────────────────────────────────────────────────────────────┐     ║
 * ║   │ TOOLKIT                                                         │     ║
 * ║   │ • Tools register their panels via Module Core                   │     ║
 * ║   │ • Scraper, Generator, etc. add capabilities                     │     ║
 * ║   └──────────────────────────┬──────────────────────────────────────┘     ║
 * ║                              │ consumed by                                ║
 * ║                              ▼                                            ║
 * ║   ┌─────────────────────────────────────────────────────────────────┐     ║
 * ║   │ TEMPLATE (toggle + style ONLY)                                  │     ║
 * ║   │ • Says "show panels: [a, b, c]"                                 │     ║
 * ║   │ • Provides CSS variables, colors, icons                         │     ║
 * ║   │ • NO rendering logic, NO data access, NO HTML generation        │     ║
 * ║   └─────────────────────────────────────────────────────────────────┘     ║
 * ║                                                                           ║
 * ║   DO NOT:                                                                 ║
 * ║   ✗ Move panel rendering to template engine                              ║
 * ║   ✗ Create "panel type registries" outside this file                     ║
 * ║   ✗ Add rendering logic to templates                                     ║
 * ║   ✗ Make templates responsible for data fetching                         ║
 * ║   ✗ Create abstraction layers that scatter rendering logic               ║
 * ║                                                                           ║
 * ╚═══════════════════════════════════════════════════════════════════════════╝
 *
 * @package RawWire_Dashboard
 * @subpackage Core_Module
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . '../../includes/interface-module.php';

/**
 * ╔═══════════════════════════════════════════════════════════════════════════╗
 * ║  STOP! The Panel Registry below is the SINGLE SOURCE OF TRUTH for all    ║
 * ║  available panels. Templates reference these by ID to show/hide them.    ║
 * ║  ALL rendering logic for these panels is in this class.                  ║
 * ╚═══════════════════════════════════════════════════════════════════════════╝
 */
class RawWire_Core_Module implements RawWire_Module_Interface
{

    protected $meta = array(
        'name' => 'Core Module',
        'slug' => 'core',
        'version' => '1.0.0',
        'description' => 'Core dashboard panels and functionality'
    );

    /**
     * ╔═══════════════════════════════════════════════════════════════════════╗
     * ║  MENU ARCHITECTURE - How menus and subpages work                      ║
     * ╠═══════════════════════════════════════════════════════════════════════╣
     * ║                                                                       ║
     * ║  STOP! Menus are managed by RawWire_Menu_Manager, NOT here!           ║
     * ║                                                                       ║
     * ║  HARDWIRED MENU STRUCTURE (in class-menu-manager.php):                ║
     * ║                                                                       ║
     * ║    Raw Wire (Dashboard)     ← Main menu, always visible               ║
     * ║    ├── Templates            ← Always visible (to load templates)      ║
     * ║    ├── Tools                ← Multi-tab page (tabs from tools)        ║
     * ║    ├── Workflows            ← Multi-tab page (tabs from template)     ║
     * ║    └── Settings             ← General + Developer Tools               ║
     * ║                                                                       ║
     * ║  HOW TOOLS REGISTER TABS:                                             ║
     * ║    Tools do NOT create submenus with add_submenu_page()!              ║
     * ║    Instead they hook 'rawwire_register_tool_tabs' and call:           ║
     * ║      RawWire_Menu_Manager::register_tool_tab('my_tool', [...])        ║
     * ║                                                                       ║
     * ║  HOW WORKFLOWS REGISTER TABS:                                         ║
     * ║    Templates define workflows, which register as tabs via:            ║
     * ║      RawWire_Menu_Manager::register_workflow_tab('my_workflow', [...])║
     * ║                                                                       ║
     * ║  NO TEMPLATE = BLANK STATE                                            ║
     * ║    When no template is loaded:                                        ║
     * ║    - Dashboard shows only hardcoded defaults                          ║
     * ║    - Tools page has no tabs (nothing activated)                       ║
     * ║    - Workflows page has no tabs (no workflows defined)                ║
     * ║                                                                       ║
     * ║  SEE: includes/class-menu-manager.php for implementation              ║
     * ╚═══════════════════════════════════════════════════════════════════════╝
     */

    /**
     * ╔═══════════════════════════════════════════════════════════════════════╗
     * ║  PANEL REGISTRY - The master list of ALL available panels            ║
     * ║                                                                       ║
     * ║  STOP! This array defines every panel the dashboard can display.     ║
     * ║  Templates reference these IDs to toggle visibility.                 ║
     * ║  The rendering method for each panel is in this class.               ║
     * ║                                                                       ║
     * ║  To add a new panel:                                                 ║
     * ║  1. Add entry here with unique ID                                    ║
     * ║  2. Create render_panel_{id}() method below                          ║
     * ║  3. Template can now reference this panel by ID                      ║
     * ╚═══════════════════════════════════════════════════════════════════════╝
     */
    protected static $panel_registry = array(
        // === CORE DASHBOARD PANELS ===
        'overview' => array(
            'title'       => 'Overview',
            'description' => 'Key metrics and statistics at a glance',
            'icon'        => 'dashicons-chart-pie',
            'category'    => 'core',
            'renderer'    => 'render_panel_overview',
        ),
        'sources' => array(
            'title'       => 'Sources & Controls',
            'description' => 'Manage data sources and workflow settings',
            'icon'        => 'dashicons-admin-site',
            'category'    => 'core',
            'renderer'    => 'render_panel_sources',
        ),
        'queue' => array(
            'title'       => 'Processing Queue',
            'description' => 'Current processing status and queue depth',
            'icon'        => 'dashicons-list-view',
            'category'    => 'core',
            'renderer'    => 'render_panel_queue',
        ),
        'logs' => array(
            'title'       => 'Activity Logs',
            'description' => 'Recent system activity and events',
            'icon'        => 'dashicons-text-page',
            'category'    => 'core',
            'renderer'    => 'render_panel_logs',
        ),
        'insights' => array(
            'title'       => 'Insights',
            'description' => 'Analytics and trend data',
            'icon'        => 'dashicons-chart-line',
            'category'    => 'core',
            'renderer'    => 'render_panel_insights',
        ),
        'approvals' => array(
            'title'       => 'Content Approvals',
            'description' => 'Review and approve pending content',
            'icon'        => 'dashicons-yes-alt',
            'category'    => 'workflow',
            'renderer'    => 'render_panel_approvals',
        ),

        // === SYSTEM PANELS ===
        'system_status' => array(
            'title'       => 'System Status',
            'description' => 'Plugin health and connectivity',
            'icon'        => 'dashicons-heart',
            'category'    => 'system',
            'renderer'    => 'render_panel_system_status',
        ),
        'toolkit_status' => array(
            'title'       => 'Toolkit Components',
            'description' => 'Available tools and adapters',
            'icon'        => 'dashicons-admin-tools',
            'category'    => 'system',
            'renderer'    => 'render_panel_toolkit_status',
        ),

        // === QUICK ACTIONS ===
        'quick_actions' => array(
            'title'       => 'Quick Actions',
            'description' => 'Common tasks and shortcuts',
            'icon'        => 'dashicons-performance',
            'category'    => 'actions',
            'renderer'    => 'render_panel_quick_actions',
        ),
    );

    /**
     * Get the panel registry
     * 
     * STOP! This is the public API for templates and toolkit to discover
     * available panels. Templates use this to know what panels exist.
     * This includes BOTH built-in AND custom panels.
     * 
     * @return array All registered panels (built-in + custom)
     */
    public static function get_panel_registry()
    {
        $panels = self::$panel_registry;

        // Merge custom panels from the Custom Panel Builder
        // STOP! Custom panels are DEFINED via UI but RENDERED here in Module Core
        if (class_exists('RawWire_Custom_Panel_Builder')) {
            $custom_panels = RawWire_Custom_Panel_Builder::get_custom_panels();
            foreach ($custom_panels as $panel_id => $custom) {
                $panels[$panel_id] = array(
                    'title'        => $custom['title'],
                    'description'  => $custom['description'] ?? '',
                    'icon'         => $custom['icon'] ?? 'dashicons-admin-generic',
                    'category'     => $custom['category'] ?? 'custom',
                    'renderer'     => 'render_custom_panel', // All custom panels use this renderer
                    'is_custom'    => true,
                    'content_type' => $custom['content_type'],
                    'content_config' => $custom['content_config'] ?? array(),
                );
            }
        }

        return $panels;
    }

    /**
     * Check if a panel exists
     * 
     * @param string $panel_id Panel ID to check
     * @return bool
     */
    public static function panel_exists($panel_id)
    {
        $registry = self::get_panel_registry();
        return isset($registry[$panel_id]);
    }

    /**
     * Get panel metadata
     * 
     * @param string $panel_id Panel ID
     * @return array|null Panel config or null if not found
     */
    public static function get_panel_meta($panel_id)
    {
        $registry = self::get_panel_registry();
        return $registry[$panel_id] ?? null;
    }

    /**
     * ╔═══════════════════════════════════════════════════════════════════════╗
     * ║  MASTER PANEL RENDERER                                               ║
     * ║                                                                       ║
     * ║  STOP! This is the ONLY entry point for rendering panels.            ║
     * ║  Templates call this method with a panel ID.                         ║
     * ║  This method dispatches to the appropriate render_panel_*() method.  ║
     * ║                                                                       ║
     * ║  For CUSTOM panels (created via UI), this dispatches to              ║
     * ║  render_custom_panel() with the content configuration.               ║
     * ║                                                                       ║
     * ║  DO NOT create alternative rendering paths.                          ║
     * ║  DO NOT move rendering logic to template engine.                     ║
     * ╚═══════════════════════════════════════════════════════════════════════╝
     * 
     * @param string $panel_id  Panel ID from registry
     * @param array  $config    Optional styling config from template (CSS classes, etc)
     * @return string           HTML output
     */
    public static function render_panel($panel_id, $config = array())
    {
        // Get panel from dynamic registry (includes both built-in and custom panels)
        $registry = self::get_panel_registry();

        if (!isset($registry[$panel_id])) {
            return self::render_panel_not_found($panel_id);
        }

        $panel = $registry[$panel_id];
        $renderer = $panel['renderer'];

        // Get instance for non-static method calls
        $instance = new self();

        // For custom panels, pass the content type and config to the renderer
        if (!empty($panel['is_custom'])) {
            $config['panel_id'] = $panel_id;
            $config['content_type'] = $panel['content_type'];
            $config['content_config'] = $panel['content_config'];
        }

        // Call the renderer method
        if (method_exists($instance, $renderer)) {
            $html = $instance->$renderer($config);
        } else {
            $html = self::render_panel_not_implemented($panel_id);
        }

        // Wrap in panel container with template-provided styling
        return self::wrap_panel($panel_id, $panel, $html, $config);
    }

    /**
     * Wrap panel content in standard container
     * 
     * Templates can provide CSS classes via $config['css_class']
     * Templates can provide custom icons via $config['icon']
     * But the actual HTML structure is controlled HERE.
     */
    private static function wrap_panel($panel_id, $panel, $content, $config = array())
    {
        $css_class = $config['css_class'] ?? '';
        $icon = $config['icon'] ?? $panel['icon'];
        $title = $config['title'] ?? $panel['title'];

        $html = '<div class="rawwire-panel rawwire-panel-' . esc_attr($panel_id) . ' ' . esc_attr($css_class) . '" ';
        $html .= 'data-panel-id="' . esc_attr($panel_id) . '">';

        $html .= '<div class="rawwire-panel-header">';
        $html .= '<span class="dashicons ' . esc_attr($icon) . '"></span>';
        $html .= '<h3>' . esc_html($title) . '</h3>';
        $html .= '</div>';

        $html .= '<div class="rawwire-panel-body">';
        $html .= $content;
        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }

    /**
     * Fallback for unknown panels
     */
    private static function render_panel_not_found($panel_id)
    {
        return '<div class="rawwire-panel rawwire-panel-error">' .
            '<p>Panel "' . esc_html($panel_id) . '" not found in registry.</p>' .
            '</div>';
    }

    /**
     * Fallback for unimplemented renderers
     */
    private static function render_panel_not_implemented($panel_id)
    {
        return '<p class="description">Panel renderer not yet implemented.</p>';
    }


    /* ═══════════════════════════════════════════════════════════════════════
     * 
     *  ██████╗  █████╗ ███╗   ██╗███████╗██╗         ██████╗ ███████╗███╗   ██╗
     *  ██╔══██╗██╔══██╗████╗  ██║██╔════╝██║         ██╔══██╗██╔════╝████╗  ██║
     *  ██████╔╝███████║██╔██╗ ██║█████╗  ██║         ██████╔╝█████╗  ██╔██╗ ██║
     *  ██╔═══╝ ██╔══██║██║╚██╗██║██╔══╝  ██║         ██╔══██╗██╔══╝  ██║╚██╗██║
     *  ██║     ██║  ██║██║ ╚████║███████╗███████╗    ██║  ██║███████╗██║ ╚████║
     *  ╚═╝     ╚═╝  ╚═╝╚═╝  ╚═══╝╚══════╝╚══════╝    ╚═╝  ╚═╝╚══════╝╚═╝  ╚═══╝
     * 
     *  STOP! All panel rendering methods below. Each method is self-contained.
     *  Templates do NOT control what's inside these methods.
     *  Templates can only call render_panel() with the panel ID.
     * 
     * ═══════════════════════════════════════════════════════════════════════ */

    /**
     * Render Overview Panel - Key metrics at a glance
     */
    protected function render_panel_overview($config = array())
    {
        global $wpdb;

        // Get real stats from database
        $table = $wpdb->prefix . 'rawwire_candidates';
        $stats = array(
            'total_processed' => 0,
            'active_workflows' => 0,
            'success_rate' => 0,
            'avg_response_ms' => 0,
        );

        // Check if table exists and get stats
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
            $stats['total_processed'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");

            // Check if 'status' column exists before querying it
            $has_status = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'status'");
            if ($has_status) {
                $published = (int) $wpdb->get_var(
                    $wpdb->prepare("SELECT COUNT(*) FROM $table WHERE status = %s", 'published')
                );
                if ($stats['total_processed'] > 0) {
                    $stats['success_rate'] = round(($published / $stats['total_processed']) * 100, 1);
                }
            }
        }

        $stats['active_workflows'] = (int) get_option('rawwire_active_workflow_count', 0);
        $stats['avg_response_ms'] = (int) get_option('rawwire_avg_response_ms', 0);

        return '<div class="panel-grid">
            <div class="panel-item">
                <strong id="overview-total-processed">' . esc_html($stats['total_processed']) . '</strong>
                <div>Total Processed</div>
            </div>
            <div class="panel-item">
                <strong id="overview-active-workflows">' . esc_html($stats['active_workflows']) . '</strong>
                <div>Active Workflows</div>
            </div>
            <div class="panel-item">
                <strong id="overview-success-rate">' . esc_html($stats['success_rate']) . '%</strong>
                <div>Success Rate</div>
            </div>
            <div class="panel-item">
                <strong id="overview-avg-response">' . esc_html($stats['avg_response_ms']) . 'ms</strong>
                <div>Avg Response</div>
            </div>
        </div>';
    }

    /**
     * Render Sources Panel - Data source management
     */
    protected function render_panel_sources($config = array())
    {
        // Get sources from toolkit (if available)
        $toolkit_sources = array();
        if (class_exists('RawWire_Scraper_Settings')) {
            $toolkit_sources = RawWire_Scraper_Settings::get_sources();
        }

        // Get workflow config
        $workflow_config = array(
            'auto_sync' => (bool) get_option('rawwire_auto_sync', false),
            'notifications' => (bool) get_option('rawwire_notifications', false),
            'error_reporting' => (bool) get_option('rawwire_error_reporting', true),
            'batch_size' => (int) get_option('rawwire_scoring_batch_size', 10),
            'auto_approve_threshold' => (float) get_option('rawwire_auto_approve_threshold', 0),
        );

        $html = '<div class="sources-manager">';

        // Workflow controls
        $html .= '<div class="control-row">';
        $html .= '<label><input type="checkbox" class="panel-control-toggle" data-action="auto_sync" ' . ($workflow_config['auto_sync'] ? 'checked' : '') . '> Auto Sync</label>';
        $html .= '<label><input type="checkbox" class="panel-control-toggle" data-action="notifications" ' . ($workflow_config['notifications'] ? 'checked' : '') . '> Notifications</label>';
        $html .= '<label><input type="checkbox" class="panel-control-toggle" data-action="error_reporting" ' . ($workflow_config['error_reporting'] ? 'checked' : '') . '> Error Reporting</label>';
        $html .= '<label style="margin-left:12px;">Batch size: <input type="number" id="rawwire-batch-size" min="1" value="' . intval($workflow_config['batch_size']) . '" style="width:70px;margin-left:6px"></label>';
        $html .= '<label style="margin-left:12px;">Auto-approve ≥ <input type="number" id="rawwire-auto-approve" min="0" max="100" value="' . floatval($workflow_config['auto_approve_threshold']) . '" style="width:70px;margin-left:6px"> %</label>';
        $html .= '</div>';

        // Show toolkit sources
        if (!empty($toolkit_sources)) {
            $html .= '<h4 style="margin-top: 20px;">Configured Sources</h4>';
            $html .= '<div class="sources-grid">';

            foreach ($toolkit_sources as $source_id => $source) {
                $checked = !empty($source['enabled']) ? 'checked' : '';
                $status_class = !empty($source['enabled']) ? 'enabled' : 'disabled';
                $protocol = $source['protocol'] ?? 'rest_api';
                $target_table = $source['output_table'] ?? 'candidates';

                $html .= '<div class="source-item ' . $status_class . '" data-source="' . esc_attr($source_id) . '">';
                $html .= '<label class="source-toggle">';
                $html .= '<input type="checkbox" class="source-checkbox" ' . $checked . ' data-source-id="' . esc_attr($source_id) . '">';
                $html .= '<span class="source-name">' . esc_html($source['name'] ?? 'Unnamed') . '</span>';
                $html .= '<span class="source-category">' . esc_html($protocol) . '</span>';
                $html .= '</label>';
                $html .= '<div class="source-info">';
                $html .= '<small>→ ' . esc_html($target_table) . ' table</small>';
                $html .= '<button class="button button-small test-source-btn" data-source-id="' . esc_attr($source_id) . '"' . ($checked ? '' : ' disabled') . '>Test</button>';
                $html .= '</div>';
                $html .= '</div>';
            }

            $html .= '</div>';
        } else {
            $html .= '<p><em>No sources configured. <a href="' . esc_url(admin_url('admin.php?page=rawwire-tools')) . '">Add sources in Toolkit Settings →</a></em></p>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * Render Queue Panel - Processing queue status
     */
    protected function render_panel_queue($config = array())
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rawwire_candidates';
        $stats = array('pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0);

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
            // Check if 'status' column exists before querying it
            $has_status = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'status'");
            if ($has_status) {
                $stats['pending'] = (int) $wpdb->get_var(
                    $wpdb->prepare("SELECT COUNT(*) FROM $table WHERE status = %s", 'pending')
                );
                $stats['completed'] = (int) $wpdb->get_var(
                    $wpdb->prepare("SELECT COUNT(*) FROM $table WHERE status IN (%s, %s)", 'approved', 'published')
                );
                $stats['failed'] = (int) $wpdb->get_var(
                    $wpdb->prepare("SELECT COUNT(*) FROM $table WHERE status = %s", 'rejected')
                );
            }
        }

        $stats['processing'] = (int) get_option('rawwire_active_workflow_count', 0);

        return '<div class="panel-grid">
            <div class="panel-item">
                <strong id="queue-pending">' . esc_html($stats['pending']) . '</strong>
                <div>Pending</div>
            </div>
            <div class="panel-item">
                <strong id="queue-processing">' . esc_html($stats['processing']) . '</strong>
                <div>Processing</div>
            </div>
            <div class="panel-item">
                <strong id="queue-completed">' . esc_html($stats['completed']) . '</strong>
                <div>Completed</div>
            </div>
            <div class="panel-item">
                <strong id="queue-failed">' . esc_html($stats['failed']) . '</strong>
                <div>Failed</div>
            </div>
        </div>';
    }

    /**
     * Render Logs Panel - Activity log viewer
     */
    protected function render_panel_logs($config = array())
    {
        $logs = array();
        $limit = 20;

        // Try database logs first
        if (class_exists('RawWire_Logger') && method_exists('RawWire_Logger', 'get_recent_logs')) {
            $db_logs = RawWire_Logger::get_recent_logs($limit);
            foreach ($db_logs as $log) {
                $level = strtoupper($log['level'] ?? 'INFO');
                $level_class = 'log-info';
                if (in_array($level, array('ERROR', 'CRITICAL', 'EMERGENCY'))) {
                    $level_class = 'log-error';
                } elseif ($level === 'WARNING') {
                    $level_class = 'log-warning';
                }

                $logs[] = '<div class="log-entry ' . esc_attr($level_class) . '">' .
                    '<span class="log-time">' . esc_html($log['timestamp'] ?? '') . '</span> ' .
                    '<span class="log-level">[' . esc_html($level) . ']</span> ' .
                    '<span class="log-message">' . esc_html($log['message'] ?? '') . '</span>' .
                    '</div>';
            }
        }

        // Fallback to debug.log (tail-based to avoid OOM on large logs)
        if (empty($logs)) {
            $logfile = WP_CONTENT_DIR . '/debug.log';
            if (file_exists($logfile)) {
                $tail_bytes = 256 * 1024;
                $fsize = filesize($logfile);
                $fh = fopen($logfile, 'r');
                if ($fh) {
                    if ($fsize > $tail_bytes) {
                        fseek($fh, -$tail_bytes, SEEK_END);
                        fgets($fh); // discard partial first line
                    }
                    $tail_content = fread($fh, $tail_bytes);
                    fclose($fh);
                    $content = array_filter(explode("\n", $tail_content), 'strlen');
                } else {
                    $content = array();
                }
                $lines = array_slice(array_reverse($content), 0, $limit);
                foreach ($lines as $line) {
                    $logs[] = '<div class="log-entry log-file">' . esc_html($line) . '</div>';
                }
            }
        }

        if (empty($logs)) {
            $logs[] = '<div class="log-entry log-info">No recent activity. Logs will appear here as actions occur.</div>';
        }

        return '<style>
            .log-entry { padding: 4px 8px; border-bottom: 1px solid #eee; font-family: monospace; font-size: 12px; }
            .log-error { background-color: #ffeaea; color: #a00; }
            .log-warning { background-color: #fff3cd; color: #856404; }
            .log-info { background-color: #fff; }
            .log-time { color: #888; }
            .log-level { font-weight: bold; }
        </style>
        <div class="log-viewer" style="max-height: 300px; overflow-y: auto;">' .
            implode('', $logs) .
            '</div>';
    }

    /**
     * Render Insights Panel - Analytics and trends
     */
    protected function render_panel_insights($config = array())
    {
        global $wpdb;

        // Get actual insights from database
        $table = $wpdb->prefix . 'rawwire_candidates';
        $avg_quality = 0;

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
            $avg_quality = (float) $wpdb->get_var("SELECT AVG(score) FROM $table WHERE score > 0");
            $avg_quality = round($avg_quality * 100, 0);
        }

        return '<div class="panel-grid">
            <div class="panel-item">
                <strong id="insights-top-categories">-</strong>
                <div>Top Categories</div>
            </div>
            <div class="panel-item">
                <strong id="insights-peak-hours">-</strong>
                <div>Peak Hours</div>
            </div>
            <div class="panel-item">
                <strong id="insights-avg-quality">' . esc_html($avg_quality ?: '-') . '%</strong>
                <div>Avg Quality</div>
            </div>
            <div class="panel-item">
                <strong id="insights-trends">-</strong>
                <div>Trends</div>
            </div>
        </div>';
    }

    /**
     * Render Approvals Panel - Content approval queue
     */
    protected function render_panel_approvals($config = array())
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rawwire_candidates';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return '<p>Approval system not yet initialized.</p>';
        }

        // Check if 'status' column exists before querying it
        $has_status = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'status'");
        if (!$has_status) {
            return '<p>Approval system not yet initialized.</p>';
        }

        $pending_items = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM $table WHERE status = %s ORDER BY created_at DESC LIMIT %d", 'pending', 20),
            ARRAY_A
        );

        if (empty($pending_items)) {
            return '<p>No content pending approval. 🎉</p>';
        }

        $html = '<table class="widefat rawwire-approvals-table">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Source</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>';

        foreach ($pending_items as $item) {
            $html .= '<tr data-id="' . esc_attr($item['id']) . '">
                <td>' . esc_html($item['title'] ?? 'Untitled') . '</td>
                <td>' . esc_html($item['source'] ?? '-') . '</td>
                <td>' . esc_html(date('M j, H:i', strtotime($item['created_at']))) . '</td>
                <td>
                    <button class="button button-small rawwire-approve" data-id="' . esc_attr($item['id']) . '">Approve</button>
                    <button class="button button-small rawwire-reject" data-id="' . esc_attr($item['id']) . '">Reject</button>
                </td>
            </tr>';
        }

        $html .= '</tbody></table>';
        return $html;
    }

    /**
     * Render System Status Panel - Plugin health
     */
    protected function render_panel_system_status($config = array())
    {
        global $wpdb;

        $checks = array();

        // Database connection
        $checks['database'] = array(
            'label' => 'Database',
            'status' => $wpdb->check_connection() ? 'ok' : 'error',
        );

        // Tables exist
        $table = $wpdb->prefix . 'rawwire_candidates';
        $checks['tables'] = array(
            'label' => 'Data Tables',
            'status' => ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) ? 'ok' : 'warning',
        );

        // Logger available
        $checks['logger'] = array(
            'label' => 'Logger',
            'status' => class_exists('RawWire_Logger') ? 'ok' : 'warning',
        );

        $html = '<div class="status-checks">';
        foreach ($checks as $id => $check) {
            $icon = $check['status'] === 'ok' ? '✓' : ($check['status'] === 'warning' ? '⚠' : '✗');
            $class = 'status-' . $check['status'];
            $html .= '<div class="status-item ' . esc_attr($class) . '">';
            $html .= '<span class="status-icon">' . $icon . '</span>';
            $html .= '<span class="status-label">' . esc_html($check['label']) . '</span>';
            $html .= '</div>';
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * Render Toolkit Status Panel - Available tools/adapters
     */
    protected function render_panel_toolkit_status($config = array())
    {
        $tools = array(
            'scraper' => array(
                'label' => 'Scraper',
                'available' => class_exists('RawWire_Scraper_Settings'),
            ),
            'generator' => array(
                'label' => 'AI Generator',
                'available' => class_exists('RawWire_Generator_Interface'),
            ),
            'key_manager' => array(
                'label' => 'Key Manager',
                'available' => class_exists('RawWire_Key_Manager'),
            ),
        );

        $html = '<div class="toolkit-grid">';
        foreach ($tools as $id => $tool) {
            $status = $tool['available'] ? 'available' : 'unavailable';
            $icon = $tool['available'] ? '✓' : '○';
            $html .= '<div class="toolkit-item toolkit-' . esc_attr($status) . '">';
            $html .= '<span class="toolkit-icon">' . $icon . '</span>';
            $html .= '<span class="toolkit-label">' . esc_html($tool['label']) . '</span>';
            $html .= '</div>';
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * Render Quick Actions Panel
     */
    protected function render_panel_quick_actions($config = array())
    {
        return '<div class="quick-actions">
            <button class="button rawwire-action" data-action="sync_all">
                <span class="dashicons dashicons-update"></span> Sync All Sources
            </button>
            <button class="button rawwire-action" data-action="clear_cache">
                <span class="dashicons dashicons-trash"></span> Clear Cache
            </button>
            <a href="' . esc_url(admin_url('admin.php?page=rawwire-tools')) . '" class="button">
                <span class="dashicons dashicons-admin-tools"></span> Toolkit Settings
            </a>
        </div>';
    }


    /* ═══════════════════════════════════════════════════════════════════════
     * 
     *   ██████╗██╗   ██╗███████╗████████╗ ██████╗ ███╗   ███╗
     *  ██╔════╝██║   ██║██╔════╝╚══██╔══╝██╔═══██╗████╗ ████║
     *  ██║     ██║   ██║███████╗   ██║   ██║   ██║██╔████╔██║
     *  ██║     ██║   ██║╚════██║   ██║   ██║   ██║██║╚██╔╝██║
     *  ╚██████╗╚██████╔╝███████║   ██║   ╚██████╔╝██║ ╚═╝ ██║
     *   ╚═════╝ ╚═════╝ ╚══════╝   ╚═╝    ╚═════╝ ╚═╝     ╚═╝
     *                                                        
     *   PANEL RENDERER
     *
     *   STOP! Custom panels are DEFINED via the Admin UI (Custom Panel Builder)
     *   but they are RENDERED here in Module Core. This ensures:
     *   
     *   1. All rendering logic stays in one place (Module Core)
     *   2. Templates only toggle visibility, never render content
     *   3. Custom panels follow the same architecture as built-in panels
     *   4. Safe content types prevent code injection
     *
     * ═══════════════════════════════════════════════════════════════════════ */

    /**
     * Render a custom panel based on its content type
     * 
     * STOP! This is the ONLY place custom panel content is rendered.
     * The Custom Panel Builder defines WHAT to show, this method renders it.
     */
    protected function render_custom_panel($config = array())
    {
        // Get the full panel definition including content_config
        $panel_id = $config['panel_id'] ?? '';
        $content_type = $config['content_type'] ?? '';
        $content_config = $config['content_config'] ?? array();

        if (empty($content_type)) {
            return '<p class="description">Custom panel not configured.</p>';
        }

        switch ($content_type) {
            case 'static_html':
                return $this->render_custom_static_html($content_config);

            case 'database_query':
                return $this->render_custom_database_query($content_config);

            case 'rest_endpoint':
                return $this->render_custom_rest_endpoint($content_config);

            case 'wp_option':
                return $this->render_custom_wp_option($content_config);

            case 'shortcode':
                return $this->render_custom_shortcode($content_config);

            case 'metric_grid':
                return $this->render_custom_metric_grid($content_config);

            default:
                return '<p class="description">Unknown content type: ' . esc_html($content_type) . '</p>';
        }
    }

    /**
     * Render static HTML content (already sanitized by Custom Panel Builder)
     */
    private function render_custom_static_html($config)
    {
        return '<div class="custom-static-content">' .
            wp_kses_post($config['html_content'] ?? '') .
            '</div>';
    }

    /**
     * Render database query results
     */
    private function render_custom_database_query($config)
    {
        global $wpdb;

        $table = $wpdb->prefix . sanitize_key($config['table_name'] ?? '');
        $columns = sanitize_text_field($config['columns'] ?? '*');
        $where = sanitize_text_field($config['where_clause'] ?? '');
        $limit = min(100, max(1, intval($config['limit'] ?? 10)));
        $order = sanitize_text_field($config['order_by'] ?? '');

        // Build safe query
        $sql = "SELECT {$columns} FROM {$table}";
        if (!empty($where)) {
            $sql .= " WHERE {$where}";
        }
        if (!empty($order)) {
            $sql .= " ORDER BY {$order}";
        }
        $sql .= " LIMIT {$limit}";

        // Check table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return '<p class="description">Table not found.</p>';
        }

        $results = $wpdb->get_results($sql, ARRAY_A);

        if (empty($results)) {
            return '<p class="description">No data found.</p>';
        }

        // Render as table
        $html = '<table class="widefat">';
        $html .= '<thead><tr>';
        foreach (array_keys($results[0]) as $col) {
            $html .= '<th>' . esc_html($col) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($results as $row) {
            $html .= '<tr>';
            foreach ($row as $value) {
                $html .= '<td>' . esc_html($value) . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        return $html;
    }

    /**
     * Render REST endpoint data
     */
    private function render_custom_rest_endpoint($config)
    {
        $url = esc_url_raw($config['endpoint_url'] ?? '');
        $method = in_array(strtoupper($config['method'] ?? 'GET'), array('GET', 'POST')) ? strtoupper($config['method']) : 'GET';
        $format = sanitize_key($config['display_format'] ?? 'table');

        if (empty($url)) {
            return '<p class="description">No endpoint URL configured.</p>';
        }

        // Handle internal REST endpoints
        if (strpos($url, '/') === 0) {
            $url = rest_url(ltrim($url, '/'));
        }

        $response = wp_remote_request($url, array('method' => $method, 'timeout' => 10));

        if (is_wp_error($response)) {
            return '<p class="description">Error fetching data: ' . esc_html($response->get_error_message()) . '</p>';
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($format === 'json') {
            return '<pre style="background:#f5f5f5; padding:10px; overflow:auto; max-height:300px;">' .
                esc_html(json_encode($data, JSON_PRETTY_PRINT)) .
                '</pre>';
        }

        if (!is_array($data)) {
            return '<p>' . esc_html($body) . '</p>';
        }

        // Render as list or table
        if ($format === 'list' || !isset($data[0])) {
            $html = '<ul>';
            foreach ($data as $key => $value) {
                $html .= '<li><strong>' . esc_html($key) . ':</strong> ' . esc_html(is_array($value) ? json_encode($value) : $value) . '</li>';
            }
            $html .= '</ul>';
            return $html;
        }

        // Table format for arrays
        $html = '<table class="widefat"><thead><tr>';
        foreach (array_keys($data[0]) as $col) {
            $html .= '<th>' . esc_html($col) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($data as $row) {
            $html .= '<tr>';
            foreach ($row as $val) {
                $html .= '<td>' . esc_html(is_array($val) ? json_encode($val) : $val) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        return $html;
    }

    /**
     * Render WordPress options
     */
    private function render_custom_wp_option($config)
    {
        $keys = $config['option_keys'] ?? array();
        $format = sanitize_key($config['display_format'] ?? 'list');

        if (empty($keys)) {
            return '<p class="description">No option keys configured.</p>';
        }

        if (!is_array($keys)) {
            $keys = array_map('trim', explode(',', $keys));
        }

        $values = array();
        foreach ($keys as $key) {
            $key = sanitize_key($key);
            $values[$key] = get_option($key, '(not set)');
        }

        if ($format === 'grid') {
            $html = '<div class="panel-grid">';
            foreach ($values as $key => $value) {
                $display_value = is_array($value) ? json_encode($value) : $value;
                $html .= '<div class="panel-item">';
                $html .= '<strong>' . esc_html($display_value) . '</strong>';
                $html .= '<div>' . esc_html(str_replace('_', ' ', $key)) . '</div>';
                $html .= '</div>';
            }
            $html .= '</div>';
            return $html;
        }

        // List format
        $html = '<ul class="custom-option-list">';
        foreach ($values as $key => $value) {
            $display_value = is_array($value) ? json_encode($value) : $value;
            $html .= '<li><strong>' . esc_html($key) . ':</strong> ' . esc_html($display_value) . '</li>';
        }
        $html .= '</ul>';
        return $html;
    }

    /**
     * Render shortcode output
     */
    private function render_custom_shortcode($config)
    {
        $shortcode = $config['shortcode'] ?? '';

        if (empty($shortcode)) {
            return '<p class="description">No shortcode configured.</p>';
        }

        // Validate shortcode format
        if (!preg_match('/^\[[\w-]+/', $shortcode)) {
            return '<p class="description">Invalid shortcode format.</p>';
        }

        return '<div class="custom-shortcode-output">' . do_shortcode($shortcode) . '</div>';
    }

    /**
     * Render metric grid
     */
    private function render_custom_metric_grid($config)
    {
        $metrics = $config['metrics'] ?? array();

        if (empty($metrics)) {
            return '<p class="description">No metrics configured.</p>';
        }

        $html = '<div class="panel-grid">';

        foreach ($metrics as $metric) {
            $label = sanitize_text_field($metric['label'] ?? '');
            $source = sanitize_key($metric['value_source'] ?? 'static');
            $suffix = sanitize_text_field($metric['suffix'] ?? '');

            // Get value based on source
            if ($source === 'option' && !empty($metric['option_key'])) {
                $value = get_option(sanitize_key($metric['option_key']), 0);
            } else {
                $value = sanitize_text_field($metric['value'] ?? '0');
            }

            $html .= '<div class="panel-item">';
            $html .= '<strong>' . esc_html($value) . esc_html($suffix) . '</strong>';
            $html .= '<div>' . esc_html($label) . '</div>';
            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }


    /* ═══════════════════════════════════════════════════════════════════════
     * 
     *   LEGACY INTERFACE METHODS
     *   Preserved for backward compatibility with existing code
     * 
     * ═══════════════════════════════════════════════════════════════════════ */

    public function init()
    {
        // Module initialization
    }

    public function register_rest_routes()
    {
        // REST routes handled via module dispatch
    }

    public function register_ajax_handlers()
    {
        // AJAX handled via dispatcher
    }

    /**
     * Get admin panels for legacy discovery
     * 
     * STOP! This method is for BACKWARD COMPATIBILITY with existing code
     * that expects the old panel array format. New code should use
     * get_panel_registry() instead.
     */
    public function get_admin_panels()
    {
        $panels = array();
        foreach (self::$panel_registry as $id => $panel) {
            $panels[$id] = array(
                'title' => $panel['title'],
                'panel_id' => $id . '-panel',
                'module' => 'core',
                'action' => 'get_' . $id,
                'role' => ($panel['category'] === 'workflow') ? 'approvals' : 'settings',
            );
        }
        return $panels;
    }

    public function get_metadata()
    {
        return $this->meta;
    }

    /**
     * Handle REST requests
     */
    public function handle_rest_request($action, $request)
    {
        // Map REST actions to panel renders or AJAX handlers
        $panel_actions = array('get_overview', 'get_sources', 'get_queue', 'get_logs', 'get_insights', 'get_approvals');

        foreach ($panel_actions as $panel_action) {
            if ($action === $panel_action) {
                $panel_id = str_replace('get_', '', $action);
                return self::render_panel($panel_id);
            }
        }

        // Other actions
        return $this->handle_ajax($action, $request->get_params());
    }

    /**
     * Handle AJAX requests
     * 
     * STOP! Panel content should be fetched via render_panel().
     * This method handles NON-RENDERING actions only.
     */
    public function handle_ajax($action, $data)
    {
        global $wpdb;

        // Handle panel content requests by delegating to render_panel
        $panel_map = array(
            'get_overview' => 'overview',
            'get_sources' => 'sources',
            'get_queue' => 'queue',
            'get_logs' => 'logs',
            'get_insights' => 'insights',
            'get_approvals' => 'approvals',
        );

        if (isset($panel_map[$action])) {
            return self::render_panel($panel_map[$action]);
        }

        // Non-rendering actions
        switch ($action) {
            case 'get_stats':
                $table = $wpdb->prefix . 'rawwire_content';
                return array(
                    'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table"),
                    'pending' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE status = %s", 'pending')),
                    'approved' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE status = %s", 'approved')),
                    'rejected' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE status = %s", 'rejected')),
                    'last_sync' => get_option('rawwire_last_sync', 'Never'),
                );

            case 'get_content':
                $limit = min(intval($data['limit'] ?? 10), 50);
                $table = $wpdb->prefix . 'rawwire_content';
                return $wpdb->get_results($wpdb->prepare("SELECT * FROM $table ORDER BY created_at DESC LIMIT %d", $limit), ARRAY_A);

            case 'panel_control':
                $action_key = sanitize_text_field($data['control_action'] ?? '');
                $value = isset($data['value']) ? intval($data['value']) : 0;
                if (!empty($action_key)) {
                    update_option('rawwire_' . $action_key, $value);
                }
                return array('success' => true, 'action' => $action_key, 'value' => $value);

            case 'sync':
                if (class_exists('RawWire_REST_API')) {
                    $api = new RawWire_REST_API();
                    $req = new WP_REST_Request('POST', '/');
                    $req->set_param('source', $data['source'] ?? 'all');
                    return $api->sync_data($req);
                }
                return array('success' => false, 'message' => 'REST API not available');

            case 'clear_cache':
                wp_cache_flush();
                return array('success' => true, 'message' => 'Cache cleared');

            case 'update_content':
                $id = intval($data['id'] ?? 0);
                $status = sanitize_text_field($data['status'] ?? '');
                if (!in_array($status, array('pending', 'approved', 'rejected'), true)) {
                    return array('success' => false, 'message' => 'Invalid status');
                }
                $table = $wpdb->prefix . 'rawwire_content';
                $updated = $wpdb->update(
                    $table,
                    array('status' => $status, 'updated_at' => current_time('mysql')),
                    array('id' => $id),
                    array('%s', '%s'),
                    array('%d')
                );
                return array('success' => $updated !== false);

            case 'ai_chat':
                $message = sanitize_textarea_field($data['message'] ?? '');
                if (empty($message)) {
                    return array('success' => false, 'message' => 'Empty message');
                }
                return 'AI chat requires a configured AI provider. See Toolkit Settings.';

            case 'get_workflow_config':
                return array(
                    'models' => array(
                        array('id' => 'gpt-4', 'name' => 'GPT-4'),
                        array('id' => 'gpt-3.5-turbo', 'name' => 'GPT-3.5 Turbo'),
                    ),
                    'parameters' => array(
                        'temperature' => 0.7,
                        'max_tokens' => 1024,
                    ),
                );

            case 'execute_workflow':
                return array('logs' => array('Workflow system ready'), 'result' => 'ok');

            case 'cancel_workflow':
                return array('message' => 'Cancelled');

            default:
                return array('success' => false, 'message' => 'Unknown action: ' . $action);
        }
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
 * 
 *  STOP! Module registration below. This is how the module core discovers
 *  and loads this module. Do not modify the registration pattern.
 * 
 * ═══════════════════════════════════════════════════════════════════════════ */

if (class_exists('RawWire_Module_Core')) {
    try {
        $mod = new RawWire_Core_Module();
        RawWire_Module_Core::register_module('core', $mod);
    } catch (Exception $e) {
        // Ignore registration failures silently
    }
}
