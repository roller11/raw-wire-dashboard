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
 * - OpenClaw agent/browser path for live web investigation
 * - OpenClaw adapter for extraction and orchestration
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
            'enabled'             => true,
            'pipeline_mode'       => 'veniceclaw',
            'search_depth'        => 'standard', // basic, standard, deep
            'auto_investigate'    => true,       // Automatically investigate after insert
            'investigation_model' => '',
            'search_provider'     => 'openclaw', // Lane A only
            'openclaw_auth_token' => 'rawwire-local-dev-2025', // OpenClaw auth token
            'deep_research'       => true,       // Use browser for full page content
            'mandatory_multi_pass' => true,
            'reinvestigation_cooldown_minutes' => 1, // Manual re-run cooldown (minutes)
            'max_searches_per_party' => 3,
            'cache_hours' => 24,
            'perplexity_pass_count' => 2,
            'perplexity_preset' => 'pro-search',
            'perplexity_max_steps' => '',
            'perplexity_search_mode' => 'web',
            'perplexity_top_p' => '',
            'perplexity_reasoning_effort' => '',
            'perplexity_return_images' => false,
            'perplexity_return_related_questions' => false,
            'perplexity_enable_search_classifier' => true,
            'perplexity_disable_search' => false,
            'perplexity_strip_thinking_response' => true,
            'perplexity_model_pass_1' => '',
            'perplexity_model_pass_2' => '',
            'perplexity_model_pass_3' => '',
            'perplexity_prompt_pass_1' => self::DEFAULT_PERPLEXITY_PROMPT_PASS_1,
            'perplexity_prompt_pass_2' => self::DEFAULT_PERPLEXITY_PROMPT_PASS_2,
            'perplexity_prompt_pass_3' => self::DEFAULT_PERPLEXITY_PROMPT_PASS_3,
        ]);
    }

    private const DEFAULT_PERPLEXITY_PROMPT_PASS_1 = <<<'PROMPT'
[BASE_PROMPT]

PERPLEXITY RESPONSES API RESEARCH INPUT (MANDATORY):
- Use Perplexity native web research for this exact target.
- Keep the query specific and contextual so search results stay on the intended entity.
- Focus on one entity only: [PARTY_NAME]. Use permit and license context to disambiguate same-name companies.
- Permit context: [PERMIT_NUMBER]
- License context: [LICENSE_NUMBER]
- Prioritize decision makers, gatekeepers, bidding contacts, procurement paths, upcoming events, current projects, partnerships, subcontractors, and relationship signals.
- Prefer public, target-specific sources such as company pages, leadership pages, registry records, project pages, event pages, sponsor rosters, procurement portals, and credible news coverage.
- If reliable public information is unavailable, state that clearly instead of guessing.
- Do not output source URLs in the narrative text. Verified source metadata will be attached separately by the system.
- Return the final dossier in the required section order with SEARCH LEDGER and EVIDENCE LOG entries based only on verifiable findings.
PROMPT;

    private const DEFAULT_PERPLEXITY_PROMPT_PASS_2 = <<<'PROMPT'
You are a professional private investigator. Your client has tasked you with a digital deep dive investigation into [INVESTIGATION_TARGET]. To be successful, you must find the names and roles of the key decision makers and gatekeepers of this company. We need contact information and actionable intelligence that will put our client in the right place at the right time. Of particular interest are bidding contacts and processes. Upcoming events, current and future projects, announcements, ground breaking ceremonies, commitments, outreach programs, partnerships, sub-contractors, as well as any notable relationships with other companies and individuals. Be thorough and track down leads to find the good information. This information is critical to our purpose and must come with sources for verification purposes. We are counting on you to bring this company's network to light.

SECOND PASS REQUIREMENTS (MANDATORY):
- Treat this as a fresh-slate investigation, but stay anchored to the exact target identity supplied in the runtime context.
- Return one final dossier only, with concrete full URLs and no markdown fences.
- Keep the mandatory dossier section order already established by the investigation flow.
- Preserve or improve the SEARCH LEDGER and EVIDENCE LOG so each claim stays source-backed.
- Prioritize decision makers, gatekeepers, bidding contacts, procurement paths, upcoming events, current projects, partnerships, subcontractors, and relationship signals.
- If you encounter similarly named entities in other states or countries, discard them unless they match the supplied permit/license/location context.
- Do not invent names, roles, contact data, projects, or events.
PROMPT;

    private const DEFAULT_PERPLEXITY_PROMPT_PASS_3 = <<<'PROMPT'
[BASE_PROMPT]

DIRECT GAP-FILL RETRY (MANDATORY):
Your prior dossier was rejected. Fill the missing evidence gaps below using Perplexity's native research capability.
[EVIDENCE_GAPS]
Return only a corrected final dossier in the mandatory section order.

