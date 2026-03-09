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
 * - OpenAI API (primary)
 * - OpenClaw CLI agent with GPT-4o-mini for deep investigations
 * - Connection state tracking with cooldown after failures
 * - Automatic retry with exponential backoff
 * - Health check with status caching
 * 
 * @package RawWire_Dashboard
 * @since   1.0.32
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-search-provider.php';

class RawWire_OpenClaw_Adapter implements RawWire_Search_Provider_Interface
{
    /** @var string Provider identifier */
    const PROVIDER_NAME = 'openclaw';

    /** @var string OpenAI API base URL (primary) */
    const OPENAI_API_BASE = 'https://api.openai.com/v1';

    /** @var string Legacy Docker gateway URL (deprecated) */
    const LEGACY_GATEWAY_URL = 'http://172.17.76.22:18789/v1';

    /** @var string Perplexity API base URL */
    const PERPLEXITY_API_BASE = 'https://api.perplexity.ai';

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

    /** @var int Max bytes of stdout/stderr to persist in trace */
    const TRACE_SNIPPET_LIMIT = 12000;

    /** @var string Base URL */
    private string $base_url;

    /** @var string Auth token / API key */
    private string $auth_token;

    /** @var string Model to use */
    private string $model;

    /**
     * Constructor
     * 
     * Resolves settings from: explicit params → rawwire_openclaw_settings → defaults.
     */
    public function __construct(?string $base_url = null, ?string $auth_token = null, ?string $model = null)
    {
        $settings = $this->get_settings();
        $pi_settings = get_option('rawwire_party_investigator_settings', []);
        $perplexity_settings = wp_parse_args(get_option('rawwire_perplexity_settings', []), [
            'api_key' => '',
            'base_url' => self::PERPLEXITY_API_BASE,
            'model' => 'sonar',
            'temperature' => 0.2,
            'max_tokens' => 8000,
            'max_passes' => 2,
            'strip_thinking_response' => true,
        ]);
        $openai_settings = wp_parse_args(get_option('rawwire_openai_settings', []), [
            'base_url' => self::OPENAI_API_BASE,
            'openclaw_api_key' => '',
            'model' => self::DEFAULT_MODEL,
            'temperature' => 0.3,
            'max_tokens' => 4000,
            'reasoning_effort' => 'off',
        ]);
        $pipeline_mode = (string) ($pi_settings['pipeline_mode'] ?? 'veniceclaw');
        $provider = (string) ($settings['provider'] ?? 'venice');
        $force_perplexity_direct = $pipeline_mode === 'perplexity_direct' && ($base_url === null || $base_url === '') && ($auth_token === null || $auth_token === '') && ($model === null || $model === '');

        // Resolve base URL: explicit → provider settings → legacy host/defaults.
        if ($base_url !== null && $base_url !== '') {
            $configured_url = $base_url;
        } elseif ($force_perplexity_direct) {
            $configured_url = $perplexity_settings['base_url'] ?? self::PERPLEXITY_API_BASE;
        } elseif ($provider === 'openai') {
            $configured_url = $openai_settings['base_url'] ?? $settings['openai_base_url'] ?? self::OPENAI_API_BASE;
        } elseif ($provider === 'ollama') {
            $configured_url = $settings['ollama_base_url'] ?? 'http://127.0.0.1:11434/v1';
        } else {
            $configured_url = $settings['host'] ?? '';
        }

        if (empty($configured_url) || $configured_url === self::LEGACY_GATEWAY_URL || $configured_url === 'http://localhost:18789') {
            $this->base_url = $force_perplexity_direct
                ? self::PERPLEXITY_API_BASE
                : ($provider === 'venice' ? 'https://api.venice.ai/api/v1' : self::OPENAI_API_BASE);
        } else {
            $this->base_url = rtrim($configured_url, '/');
        }

        // Resolve auth: explicit → provider-specific setting → provider-aware fallback.
        if ($auth_token) {
            $this->auth_token = $auth_token;
        } elseif ($force_perplexity_direct) {
            $this->auth_token = (string) ($perplexity_settings['api_key'] ?? '');
            if ($this->auth_token === '') {
                $this->auth_token = $this->get_env_value(['PERPLEXITY_API_KEY', 'PPLX_API_KEY']);
            }
        } elseif ($provider === 'openai') {
            $this->auth_token = $this->resolve_openai_compatible_api_key($this->base_url, $settings);
        } elseif (!empty($settings['auth_token'])) {
            $this->auth_token = $settings['auth_token'];
        } elseif ($this->is_openai_url($this->base_url)) {
            $this->auth_token = $this->get_ai_engine_api_key('openai');
        } else {
            $this->auth_token = '';
        }

        $provider_default_model = $settings['model'] ?? self::DEFAULT_MODEL;
        if ($force_perplexity_direct || $this->is_perplexity_url($this->base_url)) {
            $provider_default_model = $perplexity_settings['model'] ?? $settings['perplexity_model'] ?? $settings['model'] ?? 'sonar';
        } elseif ($provider === 'openai') {
            $provider_default_model = $openai_settings['model'] ?? $settings['openai_model'] ?? self::DEFAULT_MODEL;
        } elseif ($this->is_perplexity_url($this->base_url)) {
            $provider_default_model = $settings['perplexity_model'] ?? $settings['model'] ?? 'sonar';
        } elseif ($provider === 'ollama') {
            $provider_default_model = $settings['ollama_model'] ?? 'qwen2.5:14b';
        }

        $resolved_model = $model;
        if ($resolved_model === null || $resolved_model === '') {
            $resolved_model = $provider_default_model;
        }
        if ($this->is_perplexity_url($this->base_url)) {
            $perplexity_default_model = $settings['perplexity_model'] ?? $settings['model'] ?? 'sonar';
            if ($resolved_model === '' || preg_match('/^(gpt|o\d|claude|gemini)-/i', $resolved_model)) {
                $resolved_model = $perplexity_default_model;
            }
        }

        $this->model = $resolved_model;

        // Debug logging for troubleshooting
        if (function_exists('rawwire_log')) {
            $auth_source = 'unknown';
            if ($auth_token) {
                $auth_source = 'explicit';
            } elseif ($provider === 'openai') {
                $auth_source = 'openai_compatible';
            } elseif (!empty($settings['auth_token'])) {
                $auth_source = 'openclaw_settings';
            } elseif ($this->is_openai_url($this->base_url)) {
                $auth_source = 'ai_engine_env';
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
     * Whether the given URL points to Perplexity's API.
     */
    private function is_perplexity_url(string $url): bool
    {
        return strpos($url, 'perplexity.ai') !== false;
    }

    /**
     * Whether the configured runtime should use direct Perplexity research
     * instead of the OpenClaw browser-agent lane.
     */
    public function uses_direct_perplexity_research(): bool
    {
        return $this->is_perplexity_url($this->base_url);
    }

    public function get_base_url(): string
    {
        return $this->base_url;
    }

    public function get_model(): string
    {
        return $this->model;
    }

    public function get_runtime_provider_name(): string
    {
        if ($this->is_perplexity_url($this->base_url)) {
            return 'Perplexity';
        }

        if (strpos($this->base_url, 'venice.ai') !== false) {
            return 'Venice';
        }

        if ($this->is_openai_url($this->base_url)) {
            return 'OpenAI';
        }

        if (strpos($this->base_url, '11434') !== false) {
            return 'Ollama';
        }

        return 'OpenAI-Compatible';
    }

    /**
     * Resolve an env var without logging its value.
     */
    private function get_env_value(array $keys): string
    {
        foreach ($keys as $key) {
            $value = getenv($key);
            if (is_string($value) && $value !== '') {
                return $value;
            }

            if (isset($_ENV[$key]) && is_string($_ENV[$key]) && $_ENV[$key] !== '') {
                return $_ENV[$key];
            }

            if (isset($_SERVER[$key]) && is_string($_SERVER[$key]) && $_SERVER[$key] !== '') {
                return $_SERVER[$key];
            }
        }

        return '';
    }

    /**
     * Resolve auth for OpenAI-compatible endpoints like OpenAI or Perplexity.
     */
    private function resolve_openai_compatible_api_key(string $base_url, array $settings): string
    {
        $openai_settings = get_option('rawwire_openai_settings', []);
        $perplexity_settings = get_option('rawwire_perplexity_settings', []);

        if ($this->is_perplexity_url($base_url) && !empty($perplexity_settings['api_key'])) {
            return (string) $perplexity_settings['api_key'];
        }

        if (!empty($openai_settings['openclaw_api_key'])) {
            return (string) $openai_settings['openclaw_api_key'];
        }

        if (!empty($settings['openai_api_key'])) {
            return (string) $settings['openai_api_key'];
        }

        if ($this->is_perplexity_url($base_url)) {
            return $this->get_env_value(['PERPLEXITY_API_KEY', 'PPLX_API_KEY']);
        }

        return $this->get_ai_engine_api_key('openai');
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
            'provider'             => 'venice',
            'host'                 => '',
            'auth_token'           => '',
            'model'                => self::DEFAULT_MODEL,
            'perplexity_model'     => 'sonar',
            'openai_base_url'      => self::OPENAI_API_BASE,
            'openai_api_key'       => '',
            'openai_model'         => self::DEFAULT_MODEL,
            'ollama_base_url'      => 'http://127.0.0.1:11434/v1',
            'ollama_model'         => 'qwen2.5:14b',
            'max_tokens'           => 4000,
            'temperature'          => 0.3,
            'enable_web_search'    => true,   // Allow OpenClaw web search for discovery; verify with browser tools
            'enable_web_scraping'  => false,  // Disabled -- use OpenClaw browser tools instead
            'enable_web_citations' => false,  // Disabled -- not needed with OpenAI
            'analysis_max_tokens'  => 8000,
            'analysis_temperature' => 0.3,
            'request_timeout'      => self::REQUEST_TIMEOUT,
            'max_retries'          => self::MAX_RETRIES,
            'agent_deep_retry'     => true,
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
     * Check API health (OpenAI or legacy gateway)
     */
    private function check_health(): bool
    {
        if ($this->is_perplexity_url($this->base_url)) {
            rawwire_log('openclaw', 'Perplexity configured: skipping /models health probe and relying on direct request auth', 'debug');
            return !empty($this->auth_token);
        }

        $response = wp_remote_get($this->base_url . '/models', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->auth_token,
                'Accept' => 'application/json',
            ],
            'timeout' => self::CONNECT_TIMEOUT,
        ]);

        if (is_wp_error($response)) {
            $mode = $this->is_openai_url($this->base_url) ? 'OpenAI' : 'gateway';
            rawwire_log('openclaw', "Health check failed ({$mode}): " . $response->get_error_message(), 'warning');
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code === 200) {
            $mode = $this->is_openai_url($this->base_url) ? 'OpenAI' : 'gateway';
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
     * SOOTHSAYER - Investigative Intelligence Agent
     * 
     * Uses OpenClaw agent CLI with browser tools for live-web investigation.
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
        if (!is_dir($openclaw_home) && !@mkdir($openclaw_home, 0777, true)) {
            rawwire_log('openclaw', 'prepare_openclaw_runtime: Failed to create HOME dir: ' . $openclaw_home, 'error');
            return null;
        }
        @chmod($openclaw_home, 0777);

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
    Do a deep dive investigation on {$name} with focus on the Los Angeles area. This report is to reveal as many details as possible about the subject for the purpose of building a network map and generate leads for an interior design client. Report all data points focusing on decision makers, bidding processes, events participation, subcontractors, qualification, and basically anything that gives the client insight or opportunity to work with or at least meet the key principles in the target company. Upcoming projects are diamonds, actionable intel is key.

{$context}

PRIORITY OBJECTIVE (NUMBER ONE): find information that helps put our client in front of project decision makers. Treat this as the primary success criteria.
Secondary objective: aggregate findings so they can be used to build a network map of industry inner workings (people, firms, gatekeepers, relationships, procurement paths).

ABSTRACT "DIAMONDS ON THE EDGES" OBJECTIVE:
Look past the obvious company homepage facts. Hunt for edge signals that reveal access, influence, or future work, including:
- event sponsors, attendees, speakers, panelists, committee members
- charity boards, alumni groups, civic boards, trade groups, chambers
- landlords, developers, property managers, architects, expediters, permitting consultants, owner's reps
- vendor registration pages, bid portals, approved vendor lists, prequal forms
- hiring posts, award announcements, lease signings, office expansions, move-ins, tenant improvement clues
- recurring collaborators such as subcontractors, suppliers, wholesalers, reps, and distributors
- gatekeepers such as executive assistants, office managers, coordinators, precon staff, estimating admins, and procurement contacts

More importantly, search logical websites, stories, and articles. Anyplace that would be a good source of information on how one might meet face to face with decision makers — like events, seminars, keynote speeches, shareholder meetings, etc. Bidding procedures are diamonds. Upcoming projects and events are diamonds. Gather as much information as you can to achieve the goal of putting our client in front of the decision makers of the company.

Owner-builder permits still matter: wealthy individuals hire designers too. If ownership is individual/private, investigate those owner-side decision makers and access points just as aggressively.

MODEL THE REPORT AFTER A REAL STRATEGIC DOSSIER, NOT A GENERIC COMPANY PROFILE:
- Prefer target-specific sources such as the company website, leadership/team pages, LinkedIn company/person pages, BuildZoom/Blue Book/business-registry records, procurement/prequalification portals, subcontract forms, project pages, award announcements, and event/sponsor rosters.
- For each meaningful claim, cite the concrete source URL in the same bullet or immediately after it.
- Do NOT invent names, roles, projects, or affiliations. If a fact is not supported by a source, leave it out.
- Use follow-up branching research: after you identify a person, search that person by name plus company; after you identify an association/event, inspect the roster, sponsor page, speaker page, or attendee page.
- The best reports explain company structure, key decision makers, outreach programs, event participation, bidding mechanics, and practical networking moves for a subcontractor or interior design firm trying to get in front of them.

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

PLACE DATA WHERE IT BELONGS:
Organize findings so the downstream parser can place them correctly.
- PEOPLE: every real person named, with title, authority, and contact route
- COMPANY: one company fact section with identity, licensing, specialties, scale, and notable facts
- NETWORKING OPPORTUNITIES: associations, upcoming events, charity/community involvement
- DISCOVERED PROJECTS: project name, status, value, location, why it matters
- DISCOVERED ENTITIES: suppliers, vendors, wholesalers, architects, owners, developers, subcontractors, agencies, competitors, and relationship label
- ENTRY POINTS: concise actionable bullets
- SOURCES: deduplicated URLs

DECISION MAKER DRILL-DOWN (REQUIRED):
For EACH potential decision maker, include a simple mini-investigation with:
- Name
- Specific role/title
- Contact info found (LinkedIn, email, phone, assistant, office line)
- Why this person matters for winning interior design work (what they influence/approve)
- Best access route (event, intro path, procurement touchpoint, direct channel)
- Potential meeting opportunities: specific events, committees, speaking appearances, trade groups, charity boards, webinars, golf tournaments, alumni/civic ties, open houses, networking mixers, procurement conferences, or gatekeeper-mediated routes that could plausibly create face time
- Source URLs used for this person

GATEKEEPER DRILL-DOWN (REQUIRED WHEN FOUND):
For EACH gatekeeper or coordinator found, include:
- Name
- Role/title
- Which executive/project/team they appear to control access to
- Contact route
- Why they matter operationally
- Source URLs used

Output everything you find in a single comprehensive report. Cite sources with URLs throughout.

REQUIRED TODO CHECKLIST (include this section in your final output):
- [ ] TODO 1: Confirm target identity (name, license, permit/address match)
- [ ] TODO 2: Visit LADBS/CSLB/registry sources relevant to target
- [ ] TODO 3: Identify at least 2 potential decision makers
- [ ] TODO 4: Capture at least 3 concrete source URLs
- [ ] TODO 5: Produce EVIDENCE LOG entries tied to findings
- [ ] TODO 6: Summarize best entry points to reach decision makers
- [ ] TODO 7: Identify at least 2 edge-signal diamonds (events, gatekeepers, vendor portals, recurring collaborators, or upcoming work)
- Mark each item [x] only when completed with evidence.

MANDATORY EXECUTION RULES:
1) You MUST perform live web research using both OpenClaw web_search and browser tools. Use web_search for discovery, then browser navigation and web_fetch for verification.
1a) You MAY use non-browser tools for parsing/list extraction/structured cleanup, but social media details and any gated profile/contact details MUST be verified via browser navigation.
2) You MUST run at least 3 distinct searches and inspect at least 3 source URLs.
2a) You MUST run at least 1 focused follow-up search per potential decision maker identified.
3) You MUST include an "EVIDENCE LOG" section listing each search query, visited URL, and what was learned.
3a) You MUST include a "SEARCH LEDGER" section listing the exact query strings you used in order.
4) If a site blocks access, continue with other public sources via web_search plus direct browser navigation and document the block.
5) Only output exactly "INVESTIGATION_FAILED: <reason>" when browser navigation itself is unavailable.
6) Do NOT return generic advice, templates, or placeholder text.

