<?php

/**
 * @ai-context Search Instinct MCP for "Dashboard Controller Function Map v3" before modifying this file.
 */

/**
 * Dashboard AJAX Controller
 *
 * Handles all AJAX requests from the dashboard controller JS:
 *   - Mode switching (Collection / Information)
 *   - Workflow launch, stop, progress polling
 *   - Dynamic stats retrieval based on mode + workflow
 *
 * Tool-toggle gated: individual tool data is only served when that tool is enabled.
 * Each workflow maps to a specific tool and its controls are switchable.
 *
 * @package RawWire_Dashboard
 * @since   1.0.15
 */

if (! defined('ABSPATH')) {
    exit;
}

class RawWire_Dashboard_Controller
{
    /** @var self|null */
    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor - register AJAX hooks
     */
    private function __construct()
    {
        // Mode switching
        add_action('wp_ajax_rawwire_set_dashboard_mode', array($this, 'ajax_set_mode'));

        // Workflow execution
        add_action('wp_ajax_rawwire_dashboard_run_workflow', array($this, 'ajax_run_workflow'));
        add_action('wp_ajax_rawwire_dashboard_stop_workflow', array($this, 'ajax_stop_workflow'));
        add_action('wp_ajax_rawwire_dashboard_poll_progress', array($this, 'ajax_poll_progress'));

        // Dynamic stats
        add_action('wp_ajax_rawwire_dashboard_get_stats', array($this, 'ajax_get_stats'));
    }

    // =========================================================================
    // MODE SWITCHING
    // =========================================================================

    /**
     * Save user's dashboard mode preference
     */
    public function ajax_set_mode()
    {
        check_ajax_referer('rawwire_dashboard_ctrl', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
        }

        $mode = sanitize_text_field($_POST['mode'] ?? 'collection');
        if (! in_array($mode, array('collection', 'information'), true)) {
            $mode = 'collection';
        }

        update_user_meta(get_current_user_id(), 'rawwire_dashboard_mode', $mode);
        wp_send_json_success(array('mode' => $mode));
    }

    // =========================================================================
    // WORKFLOW LAUNCH / STOP
    // =========================================================================

    /**
     * Launch a workflow from the dashboard progress bar
     *
     * Routes to the correct tool's action based on automation_id.
     * Tool toggle gating: the tool must be enabled to launch.
     */
    public function ajax_run_workflow()
    {
        check_ajax_referer('rawwire_dashboard_ctrl', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
        }

        $automation_id = sanitize_text_field($_POST['automation_id'] ?? '');
        $tool_id = sanitize_text_field($_POST['tool_id'] ?? '');

        // Gate: tool must be enabled
        if ($tool_id && function_exists('rawwire_tools') && ! rawwire_tools()->is_tool_enabled($tool_id)) {
            wp_send_json_error(array('message' => 'Tool "' . $tool_id . '" is disabled. Enable it in Tools to use this workflow.'));
        }

        // Set automation state transients
        set_transient('rawwire_current_automation', $automation_id, HOUR_IN_SECONDS);
        set_transient('rawwire_automation_status', 'running', HOUR_IN_SECONDS);
        set_transient('rawwire_automation_step', 0, HOUR_IN_SECONDS);

        // Route to the correct handler based on automation_id
        $result = $this->dispatch_workflow($automation_id, $tool_id);

        if (! empty($result['success'])) {
            // Update progress to reflect completion
            set_transient('rawwire_automation_status', 'complete', HOUR_IN_SECONDS);
            $step_count = $result['total_steps'] ?? 4;
            set_transient('rawwire_automation_step', $step_count, HOUR_IN_SECONDS);

            wp_send_json_success(array(
                'message'  => $result['message'] ?? 'Workflow completed',
                'imported' => $result['imported'] ?? 0,
                'skipped'  => $result['skipped'] ?? 0,
                'fetched'  => $result['fetched'] ?? 0,
            ));
        } else {
            set_transient('rawwire_automation_status', 'error', HOUR_IN_SECONDS);
            wp_send_json_error(array(
                'message' => $result['message'] ?? 'Workflow failed',
                'errors'  => $result['errors'] ?? array(),
            ));
        }
    }

    /**
     * Stop a running workflow
     */
    public function ajax_stop_workflow()
    {
        check_ajax_referer('rawwire_dashboard_ctrl', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
        }

        // Clear automation state
        delete_transient('rawwire_current_automation');
        delete_transient('rawwire_automation_status');
        delete_transient('rawwire_automation_step');

        wp_send_json_success(array('status' => 'idle'));
    }

