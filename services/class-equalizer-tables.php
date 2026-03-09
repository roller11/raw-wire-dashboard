<?php
/**
 * Equalizer Database Tables
 * Creates and manages the 7 workflow tables for the Raw Wire Equalizer template
 * 
 * Lead Generation Pipeline:
 * - raw: Initial scraped data from web sources
 * - prospects: Filtered/scored leads ready for review
 * - leads: Qualified leads ready for outreach
 * 
 * Post Generator Pipeline:
 * - crdata: Crawled raw data for content (from Crawlomatic)
 * - content: Processed content ready for generation
 * - pending: Generated posts awaiting review
 * - posted: Published posts
 */

namespace RawWire\Dashboard\Services;

class Equalizer_Tables {
    
    /**
     * All table definitions for this template
     */
    public static function get_table_definitions() {
        return array(
            // Lead Generation Pipeline
            'raw' => array(
                'workflow' => 'lead_generation',
                'label' => 'Raw',
                'description' => 'Initial scraped data from web sources',
                'icon' => 'dashicons-download',
                'color' => '#3b82f6', // Blue
            ),
            'prospects' => array(
                'workflow' => 'lead_generation',
                'label' => 'Prospects',
                'description' => 'Filtered/scored leads ready for review',
                'icon' => 'dashicons-filter',
                'color' => '#6366f1', // Indigo
            ),
            'leads' => array(
                'workflow' => 'lead_generation',
                'label' => 'Leads',
                'description' => 'Qualified leads ready for outreach',
                'icon' => 'dashicons-businessperson',
                'color' => '#8b5cf6', // Violet
            ),
            // Post Generator Pipeline
            'crdata' => array(
                'workflow' => 'post_generator',
                'label' => 'CR Data',
                'description' => 'Crawled raw data for content',
                'icon' => 'dashicons-admin-site-alt3',
                'color' => '#10b981', // Emerald
            ),
            'content' => array(
                'workflow' => 'post_generator',
                'label' => 'Content',
                'description' => 'Processed content ready for generation',
                'icon' => 'dashicons-edit',
                'color' => '#14b8a6', // Teal
            ),
            'pending' => array(
                'workflow' => 'post_generator',
                'label' => 'Pending',
                'description' => 'Generated posts awaiting review',
                'icon' => 'dashicons-clock',
                'color' => '#f59e0b', // Amber
            ),
            'posted' => array(
                'workflow' => 'post_generator',
                'label' => 'Posted',
                'description' => 'Published posts',
                'icon' => 'dashicons-yes-alt',
                'color' => '#22c55e', // Green
            ),
        );
    }
    
    /**
     * Create all Equalizer tables
     */
    public static function create_all_tables() {
        self::create_raw_table();
        self::create_prospects_table();
        self::create_leads_table();
        self::create_crdata_table();
        self::create_content_table();
        self::create_pending_table();
        self::create_posted_table();
    }
    