QUALITY GATE — YOUR OUTPUT WILL BE REJECTED IF:
- More than 2 sections contain phrases like "not specified", "not available", "requires further research", or "check directly"
- You cite fewer than 3 distinct external URLs (LADBS homepage alone does not count)
- You list zero real person names as decision makers
- You fail to cite at least 1 target-specific source about the actual company/person being investigated
- Your EVIDENCE LOG does not contain concrete URLs tied to extracted facts
- Any section is padded with generic advice instead of sourced facts
If you cannot find real information, say so in one sentence — do NOT pad with boilerplate.

SPECIFIC RESEARCH STEPS (execute in order):
1. Navigate to https://www.cslb.ca.gov/onlineservices/checklicenseII/checklicense.aspx and look up the contractor license number. Extract: business name, license status, classifications, bonding, workers comp.
2. Search Google for the contractor name + "Los Angeles" + "construction" and visit the top 3 results. Extract: company website, key personnel, recent projects.
3. If a company website is found, navigate to their About/Team/Leadership page. Extract: names, titles, contact info.
4. Search for the contractor name on LinkedIn (via Google: site:linkedin.com/in "contractor name"). Note any profiles found.
5. Search for the contractor name + "bid" OR "RFP" OR "prequalification". Extract any procurement info.
6. Search for the contractor name + event terms like "conference", "expo", "panel", "golf", "charity", "association", "NAIOP", "AGC", "BOMA", "CoreNet", "IIDA", "AIA".
7. Search for the contractor name + collaborator terms like "supplier", "vendor", "subcontractor", "distributor", "wholesaler", "architect", "developer", "owner rep".
8. Search for the contractor/company name + terms like "leadership", "team", "president", "estimating", "preconstruction", "business development", "project executive", and open the most relevant pages.
9. Search for the contractor/company name + terms like "event", "speaker", "sponsor", "association", "charity", "golf", "panel", "conference", and inspect the event/association pages.
10. Search for the contractor/company name + terms like "subcontract", "prequalification", "vendor", "bid package", "procurement", or "PQBids" and inspect the actual process pages or documents.

