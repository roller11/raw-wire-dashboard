<?php
/**
 * Core module bridge - provides legacy dashboard endpoints via module interface
 */
if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . '../../includes/interface-module.php';

class RawWire_Core_Module implements RawWire_Module_Interface {
    protected $meta = array(
        'name' => 'Core Module',
        'slug' => 'core',
        'version' => '1.0.0',
        'description' => 'Legacy core behaviors exposed as a module'
    );

    public function init() {
        // no-op
    }

    public function register_rest_routes() {
        // REST is handled via module dispatch in core
    }

    public function register_ajax_handlers() {
        // AJAX handled via dispatcher
    }

    public function get_admin_panels() {
        return array(
            'overview' => array('title' => 'Overview', 'panel_id' => 'overview-panel', 'module' => 'core', 'action' => 'get_overview', 'role' => 'settings'),
            'sources'  => array('title' => 'Sources & Controls', 'panel_id' => 'sources-panel', 'module' => 'core', 'action' => 'get_sources', 'role' => 'settings'),
            'queue'    => array('title' => 'Processing Queue', 'panel_id' => 'queue-panel', 'module' => 'core', 'action' => 'get_queue', 'role' => 'settings'),
            'logs'     => array('title' => 'Activity Logs', 'panel_id' => 'logs-panel', 'module' => 'core', 'action' => 'get_logs', 'role' => 'settings'),
            'insights' => array('title' => 'Insights', 'panel_id' => 'insights-panel', 'module' => 'core', 'action' => 'get_insights', 'role' => 'settings'),
            'approvals' => array('title' => 'Content Approvals', 'panel_id' => 'approvals-panel', 'module' => 'core', 'action' => 'get_approvals', 'role' => 'approvals'),
        );
    }

    public function get_metadata() {
        return $this->meta;
    }

    public function handle_rest_request($action, $request) {
        // Basic dispatch to a set of supported actions (REST parity with AJAX handlers)
        switch ($action) {
            case 'stats':
                $api = new RawWire_REST_API();
                return $api->get_stats($request);
            case 'content':
                $api = new RawWire_REST_API();
                return $api->get_content($request);
            case 'get_overview':
            case 'get_sources':
            case 'get_queue':
            case 'get_logs':
            case 'get_insights':
                // Mirror AJAX handlers
                $fake_request = null;
                return $this->handle_ajax($action, array());
            case 'panel':
                // Generic panel request - delegate to ajax handler for now
                return $this->handle_ajax('panel', array('request' => $request->get_params()));
            case 'ai_chat':
            case 'get_workflow_config':
            case 'execute_workflow':
            case 'cancel_workflow':
            case 'panel_control':
                return $this->handle_ajax($action, $request->get_params());
            default:
                return array('success' => false, 'message' => 'Unknown REST action');
        }
    }

