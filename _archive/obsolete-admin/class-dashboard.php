<?php
/**
 * Admin Dashboard class for RawWire Dashboard
 *
 * @since 1.0.18
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * RawWire Admin Dashboard Class
 */
class RawWire_Admin_Dashboard {

    /**
     * Render the admin dashboard
     */
    public function render() {
        $stats = $this->get_stats();
        $content = $this->get_recent_content();

        ?>
        <div class="wrap">
            <h1><?php _e('Raw-Wire Dashboard', 'raw-wire-dashboard'); ?></h1>

            <div class="rawwire-dashboard">
                <!-- Header -->
                <div class="rawwire-header">
                    <div class="rawwire-hero">
                        <h2><?php _e('Findings Control', 'raw-wire-dashboard'); ?></h2>
                        <p><?php _e('Top findings per source, ranked, scored, and ready for human approval.', 'raw-wire-dashboard'); ?></p>
                    </div>
                    <div class="rawwire-actions">
                        <button id="rawwire-ai-discovery-btn" class="button button-primary">
                            <span class="dashicons dashicons-search"></span>
                            <?php _e('AI Discovery', 'raw-wire-dashboard'); ?>
                        </button>
                        <button id="rawwire-sync-btn" class="button button-primary">
                            <span class="dashicons dashicons-update"></span>
                            <?php _e('Sync Sources', 'raw-wire-dashboard'); ?>
                        </button>
                        <button id="rawwire-clear-cache-btn" class="button">
                            <span class="dashicons dashicons-trash"></span>
                            <?php _e('Clear Cache', 'raw-wire-dashboard'); ?>
                        </button>
                        <button class="button rawwire-workflow-search">
                            <span class="dashicons dashicons-search"></span>
                            <?php _e('Search Workflow', 'raw-wire-dashboard'); ?>
                        </button>
                        <button class="button rawwire-workflow-generative">
                            <span class="dashicons dashicons-admin-generic"></span>
                            <?php _e('Generative Workflow', 'raw-wire-dashboard'); ?>
                        </button>
                        <button class="button rawwire-chat-toggle">
                            <span class="dashicons dashicons-format-chat"></span>
                            <?php _e('AI Chat', 'raw-wire-dashboard'); ?>
                        </button>
                    </div>
                </div>

                <!-- Top Metrics + Controls -->
                <div class="rawwire-stats">
                    <div class="stat-card">
                        <h3><?php echo esc_html($stats['total']); ?></h3>
                        <p><?php _e('Total Findings', 'raw-wire-dashboard'); ?></p>
                    </div>
                    <div class="stat-card">
                        <h3><?php echo esc_html($stats['pending']); ?></h3>
                        <p><?php _e('Pending Review', 'raw-wire-dashboard'); ?></p>
                    </div>
                    <div class="stat-card">
                        <h3><?php echo esc_html($stats['approved']); ?></h3>
                        <p><?php _e('Approved', 'raw-wire-dashboard'); ?></p>
                    </div>
                    <div class="stat-card">
                        <h3><?php echo esc_html($stats['ai_discovered'] ?? 0); ?></h3>
                        <p><?php _e('AI Discovered', 'raw-wire-dashboard'); ?></p>
                    </div>
                    <div class="stat-card">
                        <h3><?php echo esc_html($stats['ai_shocking'] ?? 0); ?></h3>
                        <p><?php _e('Shocking Facts', 'raw-wire-dashboard'); ?></p>
                    </div>
                    <div class="stat-card small-meta">
                        <h4><?php _e('Last Sync', 'raw-wire-dashboard'); ?></h4>
                        <p class="last-sync-value"><?php echo esc_html($stats['last_sync']); ?></p>
                    </div>
                    <div class="stat-card small-meta">
                        <h4><?php _e('Last AI Discovery', 'raw-wire-dashboard'); ?></h4>
                        <p class="last-sync-value"><?php echo esc_html($stats['ai_last_discovery'] ?? __('Never', 'raw-wire-dashboard')); ?></p>
                    </div>
                </div>

                <!-- Data Panels -->
                <div class="rawwire-panels">
                    <?php
                    // Try to get panels from modules (core module preferred)
                    $panels = array();
                    if (class_exists('RawWire_Module_Core')) {
                        $mods = RawWire_Module_Core::get_modules();
                        if (!empty($mods)) {
                            // prefer core module if present
                            if (isset($mods['core']) && method_exists($mods['core'], 'get_admin_panels')) {
                                $panels = $mods['core']->get_admin_panels();
                            } else {
                                foreach ($mods as $m) {
                                    if (method_exists($m, 'get_admin_panels')) {
                                        $p = $m->get_admin_panels();
                                        if (is_array($p)) $panels = array_merge($panels, $p);
                                    }
                                }
                            }
                        }
                    }

                    if (empty($panels)) {
                        // Fallback: render legacy static panels
                    ?>
                    <div class="panel" id="overview-panel">
                        <div class="panel-header">
                            <h3><span class="dashicons dashicons-chart-bar"></span> <?php _e('Overview', 'raw-wire-dashboard'); ?></h3>
                        </div>
                        <div class="panel-body">
                            <p class="muted"><?php _e('System performance and processing metrics', 'raw-wire-dashboard'); ?></p>
                            <div class="panel-grid">
                                <div class="panel-item">
                                    <strong id="overview-total-processed">0</strong>
                                    <div><?php _e('Total Processed', 'raw-wire-dashboard'); ?></div>
                                </div>
                                <div class="panel-item">
                                    <strong id="overview-active-workflows">0</strong>
                                    <div><?php _e('Active Workflows', 'raw-wire-dashboard'); ?></div>
                                </div>
                                <div class="panel-item">
                                    <strong id="overview-success-rate">0%</strong>
                                    <div><?php _e('Success Rate', 'raw-wire-dashboard'); ?></div>
                                </div>
                                <div class="panel-item">
                                    <strong id="overview-avg-response">0ms</strong>
                                    <div><?php _e('Avg Response', 'raw-wire-dashboard'); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="panel" id="sources-panel">
                        <div class="panel-header">
                            <h3><span class="dashicons dashicons-admin-site"></span> <?php _e('Sources & Controls', 'raw-wire-dashboard'); ?></h3>
                        </div>
                        <div class="panel-body">
                            <p class="muted"><?php _e('Data sources and system controls', 'raw-wire-dashboard'); ?></p>
                            <div id="sources-list" class="panel-list"><?php _e('Loading sources...', 'raw-wire-dashboard'); ?></div>
                            <div class="control-row">
                                <label><input type="checkbox" class="panel-control-toggle" data-action="auto_sync"> <?php _e('Auto Sync', 'raw-wire-dashboard'); ?></label>
                                <label><input type="checkbox" class="panel-control-toggle" data-action="notifications"> <?php _e('Notifications', 'raw-wire-dashboard'); ?></label>
                                <label><input type="checkbox" class="panel-control-toggle" data-action="error_reporting"> <?php _e('Error Reporting', 'raw-wire-dashboard'); ?></label>
                            </div>
                        </div>
                    </div>

                    <div class="panel" id="queue-panel">
                        <div class="panel-header">
                            <h3><span class="dashicons dashicons-clock"></span> <?php _e('Processing Queue', 'raw-wire-dashboard'); ?></h3>
                        </div>
                        <div class="panel-body">
                            <p class="muted"><?php _e('Content processing status and queue management', 'raw-wire-dashboard'); ?></p>
                            <div class="panel-grid">
                                <div class="panel-item">
                                    <strong id="queue-pending">0</strong>
                                    <div><?php _e('Pending', 'raw-wire-dashboard'); ?></div>
                                </div>
                                <div class="panel-item">
                                    <strong id="queue-processing">0</strong>
                                    <div><?php _e('Processing', 'raw-wire-dashboard'); ?></div>
                                </div>
                                <div class="panel-item">
                                    <strong id="queue-completed">0</strong>
                                    <div><?php _e('Completed', 'raw-wire-dashboard'); ?></div>
                                </div>
                                <div class="panel-item">
                                    <strong id="queue-failed">0</strong>
                                    <div><?php _e('Failed', 'raw-wire-dashboard'); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="panel" id="logs-panel">
                        <div class="panel-header">
                            <h3><span class="dashicons dashicons-list-view"></span> <?php _e('Activity Logs', 'raw-wire-dashboard'); ?></h3>
                        </div>
                        <div class="panel-body">
                            <p class="muted"><?php _e('Recent system activity and error logs', 'raw-wire-dashboard'); ?></p>
                            <div id="logs-container" class="panel-list" style="font-family: monospace; font-size: 12px; max-height: 200px; overflow-y: auto;"><?php _e('Loading logs...', 'raw-wire-dashboard'); ?></div>
                        </div>
                    </div>

                    <div class="panel" id="insights-panel">
                        <div class="panel-header">
                            <h3><span class="dashicons dashicons-chart-line"></span> <?php _e('Insights', 'raw-wire-dashboard'); ?></h3>
                        </div>
                        <div class="panel-body">
                            <p class="muted"><?php _e('Analytics and performance insights', 'raw-wire-dashboard'); ?></p>
                            <div class="panel-grid">
                                <div class="panel-item">
                                    <strong id="insights-top-categories"><?php _e('None', 'raw-wire-dashboard'); ?></strong>
                                    <div><?php _e('Top Categories', 'raw-wire-dashboard'); ?></div>
                                </div>
                                <div class="panel-item">
                                    <strong id="insights-peak-hours"><?php _e('N/A', 'raw-wire-dashboard'); ?></strong>
                                    <div><?php _e('Peak Hours', 'raw-wire-dashboard'); ?></div>
                                </div>
                                <div class="panel-item">
                                    <strong id="insights-avg-quality">0%</strong>
                                    <div><?php _e('Avg Quality', 'raw-wire-dashboard'); ?></div>
                                </div>
                                <div class="panel-item">
                                    <strong id="insights-trends"><?php _e('No trends', 'raw-wire-dashboard'); ?></strong>
                                    <div><?php _e('Trends', 'raw-wire-dashboard'); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php
                    } else {
                        foreach ($panels as $key => $p) {
                            $panel_id = isset($p['panel_id']) ? $p['panel_id'] : 'panel-' . esc_attr($key);
                            $title = isset($p['title']) ? $p['title'] : ucfirst($key);
                            $desc = isset($p['description']) ? $p['description'] : '';
                            $module = isset($p['module']) ? $p['module'] : '';
                            $action = isset($p['action']) ? $p['action'] : '';
                    ?>
                    <div class="panel" id="<?php echo esc_attr($panel_id); ?>">
                        <div class="panel-header">
                            <h3><?php echo '<span class="dashicons dashicons-chart-bar"></span> ' . esc_html($title); ?></h3>
                        </div>
                        <div class="panel-body">
                            <?php if (!empty($desc)) : ?>
                                <p class="muted"><?php echo esc_html($desc); ?></p>
                            <?php endif; ?>
                            <div class="panel-body-content" data-module="<?php echo esc_attr($module); ?>" data-action="<?php echo esc_attr($action); ?>">
                                <?php _e('Loading...', 'raw-wire-dashboard'); ?>
                            </div>
                        </div>
                    </div>
                    <?php
                        }
                    }
                    ?>
                </div>

                <!-- Recent Findings Table -->
                <div class="rawwire-content">
                    <h3><?php _e('Recent Findings', 'raw-wire-dashboard'); ?></h3>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php _e('Title', 'raw-wire-dashboard'); ?></th>
                                <th><?php _e('Source', 'raw-wire-dashboard'); ?></th>
                                <th><?php _e('Status', 'raw-wire-dashboard'); ?></th>
                                <th><?php _e('Date', 'raw-wire-dashboard'); ?></th>
                                <th><?php _e('Actions', 'raw-wire-dashboard'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($content)): ?>
                                <tr>
                                    <td colspan="5"><?php _e('No content found.', 'raw-wire-dashboard'); ?></td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($content as $item): ?>
                                    <tr>
                                        <td><?php echo esc_html($item['title']); ?></td>
                                        <td><?php echo esc_html($item['source']); ?></td>
                                        <td>
                                            <span class="status status-<?php echo esc_attr($item['status']); ?>">
                                                <?php echo esc_html(ucfirst($item['status'])); ?>
                                            </span>
                                        </td>
                                        <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($item['created_at']))); ?></td>
                                        <td>
                                            <button class="button button-small approve-btn" data-id="<?php echo esc_attr($item['id']); ?>" data-status="approved">
                                                <?php _e('Approve', 'raw-wire-dashboard'); ?>
                                            </button>
                                            <button class="button button-small reject-btn" data-id="<?php echo esc_attr($item['id']); ?>" data-status="rejected">
                                                <?php _e('Reject', 'raw-wire-dashboard'); ?>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- AI Chat Interface -->
                <div class="rawwire-chat">
                    <div class="chat-header">
                        <h4><span class="dashicons dashicons-format-chat"></span> <?php _e('AI Assistant', 'raw-wire-dashboard'); ?></h4>
                        <button class="chat-close">&times;</button>
                    </div>
                    <div class="chat-messages">
                        <div class="chat-message assistant"><?php _e('Hello! I\'m your AI assistant. How can I help you with your RawWire dashboard today?', 'raw-wire-dashboard'); ?></div>
                    </div>
                    <div class="chat-input-area">
                        <div class="chat-input-row">
                            <input type="text" class="chat-input" placeholder="<?php esc_attr_e('Ask me anything about your data...', 'raw-wire-dashboard'); ?>">
                            <button class="chat-send"><?php _e('Send', 'raw-wire-dashboard'); ?></button>
                        </div>
                    </div>
                </div>

                <!-- Workflow Modal -->
                <div class="rawwire-workflow-modal">
                    <div class="workflow-window">
                        <div class="workflow-header">
                            <h3><?php _e('AI Workflow', 'raw-wire-dashboard'); ?></h3>
                            <button class="workflow-close">&times;</button>
                        </div>
                        <div class="workflow-body">
                            <div class="workflow-controls">
                                <div class="workflow-control">
                                    <h4><?php _e('AI Model', 'raw-wire-dashboard'); ?></h4>
                                    <select id="workflow-model">
                                        <option value=""><?php _e('Select Model...', 'raw-wire-dashboard'); ?></option>
                                    </select>
                                </div>
                                <div class="workflow-control">
                                    <h4><?php _e('Temperature', 'raw-wire-dashboard'); ?></h4>
                                    <input type="number" id="workflow-temperature" step="0.1" min="0" max="2" value="0.7">
                                </div>
                                <div class="workflow-control">
                                    <h4><?php _e('Max Tokens', 'raw-wire-dashboard'); ?></h4>
                                    <input type="number" id="workflow-max-tokens" min="1" max="4096" value="2048">
                                </div>
                            </div>
                            <div class="workflow-control">
                                <h4><?php _e('Prompt/Instructions', 'raw-wire-dashboard'); ?></h4>
                                <textarea id="workflow-prompt" rows="3" placeholder="<?php esc_attr_e('Enter your prompt or instructions...', 'raw-wire-dashboard'); ?>"></textarea>
                            </div>
                            <div class="workflow-control">
                                <h4><?php _e('Input Data', 'raw-wire-dashboard'); ?></h4>
                                <textarea id="workflow-input" rows="4" placeholder="<?php esc_attr_e('Enter input data or context...', 'raw-wire-dashboard'); ?>"></textarea>
                            </div>
                            <div class="workflow-logs">
                                <?php _e('Workflow logs will appear here...', 'raw-wire-dashboard'); ?>
                            </div>
                        </div>
                        <div class="workflow-footer">
                            <div class="workflow-status"><?php _e('Status: Ready', 'raw-wire-dashboard'); ?></div>
                            <div class="workflow-actions">
                                <button class="button workflow-cancel"><?php _e('Cancel', 'raw-wire-dashboard'); ?></button>
                                <button class="button button-primary workflow-execute"><?php _e('Execute Workflow', 'raw-wire-dashboard'); ?></button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <style>
            .rawwire-dashboard { margin-top: 20px; }
            .rawwire-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid #ddd; }
            .rawwire-hero h2 { margin: 0; font-size: 24px; }
            .rawwire-hero p { margin: 5px 0 0 0; color: #666; }
            .rawwire-actions { display: flex; gap: 10px; }
            .rawwire-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
            .stat-card { background: #fff; border: 1px solid #ddd; padding: 20px; text-align: center; border-radius: 4px; }
            .stat-card h3 { margin: 0 0 5px 0; font-size: 28px; font-weight: bold; }
            .stat-card p { margin: 0; color: #666; }
            .status { padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: bold; text-transform: uppercase; }
            .status-pending { background: #fff3cd; color: #856404; }
            .status-approved { background: #d4edda; color: #155724; }
            .status-rejected { background: #f8d7da; color: #721c24; }
            .approve-btn { background: #28a745; color: white; border-color: #28a745; }
            .reject-btn { background: #dc3545; color: white; border-color: #dc3545; }
        </style>

        <script>
        jQuery(document).ready(function($) {
            // Sync button
            $('#rawwire-sync-btn').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('<?php _e('Syncing...', 'raw-wire-dashboard'); ?>');

                $.post(ajaxurl, {
                    action: 'rawwire_sync',
                    nonce: rawwire_ajax.nonce
                })
                .done(function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data.message || '<?php _e('Sync failed', 'raw-wire-dashboard'); ?>');
                    }
                })
                .fail(function() {
                    alert('<?php _e('Network error', 'raw-wire-dashboard'); ?>');
                })
                .always(function() {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> <?php _e('Sync Sources', 'raw-wire-dashboard'); ?>');
                });
            });

            // AI Discovery button
            $('#rawwire-ai-discovery-btn').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('<?php _e('Discovering...', 'raw-wire-dashboard'); ?>');

                $.post(ajaxurl, {
                    action: 'rawwire_run_ai_discovery',
                    nonce: rawwire_ajax.nonce
                })
                .done(function(response) {
                    if (response.success) {
                        alert(response.data.message || '<?php _e('AI Discovery completed successfully!', 'raw-wire-dashboard'); ?>');
                        location.reload();
                    } else {
                        alert(response.data.message || '<?php _e('AI Discovery failed', 'raw-wire-dashboard'); ?>');
                    }
                })
                .fail(function() {
                    alert('<?php _e('Network error during AI Discovery', 'raw-wire-dashboard'); ?>');
                })
                .always(function() {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-search"></span> <?php _e('AI Discovery', 'raw-wire-dashboard'); ?>');
                });
            });

            // Content action buttons
            $('.approve-btn, .reject-btn').on('click', function() {
                var $btn = $(this);
                var id = $btn.data('id');
                var status = $btn.data('status');

                $btn.prop('disabled', true);

                $.post(ajaxurl, {
                    action: 'rawwire_update_content',
                    id: id,
                    status: status,
                    nonce: rawwire_ajax.nonce
                })
                .done(function(response) {
                    if (response.success) {
                        $btn.closest('tr').find('.status').removeClass('status-pending status-approved status-rejected').addClass('status-' + status).text(status.charAt(0).toUpperCase() + status.slice(1));
                    } else {
                        alert(response.data.message || '<?php _e('Update failed', 'raw-wire-dashboard'); ?>');
                    }
                })
                .fail(function() {
                    alert('<?php _e('Network error', 'raw-wire-dashboard'); ?>');
                })
                .always(function() {
                    $btn.prop('disabled', false);
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Get dashboard statistics
     *
     * @return array
     */
    private function get_stats() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'rawwire_content';

        // Get AI Discovery stats
        $ai_stats = array();
        if (class_exists('RawWire_AI_Discovery')) {
            $ai_stats = RawWire_AI_Discovery::get_stats();
        }

        return array(
            'total'     => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_name"),
            'pending'   => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE status = %s", 'pending')),
            'approved'  => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE status = %s", 'approved')),
            'rejected'  => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE status = %s", 'rejected')),
            'last_sync' => get_option('rawwire_last_sync', __('Never', 'raw-wire-dashboard')),
            'ai_discovered' => $ai_stats['total_discovered'] ?? 0,
            'ai_shocking' => $ai_stats['shocking_facts'] ?? 0,
            'ai_pending_review' => $ai_stats['pending_review'] ?? 0,
            'ai_last_discovery' => $ai_stats['last_discovery'] ?? __('Never', 'raw-wire-dashboard'),
        );
    }

    /**
     * Get recent content
     *
     * @return array
     */
    private function get_recent_content() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'rawwire_content';

        return $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM $table_name ORDER BY created_at DESC LIMIT %d", 20),
            ARRAY_A
        );
    }
}