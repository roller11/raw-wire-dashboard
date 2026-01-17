<?php
/**
 * Dashboard Template - All custom functionality and AJAX handlers
 * This file is completely removable - dashboard will fall back to basic display
 */

// Register template-specific AJAX handlers
add_action('wp_ajax_rawwire_save_scoring_weights', 'rawwire_template_save_scoring_weights');
add_action('wp_ajax_rawwire_get_scoring_weights', 'rawwire_template_get_scoring_weights');
add_action('wp_ajax_rawwire_save_filter_settings', 'rawwire_template_save_filter_settings');
add_action('wp_ajax_rawwire_get_filter_settings', 'rawwire_template_get_filter_settings');

/**
 * AJAX handler to save AI scoring weights
 */
function rawwire_template_save_scoring_weights() {
    check_ajax_referer('rawwire_save_scoring', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Insufficient permissions'));
        return;
    }

    $weights = isset($_POST['weights']) ? $_POST['weights'] : array();
    
    // Validate weights
    $valid_weights = array();
    $total = 0;
    foreach (array('shocking', 'unbelievable', 'newsworthy', 'unique') as $criterion) {
        $value = isset($weights[$criterion]) ? floatval($weights[$criterion]) : 25;
        $value = max(0, min(50, $value)); // Clamp between 0-50
        $valid_weights[$criterion] = $value;
        $total += $value;
    }
    
    // Normalize to 100 if needed
    if ($total > 0 && $total != 100) {
        foreach ($valid_weights as $key => $value) {
            $valid_weights[$key] = round(($value / $total) * 100, 1);
        }
    }
    
    update_option('rawwire_scoring_weights', $valid_weights);
    
    RawWire_Logger::info('AI scoring weights updated', array('weights' => $valid_weights));
    
    wp_send_json_success(array(
        'message' => 'Scoring weights saved',
        'weights' => $valid_weights
    ));
}

/**
 * AJAX handler to get AI scoring weights
 */
function rawwire_template_get_scoring_weights() {
    check_ajax_referer('rawwire_save_scoring', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Insufficient permissions'));
        return;
    }

    $weights = get_option('rawwire_scoring_weights', array(
        'shocking' => 25,
        'unbelievable' => 25,
        'newsworthy' => 25,
        'unique' => 25
    ));
    
    wp_send_json_success(array('weights' => $weights));
}

/**
 * AJAX handler to save filter settings
 */
function rawwire_template_save_filter_settings() {
    check_ajax_referer('rawwire_save_filters', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Insufficient permissions'));
        return;
    }

    $keywords = isset($_POST['keywords']) ? sanitize_textarea_field($_POST['keywords']) : '';
    $enabled = isset($_POST['enabled']) ? (bool) $_POST['enabled'] : false;
    
    $settings = array(
        'keywords' => $keywords,
        'enabled' => $enabled
    );
    
    update_option('rawwire_filter_settings', $settings);
    
    RawWire_Logger::info('Filter settings updated', $settings);
    
    wp_send_json_success(array(
        'message' => 'Filter settings saved',
        'settings' => $settings
    ));
}

/**
 * AJAX handler to get filter settings
 */
