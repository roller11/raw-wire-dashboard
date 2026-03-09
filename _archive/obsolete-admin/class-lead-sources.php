<?php

/**
 * Lead Generator — Sources Admin Page
 *
 * Import controls, pipeline dashboard, data source browser,
 * scoring weight configuration, and settings.
 *
 * @package RawWire_Dashboard
 * @since   1.0.27
 */

if (! defined('ABSPATH')) {
    exit;
}

class RawWire_Lead_Sources
{

    /**
     * Render the Sources page
     */
    public function render()
    {
        $leads    = rawwire_leads();
        $settings = $leads->get_settings();
        $weights  = $leads->get_weights();
        $stats    = $leads->get_stats();
        $datasets = RawWire_Socrata_Client::get_datasets();
        $tab      = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'import';

?>
        <div class="wrap rawwire-lead-sources">
            <h1 class="rawwire-page-title">
                <span class="dashicons dashicons-filter"></span>
                <?php _e('Lead Generator', 'raw-wire-dashboard'); ?>
            </h1>

            <nav class="nav-tab-wrapper rawwire-tabs">
                <a href="?page=rawwire-lead-sources&tab=import" class="nav-tab <?php echo $tab === 'import' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-download"></span> <?php _e('Import', 'raw-wire-dashboard'); ?>
                </a>
                <a href="?page=rawwire-lead-sources&tab=dashboard" class="nav-tab <?php echo $tab === 'dashboard' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-dashboard"></span> <?php _e('Pipeline', 'raw-wire-dashboard'); ?>
                </a>
                <a href="?page=rawwire-lead-sources&tab=records" class="nav-tab <?php echo $tab === 'records' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-database"></span> <?php _e('Records', 'raw-wire-dashboard'); ?>
                </a>
                <a href="?page=rawwire-lead-sources&tab=scoring" class="nav-tab <?php echo $tab === 'scoring' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-chart-bar"></span> <?php _e('Scoring', 'raw-wire-dashboard'); ?>
                </a>
                <a href="?page=rawwire-lead-sources&tab=settings" class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-admin-generic"></span> <?php _e('Settings', 'raw-wire-dashboard'); ?>
                </a>
            </nav>

            <div class="rawwire-tab-content">
                <?php
                switch ($tab) {
                    case 'import':
                        $this->render_import_tab($settings, $datasets);
                        break;
                    case 'records':
                        $this->render_records_tab();
                        break;
                    case 'scoring':
                        $this->render_scoring_tab($weights, $settings);
                        break;
                    case 'settings':
                        $this->render_settings_tab($settings);
                        break;
                    default:
                        $this->render_dashboard_tab($stats);
                        break;
                }
                ?>
            </div>
        </div>
    <?php
    }

    /**
     * Pipeline Dashboard
     */
    private function render_dashboard_tab($stats)
    {
    ?>
        <div class="rw-lead-dashboard">
            <!-- Pipeline Flow Animation -->
            <div class="rw-pipeline-header">
                <?php echo RawWire_Animated_SVG::pipeline_flow(600, 40, '#f4b41a'); ?>
            </div>

            <!-- Pipeline Stats Cards -->
            <div class="rw-pipeline-stats">
                <div class="rw-stat-card rw-stat-sources">
                    <div class="rw-stat-icon"><?php echo RawWire_Animated_SVG::database_active(36, '#3498db'); ?></div>
                    <div class="rw-stat-body">
                        <div class="rw-stat-number"><?php echo number_format($stats['sources']['total']); ?></div>
                        <div class="rw-stat-label"><?php _e('Source Records', 'raw-wire-dashboard'); ?></div>
                        <div class="rw-stat-detail">
                            <?php printf(__('%d unprocessed', 'raw-wire-dashboard'), $stats['sources']['unprocessed']); ?>
                        </div>
                    </div>
                </div>

                <div class="rw-stat-arrow"><span class="dashicons dashicons-arrow-right-alt"></span></div>

                <div class="rw-stat-card rw-stat-candidates">
                    <div class="rw-stat-icon"><?php echo RawWire_Animated_SVG::checkmark(36, '#f39c12'); ?></div>
                    <div class="rw-stat-body">
                        <div class="rw-stat-number"><?php echo number_format($stats['candidates']['total']); ?></div>
                        <div class="rw-stat-label"><?php _e('Candidates', 'raw-wire-dashboard'); ?></div>
                        <div class="rw-stat-detail">
                            <?php printf(__('%d pending review', 'raw-wire-dashboard'), $stats['candidates']['pending']); ?>
                        </div>
                    </div>
                </div>

                <div class="rw-stat-arrow"><span class="dashicons dashicons-arrow-right-alt"></span></div>

                <div class="rw-stat-card rw-stat-leads">
                    <div class="rw-stat-icon"><?php echo RawWire_Animated_SVG::green_leaf(36, '#27ae60'); ?></div>
                    <div class="rw-stat-body">
                        <div class="rw-stat-number"><?php echo number_format($stats['leads']['total']); ?></div>
                        <div class="rw-stat-label"><?php _e('Active Leads', 'raw-wire-dashboard'); ?></div>
                        <div class="rw-stat-detail">
                            <?php printf(
                                __('%d ready · %d contacted', 'raw-wire-dashboard'),
                                $stats['leads']['ready'],
                                $stats['leads']['contacted']
                            ); ?>
                        </div>
                    </div>
                </div>

                <div class="rw-stat-card rw-stat-archive">
                    <div class="rw-stat-icon"><?php echo RawWire_Animated_SVG::construction(36, '#9b59b6'); ?></div>
                    <div class="rw-stat-body">
                        <div class="rw-stat-number"><?php echo number_format($stats['archive']['total']); ?></div>
                        <div class="rw-stat-label"><?php _e('Archived', 'raw-wire-dashboard'); ?></div>
                    </div>
                </div>
            </div>

            <!-- Pipeline Actions -->
            <div class="rw-pipeline-actions">
                <h3><?php _e('Pipeline Actions', 'raw-wire-dashboard'); ?></h3>
                <div class="rw-action-buttons">
                    <?php
                    // Permit pipeline status
                    $pipeline_stats = [];
                    if (function_exists('rawwire_permit_pipeline')) {
                        $pipeline = rawwire_permit_pipeline();
                        $pipeline_stats = [
                            'pending' => $pipeline->count_pending_permits(),
                            'scraped' => $pipeline->count_scraped_permits(),
                        ];
                    }
                    ?>

                    <?php if (!empty($pipeline_stats['pending']) && $pipeline_stats['pending'] > 0) : ?>
                        <button type="button" class="button button-primary rw-lead-action" data-action="rw_run_permit_pipeline" data-confirm="Run pipeline for <?php echo $pipeline_stats['pending']; ?> permits? This will scrape LADBS for contractor data.">
                            <span class="dashicons dashicons-update"></span>
                            <?php printf(__('Run Pipeline (%d pending)', 'raw-wire-dashboard'), $pipeline_stats['pending']); ?>
                        </button>
                    <?php endif; ?>

                    <?php if ($stats['sources']['unprocessed'] > 0) : ?>
                        <button type="button" class="button button-primary rw-lead-action" data-action="rawwire_lead_score" data-confirm="Score <?php echo $stats['sources']['unprocessed']; ?> unprocessed records?">
                            <span class="dashicons dashicons-chart-bar"></span>
                            <?php printf(__('Score %d Records', 'raw-wire-dashboard'), $stats['sources']['unprocessed']); ?>
                        </button>
                    <?php endif; ?>

                    <?php if ($stats['leads']['enriching'] > 0) : ?>
                        <button type="button" class="button rw-lead-action" data-action="rawwire_lead_enrich" data-confirm="Enrich <?php echo $stats['leads']['enriching']; ?> leads?">
                            <span class="dashicons dashicons-search"></span>
                            <?php printf(__('Enrich %d Leads', 'raw-wire-dashboard'), $stats['leads']['enriching']); ?>
                        </button>
                    <?php endif; ?>

                    <a href="?page=rawwire-lead-sources&tab=import" class="button">
                        <span class="dashicons dashicons-download"></span>
                        <?php _e('Import New Records', 'raw-wire-dashboard'); ?>
                    </a>
                </div>
            </div>

            <!-- Conversion Funnel -->
            <?php if ($stats['leads']['total'] > 0) : ?>
                <div class="rw-lead-funnel">
                    <h3><?php _e('Lead Conversion', 'raw-wire-dashboard'); ?></h3>
                    <div class="rw-funnel-bars">
                        <?php
                        $lead_statuses = array(
                            'ready'     => array('label' => 'Ready',     'color' => '#3498db'),
                            'contacted' => array('label' => 'Contacted', 'color' => '#f39c12'),
                            'converted' => array('label' => 'Converted', 'color' => '#27ae60'),
                        );
                        $max = max(1, $stats['leads']['ready'] + $stats['leads']['contacted'] + $stats['leads']['converted']);
                        foreach ($lead_statuses as $key => $info) :
                            $count = $stats['leads'][$key];
                            $pct   = round(($count / $max) * 100);
                        ?>
                            <div class="rw-funnel-row">
                                <span class="rw-funnel-label"><?php echo $info['label']; ?></span>
                                <div class="rw-funnel-bar-wrap">
                                    <div class="rw-funnel-bar" style="width:<?php echo $pct; ?>%;background:<?php echo $info['color']; ?>"></div>
                                </div>
                                <span class="rw-funnel-count"><?php echo $count; ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php
    }

