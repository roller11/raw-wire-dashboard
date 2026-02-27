<?php

/**
 * @ai-context Search Instinct MCP for "Soothsayer v2 Complete Function Map" before modifying this file.
 */

/**
 * Soothsayer v2 - Intelligence Action Center
 *
 * Simplified, action-focused dashboard with three tabs:
 * 1. Contract Leads - Upcoming projects with contact actions
 * 2. Industry Calendar - Events timeline
 * 3. Confirmed Projects - Active opportunities
 *
 * @package RawWire_Dashboard
 * @since 1.0.31
 */

defined('ABSPATH') || exit;

/**
 * Class RawWire_Soothsayer_V2
 */
class RawWire_Soothsayer_V2
{
    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        // AJAX actions
        add_action('wp_ajax_rawwire_get_lead_details', array($this, 'ajax_get_lead_details'));
        add_action('wp_ajax_rawwire_send_to_phone', array($this, 'ajax_send_to_phone'));
        add_action('wp_ajax_rawwire_add_to_contacts', array($this, 'ajax_add_to_contacts'));
        add_action('wp_ajax_rawwire_compose_email', array($this, 'ajax_compose_email'));
        add_action('wp_ajax_rawwire_add_calendar_event', array($this, 'ajax_add_calendar_event'));
        add_action('wp_ajax_rawwire_follow_lead', array($this, 'ajax_follow_lead'));
        add_action('wp_ajax_rawwire_discuss_with_ai', array($this, 'ajax_discuss_with_ai'));
        add_action('wp_ajax_rawwire_get_events', array($this, 'ajax_get_events'));
        add_action('wp_ajax_rawwire_get_confirmed_projects', array($this, 'ajax_get_confirmed_projects'));
        // Tier investigation actions
        add_action('wp_ajax_rawwire_probe_further', array($this, 'ajax_probe_further'));
        add_action('wp_ajax_rawwire_launch_deep_dive', array($this, 'ajax_launch_deep_dive'));
        // Developer reset investigation
        add_action('wp_ajax_rawwire_reset_investigation', array($this, 'ajax_reset_investigation'));
        // Trash/archive a lead
        add_action('wp_ajax_rawwire_trash_lead', array($this, 'ajax_trash_lead'));

        // Register 48-hour cron interval
        add_filter('cron_schedules', array($this, 'register_cron_schedules'));

