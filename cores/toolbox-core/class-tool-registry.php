<?php
/**
 * Tool Registry
 * 
 * Central registry for all automation tools in the toolkit.
 * Uses Action Scheduler for orchestration instead of custom scheduling.
 * 
 * Architecture:
 * - Tools register themselves with metadata
 * - Action Scheduler handles scheduling, queuing, retries
 * - Registry provides UI and API for tool management
 * - Tools are loosely coupled and independently configurable
 * 
 * @package RawWire_Dashboard
 * @subpackage Toolbox_Core
 */

if (!defined('ABSPATH')) {
    exit;
}

class RawWire_Tool_Registry {
    
    /**
     * Singleton instance
     */
    private static $instance = null;
    
    /**
     * Registered tools
     */
    private $tools = array();
    
    /**
     * Tool categories
     */
    const CATEGORIES = array(
        'scraper'   => array('label' => 'Data Collection', 'icon' => 'dashicons-download'),
        'scorer'    => array('label' => 'AI Scoring', 'icon' => 'dashicons-chart-bar'),
        'generator' => array('label' => 'Content Generation', 'icon' => 'dashicons-edit'),
        'publisher' => array('label' => 'Publishing', 'icon' => 'dashicons-share'),
        'utility'   => array('label' => 'Utilities', 'icon' => 'dashicons-admin-tools'),
        'ai'        => array('label' => 'AI Processing', 'icon' => 'dashicons-lightbulb'),
    );
    
    /**
     * Tool status options
     */
    const STATUS = array(
        'enabled'  => 'Enabled',
        'disabled' => 'Disabled',
        'running'  => 'Running',
        'error'    => 'Error',
        'paused'   => 'Paused',
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
        // Load Action Scheduler
        $this->load_action_scheduler();
        
        // Register core tools
        add_action('init', array($this, 'register_core_tools'), 5);
        
        // Hook into Action Scheduler
        add_action('init', array($this, 'register_action_hooks'), 10);
        
        // Admin AJAX handlers
        add_action('wp_ajax_rawwire_tool_execute', array($this, 'ajax_execute_tool'));
        add_action('wp_ajax_rawwire_tool_schedule', array($this, 'ajax_schedule_tool'));
        add_action('wp_ajax_rawwire_tool_cancel', array($this, 'ajax_cancel_tool'));
        add_action('wp_ajax_rawwire_tool_status', array($this, 'ajax_get_status'));
        add_action('wp_ajax_rawwire_tools_list', array($this, 'ajax_list_tools'));
    }
    
    /**
     * Load Action Scheduler library
     */
    private function load_action_scheduler() {
        $as_path = plugin_dir_path(dirname(dirname(__FILE__))) . 'vendor/action-scheduler/action-scheduler.php';
        
        if (file_exists($as_path)) {
            require_once $as_path;
        }
    }
    
    /**
     * Check if Action Scheduler is available
     */
    public function has_action_scheduler() {
        return function_exists('as_schedule_single_action');
    }
    
    /**
     * Register a tool
     * 
     * @param string $id Unique tool identifier
     * @param array $config {
     *     @type string   $name        Display name
     *     @type string   $description Tool description
     *     @type string   $category    Tool category (scraper, scorer, etc.)
     *     @type string   $class       PHP class name that handles execution
     *     @type callable $callback    Alternative: callback function
     *     @type string   $icon        Dashicon class
     *     @type array    $settings    Tool-specific settings schema
     *     @type array    $schedule    Default schedule config
     *     @type int      $priority    Execution priority (1-10, lower = higher)
     *     @type int      $timeout     Max execution time in seconds
     *     @type int      $retries     Number of retries on failure
     *     @type bool     $concurrent  Allow concurrent executions
     * }
     */
    public function register($id, array $config) {
        $defaults = array(
            'id'          => $id,
            'name'        => $id,
            'description' => '',
            'category'    => 'utility',
            'class'       => null,
            'callback'    => null,
            'icon'        => 'dashicons-admin-generic',
            'settings'    => array(),
            'schedule'    => array(),
            'priority'    => 5,
            'timeout'     => 300,
            'retries'     => 3,
            'concurrent'  => false,
            'enabled'     => true,
        );
        
        $this->tools[$id] = wp_parse_args($config, $defaults);
        
        return $this;
    }
    
