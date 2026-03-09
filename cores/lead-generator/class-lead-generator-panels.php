<?php

/**
 * Lead Generator — Panel Provider for Template Builder
 *
 * Provides discrete render methods for each control group that
 * the template engine can call via "renderer" strings in JSON.
 * Each method outputs a self-contained panel.
 *
 * @package RawWire_Dashboard
 * @since   1.0.27
 */

if (! defined('ABSPATH')) {
    exit;
}

class RawWire_Lead_Generator_Panels
{

    // =========================================================================
    // LEAD SOURCES PAGE — PIPELINE/DASHBOARD CONTROLS
    // =========================================================================

    /**
     * Pipeline stats: 4 stat cards (sources, candidates, leads, archived)
     *
     * @param array $panel Panel config from template JSON
     * @param array $context Page render context
     */
    public static function render_pipeline_stats($panel = [], $context = [])
    {
        if (! function_exists('rawwire_leads')) {
            self::render_fallback_message(__('Lead Generator not loaded.', 'raw-wire-dashboard'));
            return;
        }

        $stats = rawwire_leads()->get_stats();
?>
        <div class="rw-panel rw-pipeline-stats-panel">
            <div class="rw-pipeline-stats">
                <div class="rw-stat-card rw-stat-sources">
                    <div class="rw-stat-icon"><span class="dashicons dashicons-database"></span></div>
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
                    <div class="rw-stat-icon"><span class="dashicons dashicons-filter"></span></div>
                    <div class="rw-stat-body">
                        <div class="rw-stat-number"><?php echo number_format($stats['candidates']['total']); ?></div>
                        <div class="rw-stat-label"><?php _e('Candidates', 'raw-wire-dashboard'); ?></div>
                        <div class="rw-stat-detail">
                            <?php printf(__('%d pending', 'raw-wire-dashboard'), $stats['candidates']['pending']); ?>
                        </div>
                    </div>
                </div>

                <div class="rw-stat-arrow"><span class="dashicons dashicons-arrow-right-alt"></span></div>

                <div class="rw-stat-card rw-stat-leads">
                    <div class="rw-stat-icon"><span class="dashicons dashicons-portfolio"></span></div>
                    <div class="rw-stat-body">
                        <div class="rw-stat-number"><?php echo number_format($stats['leads']['total']); ?></div>
                        <div class="rw-stat-label"><?php _e('Active Leads', 'raw-wire-dashboard'); ?></div>
                        <div class="rw-stat-detail">
                            <?php printf(__('%d ready, %d contacted', 'raw-wire-dashboard'), $stats['leads']['ready'], $stats['leads']['contacted']); ?>
                        </div>
                    </div>
                </div>

                <div class="rw-stat-arrow"><span class="dashicons dashicons-arrow-right-alt"></span></div>

                <div class="rw-stat-card rw-stat-archived">
                    <div class="rw-stat-icon"><span class="dashicons dashicons-archive"></span></div>
                    <div class="rw-stat-body">
                        <div class="rw-stat-number"><?php echo number_format($stats['archived']['total']); ?></div>
                        <div class="rw-stat-label"><?php _e('Archived', 'raw-wire-dashboard'); ?></div>
                    </div>
                </div>
            </div>
        </div>
    <?php
    }

    /**
     * Pipeline action buttons: Score, Enrich, Import link
     *
     * @param array $panel Panel config
     * @param array $context Page context
     */
    public static function render_pipeline_actions($panel = [], $context = [])
    {
        if (! function_exists('rawwire_leads')) {
            return;
        }

        $stats = rawwire_leads()->get_stats();
    ?>
        <div class="rw-panel rw-pipeline-actions-panel">
            <div class="rw-action-row">
                <?php if ($stats['sources']['unprocessed'] > 0): ?>
                    <button type="button" class="button button-primary rw-score-btn">
                        <span class="dashicons dashicons-chart-bar"></span>
                        <?php printf(__('Score %d Records', 'raw-wire-dashboard'), min($stats['sources']['unprocessed'], 50)); ?>
                    </button>
                <?php endif; ?>

                <?php if ($stats['leads']['enriching'] > 0): ?>
                    <button type="button" class="button button-secondary rw-enrich-batch-btn">
                        <span class="dashicons dashicons-search"></span>
                        <?php printf(__('Enrich %d Leads', 'raw-wire-dashboard'), $stats['leads']['enriching']); ?>
                    </button>
                <?php endif; ?>

                <a href="?page=rawwire-lead-sources&tab=import" class="button button-secondary">
                    <span class="dashicons dashicons-download"></span>
                    <?php _e('Import New Records', 'raw-wire-dashboard'); ?>
                </a>

                <button type="button" class="button button-link-delete rw-reset-tables-btn" style="margin-left: auto;">
                    <span class="dashicons dashicons-trash"></span>
                    <?php _e('Reset All Tables', 'raw-wire-dashboard'); ?>
                </button>
            </div>
        </div>
    <?php
    }

    /**
     * Conversion funnel: bar chart for ready/contacted/converted
     *
     * @param array $panel Panel config
     * @param array $context Page context
     */
    public static function render_conversion_funnel($panel = [], $context = [])
    {
        if (! function_exists('rawwire_leads')) {
            return;
        }

        $stats = rawwire_leads()->get_stats();
        $max   = max($stats['leads']['ready'], $stats['leads']['contacted'], $stats['leads']['converted'], 1);
    ?>
        <div class="rw-panel rw-conversion-funnel-panel">
            <h3 class="rw-panel-title">
                <span class="dashicons dashicons-chart-bar"></span>
                <?php _e('Conversion Funnel', 'raw-wire-dashboard'); ?>
            </h3>
            <div class="rw-funnel-bars">
                <div class="rw-funnel-row">
                    <span class="rw-funnel-label"><?php _e('Ready', 'raw-wire-dashboard'); ?></span>
                    <div class="rw-funnel-bar-wrap">
                        <div class="rw-funnel-bar rw-bar-ready" style="width: <?php echo ($stats['leads']['ready'] / $max) * 100; ?>%;"></div>
                    </div>
                    <span class="rw-funnel-count"><?php echo $stats['leads']['ready']; ?></span>
                </div>
                <div class="rw-funnel-row">
                    <span class="rw-funnel-label"><?php _e('Contacted', 'raw-wire-dashboard'); ?></span>
                    <div class="rw-funnel-bar-wrap">
                        <div class="rw-funnel-bar rw-bar-contacted" style="width: <?php echo ($stats['leads']['contacted'] / $max) * 100; ?>%;"></div>
                    </div>
                    <span class="rw-funnel-count"><?php echo $stats['leads']['contacted']; ?></span>
                </div>
                <div class="rw-funnel-row">
                    <span class="rw-funnel-label"><?php _e('Converted', 'raw-wire-dashboard'); ?></span>
                    <div class="rw-funnel-bar-wrap">
                        <div class="rw-funnel-bar rw-bar-converted" style="width: <?php echo ($stats['leads']['converted'] / $max) * 100; ?>%;"></div>
                    </div>
                    <span class="rw-funnel-count"><?php echo $stats['leads']['converted']; ?></span>
                </div>
            </div>
        </div>
    <?php
    }

    // =========================================================================
    // LEAD SOURCES PAGE — IMPORT CONTROLS
    // =========================================================================

