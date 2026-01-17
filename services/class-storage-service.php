<?php
/**
 * Content Storage Service
 * 
 * Handles all database operations for storing and retrieving content.
 * 
 * @package RawWire_Dashboard
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class RawWire_Storage_Service {
    
    /**
     * Table name
     */
    private $table;
    
    /**
     * Logger
     */
    private $logger;
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'rawwire_content';
        $this->logger = class_exists('RawWire_Logger') ? 'RawWire_Logger' : null;
    }
    
    /**
     * Log message
     */
    private function log($message, $level = 'info', $context = array()) {
        if ($this->logger && method_exists($this->logger, $level)) {
            call_user_func(array($this->logger, $level), "[StorageService] " . $message, $context);
        }
        error_log("[RawWire StorageService] [{$level}] {$message}");
    }
    
    /**
     * Store items to database
     * 
     * @param array $items Items to store
     * @return array Results with counts
     */
    public function store_items($items) {
        global $wpdb;
        
        $result = array(
            'success' => false,
            'stored' => 0,
            'duplicates' => 0,
            'errors' => 0,
            'items' => array(),
        );
        
        if (empty($items)) {
            $this->log('No items to store', 'warning');
            return $result;
        }
        
        $this->log('Storing ' . count($items) . ' items', 'info');
        
        // Ensure table exists
        if (!$this->table_exists()) {
            $this->create_table();
        }
        
        foreach ($items as $item) {
            // Check for duplicate title to avoid re-selecting already stored items
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->table} WHERE title = %s",
                $item['title']
            ));

            if ($existing) {
                $result['duplicates']++;
                continue;
            }

            // Build data array with all metadata fields
            $data = array(
                'title' => sanitize_text_field($item['title'] ?? 'Untitled'),
                'content' => wp_kses_post($item['content'] ?? ''),
                'status' => 'pending',
                'source' => sanitize_text_field($item['source'] ?? ''),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            );
            
            // Add optional fields if they exist in schema
            if ($this->column_exists('url')) {
                $data['url'] = esc_url_raw($item['link'] ?? '');
            }
            if ($this->column_exists('relevance') && isset($item['score'])) {
                $data['relevance'] = floatval($item['score']);
            }
            if ($this->column_exists('copyright_status')) {
                $data['copyright_status'] = sanitize_text_field($item['copyright_status'] ?? 'unknown');
            }
            if ($this->column_exists('attribution')) {
                $data['attribution'] = sanitize_text_field($item['attribution'] ?? '');
            }
            if ($this->column_exists('publication_date') && !empty($item['publication_date'])) {
                $data['publication_date'] = sanitize_text_field($item['publication_date']);
            }
            if ($this->column_exists('document_number') && !empty($item['document_number'])) {
                $data['document_number'] = sanitize_text_field($item['document_number']);
            }
            
            $inserted = $wpdb->insert($this->table, $data);
            
            if ($inserted) {
                $result['stored']++;
                $result['items'][] = array(
                    'id' => $wpdb->insert_id,
                    'title' => $data['title'],
                    'source' => $data['source'],
                );
                $this->log("Stored: {$data['title']}", 'debug');
            } else {
                $result['errors']++;
                $this->log("Failed to store: {$data['title']} - " . $wpdb->last_error, 'error');
            }
        }
        
        $result['success'] = ($result['stored'] > 0);
        $this->log("Storage complete. Stored: {$result['stored']}, Duplicates: {$result['duplicates']}, Errors: {$result['errors']}", 'info');
        
        return $result;
    }
    
    /**
     * Get pending items
     */
    public function get_pending($limit = 50) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE status = 'pending' ORDER BY created_at DESC LIMIT %d",
            $limit
        ), ARRAY_A);
    }
    
    /**
     * Get items by status
     */
    public function get_by_status($status, $limit = 50, $offset = 0) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $status,
            $limit,
            $offset
        ), ARRAY_A);
    }
    
    /**
     * Update item status
     */
    public function update_status($id, $status) {
        global $wpdb;
        
        return $wpdb->update(
            $this->table,
            array(
                'status' => sanitize_text_field($status),
                'updated_at' => current_time('mysql'),
            ),
            array('id' => intval($id))
        );
    }
    
    /**
     * Get counts by status
     */
    public function get_counts() {
        global $wpdb;
        
        $results = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM {$this->table} GROUP BY status",
            ARRAY_A
        );
        
        $counts = array(
            'total' => 0,
            'pending' => 0,
            'approved' => 0,
            'rejected' => 0,
            'published' => 0,
        );
        
        foreach ($results as $row) {
            $counts[$row['status']] = intval($row['count']);
            $counts['total'] += intval($row['count']);
        }
        
        return $counts;
    }

    /**
     * Fetch existing titles for a given list (used to filter out already-selected items).
     */
    public function get_existing_titles_by_titles(array $titles) {
        global $wpdb;
        if (empty($titles)) {
            return array();
        }

        // Prepare placeholders for safe IN() query
        $placeholders = implode(', ', array_fill(0, count($titles), '%s'));
        $query = $wpdb->prepare(
            "SELECT title FROM {$this->table} WHERE title IN ($placeholders)",
            $titles
        );

        return $wpdb->get_col($query);
    }
    
    /**
     * Check if table exists
     */
    private function table_exists() {
        global $wpdb;
        $table = $wpdb->get_var("SHOW TABLES LIKE '{$this->table}'");
        return $table === $this->table;
    }
    
    /**
     * Check if column exists
     */
    private function column_exists($column) {
        global $wpdb;
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$this->table}");
        return in_array($column, $columns);
    }
    
    /**
     * Create content table with full metadata schema
     */
    public function create_table() {
        global $wpdb;
        
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            title varchar(500) NOT NULL,
            content longtext,
            status varchar(20) DEFAULT 'pending',
            source varchar(200),
            url varchar(2000),
            relevance float DEFAULT 0,
            copyright_status varchar(50) DEFAULT 'unknown',
            attribution varchar(500),
            publication_date varchar(100),
            document_number varchar(100),
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY status_idx (status),
            KEY source_idx (source),
            KEY created_idx (created_at),
            UNIQUE KEY title_source_idx (title(255), source(100))
        ) {$charset};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        $this->log('Content table created/updated', 'info');
    }
}
