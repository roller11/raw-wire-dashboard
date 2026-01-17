<?php
/**
 * Scraper Settings Feature
 * 
 * Provides comprehensive scraper configuration through WordPress settings.
 * This is a toolkit feature - not template-specific.
 * 
 * Features:
 * - Enable/disable scraper functionality
 * - Source management (URL, API, RSS, etc.)
 * - Per-source record limits
 * - Field selection (title, summary, metadata, etc.)
 * - Protocol configuration
 * - Copyright/public domain filtering
 * 
 * @package RawWire_Dashboard
 * @subpackage Toolbox_Core
 */

if (!defined('ABSPATH')) {
    exit;
}

class RawWire_Scraper_Settings {
    
    /**
     * Option key for scraper settings
     */
    const OPTION_KEY = 'rawwire_scraper_settings';
    
    /**
     * Option key for configured sources
     */
    const SOURCES_KEY = 'rawwire_scraper_sources';
    
    /**
     * Singleton instance
     */
    private static $instance = null;
    
    /**
     * Available protocols
     */
    const PROTOCOLS = array(
        'http_get' => array(
            'label' => 'HTTP GET (Web Pages)',
            'description' => 'Fetch HTML content from web pages',
            'icon' => 'dashicons-admin-site',
        ),
        'rest_api' => array(
            'label' => 'REST API (JSON)',
            'description' => 'Fetch data from REST API endpoints',
            'icon' => 'dashicons-rest-api',
        ),
        'rss_feed' => array(
            'label' => 'RSS/Atom Feed',
            'description' => 'Parse RSS or Atom syndication feeds',
            'icon' => 'dashicons-rss',
        ),
        'graphql' => array(
            'label' => 'GraphQL API',
            'description' => 'Query GraphQL endpoints',
            'icon' => 'dashicons-networking',
        ),
        'sitemap' => array(
            'label' => 'XML Sitemap',
            'description' => 'Parse XML sitemaps for URLs',
            'icon' => 'dashicons-list-view',
        ),
    );
    
    /**
     * Collectable fields with configuration
     */
    const FIELDS = array(
        'source_url' => array(
            'label' => 'Source URL',
            'description' => 'Original URL of the content',
            'required' => true,
            'default' => true,
        ),
        'title' => array(
            'label' => 'Title',
            'description' => 'Content title or headline',
            'required' => true,
            'default' => true,
        ),
        'summary' => array(
            'label' => 'Summary/Description',
            'description' => 'Content summary or excerpt',
            'required' => false,
            'default' => true,
            'options' => array(
                'word_limit' => array(
                    'type' => 'number',
                    'label' => 'Word limit',
                    'default' => 150,
                    'min' => 10,
                    'max' => 1000,
                ),
            ),
        ),
        'full_content' => array(
            'label' => 'Full Content',
            'description' => 'Complete article or document text',
            'required' => false,
            'default' => false,
            'options' => array(
                'word_limit' => array(
                    'type' => 'number',
                    'label' => 'Word limit',
                    'default' => 2000,
                    'min' => 100,
                    'max' => 50000,
                ),
                'strip_html' => array(
                    'type' => 'checkbox',
                    'label' => 'Strip HTML tags',
                    'default' => true,
                ),
            ),
        ),
        'publish_date' => array(
            'label' => 'Publish Date',
            'description' => 'Original publication date',
            'required' => false,
            'default' => true,
        ),
        'publish_time' => array(
            'label' => 'Publish Time',
            'description' => 'Original publication time',
            'required' => false,
            'default' => true,
        ),
        'author' => array(
            'label' => 'Author',
            'description' => 'Content author or creator',
            'required' => false,
            'default' => true,
        ),
        'categories' => array(
            'label' => 'Categories/Tags',
            'description' => 'Content categorization',
            'required' => false,
            'default' => true,
        ),
        'images' => array(
            'label' => 'Images',
            'description' => 'Featured images and thumbnails',
            'required' => false,
            'default' => false,
            'options' => array(
                'download' => array(
                    'type' => 'checkbox',
                    'label' => 'Download to media library',
                    'default' => false,
                ),
                'max_images' => array(
                    'type' => 'number',
                    'label' => 'Max images per item',
                    'default' => 1,
                    'min' => 0,
                    'max' => 10,
                ),
            ),
        ),
        'metadata' => array(
            'label' => 'Metadata',
            'description' => 'Additional meta information (OpenGraph, Schema.org, etc.)',
            'required' => false,
            'default' => true,
        ),
        'copyright_status' => array(
            'label' => 'Copyright Status',
            'description' => 'License and copyright information',
            'required' => true,
            'default' => true,
            'locked' => true, // Cannot be disabled
        ),
        'source_name' => array(
            'label' => 'Source Name',
            'description' => 'Name of the source publication',
            'required' => true,
            'default' => true,
            'locked' => true, // Cannot be disabled
        ),
    );
    