    /**
     * Import form: dataset select, permit filters, date range, max records, preview, import button
     *
     * @param array $panel Panel config
     * @param array $context Page context
     */
    public static function render_import_form($panel = [], $context = [])
    {
        if (! function_exists('rawwire_leads') || ! class_exists('RawWire_Socrata_Client')) {
            self::render_fallback_message(__('Socrata client not loaded.', 'raw-wire-dashboard'));
            return;
        }

        $settings = rawwire_leads()->get_settings();
        $datasets = RawWire_Socrata_Client::get_datasets();

        // Dataset feature availability matrix for JS
        // Note: last_updated is the most recent record date (verified Feb 2026)
        $dataset_features = wp_json_encode(array(
            'pi9x-tg5x' => array('contractor' => false, 'solar' => true, 'ev' => true, 'submitted_date' => true, 'cofo_date' => true, 'height' => true, 'construction' => true, 'last_updated' => '2026-02-15', 'stale' => false),
            'xnhu-aczu' => array('contractor' => true, 'solar' => false, 'ev' => false, 'submitted_date' => false, 'cofo_date' => false, 'height' => false, 'construction' => false, 'last_updated' => '2023-05-19', 'stale' => true),
            'gwh9-jnip' => array('contractor' => false, 'solar' => true, 'ev' => true, 'submitted_date' => true, 'cofo_date' => false, 'height' => false, 'construction' => false, 'last_updated' => null, 'stale' => false),
            '9yda-i4ya' => array('contractor' => false, 'solar' => false, 'ev' => false, 'submitted_date' => false, 'cofo_date' => false, 'height' => false, 'construction' => false, 'last_updated' => null, 'stale' => false),
            'hhzr-9ttq' => array('contractor' => false, 'solar' => false, 'ev' => false, 'submitted_date' => false, 'cofo_date' => false, 'height' => false, 'construction' => false, 'last_updated' => null, 'stale' => false),
            'n3m5-kxzy' => array('contractor' => false, 'solar' => false, 'ev' => false, 'submitted_date' => false, 'cofo_date' => false, 'height' => false, 'construction' => false, 'last_updated' => null, 'stale' => false),
        ));
    ?>
        <div class="rw-panel rw-import-form-panel">
            <h3 class="rw-panel-title">
                <span class="dashicons dashicons-download"></span>
                <?php _e('Socrata Import Configuration', 'raw-wire-dashboard'); ?>
            </h3>

            <!-- ═══════════════════════════════════════════════════════
                 SECTION 1: DATA SOURCE
                 ═══════════════════════════════════════════════════════ -->
            <div class="rw-matrix-section">
                <div class="rw-matrix-header">
                    <span class="dashicons dashicons-database"></span>
                    <?php _e('Data Source', 'raw-wire-dashboard'); ?>
                </div>

                <div class="rw-matrix-body">
                    <div class="rw-form-group">
                        <label for="rw-import-dataset"><?php _e('Dataset', 'raw-wire-dashboard'); ?></label>
                        <select id="rw-import-dataset" name="dataset" class="rw-select">
                            <?php foreach ($datasets as $id => $ds) : ?>
                                <option value="<?php echo esc_attr($id); ?>" <?php selected($id, $settings['primary_dataset'] ?? 'pi9x-tg5x'); ?>
                                    data-desc="<?php echo esc_attr($ds['desc']); ?>">
                                    <?php echo esc_html($ds['name']); ?> (<?php echo esc_html($id); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="rw-field-desc" id="rw-dataset-desc">
                            <?php echo esc_html($datasets[$settings['primary_dataset'] ?? 'pi9x-tg5x']['desc'] ?? ''); ?>
                        </p>
                    </div>
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
                                    'Bldg-New'          => 'New Construction',
                                    'Bldg-Alter/Repair' => 'Alteration / Repair',
                                    'Bldg-Addition'     => 'Addition',
                                    'Bldg-Demolition'   => 'Demolition',
                                    'Grading'           => 'Grading',
                                    'Non-Bldg'          => 'Non-Building',
                                    'Mech'              => 'Mechanical',
                                    'Elec'              => 'Electrical',
                                    'Plbg'              => 'Plumbing',
                                    'Fire Sprinkler'    => 'Fire Sprinkler',
                                    'Sign'              => 'Sign',
                                    'Pool/Spa'          => 'Pool / Spa',
                                    'Retaining Wall'    => 'Retaining Wall',
                                );
                                $selected_types = $settings['permit_types'] ?? array('Bldg-New', 'Bldg-Alter/Repair', 'Bldg-Addition');
                                foreach ($all_types as $val => $label) :
                                    $checked = in_array($val, $selected_types) ? 'checked' : '';
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
                                    'Commercial'             => 'Commercial',
                                    'Apartment'              => 'Apartment',
                                    '1 or 2 Family Dwelling' => '1-2 Family Dwelling',
                                    'Onsite'                 => 'Onsite',
                                );
                                $selected_subs = $settings['permit_sub_types'] ?? array('Commercial', 'Apartment');
                                foreach ($all_subs as $val => $label) :
                                    $checked = in_array($val, $selected_subs) ? 'checked' : '';
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
                                value="<?php echo esc_attr($settings['min_valuation'] ?? 100000); ?>" step="10000" min="0">
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
                                $current_range = $settings['date_range_months'] ?? 6;
                                foreach ($ranges as $months => $label) :
                                ?>
                                    <option value="<?php echo esc_attr($months); ?>" <?php selected($months, $current_range); ?>>
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

                    <div class="rw-form-row" style="margin-top: 12px;">
                        <div class="rw-form-group rw-third">
                            <label for="rw-import-max-age"><?php _e('Max Permit Age', 'raw-wire-dashboard'); ?></label>
                            <select id="rw-import-max-age" name="max_permit_age_months">
                                <?php
                                $ages = array(
                                    6  => 'Submitted ≤ 6 Months Ago',
                                    12 => 'Submitted ≤ 1 Year Ago',
                                    24 => 'Submitted ≤ 2 Years Ago',
                                    36 => 'Submitted ≤ 3 Years Ago',
                                    0  => 'No Age Limit',
                                );
                                $current_age = $settings['max_permit_age_months'] ?? 24;
                                foreach ($ages as $months => $label) :
                                ?>
                                    <option value="<?php echo esc_attr($months); ?>" <?php selected($months, $current_age); ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="description"><?php _e('Exclude old projects that were submitted years ago but only recently issued.', 'raw-wire-dashboard'); ?></span>
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
                 SECTION 9: IMPORT CONTROLS
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
                                value="<?php echo esc_attr($settings['import_limit'] ?? 500); ?>" min="10" max="5000" step="10">
                        </div>
                        <div class="rw-form-group rw-third">
                            <label class="rw-checkbox-label rw-checkbox-label-top">
                                <input type="checkbox" id="rw-import-auto-score" name="auto_score" value="1" <?php checked($settings['auto_score'] ?? true); ?>>
                                <span class="rw-cb-text"><?php _e('Auto-Score', 'raw-wire-dashboard'); ?></span>
                            </label>
                            <p class="rw-field-desc"><?php _e('Score records after import.', 'raw-wire-dashboard'); ?></p>
                        </div>
                        <div class="rw-form-group rw-third">
                            <label class="rw-checkbox-label rw-checkbox-label-top">
                                <input type="checkbox" id="rw-import-auto-promote" name="auto_promote" value="1" <?php checked($settings['auto_promote'] ?? true); ?>>
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
                <div class="rw-progress-bar">
                    <div class="rw-progress-fill" id="rw-import-bar"></div>
                </div>
                <div class="rw-progress-text" id="rw-import-status"></div>
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

                    // Staleness warning
                    var $warn = $('#rw-dataset-stale-warning');
                    if (!$warn.length) {
                        $warn = $('<div id="rw-dataset-stale-warning" class="rw-notice rw-notice-warning" style="display:none;margin-top:8px;padding:8px 12px;border-left:4px solid #f4b41a;background:rgba(244,180,26,0.12);color:#f4b41a;font-size:13px;border-radius:4px;"></div>');
                        $('#rw-dataset-desc').after($warn);
                    }
                    if (features.stale && features.last_updated) {
                        $warn.html('<span class="dashicons dashicons-warning" style="font-size:16px;vertical-align:middle;margin-right:4px;"></span>' +
                            '<strong>Stale dataset</strong> — last updated ' + features.last_updated + '. ' +
                            'Date/recency filters may return 0 results. Consider increasing date range or using a different dataset.'
                        ).show();
                    } else {
                        $warn.hide();
                    }

                    // Contractor section
                    if (features.contractor) {
                        $('#rw-contractor-section').removeClass('rw-section-unavailable');
                    } else {
                        $('#rw-contractor-section').addClass('rw-section-unavailable');
                    }

