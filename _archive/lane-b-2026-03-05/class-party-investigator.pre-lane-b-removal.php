<?php

/**
 * @ai-context Search Instinct MCP for "Party Investigator Function Map v5" before modifying this file.
 */

/**
 * RawWire Party Investigator — AI Agent for Decision Maker Research
 *
 * Performs comprehensive web searches on human parties listed in building permits
 * (contractors, owners, applicants, architects) to gather business intelligence
 * before scoring occurs.
 *
 * Uses:
 * - Brave Search API: Web search for public information
 * - OpenClaw/AI Adapter: Extract and structure findings
 *
 * Pipeline flow: Import → Scrape LADBS → Insert → INVESTIGATE PARTIES → Score → Promote
 *
 * @package RawWire_Dashboard
 * @since   1.0.31
 */

if (!defined('ABSPATH')) {
    exit;
}

class RawWire_Party_Investigator
{

    /** @var self|null */
    private static $instance = null;

    /** @var string Brave Search API base URL */
    const BRAVE_API_URL = 'https://api.search.brave.com/res/v1/web/search';

    /** @var string Option key for settings */
    const OPTION_KEY = 'rawwire_party_investigator_settings';

    /** @var int Max searches per party (to limit API usage) */
    const MAX_SEARCH_QUERIES = 3;

    /** @var int Cache duration for search results (seconds) */
    const CACHE_TTL = 86400; // 24 hours

    /** @var string OpenAI API base (primary provider) */
    const OPENCLAW_API_BASE = 'https://api.openai.com/v1';