    /**
     * Register core built-in tools
     */
    public function register_core_tools() {
        // Data Collection Tools
        $this->register('scraper_rss', array(
            'name'        => 'RSS Feed Scraper',
            'description' => 'Collect content from RSS/Atom feeds',
            'category'    => 'scraper',
            'class'       => 'RawWire_Tool_Scraper_RSS',
            'icon'        => 'dashicons-rss',
            'settings'    => array(
                'feeds' => array('type' => 'textarea', 'label' => 'Feed URLs (one per line)'),
                'limit' => array('type' => 'number', 'label' => 'Items per feed', 'default' => 10),
            ),
        ));
        
        $this->register('scraper_api', array(
            'name'        => 'REST API Scraper',
            'description' => 'Fetch data from REST API endpoints',
            'category'    => 'scraper',
            'class'       => 'RawWire_Tool_Scraper_API',
            'icon'        => 'dashicons-rest-api',
            'settings'    => array(
                'endpoints' => array('type' => 'repeater', 'label' => 'API Endpoints'),
            ),
        ));
        
        $this->register('scraper_html', array(
            'name'        => 'HTML Scraper',
            'description' => 'Extract content from web pages using CSS selectors',
            'category'    => 'scraper',
            'class'       => 'RawWire_Tool_Scraper_HTML',
            'icon'        => 'dashicons-editor-code',
        ));
        
        // AI Scoring Tools
        $this->register('scorer_relevance', array(
            'name'        => 'AI Relevance Scorer',
            'description' => 'Score content relevance using AI models',
            'category'    => 'scorer',
            'class'       => 'RawWire_Tool_Scorer_Relevance',
            'icon'        => 'dashicons-chart-bar',
            'settings'    => array(
                'model'     => array('type' => 'select', 'label' => 'AI Model', 'options' => array()),
                'threshold' => array('type' => 'number', 'label' => 'Min Score', 'default' => 70),
            ),
        ));
        
        $this->register('scorer_sentiment', array(
            'name'        => 'Sentiment Analyzer',
            'description' => 'Analyze content sentiment (positive/negative/neutral)',
            'category'    => 'scorer',
            'class'       => 'RawWire_Tool_Scorer_Sentiment',
            'icon'        => 'dashicons-smiley',
        ));
        
        // Content Generation Tools
        $this->register('generator_article', array(
            'name'        => 'Article Generator',
            'description' => 'Generate articles from scraped content using AI',
            'category'    => 'generator',
            'class'       => 'RawWire_Tool_Generator_Article',
            'icon'        => 'dashicons-edit-page',
            'settings'    => array(
                'model'      => array('type' => 'select', 'label' => 'AI Model'),
                'word_count' => array('type' => 'number', 'label' => 'Target words', 'default' => 500),
                'tone'       => array('type' => 'select', 'label' => 'Writing tone'),
            ),
        ));
        
        $this->register('generator_summary', array(
            'name'        => 'Content Summarizer',
            'description' => 'Generate summaries of long-form content',
            'category'    => 'generator',
            'class'       => 'RawWire_Tool_Generator_Summary',
            'icon'        => 'dashicons-editor-justify',
        ));
        
        $this->register('generator_image', array(
            'name'        => 'Image Generator',
            'description' => 'Generate images using AI (DALL-E, Stable Diffusion)',
            'category'    => 'generator',
            'class'       => 'RawWire_Tool_Generator_Image',
            'icon'        => 'dashicons-format-image',
        ));
        
        // Publishing Tools
        $this->register('publisher_wordpress', array(
            'name'        => 'WordPress Publisher',
            'description' => 'Publish content as WordPress posts',
            'category'    => 'publisher',
            'class'       => 'RawWire_Tool_Publisher_WordPress',
            'icon'        => 'dashicons-wordpress',
        ));
        
        $this->register('publisher_social', array(
            'name'        => 'Social Media Publisher',
            'description' => 'Share content to social media platforms',
            'category'    => 'publisher',
            'class'       => 'RawWire_Tool_Publisher_Social',
            'icon'        => 'dashicons-share-alt',
        ));
        
        // Utility Tools
        $this->register('utility_cleanup', array(
            'name'        => 'Database Cleanup',
            'description' => 'Clean up old records and optimize tables',
            'category'    => 'utility',
            'class'       => 'RawWire_Tool_Utility_Cleanup',
            'icon'        => 'dashicons-trash',
        ));
        
        $this->register('utility_backup', array(
            'name'        => 'Data Backup',
            'description' => 'Backup tool configurations and data',
            'category'    => 'utility',
            'class'       => 'RawWire_Tool_Utility_Backup',
            'icon'        => 'dashicons-backup',
        ));
        
        // Allow plugins/themes to register additional tools
        do_action('rawwire_register_tools', $this);
    }
    
    /**
     * Register Action Scheduler hooks for all tools
     */
    public function register_action_hooks() {
        foreach ($this->tools as $id => $tool) {
            $action_name = 'rawwire_tool_' . $id;
            add_action($action_name, array($this, 'execute_tool'), 10, 2);
        }
    }
    
