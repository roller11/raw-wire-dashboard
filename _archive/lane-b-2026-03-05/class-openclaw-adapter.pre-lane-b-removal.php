<?php

/**
 * @ai-context Search Instinct MCP for "OpenClaw Adapter Function Map v2" before modifying this file.
 */

/**
 * OpenClaw Adapter (OpenAI-Primary Chat + Agent CLI)
 * 
 * Calls OpenAI API for chat completions and invokes the OpenClaw CLI
 * agent for browser-based investigations.
 * 
 * Features:
 * - OpenAI API (primary) with Venice.ai fallback
 * - OpenClaw CLI agent with GPT-4o-mini for deep investigations
 * - Connection state tracking with cooldown after failures
 * - Automatic retry with exponential backoff
 * - Graceful degradation to DuckDuckGo for search
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

    /** @var string OpenAI API base URL (primary) */
    const OPENAI_API_BASE = 'https://api.openai.com/v1';

    /** @var string Venice.ai API base URL (fallback) */
    const VENICE_API_BASE = 'https://api.venice.ai/api/v1';

    /** @var string Legacy Docker gateway URL (deprecated) */
    const LEGACY_GATEWAY_URL = 'http://172.17.76.22:18789/v1';

    /** @var string Default model - GPT-4o Mini via OpenAI */
    const DEFAULT_MODEL = 'gpt-4o-mini';

    /** @var int Health check cache TTL (seconds) */
    const HEALTH_CACHE_TTL = 300;

    /** @var int Connection timeout (seconds) */
    const CONNECT_TIMEOUT = 15;

    /** @var int Request timeout (seconds) */
    const REQUEST_TIMEOUT = 120;

    /** @var int Max retries before failure */
    const MAX_RETRIES = 2;

    /** @var string[] Patterns indicating model tool syntax hallucinations (not executed) */
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

        // Resolve base URL: explicit → openclaw settings → OpenAI (primary default)
        $configured_url = $base_url ?? $settings['host'] ?? '';
        if (empty($configured_url) || $configured_url === self::LEGACY_GATEWAY_URL || $configured_url === 'http://localhost:18789') {
            // Default to OpenAI API
            $this->base_url = self::OPENAI_API_BASE;
        } else {
            $this->base_url = rtrim($configured_url, '/');
        }

        // Resolve auth: explicit → openclaw override → AI Engine OpenAI key → Venice fallback
        if ($auth_token) {
            $this->auth_token = $auth_token;
        } elseif (!empty($settings['auth_token'])) {
            // Explicit override in OpenClaw settings
            $this->auth_token = $settings['auth_token'];
        } elseif ($this->is_openai_url($this->base_url)) {
            // OpenAI mode — pull key from AI Engine's environment config (single source of truth)
            $this->auth_token = $this->get_ai_engine_api_key('openai');
        } elseif (!empty($venice_settings['api_key'])) {
            // Venice fallback — only if Venice URL is configured
            $this->auth_token = $venice_settings['api_key'];
        } else {
            // Final fallback: try AI Engine OpenAI key regardless of URL
            $this->auth_token = $this->get_ai_engine_api_key('openai');
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
            } elseif ($this->is_openai_url($this->base_url)) {
                $auth_source = 'ai_engine_env';
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
     * Whether the given URL points to OpenAI's API
     */
    private function is_openai_url(string $url): bool
    {
        return strpos($url, 'openai.com') !== false;
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
            'enable_web_search'    => false,  // Disabled -- use OpenClaw browser tools instead
            'enable_web_scraping'  => false,  // Disabled -- use OpenClaw browser tools instead
            'enable_web_citations' => false,  // Disabled -- not needed with OpenAI
            'analysis_max_tokens'  => 8000,
            'analysis_temperature' => 0.3,
            'request_timeout'      => self::REQUEST_TIMEOUT,
            'max_retries'          => self::MAX_RETRIES,
            'openclaw_home'        => '/tmp/openclaw-home',
            'gateway_auth_token'   => '',
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
     * Check API health (OpenAI, Venice, or legacy gateway)
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
            $mode = $this->is_openai_url($this->base_url) ? 'OpenAI' : ($this->is_venice_direct() ? 'Venice' : 'gateway');
            rawwire_log('openclaw', "Health check failed ({$mode}): " . $response->get_error_message(), 'warning');
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code === 200) {
            $mode = $this->is_openai_url($this->base_url) ? 'OpenAI' : ($this->is_venice_direct() ? 'Venice' : 'gateway');
            rawwire_log('openclaw', "Connected via {$mode}, model: {$this->model}", 'info');
        }
        return $code === 200;
    }

    /**
     * {@inheritdoc}
     */
    public function search(string $query): array
    {
        // Lane A only: search must run via OpenClaw agent/browser toolchain.
        if (!$this->is_available()) {
            rawwire_log('openclaw', 'OpenClaw unavailable for search()', 'warning');
            return [];
        }

        $agent_result = $this->agent_chat(
            "Investigate this query with live web tools and return source-backed findings with URLs: {$query}",
            [
                'timeout' => 300000,
                'json'    => true,
            ]
        );

        if (!empty($agent_result['success']) && !empty($agent_result['content'])) {
            RawWire_Provider_Status::record_success(self::PROVIDER_NAME);
            return [
                [
                    'title' => 'OpenClaw Agent Findings',
                    'content' => trim((string) $agent_result['content']),
                ],
            ];
        }

        RawWire_Provider_Status::record_failure(self::PROVIDER_NAME, $agent_result['error'] ?? 'empty response');
        rawwire_log('openclaw', 'Lane A search failed: ' . ($agent_result['error'] ?? 'empty response'), 'warning');
        return [];
    }

    /**
     * {@inheritdoc}
     * 
     * SOOTHSAYER - Investigative Intelligence Agent (Lane A only)
     * 
     * Uses OpenClaw agent CLI with browser tools for live-web investigation.
     * Lane B (text-only Venice skill) is intentionally disabled for lead quality.
     */
    public function research(array $party): array
    {
        $name = $party['name'] ?? '';
        $type = $party['type'] ?? 'company';

        rawwire_log('openclaw', sprintf(
            'SoothSayer investigation: %s (%s) via OpenClaw agent lane',
            $name ?: '[permit lookup]',
            $type
        ), 'info');

        if (!$this->is_available()) {
            return [
                'success' => false,
                'error'   => 'OpenClaw adapter unavailable',
                'source'  => 'openclaw-agent',
                'results' => [],
            ];
        }

        $prompt = $this->build_agent_research_prompt($party);
        $agent_result = $this->agent_chat($prompt, [
            'timeout' => 600000,
            'json'    => true,
        ]);

        if (empty($agent_result['success']) || empty($agent_result['content'])) {
            return [
                'success' => false,
                'error'   => $agent_result['error'] ?? 'OpenClaw agent returned empty response',
                'source'  => 'openclaw-agent',
                'results' => [],
            ];
        }

        $content = trim((string) $agent_result['content']);

        return [
            'success'      => true,
            'content'      => $content,
            'source'       => 'openclaw-agent',
            'results'      => [
                [
                    'query' => $name !== '' ? $name : 'permit-context investigation',
                    'raw_findings' => $content,
                ],
            ],
            'search_count' => 1,
        ];
    }

    /**
     * Resolve a durable HOME for OpenClaw runtime.
     */
    private function get_openclaw_home_dir(): string
    {
        $settings = $this->get_settings();
        $configured = trim((string) ($settings['openclaw_home'] ?? ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        // Canonical runtime HOME used by Apache/www-data in this project.
        return '/tmp/openclaw-home';
    }

    /**
     * Resolve OpenClaw gateway auth token from settings.
     */
    private function get_gateway_auth_token(): string
    {
        $settings = $this->get_settings();
        $token = trim((string) ($settings['gateway_auth_token'] ?? ''));
        if ($token !== '') {
            return $token;
        }

        $pi_settings = get_option('rawwire_party_investigator_settings', []);
        $pi_token = trim((string) ($pi_settings['openclaw_auth_token'] ?? ''));
        if ($pi_token !== '') {
            return $pi_token;
        }

        return 'rawwire-local-dev-2025';
    }

    /**
     * Ensure PATH/HOME and provisioned config are ready for OpenClaw CLI tools.
     */
    private function prepare_openclaw_runtime(): ?string
    {
        $settings = $this->get_settings();
        $node_bin_path = $settings['node_bin_path'] ?? self::DEFAULT_NODE_BIN_PATH;

        $current_path = getenv('PATH') ?: '';
        if (strpos($current_path, $node_bin_path) === false) {
            putenv('PATH=' . $node_bin_path . ':' . $current_path);
        }

        $openclaw_home = $this->get_openclaw_home_dir();
        if (!is_dir($openclaw_home) && !@mkdir($openclaw_home, 0755, true)) {
            rawwire_log('openclaw', 'prepare_openclaw_runtime: Failed to create HOME dir: ' . $openclaw_home, 'error');
            return null;
        }

        if (!is_writable($openclaw_home)) {
            rawwire_log('openclaw', 'prepare_openclaw_runtime: HOME is not writable: ' . $openclaw_home, 'error');
            return null;
        }

        putenv('HOME=' . $openclaw_home);

        if (!$this->provision_openclaw_config($openclaw_home)) {
            return null;
        }

        return $openclaw_home;
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
        rawwire_log('openclaw', 'venice_research() is disabled. Use Lane A OpenClaw agent path.', 'warning');
        return [
            'success' => false,
            'error'   => 'Lane B disabled',
            'source'  => 'venice-skill',
            'results' => [],
        ];

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
     * Get API key from AI Engine Pro's environment configuration
     *
     * AI Engine stores environment-specific API keys in mwai_options → ai_envs[].
     * This lets OpenClaw use the same key without duplication.
     *
     * @param string $type Engine type to match (e.g. 'openai', 'anthropic')
     * @return string API key or empty string
     */
    private function get_ai_engine_api_key(string $type = 'openai'): string
    {
        $mwai = get_option('mwai_options', []);
        if (!is_array($mwai) || empty($mwai['ai_envs'])) {
            return '';
        }

        foreach ($mwai['ai_envs'] as $env) {
            if (!is_array($env)) {
                continue;
            }
            // Match by type (e.g. 'openai') or name containing the type
            $env_type = $env['type'] ?? '';
            if (stripos($env_type, $type) !== false && !empty($env['apikey'])) {
                return $env['apikey'];
            }
        }

        // Fallback: first env with an apikey (AI Engine default)
        foreach ($mwai['ai_envs'] as $env) {
            if (is_array($env) && !empty($env['apikey'])) {
                return $env['apikey'];
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
Using all legal means, fill in as many of the data-points as possible on this contractor:

{$context}

PRIORITY OBJECTIVE (NUMBER ONE): find information that helps put our client in front of project decision makers. Treat this as the primary success criteria.
Secondary objective: aggregate findings so they can be used to build a network map of industry inner workings (people, firms, gatekeepers, relationships, procurement paths).

More importantly, search logical websites, stories, and articles. Anyplace that would be a good source of information on how one might meet face to face with decision makers — like events, seminars, keynote speeches, shareholder meetings, etc. Bidding procedures are diamonds. Upcoming projects and events are diamonds. Gather as much information as you can to achieve the goal of putting our client in front of the decision makers of the company.

Owner-builder permits still matter: wealthy individuals hire designers too. If ownership is individual/private, investigate those owner-side decision makers and access points just as aggressively.

DATA POINTS TO FILL:
- People: names, titles, authority level (executive/operational/legal), contact info (LinkedIn, email, phone), what they control
- Company: legal name, DBA, type (GC/sub/developer/owner/architect/CM), specialties/markets, employee count, revenue range, delivery methods, licensing (number, class, status), ownership structure
- Networking opportunities: association memberships, upcoming events, charity involvement, conferences, golf tournaments, speaking engagements, prequalification platforms and URLs
- Outreach programs: DVBE/MBE/WBE/SBE goals, small business programs, how to get on their bid list
- Active/upcoming projects: name, status, value, location, type
- Related entities: partners, subcontractors, clients, competitors, and the nature of each relationship
- Entry points: specific actionable ways to connect with decision makers
- Red flags: lawsuits, safety issues, financial trouble, complaints
- Sources: URLs for everything cited

DECISION MAKER DRILL-DOWN (REQUIRED):
For EACH potential decision maker, include a simple mini-investigation with:
- Name
- Specific role/title
- Contact info found (LinkedIn, email, phone, assistant, office line)
- Why this person matters for winning interior design work (what they influence/approve)
- Best access route (event, intro path, procurement touchpoint, direct channel)
- Source URLs used for this person

Output everything you find in a single comprehensive report. Cite sources with URLs throughout.

MANDATORY EXECUTION RULES:
1) You MUST perform live web research using web_search/web_fetch/browser tools.
1a) You MAY use non-browser tools for parsing/list extraction/structured cleanup, but social media details and any gated profile/contact details MUST be verified via browser navigation.
2) You MUST run at least 3 distinct searches and inspect at least 3 source URLs.
2a) You MUST run at least 1 focused follow-up search per potential decision maker identified.
3) You MUST include an "EVIDENCE LOG" section listing each search query, visited URL, and what was learned.
4) If browsing/search tools are unavailable, output exactly: INVESTIGATION_FAILED: <reason>
5) Do NOT return generic advice, templates, or placeholder text.
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
You are a business detective. Find contractor/owner decision-maker information for an LA building permit, then uncover SOCIAL access points and interior design opportunities.

