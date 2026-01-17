<?php
/**
 * Workflow AJAX Handlers
 * Path: cores/template-engine/workflow-handlers.php
 *
 * AJAX handlers for template workflow operations:
 * - Approval/rejection of items
 * - AI content generation
 * - Publishing to outlets
 * - Bulk operations
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * RawWire Workflow Handlers Class
 */
class RawWire_Workflow_Handlers {

    /**
     * Initialize handlers
     */
    public static function init() {
        // Workflow status updates
        add_action('wp_ajax_rawwire_workflow_update', array(__CLASS__, 'ajax_workflow_update'));
        add_action('wp_ajax_rawwire_bulk_action', array(__CLASS__, 'ajax_bulk_action'));

        // Item operations
        add_action('wp_ajax_rawwire_item_detail', array(__CLASS__, 'ajax_item_detail'));
        add_action('wp_ajax_rawwire_item_update', array(__CLASS__, 'ajax_item_update'));

        // Panel refresh
        add_action('wp_ajax_rawwire_panel_refresh', array(__CLASS__, 'ajax_panel_refresh'));

        // Toolbox operations
        add_action('wp_ajax_rawwire_toolbox_run', array(__CLASS__, 'ajax_toolbox_run'));
        add_action('wp_ajax_rawwire_run_scraper', array(__CLASS__, 'ajax_run_scraper'));

        // Outlets
        add_action('wp_ajax_rawwire_get_outlets', array(__CLASS__, 'ajax_get_outlets'));

        // Settings
        add_action('wp_ajax_rawwire_save_setting', array(__CLASS__, 'ajax_save_setting'));
        add_action('wp_ajax_rawwire_save_settings', array(__CLASS__, 'ajax_save_settings'));
        add_action('wp_ajax_rawwire_save_template_setting', array(__CLASS__, 'ajax_save_template_setting'));
        add_action('wp_ajax_rawwire_update_custom_sources', array(__CLASS__, 'ajax_update_custom_sources'));
        // Admin helpers
        add_action('wp_ajax_rawwire_get_last_batch', array(__CLASS__, 'ajax_get_last_batch'));

        // Source management
        add_action('wp_ajax_rawwire_test_source', array(__CLASS__, 'ajax_test_source'));
        add_action('wp_ajax_rawwire_add_source', array(__CLASS__, 'ajax_add_source'));
        add_action('wp_ajax_rawwire_toggle_template_source', array(__CLASS__, 'ajax_toggle_template_source'));
    }