    /**
     * Execute a tool (called by Action Scheduler)
     * 
     * @param string $tool_id Tool identifier
     * @param array $args Execution arguments
     */
    public function execute_tool($tool_id, $args = array()) {
        if (!isset($this->tools[$tool_id])) {
            $this->log_error($tool_id, 'Tool not found');
            return;
        }
        
        $tool = $this->tools[$tool_id];
        $start_time = microtime(true);
        
        // Log start
        $this->log_execution($tool_id, 'started', array('args' => $args));
        
        try {
            // Execute via class or callback
            if (!empty($tool['class']) && class_exists($tool['class'])) {
                $instance = new $tool['class']();
                $result = $instance->execute($args);
            } elseif (!empty($tool['callback']) && is_callable($tool['callback'])) {
                $result = call_user_func($tool['callback'], $args);
            } else {
                throw new Exception('No executable handler found');
            }
            
            // Log success
            $duration = microtime(true) - $start_time;
            $this->log_execution($tool_id, 'completed', array(
                'duration' => $duration,
                'result'   => $result,
            ));
            
            return $result;
            
        } catch (Exception $e) {
            $this->log_error($tool_id, $e->getMessage());
            throw $e; // Re-throw for Action Scheduler retry logic
        }
    }
    
    /**
     * Schedule a tool for execution
     * 
     * @param string $tool_id Tool identifier
     * @param array $args Execution arguments
     * @param int|string $when When to run (timestamp, '+1 hour', 'now')
     * @param string $recurrence Optional recurrence (hourly, daily, weekly)
     * @return int|false Action ID or false on failure
     */
    public function schedule($tool_id, $args = array(), $when = 'now', $recurrence = null) {
        if (!$this->has_action_scheduler()) {
            return $this->execute_tool($tool_id, $args); // Fall back to immediate
        }
        
        $action_name = 'rawwire_tool_' . $tool_id;
        
        // Parse $when
        if ($when === 'now') {
            $timestamp = time();
        } elseif (is_string($when)) {
            $timestamp = strtotime($when);
        } else {
            $timestamp = (int) $when;
        }
        
        // Schedule based on recurrence
        if ($recurrence) {
            $interval = $this->get_interval_seconds($recurrence);
            return as_schedule_recurring_action($timestamp, $interval, $action_name, array($tool_id, $args), 'rawwire');
        } else {
            return as_schedule_single_action($timestamp, $action_name, array($tool_id, $args), 'rawwire');
        }
    }
    
    /**
     * Schedule tool to run immediately (async)
     */
    public function run_async($tool_id, $args = array()) {
        if (!$this->has_action_scheduler()) {
            return $this->execute_tool($tool_id, $args);
        }
        
        $action_name = 'rawwire_tool_' . $tool_id;
        return as_enqueue_async_action($action_name, array($tool_id, $args), 'rawwire');
    }
    
    /**
     * Cancel scheduled tool execution
     */
    public function cancel($tool_id, $args = array()) {
        if (!$this->has_action_scheduler()) {
            return false;
        }
        
        $action_name = 'rawwire_tool_' . $tool_id;
        as_unschedule_all_actions($action_name, array($tool_id, $args), 'rawwire');
        return true;
    }
    
    /**
     * Get all registered tools
     */
    public function get_tools() {
        return $this->tools;
    }
    
    /**
     * Get tools by category
     */
    public function get_tools_by_category($category) {
        return array_filter($this->tools, function($tool) use ($category) {
            return $tool['category'] === $category;
        });
    }
    
    /**
     * Get tool by ID
     */
    public function get_tool($id) {
        return $this->tools[$id] ?? null;
    }
    
    /**
     * Get pending/running actions for a tool
     */
    public function get_scheduled_actions($tool_id = null) {
        if (!$this->has_action_scheduler()) {
            return array();
        }
        
        $args = array(
            'group'  => 'rawwire',
            'status' => array(ActionScheduler_Store::STATUS_PENDING, ActionScheduler_Store::STATUS_RUNNING),
        );
        
        if ($tool_id) {
            $args['hook'] = 'rawwire_tool_' . $tool_id;
        }
        
        return as_get_scheduled_actions($args);
    }
    
    /**
     * Get tool execution history
     */
    public function get_execution_history($tool_id = null, $limit = 50) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'rawwire_tool_logs';
        
        $sql = "SELECT * FROM {$table}";
        if ($tool_id) {
            $sql .= $wpdb->prepare(" WHERE tool_id = %s", $tool_id);
        }
        $sql .= " ORDER BY created_at DESC LIMIT %d";
        
