<?php

/**
 * LADBS Contractor Data Integration
 * 
 * Fetches contractor/owner info from LADBS for existing leads
 * and updates them with the additional data.
 * 
 * @package RawWire
 */

namespace RawWire\LeadGenerator;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Contractor_Enrichment
 * 
 * Handles fetching and storing contractor data for leads.
 */
class Contractor_Enrichment
{

    /**
     * Python scraper script path
     */
    private string $scraper_path;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->scraper_path = dirname(__DIR__) . '/scripts/ladbs_scraper.py';

        // Register AJAX handlers
        add_action('wp_ajax_rw_enrich_lead', [$this, 'ajax_enrich_lead']);
        add_action('wp_ajax_rw_enrich_batch', [$this, 'ajax_enrich_batch']);

        // Register cron handler  
        add_action('rw_contractor_enrichment_cron', [$this, 'process_enrichment_queue']);

        // Schedule hourly processing if not already scheduled
        if (!wp_next_scheduled('rw_contractor_enrichment_cron')) {
            wp_schedule_event(time(), 'hourly', 'rw_contractor_enrichment_cron');
        }
    }

    /**
     * AJAX handler to enrich a single lead
     */
    public function ajax_enrich_lead(): void
    {
        check_ajax_referer('rw_lead_actions', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }

        $lead_id = absint($_POST['lead_id'] ?? 0);
        if (!$lead_id) {
            wp_send_json_error('Invalid lead ID');
        }

        $result = $this->enrich_lead($lead_id);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['error']);
        }
    }

    /**
     * Enrich a single lead with contractor data
     * 
     * @param int $lead_id Post ID of the lead
     * @return array Result with success status
     */
    public function enrich_lead(int $lead_id): array
    {
        $permit_number = get_post_meta($lead_id, '_permit_number', true);

        if (empty($permit_number)) {
            return [
                'success' => false,
                'error' => 'No permit number found for lead'
            ];
        }

        // Check if already enriched recently (within 24 hours)
        $last_enriched = get_post_meta($lead_id, '_contractor_enriched_at', true);
        if ($last_enriched && (time() - strtotime($last_enriched)) < 86400) {
            return [
                'success' => true,
                'message' => 'Already enriched within 24 hours',
                'cached' => true
            ];
        }

        // Fetch contractor data
        $contractor_data = $this->fetch_contractor_data($permit_number);

        if (!$contractor_data['success']) {
            // Log the error but don't fail completely
            update_post_meta($lead_id, '_contractor_fetch_error', $contractor_data['error']);
            return $contractor_data;
        }

        // Update lead with contractor info
        $this->update_lead_with_contractor_data($lead_id, $contractor_data);

        return [
            'success' => true,
            'contractor' => $contractor_data['contractor'] ?? [],
            'owner' => $contractor_data['owner'] ?? [],
            'applicant' => $contractor_data['applicant'] ?? []
        ];
    }

    /**
     * Fetch contractor data from LADBS via Python scraper
     * 
     * @param string $permit_number Permit number
     * @return array Scraper result
     */
    public function fetch_contractor_data(string $permit_number): array
    {
        // Try Python scraper first
        $result = $this->run_python_scraper($permit_number);

        if ($result !== null) {
            return $result;
        }

        // Fallback: Return empty with note to scrape manually
        return [
            'success' => false,
            'error' => 'Python scraper not available. Run manually: python ladbs_scraper.py --permit ' . $permit_number
        ];
    }

    /**
     * Run the Python scraper script
     * 
     * @param string $permit_number Permit number
     * @return array|null Scraper result or null if unavailable
     */
    private function run_python_scraper(string $permit_number): ?array
    {
        if (!file_exists($this->scraper_path)) {
            return null;
        }

        // Find Python executable
        $python_paths = ['python3', 'python', 'py'];
        $python = null;

        foreach ($python_paths as $path) {
            $check = shell_exec("which $path 2>/dev/null") ?: shell_exec("where $path 2>nul");
            if ($check) {
                $python = trim($check);
                break;
            }
        }

        if (!$python) {
            return null;
        }

        // Check if playwright is installed
        $check_playwright = shell_exec("$python -c \"import playwright\" 2>&1");
        if (strpos($check_playwright, 'Error') !== false || strpos($check_playwright, 'No module') !== false) {
            return null;
        }

        // Run scraper
        $escaped_permit = escapeshellarg($permit_number);
        $cmd = "$python " . escapeshellarg($this->scraper_path) . " --permit $escaped_permit --headless 2>&1";

        $output = shell_exec($cmd);

        if (empty($output)) {
            return null;
        }

        $data = json_decode($output, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'error' => 'Invalid JSON from scraper: ' . substr($output, 0, 200)
            ];
        }

        // Scraper returns array of results
        return $data[0] ?? $data;
    }

    /**
     * Update lead post meta with contractor data
     * 
     * @param int $lead_id Lead post ID
     * @param array $data Contractor data from scraper
     */
    private function update_lead_with_contractor_data(int $lead_id, array $data): void
    {
        // Owner info
        if (!empty($data['owner'])) {
            update_post_meta($lead_id, '_owner_name', $data['owner']['name'] ?? '');
            update_post_meta($lead_id, '_owner_address', $data['owner']['address'] ?? '');
            update_post_meta($lead_id, '_owner_phone', $data['owner']['phone'] ?? '');
            update_post_meta($lead_id, '_owner_email', $data['owner']['email'] ?? '');
        }

        // Contractor info
        if (!empty($data['contractor'])) {
            update_post_meta($lead_id, '_contractor_name', $data['contractor']['name'] ?? '');
            update_post_meta($lead_id, '_contractor_company', $data['contractor']['company'] ?? '');
            update_post_meta($lead_id, '_contractor_license', $data['contractor']['license_number'] ?? '');
            update_post_meta($lead_id, '_contractor_phone', $data['contractor']['phone'] ?? '');
            update_post_meta($lead_id, '_contractor_address', $data['contractor']['address'] ?? '');
        }

        // Applicant info
        if (!empty($data['applicant'])) {
            update_post_meta($lead_id, '_applicant_name', $data['applicant']['name'] ?? '');
            update_post_meta($lead_id, '_applicant_phone', $data['applicant']['phone'] ?? '');
            update_post_meta($lead_id, '_applicant_email', $data['applicant']['email'] ?? '');
        }

        // Store raw data for reference
        update_post_meta($lead_id, '_contractor_raw_data', wp_json_encode($data['raw_data'] ?? []));

        // Mark as enriched
        update_post_meta($lead_id, '_contractor_enriched_at', current_time('mysql'));
        delete_post_meta($lead_id, '_contractor_fetch_error');
    }

    /**
     * Queue a lead for contractor enrichment
     * 
     * @param int $lead_id Lead post ID
     */
    public function queue_for_enrichment(int $lead_id): void
    {
        $queue = get_option('rw_contractor_enrichment_queue', []);

        if (!in_array($lead_id, $queue, true)) {
            $queue[] = $lead_id;
            update_option('rw_contractor_enrichment_queue', $queue);
        }
    }

    /**
     * Queue all unenriched leads
     * 
     * @param int $limit Max leads to queue
     * @return int Number of leads queued
     */
    public function queue_unenriched_leads(int $limit = 100): int
    {
        $leads = get_posts([
            'post_type' => 'rw_lead',
            'posts_per_page' => $limit,
            'post_status' => 'publish',
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => '_permit_number',
                    'compare' => 'EXISTS'
                ],
                [
                    'key' => '_contractor_enriched_at',
                    'compare' => 'NOT EXISTS'
                ]
            ],
            'fields' => 'ids'
        ]);

        foreach ($leads as $lead_id) {
            $this->queue_for_enrichment($lead_id);
        }

        return count($leads);
    }

    /**
     * Process the enrichment queue (called by cron)
     * 
     * @param int $batch_size Number of leads to process per run
     */
    public function process_enrichment_queue(int $batch_size = 10): array
    {
        $queue = get_option('rw_contractor_enrichment_queue', []);

        if (empty($queue)) {
            return ['processed' => 0, 'remaining' => 0];
        }

        $to_process = array_splice($queue, 0, $batch_size);
        update_option('rw_contractor_enrichment_queue', $queue);

        $results = [];
        foreach ($to_process as $lead_id) {
            $results[$lead_id] = $this->enrich_lead($lead_id);

            // Rate limit - wait 2 seconds between requests
            if (count($results) < count($to_process)) {
                sleep(2);
            }
        }

        return [
            'processed' => count($results),
            'remaining' => count($queue),
            'results' => $results
        ];
    }

    /**
     * AJAX handler for batch enrichment
     */
    public function ajax_enrich_batch(): void
    {
        check_ajax_referer('rw_lead_actions', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }

        // Queue unenriched leads
        $queued = $this->queue_unenriched_leads(50);

        // Process a batch
        $result = $this->process_enrichment_queue(5);

        wp_send_json_success([
            'queued' => $queued,
            'processed' => $result['processed'],
            'remaining' => $result['remaining']
        ]);
    }

    /**
     * Get enrichment status for a lead
     * 
     * @param int $lead_id Lead post ID
     * @return array Status info
     */
    public function get_enrichment_status(int $lead_id): array
    {
        return [
            'enriched' => (bool) get_post_meta($lead_id, '_contractor_enriched_at', true),
            'enriched_at' => get_post_meta($lead_id, '_contractor_enriched_at', true),
            'error' => get_post_meta($lead_id, '_contractor_fetch_error', true),
            'has_contractor' => (bool) get_post_meta($lead_id, '_contractor_name', true),
            'has_owner' => (bool) get_post_meta($lead_id, '_owner_name', true),
        ];
    }
}

// Initialize
add_action('init', function () {
    new Contractor_Enrichment();
});
