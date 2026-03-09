<?php

/**
 * Lead Generator — Completed Leads Admin Page
 *
 * Displays enriched lead files ready for client outreach.
 * Status lifecycle: enriching → ready → contacted → converted → lost
 *
 * @package RawWire_Dashboard
 * @since   1.0.27
 */

if (! defined('ABSPATH')) {
    exit;
}

class RawWire_Lead_Completed
{

    /**
     * Render the Leads page
     */
    public function render()
    {
        global $wpdb;
        $table   = rawwire_leads()->table('content');
        $page    = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per     = 15;
        $offset  = ($page - 1) * $per;
        $sort    = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'score_composite';
        $order   = isset($_GET['order']) && $_GET['order'] === 'asc' ? 'ASC' : 'DESC';

        // Status filter
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'all';
        $valid  = array('all', 'enriching', 'ready', 'contacted', 'converted', 'lost');
        if (! in_array($status, $valid)) {
            $status = 'all';
        }

        $where = $status === 'all' ? '1=1' : $wpdb->prepare('status = %s', $status);

        // Validate sort column
        $valid_sorts = array('score_composite', 'valuation', 'issue_date', 'enriched_at', 'status_changed_at', 'project_name');
        if (! in_array($sort, $valid_sorts)) {
            $sort = 'score_composite';
        }

        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where}");
        $leads = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE {$where} ORDER BY {$sort} {$order} LIMIT {$per} OFFSET {$offset}",
            ARRAY_A
        );

        // Status counts for tabs
        $counts = array(
            'all'       => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
            'enriching' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'enriching'"),
            'ready'     => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'ready'"),
            'contacted' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'contacted'"),
            'converted' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'converted'"),
            'lost'      => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'lost'"),
        );

        $status_labels = array(
            'all'       => 'All',
            'enriching' => 'Enriching',
            'ready'     => 'Ready',
            'contacted' => 'Contacted',
            'converted' => 'Converted',
            'lost'      => 'Lost',
        );

        $status_icons = array(
            'enriching' => '⏳',
            'ready'     => '✅',
            'contacted' => '📧',
            'converted' => '🏆',
            'lost'      => '❌',
        );