    /**
     * Dispatch workflow to the correct tool handler
     *
     * @param string $automation_id  The automation workflow ID
     * @param string $tool_id        The parent tool ID
     * @return array Result array with 'success' key
     */
    private function dispatch_workflow($automation_id, $tool_id)
    {
        switch ($automation_id) {
            case 'records_collection':
                return $this->run_records_collection();

            default:
                return array('success' => false, 'message' => 'Unknown workflow: ' . $automation_id);
        }
    }

    /**
     * Records Collection workflow - imports Socrata building permit data
     *
     * Uses the lead generator's import() method with saved settings.
     * This is the same import triggered from Lead Generator tab 1,
     * but launched from the dashboard progress bar.
     *
     * @return array
     */
    private function run_records_collection()
    {
        // Step 1: Connecting to Socrata API
        set_transient('rawwire_automation_step', 1, HOUR_IN_SECONDS);

        if (! function_exists('rawwire_leads')) {
            return array('success' => false, 'message' => 'Lead Generator not loaded. Enable the lead_generator tool.');
        }

        $leads = rawwire_leads();
        $settings = $leads->get_settings();

        // Step 2: Fetching records from Socrata
        set_transient('rawwire_automation_step', 2, HOUR_IN_SECONDS);

        $params = array(
            'dataset'               => $settings['primary_dataset'] ?? 'xnhu-aczu',
            'limit'                 => intval($settings['import_limit'] ?? 500),
            'permit_types'          => $settings['permit_types'] ?? array(),
            'permit_sub_types'      => $settings['permit_sub_types'] ?? array(),
            'min_valuation'         => floatval($settings['min_valuation'] ?? 100000),
            'date_months'           => intval($settings['date_range_months'] ?? 6),
            'max_permit_age_months' => intval($settings['max_permit_age_months'] ?? 24),
        );

        // import() fires rawwire_import_started and rawwire_import_complete hooks,
        // auto-scores all records, and auto-promotes top N to candidates.
        // promote_candidates() fires rawwire_candidates_promoted which triggers
        // investigate_promoted_candidates() to schedule party investigations.
        $result = $leads->import($params);

        // Step 3: Scoring & promoting
        set_transient('rawwire_automation_step', 3, HOUR_IN_SECONDS);

        if (empty($result['success'])) {
            return array(
                'success' => false,
                'message' => $result['error'] ?? $result['message'] ?? 'Import failed',
                'errors'  => $result['errors'] ?? array(),
            );
        }

        // Step 4: Investigating parties (scheduled async via rawwire_candidates_promoted hook)
        set_transient('rawwire_automation_step', 4, HOUR_IN_SECONDS);

        $scored = $result['auto_scored'] ?? array();
        $promoted = isset($scored['promotion']) ? $scored['promotion']['promoted'] : 0;
        $archived = isset($scored['promotion']) ? $scored['promotion']['archived'] : 0;

        // Step 5: Complete
        set_transient('rawwire_automation_step', 5, HOUR_IN_SECONDS);

        return array(
            'success'     => true,
            'message'     => sprintf(
                'Imported %d records, scored %d, promoted top %d to candidates, archived %d.',
                $result['imported'] ?? 0,
                $scored['scored'] ?? 0,
                $promoted,
                $archived
            ),
            'imported'    => $result['imported'] ?? 0,
            'skipped'     => $result['skipped'] ?? 0,
            'fetched'     => $result['fetched'] ?? 0,
            'scored'      => $scored['scored'] ?? 0,
            'promoted'    => $promoted,
            'archived'    => $archived,
            'total_steps' => 5,
        );
    }

    // =========================================================================
    // PROGRESS POLLING
    // =========================================================================

    /**
     * Poll current workflow progress
     */
    public function ajax_poll_progress()
    {
        check_ajax_referer('rawwire_dashboard_ctrl', 'nonce');

        $automation = get_transient('rawwire_current_automation') ?: '';
        $status = get_transient('rawwire_automation_status') ?: 'idle';
        $step = intval(get_transient('rawwire_automation_step') ?: 0);

        // Look up automation definition for total steps and step labels
        $total_steps = 5;
        $step_label  = '';
        if ($automation && function_exists('rawwire_tools') && rawwire_tools()) {
            $all = rawwire_tools()->get_all_enabled_automations();
            foreach ($all as $auto) {
                if ($auto['id'] === $automation) {
                    $steps = $auto['steps'] ?? array();
                    $total_steps = count($steps);
                    // Get current step's label (steps are 1-indexed in transient, 0-indexed in array)
                    if ($step > 0 && isset($steps[$step - 1])) {
                        $step_label = $steps[$step - 1]['label'] ?? '';
                    }
                    break;
                }
            }
        }

        $percent = $total_steps > 0 ? min(100, ($step / $total_steps) * 100) : 0;
        if ($status === 'complete') {
            $percent = 100;
        }

        $status_labels = array(
            'idle'     => 'Ready',
            'running'  => 'Running',
            'complete' => 'Done',
            'error'    => 'Error',
            'paused'   => 'Paused',
        );

        // Use step label when running (e.g., "Fetching"), fall back to status label
        $display_label = ($status === 'running' && $step_label)
            ? $step_label
            : ($status_labels[$status] ?? $status);

        wp_send_json_success(array(
            'status'     => $status,
            'step'       => $step,
            'total'      => $total_steps,
            'percent'    => round($percent),
            'label'      => $display_label,
            'step_label' => $step_label,
            'message'    => $status === 'complete' ? 'Workflow completed' : '',
        ));
    }

