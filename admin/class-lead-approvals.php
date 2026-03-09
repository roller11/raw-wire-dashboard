<?php

/**
 * Lead Generator — Approvals Admin Page
 *
 * Displays scored candidates for client review.
 * Approve → moves to content/enrichment pipeline.
 * Reject → moves to archive.
 *
 * @package RawWire_Dashboard
 * @since   1.0.27
 */

if (! defined('ABSPATH')) {
    exit;
}

class RawWire_Lead_Approvals
{

    /**
     * Render the Approvals page
     */
    public function render()
    {
        global $wpdb;
        $table   = rawwire_leads()->table('candidates');
        $src_table = rawwire_leads()->table('sources');
        $page    = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per     = 20;
        $offset  = ($page - 1) * $per;
        $sort    = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'score_composite';
        $order   = isset($_GET['order']) && $_GET['order'] === 'asc' ? 'ASC' : 'DESC';

        // Filter by status
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'pending';
        $valid_statuses = array('pending', 'approved', 'all');
        if (! in_array($status_filter, $valid_statuses)) {
            $status_filter = 'pending';
        }

        $where = $status_filter === 'all' ? '1=1' : $wpdb->prepare('c.status = %s', $status_filter);

        // Validate sort column
        $valid_sorts = array('score_composite', 'valuation', 'issue_date', 'scored_at', 'permit_type');
        if (! in_array($sort, $valid_sorts)) {
            $sort = 'score_composite';
        }

        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$table} c WHERE {$where}");
        $candidates = $wpdb->get_results(
            "SELECT c.*, 
                    s.investigation_status,
                    s.investigator_summary,
                    s.investigator_confidence,
                    s.party_profiles
             FROM {$table} c 
             LEFT JOIN {$src_table} s ON s.id = c.source_id
             WHERE {$where} ORDER BY c.{$sort} {$order} LIMIT {$per} OFFSET {$offset}",
            ARRAY_A
        );

        // Status counts
        $pending_count  = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'pending'");
        $approved_count = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'approved'");

?>
        <div class="wrap rawwire-lead-approvals">
            <h1 class="rawwire-page-title">
                <span class="dashicons dashicons-yes-alt"></span>
                <?php _e('Lead Approvals', 'raw-wire-dashboard'); ?>
            </h1>

            <!-- Status filter tabs -->
            <ul class="subsubsub">
                <li>
                    <a href="?page=rawwire-lead-approvals&status=pending" class="<?php echo $status_filter === 'pending' ? 'current' : ''; ?>">
                        <?php printf(__('Pending <span class="count">(%s)</span>', 'raw-wire-dashboard'), number_format($pending_count)); ?>
                    </a> |
                </li>
                <li>
                    <a href="?page=rawwire-lead-approvals&status=approved" class="<?php echo $status_filter === 'approved' ? 'current' : ''; ?>">
                        <?php printf(__('Approved <span class="count">(%s)</span>', 'raw-wire-dashboard'), number_format($approved_count)); ?>
                    </a> |
                </li>
                <li>
                    <a href="?page=rawwire-lead-approvals&status=all" class="<?php echo $status_filter === 'all' ? 'current' : ''; ?>">
                        <?php _e('All', 'raw-wire-dashboard'); ?>
                    </a>
                </li>
            </ul>

            <!-- Bulk Actions -->
            <div class="rw-bulk-bar">
                <label>
                    <input type="checkbox" id="rw-select-all">
                    <?php _e('Select All', 'raw-wire-dashboard'); ?>
                </label>
                <select id="rw-bulk-action">
                    <option value=""><?php _e('Bulk Actions', 'raw-wire-dashboard'); ?></option>
                    <option value="approve"><?php _e('Approve Selected', 'raw-wire-dashboard'); ?></option>
                    <option value="reject"><?php _e('Reject Selected', 'raw-wire-dashboard'); ?></option>
                </select>
                <button type="button" class="button" id="rw-apply-bulk">
                    <?php _e('Apply', 'raw-wire-dashboard'); ?>
                </button>
                <span id="rw-bulk-status" class="rw-bulk-status"></span>
            </div>

