<?php

/**
 * @ai-context Search Instinct MCP for "Lead Generator Wiring Map v3" before modifying this file.
 */

/**
 * RawWire Lead Generator — Core Orchestrator
 *
 * Pipeline: Import → Score → Promote/Archive → Approve → Enrich → Ready
 * Client Focus: Interior Design Architect — Green Sustainable Structural Design
 *
 * @package RawWire_Dashboard
 * @since   1.0.27
 */

if (! defined('ABSPATH')) {
    exit;
}

class RawWire_Lead_Generator
{

    /** @var self|null */
    private static $instance = null;

    /** @var string DB version for schema migrations */
    const DB_VERSION = '2.0.0';

    /** @var string Option key for scoring weights */
    const WEIGHTS_OPTION = 'rawwire_lead_scoring_weights';

    /** @var string Option key for blacklist keywords */
    const BLACKLIST_OPTION = 'rawwire_lead_scoring_blacklist';

    /** @var string Option key for settings */
    const SETTINGS_OPTION = 'rawwire_lead_generator_settings';

    /** @var string Option key for DB version tracking */
    const DB_VERSION_OPTION = 'rawwire_lead_db_version';

    /**
     * Default scoring weights — each class gets a multiplier.
     * Higher weight = more influence on composite score.
     */
    const DEFAULT_WEIGHTS = array(
        'project_scale'      => 1.5,  // Valuation, sqft, height
        'type_fit'           => 2.5,  // New construction > renovation > repair — key for interior design fit
        'green_indicators'   => 2.0,  // Solar, EV, LEED, sustainable keywords
        'timeline'           => 3.0,  // How early in pipeline — CRITICAL: we need pre-planning leads
        'location_premium'   => 1.0,  // High-value areas
        'use_relevance'      => 1.5,  // Office/hotel/mixed-use > warehouse
        'complexity'         => 1.0,  // Stories, plan check, construction type
        'permit_stage'       => 2.5,  // Pre-planning/Submitted permits = higher priority (early engagement)
    );

    /** @var string[] Table names (without prefix) */
    const TABLES = array(
        'sources'          => 'rawwire_lead_sources',
        'candidates'       => 'rawwire_lead_candidates',
        'archive'          => 'rawwire_lead_archive',
        'content'          => 'rawwire_lead_content',
        'network_entities' => 'rawwire_network_entities',
        'company_profiles' => 'rawwire_company_profiles',
        'contacts'         => 'rawwire_contacts',
        'events'           => 'rawwire_events',
        'relationships'    => 'rawwire_relationships',
        'network_projects' => 'rawwire_network_projects',
    );

    /**
     * Default settings
     */
    const DEFAULT_SETTINGS = array(
        'candidate_threshold'   => 5.0,   // Minimum composite score to become candidate
        'candidate_limit'       => 100,   // Max candidates per scoring batch
        'import_limit'          => 500,   // Max records per import batch
        'auto_score'            => true,  // Auto-score after import
        'auto_promote'          => true,  // Auto-promote after scoring
        'auto_investigate'      => false, // Auto-investigate promoted candidates (costs API tokens)
        'triage_batch_size'     => 10,    // Records per triage chunk (keep under 2KB for Venice)
        'triage_picks'         => 2,     // How many top records to pick from each triage chunk
        'socrata_app_token'     => '',
        'socrata_secret_token'  => '',
        'textrazor_api_key'     => '',
        'primary_dataset'       => 'pi9x-tg5x',
        'secondary_datasets'    => array('xnhu-aczu', '9yda-i4ya', 'gwh9-jnip'),
        'permit_types'          => array('Bldg-New', 'Bldg-Alter/Repair', 'Bldg-Addition'),
        'permit_sub_types'      => array('Commercial', 'Apartment'),
        'min_valuation'         => 100000,
        'date_range_months'     => 6,
    );

    /** @return self */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        // Eagerly load sub-classes so static methods (e.g. get_datasets) are available
        require_once __DIR__ . '/class-socrata-client.php';
        require_once __DIR__ . '/class-lead-scorer.php';
        // DEPRECATED: Lead Enricher uses old AI Adapter — superseded by Party Investigator + OpenClaw
        // require_once __DIR__ . '/class-lead-enricher.php';
        require_once __DIR__ . '/class-party-investigator.php';