        // Register follow check cron hook
        add_action('rawwire_follow_check_cron', array($this, 'cron_follow_check'));
    }

    /**
     * Register custom cron schedules
     */
    public function register_cron_schedules($schedules)
    {
        if (!isset($schedules['fortyeight_hours'])) {
            $schedules['fortyeight_hours'] = array(
                'interval' => 48 * 60 * 60, // 48 hours in seconds
                'display' => __('Every 48 hours'),
            );
        }
        return $schedules;
    }

    /**
     * Render the main page
     */
    public function render()
    {
        $this->enqueue_assets();
?>
        <div class="soothsayer-app">
            <!-- Hero Section with Animations -->
            <div class="soothsayer-hero">
                <div class="soothsayer-hero-content">
                    <div class="soothsayer-hero-icon">
                        <span class="dashicons dashicons-visibility"></span>
                        <div class="soothsayer-icon-pulse"></div>
                    </div>
                    <h1>SOOTHSAYER</h1>
                    <p class="soothsayer-hero-subtitle">Intelligence Action Center</p>
                </div>
                <div class="soothsayer-hero-search">
                    <input type="text" id="global-search" placeholder="Search leads, projects, contacts..." />
                    <span class="dashicons dashicons-search"></span>
                </div>
                <div class="soothsayer-hero-particles" aria-hidden="true">
                    <?php for ($i = 0; $i < 20; $i++): ?>
                        <div class="particle particle-<?php echo $i; ?>"></div>
                    <?php endfor; ?>
                </div>
            </div>

            <nav class="soothsayer-tabs">
                <button class="tab-btn active" data-tab="leads">
                    <span class="dashicons dashicons-businessperson"></span>
                    Contract Leads
                </button>
                <button class="tab-btn" data-tab="calendar">
                    <span class="dashicons dashicons-calendar-alt"></span>
                    Industry Calendar
                </button>
                <button class="tab-btn" data-tab="projects">
                    <span class="dashicons dashicons-building"></span>
                    Confirmed Projects
                </button>
                <button class="tab-btn" data-tab="network">
                    <span class="dashicons dashicons-networking"></span>
                    Network
                </button>
            </nav>

            <main class="soothsayer-content">
                <!-- Tab 1: Contract Leads -->
                <section class="tab-panel active" id="panel-leads">
                    <?php $this->render_leads_panel(); ?>
                </section>

                <!-- Tab 2: Industry Calendar -->
                <section class="tab-panel" id="panel-calendar">
                    <?php $this->render_calendar_panel(); ?>
                </section>

                <!-- Tab 3: Confirmed Projects -->
                <section class="tab-panel" id="panel-projects">
                    <?php $this->render_projects_panel(); ?>
                </section>

                <!-- Tab 4: Network -->
                <section class="tab-panel" id="panel-network">
                    <?php $this->render_network_panel(); ?>
                </section>
            </main>
        </div>

        <!-- Modal for AI Discussion -->
        <div class="soothsayer-modal" id="ai-modal">
            <div class="modal-overlay"></div>
            <div class="modal-content">
                <div class="modal-header">
                    <h3>💬 Discuss with AI</h3>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="ai-chat" id="ai-chat-container"></div>
                    <div class="ai-input">
                        <textarea id="ai-message" placeholder="Ask about strategies, approaches, or how to leverage this opportunity..."></textarea>
                        <button class="btn-primary" id="ai-send">Send</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal for Email Compose -->
        <div class="soothsayer-modal" id="email-modal">
            <div class="modal-overlay"></div>
            <div class="modal-content">
                <div class="modal-header">
                    <h3>📧 Compose Email</h3>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>To:</label>
                        <input type="email" id="email-to" readonly />
                    </div>
                    <div class="form-group">
                        <label>Subject:</label>
                        <input type="text" id="email-subject" />
                    </div>
                    <div class="form-group">
                        <label>Message:</label>
                        <textarea id="email-body" rows="8"></textarea>
                    </div>
                    <div class="form-actions">
                        <button class="btn-secondary" id="ai-draft">✨ AI Draft</button>
                        <button class="btn-primary" id="email-send">Send Email</button>
                    </div>
                </div>
            </div>
        </div>
    <?php
    }

    /**
     * Tab 1: Contract Leads Panel
     */
    private function render_leads_panel()
    {
        $leads = $this->get_contract_leads();
    ?>
        <div class="split-panel">
            <!-- Left: Lead List -->
            <div class="list-panel">
                <div class="list-header">
                    <h2>Upcoming Projects</h2>
                    <select id="leads-filter">
                        <option value="all">All Leads</option>
                        <option value="hot">Hot (Score 80+)</option>
                        <option value="new">New This Week</option>
                        <option value="following">Following</option>
                    </select>
                </div>
                <ul class="lead-list" id="lead-list">
                    <?php foreach ($leads as $lead): ?>
                        <li class="lead-item" data-id="<?php echo esc_attr($lead['id']); ?>">
                            <div class="lead-score score-<?php echo $this->get_score_class($lead['score']); ?>">
                                <?php echo esc_html($lead['score']); ?>
                            </div>
                            <div class="lead-info">
                                <strong class="lead-title"><?php echo esc_html($lead['project_name']); ?></strong>
                                <span class="lead-company"><?php echo esc_html($lead['contractor']); ?></span>
                                <div class="lead-meta">
                                    <span class="lead-value">$<?php echo esc_html($this->format_value($lead['value'])); ?></span>
                                    <span class="lead-date"><?php echo esc_html($lead['start_date']); ?></span>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- Right: Lead Details -->
            <div class="detail-panel" id="lead-details">
                <div class="detail-empty">
                    <span class="dashicons dashicons-arrow-left-alt"></span>
                    <p>Select a lead to view details</p>
                </div>
            </div>
        </div>
    <?php
    }

    /**
     * Tab 2: Calendar Panel
     */
    private function render_calendar_panel()
    {
    ?>
        <div class="calendar-panel">
            <div class="calendar-header">
                <h2 id="calendar-title">February 2026</h2>
                <div class="calendar-nav">
                    <button class="btn-icon" id="prev-month">
                        <span class="dashicons dashicons-arrow-left-alt2"></span>
                    </button>
                    <button class="btn-icon" id="next-month">
                        <span class="dashicons dashicons-arrow-right-alt2"></span>
                    </button>
                </div>
                <div class="calendar-views">
                    <button class="view-btn active" data-view="month">Month</button>
                    <button class="view-btn" data-view="list">List</button>
                </div>
            </div>
            <div class="calendar-grid" id="calendar-grid">
                <!-- Calendar populated via JS -->
            </div>
            <div class="calendar-list" id="calendar-list" style="display:none;">
                <!-- List view populated via JS -->
            </div>
        </div>
    <?php
    }

    /**
     * Tab 3: Confirmed Projects Panel
     */
    private function render_projects_panel()
    {
        $projects = $this->get_confirmed_projects();
    ?>
        <div class="split-panel">
            <!-- Left: Project List -->
            <div class="list-panel">
                <div class="list-header">
                    <h2>Confirmed Projects</h2>
                    <select id="projects-filter">
                        <option value="all">All Projects</option>
                        <option value="bidding">Open Bidding</option>
                        <option value="awarded">Recently Awarded</option>
                        <option value="starting">Starting Soon</option>
                    </select>
                </div>
                <ul class="project-list" id="project-list">
                    <?php foreach ($projects as $project): ?>
                        <li class="project-item" data-id="<?php echo esc_attr($project['id']); ?>">
                            <div class="project-status status-<?php echo esc_attr($project['status']); ?>">
                                <?php echo esc_html($project['status_label']); ?>
                            </div>
                            <div class="project-info">
                                <strong class="project-title"><?php echo esc_html($project['name']); ?></strong>
                                <span class="project-gc"><?php echo esc_html($project['gc']); ?></span>
                                <div class="project-meta">
                                    <span class="project-value">$<?php echo esc_html($this->format_value($project['value'])); ?></span>
                                    <span class="project-location"><?php echo esc_html($project['location']); ?></span>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- Right: Project Details -->
            <div class="detail-panel" id="project-details">
                <div class="detail-empty">
                    <span class="dashicons dashicons-arrow-left-alt"></span>
                    <p>Select a project to view details</p>
                </div>
            </div>
        </div>
    <?php
    }

    /**
     * Tab 4: Network Panel
     * Displays discovered contractors, partners, and related projects
     */
    private function render_network_panel()
    {
        $network_data = $this->get_network_data();
    ?>
        <div class="network-panel">
            <div class="network-header">
                <h2>Discovered Network</h2>
                <p class="network-subtitle">Contractors, partners, and projects discovered during investigations</p>
            </div>

            <div class="network-grid">
                <!-- Contractors & Partners Section -->
                <div class="network-section">
                    <div class="section-header">
                        <span class="dashicons dashicons-groups"></span>
                        <h3>Contractors & Partners</h3>
                        <span class="section-count"><?php echo count($network_data['entities']); ?></span>
                    </div>
                    <ul class="network-list" id="network-entities">
                        <?php if (empty($network_data['entities'])): ?>
                            <li class="network-empty">
                                <span class="dashicons dashicons-search"></span>
                                <p>No entities discovered yet. Run Tier 1 investigations to build the network.</p>
                            </li>
                        <?php else: ?>
                            <?php foreach ($network_data['entities'] as $entity): ?>
                                <li class="network-item entity-item" data-id="<?php echo esc_attr($entity['id']); ?>" data-type="entity">
                                    <div class="network-item-header">
                                        <span class="entity-type type-<?php echo esc_attr($entity['type']); ?>">
                                            <?php echo esc_html(ucfirst($entity['type'])); ?>
                                        </span>
                                        <span class="entity-score score-<?php echo $this->score_class($entity['score']); ?>">
                                            <?php echo esc_html($entity['score']); ?>
                                        </span>
                                    </div>
                                    <strong class="entity-name"><?php echo esc_html($entity['name']); ?></strong>
                                    <span class="entity-company"><?php echo esc_html($entity['company'] ?? ''); ?></span>
                                    <div class="network-item-meta">
                                        <span class="discovered-from">via <?php echo esc_html($entity['discovered_from']); ?></span>
                                    </div>
                                    <div class="network-item-actions">
                                        <button class="btn-sm btn-probe" data-action="probe" data-id="<?php echo esc_attr($entity['id']); ?>" data-type="entity">
                                            <span class="dashicons dashicons-search"></span> Probe Further
                                        </button>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>

                <!-- Discovered Projects Section -->
                <div class="network-section">
                    <div class="section-header">
                        <span class="dashicons dashicons-building"></span>
                        <h3>Discovered Projects</h3>
                        <span class="section-count"><?php echo count($network_data['projects']); ?></span>
                    </div>
                    <ul class="network-list" id="network-projects">
                        <?php if (empty($network_data['projects'])): ?>
                            <li class="network-empty">
                                <span class="dashicons dashicons-search"></span>
                                <p>No projects discovered yet. Run Tier 1 investigations to uncover related projects.</p>
                            </li>
                        <?php else: ?>
                            <?php foreach ($network_data['projects'] as $project): ?>
                                <li class="network-item project-item" data-id="<?php echo esc_attr($project['id']); ?>" data-type="project">
                                    <div class="network-item-header">
                                        <span class="project-status status-<?php echo esc_attr($project['status']); ?>">
                                            <?php echo esc_html($project['status_label']); ?>
                                        </span>
                                        <span class="entity-score score-<?php echo $this->score_class($project['score'] ?? 0); ?>">
                                            <?php echo esc_html($project['score'] ?? '-'); ?>
                                        </span>
                                    </div>
                                    <strong class="project-name"><?php echo esc_html($project['name']); ?></strong>
                                    <span class="project-contractor"><?php echo esc_html($project['contractor'] ?? ''); ?></span>
                                    <div class="network-item-meta">
                                        <span class="project-value">$<?php echo esc_html($this->format_value($project['value'] ?? 0)); ?></span>
                                        <span class="discovered-from">via <?php echo esc_html($project['discovered_from']); ?></span>
                                    </div>
                                    <div class="network-item-actions">
                                        <button class="btn-sm btn-probe" data-action="probe" data-id="<?php echo esc_attr($project['id']); ?>" data-type="project">
                                            <span class="dashicons dashicons-search"></span> Probe Further
                                        </button>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <!-- Network Map Visualization Placeholder -->
            <div class="network-map-section">
                <div class="section-header">
                    <span class="dashicons dashicons-chart-area"></span>
                    <h3>Network Map</h3>
                    <span class="tier-badge">Tier 2</span>
                </div>
                <div class="network-map-placeholder" id="network-map">
                    <div class="map-empty">
                        <span class="dashicons dashicons-admin-site-alt3"></span>
                        <p>Launch a Tier 2 Deep Dive on any lead to generate a network map</p>
                        <p class="map-hint">The network map visualizes connections between contractors, partners, and projects</p>
                    </div>
                </div>
            </div>
        </div>
    <?php
    }

    /**
     * Get network data (discovered entities and projects)
     */
    private function get_network_data()
    {
        global $wpdb;

        $entities = [];
        $projects = [];

        // Get discovered entities from network_entities table
        $entities_table = $wpdb->prefix . 'rawwire_network_entities';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$entities_table}'") === $entities_table) {
            $rows = $wpdb->get_results(
                "SELECT id, name, company, type, relationship, score, score_hint, 
                        discovered_from, notes, probed, investigation_tier
                 FROM {$entities_table}
                 ORDER BY score DESC, created_at DESC
                 LIMIT 100",
                ARRAY_A
            );
            foreach ($rows as $row) {
                $entities[] = [
                    'id'              => $row['id'],
                    'name'            => $row['name'],
                    'company'         => $row['company'],
                    'type'            => $row['type'],
                    'relationship'    => $row['relationship'],
                    'score'           => (int) ($row['score'] ?: $row['score_hint']),
                    'discovered_from' => $row['discovered_from'],
                    'notes'           => $row['notes'],
                    'probed'          => (bool) $row['probed'],
                    'tier'            => (int) $row['investigation_tier'],
                ];
            }
        }

        // Get discovered projects from network_projects table
        $projects_table = $wpdb->prefix . 'rawwire_network_projects';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$projects_table}'") === $projects_table) {
            $rows = $wpdb->get_results(
                "SELECT id, name, location, status, status_label, value, contractor, 
                        role, score, discovered_from, probed, investigation_tier
                 FROM {$projects_table}
                 ORDER BY score DESC, created_at DESC
                 LIMIT 100",
                ARRAY_A
            );
            foreach ($rows as $row) {
                $status_label = $row['status_label'] ?: ucfirst(str_replace('_', ' ', $row['status']));
                $projects[] = [
                    'id'              => $row['id'],
                    'name'            => $row['name'],
                    'location'        => $row['location'],
                    'status'          => $row['status'],
                    'status_label'    => $status_label,
                    'value'           => (float) $row['value'],
                    'contractor'      => $row['contractor'],
                    'role'            => $row['role'],
                    'score'           => (int) $row['score'],
                    'discovered_from' => $row['discovered_from'],
                    'probed'          => (bool) $row['probed'],
                    'tier'            => (int) $row['investigation_tier'],
                ];
            }
        }

        return [
            'entities' => $entities,
            'projects' => $projects,
        ];
    }

    /**
     * Get score CSS class
     */
    private function score_class($score)
    {
        if ($score >= 80) return 'hot';
        if ($score >= 60) return 'warm';
        if ($score >= 40) return 'cool';
        return 'cold';
    }

    /**
     * Enqueue assets
     */
    private function enqueue_assets()
    {
        $plugin_url = plugin_dir_url(__FILE__) . '../';
        $version = defined('RAWWIRE_DASHBOARD_VERSION') ? RAWWIRE_DASHBOARD_VERSION : '1.0.31';

        wp_enqueue_style(
            'rawwire-soothsayer-v2',
            $plugin_url . 'css/soothsayer-v2.css',
            array(),
            $version
        );

        wp_enqueue_script(
            'rawwire-soothsayer-v2',
            $plugin_url . 'js/soothsayer-v2.js',
            array('jquery'),
            $version,
            true
        );

        wp_localize_script('rawwire-soothsayer-v2', 'soothsayerData', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rawwire_soothsayer'),
            'leadNonce' => wp_create_nonce('rawwire_lead_nonce'),
            'restUrl' => rest_url('rawwire/v1/'),
        ));
    }

    /**
     * Get contract leads data from database
     * Queries rawwire_lead_candidates table (AI-scored top records) with source data JOIN.
     * Only shows promoted candidates — sources go through AI scoring first,
     * top records become candidates, the rest are archived.
     */
    private function get_contract_leads()
    {
        global $wpdb;
        $cand_table = $wpdb->prefix . 'rawwire_lead_candidates';
        $src_table  = $wpdb->prefix . 'rawwire_lead_sources';

        // Query promoted candidates - use only columns that exist in current schema
        // Note: v2.0.0 adds many new columns but migration may not have run yet
        $results = $wpdb->get_results(
            "SELECT 
                c.id,
                c.source_id,
                c.permit_nbr,
                c.primary_address,
                c.work_desc,
                c.contractor_name,
                c.contractor_license,
                c.applicant_name,
                c.applicant_business,
                c.valuation,
                c.permit_type,
                c.permit_sub_type,
                c.use_desc,
                c.square_footage,
                c.lat,
                c.lon,
                c.city,
                c.state,
                c.council_district,
                c.zip_code,
                c.solar,
                c.ev,
                c.issue_date,
                c.score_composite,
                c.score_project_scale,
                c.score_type_fit,
                c.score_green_indicators,
                c.score_timeline,
                c.score_location,
                c.score_use_relevance,
                c.score_complexity,
                c.score_reasoning,
                c.status,
                c.scored_at,
                s.owner_name,
                s.status_desc,
                s.status_date,
                s.submitted_date,
                s.stories,
                s.construction_type,
                s.use_code,
                s.height,
                s.zone,
                s.party_profiles,
                s.investigation_status,
                s.investigator_summary,
                s.community_plan_area,
                s.neighborhood_council,
                s.cofo_date
            FROM {$cand_table} c
            LEFT JOIN {$src_table} s ON s.id = c.source_id
            WHERE c.status IN ('pending', 'approved', 'contacted')
            ORDER BY c.score_composite DESC
            LIMIT 50",
            ARRAY_A
        );

        if (empty($results)) {
            return array();
        }

        $leads = array();
        foreach ($results as $row) {
            // Use the real AI composite score (0-10 scale → 0-100 for display)
            $score = round(floatval($row['score_composite']) * 10, 0);

            // Build project name from address or work description
            $project_name = $this->build_project_name($row);

            // Parse dates — prefer issue_date (when permit became active)
            // This is the "Start" date - when work can legally begin
            $issue_date = $row['issue_date'];
            $start_date = $issue_date
                ? date('M j, Y', strtotime($issue_date))
                : 'Pending Issuance';

            // Estimate end date from project scale (valuation-based heuristic)
            $end_date = 'TBD';
            if ($row['cofo_date']) {
                $end_date = date('M Y', strtotime($row['cofo_date']));
            } elseif ($row['estimated_completion_date'] ?? null) {
                $end_date = date('M Y', strtotime($row['estimated_completion_date']));
            } elseif ($issue_date) {
                // Estimate based on valuation: roughly 1 month per $200K
                $valuation = (float) $row['valuation'];
                $months = max(3, min(36, round($valuation / 200000)));
                $end_date = date('M Y', strtotime($issue_date . " +{$months} months"));
            }

            // Build full location string
            $location = $row['primary_address'] ?? '';
            if (($row['city'] ?? '') || ($row['zip_code'] ?? '')) {
                $location .= $location ? ', ' : '';
                $location .= trim(($row['city'] ?? '') . ' ' . ($row['zip_code'] ?? ''));
            }

            $leads[] = array(
                'id' => (int) $row['id'],
                'source_id' => (int) $row['source_id'],
                'project_name' => $project_name,
                'contractor' => $row['contractor_name'] ?? 'TBD',
                'contractor_license' => $row['contractor_license'] ?? '',
                'contractor_phone' => $row['contractor_phone'] ?? '',
                'contractor_email' => $row['contractor_email'] ?? '',
                'owner' => $row['owner_name'] ?? 'Unknown Owner',
                'owner_phone' => $row['owner_phone'] ?? '',
                'owner_email' => $row['owner_email'] ?? '',
                'applicant' => $row['applicant_name'] ?? '',
                'applicant_business' => $row['applicant_business'] ?? '',
                'architect' => $row['architect_name'] ?? '',
                'architect_firm' => $row['architect_firm'] ?? '',
                'value' => (float) $row['valuation'],
                'start_date' => $start_date,
                'end_date' => $end_date,
                'submitted_date' => $row['submitted_date'] ? date('M j, Y', strtotime($row['submitted_date'])) : '',
                'issue_date' => $row['issue_date'] ? date('M j, Y', strtotime($row['issue_date'])) : '',
                'score' => $score,
                'score_breakdown' => array(
                    'project_scale'    => (float) $row['score_project_scale'],
                    'type_fit'         => (float) $row['score_type_fit'],
                    'green_indicators' => (float) $row['score_green_indicators'],
                    'timeline'         => (float) $row['score_timeline'],
                    'location'         => (float) $row['score_location'],
                    'use_relevance'    => (float) $row['score_use_relevance'],
                    'complexity'       => (float) $row['score_complexity'],
                ),
                'score_reasoning' => $row['score_reasoning'],
                'description' => $this->truncate_description($row['work_desc'], 80),
                'work_desc' => $row['work_desc'],
                'permit_nbr' => $row['permit_nbr'],
                'permit_type' => $row['permit_type'],
                'permit_sub_type' => $row['permit_sub_type'],
                'use_code' => $row['use_code'] ?? '',
                'use_desc' => $row['use_desc'] ?? '',
                'status_desc' => $row['status_desc'] ?? '',
                'status_date' => $row['status_date'] ? date('M j, Y', strtotime($row['status_date'])) : '',
                'address' => $row['primary_address'] ?? '',
                'full_location' => $location,
                'zip_code' => $row['zip_code'] ?? '',
                'city' => $row['city'] ?? '',
                'state' => ($row['state'] ?? '') ?: 'CA',
                'council_district' => $row['council_district'] ?? '',
                'community_plan_area' => $row['community_plan_area'] ?? '',
                'neighborhood_council' => $row['neighborhood_council'] ?? '',
                'square_footage' => (int) $row['square_footage'],
                'height' => $row['height'] ?? '',
                'stories' => $row['stories'] ?? '',
                'construction_type' => $row['construction_type'] ?? '',
                'zone' => $row['zone'] ?? '',
                'solar' => $row['solar'] ?? '',
                'ev' => $row['ev'] ?? '',
                'lat' => (float) $row['lat'],
                'lon' => (float) $row['lon'],
                'investigation_status' => $row['investigation_status'] ?? 'pending',
                'investigator_summary' => $row['investigator_summary'] ?? '',
                'investigator_confidence' => (int) ($row['investigator_confidence'] ?? 0),
                'party_profiles' => $row['party_profiles'] ? json_decode($row['party_profiles'], true) : null,
                'candidate_status' => $row['status'] ?? 'pending',
                'scored_at' => $row['scored_at'] ?? null,
            );
        }

        return $leads;
    }

    /**
     * Calculate opportunity score (0-100) for a permit record
     */
    private function calculate_opportunity_score($row)
    {
        $score = 50; // Base score

        // Valuation scoring (up to +30 points)
        $valuation = (float) $row['valuation'];
        if ($valuation >= 10000000) $score += 30;      // $10M+
        elseif ($valuation >= 5000000) $score += 25;   // $5M+
        elseif ($valuation >= 1000000) $score += 20;   // $1M+
        elseif ($valuation >= 500000) $score += 15;    // $500K+
        elseif ($valuation >= 100000) $score += 10;    // $100K+

        // Permit type scoring (+5 to +15)
        $permit_type = strtolower($row['permit_type'] ?? '');
        if (strpos($permit_type, 'new') !== false) $score += 15;
        elseif (strpos($permit_type, 'addition') !== false) $score += 10;
        elseif (strpos($permit_type, 'alter') !== false) $score += 5;

        // Use type scoring (+5 to +10)
        $use_desc = strtolower($row['use_desc'] ?? '');
        if (strpos($use_desc, 'commercial') !== false) $score += 10;
        elseif (strpos($use_desc, 'office') !== false) $score += 10;
        elseif (strpos($use_desc, 'hotel') !== false) $score += 8;
        elseif (strpos($use_desc, 'apartment') !== false) $score += 6;
        elseif (strpos($use_desc, 'residential') !== false) $score += 4;

        // Investigation bonus (+5 if investigated)
        if ($row['investigation_status'] === 'completed') $score += 5;

        // Cap at 100
        return min(100, max(0, $score));
    }

    /**
     * Build a readable project name from permit data
     */
    private function build_project_name($row)
    {
        // Try work description first (often most descriptive)
        $work_desc = trim($row['work_desc'] ?? '');
        if ($work_desc && strlen($work_desc) > 10) {
            // Clean up and truncate
            $name = ucwords(strtolower(preg_replace('/\s+/', ' ', $work_desc)));
            return $this->truncate_description($name, 60);
        }

        // Fall back to address + permit type
        $address = $row['primary_address'] ?? '';
        $permit_type = $row['permit_type'] ?? 'Construction';

        if ($address) {
            return $address . ' - ' . $permit_type;
        }

        return 'Permit #' . ($row['permit_nbr'] ?? $row['id']);
    }

    /**
     * Truncate description with ellipsis
     */
    private function truncate_description($text, $length = 80)
    {
        $text = trim($text);
        if (strlen($text) <= $length) return $text;
        return substr($text, 0, $length - 3) . '...';
    }

    /**
     * Get confirmed projects from candidates table
     * Shows approved/contacted candidates first, falls back to top-scored candidates
     */
    private function get_confirmed_projects()
    {
        global $wpdb;
        $cand_table = $wpdb->prefix . 'rawwire_lead_candidates';
        $src_table  = $wpdb->prefix . 'rawwire_lead_sources';

        // First try: approved or contacted candidates (user-confirmed)
        $results = $wpdb->get_results(
            "SELECT 
                c.id,
                c.permit_nbr,
                c.primary_address,
                c.work_desc,
                c.contractor_name,
                c.valuation,
                c.permit_type,
                c.permit_sub_type,
                c.use_desc,
                c.score_composite,
                c.status AS candidate_status,
                s.owner_name,
                s.status_desc,
                s.status_date,
                s.submitted_date,
                s.issue_date,
                s.community_plan_area,
                s.neighborhood_council
            FROM {$cand_table} c
            LEFT JOIN {$src_table} s ON s.id = c.source_id
            WHERE c.status IN ('approved', 'contacted')
            ORDER BY c.score_composite DESC
            LIMIT 30",
            ARRAY_A
        );

        if (empty($results)) {
            // Fall back to top-scored candidates with contractors
            $results = $wpdb->get_results(
                "SELECT 
                    c.id,
                    c.permit_nbr,
                    c.primary_address,
                    c.work_desc,
                    c.contractor_name,
                    c.valuation,
                    c.permit_type,
                    c.permit_sub_type,
                    c.use_desc,
                    c.score_composite,
                    c.status AS candidate_status,
                    s.owner_name,
                    s.status_desc,
                    s.status_date,
                    s.submitted_date,
                    s.issue_date,
                    s.community_plan_area,
                    s.neighborhood_council
                FROM {$cand_table} c
                LEFT JOIN {$src_table} s ON s.id = c.source_id
                WHERE c.status IN ('pending', 'approved', 'contacted')
                ORDER BY c.score_composite DESC
                LIMIT 20",
                ARRAY_A
            );
        }

        if (empty($results)) {
            return array();
        }

        $projects = array();
        foreach ($results as $row) {
            // Determine project status from permit status
            $status_info = $this->parse_permit_status($row['status_desc']);

            // Build location string
            $location = $this->build_location_string($row);

            $projects[] = array(
                'id' => (int) $row['id'],
                'name' => $this->build_project_name($row),
                'gc' => $row['contractor_name'] ?: 'TBD',
                'owner' => $row['owner_name'] ?: 'Unknown',
                'value' => (float) $row['valuation'],
                'location' => $location,
                'status' => $status_info['status'],
                'status_label' => $status_info['label'],
                'permit_nbr' => $row['permit_nbr'],
                'permit_type' => $row['permit_type'],
                'work_desc' => $row['work_desc'],
                'score' => round(floatval($row['score_composite']) * 10, 0),
                'candidate_status' => $row['candidate_status'],
            );
        }

        return $projects;
    }

    /**
     * Parse permit status into project phase
     */
    private function parse_permit_status($status_desc)
    {
        $status = strtolower($status_desc ?? '');

        if (strpos($status, 'issued') !== false || strpos($status, 'approved') !== false) {
            return array('status' => 'starting', 'label' => 'Issued - Starting Soon');
        }
        if (strpos($status, 'submitted') !== false || strpos($status, 'pending') !== false) {
            return array('status' => 'bidding', 'label' => 'Bidding Open');
        }
        if (strpos($status, 'review') !== false || strpos($status, 'check') !== false) {
            return array('status' => 'review', 'label' => 'Under Review');
        }
        if (strpos($status, 'complete') !== false || strpos($status, 'final') !== false) {
            return array('status' => 'awarded', 'label' => 'Awarded/In Progress');
        }

        return array('status' => 'pending', 'label' => $status_desc ?: 'Status Unknown');
    }

    /**
     * Build location string from permit data
     */
    private function build_location_string($row)
    {
        $parts = array();

        if (!empty($row['community_plan_area'])) {
            $parts[] = $row['community_plan_area'];
        } elseif (!empty($row['neighborhood_council'])) {
            $parts[] = $row['neighborhood_council'];
        }

        // Default to LA if nothing else
        if (empty($parts)) {
            $parts[] = 'Los Angeles';
        }

        $parts[] = 'CA';

        return implode(', ', $parts);
    }

    /**
     * Helper: Format currency
     */
    private function format_value($value)
    {
        if ($value >= 1000000000) {
            return round($value / 1000000000, 1) . 'B';
        }
        if ($value >= 1000000) {
            return round($value / 1000000, 1) . 'M';
        }
        if ($value >= 1000) {
            return round($value / 1000, 0) . 'K';
        }
        return number_format($value);
    }

    /**
     * Helper: Get score CSS class
     */
    private function get_score_class($score)
    {
        if ($score >= 85) return 'hot';
        if ($score >= 70) return 'warm';
        return 'cool';
    }

    // ===== AJAX HANDLERS =====

    /**
     * Get detailed lead information from database
     */
    public function ajax_get_lead_details()
    {
        check_ajax_referer('rawwire_soothsayer', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'), 403);
        }

        $lead_id = intval($_POST['lead_id'] ?? 0);
        if (!$lead_id) {
            wp_send_json_error(array('message' => 'Invalid lead ID'));
        }

        global $wpdb;
        $cand_table = $wpdb->prefix . 'rawwire_lead_candidates';
        $src_table  = $wpdb->prefix . 'rawwire_lead_sources';

        // Fetch candidate record with comprehensive data
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                c.*,
                s.party_profiles,
                s.investigation_status,
                s.investigator_summary,
                s.investigator_confidence,
                s.investigator_findings
             FROM {$cand_table} c
             LEFT JOIN {$src_table} s ON s.id = c.source_id
             WHERE c.id = %d",
            $lead_id
        ), ARRAY_A);

        if (!$row) {
            wp_send_json_error(array('message' => 'Lead not found'));
        }

        // Use real AI composite score (0-10 → 0-100 for display)
        $score = round(floatval($row['score_composite']) * 10, 0);

        // Parse party profiles for contacts (from Party Investigator)
        $contacts = array();
        $knowledge = array();
        $party_profiles = $row['party_profiles'] ? json_decode($row['party_profiles'], true) : null;

        if ($party_profiles && is_array($party_profiles)) {
            foreach ($party_profiles as $party_type => $party) {
                // New format: party['profile']['people'] contains contacts
                if (!empty($party['profile']['people'])) {
                    foreach ($party['profile']['people'] as $person) {
                        $contact = array(
                            'name' => $person['name'] ?? 'Unknown',
                            'title' => $person['title'] ?? ($party_type === 'contractor' ? 'Contractor' : 'Contact'),
                            'email' => '',
                            'phone' => '',
                            'linkedin' => $person['contact']['linkedin'] ?? '',
                        );
                        // Extract email from pattern if provided
                        if (!empty($person['contact']['email_pattern'])) {
                            $contact['email'] = $person['contact']['email_pattern'];
                        }
                        if (!empty($person['contact']['email'])) {
                            $contact['email'] = $person['contact']['email'];
                        }
                        if (!empty($person['contact']['phone'])) {
                            $contact['phone'] = $person['contact']['phone'];
                        }
                        $contacts[] = $contact;
                    }
                }
                // Fallback format: party['profile']['person'] (singular, from fallback_analysis)
                if (empty($contacts) && !empty($party['profile']['person'])) {
                    $person = $party['profile']['person'];
                    $contacts[] = array(
                        'name' => $person['full_name'] ?? $person['name'] ?? 'Unknown',
                        'title' => $person['title'] ?? ($party_type === 'contractor' ? 'Contractor' : 'Contact'),
                        'email' => $person['email'] ?? '',
                        'phone' => $person['phone'] ?? '',
                        'linkedin' => $person['linkedin'] ?? ($party['profile']['social_media']['linkedin'] ?? ''),
                    );
                }
                // Legacy format: party['contacts']
                if (!empty($party['contacts'])) {
                    foreach ($party['contacts'] as $c) {
                        $contacts[] = array(
                            'name' => $c['name'] ?? 'Unknown',
                            'title' => $c['title'] ?? $c['role'] ?? 'Contact',
                            'email' => $c['email'] ?? '',
                            'phone' => $c['phone'] ?? '',
                            'linkedin' => $c['linkedin'] ?? '',
                        );
                    }
                }
                // Last resort: Use party-level name if no contacts extracted
                if (empty($contacts) && !empty($party['name'])) {
                    $contacts[] = array(
                        'name'  => $party['name'],
                        'title' => ucfirst($party_type),
                        'email' => '',
                        'phone' => '',
                        'linkedin' => '',
                    );
                }
                // Extract knowledge from profile summary
                if (!empty($party['profile']['target_summary'])) {
                    $knowledge[] = $party['profile']['target_summary'];
                }
                // Extract knowledge/insights (legacy)
                if (!empty($party['evidence'])) {
                    $knowledge = array_merge($knowledge, array_slice($party['evidence'], 0, 3));
                }
                if (!empty($party['summary'])) {
                    $knowledge[] = $party['summary'];
                }
            }
        }

        // Build contacts from permit data (now includes phone/email from candidates table)
        if (empty($contacts)) {
            if (!empty($row['contractor_name'])) {
                $contacts[] = array(
                    'name' => $row['contractor_name'],
                    'title' => 'Contractor' . ($row['contractor_license'] ? ' (Lic: ' . $row['contractor_license'] . ')' : ''),
                    'email' => $row['contractor_email'] ?? '',
                    'phone' => $row['contractor_phone'] ?? '',
                    'linkedin' => '',
                );
            }
            if (!empty($row['applicant_name'])) {
                $contacts[] = array(
                    'name' => $row['applicant_name'],
                    'title' => 'Applicant' . ($row['applicant_business'] ? ' - ' . $row['applicant_business'] : ''),
                    'email' => $row['applicant_email'] ?? '',
                    'phone' => $row['applicant_phone'] ?? '',
                    'linkedin' => '',
                );
            }
            if (!empty($row['owner_name'])) {
                $contacts[] = array(
                    'name' => $row['owner_name'],
                    'title' => 'Property Owner',
                    'email' => $row['owner_email'] ?? '',
                    'phone' => $row['owner_phone'] ?? '',
                    'linkedin' => '',
                );
            }
            if (!empty($row['architect_name'])) {
                $contacts[] = array(
                    'name' => $row['architect_name'],
                    'title' => 'Architect' . ($row['architect_firm'] ? ' - ' . $row['architect_firm'] : ''),
                    'email' => '',
                    'phone' => '',
                    'linkedin' => '',
                );
            }
        }

        // Parse dates — issue_date is when permit is active (start)
        $issue_date = $row['issue_date'];
        $start_date = $issue_date
            ? date('M j, Y', strtotime($issue_date))
            : 'Pending Issuance';

        $submitted_date = $row['submitted_date']
            ? date('M j, Y', strtotime($row['submitted_date']))
            : '';

        // Estimate end date
        $end_date = 'TBD';
        if (!empty($row['cofo_date'])) {
            $end_date = date('M Y', strtotime($row['cofo_date']));
        } elseif (!empty($row['estimated_completion_date'])) {
            $end_date = date('M Y', strtotime($row['estimated_completion_date']));
        } elseif ($issue_date) {
            $valuation = (float) $row['valuation'];
            $months = max(3, min(36, round($valuation / 200000)));
            $end_date = date('M Y', strtotime($issue_date . " +{$months} months"));
        }

        // Build AI suggestions based on permit data
        $ai_suggestions = $this->generate_ai_suggestions($row, $score);

        // Build comprehensive lead object
        $lead = array(
            'id' => (int) $row['id'],
            'source_id' => (int) $row['source_id'],
            'project_name' => $this->build_project_name($row),
            'description' => $row['work_desc'] ?: 'No description available.',
            'work_desc' => $row['work_desc'] ?: '',
            'contractor' => $row['contractor_name'] ?: 'TBD',
            'contractor_license' => $row['contractor_license'] ?? '',
            'owner' => $row['owner_name'] ?: 'Unknown Owner',
            'value' => (float) $row['valuation'],
            'start_date' => $start_date,
            'end_date' => $end_date,
            'submitted_date' => $submitted_date,
            'issue_date' => $issue_date ? date('M j, Y', strtotime($issue_date)) : '',
            'score' => $score,
            'contacts' => $contacts,
            'upcoming_events' => array(), // TODO: Populated from events table
            'bidding_info' => array(
                'method' => $this->infer_bid_method($row),
                'prequalification' => 'Check with GC',
                'bid_deadline' => 'Contact for details',
                'permit_status' => $row['status_desc'] ?: 'Unknown',
                'sub_selection' => 'Contact contractor',
                'annual_project_count' => '-',
            ),
            'knowledge' => array_slice(array_unique($knowledge), 0, 5),
            'ai_suggestions' => $ai_suggestions,
            // Location
            'address' => $row['primary_address'],
            'zip_code' => $row['zip_code'] ?? '',
            'city' => $row['city'] ?? '',
            'state' => $row['state'] ?? 'CA',
            'council_district' => $row['council_district'] ?? '',
            'community_plan_area' => $row['community_plan_area'] ?? '',
            'neighborhood_council' => $row['neighborhood_council'] ?? '',
            'zone' => $row['zone'] ?? '',
            // Permit details
            'permit_nbr' => $row['permit_nbr'],
            'permit_type' => $row['permit_type'],
            'permit_sub_type' => $row['permit_sub_type'],
            'use_code' => $row['use_code'] ?? '',
            'use_desc' => $row['use_desc'],
            'status_desc' => $row['status_desc'] ?? '',
            'status_date' => $row['status_date'] ? date('M j, Y', strtotime($row['status_date'])) : '',
            // Building specs
            'square_footage' => (int) $row['square_footage'],
            'height' => $row['height'] ?? '',
            'stories' => $row['stories'] ?? '',
            'construction_type' => $row['construction_type'] ?? '',
            'solar' => $row['solar'] ?? '',
            'ev' => $row['ev'] ?? '',
            // Geo
            'lat' => (float) $row['lat'],
            'lon' => (float) $row['lon'],
            // Investigation
            'investigation_status' => $row['investigation_status'],
            'investigator_summary' => $row['investigator_summary'] ?? '',
            'investigator_confidence' => (int) ($row['investigator_confidence'] ?? 0),
            // Full party profiles from investigation
            'party_profiles' => $party_profiles,
        );

        ob_start();
        $this->render_lead_detail_html($lead);
        $html = ob_get_clean();

        wp_send_json_success(array('html' => $html, 'lead' => $lead));
    }

    /**
     * Generate AI suggestions based on permit data
     */
    private function generate_ai_suggestions($row, $score)
    {
        $suggestions = array();
        $valuation = (float) $row['valuation'];

        if ($score >= 80) {
            $suggestions[] = 'High-value opportunity - prioritize outreach to contractor.';
        }

        if ($valuation >= 5000000) {
            $suggestions[] = 'Large project value suggests multiple sub-contract opportunities.';
        }

        if (stripos($row['work_desc'] ?? '', 'new') !== false) {
            $suggestions[] = 'New construction project - full scope of work likely available.';
        }

        if (empty($row['contractor_name'])) {
            $suggestions[] = 'No contractor assigned yet - early engagement opportunity.';
        }

        if ($row['investigation_status'] !== 'completed') {
            $suggestions[] = 'Run Party Investigator for detailed contractor intelligence.';
        }

        // Add permit-specific suggestions
        $permit_type = strtolower($row['permit_type'] ?? '');
        if (strpos($permit_type, 'commercial') !== false || strpos($row['use_desc'] ?? '', 'Commercial') !== false) {
            $suggestions[] = 'Commercial project - typical subcontractor needs: HVAC, electrical, plumbing, fire protection.';
        }

        return array_slice($suggestions, 0, 4);
    }

    /**
     * Infer bidding method from permit data
     */
    private function infer_bid_method($row)
    {
        $valuation = (float) $row['valuation'];
        $owner = strtolower($row['owner_name'] ?? '');

        // Public entities typically use competitive bid
        $public_keywords = array('city', 'county', 'state', 'federal', 'school', 'district', 'authority', 'department');
        foreach ($public_keywords as $keyword) {
            if (strpos($owner, $keyword) !== false) {
                return 'Public Competitive Bid';
            }
        }

        if ($valuation >= 10000000) {
            return 'Negotiated/Invited Bid';
        }

        return 'Contact GC for Details';
    }

    /**
     * Render lead detail HTML - Flagship Information Delivery Engine
     * 3D panel design with gray surfaces, gold titles, accent hints
     */
    private function render_lead_detail_html($lead)
    {
        $inv_status = $lead['investigation_status'] ?? 'pending';
        $has_profiles = !empty($lead['party_profiles']) && is_array($lead['party_profiles']);
    ?>
        <div class="ss-detail" data-lead-id="<?php echo esc_attr($lead['id']); ?>">

            <!-- HEADER PANEL -->
            <div class="ss-panel ss-panel-header">
                <div class="ss-header-row">
                    <div class="ss-score score-<?php echo $this->get_score_class($lead['score']); ?>">
                        <?php echo esc_html($lead['score']); ?>
                    </div>
                    <div class="ss-header-info">
                        <h2 class="ss-title"><?php echo esc_html($lead['project_name']); ?></h2>
                        <p class="ss-subtitle">
                            <?php echo esc_html($lead['contractor']); ?> &bull; <?php echo esc_html($lead['owner']); ?>
                        </p>
                        <div class="ss-location">
                            <span class="dashicons dashicons-location"></span>
                            <span class="ss-address"><?php echo esc_html($lead['address'] ?: 'Address TBD'); ?></span>
                            <?php if (!empty($lead['city']) || !empty($lead['zip_code'])): ?>
                                <span class="ss-location-sub">
                                    <?php echo esc_html(implode(', ', array_filter([$lead['city'] ?? '', $lead['state'] ?? 'CA', $lead['zip_code'] ?? '']))); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($lead['neighborhood_council']) || !empty($lead['community_plan_area'])): ?>
                                <span class="ss-location-area"><?php echo esc_html($lead['neighborhood_council'] ?: $lead['community_plan_area']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- INTELLIGENCE BRIEF -->
            <?php $this->render_investigation_hero($lead); ?>

            <!-- METRICS PANEL -->
            <div class="ss-panel">
                <h3 class="ss-panel-title">Project Metrics</h3>
                <div class="ss-metrics-grid">
                    <div class="ss-metric">
                        <span class="ss-metric-label">Value</span>
                        <span class="ss-metric-value ss-metric-highlight">$<?php echo esc_html($this->format_value($lead['value'])); ?></span>
                    </div>
                    <div class="ss-metric">
                        <span class="ss-metric-label">Issued</span>
                        <span class="ss-metric-value"><?php echo esc_html($lead['issue_date'] ?: 'Pending'); ?></span>
                    </div>
                    <div class="ss-metric">
                        <span class="ss-metric-label">Est. Completion</span>
                        <span class="ss-metric-value"><?php echo esc_html($lead['end_date']); ?></span>
                    </div>
                    <div class="ss-metric">
                        <span class="ss-metric-label">Size</span>
                        <span class="ss-metric-value"><?php echo esc_html(number_format($lead['square_footage'])); ?> SF</span>
                    </div>
                    <?php if (!empty($lead['stories'])): ?>
                        <div class="ss-metric">
                            <span class="ss-metric-label">Stories</span>
                            <span class="ss-metric-value"><?php echo esc_html($lead['stories']); ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($lead['construction_type'])): ?>
                        <div class="ss-metric">
                            <span class="ss-metric-label">Construction</span>
                            <span class="ss-metric-value"><?php echo esc_html($lead['construction_type']); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- PERMIT FILE -->
            <div class="ss-panel">
                <h3 class="ss-panel-title">Permit File</h3>
                <div class="ss-permit-grid">
                    <div class="ss-permit-item">
                        <span class="ss-permit-label">Permit #</span>
                        <span class="ss-permit-value ss-mono"><?php echo esc_html($lead['permit_nbr']); ?></span>
                    </div>
                    <div class="ss-permit-item">
                        <span class="ss-permit-label">Type</span>
                        <span class="ss-permit-value"><?php echo esc_html($lead['permit_type']); ?></span>
                    </div>
                    <div class="ss-permit-item">
                        <span class="ss-permit-label">Sub-Type</span>
                        <span class="ss-permit-value"><?php echo esc_html($lead['permit_sub_type'] ?: '-'); ?></span>
                    </div>
                    <div class="ss-permit-item">
                        <span class="ss-permit-label">Use</span>
                        <span class="ss-permit-value"><?php echo esc_html($lead['use_desc'] ?: '-'); ?></span>
                    </div>
                    <div class="ss-permit-item">
                        <span class="ss-permit-label">Status</span>
                        <span class="ss-permit-value ss-status-badge"><?php echo esc_html($lead['status_desc'] ?: 'Unknown'); ?></span>
                    </div>
                    <?php if (!empty($lead['submitted_date'])): ?>
                        <div class="ss-permit-item">
                            <span class="ss-permit-label">Submitted</span>
                            <span class="ss-permit-value"><?php echo esc_html($lead['submitted_date']); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- SCOPE OF WORK -->
            <div class="ss-panel">
                <h3 class="ss-panel-title">Scope of Work</h3>
                <p class="ss-work-desc"><?php echo esc_html($lead['work_desc'] ?: $lead['description']); ?></p>
                <?php if (!empty($lead['solar']) || !empty($lead['ev'])): ?>
                    <div class="ss-green-tags">
                        <?php if ($lead['solar'] === 'Y'): ?>
                            <span class="ss-tag ss-tag-green"><span class="dashicons dashicons-admin-site-alt3"></span> Solar</span>
                        <?php endif; ?>
                        <?php if ($lead['ev'] === 'Y'): ?>
                            <span class="ss-tag ss-tag-green"><span class="dashicons dashicons-admin-site-alt3"></span> EV Charging</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- KEY CONTACTS -->
            <div class="ss-panel">
                <h3 class="ss-panel-title">Key Contacts</h3>
                <div class="ss-contacts-grid">
                    <?php foreach ($lead['contacts'] as $contact): ?>
                        <div class="ss-contact-card" data-email="<?php echo esc_attr($contact['email']); ?>" data-phone="<?php echo esc_attr($contact['phone']); ?>">
                            <div class="ss-contact-avatar">
                                <?php echo esc_html(strtoupper(substr($contact['name'], 0, 1))); ?>
                            </div>
                            <div class="ss-contact-info">
                                <strong class="ss-contact-name"><?php echo esc_html($contact['name']); ?></strong>
                                <span class="ss-contact-title"><?php echo esc_html($contact['title']); ?></span>
                                <?php if (!empty($contact['email'])): ?>
                                    <a href="mailto:<?php echo esc_attr($contact['email']); ?>" class="ss-contact-link">
                                        <span class="dashicons dashicons-email-alt"></span> <?php echo esc_html($contact['email']); ?>
                                    </a>
                                <?php endif; ?>
                                <?php if (!empty($contact['phone'])): ?>
                                    <a href="tel:<?php echo esc_attr($contact['phone']); ?>" class="ss-contact-link">
                                        <span class="dashicons dashicons-phone"></span> <?php echo esc_html($contact['phone']); ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                            <div class="ss-contact-actions">
                                <button class="ss-btn-icon" data-action="send-phone" title="Send to Phone">
                                    <span class="dashicons dashicons-smartphone"></span>
                                </button>
                                <button class="ss-btn-icon" data-action="add-contacts" title="Add to Contacts">
                                    <span class="dashicons dashicons-admin-users"></span>
                                </button>
                                <button class="ss-btn-icon" data-action="compose-email" title="Compose Email">
                                    <span class="dashicons dashicons-email"></span>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- BIDDING & PROCUREMENT -->
            <div class="ss-panel">
                <h3 class="ss-panel-title">Bidding &amp; Procurement</h3>
                <div class="ss-permit-grid">
                    <div class="ss-permit-item">
                        <span class="ss-permit-label">Bid Method</span>
                        <span class="ss-permit-value"><?php echo esc_html($lead['bidding_info']['method']); ?></span>
                    </div>
                    <div class="ss-permit-item">
                        <span class="ss-permit-label">Prequalification</span>
                        <span class="ss-permit-value"><?php echo esc_html($lead['bidding_info']['prequalification']); ?></span>
                    </div>
                    <div class="ss-permit-item">
                        <span class="ss-permit-label">Bid Deadline</span>
                        <span class="ss-permit-value"><?php echo esc_html($lead['bidding_info']['bid_deadline']); ?></span>
                    </div>
                    <div class="ss-permit-item">
                        <span class="ss-permit-label">Sub Selection</span>
                        <span class="ss-permit-value"><?php echo esc_html($lead['bidding_info']['sub_selection']); ?></span>
                    </div>
                </div>
            </div>

            <!-- ACTION BAR -->
            <div class="ss-action-bar">
                <button class="ss-btn ss-btn-primary" data-action="follow-lead">
                    <span class="dashicons dashicons-star-empty"></span> Follow Lead
                </button>
                <button class="ss-btn ss-btn-secondary" data-action="discuss-ai">
                    <span class="dashicons dashicons-format-chat"></span> Discuss with AI
                </button>
                <button class="ss-btn ss-btn-danger" data-action="trash-lead" data-lead-id="<?php echo esc_attr($lead['id']); ?>" data-source-id="<?php echo esc_attr($lead['source_id']); ?>">
                    <span class="dashicons dashicons-trash"></span> Trash
                </button>
            </div>

            <!-- AI INSIGHTS -->
            <?php if (!empty($lead['knowledge']) || !empty($lead['ai_suggestions'])): ?>
                <div class="ss-panel">
                    <h3 class="ss-panel-title">Intelligence &amp; AI Insights</h3>
                    <?php if (!empty($lead['knowledge'])): ?>
                        <ul class="ss-insight-list">
                            <?php foreach ($lead['knowledge'] as $insight): ?>
                                <li class="ss-insight-item">
                                    <span class="dashicons dashicons-lightbulb"></span>
                                    <span><?php echo esc_html($insight); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if (!empty($lead['ai_suggestions'])): ?>
                        <div class="ss-suggestions">
                            <?php foreach ($lead['ai_suggestions'] as $suggestion): ?>
                                <div class="ss-suggestion">
                                    <span class="dashicons dashicons-yes-alt"></span>
                                    <p><?php echo esc_html($suggestion); ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- TIER 2 DEEP DIVE -->
            <div class="ss-tier2-action">
                <button class="btn-tier2-dive" data-action="launch-deep-dive" data-lead-id="<?php echo esc_attr($lead['id']); ?>">
                    <span class="ss-tier2-icon">
                        <span class="dashicons dashicons-admin-site-alt3"></span>
                        <span class="ss-tier2-badge">T2</span>
                    </span>
                    <span class="ss-tier2-text">
                        <strong>Launch Tier 2 Deep Dive</strong>
                        <span>Map full network &bull; Investigate all connections</span>
                    </span>
                    <span class="dashicons dashicons-arrow-right-alt2"></span>
                </button>
            </div>

        </div>
    <?php
    }

    /**
     * Render investigation section as flagship intelligence brief
     * Features 3D panels, company logo placeholder, decision maker cards
     */
    private function render_investigation_hero($lead)
    {
        $inv_status = $lead['investigation_status'] ?? 'pending';
        $has_profiles = !empty($lead['party_profiles']) && is_array($lead['party_profiles']);
    ?>
        <div class="ss-intel-brief ss-inv-<?php echo esc_attr($inv_status); ?> <?php echo $has_profiles ? 'ss-has-intel' : ''; ?>">
            <div class="ss-intel-header">
                <div class="ss-intel-title-row">
                    <h3 class="ss-panel-title"><span class="dashicons dashicons-visibility"></span> Intelligence Brief</h3>
                    <span class="ss-inv-badge ss-inv-badge-<?php echo esc_attr($inv_status); ?>">
                        <?php echo esc_html(ucfirst($inv_status)); ?>
                    </span>
                </div>

                <?php if ($inv_status === 'pending' || $inv_status === 'failed'): ?>
                    <div class="ss-intel-cta">
                        <button class="ss-btn ss-btn-primary btn-investigate btn-hero-cta"
                            data-action="investigate-parties"
                            data-candidate-id="<?php echo esc_attr($lead['id']); ?>"
                            data-source-id="<?php echo esc_attr($lead['source_id']); ?>">
                            <span class="dashicons dashicons-search"></span>
                            <?php _e('Run Intelligence Scan', 'raw-wire-dashboard'); ?>
                        </button>
                        <span class="ss-intel-hint"><?php _e('AI-powered research on contractors, owners, and key parties', 'raw-wire-dashboard'); ?></span>
                    </div>
                <?php elseif ($inv_status === 'completed' || $inv_status === 'no_parties_found'): ?>
                    <div class="ss-intel-cta ss-intel-cta-rerun">
                        <button class="ss-btn ss-btn-ghost" data-action="reset-investigation" data-source-id="<?php echo esc_attr($lead['source_id']); ?>">
                            <span class="dashicons dashicons-image-rotate"></span>
                            <?php _e('Reset &amp; Re-run Investigation', 'raw-wire-dashboard'); ?>
                        </button>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($has_profiles): ?>
                <?php foreach ($lead['party_profiles'] as $party_type => $party_data): ?>
                    <?php $profile = $party_data['profile'] ?? []; ?>
                    <div class="ss-intel-party">

                        <!-- COMPANY IDENTITY with logo placeholder -->
                        <div class="ss-intel-identity">
                            <div class="ss-company-logo">
                                <?php
                                $company_name = $profile['company']['name'] ?? $party_data['name'] ?? 'Unknown';
                                $initials = '';
                                $words = explode(' ', $company_name);
                                foreach (array_slice($words, 0, 2) as $word) {
                                    $initials .= strtoupper(substr($word, 0, 1));
                                }
                                ?>
                                <span class="ss-logo-initials"><?php echo esc_html($initials); ?></span>
                                <span class="ss-logo-label">Company Logo</span>
                            </div>
                            <div class="ss-company-meta">
                                <span class="ss-party-badge"><?php echo esc_html(ucfirst($party_type)); ?></span>
                                <h4 class="ss-company-name"><?php echo esc_html($party_data['name'] ?? 'Unknown'); ?></h4>
                                <?php if (!empty($profile['value_score'])): ?>
                                    <span class="ss-value-score score-<?php echo $profile['value_score'] >= 70 ? 'high' : ($profile['value_score'] >= 40 ? 'med' : 'low'); ?>">
                                        <?php echo esc_html($profile['value_score']); ?>/100
                                    </span>
                                <?php endif; ?>
                                <?php if (!empty($profile['target_summary'])): ?>
                                    <p class="ss-target-summary"><?php echo esc_html($profile['target_summary']); ?></p>
                                <?php endif; ?>
                                <?php if (!empty($profile['company'])): ?>
                                    <div class="ss-company-tags">
                                        <?php if (!empty($profile['company']['type'])): ?>
                                            <span class="ss-tag"><?php echo esc_html($profile['company']['type']); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($profile['company']['scale'])): ?>
                                            <span class="ss-tag"><?php echo esc_html(ucfirst($profile['company']['scale'])); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($profile['company']['specialties'])): ?>
                                            <?php foreach ((array)$profile['company']['specialties'] as $spec): ?>
                                                <span class="ss-tag ss-tag-spec"><?php echo esc_html($spec); ?></span>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($profile['company']['notable_facts'])): ?>
                                        <ul class="ss-facts-list">
                                            <?php foreach ((array)$profile['company']['notable_facts'] as $fact): ?>
                                                <li><?php echo esc_html($fact); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- DECISION MAKERS -->
                        <?php if (!empty($profile['people'])): ?>
                            <div class="ss-intel-section">
                                <h5 class="ss-section-title"><span class="dashicons dashicons-businessperson"></span> Decision Makers</h5>
                                <div class="ss-dm-grid">
                                    <?php foreach ($profile['people'] as $person): ?>
                                        <div class="ss-dm-card">
                                            <div class="ss-dm-avatar">
                                                <?php echo esc_html(strtoupper(substr($person['name'] ?? '?', 0, 1))); ?>
                                            </div>
                                            <div class="ss-dm-info">
                                                <strong class="ss-dm-name"><?php echo esc_html($person['name'] ?? 'Unknown'); ?></strong>
                                                <?php if (!empty($person['title'])): ?>
                                                    <span class="ss-dm-title"><?php echo esc_html($person['title']); ?></span>
                                                <?php endif; ?>
                                                <?php if (!empty($person['authority'])): ?>
                                                    <span class="ss-dm-authority"><?php echo esc_html(ucfirst($person['authority'])); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (!empty($person['contact'])): ?>
                                                <div class="ss-dm-contact">
                                                    <?php if (!empty($person['contact']['email'])): ?>
                                                        <a href="mailto:<?php echo esc_attr($person['contact']['email']); ?>" class="ss-dm-link">
                                                            <span class="dashicons dashicons-email-alt"></span> <?php echo esc_html($person['contact']['email']); ?>
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if (!empty($person['contact']['phone'])): ?>
                                                        <a href="tel:<?php echo esc_attr($person['contact']['phone']); ?>" class="ss-dm-link">
                                                            <span class="dashicons dashicons-phone"></span> <?php echo esc_html($person['contact']['phone']); ?>
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if (!empty($person['contact']['linkedin'])): ?>
                                                        <a href="<?php echo esc_url($person['contact']['linkedin']); ?>" target="_blank" class="ss-dm-link ss-dm-linkedin">
                                                            <span class="dashicons dashicons-linkedin"></span> LinkedIn
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($person['notes'])): ?>
                                                <p class="ss-dm-notes"><?php echo esc_html($person['notes']); ?></p>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- OUTREACH STRATEGY -->
                        <?php if (!empty($profile['outreach_strategy']) || !empty($profile['entry_points'])): ?>
                            <div class="ss-intel-section ss-intel-outreach">
                                <h5 class="ss-section-title"><span class="dashicons dashicons-megaphone"></span> Recommended Approach</h5>
                                <?php if (!empty($profile['outreach_strategy'])): ?>
                                    <p class="ss-outreach-text"><?php echo esc_html($profile['outreach_strategy']); ?></p>
                                <?php endif; ?>
                                <?php if (!empty($profile['entry_points'])): ?>
                                    <ul class="ss-entry-points">
                                        <?php foreach ((array)$profile['entry_points'] as $entry): ?>
                                            <li><span class="dashicons dashicons-yes-alt"></span> <?php echo esc_html($entry); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <!-- NETWORKING OPPORTUNITIES -->
                        <?php if (!empty($profile['networking_opportunities'])): ?>
                            <div class="ss-intel-section">
                                <h5 class="ss-section-title"><span class="dashicons dashicons-groups"></span> Networking Opportunities</h5>
                                <?php $net = $profile['networking_opportunities']; ?>
                                <div class="ss-networking">
                                    <?php if (!empty($net['associations'])): ?>
                                        <div class="ss-net-group">
                                            <?php foreach ((array)$net['associations'] as $assoc): ?>
                                                <div class="ss-assoc-item">
                                                    <strong><?php echo esc_html(is_array($assoc) ? ($assoc['name'] ?? 'Association') : $assoc); ?></strong>
                                                    <?php if (is_array($assoc) && !empty($assoc['events'])): ?>
                                                        <ul class="ss-assoc-events">
                                                            <?php foreach ((array)$assoc['events'] as $event): ?>
                                                                <li><?php echo esc_html(is_array($event) ? ($event['name'] ?? $event['event'] ?? json_encode($event)) : $event); ?></li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($net['upcoming_events'])): ?>
                                        <div class="ss-net-events">
                                            <?php foreach ((array)$net['upcoming_events'] as $event): ?>
                                                <div class="ss-event-chip">
                                                    <span class="dashicons dashicons-calendar-alt"></span>
                                                    <span><?php echo esc_html(is_array($event) ? ($event['event'] ?? $event['name'] ?? '') : $event); ?></span>
                                                    <?php if (is_array($event) && !empty($event['date'])): ?>
                                                        <span class="ss-event-date"><?php echo esc_html($event['date']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($net['charity_involvement'])): ?>
                                        <p class="ss-charity">Charity: <?php echo esc_html(implode(', ', (array)$net['charity_involvement'])); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- DISCOVERED PROJECTS -->
                        <?php if (!empty($profile['discovered_projects'])): ?>
                            <div class="ss-intel-section">
                                <h5 class="ss-section-title"><span class="dashicons dashicons-clipboard"></span> Related Projects</h5>
                                <div class="ss-discovered-grid">
                                    <?php foreach ((array)$profile['discovered_projects'] as $proj): ?>
                                        <div class="ss-discovered-item">
                                            <strong><?php echo esc_html($proj['name'] ?? 'Project'); ?></strong>
                                            <?php if (!empty($proj['status'])): ?>
                                                <span class="ss-tag ss-tag-status"><?php echo esc_html($proj['status']); ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($proj['value'])): ?>
                                                <span class="ss-discovered-value"><?php echo esc_html($proj['value']); ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($proj['location'])): ?>
                                                <span class="ss-discovered-loc"><?php echo esc_html($proj['location']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- DISCOVERED ENTITIES -->
                        <?php if (!empty($profile['discovered_entities'])): ?>
                            <div class="ss-intel-section">
                                <h5 class="ss-section-title"><span class="dashicons dashicons-admin-links"></span> Related Entities</h5>
                                <ul class="ss-entities-list">
                                    <?php foreach ((array)$profile['discovered_entities'] as $entity): ?>
                                        <li>
                                            <strong><?php echo esc_html($entity['name'] ?? 'Entity'); ?></strong>
                                            <?php if (!empty($entity['type'])): ?>
                                                <span class="ss-entity-type">(<?php echo esc_html($entity['type']); ?>)</span>
                                            <?php endif; ?>
                                            <?php if (!empty($entity['relationship'])): ?>
                                                &mdash; <?php echo esc_html($entity['relationship']); ?>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <!-- RED FLAGS -->
                        <?php if (!empty($profile['red_flags'])): ?>
                            <div class="ss-intel-section ss-intel-warning">
                                <h5 class="ss-section-title ss-title-danger"><span class="dashicons dashicons-warning"></span> Red Flags</h5>
                                <ul class="ss-red-flags">
                                    <?php foreach ((array)$profile['red_flags'] as $flag): ?>
                                        <li><span class="dashicons dashicons-dismiss"></span> <?php echo esc_html($flag); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <!-- INTELLIGENCE GAPS -->
                        <?php if (!empty($profile['intelligence_gaps'])): ?>
                            <div class="ss-intel-section ss-intel-gaps">
                                <h5 class="ss-section-title"><span class="dashicons dashicons-editor-help"></span> Intelligence Gaps</h5>
                                <ul class="ss-gaps-list">
                                    <?php foreach ((array)$profile['intelligence_gaps'] as $gap): ?>
                                        <li><?php echo esc_html($gap); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <!-- BONUS INTEL -->
                        <?php if (!empty($profile['bonus_intel'])): ?>
                            <div class="ss-intel-section ss-intel-bonus">
                                <h5 class="ss-section-title"><span class="dashicons dashicons-star-filled"></span> Bonus Intelligence</h5>
                                <p><?php echo esc_html($profile['bonus_intel']); ?></p>
                            </div>
                        <?php endif; ?>

                        <!-- SOURCES -->
                        <?php if (!empty($profile['sources'])): ?>
                            <div class="ss-intel-sources">
                                <span class="ss-sources-label">Sources:</span>
                                <?php echo esc_html(implode(' | ', array_slice((array)$profile['sources'], 0, 5))); ?>
                            </div>
                        <?php endif; ?>

                    </div>
                <?php endforeach; ?>

                <!-- RE-RUN INVESTIGATION -->
                <div class="ss-intel-rerun">
                    <button class="ss-btn ss-btn-ghost" data-action="reset-investigation" data-source-id="<?php echo esc_attr($lead['source_id']); ?>">
                        <span class="dashicons dashicons-image-rotate"></span>
                        <?php _e('Re-run Investigation', 'raw-wire-dashboard'); ?>
                    </button>
                </div>
            <?php endif; ?>

            <?php if (!$has_profiles && $inv_status !== 'pending' && $inv_status !== 'failed'): ?>
                <!-- Investigation ran but no profiles — show both re-run and launch buttons -->
                <div class="ss-intel-cta">
                    <p class="ss-intel-empty-note">
                        <?php _e('Investigation completed but no party profiles were extracted.', 'raw-wire-dashboard'); ?>
                    </p>
                    <button class="ss-btn ss-btn-ghost" data-action="reset-investigation" data-source-id="<?php echo esc_attr($lead['source_id']); ?>">
                        <span class="dashicons dashicons-image-rotate"></span>
                        <?php _e('Clear &amp; Re-run Investigation', 'raw-wire-dashboard'); ?>
                    </button>
                </div>
            <?php endif; ?>
        </div>
<?php
    }

    /**
     * Send contact to phone
     */
    public function ajax_send_to_phone()
    {
        check_ajax_referer('rawwire_soothsayer', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'), 403);
        }

        $contact = array(
            'name' => sanitize_text_field($_POST['name'] ?? ''),
            'phone' => sanitize_text_field($_POST['phone'] ?? ''),
            'email' => sanitize_email($_POST['email'] ?? ''),
        );

        // TODO: Integrate with OpenClaw to send via SMS/notification
        // For now, simulate success

        wp_send_json_success(array(
            'message' => 'Contact sent to your phone',
            'contact' => $contact,
        ));
    }

    /**
     * Add to Google Contacts
     */
    public function ajax_add_to_contacts()
    {
        check_ajax_referer('rawwire_soothsayer', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'), 403);
        }

        $contact = array(
            'name' => sanitize_text_field($_POST['name'] ?? ''),
            'email' => sanitize_email($_POST['email'] ?? ''),
            'phone' => sanitize_text_field($_POST['phone'] ?? ''),
            'company' => sanitize_text_field($_POST['company'] ?? ''),
            'title' => sanitize_text_field($_POST['title'] ?? ''),
        );

        // TODO: Integrate with Google People API

        wp_send_json_success(array(
            'message' => 'Contact added to Google Contacts',
            'contact' => $contact,
        ));
    }

    /**
     * Compose email (returns template)
     */
    public function ajax_compose_email()
    {
        check_ajax_referer('rawwire_soothsayer', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'), 403);
        }

        $to = sanitize_email($_POST['email'] ?? '');
        $name = sanitize_text_field($_POST['name'] ?? '');
        $context = sanitize_text_field($_POST['context'] ?? '');

        // TODO: Generate AI draft based on context

        wp_send_json_success(array(
            'to' => $to,
            'subject' => "Introduction - {$context}",
            'body' => "Dear {$name},\n\nI hope this email finds you well...",
        ));
    }

    /**
     * Add calendar event
     */
    public function ajax_add_calendar_event()
    {
        check_ajax_referer('rawwire_soothsayer', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'), 403);
        }

        $event = array(
            'name' => sanitize_text_field($_POST['event_name'] ?? ''),
            'date' => sanitize_text_field($_POST['date'] ?? ''),
            'location' => sanitize_text_field($_POST['location'] ?? ''),
            'reminder' => sanitize_text_field($_POST['reminder'] ?? '1_day'),
        );

        // TODO: Integrate with Google Calendar API

        wp_send_json_success(array(
            'message' => 'Event added to calendar with reminder',
            'event' => $event,
        ));
    }

    /**
     * Follow lead - adds to followed items and schedules 48-hour OpenClaw web checks
     */
    public function ajax_follow_lead()
    {
        check_ajax_referer('rawwire_soothsayer', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'), 403);
        }

        $lead_id = intval($_POST['lead_id'] ?? 0);
        $follow_type = sanitize_text_field($_POST['follow_type'] ?? 'project'); // project or contact
        $user_id = get_current_user_id();

        if (!$lead_id) {
            wp_send_json_error(array('message' => 'Invalid lead ID'));
        }

        // Get followed items from options
        $followed_items = get_option('rawwire_followed_items', array());
        $item_key = "{$follow_type}_{$lead_id}";

        // Check if already following
        if (isset($followed_items[$item_key])) {
            // Unfollow
            unset($followed_items[$item_key]);
            update_option('rawwire_followed_items', $followed_items);

            // Reschedule cron if no items left
            if (empty($followed_items)) {
                wp_clear_scheduled_hook('rawwire_follow_check_cron');
            }

            wp_send_json_success(array(
                'message' => 'Stopped following this ' . $follow_type,
                'lead_id' => $lead_id,
                'following' => false,
            ));
            return;
        }

        // Get lead details for storage
        global $wpdb;
        $cand_table = $wpdb->prefix . 'rawwire_lead_candidates';
        $src_table  = $wpdb->prefix . 'rawwire_lead_sources';
        $lead = $wpdb->get_row($wpdb->prepare(
            "SELECT c.id, c.permit_nbr, c.contractor_name, c.work_desc, c.primary_address,
                    s.party_profiles
             FROM {$cand_table} c
             LEFT JOIN {$src_table} s ON s.id = c.source_id
             WHERE c.id = %d",
            $lead_id
        ), ARRAY_A);

        if (!$lead) {
            wp_send_json_error(array('message' => 'Lead not found'));
        }

        // Build follow record
        $follow_record = array(
            'lead_id' => $lead_id,
            'type' => $follow_type,
            'user_id' => $user_id,
            'followed_at' => current_time('mysql'),
            'last_checked' => null,
            'permit_number' => $lead['permit_nbr'],
            'contractor_name' => $lead['contractor_name'],
            'project_name' => $this->build_project_name($lead),
            'search_terms' => $this->build_follow_search_terms($lead, $follow_type),
            'check_count' => 0,
            'findings' => array(),
        );

        // Add to followed items
        $followed_items[$item_key] = $follow_record;
        update_option('rawwire_followed_items', $followed_items);

        // Ensure 48-hour cron is scheduled
        $this->schedule_follow_cron();

        wp_send_json_success(array(
            'message' => 'Now following this ' . $follow_type . '. OpenClaw will check for updates every 48 hours.',
            'lead_id' => $lead_id,
            'following' => true,
            'next_check' => $this->get_next_follow_check_time(),
        ));
    }

    /**
     * Build search terms for follow monitoring
     */
    private function build_follow_search_terms(array $lead, string $type): array
    {
        $terms = array();

        if ($type === 'contact' && !empty($lead['contractor_name'])) {
            // For contact follows, search for the company/person
            $terms[] = $lead['contractor_name'];
            $terms[] = $lead['contractor_name'] . ' construction';
        } else {
            // For project follows, search for permit and address
            if (!empty($lead['permit_nbr'])) {
                $terms[] = 'permit ' . $lead['permit_nbr'];
            }
            if (!empty($lead['primary_address'])) {
                $terms[] = $lead['primary_address'] . ' construction';
            }
            if (!empty($lead['work_desc'])) {
                $short_desc = substr($lead['work_desc'], 0, 50);
                $terms[] = $short_desc . ' project';
            }
        }

        return array_filter($terms);
    }

    /**
     * Schedule the 48-hour follow check cron
     */
    private function schedule_follow_cron()
    {
        // Register 48-hour interval if not exists
        add_filter('cron_schedules', function ($schedules) {
            if (!isset($schedules['fortyeight_hours'])) {
                $schedules['fortyeight_hours'] = array(
                    'interval' => 48 * 60 * 60, // 48 hours
                    'display' => __('Every 48 hours'),
                );
            }
            return $schedules;
        });

        // Schedule if not already scheduled
        if (!wp_next_scheduled('rawwire_follow_check_cron')) {
            wp_schedule_event(time() + (48 * 60 * 60), 'fortyeight_hours', 'rawwire_follow_check_cron');
        }
    }

    /**
     * Get next follow check time
     */
    private function get_next_follow_check_time(): string
    {
        $next = wp_next_scheduled('rawwire_follow_check_cron');
        if ($next) {
            return date('Y-m-d H:i:s', $next);
        }
        return 'Not scheduled';
    }

    /**
     * Run follow check cron - searches web for updates on followed items
     * This is called by WP-Cron every 48 hours
     */
    public function cron_follow_check()
    {
        $followed_items = get_option('rawwire_followed_items', array());

        if (empty($followed_items)) {
            return;
        }

        // Load OpenClaw adapter
        $openclaw_path = WP_PLUGIN_DIR . '/raw-wire-dashboard/cores/lead-generator/class-openclaw-adapter.php';
        if (!file_exists($openclaw_path)) {
            rawwire_log('soothsayer', 'OpenClaw adapter not found for follow checks', 'error');
            return;
        }

        require_once WP_PLUGIN_DIR . '/raw-wire-dashboard/cores/lead-generator/class-search-provider.php';
        require_once WP_PLUGIN_DIR . '/raw-wire-dashboard/cores/lead-generator/class-duckduckgo-provider.php';
        require_once $openclaw_path;

        $adapter = new RawWire_OpenClaw_Adapter();
        $updated = false;

        foreach ($followed_items as $key => &$item) {
            $search_terms = $item['search_terms'] ?? array();

            if (empty($search_terms)) {
                continue;
            }

            // Use the first search term for now
            $query = $search_terms[0];

            try {
                if ($adapter->is_available()) {
                    $results = $adapter->search($query);

                    // Store findings
                    $item['last_checked'] = current_time('mysql');
                    $item['check_count'] = ($item['check_count'] ?? 0) + 1;

                    if (!empty($results)) {
                        // Append new findings (keep last 10)
                        $item['findings'][] = array(
                            'checked_at' => current_time('mysql'),
                            'query' => $query,
                            'result_count' => count($results),
                            'summary' => is_array($results) ? implode("\n", array_slice($results, 0, 3)) : substr($results, 0, 500),
                        );
                        $item['findings'] = array_slice($item['findings'], -10);
                    }

                    $updated = true;

                    rawwire_log('soothsayer', "Follow check for {$key}: found " . count($results) . " results", 'info');
                }
            } catch (Exception $e) {
                rawwire_log('soothsayer', "Follow check error for {$key}: " . $e->getMessage(), 'error');
            }

            // Small delay between queries to be respectful
            usleep(500000); // 0.5 seconds
        }

        if ($updated) {
            update_option('rawwire_followed_items', $followed_items);
        }
    }

    /**
     * Get list of followed items for current user
     */
    public function get_followed_items(): array
    {
        $followed_items = get_option('rawwire_followed_items', array());
        $user_id = get_current_user_id();

        // Filter to current user's followed items
        return array_filter($followed_items, function ($item) use ($user_id) {
            return ($item['user_id'] ?? 0) === $user_id;
        });
    }

    /**
     * Check if a lead is being followed
     */
    public function is_following(int $lead_id, string $type = 'project'): bool
    {
        $followed_items = get_option('rawwire_followed_items', array());
        $item_key = "{$type}_{$lead_id}";
        return isset($followed_items[$item_key]);
    }

    /**
     * Discuss with AI - Routes through OpenClaw/Venice for intelligent responses
     */
    public function ajax_discuss_with_ai()
    {
        check_ajax_referer('rawwire_soothsayer', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'), 403);
        }

        $message = sanitize_textarea_field($_POST['message'] ?? '');
        $lead_id = intval($_POST['lead_id'] ?? 0);
        $context = sanitize_textarea_field($_POST['context'] ?? '');

        if (empty($message)) {
            wp_send_json_error(array('message' => 'Please enter a message'));
        }

        // Build context from lead data if available
        $lead_context = '';
        if ($lead_id) {
            global $wpdb;
            $cand_table = $wpdb->prefix . 'rawwire_lead_candidates';
            $src_table  = $wpdb->prefix . 'rawwire_lead_sources';
            $lead = $wpdb->get_row($wpdb->prepare(
                "SELECT c.*, s.owner_name, s.party_profiles, s.community_plan_area
                 FROM {$cand_table} c
                 LEFT JOIN {$src_table} s ON s.id = c.source_id
                 WHERE c.id = %d",
                $lead_id
            ), ARRAY_A);

            if ($lead) {
                $lead_context = $this->build_ai_lead_context($lead);
            }
        }

        // Try OpenClaw first
        $response = $this->query_openclaw_ai($message, $lead_context, $context);

        if ($response['success']) {
            wp_send_json_success(array(
                'response' => $response['content'],
                'source' => $response['source'],
            ));
        }

        // Fallback to intelligent local response
        $fallback = $this->generate_intelligent_fallback($message, $lead_context);
        wp_send_json_success(array(
            'response' => $fallback,
            'source' => 'local',
        ));
    }

    /**
     * Build AI context from lead data
     */
    private function build_ai_lead_context(array $lead): string
    {
        $context = "## Lead Intelligence Context\n\n";

        if (!empty($lead['permit_nbr'])) {
            $context .= "- Permit: {$lead['permit_nbr']}\n";
        }
        if (!empty($lead['work_desc'])) {
            $context .= "- Project: " . substr($lead['work_desc'], 0, 200) . "\n";
        }
        if (!empty($lead['primary_address'])) {
            $context .= "- Location: {$lead['primary_address']}\n";
        }
        if (!empty($lead['use_desc'])) {
            $context .= "- Use Type: {$lead['use_desc']}\n";
        }
        if (!empty($lead['permit_type'])) {
            $context .= "- Permit Type: {$lead['permit_type']}\n";
        }
        if (!empty($lead['valuation']) && $lead['valuation'] > 0) {
            $context .= "- Valuation: $" . number_format(floatval($lead['valuation'])) . "\n";
        }
        if (!empty($lead['contractor_name'])) {
            $context .= "- Contractor: {$lead['contractor_name']}\n";
        }
        if (!empty($lead['applicant_name'])) {
            $context .= "- Applicant: {$lead['applicant_name']}\n";
        }
        if (!empty($lead['owner_name'])) {
            $context .= "- Owner: {$lead['owner_name']}\n";
        }

        // Add party profiles if available
        if (!empty($lead['party_profiles'])) {
            $profiles = json_decode($lead['party_profiles'], true);
            if (is_array($profiles) && !empty($profiles)) {
                $context .= "\n### Investigated Contacts\n";
                foreach (array_slice($profiles, 0, 3) as $party) {
                    $name = $party['name'] ?? 'Unknown';
                    $role = $party['role'] ?? 'Contact';
                    $context .= "- {$name} ({$role})";
                    if (!empty($party['company'])) {
                        $context .= " at {$party['company']}";
                    }
                    $context .= "\n";
                }
            }
        }

        return $context;
    }

    /**
     * Query OpenClaw AI for intelligent response
     */
    private function query_openclaw_ai(string $message, string $lead_context, string $additional_context): array
    {
        // Load OpenClaw adapter
        $adapter_path = dirname(__FILE__) . '/../cores/lead-generator/class-openclaw-adapter.php';
        if (!file_exists($adapter_path)) {
            return array('success' => false, 'error' => 'OpenClaw adapter not found');
        }

        require_once dirname(__FILE__) . '/../cores/lead-generator/class-search-provider.php';
        require_once dirname(__FILE__) . '/../cores/lead-generator/class-duckduckgo-provider.php';
        require_once $adapter_path;

        $adapter = new RawWire_OpenClaw_Adapter();

        if (!$adapter->is_available()) {
            return array('success' => false, 'error' => 'OpenClaw not available');
        }

        // Build system prompt
        $system_prompt = "You are Soothsayer, an AI business intelligence advisor for commercial construction leads. ";
        $system_prompt .= "You help contractors identify opportunities, evaluate leads, and develop outreach strategies. ";
        $system_prompt .= "Be concise, actionable, and professional. Focus on concrete next steps.";

        if (!empty($lead_context)) {
            $system_prompt .= "\n\n" . $lead_context;
        }

        if (!empty($additional_context)) {
            $system_prompt .= "\n\nAdditional context: " . $additional_context;
        }

        try {
            // Use reflection or public method to access chat
            $response = $this->call_openclaw_chat($adapter, $system_prompt, $message);

            if (!empty($response)) {
                return array(
                    'success' => true,
                    'content' => $response,
                    'source' => 'openclaw',
                );
            }
        } catch (Exception $e) {
            rawwire_log('soothsayer', 'OpenClaw AI error: ' . $e->getMessage(), 'error');
        }

        return array('success' => false, 'error' => 'Query failed');
    }

    /**
     * Call OpenClaw chat API directly
     */
    private function call_openclaw_chat($adapter, string $system_prompt, string $user_message): string
    {
        // Get settings for direct API call
        $settings = get_option('rawwire_party_investigator_settings', array());
        $base_url = $settings['openclaw_base_url'] ?? 'http://172.17.76.22:18789/v1';
        $auth_token = $settings['openclaw_auth_token'] ?? 'rawwire-local-dev-2025';
        $model = $settings['openclaw_model'] ?? 'venice/olafangensan-glm-4.7-flash-heretic';

        $response = wp_remote_post(rtrim($base_url, '/') . '/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $auth_token,
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode(array(
                'model' => $model,
                'messages' => array(
                    array('role' => 'system', 'content' => $system_prompt),
                    array('role' => 'user', 'content' => $user_message),
                ),
                'max_tokens' => 1000,
                'temperature' => 0.7,
            )),
            'timeout' => 60,
        ));

        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($body['choices'][0]['message']['content'])) {
            return $body['choices'][0]['message']['content'];
        }

        return '';
    }

    /**
     * Generate intelligent fallback response when OpenClaw is unavailable
     */
    private function generate_intelligent_fallback(string $message, string $lead_context): string
    {
        $message_lower = strtolower($message);
        $response = "";

        // Keyword-based intelligent responses
        if (strpos($message_lower, 'approach') !== false || strpos($message_lower, 'contact') !== false) {
            $response = "## Outreach Strategy\n\n";
            $response .= "1. **Research first** - Review any party investigation data before reaching out\n";
            $response .= "2. **Warm intro** - Check if any mutual connections exist via LinkedIn\n";
            $response .= "3. **Lead with value** - Reference the specific project and how you can help\n";
            $response .= "4. **Time it right** - Contact early in the week, before 10 AM or after 2 PM\n";
            $response .= "5. **Follow up** - Schedule a reminder for 3-5 days if no response\n";
        } elseif (strpos($message_lower, 'qualify') !== false || strpos($message_lower, 'evaluate') !== false) {
            $response = "## Lead Qualification Criteria\n\n";
            $response .= "**High Priority Indicators:**\n";
            $response .= "- Valuation > $1M\n";
            $response .= "- Commercial or institutional use type\n";
            $response .= "- No contractor assigned yet\n";
            $response .= "- Recent filing date (within 30 days)\n\n";
            $response .= "**Red Flags:**\n";
            $response .= "- Permit on hold or expired\n";
            $response .= "- Very low valuation relative to scope\n";
            $response .= "- Residential conversion projects\n";
        } elseif (strpos($message_lower, 'bid') !== false || strpos($message_lower, 'prequalify') !== false) {
            $response = "## Bidding Strategy\n\n";
            $response .= "1. **Check deadlines** - Most public projects have strict submission windows\n";
            $response .= "2. **Review requirements** - Look for bonding, licensing, certifications needed\n";
            $response .= "3. **Site visit** - Schedule before bid deadline if possible\n";
            $response .= "4. **Competitive analysis** - Research who else might bid\n";
            $response .= "5. **Price strategy** - Balance competitiveness with margin requirements\n";
        } else {
            $response = "## Lead Analysis\n\n";
            $response .= "Based on the available intelligence:\n\n";
            $response .= "1. **Prioritize prequalification** if deadlines are approaching\n";
            $response .= "2. **Research the owner/developer** to understand their project history\n";
            $response .= "3. **Identify decision makers** via party investigation\n";
            $response .= "4. **Draft initial outreach** focusing on relevant experience\n\n";
            $response .= "*Note: AI assistance is currently in local mode. For more detailed analysis, ensure OpenClaw is connected.*";
        }

        return $response;
    }

    /**
     * Get events for calendar - pulls from permit data, followed items, and industry events
     */
    public function ajax_get_events()
    {
        check_ajax_referer('rawwire_soothsayer', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'), 403);
        }

        $month = intval($_POST['month'] ?? date('n'));
        $year = intval($_POST['year'] ?? date('Y'));

        $events = array();

        // Get permit-related events (filings, status changes)
        $events = array_merge($events, $this->get_permit_events($month, $year));

        // Get followed item check schedules
        $events = array_merge($events, $this->get_follow_check_events());

        // Get stored calendar events (user-created reminders)
        $events = array_merge($events, $this->get_user_calendar_events($month, $year));

        // Get industry events (static for now, could be from scraping later)
        $events = array_merge($events, $this->get_industry_events($month, $year));

        // Sort by date
        usort($events, function ($a, $b) {
            return strtotime($a['date']) - strtotime($b['date']);
        });

        wp_send_json_success(array('events' => $events));
    }

    /**
     * Get permit-related calendar events
     */
    private function get_permit_events(int $month, int $year): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'rawwire_lead_sources';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return array();
        }

        // Get permits filed this month
        $start_date = sprintf('%04d-%02d-01', $year, $month);
        $end_date = date('Y-m-t', strtotime($start_date));

        $permits = $wpdb->get_results($wpdb->prepare(
            "SELECT id, permit_nbr, work_desc, primary_address, zip_code, community_plan_area, submitted_date, permit_type, valuation
             FROM $table
             WHERE submitted_date BETWEEN %s AND %s
             ORDER BY submitted_date ASC
             LIMIT 20",
            $start_date,
            $end_date
        ), ARRAY_A);

        $events = array();
        foreach ($permits as $permit) {
            $events[] = array(
                'id' => 'permit_' . $permit['id'],
                'title' => $this->build_project_name($permit),
                'date' => $permit['submitted_date'],
                'type' => 'permit',
                'location' => $permit['community_plan_area'] ?? $permit['zip_code'] ?? 'Unknown',
                'permit_id' => $permit['id'],
                'details' => array(
                    'permit_number' => $permit['permit_nbr'] ?? '',
                    'permit_type' => $permit['permit_type'] ?? '',
                    'valuation' => $permit['valuation'] ?? 0,
                ),
            );
        }

        return $events;
    }

    /**
     * Get follow check events from scheduled cron and followed items
     */
    private function get_follow_check_events(): array
    {
        $events = array();
        $followed_items = get_option('rawwire_followed_items', array());

        if (empty($followed_items)) {
            return $events;
        }

        // Add next scheduled check as event
        $next_check = wp_next_scheduled('rawwire_follow_check_cron');
        if ($next_check) {
            $events[] = array(
                'id' => 'follow_check',
                'title' => 'Follow Check: ' . count($followed_items) . ' items',
                'date' => date('Y-m-d', $next_check),
                'type' => 'follow_check',
                'location' => 'Automated',
                'details' => array(
                    'item_count' => count($followed_items),
                    'next_run' => date('Y-m-d H:i:s', $next_check),
                ),
            );
        }

        // Add each followed item's last check as a reference
        foreach ($followed_items as $key => $item) {
            if (!empty($item['last_checked'])) {
                $events[] = array(
                    'id' => 'followed_' . $key,
                    'title' => 'Updated: ' . ($item['project_name'] ?? 'Followed Item'),
                    'date' => date('Y-m-d', strtotime($item['last_checked'])),
                    'type' => 'follow_update',
                    'location' => '',
                    'details' => array(
                        'findings_count' => count($item['findings'] ?? array()),
                        'check_count' => $item['check_count'] ?? 0,
                    ),
                );
            }
        }

        return $events;
    }

    /**
     * Get user-created calendar events (stored in options)
     */
    private function get_user_calendar_events(int $month, int $year): array
    {
        $all_events = get_option('rawwire_soothsayer_calendar_events', array());

        // Filter to month/year
        $filtered = array_filter($all_events, function ($event) use ($month, $year) {
            $event_date = strtotime($event['date'] ?? '');
            return date('n', $event_date) == $month && date('Y', $event_date) == $year;
        });

        return array_values($filtered);
    }

    /**
     * Get industry events (upcoming trade shows, deadlines, etc.)
     */
    private function get_industry_events(int $month, int $year): array
    {
        // Check for stored industry events
        $stored = get_option('rawwire_industry_events', array());
        if (!empty($stored)) {
            return array_filter($stored, function ($event) use ($month, $year) {
                $event_date = strtotime($event['date'] ?? '');
                return date('n', $event_date) == $month && date('Y', $event_date) == $year;
            });
        }

        // Fallback to some default Bay Area construction industry events
        $default_events = array(
            array(
                'id' => 'enr_awards',
                'title' => 'ENR California Best Projects Awards',
                'date' => $year . '-03-15',
                'type' => 'awards',
                'location' => 'SF Marriott Marquis',
            ),
            array(
                'id' => 'agc_spring',
                'title' => 'AGC Northern California Spring Meeting',
                'date' => $year . '-04-08',
                'type' => 'meeting',
                'location' => 'Oakland Convention Center',
            ),
            array(
                'id' => 'sf_build',
                'title' => 'SF Build Expo',
                'date' => $year . '-04-22',
                'type' => 'expo',
                'location' => 'Moscone Center',
            ),
            array(
                'id' => 'abc_conference',
                'title' => 'ABC Northern California Conference',
                'date' => $year . '-06-10',
                'type' => 'conference',
                'location' => 'San Jose McEnery Convention Center',
            ),
            array(
                'id' => 'enr_future',
                'title' => 'ENR FutureTech Conference',
                'date' => $year . '-09-18',
                'type' => 'conference',
                'location' => 'San Francisco',
            ),
        );

        return array_filter($default_events, function ($event) use ($month, $year) {
            $event_date = strtotime($event['date']);
            return date('n', $event_date) == $month && date('Y', $event_date) == $year;
        });
    }

    /**
     * Get confirmed project details - Real database lookup
     */
    public function ajax_get_confirmed_projects()
    {
        check_ajax_referer('rawwire_soothsayer', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'), 403);
        }

        $project_id = intval($_POST['project_id'] ?? 0);

        if (!$project_id) {
            wp_send_json_error(array('message' => 'Invalid project ID'));
        }

        global $wpdb;
        $candidates_table = $wpdb->prefix . 'rawwire_lead_candidates';
        $sources_table = $wpdb->prefix . 'rawwire_lead_sources';

        // Get project from candidates JOIN sources
        $lead = $wpdb->get_row($wpdb->prepare(
            "SELECT c.*, s.permit_nbr, s.permit_type, s.permit_sub_type, s.use_desc,
                    s.work_desc, s.primary_address, s.valuation, s.filing_date,
                    s.status AS permit_status, s.contractor_name, s.contractor_license,
                    s.owner_name, s.applicant_name, s.party_profiles,
                    c.score_composite, c.score_breakdown, c.score_reasoning,
                    c.candidate_status
             FROM $candidates_table c
             LEFT JOIN $sources_table s ON c.source_id = s.id
             WHERE c.id = %d",
            $project_id
        ), ARRAY_A);

        if (!$lead) {
            wp_send_json_error(array('message' => 'Project not found'));
        }

        // Use real AI composite score (0-10 → 0-100 for display)
        $score = round(floatval($lead['score_composite'] ?? 0) * 10, 1);

        // Build project object
        $project = array(
            'id' => $lead['id'],
            'name' => $this->build_project_name($lead),
            'gc' => $lead['contractor_name'] ?? 'Not Assigned',
            'gc_license' => $lead['contractor_license'] ?? '',
            'owner' => $lead['owner_name'] ?? '',
            'applicant' => $lead['applicant_name'] ?? '',
            'value' => floatval($lead['valuation'] ?? 0),
            'formatted_value' => '$' . number_format(floatval($lead['valuation'] ?? 0)),
            'address' => $this->build_location_string($lead),
            'permit_number' => $lead['permit_nbr'] ?? '',
            'permit_type' => $lead['permit_type'] ?? 'Building',
            'use_type' => $lead['use_desc'] ?? '',
            'status' => $this->parse_permit_status($lead['permit_status'] ?? ''),
            'filing_date' => $lead['filing_date'] ?? '',
            'score' => $score,
            'candidate_status' => $lead['candidate_status'] ?? 'scored_pending',
            'description' => $lead['work_desc'] ?? '',
        );

        // Get contacts from party_profiles
        $contacts = array();
        if (!empty($lead['party_profiles'])) {
            $profiles = json_decode($lead['party_profiles'], true);
            if (is_array($profiles)) {
                foreach ($profiles as $party_type => $party) {
                    // New format: party['profile']['people'] contains contacts
                    if (!empty($party['profile']['people'])) {
                        foreach ($party['profile']['people'] as $person) {
                            $email = $person['contact']['email'] ?? $person['contact']['email_pattern'] ?? '';
                            $contacts[] = array(
                                'name' => $person['name'] ?? 'Unknown',
                                'role' => $person['title'] ?? ucfirst($party_type),
                                'company' => $party['profile']['company']['name'] ?? $party['name'] ?? '',
                                'email' => $email,
                                'phone' => $person['contact']['phone'] ?? '',
                            );
                        }
                    } else {
                        // Legacy format
                        $contacts[] = array(
                            'name' => $party['name'] ?? 'Unknown',
                            'role' => $party['role'] ?? ($party['type'] ?? ucfirst($party_type)),
                            'company' => $party['company'] ?? '',
                            'email' => $party['email'] ?? '',
                            'phone' => $party['phone'] ?? '',
                        );
                    }
                }
            }
        }

        // Fallback to permit data contacts
        if (empty($contacts)) {
            if (!empty($lead['contractor_name'])) {
                $contacts[] = array(
                    'name' => $lead['contractor_name'],
                    'role' => 'Contractor',
                    'company' => $lead['contractor_name'],
                    'email' => '',
                    'phone' => '',
                );
            }
            if (!empty($lead['owner_name'])) {
                $contacts[] = array(
                    'name' => $lead['owner_name'],
                    'role' => 'Owner',
                    'company' => '',
                    'email' => '',
                    'phone' => '',
                );
            }
        }

        $project['contacts'] = $contacts;
        $project['contact_count'] = count($contacts);

        // Check if following
        $project['following'] = $this->is_following($project_id, 'project');

        wp_send_json_success(array('project' => $project));
    }

    /**
     * Probe Further - Create lead from discovered entity/project
     * Tier 1 investigation triggered for discovered network item
     */
    public function ajax_probe_further()
    {
        check_ajax_referer('rawwire_soothsayer', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'), 403);
        }

        global $wpdb;

        $type = sanitize_text_field($_POST['type'] ?? ''); // 'entity' or 'project'
        $item_id = intval($_POST['item_id'] ?? 0);
        $parent_lead_id = intval($_POST['parent_lead_id'] ?? 0);

        if (!in_array($type, array('entity', 'project')) || !$item_id) {
            wp_send_json_error(array('message' => 'Invalid parameters'));
        }

        $table_prefix = $wpdb->prefix . 'rawwire_';
        $leads_table = $table_prefix . 'leads';

        if ($type === 'entity') {
            // Get entity data
            $entity = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_prefix}network_entities WHERE id = %d",
                $item_id
            ), ARRAY_A);

            if (!$entity) {
                wp_send_json_error(array('message' => 'Entity not found'));
            }

            // Create lead from entity
            $lead_data = array(
                'lead_name' => $entity['entity_name'],
                'source_type' => 'network_discovery',
                'source_id' => 'probe_' . $entity['lead_id'],
                'party_type' => $entity['entity_type'],
                'confidence' => intval($entity['confidence']),
                'status' => 'pending_investigation',
                'metadata' => wp_json_encode(array(
                    'discovered_from_lead' => $parent_lead_id,
                    'relationship' => $entity['relationship'] ?? 'unknown',
                    'probed_at' => current_time('mysql'),
                )),
                'created_at' => current_time('mysql'),
            );

            $inserted = $wpdb->insert($leads_table, $lead_data);
            $new_lead_id = $wpdb->insert_id;

            // Mark entity as probed
            $wpdb->update(
                $table_prefix . 'network_entities',
                array('probed' => 1, 'probed_lead_id' => $new_lead_id),
                array('id' => $item_id)
            );
        } else {
            // Get project data
            $project = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_prefix}network_projects WHERE id = %d",
                $item_id
            ), ARRAY_A);

            if (!$project) {
                wp_send_json_error(array('message' => 'Project not found'));
            }

            // Create lead from project
            $lead_data = array(
                'lead_name' => $project['project_name'],
                'source_type' => 'project_discovery',
                'source_id' => 'probe_project_' . $project['lead_id'],
                'party_type' => 'project',
                'confidence' => intval($project['confidence']),
                'status' => 'pending_investigation',
                'metadata' => wp_json_encode(array(
                    'discovered_from_lead' => $parent_lead_id,
                    'estimated_value' => $project['estimated_value'] ?? null,
                    'location' => $project['location'] ?? null,
                    'probed_at' => current_time('mysql'),
                )),
                'created_at' => current_time('mysql'),
            );

            $inserted = $wpdb->insert($leads_table, $lead_data);
            $new_lead_id = $wpdb->insert_id;

            // Mark project as probed
            $wpdb->update(
                $table_prefix . 'network_projects',
                array('probed' => 1, 'probed_lead_id' => $new_lead_id),
                array('id' => $item_id)
            );
        }

        if (!$inserted) {
            wp_send_json_error(array('message' => 'Failed to create lead'));
        }

        // Schedule Tier 1 investigation for the new lead
        $lead_generator = \Raw_Wire_Dashboard\Cores\Lead_Generator\Lead_Generator::get_instance();
        if (method_exists($lead_generator, 'schedule_investigation')) {
            $lead_generator->schedule_investigation($new_lead_id, 1); // Tier 1
        }

        wp_send_json_success(array(
            'message' => 'Investigation queued',
            'lead_id' => $new_lead_id,
            'type' => $type,
        ));
    }

    /**
     * Launch Tier 2 Deep Dive
     * Recursive investigation of all discovered entities/projects
     */
    public function ajax_launch_deep_dive()
    {
        check_ajax_referer('rawwire_soothsayer', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'), 403);
        }

        global $wpdb;

        $lead_id = intval($_POST['lead_id'] ?? 0);

        if (!$lead_id) {
            wp_send_json_error(array('message' => 'Invalid lead ID'));
        }

        $table_prefix = $wpdb->prefix . 'rawwire_';

        // Get all unprobed entities for this lead
        $entities = $wpdb->get_results($wpdb->prepare(
            "SELECT id, entity_name FROM {$table_prefix}network_entities 
             WHERE lead_id = %d AND (probed = 0 OR probed IS NULL)",
            $lead_id
        ), ARRAY_A);

        // Get all unprobed projects for this lead
        $projects = $wpdb->get_results($wpdb->prepare(
            "SELECT id, project_name FROM {$table_prefix}network_projects 
             WHERE lead_id = %d AND (probed = 0 OR probed IS NULL)",
            $lead_id
        ), ARRAY_A);

        $queued = 0;
        $lead_generator = \Raw_Wire_Dashboard\Cores\Lead_Generator\Lead_Generator::get_instance();

        // Probe all entities
        foreach ($entities as $entity) {
            $_POST['type'] = 'entity';
            $_POST['item_id'] = $entity['id'];
            $_POST['parent_lead_id'] = $lead_id;

            // Direct insert to avoid recursion
            $lead_data = array(
                'lead_name' => $entity['entity_name'],
                'source_type' => 'tier2_deep_dive',
                'source_id' => 'dive_entity_' . $lead_id . '_' . $entity['id'],
                'party_type' => 'contractor',
                'status' => 'pending_investigation',
                'tier' => 2,
                'metadata' => wp_json_encode(array(
                    'parent_lead_id' => $lead_id,
                    'deep_dive_batch' => current_time('mysql'),
                )),
                'created_at' => current_time('mysql'),
            );

            $wpdb->insert($table_prefix . 'leads', $lead_data);
            $new_id = $wpdb->insert_id;

            if ($new_id && method_exists($lead_generator, 'schedule_investigation')) {
                $lead_generator->schedule_investigation($new_id, 2); // Tier 2
            }

            // Mark as probed
            $wpdb->update(
                $table_prefix . 'network_entities',
                array('probed' => 1, 'probed_lead_id' => $new_id),
                array('id' => $entity['id'])
            );

            $queued++;
        }

        // Probe all projects
        foreach ($projects as $project) {
            $lead_data = array(
                'lead_name' => $project['project_name'],
                'source_type' => 'tier2_deep_dive',
                'source_id' => 'dive_project_' . $lead_id . '_' . $project['id'],
                'party_type' => 'project',
                'status' => 'pending_investigation',
                'tier' => 2,
                'metadata' => wp_json_encode(array(
                    'parent_lead_id' => $lead_id,
                    'deep_dive_batch' => current_time('mysql'),
                )),
                'created_at' => current_time('mysql'),
            );

            $wpdb->insert($table_prefix . 'leads', $lead_data);
            $new_id = $wpdb->insert_id;

            if ($new_id && method_exists($lead_generator, 'schedule_investigation')) {
                $lead_generator->schedule_investigation($new_id, 2); // Tier 2
            }

            // Mark as probed
            $wpdb->update(
                $table_prefix . 'network_projects',
                array('probed' => 1, 'probed_lead_id' => $new_id),
                array('id' => $project['id'])
            );

            $queued++;
        }

        // Update parent lead status
        $wpdb->update(
            $table_prefix . 'leads',
            array(
                'tier' => 2,
                'tier2_launched_at' => current_time('mysql'),
            ),
            array('id' => $lead_id)
        );

        wp_send_json_success(array(
            'message' => "Tier 2 Deep Dive launched: {$queued} investigations queued",
            'queued_count' => $queued,
            'entities_count' => count($entities),
            'projects_count' => count($projects),
        ));
    }

    /**
     * AJAX: Reset Investigation (Developer Tool)
     * 
     * Clears party_profiles and resets investigation_status to allow re-running.
     * Useful for testing different investigation prompts/models.
     */
    public function ajax_reset_investigation()
    {
        check_ajax_referer('rawwire_soothsayer', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'), 403);
        }

        global $wpdb;

        $source_id = intval($_POST['source_id'] ?? 0);

        if (!$source_id) {
            wp_send_json_error(array('message' => 'Invalid source ID'));
        }

        $sources_table = $wpdb->prefix . 'rawwire_lead_sources';

        // Reset investigation fields
        $result = $wpdb->update(
            $sources_table,
            array(
                'party_profiles'          => null,
                'parties_investigated_at' => null,
                'investigation_status'    => 'pending',
                'investigator_summary'    => null,
                'investigator_confidence' => null,
            ),
            array('id' => $source_id),
            array('%s', '%s', '%s', '%s', '%d'),
            array('%d')
        );

        if ($result === false) {
            wp_send_json_error(array(
                'message' => 'Database update failed: ' . $wpdb->last_error
            ));
        }

        rawwire_log('soothsayer', sprintf(
            'Investigation reset for source #%d',
            $source_id
        ), 'info');

        wp_send_json_success(array(
            'message' => 'Investigation reset successfully. You can now re-run the investigation.',
            'source_id' => $source_id,
        ));
    }

    /**
     * AJAX: Trash/archive a lead
     *
     * Moves the candidate to 'rejected' status and archives the source record.
     * Records are NOT permanently deleted — they go to the archive table.
     */
    public function ajax_trash_lead()
    {
        check_ajax_referer('rawwire_soothsayer', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'), 403);
        }

        global $wpdb;

        $lead_id   = intval($_POST['lead_id'] ?? 0);
        $source_id = intval($_POST['source_id'] ?? 0);

        if (!$lead_id) {
            wp_send_json_error(array('message' => 'Invalid lead ID'));
        }

        $cand_table    = $wpdb->prefix . 'rawwire_lead_candidates';
        $src_table     = $wpdb->prefix . 'rawwire_lead_sources';
        $archive_table = $wpdb->prefix . 'rawwire_lead_archive';

        // Get the candidate record for archival
        $candidate = $wpdb->get_row($wpdb->prepare(
            "SELECT c.*, s.party_profiles, s.investigation_status
             FROM {$cand_table} c
             LEFT JOIN {$src_table} s ON s.id = c.source_id
             WHERE c.id = %d",
            $lead_id
        ), ARRAY_A);

        if (!$candidate) {
            wp_send_json_error(array('message' => 'Lead not found'));
        }

        // Use provided source_id or fall back to candidate's source_id
        if (!$source_id) {
            $source_id = intval($candidate['source_id']);
        }

        // Insert into archive table
        $wpdb->insert(
            $archive_table,
            array(
                'source_id'       => $source_id,
                'permit_nbr'      => $candidate['permit_nbr'] ?? '',
                'primary_address'  => $candidate['primary_address'] ?? '',
                'permit_type'      => $candidate['permit_type'] ?? '',
                'permit_sub_type'  => $candidate['permit_sub_type'] ?? '',
                'use_desc'        => $candidate['use_desc'] ?? '',
                'work_desc'       => $candidate['work_desc'] ?? '',
                'valuation'       => $candidate['valuation'] ?? 0,
                'score_composite'  => $candidate['score_composite'] ?? 0,
                'score_reasoning'  => $candidate['score_reasoning'] ?? '',
                'archived_reason'  => 'trashed',
                'archived_at'     => current_time('mysql'),
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%s')
        );

        // Update candidate status to rejected
        $wpdb->update(
            $cand_table,
            array(
                'status'            => 'rejected',
                'status_changed_at' => current_time('mysql'),
                'status_changed_by' => get_current_user_id(),
            ),
            array('id' => $lead_id),
            array('%s', '%s', '%d'),
            array('%d')
        );

        // Mark source as processed/archived
        $wpdb->update(
            $src_table,
            array('processed' => 2), // 2 = trashed (vs 1 = normal processed)
            array('id' => $source_id),
            array('%d'),
            array('%d')
        );

        rawwire_log('soothsayer', sprintf(
            'Lead #%d (source #%d) moved to trash',
            $lead_id,
            $source_id
        ), 'info');

        wp_send_json_success(array(
            'message' => 'Lead moved to trash.',
            'lead_id' => $lead_id,
        ));
    }
}