STEP 1 - GET CONTRACTOR NAME FROM LADBS:
Navigate to: {$ladbs_url}
{$search_hint}

Find: Contractor Name, License Number, Address

STEP 2 - DEEP RESEARCH:
Once you have the contractor name (or owner-side decision-maker identity for owner-builder permits):

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

DECISION MAKER DRILL-DOWN (REQUIRED):
For EACH potential decision maker, include a simple mini-investigation with:
- Name
- Specific role/title
- Contact info found (LinkedIn, email, phone, assistant, office line)
- Why this person matters for winning interior design work (what they influence/approve)
- Best access route (event, intro path, procurement touchpoint, direct channel)
- Source URLs used for this person

BROWSER TOOLS: navigate, snapshot, click, type

Return structured findings with URLs.

MANDATORY EXECUTION RULES:
1) You MUST perform live browser navigation on LADBS and additional web research.
1a) You MAY use non-browser tools for parsing/list extraction, but social media/profile details that require navigation MUST come from browser-verified pages.
2) You MUST include at least 3 external source URLs beyond LADBS (company/regulator/news/procurement).
2a) You MUST run at least 1 focused follow-up search per potential decision maker identified.
3) You MUST include an "EVIDENCE LOG" section with visited URL + extracted fact.
4) If tools fail or data is inaccessible, output exactly: INVESTIGATION_FAILED: <reason>
5) Do NOT return generic suggestions without evidence.