    /**
     * Verify nonce for template actions
     */
    protected static function verify_nonce() {
        if (!check_ajax_referer('rawwire_template_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Security check failed'));
            exit;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            exit;
        }
    }

    /**
     * Get workflow table names
     * Returns array with all 6-stage workflow table names plus legacy tables
     * @return array Table names keyed by stage name
     */
    protected static function get_workflow_tables() {
        global $wpdb;
        return array(
            'candidates' => $wpdb->prefix . 'rawwire_candidates',
            'approvals'  => $wpdb->prefix . 'rawwire_approvals',
            'content'    => $wpdb->prefix . 'rawwire_content',
            'releases'   => $wpdb->prefix . 'rawwire_releases',
            'published'  => $wpdb->prefix . 'rawwire_published',
            'archives'   => $wpdb->prefix . 'rawwire_archives',
            // Legacy tables (deprecated - use workflow tables above)
            'findings'   => $wpdb->prefix . 'rawwire_findings',
            'queue'      => $wpdb->prefix . 'rawwire_queue',
        );
    }

    /**
     * Update workflow item status
     */
    public static function ajax_workflow_update() {
        self::verify_nonce();

        $item_id = intval($_POST['item_id'] ?? 0);
        $status = sanitize_text_field($_POST['status'] ?? '');
        $source_table = sanitize_text_field($_POST['source_table'] ?? 'approvals');

        if (!$item_id || !in_array($status, array('pending', 'approved', 'rejected', 'processing', 'published'))) {
            wp_send_json_error(array('message' => 'Invalid parameters'));
        }

        global $wpdb;
        $tables = self::get_workflow_tables();
        
        // Use the specified table or default to approvals
        $table_key = in_array($source_table, array('candidates', 'approvals', 'content', 'releases', 'published', 'archives')) 
            ? $source_table 
            : 'approvals';
        $source_table_name = $tables[$table_key];

        // Update the source table
        $updated = $wpdb->update(
            $source_table_name,
            array(
                'status' => $status,
                'updated_at' => current_time('mysql')
            ),
            array('id' => $item_id),
            array('%s', '%s'),
            array('%d')
        );

        if ($updated === false) {
            wp_send_json_error(array('message' => 'Database update failed'));
        }

        // If approved, move item to next workflow stage
        if ($status === 'approved') {
            $item = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM $source_table_name WHERE id = %d", $item_id),
                ARRAY_A
            );

            if ($item) {
                // Determine next stage based on current table
                $stage_order = array('candidates', 'approvals', 'content', 'releases', 'published');
                $current_index = array_search($table_key, $stage_order);
                $next_stage = ($current_index !== false && isset($stage_order[$current_index + 1])) 
                    ? $stage_order[$current_index + 1] 
                    : null;

                if ($next_stage) {
                    $next_table = $tables[$next_stage];
                    
                    // Copy item to next stage
                    $insert_data = array(
                        'title' => $item['title'] ?? '',
                        'content' => $item['content'] ?? '',
                        'excerpt' => $item['excerpt'] ?? '',
                        'url' => $item['url'] ?? '',
                        'source' => $item['source'] ?? '',
                        'category' => $item['category'] ?? '',
                        'score' => $item['score'] ?? 0,
                        'status' => 'pending',
                        'metadata' => $item['metadata'] ?? null,
                        'created_at' => current_time('mysql'),
                        'updated_at' => current_time('mysql'),
                    );
                    $wpdb->insert($next_table, $insert_data);
                }
            }
        }

        // Log the action
        if (class_exists('RawWire_Logger')) {
            RawWire_Logger::log("Workflow item $item_id in $table_key status changed to $status", 'info');
        }

        wp_send_json_success(array(
            'message' => 'Status updated successfully',
            'status' => $status
        ));
    }

    /**
     * Handle bulk actions
     */
    public static function ajax_bulk_action() {
        self::verify_nonce();

        $bulk_action = sanitize_text_field($_POST['bulk_action'] ?? '');
        $item_ids = isset($_POST['item_ids']) ? array_map('intval', $_POST['item_ids']) : array();
        $source_table = sanitize_text_field($_POST['source_table'] ?? 'approvals');

        if (empty($item_ids) || !in_array($bulk_action, array('approve', 'reject', 'publish', 'delete'))) {
            wp_send_json_error(array('message' => 'Invalid parameters'));
        }

        global $wpdb;
        $tables = self::get_workflow_tables();
        
        // Use the specified workflow table or default to approvals
        $table_key = in_array($source_table, array('candidates', 'approvals', 'content', 'releases', 'published', 'archives')) 
            ? $source_table 
            : 'approvals';
        $table_name = $tables[$table_key];
        $success_count = 0;

        foreach ($item_ids as $item_id) {
            $status = ($bulk_action === 'approve') ? 'approved' : 
                      (($bulk_action === 'reject') ? 'rejected' : 
                      (($bulk_action === 'publish') ? 'published' : 'deleted'));

            if ($bulk_action === 'delete') {
                $result = $wpdb->delete($table_name, array('id' => $item_id), array('%d'));
            } else {
                $result = $wpdb->update(
                    $table_name,
                    array('status' => $status, 'updated_at' => current_time('mysql')),
                    array('id' => $item_id)
                );
            }

            if ($result !== false) {
                $success_count++;
            }
        }

        wp_send_json_success(array(
            'message' => "Bulk action completed: $success_count items updated",
            'count' => $success_count
        ));
    }

    /**
     * Get item details - searches across all workflow tables
     */
    public static function ajax_item_detail() {
        self::verify_nonce();

        $item_id = intval($_POST['item_id'] ?? 0);
        $source_table = sanitize_text_field($_POST['source_table'] ?? '');

        if (!$item_id) {
            wp_send_json_error(array('message' => 'Invalid item ID'));
        }

        global $wpdb;
        $tables = self::get_workflow_tables();
        $item = null;
        $found_in = '';

        // If source table specified, try that first
        if ($source_table && isset($tables[$source_table])) {
            $table_name = $tables[$source_table];
            $item = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $item_id),
                ARRAY_A
            );
            if ($item) {
                $found_in = $source_table;
            }
        }

        // If not found, search all workflow tables
        if (!$item) {
            $search_order = array('candidates', 'approvals', 'content', 'releases', 'published', 'archives');
            foreach ($search_order as $table_key) {
                $table_name = $tables[$table_key];
                $item = $wpdb->get_row(
                    $wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $item_id),
                    ARRAY_A
                );
                if ($item) {
                    $found_in = $table_key;
                    break;
                }
            }
        }

        if (!$item) {
            wp_send_json_error(array('message' => 'Item not found'));
        }

        // Build content HTML
        $content = '<div class="rawwire-item-detail">';
        $content .= '<h3>' . esc_html($item['title']) . '</h3>';

        if (!empty($item['url'])) {
            $content .= '<p><a href="' . esc_url($item['url']) . '" target="_blank">View Original</a></p>';
        }

        $content .= '<div class="rawwire-item-content">' . wp_kses_post($item['content'] ?? $item['original_content'] ?? '') . '</div>';

        if (!empty($item['generated_content'])) {
            $content .= '<h4>Generated Content</h4>';
            $content .= '<div class="rawwire-generated-content">' . wp_kses_post($item['generated_content']) . '</div>';
        }

        $content .= '</div>';

        wp_send_json_success(array(
            'title' => $item['title'],
            'content' => $content,
            'content_text' => strip_tags($item['content'] ?? $item['original_content'] ?? ''),
            'excerpt' => $item['excerpt'] ?? '',
            'source_table' => $found_in,
            'raw' => $item
        ));
    }

    /**
     * Update item field
     */
    public static function ajax_item_update() {
        self::verify_nonce();

        $item_id = intval($_POST['item_id'] ?? 0);
        $field = sanitize_text_field($_POST['field'] ?? '');
        $value = wp_kses_post($_POST['value'] ?? '');
        $move_stage = sanitize_text_field($_POST['move_stage'] ?? '');

        if (!$item_id || !$field) {
            wp_send_json_error(array('message' => 'Invalid parameters'));
        }

        global $wpdb;
        $queue_table = $wpdb->prefix . 'rawwire_queue';

        $update_data = array(
            $field => $value,
            'updated_at' => current_time('mysql')
        );

        if ($move_stage) {
            $update_data['stage'] = $move_stage;
        }

        $updated = $wpdb->update(
            $queue_table,
            $update_data,
            array('id' => $item_id)
        );

        if ($updated === false) {
            wp_send_json_error(array('message' => 'Update failed'));
        }

        wp_send_json_success(array('message' => 'Item updated'));
    }

    /**
     * Refresh a panel
     */
    public static function ajax_panel_refresh() {
        self::verify_nonce();

        $panel_id = sanitize_text_field($_POST['panel_id'] ?? '');

        if (!$panel_id) {
            wp_send_json_error(array('message' => 'Invalid panel ID'));
        }

        if (!class_exists('RawWire_Template_Engine') || !class_exists('RawWire_Panel_Renderer')) {
            wp_send_json_error(array('message' => 'Template engine not available'));
        }

        $panel_config = RawWire_Template_Engine::get_panel($panel_id);

        if (!$panel_config) {
            wp_send_json_error(array('message' => 'Panel not found'));
        }

        $html = RawWire_Panel_Renderer::render($panel_config);

        wp_send_json_success(array('html' => $html));
    }

    /**
     * Return the last batch marker so front-end can detect new approvals
     */
    public static function ajax_get_last_batch() {
        self::verify_nonce();

        $time = get_option('rawwire_last_batch_time', 0);
        $ids = get_option('rawwire_last_batch_ids', '[]');

        wp_send_json_success(array('time' => intval($time), 'ids' => json_decode($ids, true)));
    }

    /**
     * Run a toolbox operation
     */
    public static function ajax_toolbox_run() {
        self::verify_nonce();

        $toolbox = sanitize_text_field($_POST['toolbox'] ?? '');

        if (!in_array($toolbox, array('scraper', 'generator', 'poster', 'ai_discovery'))) {
            wp_send_json_error(array('message' => 'Invalid toolbox'));
        }

        switch ($toolbox) {
            case 'scraper':
                $result = self::run_scraper();
                break;
            case 'generator':
                $result = self::run_generator();
                break;
            case 'poster':
                $result = self::run_poster();
                break;
            case 'ai_discovery':
                $result = self::run_ai_discovery();
                break;
            default:
                wp_send_json_error(array('message' => 'Unknown toolbox'));
                return;
        }

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Run the scraper
     * @deprecated Use workflow orchestrator instead. This method writes to candidates table for backwards compatibility.
     */
    protected static function run_scraper() {
        if (!class_exists('RawWire_Template_Engine')) {
            return array('success' => false, 'message' => 'Template engine not available');
        }

        $template = RawWire_Template_Engine::get_active_template();
        $sources = $template['sources'] ?? array();

        if (empty($sources)) {
            return array('success' => false, 'message' => 'No sources configured');
        }

        global $wpdb;
        $tables = self::get_workflow_tables();
        $candidates_table = $tables['candidates'];
        $sources_table = $wpdb->prefix . 'rawwire_sources';
        $count = 0;

        foreach ($sources as $source) {
            if (!($source['enabled'] ?? true)) {
                continue;
            }

            $items = self::fetch_source($source);

            foreach ($items as $item) {
                // Check for duplicates in candidates table
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $candidates_table WHERE title = %s AND source = %s",
                    $item['title'],
                    $source['id'] ?? $source['label'] ?? 'unknown'
                ));

                if (!$exists) {
                    // Calculate score
                    $score = self::calculate_score($item);

                    $wpdb->insert($candidates_table, array(
                        'source' => $source['id'] ?? $source['label'] ?? 'unknown',
                        'title' => sanitize_text_field($item['title']),
                        'content' => wp_kses_post($item['content'] ?? $item['description'] ?? ''),
                        'excerpt' => sanitize_text_field(wp_trim_words($item['content'] ?? $item['description'] ?? '', 50)),
                        'url' => esc_url_raw($item['link'] ?? $item['url'] ?? ''),
                        'category' => $source['category'] ?? 'uncategorized',
                        'score' => $score,
                        'status' => 'pending',
                        'metadata' => json_encode(array('source_type' => $source['type'] ?? 'rss')),
                        'created_at' => current_time('mysql'),
                        'updated_at' => current_time('mysql')
                    ));
                    $count++;
                }
            }

            // Update source last fetched
            $wpdb->update(
                $sources_table,
                array(
                    'last_fetched' => current_time('mysql'),
                    'fetch_count' => new stdClass() // Will be incremented
                ),
                array('id' => $source['id'])
            );
        }

        // Update last scrape time
        update_option('rawwire_last_scrape_time', current_time('mysql'));

        return array(
            'success' => true,
            'message' => "Scraper completed: $count new items found",
            'count' => $count
        );
    }

    /**
     * Fetch items from a source
     */
    protected static function fetch_source($source) {
        $items = array();

        switch ($source['type'] ?? 'rss') {
            case 'rss':
                $items = self::fetch_rss($source['url']);
                break;
            case 'api':
                $items = self::fetch_api($source['url'], $source);
                break;
            default:
                break;
        }

        return $items;
    }

    /**
     * Fetch RSS feed
     */
    protected static function fetch_rss($url) {
        include_once(ABSPATH . WPINC . '/feed.php');

        $rss = fetch_feed($url);

        if (is_wp_error($rss)) {
            return array();
        }

        $max_items = $rss->get_item_quantity(20);
        $rss_items = $rss->get_items(0, $max_items);
        $items = array();

        foreach ($rss_items as $item) {
            $items[] = array(
                'title' => $item->get_title(),
                'content' => $item->get_content(),
                'description' => $item->get_description(),
                'link' => $item->get_link(),
                'date' => $item->get_date('Y-m-d H:i:s')
            );
        }

        return $items;
    }

    /**
     * Fetch API endpoint
     */
    protected static function fetch_api($url, $source) {
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'headers' => $source['headers'] ?? array()
        ));

        if (is_wp_error($response)) {
            return array();
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data)) {
            return array();
        }

        // Handle different API response formats
        if (isset($data['items'])) {
            return $data['items'];
        } elseif (isset($data['results'])) {
            return $data['results'];
        } elseif (isset($data['data'])) {
            return $data['data'];
        }

        return is_array($data) && isset($data[0]) ? $data : array();
    }

    /**
     * Calculate relevance score
     */
    protected static function calculate_score($item) {
        $score = 50; // Base score

        // Boost for keywords (simple implementation)
        $keywords = array('AI', 'technology', 'innovation', 'breaking', 'exclusive');
        $title = strtolower($item['title'] ?? '');
        $content = strtolower($item['content'] ?? $item['description'] ?? '');

        foreach ($keywords as $keyword) {
            if (strpos($title, strtolower($keyword)) !== false) {
                $score += 10;
            }
            if (strpos($content, strtolower($keyword)) !== false) {
                $score += 5;
            }
        }

        // Cap at 100
        return min(100, $score);
    }

    /**
     * Run the AI generator
     */
    protected static function run_generator() {
        $mode = sanitize_text_field($_POST['mode'] ?? 'rewrite');
        $input = wp_kses_post($_POST['input'] ?? '');
        $options = json_decode(stripslashes($_POST['options'] ?? '{}'), true);

        if (empty($input)) {
            return array('success' => false, 'message' => 'No input content');
        }

        // Get generator settings
        $api_key = get_option('rawwire_generator_api_key', '');
        $model = get_option('rawwire_generator_model', 'gpt-4');

        if (empty($api_key)) {
            // Return mock response for testing
            $output = self::mock_generate($input, $mode, $options);
        } else {
            // Call actual AI API
            $output = self::call_ai_api($input, $mode, $options, $api_key, $model);
        }

        return array(
            'success' => true,
            'output' => $output,
            'mode' => $mode
        );
    }

    /**
     * Mock AI generation for testing
     */
    protected static function mock_generate($input, $mode, $options) {
        $prefix = '';
        switch ($mode) {
            case 'rewrite':
                $audience = $options['audience'] ?? 'general';
                $prefix = "[Rewritten for $audience audience]\n\n";
                break;
            case 'summarize':
                $prefix = "[Summary]\n\n";
                $input = wp_trim_words($input, 50);
                break;
            case 'generate_headline':
                return "1. Breaking: " . wp_trim_words($input, 8) . "\n" .
                       "2. " . ucfirst(wp_trim_words($input, 6)) . " Revealed\n" .
                       "3. What You Need to Know About " . wp_trim_words($input, 5) . "\n" .
                       "4. The Latest on " . wp_trim_words($input, 5) . "\n" .
                       "5. " . wp_trim_words($input, 7) . " - Here's Why It Matters";
            case 'expand':
                $prefix = "[Expanded Article]\n\n";
                break;
        }

        return $prefix . $input;
    }

    /**
     * Call AI API (OpenAI/Anthropic)
     */
    protected static function call_ai_api($input, $mode, $options, $api_key, $model) {
        if (!class_exists('RawWire_Template_Engine')) {
            return self::mock_generate($input, $mode, $options);
        }

        $template = RawWire_Template_Engine::get_active_template();
        $prompts = $template['toolbox']['generator']['prompts'] ?? array();
        $prompt_template = $prompts[$mode] ?? "Process this content: {{content}}";

        // Replace placeholders
        $prompt = str_replace('{{content}}', $input, $prompt_template);
        $prompt = str_replace('{{audience}}', $options['audience'] ?? 'general', $prompt);
        $prompt = str_replace('{{word_count}}', $options['word_count'] ?? '500', $prompt);

        // Determine API endpoint based on model
        if (strpos($model, 'claude') !== false) {
            return self::call_anthropic($prompt, $api_key, $model);
        } else {
            return self::call_openai($prompt, $api_key, $model);
        }
    }

    /**
     * Call OpenAI API
     */
    protected static function call_openai($prompt, $api_key, $model) {
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'model' => $model,
                'messages' => array(
                    array('role' => 'user', 'content' => $prompt)
                ),
                'max_tokens' => 2000,
                'temperature' => 0.7
            )),
            'timeout' => 60
        ));

        if (is_wp_error($response)) {
            return 'Error: ' . $response->get_error_message();
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            return 'API Error: ' . ($body['error']['message'] ?? 'Unknown error');
        }

        return $body['choices'][0]['message']['content'] ?? '';
    }

    /**
     * Call Anthropic API
     */
    protected static function call_anthropic($prompt, $api_key, $model) {
        $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
            'headers' => array(
                'x-api-key' => $api_key,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'model' => $model,
                'max_tokens' => 2000,
                'messages' => array(
                    array('role' => 'user', 'content' => $prompt)
                )
            )),
            'timeout' => 60
        ));

        if (is_wp_error($response)) {
            return 'Error: ' . $response->get_error_message();
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            return 'API Error: ' . ($body['error']['message'] ?? 'Unknown error');
        }

        return $body['content'][0]['text'] ?? '';
    }

    /**
     * Run the poster
     */
    protected static function run_poster() {
        $item_id = intval($_POST['item_id'] ?? 0);
        $outlets = json_decode(stripslashes($_POST['outlets'] ?? '[]'), true);
        $schedule = sanitize_text_field($_POST['schedule'] ?? 'now');
        $schedule_time = sanitize_text_field($_POST['schedule_time'] ?? '');

        if (!$item_id) {
            return array('success' => false, 'message' => 'No item specified');
        }

        global $wpdb;
        $queue_table = $wpdb->prefix . 'rawwire_queue';

        $item = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $queue_table WHERE id = %d", $item_id),
            ARRAY_A
        );

        if (!$item) {
            return array('success' => false, 'message' => 'Item not found');
        }

        $published_to = array();

        foreach ($outlets as $outlet) {
            $result = self::publish_to_outlet($item, $outlet, $schedule, $schedule_time);
            if ($result) {
                $published_to[] = $outlet;
            }
        }

        // Update item status
        $wpdb->update(
            $queue_table,
            array(
                'status' => ($schedule === 'now') ? 'published' : 'scheduled',
                'outlets' => json_encode($published_to),
                'scheduled_at' => ($schedule !== 'now') ? self::calculate_schedule_time($schedule, $schedule_time) : null,
                'published_at' => ($schedule === 'now') ? current_time('mysql') : null,
                'updated_at' => current_time('mysql')
            ),
            array('id' => $item_id)
        );

        return array(
            'success' => true,
            'message' => ($schedule === 'now') ? 
                'Published to ' . count($published_to) . ' outlet(s)' : 
                'Scheduled for ' . count($published_to) . ' outlet(s)'
        );
    }

    /**
     * Publish to a specific outlet
     */
    protected static function publish_to_outlet($item, $outlet_id, $schedule, $schedule_time) {
        switch ($outlet_id) {
            case 'wordpress':
                return self::publish_to_wordpress($item, $schedule, $schedule_time);
            case 'twitter':
                return self::publish_to_twitter($item);
            case 'linkedin':
                return self::publish_to_linkedin($item);
            default:
                return false;
        }
    }

    /**
     * Publish to WordPress
     */
    protected static function publish_to_wordpress($item, $schedule, $schedule_time) {
        $default_status = get_option('rawwire_publish_default_status', 'draft');

        $post_data = array(
            'post_title' => $item['title'],
            'post_content' => $item['generated_content'] ?? $item['original_content'],
            'post_status' => $default_status,
            'post_author' => get_current_user_id(),
            'post_type' => 'post'
        );

        if ($schedule !== 'now' && !empty($schedule_time)) {
            $post_data['post_date'] = $schedule_time;
            $post_data['post_status'] = 'future';
        }

        // Ensure citation present when publishing
        $citation = '';
        if (!empty($item['copyright_info'])) {
            $ci = is_string($item['copyright_info']) ? json_decode($item['copyright_info'], true) : $item['copyright_info'];
            if (!empty($ci['citation'])) {
                $citation = $ci['citation'];
            }
        } elseif (!empty($item['payload'])) {
            $pl = is_string($item['payload']) ? json_decode($item['payload'], true) : $item['payload'];
            if (!empty($pl['copyright_info'])) {
                $ci = is_string($pl['copyright_info']) ? json_decode($pl['copyright_info'], true) : $pl['copyright_info'];
                if (!empty($ci['citation'])) {
                    $citation = $ci['citation'];
                }
            }
        }

        if (!empty($citation) && strpos($post_data['post_content'], $citation) === false) {
            $post_data['post_content'] .= "\n\n" . $citation;
        }

        $post_id = wp_insert_post($post_data);

        if (is_wp_error($post_id)) {
            return false;
        }

        // Persist copyright metadata on the created post for provenance
        $is_public_domain = $item['is_public_domain'] ?? null;
        if ($is_public_domain !== null) {
            update_post_meta($post_id, 'rawwire_is_public_domain', intval($is_public_domain));
        }

        if (!empty($ci)) {
            update_post_meta($post_id, 'rawwire_copyright_info', json_encode($ci));
        }

        return true;
    }

    /**
     * Publish to Twitter (placeholder)
     */
    protected static function publish_to_twitter($item) {
        // In production, this would use Twitter API
        if (class_exists('RawWire_Logger')) {
            RawWire_Logger::log("Would publish to Twitter: " . $item['title'], 'info');
        }
        return true; // Mock success
    }

    /**
     * Publish to LinkedIn (placeholder)
     */
    protected static function publish_to_linkedin($item) {
        // In production, this would use LinkedIn API
        if (class_exists('RawWire_Logger')) {
            RawWire_Logger::log("Would publish to LinkedIn: " . $item['title'], 'info');
        }
        return true; // Mock success
    }

    /**
     * Calculate schedule time from preset
     */
    protected static function calculate_schedule_time($schedule, $custom_time) {
        if ($schedule === 'custom' && !empty($custom_time)) {
            return $custom_time;
        }

        $hours = array(
            '1h' => 1,
            '3h' => 3,
            '6h' => 6,
            '12h' => 12,
            '24h' => 24
        );

        if (isset($hours[$schedule])) {
            return date('Y-m-d H:i:s', strtotime('+' . $hours[$schedule] . ' hours'));
        }

        return current_time('mysql');
    }

    /**
     * Run scraper AJAX handler
     */
    public static function ajax_run_scraper() {
        self::verify_nonce();
        $result = self::run_scraper();

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Get configured outlets
     */
    public static function ajax_get_outlets() {
        self::verify_nonce();

        if (!class_exists('RawWire_Template_Engine')) {
            wp_send_json_error(array('message' => 'Template engine not available'));
        }

        $template = RawWire_Template_Engine::get_active_template();
        $outlets = $template['toolbox']['poster']['outlets'] ?? array();

        wp_send_json_success(array('outlets' => $outlets));
    }

    /**
     * Save a single setting
     */
    public static function ajax_save_setting() {
        self::verify_nonce();

        $key = sanitize_text_field($_POST['key'] ?? '');
        $value = sanitize_text_field($_POST['value'] ?? '');

        if (empty($key)) {
            wp_send_json_error(array('message' => 'Invalid setting key'));
        }

        update_option('rawwire_' . $key, $value);

        wp_send_json_success(array('message' => 'Setting saved'));
    }

    /**
     * Save multiple settings
     */
    public static function ajax_save_settings() {
        self::verify_nonce();

        $settings = $_POST;
        unset($settings['action'], $settings['nonce']);

        foreach ($settings as $key => $value) {
            $key = sanitize_text_field($key);
            $value = is_array($value) ? array_map('sanitize_text_field', $value) : sanitize_text_field($value);
            update_option('rawwire_' . $key, $value);
        }

        wp_send_json_success(array('message' => 'Settings saved'));
    }

    /**
     * Test a source
     */
    public static function ajax_test_source() {
        self::verify_nonce();

        $url = esc_url_raw($_POST['new_source_url'] ?? '');
        $type = sanitize_text_field($_POST['new_source_type'] ?? 'rss');

        if (empty($url)) {
            wp_send_json_error(array('message' => 'URL is required'));
        }

        $source = array(
            'url' => $url,
            'type' => $type
        );

        $items = self::fetch_source($source);

        if (empty($items)) {
            wp_send_json_error(array('message' => 'No items found. Check the URL and source type.'));
        }

        wp_send_json_success(array(
            'message' => 'Source test successful! Found ' . count($items) . ' items.',
            'sample' => array_slice($items, 0, 3)
        ));
    }

    /**
     * Add a new source
     */
    public static function ajax_add_source() {
        self::verify_nonce();

        $name = sanitize_text_field($_POST['new_source_name'] ?? '');
        $url = esc_url_raw($_POST['new_source_url'] ?? '');
        $type = sanitize_text_field($_POST['new_source_type'] ?? 'rss');
        $category = sanitize_text_field($_POST['new_source_category'] ?? 'uncategorized');

        if (empty($name) || empty($url)) {
            wp_send_json_error(array('message' => 'Name and URL are required'));
        }

        $id = sanitize_title($name);

        global $wpdb;
        $table = $wpdb->prefix . 'rawwire_sources';

        // Check if table exists, create if not
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            RawWire_Template_Engine::ensure_tables();
        }

        $result = $wpdb->insert($table, array(
            'id' => $id,
            'name' => $name,
            'type' => $type,
            'url' => $url,
            'category' => $category,
            'enabled' => 1,
            'created_at' => current_time('mysql')
        ));

        if ($result === false) {
            wp_send_json_error(array('message' => 'Failed to add source'));
        }

        wp_send_json_success(array('message' => 'Source added successfully'));
    }

    /**
     * Toggle template source enabled/disabled
     */
    public static function ajax_toggle_template_source() {
        // Accept multiple nonce types
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'rawwire_template_nonce') &&
            !wp_verify_nonce($_POST['nonce'] ?? '', 'rawwire_ajax_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        $source_id = sanitize_key($_POST['source_id'] ?? '');
        $enabled = filter_var($_POST['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        
        if (empty($source_id)) {
            wp_send_json_error(array('message' => 'Source ID required'));
            return;
        }
        
        // Get active template and update source
        $template = RawWire_Template_Engine::get_active_template();
        
        if (!isset($template['sources']) || !is_array($template['sources'])) {
            wp_send_json_error(array('message' => 'No template sources found'));
            return;
        }
        
        $updated = false;
        foreach ($template['sources'] as &$source) {
            if (isset($source['id']) && $source['id'] === $source_id) {
                $source['enabled'] = $enabled;
                $updated = true;
                break;
            }
        }
        
        if (!$updated) {
            wp_send_json_error(array('message' => 'Source not found'));
            return;
        }
        
        // Save updated template
        $template_id = get_option('rawwire_active_template', 'news-aggregator');
        update_option('rawwire_template_' . $template_id . '_sources', $template['sources']);
        
        wp_send_json_success(array(
            'message' => 'Source ' . ($enabled ? 'enabled' : 'disabled'),
            'source_id' => $source_id,
            'enabled' => $enabled
        ));
    }

    /**
     * Run AI Discovery
     */
    protected static function run_ai_discovery() {
        if (!class_exists('RawWire_AI_Discovery')) {
            return array('success' => false, 'message' => 'AI Discovery engine not available');
        }

        $count = RawWire_AI_Discovery::run_discovery();

        return array(
            'success' => true,
            'message' => "AI Discovery completed: $count facts found",
            'facts_found' => $count
        );
    }

    /**
     * Run AI Analysis on content
     */
    protected static function run_ai_analysis($content, $title = '') {
        $api_key = get_option('rawwire_generator_api_key', '');
        $model = get_option('rawwire_generator_model', 'gpt-4');

        if (empty($api_key)) {
            // Mock analysis
            return array(
                'shock_value' => rand(60, 95),
                'credibility' => rand(70, 90),
                'timeliness' => rand(50, 100),
                'uniqueness' => rand(60, 95),
                'overall_score' => rand(70, 90),
                'analysis' => 'Mock AI analysis - API key not configured'
            );
        }

        $prompt = "Analyze this content for shock value, credibility, timeliness, and uniqueness. Rate each on a scale of 1-100. Content: \"$title\" - $content";

        $response = self::call_openai($prompt, $api_key, $model);

        // Parse response (simplified)
        return array(
            'shock_value' => rand(60, 95),
            'credibility' => rand(70, 90),
            'timeliness' => rand(50, 100),
            'uniqueness' => rand(60, 95),
            'overall_score' => rand(70, 90),
            'analysis' => $response
        );
    }

    /**
     * Calculate advanced AI-powered score
     */
    protected static function calculate_advanced_score($item) {
        if (!class_exists('RawWire_Template_Engine')) {
            return self::calculate_score($item);
        }

        $template = RawWire_Template_Engine::get_active_template();
        $weights = $template['workflow']['stages']['score']['config']['weights'] ?? array(
            'shock_value' => 0.4,
            'credibility' => 0.3,
            'timeliness' => 0.2,
            'uniqueness' => 0.1
        );

        // Run AI analysis
        $analysis = self::run_ai_analysis($item['content'], $item['title']);

        // Calculate weighted score
        $score = ($analysis['shock_value'] * $weights['shock_value']) +
                 ($analysis['credibility'] * $weights['credibility']) +
                 ($analysis['timeliness'] * $weights['timeliness']) +
                 ($analysis['uniqueness'] * $weights['uniqueness']);

        return round(min(100, max(0, $score)));
    }

    /**
     * Run content generation with AI
     */
    protected static function run_content_generation($item) {
        $api_key = get_option('rawwire_generator_api_key', '');
        $model = get_option('rawwire_generator_model', 'gpt-4');

        if (empty($api_key)) {
            // Mock generation
            $content = "[AI Generated Article]\n\n" . $item['title'] . "\n\n" . ($item['generated_content'] ?? $item['original_content'] ?? $item['content'] ?? '') . "\n\nThis shocking discovery reveals important insights that demand attention.";

            // Resolve copyright info from payload or DB if necessary
            $copyright_info = null;
            if (!empty($item['copyright_info'])) {
                $copyright_info = is_string($item['copyright_info']) ? json_decode($item['copyright_info'], true) : $item['copyright_info'];
            } elseif (!empty($item['payload'])) {
                $pl = is_string($item['payload']) ? json_decode($item['payload'], true) : $item['payload'];
                if (!empty($pl['copyright_info'])) {
                    $copyright_info = is_string($pl['copyright_info']) ? json_decode($pl['copyright_info'], true) : $pl['copyright_info'];
                }
            } elseif (!empty($item['metadata'])) {
                // Try to get from item metadata (workflow tables)
                $metadata = is_string($item['metadata']) ? json_decode($item['metadata'], true) : $item['metadata'];
                if (!empty($metadata['copyright_info'])) {
                    $copyright_info = $metadata['copyright_info'];
                }
            }

            if (!empty($copyright_info['citation'])) {
                $content .= "\n\n" . $copyright_info['citation'];
            }

            return $content;
        }

        $template = RawWire_Template_Engine::get_active_template();
        $config = $template['workflow']['stages']['generate']['config'] ?? array();

        $prompt = str_replace('{title}', $item['title'], $config['prompt_template'] ?? 'Write an engaging article about: {title}');
        $prompt .= "\n\nOriginal content: " . $item['content'];

        $generated_content = self::call_openai($prompt, $api_key, $model);

        // Resolve copyright info from item metadata
        $copyright_info = null;
        if (!empty($item['copyright_info'])) {
            $copyright_info = is_string($item['copyright_info']) ? json_decode($item['copyright_info'], true) : $item['copyright_info'];
        } elseif (!empty($item['payload'])) {
            $pl = is_string($item['payload']) ? json_decode($item['payload'], true) : $item['payload'];
            if (!empty($pl['copyright_info'])) {
                $copyright_info = is_string($pl['copyright_info']) ? json_decode($pl['copyright_info'], true) : $pl['copyright_info'];
            }
        } elseif (!empty($item['metadata'])) {
            $metadata = is_string($item['metadata']) ? json_decode($item['metadata'], true) : $item['metadata'];
            if (!empty($metadata['copyright_info'])) {
                $copyright_info = $metadata['copyright_info'];
            }
        }

        if (!empty($copyright_info['citation'])) {
            $generated_content .= "\n\n" . $copyright_info['citation'];
        }

        return $generated_content;
    }

    /**
     * Run image generation
     */
    protected static function run_image_generation($title) {
        // Placeholder for DALL-E or similar API integration
        return array(
            'success' => true,
            'image_url' => 'https://via.placeholder.com/1200x630/FF6B6B/FFFFFF?text=' . urlencode(substr($title, 0, 50)),
            'alt_text' => $title,
            'generated' => true
        );
    }

    /**
     * Run automated publishing
     */
    protected static function run_automated_publishing($item, $generated_content, $image_data = null) {
        $results = array();

        // Publish to WordPress
        if (get_option('rawwire_auto_wordpress_publish', false)) {
            $post_id = wp_insert_post(array(
                'post_title' => $item['title'],
                'post_content' => $generated_content,
                'post_status' => 'publish',
                'post_author' => 1,
                'post_category' => array(1)
            ));

            if ($post_id) {
                $results['wordpress'] = array('success' => true, 'post_id' => $post_id);

                // Set featured image if available
                if ($image_data && isset($image_data['image_url'])) {
                    // Download and set featured image
                    $image_id = self::download_image_to_media($image_data['image_url'], $post_id);
                    if ($image_id) {
                        set_post_thumbnail($post_id, $image_id);
                    }
                }
            }
        }

        // Social media publishing (placeholder)
        $social_platforms = get_option('rawwire_social_platforms', array());
        foreach ($social_platforms as $platform) {
            $results[$platform] = array('success' => true, 'posted' => true); // Mock success
        }

        return $results;
    }

    /**
     * Download image to WordPress media library
     */
    protected static function download_image_to_media($image_url, $post_id) {
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $tmp = download_url($image_url);
        if (is_wp_error($tmp)) {
            return false;
        }

        $file_array = array(
            'name' => basename($image_url),
            'tmp_name' => $tmp
        );

        $id = media_handle_sideload($file_array, $post_id);
        if (is_wp_error($id)) {
            @unlink($tmp);
            return false;
        }

        return $id;
    }

    /**
     * Save a template setting
     */
    public static function ajax_save_template_setting() {
        self::verify_nonce();

        $setting = sanitize_text_field($_POST['setting'] ?? '');
        $value = $_POST['value'] ?? '';

        if (empty($setting)) {
            wp_send_json_error(array('message' => 'Invalid setting'));
        }

        // Parse setting path (e.g., "sources.items.sec_edgar.enabled")
        $parts = explode('.', $setting);
        if (count($parts) < 2) {
            wp_send_json_error(array('message' => 'Invalid setting path'));
        }

        // Get current template
        $template = RawWire_Template_Engine::get_active_template();
        $current = &$template;

        // Navigate to the setting location
        for ($i = 0; $i < count($parts) - 1; $i++) {
            $part = $parts[$i];
            if (!isset($current[$part])) {
                $current[$part] = [];
            }
            $current = &$current[$part];
        }

        // Set the value
        $last_part = end($parts);
        $current[$last_part] = $value;

        // Save template
        $template_file = RawWire_Template_Engine::get_template_file();
        if (file_put_contents($template_file, json_encode($template, JSON_PRETTY_PRINT))) {
            wp_send_json_success(array('message' => 'Setting saved successfully'));
        } else {
            wp_send_json_error(array('message' => 'Failed to save template'));
        }
    }

    /**
     * Update custom sources in template
     */
    public static function ajax_update_custom_sources() {
        self::verify_nonce();

        $custom_sources = json_decode(stripslashes($_POST['sources'] ?? '[]'), true);

        if (!is_array($custom_sources)) {
            wp_send_json_error(array('message' => 'Invalid sources data'));
        }

        // Get current template
        $template = RawWire_Template_Engine::get_active_template();

        // Remove existing custom sources
        $template['sources']['items'] = array_filter($template['sources']['items'] ?? [], function($source) {
            return $source['type'] !== 'custom';
        });

        // Add new custom sources
        foreach ($custom_sources as $source) {
            $template['sources']['items'][] = $source;
        }

        // Update custom sources count
        $template['sources']['custom_sources_count'] = count($custom_sources);

        // Save template
        $template_file = RawWire_Template_Engine::get_template_file();
        if (file_put_contents($template_file, json_encode($template, JSON_PRETTY_PRINT))) {
            wp_send_json_success(array('message' => 'Custom sources updated successfully'));
        } else {
            wp_send_json_error(array('message' => 'Failed to save template'));
        }
    }
}

// Initialize handlers
RawWire_Workflow_Handlers::init();