                    // Highlight active dataset in reference sidebar
                    $('.rw-dataset-item').removeClass('rw-dataset-active');
                    $('.rw-dataset-item[data-dataset="' + ds + '"]').addClass('rw-dataset-active');
                }).trigger('change');
            });
        </script>
    <?php
    }

    /**
     * Dataset info: available datasets reference card
     *
     * @param array $panel Panel config
     * @param array $context Page context
     */
    public static function render_dataset_info($panel = [], $context = [])
    {
        if (! class_exists('RawWire_Socrata_Client')) {
            return;
        }

        $datasets = RawWire_Socrata_Client::get_datasets();
    ?>
        <div class="rw-panel rw-dataset-info-panel">
            <h3 class="rw-panel-title">
                <span class="dashicons dashicons-info"></span>
                <?php _e('Dataset Reference', 'raw-wire-dashboard'); ?>
            </h3>

            <!-- Dataset list -->
            <div class="rw-dataset-list">
                <?php foreach ($datasets as $id => $ds): ?>
                    <div class="rw-dataset-item" data-dataset="<?php echo esc_attr($id); ?>">
                        <strong><?php echo esc_html($ds['name']); ?></strong>
                        <code><?php echo esc_html($id); ?></code>
                        <p><?php echo esc_html($ds['desc']); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Field availability legend -->
            <div class="rw-dataset-legend">
                <h4><?php _e('Field Availability by Dataset', 'raw-wire-dashboard'); ?></h4>
                <table class="rw-legend-table">
                    <thead>
                        <tr>
                            <th><?php _e('Feature', 'raw-wire-dashboard'); ?></th>
                            <th title="Issued Building Permits"><?php _e('Issued', 'raw-wire-dashboard'); ?></th>
                            <th title="Bldg & Safety: Building Permits"><?php _e('BUILD', 'raw-wire-dashboard'); ?></th>
                            <th title="Submitted Building Permits"><?php _e('Submit', 'raw-wire-dashboard'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?php _e('Contractor', 'raw-wire-dashboard'); ?></td>
                            <td class="rw-legend-no">&#x2717;</td>
                            <td class="rw-legend-yes">&#x2713;</td>
                            <td class="rw-legend-no">&#x2717;</td>
                        </tr>
                        <tr>
                            <td><?php _e('Solar / EV', 'raw-wire-dashboard'); ?></td>
                            <td class="rw-legend-yes">&#x2713;</td>
                            <td class="rw-legend-no">&#x2717;</td>
                            <td class="rw-legend-yes">&#x2713;</td>
                        </tr>
                        <tr>
                            <td><?php _e('Height / Stories', 'raw-wire-dashboard'); ?></td>
                            <td class="rw-legend-yes">&#x2713;</td>
                            <td class="rw-legend-no">&#x2717;</td>
                            <td class="rw-legend-no">&#x2717;</td>
                        </tr>
                        <tr>
                            <td><?php _e('Construction Type', 'raw-wire-dashboard'); ?></td>
                            <td class="rw-legend-yes">&#x2713;</td>
                            <td class="rw-legend-no">&#x2717;</td>
                            <td class="rw-legend-no">&#x2717;</td>
                        </tr>
                        <tr>
                            <td><?php _e('Submitted Date', 'raw-wire-dashboard'); ?></td>
                            <td class="rw-legend-yes">&#x2713;</td>
                            <td class="rw-legend-no">&#x2717;</td>
                            <td class="rw-legend-yes">&#x2713;</td>
                        </tr>
                    </tbody>
                </table>
                <p class="rw-legend-note"><?php _e('Feature-dependent sections dim automatically when unavailable.', 'raw-wire-dashboard'); ?></p>
            </div>
        </div>
    <?php
    }

    // NOTE: WP All Import integration removed Feb 2026 - using custom Socrata importer

    /**
     * DEPRECATED: render_wpai_imports_table removed - WPAI no longer used
     * DEPRECATED: render_lead_sources_table removed - WPAI no longer used
     */

    // =========================================================================
    // LEAD SOURCES PAGE — RECORDS TABLE
    // =========================================================================

    /**
     * Records table: paginated source records
     *
     * @param array $panel Panel config
     * @param array $context Page context
     */
    public static function render_records_table($panel = [], $context = [])
    {
        if (! function_exists('rawwire_leads')) {
            return;
        }

        global $wpdb;
        $leads = rawwire_leads();
        $table = $leads->table('sources');
        $page  = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per   = 25;
        $offset = ($page - 1) * $per;

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $records = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY issued_date DESC LIMIT %d OFFSET %d",
            $per,
            $offset
        ));

    ?>
        <div class="rw-panel rw-records-table-panel">
            <h3 class="rw-panel-title">
                <span class="dashicons dashicons-database"></span>
                <?php printf(__('Source Records (%d total)', 'raw-wire-dashboard'), $total); ?>
            </h3>

            <table class="widefat striped rw-table">
                <thead>
                    <tr>
                        <th><?php _e('Permit #', 'raw-wire-dashboard'); ?></th>
                        <th><?php _e('Address', 'raw-wire-dashboard'); ?></th>
                        <th><?php _e('Type', 'raw-wire-dashboard'); ?></th>
                        <th><?php _e('Use', 'raw-wire-dashboard'); ?></th>
                        <th><?php _e('Valuation', 'raw-wire-dashboard'); ?></th>
                        <th><?php _e('Issued', 'raw-wire-dashboard'); ?></th>
                        <th><?php _e('Status', 'raw-wire-dashboard'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $r): ?>
                        <tr>
                            <td><code><?php echo esc_html($r->permit_number); ?></code></td>
                            <td><?php echo esc_html($r->address); ?></td>
                            <td><?php echo esc_html($r->permit_type); ?></td>
                            <td><?php echo esc_html($r->use_type); ?></td>
                            <td>$<?php echo number_format($r->valuation); ?></td>
                            <td><?php echo esc_html($r->issued_date); ?></td>
                            <td>
                                <?php if ($r->scored): ?>
                                    <span class="rw-badge rw-badge-success"><?php _e('Scored', 'raw-wire-dashboard'); ?></span>
                                <?php else: ?>
                                    <span class="rw-badge rw-badge-pending"><?php _e('Pending', 'raw-wire-dashboard'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php
            $total_pages = ceil($total / $per);
            if ($total_pages > 1) {
                echo '<div class="rw-pagination">';
                echo paginate_links([
                    'base'    => add_query_arg('paged', '%#%'),
                    'format'  => '',
                    'current' => $page,
                    'total'   => $total_pages,
                ]);
                echo '</div>';
            }
            ?>
        </div>
    <?php
    }

    // =========================================================================
    // LEAD SOURCES PAGE — SCORING WEIGHTS
    // =========================================================================

    /**
     * Scoring weights: 7 weight sliders + save/rescore buttons
     *
     * @param array $panel Panel config
     * @param array $context Page context
     */
    public static function render_scoring_weights($panel = [], $context = [])
    {
        if (! function_exists('rawwire_leads')) {
            return;
        }

        $weights = rawwire_leads()->get_weights();
        $weight_defs = [
            'project_scale'     => ['label' => __('Project Scale', 'raw-wire-dashboard'), 'desc' => __('Weight for project valuation and square footage', 'raw-wire-dashboard')],
            'type_fit'          => ['label' => __('Type Fit', 'raw-wire-dashboard'), 'desc' => __('How well permit type matches interior design work', 'raw-wire-dashboard')],
            'green_indicators'  => ['label' => __('Green Indicators', 'raw-wire-dashboard'), 'desc' => __('Solar, EV, LEED, sustainability-related work', 'raw-wire-dashboard')],
            'timeline'          => ['label' => __('Timeline', 'raw-wire-dashboard'), 'desc' => __('Recency and project phase timing', 'raw-wire-dashboard')],
            'location_premium'  => ['label' => __('Location Premium', 'raw-wire-dashboard'), 'desc' => __('High-value zip codes in LA', 'raw-wire-dashboard')],
            'use_relevance'     => ['label' => __('Use Relevance', 'raw-wire-dashboard'), 'desc' => __('Property use type (commercial, residential, etc.)', 'raw-wire-dashboard')],
            'complexity'        => ['label' => __('Complexity', 'raw-wire-dashboard'), 'desc' => __('Project complexity indicators', 'raw-wire-dashboard')],
        ];
    ?>
        <div class="rw-panel rw-scoring-weights-panel">
            <h3 class="rw-panel-title">
                <span class="dashicons dashicons-chart-bar"></span>
                <?php _e('Scoring Weight Configuration', 'raw-wire-dashboard'); ?>
            </h3>

            <div class="rw-weights-grid">
                <?php foreach ($weight_defs as $key => $def): ?>
                    <div class="rw-weight-row">
                        <div class="rw-weight-label">
                            <label for="rw-weight-<?php echo esc_attr($key); ?>"><strong><?php echo esc_html($def['label']); ?></strong></label>
                            <span class="rw-weight-desc"><?php echo esc_html($def['desc']); ?></span>
                        </div>
                        <div class="rw-weight-control">
                            <input type="range" id="rw-weight-<?php echo esc_attr($key); ?>" class="rw-weight-slider" name="weights[<?php echo esc_attr($key); ?>]" data-key="<?php echo esc_attr($key); ?>" min="0" max="5" step="0.1" value="<?php echo esc_attr($weights[$key] ?? 1); ?>">
                            <span class="rw-weight-value"><?php echo esc_html($weights[$key] ?? 1); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="rw-form-actions">
                <button type="button" class="button button-primary rw-save-weights-btn">
                    <span class="dashicons dashicons-saved"></span>
                    <?php _e('Save Weights', 'raw-wire-dashboard'); ?>
                </button>
                <button type="button" class="button button-secondary rw-rescore-btn">
                    <span class="dashicons dashicons-update"></span>
                    <?php _e('Re-Score All Candidates', 'raw-wire-dashboard'); ?>
                </button>
            </div>
        </div>

        <?php self::render_blacklist_keywords(); ?>
    <?php
    }

    /**
     * Blacklist Keywords panel — tag-based keyword management
     * Penalizes records matching blacklisted terms in work_desc, permit_type, use_desc.
     */
    public static function render_blacklist_keywords($panel = [], $context = [])
    {
        if (! function_exists('rawwire_leads')) {
            return;
        }

        $gen = rawwire_leads();
        $blacklist = $gen->get_blacklist();
    ?>
        <div class="rw-panel rw-blacklist-panel" style="margin-top: 20px;">
            <h3 class="rw-panel-title">
                <span class="dashicons dashicons-dismiss"></span>
                <?php _e('Blacklist Keywords', 'raw-wire-dashboard'); ?>
            </h3>
            <p class="rw-help-text">
                <?php _e('Keywords that penalize a record\'s score. Matched against work description, permit type, and use description. First 2 matches: −10 pts each. Beyond 2: −20 pts each additional.', 'raw-wire-dashboard'); ?>
            </p>

            <div class="rw-blacklist-container">
                <div class="rw-blacklist-input-row" style="display: flex; gap: 8px; margin-bottom: 12px;">
                    <label for="rw-blacklist-input" class="screen-reader-text"><?php _e('Blacklist keyword', 'raw-wire-dashboard'); ?></label>
                    <input type="text" id="rw-blacklist-input" class="regular-text"
                        placeholder="<?php esc_attr_e('Type a keyword and press Enter or click Add', 'raw-wire-dashboard'); ?>"
                        style="flex: 1;">
                    <button type="button" class="button" id="rw-blacklist-add-btn">
                        <span class="dashicons dashicons-plus-alt2" style="vertical-align: middle;"></span>
                        <?php _e('Add', 'raw-wire-dashboard'); ?>
                    </button>
                </div>

                <div class="rw-blacklist-tags" id="rw-blacklist-tags" style="display: flex; flex-wrap: wrap; gap: 6px; min-height: 36px; padding: 8px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                    <?php if (empty($blacklist)) : ?>
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
                    endif; ?>
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
    <?php
    }

    // =========================================================================
    // LEAD SOURCES PAGE — SETTINGS
    // =========================================================================

    /**
     * API settings: Socrata tokens, TextRazor key
     *
     * @param array $panel Panel config
     * @param array $context Page context
     */
    public static function render_api_settings($panel = [], $context = [])
    {
        if (! function_exists('rawwire_leads')) {
            return;
        }

        $settings = rawwire_leads()->get_settings();
    ?>
        <div class="rw-panel rw-api-settings-panel">
            <h3 class="rw-panel-title">
                <span class="dashicons dashicons-admin-network"></span>
                <?php _e('API Configuration', 'raw-wire-dashboard'); ?>
            </h3>

            <div class="rw-form-grid">
                <div class="rw-form-row">
                    <label for="rw-socrata-token"><?php _e('Socrata App Token', 'raw-wire-dashboard'); ?></label>
                    <input type="text" id="rw-socrata-token" name="socrata_app_token" value="<?php echo esc_attr($settings['socrata_app_token'] ?? ''); ?>" class="regular-text rw-setting-field" data-key="socrata_app_token">
                </div>
                <div class="rw-form-row">
                    <label for="rw-socrata-secret"><?php _e('Socrata Secret Token', 'raw-wire-dashboard'); ?></label>
                    <input type="password" id="rw-socrata-secret" name="socrata_secret_token" value="<?php echo esc_attr($settings['socrata_secret_token'] ?? ''); ?>" class="regular-text rw-setting-field" data-key="socrata_secret_token">
                </div>
                <div class="rw-form-row">
                    <label for="rw-textrazor-key"><?php _e('TextRazor API Key', 'raw-wire-dashboard'); ?></label>
                    <input type="password" id="rw-textrazor-key" name="textrazor_api_key" value="<?php echo esc_attr($settings['textrazor_api_key'] ?? ''); ?>" class="regular-text rw-setting-field" data-key="textrazor_api_key">
                </div>
            </div>
        </div>
    <?php
    }

    /**
     * Pipeline settings: threshold, limit, auto flags
     *
     * @param array $panel Panel config
     * @param array $context Page context
     */
    public static function render_pipeline_settings($panel = [], $context = [])
    {
        if (! function_exists('rawwire_leads')) {
            return;
        }

        $settings = rawwire_leads()->get_settings();
    ?>
        <div class="rw-panel rw-pipeline-settings-panel">
            <h3 class="rw-panel-title">
                <span class="dashicons dashicons-admin-generic"></span>
                <?php _e('Pipeline Settings', 'raw-wire-dashboard'); ?>
            </h3>

            <div class="rw-form-grid">
                <div class="rw-form-row rw-form-row-half">
                    <label for="rw-threshold"><?php _e('Candidate Threshold', 'raw-wire-dashboard'); ?></label>
                    <input type="number" id="rw-threshold" name="candidate_threshold" value="<?php echo esc_attr($settings['candidate_threshold'] ?? 60); ?>" min="0" max="100" class="rw-setting-field" data-key="candidate_threshold">
                    <span class="description"><?php _e('Min score to promote to candidate', 'raw-wire-dashboard'); ?></span>
                </div>
                <div class="rw-form-row rw-form-row-half">
                    <label for="rw-candidate-limit"><?php _e('Candidate Limit', 'raw-wire-dashboard'); ?></label>
                    <input type="number" id="rw-candidate-limit" name="candidate_limit" value="<?php echo esc_attr($settings['candidate_limit'] ?? 100); ?>" min="10" max="1000" class="rw-setting-field" data-key="candidate_limit">
                    <span class="description"><?php _e('Max candidates per batch', 'raw-wire-dashboard'); ?></span>
                </div>
                <div class="rw-form-row">
                    <label class="rw-checkbox-label">
                        <input type="checkbox" name="auto_score" value="1" <?php checked($settings['auto_score'] ?? false); ?> class="rw-setting-field" data-key="auto_score">
                        <?php _e('Auto-score new imports', 'raw-wire-dashboard'); ?>
                    </label>
                </div>
                <div class="rw-form-row">
                    <label class="rw-checkbox-label">
                        <input type="checkbox" name="auto_promote" value="1" <?php checked($settings['auto_promote'] ?? false); ?> class="rw-setting-field" data-key="auto_promote">
                        <?php _e('Auto-promote high-scoring records', 'raw-wire-dashboard'); ?>
                    </label>
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

    // =========================================================================
    // LEAD APPROVALS PAGE
    // =========================================================================

    /**
     * Approval filters: status tabs + bulk action bar
     *
     * @param array $panel Panel config
     * @param array $context Page context
     */
    public static function render_approval_filters($panel = [], $context = [])
    {
        if (! function_exists('rawwire_leads')) {
            return;
        }

        global $wpdb;
        $leads = rawwire_leads();
        $table = $leads->table('candidates');

        $counts = [
            'pending'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'pending'"),
            'approved' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'approved'"),
            'trashed'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'trashed'"),
            'all'      => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status != 'trashed'"),
        ];
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'pending';
    ?>
        <div class="rw-panel rw-approval-filters-panel">
            <ul class="subsubsub">
                <li>
                    <a href="?page=rawwire-lead-approvals&status=pending" class="<?php echo $status === 'pending' ? 'current' : ''; ?>">
                        <?php _e('Pending', 'raw-wire-dashboard'); ?> <span class="count">(<?php echo $counts['pending']; ?>)</span>
                    </a> |
                </li>
                <li>
                    <a href="?page=rawwire-lead-approvals&status=approved" class="<?php echo $status === 'approved' ? 'current' : ''; ?>">
                        <?php _e('Approved', 'raw-wire-dashboard'); ?> <span class="count">(<?php echo $counts['approved']; ?>)</span>
                    </a> |
                </li>
                <li>
                    <a href="?page=rawwire-lead-approvals&status=all" class="<?php echo $status === 'all' ? 'current' : ''; ?>">
                        <?php _e('All', 'raw-wire-dashboard'); ?> <span class="count">(<?php echo $counts['all']; ?>)</span>
                    </a><?php if ($counts['trashed'] > 0): ?> |<?php endif; ?>
                </li>
                <?php if ($counts['trashed'] > 0): ?>
                    <li>
                        <a href="?page=rawwire-lead-approvals&status=trashed" class="<?php echo $status === 'trashed' ? 'current' : ''; ?>">
                            <?php _e('Trash', 'raw-wire-dashboard'); ?> <span class="count">(<?php echo $counts['trashed']; ?>)</span>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>

            <div class="rw-bulk-actions">
                <label class="rw-checkbox-label">
                    <input type="checkbox" class="rw-select-all-candidates">
                    <?php _e('Select All', 'raw-wire-dashboard'); ?>
                </label>
                <select name="bulk_action" id="rw-bulk-action-select" class="rw-bulk-action-select">
                    <option value=""><?php _e('Bulk Actions', 'raw-wire-dashboard'); ?></option>
                    <?php if ($status === 'trashed'): ?>
                        <option value="restore"><?php _e('Restore Selected', 'raw-wire-dashboard'); ?></option>
                        <option value="purge"><?php _e('Delete Permanently', 'raw-wire-dashboard'); ?></option>
                        <option value="empty_trash"><?php _e('Empty Trash', 'raw-wire-dashboard'); ?></option>
                    <?php else: ?>
                        <option value="approve"><?php _e('Approve Selected', 'raw-wire-dashboard'); ?></option>
                        <option value="trash"><?php _e('Move to Trash', 'raw-wire-dashboard'); ?></option>
                    <?php endif; ?>
                </select>
                <button type="button" class="button rw-bulk-action-btn"><?php _e('Apply', 'raw-wire-dashboard'); ?></button>
                <span class="rw-bulk-status"></span>
            </div>
        </div>
        <?php
    }

    /**
     * Candidate grid: card-based display with scores, data, actions
     *
     * @param array $panel Panel config
     * @param array $context Page context
     */
    public static function render_candidate_grid($panel = [], $context = [])
    {
        if (! function_exists('rawwire_leads')) {
            return;
        }

        global $wpdb;
        $leads  = rawwire_leads();
        $table  = $leads->table('candidates');
        $src_table = $leads->table('sources');
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'pending';
        $sort   = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'score_composite';
        $order  = isset($_GET['order']) ? sanitize_text_field($_GET['order']) : 'DESC';
        $page   = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per    = 20;
        $offset = ($page - 1) * $per;

        // 'all' excludes trashed; explicit 'trashed' shows trash; others filter by status
        if ($status === 'all') {
            $where = "WHERE c.status != 'trashed'";
        } elseif ($status === 'trashed') {
            $where = "WHERE c.status = 'trashed'";
        } else {
            $where = $wpdb->prepare("WHERE c.status = %s", $status);
        }
        $order_clause = sanitize_sql_orderby("c.{$sort} {$order}");
        if (! $order_clause) {
            $order_clause = 'c.score_composite DESC';  // safe fallback
        }

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} c {$where}");
        $candidates = $wpdb->get_results($wpdb->prepare(
            "SELECT c.*, s.investigation_status 
             FROM {$table} c 
             LEFT JOIN {$src_table} s ON s.id = c.source_id
             {$where} ORDER BY {$order_clause} LIMIT %d OFFSET %d",
            $per,
            $offset
        ));

        if (empty($candidates)) {
        ?>
            <div class="rw-panel rw-empty-state">
                <p><?php _e('No candidates found.', 'raw-wire-dashboard'); ?></p>
                <a href="?page=rawwire-lead-sources&tab=import" class="button button-primary">
                    <?php _e('Import Records', 'raw-wire-dashboard'); ?>
                </a>
            </div>
        <?php
            return;
        }
        ?>
        <div class="rw-panel rw-candidate-grid-panel">
            <div class="rw-candidate-grid">
                <?php foreach ($candidates as $c):
                    $score_val = floatval($c->score_composite ?? 0);
                    $score_display = round($score_val * 10);  // DB stores 0-10, display 0-100
                    $score_class = $score_display >= 80 ? 'high' : ($score_display >= 60 ? 'medium' : 'low');
                    // Build scores array from individual score columns
                    $scores = [];
                    foreach (['project_scale', 'type_fit', 'green_indicators', 'timeline', 'location', 'use_relevance', 'complexity'] as $_sk) {
                        $col = 'score_' . $_sk;
                        if (isset($c->$col) && floatval($c->$col) > 0) {
                            $scores[str_replace('_', ' ', $_sk)] = floatval($c->$col) * 10;
                        }
                    }
                ?>
                    <div class="rw-candidate-card<?php echo $c->status === 'trashed' ? ' rw-card-trashed' : ''; ?>" data-id="<?php echo esc_attr($c->id); ?>">
                        <div class="rw-card-header">
                            <label class="rw-checkbox-label">
                                <input type="checkbox" class="rw-candidate-checkbox" value="<?php echo esc_attr($c->id); ?>">
                            </label>
                            <span class="rw-score-badge rw-score-<?php echo $score_class; ?>"><?php echo $score_display; ?></span>
                            <strong class="rw-card-address"><?php echo esc_html($c->primary_address); ?></strong>
                            <span class="rw-card-zip"><?php echo esc_html($c->zip_code); ?></span>
                            <span class="rw-status-badge rw-status-<?php echo esc_attr($c->status); ?>"><?php echo esc_html(ucfirst($c->status)); ?></span>
                        </div>

                        <div class="rw-card-body">
                            <div class="rw-card-row"><strong><?php _e('Permit:', 'raw-wire-dashboard'); ?></strong> <code><?php echo esc_html($c->permit_nbr); ?></code></div>
                            <div class="rw-card-row"><strong><?php _e('Type:', 'raw-wire-dashboard'); ?></strong> <?php echo esc_html($c->permit_type); ?> / <?php echo esc_html($c->permit_sub_type); ?></div>
                            <div class="rw-card-row"><strong><?php _e('Use:', 'raw-wire-dashboard'); ?></strong> <?php echo esc_html($c->use_desc); ?></div>
                            <div class="rw-card-row"><strong><?php _e('Value:', 'raw-wire-dashboard'); ?></strong> $<?php echo number_format($c->valuation); ?></div>
                            <?php if ($c->square_footage > 0): ?>
                                <div class="rw-card-row"><strong><?php _e('Sqft:', 'raw-wire-dashboard'); ?></strong> <?php echo number_format($c->square_footage); ?></div>
                            <?php endif; ?>
                            <div class="rw-card-row"><strong><?php _e('Issued:', 'raw-wire-dashboard'); ?></strong> <?php echo esc_html($c->issue_date); ?></div>
                        </div>

                        <div class="rw-card-scores">
                            <button type="button" class="rw-score-toggle"><?php _e('Score Breakdown', 'raw-wire-dashboard'); ?></button>
                            <div class="rw-score-breakdown" style="display: none;">
                                <?php foreach ($scores as $key => $val): ?>
                                    <div class="rw-score-row">
                                        <span class="rw-score-label"><?php echo esc_html(ucfirst($key)); ?></span>
                                        <div class="rw-score-bar">
                                            <div class="rw-score-fill" style="width: <?php echo min(100, $val); ?>%;"></div>
                                        </div>
                                        <span class="rw-score-val"><?php echo round($val, 1); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <?php if ($c->status === 'trashed'): ?>
                            <div class="rw-card-actions">
                                <button type="button" class="button button-primary rw-restore-btn" data-id="<?php echo esc_attr($c->id); ?>">
                                    <span class="dashicons dashicons-undo"></span> <?php _e('Restore', 'raw-wire-dashboard'); ?>
                                </button>
                                <button type="button" class="button button-link-delete rw-purge-btn" data-id="<?php echo esc_attr($c->id); ?>">
                                    <span class="dashicons dashicons-trash"></span> <?php _e('Delete Permanently', 'raw-wire-dashboard'); ?>
                                </button>
                            </div>
                        <?php elseif ($c->status === 'pending'): ?>
                            <div class="rw-card-actions">
                                <button type="button" class="button button-primary rw-approve-btn" data-id="<?php echo esc_attr($c->id); ?>">
                                    <span class="dashicons dashicons-thumbs-up"></span> <?php _e('Approve', 'raw-wire-dashboard'); ?>
                                </button>
                                <button type="button" class="button rw-trash-btn" data-id="<?php echo esc_attr($c->id); ?>">
                                    <span class="dashicons dashicons-trash"></span> <?php _e('Trash', 'raw-wire-dashboard'); ?>
                                </button>
                            </div>
                        <?php endif; ?>

                        <div class="rw-card-investigation">
                            <?php
                            $inv_status = $c->investigation_status ?? 'pending';
                            $inv_class = 'rw-inv-' . esc_attr($inv_status);
                            ?>
                            <span class="rw-inv-badge <?php echo $inv_class; ?>">
                                <?php _e('Investigation:', 'raw-wire-dashboard'); ?> <?php echo esc_html(ucfirst($inv_status)); ?>
                            </span>
                            <?php if ($inv_status === 'pending' || $inv_status === 'failed'): ?>
                                <button type="button" class="button rw-investigate-btn" data-id="<?php echo esc_attr($c->id); ?>" data-source="<?php echo esc_attr($c->source_id); ?>">
                                    <span class="dashicons dashicons-search"></span> <?php _e('Investigate', 'raw-wire-dashboard'); ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php
            $total_pages = ceil($total / $per);
            if ($total_pages > 1) {
                echo '<div class="rw-pagination">';
                echo paginate_links([
                    'base'    => add_query_arg('paged', '%#%'),
                    'format'  => '',
                    'current' => $page,
                    'total'   => $total_pages,
                ]);
                echo '</div>';
            }
            ?>
        </div>
    <?php
    }

    // =========================================================================
    // LEAD COMPLETED PAGE
    // =========================================================================

    /**
     * Completed filters: status tabs + sort + enrich batch button
     *
     * @param array $panel Panel config
     * @param array $context Page context
     */
    public static function render_completed_filters($panel = [], $context = [])
    {
        if (! function_exists('rawwire_leads')) {
            return;
        }

        global $wpdb;
        $leads = rawwire_leads();
        $table = $leads->table('content');

        $statuses = ['all', 'enriching', 'ready', 'contacted', 'converted', 'lost'];
        $counts = [];
        foreach ($statuses as $s) {
            if ($s === 'all') {
                $counts[$s] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
            } else {
                $counts[$s] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s", $s));
            }
        }

        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'all';
        $sort   = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'composite_score';
    ?>
        <div class="rw-panel rw-completed-filters-panel">
            <ul class="subsubsub">
                <?php foreach ($statuses as $i => $s): ?>
                    <li>
                        <a href="?page=rawwire-lead-completed&status=<?php echo esc_attr($s); ?>" class="<?php echo $status === $s ? 'current' : ''; ?>">
                            <?php echo esc_html(ucfirst($s)); ?> <span class="count">(<?php echo $counts[$s]; ?>)</span>
                        </a><?php echo $i < count($statuses) - 1 ? ' |' : ''; ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="rw-toolbar">
                <?php if ($counts['enriching'] > 0): ?>
                    <button type="button" class="button button-primary rw-enrich-batch-btn">
                        <span class="dashicons dashicons-search"></span>
                        <?php printf(__('Enrich %d Pending', 'raw-wire-dashboard'), $counts['enriching']); ?>
                    </button>
                <?php endif; ?>

                <label for="rw-leads-sort"><?php _e('Sort by:', 'raw-wire-dashboard'); ?></label>
                <select id="rw-leads-sort" name="orderby">
                    <option value="composite_score" <?php selected($sort, 'composite_score'); ?>><?php _e('Score', 'raw-wire-dashboard'); ?></option>
                    <option value="valuation" <?php selected($sort, 'valuation'); ?>><?php _e('Valuation', 'raw-wire-dashboard'); ?></option>
                    <option value="enriched_at" <?php selected($sort, 'enriched_at'); ?>><?php _e('Enriched Date', 'raw-wire-dashboard'); ?></option>
                    <option value="project_name" <?php selected($sort, 'project_name'); ?>><?php _e('Name', 'raw-wire-dashboard'); ?></option>
                </select>
            </div>
        </div>
        <?php
    }

    /**
     * Lead cards: enriched lead file cards with expandable details
     *
     * @param array $panel Panel config
     * @param array $context Page context
     */
    public static function render_lead_cards($panel = [], $context = [])
    {
        if (! function_exists('rawwire_leads')) {
            return;
        }

        global $wpdb;
        $leads  = rawwire_leads();
        $table  = $leads->table('content');
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'all';
        $sort   = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'composite_score';
        $order  = isset($_GET['order']) ? sanitize_text_field($_GET['order']) : 'DESC';
        $page   = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per    = 15;
        $offset = ($page - 1) * $per;

        $where = $status !== 'all' ? $wpdb->prepare("WHERE status = %s", $status) : '';
        $order_clause = sanitize_sql_orderby("{$sort} {$order}");

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} {$where}");
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} {$where} ORDER BY {$order_clause} LIMIT %d OFFSET %d",
            $per,
            $offset
        ));

        if (empty($items)) {
        ?>
            <div class="rw-panel rw-empty-state">
                <p><?php _e('No leads found.', 'raw-wire-dashboard'); ?></p>
                <a href="?page=rawwire-lead-approvals" class="button button-primary">
                    <?php _e('Review Candidates', 'raw-wire-dashboard'); ?>
                </a>
            </div>
        <?php
            return;
        }
        ?>
        <div class="rw-panel rw-lead-cards-panel">
            <div class="rw-lead-grid">
                <?php foreach ($items as $item):
                    $score_class = $item->composite_score >= 80 ? 'high' : ($item->composite_score >= 60 ? 'medium' : 'low');
                    $enriched = json_decode($item->enriched_data, true) ?: [];
                    $status_emoji = [
                        'enriching' => '⏳',
                        'ready'     => '✅',
                        'contacted' => '📧',
                        'converted' => '🎉',
                        'lost'      => '❌',
                    ];
                ?>
                    <div class="rw-lead-card" data-id="<?php echo esc_attr($item->id); ?>">
                        <div class="rw-card-header">
                            <span class="rw-status-emoji"><?php echo $status_emoji[$item->status] ?? '📋'; ?></span>
                            <strong class="rw-project-name"><?php echo esc_html($item->project_name ?: $item->address); ?></strong>
                            <span class="rw-score-badge rw-score-<?php echo $score_class; ?>"><?php echo round($item->composite_score); ?></span>
                        </div>

                        <div class="rw-card-meta">
                            <code><?php echo esc_html($item->permit_number); ?></code>
                            <span><?php echo esc_html($item->address); ?>, <?php echo esc_html($item->zip_code); ?></span>
                            <span><?php echo esc_html($item->permit_type); ?> / <?php echo esc_html($item->use_type); ?></span>
                        </div>

                        <div class="rw-card-stats">
                            <span class="rw-pill">$<?php echo number_format($item->valuation); ?></span>
                            <?php if ($item->square_footage > 0): ?>
                                <span class="rw-pill"><?php echo number_format($item->square_footage); ?> sqft</span>
                            <?php endif; ?>
                            <span class="rw-pill"><?php echo esc_html($item->issued_date); ?></span>
                        </div>

                        <div class="rw-card-status-bar">
                            <select class="rw-lead-status-select" data-id="<?php echo esc_attr($item->id); ?>">
                                <?php foreach (['enriching', 'ready', 'contacted', 'converted', 'lost'] as $s): ?>
                                    <option value="<?php echo esc_attr($s); ?>" <?php selected($item->status, $s); ?>><?php echo esc_html(ucfirst($s)); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($item->status === 'enriching'): ?>
                                <button type="button" class="button button-small rw-enrich-single-btn" data-id="<?php echo esc_attr($item->id); ?>">
                                    <?php _e('Enrich Now', 'raw-wire-dashboard'); ?>
                                </button>
                            <?php endif; ?>
                            <button type="button" class="button button-small rw-lead-expand-btn">
                                <span class="dashicons dashicons-arrow-down"></span>
                            </button>
                        </div>

                        <div class="rw-card-details" style="display: none;">
                            <?php if (! empty($enriched['summary'])): ?>
                                <div class="rw-detail-section">
                                    <h4><?php _e('Project Summary', 'raw-wire-dashboard'); ?></h4>
                                    <p><?php echo esc_html($enriched['summary']); ?></p>
                                </div>
                            <?php endif; ?>

                            <?php if (! empty($enriched['parties'])): ?>
                                <div class="rw-detail-section rw-parties-grid">
                                    <h4><?php _e('Key Parties', 'raw-wire-dashboard'); ?></h4>
                                    <?php foreach (['filing_party', 'contractor', 'architect', 'owner'] as $party):
                                        if (! empty($enriched['parties'][$party])):
                                    ?>
                                            <div class="rw-party-card">
                                                <strong><?php echo esc_html(ucfirst(str_replace('_', ' ', $party))); ?></strong>
                                                <span><?php echo esc_html($enriched['parties'][$party]); ?></span>
                                            </div>
                                    <?php endif;
                                    endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if (! empty($enriched['sustainability'])): ?>
                                <div class="rw-detail-section rw-green-section">
                                    <h4><?php _e('Sustainability Analysis', 'raw-wire-dashboard'); ?></h4>
                                    <p><?php echo esc_html($enriched['sustainability']); ?></p>
                                </div>
                            <?php endif; ?>

                            <?php if (! empty($enriched['outreach'])): ?>
                                <div class="rw-detail-section">
                                    <h4><?php _e('Outreach Strategy', 'raw-wire-dashboard'); ?></h4>
                                    <p><?php echo esc_html($enriched['outreach']); ?></p>
                                </div>
                            <?php endif; ?>

                            <?php if (! empty($item->enriched_at)): ?>
                                <div class="rw-detail-timestamps">
                                    <span><?php printf(__('Enriched: %s', 'raw-wire-dashboard'), $item->enriched_at); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php
            $total_pages = ceil($total / $per);
            if ($total_pages > 1) {
                echo '<div class="rw-pagination">';
                echo paginate_links([
                    'base'    => add_query_arg('paged', '%#%'),
                    'format'  => '',
                    'current' => $page,
                    'total'   => $total_pages,
                ]);
                echo '</div>';
            }
            ?>
        </div>
    <?php
    }

    // =========================================================================
    // UTILITY METHODS
    // =========================================================================

    /**
     * Render fallback message when dependencies missing
     *
     * @param string $message
     */
    private static function render_fallback_message($message)
    {
    ?>
        <div class="rw-panel rw-fallback-message">
            <p class="notice notice-warning"><?php echo esc_html($message); ?></p>
        </div>
    <?php
    }

    /**
     * Full page render for lead sources (used by Page Renderer)
     *
     * @param array $panel Panel config
     * @param array $context Page context
     */
    public static function render_sources_page($panel = [], $context = [])
    {
        $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'import';
    ?>
        <div class="rw-lead-sources-page">
            <nav class="nav-tab-wrapper rawwire-tabs">
                <a href="?page=rawwire-lead-sources&tab=import" class="nav-tab <?php echo $tab === 'import' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-download"></span> <?php _e('Import', 'raw-wire-dashboard'); ?>
                </a>
                <a href="?page=rawwire-lead-sources&tab=investigation" class="nav-tab <?php echo $tab === 'investigation' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-search"></span> <?php _e('Investigation', 'raw-wire-dashboard'); ?>
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
                        self::render_import_form();
                        self::render_dataset_info();
                        break;
                    case 'records':
                        self::render_records_table();
                        break;
                    case 'scoring':
                        self::render_scoring_weights();
                        break;
                    case 'settings':
                        self::render_api_settings();
                        self::render_pipeline_settings();
                        break;
                    case 'investigation':
                        if (class_exists('RawWire_AI_Settings_Panel')) {
                            RawWire_AI_Settings_Panel::get_instance()->render_party_investigator_tab();
                        } else {
                            echo '<div class="notice notice-warning"><p>' . esc_html__('AI Settings Panel not loaded. Enable the Toolbox tool.', 'raw-wire-dashboard') . '</p></div>';
                        }
                        break;
                    default:
                        self::render_pipeline_stats();
                        self::render_pipeline_actions();
                        self::render_conversion_funnel();
                        break;
                }
                ?>
            </div>
        </div>
    <?php
    }

    /**
     * Full page render for approvals (used by Page Renderer)
     *
     * @param array $panel Panel config
     * @param array $context Page context
     */
    public static function render_approvals_page($panel = [], $context = [])
    {
    ?>
        <div class="rw-lead-approvals-page">
            <?php
            self::render_approval_filters();
            self::render_candidate_grid();
            self::render_raw_table_panels();
            ?>
        </div>
    <?php
    }

    /**
     * Render scrollable panels showing raw database tables for workflow visibility
     */
    public static function render_raw_table_panels()
    {
        if (! function_exists('rawwire_leads')) {
            return;
        }

        global $wpdb;
        $leads = rawwire_leads();

        // Tables to display
        $tables = [
            'sources'           => ['title' => 'Lead Sources',      'limit' => 50],
            'candidates'        => ['title' => 'Candidates',         'limit' => 50],
            'archive'           => ['title' => 'Archive',            'limit' => 50],
            'content'           => ['title' => 'Content',            'limit' => 50],
            'investigation_mirror' => ['title' => 'Investigation Sync Audit (Source Raw → Candidate)', 'limit' => 50],
            'network_entities'  => ['title' => 'Network Entities',   'limit' => 100],
            'network_projects'  => ['title' => 'Network Projects',   'limit' => 100],
        ];

    ?>
        <div class="rw-raw-tables-section">
            <h2><?php _e('Raw Database Tables', 'raw-wire-dashboard'); ?></h2>
            <p class="description"><?php _e('Scrollable views of raw pipeline data for debugging and visibility.', 'raw-wire-dashboard'); ?></p>

            <div class="rw-table-panels">
                <?php foreach ($tables as $key => $config):
                    if ($key === 'investigation_mirror') {
                        $count = self::get_investigation_mirror_count();
                        $rows = self::get_investigation_mirror_rows((int) $config['limit']);
                    } else {
                        $table_name = $leads->table($key);
                        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
                        $rows = $wpdb->get_results("SELECT * FROM {$table_name} ORDER BY id DESC LIMIT {$config['limit']}", ARRAY_A);
                    }
                    $columns = !empty($rows) ? array_keys($rows[0]) : [];
                ?>
                    <div class="rw-table-panel" data-table="<?php echo esc_attr($key); ?>">
                        <div class="rw-table-panel-header">
                            <h3><?php echo esc_html($config['title']); ?></h3>
                            <span class="rw-table-count"><?php printf(__('%d records', 'raw-wire-dashboard'), $count); ?></span>
                            <button type="button" class="rw-table-toggle dashicons dashicons-arrow-down-alt2" aria-expanded="false"></button>
                        </div>
                        <div class="rw-table-panel-body" style="display: none;">
                            <?php if (empty($rows)): ?>
                                <p class="rw-empty"><?php _e('No records.', 'raw-wire-dashboard'); ?></p>
                            <?php else: ?>
                                <div class="rw-table-scroll">
                                    <table class="widefat striped">
                                        <thead>
                                            <tr>
                                                <?php foreach ($columns as $col): ?>
                                                    <th><?php echo esc_html($col); ?></th>
                                                <?php endforeach; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($rows as $row): ?>
                                                <tr>
                                                    <?php foreach ($row as $col => $val): ?>
                                                        <td>
                                                            <?php
                                                            if ($key === 'investigation_mirror' && in_array($col, ['source_poi_1_notes', 'source_poi_2_notes', 'source_party_profiles_raw', 'candidate_party_profiles_raw'], true)) {
                                                                echo '<textarea class="rw-expandable-textarea" rows="2" readonly>' . esc_textarea((string) ($val ?? '')) . '</textarea>';
                                                                continue;
                                                            }

                                                            // Truncate long values for readability
                                                            $display = is_string($val) && strlen($val) > 80
                                                                ? esc_html(substr($val, 0, 80)) . '...'
                                                                : esc_html($val ?? '');
                                                            echo $display;
                                                            ?>
                                                        </td>
                                                    <?php endforeach; ?>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php if ($count > $config['limit']): ?>
                                    <p class="rw-table-more"><?php printf(__('Showing %d of %d records.', 'raw-wire-dashboard'), $config['limit'], $count); ?></p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <style>
            .rw-raw-tables-section {
                margin-top: 30px;
                padding-top: 20px;
                border-top: 1px solid #333;
            }

            .rw-raw-tables-section h2 {
                color: #e0e0e0;
            }

            .rw-raw-tables-section .description {
                color: #888;
            }

            .rw-table-panels {
                display: flex;
                flex-direction: column;
                gap: 10px;
            }

            .rw-table-panel {
                border: 1px solid #333;
                border-radius: 4px;
                background: #1e1f23;
            }

            .rw-table-panel-header {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 12px 15px;
                background: #18191c;
                cursor: pointer;
            }

            .rw-table-panel-header h3 {
                margin: 0;
                flex: 1;
                font-size: 14px;
                color: #e0e0e0;
            }

            .rw-table-count {
                font-size: 12px;
                color: #e0e0e0;
                background: #333;
                padding: 2px 8px;
                border-radius: 10px;
            }

            .rw-table-toggle {
                background: none;
                border: none;
                cursor: pointer;
                color: #888;
            }

            .rw-table-panel-body {
                padding: 15px;
                background: #1e1f23;
            }

            .rw-table-scroll {
                max-height: 300px;
                overflow: auto;
                border: 1px solid #333;
            }

            .rw-table-scroll table {
                font-size: 12px;
                background: #1e1f23;
                color: #e0e0e0;
            }

            .rw-table-scroll th {
                background: #18191c;
                color: #f4b41a;
                white-space: nowrap;
                padding: 8px 10px;
                border-bottom: 1px solid #333;
            }

            .rw-table-scroll td {
                white-space: nowrap;
                padding: 6px 10px;
                max-width: 200px;
                overflow: hidden;
                text-overflow: ellipsis;
                border-bottom: 1px solid #2a2a2a;
            }

            .rw-table-scroll tr:hover td {
                background: #252629;
            }

            .rw-table-more {
                font-size: 12px;
                color: #888;
                margin: 10px 0 0;
            }

            .rw-empty {
                color: #888;
                font-style: italic;
            }

            .rw-expandable-textarea {
                width: 100%;
                min-width: 300px;
                max-width: 520px;
                min-height: 44px;
                resize: vertical;
                border: 1px solid #3a3a3a;
                border-radius: 4px;
                background: #151619;
                color: #e0e0e0;
                padding: 6px 8px;
                line-height: 1.35;
                overflow: auto;
            }
        </style>

        <script>
            jQuery(function($) {
                $('.rw-table-panel-header').on('click', function() {
                    var $panel = $(this).closest('.rw-table-panel');
                    var $body = $panel.find('.rw-table-panel-body');
                    var $toggle = $(this).find('.rw-table-toggle');

                    $body.slideToggle(200);
                    $toggle.toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-up-alt2');
                    $toggle.attr('aria-expanded', $body.is(':visible'));
                });
            });
        </script>
    <?php
    }

    /**
     * Count investigated source records for sync audit.
     */
    private static function get_investigation_mirror_count(): int
    {
        if (! function_exists('rawwire_leads')) {
            return 0;
        }

        global $wpdb;
        $leads = rawwire_leads();
        $src_table = $leads->table('sources');
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$src_table} WHERE parties_investigated_at IS NOT NULL");
    }

    /**
     * Build sync-audit rows with source as canonical investigation payload.
     * Shows whether candidate fields are missing or diverging from source.
     */
    private static function get_investigation_mirror_rows(int $limit = 50): array
    {
        if (! function_exists('rawwire_leads')) {
            return [];
        }

        global $wpdb;
        $leads = rawwire_leads();
        $src_table = $leads->table('sources');
        $cand_table = $leads->table('candidates');

        $limit = max(1, min(200, (int) $limit));

        $base_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT
                c.id AS candidate_id,
                c.source_id AS candidate_source_id,
                c.status AS candidate_status,
                c.investigation_status AS candidate_investigation_status,
                c.contractor_name AS candidate_contractor_name,
                c.owner_name AS candidate_owner_name,
                s.investigation_status AS source_investigation_status,
                s.parties_investigated_at,
                s.id AS source_id,
                s.permit_nbr,
                s.primary_address,
                s.contractor_name,
                s.owner_name,
                c.party_profiles AS candidate_party_profiles,
                s.party_profiles AS source_party_profiles
             FROM {$src_table} s
             LEFT JOIN {$cand_table} c ON c.source_id = s.id
             WHERE s.parties_investigated_at IS NOT NULL
             ORDER BY s.parties_investigated_at DESC
             LIMIT %d",
            $limit
        ), ARRAY_A);

        if (empty($base_rows)) {
            return [];
        }

        $rows = [];
        foreach ($base_rows as $row) {
            $source_profiles_json = (string) ($row['source_party_profiles'] ?? '');
            $candidate_profiles_json = (string) ($row['candidate_party_profiles'] ?? '');
            $source_profiles = json_decode($source_profiles_json, true);

            $source_poi_1_title = '';
            $source_poi_1_notes = '';
            $source_poi_2_title = '';
            $source_poi_2_notes = '';
            $coverage_bidding = 0;
            $coverage_projects = 0;
            $coverage_urls = 0;
            $sync_issue_count = 0;

            $candidate_missing = empty($row['candidate_id']);
            if ($candidate_missing) {
                $sync_issue_count++;
            }

            $profile_sync_state = 'OK';
            $source_profile_len = strlen($source_profiles_json);
            $candidate_profile_len = strlen($candidate_profiles_json);
            if ($candidate_missing) {
                $profile_sync_state = 'NO_CANDIDATE';
            } elseif ($source_profile_len !== $candidate_profile_len || $source_profiles_json !== $candidate_profiles_json) {
                $profile_sync_state = 'MISMATCH';
                $sync_issue_count++;
            }

            $status_sync_state = 'OK';
            if ($candidate_missing) {
                $status_sync_state = 'NO_CANDIDATE';
            } elseif ((string) ($row['source_investigation_status'] ?? '') !== (string) ($row['candidate_investigation_status'] ?? '')) {
                $status_sync_state = 'MISMATCH';
                $sync_issue_count++;
            }

            $contractor_sync_state = 'OK';
            $source_contractor = trim((string) ($row['contractor_name'] ?? ''));
            $candidate_contractor = trim((string) ($row['candidate_contractor_name'] ?? ''));
            if ($candidate_missing) {
                $contractor_sync_state = 'NO_CANDIDATE';
            } elseif ($source_contractor !== $candidate_contractor) {
                $contractor_sync_state = 'MISMATCH';
                $sync_issue_count++;
            }

            $owner_sync_state = 'OK';
            $source_owner = trim((string) ($row['owner_name'] ?? ''));
            $candidate_owner = trim((string) ($row['candidate_owner_name'] ?? ''));
            if ($candidate_missing) {
                $owner_sync_state = 'NO_CANDIDATE';
            } elseif ($source_owner !== $candidate_owner) {
                $owner_sync_state = 'MISMATCH';
                $sync_issue_count++;
            }

            if (is_array($source_profiles)) {
                $first_profile = null;
                foreach ($source_profiles as $profile_entry) {
                    if (is_array($profile_entry) && ! empty($profile_entry['profile']) && is_array($profile_entry['profile'])) {
                        $first_profile = $profile_entry['profile'];
                        break;
                    }
                }

                if (is_array($first_profile)) {
                    $coverage = $first_profile['research_coverage'] ?? [];
                    if (is_array($coverage)) {
                        $segment_counts = $coverage['segment_search_counts'] ?? [];
                        $coverage_bidding = (int) ($segment_counts['bidding'] ?? 0);
                        $coverage_projects = (int) ($segment_counts['projects'] ?? 0);
                        $coverage_urls = (int) ($coverage['total_unique_urls'] ?? 0);
                    }

                    $poi = $first_profile['points_of_interest'] ?? [];
                    if (is_array($poi) && ! empty($poi[0])) {
                        $source_poi_1_title = trim((string) ($poi[0]['title'] ?? ''));
                        $source_poi_1_notes = trim((string) ($poi[0]['details'] ?? ''));
                    }
                    if (is_array($poi) && ! empty($poi[1])) {
                        $source_poi_2_title = trim((string) ($poi[1]['title'] ?? ''));
                        $source_poi_2_notes = trim((string) ($poi[1]['details'] ?? ''));
                    }

                    if ($source_poi_1_title === '' && ! empty($first_profile['intelligence_gaps'][0])) {
                        $source_poi_1_title = 'Intelligence Gap #1';
                        $source_poi_1_notes = (string) $first_profile['intelligence_gaps'][0];
                    }
                    if ($source_poi_2_title === '' && ! empty($first_profile['intelligence_gaps'][1])) {
                        $source_poi_2_title = 'Intelligence Gap #2';
                        $source_poi_2_notes = (string) $first_profile['intelligence_gaps'][1];
                    }
                }
            }

            $rows[] = [
                'candidate_id' => $row['candidate_id'] !== null ? (int) $row['candidate_id'] : '',
                'source_id' => (int) $row['source_id'],
                'sync_issue_count' => $sync_issue_count,
                'profile_sync_state' => $profile_sync_state,
                'status_sync_state' => $status_sync_state,
                'contractor_sync_state' => $contractor_sync_state,
                'owner_sync_state' => $owner_sync_state,
                'permit_nbr' => (string) ($row['permit_nbr'] ?? ''),
                'candidate_status' => (string) ($row['candidate_status'] ?? ''),
                'source_investigation_status' => (string) ($row['source_investigation_status'] ?? ''),
                'candidate_investigation_status' => (string) ($row['candidate_investigation_status'] ?? ''),
                'parties_investigated_at' => (string) ($row['parties_investigated_at'] ?? ''),
                'contractor_name' => (string) ($row['contractor_name'] ?? ''),
                'owner_name' => (string) ($row['owner_name'] ?? ''),
                'candidate_contractor_name' => (string) ($row['candidate_contractor_name'] ?? ''),
                'candidate_owner_name' => (string) ($row['candidate_owner_name'] ?? ''),
                'source_profile_bytes' => $source_profile_len,
                'candidate_profile_bytes' => $candidate_profile_len,
                'coverage_bidding' => $coverage_bidding,
                'coverage_projects' => $coverage_projects,
                'coverage_total_urls' => $coverage_urls,
                'source_poi_1_title' => $source_poi_1_title,
                'source_poi_1_notes' => $source_poi_1_notes,
                'source_poi_2_title' => $source_poi_2_title,
                'source_poi_2_notes' => $source_poi_2_notes,
                'source_party_profiles_raw' => $source_profiles_json,
                'candidate_party_profiles_raw' => $candidate_profiles_json,
            ];
        }

        return $rows;
    }

    /**
     * Full page render for completed leads (used by Page Renderer)
     *
     * @param array $panel Panel config
     * @param array $context Page context
     */
    public static function render_completed_page($panel = [], $context = [])
    {
    ?>
        <div class="rw-lead-completed-page">
            <?php
            self::render_completed_filters();
            self::render_lead_cards();
            ?>
        </div>
<?php
    }
}
