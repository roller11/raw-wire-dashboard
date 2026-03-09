<?php

/**
 * RawWire Permit Pipeline Automation
 *
 * Orchestrates the full automation flow:
 * 1. Fetch permit data from custom Socrata importer
 * 2. Run LADBS scraper for contractor/owner data
 * 3. Combine records and insert into rawwire_lead_sources
 * 4. Trigger scoring pipeline (leads to AI investigation)
 *
 * Note: WP All Import integration removed Feb 2026 - using custom Socrata importer only.
 *
 * @package RawWire_Dashboard
 * @since   1.0.30
 */

if (!defined('ABSPATH')) {
    exit;
}

class RawWire_Permit_Pipeline
{

    /** @var self|null */
    private static $instance = null;

    /** @var string Path to Python scraper */
    private string $scraper_path;

    /** @var string Python executable */
    private string $python_path;

    /** @var string Path to venv (optional) */
    private string $venv_path;

    /** @var int Batch size for processing */
    const BATCH_SIZE = 10;

    /** @var int Delay between scrapes (seconds) */
    const SCRAPE_DELAY = 2;

    /**
     * Get singleton instance
     */
    public static function get_instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
        $this->scraper_path = dirname(dirname(__DIR__)) . '/scripts/ladbs_scraper.py';
        $this->python_path  = $this->detect_python();
        $this->venv_path    = dirname(dirname(__DIR__)) . '/scripts/venv';