?>
        <div class="wrap rw-leads-page">
            <h1 class="rw-page-title">
                <span class="dashicons dashicons-portfolio"></span>
                <?php _e('Lead Files', 'raw-wire-dashboard'); ?>
                <span class="rw-lead-count"><?php echo $counts['ready']; ?> <?php _e('ready', 'raw-wire-dashboard'); ?></span>
            </h1>

            <!-- Status Filter Tabs -->
            <div class="rw-status-tabs">
                <?php foreach ($status_labels as $key => $label) : ?>
                    <a href="<?php echo esc_url(add_query_arg(array('status' => $key, 'paged' => 1))); ?>"
                        class="rw-status-tab <?php echo $status === $key ? 'active' : ''; ?>">
                        <?php echo $label; ?>
                        <span class="rw-tab-count">(<?php echo $counts[$key]; ?>)</span>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Batch Actions -->
            <div class="rw-leads-toolbar">
                <button type="button" class="button rw-enrich-batch-btn" title="<?php esc_attr_e('Process pending enrichments', 'raw-wire-dashboard'); ?>">
                    <span class="dashicons dashicons-update"></span>
                    <?php _e('Enrich Pending', 'raw-wire-dashboard'); ?>
                </button>
                <div class="rw-sort-controls">
                    <label><?php _e('Sort by:', 'raw-wire-dashboard'); ?></label>
                    <select id="rw-leads-sort">
                        <option value="score_composite" <?php selected($sort, 'score_composite'); ?>><?php _e('Score', 'raw-wire-dashboard'); ?></option>
                        <option value="valuation" <?php selected($sort, 'valuation'); ?>><?php _e('Valuation', 'raw-wire-dashboard'); ?></option>
                        <option value="enriched_at" <?php selected($sort, 'enriched_at'); ?>><?php _e('Enriched Date', 'raw-wire-dashboard'); ?></option>
                        <option value="project_name" <?php selected($sort, 'project_name'); ?>><?php _e('Name', 'raw-wire-dashboard'); ?></option>
                    </select>
                </div>
            </div>

            <?php if (empty($leads)) : ?>
                <div class="rw-empty-state">
                    <span class="dashicons dashicons-portfolio" style="font-size:48px;width:48px;height:48px;color:#555;"></span>
                    <h3><?php _e('No leads found', 'raw-wire-dashboard'); ?></h3>
                    <p><?php _e('Approve candidates from the Approvals page to populate leads here.', 'raw-wire-dashboard'); ?></p>
                </div>
            <?php else : ?>
                <div class="rw-leads-grid">
                    <?php foreach ($leads as $lead) : ?>
                        <?php
                        $score   = floatval($lead['score_composite']);
                        $val     = floatval($lead['valuation']);
                        $filing  = json_decode($lead['filing_party_info'], true) ?: array();
                        $contr   = json_decode($lead['contractor_info'], true) ?: array();
                        $arch    = json_decode($lead['architect_info'], true) ?: array();
                        $owner   = json_decode($lead['owner_info'], true) ?: array();
                        $sources = json_decode($lead['research_sources'], true) ?: array();
                        $icon    = $status_icons[$lead['status']] ?? '📋';
                        ?>
                        <div class="rw-lead-card rw-lead-status-<?php echo esc_attr($lead['status']); ?>" data-id="<?php echo esc_attr($lead['id']); ?>">

                            <!-- Card Header -->
                            <div class="rw-lead-header">
                                <div class="rw-lead-title-row">
                                    <span class="rw-lead-icon"><?php echo $icon; ?></span>
                                    <h3 class="rw-lead-name"><?php echo esc_html($lead['project_name'] ?: 'Unnamed Project'); ?></h3>
                                    <span class="rw-lead-score-badge <?php echo $this->score_class($score); ?>">
                                        <?php echo number_format($score, 1); ?>
                                    </span>
                                </div>
                                <div class="rw-lead-meta-row">
                                    <span class="rw-lead-permit">#<?php echo esc_html($lead['permit_nbr']); ?></span>
                                    <span class="rw-lead-address"><?php echo esc_html($lead['primary_address']); ?>, <?php echo esc_html($lead['zip_code']); ?></span>
                                    <span class="rw-lead-type"><?php echo esc_html($lead['permit_type']); ?> — <?php echo esc_html($lead['use_desc']); ?></span>
                                </div>
                                <div class="rw-lead-key-stats">
                                    <span class="rw-stat-pill">
                                        <strong>$<?php echo number_format($val); ?></strong>
                                    </span>
                                    <?php if ($lead['square_footage']) : ?>
                                        <span class="rw-stat-pill"><?php echo number_format($lead['square_footage']); ?> sf</span>
                                    <?php endif; ?>
                                    <?php if ($lead['issue_date']) : ?>
                                        <span class="rw-stat-pill"><?php echo date('M j, Y', strtotime($lead['issue_date'])); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Status / Actions -->
                            <div class="rw-lead-status-bar">
                                <select class="rw-lead-status-select" data-id="<?php echo esc_attr($lead['id']); ?>">
                                    <option value="enriching" <?php selected($lead['status'], 'enriching'); ?>>⏳ Enriching</option>
                                    <option value="ready" <?php selected($lead['status'], 'ready'); ?>>✅ Ready</option>
                                    <option value="contacted" <?php selected($lead['status'], 'contacted'); ?>>📧 Contacted</option>
                                    <option value="converted" <?php selected($lead['status'], 'converted'); ?>>🏆 Converted</option>
                                    <option value="lost" <?php selected($lead['status'], 'lost'); ?>>❌ Lost</option>
                                </select>
                                <?php if ($lead['status'] === 'enriching') : ?>
                                    <button type="button" class="button rw-enrich-single-btn" data-id="<?php echo esc_attr($lead['id']); ?>">
                                        <span class="dashicons dashicons-search"></span>
                                        <?php _e('Enrich Now', 'raw-wire-dashboard'); ?>
                                    </button>
                                <?php endif; ?>
                                <button type="button" class="button rw-lead-expand-btn" data-id="<?php echo esc_attr($lead['id']); ?>">
                                    <span class="dashicons dashicons-arrow-down-alt2"></span>
                                </button>
                            </div>

                            <!-- Expandable Detail Section -->
                            <div class="rw-lead-details" id="rw-lead-details-<?php echo esc_attr($lead['id']); ?>" style="display:none;">

                                <!-- Project Summary -->
                                <?php if ($lead['project_summary']) : ?>
                                    <div class="rw-lead-section">
                                        <h4><span class="dashicons dashicons-text-page"></span> <?php _e('Project Summary', 'raw-wire-dashboard'); ?></h4>
                                        <div class="rw-lead-summary-text"><?php echo wpautop(esc_html($lead['project_summary'])); ?></div>
                                    </div>
                                <?php endif; ?>

                                <!-- Key Parties (2-column grid) -->
                                <div class="rw-lead-parties-grid">
                                    <?php if (! empty($filing['name']) || ! empty($filing['company'])) : ?>
                                        <div class="rw-party-card">
                                            <h5>📋 <?php _e('Filing Party', 'raw-wire-dashboard'); ?></h5>
                                            <?php if (! empty($filing['name'])) : ?>
                                                <p><strong><?php echo esc_html($filing['name']); ?></strong></p>
                                            <?php endif; ?>
                                            <?php if (! empty($filing['company'])) : ?>
                                                <p><?php echo esc_html($filing['company']); ?></p>
                                            <?php endif; ?>
                                            <?php if (! empty($filing['role'])) : ?>
                                                <p class="rw-party-role"><?php echo esc_html($filing['role']); ?></p>
                                            <?php endif; ?>
                                            <?php if (! empty($filing['contact_notes'])) : ?>
                                                <p class="rw-contact-notes"><?php echo esc_html($filing['contact_notes']); ?></p>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (! empty($contr['name']) || ! empty($contr['company'])) : ?>
                                        <div class="rw-party-card">
                                            <h5>🔨 <?php _e('Contractor', 'raw-wire-dashboard'); ?></h5>
                                            <?php if (! empty($contr['name'])) : ?>
                                                <p><strong><?php echo esc_html($contr['name']); ?></strong></p>
                                            <?php endif; ?>
                                            <?php if (! empty($contr['company'])) : ?>
                                                <p><?php echo esc_html($contr['company']); ?></p>
                                            <?php endif; ?>
                                            <?php if (! empty($contr['license'])) : ?>
                                                <p class="rw-party-license">License: <?php echo esc_html($contr['license']); ?></p>
                                            <?php endif; ?>
                                            <?php if (! empty($contr['specialties'])) : ?>
                                                <p class="rw-contact-notes"><?php echo esc_html($contr['specialties']); ?></p>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (! empty($arch['name']) || ! empty($arch['firm'])) : ?>
                                        <div class="rw-party-card">
                                            <h5>📐 <?php _e('Architect', 'raw-wire-dashboard'); ?></h5>
                                            <?php if (! empty($arch['name'])) : ?>
                                                <p><strong><?php echo esc_html($arch['name']); ?></strong></p>
                                            <?php endif; ?>
                                            <?php if (! empty($arch['firm'])) : ?>
                                                <p><?php echo esc_html($arch['firm']); ?></p>
                                            <?php endif; ?>
                                            <?php if (! empty($arch['contact_notes'])) : ?>
                                                <p class="rw-contact-notes"><?php echo esc_html($arch['contact_notes']); ?></p>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (! empty($owner['name']) || ! empty($owner['company'])) : ?>
                                        <div class="rw-party-card">
                                            <h5>🏢 <?php _e('Owner', 'raw-wire-dashboard'); ?></h5>
                                            <?php if (! empty($owner['name'])) : ?>
                                                <p><strong><?php echo esc_html($owner['name']); ?></strong></p>
                                            <?php endif; ?>
                                            <?php if (! empty($owner['company'])) : ?>
                                                <p><?php echo esc_html($owner['company']); ?></p>
                                            <?php endif; ?>
                                            <?php if (! empty($owner['contact_notes'])) : ?>
                                                <p class="rw-contact-notes"><?php echo esc_html($owner['contact_notes']); ?></p>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Location Analysis -->
                                <?php if ($lead['location_analysis']) : ?>
                                    <div class="rw-lead-section">
                                        <h4><span class="dashicons dashicons-location"></span> <?php _e('Location Analysis', 'raw-wire-dashboard'); ?></h4>
                                        <div class="rw-lead-analysis-text"><?php echo wpautop(esc_html($lead['location_analysis'])); ?></div>
                                    </div>
                                <?php endif; ?>

                                <!-- Market Context -->
                                <?php if ($lead['market_context']) : ?>
                                    <div class="rw-lead-section">
                                        <h4><span class="dashicons dashicons-chart-area"></span> <?php _e('Market Context', 'raw-wire-dashboard'); ?></h4>
                                        <div class="rw-lead-analysis-text"><?php echo wpautop(esc_html($lead['market_context'])); ?></div>
                                    </div>
                                <?php endif; ?>

                                <!-- Green Analysis -->
                                <?php if ($lead['green_analysis']) : ?>
                                    <div class="rw-lead-section rw-section-green">
                                        <h4><span class="dashicons dashicons-palmtree"></span> <?php _e('Sustainability Analysis', 'raw-wire-dashboard'); ?></h4>
                                        <div class="rw-lead-analysis-text"><?php echo wpautop(esc_html($lead['green_analysis'])); ?></div>
                                    </div>
                                <?php endif; ?>

                                <!-- Opportunity Notes -->
                                <?php if ($lead['opportunity_notes']) : ?>
                                    <div class="rw-lead-section rw-section-opportunity">
                                        <h4><span class="dashicons dashicons-megaphone"></span> <?php _e('Outreach Strategy', 'raw-wire-dashboard'); ?></h4>
                                        <div class="rw-lead-analysis-text"><?php echo wpautop(esc_html($lead['opportunity_notes'])); ?></div>
                                    </div>
                                <?php endif; ?>

                                <!-- Tags -->
                                <?php if ($lead['tags']) : ?>
                                    <div class="rw-lead-tags">
                                        <?php foreach (explode(',', $lead['tags']) as $tag) : ?>
                                            <span class="rw-lead-tag"><?php echo esc_html(trim($tag)); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Research Sources -->
                                <?php if (! empty($sources)) : ?>
                                    <div class="rw-lead-sources">
                                        <small><?php _e('Sources:', 'raw-wire-dashboard'); ?>
                                            <?php echo esc_html(implode(' • ', $sources)); ?>
                                        </small>
                                    </div>
                                <?php endif; ?>

                                <!-- Timestamps -->
                                <div class="rw-lead-timestamps">
                                    <?php if ($lead['enriched_at']) : ?>
                                        <span><?php _e('Enriched:', 'raw-wire-dashboard'); ?> <?php echo date('M j, Y g:ia', strtotime($lead['enriched_at'])); ?></span>
                                    <?php endif; ?>
                                    <?php if ($lead['status_changed_at']) : ?>
                                        <span><?php _e('Updated:', 'raw-wire-dashboard'); ?> <?php echo date('M j, Y g:ia', strtotime($lead['status_changed_at'])); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination -->
                <?php if ($total > $per) : ?>
                    <div class="tablenav bottom">
                        <div class="tablenav-pages">
                            <?php
                            echo paginate_links(array(
                                'base'    => add_query_arg('paged', '%#%'),
                                'format'  => '',
                                'current' => $page,
                                'total'   => ceil($total / $per),
                            ));
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
<?php
    }

    /**
     * Get CSS class for a score value
     */
    private function score_class($score)
    {
        $s = floatval($score);
        if ($s >= 8)  return 'rw-score-high';
        if ($s >= 6)  return 'rw-score-good';
        if ($s >= 4)  return 'rw-score-mid';
        return 'rw-score-low';
    }
}
