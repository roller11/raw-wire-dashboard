<?php

/**
 * @ai-context Search Instinct MCP for "OpenClaw Adapter Function Map v1" before modifying this file.
 */

/**
 * OpenClaw Adapter (Venice.ai Direct Web Search)
 * 
 * Calls Venice.ai API directly with web search + scraping enabled,
 * giving Grok browser-level access to public records for contractor lookups.
 * 
 * Features:
 * - Direct Venice.ai API (no Docker gateway needed)
 * - Native web search + web scraping via venice_parameters
 * - Connection state tracking with cooldown after failures
 * - Automatic retry with exponential backoff
 * - Graceful degradation to DuckDuckGo
 * - Venice model tool syntax detection
 * - Health check with status caching
 * 
 * @package RawWire_Dashboard
 * @since   1.0.32
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-search-provider.php';
require_once __DIR__ . '/class-duckduckgo-provider.php';

class RawWire_OpenClaw_Adapter implements RawWire_Search_Provider_Interface
{
    /** @var string Provider identifier */
    const PROVIDER_NAME = 'openclaw';

    /** @var string Venice.ai API base URL (OpenAI-compatible) */
    const VENICE_API_BASE = 'https://api.venice.ai/api/v1';

    /** @var string Legacy Docker gateway URL (fallback) */
    const LEGACY_GATEWAY_URL = 'http://172.17.76.22:18789/v1';

    /** @var string Default model - GLM 4.7 Flash Heretic via Venice */
    const DEFAULT_MODEL = 'olafangensan-glm-4.7-flash-heretic';

    /** @var int Health check cache TTL (seconds) */
    const HEALTH_CACHE_TTL = 300;

    /** @var int Connection timeout (seconds) */
    const CONNECT_TIMEOUT = 15;

    /** @var int Request timeout (seconds) */
    const REQUEST_TIMEOUT = 120;

    /** @var int Max retries before failure */
    const MAX_RETRIES = 2;

    /** @var string[] Patterns indicating Venice tool syntax (not executed) */
    const GARBAGE_PATTERNS = [
        '<|python_tag|>',
        '<function',
        'web_fetch(',
        'web_search(',
        '<function.web_search',
        '{"type": "function"',
        '<tool_call>',
    ];

    /** @var string OpenClaw CLI binary path (NVM install, configurable via settings) */
    const DEFAULT_OPENCLAW_PATH = '/home/ractal1/.nvm/versions/node/v22.22.0/bin/openclaw';

    /** @var string Node.js bin directory (needed for PATH) */
    const DEFAULT_NODE_BIN_PATH = '/home/ractal1/.nvm/versions/node/v22.22.0/bin';

    /** @var string Base URL */
    private string $base_url;

    /** @var string Auth token / API key */
    private string $auth_token;

    /** @var string Model to use */
    private string $model;

    /** @var RawWire_DuckDuckGo_Provider|null Fallback provider */
    private ?RawWire_DuckDuckGo_Provider $fallback = null;

    /**
     * Constructor
     * 
     * Resolves settings from: explicit params → rawwire_openclaw_settings → Venice settings → defaults.
     */
    public function __construct(?string $base_url = null, ?string $auth_token = null, ?string $model = null)
    {
        $settings = $this->get_settings();
        $venice_settings = get_option('rawwire_venice_settings', []);
        $pi_settings = get_option('rawwire_party_investigator_settings', []);

        // Resolve base URL: explicit → openclaw settings → Venice direct
        $configured_url = $base_url ?? $settings['host'] ?? '';
        if (empty($configured_url) || $configured_url === self::LEGACY_GATEWAY_URL || $configured_url === 'http://localhost:18789') {
            // Venice direct mode — no Docker needed
            $this->base_url = self::VENICE_API_BASE;
        } else {
            $this->base_url = rtrim($configured_url, '/');
        }

        // Resolve auth: explicit → openclaw override → Venice API key
        if ($auth_token) {
            $this->auth_token = $auth_token;
        } elseif (!empty($settings['auth_token'])) {
            // Explicit override in OpenClaw settings
            $this->auth_token = $settings['auth_token'];
        } elseif (!empty($venice_settings['api_key'])) {
            // Fall back to Venice API key (Venice direct mode)
            $this->auth_token = $venice_settings['api_key'];
        } else {
            // Final fallback: check config files (www-data HOME may differ)
            $this->auth_token = $this->get_venice_api_key();
        }

        // Model priority: explicit → party investigator settings → openclaw settings → default
        $this->model = $model ?? $pi_settings['investigation_model'] ?? $settings['model'] ?? self::DEFAULT_MODEL;

        // Debug logging for troubleshooting
        if (function_exists('rawwire_log')) {
            $auth_source = 'unknown';
            if ($auth_token) {
                $auth_source = 'explicit';
            } elseif (!empty($settings['auth_token'])) {
                $auth_source = 'openclaw_settings';
            } elseif (!empty($venice_settings['api_key'])) {
                $auth_source = 'venice_settings';
            } else {
                $auth_source = 'config_file';
            }
            rawwire_log('openclaw', sprintf(
                'Initialized: base=%s, auth_source=%s, auth_set=%s, model=%s',
                $this->base_url,
                $auth_source,
                !empty($this->auth_token) ? 'yes' : 'NO',
                $this->model
            ), 'debug');
        }
    }

    /**
     * Whether we're using Venice.ai direct (not Docker gateway)
     */
    private function is_venice_direct(): bool
    {
        return strpos($this->base_url, 'venice.ai') !== false;
    }

    /**
     * {@inheritdoc}
     */
    public function get_name(): string
    {
        return self::PROVIDER_NAME;
    }

    /**
     * Get settings from WordPress (rawwire_openclaw_settings)
     */
    private function get_settings(): array
    {
        return wp_parse_args(get_option('rawwire_openclaw_settings', []), [
            'enabled'              => true,
            'host'                 => '',
            'auth_token'           => '',
            'model'                => self::DEFAULT_MODEL,
            'max_tokens'           => 4000,
            'temperature'          => 0.3,
            'enable_web_search'    => false,  // NEVER use Venice web search
            'enable_web_scraping'  => false,  // NEVER use Venice web scraping
            'enable_web_citations' => false,  // NEVER use Venice web citations
            'analysis_max_tokens'  => 8000,
            'analysis_temperature' => 0.3,
            'request_timeout'      => self::REQUEST_TIMEOUT,
            'max_retries'          => self::MAX_RETRIES,
        ]);
    }

    /**
     * Get a specific setting value
     *
     * @param string $key Setting key
     * @param mixed $default Default value
     * @return mixed
     */
    public function get_setting(string $key, $default = null)
    {
        $settings = $this->get_settings();
        return $settings[$key] ?? $default;
    }

    /**
     * {@inheritdoc}
     * 
     * Checks:
     * 1. Not in cooldown from previous failures
     * 2. Auth token / API key is configured
     * 3. Health endpoint responds (cached)
     */
    public function is_available(): bool
    {
        // Check cooldown first (fast, no network)
        if (RawWire_Provider_Status::is_in_cooldown(self::PROVIDER_NAME)) {
            return false;
        }

        // Check token configured
        if (empty($this->auth_token)) {
            return false;
        }

        // Check cached health status
        $health_key = 'rw_openclaw_health';
        $cached_health = get_transient($health_key);

        if ($cached_health !== false) {
            return $cached_health === 'ok';
        }

        // Perform health check
        $healthy = $this->check_health();
        set_transient($health_key, $healthy ? 'ok' : 'down', self::HEALTH_CACHE_TTL);

        return $healthy;
    }

    /**
     * Check API health (Venice.ai or legacy gateway)
     */
    private function check_health(): bool
    {
        $response = wp_remote_get($this->base_url . '/models', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->auth_token,
                'Accept' => 'application/json',
            ],
            'timeout' => self::CONNECT_TIMEOUT,
        ]);

        if (is_wp_error($response)) {
            $mode = $this->is_venice_direct() ? 'Venice direct' : 'gateway';
            rawwire_log('openclaw', "Health check failed ({$mode}): " . $response->get_error_message(), 'warning');
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code === 200) {
            $mode = $this->is_venice_direct() ? 'Venice.ai direct' : 'Docker gateway';
            rawwire_log('openclaw', "Connected via {$mode}, model: {$this->model}", 'info');
        }
        return $code === 200;
    }

    /**
     * {@inheritdoc}
     */
    public function search(string $query): array
    {
        // Skip if not available
        if (!$this->is_available()) {
            rawwire_log('openclaw', 'Not available, falling back to DuckDuckGo', 'info');
            return $this->get_fallback()->search($query);
        }

        $response = $this->chat_with_retry([
            ['role' => 'user', 'content' => "Search the web for: {$query}. Return the results as a list with titles and URLs."]
        ]);

        // Check for valid response
        if ($this->is_valid_response($response)) {
            RawWire_Provider_Status::record_success(self::PROVIDER_NAME);
            return $this->parse_response($response);
        }

        // Invalid response - fall back
        rawwire_log('openclaw', 'Invalid response (tool syntax), falling back', 'warning');
        return $this->get_fallback()->search($query);
    }

    /**
     * {@inheritdoc}
     * 
     * SOOTHSAYER - Investigative Intelligence Agent
     * 
     * Uses OpenClaw agent CLI with browser tools (navigate, snapshot, click, type)
     * for REAL web research. Model: Venice GLM 4.7 Flash Heretic.
     * 
     * This is NOT a scraper. It's a resourceful, probing investigative agent -
     * a highly trained detective that knows how to find people and connect dots.
     * 
     * Uses Venice skill directly (python3 venice.py) which supports --model flag.
     * The openclaw agent CLI's --model flag is no longer valid.
     */
    public function research(array $party): array
    {
        $name = $party['name'] ?? '';
        $type = $party['type'] ?? 'company';

        rawwire_log('openclaw', sprintf(
            'SoothSayer investigation: %s (%s) via Venice skill',
            $name ?: '[permit lookup]',
            $type
        ), 'info');

        // Delegate to venice_research which uses the working Venice skill script
        return $this->venice_research($party);
    }

    /**
     * SoothSayer system prompt - defines the investigative agent persona
     */
    private function get_soothsayer_system_prompt(): string
    {
        return <<<SYSTEM
You are SOOTHSAYER, an elite investigative intelligence agent specialized in LA commercial construction and interior design business development.

You are NOT a scraper. You are a resourceful, probing detective who knows how to find people and connect the dots.

YOUR MISSION:
Map the target's network with actionable professional intelligence that enables your client (an interior design firm) to get in front of decision makers.

INVESTIGATION APPROACH:
- Be creative and think laterally
- Look for indirect signals and abstract indicators
- Cross-reference multiple sources
- Follow the network connections
- Find the social access points

OUTPUT FORMAT:
Return structured intelligence in clear sections. Include confidence levels and source citations.
SYSTEM;
    }

    /**
     * Build SoothSayer investigation prompt based on party data
     */
    private function build_soothsayer_prompt(array $party): string
    {
        $name = $party['name'] ?? '';
        $type = $party['type'] ?? 'company';
        $company = $party['company'] ?? '';
        $license = $party['license'] ?? '';
        $permit_nbr = $party['permit_nbr'] ?? '';
        $project_address = $party['project_address'] ?? '';
        $location = $party['city'] ?? $party['location'] ?? 'Los Angeles, California';

        // If no name but have permit info, start with LADBS lookup
        if (empty($name) && (!empty($permit_nbr) || !empty($project_address))) {
            return $this->build_soothsayer_permit_prompt($permit_nbr, $project_address, $license, $location);
        }

        // Build context
        $context = [];
        $context[] = "TARGET: {$name}";
        $context[] = "Type: {$type}";
        if ($company && $company !== $name) {
            $context[] = "Company: {$company}";
        }
        $context[] = "Location: {$location}";
        if ($permit_nbr) {
            $context[] = "LA Permit #: {$permit_nbr}";
        }
        if ($license) {
            $context[] = "CA Contractor License: {$license}";
        }
        $context_str = implode("\n", $context);

        return <<<PROMPT
INVESTIGATE THIS TARGET:

{$context_str}

=== TIER 1: IDENTITY & CONTACT ===
Find: Full legal name, addresses, emails, phone numbers, website, LinkedIn
Verify: CA contractor license at cslb.ca.gov if applicable

=== TIER 2: COMPANY INTELLIGENCE ===
- Company structure & org chart
- Key players & decision makers (who signs contracts?)
- Revenue indicators & company trajectory
- Current & past projects (especially interiors, FF&E, tenant improvements)

=== TIER 3: BIDDING & PROCUREMENT ===
- How do they select contractors/vendors? (RFP, prequalification, invite-only?)
- Vendor registration requirements or approved vendor lists
- Past bid announcements or award notices
- Subcontractor preferences

=== TIER 4: INTERIOR DESIGN SIGNALS ===
Look for abstract indicators:
- "Workplace experience", "space planning", "tenant improvements"
- "Amenity upgrades", "lobby renovation", "common area refresh"
- "Furniture procurement", "FF&E", "finishes"
- "Sustainability", "LEED", "wellness certification"
- New lease announcements (they'll need buildout)
- Award wins for "best workplace" or "office design"
- Healthcare, hospitality, education projects (high interior design need)

=== TIER 5: SOCIAL ACCESS POINTS ===
Find ways to get in front of decision makers:
- Industry events, conferences, golf tournaments they sponsor/attend
- Charity boards, foundation involvement
- Industry association leadership (AGC, NAIOP, BOMA, CoreNet, IIDA, AIA)
- Country clubs, business groups, alumni networks
- Speaking engagements, panel appearances
- Executive assistants who control calendars

=== TIER 6: NETWORK EXPANSION ===
- Partners, suppliers, subcontractors they work with
- Known business associates
- Other business entities (parent companies, subsidiaries)

Return findings in structured sections with source URLs. Include confidence levels (high/medium/low) for each finding.
PROMPT;
    }

    /**
     * Build SoothSayer prompt when we only have permit info (no contractor name)
     */
    private function build_soothsayer_permit_prompt(string $permit_nbr, string $project_address, string $license, string $location): string
    {
        $context = [];
        if ($permit_nbr) {
            $context[] = "LA Building Permit: {$permit_nbr}";
        }
        if ($project_address) {
            $context[] = "Project Address: {$project_address}";
        }
        if ($license) {
            $context[] = "Contractor License (if known): {$license}";
        }
        $context[] = "Location: {$location}";
        $context_str = implode("\n", $context);

        return <<<PROMPT
PERMIT-BASED INVESTIGATION:

{$context_str}

=== STEP 1: IDENTIFY THE PARTIES ===
Search LADBS permit records, LA County Assessor, and public records to find:
- Contractor name and license number
- Property owner
- Applicant/developer
- Any architect or engineer of record

=== STEP 2: DEEP INVESTIGATION ===
Once you identify the contractor/owner, investigate them fully:

IDENTITY & CONTACT:
- Full legal name, addresses, emails, phone numbers
- Website and LinkedIn profiles
- Verify CA contractor license at cslb.ca.gov

COMPANY INTELLIGENCE:
- Key decision makers (who signs contracts?)
- Company structure and trajectory
- Current projects and specialties

INTERIOR DESIGN SIGNALS:
- Look for: "tenant improvements", "FF&E", "buildout", "space planning"
- Healthcare, hospitality, education projects
- Any workplace or amenity upgrades

SOCIAL ACCESS POINTS:
- Industry associations (AGC, NAIOP, BOMA, CoreNet, IIDA)
- Events, charities, golf tournaments
- Speaking engagements or board positions

BIDDING & PROCUREMENT:
- How do they select vendors?
- Prequalification requirements
- Vendor registration portals

Return findings in structured sections with source URLs and confidence levels.
PROMPT;
    }

    /**
     * Research using Venice AI skill directly
     * 
     * Uses the venice-ai skill's Python scripts with built-in web search.
     * More reliable than going through OpenClaw agent for simple research.
     */
    private function venice_research(array $party): array
    {
        $name = $party['name'] ?? '';
        $type = $party['type'] ?? 'company';
        $company = $party['company'] ?? '';
        $location = $party['location'] ?? $party['city'] ?? 'Los Angeles, California';
        $license = $party['license'] ?? '';

        // Build search query
        $search_terms = [];
        $search_terms[] = $name;
        if ($company && $company !== $name) {
            $search_terms[] = $company;
        }
        $search_terms[] = $location;
        if ($type === 'contractor' && $license) {
            $search_terms[] = 'California contractor license';
        }
        $query = implode(' ', $search_terms);

        // Build research prompt
        $prompt = $this->build_venice_research_prompt($party);

        // Get Venice API key from config
        $api_key = $this->get_venice_api_key();
        if (empty($api_key)) {
            rawwire_log('openclaw', 'Venice API key not configured', 'error');
            return ['success' => false, 'error' => 'Venice API key not configured'];
        }

        // Venice skill script path
        $skill_path = getenv('HOME') . '/.openclaw/skills/venice-ai/scripts/venice.py';
        if (!file_exists($skill_path)) {
            rawwire_log('openclaw', 'Venice skill not installed: ' . $skill_path, 'error');
            return ['success' => false, 'error' => 'Venice skill not installed'];
        }

        // Build command WITHOUT web search (never use Venice web search/scraping)
        // Use configured model (default: grok-41-fast which is proven to work well)
        $model = $this->model;
        $cmd = sprintf(
            'VENICE_API_KEY=%s python3 %s chat %s --model %s --strip-thinking 2>&1',
            escapeshellarg($api_key),
            escapeshellarg($skill_path),
            escapeshellarg($prompt),
            escapeshellarg($model)
        );

        rawwire_log('openclaw', 'Running Venice skill: ' . substr($cmd, 0, 200) . '...', 'debug');

        // Execute with timeout
        $timeout_sec = 120;
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);

        if (!is_resource($process)) {
            rawwire_log('openclaw', 'Failed to spawn Venice process', 'error');
            return ['success' => false, 'error' => 'Failed to spawn Venice process'];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $start_time = microtime(true);

        while (true) {
            $status = proc_get_status($process);
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);

            if (!$status['running']) {
                break;
            }

            if ((microtime(true) - $start_time) > $timeout_sec) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                rawwire_log('openclaw', 'Venice timeout after ' . $timeout_sec . 's', 'warning');
                return ['success' => false, 'error' => 'Venice timeout'];
            }

            usleep(100000);
        }

        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit_code = proc_close($process);

        rawwire_log('openclaw', sprintf(
            'Venice skill exit %d, stdout %d bytes, stderr %d bytes',
            $exit_code,
            strlen($stdout),
            strlen($stderr)
        ), 'debug');

        if ($exit_code !== 0 || empty($stdout)) {
            $error = !empty($stderr) ? $stderr : 'No output from Venice';
            rawwire_log('openclaw', 'Venice skill error: ' . substr($error, 0, 500), 'warning');
            return ['success' => false, 'error' => $error];
        }

        return [
            'success' => true,
            'content' => trim($stdout),
            'source'  => 'venice-skill',
            'results' => [
                ['query' => $query, 'raw_findings' => trim($stdout)]
            ],
            'search_count' => 1,
        ];
    }

    /**
     * Build Venice research prompt
     */
    private function build_venice_research_prompt(array $party): string
    {
        $name = $party['name'] ?? '';
        $type = $party['type'] ?? 'company';
        $company = $party['company'] ?? '';
        $location = $party['city'] ?? $party['location'] ?? 'Los Angeles, California';
        $license = $party['license'] ?? '';
        $address = $party['address'] ?? '';
        $permit_nbr = $party['permit_nbr'] ?? '';
        $project_address = $party['project_address'] ?? '';

        // Build compact context
        $context = "{$name}";
        if ($company && $company !== $name) {
            $context .= " ({$company})";
        }
        $context .= ", {$type} in {$location}";
        if ($license) {
            $context .= ", CA License #{$license}";
        }

        return <<<PROMPT
Research this target: {$context}

FIND AND RETURN:
- Company website URL
- Email address
- Phone number
- LinkedIn profile URL
- Key personnel names and titles

Return what you know about this target. Include source URLs where possible.
PROMPT;
    }

    /**
     * Build prompt for LADBS lookup when we don't have contractor name
     */
    private function build_ladbs_lookup_prompt(string $permit_nbr, string $project_address, string $license): string
    {
        $ladbs_url = 'https://www.ladbsservices2.lacity.org/OnlineServices/PermitReport/PermitResults';

        return <<<PROMPT
You are a business detective. I need you to find contractor information for an LA building permit.

STEP 1 - GET CONTRACTOR NAME:
Go to LADBS permit lookup: {$ladbs_url}
Search for permit number: {$permit_nbr}
OR search by address: {$project_address}

Find the CONTRACTOR NAME and LICENSE NUMBER on the permit details.

STEP 2 - RESEARCH THE CONTRACTOR:
Once you have the contractor name, web search for:
1. Company website
2. Email address
3. Phone number  
4. LinkedIn profile
5. CSLB license verification at cslb.ca.gov

Return:
- Contractor name (from LADBS)
- License number
- Contact info found
- Source URLs

Be concise.
PROMPT;
    }

    /**
     * Get Venice API key from OpenClaw config
     */
    private function get_venice_api_key(): string
    {
        // Try environment first
        $key = getenv('VENICE_API_KEY');
        if (!empty($key)) {
            return $key;
        }

        // Common config paths (www-data runs as service, HOME may be /var/www)
        $config_paths = [
            getenv('HOME') . '/.openclaw/openclaw.json',
            '/home/ractal1/.openclaw/openclaw.json',  // User's config
            '/root/.openclaw/openclaw.json',
        ];

        foreach ($config_paths as $config_path) {
            if (!file_exists($config_path)) {
                continue;
            }

            $config = json_decode(file_get_contents($config_path), true);
            if (!is_array($config)) {
                continue;
            }

            // Check skills.entries first
            $key = $config['skills']['entries']['venice-ai']['env']['VENICE_API_KEY'] ?? '';
            if (!empty($key)) {
                return $key;
            }

            // Fall back to models.providers.venice.apiKey
            $key = $config['models']['providers']['venice']['apiKey'] ?? '';
            if (!empty($key)) {
                return $key;
            }
        }

        return '';
    }

    /**
     * Build comprehensive prompt for agent-based research
     * 
     * Unlike API calls, agent receives full context in a single message.
     * Includes system instructions, party details, and required outputs.
     * 
     * This is the FLAGSHIP investigation prompt — designed to produce
     * Perplexity-grade intelligence reports using OpenClaw browser tools.
     */
    public function build_agent_research_prompt(array $party): string
    {
        $name = $party['name'] ?? '';
        $type = $party['type'] ?? 'company';
        $company = $party['company'] ?? '';
        $location = $party['city'] ?? $party['location'] ?? 'Los Angeles, California';
        $license = $party['license'] ?? '';
        $permit_nbr = $party['permit_nbr'] ?? '';
        $project_address = $party['project_address'] ?? '';

        // If no name but have permit info, start with LADBS lookup
        if (empty($name) && (!empty($permit_nbr) || !empty($project_address))) {
            return $this->build_ladbs_first_prompt($permit_nbr, $project_address, $license, $location);
        }

        // Build context section
        $context = "TARGET: {$name}";
        $context .= "\nType: {$type}";
        if ($company && $company !== $name) {
            $context .= "\nCompany: {$company}";
        }
        $context .= "\nLocation: {$location}";
        if ($permit_nbr) {
            $context .= "\nLA Permit #: {$permit_nbr}";
        }
        if ($license) {
            $context .= "\nContractor License: {$license}";
        }

        return <<<PROMPT
You are an elite business intelligence researcher. Your job is to produce a comprehensive, deeply researched investigation report — the kind a high-end research firm would deliver.

You have full browser access. USE IT AGGRESSIVELY. Visit real websites, navigate pages, extract actual data. Do NOT rely on your training data alone.

{$context}

PRODUCE A COMPREHENSIVE INVESTIGATION COVERING ALL OF THESE SECTIONS:

## 1. COMPANY STRUCTURE & IDENTITY
- Legal name, DBA, incorporation state, founding year
- Headquarters address, regional offices
- Employee count, revenue range, annual project volume
- Licensing: CA contractor license number, class, status (verify at CSLB.ca.gov)
- Markets served (K-12, healthcare, civic, multi-family, commercial, etc.)
- Delivery methods (GC, CM-at-risk, Design-Build, Lease-Leaseback, IPD)
- Ownership structure (private, ESOP, public, PE-backed)

## 2. KEY DECISION MAKERS (Organize by tier)
### Executive/Board (strategy, major awards)
- Names, titles, tenure, background, committee roles
### Operational/BD Leadership (networking targets — these are who a sub wants to reach)
- Estimating leads, VP Operations, sector VPs, BD directors
- Who controls bid lists and trade partner selection?
### Legal & Risk
- CLO, risk officers, insurance/bonding gatekeepers

For EACH person: name, exact title, relevant background, what they control.

## 3. OUTREACH, DIVERSITY & SMALL BUSINESS PROGRAMS
- DVBE, MBE/WBE, SBE outreach programs and goals
- Subcontractor databases they maintain
- How they advertise opportunities (bid registers, publications)
- Case studies where they exceeded diversity goals
- Community outreach initiatives

## 4. SUBCONTRACTOR PREQUALIFICATION & BIDDING PROCESS
- Online prequalification platforms (PQBids, BuildingConnected, etc.)
- What their prequal requires (safety/EMR, financials, bonding, project history)
- How they evaluate sub bids (price, schedule, constructability, VE)
- Standard subcontract terms and documentation culture
- How to get on their bid list

## 5. PROFESSIONAL ORGANIZATIONS & EVENTS
- Which industry orgs their leaders belong to (AGC, CMAA, NAIOP, BOMA, CoreNet, AIA, USGBC, IIDA, ACE Mentor)
- Conferences, golf tournaments, charity events they sponsor or attend
- Speaking engagements and panel appearances
- Social media presence and engagement patterns

## 6. ACTIVE & UPCOMING PROJECTS
- Current projects under construction
- Projects in planning/design/permitting phase
- Recently won contracts or announcements
- Interior design signals: TI work, FF&E, amenity upgrades, lobby renovations

## 7. PRACTICAL NETWORKING STRATEGY
- Concrete steps a subcontractor should take in the next 60-90 days
- Specific people to connect with on LinkedIn
- Events to attend
- How to get into their prequal system
- What differentiators to emphasize

BROWSER RESEARCH STRATEGY:
1. Start with their company website — About, Team/Leadership, Projects pages
2. Check CSLB.ca.gov for license verification
3. LinkedIn company page + key executives
4. PQBids or BuildingConnected for prequalification
5. Industry news (ENR, Construction Dive) for recent projects
6. Google "{$name}" + "subcontractor" OR "prequalification" OR "bid" OR "RFP"
7. Check professional org directories (AGC, CMAA, NAIOP)

INSTRUCTIONS:
- Cite your sources with URLs throughout
- Name REAL people with REAL titles — verify on the actual website, don't guess
- If you can't find something, say so explicitly as an intelligence gap
- Be thorough — this report drives real business decisions
PROMPT;
    }

    /**
     * Build prompt that starts with LADBS lookup when we don't have contractor name
     */
    private function build_ladbs_first_prompt(string $permit_nbr, string $project_address, string $license, string $location): string
    {
        $ladbs_url = 'https://www.ladbsservices2.lacity.org/OnlineServices/PermitReport/PermitResults';

        $search_hint = '';
        if ($permit_nbr) {
            $search_hint = "Search by permit number: {$permit_nbr}";
        } elseif ($project_address) {
            $search_hint = "Search by address: {$project_address}";
        }

        return <<<PROMPT
You are a business detective. Find contractor information for an LA building permit, then uncover SOCIAL access points and interior design opportunities.

STEP 1 - GET CONTRACTOR NAME FROM LADBS:
Navigate to: {$ladbs_url}
{$search_hint}

Find: Contractor Name, License Number, Address

STEP 2 - DEEP RESEARCH:
Once you have the contractor name:

1. BIDDING & PROCUREMENT
   - How do they select subcontractors? RFP process?
   - Prequalification requirements
   - Vendor registration portal

2. UPCOMING PROJECTS
   - Projects in planning or design phase
   - Recent permit applications (they may need interiors)
   - Announced wins or new contracts

3. INTERIOR DESIGN INDICATORS
   - "Tenant improvements", "TI work", "buildout"
   - "FF&E", "furniture", "finishes"
   - Healthcare, hospitality, education projects (high interior design need)

4. SOCIAL ACCESS
   - Industry events they sponsor (AGC golf, NAIOP events)
   - Association leadership roles
   - Charity involvement
   - Key executives' LinkedIn profiles

5. DECISION MAKERS
   - Who handles design/interiors? (VP Operations, Project Director)
   - Contact info, email patterns

BROWSER TOOLS: navigate, snapshot, click, type

Return structured findings with URLs.
PROMPT;
    }

    /**
     * Build research prompt for a party
     */
    private function build_research_prompt(array $party): string
    {
        $name = $party['name'] ?? 'Unknown';
        $type = $party['type'] ?? 'company';
        $company = $party['company'] ?? '';
        $location = $party['city'] ?? $party['location'] ?? 'California';
        $license = $party['license'] ?? '';

        $context = "Research this {$type}: {$name}";
        if ($company && $company !== $name) {
            $context .= "\nCompany: {$company}";
        }
        $context .= "\nLocation: {$location}";
        if ($license) {
            $context .= "\nContractor License: {$license}";
        }

        return <<<PROMPT
{$context}

Find and return:
1. Company/person identity verification
2. LinkedIn profile(s)
3. Contact information (email patterns, phone, address)
4. Industry association memberships (AGC, NAIOP, BOMA, etc.)
5. Recent projects or news (2025-2026)
6. Key people with titles

Return your findings as structured text with clear sections.
PROMPT;
    }

    /**
     * Chat with automatic retry on failure
     */
    private function chat_with_retry(array $messages, array $options = []): string
    {
        $settings = $this->get_settings();
        $max_retries = (int) ($settings['max_retries'] ?? self::MAX_RETRIES);
        $last_error = '';

        for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
            $response = $this->chat($messages, $options);

            // Got a response (might be garbage, but we connected)
            if (!empty($response)) {
                return $response;
            }

            // Empty response - retry
            $last_error = 'Empty response';
            rawwire_log('openclaw', "Attempt {$attempt}/{$max_retries} failed: {$last_error}", 'warning');

            if ($attempt < $max_retries) {
                // Exponential backoff: 1s, 2s, 4s...
                sleep(pow(2, $attempt - 1));
            }
        }

        // All retries failed
        RawWire_Provider_Status::record_failure(self::PROVIDER_NAME, $last_error);
        return '';
    }

    /**
     * Raw chat completion request
     * 
     * Uses Venice.ai direct API for chat completions.
     * Web search and scraping are DISABLED — use OpenClaw browser tools instead.
     */
    public function chat(array $messages, array $options = []): string
    {
        $settings = $this->get_settings();

        $body = [
            'model' => $this->model,
            'messages' => $messages,
            'max_tokens' => $options['max_tokens'] ?? (int) ($settings['max_tokens'] ?? 4000),
            'temperature' => $options['temperature'] ?? (float) ($settings['temperature'] ?? 0.3),
        ];

        // Venice direct mode: inject venice_parameters (web search/scraping ALWAYS off)
        if ($this->is_venice_direct()) {
            $body['venice_parameters'] = [
                'enable_web_search'    => 'off',
                'enable_web_scraping'  => false,
                'enable_web_citations' => false,
                'include_venice_system_prompt' => false,
            ];
        }

        $timeout = (int) ($options['timeout'] ?? $settings['request_timeout'] ?? self::REQUEST_TIMEOUT);

        $json_body = wp_json_encode($body);

        // Debug: log payload size to diagnose Venice ~2KB limit issues
        rawwire_log('openclaw', sprintf(
            'POST payload: %d bytes (model=%s, msgs=%d, max_tokens=%d)',
            strlen($json_body),
            $this->model,
            count($messages),
            $body['max_tokens']
        ), 'debug');

        $response = wp_remote_post($this->base_url . '/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->auth_token,
                'Content-Type' => 'application/json',
                'Expect'       => '',  // Prevent cURL Expect: 100-continue (Venice/Cloudflare drops it)
            ],
            'body'        => $json_body,
            'timeout'     => $timeout,
            'httpversion' => '1.1',
        ]);

        if (is_wp_error($response)) {
            RawWire_Provider_Status::record_failure(self::PROVIDER_NAME, $response->get_error_message());
            rawwire_log('openclaw', 'Request failed: ' . $response->get_error_message(), 'error');
            return '';
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            RawWire_Provider_Status::record_failure(self::PROVIDER_NAME, "HTTP {$code}");
            rawwire_log('openclaw', "HTTP error: {$code}", 'error');
            return '';
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $content = $data['choices'][0]['message']['content'] ?? '';

        // Record success to reset any failure cooldown
        if (!empty($content)) {
            RawWire_Provider_Status::record_success(self::PROVIDER_NAME);
        }

        return $content;
    }

    /**
     * Check if response is valid (not raw tool syntax)
     */
    private function is_valid_response(string $response): bool
    {
        if (empty($response)) {
            return false;
        }

        foreach (self::GARBAGE_PATTERNS as $pattern) {
            if (stripos($response, $pattern) !== false) {
                rawwire_log('openclaw', "Detected garbage pattern: {$pattern}", 'debug');
                return false;
            }
        }

        // Must have some meaningful content (not just whitespace)
        return strlen(trim($response)) > 50;
    }

    /**
     * Parse OpenClaw response into structured results
     */
    private function parse_response(string $response): array
    {
        // Try JSON first
        $json = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            return $json;
        }

        // Return as raw text result
        return [
            ['title' => 'Research Findings', 'content' => $response]
        ];
    }

    /**
     * Get fallback provider (lazy loaded)
     */
    private function get_fallback(): RawWire_DuckDuckGo_Provider
    {
        if ($this->fallback === null) {
            $this->fallback = new RawWire_DuckDuckGo_Provider();
        }
        return $this->fallback;
    }

    /**
     * Research using fallback provider
     */
    private function fallback_research(array $party): array
    {
        $result = $this->get_fallback()->research($party);
        $result['source'] = 'duckduckgo_fallback';
        return $result;
    }

    /**
     * Force health check (bypass cache)
     */
    public function force_health_check(): bool
    {
        delete_transient('rw_openclaw_health');
        RawWire_Provider_Status::reset(self::PROVIDER_NAME);
        return $this->is_available();
    }

    // =========================================================================
    // AGENT CLI METHODS (Browser Tool Access)
    // =========================================================================

    /**
     * Provision a full OpenClaw config directory so the agent can run as www-data.
     *
     * Apache's www-data user cannot read /home/ractal1/.openclaw/ (mode 700).
     * This method creates a writable HOME with all required config files generated
     * from WP settings — so the agent finds its API key, model, browser, and tools.
     *
     * Config is written once and reused across requests (persists in /tmp).
     * When WP settings change, the config hash changes → fresh config is written.
     *
     * @return string Path to the provisioned HOME directory
     */
    private function provision_openclaw_home(): string
    {
        $home_dir = sys_get_temp_dir() . '/openclaw-home';
        $config_dir = $home_dir . '/.openclaw';
        $config_file = $config_dir . '/openclaw.json';
        $env_file = $config_dir . '/.env';
        $agent_dir = $config_dir . '/agents/main/agent';
        $hash_file = $config_dir . '/.config-hash';

        // Build config from WP settings
        $api_key = $this->auth_token;
        $base_url = $this->base_url;
        $model_id = $this->model;

        // Config hash to detect when WP settings change → regenerate
        $config_hash = md5($api_key . '|' . $base_url . '|' . $model_id);

        // Check if we already have a valid, current config
        if (is_file($config_file) && is_file($hash_file)) {
            $existing_hash = @file_get_contents($hash_file);
            if ($existing_hash === $config_hash) {
                return $home_dir;
            }
        }

        // Create directory tree
        @mkdir($agent_dir, 0755, true);
        @mkdir($config_dir . '/browser', 0755, true);

        // === openclaw.json — main config ===
        // NOTE: Do NOT include custom meta keys (source, generatedAt) — OpenClaw
        // validates config strictly and rejects unrecognized keys, causing the
        // agent to output error messages instead of investigation data.
        $config = [
            'browser' => [
                'enabled' => true,
                'executablePath' => '/usr/bin/chromium-browser',
                'headless' => true,
                'noSandbox' => true,
            ],
            'models' => [
                'mode' => 'merge',
                'providers' => [
                    'venice' => [
                        'baseUrl' => $base_url,
                        'apiKey' => $api_key,
                        'api' => 'openai-completions',
                        'models' => [
                            [
                                'id' => $model_id,
                                'name' => 'Venice Model (auto-provisioned)',
                                'reasoning' => true,
                                'input' => ['text'],
                                'cost' => ['input' => 0, 'output' => 0, 'cacheRead' => 0, 'cacheWrite' => 0],
                                'contextWindow' => 131072,
                                'maxTokens' => 8192,
                            ],
                        ],
                    ],
                ],
            ],
            'agents' => [
                'defaults' => [
                    'model' => [
                        'primary' => 'venice/' . $model_id,
                    ],
                    'maxConcurrent' => 2,
                    'subagents' => [
                        'maxConcurrent' => 4,
                    ],
                ],
            ],
            'tools' => [
                'allow' => [
                    'exec', 'process', 'read', 'write', 'edit',
                    'web_search', 'web_fetch',
                    'sessions_list', 'sessions_history',
                    'agents_list', 'browser',
                ],
                'web' => [
                    'search' => ['enabled' => true],
                    'fetch' => ['enabled' => true],
                ],
                'elevated' => ['enabled' => true],
                'exec' => [
                    'backgroundMs' => 10000,
                    'timeoutSec' => 300,
                ],
            ],
        ];

        file_put_contents($config_file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // === .env — API keys as environment variables ===
        $env_content = "OPENAI_API_KEY={$api_key}\n";
        $env_content .= "OPENAI_BASE_URL={$base_url}\n";
        $env_content .= "VENICE_API_KEY={$api_key}\n";
        file_put_contents($env_file, $env_content);

        // === agents/main/agent/models.json — agent-specific model list ===
        $agent_models = [
            'providers' => [
                'venice' => [
                    'baseUrl' => $base_url,
                    'api' => 'openai-completions',
                    'models' => [
                        [
                            'id' => $model_id,
                            'name' => 'Venice Model (auto-provisioned)',
                            'reasoning' => true,
                            'input' => ['text'],
                            'cost' => ['input' => 0, 'output' => 0, 'cacheRead' => 0, 'cacheWrite' => 0],
                            'contextWindow' => 131072,
                            'maxTokens' => 8192,
                        ],
                    ],
                ],
            ],
        ];

        file_put_contents($agent_dir . '/models.json', json_encode($agent_models, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Write config hash for cache validation
        file_put_contents($hash_file, $config_hash);

        // Clear any workspace personality files (AGENTS.md, SOUL.md, etc.)
        // that OpenClaw may have cached from prior runs — we want the agent
        // to focus solely on the investigation prompt, not adopt a persona
        $workspace_dir = $config_dir . '/workspace';
        if (is_dir($workspace_dir)) {
            $personality_files = ['AGENTS.md', 'SOUL.md', 'TOOLS.md', 'IDENTITY.md', 'USER.md', 'HEARTBEAT.md', 'BOOTSTRAP.md', 'MEMORY.md'];
            foreach ($personality_files as $pf) {
                $pf_path = $workspace_dir . '/' . $pf;
                if (is_file($pf_path)) {
                    @unlink($pf_path);
                }
            }
        }

        rawwire_log('openclaw', sprintf(
            'Provisioned OpenClaw HOME at %s (model: venice/%s)',
            $home_dir,
            $model_id
        ), 'info');

        return $home_dir;
    }

    /**
     * Chat via OpenClaw agent CLI (with browser tools)
     * 
     * Unlike chat() which calls Venice API directly, this calls the local
     * OpenClaw agent via CLI, giving it access to browser tools (navigate,
     * snapshot, click, fill, etc.) for scraping JavaScript-rendered sites.
     * 
     * Required for LADBS and other SPAs that don't work with HTTP scraping.
     * 
     * @param string $message User message/prompt for the agent
     * @param array  $options Optional: agent, timeout, json, model
     * @return array{success: bool, content?: string, error?: string}
     */
    public function agent_chat(string $message, array $options = []): array
    {
        $agent = $options['agent'] ?? 'main';
        $timeout_ms = $options['timeout'] ?? 120000;
        $json_mode = $options['json'] ?? true;
        $model = $options['model'] ?? 'venice/' . $this->model;

        // Get openclaw binary path from settings or default
        $settings = $this->get_settings();
        $openclaw_path = $settings['openclaw_path'] ?? self::DEFAULT_OPENCLAW_PATH;
        $node_bin_path = $settings['node_bin_path'] ?? self::DEFAULT_NODE_BIN_PATH;

        // Ensure PATH includes Node.js bin directory
        $current_path = getenv('PATH') ?: '';
        if (strpos($current_path, $node_bin_path) === false) {
            putenv('PATH=' . $node_bin_path . ':' . $current_path);
        }

        // OpenClaw reads config from ~/.openclaw/ — we must provision a valid config
        // at a writable HOME so www-data can run the agent with proper API keys
        $openclaw_home = $this->provision_openclaw_home();
        putenv('HOME=' . $openclaw_home);

        // Build CLI command with explicit path
        $cmd_parts = [
            escapeshellarg($openclaw_path) . ' agent',
            '--agent ' . escapeshellarg($agent),
            '--local',
        ];

        if ($json_mode) {
            $cmd_parts[] = '--json';
        }

        $cmd_parts[] = '--message ' . escapeshellarg($message);

        $cmd = implode(' ', $cmd_parts) . ' 2>&1';

        rawwire_log('openclaw', sprintf('agent_chat: Executing CLI: %s', $cmd), 'debug');

        // Execute with timeout
        $timeout_sec = (int) ceil($timeout_ms / 1000);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);

        if (!is_resource($process)) {
            rawwire_log('openclaw', 'agent_chat: Failed to spawn process', 'error');
            return [
                'success' => false,
                'error'   => 'Failed to spawn openclaw agent process',
            ];
        }

        fclose($pipes[0]); // Close stdin

        // Set non-blocking
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $start_time = microtime(true);

        while (true) {
            $status = proc_get_status($process);

            // Read any available output
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);

            // Check if process exited
            if (!$status['running']) {
                break;
            }

            // Check timeout
            if ((microtime(true) - $start_time) > $timeout_sec) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                rawwire_log('openclaw', 'agent_chat: Timeout after ' . $timeout_sec . 's', 'warning');
                return [
                    'success' => false,
                    'error'   => 'Agent timeout after ' . $timeout_sec . ' seconds',
                ];
            }

            usleep(100000); // 100ms
        }

        // Final read
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit_code = proc_close($process);

        rawwire_log('openclaw', sprintf(
            'agent_chat: Exit code %d, stdout %d bytes, stderr %d bytes',
            $exit_code,
            strlen($stdout),
            strlen($stderr)
        ), 'debug');

        // Parse JSON output if enabled
        if ($json_mode && !empty($stdout)) {
            $json = json_decode($stdout, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                // OpenClaw agent returns payloads[0].text in JSON mode
                if (isset($json['payloads'][0]['text'])) {
                    return [
                        'success' => true,
                        'content' => $json['payloads'][0]['text'],
                        'raw'     => $json,
                    ];
                }
                // Also handle 'response' key (alternate format)
                if (isset($json['response'])) {
                    return [
                        'success' => true,
                        'content' => $json['response'],
                        'raw'     => $json,
                    ];
                }
                // JSON parsed but no known content field — return whole thing
                rawwire_log('openclaw', 'agent_chat: JSON parsed but unknown structure. Keys: ' . implode(', ', array_keys($json)), 'warning');
            }
        }

        // Return raw output
        if (!empty($stdout)) {
            return [
                'success' => true,
                'content' => trim($stdout),
            ];
        }

        // Error case
        return [
            'success' => false,
            'error'   => !empty($stderr) ? trim($stderr) : 'No output from agent',
        ];
    }

    /**
     * Start browser if not running (ensures browser is available)
     * 
     * @param string $profile Browser profile name (default: openclaw)
     * @return bool True if browser is running
     */
    public function ensure_browser(string $profile = 'openclaw'): bool
    {
        // Check status
        $status_cmd = 'openclaw browser --browser-profile ' . escapeshellarg($profile) . ' status --json 2>&1';
        $status_json = shell_exec($status_cmd);

        if ($status_json) {
            $status = json_decode($status_json, true);
            if (!empty($status['running'])) {
                rawwire_log('openclaw', "Browser profile '{$profile}' already running", 'debug');
                return true;
            }
        }

        // Start browser
        rawwire_log('openclaw', "Starting browser profile '{$profile}'...", 'info');
        $start_cmd = 'openclaw browser --browser-profile ' . escapeshellarg($profile) . ' start 2>&1';
        $result = shell_exec($start_cmd);

        // Verify it started
        sleep(2); // Give it time to initialize
        $status_json = shell_exec($status_cmd);

        if ($status_json) {
            $status = json_decode($status_json, true);
            if (!empty($status['running'])) {
                rawwire_log('openclaw', "Browser profile '{$profile}' started successfully", 'info');
                return true;
            }
        }

        rawwire_log('openclaw', "Failed to start browser profile '{$profile}'", 'error');
        return false;
    }

    /**
     * Scrape LADBS permit page using browser
     * 
     * Navigates to LADBS, enters address, and extracts permit data including
     * contractor info from permit detail pages. Handles the multi-step SPA workflow.
     * 
     * @param string $address Full address to look up
     * @return array{success: bool, permits?: array, error?: string}
     */
    public function scrape_ladbs_permit(string $address): array
    {
        $profile = 'openclaw';

        // Ensure browser is running
        if (!$this->ensure_browser($profile)) {
            return [
                'success' => false,
                'error'   => 'Could not start browser',
            ];
        }

        // Parse address into number and street name
        // Handle various formats: "123 Main St", "123 S Main Street", etc.
        if (preg_match('/^(\d+)\s+(?:[NSEW]\.?\s+)?(.+)$/i', trim($address), $matches)) {
            $street_number = $matches[1];
            // Remove common street suffixes
            $street_name = preg_replace('/\s+(st|street|ave|avenue|blvd|boulevard|dr|drive|rd|road|ct|court|pl|place|ln|lane|way|pk|pkwy|parkway|cir|circle)\.?$/i', '', trim($matches[2]));
            // Also remove directional suffixes that might remain
            $street_name = preg_replace('/\s+[NSEW]\.?$/i', '', $street_name);
        } else {
            return [
                'success' => false,
                'error'   => 'Could not parse address: ' . $address,
            ];
        }

        rawwire_log('openclaw', sprintf(
            'LADBS browser scrape: street_number=%s, street_name=%s',
            $street_number,
            $street_name
        ), 'info');

        $base_cmd = 'openclaw browser --browser-profile ' . escapeshellarg($profile);

        // Step 1: Navigate to LADBS Permit Lookup
        $nav_url = 'https://www.ladbsservices2.lacity.org/OnlineServices/?service=plr';
        shell_exec($base_cmd . ' navigate ' . escapeshellarg($nav_url) . ' 2>&1');
        sleep(2);
        shell_exec($base_cmd . ' wait --load networkidle --timeout 15000 2>&1');

        // Step 2: Fill the search form using the correct fill command format
        // e81 = Street Number textbox, e88 = Street Name textbox
        $fields_json = json_encode([
            ['ref' => 'e81', 'type' => 'fill', 'value' => $street_number],
            ['ref' => 'e88', 'type' => 'fill', 'value' => $street_name],
        ]);
        $fill_result = shell_exec($base_cmd . ' fill --fields ' . escapeshellarg($fields_json) . ' 2>&1');

        if (strpos($fill_result, 'Error') !== false) {
            rawwire_log('openclaw', 'LADBS fill failed: ' . $fill_result, 'warning');
            return [
                'success' => false,
                'error'   => 'Failed to fill search form: ' . $fill_result,
            ];
        }

        // Step 3: Click Search button (e95)
        shell_exec($base_cmd . ' click e95 2>&1');
        sleep(3);
        shell_exec($base_cmd . ' wait --load networkidle --timeout 20000 2>&1');

        // Step 4: Get initial snapshot to find permit tabs
        $snapshot = shell_exec($base_cmd . ' snapshot --format ai --limit 300 2>&1');

        if (empty($snapshot) || strpos($snapshot, 'Error') !== false) {
            rawwire_log('openclaw', 'LADBS: Failed to get search results snapshot', 'warning');
            return [
                'success' => false,
                'error'   => 'Failed to capture search results',
            ];
        }

        // Check for "no results" or errors
        if (stripos($snapshot, 'no data') !== false && stripos($snapshot, 'Permit Information') === false) {
            rawwire_log('openclaw', 'LADBS: No results found for address', 'info');
            return [
                'success' => true,
                'permits' => [],
                'message' => 'No permits found for address',
            ];
        }

        // Step 5: Look for "Permit Information found" tab and click it
        if (preg_match('/tab "Permit Information found: (\d+)".*?\[ref=(e\d+)\]/', $snapshot, $permit_tab)) {
            $permit_count = (int) $permit_tab[1];
            $permit_tab_ref = $permit_tab[2];

            rawwire_log('openclaw', sprintf('LADBS: Found %d permits, clicking tab %s', $permit_count, $permit_tab_ref), 'info');

            shell_exec($base_cmd . ' click ' . escapeshellarg($permit_tab_ref) . ' 2>&1');
            sleep(2);
        }

        // Step 6: Get snapshot with permit list
        $snapshot = shell_exec($base_cmd . ' snapshot --format ai --limit 500 2>&1');

        // Step 7: Find permit links and get details
        $permits = [];

        // Look for permit links like: link "23043-90000-01049" [ref=e202]
        if (preg_match_all('/link "(\d+-\d+-\d+)".*?\[ref=(e\d+)\]/', $snapshot, $permit_links, PREG_SET_ORDER)) {
            rawwire_log('openclaw', sprintf('LADBS: Found %d permit links to check', count($permit_links)), 'debug');

            // Get details for up to 5 permits to avoid timeout
            $max_permits = min(5, count($permit_links));

            for ($i = 0; $i < $max_permits; $i++) {
                $permit_number = $permit_links[$i][1];
                $permit_ref = $permit_links[$i][2];

                // Click the permit link
                shell_exec($base_cmd . ' click ' . escapeshellarg($permit_ref) . ' 2>&1');
                sleep(2);

                // Check if a new tab opened
                $tabs_json = shell_exec($base_cmd . ' tabs --json 2>&1');
                $tabs = json_decode($tabs_json, true);

                if (is_array($tabs) && count($tabs) > 1) {
                    // Focus the new tab (the one with PcisPermitDetail in URL)
                    foreach ($tabs as $tab) {
                        if (isset($tab['url']) && strpos($tab['url'], 'PcisPermitDetail') !== false) {
                            shell_exec($base_cmd . ' focus ' . escapeshellarg($tab['id']) . ' 2>&1');
                            sleep(2);
                            break;
                        }
                    }
                }

                // Get permit detail snapshot
                $detail_snapshot = shell_exec($base_cmd . ' snapshot --format ai --limit 200 2>&1');

                // Extract contractor info from the detail page
                $permit_info = [
                    'permit_number' => $permit_number,
                ];

                // Look for contractor info: 'cell "Contractor" [ref=e96]' followed by contractor name
                if (preg_match('/Contractor.*?cell "([^"]+); Lic\. No\.: (\d+-[A-Z]\d+)"/', $detail_snapshot, $contractor)) {
                    $permit_info['contractor_name'] = trim($contractor[1]);
                    $permit_info['contractor_license'] = trim($contractor[2]);
                } elseif (preg_match('/Contractor.*?cell "([^"]+)".*?cell "([^"]+)"/', $detail_snapshot, $contractor_alt)) {
                    $permit_info['contractor_name'] = trim($contractor_alt[1]);
                }

                // Look for permit status
                if (preg_match('/Current Status.*?definition[^:]*: (.*?) on (\d+\/\d+\/\d+)/', $detail_snapshot, $status)) {
                    $permit_info['status'] = trim($status[1]);
                    $permit_info['status_date'] = trim($status[2]);
                }

                // Look for work description
                if (preg_match('/Work Description.*?definition[^:]*: ([^\n]+)/', $detail_snapshot, $work)) {
                    $permit_info['work_description'] = trim($work[1]);
                }

                // Look for permit type
                if (preg_match('/Type.*?definition[^:]*: ([^\n]+)/', $detail_snapshot, $type)) {
                    $permit_info['type'] = trim($type[1]);
                }

                $permits[] = $permit_info;

                // Close this detail tab and go back to results
                if (is_array($tabs) && count($tabs) > 1) {
                    shell_exec($base_cmd . ' close 2>&1');
                    sleep(1);
                }
            }
        }

        if (empty($permits)) {
            // Try AI extraction as fallback
            rawwire_log('openclaw', 'LADBS: No permits parsed, trying AI extraction', 'info');

            return [
                'success' => true,
                'permits' => [],
                'raw_snapshot' => $snapshot,
            ];
        }

        rawwire_log('openclaw', sprintf('LADBS: Extracted %d permits with details', count($permits)), 'info');

        return [
            'success' => true,
            'permits' => $permits,
        ];
    }
}