        $this->register_hooks();
    }

    /**
     * Register WordPress hooks
     */
    private function register_hooks(): void
    {
        // AJAX handlers
        add_action('wp_ajax_rw_run_permit_pipeline', [$this, 'ajax_run_pipeline']);
        add_action('wp_ajax_rw_pipeline_status', [$this, 'ajax_get_status']);

        // Cron for automated processing
        add_action('rw_permit_pipeline_cron', [$this, 'cron_run_pipeline']);

        // Schedule hourly if not scheduled
        if (!wp_next_scheduled('rw_permit_pipeline_cron')) {
            wp_schedule_event(time(), 'hourly', 'rw_permit_pipeline_cron');
        }
    }

    /**
     * Detect Python executable
     */
    private function detect_python(): string
    {
        // Check for venv first
        $venv_python = dirname(dirname(__DIR__)) . '/scripts/venv/bin/python';
        if (file_exists($venv_python)) {
            return $venv_python;
        }

        // WSL python3
        exec('which python3 2>/dev/null', $output, $code);
        if ($code === 0 && !empty($output[0])) {
            return $output[0];
        }

        // Windows python
        exec('where python.exe 2>NUL', $output2, $code2);
        if ($code2 === 0 && !empty($output2[0])) {
            return $output2[0];
        }

        return 'python3';
    }

    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================

    /**
     * AJAX: Run the full pipeline
     */
    public function ajax_run_pipeline(): void
    {
        check_ajax_referer('rawwire_lead_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $limit = absint($_POST['limit'] ?? self::BATCH_SIZE);
        $skip_scraped = !isset($_POST['rescrape']);

        $result = $this->run_pipeline($limit, $skip_scraped);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX: Get pipeline status
     */
    public function ajax_get_status(): void
    {
        check_ajax_referer('rawwire_lead_nonce', 'nonce');

        wp_send_json_success([
            'pending_permits'   => $this->count_pending_permits(),
            'scraped_permits'   => $this->count_scraped_permits(),
            'unprocessed'       => $this->count_unprocessed_sources(),
        ]);
    }

    /**
     * Cron: Run pipeline automatically
     */
    public function cron_run_pipeline(): void
    {
        $result = $this->run_pipeline(self::BATCH_SIZE, true);
        rawwire_log('permit_pipeline', 'Cron run: ' . wp_json_encode($result));
    }

    // =========================================================================
    // MAIN PIPELINE
    // =========================================================================

    /**
     * Run the full automation pipeline
     *
     * @param int  $limit        Max permits to process
     * @param bool $skip_scraped Skip already-scraped permits
     * @return array Result summary
     */
    public function run_pipeline(int $limit = 10, bool $skip_scraped = true): array
    {
        global $wpdb;

        $start_time = microtime(true);
        $results = [
            'success'      => true,
            'permits_found' => 0,
            'scraped'      => 0,
            'scrape_errors' => 0,
            'inserted'     => 0,
            'insert_errors' => 0,
            'already_exists' => 0,
            'scored'       => 0,
            'messages'     => [],
        ];

        // Step 1: Get pending permits from WP All Import
        $permits = $this->get_pending_permits($limit, $skip_scraped);
        $results['permits_found'] = count($permits);

        if (empty($permits)) {
            $results['messages'][] = 'No pending permits found';
            return $results;
        }

        // Step 2: Process each permit
        foreach ($permits as $permit) {
            $permit_result = $this->process_permit($permit);

            if ($permit_result['scraped']) {
                $results['scraped']++;
            }
            if (!empty($permit_result['scrape_error'])) {
                $results['scrape_errors']++;
            }
            if ($permit_result['inserted']) {
                $results['inserted']++;
            }
            if ($permit_result['already_exists']) {
                $results['already_exists']++;
            }
            if (!empty($permit_result['insert_error'])) {
                $results['insert_errors']++;
            }

            // Delay between scrapes to avoid rate limiting
            if ($permit_result['scraped'] && $limit > 1) {
                sleep(self::SCRAPE_DELAY);
            }
        }

        // Step 3: Trigger party investigation for newly inserted records
        // NOTE: Scoring now happens AFTER investigation completes (see RawWire_Party_Investigator)
        if ($results['inserted'] > 0) {
            $this->trigger_party_investigations($results);
        }

        $results['duration'] = round(microtime(true) - $start_time, 2);
        $results['messages'][] = "Pipeline completed in {$results['duration']}s";

        return $results;
    }

    /**
     * Trigger party investigations for newly inserted sources
     *
     * After investigation completes, scoring will be triggered automatically.
     *
     * @param array &$results Results array to update
     */
    private function trigger_party_investigations(array &$results): void
    {
        if (!function_exists('rawwire_party_investigator')) {
            // Investigator not available - fall back to immediate scoring
            if (function_exists('rawwire_leads')) {
                $leads = rawwire_leads();
                $score_result = $leads->score_unprocessed($results['inserted']);
                $results['scored'] = $score_result['scored'] ?? 0;
                $results['messages'][] = "Scored {$results['scored']} records (investigation skipped)";
            }
            return;
        }

        $investigator = rawwire_party_investigator();

        if (!$investigator->is_available()) {
            // Search provider not configured - fall back to immediate scoring
            if (function_exists('rawwire_leads')) {
                $leads = rawwire_leads();
                $score_result = $leads->score_unprocessed($results['inserted']);
                $results['scored'] = $score_result['scored'] ?? 0;
                $results['messages'][] = "Scored {$results['scored']} records (investigation not configured)";
            }
            return;
        }

        // Get uninvestigated sources and schedule investigation
        global $wpdb;
        $sources_table = rawwire_leads()->table('sources');

        $uninvestigated = $wpdb->get_col(
            "SELECT id FROM {$sources_table} 
             WHERE parties_investigated_at IS NULL 
               AND investigation_status = 'pending'
             ORDER BY imported_at DESC 
             LIMIT " . $results['inserted']
        );

        foreach ($uninvestigated as $source_id) {
            // Schedule async investigation
            wp_schedule_single_event(time(), 'rawwire_investigate_source_parties', [(int)$source_id]);
        }

        $results['investigations_scheduled'] = count($uninvestigated);
        $results['messages'][] = sprintf(
            "Scheduled %d party investigations. Scoring will occur after investigations complete.",
            count($uninvestigated)
        );
    }

    /**
     * Process a single permit through the pipeline
     *
     * @param array $permit Permit data with post_id, permit_number
     * @return array Result for this permit
     */
    private function process_permit(array $permit): array
    {
        global $wpdb;

        $result = [
            'permit_number' => $permit['permit_number'],
            'post_id'       => $permit['post_id'],
            'scraped'       => false,
            'scrape_error'  => null,
            'inserted'      => false,
            'already_exists' => false,
            'insert_error'  => null,
        ];

        // Check if already in lead_sources
        $leads = function_exists('rawwire_leads') ? rawwire_leads() : null;
        if ($leads) {
            $table = $leads->table('sources');
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE permit_nbr = %s",
                $permit['permit_number']
            ));

            if ($exists) {
                $result['already_exists'] = true;
                return $result;
            }
        }

        // Step A: Get Socrata data from post meta
        $socrata_data = $this->extract_socrata_data($permit['post_id']);

        // Step B: Scrape LADBS for contractor/owner data
        $ladbs_data = $this->scrape_ladbs($permit['permit_number']);

        if ($ladbs_data['success']) {
            $result['scraped'] = true;

            // Save scrape result to post meta
            $this->save_scrape_to_postmeta($permit['post_id'], $ladbs_data);
        } else {
            $result['scrape_error'] = $ladbs_data['error'] ?? 'Unknown scrape error';
            // Continue anyway - we still have Socrata data
        }

        // Step B2: Cross-reference with xnhu-aczu for party data
        // This dataset has contractor/applicant info not in pi9x-tg5x
        $party_data = $this->fetch_socrata_party_data($permit['permit_number']);
        if (!empty($party_data)) {
            $socrata_data = array_merge($socrata_data, $party_data);
            $result['party_data_found'] = true;
        }

        // Step C: Combine and insert into lead_sources
        if ($leads) {
            $insert_result = $this->insert_to_lead_sources($socrata_data, $ladbs_data, $permit);

            if ($insert_result['success']) {
                $result['inserted'] = true;
            } else {
                $result['insert_error'] = $insert_result['error'];
            }
        }

        return $result;
    }

    // =========================================================================
    // DATA EXTRACTION
    // =========================================================================

    /**
     * Get pending permits from WP All Import posts
     *
     * @param int  $limit Max permits to fetch
     * @param bool $skip_scraped Skip permits with contractor data
     * @return array List of permits
     */
    public function get_pending_permits(int $limit = 10, bool $skip_scraped = true): array
    {
        global $wpdb;

        $table_prefix = $wpdb->prefix;

        // Check if pmxi_posts table exists
        $pmxi_table = $table_prefix . 'pmxi_posts';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$pmxi_table}'");
        if (!$table_exists) {
            return [];
        }

        if ($skip_scraped) {
            // Get permits that don't have contractor data yet
            $query = $wpdb->prepare("
                SELECT
                    p.post_id,
                    p.unique_key as permit_number,
                    p.import_id
                FROM {$pmxi_table} p
                LEFT JOIN {$table_prefix}postmeta m
                    ON p.post_id = m.post_id
                    AND m.meta_key = '_contractor_enriched_at'
                WHERE m.meta_id IS NULL
                    AND p.unique_key IS NOT NULL
                    AND p.unique_key != ''
                LIMIT %d
            ", $limit);
        } else {
            $query = $wpdb->prepare("
                SELECT
                    post_id,
                    unique_key as permit_number,
                    import_id
                FROM {$pmxi_table}
                WHERE unique_key IS NOT NULL
                    AND unique_key != ''
                LIMIT %d
            ", $limit);
        }

        return $wpdb->get_results($query, ARRAY_A) ?: [];
    }

    /**
     * Extract Socrata data from WordPress post meta
     *
     * @param int $post_id WordPress post ID
     * @return array Mapped data
     */
    public function extract_socrata_data(int $post_id): array
    {
        // Map post meta to lead_sources fields
        $meta_mapping = [
            'permit_nbr'        => ['permit_number', '_permit_number'],
            'primary_address'   => ['address', '_address'],
            'zip_code'          => ['zip_code', '_zip_code'],
            'council_district'  => ['council_district', '_council_district'],
            'permit_type'       => ['permit_type', '_permit_type'],
            'permit_sub_type'   => ['permit_sub_type', '_permit_sub_type'],
            'use_desc'          => ['use_description', '_use_description'],
            'work_desc'         => ['work_description', '_work_description'],
            'valuation'         => ['valuation', '_valuation'],
            'square_footage'    => ['floor_area_sqft', '_floor_area_sqft'],
            'stories'           => ['stories', '_stories'],
            'status_desc'       => ['permit_status', '_permit_status'],
            'issue_date'        => ['issue_date', '_issue_date'],
            'lat'               => ['latitude', '_latitude'],
            'lon'               => ['longitude', '_longitude'],
            'applicant_name'    => ['applicant_name', '_applicant_name'],
            'applicant_business' => ['applicant_company', '_applicant_company'],
        ];

        $data = [];
        foreach ($meta_mapping as $field => $meta_keys) {
            foreach ($meta_keys as $meta_key) {
                $value = get_post_meta($post_id, $meta_key, true);
                // Clean up duplicated values (WP All Import bug)
                if ($value && strlen($value) > 10) {
                    $half_len = intval(strlen($value) / 2);
                    $first_half = substr($value, 0, $half_len);
                    $second_half = substr($value, $half_len);
                    if ($first_half === $second_half) {
                        $value = $first_half;
                    }
                }
                if ($value) {
                    $data[$field] = $value;
                    break;
                }
            }
        }

        // Get raw JSON if available
        $raw_json = get_post_meta($post_id, 'raw_json', true)
            ?: get_post_meta($post_id, '_raw_json', true);
        if ($raw_json) {
            $data['raw_json'] = $raw_json;
        }

        // Source info
        $data['source_api'] = 'wpai_socrata';
        $data['source_dataset'] = get_post_meta($post_id, 'import_source', true) ?: 'socrata_permits';
        $data['source_record_id'] = $post_id; // Use WP post ID as record reference

        return $data;
    }

    /**
     * Scrape LADBS for contractor/owner data
     *
     * @param string $permit_number Permit number
     * @return array Scraper result
     */
    public function scrape_ladbs(string $permit_number): array
    {
        // First check if scraper exists
        if (!file_exists($this->scraper_path)) {
            return [
                'success' => false,
                'error'   => 'Scraper script not found: ' . $this->scraper_path,
            ];
        }

        // Build command with proper escaping
        $escaped_permit = escapeshellarg($permit_number);
        $escaped_script = escapeshellarg($this->scraper_path);

        // Use JSON output mode
        $cmd = "{$this->python_path} {$escaped_script} --permit {$escaped_permit} --json --headless 2>&1";

        // Run the scraper
        $output = [];
        $exit_code = 0;
        exec($cmd, $output, $exit_code);

        $output_str = implode("\n", $output);

        // Try to parse JSON from output
        // Look for JSON object in output (scraper outputs to stdout)
        if (preg_match('/\{[^{}]*"success"[^{}]*\}/s', $output_str, $matches)) {
            $json = json_decode($matches[0], true);
            if ($json !== null) {
                return $json;
            }
        }

        // Try parsing entire output as JSON
        $json = json_decode($output_str, true);
        if ($json !== null) {
            return $json;
        }

        // Scraper failed
        return [
            'success' => false,
            'error'   => "Scraper exited with code {$exit_code}",
            'output'  => substr($output_str, 0, 500),
        ];
    }

    /**
     * Save scrape results to WordPress post meta
     *
     * @param int   $post_id Post ID
     * @param array $data    Scraper result
     */
    private function save_scrape_to_postmeta(int $post_id, array $data): void
    {
        if (!$data['success']) {
            update_post_meta($post_id, '_contractor_fetch_error', $data['error'] ?? 'Unknown error');
            return;
        }

        // Owner info
        $owner = $data['owner'] ?? [];
        if (!empty($owner['name'])) {
            update_post_meta($post_id, '_owner_name', sanitize_text_field($owner['name']));
        }
        if (!empty($owner['address'])) {
            update_post_meta($post_id, '_owner_address', sanitize_text_field($owner['address']));
        }
        if (!empty($owner['phone'])) {
            update_post_meta($post_id, '_owner_phone', sanitize_text_field($owner['phone']));
        }

        // Contractor info
        $contractor = $data['contractor'] ?? [];
        if (!empty($contractor['name'])) {
            update_post_meta($post_id, '_contractor_name', sanitize_text_field($contractor['name']));
        }
        if (!empty($contractor['company'])) {
            update_post_meta($post_id, '_contractor_company', sanitize_text_field($contractor['company']));
        }
        if (!empty($contractor['license_number'])) {
            update_post_meta($post_id, '_contractor_license', sanitize_text_field($contractor['license_number']));
        }
        if (!empty($contractor['phone'])) {
            update_post_meta($post_id, '_contractor_phone', sanitize_text_field($contractor['phone']));
        }

        // Applicant info
        $applicant = $data['applicant'] ?? [];
        if (!empty($applicant['name'])) {
            update_post_meta($post_id, '_applicant_name', sanitize_text_field($applicant['name']));
        }
        if (!empty($applicant['phone'])) {
            update_post_meta($post_id, '_applicant_phone', sanitize_text_field($applicant['phone']));
        }

        // Raw data
        if (!empty($data['raw_data'])) {
            update_post_meta($post_id, '_contractor_raw_data', wp_json_encode($data['raw_data']));
        }

        // Timestamp
        update_post_meta($post_id, '_contractor_enriched_at', current_time('mysql'));
    }

    /**
     * Insert combined data into rawwire_lead_sources table
     *
     * @param array $socrata_data Data from Socrata/WP postmeta
     * @param array $ladbs_data   Data from LADBS scraper
     * @param array $permit       Permit info with post_id
     * @return array Result
     */
    private function insert_to_lead_sources(array $socrata_data, array $ladbs_data, array $permit): array
    {
        global $wpdb;

        if (!function_exists('rawwire_leads')) {
            return ['success' => false, 'error' => 'Lead generator not available'];
        }

        $leads = rawwire_leads();
        $table = $leads->table('sources');

        // Build insert data
        $insert_data = [
            'source_api'       => $socrata_data['source_api'] ?? 'wpai_ladbs',
            'source_dataset'   => $socrata_data['source_dataset'] ?? 'wpai_permits',
            'source_record_id' => (string) $permit['post_id'],
            'permit_nbr'       => $permit['permit_number'],
            'primary_address'  => $socrata_data['primary_address'] ?? '',
            'zip_code'         => $socrata_data['zip_code'] ?? '',
            'council_district' => $socrata_data['council_district'] ?? '',
            'permit_type'      => $socrata_data['permit_type'] ?? '',
            'permit_sub_type'  => $socrata_data['permit_sub_type'] ?? '',
            'use_desc'         => $socrata_data['use_desc'] ?? '',
            'work_desc'        => $socrata_data['work_desc'] ?? '',
            'valuation'        => floatval($socrata_data['valuation'] ?? 0),
            'square_footage'   => intval($socrata_data['square_footage'] ?? 0),
            'stories'          => $socrata_data['stories'] ?? '',
            'status_desc'      => $socrata_data['status_desc'] ?? '',
            'issue_date'       => $this->parse_date($socrata_data['issue_date'] ?? ''),
            'lat'              => floatval($socrata_data['lat'] ?? 0),
            'lon'              => floatval($socrata_data['lon'] ?? 0),
            'import_batch'     => 'wpai_pipeline_' . date('Ymd'),
            'imported_at'      => current_time('mysql'),
            'processed'        => 0,
        ];

        // Add contractor data from LADBS scrape
        if (!empty($ladbs_data['success'])) {
            $contractor = $ladbs_data['contractor'] ?? [];
            $owner = $ladbs_data['owner'] ?? [];
            $applicant = $ladbs_data['applicant'] ?? [];

            if (!empty($contractor['name'])) {
                $insert_data['contractor_name'] = $contractor['name'];
            }
            if (!empty($contractor['license_number'])) {
                $insert_data['contractor_license'] = $contractor['license_number'];
            }
            if (!empty($applicant['name'])) {
                $insert_data['applicant_name'] = $applicant['name'];
            }
            if (!empty($applicant['company'])) {
                $insert_data['applicant_business'] = $applicant['company'];
            }

            // Store full scrape data in meta
            $insert_data['meta'] = wp_json_encode([
                'owner'      => $owner,
                'contractor' => $contractor,
                'applicant'  => $applicant,
                'wp_post_id' => $permit['post_id'],
            ]);
        }

        // Fallback to Socrata xnhu-aczu party data if LADBS scrape missed fields
        // This data was fetched in process_permit() via fetch_socrata_party_data()
        if (empty($insert_data['contractor_name']) && !empty($socrata_data['contractor_name'])) {
            $insert_data['contractor_name'] = $socrata_data['contractor_name'];
        }
        if (empty($insert_data['contractor_license']) && !empty($socrata_data['contractor_license'])) {
            $insert_data['contractor_license'] = $socrata_data['contractor_license'];
        }
        if (empty($insert_data['applicant_name']) && !empty($socrata_data['applicant_name'])) {
            $insert_data['applicant_name'] = $socrata_data['applicant_name'];
        }
        if (empty($insert_data['applicant_business']) && !empty($socrata_data['applicant_business'])) {
            $insert_data['applicant_business'] = $socrata_data['applicant_business'];
        }

        // Store meta if we don't have it yet (LADBS failed but we have xnhu-aczu data)
        if (empty($insert_data['meta'])) {
            $insert_data['meta'] = wp_json_encode([
                'wp_post_id' => $permit['post_id'],
                'scrape_error' => $ladbs_data['error'] ?? 'No scrape data',
                'socrata_party_data' => !empty($socrata_data['contractor_name']) || !empty($socrata_data['applicant_name']),
            ]);
        }

        // Store raw JSON from Socrata
        if (!empty($socrata_data['raw_json'])) {
            $insert_data['raw_json'] = $socrata_data['raw_json'];
        }

        // Insert
        $result = $wpdb->insert($table, $insert_data);

        if ($result === false) {
            return [
                'success' => false,
                'error'   => $wpdb->last_error,
            ];
        }

        return [
            'success'   => true,
            'source_id' => $wpdb->insert_id,
        ];
    }

    /**
     * Parse various date formats to MySQL datetime
     */
    private function parse_date(string $date_str): ?string
    {
        if (empty($date_str)) {
            return null;
        }

        // Handle Socrata ISO format with .000
        $date_str = preg_replace('/\.\d{3}$/', '', $date_str);

        $timestamp = strtotime($date_str);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    // =========================================================================
    // STATUS QUERIES
    // =========================================================================

    /**
     * Count permits pending scraping
     */
    public function count_pending_permits(): int
    {
        global $wpdb;

        $pmxi_table = $wpdb->prefix . 'pmxi_posts';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$pmxi_table}'");
        if (!$table_exists) {
            return 0;
        }

        return (int) $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$pmxi_table} p
            LEFT JOIN {$wpdb->prefix}postmeta m
                ON p.post_id = m.post_id
                AND m.meta_key = '_contractor_enriched_at'
            WHERE m.meta_id IS NULL
                AND p.unique_key IS NOT NULL
                AND p.unique_key != ''
        ");
    }

    /**
     * Count permits already scraped
     */
    public function count_scraped_permits(): int
    {
        global $wpdb;

        $pmxi_table = $wpdb->prefix . 'pmxi_posts';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$pmxi_table}'");
        if (!$table_exists) {
            return 0;
        }

        return (int) $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$pmxi_table} p
            INNER JOIN {$wpdb->prefix}postmeta m
                ON p.post_id = m.post_id
                AND m.meta_key = '_contractor_enriched_at'
            WHERE p.unique_key IS NOT NULL
                AND p.unique_key != ''
        ");
    }

    /**
     * Count unprocessed sources
     */
    public function count_unprocessed_sources(): int
    {
        global $wpdb;

        if (!function_exists('rawwire_leads')) {
            return 0;
        }

        $table = rawwire_leads()->table('sources');
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE processed = 0");
    }

    /**
     * Fetch party data for a permit from Socrata xnhu-aczu dataset
     *
     * This dataset contains contractor/applicant names that are NOT
     * available in the primary pi9x-tg5x dataset we imported from.
     *
     * @param string $permit_number Permit number to look up
     * @return array Party data with contractor_name, applicant_name, etc.
     */
    private function fetch_socrata_party_data(string $permit_number): array
    {
        if (!function_exists('rawwire_leads')) {
            return [];
        }

        $leads = rawwire_leads();
        $client = $leads->get_socrata_client();

        if (is_wp_error($client)) {
            return [];
        }

        $party_data = $client->fetch_party_data($permit_number);

        if (empty($party_data)) {
            return [];
        }

        rawwire_log('permit_pipeline', sprintf(
            'Fetched party data from xnhu-aczu for %s: contractor=%s, applicant=%s',
            $permit_number,
            $party_data['contractor_name'] ?? 'none',
            $party_data['applicant_name'] ?? 'none'
        ), 'debug');

        return $party_data;
    }
}

/**
 * Get pipeline instance
 */
function rawwire_permit_pipeline(): RawWire_Permit_Pipeline
{
    return RawWire_Permit_Pipeline::get_instance();
}
