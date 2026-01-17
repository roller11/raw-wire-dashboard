<?php
/**
 * Abstract Base Tool
 * 
 * Base class for all automation tools in the toolkit.
 * Provides common functionality for execution, logging, and settings.
 * 
 * @package RawWire_Dashboard
 * @subpackage Toolbox_Core
 */

if (!defined('ABSPATH')) {
    exit;
}

abstract class RawWire_Tool_Base {
    
    /**
     * Tool ID (set by child class)
     * @var string
     */
    protected $id = '';
    
    /**
     * Tool settings
     * @var array
     */
    protected $settings = array();
    
    /**
     * Execution context
     * @var array
     */
    protected $context = array();
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->settings = $this->get_settings();
    }
    
    /**
     * Execute the tool (must be implemented by child)
     * 
     * @param array $args Execution arguments
     * @return array Result data
     */
    abstract public function execute(array $args = array());
    
    /**
     * Get tool settings from database
     * 
     * @return array
     */
    protected function get_settings() {
        $option_key = 'rawwire_tool_' . $this->id . '_settings';
        return get_option($option_key, $this->get_default_settings());
    }
    
    /**
     * Save tool settings
     * 
     * @param array $settings
     */
    protected function save_settings(array $settings) {
        $option_key = 'rawwire_tool_' . $this->id . '_settings';
        update_option($option_key, $settings);
        $this->settings = $settings;
    }
    
    /**
     * Get default settings (override in child)
     * 
     * @return array
     */
    protected function get_default_settings() {
        return array();
    }
    
    /**
     * Log a message
     * 
     * @param string $message
     * @param string $level info|warning|error
     */
    protected function log($message, $level = 'info') {
        $log_entry = sprintf(
            '[%s] [%s] [%s] %s',
            current_time('Y-m-d H:i:s'),
            strtoupper($level),
            $this->id,
            $message
        );
        
        // Log to WordPress debug log
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log($log_entry);
        }
        
        // Store in activity log
        do_action('rawwire_tool_log', $this->id, $message, $level);
    }
    
    /**
     * Make HTTP request with error handling
     * 
     * @param string $url
     * @param array $args wp_remote_get/post args
     * @return array|WP_Error
     */
    protected function http_request($url, $args = array()) {
        $defaults = array(
            'timeout'    => $this->settings['timeout'] ?? 30,
            'user-agent' => 'RawWire-Toolkit/1.0',
            'headers'    => array(),
        );
        
        $args = wp_parse_args($args, $defaults);
        
        // Add auth if configured
        if (!empty($this->settings['auth_type'])) {
            $args['headers'] = array_merge(
                $args['headers'],
                $this->get_auth_headers()
            );
        }
        
        $method = $args['method'] ?? 'GET';
        unset($args['method']);
        
        if ($method === 'POST') {
            return wp_remote_post($url, $args);
        }
        
        return wp_remote_get($url, $args);
    }
    
    /**
     * Get authentication headers based on settings
     * 
     * @return array
     */
    protected function get_auth_headers() {
        $auth_type = $this->settings['auth_type'] ?? 'none';
        $headers = array();
        
        switch ($auth_type) {
            case 'api_key':
                $headers['X-API-Key'] = $this->settings['auth_key'] ?? '';
                break;
                
            case 'bearer':
                $headers['Authorization'] = 'Bearer ' . ($this->settings['auth_key'] ?? '');
                break;
                
            case 'basic':
                $credentials = base64_encode(
                    ($this->settings['auth_user'] ?? '') . ':' . ($this->settings['auth_pass'] ?? '')
                );
                $headers['Authorization'] = 'Basic ' . $credentials;
                break;
        }
        
        return $headers;
    }
    
    /**
     * Store result in database table
     * 
     * @param string $table Table name (without prefix)
     * @param array $data Row data
     * @return int|false Insert ID or false
     */
    protected function store_result($table, array $data) {
        global $wpdb;
        
        $full_table = $wpdb->prefix . 'rawwire_' . $table;
        
        $data['created_at'] = current_time('mysql');
        $data['tool_id'] = $this->id;
        
        $result = $wpdb->insert($full_table, $data);
        
        if ($result === false) {
            $this->log("Failed to store result: " . $wpdb->last_error, 'error');
            return false;
        }
        
        return $wpdb->insert_id;
    }
    
    /**
     * Get items from queue for processing
     * 
     * @param string $status Status to filter by
     * @param int $limit Max items to get
     * @return array
     */
    protected function get_queue_items($status = 'pending', $limit = 10) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'rawwire_candidates';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE status = %s ORDER BY created_at ASC LIMIT %d",
            $status,
            $limit
        ));
    }
    
    /**
     * Update queue item status
     * 
     * @param int $id Item ID
     * @param string $status New status
     * @param array $extra Extra data to update
     */
    protected function update_queue_item($id, $status, $extra = array()) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'rawwire_candidates';
        
        $data = array_merge(
            array('status' => $status, 'updated_at' => current_time('mysql')),
            $extra
        );
        
        $wpdb->update($table, $data, array('id' => $id));
    }
    
    /**
     * Dispatch event for other tools/systems
     * 
     * @param string $event Event name
     * @param array $data Event data
     */
    protected function dispatch_event($event, $data = array()) {
        do_action('rawwire_tool_event', $event, $this->id, $data);
        do_action('rawwire_tool_event_' . $event, $this->id, $data);
    }
    
    /**
     * Check if should continue processing (for long-running tools)
     * 
     * @return bool
     */
    protected function should_continue() {
        // Check execution time
        $max_time = $this->settings['timeout'] ?? 300;
        $elapsed = microtime(true) - ($this->context['start_time'] ?? microtime(true));
        
        if ($elapsed > ($max_time * 0.9)) {
            $this->log('Approaching timeout, stopping execution', 'warning');
            return false;
        }
        
        // Check memory usage
        $memory_limit = $this->get_memory_limit();
        $memory_used = memory_get_usage(true);
        
        if ($memory_used > ($memory_limit * 0.8)) {
            $this->log('High memory usage, stopping execution', 'warning');
            return false;
        }
        
        return true;
    }
    
    /**
     * Get memory limit in bytes
     * 
     * @return int
     */
    private function get_memory_limit() {
        $limit = ini_get('memory_limit');
        
        if (preg_match('/^(\d+)(.)$/', $limit, $matches)) {
            $value = (int) $matches[1];
            switch (strtoupper($matches[2])) {
                case 'G': return $value * 1024 * 1024 * 1024;
                case 'M': return $value * 1024 * 1024;
                case 'K': return $value * 1024;
            }
        }
        
        return (int) $limit;
    }
}