        $this->maybe_create_tables();
        $this->register_hooks();
    }

    /**
     * Register WordPress hooks
     */
    private function register_hooks()
    {
        // AJAX handlers
        add_action('wp_ajax_rawwire_lead_import',        array($this, 'ajax_import'));
        add_action('wp_ajax_rawwire_lead_score',         array($this, 'ajax_score_batch'));
        add_action('wp_ajax_rawwire_lead_promote',       array($this, 'ajax_promote'));
        add_action('wp_ajax_rawwire_lead_approve',       array($this, 'ajax_approve'));
        add_action('wp_ajax_rawwire_lead_reject',        array($this, 'ajax_reject'));
        add_action('wp_ajax_rawwire_lead_trash',         array($this, 'ajax_trash'));
        add_action('wp_ajax_rawwire_lead_restore',       array($this, 'ajax_restore'));
        add_action('wp_ajax_rawwire_lead_purge',         array($this, 'ajax_purge'));
        add_action('wp_ajax_rawwire_lead_enrich',        array($this, 'ajax_enrich'));
        add_action('wp_ajax_rawwire_lead_update_weights', array($this, 'ajax_update_weights'));
        add_action('wp_ajax_rawwire_lead_rescore',       array($this, 'ajax_rescore_all'));
        add_action('wp_ajax_rawwire_lead_save_settings', array($this, 'ajax_save_settings'));
        add_action('wp_ajax_rawwire_lead_save_blacklist', array($this, 'ajax_save_blacklist'));
        add_action('wp_ajax_rawwire_lead_get_stats',     array($this, 'ajax_get_stats'));
        add_action('wp_ajax_rawwire_lead_bulk_action',   array($this, 'ajax_bulk_action'));
        add_action('wp_ajax_rawwire_lead_update_status', array($this, 'ajax_update_lead_status'));
        add_action('wp_ajax_rawwire_lead_preview',       array($this, 'ajax_preview_import'));
        add_action('wp_ajax_rawwire_lead_reset_tables',  array($this, 'ajax_reset_tables'));

        // Cron for automated pipeline
        add_action('rawwire_lead_cron_import',  array($this, 'cron_import'));
        add_action('rawwire_lead_cron_score',   array($this, 'cron_score'));
        add_action('rawwire_lead_cron_promote',  array($this, 'cron_promote'));

        // Pipeline event: auto-investigate promoted candidates (if enabled)
        $settings = $this->get_settings();
        if (!empty($settings['auto_investigate'])) {
            add_action('rawwire_candidates_promoted', array($this, 'investigate_promoted_candidates'), 10, 2);
        }

        // Manual investigation AJAX handler
        add_action('wp_ajax_rawwire_lead_investigate', array($this, 'ajax_investigate_single'));

        // Enqueue assets
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    /**
     * Enqueue lead generator assets on relevant pages
     */
    public function enqueue_assets($hook)
    {
        if (false === strpos($hook, 'rawwire-lead')) {
            return;
        }

        $base_url = plugin_dir_url(dirname(__DIR__));

        wp_enqueue_style(
            'rawwire-lead-generator',
            $base_url . 'assets/css/lead-generator.css',
            array(),
            '1.0.11'
        );

        wp_enqueue_script(
            'rawwire-lead-generator',
            $base_url . 'assets/js/lead-generator.js',
            array('jquery'),
            '1.0.11',
            true
        );

        wp_localize_script('rawwire-lead-generator', 'RawWireLeads', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('rawwire_lead_nonce'),
            'strings' => array(
                'confirm_approve'     => __('Approve this candidate?', 'raw-wire-dashboard'),
                'confirm_reject'      => __('Reject this candidate?', 'raw-wire-dashboard'),
                'confirm_bulk'        => __('Apply action to selected items?', 'raw-wire-dashboard'),
                'importing'           => __('Importing records...', 'raw-wire-dashboard'),
                'scoring'             => __('Scoring records...', 'raw-wire-dashboard'),
                'enriching'           => __('Enriching lead data...', 'raw-wire-dashboard'),
                'import_complete'     => __('Import complete!', 'raw-wire-dashboard'),
                'score_complete'      => __('Scoring complete!', 'raw-wire-dashboard'),
                'no_selection'        => __('No items selected.', 'raw-wire-dashboard'),
            ),
        ));
    }

    // =========================================================================
    // DATABASE SCHEMA
    // =========================================================================

    /**
     * Create/update database tables
     */
    public function maybe_create_tables()
    {
        $installed_version = get_option(self::DB_VERSION_OPTION, '0');
        if (version_compare($installed_version, self::DB_VERSION, '>=')) {
            return;
        }

        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // v2.0.0 migration: Add new columns to existing tables
        // dbDelta doesn't add columns, so we do it explicitly
        if (version_compare($installed_version, '2.0.0', '<') && $installed_version !== '0') {
            $this->migrate_to_v2();
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Sources table — raw imported records
        $table = $wpdb->prefix . self::TABLES['sources'];
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_api varchar(50) NOT NULL DEFAULT 'socrata_permits',
            source_dataset varchar(50) NOT NULL DEFAULT '',
            source_record_id varchar(100) NOT NULL DEFAULT '',
            permit_nbr varchar(50) NOT NULL DEFAULT '',
            primary_address text,
            zip_code varchar(20) DEFAULT '',
            council_district varchar(10) DEFAULT '',
            permit_type varchar(100) DEFAULT '',
            permit_sub_type varchar(100) DEFAULT '',
            use_code varchar(20) DEFAULT '',
            use_desc varchar(200) DEFAULT '',
            work_desc text,
            valuation decimal(15,2) DEFAULT 0.00,
            square_footage int DEFAULT 0,
            height varchar(50) DEFAULT '',
            stories varchar(20) DEFAULT '',
            construction_type varchar(100) DEFAULT '',
            status_desc varchar(100) DEFAULT '',
            status_date datetime DEFAULT NULL,
            submitted_date datetime DEFAULT NULL,
            issue_date datetime DEFAULT NULL,
            cofo_date datetime DEFAULT NULL,
            zone varchar(50) DEFAULT '',
            community_plan_area varchar(200) DEFAULT '',
            neighborhood_council varchar(200) DEFAULT '',
            lat decimal(10,7) DEFAULT NULL,
            lon decimal(10,7) DEFAULT NULL,
            contractor_name varchar(200) DEFAULT '',
            contractor_license varchar(50) DEFAULT '',
            principal_name varchar(200) DEFAULT NULL,
            applicant_name varchar(200) DEFAULT '',
            applicant_business varchar(200) DEFAULT '',
            solar varchar(10) DEFAULT '',
            ev varchar(10) DEFAULT '',
            business_unit varchar(100) DEFAULT '',
            owner_name varchar(200) DEFAULT '',
            owner_address text,
            owner_city varchar(100) DEFAULT '',
            contractor_address text,
            contractor_city varchar(100) DEFAULT '',
            contractor_state varchar(20) DEFAULT '',
            party_profiles longtext,
            parties_investigated_at datetime DEFAULT NULL,
            investigation_status varchar(20) DEFAULT 'pending',
            investigated_by varchar(100) DEFAULT '',
            investigator_notes text,
            investigator_findings longtext,
            investigator_flags text,
            investigator_opportunities text,
            investigator_confidence tinyint unsigned DEFAULT NULL,
            investigator_summary text,
            raw_json longtext,
            tags text,
            meta longtext,
            import_batch varchar(50) DEFAULT '',
            imported_at datetime NOT NULL,
            processed tinyint(1) NOT NULL DEFAULT 0,
            processed_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idx_source_record (source_dataset, source_record_id),
            KEY idx_permit (permit_nbr),
            KEY idx_type (permit_type, permit_sub_type),
            KEY idx_processed (processed),
            KEY idx_issue_date (issue_date),
            KEY idx_valuation (valuation),
            KEY idx_batch (import_batch)
        ) {$charset_collate};");

        // Candidates table — AI-scored records awaiting approval
        $table = $wpdb->prefix . self::TABLES['candidates'];
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_id bigint(20) unsigned NOT NULL,
            permit_nbr varchar(50) NOT NULL DEFAULT '',
            primary_address text,
            zip_code varchar(20) DEFAULT '',
            city varchar(100) DEFAULT '',
            state varchar(20) DEFAULT 'CA',
            council_district varchar(10) DEFAULT '',
            community_plan_area varchar(200) DEFAULT '',
            neighborhood_council varchar(200) DEFAULT '',
            permit_type varchar(100) DEFAULT '',
            permit_sub_type varchar(100) DEFAULT '',
            use_code varchar(20) DEFAULT '',
            use_desc varchar(200) DEFAULT '',
            work_desc text,
            valuation decimal(15,2) DEFAULT 0.00,
            square_footage int DEFAULT 0,
            height varchar(50) DEFAULT '',
            stories varchar(20) DEFAULT '',
            construction_type varchar(100) DEFAULT '',
            status_desc varchar(100) DEFAULT '',
            status_date datetime DEFAULT NULL,
            submitted_date datetime DEFAULT NULL,
            issue_date datetime DEFAULT NULL,
            cofo_date datetime DEFAULT NULL,
            estimated_start_date date DEFAULT NULL,
            estimated_completion_date date DEFAULT NULL,
            contractor_name varchar(200) DEFAULT '',
            contractor_license varchar(50) DEFAULT '',
            contractor_address text,
            contractor_city varchar(100) DEFAULT '',
            contractor_state varchar(20) DEFAULT '',
            contractor_phone varchar(50) DEFAULT '',
            contractor_email varchar(200) DEFAULT '',
            principal_name varchar(200) DEFAULT NULL,
            applicant_name varchar(200) DEFAULT '',
            applicant_business varchar(200) DEFAULT '',
            applicant_phone varchar(50) DEFAULT '',
            applicant_email varchar(200) DEFAULT '',
            owner_name varchar(200) DEFAULT '',
            owner_address text,
            owner_city varchar(100) DEFAULT '',
            owner_phone varchar(50) DEFAULT '',
            owner_email varchar(200) DEFAULT '',
            architect_name varchar(200) DEFAULT '',
            architect_firm varchar(200) DEFAULT '',
            architect_license varchar(50) DEFAULT '',
            lat decimal(10,7) DEFAULT NULL,
            lon decimal(10,7) DEFAULT NULL,
            solar varchar(10) DEFAULT '',
            ev varchar(10) DEFAULT '',
            zone varchar(50) DEFAULT '',
            score_project_scale decimal(3,1) DEFAULT 0.0,
            score_type_fit decimal(3,1) DEFAULT 0.0,
            score_green_indicators decimal(3,1) DEFAULT 0.0,
            score_timeline decimal(3,1) DEFAULT 0.0,
            score_location decimal(3,1) DEFAULT 0.0,
            score_use_relevance decimal(3,1) DEFAULT 0.0,
            score_complexity decimal(3,1) DEFAULT 0.0,
            score_composite decimal(4,2) DEFAULT 0.00,
            score_reasoning text,
            status varchar(20) NOT NULL DEFAULT 'pending',
            status_changed_at datetime DEFAULT NULL,
            status_changed_by bigint(20) unsigned DEFAULT NULL,
            scored_at datetime DEFAULT NULL,
            tags text,
            meta longtext,
            PRIMARY KEY (id),
            KEY idx_source (source_id),
            KEY idx_status (status),
            KEY idx_score (score_composite),
            KEY idx_permit (permit_nbr),
            KEY idx_issue_date (issue_date),
            KEY idx_zip (zip_code)
        ) {$charset_collate};");

        // Archive table — rejected/low-score records
        $table = $wpdb->prefix . self::TABLES['archive'];
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_id bigint(20) unsigned NOT NULL,
            permit_nbr varchar(50) NOT NULL DEFAULT '',
            primary_address text,
            permit_type varchar(100) DEFAULT '',
            permit_sub_type varchar(100) DEFAULT '',
            use_desc varchar(200) DEFAULT '',
            work_desc text,
            valuation decimal(15,2) DEFAULT 0.00,
            score_composite decimal(4,2) DEFAULT 0.00,
            score_reasoning text,
            archived_reason varchar(200) DEFAULT 'low_score',
            archived_at datetime NOT NULL,
            tags text,
            meta longtext,
            PRIMARY KEY (id),
            KEY idx_source (source_id),
            KEY idx_reason (archived_reason)
        ) {$charset_collate};");

        // Content table — approved leads with enriched research
        $table = $wpdb->prefix . self::TABLES['content'];
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            candidate_id bigint(20) unsigned NOT NULL,
            source_id bigint(20) unsigned NOT NULL,
            permit_nbr varchar(50) NOT NULL DEFAULT '',
            primary_address text,
            zip_code varchar(20) DEFAULT '',
            permit_type varchar(100) DEFAULT '',
            permit_sub_type varchar(100) DEFAULT '',
            use_desc varchar(200) DEFAULT '',
            work_desc text,
            valuation decimal(15,2) DEFAULT 0.00,
            square_footage int DEFAULT 0,
            issue_date datetime DEFAULT NULL,
            lat decimal(10,7) DEFAULT NULL,
            lon decimal(10,7) DEFAULT NULL,
            project_name varchar(500) DEFAULT '',
            project_summary text,
            filing_party_info longtext,
            contractor_info longtext,
            architect_info longtext,
            owner_info longtext,
            location_analysis text,
            market_context text,
            green_analysis text,
            opportunity_notes text,
            research_sources longtext,
            score_composite decimal(4,2) DEFAULT 0.00,
            status varchar(20) NOT NULL DEFAULT 'enriching',
            enriched_at datetime DEFAULT NULL,
            status_changed_at datetime DEFAULT NULL,
            status_changed_by bigint(20) unsigned DEFAULT NULL,
            tags text,
            meta longtext,
            PRIMARY KEY (id),
            KEY idx_candidate (candidate_id),
            KEY idx_status (status),
            KEY idx_score (score_composite)
        ) {$charset_collate};");

        // Network entities table — discovered contractors, partners, subcontractors
        $table = $wpdb->prefix . self::TABLES['network_entities'];
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_id bigint(20) unsigned DEFAULT NULL,
            discovered_from varchar(100) DEFAULT '',
            name varchar(200) NOT NULL DEFAULT '',
            company varchar(200) DEFAULT '',
            type varchar(50) NOT NULL DEFAULT 'contractor',
            relationship varchar(200) DEFAULT '',
            license varchar(50) DEFAULT '',
            contact_info longtext,
            score int DEFAULT 0,
            score_hint int DEFAULT 0,
            notes text,
            probed tinyint(1) NOT NULL DEFAULT 0,
            probed_at datetime DEFAULT NULL,
            investigation_tier tinyint(1) NOT NULL DEFAULT 0,
            tier2_completed tinyint(1) NOT NULL DEFAULT 0,
            raw_data longtext,
            meta longtext,
            created_at datetime NOT NULL,
            updated_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_source (source_id),
            KEY idx_type (type),
            KEY idx_score (score),
            KEY idx_probed (probed),
            KEY idx_name (name(100))
        ) {$charset_collate};");

        // Network projects table — discovered related jobs and project opportunities
        $table = $wpdb->prefix . self::TABLES['network_projects'];
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_id bigint(20) unsigned DEFAULT NULL,
            discovered_from varchar(100) DEFAULT '',
            name varchar(255) NOT NULL DEFAULT '',
            location text,
            status varchar(50) DEFAULT '',
            status_label varchar(100) DEFAULT '',
            value decimal(15,2) DEFAULT NULL,
            contractor varchar(255) DEFAULT '',
            role varchar(100) DEFAULT '',
            score int DEFAULT 0,
            notes text,
            probed tinyint(1) NOT NULL DEFAULT 0,
            probed_at datetime DEFAULT NULL,
            investigation_tier tinyint(1) NOT NULL DEFAULT 0,
            tier2_completed tinyint(1) NOT NULL DEFAULT 0,
            raw_data longtext,
            meta longtext,
            created_at datetime NOT NULL,
            updated_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_source (source_id),
            KEY idx_status (status),
            KEY idx_score (score),
            KEY idx_probed (probed),
            KEY idx_name (name(100))
        ) {$charset_collate};");

        // =====================================================================
        // COMPANY PROFILES — Rich company intelligence from investigations
        // =====================================================================
        $table = $wpdb->prefix . self::TABLES['company_profiles'];
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_id bigint(20) unsigned DEFAULT NULL,
            entity_id bigint(20) unsigned DEFAULT NULL,
            
            -- Core Identity
            name varchar(300) NOT NULL DEFAULT '',
            legal_name varchar(300) DEFAULT '',
            dba_names text,
            company_type varchar(100) DEFAULT '',
            
            -- Size & Revenue
            employee_count int DEFAULT NULL,
            employee_range varchar(50) DEFAULT '',
            annual_revenue decimal(15,2) DEFAULT NULL,
            revenue_range varchar(50) DEFAULT '',
            year_founded int DEFAULT NULL,
            years_in_business int DEFAULT NULL,
            
            -- Location
            hq_address text,
            hq_city varchar(100) DEFAULT '',
            hq_state varchar(20) DEFAULT '',
            hq_zip varchar(20) DEFAULT '',
            hq_country varchar(50) DEFAULT 'USA',
            office_locations text,
            service_areas text,
            
            -- Licensing & Certifications
            contractor_license varchar(50) DEFAULT '',
            license_class varchar(100) DEFAULT '',
            license_status varchar(50) DEFAULT '',
            license_expiry date DEFAULT NULL,
            bonding_amount decimal(15,2) DEFAULT NULL,
            insurance_amount decimal(15,2) DEFAULT NULL,
            certifications text,
            programs text,
            
            -- Web Presence
            website_url varchar(500) DEFAULT '',
            website_tech_stack text,
            website_last_updated date DEFAULT NULL,
            linkedin_url varchar(500) DEFAULT '',
            linkedin_employee_count int DEFAULT NULL,
            facebook_url varchar(500) DEFAULT '',
            instagram_url varchar(500) DEFAULT '',
            twitter_url varchar(500) DEFAULT '',
            youtube_url varchar(500) DEFAULT '',
            yelp_url varchar(500) DEFAULT '',
            google_business_url varchar(500) DEFAULT '',
            
            -- Industry Position
            specialties text,
            project_types text,
            typical_project_size varchar(100) DEFAULT '',
            market_segments text,
            
            -- Bidding & Procurement
            bidding_method varchar(100) DEFAULT '',
            prequalification_required tinyint(1) DEFAULT 0,
            prequalification_details text,
            bid_submission_process text,
            sub_selection_method varchar(200) DEFAULT '',
            typical_sub_notice_days int DEFAULT NULL,
            payment_terms text,
            bonding_requirements text,
            
            -- Subcontractor Relations
            preferred_subs text,
            sub_categories_needed text,
            sub_qualification_criteria text,
            typical_sub_contract_value varchar(100) DEFAULT '',
            
            -- Key Partnerships
            architects text,
            engineering_firms text,
            suppliers text,
            preferred_vendors text,
            
            -- Upcoming Activity
            upcoming_projects text,
            bid_opportunities text,
            expansion_plans text,
            hiring_status text,
            
            -- Reputation & Reviews
            bbb_rating varchar(10) DEFAULT '',
            yelp_rating decimal(2,1) DEFAULT NULL,
            google_rating decimal(2,1) DEFAULT NULL,
            notable_awards text,
            media_mentions text,
            
            -- Contact Strategy
            gatekeeper_name varchar(200) DEFAULT '',
            gatekeeper_title varchar(100) DEFAULT '',
            gatekeeper_contact text,
            best_approach text,
            decision_maker_access text,
            
            -- Investigation Metadata
            investigation_tier tinyint(1) DEFAULT 1,
            confidence_score tinyint unsigned DEFAULT NULL,
            data_freshness_score tinyint unsigned DEFAULT NULL,
            last_investigated_at datetime DEFAULT NULL,
            investigation_notes text,
            sources_used text,
            
            -- System
            created_at datetime NOT NULL,
            updated_at datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_source (source_id),
            KEY idx_entity (entity_id),
            KEY idx_name (name(100)),
            KEY idx_license (contractor_license),
            KEY idx_tier (investigation_tier)
        ) {$charset_collate};");

        // =====================================================================
        // CONTACTS — Individual people with full professional profiles
        // =====================================================================
        $table = $wpdb->prefix . self::TABLES['contacts'];
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_id bigint(20) unsigned DEFAULT NULL,
            company_id bigint(20) unsigned DEFAULT NULL,
            entity_id bigint(20) unsigned DEFAULT NULL,
            
            -- Identity
            first_name varchar(100) NOT NULL DEFAULT '',
            last_name varchar(100) NOT NULL DEFAULT '',
            full_name varchar(200) AS (CONCAT(first_name, ' ', last_name)) STORED,
            title varchar(200) DEFAULT '',
            department varchar(100) DEFAULT '',
            role_type varchar(50) DEFAULT '',
            seniority_level varchar(50) DEFAULT '',
            is_decision_maker tinyint(1) DEFAULT 0,
            is_gatekeeper tinyint(1) DEFAULT 0,
            is_key_contact tinyint(1) DEFAULT 0,
            
            -- Contact Info
            email varchar(200) DEFAULT '',
            email_verified tinyint(1) DEFAULT 0,
            email_source varchar(100) DEFAULT '',
            phone varchar(50) DEFAULT '',
            phone_type varchar(20) DEFAULT '',
            mobile varchar(50) DEFAULT '',
            fax varchar(50) DEFAULT '',
            direct_line varchar(50) DEFAULT '',
            
            -- Location
            office_address text,
            office_city varchar(100) DEFAULT '',
            office_state varchar(20) DEFAULT '',
            office_zip varchar(20) DEFAULT '',
            
            -- Social Profiles
            linkedin_url varchar(500) DEFAULT '',
            linkedin_connections int DEFAULT NULL,
            twitter_url varchar(500) DEFAULT '',
            facebook_url varchar(500) DEFAULT '',
            instagram_url varchar(500) DEFAULT '',
            personal_website varchar(500) DEFAULT '',
            
            -- Professional Background
            years_experience int DEFAULT NULL,
            education text,
            certifications text,
            professional_associations text,
            previous_companies text,
            notable_projects text,
            
            -- Network Position
            reports_to varchar(200) DEFAULT '',
            direct_reports int DEFAULT NULL,
            influence_score tinyint unsigned DEFAULT NULL,
            relationship_strength tinyint unsigned DEFAULT NULL,
            
            -- Engagement
            last_contacted_at datetime DEFAULT NULL,
            last_response_at datetime DEFAULT NULL,
            preferred_contact_method varchar(50) DEFAULT '',
            best_time_to_contact varchar(100) DEFAULT '',
            notes text,
            
            -- Investigation
            confidence_score tinyint unsigned DEFAULT NULL,
            data_sources text,
            last_verified_at datetime DEFAULT NULL,
            investigation_tier tinyint(1) DEFAULT 1,
            
            -- System
            created_at datetime NOT NULL,
            updated_at datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_source (source_id),
            KEY idx_company (company_id),
            KEY idx_entity (entity_id),
            KEY idx_name (last_name, first_name),
            KEY idx_email (email),
            KEY idx_decision_maker (is_decision_maker),
            KEY idx_tier (investigation_tier)
        ) {$charset_collate};");

        // =====================================================================
        // EVENTS — Networking opportunities, industry events, bid deadlines
        // =====================================================================
        $table = $wpdb->prefix . self::TABLES['events'];
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_id bigint(20) unsigned DEFAULT NULL,
            company_id bigint(20) unsigned DEFAULT NULL,
            
            -- Event Details
            event_type varchar(50) NOT NULL DEFAULT 'industry',
            name varchar(500) NOT NULL DEFAULT '',
            description text,
            
            -- Timing
            start_date datetime DEFAULT NULL,
            end_date datetime DEFAULT NULL,
            timezone varchar(50) DEFAULT 'America/Los_Angeles',
            is_all_day tinyint(1) DEFAULT 0,
            recurrence varchar(100) DEFAULT '',
            
            -- Location
            venue_name varchar(300) DEFAULT '',
            address text,
            city varchar(100) DEFAULT '',
            state varchar(20) DEFAULT '',
            zip varchar(20) DEFAULT '',
            is_virtual tinyint(1) DEFAULT 0,
            virtual_url varchar(500) DEFAULT '',
            
            -- Event Metadata
            organizer varchar(300) DEFAULT '',
            registration_url varchar(500) DEFAULT '',
            registration_deadline datetime DEFAULT NULL,
            cost decimal(10,2) DEFAULT 0.00,
            attendee_count int DEFAULT NULL,
            target_audience text,
            
            -- Networking Value
            contacts_attending text,
            companies_attending text,
            key_speakers text,
            networking_opportunities text,
            relevance_score tinyint unsigned DEFAULT NULL,
            
            -- Bidding Events (for bid deadlines)
            bid_number varchar(100) DEFAULT '',
            project_name varchar(500) DEFAULT '',
            bid_value decimal(15,2) DEFAULT NULL,
            prebid_meeting datetime DEFAULT NULL,
            
            -- User Tracking
            user_attending tinyint(1) DEFAULT 0,
            user_notes text,
            follow_up_actions text,
            calendar_synced tinyint(1) DEFAULT 0,
            calendar_event_id varchar(200) DEFAULT '',
            
            -- Source
            discovered_from varchar(200) DEFAULT '',
            source_url varchar(500) DEFAULT '',
            last_verified_at datetime DEFAULT NULL,
            
            -- System
            created_at datetime NOT NULL,
            updated_at datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_source (source_id),
            KEY idx_company (company_id),
            KEY idx_type (event_type),
            KEY idx_start (start_date),
            KEY idx_attending (user_attending)
        ) {$charset_collate};");

        // =====================================================================
        // RELATIONSHIPS — Connections between entities (companies, contacts)
        // =====================================================================
        $table = $wpdb->prefix . self::TABLES['relationships'];
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            
            -- Source Entity
            from_type varchar(50) NOT NULL DEFAULT 'company',
            from_id bigint(20) unsigned NOT NULL,
            
            -- Target Entity
            to_type varchar(50) NOT NULL DEFAULT 'company',
            to_id bigint(20) unsigned NOT NULL,
            
            -- Relationship
            relationship_type varchar(100) NOT NULL DEFAULT 'works_with',
            relationship_label varchar(200) DEFAULT '',
            direction varchar(20) DEFAULT 'bidirectional',
            strength tinyint unsigned DEFAULT 50,
            
            -- Context
            project_context text,
            time_period varchar(100) DEFAULT '',
            frequency varchar(50) DEFAULT '',
            last_interaction datetime DEFAULT NULL,
            
            -- Evidence
            evidence_sources text,
            confidence_score tinyint unsigned DEFAULT NULL,
            discovered_from varchar(200) DEFAULT '',
            
            -- System
            created_at datetime NOT NULL,
            updated_at datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_from (from_type, from_id),
            KEY idx_to (to_type, to_id),
            KEY idx_type (relationship_type),
            KEY idx_strength (strength)
        ) {$charset_collate};");

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
    }

    /**
     * Migrate existing tables to v2.0.0 schema
     * dbDelta doesn't add columns, so we do it explicitly via ALTER TABLE
     */
    private function migrate_to_v2()
    {
        global $wpdb;
        $wpdb->hide_errors();

        // Helper to add column if it doesn't exist
        $add_column = function ($table, $column, $definition) use ($wpdb) {
            $full_table = $wpdb->prefix . $table;
            $exists = $wpdb->get_var("SHOW COLUMNS FROM {$full_table} LIKE '{$column}'");
            if (!$exists) {
                $wpdb->query("ALTER TABLE {$full_table} ADD COLUMN {$column} {$definition}");
            }
        };

        // Sources table - new columns
        $add_column('rawwire_lead_sources', 'city', "varchar(100) DEFAULT ''");
        $add_column('rawwire_lead_sources', 'state', "varchar(50) DEFAULT 'CA'");
        $add_column('rawwire_lead_sources', 'contractor_name', "varchar(255) DEFAULT ''");
        $add_column('rawwire_lead_sources', 'contractor_company', "varchar(255) DEFAULT ''");
        $add_column('rawwire_lead_sources', 'contractor_license', "varchar(50) DEFAULT ''");
        $add_column('rawwire_lead_sources', 'contractor_phone', "varchar(50) DEFAULT ''");
        $add_column('rawwire_lead_sources', 'contractor_email', "varchar(255) DEFAULT ''");
        $add_column('rawwire_lead_sources', 'owner_phone', "varchar(50) DEFAULT ''");
        $add_column('rawwire_lead_sources', 'owner_email', "varchar(255) DEFAULT ''");
        $add_column('rawwire_lead_sources', 'applicant_name', "varchar(255) DEFAULT ''");
        $add_column('rawwire_lead_sources', 'applicant_business', "varchar(255) DEFAULT ''");
        $add_column('rawwire_lead_sources', 'applicant_phone', "varchar(50) DEFAULT ''");
        $add_column('rawwire_lead_sources', 'applicant_email', "varchar(255) DEFAULT ''");
        $add_column('rawwire_lead_sources', 'architect_name', "varchar(255) DEFAULT ''");
        $add_column('rawwire_lead_sources', 'architect_firm', "varchar(255) DEFAULT ''");
        $add_column('rawwire_lead_sources', 'architect_phone', "varchar(50) DEFAULT ''");
        $add_column('rawwire_lead_sources', 'architect_license', "varchar(50) DEFAULT ''");
        $add_column('rawwire_lead_sources', 'engineer_name', "varchar(255) DEFAULT ''");
        $add_column('rawwire_lead_sources', 'engineer_firm', "varchar(255) DEFAULT ''");
        $add_column('rawwire_lead_sources', 'height', "varchar(50) DEFAULT ''");
        $add_column('rawwire_lead_sources', 'stories', "varchar(20) DEFAULT ''");
        $add_column('rawwire_lead_sources', 'units', "int DEFAULT NULL");
        $add_column('rawwire_lead_sources', 'construction_type', "varchar(100) DEFAULT ''");
        $add_column('rawwire_lead_sources', 'occupancy_type', "varchar(100) DEFAULT ''");
        $add_column('rawwire_lead_sources', 'zone', "varchar(50) DEFAULT ''");
        $add_column('rawwire_lead_sources', 'lot_size', "varchar(50) DEFAULT ''");
        $add_column('rawwire_lead_sources', 'parcel_number', "varchar(50) DEFAULT ''");
        $add_column('rawwire_lead_sources', 'neighborhood_council', "varchar(100) DEFAULT ''");
        $add_column('rawwire_lead_sources', 'community_plan_area', "varchar(100) DEFAULT ''");
        $add_column('rawwire_lead_sources', 'solar', "varchar(50) DEFAULT ''");
        $add_column('rawwire_lead_sources', 'ev', "varchar(50) DEFAULT ''");
        $add_column('rawwire_lead_sources', 'issue_date', "datetime DEFAULT NULL");
        $add_column('rawwire_lead_sources', 'expiration_date', "datetime DEFAULT NULL");
        $add_column('rawwire_lead_sources', 'cofo_date', "datetime DEFAULT NULL");
        $add_column('rawwire_lead_sources', 'estimated_completion_date', "datetime DEFAULT NULL");
        $add_column('rawwire_lead_sources', 'investigation_status', "enum('none','pending','in_progress','complete','failed') DEFAULT 'none'");
        $add_column('rawwire_lead_sources', 'investigator_summary', "text");
        $add_column('rawwire_lead_sources', 'investigator_confidence', "tinyint unsigned DEFAULT NULL");
        $add_column('rawwire_lead_sources', 'investigated_at', "datetime DEFAULT NULL");

        // Candidates table - new columns
        $add_column('rawwire_lead_candidates', 'city', "varchar(100) DEFAULT ''");
        $add_column('rawwire_lead_candidates', 'state', "varchar(50) DEFAULT 'CA'");
        $add_column('rawwire_lead_candidates', 'zip_code', "varchar(20) DEFAULT ''");
        $add_column('rawwire_lead_candidates', 'contractor_name', "varchar(255) DEFAULT ''");
        $add_column('rawwire_lead_candidates', 'contractor_license', "varchar(50) DEFAULT ''");
        $add_column('rawwire_lead_candidates', 'contractor_phone', "varchar(50) DEFAULT ''");
        $add_column('rawwire_lead_candidates', 'contractor_email', "varchar(255) DEFAULT ''");
        $add_column('rawwire_lead_candidates', 'owner_name', "varchar(255) DEFAULT ''");
        $add_column('rawwire_lead_candidates', 'owner_phone', "varchar(50) DEFAULT ''");
        $add_column('rawwire_lead_candidates', 'owner_email', "varchar(255) DEFAULT ''");
        $add_column('rawwire_lead_candidates', 'applicant_name', "varchar(255) DEFAULT ''");
        $add_column('rawwire_lead_candidates', 'applicant_business', "varchar(255) DEFAULT ''");
        $add_column('rawwire_lead_candidates', 'architect_name', "varchar(255) DEFAULT ''");
        $add_column('rawwire_lead_candidates', 'architect_firm', "varchar(255) DEFAULT ''");
        $add_column('rawwire_lead_candidates', 'issue_date', "datetime DEFAULT NULL");
        $add_column('rawwire_lead_candidates', 'cofo_date', "datetime DEFAULT NULL");
        $add_column('rawwire_lead_candidates', 'estimated_completion_date', "datetime DEFAULT NULL");
        $add_column('rawwire_lead_candidates', 'height', "varchar(50) DEFAULT ''");
        $add_column('rawwire_lead_candidates', 'stories', "varchar(20) DEFAULT ''");
        $add_column('rawwire_lead_candidates', 'square_footage', "int DEFAULT 0");
        $add_column('rawwire_lead_candidates', 'construction_type', "varchar(100) DEFAULT ''");
        $add_column('rawwire_lead_candidates', 'zone', "varchar(50) DEFAULT ''");
        $add_column('rawwire_lead_candidates', 'council_district', "varchar(10) DEFAULT ''");
        $add_column('rawwire_lead_candidates', 'community_plan_area', "varchar(100) DEFAULT ''");
        $add_column('rawwire_lead_candidates', 'neighborhood_council', "varchar(100) DEFAULT ''");
        $add_column('rawwire_lead_candidates', 'solar', "varchar(50) DEFAULT ''");
        $add_column('rawwire_lead_candidates', 'ev', "varchar(50) DEFAULT ''");
        $add_column('rawwire_lead_candidates', 'lat', "decimal(10,7) DEFAULT NULL");
        $add_column('rawwire_lead_candidates', 'lon', "decimal(10,7) DEFAULT NULL");
        $add_column('rawwire_lead_candidates', 'investigation_status', "enum('none','pending','in_progress','complete','failed') DEFAULT 'none'");
        $add_column('rawwire_lead_candidates', 'investigator_summary', "text");
        $add_column('rawwire_lead_candidates', 'investigator_confidence', "tinyint unsigned DEFAULT NULL");
        $add_column('rawwire_lead_candidates', 'investigated_at', "datetime DEFAULT NULL");
        $add_column('rawwire_lead_candidates', 'party_profiles', "longtext");

        // Add indexes for new columns
        $wpdb->query("ALTER TABLE {$wpdb->prefix}rawwire_lead_candidates ADD INDEX idx_city (city)");
        $wpdb->query("ALTER TABLE {$wpdb->prefix}rawwire_lead_candidates ADD INDEX idx_investigation (investigation_status)");
        $wpdb->query("ALTER TABLE {$wpdb->prefix}rawwire_lead_sources ADD INDEX idx_investigation (investigation_status)");

        $wpdb->show_errors();
    }

    // =========================================================================
    // SETTINGS
    // =========================================================================

    /**
     * Get all settings merged with defaults
     */
    public function get_settings()
    {
        $saved = get_option(self::SETTINGS_OPTION, array());
        return wp_parse_args($saved, self::DEFAULT_SETTINGS);
    }

    /**
     * Get scoring weights merged with defaults
     */
    public function get_weights()
    {
        $saved = get_option(self::WEIGHTS_OPTION, array());
        return wp_parse_args($saved, self::DEFAULT_WEIGHTS);
    }

    /**
     * Get blacklist keywords (array of lowercase strings)
     */
    public function get_blacklist()
    {
        $saved = get_option(self::BLACKLIST_OPTION, array());
        return is_array($saved) ? array_values(array_filter(array_map('trim', array_map('strtolower', $saved)))) : array();
    }

    /**
     * Get a specific table name with prefix
     */
    public function table($key)
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLES[$key];
    }

    // =========================================================================
    // PIPELINE: IMPORT
    // =========================================================================

    /**
     * Import records from Socrata API
     *
     * @param array $params Override import parameters
     * @return array Result with counts
     */
    public function import($params = array())
    {
        $settings = $this->get_settings();
        $params   = wp_parse_args($params, array(
            'dataset'        => $settings['primary_dataset'],
            'limit'          => $settings['import_limit'],
            'permit_types'   => $settings['permit_types'],
            'permit_sub_types' => $settings['permit_sub_types'],
            'min_valuation'  => $settings['min_valuation'],
            'date_months'    => $settings['date_range_months'],
        ));

        $client = $this->get_socrata_client();
        if (is_wp_error($client)) {
            return array('success' => false, 'error' => $client->get_error_message());
        }

        $records = $client->fetch_permits($params);
        if (is_wp_error($records)) {
            return array('success' => false, 'error' => $records->get_error_message());
        }

        $batch_id  = 'import_' . date('Ymd_His');
        $imported  = 0;
        $skipped   = 0;
        $errors    = 0;
        $total     = count($records);

        global $wpdb;
        $table = $this->table('sources');

        /**
         * Fires when records start arriving from Socrata.
         *
         * @param int    $total    Total records fetched from API
         * @param string $batch_id Import batch identifier
         * @param array  $params   Import parameters used
         */
        do_action('rawwire_import_started', $total, $batch_id, $params);
        rawwire_log('lead_generator', sprintf('Import started: %d records in batch %s', $total, $batch_id));

        foreach ($records as $record) {
            $mapped = $client->map_record($record, $params['dataset']);

            // Check for duplicate
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE source_dataset = %s AND source_record_id = %s",
                $mapped['source_dataset'],
                $mapped['source_record_id']
            ));

            if ($exists) {
                $skipped++;
                continue;
            }

            $mapped['import_batch'] = $batch_id;
            $mapped['imported_at']  = current_time('mysql');
            $mapped['raw_json']     = wp_json_encode($record);

            $result = $wpdb->insert($table, $mapped);
            if (false === $result) {
                $errors++;
                rawwire_log('lead_generator', 'Import insert failed: ' . $wpdb->last_error, 'error');
            } else {
                $imported++;
            }
        }

        $summary = array(
            'success'  => true,
            'batch_id' => $batch_id,
            'fetched'  => count($records),
            'imported' => $imported,
            'skipped'  => $skipped,
            'errors'   => $errors,
        );

        rawwire_log('lead_generator', sprintf(
            'Import complete: %d fetched, %d imported, %d skipped, %d errors (batch: %s)',
            count($records),
            $imported,
            $skipped,
            $errors,
            $batch_id
        ));

        // Cross-reference with xnhu-aczu for contractor/party data
        if ($imported > 0 && $params['dataset'] !== 'xnhu-aczu') {
            $this->enrich_batch_with_party_data($batch_id, $client);
        }

        /**
         * Fires when all records have been imported and enriched.
         * AI scoring should begin after this hook. The total count
         * lets listeners know when the full batch is ready.
         *
         * @param array  $summary  Import summary (imported, skipped, errors, fetched)
         * @param string $batch_id Batch identifier
         * @param int    $total    Total records that were fetched from API
         */
        do_action('rawwire_import_complete', $summary, $batch_id, count($records));

        // Auto-score if enabled — scores ALL unprocessed, then promotes top N
        if ($settings['auto_score'] && $imported > 0) {
            $summary['auto_scored'] = $this->score_unprocessed($imported);
        }

        return $summary;
    }

    // =========================================================================
    // PIPELINE: ENRICH (Socrata xnhu-aczu cross-reference)
    // =========================================================================

    /**
     * Enrich a batch of imported records with contractor/party data from xnhu-aczu
     *
     * Cross-references permit numbers against the stale-but-rich xnhu-aczu dataset
     * to pull contractor_name, license, principal, applicant, etc.
     *
     * @param string                $batch_id Import batch ID
     * @param RawWire_Socrata_Client $client   Socrata client instance
     */
    private function enrich_batch_with_party_data(string $batch_id, $client): void
    {
        global $wpdb;
        $table = $this->table('sources');

        // Get permit numbers from this batch that don't already have contractor data
        $permit_numbers = $wpdb->get_col($wpdb->prepare(
            "SELECT permit_nbr FROM {$table} 
             WHERE import_batch = %s 
               AND (contractor_name IS NULL OR contractor_name = '')
             LIMIT 50",
            $batch_id
        ));

        if (empty($permit_numbers)) {
            return;
        }

        // Bulk fetch party data from xnhu-aczu
        $party_map = $client->fetch_party_data_bulk($permit_numbers);

        if (empty($party_map)) {
            rawwire_log('lead_generator', sprintf(
                'Party cross-reference: 0/%d permits found in xnhu-aczu',
                count($permit_numbers)
            ));
            return;
        }

        $enriched = 0;
        foreach ($party_map as $permit_nbr => $party) {
            if (empty($party['contractor_name']) && empty($party['applicant_name'])) {
                continue;
            }

            $update_data = array();
            if (!empty($party['contractor_name'])) {
                $update_data['contractor_name'] = $party['contractor_name'];
            }
            if (!empty($party['contractor_license'])) {
                $update_data['contractor_license'] = $party['contractor_license'];
            }
            if (!empty($party['principal_name'])) {
                $update_data['principal_name'] = $party['principal_name'];
            }
            if (!empty($party['applicant_name'])) {
                $update_data['applicant_name'] = $party['applicant_name'];
            }

            if (!empty($update_data)) {
                $wpdb->update(
                    $table,
                    $update_data,
                    array('permit_nbr' => $permit_nbr, 'import_batch' => $batch_id)
                );
                $enriched++;
            }
        }

        rawwire_log('lead_generator', sprintf(
            'Party cross-reference: %d/%d permits enriched from xnhu-aczu',
            $enriched,
            count($permit_numbers)
        ));
    }

    /**
     * Schedule async party investigations for a batch (score-priority order)
     *
     * Top-scored leads get investigated first (shorter delay), lower-ranked
     * leads trickle in over time to build network maps.
     *
     * @param string $batch_id Import batch ID
     */
    private function schedule_party_investigations(string $batch_id): void
    {
        global $wpdb;
        $src_table  = $this->table('sources');
        $cand_table = $this->table('candidates');

        // Get source IDs ordered by score (highest first)
        $sources = $wpdb->get_results($wpdb->prepare(
            "SELECT s.id, COALESCE(c.score_composite, 0) as score
             FROM {$src_table} s
             LEFT JOIN {$cand_table} c ON s.id = c.source_id
             WHERE s.import_batch = %s 
               AND s.parties_investigated_at IS NULL
             ORDER BY COALESCE(c.score_composite, 0) DESC",
            $batch_id
        ));

        $deep_threshold = 6.0;
        $delay = 5; // Start delay in seconds

        foreach ($sources as $source) {
            $score = (float) $source->score;
            // Deep-tier leads (score >= 6.0): schedule sooner, tighter stagger
            // Basic-tier: longer stagger to spread API usage
            $stagger = $score >= $deep_threshold ? rand(2, 5) : rand(10, 30);

            wp_schedule_single_event(
                time() + $delay,
                'rawwire_investigate_source_parties',
                array((int) $source->id)
            );

            $delay += $stagger;
        }

        rawwire_log('lead_generator', sprintf(
            'Scheduled %d party investigations for batch %s (score-priority, deep: %d, basic: %d)',
            count($sources),
            $batch_id,
            count(array_filter($sources, fn($s) => (float) $s->score >= $deep_threshold)),
            count(array_filter($sources, fn($s) => (float) $s->score < $deep_threshold))
        ));
    }

    // =========================================================================
    // PIPELINE: SCORE
    // =========================================================================

    /**
     * Score all unprocessed source records
     *
     * @param int $limit Max records to score
     * @return array Result with counts
     */
    public function score_unprocessed($limit = 50)
    {
        global $wpdb;
        $table = $this->table('sources');

        $records = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE processed = 0 ORDER BY valuation DESC LIMIT %d",
            $limit
        ), ARRAY_A);

        if (empty($records)) {
            return array('success' => true, 'scored' => 0, 'message' => 'No unprocessed records.');
        }

        $scorer     = $this->get_scorer();
        $settings   = $this->get_settings();
        $batch_size = intval($settings['triage_batch_size'] ?? 10);
        $picks      = intval($settings['triage_picks'] ?? 2);
        $scored     = 0;
        $failed     = 0;

        // ── PASS 1: Combined triage + AI scoring via Grok ──
        // triage_batch now returns [id => adjustments] instead of flat ID array.
        // This eliminates separate AI scoring calls, avoiding Venice rate limits.
        $triage_results = $scorer->triage_batch($records, $batch_size, $picks);

        $ai_scored   = 0;
        $heur_scored = 0;

        rawwire_log('lead_scorer', sprintf(
            'Combined scoring: %d records total, %d selected with AI adjustments, %d heuristic-only',
            count($records),
            count($triage_results),
            count($records) - count($triage_results)
        ), 'info');

        // ── PASS 2: Apply scores — triage-selected get pre-computed AI adjustments, rest get heuristic-only ──
        foreach ($records as $record) {
            $record_id = (int) $record['id'];
            $has_ai = isset($triage_results[$record_id]);
            $adjustments = $has_ai ? $triage_results[$record_id] : array();

            // Pass pre-computed adjustments — no separate AI API call needed
            $result = $scorer->score($record, ! $has_ai, $adjustments);

            if (is_wp_error($result)) {
                $failed++;
                rawwire_log('lead_scorer', 'Score failed for #' . $record['id'] . ': ' . $result->get_error_message(), 'warning');
                continue;
            }

            // Mark source as processed
            $wpdb->update($table, array(
                'processed'    => 1,
                'processed_at' => current_time('mysql'),
            ), array('id' => $record['id']));

            if ($has_ai) {
                $ai_scored++;
            } else {
                $heur_scored++;
            }
            $scored++;
        }

        $summary = array(
            'success'      => true,
            'total'        => count($records),
            'scored'       => $scored,
            'ai_scored'    => $ai_scored,
            'heur_scored'  => $heur_scored,
            'triage_batch_size' => $batch_size,
            'triage_picks'     => $picks,
            'failed'       => $failed,
        );

        rawwire_log('lead_scorer', sprintf(
            'Scoring complete: %d scored (%d AI + %d heuristic), %d failed',
            $scored,
            $ai_scored,
            $heur_scored,
            $failed
        ), 'info');

        // Auto-promote if enabled
        if ($settings['auto_promote'] && $scored > 0) {
            $summary['promotion'] = $this->promote_candidates();
        }

        return $summary;
    }

    // =========================================================================
    // PIPELINE: PROMOTE / ARCHIVE
    // =========================================================================

    /**
     * Move scored records to candidates or archive based on threshold
     *
     * @return array Result with counts
     */
    public function promote_candidates()
    {
        global $wpdb;
        $settings  = $this->get_settings();
        $threshold = floatval($settings['candidate_threshold']);
        $limit     = intval($settings['candidate_limit']);
        $src_table = $this->table('sources');
        $cand_table = $this->table('candidates');
        $arch_table = $this->table('archive');

        // Get all scored but not-yet-promoted records
        $scored = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, c.score_composite, c.score_project_scale, c.score_type_fit,
                    c.score_green_indicators, c.score_timeline, c.score_location,
                    c.score_use_relevance, c.score_complexity, c.score_reasoning, c.scored_at
             FROM {$src_table} s
             INNER JOIN {$cand_table} c ON c.source_id = s.id
             WHERE c.status = 'scored_pending'
             ORDER BY c.score_composite DESC
             LIMIT %d",
            $limit * 2
        ), ARRAY_A);

        // Alternative: check candidates table for scored_pending
        // Actually, the scorer inserts directly into candidates with status 'scored_pending'
        $pending = $wpdb->get_results(
            "SELECT * FROM {$cand_table} WHERE status = 'scored_pending' ORDER BY score_composite DESC",
            ARRAY_A
        );

        $promoted = 0;
        $archived = 0;
        $promoted_ids = array();

        foreach ($pending as $record) {
            if (floatval($record['score_composite']) >= $threshold && $promoted < $limit) {
                // Promote to candidate
                $wpdb->update(
                    $cand_table,
                    array('status' => 'pending', 'status_changed_at' => current_time('mysql')),
                    array('id' => $record['id'])
                );
                $promoted++;
                $promoted_ids[] = (int) $record['id'];
            } else {
                // Archive
                $wpdb->insert($arch_table, array(
                    'source_id'       => $record['source_id'],
                    'permit_nbr'      => $record['permit_nbr'],
                    'primary_address'  => $record['primary_address'],
                    'permit_type'     => $record['permit_type'],
                    'permit_sub_type' => $record['permit_sub_type'],
                    'use_desc'        => $record['use_desc'],
                    'work_desc'       => $record['work_desc'],
                    'valuation'       => $record['valuation'],
                    'score_composite' => $record['score_composite'],
                    'score_reasoning' => $record['score_reasoning'],
                    'archived_reason' => floatval($record['score_composite']) < $threshold ? 'low_score' : 'over_limit',
                    'archived_at'     => current_time('mysql'),
                ));

                $wpdb->delete($cand_table, array('id' => $record['id']));
                $archived++;
            }
        }

        $result = array(
            'success'      => true,
            'promoted'     => $promoted,
            'promoted_ids' => $promoted_ids,
            'archived'     => $archived,
            'threshold'    => $threshold,
        );

        /**
         * Fires after candidates have been promoted/archived.
         * Listeners should launch party investigation on promoted candidates.
         *
         * @param int[] $promoted_ids Candidate IDs that were promoted (top N)
         * @param array $result       Promotion summary
         */
        if (! empty($promoted_ids)) {
            do_action('rawwire_candidates_promoted', $promoted_ids, $result);
            rawwire_log('lead_generator', sprintf(
                'Promoted %d candidates (IDs: %s), archived %d. Firing rawwire_candidates_promoted hook.',
                $promoted,
                implode(', ', $promoted_ids),
                $archived
            ));
        }

        return $result;
    }

    // =========================================================================
    // PIPELINE: AUTO-INVESTIGATE PROMOTED CANDIDATES
    // =========================================================================

    /**
     * Launch party investigation on promoted candidates.
     * Hooked to 'rawwire_candidates_promoted' — fires after promote_candidates().
     *
     * Investigates the source records behind the top candidates so Soothsayer
     * gets party contacts and intelligence for the best leads only.
     *
     * @param int[] $candidate_ids Promoted candidate IDs
     * @param array $promotion     Promotion summary
     */
    public function investigate_promoted_candidates(array $candidate_ids, array $promotion): void
    {
        if (empty($candidate_ids)) {
            return;
        }

        if (! function_exists('rawwire_party_investigator')) {
            rawwire_log('lead_generator', 'Party Investigator not loaded — skipping candidate investigation.', 'warning');
            return;
        }

        $investigator = rawwire_party_investigator();
        if (! $investigator || ! $investigator->is_available()) {
            rawwire_log('lead_generator', 'Party Investigator not available — skipping candidate investigation.', 'warning');
            return;
        }

        global $wpdb;
        $cand_table = $this->table('candidates');
        $src_table  = $this->table('sources');

        // Get source IDs for the promoted candidates (only investigate uninvestigated)
        $placeholders = implode(',', array_fill(0, count($candidate_ids), '%d'));
        $source_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT c.source_id 
             FROM {$cand_table} c
             INNER JOIN {$src_table} s ON s.id = c.source_id
             WHERE c.id IN ({$placeholders})
               AND (s.investigation_status IS NULL OR s.investigation_status = 'pending')
             ORDER BY c.score_composite DESC",
            ...$candidate_ids
        ));

        if (empty($source_ids)) {
            rawwire_log('lead_generator', 'All promoted candidates already investigated — nothing to do.');
            return;
        }

        // Schedule staggered investigations for the top candidates
        $delay = 5; // Start 5 seconds from now
        $scheduled = 0;

        foreach ($source_ids as $source_id) {
            wp_schedule_single_event(
                time() + $delay,
                'rawwire_investigate_source_parties',
                array((int) $source_id)
            );
            $delay += rand(3, 8); // Stagger 3-8 seconds apart to avoid API flooding
            $scheduled++;
        }

        rawwire_log('lead_generator', sprintf(
            'Scheduled %d party investigations for promoted candidates (IDs: %s)',
            $scheduled,
            implode(', ', $candidate_ids)
        ));

        /**
         * Fires after all promoted candidate investigations have been scheduled.
         * The pipeline is now complete — import → score → promote → investigate.
         *
         * @param int[] $candidate_ids Promoted candidate IDs
         * @param int   $scheduled     Number of investigations queued
         */
        do_action('rawwire_pipeline_complete', $candidate_ids, $scheduled);
    }

    // =========================================================================
    // PIPELINE: APPROVE / REJECT
    // =========================================================================

    /**
     * Approve a candidate — move to content table for enrichment
     */
    public function approve_candidate($candidate_id)
    {
        global $wpdb;
        $cand_table = $this->table('candidates');
        $cont_table = $this->table('content');

        $candidate = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$cand_table} WHERE id = %d",
            $candidate_id
        ), ARRAY_A);

        if (! $candidate) {
            return new WP_Error('not_found', 'Candidate not found.');
        }

        // Insert into content table
        $wpdb->insert($cont_table, array(
            'candidate_id'    => $candidate['id'],
            'source_id'       => $candidate['source_id'],
            'permit_nbr'      => $candidate['permit_nbr'],
            'primary_address' => $candidate['primary_address'],
            'zip_code'        => $candidate['zip_code'],
            'permit_type'     => $candidate['permit_type'],
            'permit_sub_type' => $candidate['permit_sub_type'],
            'use_desc'        => $candidate['use_desc'],
            'work_desc'       => $candidate['work_desc'],
            'valuation'       => $candidate['valuation'],
            'square_footage'  => $candidate['square_footage'],
            'issue_date'      => $candidate['issue_date'],
            'lat'             => $candidate['lat'],
            'lon'             => $candidate['lon'],
            'score_composite' => $candidate['score_composite'],
            'status'          => 'enriching',
            'status_changed_at' => current_time('mysql'),
            'status_changed_by' => get_current_user_id(),
        ));

        $content_id = $wpdb->insert_id;

        // Update candidate status
        $wpdb->update(
            $cand_table,
            array(
                'status'           => 'approved',
                'status_changed_at' => current_time('mysql'),
                'status_changed_by' => get_current_user_id(),
            ),
            array('id' => $candidate_id)
        );

        // DEPRECATED: Old enricher used AI Adapter which is rate-limited/broken
        // Party Investigator + OpenClaw now handles deep research
        // wp_schedule_single_event(time(), 'rawwire_lead_enrich_single', array($content_id));

        return array('success' => true, 'content_id' => $content_id);
    }

    /**
     * Reject / trash a candidate — soft-delete by setting status to 'trashed'
     */
    public function reject_candidate($candidate_id)
    {
        return $this->trash_candidate($candidate_id);
    }

    /**
     * Soft-delete: move candidate to trash
     */
    public function trash_candidate($candidate_id)
    {
        global $wpdb;
        $table = $this->table('candidates');

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE id = %d",
            $candidate_id
        ));

        if (! $exists) {
            return new WP_Error('not_found', 'Candidate not found.');
        }

        $wpdb->update(
            $table,
            array('status' => 'trashed'),
            array('id' => $candidate_id)
        );

        return array('success' => true);
    }

    /**
     * Restore a trashed candidate back to pending
     */
    public function restore_candidate($candidate_id)
    {
        global $wpdb;
        $table = $this->table('candidates');

        $status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$table} WHERE id = %d",
            $candidate_id
        ));

        if (! $status) {
            return new WP_Error('not_found', 'Candidate not found.');
        }

        if ($status !== 'trashed') {
            return new WP_Error('not_trashed', 'Candidate is not in trash.');
        }

        $wpdb->update(
            $table,
            array('status' => 'pending'),
            array('id' => $candidate_id)
        );

        return array('success' => true);
    }

    /**
     * Permanently delete a candidate — archive and remove
     */
    public function purge_candidate($candidate_id)
    {
        global $wpdb;
        $cand_table = $this->table('candidates');
        $arch_table = $this->table('archive');

        $candidate = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$cand_table} WHERE id = %d",
            $candidate_id
        ), ARRAY_A);

        if (! $candidate) {
            return new WP_Error('not_found', 'Candidate not found.');
        }

        // Archive
        $wpdb->insert($arch_table, array(
            'source_id'       => $candidate['source_id'],
            'permit_nbr'      => $candidate['permit_nbr'],
            'primary_address' => $candidate['primary_address'],
            'permit_type'     => $candidate['permit_type'],
            'permit_sub_type' => $candidate['permit_sub_type'],
            'use_desc'        => $candidate['use_desc'],
            'work_desc'       => $candidate['work_desc'],
            'valuation'       => $candidate['valuation'],
            'score_composite' => $candidate['score_composite'],
            'score_reasoning' => $candidate['score_reasoning'],
            'archived_reason' => 'purged_by_user',
            'archived_at'     => current_time('mysql'),
            'tags'            => $candidate['tags'],
        ));

        // Hard delete
        $wpdb->delete($cand_table, array('id' => $candidate_id));

        return array('success' => true);
    }

    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================

    public function ajax_import()
    {
        check_ajax_referer('rawwire_lead_nonce', 'nonce');
        if (! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
        }

        $params = array();
        if (! empty($_POST['dataset'])) {
            $params['dataset'] = sanitize_text_field($_POST['dataset']);
        }
        if (! empty($_POST['limit'])) {
            $params['limit'] = intval($_POST['limit']);
        }
        if (! empty($_POST['permit_types'])) {
            $params['permit_types'] = array_map('sanitize_text_field', (array) $_POST['permit_types']);
        }
        if (! empty($_POST['permit_sub_types'])) {
            $params['permit_sub_types'] = array_map('sanitize_text_field', (array) $_POST['permit_sub_types']);
        }
        if (isset($_POST['min_valuation'])) {
            $params['min_valuation'] = floatval($_POST['min_valuation']);
        }
        if (isset($_POST['date_months'])) {
            $params['date_months'] = intval($_POST['date_months']);
        }
        if (! empty($_POST['require_party_data'])) {
            $params['require_party_data'] = true;
        }
        if (! empty($_POST['exclude_owner_builder'])) {
            $params['exclude_owner_builder'] = true;
        }

        // Extended filter params from the selection matrix
        $text_fields = array(
            'zip_codes',
            'council_districts',
            'community_plan_area',
            'zone',
            'use_desc_search',
            'use_code',
            'work_desc_search',
            'status_filter',
            'construction_type',
            'contractor_name_search',
            'license_search',
            'sort_by',
            'sort_order',
        );
        foreach ($text_fields as $f) {
            if (! empty($_POST[$f])) {
                $params[$f] = sanitize_text_field($_POST[$f]);
            }
        }
        $numeric_fields = array('max_valuation', 'min_sqft');
        foreach ($numeric_fields as $f) {
            if (! empty($_POST[$f])) {
                $params[$f] = floatval($_POST[$f]);
            }
        }
        $bool_fields = array('solar_only', 'ev_only');
        foreach ($bool_fields as $f) {
            if (! empty($_POST[$f])) {
                $params[$f] = true;
            }
        }

        $result = $this->import($params);
        if (! empty($result['success'])) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    public function ajax_preview_import()
    {
        check_ajax_referer('rawwire_lead_nonce', 'nonce');
        if (! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
        }

        $client = $this->get_socrata_client();
        if (is_wp_error($client)) {
            wp_send_json_error(array('message' => $client->get_error_message()));
        }

        $settings = $this->get_settings();
        $preview_params = array(
            'dataset'          => ! empty($_POST['dataset']) ? sanitize_text_field($_POST['dataset']) : $settings['primary_dataset'],
            'permit_types'     => ! empty($_POST['permit_types']) ? array_map('sanitize_text_field', (array) $_POST['permit_types']) : $settings['permit_types'],
            'permit_sub_types' => ! empty($_POST['permit_sub_types']) ? array_map('sanitize_text_field', (array) $_POST['permit_sub_types']) : $settings['permit_sub_types'],
            'min_valuation'    => isset($_POST['min_valuation']) ? floatval($_POST['min_valuation']) : $settings['min_valuation'],
            'date_months'      => isset($_POST['date_months']) ? intval($_POST['date_months']) : $settings['date_range_months'],
        );

        // Extended selection matrix params for preview
        $text_fields = array(
            'zip_codes',
            'council_districts',
            'community_plan_area',
            'zone',
            'use_desc_search',
            'use_code',
            'work_desc_search',
            'status_filter',
            'construction_type',
            'contractor_name_search',
            'license_search',
        );
        foreach ($text_fields as $f) {
            if (! empty($_POST[$f])) {
                $preview_params[$f] = sanitize_text_field($_POST[$f]);
            }
        }
        $numeric_fields = array('max_valuation', 'min_sqft');
        foreach ($numeric_fields as $f) {
            if (! empty($_POST[$f])) {
                $preview_params[$f] = floatval($_POST[$f]);
            }
        }
        $bool_fields = array('solar_only', 'ev_only', 'require_party_data', 'exclude_owner_builder');
        foreach ($bool_fields as $f) {
            if (! empty($_POST[$f])) {
                $preview_params[$f] = true;
            }
        }

        $count = $client->count_permits($preview_params);

        if (is_wp_error($count)) {
            wp_send_json_error(array('message' => $count->get_error_message()));
        }

        wp_send_json_success(array('available' => intval($count)));
    }

    public function ajax_score_batch()
    {
        check_ajax_referer('rawwire_lead_nonce', 'nonce');
        if (! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
        }

        $limit  = ! empty($_POST['limit']) ? intval($_POST['limit']) : 50;
        $result = $this->score_unprocessed($limit);
        wp_send_json_success($result);
    }

    public function ajax_promote()
    {
        check_ajax_referer('rawwire_lead_nonce', 'nonce');
        if (! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
        }

        $result = $this->promote_candidates();
        wp_send_json_success($result);
    }

    public function ajax_approve()
    {
        check_ajax_referer('rawwire_lead_nonce', 'nonce');
        if (! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
        }

        $id = intval($_POST['candidate_id']);
        $result = $this->approve_candidate($id);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        wp_send_json_success($result);
    }

    public function ajax_reject()
    {
        check_ajax_referer('rawwire_lead_nonce', 'nonce');
        if (! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
        }

        $id = intval($_POST['candidate_id']);
        $result = $this->trash_candidate($id);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        wp_send_json_success($result);
    }

    public function ajax_trash()
    {
        check_ajax_referer('rawwire_lead_nonce', 'nonce');
        if (! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
        }

        $id = intval($_POST['candidate_id']);
        $result = $this->trash_candidate($id);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        wp_send_json_success($result);
    }

    public function ajax_restore()
    {
        check_ajax_referer('rawwire_lead_nonce', 'nonce');
        if (! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
        }

        $id = intval($_POST['candidate_id']);
        $result = $this->restore_candidate($id);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        wp_send_json_success($result);
    }

    public function ajax_purge()
    {
        check_ajax_referer('rawwire_lead_nonce', 'nonce');
        if (! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
        }

        $id = intval($_POST['candidate_id']);
        $result = $this->purge_candidate($id);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        wp_send_json_success($result);
    }

    /**
     * AJAX: Manually trigger investigation for a single source
     * 
     * Accepts either source_id directly or candidate_id (will look up source_id)
     */
    public function ajax_investigate_single()
    {
        // Capture any stray output that might corrupt JSON response
        ob_start();

        check_ajax_referer('rawwire_lead_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            ob_end_clean();
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
        }

        // Investigation can take 5+ minutes (agent browsing, API calls).
        // Remove PHP execution time limit to prevent fatal timeout.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        global $wpdb;

        // Accept source_id directly or look it up from candidate_id
        $source_id = isset($_POST['source_id']) ? intval($_POST['source_id']) : 0;
        $candidate_id = isset($_POST['candidate_id']) ? intval($_POST['candidate_id']) : 0;

        rawwire_log('investigate_ajax', sprintf(
            'Investigation request: source_id=%d, candidate_id=%d',
            $source_id,
            $candidate_id
        ));

        if (!$source_id && $candidate_id) {
            $cand_table = $this->table('candidates');
            $source_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT source_id FROM {$cand_table} WHERE id = %d",
                $candidate_id
            ));
            rawwire_log('investigate_ajax', sprintf(
                'Looked up source_id=%d from candidate_id=%d',
                $source_id,
                $candidate_id
            ));
        }

        if (!$source_id) {
            rawwire_log('investigate_ajax', 'ERROR: No source_id resolved', 'error');
            ob_end_clean();
            wp_send_json_error(array('message' => 'No source_id or candidate_id provided.'));
        }

        // Check source exists
        $src_table = $this->table('sources');
        $source = $wpdb->get_row($wpdb->prepare(
            "SELECT id, investigation_status FROM {$src_table} WHERE id = %d",
            $source_id
        ));

        if (!$source) {
            rawwire_log('investigate_ajax', sprintf('ERROR: Source #%d not found', $source_id), 'error');
            ob_end_clean();
            wp_send_json_error(array('message' => 'Source not found.'));
        }

        rawwire_log('investigate_ajax', sprintf(
            'Source found: id=%d, current_status=%s',
            $source->id,
            $source->investigation_status ?? 'NULL'
        ));

        // Invoke Party Investigator directly
        if (!function_exists('rawwire_party_investigator')) {
            ob_end_clean();
            wp_send_json_error(array('message' => 'Party Investigator not loaded.'));
        }

        $investigator = rawwire_party_investigator();
        if (!$investigator || !$investigator->is_available()) {
            ob_end_clean();
            wp_send_json_error(array('message' => 'Party Investigator not available. Check AI settings.'));
        }

        $force_retry = !empty($_POST['force']) || strtolower((string) ($source->investigation_status ?? '')) === 'failed';
        rawwire_log('investigate_ajax', sprintf(
            'Calling investigate_source_parties... (force=%s)',
            $force_retry ? 'true' : 'false'
        ));
        $result = $investigator->investigate_source_parties($source_id, $force_retry);

        if (is_wp_error($result)) {
            rawwire_log('investigate_ajax', 'WP_Error: ' . $result->get_error_message(), 'error');
            ob_end_clean();
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        // Verify DB was updated
        $updated_source = $wpdb->get_row($wpdb->prepare(
            "SELECT id, investigation_status, parties_investigated_at FROM {$src_table} WHERE id = %d",
            $source_id
        ));
        $final_status = strtolower((string) ($updated_source->investigation_status ?? ''));

        // Check if it was skipped
        if (!empty($result['skipped'])) {
            rawwire_log('investigate_ajax', sprintf('Skipped: %s', $result['reason'] ?? 'unknown'));
        } elseif ($final_status === 'incomplete') {
            rawwire_log('investigate_ajax', sprintf(
                'Incomplete: %d parties investigated (quality threshold not met)',
                $result['parties_count'] ?? 0
            ));
        } elseif ($final_status === 'failed') {
            rawwire_log('investigate_ajax', sprintf(
                'Failed: %d parties investigated',
                $result['parties_count'] ?? 0
            ));
        } else {
            rawwire_log('investigate_ajax', sprintf(
                'Completed: %d parties investigated',
                $result['parties_count'] ?? 0
            ));
        }

        rawwire_log('investigate_ajax', sprintf(
            'After investigation: status=%s, investigated_at=%s',
            $updated_source->investigation_status ?? 'NULL',
            $updated_source->parties_investigated_at ?? 'NULL'
        ));

        // Capture and log any stray output that could corrupt the JSON response
        $stray_output = ob_get_clean();
        if (!empty($stray_output)) {
            rawwire_log('investigate_ajax', sprintf(
                'WARNING: Stray output detected (%d bytes): %s',
                strlen($stray_output),
                substr($stray_output, 0, 500)
            ), 'warning');
        }

        // Check if investigation actually succeeded vs failed for all parties
        if (isset($result['success']) && $result['success'] === false) {
            wp_send_json_error(array(
                'message'         => $result['message'] ?? 'Investigation failed for all parties. No data was saved.',
                'source_id'       => $source_id,
                'failure_reasons' => $result['failure_reasons'] ?? [],
            ));
        }

        $response_message = 'Investigation completed.';
        if ($final_status === 'incomplete') {
            $response_message = 'Investigation completed with partial data (status: incomplete).';
        } elseif ($final_status === 'failed') {
            $response_message = 'Investigation failed quality checks (status: failed).';
        }

        wp_send_json_success(array(
            'message'         => $response_message,
            'source_id'       => $source_id,
            'result'          => $result,
            'failure_reasons' => $result['failure_reasons'] ?? [],
        ));
    }

    public function ajax_enrich()
    {
        check_ajax_referer('rawwire_lead_nonce', 'nonce');
        if (! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
        }

        $content_id = intval($_POST['content_id']);
        $enricher   = $this->get_enricher();
        $result     = $enricher->enrich($content_id);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        wp_send_json_success($result);
    }

    public function ajax_rescore_all()
    {
        check_ajax_referer('rawwire_lead_nonce', 'nonce');
        if (! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
        }

        global $wpdb;
        $table = $this->table('sources');
        $cand_table = $this->table('candidates');

        // Reset all sources to unprocessed
        $reset = $wpdb->query("UPDATE {$table} SET processed = 0");

        // Remove ALL existing candidates (they'll be recreated with new scores)
        $wpdb->query("DELETE FROM {$cand_table}");

        // Now score them all
        $limit  = ! empty($_POST['limit']) ? intval($_POST['limit']) : 500;
        $result = $this->score_unprocessed($limit);
        $result['reset'] = $reset;

        rawwire_log('lead_generator', 'Re-scored all records: ' . $result['scored'] . ' scored, ' . $result['failed'] . ' failed', 'info');
        wp_send_json_success($result);
    }

    public function ajax_update_weights()
    {
        check_ajax_referer('rawwire_lead_nonce', 'nonce');
        if (! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
        }

        $weights = array();
        foreach (array_keys(self::DEFAULT_WEIGHTS) as $key) {
            if (isset($_POST['weights'][$key])) {
                $weights[$key] = max(0, min(5, floatval($_POST['weights'][$key])));
            }
        }

        update_option(self::WEIGHTS_OPTION, $weights);
        wp_send_json_success(array('weights' => $this->get_weights()));
    }

    /**
     * AJAX: Save blacklist keywords
     */
    public function ajax_save_blacklist()
    {
        check_ajax_referer('rawwire_lead_nonce', 'nonce');
        if (! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
        }

        $raw = isset($_POST['keywords']) ? $_POST['keywords'] : '';
        if (is_string($raw)) {
            // Comma-separated string from input
            $keywords = array_values(array_unique(array_filter(array_map('trim', array_map('strtolower', explode(',', $raw))))));
        } elseif (is_array($raw)) {
            $keywords = array_values(array_unique(array_filter(array_map('trim', array_map('strtolower', $raw)))));
        } else {
            $keywords = array();
        }

        update_option(self::BLACKLIST_OPTION, $keywords);

        rawwire_log('lead_generator', sprintf('Blacklist updated: %d keywords [%s]', count($keywords), implode(', ', $keywords)), 'info');
        wp_send_json_success(array('keywords' => $keywords, 'count' => count($keywords)));
    }

    public function ajax_save_settings()
    {
        check_ajax_referer('rawwire_lead_nonce', 'nonce');
        if (! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
        }

        $settings = $this->get_settings();

        // Update only provided fields
        $fields = array(
            'candidate_threshold',
            'candidate_limit',
            'import_limit',
            'min_valuation',
            'date_range_months',
            'max_permit_age_months',
            'triage_batch_size',
            'triage_picks',
            'socrata_app_token',
            'socrata_secret_token',
            'textrazor_api_key',
            'primary_dataset',
        );

        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                $settings[$field] = sanitize_text_field($_POST[$field]);
            }
        }

        // Boolean fields
        foreach (array('auto_score', 'auto_promote') as $field) {
            if (isset($_POST[$field])) {
                $settings[$field] = (bool) $_POST[$field];
            }
        }

        // Array fields
        if (! empty($_POST['permit_types'])) {
            $settings['permit_types'] = array_map('sanitize_text_field', (array) $_POST['permit_types']);
        }
        if (! empty($_POST['permit_sub_types'])) {
            $settings['permit_sub_types'] = array_map('sanitize_text_field', (array) $_POST['permit_sub_types']);
        }

        update_option(self::SETTINGS_OPTION, $settings);
        wp_send_json_success(array('settings' => $settings));
    }

    public function ajax_get_stats()
    {
        check_ajax_referer('rawwire_lead_nonce', 'nonce');
        if (! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
        }

        wp_send_json_success($this->get_stats());
    }

    /**
     * AJAX: Reset (truncate) all lead generator tables
     */
    public function ajax_reset_tables()
    {
        check_ajax_referer('rawwire_lead_nonce', 'nonce');
        if (! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
        }

        global $wpdb;

        $tables = array(
            'sources',
            'candidates',
            'archive',
            'content',
            'network_entities',
            'network_projects',
        );

        $truncated = array();
        $errors = array();

        foreach ($tables as $table_key) {
            $full_table = $this->table($table_key);

            // Check if table exists
            $exists = $wpdb->get_var("SHOW TABLES LIKE '{$full_table}'");
            if (!$exists) {
                $errors[] = "{$table_key} does not exist";
                continue;
            }

            $result = $wpdb->query("TRUNCATE TABLE {$full_table}");
            if ($result === false) {
                $errors[] = "{$table_key} truncate failed";
            } else {
                $truncated[] = $table_key;
            }
        }

        // Force schema upgrade to add any new columns
        delete_option(self::DB_VERSION_OPTION);
        $this->maybe_create_tables();

        wp_send_json_success(array(
            'truncated' => $truncated,
            'errors'    => $errors,
            'message'   => sprintf('Cleared %d tables. Schema refreshed to v%s.', count($truncated), self::DB_VERSION),
        ));
    }

    public function ajax_bulk_action()
    {
        check_ajax_referer('rawwire_lead_nonce', 'nonce');
        if (! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
        }

        $action = sanitize_text_field($_POST['bulk_action']);
        $ids    = array_map('intval', (array) $_POST['ids']);

        if (empty($ids)) {
            wp_send_json_error(array('message' => 'No items selected.'));
        }

        $results = array('success' => 0, 'failed' => 0);

        // empty_trash: purge ALL trashed candidates (ignores $ids)
        if ($action === 'empty_trash') {
            global $wpdb;
            $table = $this->table('candidates');
            $trashed = $wpdb->get_col("SELECT id FROM {$table} WHERE status = 'trashed'");
            foreach ($trashed as $tid) {
                $r = $this->purge_candidate((int) $tid);
                if (is_wp_error($r)) {
                    $results['failed']++;
                } else {
                    $results['success']++;
                }
            }
            wp_send_json_success($results);
        }

        foreach ($ids as $id) {
            switch ($action) {
                case 'approve':
                    $r = $this->approve_candidate($id);
                    break;
                case 'reject':
                case 'trash':
                    $r = $this->trash_candidate($id);
                    break;
                case 'restore':
                    $r = $this->restore_candidate($id);
                    break;
                case 'purge':
                    $r = $this->purge_candidate($id);
                    break;
                default:
                    $r = new WP_Error('invalid_action', 'Unknown action.');
            }

            if (is_wp_error($r)) {
                $results['failed']++;
            } else {
                $results['success']++;
            }
        }

        wp_send_json_success($results);
    }

    public function ajax_update_lead_status()
    {
        check_ajax_referer('rawwire_lead_nonce', 'nonce');
        if (! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
        }

        global $wpdb;
        $content_id = intval($_POST['content_id']);
        $new_status = sanitize_text_field($_POST['status']);
        $valid = array('enriching', 'ready', 'contacted', 'converted', 'lost');

        if (! in_array($new_status, $valid, true)) {
            wp_send_json_error(array('message' => 'Invalid status.'));
        }

        $wpdb->update(
            $this->table('content'),
            array(
                'status'           => $new_status,
                'status_changed_at' => current_time('mysql'),
                'status_changed_by' => get_current_user_id(),
            ),
            array('id' => $content_id)
        );

        wp_send_json_success(array('status' => $new_status));
    }

    // =========================================================================
    // STATS
    // =========================================================================

    /**
     * Get pipeline statistics
     */
    public function get_stats()
    {
        global $wpdb;

        return array(
            'sources' => array(
                'total'       => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . $this->table('sources')),
                'unprocessed' => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . $this->table('sources') . " WHERE processed = 0"),
                'processed'   => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . $this->table('sources') . " WHERE processed = 1"),
            ),
            'candidates' => array(
                'total'    => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . $this->table('candidates') . " WHERE status != 'trashed'"),
                'pending'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . $this->table('candidates') . " WHERE status = 'pending'"),
                'approved' => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . $this->table('candidates') . " WHERE status = 'approved'"),
                'trashed'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . $this->table('candidates') . " WHERE status = 'trashed'"),
            ),
            'archive' => array(
                'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . $this->table('archive')),
            ),
            'leads' => array(
                'total'     => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . $this->table('content')),
                'enriching' => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . $this->table('content') . " WHERE status = 'enriching'"),
                'ready'     => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . $this->table('content') . " WHERE status = 'ready'"),
                'contacted' => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . $this->table('content') . " WHERE status = 'contacted'"),
                'converted' => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . $this->table('content') . " WHERE status = 'converted'"),
            ),
        );
    }

    // =========================================================================
    // CRON
    // =========================================================================

    public function cron_import()
    {
        $this->import();
    }

    public function cron_score()
    {
        $this->score_unprocessed(100);
    }

    public function cron_promote()
    {
        $this->promote_candidates();
    }

    // =========================================================================
    // FACTORY HELPERS
    // =========================================================================

    /**
     * @return RawWire_Socrata_Client|WP_Error
     */
    public function get_socrata_client()
    {
        $settings = $this->get_settings();
        // Token is optional - Socrata allows anonymous access (rate limited to ~1000/hr)
        if (empty($settings['socrata_app_token'])) {
            rawwire_log('lead_generator', 'No Socrata token configured - using anonymous access (rate limited)', 'warning');
        }
        require_once __DIR__ . '/class-socrata-client.php';
        return new RawWire_Socrata_Client(
            $settings['socrata_app_token'] ?? '',
            $settings['socrata_secret_token'] ?? ''
        );
    }

    /**
     * @return RawWire_Lead_Scorer
     */
    public function get_scorer()
    {
        require_once __DIR__ . '/class-lead-scorer.php';
        return new RawWire_Lead_Scorer($this);
    }

    /**
     * @deprecated Superseded by Party Investigator + OpenClaw
     * @return null
     */
    public function get_enricher()
    {
        rawwire_log('lead_generator', 'get_enricher() is deprecated — use Party Investigator instead', 'warning');
        return null;
    }
}

/**
 * Global accessor
 *
 * @return RawWire_Lead_Generator
 */
function rawwire_leads()
{
    return RawWire_Lead_Generator::get_instance();
}

/**
 * Logging stub — safe fallback when full logger is not loaded.
 * Maps to error_log so lead pipeline calls never fatal.
 */
if (! function_exists('rawwire_log')) {
    function rawwire_log($channel, $message, $level = 'info')
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('[RawWire/%s] [%s] %s', $channel, $level, $message));
        }
    }
}