function rawwire_template_get_filter_settings() {
    check_ajax_referer('rawwire_save_filters', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Insufficient permissions'));
        return;
    }

    $settings = get_option('rawwire_filter_settings', array(
        'keywords' => '',
        'enabled' => false
    ));
    
    wp_send_json_success(array('settings' => $settings));
}
?>
<div class="wrap rawwire-dashboard" data-rawwire-template="<?php echo esc_attr($template_config['name'] ?? 'raw-wire-default'); ?>">
    <h1 style="position: absolute; left: -9999px;">Raw-Wire Dashboard</h1>

    <!-- Hero Header Section -->
    <section class="rawwire-hero">
        <div class="hero-content">
            <p class="eyebrow">Raw-Wire Control Center</p>
            <h1>Findings Dashboard</h1>
            <p class="lede">Top findings per source, ranked, scored, and ready for human approval.</p>
            <div class="hero-meta">
                <span class="pill subtle">Template: <?php echo esc_html($template_config['name'] ?? 'default'); ?></span>
                <span class="pill subtle">Last Sync: <?php echo esc_html($stats['last_sync'] ?? 'Never'); ?></span>
            </div>
        </div>
        <div class="hero-actions">
            <div class="button-group">
                <button id="fetch-data-btn" class="button button-primary">
                    <span class="dashicons dashicons-update"></span> Sync Sources
                </button>
                <button id="clear-cache-btn" class="button ghost">
                    <span class="dashicons dashicons-trash"></span> Clear Cache
                </button>
            </div>
            <div id="control-status"></div>
        </div>
    </section>

    <!-- Statistics Cards -->
    <section class="stat-deck">
        <?php
            $stats_cards = $module['stats']['cards'] ?? array();
            if (empty($stats_cards)) {
                // Fallback to hard-coded cards if module config is missing
                $stats_cards = array(
                    array('label' => 'Total Findings', 'field' => 'total', 'subtitle' => 'Across all sources'),
                    array('label' => 'Pending Review', 'field' => 'pending', 'subtitle' => 'Awaiting human judgment'),
                    array('label' => 'Approved', 'field' => 'approved', 'subtitle' => 'Ready for distribution'),
                    array('label' => 'Fresh (24h)', 'field' => 'fresh_24h', 'subtitle' => 'Recent, higher-signal items'),
                    array('label' => 'Avg Score', 'field' => 'avg_score', 'subtitle' => 'Weighted relevance', 'highlight' => true),
                );
            }
            
            foreach ($stats_cards as $card) {
                $label = esc_html($card['label'] ?? '');
                $field = $card['field'] ?? '';
                $value = esc_html($ui_metrics[$field] ?? '0');
                $subtitle = esc_html($card['subtitle'] ?? '');
                $highlight_class = !empty($card['highlight']) ? ' highlight' : '';
                ?>
                <div class="stat-card<?php echo $highlight_class; ?>">
                    <p><?php echo $label; ?></p>
                    <h2><?php echo $value; ?></h2>
                    <small><?php echo $subtitle; ?></small>
                </div>
                <?php
            }
        ?>
    </section>

    <section class="control-bar">
        <div class="filter-group">
            <label>
                Source
                <select id="filter-source">
                    <option value="">All sources</option>
                    <?php foreach (($template_config['filters']['sources'] ?? []) as $source): ?>
                        <option value="<?php echo esc_attr($source); ?>"><?php echo esc_html(ucfirst($source)); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Category
                <select id="filter-category">
                    <option value="">All categories</option>
                    <?php foreach (($template_config['filters']['categories'] ?? []) as $category): ?>
                        <option value="<?php echo esc_attr($category); ?>"><?php echo esc_html(ucfirst($category)); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Status
                <select id="filter-status">
                    <option value="">Any status</option>
                    <?php foreach (($template_config['filters']['statuses'] ?? []) as $status): ?>
                        <option value="<?php echo esc_attr($status); ?>"><?php echo esc_html(ucfirst($status)); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="slider-label">
                Min Score <span id="score-value">0</span>
                <input type="range" id="filter-score" min="0" max="100" step="5" value="0" />
            </label>
        </div>
        <div class="quick-filters">
            <button class="chip" data-filter="fresh">Fresh 24h</button>
            <button class="chip" data-filter="pending">Pending</button>
            <button class="chip" data-filter="approved">Approved</button>
            <button class="chip" data-filter="highscore">Score > 80</button>
        </div>
    </section>

    <section class="board">
        <div class="board-main">
            <div class="board-header">
                <div>
                    <p class="eyebrow">Top Findings</p>
                    <h3>Ranked list · multi-source</h3>
                </div>
                <span class="pill subtle">Showing up to 20 recent items</span>
            </div>

            <?php if (!empty($findings)): ?>
                <div class="finding-list" id="finding-list">
                    <?php foreach ($findings as $finding): ?>
                        <article class="finding-card"
                            data-id="<?php echo esc_attr($finding['id']); ?>"
                            data-source="<?php echo esc_attr($finding['source']); ?>"
                            data-category="<?php echo esc_attr($finding['category']); ?>"
                            data-status="<?php echo esc_attr($finding['status']); ?>"
                            data-score="<?php echo esc_attr($finding['score']); ?>"
                            data-rank="<?php echo esc_attr($finding['rank']); ?>"
                            data-freshness="<?php echo esc_attr($finding['freshness'] ?? 0); ?>"
                            data-link="<?php echo esc_url($finding['link']); ?>"
                            data-title="<?php echo esc_attr($finding['title']); ?>"
                            data-summary="<?php echo esc_attr($finding['summary'] ?: ''); ?>"
                            data-confidence="<?php echo esc_attr(number_format($finding['confidence'] * 100, 0)); ?>"
                            data-tags="<?php echo esc_attr(implode(',', $finding['tags'] ?? [])); ?>">
                            <div class="finding-rank">#<?php echo esc_html($finding['rank']); ?></div>
                            <div class="finding-body">
                                <div class="finding-top">
                                    <div>
                                        <h4 class="finding-title"><?php echo esc_html($finding['title']); ?></h4>
                                        <p class="finding-meta">
                                            <span class="badge tone-info"><?php echo esc_html(ucfirst($finding['source'])); ?></span>
                                            <span class="badge tone-muted"><?php echo esc_html($finding['category']); ?></span>
                                            <span class="badge tone-outline">Score: <?php echo esc_html($finding['score']); ?></span>
                                            <span class="badge tone-muted"><?php echo esc_html($finding['freshness_label']); ?></span>
                                        </p>
                                    </div>
                                    <div class="finding-actions">
                                        <button class="button button-small ghost approve-btn" data-id="<?php echo esc_attr($finding['id']); ?>">Approve</button>
                                        <button class="button button-small ghost snooze-btn" data-id="<?php echo esc_attr($finding['id']); ?>">Snooze</button>
                                    </div>
                                </div>
                                <p class="finding-summary"><?php echo esc_html($finding['summary'] ?: 'No summary available yet.'); ?></p>
                                <div class="finding-tags">
                                    <?php foreach (($finding['tags'] ?? []) as $tag): ?>
                                        <span class="tag"><?php echo esc_html($tag); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="finding-meta-col">
                                <div class="mini-stat">
                                    <span class="label">Confidence</span>
                                    <strong><?php echo esc_html(number_format($finding['confidence'] * 100, 0)); ?>%</strong>
                                </div>
                                <div class="mini-stat">
                                    <span class="label">Status</span>
                                    <span class="pill tone-<?php echo esc_attr($finding['status']); ?>"><?php echo esc_html(ucfirst($finding['status'])); ?></span>
                                </div>
                                <?php if (!empty($finding['link'])): ?>
                                    <a class="text-link" href="<?php echo esc_url($finding['link']); ?>" target="_blank" rel="noopener">Open source</a>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state" role="status">
                    <span class="dashicons dashicons-admin-post" style="font-size: 48px; opacity: 0.3; margin-bottom: 16px;"></span>
                    <h3 style="margin: 0 0 8px 0; font-size: 18px;">No findings yet</h3>
                    <p style="margin: 0 0 16px 0; color: #666;">Click "Sync Sources" above to fetch and analyze data</p>
                    <button class="button button-primary" onclick="document.getElementById('fetch-data-btn').click();">Get Started</button>
                </div>
            <?php endif; ?>
        </div>

        <aside class="insights-panel">
            <div class="panel-block">
                <p class="eyebrow">Signals</p>
                <h4>What we’re tracking</h4>
                <ul class="stacked">
                    <li>Novelty vs. historical baseline</li>
                    <li>Regulatory and compliance triggers</li>
                    <li>Market and sentiment drift</li>
                    <li>Technical risk/bug density</li>
                </ul>
            </div>
            <?php
            // Get per-source statistics from database
            global $wpdb;
            $source_stats = $wpdb->get_results("
                SELECT 
                    source,
                    COUNT(*) as total_items,
                    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_count,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                    MAX(created_at) as last_pull
                FROM {$wpdb->prefix}rawwire_content
                GROUP BY source
                ORDER BY last_pull DESC
            ");
            ?>
            
            <div class="panel-block">
                <p class="eyebrow">Sources Performance</p>
                <h4>Active Data Streams</h4>
                <?php if (!empty($source_stats)): ?>
                    <div style="max-height: 400px; overflow-y: auto;">
                        <?php foreach ($source_stats as $source): 
                            $option_key = 'rawwire_source_stats_' . sanitize_title($source->source);
                            $detailed_stats = get_option($option_key, array());
                        ?>
                            <div style="margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid #e0e0e0;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                    <strong style="font-size: 13px;"><?php echo esc_html($source->source); ?></strong>
                                    <span class="pill subtle"><?php echo esc_html($source->total_items); ?> stored</span>
                                </div>
                                
                                <?php if (!empty($detailed_stats)): ?>
                                    <div style="font-size: 11px; color: #666; display: grid; grid-template-columns: 1fr 1fr; gap: 4px; margin-top: 6px;">
                                        <div>📊 Scraped: <strong><?php echo esc_html($detailed_stats['scraped'] ?? 0); ?></strong></div>
                                        <div>🔁 Dupes: <strong><?php echo esc_html($detailed_stats['duplicates'] ?? 0); ?></strong></div>
                                        <div>⭐ Avg Score: <strong><?php echo esc_html($detailed_stats['avg_score'] ?? 'N/A'); ?></strong></div>
                                        <div>🏆 Top 5 Avg: <strong><?php echo esc_html($detailed_stats['avg_top5_score'] ?? 'N/A'); ?></strong></div>
                                    </div>
                                <?php endif; ?>
                                
                                <div style="font-size: 11px; color: #666; margin-top: 6px;">
                                    <span style="color: #46b450;">✓ <?php echo esc_html($source->approved_count); ?> approved</span>
                                    <span style="margin-left: 12px; color: #f0b849;">○ <?php echo esc_html($source->pending_count); ?> pending</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p style="color: #666; font-size: 13px; margin-top: 8px;">No sources synced yet. Click "Sync Sources" to start pulling data.</p>
                <?php endif; ?>
            </div>
            <div class="panel-block">
                <p class="eyebrow">AI Scoring Controls</p>
                <h4>Criteria Weights</h4>
                <form id="rawwire-scoring-controls" style="font-size: 12px;">
                    <div style="margin-bottom: 8px;">
                        <label style="display: block; margin-bottom: 2px;">Shocking (25%)</label>
                        <input type="range" name="shocking" min="0" max="50" value="25" style="width: 100%;" />
                        <span class="weight-value">25</span>
                    </div>
                    <div style="margin-bottom: 8px;">
                        <label style="display: block; margin-bottom: 2px;">Unbelievable (25%)</label>
                        <input type="range" name="unbelievable" min="0" max="50" value="25" style="width: 100%;" />
                        <span class="weight-value">25</span>
                    </div>
                    <div style="margin-bottom: 8px;">
                        <label style="display: block; margin-bottom: 2px;">Newsworthy (25%)</label>
                        <input type="range" name="newsworthy" min="0" max="50" value="25" style="width: 100%;" />
                        <span class="weight-value">25</span>
                    </div>
                    <div style="margin-bottom: 8px;">
                        <label style="display: block; margin-bottom: 2px;">Unique (25%)</label>
                        <input type="range" name="unique" min="0" max="50" value="25" style="width: 100%;" />
                        <span class="weight-value">25</span>
                    </div>
                    <button type="button" class="button button-secondary" id="save-scoring-weights" style="width: 100%; margin-top: 8px;">Save Weights</button>
                </form>
            </div>
            <div class="panel-block">
                <p class="eyebrow">Search Filters</p>
                <h4>Keyword Filtering</h4>
                <form id="rawwire-filter-controls" style="font-size: 12px;">
                    <div style="margin-bottom: 8px;">
                        <label style="display: block; margin-bottom: 4px;">Custom Keywords</label>
                        <textarea name="keywords" rows="3" style="width: 100%; font-size: 11px;" placeholder="Enter keywords separated by commas..."></textarea>
                        <small style="color: #666;">Leave empty to analyze all content</small>
                    </div>
                    <div style="margin-bottom: 8px;">
                        <label style="display: block; margin-bottom: 4px;">
                            <input type="checkbox" name="enable_filter" /> Enable Keyword Filter
                        </label>
                    </div>
                    <button type="button" class="button button-secondary" id="save-filter-settings" style="width: 100%;">Save Filters</button>
                </form>
            </div>
            <div class="panel-block">
                <p class="eyebrow">System</p>
                <p><strong>DB Version:</strong> <?php echo esc_html(get_option('rawwire_db_version', 'Unknown')); ?></p>
                <p><strong>Last Sync:</strong> <?php echo esc_html($stats['last_sync']); ?></p>
            </div>
        </aside>
    </section>

    <section class="dashboard-section rawwire-activity-logs">
        <div class="section-header">
            <div>
                <p class="eyebrow">Activity Logs</p>
                <h3>System monitoring & error tracking</h3>
            </div>
            <div class="logs-controls">
                <div class="logs-stats">
                    <span class="stat-item">
                        <span class="dashicons dashicons-info"></span>
                        <span id="info-count">0</span> Info
                    </span>
                    <span class="stat-item">
                        <span class="dashicons dashicons-admin-tools"></span>
                        <span id="debug-count">0</span> Debug
                    </span>
                    <span class="stat-item">
                        <span class="dashicons dashicons-warning"></span>
                        <span id="error-count">0</span> Errors
                    </span>
                </div>
                <div class="button-group">
                    <button id="refresh-logs" class="button">
                        <span class="dashicons dashicons-update"></span> Refresh
                    </button>
                    <button id="clear-logs" class="button" data-requires-cap="manage_options">
                        <span class="dashicons dashicons-trash"></span> Clear All
                    </button>
                    <button id="export-logs" class="button">
                        <span class="dashicons dashicons-download"></span> Export
                    </button>
                </div>
            </div>
        </div>

        <!-- Sync Status Section -->
        <div class="sync-status-panel">
            <div class="sync-info-grid">
                <div class="sync-info-item">
                    <span class="dashicons dashicons-clock"></span>
                    <div>
                        <strong>Last Sync:</strong>
                        <span id="last-sync-time"><?php echo esc_html($stats['last_sync']); ?></span>
                    </div>
                </div>
                <div class="sync-info-item">
                    <span class="dashicons dashicons-database"></span>
                    <div>
                        <strong>Total Items:</strong>
                        <span id="total-items"><?php echo esc_html($stats['total_issues']); ?></span>
                    </div>
                </div>
                <div class="sync-info-item">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <div>
                        <strong>Approved:</strong>
                        <span id="approved-count"><?php echo esc_html($stats['approved_issues']); ?></span>
                    </div>
                </div>
                <div class="sync-info-item">
                    <span class="dashicons dashicons-hourglass"></span>
                    <div>
                        <strong>Pending:</strong>
                        <span id="pending-count"><?php echo esc_html($stats['pending_issues']); ?></span>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($findings) && count($findings) > 0): ?>
            <div class="recent-entries-list">
                <h4 class="recent-entries-title">
                    <span class="dashicons dashicons-editor-ul"></span>
                    Recent Entries (Last <?php echo min(5, count($findings)); ?>)
                </h4>
                <ul class="recent-items">
                    <?php foreach (array_slice($findings, 0, 5) as $finding): ?>
                    <li class="recent-item">
                        <span class="recent-item-badge tone-<?php echo esc_attr($finding['status']); ?>">
                            <?php echo esc_html(ucfirst($finding['status'])); ?>
                        </span>
                        <span class="recent-item-title" title="<?php echo esc_attr($finding['title']); ?>">
                            <?php echo esc_html(wp_trim_words($finding['title'], 10)); ?>
                        </span>
                        <span class="recent-item-meta">
                            Score: <?php echo esc_html($finding['score']); ?> | 
                            <?php echo esc_html($finding['freshness_label']); ?>
                        </span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php else: ?>
            <div class="no-recent-entries">
                <span class="dashicons dashicons-info"></span>
                <p>No recent entries. Click "Sync Sources" to fetch data.</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Tab Navigation -->
        <div class="activity-logs-tabs">
            <button class="tab-button active" data-tab="info">
                <span class="dashicons dashicons-info"></span> Info
            </button>
            <button class="tab-button" data-tab="debug">
                <span class="dashicons dashicons-admin-tools"></span> Debug
            </button>
            <button class="tab-button" data-tab="error">
                <span class="dashicons dashicons-warning"></span> Errors
            </button>
        </div>

        <!-- Tab Content -->
        <div id="info-tab" class="tab-pane active">
            <div class="logs-container" data-type="info">
                <div class="logs-loading">
                    <span class="spinner is-active"></span> Loading logs...
                </div>
            </div>
        </div>

        <div id="debug-tab" class="tab-pane">
            <div class="logs-container" data-type="debug">
                <div class="logs-loading">
                    <span class="spinner is-active"></span> Loading debug logs...
                </div>
            </div>
        </div>

        <div id="error-tab" class="tab-pane">
            <div class="logs-container" data-type="error">
                <div class="logs-loading">
                    <span class="spinner is-active"></span> Loading logs...
                </div>
            </div>
        </div>
    </section>

    <aside class="drawer" id="finding-drawer" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="drawer-title">
        <div class="drawer-header">
            <div>
                <p class="eyebrow">Finding details</p>
                <h3 id="drawer-title">Select a finding</h3>
            </div>
            <button class="button ghost" id="drawer-close" aria-label="Close drawer">Close</button>
        </div>
        <div class="drawer-body">
            <p id="drawer-summary" class="drawer-summary">Click any finding card to see full context.</p>
            <div class="drawer-meta" id="drawer-meta" role="list"></div>
            <div class="drawer-tags" id="drawer-tags"></div>
            <div class="drawer-actions">
                <button class="button button-primary" id="drawer-approve" data-requires-cap="manage_options">Approve</button>
                <button class="button ghost" id="drawer-snooze" data-requires-cap="manage_options">Snooze</button>
                <a class="text-link" id="drawer-link" href="#" target="_blank" rel="noopener">Open source</a>
            </div>
        </div>
    </aside>