    // =========================================================================
    // DYNAMIC STATS
    // =========================================================================

    /**
     * Get stats for the dashboard statistics panel
     *
     * Returns different metrics depending on dashboard mode and selected workflow.
     * All stats are tool-toggle gated.
     */
    public function ajax_get_stats()
    {
        check_ajax_referer('rawwire_dashboard_ctrl', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
        }

        $mode = sanitize_text_field($_POST['mode'] ?? 'collection');
        $workflow = sanitize_text_field($_POST['workflow'] ?? '');

        $stats = array();

        if ($mode === 'collection') {
            $stats = $this->get_collection_stats($workflow);
        } elseif ($mode === 'information') {
            $stats = $this->get_information_stats();
        }

        wp_send_json_success($stats);
    }

    /**
     * Collection mode stats - shows data relevant to the selected workflow
     *
     * @param string $workflow  Active workflow ID
     * @return array Stats metrics
     */
    private function get_collection_stats($workflow = '')
    {
        // Default: use lead_generator stats if tool is enabled
        if (function_exists('rawwire_tools') && rawwire_tools()->is_tool_enabled('lead_generator')) {
            return $this->get_lead_generator_stats($workflow);
        }

        // Fallback: generic collection stats
        return array(
            array('id' => 'sources', 'label' => 'Sources', 'value' => '0', 'icon' => 'dashicons-database'),
            array('id' => 'collected', 'label' => 'Collected', 'value' => '0', 'icon' => 'dashicons-download'),
            array('id' => 'processed', 'label' => 'Processed', 'value' => '0', 'icon' => 'dashicons-yes-alt'),
            array('id' => 'errors', 'label' => 'Errors', 'value' => '0', 'icon' => 'dashicons-warning', 'highlight' => 'danger'),
        );
    }

    /**
     * Lead generator specific stats, context-aware by workflow
     *
     * @param string $workflow  Active workflow ID
     * @return array
     */
    private function get_lead_generator_stats($workflow = '')
    {
        if (! function_exists('rawwire_leads')) {
            return array();
        }

        $lg = rawwire_leads();
        $db_stats = $lg->get_stats();

        if ($workflow === 'records_collection') {
            // Records Collection workflow: focus on import metrics
            return array(
                array(
                    'id'    => 'source_records',
                    'label' => 'Source Records',
                    'value' => number_format($db_stats['sources']['total'] ?? 0),
                    'icon'  => 'dashicons-database',
                ),
                array(
                    'id'    => 'unprocessed',
                    'label' => 'Unprocessed',
                    'value' => number_format($db_stats['sources']['unprocessed'] ?? 0),
                    'icon'  => 'dashicons-clock',
                ),
                array(
                    'id'        => 'last_import',
                    'label'     => 'Last Import',
                    'value'     => $db_stats['last_import_count'] ?? '0',
                    'icon'      => 'dashicons-download',
                    'highlight' => 'success',
                ),
                array(
                    'id'    => 'duplicates_skipped',
                    'label' => 'Skipped (Dupes)',
                    'value' => $db_stats['last_import_skipped'] ?? '0',
                    'icon'  => 'dashicons-dismiss',
                ),
            );
        }

        // Default collection stats: full pipeline overview
        return array(
            array(
                'id'    => 'source_records',
                'label' => 'Source Records',
                'value' => number_format($db_stats['sources']['total'] ?? 0),
                'icon'  => 'dashicons-database',
            ),
            array(
                'id'    => 'candidates',
                'label' => 'Candidates',
                'value' => number_format($db_stats['candidates']['total'] ?? 0),
                'icon'  => 'dashicons-filter',
            ),
            array(
                'id'        => 'active_leads',
                'label'     => 'Active Leads',
                'value'     => number_format($db_stats['leads']['total'] ?? 0),
                'icon'      => 'dashicons-portfolio',
                'highlight' => 'success',
            ),
            array(
                'id'    => 'archived',
                'label' => 'Archived',
                'value' => number_format($db_stats['archive']['total'] ?? 0),
                'icon'  => 'dashicons-archive',
            ),
        );
    }