PREVIOUS DOSSIER
[RESEARCH_TEXT]
END PREVIOUS DOSSIER
PROMPT;

    public static function get_default_perplexity_prompt_templates(): array
    {
        return [
            'pass_1' => self::DEFAULT_PERPLEXITY_PROMPT_PASS_1,
            'pass_2' => self::DEFAULT_PERPLEXITY_PROMPT_PASS_2,
            'pass_3' => self::DEFAULT_PERPLEXITY_PROMPT_PASS_3,
        ];
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

        require_once __DIR__ . '/class-openclaw-adapter.php';
        $adapter = new RawWire_OpenClaw_Adapter();
        return $adapter->is_available();
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
        return (string) ($settings['pipeline_mode'] ?? 'veniceclaw');
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

        // Applicant or applicant business
        $applicant_name = trim((string) ($source['applicant_name'] ?? ''));
        $applicant_business = trim((string) ($source['applicant_business'] ?? ''));
        if ($applicant_name !== '' || $applicant_business !== '') {
            $parties[] = [
                'type'     => 'applicant',
                'name'     => $this->clean_name($applicant_name !== '' ? $applicant_name : $applicant_business),
                'company'  => $applicant_business,
                'business' => $applicant_business,
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

    private function recover_parties_from_existing_context(int $source_id, array $source): array
    {
        $permit_nbr = $source['permit_nbr'] ?? '';
        $project_address = $source['primary_address'] ?? $source['address'] ?? '';
        $recovered = [];
        $seen = [];

        $append_party = function (string $type, string $name) use (&$recovered, &$seen, $permit_nbr, $project_address, $source): void {
            $name = trim($name);
            if (strlen($name) <= 2 || $this->is_placeholder_name($name) || $this->is_unusable_party_name($name)) {
                return;
            }

            $normalized_name = $this->clean_name($name);
            if (strlen($normalized_name) <= 2 || $this->is_placeholder_name($normalized_name) || $this->is_unusable_party_name($normalized_name)) {
                return;
            }

            $key = strtolower($type . '|' . $normalized_name);
            if (isset($seen[$key])) {
                return;
            }

            $seen[$key] = true;
            $party = [
                'type'    => $type,
                'name'    => $normalized_name,
                'company' => $this->extract_company_from_name($name),
            ];

            if ($type === 'contractor') {
                $party['license'] = $source['contractor_license'] ?? '';
                $party['address'] = $source['contractor_address'] ?? '';
                $party['city'] = $source['contractor_city'] ?? 'Los Angeles';
                $party['state'] = $source['contractor_state'] ?? 'CA';
                $party['permit_nbr'] = $permit_nbr;
                $party['project_address'] = $project_address;
            } elseif ($type === 'owner') {
                $party['address'] = $source['owner_address'] ?? '';
                $party['city'] = $source['owner_city'] ?? '';
                $party['state'] = 'CA';
            } elseif ($type === 'applicant') {
                $party['business'] = $source['applicant_business'] ?? '';
            }

            $recovered[] = $party;
        };

        $existing_profiles = json_decode((string) ($source['party_profiles'] ?? ''), true);
        if (is_array($existing_profiles)) {
            foreach ($existing_profiles as $type => $profile) {
                if (!is_string($type) || strpos($type, '_') === 0 || !is_array($profile)) {
                    continue;
                }

                $append_party($type, (string) ($profile['name'] ?? ''));
            }
        }

        $file_path = $this->get_investigation_dir() . '/source_' . $source_id . '.json';
        if (file_exists($file_path)) {
            $file_data = json_decode((string) file_get_contents($file_path), true);
            if (is_array($file_data) && !empty($file_data['parties']) && is_array($file_data['parties'])) {
                foreach ($file_data['parties'] as $party) {
                    if (!is_array($party)) {
                        continue;
                    }

                    $type = trim((string) ($party['type'] ?? ''));
                    $name = trim((string) ($party['name'] ?? ''));
                    if ($type === '' || $name === '') {
                        continue;
                    }

                    $append_party($type, $name);
                }
            }
        }

        if (!empty($recovered)) {
            rawwire_log('party_investigator', sprintf(
                'Recovered %d prior parties for source #%d from saved investigation context',
                count($recovered),
                $source_id
            ), 'info');
        }

        return $recovered;
    }

    /**
     * Clean and normalize a name
     */
    private function clean_name(string $name): string
    {
        // Strip accessibility-tree / snapshot artifacts that leak from LADBS scraper
        $name = preg_replace('/\s*\[ref=[a-z0-9]+\]/i', '', $name);
        $name = preg_replace('/\s*-?\s*(?:cell|row)\s+"/i', '', $name);
        // Take only first line if multiline junk remains
        if (strpos($name, "\n") !== false) {
            $name = trim(strtok($name, "\n"), " \t\"',.-");
        }

        // Remove common suffixes
        $name = preg_replace('/\s+(INC|LLC|CORP|LP|LTD|CO)\.?$/i', '', $name);
        $name = trim($name, " \t\n\r\0\x0B\"',.-");

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
     * Search for information about a party via Lane A OpenClaw adapter.
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

        // Handle structured result arrays.
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


    // Legacy multi-provider search methods removed.


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

        if ($adapter->uses_direct_perplexity_research()) {
            if (!$adapter->is_available()) {
                rawwire_log('party_investigator', 'Direct Perplexity investigation unavailable — API not configured', 'warning');
                return null;
            }

            return $this->investigate_party_via_direct_provider($party, $source, $adapter, $enriched_party, $prompt);
        }

        // Agent must be available (OpenClaw CLI installed, enabled)
        if (!$adapter->is_available()) {
            rawwire_log('party_investigator', 'Agent investigation unavailable — OpenClaw not configured', 'warning');
            return null;
        }

        rawwire_log('party_investigator', sprintf(
            'Agent investigation starting for %s (prompt %d chars, timeout 300s)',
            $party['name'] ?? 'unknown',
            strlen($prompt)
        ), 'info');

        $browser_runtime = $this->build_investigation_runtime_metadata($adapter, 'agent_browser');

        $browser_pass_prompt = $this->build_browser_research_pass_prompt($prompt, $source, $enriched_party);

        // PASS 1: Browser-first evidence gathering.
        $agent_result = $adapter->agent_chat($browser_pass_prompt, [
            'timeout' => 600000,  // 10 minutes — let 4o-mini browse thoroughly
            'json'    => true,
        ]);
        $trace_path = $agent_result['trace_path'] ?? '';

        if ($trace_path === '') {
            $trace_path = $adapter->persist_agent_trace([
                'source_id' => (int) ($source['id'] ?? 0),
                'party_name' => (string) ($party['name'] ?? ''),
                'party_type' => (string) ($party['type'] ?? ''),
                'agent' => 'main',
                'started_at' => current_time('mysql'),
                'message_sha1' => sha1($browser_pass_prompt),
                'success' => !empty($agent_result['success']),
                'fallback_trace' => true,
                'error' => (string) ($agent_result['error'] ?? ''),
                'payload_preview' => substr((string) ($agent_result['content'] ?? ''), 0, 4000),
            ]);
        }

        if ($trace_path !== '') {
            rawwire_log('party_investigator', sprintf(
                'Agent trace for %s: %s',
                $party['name'] ?? 'unknown',
                $trace_path
            ), 'info');
        }

        if (!$agent_result['success'] || empty($agent_result['content'])) {
            $error = $agent_result['error'] ?? 'empty response';
            rawwire_log('party_investigator', 'Agent investigation failed: ' . $error, 'warning');
            return null;
        }

        $research_text = $agent_result['content'];

        // Fast-fail explicit toolchain/config failures only.
        if (preg_match('/INVESTIGATION_FAILED:\s*(.+)$/im', $research_text, $fail_match)) {
            $failure_reason = trim((string) ($fail_match[1] ?? 'unknown reason'));
            rawwire_log('party_investigator', sprintf(
                'Agent reported explicit failure for %s; forcing one browser-only retry: %s',
                $party['name'] ?? 'unknown',
                $failure_reason
            ), 'info');

            $party_name = trim((string) ($party['name'] ?? ''));
            $license_num = trim((string) ($party['license'] ?? ''));
            $permit_num = trim((string) ($source['permit_nbr'] ?? $party['permit_nbr'] ?? ''));

            $direct_targets = [
                '- LADBS permit portal: https://www.ladbsservices2.lacity.org/OnlineServices/PermitReport/PermitResults',
                '- CSLB license check: https://www2.cslb.ca.gov/OnlineServices/CheckLicenseII/LicenseDetail.aspx',
                '- California business registry: https://bizfileonline.sos.ca.gov/search/business',
            ];
            if ($permit_num !== '') {
                $direct_targets[] = '- MUST lookup permit number in LADBS: ' . $permit_num;
            }
            if ($license_num !== '') {
                $direct_targets[] = '- MUST lookup contractor license in CSLB: ' . $license_num;
            }
            if ($party_name !== '') {
                $direct_targets[] = '- MUST lookup target name in CA registry/news/procurement pages: ' . $party_name;
            }

            $browser_only_prompt = $this->build_browser_research_pass_prompt($prompt, $source, $enriched_party) . "\n\nTOOL AVAILABILITY OVERRIDE (MANDATORY):\n"
                . "- Continue investigation using BROWSER NAVIGATION ONLY (and web_fetch if available).\n"
                . "- Do NOT emit INVESTIGATION_FAILED unless browser navigation itself is unavailable.\n"
                . "- If a specific site blocks access, continue with other public sources and document the block.\n"
                . "- Use these direct browser targets first:\n" . implode("\n", $direct_targets) . "\n"
                . "- Provide EVIDENCE LOG with visited URLs and findings from browser-driven research.\n";

            $browser_retry = $adapter->agent_chat($browser_only_prompt, [
                'timeout' => 600000,
                'json'    => true,
            ]);

            if (!empty($browser_retry['success']) && !empty($browser_retry['content'])) {
                $research_text = $browser_retry['content'];
                $trace_path = $browser_retry['trace_path'] ?? $trace_path;
                if ($trace_path === '') {
                    $trace_path = $adapter->persist_agent_trace([
                        'source_id' => (int) ($source['id'] ?? 0),
                        'party_name' => (string) ($party['name'] ?? ''),
                        'party_type' => (string) ($party['type'] ?? ''),
                        'agent' => 'main',
                        'started_at' => current_time('mysql'),
                        'message_sha1' => sha1($browser_only_prompt),
                        'success' => true,
                        'fallback_trace' => true,
                        'payload_preview' => substr((string) $research_text, 0, 4000),
                    ]);
                }
                if (!empty($browser_retry['trace_path'])) {
                    rawwire_log('party_investigator', sprintf(
                        'Browser-only retry trace for %s: %s',
                        $party['name'] ?? 'unknown',
                        $browser_retry['trace_path']
                    ), 'info');
                }
            } else {
                rawwire_log('party_investigator', sprintf(
                    'Browser-only retry failed for %s after explicit failure: %s',
                    $party['name'] ?? 'unknown',
                    $browser_retry['error'] ?? 'empty response'
                ), 'warning');
                return null;
            }
        }

        $this->save_discovery_file($source, $party, $research_text, $browser_runtime, 'browser_pass_1_research');

        $synthesis_prompt = $this->build_synthesis_pass_prompt($prompt, $research_text);
        $synthesis_result = $adapter->agent_chat($synthesis_prompt, [
            'timeout' => 600000,
            'json'    => true,
        ]);

        if (empty($synthesis_result['success']) || empty($synthesis_result['content'])) {
            rawwire_log('party_investigator', sprintf(
                'Agent synthesis pass failed for %s: %s',
                $party['name'] ?? 'unknown',
                $synthesis_result['error'] ?? 'empty response'
            ), 'warning');
            return null;
        }

        $investigation_text = (string) $synthesis_result['content'];
        $trace_path = $synthesis_result['trace_path'] ?? $trace_path;

        // Always save raw discovery output for human review/auditing,
        // even if it fails evidence validation and is rejected.
        $this->save_discovery_file($source, $party, $investigation_text, $browser_runtime, 'browser_pass_2_synthesis');

        if (!$this->is_valid_agent_investigation($investigation_text, $enriched_party)) {
            $diag = $this->get_agent_investigation_diagnostics($investigation_text, $enriched_party);

            rawwire_log('party_investigator', sprintf(
                'Agent investigation rejected for %s -- urls=%d, hosts=%d, target_urls=%d, evidence_urls=%d, evidence_section=%s, failure_signature=%s',
                $party['name'] ?? 'unknown',
                $diag['url_count'],
                $diag['host_count'],
                $diag['target_url_count'],
                $diag['evidence_url_count'],
                $diag['has_evidence_section'] ? 'yes' : 'no',
                $diag['failure_signature'] !== '' ? $diag['failure_signature'] : 'none'
            ), 'warning');

            $allow_agent_deep_retry = (bool) $adapter->get_setting('agent_deep_retry', true);
            if (!$allow_agent_deep_retry) {
                $this->write_investigation_rejection_note((int) ($source['id'] ?? 0), $party, $diag, 'initial multi-pass output rejected');
                return null;
            }

            if (!$this->should_attempt_gap_fill_retry($diag)) {
                rawwire_log('party_investigator', sprintf(
                    'Skipping gap-fill for %s -- generic-source stall detected (urls=%d, hosts=%d, target_urls=%d, evidence_urls=%d, people=%d, generic_only=%s)',
                    $party['name'] ?? 'unknown',
                    $diag['url_count'],
                    $diag['host_count'],
                    $diag['target_url_count'],
                    $diag['evidence_url_count'],
                    $diag['named_people_count'],
                    !empty($diag['generic_host_only']) ? 'yes' : 'no'
                ), 'warning');
                $this->write_investigation_rejection_note((int) ($source['id'] ?? 0), $party, $diag, 'initial multi-pass output rejected without gap-fill (generic-source stall)');
                return null;
            }

            // PASS 3: Browser gap-fill based on failed diagnostics.
            $retry_prompt = $this->build_gap_fill_pass_prompt($prompt, $research_text, $diag);

            rawwire_log('party_investigator', sprintf(
                'Agent gap-fill pass starting for %s after sparse output',
                $party['name'] ?? 'unknown'
            ), 'info');

            $retry_result = $adapter->agent_chat($retry_prompt, [
                'timeout' => 600000,
                'json'    => true,
            ]);

            if (!empty($retry_result['success']) && !empty($retry_result['content'])) {
                $gap_fill_research = $retry_result['content'];
                $trace_path = $retry_result['trace_path'] ?? $trace_path;
                if ($trace_path === '') {
                    $trace_path = $adapter->persist_agent_trace([
                        'source_id' => (int) ($source['id'] ?? 0),
                        'party_name' => (string) ($party['name'] ?? ''),
                        'party_type' => (string) ($party['type'] ?? ''),
                        'agent' => 'main',
                        'started_at' => current_time('mysql'),
                        'message_sha1' => sha1($retry_prompt),
                        'success' => true,
                        'fallback_trace' => true,
                        'payload_preview' => substr((string) $gap_fill_research, 0, 4000),
                    ]);
                }
                if (!empty($retry_result['trace_path'])) {
                    rawwire_log('party_investigator', sprintf(
                        'Agent deep-retry trace for %s: %s',
                        $party['name'] ?? 'unknown',
                        $retry_result['trace_path']
                    ), 'info');
                }
                $this->save_discovery_file($source, $party, $gap_fill_research, $browser_runtime, 'browser_pass_3_gap_fill');

                $final_synthesis_prompt = $this->build_synthesis_pass_prompt($prompt, $research_text . "\n\n--- GAP FILL RESEARCH ---\n" . $gap_fill_research);
                $final_synthesis = $adapter->agent_chat($final_synthesis_prompt, [
                    'timeout' => 600000,
                    'json'    => true,
                ]);

                if (empty($final_synthesis['success']) || empty($final_synthesis['content'])) {
                    $this->write_investigation_rejection_note((int) ($source['id'] ?? 0), $party, $diag, 'final synthesis failed after gap-fill');
                    return null;
                }

                $retry_text = (string) $final_synthesis['content'];

                if ($this->is_valid_agent_investigation($retry_text, $party)) {
                    $investigation_text = $retry_text;
                    rawwire_log('party_investigator', sprintf(
                        'Agent multi-pass gap-fill accepted for %s (%d chars)',
                        $party['name'] ?? 'unknown',
                        strlen($investigation_text)
                    ), 'info');
                } else {
                    $retry_diag = $this->get_agent_investigation_diagnostics($retry_text, $party);
                    rawwire_log('party_investigator', sprintf(
                        'Agent deep-retry rejected for %s -- urls=%d, hosts=%d, target_urls=%d, evidence_urls=%d, evidence_section=%s, failure_signature=%s',
                        $party['name'] ?? 'unknown',
                        $retry_diag['url_count'],
                        $retry_diag['host_count'],
                        $retry_diag['target_url_count'],
                        $retry_diag['evidence_url_count'],
                        $retry_diag['has_evidence_section'] ? 'yes' : 'no',
                        $retry_diag['failure_signature'] !== '' ? $retry_diag['failure_signature'] : 'none'
                    ), 'warning');
                    $this->write_investigation_rejection_note((int) ($source['id'] ?? 0), $party, $retry_diag, 'multi-pass output rejected after gap-fill');
                    return null;
                }
            } else {
                rawwire_log('party_investigator', sprintf(
                    'Agent gap-fill pass failed for %s: %s',
                    $party['name'] ?? 'unknown',
                    $retry_result['error'] ?? 'empty response'
                ), 'warning');
                $this->write_investigation_rejection_note((int) ($source['id'] ?? 0), $party, $diag, 'gap-fill pass failed');
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
            'investigation_provider' => $browser_runtime['investigation_provider'],
            'investigation_model'   => $browser_runtime['investigation_model'],
            'investigation_base_url' => $browser_runtime['investigation_base_url'],
            'investigation_status'  => 'pending_extraction',
            'trace_path'            => $trace_path,
        ];

        rawwire_log('party_investigator', sprintf(
            'Agent investigation complete for %s -- %d chars of raw findings (pending extraction)',
            $party['name'] ?? 'unknown',
            strlen($investigation_text)
        ), 'info');

        return $profile;
    }

    /**
     * Investigate a party directly via Perplexity's native web-research lane.
     */
    private function investigate_party_via_direct_provider(array $party, array $source, RawWire_OpenClaw_Adapter $adapter, array $enriched_party, string $base_prompt): ?array
    {
        $investigator_settings = self::get_settings();
        $perplexity_settings = wp_parse_args(get_option('rawwire_perplexity_settings', []), [
            'model' => 'sonar',
            'temperature' => 0.2,
            'max_tokens' => 8000,
            'strip_thinking_response' => true,
        ]);
        $direct_temperature = max(0, min(1, (float) ($perplexity_settings['temperature'] ?? 0.2)));
        $direct_max_tokens = min(128000, max(1000, (int) ($perplexity_settings['max_tokens'] ?? 8000)));
        $max_passes = min(3, max(1, (int) ($investigator_settings['perplexity_pass_count'] ?? 2)));
        $perplexity_preset = in_array((string) ($investigator_settings['perplexity_preset'] ?? 'pro-search'), ['', 'fast-search', 'pro-search', 'deep-research', 'advanced-deep-research'], true)
            ? (string) ($investigator_settings['perplexity_preset'] ?? 'pro-search')
            : 'pro-search';
        $perplexity_max_steps = ($investigator_settings['perplexity_max_steps'] ?? '') === ''
            ? null
            : min(10, max(1, (int) ($investigator_settings['perplexity_max_steps'] ?? 3)));
        $strip_thinking_response = array_key_exists('perplexity_strip_thinking_response', $investigator_settings)
            ? !empty($investigator_settings['perplexity_strip_thinking_response'])
            : !empty($perplexity_settings['strip_thinking_response']);
        $direct_request_options = [
            'prefer_responses_api' => true,
            'timeout' => 300,
            'max_tokens' => $direct_max_tokens,
            'temperature' => $direct_temperature,
            'preset' => $perplexity_preset,
            'max_steps' => $perplexity_max_steps,
            'top_p' => ($investigator_settings['perplexity_top_p'] ?? '') === ''
                ? (float) ($perplexity_settings['top_p'] ?? 0.9)
                : max(0, min(1, (float) $investigator_settings['perplexity_top_p'])),
            'reasoning_effort' => in_array((string) ($investigator_settings['perplexity_reasoning_effort'] ?? ''), ['off', 'minimal', 'low', 'medium', 'high'], true)
                ? (string) $investigator_settings['perplexity_reasoning_effort']
                : (string) ($perplexity_settings['reasoning_effort'] ?? 'off'),
            'search_mode' => (string) ($investigator_settings['perplexity_search_mode'] ?? 'web'),
            'return_images' => !empty($investigator_settings['perplexity_return_images']),
            'return_related_questions' => !empty($investigator_settings['perplexity_return_related_questions']),
            'enable_search_classifier' => !empty($investigator_settings['perplexity_enable_search_classifier']),
            'disable_search' => !empty($investigator_settings['perplexity_disable_search']),
        ];
        $provider_default_model = $this->normalize_perplexity_model_name((string) ($perplexity_settings['model'] ?? 'sonar'));
        $first_pass_model = $this->resolve_perplexity_pass_model('pass_1', $provider_default_model, $investigator_settings, $perplexity_preset !== '');
        $second_pass_model = $this->resolve_perplexity_pass_model('pass_2', $provider_default_model, $investigator_settings, $perplexity_preset !== '');
        $third_pass_model = $this->resolve_perplexity_pass_model('pass_3', $provider_default_model, $investigator_settings, $perplexity_preset !== '');
        $first_pass_runtime = $this->build_investigation_runtime_metadata($adapter, 'perplexity_direct', $this->get_perplexity_runtime_label($first_pass_model, $perplexity_preset));
        $final_runtime = $first_pass_runtime;
        $saved_final_output = false;

        rawwire_log('party_investigator', sprintf(
            'Direct Perplexity investigation starting for %s (timeout 300s)',
            $party['name'] ?? 'unknown'
        ), 'info');

        $research_result = $adapter->chat_with_metadata([], [
            'instructions' => 'You are a source-driven business intelligence investigator. Return only the requested dossier text. Do not explain your search process. If reliable information is unavailable, say so explicitly instead of guessing.',
            'input' => $this->build_direct_research_prompt($base_prompt, $source, $enriched_party),
            'model' => $first_pass_model,
        ] + $direct_request_options);

        if (empty($research_result['success']) || empty($research_result['content'])) {
            rawwire_log('party_investigator', sprintf(
                'Direct Perplexity investigation failed for %s: %s',
                $party['name'] ?? 'unknown',
                $research_result['error'] ?? 'empty response'
            ), 'warning');
            return null;
        }

        $investigation_text = $this->append_direct_citation_appendix(
            (string) $research_result['content'],
            (array) ($research_result['citations'] ?? []),
            (array) ($research_result['search_results'] ?? [])
        );
        $first_pass_runtime = $this->build_investigation_runtime_metadata($adapter, 'perplexity_direct', $this->get_perplexity_runtime_label($first_pass_model, $perplexity_preset, (string) ($research_result['model'] ?? '')));
        $final_runtime = $first_pass_runtime;
        $this->save_discovery_file($source, $party, $investigation_text, $first_pass_runtime, 'direct_pass_1_research');

        $second_pass_prompt = $this->build_direct_second_pass_prompt($base_prompt, $investigation_text, $source, $enriched_party);
        if ($max_passes > 1 && $second_pass_prompt !== '') {
            $second_pass_result = $adapter->chat_with_metadata([], [
                'instructions' => 'You are a source-driven business intelligence investigator refining a dossier. Use source-backed findings only. If identity or evidence is ambiguous, say so clearly instead of switching entities or guessing.',
                'input' => $second_pass_prompt,
                'model' => $second_pass_model,
            ] + $direct_request_options);

            if (!empty($second_pass_result['success']) && !empty($second_pass_result['content'])) {
                $investigation_text = $this->append_direct_citation_appendix(
                    (string) $second_pass_result['content'],
                    (array) ($second_pass_result['citations'] ?? []),
                    (array) ($second_pass_result['search_results'] ?? [])
                );
                $final_runtime = $this->build_investigation_runtime_metadata($adapter, 'perplexity_agent_second_pass', $this->get_perplexity_runtime_label($second_pass_model, $perplexity_preset, (string) ($second_pass_result['model'] ?? '')));

                rawwire_log('party_investigator', sprintf(
                    'Direct Perplexity Agent API second pass complete for %s via %s -- %d chars of refined findings',
                    $party['name'] ?? 'unknown',
                    $final_runtime['investigation_model'],
                    strlen($investigation_text)
                ), 'info');
            } else {
                rawwire_log('party_investigator', sprintf(
                    'Direct Perplexity Agent API second pass failed for %s: %s',
                    $party['name'] ?? 'unknown',
                    $second_pass_result['error'] ?? 'empty response'
                ), 'warning');
            }
        }

        if ($strip_thinking_response) {
            $investigation_text = preg_replace('/<think\b[^>]*>.*?<\/think>/is', '', $investigation_text) ?? $investigation_text;
            $investigation_text = trim($investigation_text);
        }

        // Preserve raw direct-provider output for human review even if
        // the downstream validation gate rejects it.
        $this->save_discovery_file($source, $party, $investigation_text, $final_runtime, $max_passes > 1 ? 'direct_pass_2_refinement' : 'direct_pass_1_final');
        $saved_final_output = true;

        if (!$this->is_valid_agent_investigation($investigation_text, $party)) {
            $diag = $this->get_agent_investigation_diagnostics($investigation_text, $party);

            if ($this->should_accept_direct_investigation($diag)) {
                rawwire_log('party_investigator', sprintf(
                    'Direct Perplexity investigation accepted via direct-lane fallback for %s -- urls=%d, hosts=%d, target_urls=%d, evidence_urls=%d',
                    $party['name'] ?? 'unknown',
                    $diag['url_count'],
                    $diag['host_count'],
                    $diag['target_url_count'],
                    $diag['evidence_url_count']
                ), 'info');
            } else {
                rawwire_log('party_investigator', sprintf(
                    'Direct Perplexity investigation rejected for %s -- urls=%d, hosts=%d, target_urls=%d, evidence_urls=%d, evidence_section=%s, failure_signature=%s',
                    $party['name'] ?? 'unknown',
                    $diag['url_count'],
                    $diag['host_count'],
                    $diag['target_url_count'],
                    $diag['evidence_url_count'],
                    $diag['has_evidence_section'] ? 'yes' : 'no',
                    $diag['failure_signature'] !== '' ? $diag['failure_signature'] : 'none'
                ), 'warning');

                if ($max_passes < 3) {
                    return null;
                }

                $retry_result = $adapter->chat_with_metadata([], [
                    'instructions' => 'You are a source-driven business intelligence investigator filling specific evidence gaps. Use source-backed findings only and state clearly when no additional reliable evidence is available.',
                    'input' => $this->build_direct_gap_fill_prompt($base_prompt, $investigation_text, $diag),
                    'model' => $third_pass_model,
                ] + $direct_request_options);

                if (empty($retry_result['success']) || empty($retry_result['content'])) {
                    rawwire_log('party_investigator', sprintf(
                        'Direct Perplexity retry failed for %s: %s',
                        $party['name'] ?? 'unknown',
                        $retry_result['error'] ?? 'empty response'
                    ), 'warning');
                    return null;
                }

                $retry_text = $this->append_direct_citation_appendix(
                    (string) $retry_result['content'],
                    (array) ($retry_result['citations'] ?? []),
                    (array) ($retry_result['search_results'] ?? [])
                );

                // Preserve retry output too so rejected direct-lane reruns
                // still leave an audit trail for inspection.
                $this->save_discovery_file($source, $party, $retry_text, $first_pass_runtime, 'direct_pass_3_gap_fill');

                if (!$this->is_valid_agent_investigation($retry_text, $enriched_party)) {
                    $retry_diag = $this->get_agent_investigation_diagnostics($retry_text, $enriched_party);
                    if (!$this->should_accept_direct_investigation($retry_diag)) {
                        rawwire_log('party_investigator', sprintf(
                            'Direct Perplexity retry rejected for %s -- urls=%d, hosts=%d, target_urls=%d, evidence_urls=%d, evidence_section=%s, failure_signature=%s',
                            $party['name'] ?? 'unknown',
                            $retry_diag['url_count'],
                            $retry_diag['host_count'],
                            $retry_diag['target_url_count'],
                            $retry_diag['evidence_url_count'],
                            $retry_diag['has_evidence_section'] ? 'yes' : 'no',
                            $retry_diag['failure_signature'] !== '' ? $retry_diag['failure_signature'] : 'none'
                        ), 'warning');
                        return null;
                    }
                }

                $investigation_text = $retry_text;
                $final_runtime = $this->build_investigation_runtime_metadata($adapter, 'perplexity_direct_gap_fill', $this->get_perplexity_runtime_label($third_pass_model, $perplexity_preset, (string) ($retry_result['model'] ?? '')));
                $saved_final_output = false;
            }
        }

        if (!$saved_final_output) {
            $this->save_discovery_file($source, $party, $investigation_text, $final_runtime, 'direct_final');
        }

        rawwire_log('party_investigator', sprintf(
            'Direct Perplexity investigation complete for %s -- %d chars of raw findings',
            $party['name'] ?? 'unknown',
            strlen($investigation_text)
        ), 'info');

        return [
            'raw_investigation'    => $investigation_text,
            'investigation_method' => $final_runtime['investigation_method'],
            'investigation_provider' => $final_runtime['investigation_provider'],
            'investigation_model' => $final_runtime['investigation_model'],
            'investigation_base_url' => $final_runtime['investigation_base_url'],
            'investigation_status' => 'pending_extraction',
            'trace_path'           => '',
        ];
    }

    private function build_investigation_runtime_metadata(RawWire_OpenClaw_Adapter $adapter, string $method, ?string $model = null): array
    {
        return [
            'investigation_method' => $method,
            'investigation_provider' => $adapter->get_runtime_provider_name(),
            'investigation_model' => $model !== null && $model !== '' ? $model : $adapter->get_model(),
            'investigation_base_url' => $adapter->get_base_url(),
        ];
    }

    private function resolve_perplexity_pass_model(string $pass, string $fallback_model, ?array $settings = null, bool $allow_preset_model = false): string
    {
        $settings = $settings ?? self::get_settings();
        $configured_model = $this->normalize_perplexity_model_name(trim((string) ($settings['perplexity_model_' . $pass] ?? '')));

        if ($configured_model === '' && $allow_preset_model) {
            return '';
        }

        return $configured_model !== '' ? $configured_model : $fallback_model;
    }

    private function get_perplexity_runtime_label(string $explicit_model, string $preset, string $actual_model = ''): string
    {
        if ($actual_model !== '') {
            return $actual_model;
        }

        if ($explicit_model !== '') {
            return $explicit_model;
        }

        if ($preset !== '') {
            return 'preset:' . $preset;
        }

        return 'unknown';
    }

    private function normalize_perplexity_model_name(string $model): string
    {
        if (strpos($model, 'perplexity/') === 0) {
            $normalized = substr($model, strlen('perplexity/'));
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return $model;
    }

    /**
     * PASS 1 prompt: force browser-first evidence gathering, especially social/profile pages.
     */
    private function build_browser_research_pass_prompt(string $base_prompt, array $source, array $party): string
    {
        $party_name = trim((string) ($party['name'] ?? ''));
        $permit_num = trim((string) ($source['permit_nbr'] ?? $party['permit_nbr'] ?? ''));
        $license_num = trim((string) ($party['license'] ?? ''));

        return $base_prompt . "\n\nPASS 1 OF 3 — EVIDENCE GATHERING (MANDATORY):\n"
            . "- Do NOT write the final polished report yet. Gather evidence first.\n"
            . "- Use BOTH web_search and browser tools. Use web_search to discover candidate sources quickly, then use browser navigation and web_fetch to verify the useful ones.\n"
            . "- You MUST investigate social/profile surfaces because they are difficult for bots to summarize reliably.\n"
            . "- Search for LinkedIn company page, LinkedIn person profiles, Instagram, Facebook, X/Twitter, YouTube, association roster pages, event speaker/sponsor pages, and company leadership/team pages.\n"
            . "- For each meaningful individual you find, run a focused probe on that person aimed at discovering plausible meeting opportunities and access routes.\n"
            . "- Person probes must look for: speaking appearances, event rosters, sponsor pages, committee memberships, association leadership, webinars, charity boards, golf outings, alumni groups, podcasts, panel appearances, assistant/gatekeeper contacts, and upcoming procurement or networking events.\n"
            . "- For each social/profile surface, record one of: verified URL, blocked page, no relevant result after search, or ambiguous result.\n"
            . ($permit_num !== '' ? "- Permit context: {$permit_num}\n" : '')
            . ($license_num !== '' ? "- License context: {$license_num}\n" : '')
            . ($party_name !== '' ? "- Target name: {$party_name}\n" : '')
            . "- REQUIRED OUTPUT FOR PASS 1:\n"
            . "1. SEARCH LEDGER with exact queries in order\n"
            . "2. EVIDENCE LOG with query, visited URL, extracted fact, and whether it came from web_search discovery, browser verification, or both\n"
            . "3. SOCIAL / PROFILE CHECK section with each surface checked and result\n"
            . "4. INDIVIDUAL PROBE LEDGER with one subsection per meaningful person\n"
            . "5. TARGET-SPECIFIC FACT PACK with only source-backed facts\n"
            . "- Do not invent names, titles, events, projects, or contacts.\n";
    }

    /**
     * Direct Perplexity prompt: preserve the same dossier format without browser-tool requirements.
     */
    private function build_direct_research_prompt(string $base_prompt, array $source, array $party): string
    {
        $party_name = trim((string) ($party['name'] ?? ''));
        $permit_num = trim((string) ($source['permit_nbr'] ?? $party['permit_nbr'] ?? ''));
        $license_num = trim((string) ($party['license'] ?? ''));

        return $this->render_perplexity_prompt_template('pass_1', [
            '[BASE_PROMPT]' => $base_prompt,
            '[PARTY_NAME]' => $party_name !== '' ? $party_name : 'not provided',
            '[PERMIT_NUMBER]' => $permit_num !== '' ? $permit_num : 'not provided',
            '[LICENSE_NUMBER]' => $license_num !== '' ? $license_num : 'not provided',
        ]);
    }

    /**
     * PASS 2 prompt: synthesize final dossier only from gathered evidence.
     */
    private function build_synthesis_pass_prompt(string $base_prompt, string $research_text): string
    {
        return $base_prompt . "\n\nPASS 2 OF 3 — SYNTHESIZE FINAL DOSSIER FROM EVIDENCE ONLY:\n"
            . "Use ONLY the evidence gathered below. Do not invent any additional facts.\n"
            . "If social/profile research was blocked or empty, preserve that result in the report and evidence log.\n"
            . "For each meaningful person, include a specific probe result focused on meeting opportunities and access routes, or explicitly state that none were found after probing.\n"
            . "Keep the final mandatory section order. Keep SEARCH LEDGER and EVIDENCE LOG concrete.\n"
            . "Every named person must have a cited source URL or they must be omitted.\n"
            . "\nBEGIN PASS 1 EVIDENCE\n"
            . $research_text
            . "\nEND PASS 1 EVIDENCE\n";
    }

    /**
     * PASS 3 prompt: fill missing evidence gaps with another browser pass.
     *
     * @param array<string,mixed> $diag
     */
    private function build_gap_fill_pass_prompt(string $base_prompt, string $research_text, array $diag): string
    {
        $gaps = [];
        if (($diag['target_url_count'] ?? 0) < 1) {
            $gaps[] = '- Find at least one target-specific company/person page and cite it directly.';
        }
        if (($diag['named_people_count'] ?? 0) < 2) {
            $gaps[] = '- Find at least two real named people tied to the target with source URLs.';
        }
        if (($diag['evidence_url_count'] ?? 0) < 2) {
            $gaps[] = '- Expand the EVIDENCE LOG with more concrete URLs tied to extracted facts.';
        }
        if (($diag['has_social_profile_check'] ?? false) === false) {
            $gaps[] = '- Perform explicit social/profile checks and document verified URLs, blocks, or not-found results.';
        }
        if (($diag['named_people_count'] ?? 0) >= 1) {
            $gaps[] = '- For each meaningful person already identified, do a focused person probe for meeting opportunities, gatekeepers, and event-based access routes.';
        }
        if (($diag['generic_advice_hits'] ?? 0) > 0) {
            $gaps[] = '- Replace generic networking advice with source-backed access points only.';
        }

        return $base_prompt . "\n\nPASS 3 OF 3 — GAP-FILL BROWSER RESEARCH:\n"
            . "Your synthesized report was rejected. Fill the missing evidence gaps below using both web_search and browser verification.\n"
            . implode("\n", $gaps) . "\n"
            . "You are only allowed to return another evidence block if you discover at least one new target-specific company/person page OR at least one real named person tied to the target with a source URL.\n"
            . "If you are still limited to generic portals, blocked pages, or regulator-only results, return exactly: NO_NEW_EVIDENCE_FOUND\n"
            . "Return only new SEARCH LEDGER entries, new EVIDENCE LOG entries, updated SOCIAL / PROFILE CHECK results, INDIVIDUAL PROBE LEDGER updates, and any newly discovered target-specific facts.\n"
            . "\nPREVIOUS EVIDENCE\n"
            . $research_text
            . "\nEND PREVIOUS EVIDENCE\n";
    }

    /**
     * Direct Perplexity retry prompt to fill missing dossier evidence.
     *
     * @param array<string,mixed> $diag
     */
    private function build_direct_gap_fill_prompt(string $base_prompt, string $research_text, array $diag): string
    {
        $gaps = [];
        if (($diag['target_url_count'] ?? 0) < 1) {
            $gaps[] = '- Find at least one target-specific company or person page and cite the full URL.';
        }
        if (($diag['named_people_count'] ?? 0) < 2) {
            $gaps[] = '- Identify at least two real named people tied to the target with direct source URLs.';
        }
        if (($diag['evidence_url_count'] ?? 0) < 2) {
            $gaps[] = '- Expand the EVIDENCE LOG with more concrete URLs tied to extracted facts.';
        }
        if (($diag['has_social_profile_check'] ?? false) === false) {
            $gaps[] = '- Add explicit social/profile checks documenting verified URLs, blocks, or not-found results.';
        }
        if (($diag['generic_advice_hits'] ?? 0) > 0) {
            $gaps[] = '- Replace generic advice with source-backed access points only.';
        }

        return $this->render_perplexity_prompt_template('pass_3', [
            '[BASE_PROMPT]' => $base_prompt,
            '[EVIDENCE_GAPS]' => implode("\n", $gaps),
            '[RESEARCH_TEXT]' => $research_text,
        ]);
    }

    /**
     * Optional direct-provider second-pass refinement prompt.
     *
     * Leave the returned string empty to disable the second pass.
     * Replace the placeholder body with your custom prompt when ready.
     *
     * @param array<string,mixed> $source
     * @param array<string,mixed> $party
     */
    private function build_direct_second_pass_prompt(string $base_prompt, string $research_text, array $source, array $party): string
    {
        $investigation_target = trim((string) ($party['name'] ?? ''));
        if ($investigation_target === '') {
            $investigation_target = trim((string) ($party['company'] ?? ''));
        }
        if ($investigation_target === '') {
            $investigation_target = 'the target company';
        }

        $permit_num = trim((string) ($source['permit_nbr'] ?? $party['permit_nbr'] ?? ''));
        $license_num = trim((string) ($party['license'] ?? ''));
        $project_address = trim((string) ($source['primary_address'] ?? $source['address'] ?? $party['address'] ?? ''));
        $target_city = trim((string) ($party['city'] ?? ''));
        $target_state = trim((string) ($party['state'] ?? ''));

        $identity_lines = [
            'IDENTITY GUARDRAILS (MANDATORY):',
            '- Investigate only this exact target entity: ' . $investigation_target,
        ];

        if ($permit_num !== '') {
            $identity_lines[] = '- Permit context: ' . $permit_num;
        }

        if ($license_num !== '') {
            $identity_lines[] = '- License context: ' . $license_num;
        }

        if ($project_address !== '') {
            $identity_lines[] = '- Project / source address context: ' . $project_address;
        }

        if ($target_city !== '' || $target_state !== '') {
            $identity_lines[] = '- Geographic context: ' . trim($target_city . ', ' . $target_state, ', ');
        }

        $identity_lines[] = '- Use the PASS 1 evidence below as a disambiguation anchor.';
        $identity_lines[] = '- If a result refers to a similarly named company in a different jurisdiction or with incompatible license/permit context, exclude it.';
        $identity_lines[] = '- If identity remains ambiguous, say so explicitly and keep searching for California / LADBS-aligned evidence instead of switching entities.';

        $rendered_prompt = $this->render_perplexity_prompt_template('pass_2', [
            '[INVESTIGATION_TARGET]' => $investigation_target,
            '[BASE_PROMPT]' => $base_prompt,
            '[RESEARCH_TEXT]' => $research_text,
            '[PARTY_NAME]' => $investigation_target,
            '[PERMIT_NUMBER]' => $permit_num,
            '[LICENSE_NUMBER]' => $license_num,
        ]);

        return implode("\n", $identity_lines)
            . "\n\n"
            . $rendered_prompt
            . "\n\nPASS 1 EVIDENCE CONTEXT\n"
            . $research_text
            . "\nEND PASS 1 EVIDENCE CONTEXT\n";
    }

    private function render_perplexity_prompt_template(string $pass, array $replacements): string
    {
        $defaults = self::get_default_perplexity_prompt_templates();
        $settings = self::get_settings();
        $setting_key = 'perplexity_prompt_' . $pass;
        $template = trim((string) ($settings[$setting_key] ?? ''));
        if ($template === '') {
            $template = $defaults[$pass] ?? '';
        }

        return trim(strtr($template, $replacements));
    }

    /**
     * Append response metadata URLs so downstream validation sees the concrete sources.
     *
     * @param string[] $citations
     * @param array<int,array<string,mixed>> $search_results
     */
    private function append_direct_citation_appendix(string $text, array $citations, array $search_results): string
    {
        $urls = [];

        foreach ($citations as $citation) {
            $citation = trim((string) $citation);
            if ($citation !== '') {
                $urls[] = $citation;
            }
        }

        foreach ($search_results as $result) {
            $url = trim((string) ($result['url'] ?? ''));
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        $urls = array_values(array_unique($urls));
        if (empty($urls)) {
            return $text;
        }

        $evidence_lines = implode("\n", array_map(static function (string $url): string {
            return "- Query: Perplexity native research\n  - URL: {$url}\n  - Extracted Fact: Provider-returned citation from direct research response.";
        }, $urls));

        if (preg_match('/(^#+\s*EVIDENCE LOG\s*$)/im', $text)) {
            $text = preg_replace('/(^#+\s*EVIDENCE LOG\s*$)/im', "$1\n" . $evidence_lines, $text, 1) ?? $text;
        } else {
            $text = rtrim($text) . "\n\n## EVIDENCE LOG\n" . $evidence_lines;
        }

        return rtrim($text) . "\n\n## DIRECT SOURCE URL APPENDIX\n"
            . implode("\n", array_map(static function (string $url): string {
                return '- ' . $url;
            }, $urls));
    }

    /**
     * Direct-provider reports can still be useful even if they miss one of the
     * stricter browser-lane checklist thresholds. Accept them when they show
     * clear, source-backed target research.
     *
     * @param array<string,mixed> $diag
     */
    private function should_accept_direct_investigation(array $diag): bool
    {
        if (!empty($diag['failure_signature'])) {
            return false;
        }

        if (($diag['url_count'] ?? 0) < 6) {
            return false;
        }

        if (($diag['host_count'] ?? 0) < 4) {
            return false;
        }

        if (($diag['target_url_count'] ?? 0) < 1) {
            return false;
        }

        if (($diag['evidence_url_count'] ?? 0) < 2) {
            return false;
        }

        if (!empty($diag['generic_host_only'])) {
            return false;
        }

        return true;
    }

    /**
     * Persist a brief rejection reason to the source row for operator visibility.
     *
     * @param array<string,mixed> $party
     * @param array<string,mixed> $diag
     */
    private function write_investigation_rejection_note(int $source_id, array $party, array $diag, string $reason): void
    {
        if ($source_id <= 0) {
            return;
        }

        global $wpdb;
        $sources_table = rawwire_leads()->table('sources');
        $note = sprintf(
            'Rejected %s investigation: %s | urls=%d hosts=%d target_urls=%d evidence_urls=%d people=%d social_check=%s',
            (string) ($party['name'] ?? 'target'),
            $reason,
            (int) ($diag['url_count'] ?? 0),
            (int) ($diag['host_count'] ?? 0),
            (int) ($diag['target_url_count'] ?? 0),
            (int) ($diag['evidence_url_count'] ?? 0),
            (int) ($diag['named_people_count'] ?? 0),
            !empty($diag['has_social_profile_check']) ? 'yes' : 'no'
        );

        $wpdb->update(
            $sources_table,
            [
                'investigator_notes' => $note,
                'investigation_status' => 'incomplete',
            ],
            ['id' => $source_id]
        );
    }

    /**
     * Validate that agent output reflects real web investigation (not placeholder/generic text).
     */
    private function is_valid_agent_investigation(string $text, array $party = []): bool
    {
        $normalized = trim($text);
        if (strlen($normalized) < 300) {
            return false;
        }

        $diag = $this->get_agent_investigation_diagnostics($normalized, $party);

        if ($diag['failure_signature'] !== '') {
            return false;
        }
        if ($diag['url_count'] < 4 || $diag['host_count'] < 3) {
            return false;
        }
        if (!$diag['has_evidence_section'] || !$diag['has_search_ledger']) {
            return false;
        }
        if ($diag['evidence_url_count'] < 2) {
            return false;
        }
        if ($diag['checked_todo_count'] < 5) {
            return false;
        }
        if ($diag['named_people_count'] < 2) {
            return false;
        }
        if ($diag['target_url_count'] < 1) {
            return false;
        }
        if ($diag['generic_advice_hits'] > 2) {
            return false;
        }

        return true;
    }

    /**
     * Decide whether a failed synthesis has enough signal to justify another expensive gap-fill pass.
     *
     * @param array<string,mixed> $diag
     */
    private function should_attempt_gap_fill_retry(array $diag): bool
    {
        if (!empty($diag['failure_signature'])) {
            return true;
        }

        if (($diag['target_url_count'] ?? 0) >= 1 || ($diag['named_people_count'] ?? 0) >= 1) {
            return true;
        }

        if (($diag['evidence_url_count'] ?? 0) >= 3 && empty($diag['generic_host_only'])) {
            return true;
        }

        if (!empty($diag['reported_no_new_evidence'])) {
            return false;
        }

        return false;
    }

    /**
     * Collect agent-investigation quality diagnostics for logging/gating.
     *
     * @param string $text
     * @param array  $party
     * @return array{url_count:int,host_count:int,target_url_count:int,evidence_url_count:int,checked_todo_count:int,named_people_count:int,generic_advice_hits:int,has_evidence_section:bool,has_search_ledger:bool,has_social_profile_check:bool,generic_host_only:bool,reported_no_new_evidence:bool,failure_signature:string}
     */
    private function get_agent_investigation_diagnostics(string $text, array $party = []): array
    {
        $lower = strtolower($text);
        $failure_signatures = [
            'investigation_failed:',
            // Keep API-key signatures specific to avoid false positives from
            // third-party snippets in otherwise valid reports.
            'missing openai api key',
            'openai_api_key',
            'no openai api key found',
            'connection error',
            'api unavailable',
            'manual research recommended',
            'no output from agent',
            'unable to perform live web searches',
            'unable to perform live web search',
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
        $hosts = [];
        foreach ($unique_urls as $url) {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($host !== '') {
                $hosts[$host] = true;
            }
        }

        $generic_hosts = [
            'www.cslb.ca.gov',
            'www2.cslb.ca.gov',
            'www.linkedin.com',
            'linkedin.com',
            'www.buildzoom.com',
            'buildzoom.com',
            'www.google.com',
            'google.com',
            'www.bing.com',
            'bing.com',
            'www.naiop.org',
            'naiop.org',
            'www.yellowpages.com',
            'yellowpages.com',
            'www.yelp.com',
            'yelp.com',
            'www.facebook.com',
            'facebook.com',
            'www.instagram.com',
            'instagram.com',
            'x.com',
            'twitter.com',
        ];
        $generic_host_only = !empty($hosts);
        foreach (array_keys($hosts) as $host) {
            if (!in_array($host, $generic_hosts, true)) {
                $generic_host_only = false;
                break;
            }
        }

        $evidence_section = $this->extract_markdown_section($text, 'evidence log');
        preg_match_all('#https?://[^\s)\]>"]+#i', $evidence_section, $evidence_url_matches);
        $evidence_urls = array_values(array_unique($evidence_url_matches[0] ?? []));

        preg_match_all('/^\s*-\s*\[x\]\s+TODO\b/im', $text, $todo_matches);

        preg_match_all('/^\s*(?:[-*]|\d+\.)\s+\*\*([A-Z][A-Za-z\'.&-]+(?:\s+[A-Z][A-Za-z\'.&-]+){0,4})\*\*/m', $text, $people_matches);
        $named_people = array_values(array_unique(array_map('trim', $people_matches[1] ?? [])));

        $generic_advice_patterns = [
            'not obtained yet',
            'local contractor meetups',
            'local construction networking events',
            'explore board of directors',
            'worth monitoring future engagements closely',
            'could be a point of skepticism',
            'requires further research',
            'check directly',
            'contact info: not obtained',
        ];
        $generic_advice_hits = 0;
        foreach ($generic_advice_patterns as $pattern) {
            if (str_contains($lower, $pattern)) {
                $generic_advice_hits++;
            }
        }

        $target_tokens = $this->get_target_identity_tokens($party);
        $target_url_count = 0;
        foreach ($unique_urls as $url) {
            $url_lower = strtolower($url);
            foreach ($target_tokens as $token) {
                if ($token !== '' && str_contains($url_lower, $token)) {
                    $target_url_count++;
                    break;
                }
            }
        }

        $has_evidence_section =
            str_contains($lower, 'evidence log') ||
            str_contains($lower, 'sources:') ||
            str_contains($lower, 'source urls');
        $has_search_ledger = str_contains($lower, 'search ledger');
        $has_social_profile_check =
            str_contains($lower, 'social / profile check') ||
            str_contains($lower, 'social/profile check') ||
            str_contains($lower, 'linkedin.com/') ||
            str_contains($lower, 'instagram.com/') ||
            str_contains($lower, 'facebook.com/') ||
            str_contains($lower, 'x.com/') ||
            str_contains($lower, 'twitter.com/');
        $reported_no_new_evidence = str_contains($lower, 'no_new_evidence_found');

        return [
            'url_count'             => count($unique_urls),
            'host_count'            => count($hosts),
            'target_url_count'      => $target_url_count,
            'evidence_url_count'    => count($evidence_urls),
            'checked_todo_count'    => count($todo_matches[0] ?? []),
            'named_people_count'    => count($named_people),
            'generic_advice_hits'   => $generic_advice_hits,
            'has_evidence_section'  => $has_evidence_section,
            'has_search_ledger'     => $has_search_ledger,
            'has_social_profile_check' => $has_social_profile_check,
            'generic_host_only'     => $generic_host_only,
            'reported_no_new_evidence' => $reported_no_new_evidence,
            'failure_signature'     => $matched_failure_sig,
        ];
    }

    /**
     * Extract a markdown section body by heading label.
     */
    private function extract_markdown_section(string $text, string $heading): string
    {
        $pattern = '/(?:^|\n)#+\s*' . preg_quote($heading, '/') . '\s*\n(.*?)(?=\n#+\s+[A-Z]|\z)/is';
        if (preg_match($pattern, $text, $matches)) {
            return trim((string) ($matches[1] ?? ''));
        }

        return '';
    }

    /**
     * Build normalized target tokens for validating source specificity.
     *
     * @param array $party
     * @return string[]
     */
    private function get_target_identity_tokens(array $party): array
    {
        $values = [
            (string) ($party['name'] ?? ''),
            (string) ($party['company'] ?? ''),
        ];
        $raw_identity_values = [
            (string) ($party['permit_nbr'] ?? ''),
            (string) ($party['license'] ?? ''),
        ];
        $ignored = [
            'inc',
            'llc',
            'corp',
            'co',
            'company',
            'construction',
            'builders',
            'builder',
            'group',
            'development',
            'contracting',
            'services',
            'bros',
            'brothers',
        ];

        $tokens = [];
        foreach ($values as $value) {
            $value = strtolower(trim($value));
            if ($value === '') {
                continue;
            }

            $parts = preg_split('/[^a-z0-9]+/i', strtolower($value)) ?: [];
            foreach ($parts as $part) {
                if (strlen($part) < 4 || in_array($part, $ignored, true)) {
                    continue;
                }
                $tokens[$part] = true;
            }

            $compact = preg_replace('/[^a-z0-9]+/i', '', $value);
            if (is_string($compact) && strlen($compact) >= 3 && !in_array($compact, $ignored, true)) {
                $tokens[$compact] = true;
            }
        }

        foreach ($raw_identity_values as $value) {
            $value = strtolower(trim($value));
            if ($value === '') {
                continue;
            }

            if (strlen($value) >= 4) {
                $tokens[$value] = true;
            }

            $compact = preg_replace('/[^a-z0-9]+/i', '', $value);
            if (is_string($compact) && strlen($compact) >= 4) {
                $tokens[$compact] = true;
            }
        }

        return array_keys($tokens);
    }

    /**
     * Save raw discovery text to a file for human review
     *
     * @param array  $source             Source record
     * @param array  $party              Party data
     * @param string $investigation_text  Raw agent output
     */
    private function save_discovery_file(array $source, array $party, string $investigation_text, array $runtime = [], string $stage = ''): void
    {
        $dir = $this->get_secure_output_dir('discoveries');

        $source_id = $source['id'] ?? 0;
        $party_name = sanitize_file_name($party['name'] ?? 'unknown');
        $stage_slug = sanitize_file_name($stage !== '' ? $stage : 'discovery');
        $microtime = microtime(true);
        $seconds = (int) $microtime;
        $microseconds = (int) (($microtime - $seconds) * 1000000);
        $timestamp = date('Ymd_His', $seconds) . sprintf('_%06d', $microseconds);
        $filename = "{$dir}/source_{$source_id}_{$party_name}_{$stage_slug}_{$timestamp}.txt";

        $header = "=== RAW WIRE DISCOVERY FILE ===\n";
        $header .= "Source ID: {$source_id}\n";
        $header .= "Party: " . ($party['name'] ?? 'unknown') . "\n";
        $header .= "License: " . ($party['license'] ?? 'N/A') . "\n";
        $header .= "Permit: " . ($source['permit_nbr'] ?? 'N/A') . "\n";
        $header .= "Generated: " . current_time('mysql') . "\n";
        $header .= "Stage: {$stage_slug}\n";
        $header .= "Method: " . ($runtime['investigation_method'] ?? 'unknown') . "\n";
        $header .= "Provider: " . ($runtime['investigation_provider'] ?? 'unknown') . "\n";
        $header .= "Model: " . ($runtime['investigation_model'] ?? 'unknown') . "\n";
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
    // JSON Utilities
    // -------------------------------------------------------------------------

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

        $run_started = microtime(true);
        $max_runtime_seconds = max(180, (int) apply_filters('rawwire_party_investigator_max_runtime_seconds', 900));

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

        $this->mark_source_phase($source_id, 'running', sprintf('Started investigation run (force=%s)', $force ? 'yes' : 'no'));

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
                $recovered_parties = $this->recover_parties_from_existing_context($source_id, $source);
                if (!empty($recovered_parties)) {
                    $parties = $recovered_parties;
                    $has_named_parties = true;

                    rawwire_log('party_investigator', sprintf(
                        'Permit discovery empty for source #%d, but recovered %d named parties from prior context',
                        $source_id,
                        count($parties)
                    ), 'info');
                }

                // LADBS discovery failed — do NOT waste tokens investigating empty-name parties
                // Check if discover_parties_from_permit already wrote an Owner-Builder note
                $existing_notes = $wpdb->get_var($wpdb->prepare(
                    "SELECT investigator_notes FROM {$sources_table} WHERE id = %d",
                    $source_id
                ));
                $is_owner_builder = (strpos($existing_notes ?? '', 'Owner-Builder') !== false);

                if (!empty($recovered_parties)) {
                    $allow_permit_context_investigation = true;
                } elseif ($is_owner_builder) {
                    // Do not short-circuit owner-builder permits.
                    // Continue with permit-context investigation even without a named contractor,
                    // because owner-side decision makers are still valid targets.
                    $allow_permit_context_investigation = true;

                    rawwire_log('party_investigator', sprintf(
                        'Owner-Builder permit for source #%d: continuing permit-context investigation (no early skip)',
                        $source_id
                    ), 'info');
                } else {
                    $note = 'LADBS scrape could not extract contractor, owner, applicant, or business data, and no reusable prior party context was found.';
                    $status = 'failed';

                    rawwire_log('party_investigator', sprintf(
                        'LADBS discovery empty for source #%d — no usable party context, aborting investigation',
                        $source_id
                    ), 'warning');

                    $this->mark_source_phase($source_id, $status, $note, true);

                    return [
                        'success'        => false,
                        'skipped'        => true,
                        'owner_builder'  => false,
                        'reason'         => 'LADBS discovery failed — no usable party context to investigate',
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
                $recovered_parties = $this->recover_parties_from_existing_context($source_id, $source);
                if (!empty($recovered_parties)) {
                    $parties = $recovered_parties;
                    $has_named_parties = true;

                    rawwire_log('party_investigator', sprintf(
                        'Discovery yielded no usable names for source #%d, but recovered %d parties from prior context',
                        $source_id,
                        count($parties)
                    ), 'info');
                }
            }

            if (!$has_named_parties && !$allow_permit_context_investigation) {
                rawwire_log('party_investigator', sprintf(
                    'Discovery returned data but no usable party names for source #%d — aborting',
                    $source_id
                ), 'warning');

                $this->mark_source_phase(
                    $source_id,
                    'failed',
                    'LADBS scraped but no contractor, owner, applicant, or business name was usable.',
                    true
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

        if ($this->is_runtime_expired($run_started, $max_runtime_seconds)) {
            $note = sprintf('Runtime guard tripped before party loop (%ds max).', $max_runtime_seconds);
            $this->mark_source_phase($source_id, 'failed', $note, true);
            return [
                'success'   => false,
                'skipped'   => true,
                'reason'    => $note,
                'source_id' => $source_id,
            ];
        }

        if (empty($parties)) {
            // Mark as investigated even if nothing found, to avoid retrying
            $this->mark_source_phase($source_id, 'no_parties_found', 'No parties found even after permit discovery', true);

            return [
                'skipped'   => true,
                'reason'    => 'No parties found even after permit discovery',
                'source_id' => $source_id,
            ];
        }

        $investigations = [];
        $failure_reasons = [];

        foreach ($parties as $party) {
            if ($this->is_runtime_expired($run_started, $max_runtime_seconds)) {
                $note = sprintf('Runtime guard tripped during party loop (%ds max).', $max_runtime_seconds);
                $this->mark_source_phase($source_id, 'failed', $note, true);
                return [
                    'success'         => false,
                    'source_id'       => $source_id,
                    'parties_count'   => count($investigations),
                    'failure_reasons' => $failure_reasons,
                    'message'         => $note,
                ];
            }

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

            $this->mark_source_phase($source_id, 'failed', 'Investigation failed: ' . $failure_summary, true);

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

        $this->mark_source_phase($source_id, 'running', 'Raw findings saved; starting extraction phase');

        // Step 1.5: Optional deterministic entity deep-dive pass.
        // Disabled by default to avoid long-running duplicate agent calls in Soothsayer runs.
        $entity_report_file = '';
        $run_entity_deep_dive = (bool) apply_filters(
            'rawwire_party_investigator_entity_deep_dive_enabled',
            false,
            $source_id,
            $source,
            $investigations
        );

        if ($run_entity_deep_dive) {
            $entity_report_file = $this->build_entity_deep_dive_report($source_id, $source, $investigations);
            if (!empty($entity_report_file)) {
                $parsed_discovery = $this->extract_discovered_parties_from_entity_report($entity_report_file);
                if (!empty($parsed_discovery)) {
                    $this->apply_discovered_parties($source_id, $parsed_discovery);
                }
            }
        } else {
            rawwire_log('party_investigator', sprintf(
                'Entity deep-dive skipped for source #%d (feature disabled by default)',
                $source_id
            ), 'debug');
        }

        // Step 2: Use cheap AI to extract structured profiles from the file
        if ($this->is_runtime_expired($run_started, $max_runtime_seconds)) {
            $note = sprintf('Runtime guard tripped before extraction (%ds max).', $max_runtime_seconds);
            $this->mark_source_phase($source_id, 'failed', $note, true);
            return [
                'success'   => false,
                'source_id' => $source_id,
                'reason'    => $note,
            ];
        }

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
        $this->mark_source_phase($source_id, 'running', 'Extraction complete; saving profiles and triggering scoring');
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
     * Update source-level investigation phase for UI/runtime visibility.
     */
    private function mark_source_phase(int $source_id, string $status, string $note, bool $mark_completed_at = false): void
    {
        global $wpdb;
        $sources_table = rawwire_leads()->table('sources');

        $update = [
            'investigation_status' => $status,
            'investigator_notes'   => $note,
        ];

        if ($mark_completed_at) {
            $update['parties_investigated_at'] = current_time('mysql');
        }

        $wpdb->update($sources_table, $update, ['id' => $source_id]);
        $this->mirror_source_investigation_to_candidates($source_id, $update);
        rawwire_log('party_investigator', sprintf('Phase update source #%d: %s - %s', $source_id, $status, $note), 'debug');
    }

    /**
     * Mirror source-side investigation state into candidate rows for the same source.
     */
    private function mirror_source_investigation_to_candidates(int $source_id, array $source_update): void
    {
        global $wpdb;

        $candidates_table = rawwire_leads()->table('candidates');
        if (empty($candidates_table)) {
            return;
        }

        static $candidate_columns = null;
        if ($candidate_columns === null) {
            $candidate_columns = $wpdb->get_col("SHOW COLUMNS FROM {$candidates_table}", 0);
            if (!is_array($candidate_columns)) {
                $candidate_columns = [];
            }
        }

        $candidate_update = [];

        if (array_key_exists('investigation_status', $source_update) && in_array('investigation_status', $candidate_columns, true)) {
            $status_map = [
                'running'          => 'in_progress',
                'completed'        => 'complete',
                'incomplete'       => 'failed',
                'no_parties_found' => 'failed',
                'failed'           => 'failed',
                'pending'          => 'pending',
            ];

            $source_status = strtolower((string) $source_update['investigation_status']);
            if (isset($status_map[$source_status])) {
                $candidate_update['investigation_status'] = $status_map[$source_status];
            }
        }

        if (array_key_exists('investigator_notes', $source_update) && in_array('investigator_summary', $candidate_columns, true)) {
            $candidate_update['investigator_summary'] = (string) $source_update['investigator_notes'];
        }

        if (array_key_exists('parties_investigated_at', $source_update) && in_array('investigated_at', $candidate_columns, true)) {
            $candidate_update['investigated_at'] = $source_update['parties_investigated_at'];
        }

        if (array_key_exists('party_profiles', $source_update) && in_array('party_profiles', $candidate_columns, true)) {
            $candidate_update['party_profiles'] = $source_update['party_profiles'];
        }

        if (empty($candidate_update)) {
            return;
        }

        $result = $wpdb->update($candidates_table, $candidate_update, ['source_id' => $source_id]);
        if ($result === false) {
            rawwire_log('party_investigator', sprintf(
                'Candidate mirror FAILED for source #%d: %s',
                $source_id,
                $wpdb->last_error
            ), 'warning');
            return;
        }

        if ($result > 0) {
            rawwire_log('party_investigator', sprintf(
                'Candidate mirror OK for source #%d: %d row(s) updated',
                $source_id,
                $result
            ), 'debug');
        }
    }

    /**
     * Global runtime guard for long investigation runs.
     */
    private function is_runtime_expired(float $run_started, int $max_runtime_seconds): bool
    {
        return (microtime(true) - $run_started) > $max_runtime_seconds;
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
            $raw_is_valid = !empty($raw) && $this->is_valid_agent_investigation((string) $raw, [
                'name' => $name,
                'company' => $profile['company_name'] ?? $name,
            ]);

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

            $failed_update = [
                'parties_investigated_at' => current_time('mysql'),
                'investigation_status'    => 'failed',
                'investigator_notes'      => 'All investigation results were placeholder data. No real research data obtained.',
            ];

            $wpdb->update(
                $sources_table,
                $failed_update,
                ['id' => $source_id]
            );
            $failed_update['party_profiles'] = null;
            $this->mirror_source_investigation_to_candidates($source_id, $failed_update);
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
            $this->mirror_source_investigation_to_candidates($source_id, $update_data);
            $current_source = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$sources_table} WHERE id = %d", $source_id),
                ARRAY_A
            );
            if (is_array($current_source)) {
                $this->sync_network_entities_for_source($source_id, $current_source, $investigations);
            }
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
    private function build_entity_deep_dive_report(int $source_id, array $source, array $investigations, array $seed_entities = []): string
    {
        $entities = !empty($seed_entities) ? array_values($seed_entities) : $this->collect_entity_deep_dive_seeds($source, $investigations);
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
     * Rebuild lightweight investigation rows from saved party_profiles JSON.
     *
     * @param array $party_profiles
     * @return array<int, array<string, mixed>>
     */
    private function build_investigations_from_saved_profiles(array $party_profiles): array
    {
        $investigations = [];

        foreach ($party_profiles as $party_type => $party_data) {
            if (!is_string($party_type) || strpos($party_type, '_') === 0 || !is_array($party_data)) {
                continue;
            }

            $party_name = trim((string) ($party_data['name'] ?? ''));
            $profile = $party_data['profile'] ?? [];
            if (!is_array($profile)) {
                $profile = [];
            }

            if ($party_name === '' && empty($profile)) {
                continue;
            }

            $investigations[] = [
                'party_type' => $party_type,
                'party_name' => $party_name,
                'profile'    => $profile,
            ];
        }

        return $investigations;
    }

    /**
     * Collect deterministic entity seeds from source + investigations.
     *
     * @param array $source
     * @param array $investigations
     * @return array<int, array<string, mixed>>
     */
    private function collect_entity_deep_dive_seeds(array $source, array $investigations): array
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
            if ($name === '' || $this->is_unusable_party_name($name)) {
                continue;
            }

            $entities[] = [
                'name'            => $name,
                'company'         => $name,
                'type'            => $type,
                'relationship'    => 'source_record',
                'discovered_from' => 'source_record',
                'score_hint'      => 100,
            ];
        }

        foreach ($investigations as $inv) {
            $party_name = trim((string) ($inv['party_name'] ?? ''));
            $party_type = $this->normalize_entity_type((string) ($inv['party_type'] ?? 'contractor'));
            $profile = $inv['profile'] ?? [];
            if (!is_array($profile)) {
                $profile = [];
            }

            if ($party_name !== '' && !$this->is_unusable_party_name($party_name)) {
                $entities[] = [
                    'name'            => $party_name,
                    'company'         => trim((string) ($profile['company_name'] ?? $party_name)),
                    'type'            => $party_type,
                    'relationship'    => 'investigated_party',
                    'discovered_from' => (string) ($inv['party_type'] ?? 'investigated_party'),
                    'score_hint'      => (int) ($profile['value_score'] ?? 0),
                ];
            }

            $discovered = $profile['discovered_entities'] ?? [];
            if (!is_array($discovered)) {
                continue;
            }

            foreach ($discovered as $entity) {
                if (!is_array($entity)) {
                    continue;
                }

                $name = trim((string) ($entity['name'] ?? ''));
                $type = $this->normalize_entity_type((string) ($entity['type'] ?? ''));
                if ($name === '' || $this->is_unusable_party_name($name)) {
                    continue;
                }

                $entities[] = [
                    'name'            => $name,
                    'company'         => trim((string) ($entity['company'] ?? $name)),
                    'type'            => $type,
                    'relationship'    => trim((string) ($entity['relationship'] ?? 'discovered_entity')),
                    'discovered_from' => $party_name !== '' ? $party_name : (string) ($inv['party_type'] ?? 'investigated_party'),
                    'score_hint'      => (int) ($entity['score_hint'] ?? 0),
                ];
            }
        }

        $deduped = [];
        foreach ($entities as $entity) {
            $key = strtolower(trim((string) ($entity['type'] ?? '')) . '|' . trim((string) ($entity['name'] ?? '')));
            if ($key === '|') {
                continue;
            }

            if (!isset($deduped[$key])) {
                $deduped[$key] = $entity;
                continue;
            }

            if ((int) ($entity['score_hint'] ?? 0) > (int) ($deduped[$key]['score_hint'] ?? 0)) {
                $deduped[$key]['score_hint'] = (int) $entity['score_hint'];
            }

            foreach (['company', 'relationship', 'discovered_from'] as $field) {
                if (empty($deduped[$key][$field]) && !empty($entity[$field])) {
                    $deduped[$key][$field] = $entity[$field];
                }
            }
        }

        return array_values($deduped);
    }

    /**
     * Sync derived entity seeds into the network_entities table.
     *
     * @param int $source_id
     * @param array $source
     * @param array $investigations
     * @return int
     */
    private function sync_network_entities_for_source(int $source_id, array $source, array $investigations): int
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rawwire_network_entities';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return 0;
        }

        $entities = $this->collect_entity_deep_dive_seeds($source, $investigations);
        $existing_rows = $wpdb->get_results(
            $wpdb->prepare("SELECT id, name, type FROM {$table} WHERE source_id = %d", $source_id),
            ARRAY_A
        );

        $existing_map = [];
        foreach ($existing_rows as $row) {
            $existing_map[strtolower(trim((string) ($row['type'] ?? '')) . '|' . trim((string) ($row['name'] ?? '')))] = (int) ($row['id'] ?? 0);
        }

        $seen_keys = [];
        $now = current_time('mysql');

        foreach ($entities as $entity) {
            $key = strtolower(trim((string) ($entity['type'] ?? '')) . '|' . trim((string) ($entity['name'] ?? '')));
            if ($key === '|') {
                continue;
            }

            $seen_keys[$key] = true;
            $data = [
                'source_id'        => $source_id,
                'discovered_from'  => (string) ($entity['discovered_from'] ?? ''),
                'name'             => (string) ($entity['name'] ?? ''),
                'company'          => (string) ($entity['company'] ?? ''),
                'type'             => (string) ($entity['type'] ?? 'contractor'),
                'relationship'     => (string) ($entity['relationship'] ?? ''),
                'score_hint'       => (int) ($entity['score_hint'] ?? 0),
                'updated_at'       => $now,
            ];

            if (isset($existing_map[$key]) && $existing_map[$key] > 0) {
                $wpdb->update($table, $data, ['id' => $existing_map[$key]]);
            } else {
                $data['created_at'] = $now;
                $wpdb->insert($table, $data);
            }
        }

        foreach ($existing_map as $key => $row_id) {
            if (!isset($seen_keys[$key])) {
                $wpdb->delete($table, ['id' => $row_id]);
            }
        }

        rawwire_log('party_investigator', sprintf(
            'Synced %d network entities for source #%d',
            count($entities),
            $source_id
        ));

        return count($entities);
    }

    /**
     * Mark network entities as completed by the Tier 2 deep-dive pass.
     *
     * @param int $source_id
     * @param array<int> $entity_ids
     * @return void
     */
    private function mark_network_entities_deep_dive_complete(int $source_id, array $entity_ids = []): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rawwire_network_entities';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return;
        }

        $now = current_time('mysql');
        $entity_ids = array_values(array_filter(array_map('intval', $entity_ids)));

        if (empty($entity_ids)) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table}
                 SET probed = 1,
                     probed_at = %s,
                     investigation_tier = 2,
                     tier2_completed = 1,
                     updated_at = %s
                 WHERE source_id = %d",
                $now,
                $now,
                $source_id
            ));
            return;
        }

        $placeholders = implode(',', array_fill(0, count($entity_ids), '%d'));
        $params = array_merge([$now, $now, $source_id], $entity_ids);
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table}
                 SET probed = 1,
                     probed_at = %s,
                     investigation_tier = 2,
                     tier2_completed = 1,
                     updated_at = %s
                 WHERE source_id = %d AND id IN ({$placeholders})",
                $params
            )
        );
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

    /**
     * Run the entity deep-dive phase against an already investigated source.
     *
     * @param int $source_id
     * @param array<int> $entity_ids Optional network entity row ids to target.
     * @return array|WP_Error
     */
    public function run_entity_deep_dive(int $source_id, array $entity_ids = [])
    {
        global $wpdb;

        $sources_table = rawwire_leads()->table('sources');
        $source = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$sources_table} WHERE id = %d", $source_id),
            ARRAY_A
        );

        if (!$source) {
            return new WP_Error('source_not_found', 'Source not found');
        }

        $party_profiles = json_decode((string) ($source['party_profiles'] ?? ''), true);
        if (!is_array($party_profiles)) {
            $party_profiles = [];
        }

        $investigations = $this->build_investigations_from_saved_profiles($party_profiles);
        if (empty($investigations)) {
            return new WP_Error('missing_party_profiles', 'Run Tier 1 investigation before launching Tier 2 deep dive.');
        }

        $synced_count = $this->sync_network_entities_for_source($source_id, $source, $investigations);

        $seed_entities = [];
        $entity_ids = array_values(array_filter(array_map('intval', $entity_ids)));
        if (!empty($entity_ids)) {
            $network_table = $wpdb->prefix . 'rawwire_network_entities';
            $placeholders = implode(',', array_fill(0, count($entity_ids), '%d'));
            $params = array_merge([$source_id], $entity_ids);
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT name, company, type, relationship, discovered_from, score_hint
                     FROM {$network_table}
                     WHERE source_id = %d AND id IN ({$placeholders})",
                    $params
                ),
                ARRAY_A
            );

            foreach ($rows as $row) {
                $name = trim((string) ($row['name'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $seed_entities[] = [
                    'name'            => $name,
                    'company'         => trim((string) ($row['company'] ?? $name)),
                    'type'            => $this->normalize_entity_type((string) ($row['type'] ?? 'contractor')),
                    'relationship'    => trim((string) ($row['relationship'] ?? 'discovered_entity')),
                    'discovered_from' => trim((string) ($row['discovered_from'] ?? 'network_map')),
                    'score_hint'      => (int) ($row['score_hint'] ?? 0),
                ];
            }
        }

        $report_path = $this->build_entity_deep_dive_report($source_id, $source, $investigations, $seed_entities);
        if ($report_path === '') {
            return new WP_Error('deep_dive_empty', 'No viable entities were available for a Tier 2 deep dive.');
        }

        $parsed_discovery = $this->extract_discovered_parties_from_entity_report($report_path);
        if (!empty($parsed_discovery)) {
            $this->apply_discovered_parties($source_id, $parsed_discovery, 'Entity deep dive report');
        }

        $this->mark_network_entities_deep_dive_complete($source_id, $entity_ids);

        return [
            'success'        => true,
            'source_id'      => $source_id,
            'report_path'    => $report_path,
            'entity_count'   => !empty($seed_entities) ? count($seed_entities) : $synced_count,
            'applied_fields' => array_keys(array_diff_key($parsed_discovery, ['_discovery_meta' => true])),
        ];
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