    /**
     * Public domain source presets
     */
    const PUBLIC_DOMAIN_PRESETS = array(
        'federal_register' => array(
            'name' => 'Federal Register (US Gov)',
            'url' => 'https://www.federalregister.gov/api/v1/documents.json',
            'protocol' => 'rest_api',
            'copyright' => 'public_domain',
            'description' => 'US government regulations and notices',
            'params' => array(
                'per_page' => 20,
                'order' => 'newest',
            ),
        ),
        'regulations_gov' => array(
            'name' => 'Regulations.gov',
            'url' => 'https://api.regulations.gov/v4/documents',
            'protocol' => 'rest_api',
            'copyright' => 'public_domain',
            'description' => 'Federal regulatory documents and public comments',
            'requires_key' => true,
            'key_signup_url' => 'https://open.gsa.gov/api/regulationsgov/',
        ),
        'congress_bills' => array(
            'name' => 'Congress.gov Bills',
            'url' => 'https://api.congress.gov/v3/bill',
            'protocol' => 'rest_api',
            'copyright' => 'public_domain',
            'description' => 'US Congressional bills and legislation',
            'requires_key' => true,
            'key_signup_url' => 'https://api.congress.gov/sign-up/',
        ),
        'data_gov' => array(
            'name' => 'Data.gov Catalog',
            'url' => 'https://catalog.data.gov/api/3/action/package_search',
            'protocol' => 'rest_api',
            'copyright' => 'public_domain',
            'description' => 'US government open data catalog',
        ),
        'arxiv' => array(
            'name' => 'arXiv Preprints',
            'url' => 'http://export.arxiv.org/api/query',
            'protocol' => 'rest_api',
            'copyright' => 'open_access',
            'description' => 'Scientific preprints (CC BY license)',
        ),
        'pubmed' => array(
            'name' => 'PubMed Central',
            'url' => 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi',
            'protocol' => 'rest_api',
            'copyright' => 'open_access',
            'description' => 'Open access medical research',
        ),
        'europeana' => array(
            'name' => 'Europeana',
            'url' => 'https://api.europeana.eu/record/v2/search.json',
            'protocol' => 'rest_api',
            'copyright' => 'public_domain',
            'description' => 'European cultural heritage',
            'requires_key' => true,
        ),
        'openlibrary' => array(
            'name' => 'Open Library',
            'url' => 'https://openlibrary.org/search.json',
            'protocol' => 'rest_api',
            'copyright' => 'public_domain',
            'description' => 'Public domain books and texts',
        ),
        'wikimedia_commons' => array(
            'name' => 'Wikimedia Commons',
            'url' => 'https://commons.wikimedia.org/w/api.php',
            'protocol' => 'rest_api',
            'copyright' => 'creative_commons',
            'description' => 'Free media files (various CC licenses)',
        ),
        'github_trending' => array(
            'name' => 'GitHub Trending',
            'url' => 'https://api.github.com/search/repositories',
            'protocol' => 'rest_api',
            'copyright' => 'open_source',
            'description' => 'Open source repositories',
            'params' => array(
                'q' => 'stars:>100',
                'sort' => 'updated',
            ),
        ),
    );
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_ajax_rawwire_save_scraper_source', array($this, 'ajax_save_source'));
        add_action('wp_ajax_rawwire_delete_scraper_source', array($this, 'ajax_delete_source'));
        add_action('wp_ajax_rawwire_test_scraper_source', array($this, 'ajax_test_source'));
        add_action('wp_ajax_rawwire_add_preset_source', array($this, 'ajax_add_preset'));
        add_action('wp_ajax_rawwire_save_scraper_settings', array($this, 'ajax_save_settings'));
        add_action('wp_ajax_rawwire_get_scraper_source', array($this, 'ajax_get_source'));
        add_action('wp_ajax_rawwire_toggle_scraper_source', array($this, 'ajax_toggle_source'));
        add_action('wp_ajax_rawwire_clear_scraper_sources', array($this, 'ajax_clear_sources'));
        add_action('wp_ajax_rawwire_run_scraper', array($this, 'ajax_run_scraper'));
    }
    
    /**
     * Register settings
     * Note: Sources (SOURCES_KEY) are NOT registered here - they use direct DB operations
     * to completely bypass WordPress option sanitization hooks that were causing data loss
     */
    public function register_settings() {
        register_setting('rawwire_scraper', self::OPTION_KEY, array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_settings'),
            'default' => $this->get_default_settings(),
        ));
        
        // Sources are managed via direct $wpdb operations in save_source() and get_sources()
        // DO NOT register_setting for SOURCES_KEY - it causes sanitize callback issues
    }
    
    /**
     * Get default settings
     */
    public function get_default_settings() {
        $fields = array();
        foreach (self::FIELDS as $key => $field) {
            $fields[$key] = array(
                'enabled' => $field['default'],
                'options' => array(),
            );
            if (isset($field['options'])) {
                foreach ($field['options'] as $opt_key => $opt) {
                    $fields[$key]['options'][$opt_key] = $opt['default'];
                }
            }
        }
        
        return array(
            'enabled' => false,
            'default_protocol' => 'rest_api',
            'default_records_per_source' => 10,
            'respect_robots_txt' => true,
            'user_agent' => 'RawWire-Scraper/1.0 (WordPress Plugin)',
            'request_delay' => 1, // seconds between requests
            'timeout' => 30,
            'fields' => $fields,
            'copyright_filter' => 'public_only', // public_only, open_license, all
            'store_raw_response' => false,
            'auto_schedule' => false,
            'schedule_interval' => 'hourly',
        );
    }
    
    /**
     * Get current settings
     */
    public static function get_settings() {
        $instance = self::get_instance();
        $settings = get_option(self::OPTION_KEY, array());
        return wp_parse_args($settings, $instance->get_default_settings());
    }
    
    /**
     * Check if scraper is enabled
     */
    public static function is_enabled() {
        $settings = self::get_settings();
        return !empty($settings['enabled']);
    }
    
    /**
     * Get configured sources (bypass cache to ensure fresh data)
     */
    public static function get_sources() {
        global $wpdb;
        
        // Read directly from database to avoid any caching issues
        $option_name = self::SOURCES_KEY;
        $row = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            $option_name
        ));
        
        if ($row === null) {
            return array();
        }
        
        $sources = maybe_unserialize($row);
        return is_array($sources) ? $sources : array();
    }
    
    /**
     * Save a source (bypasses register_setting sanitization)
     */
    public static function save_source($source) {
        global $wpdb;
        
        $sources = self::get_sources();
        
        // Generate new ID if empty or not set
        $id = !empty($source['id']) ? $source['id'] : wp_generate_uuid4();
        $source['id'] = $id;
        $source['updated_at'] = current_time('mysql');
        
        if (!isset($source['created_at'])) {
            $source['created_at'] = current_time('mysql');
        }
        
        $sources[$id] = $source;
        
        // Direct database update to bypass register_setting sanitize callback
        // The callback was stripping fields even though we fixed it
        $option_name = self::SOURCES_KEY;
        $serialized = maybe_serialize($sources);
        
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s",
            $option_name
        ));
        
        if ($exists) {
            $wpdb->update(
                $wpdb->options,
                array('option_value' => $serialized),
                array('option_name' => $option_name)
            );
        } else {
            $wpdb->insert(
                $wpdb->options,
                array(
                    'option_name' => $option_name,
                    'option_value' => $serialized,
                    'autoload' => 'yes'
                )
            );
        }
        
        // Clear WordPress option cache
        wp_cache_delete($option_name, 'options');
        
        return $id;
    }
    
    /**
     * Delete a source (bypasses register_setting sanitization)
     */
    public static function delete_source($id) {
        global $wpdb;
        
        $sources = self::get_sources();
        if (isset($sources[$id])) {
            unset($sources[$id]);
            
            // Direct database update
            $option_name = self::SOURCES_KEY;
            $serialized = maybe_serialize($sources);
            
            $wpdb->update(
                $wpdb->options,
                array('option_value' => $serialized),
                array('option_name' => $option_name)
            );
            
            // Clear WordPress option cache
            wp_cache_delete($option_name, 'options');
            
            return true;
        }
        return false;
    }
    
    /**
     * Sanitize settings
     */
    public function sanitize_settings($input) {
        $sanitized = array();
        
        $sanitized['enabled'] = !empty($input['enabled']);
        $sanitized['default_protocol'] = sanitize_key($input['default_protocol'] ?? 'rest_api');
        $sanitized['default_records_per_source'] = absint($input['default_records_per_source'] ?? 10);
        $sanitized['respect_robots_txt'] = !empty($input['respect_robots_txt']);
        $sanitized['user_agent'] = sanitize_text_field($input['user_agent'] ?? '');
        $sanitized['request_delay'] = max(0, floatval($input['request_delay'] ?? 1));
        $sanitized['timeout'] = absint($input['timeout'] ?? 30);
        $sanitized['copyright_filter'] = sanitize_key($input['copyright_filter'] ?? 'public_only');
        $sanitized['store_raw_response'] = !empty($input['store_raw_response']);
        $sanitized['auto_schedule'] = !empty($input['auto_schedule']);
        $sanitized['schedule_interval'] = sanitize_key($input['schedule_interval'] ?? 'hourly');
        
        // Sanitize field settings
        $sanitized['fields'] = array();
        foreach (self::FIELDS as $key => $field_def) {
            $field_input = $input['fields'][$key] ?? array();
            
            // Locked fields are always enabled
            $is_locked = !empty($field_def['locked']);
            
            $sanitized['fields'][$key] = array(
                'enabled' => $is_locked ? true : !empty($field_input['enabled']),
                'options' => array(),
            );
            
            if (isset($field_def['options'])) {
                foreach ($field_def['options'] as $opt_key => $opt_def) {
                    $opt_value = $field_input['options'][$opt_key] ?? $opt_def['default'];
                    
                    switch ($opt_def['type']) {
                        case 'number':
                            $opt_value = absint($opt_value);
                            if (isset($opt_def['min'])) $opt_value = max($opt_def['min'], $opt_value);
                            if (isset($opt_def['max'])) $opt_value = min($opt_def['max'], $opt_value);
                            break;
                        case 'checkbox':
                            $opt_value = !empty($opt_value);
                            break;
                        default:
                            $opt_value = sanitize_text_field($opt_value);
                    }
                    
                    $sanitized['fields'][$key]['options'][$opt_key] = $opt_value;
                }
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Sanitize sources
     */
    public function sanitize_sources($input) {
        if (!is_array($input)) {
            return array();
        }
        
        $sanitized = array();
        foreach ($input as $id => $source) {
            $sanitized[$id] = array(
                'id' => sanitize_key($id),
                'name' => sanitize_text_field($source['name'] ?? ''),
                'type' => sanitize_key($source['type'] ?? $source['protocol'] ?? 'rest_api'),
                'address_type' => sanitize_key($source['address_type'] ?? 'url'),
                'address' => esc_url_raw($source['address'] ?? $source['url'] ?? ''),
                'url' => esc_url_raw($source['url'] ?? $source['address'] ?? ''),
                'protocol' => sanitize_key($source['protocol'] ?? $source['type'] ?? 'rest_api'),
                'auth_type' => sanitize_key($source['auth_type'] ?? 'none'),
                'auth_key' => sanitize_text_field($source['auth_key'] ?? ''),
                'auth_user' => sanitize_text_field($source['auth_user'] ?? ''),
                'auth_pass' => sanitize_text_field($source['auth_pass'] ?? ''),
                'records_limit' => absint($source['records_limit'] ?? 10),
                'copyright' => sanitize_key($source['copyright'] ?? 'unknown'),
                'output_table' => sanitize_key($source['output_table'] ?? 'candidates'),
                'columns' => sanitize_text_field($source['columns'] ?? 'title, summary, source_url'),
                'timeout' => absint($source['timeout'] ?? 30),
                'delay' => floatval($source['delay'] ?? 1),
                'headers' => sanitize_text_field($source['headers'] ?? ''),
                'params' => sanitize_text_field($source['params'] ?? ''),
                'enabled' => !empty($source['enabled']),
                'api_key' => sanitize_text_field($source['api_key'] ?? ''),
                'custom_headers' => $this->sanitize_headers($source['custom_headers'] ?? array()),
                'selectors' => $this->sanitize_selectors($source['selectors'] ?? array()),
                'created_at' => sanitize_text_field($source['created_at'] ?? current_time('mysql')),
                'updated_at' => current_time('mysql'),
            );
        }
        
        return $sanitized;
    }
    
    /**
     * Sanitize headers array
     */
    private function sanitize_headers($headers) {
        if (!is_array($headers)) return array();
        $clean = array();
        foreach ($headers as $key => $value) {
            $clean[sanitize_text_field($key)] = sanitize_text_field($value);
        }
        return $clean;
    }
    
    /**
     * Sanitize params array
     */
    private function sanitize_params($params) {
        if (!is_array($params)) return array();
        $clean = array();
        foreach ($params as $key => $value) {
            $clean[sanitize_key($key)] = sanitize_text_field($value);
        }
        return $clean;
    }
    
    /**
     * Sanitize CSS selectors
     */
    private function sanitize_selectors($selectors) {
        if (!is_array($selectors)) return array();
        $clean = array();
        foreach ($selectors as $key => $value) {
            // Allow CSS selector characters
            $clean[sanitize_key($key)] = preg_replace('/[^a-zA-Z0-9\s\.\#\[\]\=\-\_\:\>\+\~\*\,\(\)]/', '', $value);
        }
        return $clean;
    }
    
    /**
     * AJAX: Save source (handles new horizontal form data)
     */
    public function ajax_save_source() {
        check_ajax_referer('rawwire_scraper_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        // Get source data from POST (can be direct or in 'source' array)
        $source_data = isset($_POST['source']) && is_array($_POST['source']) ? $_POST['source'] : $_POST;
        
        $source = array(
            'id' => sanitize_key($source_data['id'] ?? ''),
            'name' => sanitize_text_field($source_data['name'] ?? ''),
            'type' => sanitize_key($source_data['type'] ?? 'rest_api'),
            'address_type' => sanitize_key($source_data['address_type'] ?? 'url'),
            'address' => esc_url_raw($source_data['address'] ?? $source_data['url'] ?? ''),
            'url' => esc_url_raw($source_data['address'] ?? $source_data['url'] ?? ''), // Alias for compatibility
            'protocol' => sanitize_key($source_data['type'] ?? $source_data['protocol'] ?? 'rest_api'),
            'auth_type' => sanitize_key($source_data['auth_type'] ?? 'none'),
            'auth_key' => sanitize_text_field($source_data['auth_key'] ?? ''),
            'auth_user' => sanitize_text_field($source_data['auth_user'] ?? ''),
            'auth_pass' => sanitize_text_field($source_data['auth_pass'] ?? ''),
            'records_limit' => absint($source_data['records_limit'] ?? 10),
            'copyright' => sanitize_key($source_data['copyright'] ?? 'unknown'),
            'output_table' => sanitize_key($source_data['output_table'] ?? 'candidates'),
            'columns' => sanitize_text_field($source_data['columns'] ?? 'title, summary, source_url'),
            'timeout' => absint($source_data['timeout'] ?? 30),
            'delay' => floatval($source_data['delay'] ?? 1),
            'headers' => sanitize_text_field($source_data['headers'] ?? ''),
            'params' => sanitize_text_field($source_data['params'] ?? ''),
            'enabled' => !isset($source_data['enabled']) || !empty($source_data['enabled']),
        );
        
        // Parse custom headers if provided as JSON
        if (!empty($source['headers'])) {
            $parsed = json_decode(stripslashes($source['headers']), true);
            if (is_array($parsed)) {
                $source['custom_headers'] = $this->sanitize_headers($parsed);
            }
        }
        
        // Parse CSS selectors for HTML scraping
        if (!empty($source_data['selectors']) && is_array($source_data['selectors'])) {
            $source['selectors'] = $this->sanitize_selectors($source_data['selectors']);
        }
        
        $id = self::save_source($source);
        
        wp_send_json_success(array(
            'id' => $id,
            'message' => 'Source saved successfully',
            'source' => $source,
        ));
    }
    
    /**
     * AJAX: Delete source
     */
    public function ajax_delete_source() {
        check_ajax_referer('rawwire_scraper_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $id = sanitize_key($_POST['source_id'] ?? '');
        
        if (self::delete_source($id)) {
            wp_send_json_success('Source deleted');
        } else {
            wp_send_json_error('Source not found');
        }
    }
    
    /**
     * AJAX: Test source connection
     */
    public function ajax_test_source() {
        check_ajax_referer('rawwire_scraper_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $url = esc_url_raw($_POST['url'] ?? '');
        $protocol = sanitize_key($_POST['protocol'] ?? 'rest_api');
        
        if (empty($url)) {
            wp_send_json_error('URL is required');
        }
        
        // Simple connection test
        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'headers' => array(
                'User-Agent' => 'RawWire-Scraper/1.0 (WordPress Plugin)',
            ),
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error(array(
                'message' => 'Connection failed: ' . $response->get_error_message(),
            ));
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($code !== 200) {
            wp_send_json_error(array(
                'message' => "Server returned status {$code}",
                'code' => $code,
            ));
        }
        
        // Try to detect content type
        $content_type = wp_remote_retrieve_header($response, 'content-type');
        $is_json = strpos($content_type, 'json') !== false;
        $is_xml = strpos($content_type, 'xml') !== false;
        $is_html = strpos($content_type, 'html') !== false;
        
        $sample = array();
        if ($is_json) {
            $data = json_decode($body, true);
            if ($data) {
                $sample = array_slice($data, 0, 3);
                if (isset($data['items'])) {
                    $sample = array_slice($data['items'], 0, 3);
                } elseif (isset($data['results'])) {
                    $sample = array_slice($data['results'], 0, 3);
                }
            }
        }
        
        wp_send_json_success(array(
            'message' => 'Connection successful',
            'content_type' => $content_type,
            'format' => $is_json ? 'json' : ($is_xml ? 'xml' : ($is_html ? 'html' : 'unknown')),
            'sample_data' => $sample,
            'response_size' => strlen($body),
        ));
    }
    
    /**
     * AJAX: Add preset source
     */
    public function ajax_add_preset() {
        check_ajax_referer('rawwire_scraper_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $preset_key = sanitize_key($_POST['preset'] ?? '');
        
        if (!isset(self::PUBLIC_DOMAIN_PRESETS[$preset_key])) {
            wp_send_json_error('Unknown preset');
        }
        
        $preset = self::PUBLIC_DOMAIN_PRESETS[$preset_key];
        
        $source = array(
            'name' => $preset['name'],
            'url' => $preset['url'],
            'protocol' => $preset['protocol'],
            'copyright' => $preset['copyright'],
            'enabled' => true,
            'records_limit' => 10,
            'output_table' => 'candidates', // Default to candidates workflow table
            'columns' => 'title, summary, source_url, published_date, author, copyright_status',
            'params' => $preset['params'] ?? array(),
        );
        
        $id = self::save_source($source);
        
        wp_send_json_success(array(
            'id' => $id,
            'source' => $source,
            'message' => 'Preset source added',
            'requires_key' => !empty($preset['requires_key']),
        ));
    }
    
    /**
     * AJAX: Save general settings
     */
    public function ajax_save_settings() {
        check_ajax_referer('rawwire_scraper_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        $settings_data = isset($_POST['settings']) && is_array($_POST['settings']) ? $_POST['settings'] : $_POST;
        
        $current = self::get_settings();
        
        // Update only provided settings
        $settings = array_merge($current, array(
            'enabled' => isset($settings_data['enabled']) ? (bool) $settings_data['enabled'] : $current['enabled'],
            'default_records_per_source' => isset($settings_data['default_records_per_source']) 
                ? absint($settings_data['default_records_per_source']) 
                : $current['default_records_per_source'],
            'copyright_filter' => isset($settings_data['copyright_filter']) 
                ? sanitize_key($settings_data['copyright_filter']) 
                : $current['copyright_filter'],
            'user_agent' => isset($settings_data['user_agent']) 
                ? sanitize_text_field($settings_data['user_agent']) 
                : $current['user_agent'],
            'respect_robots_txt' => isset($settings_data['respect_robots_txt']) 
                ? (bool) $settings_data['respect_robots_txt'] 
                : $current['respect_robots_txt'],
            'auto_schedule' => isset($settings_data['auto_schedule']) 
                ? (bool) $settings_data['auto_schedule'] 
                : $current['auto_schedule'],
            'schedule_interval' => isset($settings_data['schedule_interval']) 
                ? sanitize_key($settings_data['schedule_interval']) 
                : $current['schedule_interval'],
            'store_raw_response' => isset($settings_data['store_raw_response']) 
                ? (bool) $settings_data['store_raw_response'] 
                : $current['store_raw_response'],
        ));
        
        update_option(self::OPTION_KEY, $settings);
        
        wp_send_json_success(array('message' => 'Settings saved'));
    }
    
    /**
     * AJAX: Get single source data
     */
    public function ajax_get_source() {
        check_ajax_referer('rawwire_scraper_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        $source_id = sanitize_key($_POST['source_id'] ?? '');
        $sources = self::get_sources();
        
        if (isset($sources[$source_id])) {
            wp_send_json_success($sources[$source_id]);
        } else {
            wp_send_json_error(array('message' => 'Source not found'));
        }
    }
    
    /**
     * AJAX: Toggle source enabled state
     */
    public function ajax_toggle_source() {
        // Accept either scraper-specific or global nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'rawwire_scraper_nonce') && 
            !wp_verify_nonce($_POST['nonce'] ?? '', 'rawwire_ajax_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        $source_id = sanitize_key($_POST['source_id'] ?? '');
        $enabled = !empty($_POST['enabled']);
        
        $sources = self::get_sources();
        
        if (isset($sources[$source_id])) {
            $sources[$source_id]['enabled'] = $enabled;
            update_option(self::SOURCES_KEY, $sources);
            wp_send_json_success(array('message' => 'Source toggled'));
        } else {
            wp_send_json_error(array('message' => 'Source not found'));
        }
    }
    
    /**
     * AJAX: Clear all sources
     */
    public function ajax_clear_sources() {
        check_ajax_referer('rawwire_scraper_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        // Use direct DB to be consistent with save_source/delete_source
        global $wpdb;
        $option_name = self::SOURCES_KEY;
        $serialized = maybe_serialize(array());
        
        $wpdb->update(
            $wpdb->options,
            array('option_value' => $serialized),
            array('option_name' => $option_name)
        );
        wp_cache_delete($option_name, 'options');
        
        wp_send_json_success(array('message' => 'All sources cleared'));
    }
    
    /**
     * AJAX: Run scraper collection
     */
    public function ajax_run_scraper() {
        check_ajax_referer('rawwire_scraper_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        $sources = self::get_sources();
        $settings = self::get_settings();
        
        if (empty($sources)) {
            wp_send_json_error(array('message' => 'No sources configured'));
        }
        
        $total_collected = 0;
        $errors = array();
        
        foreach ($sources as $id => $source) {
            if (empty($source['enabled'])) {
                continue;
            }
            
            try {
                $result = $this->collect_from_source($source, $settings);
                $total_collected += $result['count'] ?? 0;
            } catch (Exception $e) {
                $errors[] = $source['name'] . ': ' . $e->getMessage();
            }
        }
        
        wp_send_json_success(array(
            'message' => 'Collection complete',
            'records_collected' => $total_collected,
            'errors' => $errors,
        ));
    }
    
    /**
     * Collect data from a single source
     */
    private function collect_from_source($source, $settings) {
        global $wpdb;
        
        $url = $source['address'] ?? $source['url'] ?? '';
        if (empty($url)) {
            throw new Exception('No URL configured');
        }
        
        // Build request args
        $args = array(
            'timeout' => $source['timeout'] ?? $settings['timeout'] ?? 30,
            'headers' => array(
                'User-Agent' => $settings['user_agent'],
            ),
        );
        
        // Add authentication
        if (!empty($source['auth_type']) && $source['auth_type'] !== 'none') {
            // First check centralized key manager for well-known keys
            $auth_key = $source['auth_key'] ?? '';
            
            if (function_exists('rawwire_keys')) {
                $key_manager = rawwire_keys();
                
                // Map source names to key IDs
                $source_key_map = [
                    'Regulations.gov'     => 'regulations_gov',
                    'Congress.gov Bills'  => 'congress_gov',
                    'Europeana'           => 'europeana',
                    'GitHub Trending'     => 'github',
                ];
                
                $source_name = $source['name'] ?? '';
                if (isset($source_key_map[$source_name])) {
                    $managed_key = $key_manager->get_key($source_key_map[$source_name]);
                    if (!empty($managed_key)) {
                        $auth_key = $managed_key;
                    }
                }
            }
            
            switch ($source['auth_type']) {
                case 'api_key':
                    $args['headers']['X-API-Key'] = $auth_key;
                    break;
                case 'bearer_token':
                    $args['headers']['Authorization'] = 'Bearer ' . $auth_key;
                    break;
                case 'basic_auth':
                    $args['headers']['Authorization'] = 'Basic ' . base64_encode($source['auth_user'] . ':' . $source['auth_pass']);
                    break;
            }
        }
        
        // Add delay between requests if configured
        if (!empty($settings['request_delay'])) {
            usleep($settings['request_delay'] * 1000000);
        }
        
        // Make request
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            throw new Exception("HTTP {$code} response");
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!$data) {
            throw new Exception('Invalid JSON response');
        }
        
        // Extract items from response
        $items = $data;
        if (isset($data['items'])) $items = $data['items'];
        elseif (isset($data['results'])) $items = $data['results'];
        elseif (isset($data['data'])) $items = $data['data'];
        
        if (!is_array($items)) {
            $items = array($items);
        }
        
        // Limit records
        $limit = $source['records_limit'] ?? 10;
        $items = array_slice($items, 0, $limit);
        
        // Parse columns
        $columns = array_map('trim', explode(',', $source['columns'] ?? 'title,source_url'));
        
        // Ensure table exists
        $table_name = $wpdb->prefix . 'rawwire_' . ($source['output_table'] ?? 'candidates');
        $this->ensure_table_exists($table_name, $columns);
        
        // Insert data
        $inserted = 0;
        foreach ($items as $item) {
            $row = array(
                'source_id' => $source['id'] ?? '',
                'source_name' => $source['name'],
                'copyright_status' => $source['copyright'] ?? 'unknown',
                'collected_at' => current_time('mysql'),
            );
            
            foreach ($columns as $col) {
                if (isset($item[$col])) {
                    $row[$col] = is_array($item[$col]) ? json_encode($item[$col]) : $item[$col];
                }
            }
            
            $wpdb->insert($table_name, $row);
            if ($wpdb->insert_id) {
                $inserted++;
            }
        }
        
        return array('count' => $inserted);
    }
    
    /**
     * Ensure output table exists with required columns
     */
    private function ensure_table_exists($table_name, $columns) {
        global $wpdb;
        
        $charset = $wpdb->get_charset_collate();
        
        // Build column definitions
        $col_defs = array(
            'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',
            'source_id VARCHAR(64)',
            'source_name VARCHAR(255)',
            'copyright_status VARCHAR(64)',
            'collected_at DATETIME DEFAULT CURRENT_TIMESTAMP',
        );
        
        foreach ($columns as $col) {
            $col = sanitize_key($col);
            if (!in_array($col, array('id', 'source_id', 'source_name', 'copyright_status', 'collected_at'))) {
                $col_defs[] = "{$col} TEXT";
            }
        }
        
        $col_defs[] = 'PRIMARY KEY (id)';
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (" . implode(', ', $col_defs) . ") {$charset};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Get available protocols
     */
    public static function get_protocols() {
        return self::PROTOCOLS;
    }
    
    /**
     * Get collectable fields
     */
    public static function get_fields() {
        return self::FIELDS;
    }
    
    /**
     * Get public domain presets
     */
    public static function get_presets() {
        return self::PUBLIC_DOMAIN_PRESETS;
    }
}

// Initialize
RawWire_Scraper_Settings::get_instance();