FINAL REPORT FORMAT (MANDATORY):
1. TARGET SUMMARY
2. PEOPLE
3. COMPANY
4. NETWORKING OPPORTUNITIES
5. DISCOVERED PROJECTS
6. DISCOVERED ENTITIES
7. ENTRY POINTS
8. RED FLAGS
9. SOURCES
10. SEARCH LEDGER
11. EVIDENCE LOG
12. REQUIRED TODO CHECKLIST
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
    Do a deep dive investigation on the permit-linked target with focus on the Los Angeles area. This report is to reveal as many details as possible about the subject for the purpose of building a network map and generate leads for an interior design client. Report all data points focusing on decision makers, bidding processes, events participation, subcontractors, qualification, and basically anything that gives the client insight or opportunity to work with or at least meet the key principles in the target company. Upcoming projects are diamonds, actionable intel is key.

STEP 1 - GET CONTRACTOR NAME FROM LADBS:
Navigate to: {$ladbs_url}
{$search_hint}

Find: Contractor Name, License Number, Address

STEP 2 - DEEP RESEARCH (execute these specific steps):
Once you have the contractor name (or owner-side decision-maker identity for owner-builder permits):

2a. Navigate to https://www.cslb.ca.gov/onlineservices/checklicenseII/checklicense.aspx and look up the license. Extract: business name, status, classifications, bonding, workers comp, address.
2b. Search Google for "[contractor name]" + "Los Angeles" and visit the top 3 results.
2c. If company website found, navigate to About/Team/Leadership page.
2d. Search Google for site:linkedin.com/in "[contractor name]" and note profiles found.
2e. Search Google for "[contractor name]" + ("bid" OR "RFP" OR "prequalification" OR "vendor registration").

FOR EACH SEARCH, RECORD:
- The exact query used
- Each URL visited
- What specific facts were extracted

DATA POINTS TO FILL (with sourced facts, not guesses):
1. BIDDING & PROCUREMENT — subcontractor selection, RFP process, prequalification requirements, vendor portal URL
2. UPCOMING PROJECTS — projects in planning/design phase, recent permits, announced wins
3. INTERIOR DESIGN INDICATORS — TI work, buildouts, FF&E, healthcare/hospitality/education projects
4. SOCIAL ACCESS — events they sponsor, association roles, charity involvement, LinkedIn profiles
5. DECISION MAKERS — who handles design/interiors, contact info, email patterns

DECISION MAKER DRILL-DOWN (REQUIRED):
For EACH potential decision maker, include:
- Name
- Specific role/title
- Contact info found (LinkedIn, email, phone, assistant, office line)
- Why this person matters for winning interior design work
- Best access route
- Source URLs used for this person

Return structured findings with URLs.

REQUIRED TODO CHECKLIST (include this section in your final output):
- [ ] TODO 1: Retrieve contractor/owner identity from LADBS context
- [ ] TODO 2: Validate contractor/license details via CSLB or equivalent source
- [ ] TODO 3: Identify at least 2 decision makers or explicitly state not found with proof
- [ ] TODO 4: Capture at least 3 external source URLs beyond LADBS
- [ ] TODO 5: Provide EVIDENCE LOG with URL + extracted fact
- [ ] TODO 6: Provide actionable outreach entry points
- Mark each item [x] only when completed with evidence.

MANDATORY EXECUTION RULES:
1) You MUST perform live browser navigation on LADBS and additional web research using both web_search and browser tools.
1a) You MAY use non-browser tools for parsing/list extraction, but social media/profile details that require navigation MUST come from browser-verified pages.
2) You MUST include at least 3 external source URLs beyond LADBS (company/regulator/news/procurement).
2a) You MUST run at least 1 focused follow-up search per potential decision maker identified.
3) You MUST include an "EVIDENCE LOG" section with visited URL + extracted fact.
4) If a site blocks access, continue using web_search plus browser navigation on LADBS/CSLB/registry/company pages and document the block.
5) Only output exactly "INVESTIGATION_FAILED: <reason>" when browser navigation itself is unavailable.
6) Do NOT return generic suggestions without evidence.

QUALITY GATE — YOUR OUTPUT WILL BE REJECTED IF:
- More than 2 sections say "not specified" / "not available" / "requires further research"
- Fewer than 3 distinct external URLs cited
- Zero real person names identified as decision makers
- Sections padded with generic advice instead of sourced facts