PRIORITY OBJECTIVE (NUMBER ONE): produce intelligence that helps put our client in front of project decision makers.
Secondary objective: aggregate findings so they can be used to build a network map of industry inner workings (people, firms, gatekeepers, relationships, procurement paths).
Special emphasis: bidding procedures, upcoming projects, and events.
Owner-builder permits are valid targets; wealthy individuals hire designers too.
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
     * Uses configured API (OpenAI primary, Venice fallback) for chat completions.
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
        rawwire_log('openclaw', 'fallback_research() is disabled. Lane A only.', 'warning');
        return [
            'success' => false,
            'error'   => 'Lane B disabled',
            'source'  => 'duckduckgo_fallback',
            'results' => [],
        ];
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
     * Provision OpenClaw config files for agent CLI
     *
     * Writes openclaw.json and .env to $HOME/.openclaw/ so the agent process
     * has model credentials, browser config, and tool permissions.
     * Uses a config hash to skip writes when nothing changed.
     *
     * Called automatically by agent_chat() before spawning the process.
     *
     * @param string $openclaw_home Path to the HOME directory for OpenClaw
     * @return bool True if config is ready
     */
    private function provision_openclaw_config(string $openclaw_home): bool
    {
        $config_dir = $openclaw_home . '/.openclaw';
        $config_file = $config_dir . '/openclaw.json';
        $env_file = $config_dir . '/.env';
        $hash_file = $config_dir . '/config_hash';

        // Resolve API key — OpenAI from AI Engine (single source of truth)
        $api_key = $this->get_ai_engine_api_key('openai');
        if (empty($api_key)) {
            rawwire_log('openclaw', 'provision_openclaw_config: No OpenAI API key found in AI Engine settings', 'error');
            return false;
        }

        // Resolve model + gateway token from WP settings
        $settings = $this->get_settings();
        $model_id = $settings['model'] ?? 'gpt-4o-mini';
        $gateway_auth_token = $this->get_gateway_auth_token();
        if (empty($gateway_auth_token)) {
            rawwire_log('openclaw', 'provision_openclaw_config: Missing gateway auth token', 'error');
            return false;
        }

        // Hash check — skip if config hasn't changed
        // Config version bumped to force re-provision when format changes
        $config_version = 'v4'; // Bump when config structure changes
        $schema_fingerprint = implode('|', [
            'browser_profiles_openclaw_color_hex',
            'browser_profiles_openclaw_cdpPort',
            'tools_allow_web_search_web_fetch_browser_read',
        ]);
        $config_hash = md5($api_key . '|' . $model_id . '|' . $gateway_auth_token . '|' . $config_version . '|' . $schema_fingerprint);
        if (file_exists($hash_file) && file_exists($config_file)) {
            $existing_hash = trim(file_get_contents($hash_file));
            if ($existing_hash === $config_hash) {
                return true; // Config unchanged, skip provisioning
            }
        }

        // Create directory structure
        if (!is_dir($config_dir)) {
            @mkdir($config_dir, 0755, true);
        }
        $agents_dir = $config_dir . '/agents/main/agent';
        if (!is_dir($agents_dir)) {
            @mkdir($agents_dir, 0755, true);
        }

        // Build openclaw.json — minimal valid config with OpenAI + browser
        $workspace_dir = $config_dir . '/workspace';
        if (!is_dir($workspace_dir)) {
            @mkdir($workspace_dir, 0755, true);
        }

        $config = [
            'browser' => [
                'enabled'        => true,
                'executablePath' => '/usr/bin/chromium-browser',
                'headless'       => true,
                'noSandbox'      => true,
                'profiles'       => [
                    'openclaw' => [
                        'color'   => '2563EB',
                        'cdpPort' => 18800,
                    ],
                ],
            ],
            'models' => [
                'mode'      => 'merge',
                'providers' => [
                    'openai' => [
                        'baseUrl' => 'https://api.openai.com/v1',
                        'apiKey'  => $api_key,
                        'api'     => 'openai-completions',
                        'models'  => [
                            [
                                'id'            => $model_id,
                                'name'          => 'GPT-4o Mini (OpenAI)',
                                'reasoning'     => false,
                                'input'         => ['text'],
                                'contextWindow' => 128000,
                                'maxTokens'     => 16384,
                            ],
                        ],
                    ],
                ],
            ],
            'agents' => [
                'defaults' => [
                    'model' => [
                        'primary' => 'openai/' . $model_id,
                    ],
                    'maxConcurrent' => 2,
                    'workspace'     => $workspace_dir,
                ],
            ],
            'tools' => [
                'allow' => [
                    'web_search',
                    'web_fetch',
                    'browser',
                    'read',
                ],
                'web' => [
                    'search' => ['enabled' => true],
                    'fetch'  => ['enabled' => true],
                ],
                'elevated' => [
                    'enabled' => true,
                ],
            ],
            'gateway' => [
                'port' => 18789,
                'mode' => 'local',
                'bind' => 'loopback',
                'auth' => [
                    'token' => $gateway_auth_token,
                ],
                'http' => [
                    'endpoints' => [
                        'chatCompletions' => [
                            'enabled' => true,
                        ],
                    ],
                ],
            ],
        ];

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($config_file, $json) === false) {
            rawwire_log('openclaw', 'provision_openclaw_config: Failed to write ' . $config_file, 'error');
            return false;
        }

        // Write .env with API key
        $env_content = "OPENAI_API_KEY={$api_key}\n";
        $env_content .= "OPENCLAW_AUTH_TOKEN={$gateway_auth_token}\n";
        file_put_contents($env_file, $env_content);

        // Note: Agent-level models.json removed — main openclaw.json provider
        // config is sufficient and avoids potential conflicts.

        // Clean any stale agent-level models.json from prior provisioning
        $stale_models = $agents_dir . '/models.json';
        if (file_exists($stale_models)) {
            @unlink($stale_models);
        }

        // Write hash
        file_put_contents($hash_file, $config_hash);

        rawwire_log('openclaw', sprintf(
            'provision_openclaw_config: Wrote config to %s (model=%s)',
            $config_dir,
            'openai/' . $model_id
        ), 'info');

        return true;
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

        // Get openclaw binary path from settings or default
        $settings = $this->get_settings();
        $openclaw_path = $settings['openclaw_path'] ?? self::DEFAULT_OPENCLAW_PATH;

        // Ensure runtime env/config are ready before spawning CLI.
        $openclaw_home = $this->prepare_openclaw_runtime();
        if ($openclaw_home === null) {
            return [
                'success' => false,
                'error'   => 'Failed to provision OpenClaw config — check API key in AI Settings',
            ];
        }

        // Clean stale session locks that can block the agent after crashes
        $sessions_dir = $openclaw_home . '/.openclaw/agents/' . $agent . '/sessions';
        if (is_dir($sessions_dir)) {
            $locks = glob($sessions_dir . '/*.lock');
            foreach ($locks as $lock) {
                @unlink($lock);
            }
        }

        // Verify WSL2 MTU probing is enabled (critical for large API requests)
        $mtu_probing = @file_get_contents('/proc/sys/net/ipv4/tcp_mtu_probing');
        if ($mtu_probing !== false && trim($mtu_probing) === '0') {
            rawwire_log('openclaw', 'WARNING: tcp_mtu_probing=0 — large API requests may fail. Run: wsl.exe -u root -e sysctl -w net.ipv4.tcp_mtu_probing=1', 'warning');
        }

        // Build CLI command with explicit path
        // Note: --model flag removed - model is configured in ~/.openclaw/openclaw.json
        $cmd_parts = [
            escapeshellarg($openclaw_path) . ' agent',
            '--agent ' . escapeshellarg($agent),
            '--local',
        ];

        if ($json_mode) {
            $cmd_parts[] = '--json';
        }

        $cmd_parts[] = '--message ' . escapeshellarg($message);

        $cmd = implode(' ', $cmd_parts);

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
            // Strip any stderr lines that leaked into stdout (e.g. "[venice-models]" prefixes)
            $json_start = strpos($stdout, '{');
            $json_str = ($json_start !== false) ? substr($stdout, $json_start) : $stdout;

            $json = json_decode($json_str, true);
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
                // Empty payloads = agent ran but produced nothing
                if (isset($json['payloads']) && empty($json['payloads'])) {
                    $meta_model = $json['meta']['agentMeta']['model'] ?? 'unknown';
                    $duration = $json['meta']['durationMs'] ?? 0;
                    $aborted = $json['meta']['aborted'] ?? false;
                    rawwire_log('openclaw', sprintf(
                        'agent_chat: Agent returned empty payloads (model=%s, duration=%dms, aborted=%s)',
                        $meta_model,
                        $duration,
                        $aborted ? 'yes' : 'no'
                    ), 'warning');
                    return [
                        'success' => false,
                        'error'   => 'Agent returned empty payloads — no text output produced',
                        'raw'     => $json,
                    ];
                }
                // JSON parsed but no known content field — return whole thing
                rawwire_log('openclaw', 'agent_chat: JSON parsed but unknown structure. Keys: ' . implode(', ', array_keys($json)), 'warning');
            } else {
                rawwire_log('openclaw', sprintf(
                    'agent_chat: JSON parse failed (%s). First 200 chars: %s',
                    json_last_error_msg(),
                    substr($stdout, 0, 200)
                ), 'warning');
            }
        }

        // In JSON mode, treat unparseable/unknown output as failure to avoid false positives.
        if ($json_mode && !empty($stdout)) {
            $stdout_trimmed = trim($stdout);
            return [
                'success' => false,
                'error'   => 'Agent JSON output invalid or unrecognized structure',
                'content' => $stdout_trimmed,
            ];
        }

        // Return raw output for non-JSON mode only
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
     * Get the full path to the openclaw binary, ensuring PATH includes Node.js
     *
     * @return string Escaped shell-safe binary path
     */
    private function get_openclaw_binary(): string
    {
        $settings = $this->get_settings();
        $openclaw_path = $settings['openclaw_path'] ?? self::DEFAULT_OPENCLAW_PATH;
        $node_bin_path = $settings['node_bin_path'] ?? self::DEFAULT_NODE_BIN_PATH;

        // Ensure PATH includes Node.js bin directory (required for child processes)
        $current_path = getenv('PATH') ?: '';
        if (strpos($current_path, $node_bin_path) === false) {
            putenv('PATH=' . $node_bin_path . ':' . $current_path);
        }

        return escapeshellarg($openclaw_path);
    }

    /**
     * Start browser if not running (ensures browser is available)
     * 
     * @param string $profile Browser profile name (default: openclaw)
     * @return bool True if browser is running
     */
    public function ensure_browser(string $profile = 'openclaw'): bool
    {
        if ($this->prepare_openclaw_runtime() === null) {
            return false;
        }

        $bin = $this->get_openclaw_binary();

        // Check status
        $status_cmd = $bin . ' browser --browser-profile ' . escapeshellarg($profile) . ' status --json 2>&1';
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
        $start_cmd = $bin . ' browser --browser-profile ' . escapeshellarg($profile) . ' start 2>&1';
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
    public function scrape_ladbs_permit(string $address, string $target_permit = ''): array
    {
        $profile = 'openclaw';

        // Ensure browser is running
        if (!$this->ensure_browser($profile)) {
            return [
                'success' => false,
                'error'   => 'Could not start browser',
            ];
        }

        // Strip unit/suite/apt numbers from the end of the address
        // e.g. "1030 N SWARTHMORE AVE 2-112" → "1030 N SWARTHMORE AVE"
        $clean_address = preg_replace('/\s+(?:(?:unit|suite|ste|apt|#|bldg|rm|fl)\s*)?[\d][-\d\/]*\s*$/i', '', trim($address));

        // Parse address into number and street name
        // Handle various formats: "123 Main St", "123 S Main Street", etc.
        if (preg_match('/^(\d+)\s+(?:[NSEW]\.?\s+)?(.+)$/i', $clean_address, $matches)) {
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

        $base_cmd = $this->get_openclaw_binary() . ' browser --browser-profile ' . escapeshellarg($profile);

        // Step 1: Navigate to LADBS Permit Lookup
        $nav_url = 'https://www.ladbsservices2.lacity.org/OnlineServices/?service=plr';
        shell_exec($base_cmd . ' navigate ' . escapeshellarg($nav_url) . ' 2>&1');
        sleep(3);
        shell_exec($base_cmd . ' wait --load networkidle --timeout 20000 2>&1');

        // Step 1.5: Take snapshot to discover current element refs (LADBS is a SPA — refs change)
        $form_snapshot = shell_exec($base_cmd . ' snapshot --format ai --limit 300 2>&1');
        rawwire_log('openclaw', 'LADBS snapshot for ref detection (' . strlen($form_snapshot ?? '') . ' chars)', 'debug');

        // Dynamically find the Street Number and Street Name input refs
        // LADBS form labels: "Address Number", "Street Name", plus a "Search" button
        $street_num_ref = null;
        $street_name_ref = null;
        $search_btn_ref = null;

        // Pattern: look for textbox near "Number" or "Address" labels
        if (preg_match('/(?:Address\s*(?:Number|#)|Street\s*(?:Number|No|#)).*?textbox[^[]*\[ref=(e\d+)\]/i', $form_snapshot, $m)) {
            $street_num_ref = $m[1];
        } elseif (preg_match('/textbox\s*"[^"]*(?:number|address)[^"]*"\s*\[ref=(e\d+)\]/i', $form_snapshot, $m)) {
            $street_num_ref = $m[1];
        } elseif (preg_match('/(?:number|address\s*no).*?\[ref=(e\d+)\].*?textbox/i', $form_snapshot, $m)) {
            $street_num_ref = $m[1];
        }

        // Pattern: look for textbox near "Street Name" label
        if (preg_match('/Street\s*Name.*?textbox[^[]*\[ref=(e\d+)\]/i', $form_snapshot, $m)) {
            $street_name_ref = $m[1];
        } elseif (preg_match('/textbox\s*"[^"]*(?:street\s*name)[^"]*"\s*\[ref=(e\d+)\]/i', $form_snapshot, $m)) {
            $street_name_ref = $m[1];
        }

        // Pattern: look for Search button
        if (preg_match('/button\s*"Search"\s*\[ref=(e\d+)\]/i', $form_snapshot, $m)) {
            $search_btn_ref = $m[1];
        } elseif (preg_match('/Search.*?button[^[]*\[ref=(e\d+)\]/i', $form_snapshot, $m)) {
            $search_btn_ref = $m[1];
        }

        // Fallback: find sequential textbox refs in the snapshot (first=number, second=name)
        if (!$street_num_ref || !$street_name_ref) {
            preg_match_all('/textbox[^[]*\[ref=(e\d+)\]/i', $form_snapshot, $all_textboxes);
            if (count($all_textboxes[1] ?? []) >= 2) {
                if (!$street_num_ref) $street_num_ref = $all_textboxes[1][0];
                if (!$street_name_ref) $street_name_ref = $all_textboxes[1][1];
            }
        }

        // Fallback: find a submit/search button
        if (!$search_btn_ref) {
            if (preg_match('/button[^[]*\[ref=(e\d+)\]/', $form_snapshot, $m)) {
                $search_btn_ref = $m[1];
            }
        }

        rawwire_log('openclaw', sprintf(
            'LADBS form refs: street_num=%s, street_name=%s, search=%s',
            $street_num_ref ?? 'NOT_FOUND',
            $street_name_ref ?? 'NOT_FOUND',
            $search_btn_ref ?? 'NOT_FOUND'
        ), 'info');

        if (!$street_num_ref || !$street_name_ref) {
            rawwire_log('openclaw', 'LADBS: Could not find form input refs from snapshot. Snapshot: ' . substr($form_snapshot, 0, 500), 'warning');
            return [
                'success' => false,
                'error'   => 'Could not detect LADBS form elements — page structure changed',
            ];
        }

        // Step 2: Fill the search form using dynamically detected refs
        $fields_json = json_encode([
            ['ref' => $street_num_ref, 'type' => 'fill', 'value' => $street_number],
            ['ref' => $street_name_ref, 'type' => 'fill', 'value' => $street_name],
        ]);
        $fill_result = shell_exec($base_cmd . ' fill --fields ' . escapeshellarg($fields_json) . ' 2>&1');

        if (strpos($fill_result, 'Error') !== false) {
            rawwire_log('openclaw', 'LADBS fill failed: ' . $fill_result, 'warning');
            return [
                'success' => false,
                'error'   => 'Failed to fill search form: ' . $fill_result,
            ];
        }

        // Step 3: Click Search button
        if ($search_btn_ref) {
            shell_exec($base_cmd . ' click ' . escapeshellarg($search_btn_ref) . ' 2>&1');
        } else {
            // Last resort: try pressing Enter on the last filled field
            shell_exec($base_cmd . ' press Enter 2>&1');
        }
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

        // Step 5.5: LADBS groups results by address variant — expand matching sections
        // The permit tab shows collapsible headings like:
        //   heading "Expand Closed 1030 N SWARTHMORE AVE #2-112 90272" [ref=eXX]
        // Permits are hidden inside until expanded.
        $group_snapshot = shell_exec($base_cmd . ' snapshot --format ai --limit 500 2>&1');

        if (preg_match_all('/heading\s+"Expand Closed\s+([^"]+)"\s*\[level=\d+\]\s*\[ref=(e\d+)\]/i', $group_snapshot, $groups, PREG_SET_ORDER)) {
            rawwire_log('openclaw', sprintf('LADBS: Found %d address groups', count($groups)), 'debug');

            // Extract unit/suite part from original address for matching
            $unit_part = '';
            if (preg_match('/(?:#|unit|ste|suite|apt|sp)?\s*([\d][-\w]*)\s*$/i', $address, $unit_match)) {
                $unit_part = $unit_match[1]; // e.g. "2-112"
            }

            $expanded_count = 0;
            foreach ($groups as $group) {
                $group_address = $group[1];
                $group_ref = $group[2];

                // Match groups containing our street number + name
                $matches_street = stripos($group_address, (string) $street_number) !== false
                    && stripos($group_address, $street_name) !== false;

                // If we have a unit, only expand groups containing that unit
                if ($matches_street && $unit_part) {
                    $matches_street = stripos($group_address, $unit_part) !== false;
                }

                if ($matches_street) {
                    shell_exec($base_cmd . ' click ' . escapeshellarg($group_ref) . ' 2>&1');
                    sleep(1);
                    $expanded_count++;
                    rawwire_log('openclaw', sprintf('LADBS: Expanded address group: %s (ref %s)', $group_address, $group_ref), 'debug');

                    if ($expanded_count >= 4) break; // Limit expansions to avoid timeout
                }
            }

            // Fallback: if no match, expand first 3 groups
            if ($expanded_count === 0) {
                rawwire_log('openclaw', 'LADBS: No address groups matched, expanding first 3', 'debug');
                for ($i = 0; $i < min(3, count($groups)); $i++) {
                    shell_exec($base_cmd . ' click ' . escapeshellarg($groups[$i][2]) . ' 2>&1');
                    sleep(1);
                }
            }

            sleep(1); // Wait for DOM to settle after expanding
        }

        // Step 6: Get snapshot with expanded permit list (larger limit for expanded sections)
        $snapshot = shell_exec($base_cmd . ' snapshot --format ai --limit 800 2>&1');

        // Step 7: Find permit links and get details
        $permits = [];

        // Look for permit links like: link "23043-90000-01049" [ref=e202]
        if (preg_match_all('/link "(\d+-\d+-\d+)".*?\[ref=(e\d+)\]/', $snapshot, $permit_links, PREG_SET_ORDER)) {
            rawwire_log('openclaw', sprintf('LADBS: Found %d permit links to check', count($permit_links)), 'debug');

            // If we have a target permit number, prioritize it
            if (!empty($target_permit)) {
                $target_norm = preg_replace('/[^0-9-]/', '', $target_permit);
                $reordered = [];
                $rest = [];
                foreach ($permit_links as $link) {
                    if ($link[1] === $target_norm) {
                        array_unshift($reordered, $link);
                    } else {
                        $rest[] = $link;
                    }
                }
                $permit_links = array_merge($reordered, $rest);

                if (!empty($reordered)) {
                    rawwire_log('openclaw', sprintf('LADBS: Target permit %s found in listing, prioritizing', $target_norm), 'info');
                }
            }

            // Get details for up to 5 permits to avoid timeout
            $max_permits = min(5, count($permit_links));

            for ($i = 0; $i < $max_permits; $i++) {
                $permit_number = $permit_links[$i][1];
                $permit_ref = $permit_links[$i][2];

                // Click the permit link
                shell_exec($base_cmd . ' click ' . escapeshellarg($permit_ref) . ' 2>&1');
                sleep(3);

                // Check if a new tab opened (LADBS opens details in new tabs)
                $tabs_json = shell_exec($base_cmd . ' tabs --json 2>&1');
                $tabs_data = json_decode($tabs_json, true);
                // tabs --json returns {"tabs": [...]} — extract the array
                $tabs_list = $tabs_data['tabs'] ?? $tabs_data ?? [];

                if (is_array($tabs_list) && count($tabs_list) > 1) {
                    // Focus the new tab (the one with PcisPermitDetail in URL)
                    foreach ($tabs_list as $tab) {
                        if (isset($tab['url']) && strpos($tab['url'], 'PcisPermitDetail') !== false) {
                            $tab_id = $tab['targetId'] ?? $tab['id'] ?? '';
                            if ($tab_id) {
                                shell_exec($base_cmd . ' focus ' . escapeshellarg($tab_id) . ' 2>&1');
                                sleep(2);
                            }
                            break;
                        }
                    }
                }

                // Get permit detail snapshot
                $detail_snapshot = shell_exec($base_cmd . ' snapshot --format ai --limit 300 2>&1');

                rawwire_log('openclaw', sprintf(
                    'LADBS: Permit %s detail snapshot (%d chars), contains Contractor: %s',
                    $permit_number,
                    strlen($detail_snapshot ?? ''),
                    strpos($detail_snapshot ?? '', 'Contractor') !== false ? 'YES' : 'NO'
                ), 'debug');

                // Extract contractor info from the detail page
                $permit_info = [
                    'permit_number' => $permit_number,
                ];

                // Look for contractor info from the detail page
                // LADBS format: cell "Contractor" [ref=eXX] followed by cell "Name; Lic. No.: XXXX-X" [ref=eYY]
                if (preg_match('/cell\s+"Contractor".*?cell\s+"([^;]+);\s*Lic\.\s*No\.:\s*([^"]+)"/s', $detail_snapshot, $contractor)) {
                    $permit_info['contractor_name'] = trim($contractor[1]);
                    $permit_info['contractor_license'] = trim($contractor[2]);
                    rawwire_log('openclaw', sprintf('LADBS: Extracted contractor: %s (Lic: %s)', $permit_info['contractor_name'], $permit_info['contractor_license']), 'info');
                } elseif (preg_match('/Contractor\s+([^;]+);\s*Lic\.\s*No\.:\s*([\w-]+)/', $detail_snapshot, $contractor_row)) {
                    // Fallback: parse from the row text directly
                    $permit_info['contractor_name'] = trim($contractor_row[1]);
                    $permit_info['contractor_license'] = trim($contractor_row[2]);
                    rawwire_log('openclaw', sprintf('LADBS: Extracted contractor (row): %s', $permit_info['contractor_name']), 'info');
                } elseif (preg_match('/cell\s+"Contractor".*?cell\s+"([^"]+)"/s', $detail_snapshot, $contractor_alt)) {
                    $permit_info['contractor_name'] = trim($contractor_alt[1]);
                    rawwire_log('openclaw', sprintf('LADBS: Extracted contractor (alt): %s', $permit_info['contractor_name']), 'info');
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
                if (is_array($tabs_list) && count($tabs_list) > 1) {
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