    /**
     * Create RAW table - Initial scraped data
     */
    public static function create_raw_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'rawwire_raw';
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            title varchar(500) NOT NULL,
            content longtext,
            url varchar(2000),
            source varchar(200),
            source_type varchar(50) DEFAULT 'web',
            meta_data longtext,
            raw_html longtext,
            status varchar(20) DEFAULT 'new',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY source_idx (source),
            KEY status_idx (status),
            KEY created_idx (created_at)
        ) {$charset};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Create PROSPECTS table - Filtered/scored leads
     */
    public static function create_prospects_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'rawwire_prospects';
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            raw_id bigint(20),
            company_name varchar(500),
            contact_name varchar(255),
            email varchar(255),
            phone varchar(50),
            website varchar(500),
            industry varchar(100),
            location varchar(255),
            score float DEFAULT 0,
            score_reason longtext,
            source varchar(200),
            meta_data longtext,
            status varchar(20) DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            scored_at datetime,
            PRIMARY KEY (id),
            KEY raw_id_idx (raw_id),
            KEY score_idx (score),
            KEY status_idx (status),
            KEY created_idx (created_at)
        ) {$charset};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Create LEADS table - Qualified leads
     */
    public static function create_leads_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'rawwire_leads';
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            prospect_id bigint(20),
            company_name varchar(500),
            contact_name varchar(255),
            email varchar(255),
            phone varchar(50),
            website varchar(500),
            industry varchar(100),
            location varchar(255),
            score float DEFAULT 0,
            qualification_notes longtext,
            source varchar(200),
            status varchar(20) DEFAULT 'qualified',
            outreach_status varchar(50) DEFAULT 'pending',
            last_contact datetime,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            qualified_at datetime,
            PRIMARY KEY (id),
            KEY prospect_id_idx (prospect_id),
            KEY score_idx (score),
            KEY status_idx (status),
            KEY outreach_idx (outreach_status),
            KEY created_idx (created_at)
        ) {$charset};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Create CRDATA table - Crawlomatic raw data
     */
    public static function create_crdata_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'rawwire_crdata';
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            title varchar(500) NOT NULL,
            content longtext,
            url varchar(2000),
            source varchar(200),
            crawl_rule_id varchar(100),
            crawl_date datetime,
            raw_html longtext,
            images longtext,
            meta_data longtext,
            status varchar(20) DEFAULT 'new',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY source_idx (source),
            KEY status_idx (status),
            KEY rule_idx (crawl_rule_id),
            KEY created_idx (created_at)
        ) {$charset};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Create CONTENT table - Processed content for generation
     */
    public static function create_content_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'rawwire_eq_content';
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            crdata_id bigint(20),
            title varchar(500) NOT NULL,
            original_content longtext,
            processed_content longtext,
            url varchar(2000),
            source varchar(200),
            category varchar(100),
            tags varchar(500),
            score float DEFAULT 0,
            status varchar(20) DEFAULT 'queued',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            processed_at datetime,
            PRIMARY KEY (id),
            KEY crdata_id_idx (crdata_id),
            KEY status_idx (status),
            KEY score_idx (score),
            KEY created_idx (created_at)
        ) {$charset};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Create PENDING table - Generated posts awaiting review
     */
    public static function create_pending_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'rawwire_pending';
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            content_id bigint(20),
            title varchar(500) NOT NULL,
            generated_content longtext,
            featured_image varchar(2000),
            category varchar(100),
            tags varchar(500),
            post_type varchar(50) DEFAULT 'post',
            post_status varchar(20) DEFAULT 'draft',
            source varchar(200),
            source_url varchar(2000),
            ai_model varchar(100),
            generation_prompt longtext,
            status varchar(20) DEFAULT 'pending_review',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            generated_at datetime,
            PRIMARY KEY (id),
            KEY content_id_idx (content_id),
            KEY status_idx (status),
            KEY created_idx (created_at)
        ) {$charset};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Create POSTED table - Published posts
     */
    public static function create_posted_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'rawwire_posted';
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            pending_id bigint(20),
            wp_post_id bigint(20),
            title varchar(500) NOT NULL,
            post_url varchar(2000),
            category varchar(100),
            tags varchar(500),
            source varchar(200),
            source_url varchar(2000),
            published_at datetime,
            views int DEFAULT 0,
            engagement_score float DEFAULT 0,
            status varchar(20) DEFAULT 'published',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY pending_id_idx (pending_id),
            KEY wp_post_id_idx (wp_post_id),
            KEY status_idx (status),
            KEY published_idx (published_at),
            KEY created_idx (created_at)
        ) {$charset};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Get table counts for all Equalizer tables
     */
    public static function get_table_counts() {
        global $wpdb;
        
        $counts = array();
        $tables = array(
            'raw' => $wpdb->prefix . 'rawwire_raw',
            'prospects' => $wpdb->prefix . 'rawwire_prospects',
            'leads' => $wpdb->prefix . 'rawwire_leads',
            'crdata' => $wpdb->prefix . 'rawwire_crdata',
            'content' => $wpdb->prefix . 'rawwire_eq_content',
            'pending' => $wpdb->prefix . 'rawwire_pending',
            'posted' => $wpdb->prefix . 'rawwire_posted',
        );
        
        foreach ($tables as $key => $table) {
            $exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table;
            if ($exists) {
                $counts[$key] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
            } else {
                $counts[$key] = 0;
            }
        }
        
        return $counts;
    }
    
    /**
     * Clear Lead Generation workflow tables
     */
    public static function clear_lead_gen_tables() {
        global $wpdb;
        
        $tables = array(
            $wpdb->prefix . 'rawwire_raw',
            $wpdb->prefix . 'rawwire_prospects',
            $wpdb->prefix . 'rawwire_leads',
        );
        
        $cleared = 0;
        foreach ($tables as $table) {
            if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
                $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
                $wpdb->query("TRUNCATE TABLE {$table}");
                $cleared += $count;
            }
        }
        
        return $cleared;
    }
    
    /**
     * Clear Post Generator workflow tables
     */
    public static function clear_post_gen_tables() {
        global $wpdb;
        
        $tables = array(
            $wpdb->prefix . 'rawwire_crdata',
            $wpdb->prefix . 'rawwire_eq_content',
            $wpdb->prefix . 'rawwire_pending',
            $wpdb->prefix . 'rawwire_posted',
        );
        
        $cleared = 0;
        foreach ($tables as $table) {
            if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
                $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
                $wpdb->query("TRUNCATE TABLE {$table}");
                $cleared += $count;
            }
        }
        
        return $cleared;
    }
    
    /**
     * Get Crawlomatic rules list
     */
    public static function get_crawlomatic_rules() {
        $rules = get_option('crawlomatic_rules_list', array());
        $formatted = array();
        
        if (!empty($rules)) {
            $index = 0;
            foreach ($rules as $rule) {
                $values = array_values($rule);
                $formatted[] = array(
                    'index' => $index,
                    'urls' => isset($values[0]) ? $values[0] : '',
                    'schedule' => isset($values[1]) ? $values[1] : '',
                    'active' => isset($values[2]) ? $values[2] : '0',
                    'last_run' => isset($values[3]) ? $values[3] : '',
                    'max_items' => isset($values[4]) ? $values[4] : 50,
                    'post_status' => isset($values[5]) ? $values[5] : 'publish',
                    'post_type' => isset($values[6]) ? $values[6] : 'post',
                    'selector_type' => isset($values[20]) ? $values[20] : 'class',
                    'selector' => isset($values[21]) ? $values[21] : '',
                    'unique_id' => isset($values[33]) ? $values[33] : '',
                );
                $index++;
            }
        }
        
        return $formatted;
    }
    
    /**
     * Run a Crawlomatic rule by index
     */
    public static function run_crawlomatic_rule($rule_index) {
        if (function_exists('crawlomatic_run_rule')) {
            return crawlomatic_run_rule($rule_index, 0);
        }
        return false;
    }
}