</div>

<script>
// Load the activity logs JS file and initialize after document ready
(function() {
    // Provide fallback RawWireLogsConfig if not set by wp_localize_script
    if (typeof window.RawWireLogsConfig === 'undefined') {
        console.log('RawWireLogsConfig not found, setting fallback');
        window.RawWireLogsConfig = {
            ajax_url: '<?php echo admin_url('admin-ajax.php'); ?>',
            nonce: '<?php echo wp_create_nonce('rawwire_activity_logs'); ?>',
            strings: {
                loading: 'Loading logs...',
                error_loading: 'Error loading logs.',
                no_logs: 'No logs found.',
                clear_confirm: 'Are you sure you want to clear all activity logs?',
                clear_success: 'Logs cleared successfully.',
                clear_error: 'Error clearing logs.'
            }
        };
    }
    
    var scriptUrl = '<?php echo plugin_dir_url(__FILE__) . 'js/activity-logs.js'; ?>';
    var existingScript = document.querySelector('script[src*="activity-logs.js"]');
    
    if (!existingScript) {
        console.log('Activity logs JS not loaded, loading manually...');
        var script = document.createElement('script');
        script.src = scriptUrl;
        script.async = true;
        script.onload = function() {
            console.log('Activity logs JS loaded');
            if (jQuery && jQuery.ready) {
                jQuery(document).ready(function() {
                    if (window.RawWireActivityLogsManager) {
                        console.log('Initializing RawWireActivityLogsManager');
                        window.RawWireActivityLogsManager.init();
                    }
                });
            }
        };
        document.head.appendChild(script);
    } else {
        console.log('Activity logs JS already loaded');
        // Script is already loaded, just ensure it's initialized
        if (jQuery && jQuery.ready) {
            jQuery(document).ready(function() {
                if (window.RawWireActivityLogsManager) {
                    console.log('Re-initializing RawWireActivityLogsManager');
                    window.RawWireActivityLogsManager.init();
                }
            });
        }
    }
})();

    // Handle dynamic scoring controls
    jQuery(document).ready(function($) {
        // Update weight display as sliders change
        $('#rawwire-scoring-controls input[type="range"]').on('input', function() {
            $(this).next('.weight-value').text($(this).val());
        });

        // Save scoring weights
        $('#save-scoring-weights').on('click', function() {
            var weights = {
                shocking: $('#rawwire-scoring-controls input[name="shocking"]').val(),
                unbelievable: $('#rawwire-scoring-controls input[name="unbelievable"]').val(),
                newsworthy: $('#rawwire-scoring-controls input[name="newsworthy"]').val(),
                unique: $('#rawwire-scoring-controls input[name="unique"]').val()
            };

            $.post(ajaxurl, {
                action: 'rawwire_save_scoring_weights',
                nonce: '<?php echo wp_create_nonce("rawwire_save_scoring"); ?>',
                weights: weights
            }, function(response) {
                if (response.success) {
                    alert('Scoring weights saved!');
                } else {
                    alert('Failed to save weights');
                }
            });
        });

        // Save filter settings  
        $('#save-filter-settings').on('click', function() {
            var keywords = $('#rawwire-filter-controls textarea[name="keywords"]').val();
            var enabled = $('#rawwire-filter-controls input[name="enable_filter"]').is(':checked');

            $.post(ajaxurl, {
                action: 'rawwire_save_filter_settings',
                nonce: '<?php echo wp_create_nonce("rawwire_save_filters"); ?>',
                keywords: keywords,
                enabled: enabled
            }, function(response) {
                if (response.success) {
                    alert('Filter settings saved!');
                } else {
                    alert('Failed to save filters');
                }
            });
        });
    });
</script>