    /**
     * Information mode stats - high-level overview of the system
     *
     * @return array
     */
    private function get_information_stats()
    {
        $stats = array();

        // System-wide info stats
        $stats[] = array(
            'id'    => 'total_records',
            'label' => 'Total Records',
            'value' => $this->count_total_records(),
            'icon'  => 'dashicons-database',
        );

        $stats[] = array(
            'id'    => 'active_tools',
            'label' => 'Active Tools',
            'value' => $this->count_active_tools(),
            'icon'  => 'dashicons-admin-tools',
        );

        $stats[] = array(
            'id'    => 'recent_activity',
            'label' => 'Recent Activity',
            'value' => $this->count_recent_logs(),
            'icon'  => 'dashicons-list-view',
        );

        $stats[] = array(
            'id'        => 'system_health',
            'label'     => 'System Health',
            'value'     => $this->get_system_health(),
            'icon'      => 'dashicons-heart',
            'highlight' => 'success',
        );

        return $stats;
    }

    // =========================================================================
    // STAT HELPERS
    // =========================================================================

    /**
     * Count total records across all workflow tables
     */
    private function count_total_records()
    {
        global $wpdb;
        $total = 0;
        $tables = array('lead_sources', 'lead_candidates', 'lead_content', 'lead_archive');

        foreach ($tables as $table) {
            $full = $wpdb->prefix . 'rawwire_' . $table;
            if ($wpdb->get_var("SHOW TABLES LIKE '{$full}'") === $full) {
                $total += intval($wpdb->get_var("SELECT COUNT(*) FROM `{$full}`"));
            }
        }

        return number_format($total);
    }

    /**
     * Count enabled tools
     */
    private function count_active_tools()
    {
        if (function_exists('rawwire_tools')) {
            $tools = rawwire_tools();
            $count = 0;
            $all = $tools->get_tool_definitions();
            foreach ($all as $id => $def) {
                if ($tools->is_tool_enabled($id)) {
                    $count++;
                }
            }
            return (string) $count;
        }
        return '0';
    }

    /**
     * Count recent log entries (last 24h)
     */
    private function count_recent_logs()
    {
        $count = 0;
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            $log_file = WP_CONTENT_DIR . '/debug.log';
            if (file_exists($log_file)) {
                // Tail-based read to avoid OOM on large logs
                $tail_bytes = 256 * 1024;
                $fsize = filesize($log_file);
                $fh = fopen($log_file, 'r');
                if (!$fh) {
                    return '0';
                }
                if ($fsize > $tail_bytes) {
                    fseek($fh, -$tail_bytes, SEEK_END);
                    fgets($fh); // discard partial first line
                }
                $tail = fread($fh, $tail_bytes);
                fclose($fh);
                $lines = $tail ? explode("\n", $tail) : array();
                $cutoff = time() - DAY_IN_SECONDS;
                foreach (array_reverse($lines) as $line) {
                    if (stripos($line, 'rawwire') === false && stripos($line, 'raw-wire') === false) {
                        continue;
                    }
                    if (preg_match('/^\[([^\]]+)\]/', $line, $m)) {
                        $ts = strtotime($m[1]);
                        if ($ts && $ts >= $cutoff) {
                            $count++;
                        } elseif ($ts && $ts < $cutoff) {
                            break;
                        }
                    }
                }
            }
        }
        return (string) $count;
    }

    /**
     * Simple system health indicator
     */
    private function get_system_health()
    {
        // Check for recent errors in last hour
        $error_count = 0;
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            $log_file = WP_CONTENT_DIR . '/debug.log';
            if (file_exists($log_file)) {
                // Tail-based read to avoid OOM on large logs
                $tail_bytes = 64 * 1024; // 64KB is plenty for ~100 lines
                $fsize = filesize($log_file);
                $fh = fopen($log_file, 'r');
                if ($fh) {
                    if ($fsize > $tail_bytes) {
                        fseek($fh, -$tail_bytes, SEEK_END);
                        fgets($fh);
                    }
                    $tail = fread($fh, $tail_bytes);
                    fclose($fh);
                    $lines = array_slice(array_filter(explode("\n", $tail), 'strlen'), -100);
                } else {
                    $lines = array();
                }
                $cutoff = time() - HOUR_IN_SECONDS;
                foreach ($lines as $line) {
                    if ((stripos($line, 'error') !== false || stripos($line, 'fatal') !== false) &&
                        (stripos($line, 'rawwire') !== false || stripos($line, 'raw-wire') !== false)
                    ) {
                        if (preg_match('/^\[([^\]]+)\]/', $line, $m)) {
                            $ts = strtotime($m[1]);
                            if ($ts && $ts >= $cutoff) {
                                $error_count++;
                            }
                        }
                    }
                }
            }
        }

        if ($error_count === 0) {
            return 'Good';
        } elseif ($error_count <= 3) {
            return 'Warning';
        }
        return 'Issues';
    }
}