    /**
     * Import Tab — Full Socrata parameter selection matrix
     */
    private function render_import_tab($settings, $datasets)
    {
        // Get record counts for summary
        global $wpdb;
        $sources_table = rawwire_leads()->table('sources');
        $total_records = $wpdb->get_var("SELECT COUNT(*) FROM {$sources_table}");
        $unprocessed   = $wpdb->get_var("SELECT COUNT(*) FROM {$sources_table} WHERE processed = 0");
        $last_import   = $wpdb->get_var("SELECT MAX(imported_at) FROM {$sources_table}");

        // Dataset feature availability matrix
        $dataset_features = wp_json_encode(array(
            'pi9x-tg5x' => array('contractor' => false, 'solar' => true, 'ev' => true, 'submitted_date' => true, 'cofo_date' => true, 'height' => true, 'construction' => true),
            'xnhu-aczu' => array('contractor' => true, 'solar' => false, 'ev' => false, 'submitted_date' => false, 'cofo_date' => false, 'height' => false, 'construction' => false),
            'gwh9-jnip' => array('contractor' => false, 'solar' => true, 'ev' => true, 'submitted_date' => true, 'cofo_date' => false, 'height' => false, 'construction' => false),
            '9yda-i4ya' => array('contractor' => false, 'solar' => false, 'ev' => false, 'submitted_date' => false, 'cofo_date' => false, 'height' => false, 'construction' => false),
            'hhzr-9ttq' => array('contractor' => false, 'solar' => false, 'ev' => false, 'submitted_date' => false, 'cofo_date' => false, 'height' => false, 'construction' => false),
            'n3m5-kxzy' => array('contractor' => false, 'solar' => false, 'ev' => false, 'submitted_date' => false, 'cofo_date' => false, 'height' => false, 'construction' => false),
        ));
    ?>
        <div class="rw-lead-import">
            <!-- Records Summary -->
            <div class="rw-import-summary">
                <div class="rw-summary-stat">
                    <span class="rw-summary-number"><?php echo number_format($total_records); ?></span>
                    <span class="rw-summary-label"><?php _e('Total Records', 'raw-wire-dashboard'); ?></span>
                </div>
                <div class="rw-summary-stat">
                    <span class="rw-summary-number"><?php echo number_format($unprocessed); ?></span>
                    <span class="rw-summary-label"><?php _e('Unprocessed', 'raw-wire-dashboard'); ?></span>
                </div>
                <div class="rw-summary-stat">
                    <span class="rw-summary-number"><?php echo $last_import ? human_time_diff(strtotime($last_import)) . ' ago' : '—'; ?></span>
                    <span class="rw-summary-label"><?php _e('Last Import', 'raw-wire-dashboard'); ?></span>
                </div>
            </div>

            <div class="rw-import-grid">
                <div class="rw-card rw-import-form">
                    <h3><span class="dashicons dashicons-download"></span> <?php _e('Socrata Import Configuration', 'raw-wire-dashboard'); ?></h3>

                    <!-- ═══════════════════════════════════════════════════════
                         SECTION 1: DATA SOURCE
                         ═══════════════════════════════════════════════════════ -->
                    <div class="rw-matrix-section">
                        <div class="rw-matrix-header">
                            <span class="dashicons dashicons-database"></span>
                            <?php _e('Data Source', 'raw-wire-dashboard'); ?>
                        </div>

                        <div class="rw-form-group">
                            <label for="rw-import-dataset"><?php _e('Dataset', 'raw-wire-dashboard'); ?></label>
                            <select id="rw-import-dataset" name="dataset">
                                <?php foreach ($datasets as $id => $ds) : ?>
                                    <option value="<?php echo esc_attr($id); ?>" <?php selected($id, $settings['primary_dataset']); ?>
                                        data-desc="<?php echo esc_attr($ds['desc']); ?>">
                                        <?php echo esc_html($ds['name']); ?> (<?php echo esc_html($id); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="rw-field-desc" id="rw-dataset-desc">
                                <?php echo esc_html($datasets[$settings['primary_dataset']]['desc'] ?? ''); ?>
                            </p>
                        </div>
                    </div>

                    <!-- ═══════════════════════════════════════════════════════
                         SECTION 2: PERMIT TYPE MATRIX
                         ═══════════════════════════════════════════════════════ -->
                    <div class="rw-matrix-section">
                        <div class="rw-matrix-header">
                            <span class="dashicons dashicons-hammer"></span>
                            <?php _e('Permit Type Filters', 'raw-wire-dashboard'); ?>
                            <span class="rw-matrix-toggle" data-target="rw-permit-matrix">
                                <span class="dashicons dashicons-arrow-down-alt2"></span>
                            </span>
                        </div>

                        <div class="rw-matrix-body" id="rw-permit-matrix">
                            <div class="rw-form-row">
                                <div class="rw-form-group rw-half">
                                    <label><?php _e('Permit Types', 'raw-wire-dashboard'); ?>
                                        <a href="#" class="rw-select-toggle" data-group="permit_types"><?php _e('all / none', 'raw-wire-dashboard'); ?></a>
                                    </label>
                                    <div class="rw-checkbox-grid">
                                        <?php
                                        $all_types = array(
                                            'Bldg-New'            => 'New Construction',
                                            'Bldg-Alter/Repair'   => 'Alteration / Repair',
                                            'Bldg-Addition'       => 'Addition',
                                            'Bldg-Demolition'     => 'Demolition',
                                            'Grading'             => 'Grading',
                                            'Non-Bldg'            => 'Non-Building',
                                            'Mech'                => 'Mechanical',
                                            'Elec'                => 'Electrical',
                                            'Plbg'                => 'Plumbing',
                                            'Fire Sprinkler'      => 'Fire Sprinkler',
                                            'Sign'                => 'Sign',
                                            'Pool/Spa'            => 'Pool / Spa',
                                            'Retaining Wall'      => 'Retaining Wall',
                                        );
                                        foreach ($all_types as $val => $label) :
                                            $checked = in_array($val, $settings['permit_types']) ? 'checked' : '';
                                        ?>
                                            <label class="rw-checkbox-label">
                                                <input type="checkbox" name="permit_types[]" value="<?php echo esc_attr($val); ?>" <?php echo $checked; ?>>
                                                <span class="rw-cb-text"><?php echo esc_html($label); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div class="rw-form-group rw-half">
                                    <label><?php _e('Sub-Types', 'raw-wire-dashboard'); ?>
                                        <a href="#" class="rw-select-toggle" data-group="permit_sub_types"><?php _e('all / none', 'raw-wire-dashboard'); ?></a>
                                    </label>
                                    <div class="rw-checkbox-grid">
                                        <?php
                                        $all_subs = array(
                                            'Commercial'              => 'Commercial',
                                            'Apartment'               => 'Apartment',
                                            '1 or 2 Family Dwelling'  => '1-2 Family Dwelling',
                                            'Onsite'                  => 'Onsite',
                                        );
                                        foreach ($all_subs as $val => $label) :
                                            $checked = in_array($val, $settings['permit_sub_types']) ? 'checked' : '';
                                        ?>
                                            <label class="rw-checkbox-label">
                                                <input type="checkbox" name="permit_sub_types[]" value="<?php echo esc_attr($val); ?>" <?php echo $checked; ?>>
                                                <span class="rw-cb-text"><?php echo esc_html($label); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══════════════════════════════════════════════════════
                         SECTION 3: VALUE & SCALE
                         ═══════════════════════════════════════════════════════ -->
                    <div class="rw-matrix-section">
                        <div class="rw-matrix-header">
                            <span class="dashicons dashicons-money-alt"></span>
                            <?php _e('Value & Scale', 'raw-wire-dashboard'); ?>
                        </div>

                        <div class="rw-matrix-body">
                            <div class="rw-form-row">
                                <div class="rw-form-group rw-third">
                                    <label for="rw-import-min-val"><?php _e('Min Valuation ($)', 'raw-wire-dashboard'); ?></label>
                                    <input type="number" id="rw-import-min-val" name="min_valuation"
                                        value="<?php echo esc_attr($settings['min_valuation']); ?>" step="10000" min="0">
                                </div>
                                <div class="rw-form-group rw-third">
                                    <label for="rw-import-max-val"><?php _e('Max Valuation ($)', 'raw-wire-dashboard'); ?></label>
                                    <input type="number" id="rw-import-max-val" name="max_valuation"
                                        value="" step="10000" min="0" placeholder="No limit">
                                </div>
                                <div class="rw-form-group rw-third">
                                    <label for="rw-import-min-sqft"><?php _e('Min Sq Footage', 'raw-wire-dashboard'); ?></label>
                                    <input type="number" id="rw-import-min-sqft" name="min_sqft"
                                        value="" step="500" min="0" placeholder="Any">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══════════════════════════════════════════════════════
                         SECTION 4: DATE & RECENCY
                         ═══════════════════════════════════════════════════════ -->
                    <div class="rw-matrix-section">
                        <div class="rw-matrix-header">
                            <span class="dashicons dashicons-calendar-alt"></span>
                            <?php _e('Date & Recency', 'raw-wire-dashboard'); ?>
                        </div>

                        <div class="rw-matrix-body">
                            <div class="rw-form-row">
                                <div class="rw-form-group rw-third">
                                    <label for="rw-import-date-range"><?php _e('Date Range', 'raw-wire-dashboard'); ?></label>
                                    <select id="rw-import-date-range" name="date_range_months">
                                        <?php
                                        $ranges = array(
                                            1  => 'Last 1 Month',
                                            2  => 'Last 2 Months',
                                            3  => 'Last 3 Months',
                                            6  => 'Last 6 Months',
                                            9  => 'Last 9 Months',
                                            12 => 'Last 12 Months',
                                            18 => 'Last 18 Months',
                                            24 => 'Last 2 Years',
                                            36 => 'Last 3 Years',
                                            0  => 'All Time (no filter)',
                                        );
                                        foreach ($ranges as $months => $label) :
                                        ?>
                                            <option value="<?php echo esc_attr($months); ?>" <?php selected($months, $settings['date_range_months']); ?>>
                                                <?php echo esc_html($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="rw-form-group rw-third">
                                    <label for="rw-import-sort-by"><?php _e('Sort By', 'raw-wire-dashboard'); ?></label>
                                    <select id="rw-import-sort-by" name="sort_by">
                                        <option value="issue_date" selected><?php _e('Issue Date', 'raw-wire-dashboard'); ?></option>
                                        <option value="submitted_date"><?php _e('Submitted Date', 'raw-wire-dashboard'); ?></option>
                                        <option value="valuation"><?php _e('Valuation', 'raw-wire-dashboard'); ?></option>
                                        <option value="status_date"><?php _e('Status Date', 'raw-wire-dashboard'); ?></option>
                                    </select>
                                </div>
                                <div class="rw-form-group rw-third">
                                    <label for="rw-import-sort-order"><?php _e('Sort Order', 'raw-wire-dashboard'); ?></label>
                                    <select id="rw-import-sort-order" name="sort_order">
                                        <option value="DESC" selected><?php _e('Newest First', 'raw-wire-dashboard'); ?></option>
                                        <option value="ASC"><?php _e('Oldest First', 'raw-wire-dashboard'); ?></option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══════════════════════════════════════════════════════
                         SECTION 5: LOCATION FILTERS
                         ═══════════════════════════════════════════════════════ -->
                    <div class="rw-matrix-section">
                        <div class="rw-matrix-header">
                            <span class="dashicons dashicons-location"></span>
                            <?php _e('Location Filters', 'raw-wire-dashboard'); ?>
                            <span class="rw-matrix-toggle" data-target="rw-location-matrix">
                                <span class="dashicons dashicons-arrow-down-alt2"></span>
                            </span>
                        </div>

                        <div class="rw-matrix-body rw-collapsed" id="rw-location-matrix">
                            <div class="rw-form-row">
                                <div class="rw-form-group rw-half">
                                    <label for="rw-import-zip"><?php _e('Zip Code(s)', 'raw-wire-dashboard'); ?></label>
                                    <input type="text" id="rw-import-zip" name="zip_codes"
                                        placeholder="<?php esc_attr_e('e.g., 90012, 90001, 90071', 'raw-wire-dashboard'); ?>">
                                    <p class="rw-field-desc"><?php _e('Comma-separated. Leave blank for all.', 'raw-wire-dashboard'); ?></p>
                                </div>
                                <div class="rw-form-group rw-half">
                                    <label for="rw-import-cd"><?php _e('Council District(s)', 'raw-wire-dashboard'); ?></label>
                                    <input type="text" id="rw-import-cd" name="council_districts"
                                        placeholder="<?php esc_attr_e('e.g., 1, 8, 14', 'raw-wire-dashboard'); ?>">
                                    <p class="rw-field-desc"><?php _e('LA City council districts 1-15.', 'raw-wire-dashboard'); ?></p>
                                </div>
                            </div>

                            <div class="rw-form-row">
                                <div class="rw-form-group rw-half">
                                    <label for="rw-import-cpa"><?php _e('Community Plan Area', 'raw-wire-dashboard'); ?></label>
                                    <input type="text" id="rw-import-cpa" name="community_plan_area"
                                        placeholder="<?php esc_attr_e('e.g., Hollywood, Westwood', 'raw-wire-dashboard'); ?>">
                                    <p class="rw-field-desc"><?php _e('Partial match. Leave blank for all.', 'raw-wire-dashboard'); ?></p>
                                </div>
                                <div class="rw-form-group rw-half">
                                    <label for="rw-import-zone"><?php _e('Zone', 'raw-wire-dashboard'); ?></label>
                                    <input type="text" id="rw-import-zone" name="zone"
                                        placeholder="<?php esc_attr_e('e.g., C2, R3, M1', 'raw-wire-dashboard'); ?>">
                                    <p class="rw-field-desc"><?php _e('Zoning code filter.', 'raw-wire-dashboard'); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══════════════════════════════════════════════════════
                         SECTION 6: BUILDING USE & STATUS
                         ═══════════════════════════════════════════════════════ -->
                    <div class="rw-matrix-section">
                        <div class="rw-matrix-header">
                            <span class="dashicons dashicons-building"></span>
                            <?php _e('Building Use & Status', 'raw-wire-dashboard'); ?>
                            <span class="rw-matrix-toggle" data-target="rw-use-matrix">
                                <span class="dashicons dashicons-arrow-down-alt2"></span>
                            </span>
                        </div>

                        <div class="rw-matrix-body rw-collapsed" id="rw-use-matrix">
                            <div class="rw-form-row">
                                <div class="rw-form-group rw-half">
                                    <label for="rw-import-use-desc"><?php _e('Use Description Contains', 'raw-wire-dashboard'); ?></label>
                                    <input type="text" id="rw-import-use-desc" name="use_desc_search"
                                        placeholder="<?php esc_attr_e('e.g., Office, Hotel, Restaurant', 'raw-wire-dashboard'); ?>">
                                    <p class="rw-field-desc"><?php _e('Search within use description field.', 'raw-wire-dashboard'); ?></p>
                                </div>
                                <div class="rw-form-group rw-half">
                                    <label for="rw-import-use-code"><?php _e('Use Code', 'raw-wire-dashboard'); ?></label>
                                    <input type="text" id="rw-import-use-code" name="use_code"
                                        placeholder="<?php esc_attr_e('e.g., 0300, 0500, 0600', 'raw-wire-dashboard'); ?>">
                                    <p class="rw-field-desc"><?php _e('Comma-separated LADBS use codes.', 'raw-wire-dashboard'); ?></p>
                                </div>
                            </div>

                            <div class="rw-form-row">
                                <div class="rw-form-group rw-half">
                                    <label for="rw-import-work-desc"><?php _e('Work Description Contains', 'raw-wire-dashboard'); ?></label>
                                    <input type="text" id="rw-import-work-desc" name="work_desc_search"
                                        placeholder="<?php esc_attr_e('e.g., LEED, sustainable, solar', 'raw-wire-dashboard'); ?>">
                                    <p class="rw-field-desc"><?php _e('Full-text keyword search on scope of work.', 'raw-wire-dashboard'); ?></p>
                                </div>
                                <div class="rw-form-group rw-half">
                                    <label for="rw-import-status"><?php _e('Permit Status', 'raw-wire-dashboard'); ?></label>
                                    <select id="rw-import-status" name="status_filter">
                                        <option value=""><?php _e('All Statuses', 'raw-wire-dashboard'); ?></option>
                                        <option value="Permit Issued"><?php _e('Permit Issued', 'raw-wire-dashboard'); ?></option>
                                        <option value="Permit Finaled"><?php _e('Permit Finaled', 'raw-wire-dashboard'); ?></option>
                                        <option value="CofO Issued"><?php _e('CofO Issued', 'raw-wire-dashboard'); ?></option>
                                        <option value="Plan Check"><?php _e('Plan Check', 'raw-wire-dashboard'); ?></option>
                                        <option value="Ready to Issue"><?php _e('Ready to Issue', 'raw-wire-dashboard'); ?></option>
                                        <option value="Corrections Issued"><?php _e('Corrections Issued', 'raw-wire-dashboard'); ?></option>
                                        <option value="Submitted"><?php _e('Submitted', 'raw-wire-dashboard'); ?></option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══════════════════════════════════════════════════════
                         SECTION 7: GREEN & SPECIALTY FEATURES
                         ═══════════════════════════════════════════════════════ -->
                    <div class="rw-matrix-section" id="rw-green-section">
                        <div class="rw-matrix-header">
                            <span class="dashicons dashicons-admin-site-alt3"></span>
                            <?php _e('Green & Specialty Features', 'raw-wire-dashboard'); ?>
                        </div>

                        <div class="rw-matrix-body">
                            <div class="rw-form-row">
                                <div class="rw-form-group rw-third">
                                    <label class="rw-checkbox-label">
                                        <input type="checkbox" id="rw-import-solar" name="solar_only" value="1">
                                        <span class="rw-cb-text"><?php _e('Solar Permits Only', 'raw-wire-dashboard'); ?></span>
                                    </label>
                                    <p class="rw-field-desc"><?php _e('Only permits flagged with solar.', 'raw-wire-dashboard'); ?></p>
                                </div>
                                <div class="rw-form-group rw-third">
                                    <label class="rw-checkbox-label">
                                        <input type="checkbox" id="rw-import-ev" name="ev_only" value="1">
                                        <span class="rw-cb-text"><?php _e('EV Charging Only', 'raw-wire-dashboard'); ?></span>
                                    </label>
                                    <p class="rw-field-desc"><?php _e('Only permits flagged with EV charging.', 'raw-wire-dashboard'); ?></p>
                                </div>
                                <div class="rw-form-group rw-third">
                                    <label for="rw-import-construction"><?php _e('Construction Type', 'raw-wire-dashboard'); ?></label>
                                    <input type="text" id="rw-import-construction" name="construction_type"
                                        placeholder="<?php esc_attr_e('e.g., V-B, I-A', 'raw-wire-dashboard'); ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══════════════════════════════════════════════════════
                         SECTION 8: CONTRACTOR FILTERS
                         ═══════════════════════════════════════════════════════ -->
                    <div class="rw-matrix-section" id="rw-contractor-section">
                        <div class="rw-matrix-header">
                            <span class="dashicons dashicons-businessperson"></span>
                            <?php _e('Contractor / Party Filters', 'raw-wire-dashboard'); ?>
                            <span class="rw-matrix-badge rw-badge-dataset"><?php _e('xnhu-aczu only', 'raw-wire-dashboard'); ?></span>
                        </div>

                        <div class="rw-matrix-body">
                            <div class="rw-form-row">
                                <div class="rw-form-group rw-half">
                                    <label class="rw-checkbox-label">
                                        <input type="checkbox" id="rw-import-require-party" name="require_party_data" value="1">
                                        <span class="rw-cb-text"><?php _e('Require Contractor Data', 'raw-wire-dashboard'); ?></span>
                                    </label>
                                    <p class="rw-field-desc"><?php _e('Only records with contractor/applicant info.', 'raw-wire-dashboard'); ?></p>
                                </div>
                                <div class="rw-form-group rw-half">
                                    <label class="rw-checkbox-label">
                                        <input type="checkbox" id="rw-import-exclude-owner" name="exclude_owner_builder" value="1" checked>
                                        <span class="rw-cb-text"><?php _e('Exclude Owner-Builder', 'raw-wire-dashboard'); ?></span>
                                    </label>
                                    <p class="rw-field-desc"><?php _e('Skip OWNER-BUILDER entries (not real leads).', 'raw-wire-dashboard'); ?></p>
                                </div>
                            </div>

                            <div class="rw-form-row">
                                <div class="rw-form-group rw-half">
                                    <label for="rw-import-contractor-name"><?php _e('Contractor Name Contains', 'raw-wire-dashboard'); ?></label>
                                    <input type="text" id="rw-import-contractor-name" name="contractor_name_search"
                                        placeholder="<?php esc_attr_e('e.g., AECOM, Turner', 'raw-wire-dashboard'); ?>">
                                </div>
                                <div class="rw-form-group rw-half">
                                    <label for="rw-import-license"><?php _e('License Number', 'raw-wire-dashboard'); ?></label>
                                    <input type="text" id="rw-import-license" name="license_search"
                                        placeholder="<?php esc_attr_e('Exact license #', 'raw-wire-dashboard'); ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══════════════════════════════════════════════════════
                         SECTION 9: IMPORT CONTROLS & POST-IMPORT
                         ═══════════════════════════════════════════════════════ -->
                    <div class="rw-matrix-section">
                        <div class="rw-matrix-header">
                            <span class="dashicons dashicons-admin-settings"></span>
                            <?php _e('Import Controls', 'raw-wire-dashboard'); ?>
                        </div>

                        <div class="rw-matrix-body">
                            <div class="rw-form-row">
                                <div class="rw-form-group rw-third">
                                    <label for="rw-import-limit"><?php _e('Max Records', 'raw-wire-dashboard'); ?></label>
                                    <input type="number" id="rw-import-limit" name="limit"
                                        value="<?php echo esc_attr($settings['import_limit']); ?>" min="10" max="5000" step="10">
                                </div>
                                <div class="rw-form-group rw-third">
                                    <label class="rw-checkbox-label rw-checkbox-label-top">
                                        <input type="checkbox" id="rw-import-auto-score" name="auto_score" value="1" <?php checked($settings['auto_score']); ?>>
                                        <span class="rw-cb-text"><?php _e('Auto-Score', 'raw-wire-dashboard'); ?></span>
                                    </label>
                                    <p class="rw-field-desc"><?php _e('Score records after import.', 'raw-wire-dashboard'); ?></p>
                                </div>
                                <div class="rw-form-group rw-third">
                                    <label class="rw-checkbox-label rw-checkbox-label-top">
                                        <input type="checkbox" id="rw-import-auto-promote" name="auto_promote" value="1" <?php checked($settings['auto_promote']); ?>>
                                        <span class="rw-cb-text"><?php _e('Auto-Promote', 'raw-wire-dashboard'); ?></span>
                                    </label>
                                    <p class="rw-field-desc"><?php _e('Promote above threshold.', 'raw-wire-dashboard'); ?></p>
                                </div>
                            </div>

                            <div class="rw-form-group">
                                <label for="rw-import-name"><?php _e('Import Batch Name', 'raw-wire-dashboard'); ?></label>
                                <input type="text" id="rw-import-name" name="import_name"
                                    placeholder="<?php esc_attr_e('e.g., LA Commercial > $100K — Feb 2026', 'raw-wire-dashboard'); ?>">
                                <p class="rw-field-desc"><?php _e('Optional label for this batch.', 'raw-wire-dashboard'); ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- ═══════════════════════════════════════════════════════
                         ACTION BUTTONS
                         ═══════════════════════════════════════════════════════ -->
                    <div class="rw-form-actions rw-import-actions-bar">
                        <button type="button" class="button rw-preview-count-btn" id="rw-preview-import">
                            <span class="dashicons dashicons-visibility"></span>
                            <?php _e('Preview Count', 'raw-wire-dashboard'); ?>
                        </button>
                        <span class="rw-preview-result"></span>

                        <button type="button" class="button rw-save-import-defaults-btn" id="rw-save-import-defaults">
                            <span class="dashicons dashicons-saved"></span>
                            <?php _e('Save as Defaults', 'raw-wire-dashboard'); ?>
                        </button>

                        <button type="button" class="button button-primary rw-import-btn" id="rw-import-records">
                            <span class="dashicons dashicons-download"></span>
                            <?php _e('Import Records', 'raw-wire-dashboard'); ?>
                        </button>
                    </div>

                    <div class="rw-import-progress" style="display:none;">
                        <div class="rw-progress-bar-wrap">
                            <div class="rw-progress-bar" id="rw-import-bar"></div>
                        </div>
                        <div class="rw-progress-text" id="rw-import-status"></div>
                    </div>
                </div>

                <!-- Available Datasets sidebar -->
                <div class="rw-card rw-dataset-info">
                    <h3><span class="dashicons dashicons-info-outline"></span> <?php _e('Dataset Reference', 'raw-wire-dashboard'); ?></h3>
                    <div class="rw-dataset-list">
                        <?php foreach ($datasets as $id => $ds) : ?>
                            <div class="rw-dataset-item" data-dataset="<?php echo esc_attr($id); ?>">
                                <strong><?php echo esc_html($ds['name']); ?></strong>
                                <code><?php echo esc_html($id); ?></code>
                                <p><?php echo esc_html($ds['desc']); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="rw-dataset-legend">
                        <h4><?php _e('Field Availability', 'raw-wire-dashboard'); ?></h4>
                        <table class="rw-legend-table">
                            <thead>
                                <tr>
                                    <th><?php _e('Feature', 'raw-wire-dashboard'); ?></th>
                                    <th title="pi9x-tg5x">Issued</th>
                                    <th title="xnhu-aczu">BUILD</th>
                                    <th title="gwh9-jnip">Submitted</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Contractor</td>
                                    <td class="rw-no">&#x2717;</td>
                                    <td class="rw-yes">&#x2713;</td>
                                    <td class="rw-no">&#x2717;</td>
                                </tr>
                                <tr>
                                    <td>Applicant</td>
                                    <td class="rw-no">&#x2717;</td>
                                    <td class="rw-yes">&#x2713;</td>
                                    <td class="rw-no">&#x2717;</td>
                                </tr>
                                <tr>
                                    <td>Solar / EV</td>
                                    <td class="rw-yes">&#x2713;</td>
                                    <td class="rw-no">&#x2717;</td>
                                    <td class="rw-yes">&#x2713;</td>
                                </tr>
                                <tr>
                                    <td>Height</td>
                                    <td class="rw-yes">&#x2713;</td>
                                    <td class="rw-no">&#x2717;</td>
                                    <td class="rw-no">&#x2717;</td>
                                </tr>
                                <tr>
                                    <td>Constr. Type</td>
                                    <td class="rw-yes">&#x2713;</td>
                                    <td class="rw-no">&#x2717;</td>
                                    <td class="rw-no">&#x2717;</td>
                                </tr>
                                <tr>
                                    <td>Submitted Date</td>
                                    <td class="rw-yes">&#x2713;</td>
                                    <td class="rw-no">&#x2717;</td>
                                    <td class="rw-yes">&#x2713;</td>
                                </tr>
                                <tr>
                                    <td>CofO Date</td>
                                    <td class="rw-yes">&#x2713;</td>
                                    <td class="rw-no">&#x2717;</td>
                                    <td class="rw-no">&#x2717;</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <script>
            jQuery(function($) {
                var datasetFeatures = <?php echo $dataset_features; ?>;

                // Toggle collapsible sections
                $(document).on('click', '.rw-matrix-toggle', function(e) {
                    e.preventDefault();
                    var targetId = $(this).data('target');
                    var $target = $('#' + targetId);
                    $target.toggleClass('rw-collapsed');
                    $(this).find('.dashicons').toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-up-alt2');
                });

                // Select all / none toggle for checkbox groups
                $(document).on('click', '.rw-select-toggle', function(e) {
                    e.preventDefault();
                    var group = $(this).data('group');
                    var $boxes = $('input[name="' + group + '[]"]');
                    var allChecked = $boxes.filter(':checked').length === $boxes.length;
                    $boxes.prop('checked', !allChecked);
                });

                // Dataset change: update description + show/hide feature-dependent sections
                $('#rw-import-dataset').on('change', function() {
                    var ds = $(this).val();
                    var desc = $(this).find(':selected').data('desc');
                    $('#rw-dataset-desc').text(desc || '');

                    var features = datasetFeatures[ds] || {};

                    // Contractor section
                    if (features.contractor) {
                        $('#rw-contractor-section').removeClass('rw-section-unavailable');
                    } else {
                        $('#rw-contractor-section').addClass('rw-section-unavailable');
                    }

                    // Highlight active dataset in reference
                    $('.rw-dataset-item').removeClass('rw-dataset-active');
                    $('.rw-dataset-item[data-dataset="' + ds + '"]').addClass('rw-dataset-active');
                }).trigger('change');
            });
        </script>
    <?php
    }

    /**
     * Records Tab — browse imported source records
     */
    private function render_records_tab()
    {
        global $wpdb;
        $table   = rawwire_leads()->table('sources');
        $page    = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per     = 25;
        $offset  = ($page - 1) * $per;
        $total   = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $records = $wpdb->get_results($wpdb->prepare(
            "SELECT id, permit_nbr, primary_address, zip_code, permit_type, permit_sub_type,
                    use_desc, valuation, square_footage, issue_date, contractor_name,
                    processed, import_batch, tags
             FROM {$table}
             ORDER BY imported_at DESC
             LIMIT %d OFFSET %d",
            $per,
            $offset
        ), ARRAY_A);

    ?>
        <div class="rw-lead-records">
            <div class="rw-records-header">
                <span><?php printf(__('%s records', 'raw-wire-dashboard'), number_format($total)); ?></span>
            </div>

            <table class="wp-list-table widefat fixed striped rw-records-table">
                <thead>
                    <tr>
                        <th class="column-permit"><?php _e('Permit #', 'raw-wire-dashboard'); ?></th>
                        <th class="column-address"><?php _e('Address', 'raw-wire-dashboard'); ?></th>
                        <th class="column-type"><?php _e('Type', 'raw-wire-dashboard'); ?></th>
                        <th class="column-use"><?php _e('Use', 'raw-wire-dashboard'); ?></th>
                        <th class="column-value"><?php _e('Valuation', 'raw-wire-dashboard'); ?></th>
                        <th class="column-date"><?php _e('Issued', 'raw-wire-dashboard'); ?></th>
                        <th class="column-status"><?php _e('Status', 'raw-wire-dashboard'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($records)) : ?>
                        <tr>
                            <td colspan="7" class="rw-empty"><?php _e('No records imported yet. Use the Import tab to pull data.', 'raw-wire-dashboard'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($records as $r) : ?>
                            <tr>
                                <td><code><?php echo esc_html($r['permit_nbr']); ?></code></td>
                                <td><?php echo esc_html($r['primary_address']); ?><br><small><?php echo esc_html($r['zip_code']); ?></small></td>
                                <td><span class="rw-tag"><?php echo esc_html($r['permit_type']); ?></span><br>
                                    <span class="rw-tag rw-tag-sub"><?php echo esc_html($r['permit_sub_type']); ?></span>
                                </td>
                                <td><?php echo esc_html($r['use_desc']); ?></td>
                                <td class="rw-val">$<?php echo number_format($r['valuation']); ?></td>
                                <td><?php echo $r['issue_date'] ? date('M j, Y', strtotime($r['issue_date'])) : '—'; ?></td>
                                <td>
                                    <?php if ($r['processed']) : ?>
                                        <span class="rw-badge rw-badge-scored"><?php _e('Scored', 'raw-wire-dashboard'); ?></span>
                                    <?php else : ?>
                                        <span class="rw-badge rw-badge-pending"><?php _e('Pending', 'raw-wire-dashboard'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

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
        </div>
    <?php
    }

    /**
     * Scoring Weights Tab
     */
    private function render_scoring_tab($weights, $settings = array())
    {
        $weight_labels = array(
            'project_scale'    => array('Project Scale',      'Valuation, square footage, building height. Larger projects = more potential.'),
            'type_fit'         => array('Type Fit',           'New construction scores highest, then additions, then renovations.'),
            'green_indicators' => array('Green Indicators',   'Solar, EV, LEED, sustainability keywords in work description. Highest weight for green architect.'),
            'timeline'         => array('Timeline',           'Earlier pipeline stage = more time to approach. Submitted > Issued > Finaled.'),
            'location_premium' => array('Location Premium',   'High-value LA neighborhoods and commercial zones get bonus.'),
            'use_relevance'    => array('Use Relevance',      'Office/hotel/restaurant score high. Warehouse/parking score low.'),
            'complexity'       => array('Complexity',         'Number of stories, construction type, plan check level.'),
            'permit_stage'     => array('Permit Stage',       'Pre-planning and submitted permits get HIGH priority for early engagement before competition.'),
        );
    ?>
        <div class="rw-lead-scoring">
            <div class="rw-card">
                <h3><span class="dashicons dashicons-chart-bar"></span> <?php _e('Scoring Weight Configuration', 'raw-wire-dashboard'); ?></h3>
                <p class="rw-help-text">
                    <?php _e('Adjust weights to prioritize what matters most for your client. Higher weight = more influence on the composite score. Range: 0.0 to 5.0.', 'raw-wire-dashboard'); ?>
                </p>

                <div class="rw-weights-grid" id="rw-weights-form">
                    <?php foreach ($weight_labels as $key => $info) :
                        $val = isset($weights[$key]) ? $weights[$key] : 1.0;
                    ?>
                        <div class="rw-weight-row">
                            <div class="rw-weight-info">
                                <strong><?php echo esc_html($info[0]); ?></strong>
                                <p><?php echo esc_html($info[1]); ?></p>
                            </div>
                            <div class="rw-weight-control">
                                <input type="range" class="rw-weight-slider" data-key="<?php echo esc_attr($key); ?>"
                                    min="0" max="5" step="0.1" value="<?php echo esc_attr($val); ?>">
                                <span class="rw-weight-value"><?php echo number_format($val, 1); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <div class="rw-form-actions">
                        <button type="button" class="button button-primary rw-save-weights-btn" id="rw-save-weights">
                            <span class="dashicons dashicons-saved"></span>
                            <?php _e('Save Weights', 'raw-wire-dashboard'); ?>
                        </button>
                        <button type="button" class="button rw-rescore-btn" id="rw-rescore-all">
                            <span class="dashicons dashicons-update"></span>
                            <?php _e('Re-Score All Candidates', 'raw-wire-dashboard'); ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- AI Triage Settings -->
            <div class="rw-card" style="margin-top: 20px;">
                <h3><span class="dashicons dashicons-dismiss"></span> <?php _e('Blacklist Keywords', 'raw-wire-dashboard'); ?></h3>
                <p class="rw-help-text">
                    <?php _e('Keywords that penalize a record\'s score. Matched against work description, permit type, and use description. First keyword: −10 pts. Beyond two keywords: −20 pts each additional.', 'raw-wire-dashboard'); ?>
                </p>

                <div class="rw-blacklist-container">
                    <div class="rw-blacklist-input-row" style="display: flex; gap: 8px; margin-bottom: 12px;">
                        <input type="text" id="rw-blacklist-input" class="regular-text"
                            placeholder="<?php esc_attr_e('Type a keyword and press Enter or click Add', 'raw-wire-dashboard'); ?>"
                            style="flex: 1;">
                        <button type="button" class="button" id="rw-blacklist-add-btn">
                            <span class="dashicons dashicons-plus-alt2" style="vertical-align: middle;"></span>
                            <?php _e('Add', 'raw-wire-dashboard'); ?>
                        </button>
                    </div>

                    <div class="rw-blacklist-tags" id="rw-blacklist-tags" style="display: flex; flex-wrap: wrap; gap: 6px; min-height: 36px; padding: 8px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                        <?php
                        $gen = RawWire_Lead_Generator::get_instance();
                        $blacklist = $gen->get_blacklist();
                        if (empty($blacklist)) :
                        ?>
                            <span class="rw-blacklist-empty" style="color: #999; font-style: italic;">
                                <?php _e('No blacklist keywords set. Records are scored without penalty.', 'raw-wire-dashboard'); ?>
                            </span>
                            <?php else :
                            foreach ($blacklist as $kw) : ?>
                                <span class="rw-blacklist-tag" data-keyword="<?php echo esc_attr($kw); ?>"
                                    style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; background: #fee; border: 1px solid #c00; border-radius: 12px; font-size: 13px; color: #900;">
                                    <?php echo esc_html($kw); ?>
                                    <button type="button" class="rw-blacklist-remove" data-keyword="<?php echo esc_attr($kw); ?>"
                                        style="background: none; border: none; color: #c00; cursor: pointer; font-size: 16px; line-height: 1; padding: 0 2px;"
                                        title="<?php esc_attr_e('Remove', 'raw-wire-dashboard'); ?>">&times;</button>
                                </span>
                        <?php endforeach;
                        endif;
                        ?>
                    </div>

                    <div class="rw-form-actions" style="margin-top: 12px;">
                        <button type="button" class="button button-primary" id="rw-save-blacklist">
                            <span class="dashicons dashicons-saved"></span>
                            <?php _e('Save Blacklist', 'raw-wire-dashboard'); ?>
                        </button>
                        <span class="rw-blacklist-count" style="margin-left: 12px; color: #666;">
                            <?php printf(__('%d keyword(s)', 'raw-wire-dashboard'), count($blacklist)); ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- AI Triage Settings -->
            <div class="rw-card" style="margin-top: 20px;">
                <h3><span class="dashicons dashicons-filter"></span> <?php _e('AI Triage (Two-Pass Scoring)', 'raw-wire-dashboard'); ?></h3>
                <p class="rw-help-text">
                    <?php _e('Grok triages records in small chunks to stay within API limits. Each chunk gets a fast triage call (~8s, no web search). The top picks from each chunk then get full AI scoring with web search + contractor lookup (~19s each). The rest receive heuristic-only scores.', 'raw-wire-dashboard'); ?>
                </p>

                <div class="rw-form-group" style="display: flex; gap: 30px; align-items: flex-start;">
                    <div>
                        <label for="rw-triage-batch-size"><?php _e('Records per Chunk', 'raw-wire-dashboard'); ?></label>
                        <input type="number" id="rw-triage-batch-size" name="triage_batch_size"
                            value="<?php echo esc_attr($settings['triage_batch_size'] ?? 10); ?>" min="3" max="20" step="1"
                            class="rw-setting-field" data-key="triage_batch_size" style="width: 80px;">
                        <p class="description">
                            <?php _e('How many records per triage chunk. Keep ≤15 for Venice API stability.', 'raw-wire-dashboard'); ?>
                        </p>
                    </div>
                    <div>
                        <label for="rw-triage-picks"><?php _e('Picks per Chunk', 'raw-wire-dashboard'); ?></label>
                        <input type="number" id="rw-triage-picks" name="triage_picks"
                            value="<?php echo esc_attr($settings['triage_picks'] ?? 2); ?>" min="1" max="10" step="1"
                            class="rw-setting-field" data-key="triage_picks" style="width: 80px;">
                        <p class="description">
                            <?php _e('Best records selected from each chunk for full AI + web search scoring.', 'raw-wire-dashboard'); ?>
                        </p>
                    </div>
                </div>
                <p class="description" style="margin-top: 8px; font-style: italic;">
                    <?php
                    $bs = intval($settings['triage_batch_size'] ?? 10);
                    $pp = intval($settings['triage_picks'] ?? 2);
                    printf(
                        __('With 50 records: %d chunks × %d picks = %d records get full AI scoring.', 'raw-wire-dashboard'),
                        (int) ceil(50 / $bs),
                        $pp,
                        (int) ceil(50 / $bs) * $pp
                    );
                    ?>
                </p>

                <div class="rw-form-actions">
                    <button type="button" class="button button-primary" id="rw-save-triage-settings" onclick="jQuery.post(ajaxurl, { action: 'rawwire_lead_save_settings', nonce: RawWireLeads.nonce, triage_batch_size: jQuery('#rw-triage-batch-size').val(), triage_picks: jQuery('#rw-triage-picks').val() }, function(r) { alert(r.success ? 'Triage settings saved.' : 'Save failed.'); });">
                        <span class="dashicons dashicons-saved"></span>
                        <?php _e('Save Triage Settings', 'raw-wire-dashboard'); ?>
                    </button>
                </div>
            </div>
        </div>
    <?php
    }

    /**
     * Settings Tab
     */
    private function render_settings_tab($settings)
    {
    ?>
        <div class="rw-lead-settings">
            <div class="rw-settings-grid">
                <!-- API Keys -->
                <div class="rw-card">
                    <h3><span class="dashicons dashicons-admin-network"></span> <?php _e('API Configuration', 'raw-wire-dashboard'); ?></h3>

                    <div class="rw-form-group">
                        <label for="rw-socrata-token"><?php _e('Socrata App Token', 'raw-wire-dashboard'); ?></label>
                        <input type="text" id="rw-socrata-token" name="socrata_app_token"
                            value="<?php echo esc_attr($settings['socrata_app_token']); ?>" class="regular-text rw-setting-field" data-key="socrata_app_token"
                            placeholder="Your Socrata API app token">
                    </div>

                    <div class="rw-form-group">
                        <label for="rw-socrata-secret"><?php _e('Socrata Secret Token', 'raw-wire-dashboard'); ?></label>
                        <input type="password" id="rw-socrata-secret" name="socrata_secret_token"
                            value="<?php echo esc_attr($settings['socrata_secret_token']); ?>" class="regular-text rw-setting-field" data-key="socrata_secret_token"
                            placeholder="Optional — for authenticated access">
                    </div>

                    <div class="rw-form-group">
                        <label for="rw-textrazor-key"><?php _e('TextRazor API Key', 'raw-wire-dashboard'); ?></label>
                        <input type="password" id="rw-textrazor-key" name="textrazor_api_key"
                            value="<?php echo esc_attr($settings['textrazor_api_key']); ?>" class="regular-text rw-setting-field" data-key="textrazor_api_key"
                            placeholder="For NLP entity extraction (optional)">
                    </div>
                </div>
            </div>

            <!-- Pipeline Settings -->
            <div class="rw-card">
                <h3><span class="dashicons dashicons-admin-settings"></span> <?php _e('Pipeline Settings', 'raw-wire-dashboard'); ?></h3>

                <div class="rw-form-group">
                    <label for="rw-threshold"><?php _e('Candidate Threshold', 'raw-wire-dashboard'); ?></label>
                    <input type="number" id="rw-threshold" name="candidate_threshold"
                        value="<?php echo esc_attr($settings['candidate_threshold']); ?>" step="0.5" min="1" max="10" class="rw-setting-field" data-key="candidate_threshold">
                    <p class="description"><?php _e('Minimum composite score to become a candidate (1-10).', 'raw-wire-dashboard'); ?></p>
                </div>

                <div class="rw-form-group">
                    <label for="rw-cand-limit"><?php _e('Candidate Limit per Batch', 'raw-wire-dashboard'); ?></label>
                    <input type="number" id="rw-cand-limit" name="candidate_limit"
                        value="<?php echo esc_attr($settings['candidate_limit']); ?>" min="10" max="1000" class="rw-setting-field" data-key="candidate_limit">
                </div>

                <div class="rw-form-group">
                    <label class="rw-checkbox-label">
                        <input type="checkbox" name="auto_score" value="1" <?php checked($settings['auto_score']); ?> class="rw-setting-field" data-key="auto_score">
                        <?php _e('Auto-score after import', 'raw-wire-dashboard'); ?>
                    </label>
                </div>

                <div class="rw-form-group">
                    <label class="rw-checkbox-label">
                        <input type="checkbox" name="auto_promote" value="1" <?php checked($settings['auto_promote']); ?> class="rw-setting-field" data-key="auto_promote">
                        <?php _e('Auto-promote/archive after scoring', 'raw-wire-dashboard'); ?>
                    </label>
                </div>
            </div>
        </div>

        <div class="rw-form-actions">
            <button type="button" class="button button-primary rw-save-settings-btn">
                <span class="dashicons dashicons-saved"></span>
                <?php _e('Save Settings', 'raw-wire-dashboard'); ?>
            </button>
        </div>
        </div>
<?php
    }
}