    public function handle_ajax($action, $data) {
        global $wpdb;

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
                $limit = intval($data['limit'] ?? 10);
                $limit = min($limit, 50);
                $table = $wpdb->prefix . 'rawwire_content';
                $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table ORDER BY created_at DESC LIMIT %d", $limit), ARRAY_A);
                return $rows;

            case 'get_overview':
                return '<div class="panel-grid">
                    <div class="panel-item">
                        <strong id="overview-total-processed">' . rand(100, 1000) . '</strong>
                        <div>Total Processed</div>
                    </div>
                    <div class="panel-item">
                        <strong id="overview-active-workflows">' . rand(0,5) . '</strong>
                        <div>Active Workflows</div>
                    </div>
                    <div class="panel-item">
                        <strong id="overview-success-rate">' . rand(85,98) . '%</strong>
                        <div>Success Rate</div>
                    </div>
                    <div class="panel-item">
                        <strong id="overview-avg-response">' . rand(50,200) . 'ms</strong>
                        <div>Avg Response</div>
                    </div>
                </div>';

            case 'get_sources':
                // Get active template sources (direct array in template JSON)
                $template = RawWire_Template_Engine::get_active_template();
                
                // Template sources are directly under 'sources' key, not 'sources.items'
                $template_sources = isset($template['sources']) && is_array($template['sources']) ? $template['sources'] : [];
                
                // Filter out panel definitions (sources with 'type' => 'data')
                $template_sources = array_filter($template_sources, function($source) {
                    return isset($source['id']) && isset($source['url']);
                });

                $html = '<div class="sources-manager">';
                
                // Show template sources if any
                if (!empty($template_sources)) {
                    $html .= '<h4>Template Sources (Built-in)</h4>';
                    $html .= '<div class="sources-grid">';

                    foreach ($template_sources as $source) {
                        $checked = !empty($source['enabled']) ? 'checked' : '';
                        $status_class = !empty($source['enabled']) ? 'enabled' : 'disabled';
                        $category = $source['category'] ?? 'general';
                        $source_type = $source['type'] ?? 'unknown';

                        $html .= '<div class="source-item ' . $status_class . '" data-source="' . esc_attr($source['id']) . '">';
                        $html .= '<label class="source-toggle">';
                        $html .= '<input type="checkbox" class="source-checkbox" ' . $checked . ' data-source-id="' . esc_attr($source['id']) . '">';
                        $html .= '<span class="source-name">' . esc_html($source['label'] ?? $source['id']) . '</span>';
                        $html .= '<span class="source-category">' . esc_html($category) . '</span>';
                        $html .= '</label>';
                        $html .= '<div class="source-info">';
                        $html .= '<small>Type: ' . esc_html($source_type) . '</small>';
                        $html .= '</div>';
                        $html .= '</div>';
                    }
                    $html .= '</div>';
                } else {
                    $html .= '<p><em>No template sources configured. Add sources using Scraper Toolkit below.</em></p>';
                }

                // Remove old custom sources section - now handled by Scraper Toolkit
                // Users should go to Settings → Scraper Toolkit to add custom sources

                $html .= '<div class="control-row">';
                $html .= '<label><input type="checkbox" class="panel-control-toggle" data-action="auto_sync" ' . (get_option('rawwire_auto_sync') ? 'checked' : '') . '> Auto Sync</label>';
                $html .= '<label><input type="checkbox" class="panel-control-toggle" data-action="notifications" ' . (get_option('rawwire_notifications') ? 'checked' : '') . '> Notifications</label>';
                $html .= '<label><input type="checkbox" class="panel-control-toggle" data-action="error_reporting" ' . (get_option('rawwire_error_reporting') ? 'checked' : '') . '> Error Reporting</label>';
                $html .= '<label style="margin-left:12px;">Batch size: <input type="number" id="rawwire-batch-size" min="1" value="' . intval(get_option('rawwire_scoring_batch_size', 10)) . '" style="width:70px;margin-left:6px"></label>';
                $html .= '<label style="margin-left:12px;">Auto-approve ≥ <input type="number" id="rawwire-auto-approve" min="0" max="100" value="' . floatval(get_option('rawwire_auto_approve_threshold', 0)) . '" style="width:70px;margin-left:6px"> %</label>';
                $html .= '</div>';

                $html .= '<script>
                jQuery(document).ready(function($) {
                    // Toggle template source enable/disable
                    $(".source-checkbox").on("change", function() {
                        var sourceId = $(this).data("source-id");
                        var enabled = $(this).is(":checked");
                        var $item = $(this).closest(".source-item");

                        $item.toggleClass("enabled", enabled).toggleClass("disabled", !enabled);

                        // Save to template sources
                        $.post(ajaxurl, {
                            action: "rawwire_toggle_template_source",
                            source_id: sourceId,
                            enabled: enabled,
                            nonce: rawwire_ajax.nonce
                        });
                    });

                    // Toggle toolkit source enable/disable
                    $(".toolkit-source-checkbox").on("change", function() {
                        var sourceId = $(this).data("source-id");
                        var enabled = $(this).is(":checked");
                        var $item = $(this).closest(".source-item");

                        $item.toggleClass("enabled", enabled).toggleClass("disabled", !enabled);

                        // Save to toolkit sources
                        $.post(ajaxurl, {
                            action: "rawwire_toggle_scraper_source",
                            source_id: sourceId,
                            enabled: enabled,
                            nonce: rawwire_ajax.nonce
                        });
                    });

                    // Batch size and auto-approve handlers
                    $(document).on("change", "#rawwire-batch-size", function() {
                        var val = $(this).val();
                        $.post(ajaxurl, { action: "rawwire_save_setting", key: "scoring_batch_size", value: val, nonce: rawwire_ajax.nonce });
                    });

                    $(document).on("change", "#rawwire-auto-approve", function() {
                        var val = $(this).val();
                        $.post(ajaxurl, { action: "rawwire_save_setting", key: "auto_approve_threshold", value: val, nonce: rawwire_ajax.nonce });
                    });
                });
                </script>';

                // Add Scraper Toolkit sources section
                $toolkit_sources = class_exists('RawWire_Scraper_Settings') 
                    ? RawWire_Scraper_Settings::get_sources() 
                    : array();
                if (!empty($toolkit_sources)) {
                    $html .= '<h4 style="margin-top: 20px;">Scraper Toolkit Sources</h4>';
                    $html .= '<div class="sources-grid toolkit-sources">';
                    
                    foreach ($toolkit_sources as $source_id => $source) {
                        $checked = !empty($source['enabled']) ? 'checked' : '';
                        $status_class = !empty($source['enabled']) ? 'enabled' : 'disabled';
                        $protocol = $source['protocol'] ?? 'rest_api';
                        $target_table = $source['output_table'] ?? 'candidates';
                        
                        $html .= '<div class="source-item ' . $status_class . '" data-source="' . esc_attr($source_id) . '">';
                        $html .= '<label class="source-toggle">';
                        $html .= '<input type="checkbox" class="toolkit-source-checkbox" ' . $checked . ' data-source-id="' . esc_attr($source_id) . '">';
                        $html .= '<span class="source-name">' . esc_html($source['name'] ?? 'Unnamed') . '</span>';
                        $html .= '<span class="source-category">' . esc_html($protocol) . '</span>';
                        $html .= '</label>';
                        $html .= '<div class="source-info">';
                        $html .= '<small>→ ' . esc_html($target_table) . ' table</small>';
                        $html .= '</div>';
                        $html .= '</div>';
                    }
                    
                    $html .= '</div>';
                    $html .= '<p><small><a href="' . esc_url(admin_url('admin.php?page=rawwire-tools')) . '">Manage toolkit sources →</a></small></p>';
                }

                $html .= '</div>';

                return $html;

            case 'get_queue':
                return '<div class="panel-grid">
                    <div class="panel-item">
                        <strong id="queue-pending">' . rand(0, 10) . '</strong>
                        <div>Pending</div>
                    </div>
                    <div class="panel-item">
                        <strong id="queue-processing">' . rand(0, 3) . '</strong>
                        <div>Processing</div>
                    </div>
                    <div class="panel-item">
                        <strong id="queue-completed">' . rand(50, 200) . '</strong>
                        <div>Completed</div>
                    </div>
                    <div class="panel-item">
                        <strong id="queue-failed">' . rand(0, 5) . '</strong>
                        <div>Failed</div>
                    </div>
                </div>';

            case 'get_logs':
                // Retrieve logs from database via RawWire_Logger
                $logs = array();
                $limit = isset($data['limit']) ? intval($data['limit']) : 20;
                $limit = min($limit, 100);
                
                if (class_exists('RawWire_Logger') && method_exists('RawWire_Logger', 'get_recent_logs')) {
                    $db_logs = RawWire_Logger::get_recent_logs($limit);
                    foreach ($db_logs as $log) {
                        $timestamp = isset($log['timestamp']) ? $log['timestamp'] : '';
                        $level = isset($log['level']) ? strtoupper($log['level']) : 'INFO';
                        $message = isset($log['message']) ? $log['message'] : '';
                        $context = isset($log['context']) && is_array($log['context']) ? $log['context'] : array();
                        $log_type = isset($context['log_type']) ? $context['log_type'] : '';
                        
                        $level_class = 'log-info';
                        if (in_array($level, array('ERROR', 'CRITICAL', 'EMERGENCY'))) {
                            $level_class = 'log-error';
                        } elseif ($level === 'WARNING') {
                            $level_class = 'log-warning';
                        } elseif ($level === 'DEBUG') {
                            $level_class = 'log-debug';
                        }
                        
                        $logs[] = '<div class="log-entry ' . esc_attr($level_class) . '">' .
                            '<span class="log-time">' . esc_html($timestamp) . '</span> ' .
                            '<span class="log-level">[' . esc_html($level) . ']</span> ' .
                            ($log_type ? '<span class="log-type">[' . esc_html($log_type) . ']</span> ' : '') .
                            '<span class="log-message">' . esc_html($message) . '</span>' .
                            '</div>';
                    }
                }
                
                // Fallback: also check debug.log if database logs are empty
                if (empty($logs)) {
                    $logfile = WP_CONTENT_DIR . '/debug.log';
                    if (file_exists($logfile)) {
                        $content = file($logfile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                        $lines = array_slice(array_reverse($content), 0, $limit);
                        foreach ($lines as $line) {
                            $logs[] = '<div class="log-entry log-file">' . esc_html($line) . '</div>';
                        }
                    }
                }
                
                if (empty($logs)) {
                    $logs[] = '<div class="log-entry log-info">No recent activity logs available. Logs will appear here when actions are performed.</div>';
                }
                
                return '<style>
                    .log-entry { padding: 4px 8px; border-bottom: 1px solid #eee; font-family: monospace; font-size: 12px; }
                    .log-error { background-color: #ffeaea; color: #a00; }
                    .log-warning { background-color: #fff3cd; color: #856404; }
                    .log-debug { background-color: #f0f0f0; color: #666; }
                    .log-info { background-color: #fff; }
                    .log-time { color: #888; }
                    .log-level { font-weight: bold; }
                    .log-type { color: #0073aa; }
                </style>
                <div class="panel-list log-viewer" style="max-height: 300px; overflow-y: auto;">' . 
                    implode('', $logs) . 
                '</div>';

            case 'get_insights':
                return '<div class="panel-grid">
                    <div class="panel-item">
                        <strong id="insights-top-categories">Technology, Business</strong>
                        <div>Top Categories</div>
                    </div>
                    <div class="panel-item">
                        <strong id="insights-peak-hours">2-4 PM EST</strong>
                        <div>Peak Hours</div>
                    </div>
                    <div class="panel-item">
                        <strong id="insights-avg-quality">' . rand(75, 95) . '%</strong>
                        <div>Avg Quality</div>
                    </div>
                    <div class="panel-item">
                        <strong id="insights-trends">Increasing AI content</strong>
                        <div>Trends</div>
                    </div>
                </div>';

            case 'ai_chat':
                $message = sanitize_textarea_field($data['message'] ?? '');
                if (empty($message)) {
                    return array('success' => false, 'message' => 'Empty message');
                }
                $responses = array(
                    'This is a mock AI response. Configure a real MPC connector to enable AI.',
                    'I can help analyze data and suggest automations.',
                );
                $response = $responses[array_rand($responses)];
                if (class_exists('RawWire_Logger')) {
                    RawWire_Logger::log('AI chat (core)', 'info', array('msg' => $message, 'resp' => $response));
                }
                return $response;

            case 'get_workflow_config':
                return array(
                    'models' => array(
                        array('id' => 'gpt-4', 'name' => 'GPT-4'),
                        array('id' => 'gpt-3.5-turbo', 'name' => 'GPT-3.5 Turbo'),
                    ),
                    'parameters' => array(
                        'temperature' => 0.7,
                        'max_tokens' => 1024,
                        'model' => 'gpt-4',
                    ),
                );

            case 'execute_workflow':
                // Simulate workflow execution and return logs/result
                $logs = array(
                    'Workflow initiated at ' . current_time('mysql'),
                    'Preparing inputs...',
                    'Invoking model...',
                    'Workflow completed.'
                );
                if (class_exists('RawWire_Logger')) {
                    RawWire_Logger::log('Executed workflow (core)', 'info', array('logs' => $logs));
                }
                return array('logs' => $logs, 'result' => 'ok');

            case 'cancel_workflow':
                return array('message' => 'Cancelled');

            case 'panel_control':
                $action_key = sanitize_text_field($data['control_action'] ?? '');
                $value = isset($data['value']) ? intval($data['value']) : 0;
                $panel_id = sanitize_text_field($data['panel_id'] ?? '');
                // Persist as option for legacy controls
                if (!empty($action_key)) {
                    update_option('rawwire_' . $action_key, $value);
                }
                return array('success' => true, 'panel' => $panel_id, 'action' => $action_key, 'value' => $value);

            case 'sync':
                // Trigger REST sync endpoint via internal API
                $api = new RawWire_REST_API();
                $req = new WP_REST_Request('POST', '/');
                $req->set_param('source', $data['source'] ?? 'all');
                return $api->sync_data($req);

            case 'clear_cache':
                wp_cache_flush();
                return array('message' => 'Cache cleared');

            case 'update_content':
                $id = intval($data['id'] ?? 0);
                $status = sanitize_text_field($data['status'] ?? '');
                if (!in_array($status, array('pending','approved','rejected'), true)) {
                    return array('success' => false, 'message' => 'Invalid status');
                }
                $table = $wpdb->prefix . 'rawwire_content';
                $updated = $wpdb->update($table, array('status'=>$status,'updated_at'=>current_time('mysql')), array('id'=>$id), array('%s','%s'), array('%d'));
                if ($updated === false) {
                    return array('success'=>false,'message'=>'Update failed');
                }
                return array('success'=>true);

            case 'get_approvals':
                // Get pending content for approvals
                $table = $wpdb->prefix . 'rawwire_content';
                $pending_items = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE status = %s ORDER BY created_at DESC LIMIT %d", 'pending', 20), ARRAY_A);
                
                if (empty($pending_items)) {
                    return '<p>No content pending approval.</p>';
                }
                
                $html = '<table class="widefat rawwire-approvals-table">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Source</th>
                            <th>Created</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>';
                
                foreach ($pending_items as $item) {
                    $html .= '<tr>
                        <td>' . esc_html($item['title']) . '</td>
                        <td>' . esc_html($item['source']) . '</td>
                        <td>' . esc_html(date('M j, Y H:i', strtotime($item['created_at']))) . '</td>
                        <td><span class="status-pending">Pending</span></td>
                        <td>
                            <button class="button button-small rawwire-approve" data-id="' . esc_attr($item['id']) . '">Approve</button>
                            <button class="button button-small rawwire-reject" data-id="' . esc_attr($item['id']) . '">Reject</button>
                        </td>
                    </tr>';
                }
                
                $html .= '</tbody></table>';

                // Poll for new batch markers and refresh approvals panel when new items arrive
                $html .= '<script>jQuery(function($){
                    var lastSeen = 0;
                    function checkBatch(){
                        $.post(ajaxurl, { action: "rawwire_get_last_batch", nonce: rawwire_ajax.nonce })
                        .done(function(res){
                            if (res.success && res.data.time && res.data.time > lastSeen) {
                                lastSeen = res.data.time;
                                // If on approvals panel, refresh the page content
                                if (document.location.pathname.indexOf("raw-wire-approvals") !== -1) {
                                    location.reload();
                                }
                            }
                        });
                    }
                    setInterval(checkBatch, 5000);
                });</script>';

                return $html;

            default:
        }
    }
}

// Register module with module core
if (class_exists('RawWire_Module_Core')) {
    try {
        $mod = new RawWire_Core_Module();
        RawWire_Module_Core::register_module('core', $mod);
    } catch (Exception $e) {
        // ignore registration failures
    }
}
