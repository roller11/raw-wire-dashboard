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

    /** @var string OpenClaw gateway API base (Venice.ai direct) */
    const OPENCLAW_API_BASE = 'https://api.venice.ai/api/v1';

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
            'investigation_model' => 'olafangensan-glm-4.7-flash-heretic', // Venice GLM 4.7 Flash Heretic
            'search_provider'     => 'openclaw', // duckduckgo, brave, openclaw
            'openclaw_auth_token' => 'rawwire-local-dev-2025', // OpenClaw auth token
            'deep_research'       => true,       // Use browser for full page content
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

        // Check selected provider
        $provider = $settings['search_provider'] ?? 'duckduckgo';

        switch ($provider) {
            case 'duckduckgo':
                return true; // DuckDuckGo requires no API key
            case 'openclaw':
                return $this->is_openclaw_available();
            case 'brave':
            default:
                return !empty($settings['brave_api_key']);
        }
    }

    /**
     * Check if OpenClaw/Venice gateway is available
     */
    private function is_openclaw_available(): bool
    {
        $settings = self::get_settings();
        // Auth token: prefer openclaw token, fall back to Venice API key
        $auth_token = $settings['openclaw_auth_token'] ?? '';
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
        $settings = self::get_settings();
        return $settings['search_provider'] ?? 'brave';
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
     * Uses a multi-step approach:
     * 1. Try LADBS browser scraping first (if we have an address) - this works for JS SPAs
     * 2. Fall back to Venice web search if browser fails
     * 3. Extract structured party data from findings
     *
     * @param array $source Row from rawwire_lead_sources table
     * @return array Discovered party names keyed by field, empty if nothing found
     */
    public function discover_parties_from_permit(array $source): array
    {
        $permit_nbr  = $source['permit_nbr'] ?? '';
        $address     = $source['primary_address'] ?? '';
        $work_desc   = $source['work_desc'] ?? '';
        $permit_type = $source['permit_type'] ?? '';
        $valuation   = $source['valuation'] ?? '';

        // Need at least a permit number or address to search
        if (empty($permit_nbr) && empty($address)) {
            rawwire_log('party_investigator', 'Discovery skipped: no permit number or address', 'debug');
            return [];
        }

        rawwire_log('party_investigator', sprintf(
            'Discovering parties for permit %s at %s',
            $permit_nbr,
            $address
        ));

        // Use OpenClaw adapter
        require_once __DIR__ . '/class-openclaw-adapter.php';
        $adapter = new RawWire_OpenClaw_Adapter();

        // =====================================================================
        // STEP 0: Try AGENT-POWERED LADBS lookup (resilient — agent navigates itself)
        // =====================================================================
        if ($adapter->is_available() && (!empty($permit_nbr) || !empty($address))) {
            rawwire_log('party_investigator', 'Attempting agent-powered LADBS discovery...', 'info');

            $ladbs_prompt = $adapter->build_agent_research_prompt([
                'name'            => '',  // No name yet — that's what we're discovering
                'type'            => 'permit_lookup',
                'permit_nbr'     => $permit_nbr,
                'project_address' => $address,
                'location'        => 'Los Angeles, California',
            ]);

            $agent_result = $adapter->agent_chat($ladbs_prompt, [
                'timeout' => 180000,  // 3 minutes for LADBS lookup
                'json'    => true,
            ]);

            if ($agent_result['success'] && !empty($agent_result['content'])) {
                rawwire_log('party_investigator', sprintf(
                    'Agent LADBS discovery returned %d chars',
                    strlen($agent_result['content'])
                ), 'info');

                // Extract party names from agent's findings
                $discovered = $this->extract_parties_from_text($agent_result['content']);

                // Also try JSON extraction in case agent returned structured data
                if (empty($discovered)) {
                    $extraction = $adapter->chat([
                        ['role' => 'system', 'content' => 'Extract structured data from research findings. Return ONLY valid JSON, no markdown, no explanation.'],
                        ['role' => 'user', 'content' => $this->build_extraction_prompt($agent_result['content'])],
                    ], [
                        'max_tokens'  => 1000,
                        'temperature' => 0.1,
                    ]);
                    if (!empty($extraction)) {
                        $discovered = $this->parse_discovery_response($extraction);
                    }
                }

                if (!empty($discovered)) {
                    $this->apply_discovered_parties($source['id'], $discovered, 'Agent LADBS lookup');
                    rawwire_log('party_investigator', sprintf(
                        'Agent discovered %d party fields: %s',
                        count($discovered),
                        implode(', ', array_keys($discovered))
                    ));
                    return $discovered;
                }

                // Agent found something but we couldn't extract — save raw
                $this->save_raw_research($source['id'], $agent_result['content']);
            } else {
                $error = $agent_result['error'] ?? 'no response';
                rawwire_log('party_investigator', 'Agent LADBS lookup failed: ' . $error, 'warning');
            }
        }

        // =====================================================================
        // STEP 1: Fallback — hardcoded LADBS browser scraping (fragile but fast)
        // =====================================================================
        $ladbs_data = null;
        if (!empty($address)) {
            rawwire_log('party_investigator', 'Falling back to hardcoded LADBS browser scrape for: ' . $address, 'info');
            $ladbs_result = $adapter->scrape_ladbs_permit($address);

            if ($ladbs_result['success'] && !empty($ladbs_result['permits'])) {
                rawwire_log('party_investigator', sprintf(
                    'LADBS browser scrape SUCCESS: found %d permits',
                    count($ladbs_result['permits'])
                ), 'info');
                $ladbs_data = $ladbs_result['permits'];
            } else {
                $error = $ladbs_result['error'] ?? 'no permits found';
                rawwire_log('party_investigator', 'LADBS browser scrape failed: ' . $error, 'warning');
            }
        }

        // If LADBS gave us data, extract parties from it
        if (!empty($ladbs_data)) {
            $discovered = $this->extract_parties_from_ladbs($ladbs_data, $permit_nbr);
            if (!empty($discovered)) {
                $this->apply_discovered_parties($source['id'], $discovered, 'LADBS browser scrape');
                rawwire_log('party_investigator', sprintf(
                    'Discovered %d party fields from LADBS browser: %s',
                    count($discovered),
                    implode(', ', array_keys($discovered))
                ));
                return $discovered;
            }
        }

        // =====================================================================
        // STEP 2: Venice API analysis (no browser tools — AI knowledge only)
        // =====================================================================
        if (!$adapter->is_available()) {
            rawwire_log('party_investigator', 'Discovery failed: OpenClaw/Venice not available', 'warning');
            return [];
        }

        $research_prompt = $this->build_discovery_research_prompt($permit_nbr, $address, $work_desc, $permit_type, $valuation);

        $research = $adapter->chat([
            ['role' => 'system', 'content' => 'You are a commercial real estate and construction industry researcher. Use your training knowledge to analyze this permit. Identify likely property owners, contractors, and architects based on the permit details, address, and any patterns you recognize. Note: you do NOT have web access — work from your knowledge base.'],
            ['role' => 'user', 'content' => $research_prompt],
        ], [
            'max_tokens'  => 2500,
            'temperature' => 0.3,
        ]);

        if (empty($research) || strlen($research) < 100) {
            rawwire_log('party_investigator', 'Discovery step 2 failed: insufficient research response', 'warning');
            return [];
        }

        rawwire_log('party_investigator', sprintf(
            'Discovery research returned %d chars, extracting parties...',
            strlen($research)
        ));

        // STEP 3: Extract structured data from the research
        $extraction = $adapter->chat([
            ['role' => 'system', 'content' => 'Extract structured data from research findings. Return ONLY valid JSON, no markdown, no explanation.'],
            ['role' => 'user', 'content' => $this->build_extraction_prompt($research)],
        ], [
            'max_tokens'  => 1000,
            'temperature' => 0.1,
        ]);

        if (empty($extraction)) {
            // Fallback: try to extract from the raw research text
            $discovered = $this->extract_parties_from_text($research);
        } else {
            $discovered = $this->parse_discovery_response($extraction);
        }

        // If JSON extraction failed, try text extraction from research
        if (empty($discovered) && !empty($research)) {
            $discovered = $this->extract_parties_from_text($research);
        }

        if (empty($discovered)) {
            rawwire_log('party_investigator', 'Discovery: could not extract structured party data', 'info');
            // Still save the raw research as intelligence
            $this->save_raw_research($source['id'], $research);
            return [];
        }

        // Update the source record with discovered data
        $this->apply_discovered_parties($source['id'], $discovered, $research);

        rawwire_log('party_investigator', sprintf(
            'Discovered %d party fields for permit %s: %s',
            count($discovered),
            $permit_nbr,
            implode(', ', array_keys($discovered))
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
            if (strlen($name) > 3 && strlen($name) < 200) {
                $discovered['owner_name'] = $name;
            }
        }

        // Look for contractor patterns
        if (preg_match('/(?:general contractor|contractor[:\s]+|GC[:\s]+)\s*\*?\*?([A-Z][A-Za-z\s,\.&]+(?:Inc|LLC|Corp|Construction|Builders?|Development|Contracting)?)\*?\*?/i', $text, $m)) {
            $name = trim(preg_replace('/\*+/', '', $m[1]));
            $name = rtrim($name, ' .,');
            if (strlen($name) > 3 && strlen($name) < 200) {
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
        // Load provider factory
        require_once __DIR__ . '/class-search-provider-factory.php';

        rawwire_log('party_investigator', sprintf(
            'Starting search for %s: %s',
            $party['type'] ?? 'unknown',
            $party['name'] ?? 'unknown'
        ), 'debug');

        // Use factory for automatic provider selection and fallback
        $result = RawWire_Search_Provider_Factory::research($party);

        if (!$result['success']) {
            rawwire_log('party_investigator', 'All providers failed for: ' . ($party['name'] ?? 'unknown'), 'warning');
            return [];
        }

        rawwire_log('party_investigator', sprintf(
            'Search complete via %s, %d results',
            $result['source'] ?? 'unknown',
            $result['search_count'] ?? count($result['results'] ?? [])
        ), 'debug');

        // Normalize to expected format for analyze_with_ai()
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
            'timeout' => 300000,  // 5 minutes — agent needs time to browse
            'json'    => true,
        ]);

        if (!$agent_result['success'] || empty($agent_result['content'])) {
            $error = $agent_result['error'] ?? 'empty response';
            rawwire_log('party_investigator', 'Agent investigation failed: ' . $error, 'warning');
            return null;
        }

        $investigation_text = $agent_result['content'];
        rawwire_log('party_investigator', sprintf(
            'Agent returned %d chars of investigation for %s',
            strlen($investigation_text),
            $party['name'] ?? 'unknown'
        ), 'info');

        // STEP 2: Extract structured JSON from the rich investigation
        $extraction_prompt = $this->build_agent_extraction_prompt($party, $investigation_text);

        $analysis_max_tokens = (int) $adapter->get_setting('analysis_max_tokens', 8000);
        $analysis_temperature = (float) $adapter->get_setting('analysis_temperature', 0.3);

        $extraction_response = $adapter->chat([
            ['role' => 'system', 'content' => 'You are a data extraction specialist. Extract structured data from the investigation report below. Return ONLY valid JSON — no markdown fences, no explanation text. Start with { and end with }.'],
            ['role' => 'user', 'content' => $extraction_prompt],
        ], [
            'max_tokens'        => $analysis_max_tokens,
            'temperature'       => $analysis_temperature,
            'enable_web_search' => false,
        ]);

        $profile = null;
        if (!empty($extraction_response)) {
            $profile = $this->extract_json_from_response($extraction_response);
        }

        if ($profile === null) {
            rawwire_log('party_investigator', 'JSON extraction failed from agent findings — storing raw only', 'warning');
            // Return a minimal profile with the raw investigation attached
            $profile = $this->fallback_analysis($party, []);
        }

        // Attach the raw investigation report for display
        $profile['raw_investigation'] = $investigation_text;
        $profile['investigation_method'] = 'agent_browser';

        rawwire_log('party_investigator', sprintf(
            'Agent investigation complete for %s — value_score: %s, people: %d',
            $party['name'] ?? 'unknown',
            $profile['value_score'] ?? 'N/A',
            count($profile['people'] ?? [])
        ), 'info');

        return $profile;
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
     * Analyze search results with AI to extract structured data
     *
     * This is the FALLBACK path when agent investigation is unavailable.
     * Uses traditional search results + Venice API chat (no browser tools).
     *
     * @param array $party Party data
     * @param array $search_results Results from search_party()
     * @return array Structured profile
     */
    public function analyze_with_ai(array $party, array $search_results): array
    {
        rawwire_log('party_investigator', 'Starting AI analysis (fallback) for: ' . ($party['name'] ?? 'unknown'), 'debug');

        $prompt = $this->build_analysis_prompt($party, $search_results);
        rawwire_log('party_investigator', 'Built prompt, sending to AI...', 'debug');

        // Venice API chat — no browser tools, uses pre-gathered search results
        $result = $this->analyze_via_openclaw($prompt);

        if ($result !== null) {
            $result['investigation_method'] = 'api_search_fallback';
            rawwire_log('party_investigator', 'AI analysis complete via Venice API (fallback)', 'debug');
            return $result;
        }

        rawwire_log('party_investigator', 'OpenClaw analysis failed — returning fallback', 'warning');
        return $this->fallback_analysis($party, $search_results);
    }

    /**
     * Run analysis through OpenClaw/Venice adapter directly
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
        $analysis_max_tokens = (int) $adapter->get_setting('analysis_max_tokens', 8000);
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
     * NOTE: Prompt has been condensed to stay under ~2KB to prevent Venice API timeouts.
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

    /**
     * Fallback analysis when AI unavailable
     */
    private function fallback_analysis(array $party, array $search_results): array
    {
        // Use 'people' array format to match AI output schema and Soothsayer expectations
        $profile = [
            'value_score' => 20,
            'target_summary' => sprintf(
                '%s (%s) - Manual research recommended.',
                $party['name'],
                ucfirst($party['type'] ?? 'contact')
            ),
            'people' => [
                [
                    'name'      => $party['name'],
                    'title'     => ucfirst($party['type'] ?? 'Contact'),
                    'authority' => 'unknown',
                    'contact'   => [],
                    'notes'     => 'AI analysis unavailable - manual research recommended.',
                ],
            ],
            'company' => [
                'name'         => $party['company'] ?? 'Unknown',
                'type'         => 'construction',
                'specialties'  => [],
                'scale'        => 'unknown',
                'notable_facts' => [],
            ],
            'intelligence_gaps' => ['AI analysis failed - recommend manual web research'],
            'sources' => [],
        ];

        // Extract URLs from search results
        foreach ($search_results as $queryResult) {
            if (!is_array($queryResult) || !isset($queryResult['results'])) {
                continue;
            }

            // Handle OpenClaw raw findings format
            if (isset($queryResult['results']['raw_findings'])) {
                $profile['sources'][] = 'OpenClaw research';
                $profile['raw_findings'] = $queryResult['results']['raw_findings'];
                continue;
            }

            if (!is_array($queryResult['results'])) {
                continue;
            }

            foreach ($queryResult['results'] as $r) {
                if (!is_array($r) || !isset($r['url'])) {
                    continue;
                }
                $profile['sources'][] = $r['url'];

                // Extract LinkedIn if found
                if (strpos($r['url'], 'linkedin.com') !== false) {
                    $profile['people'][0]['contact']['linkedin'] = $r['url'];
                }
            }
        }

        $profile['outreach_strategy'] = 'AI analysis unavailable. Manual research recommended based on search results.';

        return $profile;
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
            return new WP_Error('not_available', 'Party investigation not available. Configure Brave API key.');
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
            $last_check = strtotime($source['parties_investigated_at']);
            if (time() - $last_check < 86400) { // Don't re-investigate within 24h
                return [
                    'skipped'   => true,
                    'reason'    => 'Recently investigated',
                    'source_id' => $source_id,
                ];
            }
        }

        $parties = $this->extract_parties($source);

        // If no parties found, try permit-based discovery via Grok web search
        if (empty($parties)) {
            rawwire_log('party_investigator', sprintf(
                'No pre-existing parties for source #%d, attempting permit discovery...',
                $source_id
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

        foreach ($parties as $party) {
            rawwire_log('party_investigator', sprintf(
                'Investigating %s: %s',
                $party['type'],
                $party['name']
            ));

            // PRIMARY PATH: Agent-powered investigation (browser tools, real web browsing)
            $search_results = [];
            $profile = $this->investigate_party_via_agent($party, $source);

            // FALLBACK PATH: Traditional search + API analysis (no browser tools)
            if ($profile === null) {
                rawwire_log('party_investigator', sprintf(
                    'Agent unavailable for %s — falling back to search + API analysis',
                    $party['name']
                ), 'info');

                $search_results = $this->search_party($party);
                $profile = $this->analyze_with_ai($party, $search_results);
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

        // Store investigation results
        $this->save_investigations($source_id, $investigations);

        rawwire_log('party_investigator', sprintf(
            'Completed investigation for source #%d — %d parties',
            $source_id,
            count($investigations)
        ));

        return [
            'success'        => true,
            'source_id'      => $source_id,
            'parties_count'  => count($investigations),
            'investigations' => $investigations,
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

        // Quality gate: determine if the investigation produced real intelligence
        $quality = $this->assess_investigation_quality($party_profiles);

        rawwire_log('party_investigator', sprintf(
            'Saving investigations for source #%d: %d profiles, quality=%s (score=%d)',
            $source_id,
            count($party_profiles),
            $quality['status'],
            $quality['score']
        ));

        $update_data = [
            'party_profiles'          => wp_json_encode($party_profiles),
            'parties_investigated_at' => current_time('mysql'),
            'investigation_status'    => $quality['status'],
        ];

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
                'DB update OK for source #%d: %d rows affected (status: %s)',
                $source_id,
                $result,
                $quality['status']
            ));

            if ($quality['status'] === 'failed') {
                rawwire_log('party_investigator', sprintf(
                    'Quality gate REJECTED source #%d: %s',
                    $source_id,
                    $quality['reason']
                ), 'warning');
            }
        }

        // Only trigger scoring if quality gate passed
        if ($quality['status'] === 'completed') {
            $this->trigger_scoring_for_source($source_id);
        }
    }

    /**
     * Assess whether investigation results contain real intelligence.
     *
     * Prevents garbage data (error messages, empty profiles, generic fallbacks)
     * from being marked as "completed". Returns a quality verdict.
     *
     * @param array $party_profiles Map of party_type => [name, profile]
     * @return array{status: string, score: int, reason: string}
     */
    private function assess_investigation_quality(array $party_profiles): array
    {
        if (empty($party_profiles)) {
            return ['status' => 'failed', 'score' => 0, 'reason' => 'No profiles at all'];
        }

        $total_score = 0;
        $profile_count = 0;

        foreach ($party_profiles as $type => $entry) {
            $profile = $entry['profile'] ?? [];
            $raw = $profile['raw_investigation'] ?? '';
            $score = 0;

            // Check for error messages masquerading as investigation results
            $error_signatures = [
                'EACCES', 'permission denied', 'Error:', 'not found',
                'No API key', 'FailoverError', 'timeout', 'SIGTERM',
            ];
            foreach ($error_signatures as $sig) {
                if (stripos($raw, $sig) !== false && strlen($raw) < 500) {
                    // Short text containing error = garbage
                    return ['status' => 'failed', 'score' => 0, 'reason' => "Error in raw_investigation: {$sig}"];
                }
            }

            // Score based on what's actually populated
            if (!empty($profile['company']['name'])) {
                $score += 15;
            }
            if (!empty($profile['people']) && is_array($profile['people']) && count($profile['people']) > 0) {
                $score += 25; // People are the most valuable intelligence
            }
            if (!empty($profile['target_summary']) && strlen($profile['target_summary']) > 20) {
                $score += 10;
            }
            if (($profile['value_score'] ?? 0) > 0) {
                $score += 10;
            }
            if (!empty($profile['entry_points']) && is_array($profile['entry_points'])) {
                $score += 10;
            }
            if (!empty($profile['networking_opportunities']) && is_array($profile['networking_opportunities'])) {
                $score += 10;
            }
            if (!empty($profile['sources']) && is_array($profile['sources'])) {
                $score += 5;
            }
            // Raw investigation text length is a basic quality signal
            if (strlen($raw) > 2000) {
                $score += 10;
            } elseif (strlen($raw) > 500) {
                $score += 5;
            }

            $total_score += $score;
            $profile_count++;
        }

        if ($profile_count === 0) {
            return ['status' => 'failed', 'score' => 0, 'reason' => 'No valid profiles'];
        }

        $avg_score = (int) ($total_score / $profile_count);

        // Threshold: at least 20 points average = some real data found
        if ($avg_score >= 20) {
            return ['status' => 'completed', 'score' => $avg_score, 'reason' => 'Passed quality gate'];
        }

        return [
            'status' => 'failed',
            'score'  => $avg_score,
            'reason' => "Quality score too low ({$avg_score}/100) — insufficient intelligence gathered",
        ];
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
            wp_send_json_error(['message' => 'Brave API key not configured. Go to AI Settings.']);
        }

        $search_results = $this->search_party($party);
        $profile = $this->analyze_with_ai($party, $search_results);

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