PRIORITY OBJECTIVE (NUMBER ONE): produce intelligence that helps put our client in front of project decision makers.
Secondary objective: aggregate findings so they can be used to build a network map of industry inner workings (people, firms, gatekeepers, relationships, procurement paths).
Special emphasis: bidding procedures, upcoming projects, and events.
Owner-builder permits are valid targets; wealthy individuals hire designers too.
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
     * Uses configured API (OpenAI) for chat completions.
     * Web search and scraping are DISABLED — use OpenClaw browser tools instead.
     */
    public function chat_with_metadata(array $messages, array $options = []): array
    {
        $settings = $this->get_settings();
        $requested_model = trim((string) ($options['model'] ?? ''));
        $request_model = $this->normalize_perplexity_request_model($requested_model !== '' ? $requested_model : $this->model);

        $timeout = (int) ($options['timeout'] ?? $settings['request_timeout'] ?? self::REQUEST_TIMEOUT);

        $use_responses_api = $this->is_perplexity_url($this->base_url) && (
            !empty($options['prefer_responses_api'])
            || !empty($options['preset'])
            || array_key_exists('max_steps', $options)
            || array_key_exists('instructions', $options)
            || array_key_exists('input', $options)
            || strpos($request_model, 'xai/') === 0
        );

        if ($use_responses_api) {
            return $this->responses_with_metadata($messages, $options, $timeout, $request_model, $requested_model !== '');
        }

        $body = [
            'model' => $request_model,
            'messages' => $messages,
            'max_tokens' => $options['max_tokens'] ?? (int) ($settings['max_tokens'] ?? 4000),
            'temperature' => $options['temperature'] ?? (float) ($settings['temperature'] ?? 0.3),
        ];

        $body = array_merge($body, $this->build_provider_request_options($request_model, $options));

        $json_body = wp_json_encode($body);

        // Debug payload size for request diagnostics.
        rawwire_log('openclaw', sprintf(
            'POST payload: %d bytes (model=%s, msgs=%d, max_tokens=%d)',
            strlen($json_body),
            $request_model,
            count($messages),
            $body['max_tokens']
        ), 'debug');

        $response = wp_remote_post($this->base_url . '/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->auth_token,
                'Content-Type' => 'application/json',
                'Expect'       => '',
            ],
            'body'        => $json_body,
            'timeout'     => $timeout,
            'httpversion' => '1.1',
        ]);

        if (is_wp_error($response)) {
            RawWire_Provider_Status::record_failure(self::PROVIDER_NAME, $response->get_error_message());
            rawwire_log('openclaw', 'Request failed: ' . $response->get_error_message(), 'error');
            return [
                'success' => false,
                'content' => '',
                'citations' => [],
                'search_results' => [],
                'error' => $response->get_error_message(),
                'status_code' => 0,
                'raw' => null,
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            RawWire_Provider_Status::record_failure(self::PROVIDER_NAME, "HTTP {$code}");
            rawwire_log('openclaw', "HTTP error: {$code}", 'error');
            return [
                'success' => false,
                'content' => '',
                'citations' => [],
                'search_results' => [],
                'error' => "HTTP {$code}",
                'status_code' => $code,
                'raw' => null,
            ];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $content = $data['choices'][0]['message']['content'] ?? '';
        $citations = array_values(array_filter(array_map('strval', (array) ($data['citations'] ?? []))));
        $search_results = array_values(array_filter((array) ($data['search_results'] ?? []), static function ($item): bool {
            return is_array($item);
        }));

        // Record success to reset any failure cooldown
        if (!empty($content)) {
            RawWire_Provider_Status::record_success(self::PROVIDER_NAME);
        }

        return [
            'success' => !empty($content),
            'content' => (string) $content,
            'citations' => $citations,
            'search_results' => $search_results,
            'error' => '',
            'status_code' => $code,
            'raw' => $data,
        ];
    }

    private function normalize_perplexity_request_model(string $request_model): string
    {
        if (!$this->is_perplexity_url($this->base_url)) {
            return $request_model;
        }

        if (strpos($request_model, 'perplexity/') === 0) {
            $normalized = substr($request_model, strlen('perplexity/'));
            if (is_string($normalized) && $normalized !== '') {
                return $normalized;
            }
        }

        return $request_model;
    }

    /**
     * Add provider-specific request options that are supported by the current endpoint.
     */
    private function build_provider_request_options(string $request_model, array $options): array
    {
        if ($this->is_perplexity_url($this->base_url)) {
            $settings = wp_parse_args(get_option('rawwire_perplexity_settings', []), [
                'top_p' => 0.9,
                'reasoning_effort' => 'off',
                'search_mode' => 'web',
                'return_images' => false,
                'return_related_questions' => false,
                'enable_search_classifier' => true,
                'disable_search' => false,
            ]);

            $payload = [
                'top_p' => max(0, min(1, (float) ($options['top_p'] ?? $settings['top_p'] ?? 0.9))),
            ];

            $reasoning_effort = (string) ($options['reasoning_effort'] ?? $settings['reasoning_effort'] ?? 'off');
            if ($reasoning_effort !== 'off') {
                $payload['reasoning_effort'] = $reasoning_effort;
            }

            $payload['web_search_options'] = [
                'search_mode' => (string) ($options['search_mode'] ?? $settings['search_mode'] ?? 'web'),
                'return_images' => array_key_exists('return_images', $options) ? !empty($options['return_images']) : !empty($settings['return_images']),
                'return_related_questions' => array_key_exists('return_related_questions', $options) ? !empty($options['return_related_questions']) : !empty($settings['return_related_questions']),
                'enable_search_classifier' => array_key_exists('enable_search_classifier', $options) ? !empty($options['enable_search_classifier']) : !empty($settings['enable_search_classifier']),
                'disable_search' => array_key_exists('disable_search', $options) ? !empty($options['disable_search']) : !empty($settings['disable_search']),
            ];

            return $payload;
        }

        $settings = wp_parse_args(get_option('rawwire_openai_settings', []), [
            'top_p' => 1.0,
            'reasoning_effort' => 'off',
            'tool_choice' => 'auto',
            'parallel_tool_calls' => true,
            'allow_tool_calls' => true,
            'allow_mcp_tools' => true,
            'allow_openclaw_tools' => true,
        ]);

        $payload = [
            'top_p' => max(0, min(1, (float) ($options['top_p'] ?? $settings['top_p'] ?? 1.0))),
        ];

        $reasoning_effort = (string) ($options['reasoning_effort'] ?? $settings['reasoning_effort'] ?? 'off');
        if ($reasoning_effort !== 'off') {
            $payload['reasoning_effort'] = $reasoning_effort;
        }

        $tool_calls_enabled = !array_key_exists('allow_tool_calls', $options)
            ? !empty($settings['allow_tool_calls'])
            : !empty($options['allow_tool_calls']);

        $tool_payload = $this->filter_tool_payload((array) ($options['tools'] ?? []), $settings);
        if ($tool_calls_enabled && !empty($tool_payload)) {
            $payload['tools'] = $tool_payload;
            $payload['tool_choice'] = $options['tool_choice'] ?? ($settings['tool_choice'] ?? 'auto');
            $payload['parallel_tool_calls'] = array_key_exists('parallel_tool_calls', $options)
                ? !empty($options['parallel_tool_calls'])
                : !empty($settings['parallel_tool_calls']);
        }

        return $payload;
    }

    /**
     * Filter tool definitions according to provider availability toggles.
     */
    private function filter_tool_payload(array $tools, array $settings): array
    {
        $filtered = [];

        foreach ($tools as $tool) {
            if (!is_array($tool)) {
                continue;
            }

            $kind = $this->classify_tool_payload($tool);
            if ($kind === 'mcp' && empty($settings['allow_mcp_tools'])) {
                continue;
            }

            if ($kind === 'openclaw' && empty($settings['allow_openclaw_tools'])) {
                continue;
            }

            $filtered[] = $tool;
        }

        return $filtered;
    }

    /**
     * Best-effort tool classification for provider gating.
     */
    private function classify_tool_payload(array $tool): string
    {
        $type = strtolower((string) ($tool['type'] ?? ''));
        $name = strtolower((string) ($tool['function']['name'] ?? $tool['name'] ?? ''));
        $haystack = trim($type . ' ' . $name);

        if ($haystack === '') {
            return 'generic';
        }

        if (strpos($haystack, 'mcp') !== false) {
            return 'mcp';
        }

        if (
            strpos($haystack, 'openclaw') !== false
            || strpos($haystack, 'browser') !== false
            || strpos($haystack, 'web_search') !== false
            || strpos($haystack, 'web_fetch') !== false
        ) {
            return 'openclaw';
        }

        return 'generic';
    }

    private function responses_with_metadata(array $messages, array $options, int $timeout, string $request_model, bool $has_explicit_model): array
    {
        $body = $this->build_responses_payload($messages, $options, $request_model, $has_explicit_model);

        $json_body = wp_json_encode($body);
        rawwire_log('openclaw', sprintf(
            'POST Perplexity Responses API payload: %d bytes (model=%s, preset=%s)',
            strlen($json_body),
            (string) ($body['model'] ?? 'preset-default'),
            (string) ($body['preset'] ?? 'none')
        ), 'debug');

        $endpoint = preg_match('#/v1/?$#', $this->base_url)
            ? rtrim($this->base_url, '/') . '/responses'
            : rtrim($this->base_url, '/') . '/v1/responses';

        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->auth_token,
                'Content-Type' => 'application/json',
                'Expect'       => '',
            ],
            'body'        => $json_body,
            'timeout'     => $timeout,
            'httpversion' => '1.1',
        ]);

        if (is_wp_error($response)) {
            RawWire_Provider_Status::record_failure(self::PROVIDER_NAME, $response->get_error_message());
            rawwire_log('openclaw', 'Responses request failed: ' . $response->get_error_message(), 'error');
            return [
                'success' => false,
                'content' => '',
                'citations' => [],
                'search_results' => [],
                'error' => $response->get_error_message(),
                'status_code' => 0,
                'raw' => null,
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $error_body = trim((string) wp_remote_retrieve_body($response));
            RawWire_Provider_Status::record_failure(self::PROVIDER_NAME, "HTTP {$code}");
            rawwire_log('openclaw', sprintf(
                'Responses HTTP error: %d body=%s',
                $code,
                $error_body !== '' ? $error_body : '[empty]'
            ), 'error');
            return [
                'success' => false,
                'content' => '',
                'citations' => [],
                'search_results' => [],
                'error' => $error_body !== '' ? "HTTP {$code}: {$error_body}" : "HTTP {$code}",
                'status_code' => $code,
                'raw' => null,
            ];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $content = $this->extract_responses_content($data);
        $citations = $this->extract_responses_citations($data);
        $search_results = $this->extract_responses_search_results($data);

        if (!empty($content)) {
            RawWire_Provider_Status::record_success(self::PROVIDER_NAME);
        }

        return [
            'success' => !empty($content),
            'content' => $content,
            'citations' => $citations,
            'search_results' => $search_results,
            'error' => '',
            'status_code' => $code,
            'model' => (string) ($data['model'] ?? ($body['model'] ?? '')),
            'raw' => $data,
        ];
    }

    private function build_responses_payload(array $messages, array $options, string $request_model, bool $has_explicit_model): array
    {
        $settings = wp_parse_args(get_option('rawwire_perplexity_settings', []), [
            'max_tokens' => 8000,
            'reasoning_effort' => 'off',
        ]);

        $body = [
            'input' => (string) ($options['input'] ?? $this->build_responses_input($messages)),
            'max_output_tokens' => min(128000, max(1, (int) ($options['max_tokens'] ?? $settings['max_tokens'] ?? 8000))),
        ];

        $instructions = trim((string) ($options['instructions'] ?? $this->build_responses_instructions($messages)));
        if ($instructions !== '') {
            $body['instructions'] = $instructions;
        }

        $preset = trim((string) ($options['preset'] ?? ''));
        if ($preset !== '') {
            $body['preset'] = $preset;
        }

        $should_send_model = $preset === '';
        if ($preset !== '' && $request_model !== '') {
            $should_send_model = strpos($request_model, '/') !== false;
        }

        if (($has_explicit_model || $preset === '') && $should_send_model) {
            $body['model'] = $request_model;
        }

        if (array_key_exists('max_steps', $options) && $options['max_steps'] !== '' && $options['max_steps'] !== null) {
            $body['max_steps'] = min(10, max(1, (int) $options['max_steps']));
        }

        $reasoning_effort = $this->normalize_responses_reasoning_effort((string) ($options['reasoning_effort'] ?? $settings['reasoning_effort'] ?? 'off'));
        if ($reasoning_effort !== null) {
            $body['reasoning'] = [
                'effort' => $reasoning_effort,
            ];
        }

        $tools = [];
        if (!empty($options['tools']) && is_array($options['tools'])) {
            $tools = $options['tools'];
        } elseif ($preset === '') {
            $tools = $this->build_default_responses_tools($options);
        }

        if (!empty($tools)) {
            $body['tools'] = $tools;
        }

        return $body;
    }

    private function build_responses_instructions(array $messages): string
    {
        $parts = [];

        foreach ($messages as $message) {
            $role = strtolower((string) ($message['role'] ?? ''));
            if ($role !== 'system' && $role !== 'developer') {
                continue;
            }

            $content = trim((string) ($message['content'] ?? ''));
            if ($content !== '') {
                $parts[] = $content;
            }
        }

        return implode("\n\n", $parts);
    }

    private function build_default_responses_tools(array $options): array
    {
        if (!empty($options['disable_search'])) {
            return [];
        }

        return [
            [
                'type' => 'web_search',
                'max_tokens' => min(10000, max(1000, (int) ($options['max_tokens'] ?? 8000))),
                'max_tokens_per_page' => 4000,
            ],
            [
                'type' => 'fetch_url',
                'max_urls' => 10,
            ],
        ];
    }

    private function normalize_responses_reasoning_effort(string $effort): ?string
    {
        $effort = strtolower(trim($effort));
        if ($effort === '' || $effort === 'off') {
            return null;
        }

        if ($effort === 'minimal') {
            return 'low';
        }

        return in_array($effort, ['low', 'medium', 'high'], true) ? $effort : null;
    }

    private function build_responses_input(array $messages): string
    {
        $parts = [];
        foreach ($messages as $message) {
            $role_name = strtolower((string) ($message['role'] ?? 'user'));
            if ($role_name === 'system' || $role_name === 'developer') {
                continue;
            }

            $role = strtoupper($role_name === 'assistant' ? 'ASSISTANT' : 'USER');
            $content = trim((string) ($message['content'] ?? ''));
            if ($content !== '') {
                $parts[] = $role . ":\n" . $content;
            }
        }

        return implode("\n\n", $parts);
    }

    private function extract_responses_content($data): string
    {
        if (!is_array($data)) {
            return '';
        }

        if (!empty($data['output_text']) && is_string($data['output_text'])) {
            return $data['output_text'];
        }

        foreach ((array) ($data['output'] ?? []) as $item) {
            foreach ((array) ($item['content'] ?? []) as $content_item) {
                $text = trim((string) ($content_item['text'] ?? ''));
                if ($text !== '') {
                    return $text;
                }
            }
        }

        return '';
    }

    private function extract_responses_citations($data): array
    {
        $urls = [];

        foreach ((array) ($data['output'] ?? []) as $item) {
            foreach ((array) ($item['content'] ?? []) as $content_item) {
                foreach ((array) ($content_item['annotations'] ?? []) as $annotation) {
                    $url = trim((string) ($annotation['url'] ?? ''));
                    if ($url !== '') {
                        $urls[] = $url;
                    }
                }
            }
        }

        return array_values(array_unique($urls));
    }

    private function extract_responses_search_results($data): array
    {
        $results = [];

        foreach ((array) ($data['output'] ?? []) as $item) {
            if (($item['type'] ?? '') !== 'search_results') {
                continue;
            }

            foreach ((array) ($item['results'] ?? []) as $result) {
                if (!is_array($result) || empty($result['url'])) {
                    continue;
                }

                $results[] = [
                    'title' => (string) ($result['title'] ?? ''),
                    'url' => (string) ($result['url'] ?? ''),
                    'date' => (string) ($result['date'] ?? ''),
                    'snippet' => (string) ($result['snippet'] ?? ''),
                ];
            }
        }

        return $results;
    }

    /**
     * Raw chat completion request returning content only.
     */
    public function chat(array $messages, array $options = []): string
    {
        $result = $this->chat_with_metadata($messages, $options);
        return (string) ($result['content'] ?? '');
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
        $pidfile = $config_dir . '/gateway.pid';

        $settings = $this->get_settings();
        $provider = (string) ($settings['provider'] ?? 'venice');
        $provider_base_url = $this->base_url;
        $provider_name = 'OpenAI-Compatible';
        $model_id = $this->model;

        if ($provider === 'openai') {
            $provider_base_url = rtrim((string) ($settings['openai_base_url'] ?? $provider_base_url), '/');
            $api_key = $this->resolve_openai_compatible_api_key($provider_base_url, $settings);
            $model_id = (string) ($settings['openai_model'] ?? ($model_id ?: self::DEFAULT_MODEL));
            if ($this->is_perplexity_url($provider_base_url)) {
                $provider_name = 'Perplexity';
            }
        } elseif ($provider === 'ollama') {
            $provider_base_url = rtrim((string) ($settings['ollama_base_url'] ?? 'http://127.0.0.1:11434/v1'), '/');
            $api_key = '';
            $provider_name = 'Ollama';
            $model_id = (string) ($settings['ollama_model'] ?? ($model_id ?: 'qwen2.5:14b'));
        } else {
            $provider_base_url = rtrim((string) ($settings['host'] ?? $provider_base_url), '/');
            $api_key = (string) ($settings['auth_token'] ?? '');
            $provider_name = 'Venice';
            $model_id = (string) ($settings['model'] ?? $model_id);
        }

        if ($provider !== 'ollama' && empty($api_key)) {
            if ($provider === 'openai' && $this->is_perplexity_url($provider_base_url)) {
                rawwire_log('openclaw', 'provision_openclaw_config: No Perplexity API key found in env or OpenClaw settings', 'error');
            } else {
                rawwire_log('openclaw', 'provision_openclaw_config: No API key found for configured provider', 'error');
            }
            return false;
        }

        $gateway_auth_token = $this->get_gateway_auth_token();
        if (empty($gateway_auth_token)) {
            rawwire_log('openclaw', 'provision_openclaw_config: Missing gateway auth token', 'error');
            return false;
        }

        // Hash check — skip if config hasn't changed
        // Config version bumped to force re-provision when format changes
        $config_version = 'v9'; // Bump when config structure changes
        $schema_fingerprint = implode('|', [
            'browser_profiles_openclaw_color_hex',
            'browser_profiles_openclaw_cdpPort',
            'tools_allow_web_search_web_fetch_browser_read',
            'gateway_port_18889',
            'browser_defaultProfile_openclaw',
        ]);
        $config_hash = md5($api_key . '|' . $model_id . '|' . $gateway_auth_token . '|' . $config_version . '|' . $schema_fingerprint);
        if (file_exists($hash_file) && file_exists($config_file)) {
            $existing_hash = trim(file_get_contents($hash_file));
            $existing_config = json_decode((string) file_get_contents($config_file), true);
            $config_is_complete = is_array($existing_config)
                && !empty($existing_config['models']['providers']['openai']['models'][0]['id'])
                && !empty($existing_config['agents']['defaults']['model']['primary'])
                && !empty($existing_config['browser']['defaultProfile'])
                && (int) ($existing_config['gateway']['port'] ?? 0) === 18889
                && in_array('web_search', (array) ($existing_config['tools']['allow'] ?? []), true)
                && in_array('web_fetch', (array) ($existing_config['tools']['allow'] ?? []), true)
                && in_array('browser', (array) ($existing_config['tools']['allow'] ?? []), true)
                && !empty($existing_config['tools']['web']['search']['enabled'])
                && !empty($existing_config['tools']['web']['fetch']['enabled']);

            if ($existing_hash === $config_hash && $config_is_complete) {
                return true; // Config unchanged, skip provisioning
            }
        }

        // Create directory structure — use 0777 so both www-data and shell users can write
        if (!is_dir($config_dir)) {
            @mkdir($config_dir, 0777, true);
        }
        @chmod($config_dir, 0777);

        if (!is_writable($config_dir)) {
            rawwire_log('openclaw', 'provision_openclaw_config: Config dir not writable: ' . $config_dir, 'error');
            return false;
        }

        $agents_dir = $config_dir . '/agents/main/agent';
        if (!is_dir($agents_dir)) {
            @mkdir($agents_dir, 0777, true);
        }
        @chmod($agents_dir, 0777);

        // Build openclaw.json — minimal valid config with OpenAI + browser
        $workspace_dir = $config_dir . '/workspace';
        if (!is_dir($workspace_dir)) {
            @mkdir($workspace_dir, 0777, true);
        }

        $config = [
            'browser' => [
                'enabled'        => true,
                'executablePath' => '/usr/bin/chromium-browser',
                'headless'       => true,
                'noSandbox'      => true,
                'defaultProfile' => 'openclaw',
                'profiles'       => [
                    'openclaw' => [
                        'color'   => '2563EB',
                        'cdpPort' => 18900,
                    ],
                ],
            ],
            'models' => [
                'mode'      => 'merge',
                'providers' => [
                    'openai' => [
                        'baseUrl' => $provider_base_url,
                        'apiKey'  => $api_key,
                        'api'     => 'openai-completions',
                        'models'  => [
                            [
                                'id'            => $model_id,
                                'name'          => $provider_name . ' - ' . $model_id,
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
                'port' => 18889,
                'mode' => 'local',
                'bind' => 'loopback',
                'auth' => [
                    'token' => $gateway_auth_token,
                ],
                'remote' => [
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
        $existing_json = file_exists($config_file) ? (string) file_get_contents($config_file) : '';
        $config_changed = $existing_json !== $json;
        if (file_put_contents($config_file, $json) === false) {
            rawwire_log('openclaw', 'provision_openclaw_config: Failed to write ' . $config_file, 'error');
            return false;
        }

        // Write .env with API key
        $env_content = "OPENAI_API_KEY={$api_key}\n";
        $env_content .= "OPENCLAW_AUTH_TOKEN={$gateway_auth_token}\n";
        $existing_env_content = file_exists($env_file) ? (string) file_get_contents($env_file) : '';
        $env_changed = $existing_env_content !== $env_content;
        file_put_contents($env_file, $env_content);

        // Note: Agent-level models.json removed — main openclaw.json provider
        // config is sufficient and avoids potential conflicts.

        // Write auth-profiles.json to establish agent-level auth for OpenAI.
        // Without this, OpenClaw's failover may select built-in providers (e.g. anthropic)
        // that have no API key, causing instant failure.
        $auth_profiles_file = $agents_dir . '/auth-profiles.json';
        $auth_profiles = [
            'openai' => [
                'apiKey'  => $api_key,
                'baseUrl' => $provider_base_url,
            ],
        ];
        $auth_profiles_json = json_encode($auth_profiles, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $existing_auth_profiles_json = file_exists($auth_profiles_file) ? (string) file_get_contents($auth_profiles_file) : '';
        $auth_profiles_changed = $existing_auth_profiles_json !== $auth_profiles_json;
        file_put_contents($auth_profiles_file, $auth_profiles_json);

        // Clean any stale agent-level models.json from prior provisioning
        $stale_models = $agents_dir . '/models.json';
        if (file_exists($stale_models)) {
            @unlink($stale_models);
        }

        // Write hash
        file_put_contents($hash_file, $config_hash);

        if ($config_changed || $env_changed || $auth_profiles_changed) {
            @unlink($pidfile);
            shell_exec("pkill -f 'openclaw gateway --port 18889' 2>/dev/null");
            rawwire_log('openclaw', 'provision_openclaw_config: Restarting gateway to pick up runtime config changes', 'info');
        }

        rawwire_log('openclaw', sprintf(
            'provision_openclaw_config: Wrote config to %s (model=%s)',
            $config_dir,
            'openai/' . $model_id
        ), 'info');

        return true;
    }

    /**
     * Ensure the OpenClaw gateway is running for this config.
     *
     * The embedded --local gateway has reliability issues with browser tools,
     * so we run a persistent gateway process that the agent connects to.
     * Gateway is started on port 18889 with browser profile auto-started.
     *
     * @param string $openclaw_home HOME directory for the OpenClaw config
     * @return bool True if gateway is confirmed running
     */
    private function ensure_gateway_running(string $openclaw_home): bool
    {
        $gateway_port = 18889;
        $pidfile = $openclaw_home . '/.openclaw/gateway.pid';

        // Quick check: is anything already listening on our port?
        $sock = @fsockopen('127.0.0.1', $gateway_port, $errno, $errstr, 1);
        if ($sock) {
            fclose($sock);
            return true;
        }

        // Gateway not running — start it
        $settings = $this->get_settings();
        $openclaw_path = $settings['openclaw_path'] ?? self::DEFAULT_OPENCLAW_PATH;
        $node_bin_path = $settings['node_bin_path'] ?? self::DEFAULT_NODE_BIN_PATH;
        $base_path = getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
        if (strpos($base_path, $node_bin_path) === false) {
            $base_path = $node_bin_path . ':' . $base_path;
        }

        $log_file = '/tmp/openclaw/gateway-' . date('Y-m-d') . '.log';
        @mkdir(dirname($log_file), 0777, true);

        $gateway_api_key = $this->auth_token;
        $perplexity_api_key = $this->get_env_value(['PERPLEXITY_API_KEY', 'PPLX_API_KEY']);
        $gateway_auth_token = $this->get_gateway_auth_token();

        $cmd = sprintf(
            'HOME=%s PATH=%s OPENAI_API_KEY=%s PERPLEXITY_API_KEY=%s OPENCLAW_AUTH_TOKEN=%s setsid -f %s gateway --port %d >> %s 2>&1 < /dev/null',
            escapeshellarg($openclaw_home),
            escapeshellarg($base_path),
            escapeshellarg($gateway_api_key),
            escapeshellarg($perplexity_api_key),
            escapeshellarg($gateway_auth_token),
            escapeshellarg($openclaw_path),
            $gateway_port,
            escapeshellarg($log_file)
        );

        shell_exec($cmd);

        // Wait for gateway to become ready (up to 8s)
        $deadline = microtime(true) + 8.0;
        while (microtime(true) < $deadline) {
            usleep(500000); // 500ms
            $sock = @fsockopen('127.0.0.1', $gateway_port, $errno, $errstr, 1);
            if ($sock) {
                fclose($sock);
                $pid = trim((string) shell_exec("pgrep -f 'openclaw gateway --port 18889' | tail -n 1"));
                if ($pid !== '' && ctype_digit($pid)) {
                    @file_put_contents($pidfile, $pid);
                }
                rawwire_log('openclaw', sprintf('ensure_gateway_running: Gateway started (pid=%s, port=%d)', $pid, $gateway_port), 'info');

                // Start browser profile (fire and forget)
                $browser_cmd = sprintf(
                    'HOME=%s PATH=%s OPENAI_API_KEY=%s PERPLEXITY_API_KEY=%s OPENCLAW_AUTH_TOKEN=%s %s browser start --browser-profile openclaw > /dev/null 2>&1 &',
                    escapeshellarg($openclaw_home),
                    escapeshellarg($base_path),
                    escapeshellarg($gateway_api_key),
                    escapeshellarg($perplexity_api_key),
                    escapeshellarg($gateway_auth_token),
                    escapeshellarg($openclaw_path)
                );
                shell_exec($browser_cmd);
                usleep(2000000); // 2s for browser to initialize
                return true;
            }
        }

        rawwire_log('openclaw', 'ensure_gateway_running: Gateway did not become ready within timeout', 'error');
        return false;
    }

    /**
     * Check if the gateway is listening on port 18889.
     */
    private function is_gateway_listening(): bool
    {
        $sock = @fsockopen('127.0.0.1', 18889, $errno, $errstr, 1);
        if ($sock) {
            fclose($sock);
            return true;
        }
        return false;
    }

    /**
     * Chat via OpenClaw agent CLI (with browser tools)
     * 
     * Unlike chat() which calls the HTTP API directly, this calls the local
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
        $timeout_ms = (int) ($options['timeout'] ?? 120000);
        if ($timeout_ms < 30000) {
            $timeout_ms = 30000;
        }
        if ($timeout_ms > 900000) {
            $timeout_ms = 900000;
        }
        $json_mode = $options['json'] ?? true;
        $trace = [
            'started_at' => current_time('mysql'),
            'agent' => $options['agent'] ?? 'main',
            'json_mode' => (bool) $json_mode,
            'timeout_ms' => $timeout_ms,
            'message_chars' => strlen($message),
            'message_sha1' => sha1($message),
            'message_preview' => substr($message, 0, 1000),
        ];

        // Get openclaw binary path from settings or default
        $settings = $this->get_settings();
        $openclaw_path = $settings['openclaw_path'] ?? self::DEFAULT_OPENCLAW_PATH;

        if (!file_exists($openclaw_path) || !is_executable($openclaw_path)) {
            rawwire_log('openclaw', 'agent_chat: openclaw binary missing or not executable at ' . $openclaw_path, 'error');
            return [
                'success' => false,
                'error'   => 'OpenClaw binary unavailable at configured path',
            ];
        }

        // Ensure runtime env/config are ready before spawning CLI.
        $openclaw_home = $this->prepare_openclaw_runtime();
        if ($openclaw_home === null) {
            $trace['error'] = 'Failed to provision OpenClaw config — check API key in AI Settings';
            $this->write_agent_trace($trace);
            return [
                'success' => false,
                'error'   => 'Failed to provision OpenClaw config — check API key in AI Settings',
            ];
        }
        $trace['openclaw_home'] = $openclaw_home;
        $trace['session_candidates_before'] = $this->collect_session_candidates($openclaw_home, $agent);

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

        // Ensure the persistent gateway is running (browser tools require it)
        if (!$this->ensure_gateway_running($openclaw_home)) {
            rawwire_log('openclaw', 'agent_chat: Gateway not available, falling back to --local', 'warning');
        }

        // Build CLI command — connect to running gateway (not --local)
        // Falls back to --local only if gateway couldn't be started.
        $use_local = !$this->is_gateway_listening();
        $cmd_parts = [
            escapeshellarg($openclaw_path) . ' agent',
            '--agent ' . escapeshellarg($agent),
        ];
        if ($use_local) {
            $cmd_parts[] = '--local';
        }

        if ($json_mode) {
            $cmd_parts[] = '--json';
        }

        $cmd_parts[] = '--message ' . escapeshellarg($message);

        // Prefix with exec so /bin/sh does not linger as a parent wrapper.
        $cmd = 'exec ' . implode(' ', $cmd_parts);
        $trace['command'] = $cmd;
        $trace['use_local'] = $use_local;

        rawwire_log('openclaw', sprintf('agent_chat: Executing CLI: %s', $cmd), 'debug');

        // Execute with timeout
        $timeout_sec = (int) ceil($timeout_ms / 1000);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // Pass environment explicitly so HOME is guaranteed to reach the child process.
        // putenv() alone may not propagate reliably under all PHP SAPI modes.
        $settings = $this->get_settings();
        $node_bin_path = $settings['node_bin_path'] ?? self::DEFAULT_NODE_BIN_PATH;
        $base_path = getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
        if (strpos($base_path, $node_bin_path) === false) {
            $base_path = $node_bin_path . ':' . $base_path;
        }
        $env = [
            'HOME'              => $openclaw_home,
            'PATH'              => $base_path,
            'OPENAI_API_KEY'    => $this->auth_token,
            'PERPLEXITY_API_KEY' => $this->get_env_value(['PERPLEXITY_API_KEY', 'PPLX_API_KEY']),
            'NODE_ENV'          => 'production',
        ];

        $process = proc_open($cmd, $descriptors, $pipes, null, $env);

        if (!is_resource($process)) {
            rawwire_log('openclaw', 'agent_chat: Failed to spawn process', 'error');
            $trace['error'] = 'Failed to spawn openclaw agent process';
            $trace['session_candidates_after'] = $this->collect_session_candidates($openclaw_home, $agent);
            $this->write_agent_trace($trace);
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
        $last_heartbeat_sec = 0;

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
                $status_before_kill = proc_get_status($process);
                proc_terminate($process);
                usleep(200000);

                // Escalate to SIGKILL when still running.
                $status_after_term = proc_get_status($process);
                if (!empty($status_after_term['running'])) {
                    proc_terminate($process, 9);
                    $pid = (int) ($status_before_kill['pid'] ?? 0);
                    if ($pid > 0) {
                        @exec('kill -9 ' . $pid . ' >/dev/null 2>&1');
                    }
                }

                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                rawwire_log('openclaw', 'agent_chat: Timeout after ' . $timeout_sec . 's', 'warning');
                $trace['duration_ms'] = (int) round((microtime(true) - $start_time) * 1000);
                $trace['stdout_tail'] = substr($stdout, -self::TRACE_SNIPPET_LIMIT);
                $trace['stderr_tail'] = substr($stderr, -self::TRACE_SNIPPET_LIMIT);
                $trace['error'] = 'Agent timeout after ' . $timeout_sec . ' seconds';
                $trace['session_candidates_after'] = $this->collect_session_candidates($openclaw_home, $agent);
                $trace_path = $this->write_agent_trace($trace);
                return [
                    'success' => false,
                    'error'   => 'Agent timeout after ' . $timeout_sec . ' seconds',
                    'trace_path' => $trace_path,
                ];
            }

            // Progress heartbeat for long-running investigations.
            $elapsed_sec = (int) floor(microtime(true) - $start_time);
            if ($elapsed_sec >= ($last_heartbeat_sec + 30)) {
                $last_heartbeat_sec = $elapsed_sec;
                rawwire_log('openclaw', sprintf(
                    'agent_chat: still running (%ds elapsed, stdout=%dB, stderr=%dB)',
                    $elapsed_sec,
                    strlen($stdout),
                    strlen($stderr)
                ), 'debug');
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
        $trace['finished_at'] = current_time('mysql');
        $trace['duration_ms'] = (int) round((microtime(true) - $start_time) * 1000);
        $trace['exit_code'] = $exit_code;
        $trace['stdout_tail'] = substr($stdout, -self::TRACE_SNIPPET_LIMIT);
        $trace['stderr_tail'] = substr($stderr, -self::TRACE_SNIPPET_LIMIT);
        $trace['session_candidates_after'] = $this->collect_session_candidates($openclaw_home, $agent);

        // Parse JSON output if enabled
        if ($json_mode && !empty($stdout)) {
            // Strip any stderr lines that leaked into stdout.
            $json_start = strpos($stdout, '{');
            $json_str = ($json_start !== false) ? substr($stdout, $json_start) : $stdout;

            $json = json_decode($json_str, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                // Gateway mode wraps in { runId, status, result: { payloads } }
                // Unwrap to normalize both formats.
                if (isset($json['result']['payloads'])) {
                    $json = $json['result'];
                }
                $trace['raw_json_keys'] = array_keys($json);
                $trace['run_id'] = $json['runId'] ?? $json['meta']['runId'] ?? null;
                $trace['payload_count'] = isset($json['payloads']) && is_array($json['payloads']) ? count($json['payloads']) : null;
                $trace['payload_preview'] = isset($json['payloads'][0]['text']) ? substr((string) $json['payloads'][0]['text'], 0, self::TRACE_SNIPPET_LIMIT) : '';
                $trace['content_urls'] = isset($json['payloads'][0]['text']) ? $this->extract_urls((string) $json['payloads'][0]['text']) : [];

                // OpenClaw agent returns payloads[0].text in JSON mode
                if (isset($json['payloads'][0]['text'])) {
                    $trace['success'] = true;
                    $trace_path = $this->write_agent_trace($trace);
                    return [
                        'success' => true,
                        'content' => $json['payloads'][0]['text'],
                        'raw'     => $json,
                        'trace_path' => $trace_path,
                    ];
                }
                // Also handle 'response' key (alternate format)
                if (isset($json['response'])) {
                    $trace['success'] = true;
                    $trace['payload_preview'] = substr((string) $json['response'], 0, self::TRACE_SNIPPET_LIMIT);
                    $trace['content_urls'] = $this->extract_urls((string) $json['response']);
                    $trace_path = $this->write_agent_trace($trace);
                    return [
                        'success' => true,
                        'content' => $json['response'],
                        'raw'     => $json,
                        'trace_path' => $trace_path,
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
                    $trace['error'] = 'Agent returned empty payloads — no text output produced';
                    $trace_path = $this->write_agent_trace($trace);
                    return [
                        'success' => false,
                        'error'   => 'Agent returned empty payloads — no text output produced',
                        'raw'     => $json,
                        'trace_path' => $trace_path,
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
            $trace['error'] = 'Agent JSON output invalid or unrecognized structure';
            $trace['payload_preview'] = substr($stdout_trimmed, 0, self::TRACE_SNIPPET_LIMIT);
            $trace['content_urls'] = $this->extract_urls($stdout_trimmed);
            $trace_path = $this->write_agent_trace($trace);
            return [
                'success' => false,
                'error'   => 'Agent JSON output invalid or unrecognized structure',
                'content' => $stdout_trimmed,
                'trace_path' => $trace_path,
            ];
        }

        // Return raw output for non-JSON mode only
        if (!empty($stdout)) {
            $trace['success'] = true;
            $trace['payload_preview'] = substr(trim($stdout), 0, self::TRACE_SNIPPET_LIMIT);
            $trace['content_urls'] = $this->extract_urls(trim($stdout));
            $trace_path = $this->write_agent_trace($trace);
            return [
                'success' => true,
                'content' => trim($stdout),
                'trace_path' => $trace_path,
            ];
        }

        // Error case
        $trace['error'] = !empty($stderr) ? trim($stderr) : 'No output from agent';
        $trace_path = $this->write_agent_trace($trace);
        return [
            'success' => false,
            'error'   => !empty($stderr) ? trim($stderr) : 'No output from agent',
            'trace_path' => $trace_path,
        ];
    }

    /**
     * Persist an OpenClaw agent trace for post-run debugging.
     */
    private function write_agent_trace(array $trace): string
    {
        $dir = $this->get_secure_output_dir('openclaw-traces');
        $timestamp = date('Ymd_His');
        $hash = substr(sha1(($trace['message_sha1'] ?? '') . '|' . ($trace['started_at'] ?? '') . '|' . ($trace['agent'] ?? 'main')), 0, 12);
        $path = $dir . '/trace_' . $timestamp . '_' . $hash . '.json';

        $written = @file_put_contents($path, wp_json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if ($written === false) {
            rawwire_log('openclaw', 'write_agent_trace: Failed to write trace file ' . $path, 'warning');
            return '';
        }

        @chmod($path, 0640);
        rawwire_log('openclaw', 'Agent trace saved: ' . $path, 'info');
        return $path;
    }

    /**
     * Persist a caller-supplied trace when agent_chat does not return a trace path.
     */
    public function persist_agent_trace(array $trace): string
    {
        return $this->write_agent_trace($trace);
    }

    /**
     * Collect recent session-file candidates from both the runtime HOME and the real user HOME.
     */
    private function collect_session_candidates(string $openclaw_home, string $agent): array
    {
        $paths = [
            $openclaw_home . '/.openclaw/agents/' . $agent . '/sessions/*.jsonl',
            '/home/ractal1/.openclaw/agents/' . $agent . '/sessions/*.jsonl',
        ];

        $files = [];
        foreach ($paths as $pattern) {
            $matches = glob($pattern) ?: [];
            foreach ($matches as $match) {
                $files[$match] = $match;
            }
        }

        if (empty($files)) {
            return [];
        }

        usort($files, static function ($left, $right) {
            return filemtime($right) <=> filemtime($left);
        });

        $candidates = [];
        foreach (array_slice($files, 0, 5) as $file) {
            $candidates[] = [
                'path' => $file,
                'mtime' => @date('c', (int) @filemtime($file)),
                'size' => @filesize($file),
            ];
        }

        return $candidates;
    }

    /**
     * Extract URLs from captured agent output.
     */
    private function extract_urls(string $text): array
    {
        preg_match_all('#https?://[^\s)\]>"\']+#i', $text, $matches);
        $urls = array_values(array_unique($matches[0] ?? []));
        return array_slice($urls, 0, 100);
    }

    /**
     * Resolve/create secure plugin-local output directory.
     */
    private function get_secure_output_dir(string $subdir): string
    {
        $plugin_root = dirname(__DIR__, 2);
        $base_dir = $plugin_root . '/storage/reports';
        $dir = $base_dir . '/' . trim($subdir, '/');

        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        @chmod($base_dir, 0750);
        @chmod($dir, 0750);

        $index_file = $base_dir . '/index.php';
        if (!file_exists($index_file)) {
            @file_put_contents($index_file, "<?php\nif (!defined('ABSPATH')) { exit; }\n");
            @chmod($index_file, 0640);
        }

        $htaccess_file = $base_dir . '/.htaccess';
        if (!file_exists($htaccess_file)) {
            @file_put_contents($htaccess_file, "Order allow,deny\nDeny from all\n");
            @chmod($htaccess_file, 0640);
        }

        return $dir;
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
