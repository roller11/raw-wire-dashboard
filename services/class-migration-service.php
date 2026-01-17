<?php
/**
 * Database Migration Service
 * Handles creation and updates of database tables
 * 
 * Tables:
 * - candidates: Raw findings from scraper (temporary staging)
 * - approvals: AI-approved items awaiting human review
 * - content: Human-approved items in AI generation queue
 * - releases: Generated content ready for publishing
 * - archives: Rejected items (permanent storage)
 */

namespace RawWire\Dashboard\Services;

class Migration_Service {
    
    /**
     * Create candidates table
     * Stores scraped items awaiting AI scoring (temporary)
     */
    public static function create_candidates_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'rawwire_candidates';
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            title varchar(500) NOT NULL,
            content longtext,
            link varchar(2000),
            source varchar(200),
            copyright_status varchar(50) DEFAULT 'unknown',
            copyright_info longtext,
            attribution varchar(500),
            publication_date varchar(100),
            document_number varchar(100),
            score int DEFAULT NULL,
            reasoning longtext,
            scorer varchar(50) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY source_idx (source),
            KEY score_idx (score),
            KEY created_idx (created_at),
            UNIQUE KEY title_source_idx (title(255), source(100))
        ) {$charset};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        error_log('RawWire: Candidates table created/updated');
    }
    
    /**
     * Create approvals table
     * Stores AI-approved items awaiting human review
     */
    public static function create_approvals_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'rawwire_approvals';
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            title varchar(500) NOT NULL,
            content longtext,
            link varchar(2000),
            source varchar(200),
            copyright_status varchar(50) DEFAULT 'unknown',
            copyright_info longtext,
            attribution varchar(500),
            publication_date varchar(100),
            document_number varchar(100),
            score float DEFAULT 0,
            ai_reason longtext,
            status varchar(20) DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            scored_at datetime,
            PRIMARY KEY (id),
            KEY source_idx (source),
            KEY status_idx (status),
            KEY score_idx (score),
            KEY created_idx (created_at),
            UNIQUE KEY title_source_idx (title(255), source(100))
        ) {$charset};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        error_log('RawWire: Approvals table created/updated');
    }
    
    /**
     * Create content table
     * Stores human-approved items in AI generation queue
     */
    public static function create_content_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'rawwire_content';
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            title varchar(500) NOT NULL,
            original_content longtext,
            generated_content longtext,
            link varchar(2000),
            source varchar(200),
            copyright_status varchar(50) DEFAULT 'unknown',
            copyright_info longtext,
            attribution varchar(500),
            publication_date varchar(100),
            document_number varchar(100),
            score float DEFAULT 0,
            ai_reason longtext,
            status varchar(20) DEFAULT 'queued',
            approved_at datetime,
            generation_started_at datetime,
            generation_completed_at datetime,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY source_idx (source),
            KEY status_idx (status),
            KEY created_idx (created_at),
            UNIQUE KEY title_source_idx (title(255), source(100))
        ) {$charset};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        error_log('RawWire: Content table created/updated');
    }
    
    /**
     * Create releases table
     * Stores generated content ready for publishing
     */
    public static function create_releases_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'rawwire_releases';
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            title varchar(500) NOT NULL,
            original_content longtext,
            generated_content longtext,
            link varchar(2000),
            source varchar(200),
            copyright_status varchar(50) DEFAULT 'unknown',
            copyright_info longtext,
            attribution varchar(500),
            publication_date varchar(100),
            document_number varchar(100),
            score float DEFAULT 0,
            status varchar(20) DEFAULT 'ready',
            approved_at datetime,
            generated_at datetime,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY source_idx (source),
            KEY status_idx (status),
            KEY created_idx (created_at),
            UNIQUE KEY title_source_idx (title(255), source(100))
        ) {$charset};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        error_log('RawWire: Releases table created/updated');
    }
    
    /**
     * Create archives table
     * Stores rejected items (permanent historical record)
     */
    public static function create_archives_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'rawwire_archives';
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            title varchar(500) NOT NULL,
            content longtext,
            link varchar(2000),
            source varchar(200),
            copyright_status varchar(50) DEFAULT 'unknown',
            copyright_info longtext,
            attribution varchar(500),
            publication_date varchar(100),
            document_number varchar(100),
            score float DEFAULT 0,
            ai_reason longtext,
            result varchar(20) DEFAULT 'Rejected',
            rejection_reason varchar(50) DEFAULT 'ai_rejected',
            status varchar(20) DEFAULT 'archived',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            scored_at datetime,
            archived_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY source_idx (source),
            KEY result_idx (result),
            KEY rejection_reason_idx (rejection_reason),
            KEY created_idx (created_at),
            UNIQUE KEY title_source_idx (title(255), source(100))
        ) {$charset};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        error_log('RawWire: Archives table created/updated');
    }
    
    /**
     * Create published table
     * Stores final published content (finished products)
     */
    public static function create_published_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'rawwire_published';
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            title varchar(500) NOT NULL,
            original_content longtext,
            generated_content longtext,
            final_content longtext,
            link varchar(2000),
            source varchar(200),
            copyright_status varchar(50) DEFAULT 'cleared',
            copyright_info longtext,
            attribution varchar(500),
            publication_date varchar(100),
            document_number varchar(100),
            score float DEFAULT 0,
            status varchar(20) DEFAULT 'published',
            wp_post_id bigint(20) DEFAULT NULL,
            published_url varchar(2000),
            approved_at datetime,
            generated_at datetime,
            published_at datetime DEFAULT CURRENT_TIMESTAMP,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY source_idx (source),
            KEY status_idx (status),
            KEY published_at_idx (published_at),
            KEY wp_post_id_idx (wp_post_id),
            UNIQUE KEY title_source_idx (title(255), source(100))
        ) {$charset};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        error_log('RawWire: Published table created/updated');
    }
    
    /**
     * Run all migrations
     */
    public static function run_migrations() {
        self::create_candidates_table();
        self::create_approvals_table();
        self::create_content_table();
        self::create_releases_table();
        self::create_archives_table();
        self::create_published_table();
    }
}