    /**
     * Get singleton instance
     */
    public static function get_instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
        $this->register_hooks();
    }

    /**
     * Register WordPress hooks
     */
    private function register_hooks(): void
    {
        // AJAX handlers
        add_action('wp_ajax_rw_investigate_party', [$this, 'ajax_investigate_party']);
        add_action('wp_ajax_rw_investigate_source', [$this, 'ajax_investigate_source']);
        add_action('wp_ajax_rw_get_investigation_display', [$this, 'ajax_get_investigation_display']);

        // Async investigation hook
        add_action('rawwire_investigate_source_parties', [$this, 'investigate_source_parties'], 10, 1);
    }

    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------

    /**
     * Get investigator settings
     */
    public static function get_settings(): array
    {
        return wp_parse_args(get_option(self::OPTION_KEY, []), [
            'brave_api_key'       => '',
            'enabled'             => true,
            'search_depth'        => 'standard', // basic, standard, deep
            'auto_investigate'    => true,       // Automatically investigate after insert
            'investigation_model' => 'gpt-4o-mini', // OpenAI GPT-4o Mini (fast, cheap, good at browsing)
            'search_provider'     => 'openclaw', // Lane A only
            'openclaw_auth_token' => 'rawwire-local-dev-2025', // OpenClaw auth token
            'deep_research'       => true,       // Use browser for full page content
            'reinvestigation_cooldown_minutes' => 1, // Manual re-run cooldown (minutes)
        ]);
    }

    /**
     * Save investigator settings
     */
    public static function save_settings(array $settings): bool
    {
        return update_option(self::OPTION_KEY, wp_parse_args($settings, self::get_settings()));
    }

    /**
     * Check if investigation is available
     */
    public function is_available(): bool
    {
        $settings = self::get_settings();

        if (!$settings['enabled']) {
            return false;
        }

        // Lane A only: OpenClaw browser-agent path must be healthy.
        return $this->is_openclaw_available();
    }

    /**
     * Check if OpenClaw/OpenAI API is available
     */
    private function is_openclaw_available(): bool
    {
        // For API health check, we need the OpenAI API key (not the local gateway token).
        // The openclaw_auth_token setting is for the local OpenClaw gateway, not for OpenAI API.
        $auth_token = '';

        // Pull OpenAI key from AI Engine settings (single source of truth)
        $mwai = get_option('mwai_options', []);
        $envs = $mwai['ai_envs'] ?? [];
        foreach ($envs as $env) {
            if (($env['type'] ?? '') === 'openai' && !empty($env['apikey'])) {
                $auth_token = $env['apikey'];
                break;
            }
        }

        // Fallback: Venice API key (if using Venice endpoint)
        if (empty($auth_token)) {
            $venice_settings = get_option('rawwire_venice_settings', []);
            $auth_token = $venice_settings['api_key'] ?? '';
        }

        if (empty($auth_token)) {
            return false;
        }

        // Cached health check
        $cached = get_transient('rw_party_inv_health');
        if ($cached !== false) {
            return $cached === 'ok';
        }

        $response = wp_remote_get(self::OPENCLAW_API_BASE . '/models', [
            'headers' => [
                'Authorization' => 'Bearer ' . $auth_token,
                'Accept'        => 'application/json',
            ],
            'timeout' => 10,
        ]);

        $available = !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
        set_transient('rw_party_inv_health', $available ? 'ok' : 'down', 300);
        return $available;
    }

    /**
     * Get the active search provider
     */
    public function get_search_provider(): string
    {
        return 'openclaw';
    }

    // -------------------------------------------------------------------------
    // Party Extraction
    // -------------------------------------------------------------------------

    /**
     * Extract all searchable parties from a lead_sources record
     *
     * @param array $source Row from rawwire_lead_sources table
     * @return array Array of party data with type, name, company, etc.
     */
    public function extract_parties(array $source): array
    {
        $parties = [];

        // Contractor - always add even if name empty, we can fetch from LADBS
        $permit_nbr = $source['permit_nbr'] ?? '';
        $project_address = $source['address'] ?? '';

        $parties[] = [
            'type'        => 'contractor',
            'name'        => !empty($source['contractor_name']) ? $this->clean_name($source['contractor_name']) : '',
            'company'     => !empty($source['contractor_name']) ? $this->extract_company_from_name($source['contractor_name']) : '',
            'license'     => $source['contractor_license'] ?? '',
            'address'     => $source['contractor_address'] ?? '',
            'city'        => $source['contractor_city'] ?? 'Los Angeles',
            'state'       => $source['contractor_state'] ?? 'CA',
            'permit_nbr'  => $permit_nbr,
            'project_address' => $project_address,
        ];

        // Owner
        if (!empty($source['owner_name'])) {
            $parties[] = [
                'type'    => 'owner',
                'name'    => $this->clean_name($source['owner_name']),
                'company' => $this->extract_company_from_name($source['owner_name']),
                'address' => $source['owner_address'] ?? '',
                'city'    => $source['owner_city'] ?? '',
                'state'   => 'CA',
            ];
        }

        // Applicant
        if (!empty($source['applicant_name'])) {
            $parties[] = [
                'type'     => 'applicant',
                'name'     => $this->clean_name($source['applicant_name']),
                'company'  => $source['applicant_business'] ?? '',
                'business' => $source['applicant_business'] ?? '',
            ];
        }

        // Filter out duplicates and invalid entries
        // Keep entries with permit_nbr even if name is empty (AI will fetch from LADBS)
        $parties = array_filter($parties, function ($p) {
            // Has valid name
            if (strlen($p['name'] ?? '') > 2 && !$this->is_placeholder_name($p['name'])) {
                return true;
            }
            // Or has permit_nbr (contractor type can look it up)
            if ($p['type'] === 'contractor' && !empty($p['permit_nbr'])) {
                return true;
            }
            return false;
        });

        return array_values($parties);
    }

    /**
     * Clean and normalize a name
     */
    private function clean_name(string $name): string
    {
        // Remove common suffixes
        $name = preg_replace('/\s+(INC|LLC|CORP|LP|LTD|CO)\.?$/i', '', $name);
        $name = trim($name);

        // Fix all-caps
        if (strtoupper($name) === $name) {
            $name = ucwords(strtolower($name));
        }

        return $name;
    }

    /**
     * Extract company name if embedded in name field
     */
    private function extract_company_from_name(string $name): string
    {
        // Check if name contains company indicators
        if (preg_match('/(Inc|LLC|Corp|LP|LTD|Company|Construction|Builders?|Development|Properties|Holdings)/i', $name)) {
            return $name;
        }
        return '';
    }

    /**
     * Check if name is a placeholder
     */
    private function is_placeholder_name(string $name): bool
    {
        $placeholders = ['n/a', 'na', 'none', 'unknown', 'tbd', 'owner', 'contractor', 'applicant', 'self'];
        return in_array(strtolower(trim($name)), $placeholders);
    }

    /**
     * Reject unusable party names extracted from noisy/sentinel responses.
     */
    private function is_unusable_party_name(string $name): bool
    {
        $name = trim($name);
        if ($name === '') {
            return true;
        }

        $normalized = strtolower(preg_replace('/\s+/', ' ', $name));
        $blocked = [
            'information',
            'details',
            'name',
            'owner',
            'contractor',
            'applicant',
            'principal',
            'contractor_lookup_failed',
            'contractor lookup failed',
            'unknown',
            'none',
            'n/a',
            'performing the work',
            'of this property',
            'the work',
            'the project',
            'the property',
            'the building',
            'the permit',
            'not applicable',
            'see below',
            'see above',
            'to be determined',
        ];

        if (in_array($normalized, $blocked, true)) {
            return true;
        }

        if (preg_match('/^(contractor|owner|applicant|principal)\s*(name)?$/i', $name)) {
            return true;
        }

        if (str_contains($normalized, 'contractor_lookup_failed') || str_contains($normalized, 'contractor lookup failed')) {
            return true;
        }

        // Reject sentence fragments that start with common articles/prepositions/verbs
        // Real company names don't start with "of", "the" (rare), "for", "performing", etc.
        if (preg_match('/^(of|for|to|from|in|at|by|on|with|this|that|these|those|which|where|performing|doing|making|having|being|was|were|is|are|has|have|will|shall|should|would|could|may|might|can|cannot)\s/i', $name)) {
            return true;
        }

        // Reject names with common verb forms that indicate sentence fragments
        if (preg_match('/\b(is|was|are|were|has been|will be|shall be|performing|responsible for)\b/i', $name)) {
            return true;
        }

        return $this->is_explanatory_text($name);
    }

    /**
     * Detect if a value is explanatory/filler text from the AI rather than an actual name.
     *
     * Catches cases like: "name, license number, or details listed in LADBS permit records..."
     * which Grok sometimes returns when it can't find the actual data.
     */
    private function is_explanatory_text(string $val): bool
    {
        $val = trim($val);

        // Too long for a real name/company — most are under 80 chars
        if (strlen($val) > 100) {
            return true;
        }

        // Contains filler phrases that indicate AI explanation, not data
        $filler_phrases = [
            'not found',
            'not available',
            'not specified',
            'not listed',
            'not provided',
            'not identified',
            'not disclosed',
            'no specific',
            'no information',
            'could not',
            'unable to',
            'listed in',
            'listed for',
            'details listed',
            'details were',
            'details specific',
            'specific to',
            'permit records',
            'according to',
            'based on',
            'it appears',
            'it seems',
            'unfortunately',
            'i couldn\'t',
            'i could not',
            'no contractor',
            'no applicant',
            'no owner',
            'public records',
            'further research',
            'additional research',
            'was listed for',
            'were identified',
            'were found',
            'was found',
            'license number',
            'phone number',
            'contact detail',
            'mailing address',
            'was specified',
            'was disclosed',
            'typically',
            'generally',
            'would need',
            'should check',
            'may require',
            'the permit',
            'the record',
            'the application',
            'permit holder',
            'permit applicant',
            'for this permit',
            'for this project',
            'related to',
        ];
        $lower = strtolower($val);
        foreach ($filler_phrases as $phrase) {
            if (str_contains($lower, $phrase)) {
                return true;
            }
        }

        // Too many words for a name (>6 words likely a sentence fragment, not a person/company)
        if (str_word_count($val) > 6) {
            return true;
        }

        // Contains commas suggesting a list or sentence (real names rarely have >1 comma)
        if (substr_count($val, ',') > 1) {
            return true;
        }

        return false;
    }

    /**
     * Clean a license number string — strip common prefixes and non-numeric chars.
     */
    private function clean_license_number(string $license): string
    {
        // Strip leading # or "No." or "Lic" prefixes
        $license = preg_replace('/^[#\s]+/', '', trim($license));
        $license = preg_replace('/^(no\.?\s*|lic\.?\s*#?\s*)/i', '', $license);
        // Keep only digits
        $cleaned = preg_replace('/[^0-9]/', '', $license);
        // Validate: CSLB licenses are 5-7 digits
        if (strlen($cleaned) >= 5 && strlen($cleaned) <= 7) {
            return $cleaned;
        }
        // If we have something but it doesn't look like a license, return as-is trimmed
        return trim($license);
    }

    // -------------------------------------------------------------------------
    // Permit-Based Party Discovery (when no names exist)
    // -------------------------------------------------------------------------

    /**
     * Discover parties from a permit when no contractor/applicant/owner names exist.
     *
     * Uses the non-AI LADBS direct URL scraper to extract contractor info.
     * No AI, no agents -- just browser navigate + snapshot + regex.
     *
     * @param array $source Row from rawwire_lead_sources table
     * @return array Discovered party names keyed by field, empty if nothing found
     */
    public function discover_parties_from_permit(array $source): array
    {
        $permit_nbr = $source['permit_nbr'] ?? '';

        if (empty($permit_nbr)) {
            rawwire_log('party_investigator', 'Discovery skipped: no permit number', 'debug');
            return [];
        }

        rawwire_log('party_investigator', sprintf(
            'LADBS scrape for contractor on permit %s',
            $permit_nbr
        ));

        // Non-AI LADBS scraper -- direct URL, browser snapshot, regex parse
        require_once __DIR__ . '/class-ladbs-scraper.php';
        $scraper = new RawWire_LADBS_Scraper();
        $max_attempts = 3;
        $result = ['success' => false, 'error' => 'not attempted'];

        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            rawwire_log('party_investigator', sprintf(
                'LADBS scrape attempt %d/%d for permit %s',
                $attempt,
                $max_attempts,
                $permit_nbr
            ), 'debug');

            $result = $scraper->scrape_permit($permit_nbr);

            if (!empty($result['owner_builder'])) {
                break;
            }

            if (!empty($result['success']) && !empty($result['contractor_name'])) {
                break;
            }

            if ($attempt < $max_attempts) {
                sleep(2);
            }
        }

        if (!$result['success'] || empty($result['contractor_name'])) {
            $error = $result['error'] ?? 'no contractor found';
            $is_owner_builder = !empty($result['owner_builder']);

            if ($is_owner_builder) {
                rawwire_log('party_investigator', sprintf(
                    'LADBS scrape: Owner-Builder permit %s — no external contractor',
                    $permit_nbr
                ), 'info');

                // Save the owner-builder flag to DB so we don't re-scrape
                global $wpdb;
                $sources_table = rawwire_leads()->table('sources');
                $wpdb->update(
                    $sources_table,
                    ['investigator_notes' => 'Owner-Builder permit — no external contractor to investigate'],
                    ['id' => (int) $source['id']]
                );
            } else {
                rawwire_log('party_investigator', sprintf(
                    'LADBS scrape failed after %d attempts for permit %s: %s',
                    $max_attempts,
                    $permit_nbr,
                    $error
                ), 'warning');
            }

            return [];
        }

        $discovered = [
            'contractor_name' => $result['contractor_name'],
        ];
        if (!empty($result['contractor_license'])) {
            $discovered['contractor_license'] = $result['contractor_license'];
        }
        if (!empty($result['contractor_address'])) {
            $discovered['contractor_address'] = $result['contractor_address'];
        }

        $is_promoted_engineer = !empty($result['engineer_promoted']);
        $discovered['_discovery_meta'] = [
            'confidence'         => $is_promoted_engineer ? 'medium' : 'high',
            'method'             => $is_promoted_engineer ? 'ladbs_engineer_promotion' : 'ladbs_direct_scrape',
            'owner_builder'      => !empty($result['owner_builder']),
            'engineer_promoted'  => $is_promoted_engineer,
            'sources'            => ['LADBS Permit Detail Page'],
            'discovered_at'      => current_time('mysql'),
        ];

        $this->apply_discovered_parties((int) $source['id'], $discovered, 'LADBS direct URL scrape');

        // If engineer was promoted, note it in investigator_notes
        if ($is_promoted_engineer) {
            global $wpdb;
            $sources_table = rawwire_leads()->table('sources');
            $wpdb->update(
                $sources_table,
                ['investigator_notes' => sprintf(
                    'Owner-Builder permit — engineer "%s" (Lic: %s) promoted as lead',
                    $result['contractor_name'],
                    $result['contractor_license'] ?? 'N/A'
                )],
                ['id' => (int) $source['id']]
            );
        }

        rawwire_log('party_investigator', sprintf(
            'LADBS scrape found %s: %s (Lic: %s)',
            $is_promoted_engineer ? 'engineer (promoted)' : 'contractor',
            $result['contractor_name'],
            $result['contractor_license'] ?? 'N/A'
        ));

        return $discovered;
    }


    /**
     * Extract party data from LADBS scrape results
     *
     * @param array  $permits Array of permit data from LADBS
     * @param string $target_permit_nbr Optional permit to match
     * @return array Party data keyed by field
     */
    private function extract_parties_from_ladbs(array $permits, string $target_permit_nbr = ''): array
    {
        $discovered = [];

        foreach ($permits as $permit) {
            // If we have a target permit, try to match it
            $permit_num = $permit['permit_number'] ?? $permit['permit_nbr'] ?? '';
            if (!empty($target_permit_nbr) && !empty($permit_num)) {
                // Normalize and compare
                $target_norm = preg_replace('/[^a-z0-9]/i', '', strtolower($target_permit_nbr));
                $current_norm = preg_replace('/[^a-z0-9]/i', '', strtolower($permit_num));
                if (strpos($current_norm, $target_norm) === false && strpos($target_norm, $current_norm) === false) {
                    continue; // Skip non-matching permits
                }
            }

            // Extract contractor info
            if (!empty($permit['contractor_name']) && empty($discovered['contractor_name'])) {
                $discovered['contractor_name'] = trim($permit['contractor_name']);
            }
            if (!empty($permit['contractor_license']) && empty($discovered['contractor_lic'])) {
                $discovered['contractor_lic'] = trim($permit['contractor_license']);
            }

            // Extract owner info
            if (!empty($permit['owner_name']) && empty($discovered['owner_name'])) {
                $discovered['owner_name'] = trim($permit['owner_name']);
            }

            // Extract applicant info
            if (!empty($permit['applicant_name']) && empty($discovered['applicant_name'])) {
                $discovered['applicant_name'] = trim($permit['applicant_name']);
            }
        }

        return $discovered;
    }

    /**
     * Build the free-form research prompt (Step 1)
     */
    private function build_discovery_research_prompt(
        string $permit_nbr,
        string $address,
        string $work_desc,
        string $permit_type,
        string $valuation
    ): string {
        $parts = [];
        if ($address) {
            $parts[] = "Address: {$address}";
        }
        if ($permit_nbr) {
            $parts[] = "LA Building Permit: {$permit_nbr}";
        }
        if ($work_desc) {
            $parts[] = "Work: {$work_desc}";
        }
        if ($permit_type) {
            $parts[] = "Type: {$permit_type}";
        }
        if ($valuation && $valuation > 0) {
            $parts[] = "Value: \${$valuation}";
        }

        $info = implode("\n", $parts);

        return <<<PROMPT
I need intelligence on this commercial property and construction project in Los Angeles:

{$info}

Research and tell me:
1. Who OWNS this property? Check LA County Assessor records, Zillow, property databases.
2. What CONTRACTOR is doing this work? Check CSLB, building permit records, job site signs, construction industry sites.
3. Who APPLIED for this permit? Check LADBS records.
4. What businesses or tenants are at this address?
5. Any other people or companies connected to this project.

Be specific with names, license numbers, addresses, and phone numbers when you find them.
PROMPT;
    }

    /**
     * Build the extraction prompt (Step 2) 
     */
    private function build_extraction_prompt(string $research): string
    {
        return <<<PROMPT
From the research below, extract the contractor, owner, and applicant information.

RESEARCH:
{$research}

Return ONLY this JSON (use "" for anything not found):
{"contractor_name":"","contractor_license":"","applicant_name":"","owner_name":"","principal_name":"","contractor_address":"","contractor_city":"","contractor_state":"","confidence":"low/medium/high","sources":["where data came from"]}
PROMPT;
    }

    /**
     * Extract party names from free-form research text using regex patterns
     */
    private function extract_parties_from_text(string $text): array
    {
        $discovered = [];

        // Look for owner patterns
        if (preg_match('/(?:owned by|owner[:\s]+|property owner[:\s]+)\s*\*?\*?([A-Z][A-Za-z\s,\.&]+(?:LLC|Inc|Corp|LP|Trust|Company|Properties|Holdings|Investments|Group|Partners)?)\*?\*?/i', $text, $m)) {
            $name = trim(preg_replace('/\*+/', '', $m[1]));
            $name = rtrim($name, ' .,');
            if (strlen($name) > 3 && strlen($name) < 200 && !$this->is_unusable_party_name($name)) {
                $discovered['owner_name'] = $name;
            }
        }

        // Look for contractor patterns
        if (preg_match('/(?:general contractor|contractor[:\s]+|GC[:\s]+)\s*\*?\*?([A-Z][A-Za-z\s,\.&]+(?:Inc|LLC|Corp|Construction|Builders?|Development|Contracting)?)\*?\*?/i', $text, $m)) {
            $name = trim(preg_replace('/\*+/', '', $m[1]));
            $name = rtrim($name, ' .,');
            if (strlen($name) > 3 && strlen($name) < 200 && !$this->is_unusable_party_name($name)) {
                $discovered['contractor_name'] = $name;
            }
        }

        // Look for CSLB license numbers
        if (preg_match('/(?:CSLB|license|lic)[#:\s]*(\d{5,7})/i', $text, $m)) {
            $discovered['contractor_license'] = $m[1];
        }

        // Look for APN (useful for property research)
        if (preg_match('/APN[:\s]*(\d{4}[-\s]\d{3}[-\s]\d{3})/i', $text, $m)) {
            $discovered['_apn'] = str_replace(' ', '-', $m[1]);
        }

        if (!empty($discovered)) {
            $discovered['_discovery_meta'] = [
                'confidence' => 'medium',
                'method'     => 'text_extraction',
                'sources'    => ['Grok web search research'],
                'discovered_at' => current_time('mysql'),
            ];
        }

        return $discovered;
    }

    /**
     * Save raw research text as intelligence even when no structured data extracted
     */
    private function save_raw_research(int $source_id, string $research): void
    {
        global $wpdb;
        $sources_table = rawwire_leads()->table('sources');

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT party_profiles FROM {$sources_table} WHERE id = %d",
            $source_id
        ));
        $profiles = json_decode($existing, true) ?: [];
        $profiles['_raw_research'] = [
            'content'       => substr($research, 0, 5000), // Cap at 5KB
            'discovered_at' => current_time('mysql'),
        ];

        $wpdb->update(
            $sources_table,
            ['party_profiles' => wp_json_encode($profiles)],
            ['id' => $source_id]
        );
    }

    /**
     * Parse the discovery response from Grok
     */
    private function parse_discovery_response(string $response): array
    {
        // Strip markdown code fences if present
        $response = preg_replace('/^```(?:json)?\s*/m', '', $response);
        $response = preg_replace('/```\s*$/m', '', $response);
        $response = trim($response);

        // Try JSON decode
        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            // Try extracting JSON from mixed content
            if (preg_match('/\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/s', $response, $match)) {
                $data = json_decode($match[0], true);
            }
        }

        if (!is_array($data)) {
            rawwire_log('party_investigator', 'Discovery parse failed: ' . substr($response, 0, 200), 'warning');
            return [];
        }

        // Filter to only non-empty, meaningful values
        $fields = [
            'contractor_name',
            'contractor_license',
            'applicant_name',
            'owner_name',
            'principal_name',
            'contractor_address',
            'contractor_city',
            'contractor_state',
            'contractor_phone',
        ];

        $discovered = [];
        foreach ($fields as $field) {
            $val = $data[$field] ?? '';
            if (!empty($val) && !$this->is_placeholder_name($val) && strlen($val) > 1) {
                // Quality filter: reject explanatory text that leaked from AI response
                if ($this->is_explanatory_text($val)) {
                    continue;
                }
                if (in_array($field, ['contractor_name', 'applicant_name', 'owner_name', 'principal_name'], true) && $this->is_unusable_party_name($val)) {
                    continue;
                }
                $discovered[$field] = trim($val);
            }
        }

        // Clean license numbers — strip "#" prefix, validate format
        if (!empty($discovered['contractor_license'])) {
            $discovered['contractor_license'] = $this->clean_license_number($discovered['contractor_license']);
        }

        // Store discovery metadata
        if (!empty($discovered)) {
            $discovered['_discovery_meta'] = [
                'confidence' => $data['confidence'] ?? 'unknown',
                'sources'    => $data['sources'] ?? [],
                'discovered_at' => current_time('mysql'),
            ];
        }

        return $discovered;
    }

    /**
     * Apply discovered party data to the source record
     */
    private function apply_discovered_parties(int $source_id, array $discovered, string $research = ''): void
    {
        global $wpdb;
        $sources_table = rawwire_leads()->table('sources');

        // Separate metadata from DB fields
        $meta = $discovered['_discovery_meta'] ?? [];
        unset($discovered['_discovery_meta']);

        // Only update DB columns that exist in the schema
        $allowed_columns = [
            'contractor_name',
            'contractor_license',
            'applicant_name',
            'owner_name',
            'principal_name',
            'contractor_address',
            'contractor_city',
            'contractor_state',
        ];

        $updates = [];
        foreach ($allowed_columns as $col) {
            if (!empty($discovered[$col])) {
                $updates[$col] = $discovered[$col];
            }
        }

        if (empty($updates)) {
            return;
        }

        // Store discovery source in party_profiles as metadata
        $existing_profiles = $wpdb->get_var($wpdb->prepare(
            "SELECT party_profiles FROM {$sources_table} WHERE id = %d",
            $source_id
        ));
        $profiles = json_decode($existing_profiles ?: '{}', true) ?: [];
        $profiles['_discovery'] = $meta;
        if (!empty($research)) {
            $profiles['_raw_research'] = [
                'content'       => substr($research, 0, 5000),
                'discovered_at' => current_time('mysql'),
            ];
        }
        $updates['party_profiles'] = wp_json_encode($profiles);

        $wpdb->update($sources_table, $updates, ['id' => $source_id]);

        rawwire_log('party_investigator', sprintf(
            'Updated source #%d with discovered data: %s',
            $source_id,
            implode(', ', array_keys($updates))
        ));
    }

    // -------------------------------------------------------------------------
    // Web Search (Provider-Based)
    // -------------------------------------------------------------------------

    /**
     * Search for information about a party using configured provider
     *
     * Uses the Search Provider Factory for automatic fallback handling.
     * Provider chain: openclaw -> brave -> duckduckgo
     *
     * @param array $party Party data from extract_parties()
     * @return array Search results in standard format
     */
    public function search_party(array $party): array
    {
        require_once __DIR__ . '/class-openclaw-adapter.php';
        $adapter = new RawWire_OpenClaw_Adapter();

        rawwire_log('party_investigator', sprintf(
            'Starting Lane A investigation research for %s: %s',
            $party['type'] ?? 'unknown',
            $party['name'] ?? 'unknown'
        ), 'debug');

        if (!$adapter->is_available()) {
            rawwire_log('party_investigator', 'OpenClaw adapter unavailable for search_party()', 'warning');
            return [];
        }

        $result = $adapter->research($party);

        if (!$result['success']) {
            rawwire_log('party_investigator', 'Lane A research failed for: ' . ($party['name'] ?? 'unknown'), 'warning');
            return [];
        }

        rawwire_log('party_investigator', sprintf(
            'Lane A research complete via %s, %d result bundles',
            $result['source'] ?? 'unknown',
            $result['search_count'] ?? count($result['results'] ?? [])
        ), 'debug');

        // Normalize to a consistent report bundle format.
        return $this->normalize_search_results($result);
    }

    /**
     * Normalize search results to standard format
     * 
     * Ensures consistent format regardless of provider.
     */
    private function normalize_search_results(array $result): array
    {
        $normalized = [];

        // Handle results array (DuckDuckGo/Brave style)
        if (!empty($result['results']) && is_array($result['results'])) {
            foreach ($result['results'] as $query_result) {
                if (is_array($query_result)) {
                    $normalized[] = [
                        'query' => $query_result['query'] ?? 'research',
                        'results' => $query_result['results'] ?? $query_result,
                        'cached' => $query_result['cached'] ?? false,
                        'provider' => $query_result['provider'] ?? $result['source'] ?? 'unknown',
                    ];
                }
            }
        }

        // Handle raw content (OpenClaw style)
        if (empty($normalized) && !empty($result['content'])) {
            $normalized[] = [
                'query' => 'OpenClaw research',
                'results' => ['raw_findings' => $result['content']],
                'cached' => false,
                'provider' => $result['source'] ?? 'openclaw',
            ];
        }

        return $normalized;
    }


    // Legacy search methods (search_party_duckduckgo, search_party_openclaw,
    // search_party_brave, build_search_queries, parse_brave_results, parse_duckduckgo_html)
    // removed 2026-02-19 — all search now routes through RawWire_Search_Provider_Factory


    // -------------------------------------------------------------------------
    // Agent-Powered Investigation (Flagship Path)
    // -------------------------------------------------------------------------

    /**
     * Investigate a party using the OpenClaw agent with browser tools
     *
     * This is the PRIMARY investigation path. The agent can navigate real websites,
     * click through pages, and extract live data — producing Perplexity-grade reports.
     *
     * Flow:
     *   1. agent_chat() with investigation prompt → rich findings (real web browsing)
     *   2. chat() extraction pass → structured JSON from those findings
     *   3. Combine: raw_investigation (display) + structured profile (scoring)
     *
     * @param array $party Party data (name, type, company, license, etc.)
     * @param array $source Row from rawwire_lead_sources (for permit/address context)
     * @return array|null Structured profile or null if agent unavailable
     */
    private function investigate_party_via_agent(array $party, array $source = []): ?array
    {
        require_once __DIR__ . '/class-openclaw-adapter.php';
        $adapter = new RawWire_OpenClaw_Adapter();

        // Agent must be available (OpenClaw CLI installed, enabled)
        if (!$adapter->is_available()) {
            rawwire_log('party_investigator', 'Agent investigation unavailable — OpenClaw not configured', 'warning');
            return null;
        }

        // Lane A is mandatory for lead quality in this application.

        // Enrich party with source context
        $enriched_party = $party;
        if (!empty($source['permit_nbr'])) {
            $enriched_party['permit_nbr'] = $source['permit_nbr'];
        }
        if (!empty($source['primary_address'])) {
            $enriched_party['project_address'] = $source['primary_address'];
        }
        if (!empty($source['city'])) {
            $enriched_party['city'] = $source['city'];
        }

        // Build the investigation prompt (adapter handles the heavy lifting)
        $prompt = $adapter->build_agent_research_prompt($enriched_party);

        rawwire_log('party_investigator', sprintf(
            'Agent investigation starting for %s (prompt %d chars, timeout 300s)',
            $party['name'] ?? 'unknown',
            strlen($prompt)
        ), 'info');

        // STEP 1: Agent does real web research with browser tools (5 min timeout)
        $agent_result = $adapter->agent_chat($prompt, [
            'timeout' => 600000,  // 10 minutes — let 4o-mini browse thoroughly
            'json'    => true,
        ]);

        if (!$agent_result['success'] || empty($agent_result['content'])) {
            $error = $agent_result['error'] ?? 'empty response';
            rawwire_log('party_investigator', 'Agent investigation failed: ' . $error, 'warning');
            return null;
        }

        $investigation_text = $agent_result['content'];

        // Fast-fail known toolchain/config errors to avoid wasting retries/tokens.
        if (
            preg_match('/INVESTIGATION_FAILED:.*brave\s+search\s+api\s+key/i', $investigation_text)
            || preg_match('/Brave\s+Search\s+API\s+key/i', $investigation_text)
        ) {
            rawwire_log('party_investigator', sprintf(
                'Agent investigation rejected for %s -- toolchain unavailable (Brave API key requirement)',
                $party['name'] ?? 'unknown'
            ), 'warning');
            return null;
        }

        // Always save raw discovery output for human review/auditing,
        // even if it fails evidence validation and is rejected.
        $this->save_discovery_file($source, $party, $investigation_text);

        if (!$this->is_valid_agent_investigation($investigation_text)) {
            $diag = $this->get_agent_investigation_diagnostics($investigation_text);

            rawwire_log('party_investigator', sprintf(
                'Agent investigation rejected for %s -- urls=%d, evidence_section=%s, failure_signature=%s',
                $party['name'] ?? 'unknown',
                $diag['url_count'],
                $diag['has_evidence_section'] ? 'yes' : 'no',
                $diag['failure_signature'] !== '' ? $diag['failure_signature'] : 'none'
            ), 'warning');

            $allow_agent_deep_retry = (bool) $adapter->get_setting('agent_deep_retry', false);
            if (!$allow_agent_deep_retry) {
                return null;
            }

            // One hard retry before fallback when explicitly enabled.
            $retry_prompt = $prompt . "\n\nRETRY DIRECTIVE (MANDATORY):\n"
                . "- Your prior response was rejected as insufficient evidence.\n"
                . "- You MUST perform live browsing/search now and provide ONLY factual findings.\n"
                . "- Include at least 3 unique source URLs and an EVIDENCE LOG section.\n"
                . "- If tools are truly unavailable, output exactly: INVESTIGATION_FAILED: <reason>\n"
                . "- Do not provide generic guidance or instructions.\n";

            rawwire_log('party_investigator', sprintf(
                'Agent deep-retry starting for %s after sparse output',
                $party['name'] ?? 'unknown'
            ), 'info');

            $retry_result = $adapter->agent_chat($retry_prompt, [
                'timeout' => 600000,
                'json'    => true,
            ]);

            if (!empty($retry_result['success']) && !empty($retry_result['content'])) {
                $retry_text = $retry_result['content'];
                $this->save_discovery_file($source, $party, $retry_text);

                if ($this->is_valid_agent_investigation($retry_text)) {
                    $investigation_text = $retry_text;
                    rawwire_log('party_investigator', sprintf(
                        'Agent deep-retry accepted for %s (%d chars)',
                        $party['name'] ?? 'unknown',
                        strlen($investigation_text)
                    ), 'info');
                } else {
                    $retry_diag = $this->get_agent_investigation_diagnostics($retry_text);
                    rawwire_log('party_investigator', sprintf(
                        'Agent deep-retry rejected for %s -- urls=%d, evidence_section=%s, failure_signature=%s',
                        $party['name'] ?? 'unknown',
                        $retry_diag['url_count'],
                        $retry_diag['has_evidence_section'] ? 'yes' : 'no',
                        $retry_diag['failure_signature'] !== '' ? $retry_diag['failure_signature'] : 'none'
                    ), 'warning');
                    return null;
                }
            } else {
                rawwire_log('party_investigator', sprintf(
                    'Agent deep-retry failed for %s: %s',
                    $party['name'] ?? 'unknown',
                    $retry_result['error'] ?? 'empty response'
                ), 'warning');
                return null;
            }
        }

        rawwire_log('party_investigator', sprintf(
            'Agent returned %d chars of investigation for %s',
            strlen($investigation_text),
            $party['name'] ?? 'unknown'
        ), 'info');

        // Raw investigation preserved -- structured extraction deferred to cheap AI file pass
        // NO fallback/placeholder data — only real research gets saved
        $profile = [
            'raw_investigation'     => $investigation_text,
            'investigation_method'  => 'agent_browser',
            'investigation_status'  => 'pending_extraction',
        ];

        rawwire_log('party_investigator', sprintf(
            'Agent investigation complete for %s -- %d chars of raw findings (pending extraction)',
            $party['name'] ?? 'unknown',
            strlen($investigation_text)
        ), 'info');

        return $profile;
    }

    /**
     * Validate that agent output reflects real web investigation (not placeholder/generic text).
     */
    private function is_valid_agent_investigation(string $text): bool
    {
        $normalized = trim($text);
        if (strlen($normalized) < 300) {
            return false;
        }

        $diag = $this->get_agent_investigation_diagnostics($normalized);

        if ($diag['failure_signature'] !== '') {
            return false;
        }
        if ($diag['url_count'] < 2) {
            return false;
        }

        return $diag['has_evidence_section'];
    }

    /**
     * Collect agent-investigation quality diagnostics for logging/gating.
     *
     * @param string $text
     * @return array{url_count:int,has_evidence_section:bool,failure_signature:string}
     */
    private function get_agent_investigation_diagnostics(string $text): array
    {
        $lower = strtolower($text);
        $failure_signatures = [
            'investigation_failed:',
            'cannot access external web search',
            'missing api key',
            'connection error',
            'api unavailable',
            'manual research recommended',
            'no output from agent',
            'tool unavailable',
            'unable to perform live web searches',
            'unable to perform live web search',
            'access restrictions',
        ];

        $matched_failure_sig = '';
        foreach ($failure_signatures as $sig) {
            if (str_contains($lower, $sig)) {
                $matched_failure_sig = $sig;
                break;
            }
        }

        preg_match_all('#https?://[^\s)\]>"]+#i', $text, $url_matches);
        $unique_urls = array_values(array_unique($url_matches[0] ?? []));

        $has_evidence_section =
            str_contains($lower, 'evidence log') ||
            str_contains($lower, 'sources:') ||
            str_contains($lower, 'source urls');

        return [
            'url_count'             => count($unique_urls),
            'has_evidence_section'  => $has_evidence_section,
            'failure_signature'     => $matched_failure_sig,
        ];
    }

    /**
     * Save raw discovery text to a file for human review
     *
     * @param array  $source             Source record
     * @param array  $party              Party data
     * @param string $investigation_text  Raw agent output
     */
    private function save_discovery_file(array $source, array $party, string $investigation_text): void
    {
        $dir = $this->get_secure_output_dir('discoveries');

        $source_id = $source['id'] ?? 0;
        $party_name = sanitize_file_name($party['name'] ?? 'unknown');
        $timestamp = date('Ymd_His');
        $filename = "{$dir}/source_{$source_id}_{$party_name}_{$timestamp}.txt";

        $header = "=== RAW WIRE DISCOVERY FILE ===\n";
        $header .= "Source ID: {$source_id}\n";
        $header .= "Party: " . ($party['name'] ?? 'unknown') . "\n";
        $header .= "License: " . ($party['license'] ?? 'N/A') . "\n";
        $header .= "Permit: " . ($source['permit_nbr'] ?? 'N/A') . "\n";
        $header .= "Generated: " . current_time('mysql') . "\n";
        $header .= "Method: agent_browser (GPT-4o-mini via OpenClaw)\n";
        $header .= str_repeat('=', 50) . "\n\n";

        $written = file_put_contents($filename, $header . $investigation_text);
        @chmod($filename, 0640);

        if ($written !== false) {
            rawwire_log('party_investigator', sprintf(
                'Discovery file saved: %s (%d bytes)',
                $filename,
                $written
            ), 'info');
        } else {
            rawwire_log('party_investigator', 'Failed to write discovery file: ' . $filename, 'warning');
        }
    }


    /**
     * Build extraction prompt to convert raw investigation text into structured JSON
     *
     * @param array  $party              Party context
     * @param string $investigation_text  Raw investigation from agent
     * @return string Prompt for JSON extraction
     */
    private function build_agent_extraction_prompt(array $party, string $investigation_text): string
    {
        $name = $party['name'] ?? 'Unknown';

        // Truncate investigation if extremely long (LLM context limits)
        $max_investigation_chars = 12000;
        if (strlen($investigation_text) > $max_investigation_chars) {
            $investigation_text = substr($investigation_text, 0, $max_investigation_chars) . "\n\n[Investigation truncated for extraction — full text preserved separately]";
        }

        return <<<PROMPT
Extract structured intelligence from this investigation report about {$name}.

=== INVESTIGATION REPORT ===
{$investigation_text}
=== END REPORT ===

Return JSON with this structure:
{
  "value_score": 0-100,
  "target_summary": "One sentence: who is this and why are they valuable as a business target?",
  "people": [
    {
      "name": "Full name",
      "title": "Exact title",
      "authority": "final|recommender|influencer|gatekeeper",
      "tier": "executive|operational|legal",
      "contact": {"linkedin": "", "email": "", "phone": ""},
      "notes": "Why this person matters, what they control"
    }
  ],
  "company": {
    "name": "Legal entity name",
    "type": "GC|subcontractor|developer|owner|architect|CM",
    "specialties": ["market sectors"],
    "scale": "revenue range or employee count",
    "delivery_methods": ["GC", "CM-at-risk", "Design-Build"],
    "licensing": {"number": "", "class": "", "status": ""},
    "ownership": "private|ESOP|public|PE-backed",
    "notable_facts": []
  },
  "networking_opportunities": {
    "associations": ["org memberships"],
    "upcoming_events": ["events worth attending"],
    "charity_involvement": ["causes and events"],
    "prequalification": {"platform": "", "url": "", "requirements": []}
  },
  "outreach_programs": {
    "dvbe_goals": "",
    "small_business_programs": [],
    "how_to_get_on_bid_list": ""
  },
  "discovered_projects": [
    {"name": "", "status": "active|planning|completed", "value": "", "location": "", "type": ""}
  ],
  "discovered_entities": [
    {"name": "", "type": "company|person|agency", "relationship": "partner|sub|client|competitor", "score_hint": 0}
  ],
  "entry_points": ["Specific actionable ways to connect with this target"],
  "outreach_strategy": "Detailed 60-90 day networking plan",
  "intelligence_gaps": ["What we couldn't find — drives future research"],
  "red_flags": ["Concerns: lawsuits, safety issues, financial trouble"],
  "sources": ["URLs cited in the investigation"]
}

EXTRACTION RULES:
- Extract ALL people mentioned with their exact titles
- Extract ALL projects mentioned
- Extract ALL organizations and associations
- If the report says something wasn't found, list it in intelligence_gaps
- value_score: 90+ = major GC with clear entry points, 70-89 = good target, 50-69 = moderate, <50 = low value
- Be thorough — every fact in the report should map to a field
PROMPT;
    }

    // -------------------------------------------------------------------------
    // AI Analysis (Fallback Path)
    // -------------------------------------------------------------------------

    /**
     * Legacy Lane B analysis path (disabled).
     *
     * Lane A agent-browser investigation is required for lead quality.
     */
    public function analyze_with_ai(array $party, array $search_results): ?array
    {
        rawwire_log('party_investigator', 'Lane B analyze_with_ai() is disabled by design', 'warning');
        return null;
    }

    /**
     * Run analysis through OpenClaw/OpenAI adapter directly
     *
     * Reads analysis_max_tokens and analysis_temperature from rawwire_openclaw_settings
     * so they can be tuned from the AI Settings → OpenClaw tab without touching code.
     */
    private function analyze_via_openclaw(string $prompt): ?array
    {
        require_once __DIR__ . '/class-openclaw-adapter.php';
        $adapter = new RawWire_OpenClaw_Adapter();

        // Check if OpenClaw is enabled in settings
        $oc_enabled = $adapter->get_setting('enabled', true);
        if (!$oc_enabled) {
            rawwire_log('party_investigator', 'OpenClaw disabled in settings', 'warning');
            return null;
        }

        if (!$adapter->is_available()) {
            rawwire_log('party_investigator', 'OpenClaw not available for analysis', 'warning');
            return null;
        }

        // Pull analysis parameters from settings (configurable via AI Settings → OpenClaw tab)
        $analysis_max_tokens = (int) $adapter->get_setting('analysis_max_tokens', 2000);
        $analysis_max_tokens = max(500, min($analysis_max_tokens, 2500));
        $analysis_temperature = (float) $adapter->get_setting('analysis_temperature', 0.3);

        rawwire_log('party_investigator', sprintf(
            'Analysis params: max_tokens=%d, temperature=%.2f',
            $analysis_max_tokens,
            $analysis_temperature
        ), 'debug');

        $response = $adapter->chat([
            ['role' => 'system', 'content' => 'You are a business intelligence analyst. Return ONLY valid JSON. No markdown fences, no explanation text before or after. Start with { and end with }.'],
            ['role' => 'user', 'content' => $prompt],
        ], [
            'max_tokens'        => $analysis_max_tokens,
            'temperature'       => $analysis_temperature,
            'enable_web_search' => false,  // We already have search results — skip to avoid timeout
        ]);

        if (empty($response)) {
            rawwire_log('party_investigator', 'OpenClaw returned empty response for analysis', 'warning');
            return null;
        }

        rawwire_log('party_investigator', 'OpenClaw analysis raw response length: ' . strlen($response), 'debug');
        rawwire_log('party_investigator', 'OpenClaw analysis first 200 chars: ' . substr($response, 0, 200), 'debug');

        // Aggressive JSON extraction — handle markdown fences, preamble text, etc.
        $json = $this->extract_json_from_response($response);

        if ($json === null) {
            rawwire_log('party_investigator', 'Failed to parse JSON from OpenClaw analysis. Full response: ' . substr($response, 0, 500), 'warning');
            return null;
        }

        rawwire_log('party_investigator', 'OpenClaw analysis parsed successfully, keys: ' . implode(', ', array_keys($json)), 'debug');
        return $json;
    }

    /**
     * Extract JSON object from a response that may contain markdown, preamble, etc.
     */
    private function extract_json_from_response(string $response): ?array
    {
        $response = trim($response);

        // 1. Strip markdown fences
        $cleaned = preg_replace('/^```(?:json)?\s*\n?/m', '', $response);
        $cleaned = preg_replace('/\n?\s*```\s*$/m', '', $cleaned);
        $cleaned = trim($cleaned);

        // 2. Try direct decode
        $decoded = json_decode($cleaned, true);
        if (is_array($decoded) && !empty($decoded)) {
            return $decoded;
        }

        // 3. Find first { and last } — extract the JSON object
        $first_brace = strpos($response, '{');
        $last_brace = strrpos($response, '}');

        if ($first_brace !== false && $last_brace !== false && $last_brace > $first_brace) {
            $json_str = substr($response, $first_brace, $last_brace - $first_brace + 1);
            $decoded = json_decode($json_str, true);
            if (is_array($decoded) && !empty($decoded)) {
                return $decoded;
            }

            // 4. Try fixing common issues: trailing commas, unescaped newlines
            $json_str = preg_replace('/,\s*([\]}])/', '$1', $json_str);
            $decoded = json_decode($json_str, true);
            if (is_array($decoded) && !empty($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Build AI analysis prompt
     *
     * Philosophy: Instruct by EXAMPLE, not by rigid rules. Don't constrain the data
     * to fit a schema - expand the schema to capture everything valuable. If we find
     * a diamond while mining for gold, we keep the diamond.
     *
     * NOTE: Prompt has been condensed to stay under ~2KB to prevent API timeouts.
     */
    private function build_analysis_prompt(array $party, array $search_results): string
    {
        $name = $party['name'];
        $type = ucfirst($party['type'] ?? 'Contact');
        $company = $party['company'] ?? 'Unknown';
        $license = $party['license'] ?? 'N/A';

        // Compile search results (truncate to prevent bloat)
        $search_text = '';
        $max_search_chars = 1500;  // Limit search results to prevent huge prompts
        foreach ($search_results as $queryResult) {
            if (strlen($search_text) > $max_search_chars) {
                $search_text .= "\n[Additional results truncated]";
                break;
            }

            // Handle OpenClaw raw findings
            if (isset($queryResult['results']['raw_findings'])) {
                $search_text .= substr($queryResult['results']['raw_findings'], 0, $max_search_chars) . "\n";
                continue;
            }

            // Handle 'web' key structure (from test data)
            if (isset($queryResult['title'])) {
                $search_text .= "- {$queryResult['title']}: {$queryResult['snippet']}\n";
                continue;
            }

            // Standard results array
            if (is_array($queryResult['results'] ?? null)) {
                foreach ($queryResult['results'] as $r) {
                    if (!is_array($r)) continue;
                    $search_text .= sprintf("- %s: %s\n", $r['title'] ?? 'Result', $r['description'] ?? $r['snippet'] ?? '');
                }
            }
        }

        // Handle direct 'web' array structure
        if (isset($search_results['web']) && is_array($search_results['web'])) {
            foreach ($search_results['web'] as $r) {
                $search_text .= sprintf("- %s: %s\n", $r['title'] ?? 'Result', $r['snippet'] ?? $r['description'] ?? '');
            }
        }

        return <<<PROMPT
Analyze this construction industry contact and return a JSON intelligence profile.

TARGET: {$name} ({$type}) at {$company}, License: {$license}

SEARCH RESULTS:
{$search_text}

Return JSON with this structure:
{
  "value_score": 0-100,
  "target_summary": "One sentence: who is this and why valuable?",
  "people": [{"name": "", "title": "", "authority": "final|recommender|influencer", "contact": {}, "notes": ""}],
  "company": {"name": "", "type": "", "specialties": [], "scale": "", "notable_facts": []},
  "networking_opportunities": {"associations": [], "upcoming_events": [], "charity_involvement": []},
  "discovered_projects": [{"name": "", "status": "", "value": "", "location": ""}],
  "discovered_entities": [{"name": "", "type": "", "relationship": "", "score_hint": 0}],
  "entry_points": ["Best ways to reach them"],
  "outreach_strategy": "Specific recommendation",
  "intelligence_gaps": ["What we couldn't find"],
  "red_flags": []
}

Extract ALL people, projects, and partner companies mentioned. Be thorough.
PROMPT;
    }

    // -------------------------------------------------------------------------
    // Investigation Orchestration
    // -------------------------------------------------------------------------

    /**
     * Investigate all parties for a lead_sources record
     *
     * @param int $source_id ID in rawwire_lead_sources
     * @param bool $force Force re-investigation even if recently done
     * @return array|WP_Error
     */
    public function investigate_source_parties(int $source_id, bool $force = false)
    {
        global $wpdb;

        if (!$this->is_available()) {
            $provider = self::get_settings()['search_provider'] ?? 'unknown';
            return new WP_Error('not_available', sprintf(
                'Party investigation not available. Provider "%s" is not configured or unreachable. Check AI Settings.',
                $provider
            ));
        }

        $sources_table = rawwire_leads()->table('sources');
        $source = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$sources_table} WHERE id = %d",
            $source_id
        ), ARRAY_A);

        if (!$source) {
            return new WP_Error('not_found', 'Source record not found.');
        }

        // Check if already investigated (can be bypassed with force)
        if (!$force && !empty($source['parties_investigated_at'])) {
            $settings = self::get_settings();
            $cooldown_minutes = max(0, (int) ($settings['reinvestigation_cooldown_minutes'] ?? 1));
            $cooldown_seconds = $cooldown_minutes * 60;

            if ($cooldown_seconds <= 0) {
                $cooldown_seconds = 0;
            }

            $last_check = strtotime($source['parties_investigated_at']);
            if ($cooldown_seconds > 0 && time() - $last_check < $cooldown_seconds) {
                return [
                    'skipped'   => true,
                    'reason'    => 'Recently investigated',
                    'source_id' => $source_id,
                ];
            }
        }

        $parties = $this->extract_parties($source);

        // Check if any party has an actual name (not just permit-number stubs)
        $has_named_parties = false;
        foreach ($parties as $p) {
            if (!empty($p['name']) && strlen($p['name']) > 2) {
                $has_named_parties = true;
                break;
            }
        }

        // If no NAMED parties, try permit-based discovery via LADBS/web search
        $allow_permit_context_investigation = false;

        if (!$has_named_parties) {
            rawwire_log('party_investigator', sprintf(
                'No named parties for source #%d (%d stubs), attempting permit discovery...',
                $source_id,
                count($parties)
            ));

            $discovered = $this->discover_parties_from_permit($source);

            if (!empty($discovered)) {
                // Reload the source record with newly discovered data
                $source = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$sources_table} WHERE id = %d",
                    $source_id
                ), ARRAY_A);

                // Re-extract parties from the updated record
                $parties = $this->extract_parties($source);

                rawwire_log('party_investigator', sprintf(
                    'Permit discovery found %d fields, extracted %d parties',
                    count($discovered),
                    count($parties)
                ));
            } else {
                // LADBS discovery failed — do NOT waste tokens investigating empty-name parties
                // Check if discover_parties_from_permit already wrote an Owner-Builder note
                $existing_notes = $wpdb->get_var($wpdb->prepare(
                    "SELECT investigator_notes FROM {$sources_table} WHERE id = %d",
                    $source_id
                ));
                $is_owner_builder = (strpos($existing_notes ?? '', 'Owner-Builder') !== false);

                if ($is_owner_builder) {
                    // Do not short-circuit owner-builder permits.
                    // Continue with permit-context investigation even without a named contractor,
                    // because owner-side decision makers are still valid targets.
                    $allow_permit_context_investigation = true;

                    rawwire_log('party_investigator', sprintf(
                        'Owner-Builder permit for source #%d: continuing permit-context investigation (no early skip)',
                        $source_id
                    ), 'info');
                } else {
                    $note = 'LADBS scrape could not extract contractor data. No named parties to investigate.';
                    $status = 'failed';

                    rawwire_log('party_investigator', sprintf(
                        'LADBS discovery empty for source #%d — no contractor data, aborting investigation',
                        $source_id
                    ), 'warning');

                    $wpdb->update(
                        $sources_table,
                        [
                            'parties_investigated_at' => current_time('mysql'),
                            'investigation_status'    => $status,
                            'investigator_notes'      => $note,
                        ],
                        ['id' => $source_id]
                    );

                    return [
                        'success'        => false,
                        'skipped'        => true,
                        'owner_builder'  => false,
                        'reason'         => 'LADBS discovery failed — no named parties to investigate',
                        'source_id'      => $source_id,
                    ];
                }
            }

            // Re-check: after discovery, do we actually have named parties now?
            $has_named_parties = false;
            foreach ($parties as $p) {
                if (!empty($p['name']) && strlen($p['name']) > 2) {
                    $has_named_parties = true;
                    break;
                }
            }

            if (!$has_named_parties && !$allow_permit_context_investigation) {
                rawwire_log('party_investigator', sprintf(
                    'Discovery returned data but no usable party names for source #%d — aborting',
                    $source_id
                ), 'warning');

                $wpdb->update(
                    $sources_table,
                    [
                        'parties_investigated_at' => current_time('mysql'),
                        'investigation_status'    => 'failed',
                        'investigator_notes'      => 'LADBS scraped but contractor name was empty or unparseable.',
                    ],
                    ['id' => $source_id]
                );

                return [
                    'success'   => false,
                    'skipped'   => true,
                    'reason'    => 'Discovery returned data but no usable party names',
                    'source_id' => $source_id,
                ];
            }

            if (!$has_named_parties && $allow_permit_context_investigation) {
                rawwire_log('party_investigator', sprintf(
                    'Proceeding with permit-context investigation for owner-builder source #%d',
                    $source_id
                ), 'info');
            }
        }

        if (empty($parties)) {
            // Mark as investigated even if nothing found, to avoid retrying
            $wpdb->update(
                $sources_table,
                [
                    'parties_investigated_at' => current_time('mysql'),
                    'investigation_status'    => 'no_parties_found',
                ],
                ['id' => $source_id]
            );

            return [
                'skipped'   => true,
                'reason'    => 'No parties found even after permit discovery',
                'source_id' => $source_id,
            ];
        }

        $investigations = [];
        $failure_reasons = [];

        foreach ($parties as $party) {
            rawwire_log('party_investigator', sprintf(
                'Investigating %s: %s',
                $party['type'],
                $party['name']
            ));

            // Lane A only: Agent-powered investigation (browser tools, real web browsing)
            $search_results = [];
            $profile = $this->investigate_party_via_agent($party, $source);

            // No fallback lane: if agent investigation fails, skip this party.
            if ($profile === null) {
                $failure_reasons[] = sprintf(
                    '%s "%s": Lane A agent investigation failed — no data saved',
                    ucfirst($party['type']),
                    $party['name']
                );
                rawwire_log('party_investigator', sprintf(
                    'SKIPPING %s "%s" — Lane A returned null, refusing to save placeholder data',
                    $party['type'],
                    $party['name']
                ), 'warning');
                sleep(1);
                continue;
            }

            $investigations[] = [
                'party_type' => $party['type'],
                'party_name' => $party['name'],
                'profile'    => $profile,
                'search_count' => count($search_results),
            ];

            // Delay between parties
            sleep(1);
        }

        // If ALL parties failed, mark source as failed -- do NOT save empty/placeholder data
        if (empty($investigations)) {
            $failure_summary = implode('; ', $failure_reasons);

            rawwire_log('party_investigator', sprintf(
                'ALL parties failed for source #%d -- marking as failed. Reasons: %s',
                $source_id,
                $failure_summary
            ), 'error');

            $wpdb->update(
                $sources_table,
                [
                    'parties_investigated_at' => current_time('mysql'),
                    'investigation_status'    => 'failed',
                    'investigator_notes'      => 'Investigation failed: ' . $failure_summary,
                ],
                ['id' => $source_id]
            );

            return [
                'success'         => false,
                'source_id'       => $source_id,
                'parties_count'   => 0,
                'failure_reasons' => $failure_reasons,
                'message'         => 'Investigation failed for all parties. No data was saved.',
            ];
        }

        // Step 1: Dump ALL raw investigation findings to a single file
        $file_path = $this->dump_investigation_file($source_id, $source, $investigations);

        rawwire_log('party_investigator', sprintf(
            'Investigation findings dumped to %s (%d parties)',
            $file_path,
            count($investigations)
        ));

        // Step 1.5: Deterministic entity deep-dive report + parser hook to source columns
        $entity_report_file = $this->build_entity_deep_dive_report($source_id, $source, $investigations);
        if (!empty($entity_report_file)) {
            $parsed_discovery = $this->extract_discovered_parties_from_entity_report($entity_report_file);
            if (!empty($parsed_discovery)) {
                $this->apply_discovered_parties($source_id, $parsed_discovery);
            }
        }

        // Step 2: Use cheap AI to extract structured profiles from the file
        $enriched = $this->extract_profiles_from_file($file_path);

        // Step 3: Merge AI-extracted profiles into investigation results
        if (!empty($enriched) && is_array($enriched)) {
            foreach ($investigations as &$inv) {
                $key = $inv['party_type'];
                if (isset($enriched[$key]) && is_array($enriched[$key])) {
                    $inv['profile'] = array_merge($inv['profile'] ?? [], $enriched[$key]);
                }
            }
            unset($inv);
        }

        // Step 4: Save merged results to DB and trigger scoring
        $this->save_investigations($source_id, $investigations);

        rawwire_log('party_investigator', sprintf(
            'Completed investigation for source #%d -- %d parties (skipped %d), file: %s',
            $source_id,
            count($investigations),
            count($failure_reasons),
            basename($file_path)
        ));

        return [
            'success'            => true,
            'source_id'          => $source_id,
            'parties_count'      => count($investigations),
            'investigation_file' => $file_path,
            'entity_report_file' => $entity_report_file,
            'investigations'     => $investigations,
            'failure_reasons'    => $failure_reasons,
        ];
    }


    /**
     * Save investigation results to database and trigger scoring
     */
    private function save_investigations(int $source_id, array $investigations): void
    {
        global $wpdb;
        $sources_table = rawwire_leads()->table('sources');

        // Store as JSON in source record
        $party_profiles = [];
        foreach ($investigations as $inv) {
            $party_profiles[$inv['party_type']] = [
                'name'    => $inv['party_name'],
                'profile' => $inv['profile'],
            ];
        }

        rawwire_log('party_investigator', sprintf(
            'Saving investigations for source #%d: %d profiles',
            $source_id,
            count($party_profiles)
        ));

        // Quality gate: check if we have ANY meaningful research data before saving
        // Reject profiles that only contain placeholder/fallback markers
        $has_meaningful_data = false;
        $placeholder_markers = [
            'Manual research recommended',
            'AI analysis unavailable',
            'AI analysis failed',
        ];

        foreach ($party_profiles as $key => $pp) {
            $profile = $pp['profile'] ?? [];
            $name = $pp['name'] ?? '';
            $score = $profile['value_score'] ?? 0;
            $summary = $profile['target_summary'] ?? '';
            $raw = $profile['raw_investigation'] ?? '';

            // Check for placeholder data markers
            $is_placeholder = false;
            foreach ($placeholder_markers as $marker) {
                if (stripos($summary, $marker) !== false) {
                    $is_placeholder = true;
                    break;
                }
            }

            // Score of exactly 20 with no raw investigation = fallback shell
            if ($score === 20 && empty($raw) && empty($profile['people'][0]['contact'] ?? [])) {
                $is_placeholder = true;
            }

            if ($is_placeholder) {
                rawwire_log('party_investigator', sprintf(
                    'Source #%d: Rejecting placeholder profile for %s "%s" -- not saving to DB',
                    $source_id,
                    $key,
                    $name
                ), 'warning');
                unset($party_profiles[$key]);
                continue;
            }

            $method = $profile['investigation_method'] ?? '';
            $raw_is_valid = !empty($raw) && $this->is_valid_agent_investigation((string) $raw);

            // Real data: has a name and either good extraction score or valid raw evidence-backed investigation
            if (strlen($name) > 2 && ($score >= 30 || $raw_is_valid)) {
                $has_meaningful_data = true;
            } elseif ($method === 'agent_browser') {
                rawwire_log('party_investigator', sprintf(
                    'Source #%d: agent_browser output for %s failed evidence validation',
                    $source_id,
                    $name
                ), 'warning');
            }
        }

        // If everything was filtered out, mark as failed
        if (empty($party_profiles)) {
            rawwire_log('party_investigator', sprintf(
                'Source #%d: All profiles were placeholder data -- marking as failed, nothing saved',
                $source_id
            ), 'error');

            $wpdb->update(
                $sources_table,
                [
                    'parties_investigated_at' => current_time('mysql'),
                    'investigation_status'    => 'failed',
                    'investigator_notes'      => 'All investigation results were placeholder data. No real research data obtained.',
                ],
                ['id' => $source_id]
            );
            return;
        }

        $status = $has_meaningful_data ? 'completed' : 'incomplete';
        if (!$has_meaningful_data) {
            rawwire_log('party_investigator', sprintf(
                'Source #%d: Investigation data present but below quality threshold -- marking as incomplete',
                $source_id
            ), 'warning');
        }

        $update_data = [
            'party_profiles'          => wp_json_encode($party_profiles),
            'parties_investigated_at' => current_time('mysql'),
            'investigation_status'    => $status,
        ];

        // Preserve non-party metadata previously written into party_profiles
        // (e.g. _discovery, _raw_research) so parser/discovery hooks survive save.
        $existing_profiles_raw = $wpdb->get_var($wpdb->prepare(
            "SELECT party_profiles FROM {$sources_table} WHERE id = %d",
            $source_id
        ));
        $existing_profiles = json_decode((string) ($existing_profiles_raw ?: '{}'), true);
        if (is_array($existing_profiles)) {
            foreach ($existing_profiles as $k => $v) {
                if (is_string($k) && strpos($k, '_') === 0 && !array_key_exists($k, $party_profiles)) {
                    $party_profiles[$k] = $v;
                }
            }
            $update_data['party_profiles'] = wp_json_encode($party_profiles);
        }

        $result = $wpdb->update(
            $sources_table,
            $update_data,
            ['id' => $source_id]
        );

        if ($result === false) {
            rawwire_log('party_investigator', sprintf(
                'DB update FAILED for source #%d: %s',
                $source_id,
                $wpdb->last_error
            ), 'error');
        } else {
            rawwire_log('party_investigator', sprintf(
                'DB update OK for source #%d: %d rows affected',
                $source_id,
                $result
            ));
        }

        // Now trigger scoring for this source
        $this->trigger_scoring_for_source($source_id);
    }

    // -------------------------------------------------------------------------
    // Investigation File Output + Cheap AI Population
    // -------------------------------------------------------------------------

    /**
     * Get the investigation output directory
     *
     * @return string Directory path (created if needed)
     */
    private function get_investigation_dir(): string
    {
        return $this->get_secure_output_dir('investigations');
    }

    /**
     * Resolve/create secure plugin-local output directory for investigator artifacts.
     *
     * @param string $subdir Child folder under plugin storage/reports
     * @return string
     */
    private function get_secure_output_dir(string $subdir): string
    {
        $plugin_root = dirname(__DIR__, 2);
        $base_dir = $plugin_root . '/storage/reports';
        $dir = $base_dir . '/' . trim($subdir, '/');

        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        @chmod($base_dir, 0750);
        @chmod($dir, 0750);

        $index_file = $base_dir . '/index.php';
        if (!file_exists($index_file)) {
            file_put_contents($index_file, "<?php\nif (!defined('ABSPATH')) { exit; }\n");
            @chmod($index_file, 0640);
        }

        $htaccess_file = $base_dir . '/.htaccess';
        if (!file_exists($htaccess_file)) {
            file_put_contents($htaccess_file, "Order allow,deny\nDeny from all\n");
            @chmod($htaccess_file, 0640);
        }

        return $dir;
    }

    /**
     * Dump all raw investigation findings to a single JSON file
     *
     * This is the intermediate output -- the investigator writes here,
     * and the cheap AI populator reads from here.
     *
     * @param int   $source_id      Source ID
     * @param array $source         Source record from DB
     * @param array $investigations Investigation results from per-party loop
     * @return string Path to the created file
     */
    private function dump_investigation_file(int $source_id, array $source, array $investigations): string
    {
        $dir = $this->get_investigation_dir();

        $data = [
            'source_id'       => $source_id,
            'permit_nbr'      => $source['permit_nbr'] ?? '',
            'address'         => $source['primary_address'] ?? $source['address'] ?? '',
            'investigated_at' => current_time('mysql'),
            'parties'         => [],
        ];

        foreach ($investigations as $inv) {
            $data['parties'][] = [
                'type'         => $inv['party_type'],
                'name'         => $inv['party_name'],
                'raw_findings' => $inv['profile']['raw_investigation'] ?? '',
                'method'       => $inv['profile']['investigation_method'] ?? 'unknown',
            ];
        }

        $file_path = $dir . '/source_' . $source_id . '.json';
        file_put_contents($file_path, wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($file_path, 0640);

        rawwire_log('party_investigator', sprintf(
            'Investigation file written: %s (%d bytes)',
            $file_path,
            filesize($file_path)
        ), 'debug');

        return $file_path;
    }

    /**
     * Use cheap AI to extract structured profiles from an investigation file
     *
     * Reads the investigation file, sends contents to a cheap AI model,
     * and returns structured profiles keyed by party type.
     *
     * @param string $file_path Path to investigation JSON file
     * @return array Extracted profiles keyed by party type, or empty array
     */
    private function extract_profiles_from_file(string $file_path): array
    {
        if (!file_exists($file_path)) {
            rawwire_log('party_investigator', 'Investigation file not found: ' . $file_path, 'warning');
            return [];
        }

        $contents = file_get_contents($file_path);
        if (empty($contents)) {
            return [];
        }

        require_once __DIR__ . '/class-openclaw-adapter.php';
        $adapter = new RawWire_OpenClaw_Adapter();

        if (!$adapter->is_available()) {
            rawwire_log('party_investigator', 'AI adapter not available for cheap AI extraction', 'warning');
            return [];
        }

        $prompt = $this->build_file_extraction_prompt($contents);

        $response = $adapter->chat([
            ['role' => 'system', 'content' => 'You extract structured intelligence profiles from raw investigation findings. Return ONLY valid JSON. No markdown fences, no explanation. Start with { and end with }.'],
            ['role' => 'user', 'content' => $prompt],
        ], [
            'max_tokens'  => 4000,
            'temperature' => 0.1,
        ]);

        if (empty($response)) {
            rawwire_log('party_investigator', 'Cheap AI extraction returned empty response', 'warning');
            return [];
        }

        $data = $this->extract_json_from_response($response);
        if (!is_array($data)) {
            rawwire_log('party_investigator', 'Cheap AI extraction: failed to parse JSON', 'warning');
            return [];
        }

        rawwire_log('party_investigator', sprintf(
            'Cheap AI extraction: %d party types populated',
            count($data)
        ));

        return $data;
    }

    /**
     * Build extraction prompt for cheap AI to parse investigation file
     *
     * @param string $findings Raw investigation file contents (JSON)
     * @return string Prompt text
     */
    private function build_file_extraction_prompt(string $findings): string
    {
        // Truncate if extremely long (keep under LLM context limits)
        if (strlen($findings) > 15000) {
            $findings = substr($findings, 0, 15000) . "

[Truncated for extraction]";
        }

        return <<<PROMPT
Extract structured intelligence from these raw investigation findings.

=== INVESTIGATION FILE ===
{$findings}
=== END FILE ===

Return JSON keyed by party type (contractor, owner, applicant). For each:
{
  "contractor": {
    "value_score": 0-100,
    "target_summary": "who this is and why valuable",
    "people": [{"name":"", "title":"", "authority":"final|recommender|influencer", "contact":{}, "notes":""}],
    "company": {"name":"", "type":"", "specialties":[], "scale":"", "notable_facts":[]},
    "networking_opportunities": {"associations":[], "upcoming_events":[], "charity_involvement":[]},
    "discovered_projects": [{"name":"", "status":"", "value":"", "location":""}],
    "discovered_entities": [{"name":"", "type":"", "relationship":"", "score_hint":0}],
    "entry_points": [],
    "outreach_strategy": "",
    "intelligence_gaps": [],
    "red_flags": [],
    "sources": []
  }
}

Extract ALL people, companies, projects mentioned. Be thorough. Use "" for unknowns.
PROMPT;
    }

    /**
     * Build deterministic per-entity deep-dive report from known source entities.
     *
     * Flow:
     *  - collect entities from source row + discovered_entities in profiles
     *  - run provider-backed deep search for each entity
     *  - write a single consolidated text report
     *
     * @return string Absolute report file path, or empty string on failure/no entities
     */
    private function build_entity_deep_dive_report(int $source_id, array $source, array $investigations): string
    {
        $entities = [];

        $seed_fields = [
            'contractor_name' => 'contractor',
            'owner_name'      => 'owner',
            'applicant_name'  => 'applicant',
            'principal_name'  => 'principal',
        ];

        foreach ($seed_fields as $field => $type) {
            $name = trim((string) ($source[$field] ?? ''));
            if ($name !== '' && !$this->is_unusable_party_name($name)) {
                $entities[] = [
                    'name' => $name,
                    'type' => $type,
                    'relationship' => 'source_record',
                ];
            }
        }

        // Always include investigated parties as deterministic entity seeds.
        foreach ($investigations as $inv) {
            $party_name = trim((string) ($inv['party_name'] ?? ''));
            if ($party_name === '' || $this->is_unusable_party_name($party_name)) {
                continue;
            }

            $entities[] = [
                'name' => $party_name,
                'type' => $this->normalize_entity_type((string) ($inv['party_type'] ?? 'contractor')),
                'relationship' => 'investigated_party',
            ];
        }

        foreach ($investigations as $inv) {
            $profile = $inv['profile'] ?? [];
            $discovered = $profile['discovered_entities'] ?? [];
            if (!is_array($discovered)) {
                continue;
            }

            foreach ($discovered as $entity) {
                $name = trim((string) ($entity['name'] ?? ''));
                $type = $this->normalize_entity_type((string) ($entity['type'] ?? ''));
                if ($name === '' || $this->is_unusable_party_name($name)) {
                    continue;
                }

                $entities[] = [
                    'name' => $name,
                    'type' => $type,
                    'relationship' => trim((string) ($entity['relationship'] ?? 'discovered_entity')),
                ];
            }
        }

        // De-duplicate by normalized type + name
        $deduped = [];
        foreach ($entities as $entity) {
            $key = strtolower($entity['type'] . '|' . $entity['name']);
            if (!isset($deduped[$key])) {
                $deduped[$key] = $entity;
            }
        }

        $entities = array_values($deduped);
        if (empty($entities)) {
            return '';
        }

        $report_lines = [];
        $report_lines[] = 'RAW-WIRE ENTITY DEEP DIVE REPORT';
        $report_lines[] = 'source_id: ' . $source_id;
        $report_lines[] = 'permit_nbr: ' . (string) ($source['permit_nbr'] ?? '');
        $report_lines[] = 'address: ' . (string) ($source['primary_address'] ?? $source['address'] ?? '');
        $report_lines[] = 'generated_at: ' . current_time('mysql');
        $report_lines[] = '';

        $max_entities = 8;
        $searched = 0;

        foreach ($entities as $entity) {
            if ($searched >= $max_entities) {
                break;
            }

            $party = [
                'type'    => $entity['type'],
                'name'    => $entity['name'],
                'company' => $entity['name'],
                'license' => (string) ($source['contractor_license'] ?? ''),
                'city'    => (string) ($source['city'] ?? 'Los Angeles'),
                'state'   => (string) ($source['state'] ?? 'CA'),
            ];

            $results = $this->search_party($party);

            $report_lines[] = '---';
            $report_lines[] = 'ENTITY: ' . $entity['name'];
            $report_lines[] = 'TYPE: ' . $entity['type'];
            $report_lines[] = 'RELATIONSHIP: ' . ($entity['relationship'] ?: 'unknown');

            if (empty($results)) {
                $report_lines[] = 'SEARCH_RESULTS: none';
                $report_lines[] = '';
                $searched++;
                continue;
            }

            $report_lines[] = 'SEARCH_RESULTS:';

            $snippet_count = 0;
            foreach ($results as $query_result) {
                if ($snippet_count >= 8) {
                    break;
                }

                $provider = (string) ($query_result['provider'] ?? 'unknown');
                $query = (string) ($query_result['query'] ?? 'research');
                $report_lines[] = '- query: ' . $query . ' | provider: ' . $provider;

                $items = $query_result['results'] ?? [];
                if (!is_array($items)) {
                    $items = [];
                }

                // Provider can return compact raw_findings blobs instead of itemized rows.
                if (isset($items['raw_findings']) && is_string($items['raw_findings'])) {
                    $raw_findings = trim((string) $items['raw_findings']);
                    if ($raw_findings !== '') {
                        $report_lines[] = '  * raw_findings: ' . preg_replace('/\s+/', ' ', substr($raw_findings, 0, 1200));
                        $snippet_count++;
                    }
                    continue;
                }

                foreach ($items as $item) {
                    if ($snippet_count >= 8) {
                        break;
                    }

                    if (!is_array($item)) {
                        continue;
                    }

                    $title = trim((string) ($item['title'] ?? ''));
                    $url = trim((string) ($item['url'] ?? ''));
                    $snippet = trim((string) ($item['snippet'] ?? $item['description'] ?? ''));

                    if ($title === '' && $url === '' && $snippet === '') {
                        continue;
                    }

                    $report_lines[] = sprintf(
                        '  * title: %s | url: %s | snippet: %s',
                        $title,
                        $url,
                        preg_replace('/\s+/', ' ', $snippet)
                    );
                    $snippet_count++;
                }
            }

            $report_lines[] = '';
            $searched++;
        }

        $dir = $this->get_secure_output_dir('entity-deep-dives');
        $file_path = $dir . '/source_' . $source_id . '_entity_report.txt';
        file_put_contents($file_path, implode("\n", $report_lines));
        @chmod($file_path, 0640);

        rawwire_log('party_investigator', sprintf(
            'Entity deep-dive report written: %s (entities=%d, searched=%d)',
            $file_path,
            count($entities),
            $searched
        ));

        return $file_path;
    }

    /**
     * Parse entity report into structured discovered fields for source column updates.
     */
    private function extract_discovered_parties_from_entity_report(string $report_path): array
    {
        if (!file_exists($report_path)) {
            return [];
        }

        $report = (string) file_get_contents($report_path);
        if ($report === '') {
            return [];
        }

        if (strlen($report) > 18000) {
            $report = substr($report, 0, 18000) . "\n\n[Truncated for parser]";
        }

        require_once __DIR__ . '/class-openclaw-adapter.php';

        $messages = [
            ['role' => 'system', 'content' => 'Extract structured permit party fields from investigation reports. Return ONLY JSON. No markdown fences or prose.'],
            ['role' => 'user', 'content' => $this->build_entity_report_parse_prompt($report)],
        ];

        $responses = [];

        // Primary parser model requested by workflow spec.
        $adapter_gpt5 = new RawWire_OpenClaw_Adapter(null, null, 'gpt-5-mini');
        if ($adapter_gpt5->is_available()) {
            $responses[] = $adapter_gpt5->chat($messages, [
                'max_tokens'  => 1200,
                'temperature' => 0.0,
            ]);
        }

        // Fallback parser model for compatibility if gpt-5-mini route is unavailable.
        $adapter_default = new RawWire_OpenClaw_Adapter();
        if ($adapter_default->is_available()) {
            $responses[] = $adapter_default->chat($messages, [
                'max_tokens'  => 1200,
                'temperature' => 0.0,
            ]);
        }

        foreach ($responses as $response) {
            if (empty($response)) {
                continue;
            }

            $discovered = $this->parse_discovery_response($response);
            if (!empty($discovered)) {
                $discovered['_discovery_meta'] = [
                    'confidence'    => $discovered['_discovery_meta']['confidence'] ?? 'medium',
                    'method'        => 'entity_deep_dive_report_parser',
                    'sources'       => ['entity_report:' . basename($report_path)],
                    'discovered_at' => current_time('mysql'),
                ];

                rawwire_log('party_investigator', sprintf(
                    'Entity report parser extracted fields: %s',
                    implode(', ', array_keys(array_diff_key($discovered, ['_discovery_meta' => true])))
                ));

                return $discovered;
            }
        }

        // Deterministic fallback: regex extraction from report text
        $fallback = $this->parse_entity_report_fallback($report);
        if (!empty($fallback)) {
            $fallback['_discovery_meta'] = [
                'confidence'    => 'medium',
                'method'        => 'entity_deep_dive_report_parser_fallback',
                'sources'       => ['entity_report:' . basename($report_path)],
                'discovered_at' => current_time('mysql'),
            ];

            rawwire_log('party_investigator', sprintf(
                'Entity report fallback extracted fields: %s',
                implode(', ', array_keys($fallback))
            ));

            return $fallback;
        }

        rawwire_log('party_investigator', 'Entity report parser produced no usable fields', 'warning');
        return [];
    }

    /**
     * Deterministic parser for entity report text when model extraction returns empty.
     */
    private function parse_entity_report_fallback(string $report): array
    {
        $discovered = [];

        if (preg_match('/^ENTITY:\s*(.+)$/mi', $report, $m)) {
            $name = trim((string) ($m[1] ?? ''));
            if ($name !== '' && !$this->is_unusable_party_name($name)) {
                $discovered['contractor_name'] = $name;
            }
        }

        if (preg_match('/\b(?:Lic(?:ense)?\s*:?\s*#?|License\s*#?\s*)(\d{5,7}(?:-[A-Za-z])?)\b/i', $report, $m)) {
            $discovered['contractor_license'] = $this->clean_license_number((string) $m[1]);
        }

        if (preg_match('/qualifying\s+individual\s+for\s+[^\n]*?\s+is\s+([A-Z][A-Za-z\s\.-]{2,120})/i', $report, $m)) {
            $principal = trim((string) ($m[1] ?? ''));
            $principal = rtrim($principal, ' .,;');
            if ($principal !== '' && !$this->is_unusable_party_name($principal)) {
                $discovered['principal_name'] = $principal;
            }
        }

        if (preg_match('/\bowner\s*:?\s*([A-Z][A-Za-z\s\.,&-]{2,120})/i', $report, $m)) {
            $owner = trim((string) ($m[1] ?? ''));
            $owner = rtrim($owner, ' .,;');
            if ($owner !== '' && !$this->is_unusable_party_name($owner)) {
                $discovered['owner_name'] = $owner;
            }
        }

        if (preg_match('/\bapplicant\s*:?\s*([A-Z][A-Za-z\s\.,&-]{2,120})/i', $report, $m)) {
            $applicant = trim((string) ($m[1] ?? ''));
            $applicant = rtrim($applicant, ' .,;');
            if ($applicant !== '' && !$this->is_unusable_party_name($applicant)) {
                $discovered['applicant_name'] = $applicant;
            }
        }

        return $discovered;
    }

    /**
     * Prompt for parsing entity deep-dive report into DB-safe source fields.
     */
    private function build_entity_report_parse_prompt(string $report): string
    {
        return <<<PROMPT
Parse this entity deep-dive report and extract ONLY concrete fields for permit parties.

Return JSON with ONLY these keys (omit unknowns):
- contractor_name
- contractor_license
- applicant_name
- owner_name
- principal_name
- contractor_address
- contractor_city
- contractor_state
- confidence
- sources

Rules:
- Return ONLY valid JSON object.
- Do not include markdown.
- Do not infer or invent unknown values.
- Prefer exact names and license numbers from evidence.

REPORT:
{$report}
PROMPT;
    }

    /**
     * Normalize free-form entity type labels to known investigation party types.
     */
    private function normalize_entity_type(string $type): string
    {
        $normalized = strtolower(trim($type));

        if (in_array($normalized, ['contractor', 'gc', 'general_contractor'], true)) {
            return 'contractor';
        }
        if (in_array($normalized, ['owner', 'property_owner'], true)) {
            return 'owner';
        }
        if (in_array($normalized, ['applicant', 'permit_applicant'], true)) {
            return 'applicant';
        }
        if (in_array($normalized, ['principal', 'signatory'], true)) {
            return 'principal';
        }

        return $normalized !== '' ? $normalized : 'contractor';
    }

    /**
     * Trigger scoring for a source after investigation completes
     */
    private function trigger_scoring_for_source(int $source_id): void
    {
        if (!function_exists('rawwire_leads')) {
            return;
        }

        $leads = rawwire_leads();

        // Mark as unprocessed so scorer picks it up
        global $wpdb;
        $sources_table = $leads->table('sources');

        $wpdb->update(
            $sources_table,
            ['processed' => 0],
            ['id' => $source_id]
        );

        // Run scoring for this specific source
        $leads->score_unprocessed(1);

        rawwire_log('party_investigator', sprintf(
            'Triggered scoring for source #%d after investigation',
            $source_id
        ));
    }

    // -------------------------------------------------------------------------
    // AJAX Handlers
    // -------------------------------------------------------------------------

    /**
     * AJAX: Investigate a single party
     */
    public function ajax_investigate_party(): void
    {
        check_ajax_referer('rawwire_dashboard_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        $party = [
            'type'    => sanitize_text_field($_POST['party_type'] ?? 'unknown'),
            'name'    => sanitize_text_field($_POST['party_name'] ?? ''),
            'company' => sanitize_text_field($_POST['company'] ?? ''),
            'license' => sanitize_text_field($_POST['license'] ?? ''),
            'city'    => sanitize_text_field($_POST['city'] ?? 'Los Angeles'),
            'state'   => sanitize_text_field($_POST['state'] ?? 'CA'),
        ];

        if (empty($party['name'])) {
            wp_send_json_error(['message' => 'Party name required']);
        }

        if (!$this->is_available()) {
            wp_send_json_error(['message' => 'Search provider not configured or unreachable. Go to AI Settings.']);
        }

        $search_results = [];
        $profile = $this->investigate_party_via_agent($party, []);

        if ($profile === null) {
            wp_send_json_error([
                'message' => 'Lane A investigation failed. OpenClaw agent/browser path must be healthy.',
            ]);
        }

        wp_send_json_success([
            'party'          => $party,
            'profile'        => $profile,
            'search_results' => $search_results,
        ]);
    }

    /**
     * AJAX: Investigate all parties for a source
     */
    public function ajax_investigate_source(): void
    {
        check_ajax_referer('rawwire_dashboard_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        $source_id = intval($_POST['source_id'] ?? 0);

        if (!$source_id) {
            wp_send_json_error(['message' => 'Source ID required']);
        }

        $result = $this->investigate_source_parties($source_id);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success($result);
    }

    // -------------------------------------------------------------------------
    // Integration with Pipeline
    // -------------------------------------------------------------------------

    /**
     * Hook into permit pipeline to investigate after insert
     *
     * @param int $source_id
     */
    public function on_source_inserted(int $source_id): void
    {
        $settings = self::get_settings();

        if ($settings['auto_investigate'] && $this->is_available()) {
            // Schedule async investigation
            wp_schedule_single_event(time(), 'rawwire_investigate_source_parties', [$source_id]);
        }
    }

    /**
     * AJAX handler to render investigation display
     */
    public function ajax_get_investigation_display(): void
    {
        check_ajax_referer('rawwire_admin_nonce', 'nonce');

        $source_id = absint($_POST['source_id'] ?? 0);
        if (!$source_id) {
            wp_send_json_error(['message' => 'Missing source ID']);
        }

        // Get source data from database
        global $wpdb;
        $table = $wpdb->prefix . 'rawwire_sources';
        $source = $wpdb->get_row(
            $wpdb->prepare("SELECT party_profiles, owner, contractor FROM {$table} WHERE id = %d", $source_id),
            ARRAY_A
        );

        if (!$source || empty($source['party_profiles'])) {
            wp_send_json_error(['message' => 'No investigation data found']);
        }

        $party_profiles = json_decode($source['party_profiles'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(['message' => 'Invalid investigation data']);
        }

        // Build display data structure
        $display_data = [
            'id'          => $source_id,
            'company'     => $source['owner'] ?: $source['contractor'],
            'tier'        => 2, // Will be calculated by the template
            'last_update' => current_time('mysql'),
            'categories'  => [],
        ];

        // Map party profiles to display categories
        foreach ($party_profiles as $party_type => $profile) {
            if (empty($profile['findings'])) {
                continue;
            }

            foreach ($profile['findings'] as $finding) {
                $category = $this->map_finding_category($finding['category'] ?? 'edge_intel');
                if (!isset($display_data['categories'][$category])) {
                    $display_data['categories'][$category] = [];
                }
                $display_data['categories'][$category][] = [
                    'title'      => $finding['title'] ?? '',
                    'value'      => $finding['value'] ?? '',
                    'confidence' => $finding['confidence'] ?? 'inferred',
                    'source'     => $finding['source'] ?? '',
                ];
            }
        }

        // Render the HTML
        $html = '';
        if (function_exists('rawwire_render_investigation')) {
            $html = rawwire_render_investigation($display_data);
        } else {
            $html = '<div class="notice notice-error"><p>Investigation template not loaded</p></div>';
        }

        wp_send_json_success([
            'html' => $html,
            'data' => $display_data,
        ]);
    }

    /**
     * Map finding category to display category
     */
    private function map_finding_category(string $category): string
    {
        $mapping = [
            'contact'      => 'contacts',
            'contacts'     => 'contacts',
            'phone'        => 'contacts',
            'email'        => 'contacts',
            'project'      => 'projects',
            'projects'     => 'projects',
            'relationship' => 'relationships',
            'relationships' => 'relationships',
            'event'        => 'gatherings',
            'gatherings'   => 'gatherings',
            'affiliation'  => 'affiliations',
            'affiliations' => 'affiliations',
            'community'    => 'community',
            'edge'         => 'edge_intel',
            'edge_intel'   => 'edge_intel',
        ];
        return $mapping[strtolower($category)] ?? 'edge_intel';
    }
}

/**
 * Get Party Investigator instance
 */
function rawwire_party_investigator(): RawWire_Party_Investigator
{
    return RawWire_Party_Investigator::get_instance();
}