        return $wpdb->get_results($wpdb->prepare($sql, $limit));
    }
    
    /**
     * Convert recurrence string to seconds
     */
    private function get_interval_seconds($recurrence) {
        $intervals = array(
            'minutely'   => MINUTE_IN_SECONDS,
            'five_min'   => 5 * MINUTE_IN_SECONDS,
            'fifteen_min'=> 15 * MINUTE_IN_SECONDS,
            'hourly'     => HOUR_IN_SECONDS,
            'twicedaily' => 12 * HOUR_IN_SECONDS,
            'daily'      => DAY_IN_SECONDS,
            'weekly'     => WEEK_IN_SECONDS,
        );
        
        return $intervals[$recurrence] ?? HOUR_IN_SECONDS;
    }
    
    /**
     * Log tool execution
     */
    private function log_execution($tool_id, $status, $data = array()) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'rawwire_tool_logs';
        
        // Ensure table exists
        $this->ensure_log_table();
        
        $wpdb->insert($table, array(
            'tool_id'    => $tool_id,
            'status'     => $status,
            'data'       => json_encode($data),
            'created_at' => current_time('mysql'),
        ));
    }
    
    /**
     * Log error
     */
    private function log_error($tool_id, $message) {
        $this->log_execution($tool_id, 'error', array('error' => $message));
        error_log("[RawWire Tool Error] {$tool_id}: {$message}");
    }
    
    /**
     * Ensure log table exists
     */
    private function ensure_log_table() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'rawwire_tool_logs';
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            tool_id VARCHAR(64) NOT NULL,
            status VARCHAR(32) NOT NULL,
            data LONGTEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY tool_id (tool_id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset};";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
    
    /**
     * AJAX: Execute tool immediately
     */
    public function ajax_execute_tool() {
        check_ajax_referer('rawwire_tools_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        $tool_id = sanitize_key($_POST['tool_id'] ?? '');
        $args = isset($_POST['args']) ? (array) $_POST['args'] : array();
        
        if (!isset($this->tools[$tool_id])) {
            wp_send_json_error(array('message' => 'Tool not found'));
        }
        
        $action_id = $this->run_async($tool_id, $args);
        
        wp_send_json_success(array(
            'message'   => 'Tool scheduled for immediate execution',
            'action_id' => $action_id,
        ));
    }
    
    /**
     * AJAX: Schedule tool
     */
    public function ajax_schedule_tool() {
        check_ajax_referer('rawwire_tools_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        $tool_id = sanitize_key($_POST['tool_id'] ?? '');
        $args = isset($_POST['args']) ? (array) $_POST['args'] : array();
        $when = sanitize_text_field($_POST['when'] ?? 'now');
        $recurrence = sanitize_key($_POST['recurrence'] ?? '');
        
        $action_id = $this->schedule($tool_id, $args, $when, $recurrence ?: null);
        
        wp_send_json_success(array(
            'message'   => 'Tool scheduled successfully',
            'action_id' => $action_id,
        ));
    }
    
    /**
     * AJAX: Cancel scheduled tool
     */
    public function ajax_cancel_tool() {
        check_ajax_referer('rawwire_tools_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        $tool_id = sanitize_key($_POST['tool_id'] ?? '');
        $this->cancel($tool_id);
        
        wp_send_json_success(array('message' => 'Scheduled actions cancelled'));
    }
    
    /**
     * AJAX: Get tool status
     */
    public function ajax_get_status() {
        check_ajax_referer('rawwire_tools_nonce', 'nonce');
        
        $tool_id = sanitize_key($_POST['tool_id'] ?? '');
        
        $scheduled = $this->get_scheduled_actions($tool_id ?: null);
        $history = $this->get_execution_history($tool_id ?: null, 10);
        
        wp_send_json_success(array(
            'scheduled' => count($scheduled),
            'history'   => $history,
        ));
    }
    
    /**
     * AJAX: List all tools
     */
    public function ajax_list_tools() {
        check_ajax_referer('rawwire_tools_nonce', 'nonce');
        
        $tools = array();
        foreach ($this->tools as $id => $tool) {
            $tools[] = array(
                'id'          => $id,
                'name'        => $tool['name'],
                'description' => $tool['description'],
                'category'    => $tool['category'],
                'icon'        => $tool['icon'],
                'enabled'     => $tool['enabled'],
            );
        }
        
        wp_send_json_success(array(
            'tools'      => $tools,
            'categories' => self::CATEGORIES,
        ));
    }
}

// Initialize
RawWire_Tool_Registry::get_instance();