            <!-- Candidates Grid -->
            <?php if (empty($candidates)) : ?>
                <div class="rw-empty-state">
                    <span class="dashicons dashicons-clipboard"></span>
                    <p><?php _e('No candidates to review. Import and score records to populate this page.', 'raw-wire-dashboard'); ?></p>
                    <a href="?page=rawwire-lead-sources&tab=import" class="button button-primary">
                        <?php _e('Import Records', 'raw-wire-dashboard'); ?>
                    </a>
                </div>
            <?php else : ?>
                <div class="rw-candidates-grid">
                    <?php foreach ($candidates as $c) : ?>
                        <div class="rw-candidate-card" data-id="<?php echo esc_attr($c['id']); ?>">
                            <!-- Header with score -->
                            <div class="rw-candidate-header">
                                <label class="rw-candidate-check">
                                    <input type="checkbox" class="rw-candidate-select" value="<?php echo esc_attr($c['id']); ?>">
                                </label>
                                <div class="rw-candidate-score <?php echo $this->score_class($c['score_composite']); ?>">
                                    <span class="rw-score-num"><?php echo number_format($c['score_composite'], 1); ?></span>
                                    <span class="rw-score-label">/10</span>
                                </div>
                                <div class="rw-candidate-title">
                                    <h4><?php echo esc_html($c['primary_address']); ?></h4>
                                    <span class="rw-candidate-zip"><?php echo esc_html($c['zip_code']); ?></span>
                                </div>
                                <div class="rw-candidate-status">
                                    <span class="rw-badge rw-badge-<?php echo esc_attr($c['status']); ?>">
                                        <?php echo ucfirst($c['status']); ?>
                                    </span>
                                </div>
                            </div>

                            <!-- Key Data -->
                            <div class="rw-candidate-data">
                                <div class="rw-data-row">
                                    <span class="rw-data-label"><?php _e('Permit', 'raw-wire-dashboard'); ?></span>
                                    <code><?php echo esc_html($c['permit_nbr']); ?></code>
                                </div>
                                <div class="rw-data-row">
                                    <span class="rw-data-label"><?php _e('Type', 'raw-wire-dashboard'); ?></span>
                                    <span class="rw-tag"><?php echo esc_html($c['permit_type']); ?></span>
                                    <span class="rw-tag rw-tag-sub"><?php echo esc_html($c['permit_sub_type']); ?></span>
                                </div>
                                <div class="rw-data-row">
                                    <span class="rw-data-label"><?php _e('Use', 'raw-wire-dashboard'); ?></span>
                                    <span><?php echo esc_html($c['use_desc']); ?></span>
                                </div>
                                <div class="rw-data-row">
                                    <span class="rw-data-label"><?php _e('Value', 'raw-wire-dashboard'); ?></span>
                                    <span class="rw-val-highlight">$<?php echo number_format($c['valuation']); ?></span>
                                </div>
                                <?php if ($c['square_footage']) : ?>
                                    <div class="rw-data-row">
                                        <span class="rw-data-label"><?php _e('Sqft', 'raw-wire-dashboard'); ?></span>
                                        <span><?php echo number_format($c['square_footage']); ?> sqft</span>
                                    </div>
                                <?php endif; ?>
                                <div class="rw-data-row">
                                    <span class="rw-data-label"><?php _e('Issued', 'raw-wire-dashboard'); ?></span>
                                    <span><?php echo $c['issue_date'] ? date('M j, Y', strtotime($c['issue_date'])) : '—'; ?></span>
                                </div>
                                <?php if ($c['contractor_name']) : ?>
                                    <div class="rw-data-row">
                                        <span class="rw-data-label"><?php _e('Contractor', 'raw-wire-dashboard'); ?></span>
                                        <span><?php echo esc_html($c['contractor_name']); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($c['applicant_name'] || $c['applicant_business']) : ?>
                                    <div class="rw-data-row">
                                        <span class="rw-data-label"><?php _e('Applicant', 'raw-wire-dashboard'); ?></span>
                                        <span>
                                            <?php echo esc_html($c['applicant_name']); ?>
                                            <?php if ($c['applicant_business']) echo '<br><small>' . esc_html($c['applicant_business']) . '</small>'; ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Work Description -->
                            <?php if ($c['work_desc']) : ?>
                                <div class="rw-candidate-desc">
                                    <strong><?php _e('Work Description:', 'raw-wire-dashboard'); ?></strong>
                                    <p><?php echo esc_html($c['work_desc']); ?></p>
                                </div>
                            <?php endif; ?>

                            <!-- Score Breakdown -->
                            <div class="rw-score-breakdown">
                                <button type="button" class="rw-toggle-scores"><?php _e('Score Breakdown ▾', 'raw-wire-dashboard'); ?></button>
                                <div class="rw-score-details" style="display:none;">
                                    <?php
                                    $score_labels = array(
                                        'score_project_scale'    => 'Scale',
                                        'score_type_fit'         => 'Type Fit',
                                        'score_green_indicators' => 'Green',
                                        'score_timeline'         => 'Timeline',
                                        'score_location'         => 'Location',
                                        'score_use_relevance'    => 'Use',
                                        'score_complexity'       => 'Complex',
                                    );
                                    foreach ($score_labels as $key => $label) :
                                        $val = floatval($c[$key]);
                                    ?>
                                        <div class="rw-score-bar-row">
                                            <span class="rw-score-bar-label"><?php echo $label; ?></span>
                                            <div class="rw-score-bar-wrap">
                                                <div class="rw-score-bar <?php echo $this->score_class($val); ?>"
                                                    style="width:<?php echo ($val / 10) * 100; ?>%"></div>
                                            </div>
                                            <span class="rw-score-bar-val"><?php echo number_format($val, 1); ?></span>
                                        </div>
                                    <?php endforeach; ?>

                                    <?php if ($c['score_reasoning']) : ?>
                                        <div class="rw-reasoning">
                                            <pre><?php echo esc_html($c['score_reasoning']); ?></pre>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Green / Sustainability Indicators -->
                            <?php if ($c['solar'] === 'Y' || $c['ev'] === 'Y' || floatval($c['score_green_indicators']) >= 5) : ?>
                                <div class="rw-green-badges">
                                    <?php if ($c['solar'] === 'Y') : ?>
                                        <span class="rw-green-badge rw-badge-solar">☀ Solar</span>
                                    <?php endif; ?>
                                    <?php if ($c['ev'] === 'Y') : ?>
                                        <span class="rw-green-badge rw-badge-ev">⚡ EV</span>
                                    <?php endif; ?>
                                    <?php if (floatval($c['score_green_indicators']) >= 7) : ?>
                                        <span class="rw-green-badge rw-badge-green">🌿 High Green Score</span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Actions -->
                            <?php if ($c['status'] === 'pending') : ?>
                                <div class="rw-candidate-actions">
                                    <button type="button" class="button button-primary rw-approve-btn" data-id="<?php echo esc_attr($c['id']); ?>">
                                        <span class="dashicons dashicons-thumbs-up"></span>
                                        <?php _e('Approve', 'raw-wire-dashboard'); ?>
                                    </button>
                                    <button type="button" class="button rw-reject-btn" data-id="<?php echo esc_attr($c['id']); ?>">
                                        <span class="dashicons dashicons-thumbs-down"></span>
                                        <?php _e('Reject', 'raw-wire-dashboard'); ?>
                                    </button>
                                </div>
                            <?php endif; ?>

                            <!-- Investigation Status & Intel Summary -->
                            <div class="rw-card-investigation">
                                <?php
                                $inv_status = $c['investigation_status'] ?? 'pending';
                                $inv_class = 'rw-inv-' . esc_attr($inv_status);
                                $inv_confidence = $c['investigator_confidence'] ?? null;
                                $inv_summary = $c['investigator_summary'] ?? '';
                                $party_profiles = $c['party_profiles'] ? json_decode($c['party_profiles'], true) : null;
                                ?>
                                <span class="rw-inv-badge <?php echo $inv_class; ?>">
                                    <?php _e('Investigation:', 'raw-wire-dashboard'); ?> <?php echo esc_html(ucfirst($inv_status)); ?>
                                    <?php if ($inv_confidence !== null) : ?>
                                        <span class="rw-inv-confidence">(<?php echo intval($inv_confidence); ?>% confidence)</span>
                                    <?php endif; ?>
                                </span>
                                <?php if ($inv_status === 'pending' || $inv_status === 'failed') : ?>
                                    <button type="button" class="button rw-investigate-btn" data-id="<?php echo esc_attr($c['id']); ?>" data-source="<?php echo esc_attr($c['source_id']); ?>">
                                        <span class="dashicons dashicons-search"></span> <?php _e('Investigate', 'raw-wire-dashboard'); ?>
                                    </button>
                                <?php endif; ?>

                                <?php if ($inv_summary) : ?>
                                    <div class="rw-inv-summary">
                                        <strong><?php _e('Intel Summary:', 'raw-wire-dashboard'); ?></strong>
                                        <p><?php echo esc_html($inv_summary); ?></p>
                                    </div>
                                <?php endif; ?>

                                <?php if ($party_profiles && is_array($party_profiles)) : ?>
                                    <div class="rw-inv-parties">
                                        <strong><?php _e('Discovered Parties:', 'raw-wire-dashboard'); ?></strong>
                                        <ul class="rw-party-list">
                                            <?php foreach ($party_profiles as $party_type => $party_data) :
                                                // Handle multiple profile formats
                                                $name = '';
                                                $contact_info = array();
                                                if (is_array($party_data)) {
                                                    $profile = $party_data['profile'] ?? $party_data;
                                                    $name = $party_data['name'] ?? $profile['name'] ?? $profile['company_name'] ?? '';
                                                    if (!empty($profile['email'])) $contact_info[] = $profile['email'];
                                                    if (!empty($profile['phone'])) $contact_info[] = $profile['phone'];
                                                    if (!empty($profile['website'])) $contact_info[] = $profile['website'];
                                                }
                                            ?>
                                                <li>
                                                    <span class="rw-party-type"><?php echo esc_html(ucfirst(str_replace('_', ' ', $party_type))); ?>:</span>
                                                    <span class="rw-party-name"><?php echo esc_html($name ?: '(unnamed)'); ?></span>
                                                    <?php if ($contact_info) : ?>
                                                        <span class="rw-party-contact"><?php echo esc_html(implode(' | ', $contact_info)); ?></span>
                                                    <?php endif; ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>
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
