<?php

/**
 * Venice.ai Generator Adapter (Privacy Tier)
 * Integration with Venice.ai privacy-first AI models.
 * 
 * Venice.ai is OpenAI-compatible with zero data retention.
 * Supports: chat completions, function/tool calling, web search,
 * vision, reasoning, structured JSON output, and streaming.
 * 
 * Available models (PRIVATE = zero data retention):
 *   - zai-org-glm-4.7       : Flagship 198K context, reasoning + tools
 *   - zai-org-glm-4.7-flash : 128K context, fast + tools (BETA)
 *   - venice-uncensored      : 32K context, unfiltered
 *   - mistral-31-24b         : 128K context, vision + tools
 *   - llama-3.3-70b          : 128K context, balanced + tools
 *   - deepseek-v3.2          : 160K context, code-focused
 *   - kimi-k2-thinking       : 256K context, deep reasoning + tools
 *   - qwen3-235b-a22b-thinking-2507 : 128K context, reasoning
 *
 * @package    RawWire_Dashboard
 * @subpackage Toolbox_Core
 * @since      1.0.24
 * @see        https://docs.venice.ai/overview/getting-started
 */
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../class-adapter-base.php';
require_once dirname(dirname(__DIR__)) . '/interfaces/interface-generator.php';

class RawWire_Adapter_Generator_Venice extends RawWire_Adapter_Base implements RawWire_Generator_Interface
{

    protected $name = 'Venice.ai';
    protected $version = '1.0.0';
    protected $tier = 'privacy';
    protected $capabilities = array(
        'text_generation',
        'summarization',
        'analysis',
        'function_calling',
        'vision',
        'reasoning',
        'structured_output',
        'privacy',
    );
    protected $required_fields = array('api_key');

    /**
     * Venice API base URL (OpenAI-compatible)
     */
    const API_BASE = 'https://api.venice.ai/api/v1';

    /**
     * Default model - GLM 4.7 Flash Heretic (cheap & fast)
     */
    const DEFAULT_MODEL = 'olafangensan-glm-4.7-flash-heretic';

    /**
     * Models that support function/tool calling
     */
    const TOOL_CAPABLE_MODELS = array(
        'olafangensan-glm-4.7-flash-heretic',
        'zai-org-glm-4.7',
        'zai-org-glm-4.7-flash',
        'zai-org-glm-5',
        'mistral-31-24b',
        'llama-3.3-70b',
        'kimi-k2-thinking',
        'kimi-k2-5',
        'gemini-3-flash-preview',
        'gemini-3-pro-preview',
        'gemini-3-1-pro-preview',
        'gemini-2.5-flash',
        'gemini-2.5-pro',
        'grok-41-fast',
        'grok-code-fast-1',
        'claude-sonnet-4-5',
        'claude-sonnet-4-6',
        'claude-opus-4-5',
        'claude-opus-4-6',
        'openai-gpt-52',
        'openai-gpt-52-codex',
        'minimax-m21',
        'minimax-m25',
    );

    /**
     * Models that support vision (image input)
     */
    const VISION_MODELS = array(
        'mistral-31-24b',
        'qwen3-vl-235b-a22b',
        'kimi-k2-5',
        'gemini-3-flash-preview',
        'gemini-3-pro-preview',
        'gemini-3-1-pro-preview',
        'gemini-2.5-flash',
        'gemini-2.5-pro',
        'claude-sonnet-4-5',
        'claude-sonnet-4-6',
        'claude-opus-4-5',
        'claude-opus-4-6',
        'openai-gpt-52',
        'openai-gpt-52-codex',
    );

    /**
     * Models that support reasoning/thinking
     */
    const REASONING_MODELS = array(
        'zai-org-glm-4.7',
        'zai-org-glm-4.7-flash',
        'zai-org-glm-5',
        'kimi-k2-thinking',
        'qwen3-235b-a22b-thinking-2507',
        'gemini-3-pro-preview',
        'gemini-3-1-pro-preview',
        'gemini-2.5-flash',
        'gemini-2.5-pro',
        'claude-sonnet-4-6',
        'claude-opus-4-6',
    );

    /**
     * Available model options with metadata
     * 
     * Cost tiers (per 1M tokens):
     *   - budget: Under $1 combined
     *   - mid: $1-5 combined  
     *   - premium: Over $5 combined
     *
     * @return array
     */
    public static function get_available_models()
    {
        // Try cached models first (refreshed every 6 hours)
        $cached = get_transient('rawwire_venice_models');
        if ($cached !== false && !empty($cached)) {
            return $cached;
        }

        // Try fetching from API
        $api_models = self::fetch_models_from_api();
        if (!empty($api_models)) {
            return $api_models;
        }

        // Fallback to static list
        return self::get_fallback_models();
    }

    /**
     * Fetch models from Venice API and cache them
     *
     * @param bool $force Force refresh even if cache exists
     * @return array|false Array of models or false on failure
     */
    public static function fetch_models_from_api($force = false)
    {
        if (!$force) {
            $cached = get_transient('rawwire_venice_models');
            if ($cached !== false) {
                return $cached;
            }
        }

        // Get API key from settings
        $settings = get_option('rawwire_venice_settings', array());
        $api_key = $settings['api_key'] ?? '';

        if (empty($api_key)) {
            return false;
        }

        $response = wp_remote_get(self::API_BASE . '/models', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            error_log('Venice: Failed to fetch models - ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            error_log('Venice: Models API returned HTTP ' . $code);
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $api_models = $body['data'] ?? array();

        if (empty($api_models)) {
            return false;
        }

        // Get metadata enrichment data
        $metadata = self::get_model_metadata();

        // Transform API response to our format
        $models = array();
        foreach ($api_models as $model) {
            $id = $model['id'] ?? '';
            if (empty($id)) {
                continue;
            }

            // Skip non-text models (image generators, etc)
            $type = $model['type'] ?? 'text';
            if ($type !== 'text') {
                continue;
            }

            // Get metadata if we have it, otherwise use defaults
            $meta = $metadata[$id] ?? array();

            $models[$id] = array(
                'label'    => $meta['label'] ?? self::format_model_label($id),
                'tier'     => $meta['tier'] ?? self::infer_tier($model),
                'context'  => $model['context_length'] ?? $meta['context'] ?? 32000,
                'tools'    => $meta['tools'] ?? false,
                'vision'   => $meta['vision'] ?? false,
                'reason'   => $meta['reason'] ?? false,
                'cost'     => $meta['cost'] ?? self::format_cost($model),
            );
        }

        // Sort by tier then label
        uasort($models, function ($a, $b) {
            $tier_order = array('budget' => 0, 'mid' => 1, 'premium' => 2);
            $tier_cmp = ($tier_order[$a['tier']] ?? 1) - ($tier_order[$b['tier']] ?? 1);
            if ($tier_cmp !== 0) {
                return $tier_cmp;
            }
            return strcmp($a['label'], $b['label']);
        });

        // Cache for 6 hours
        set_transient('rawwire_venice_models', $models, 6 * HOUR_IN_SECONDS);

        return $models;
    }

    /**
     * Format model ID into readable label
     *
     * @param string $id Model ID
     * @return string Formatted label
     */
    private static function format_model_label($id)
    {
        // Common replacements
        $label = str_replace(array('-', '_'), ' ', $id);
        $label = ucwords($label);

        // Fix common patterns
        $label = preg_replace('/(\d+)b\b/i', '$1B', $label);
        $label = preg_replace('/(\d+)k\b/i', '$1K', $label);
        $label = str_ireplace('Gpt ', 'GPT-', $label);
        $label = str_ireplace('Llama ', 'Llama ', $label);
        $label = str_ireplace('Qwen ', 'Qwen ', $label);

        return $label;
    }

    /**
     * Infer pricing tier from model data
     *
     * @param array $model API model data
     * @return string Tier: budget, mid, or premium
     */
    private static function infer_tier($model)
    {
        $id = strtolower($model['id'] ?? '');

        // Premium indicators
        if (preg_match('/405b|opus|gpt-4o(?!-mini)|claude-3\.[57]|gemini.*pro/i', $id)) {
            return 'premium';
        }

        // Budget indicators
        if (preg_match('/flash|mini|small|7b|8b|14b/i', $id)) {
            return 'budget';
        }

        return 'mid';
    }

    /**
     * Format cost string from model pricing data
     *
     * @param array $model API model data
     * @return string Formatted cost string
     */
    private static function format_cost($model)
    {
        $pricing = $model['pricing'] ?? array();
        $input = $pricing['input'] ?? $pricing['prompt'] ?? null;
        $output = $pricing['output'] ?? $pricing['completion'] ?? null;

        if ($input !== null && $output !== null) {
            // Venice returns price per token, convert to per 1M
            $in_per_m = floatval($input) * 1000000;
            $out_per_m = floatval($output) * 1000000;
            return sprintf('$%.2f in / $%.2f out', $in_per_m, $out_per_m);
        }

        return 'Pricing TBD';
    }

    /**
     * Known model metadata for enrichment
     * This supplements API data with capabilities we've tested
     *
     * @return array
     */
    private static function get_model_metadata()
    {
        return array(
            // GLM models
            'zai-org-glm-4.7' => array(
                'label' => 'GLM 4.7 (Flagship)',
                'tier' => 'premium',
                'tools' => true,
                'reason' => true,
                'cost' => '$0.55 in / $2.65 out',
            ),
            'zai-org-glm-4.7-flash' => array(
                'label' => 'GLM 4.7 Flash',
                'tier' => 'budget',
                'tools' => true,
                'reason' => true,
                'cost' => '$0.13 in / $0.50 out',
            ),
            // Llama models
            'llama-3.3-70b' => array(
                'label' => 'Llama 3.3 70B',
                'tier' => 'mid',
                'tools' => true,
                'cost' => '$0.70 in / $2.80 out',
            ),
            'llama-3.1-405b' => array(
                'label' => 'Llama 3.1 405B',
                'tier' => 'premium',
                'cost' => '$1.00 in / $3.00 out',
            ),
            // DeepSeek models
            'deepseek-v3.2' => array(
                'label' => 'DeepSeek V3.2',
                'tier' => 'mid',
                'cost' => '$0.40 in / $1.00 out',
            ),
            'deepseek-r1' => array(
                'label' => 'DeepSeek R1',
                'tier' => 'mid',
                'reason' => true,
                'cost' => '$0.55 in / $2.19 out',
            ),
            'deepseek-r1-llama-70b' => array(
                'label' => 'DeepSeek R1 Llama 70B',
                'tier' => 'mid',
                'reason' => true,
                'cost' => '$0.55 in / $2.19 out',
            ),
            // Qwen models
            'qwen3-235b-a22b' => array(
                'label' => 'Qwen3 235B',
                'tier' => 'mid',
                'cost' => '$0.30 in / $1.20 out',
            ),
            'qwen3-235b-a22b-thinking-2507' => array(
                'label' => 'Qwen3 235B Thinking',
                'tier' => 'mid',
                'reason' => true,
                'cost' => '$0.30 in / $1.20 out',
            ),
            'qwen3-vl-235b-a22b' => array(
                'label' => 'Qwen3 VL 235B (Vision)',
                'tier' => 'mid',
                'vision' => true,
                'cost' => '$0.40 in / $1.60 out',
            ),
            'qwen-qwq-32b' => array(
                'label' => 'Qwen QWQ 32B',
                'tier' => 'budget',
                'reason' => true,
                'cost' => '$0.20 in / $0.60 out',
            ),
            'qwen-2.5-vl-72b' => array(
                'label' => 'Qwen 2.5 VL 72B (Vision)',
                'tier' => 'premium',
                'vision' => true,
                'cost' => '$0.80 in / $3.20 out',
            ),
            'qwen-2.5-coder-32b' => array(
                'label' => 'Qwen 2.5 Coder 32B',
                'tier' => 'premium',
                'cost' => '$0.60 in / $2.40 out',
            ),
            // Kimi models
            'kimi-k2-thinking' => array(
                'label' => 'Kimi K2 Thinking',
                'tier' => 'mid',
                'context' => 256000,
                'tools' => true,
                'reason' => true,
                'cost' => '$0.75 in / $3.20 out',
            ),
            'kimi-k2-5' => array(
                'label' => 'Kimi K2.5',
                'tier' => 'mid',
                'context' => 256000,
                'tools' => true,
                'vision' => true,
                'cost' => '$0.75 in / $3.75 out',
            ),
            // Mistral models
            'mistral-31-24b' => array(
                'label' => 'Mistral 3.1 24B',
                'tier' => 'mid',
                'tools' => true,
                'vision' => true,
                'cost' => '$0.50 in / $2.00 out',
            ),
            // Dolphin models (uncensored)
            'dolphin-2.9.3' => array(
                'label' => 'Dolphin 2.9.3 (Uncensored)',
                'tier' => 'mid',
                'cost' => '$0.30 in / $0.80 out',
            ),
            'dolphin-3.0-r1' => array(
                'label' => 'Dolphin 3.0 R1 (Uncensored)',
                'tier' => 'mid',
                'reason' => true,
                'cost' => '$0.30 in / $0.80 out',
            ),
            'venice-uncensored' => array(
                'label' => 'Venice Uncensored',
                'tier' => 'mid',
                'cost' => '$0.20 in / $0.90 out',
            ),
            // OpenAI models
            'gpt-4o' => array(
                'label' => 'GPT-4o',
                'tier' => 'premium',
                'tools' => true,
                'vision' => true,
                'cost' => '$2.50 in / $10.00 out',
            ),
            'gpt-4o-mini' => array(
                'label' => 'GPT-4o Mini',
                'tier' => 'budget',
                'tools' => true,
                'vision' => true,
                'cost' => '$0.15 in / $0.60 out',
            ),
            'o3-mini' => array(
                'label' => 'O3 Mini',
                'tier' => 'premium',
                'tools' => true,
                'reason' => true,
                'cost' => '$1.10 in / $4.40 out',
            ),
            // Google models
            'gemini-3-flash-preview' => array(
                'label' => 'Gemini 3 Flash (Preview)',
                'tier' => 'mid',
                'tools' => true,
                'vision' => true,
                'cost' => '$0.70 in / $3.75 out',
            ),
            'gemini-3-pro-preview' => array(
                'label' => 'Gemini 3 Pro (Preview)',
                'tier' => 'premium',
                'tools' => true,
                'vision' => true,
                'reason' => true,
                'cost' => '$2.50 in / $15.00 out',
            ),
            'gemini-2.5-flash' => array(
                'label' => 'Gemini 2.5 Flash',
                'tier' => 'budget',
                'tools' => true,
                'vision' => true,
                'reason' => true,
                'cost' => '$0.15 in / $0.60 out',
            ),
            'gemini-2.5-pro' => array(
                'label' => 'Gemini 2.5 Pro',
                'tier' => 'premium',
                'tools' => true,
                'vision' => true,
                'reason' => true,
                'cost' => '$1.25 in / $10.00 out',
            ),
            // Anthropic models
            'claude-3.5-sonnet' => array(
                'label' => 'Claude 3.5 Sonnet',
                'tier' => 'premium',
                'tools' => true,
                'vision' => true,
                'cost' => '$3.00 in / $15.00 out',
            ),
            'claude-3.7-sonnet' => array(
                'label' => 'Claude 3.7 Sonnet',
                'tier' => 'premium',
                'tools' => true,
                'vision' => true,
                'reason' => true,
                'cost' => '$3.00 in / $15.00 out',
            ),
            'claude-sonnet-4-5' => array(
                'label' => 'Claude Sonnet 4.5',
                'tier' => 'premium',
                'context' => 198000,
                'tools' => true,
                'vision' => true,
                'cost' => '$3.75 in / $18.75 out',
            ),
            'claude-sonnet-4-6' => array(
                'label' => 'Claude Sonnet 4.6',
                'tier' => 'premium',
                'context' => 1000000,
                'tools' => true,
                'vision' => true,
                'reason' => true,
                'cost' => '$3.75 in / $18.75 out',
            ),
            'claude-opus-4-5' => array(
                'label' => 'Claude Opus 4.5',
                'tier' => 'premium',
                'context' => 198000,
                'tools' => true,
                'vision' => true,
                'cost' => '$6.00 in / $30.00 out',
            ),
            'claude-opus-4-6' => array(
                'label' => 'Claude Opus 4.6',
                'tier' => 'premium',
                'context' => 1000000,
                'tools' => true,
                'vision' => true,
                'reason' => true,
                'cost' => '$6.00 in / $30.00 out',
            ),
            // xAI Grok models
            'grok-41-fast' => array(
                'label' => 'Grok 4.1 Fast',
                'tier' => 'mid',
                'context' => 256000,
                'tools' => true,
                'cost' => '$0.50 in / $1.25 out',
            ),
            'grok-code-fast-1' => array(
                'label' => 'Grok Code Fast 1',
                'tier' => 'mid',
                'context' => 256000,
                'tools' => true,
                'cost' => '$0.25 in / $1.87 out',
            ),
            // GLM 5
            'zai-org-glm-5' => array(
                'label' => 'GLM 5 (Latest)',
                'tier' => 'premium',
                'context' => 198000,
                'tools' => true,
                'reason' => true,
                'cost' => '$1.00 in / $3.20 out',
            ),
            // OpenAI via Venice
            'openai-gpt-52' => array(
                'label' => 'GPT-5.2',
                'tier' => 'premium',
                'context' => 256000,
                'tools' => true,
                'vision' => true,
                'cost' => '$2.19 in / $17.50 out',
            ),
            'openai-gpt-52-codex' => array(
                'label' => 'GPT-5.2 Codex',
                'tier' => 'premium',
                'context' => 256000,
                'tools' => true,
                'vision' => true,
                'cost' => '$2.19 in / $17.50 out',
            ),
            'openai-gpt-oss-120b' => array(
                'label' => 'GPT OSS 120B',
                'tier' => 'budget',
                'context' => 128000,
                'tools' => true,
                'cost' => '$0.07 in / $0.30 out',
            ),
            // MiniMax models
            'minimax-m21' => array(
                'label' => 'MiniMax M2.1',
                'tier' => 'mid',
                'context' => 198000,
                'tools' => true,
                'cost' => '$0.40 in / $1.60 out',
            ),
            'minimax-m25' => array(
                'label' => 'MiniMax M2.5',
                'tier' => 'mid',
                'context' => 198000,
                'tools' => true,
                'cost' => '$0.40 in / $1.60 out',
            ),
            // Gemini 3.1 Pro
            'gemini-3-1-pro-preview' => array(
                'label' => 'Gemini 3.1 Pro (Preview)',
                'tier' => 'premium',
                'context' => 1000000,
                'tools' => true,
                'vision' => true,
                'reason' => true,
                'cost' => '$2.50 in / $15.00 out',
            ),
        );
    }

    /**
     * Fallback static model list when API is unavailable
     *
     * @return array
     */
    private static function get_fallback_models()
    {
        return array(
            'zai-org-glm-4.7-flash' => array(
                'label'    => 'GLM 4.7 Flash ⭐',
                'tier'     => 'budget',
                'context'  => 128000,
                'tools'    => true,
                'vision'   => false,
                'reason'   => true,
                'cost'     => '$0.13 in / $0.50 out',
            ),
            'grok-41-fast' => array(
                'label'    => 'Grok 4.1 Fast',
                'tier'     => 'mid',
                'context'  => 256000,
                'tools'    => true,
                'vision'   => false,
                'reason'   => false,
                'cost'     => '$0.50 in / $1.25 out',
            ),
            'llama-3.3-70b' => array(
                'label'    => 'Llama 3.3 70B',
                'tier'     => 'mid',
                'context'  => 128000,
                'tools'    => true,
                'vision'   => false,
                'reason'   => false,
                'cost'     => '$0.70 in / $2.80 out',
            ),
            'gemini-3-flash-preview' => array(
                'label'    => 'Gemini 3 Flash (Preview)',
                'tier'     => 'mid',
                'context'  => 256000,
                'tools'    => true,
                'vision'   => true,
                'reason'   => false,
                'cost'     => '$0.70 in / $3.75 out',
            ),
            'deepseek-v3.2' => array(
                'label'    => 'DeepSeek V3.2',
                'tier'     => 'mid',
                'context'  => 160000,
                'tools'    => false,
                'vision'   => false,
                'reason'   => false,
                'cost'     => '$0.40 in / $1.00 out',
            ),
        );
    }

    // -------------------------------------------------------------------------
    // Connection Test
    // -------------------------------------------------------------------------

    /**
     * Test Venice.ai API connection
     *
     * @return array{success: bool, message: string, details?: array}
     */
    public function test_connection()
    {
        $validation = $this->validate_config();
        if (is_wp_error($validation)) {
            return array(
                'success' => false,
                'message' => $validation->get_error_message(),
            );
        }

        // List models to verify the API key works
        $response = $this->http_request(self::API_BASE . '/models', array(
            'headers' => $this->build_headers(),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => 'Venice.ai API connection failed: ' . $response->get_error_message(),
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200) {
            $model_count = count($body['data'] ?? array());
            $configured_model = $this->get_config('model', self::DEFAULT_MODEL);

            $this->log('Venice.ai connection test passed', 'info', array(
                'models_available' => $model_count,
            ));

            return array(
                'success' => true,
                'message' => "Venice.ai connected - {$model_count} models available",
                'details' => array(
                    'capabilities'     => $this->capabilities,
                    'models_available' => $model_count,
                    'configured_model' => $configured_model,
                    'privacy'          => 'Zero data retention',
                ),
            );
        }

        $error_msg = $body['error']['message'] ?? "API returned HTTP {$code}";
        return array(
            'success' => false,
            'message' => $error_msg,
        );
    }

    // -------------------------------------------------------------------------
    // HTTP Helpers
    // -------------------------------------------------------------------------

    /**
     * Build authorization headers for Venice API
     *
     * @return array
     */
    private function build_headers()
    {
        return array(
            'Authorization' => 'Bearer ' . $this->get_config('api_key'),
            'Content-Type'  => 'application/json',
        );
    }

    /**
     * Build Venice-specific parameters block
     *
     * Merges adapter config defaults with per-request overrides.
     *
     * @param array $options Request options that may contain venice_parameters
     * @return array
     */
    private function build_venice_parameters(array $options = array())
    {
        $params = array();

        $params['enable_web_search'] = $options['enable_web_search']
            ?? $this->get_config('enable_web_search', 'off');
        $params['enable_web_scraping'] = (bool) ($options['enable_web_scraping']
            ?? $this->get_config('enable_web_scraping', false));
        $params['enable_web_citations'] = (bool) ($options['enable_web_citations']
            ?? $this->get_config('enable_web_citations', false));

        // Strip thinking tokens from reasoning models
        $strip = $options['strip_thinking'] ?? $this->get_config('strip_thinking_response', true);
        $params['strip_thinking_response'] = (bool) $strip;
        $params['disable_thinking'] = (bool) ($options['disable_thinking']
            ?? $this->get_config('disable_thinking', false));

        // Disable Venice's default system prompt (we inject our own)
        $params['include_venice_system_prompt'] = (bool) $this->get_config(
            'include_venice_system_prompt',
            false
        );

        // Allow explicit overrides from caller
        if (!empty($options['venice_parameters']) && is_array($options['venice_parameters'])) {
            $params = array_merge($params, $options['venice_parameters']);
        }

        return $params;
    }

    /**
     * Filter tool definitions according to provider capability settings.
     *
     * @param array $tools
     * @return array
     */
    private function filter_tools_for_settings(array $tools)
    {
        if (!$this->get_config('allow_tool_calls', true)) {
            return array();
        }

        $filtered = array();
        foreach ($tools as $tool) {
            if (!is_array($tool)) {
                continue;
            }

            $kind = $this->classify_tool_for_settings($tool);
            if ($kind === 'mcp' && !$this->get_config('allow_mcp_tools', true)) {
                continue;
            }

            if ($kind === 'openclaw' && !$this->get_config('allow_openclaw_tools', true)) {
                continue;
            }

            $filtered[] = $tool;
        }

        return $filtered;
    }

    /**
     * Best-effort tool classification for provider-side toggles.
     *
     * @param array $tool
     * @return string
     */
    private function classify_tool_for_settings(array $tool)
    {
        $type = strtolower((string) ($tool['type'] ?? ''));
        $name = strtolower((string) ($tool['function']['name'] ?? $tool['name'] ?? ''));
        $haystack = trim($type . ' ' . $name);

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

    // -------------------------------------------------------------------------
    // Generator Interface: generate()
    // -------------------------------------------------------------------------

    /**
     * Generate content from a prompt
     *
     * @param string $prompt User prompt text
     * @param array  $options {model, max_tokens, temperature, tools, web_search, ...}
     * @return array{success: bool, content?: string, usage?: array, error?: string}
     */
    public function generate(string $prompt, array $options = array())
    {
        $validation = $this->validate_config();
        if (is_wp_error($validation)) {
            return array('success' => false, 'error' => $validation->get_error_message());
        }

        $model      = $options['model'] ?? $this->get_config('model', self::DEFAULT_MODEL);
        $max_tokens = $options['max_tokens'] ?? $this->get_config('max_tokens', 2000);
        $temperature = $options['temperature'] ?? $this->get_config('temperature', 0.7);

        $body = array(
            'model'    => $model,
            'messages' => array(
                array('role' => 'user', 'content' => $prompt),
            ),
            'max_tokens'  => intval($max_tokens),
            'temperature' => floatval($temperature),
            'top_p'       => floatval($options['top_p'] ?? $this->get_config('top_p', 0.9)),
        );

        $reasoning_effort = $options['reasoning_effort'] ?? $this->get_config('reasoning_effort', 'off');
        if ($reasoning_effort !== 'off' && $this->model_supports_reasoning($model)) {
            $body['reasoning_effort'] = $reasoning_effort;
        }

        // Attach Venice-specific parameters
        $venice_params = $this->build_venice_parameters($options);
        if (!empty($venice_params)) {
            $body['venice_parameters'] = $venice_params;
        }

        // Attach tool definitions if provided
        $filtered_tools = $this->filter_tools_for_settings((array) ($options['tools'] ?? array()));
        if (!empty($filtered_tools) && $this->model_supports_tools($model)) {
            $body['tools'] = $filtered_tools;
            if (isset($options['tool_choice'])) {
                $body['tool_choice'] = $options['tool_choice'];
            }
            $body['parallel_tool_calls'] = !empty($options['parallel_tool_calls'])
                || $this->get_config('parallel_tool_calls', true);
        }

        // Structured JSON output
        if (!empty($options['response_format'])) {
            $body['response_format'] = $options['response_format'];
        }

        $this->log('Venice.ai generation request', 'info', array(
            'model'         => $model,
            'max_tokens'    => $max_tokens,
            'prompt_length' => strlen($prompt),
            'web_search'    => 'off',  // NEVER use Venice web search
        ));

        $response = $this->http_request(self::API_BASE . '/chat/completions', array(
            'method'  => 'POST',
            'headers' => $this->build_headers(),
            'body'    => wp_json_encode($body),
            'timeout' => 120,
        ));

        return $this->parse_chat_response($response, $model);
    }

    // -------------------------------------------------------------------------
    // Generator Interface: chat()
    // -------------------------------------------------------------------------

    /**
     * Generate with a system message context
     *
     * @param string $system_prompt System instructions
     * @param string $user_prompt   User message
     * @param array  $options       Generation options
     * @return array{success: bool, content?: string, usage?: array, error?: string}
     */
    public function chat(string $system_prompt, string $user_prompt, array $options = array())
    {
        $validation = $this->validate_config();
        if (is_wp_error($validation)) {
            return array('success' => false, 'error' => $validation->get_error_message());
        }

        $model       = $options['model'] ?? $this->get_config('model', self::DEFAULT_MODEL);
        $max_tokens  = $options['max_tokens'] ?? $this->get_config('max_tokens', 4096);
        $temperature = $options['temperature'] ?? $this->get_config('temperature', 0.7);

        $messages = array(
            array('role' => 'system', 'content' => $system_prompt),
            array('role' => 'user',   'content' => $user_prompt),
        );

        // Insert conversation history between system and user messages
        if (!empty($options['history']) && is_array($options['history'])) {
            array_splice($messages, 1, 0, $options['history']);
        }

        $body = array(
            'model'       => $model,
            'messages'    => $messages,
            'max_tokens'  => intval($max_tokens),
            'temperature' => floatval($temperature),
            'top_p'       => floatval($options['top_p'] ?? $this->get_config('top_p', 0.9)),
        );

        $reasoning_effort = $options['reasoning_effort'] ?? $this->get_config('reasoning_effort', 'off');
        if ($reasoning_effort !== 'off' && $this->model_supports_reasoning($model)) {
            $body['reasoning_effort'] = $reasoning_effort;
        }

        // Venice parameters
        $venice_params = $this->build_venice_parameters($options);
        if (!empty($venice_params)) {
            $body['venice_parameters'] = $venice_params;
        }

        // Tool definitions for agentic use
        $filtered_tools = $this->filter_tools_for_settings((array) ($options['tools'] ?? array()));
        if (!empty($filtered_tools) && $this->model_supports_tools($model)) {
            $body['tools'] = $filtered_tools;
            if (isset($options['tool_choice'])) {
                $body['tool_choice'] = $options['tool_choice'];
            }
            $body['parallel_tool_calls'] = !empty($options['parallel_tool_calls'])
                || $this->get_config('parallel_tool_calls', true);
        }

        // Structured JSON output
        if (!empty($options['response_format'])) {
            $body['response_format'] = $options['response_format'];
        }

        $this->log('Venice.ai chat request', 'info', array(
            'model'          => $model,
            'messages_count' => count($messages),
        ));

        $response = $this->http_request(self::API_BASE . '/chat/completions', array(
            'method'  => 'POST',
            'headers' => $this->build_headers(),
            'body'    => wp_json_encode($body),
            'timeout' => 120,
        ));

        return $this->parse_chat_response($response, $model);
    }

    // -------------------------------------------------------------------------
    // Generator Interface: summarize()
    // -------------------------------------------------------------------------

    /**
     * Summarize content
     *
     * @param string $content Content to summarize
     * @param array  $options {length: short|medium|long, style: string}
     * @return array{success: bool, summary?: string, error?: string}
     */
    public function summarize(string $content, array $options = array())
    {
        $length = $options['length'] ?? 'medium';
        $style  = $options['style'] ?? 'informative';

        $length_instructions = array(
            'short'  => 'Provide a brief summary in 2-3 sentences.',
            'medium' => 'Provide a comprehensive summary in one paragraph.',
            'long'   => 'Provide a detailed summary covering all main points.',
        );

        $instruction = $length_instructions[$length] ?? $length_instructions['medium'];
        $prompt = "Please summarize the following content. {$instruction}\n\nStyle: {$style}\n\nContent:\n{$content}";

        $token_limits = array('short' => 150, 'medium' => 400, 'long' => 800);

        $result = $this->generate($prompt, array_merge($options, array(
            'max_tokens'  => $token_limits[$length] ?? 400,
            'temperature' => 0.5,
        )));

        if (!$result['success']) {
            return $result;
        }

        return array(
            'success' => true,
            'summary' => $result['content'],
            'usage'   => $result['usage'] ?? array(),
        );
    }

    // -------------------------------------------------------------------------
    // Generator Interface: analyze()
    // -------------------------------------------------------------------------

    /**
     * Analyze content and extract structured insights
     *
     * Uses Venice structured JSON output when available.
     *
     * @param string $content Content to analyze
     * @param array  $schema  Expected output schema
     * @return array{success: bool, analysis?: array, error?: string}
     */
    public function analyze(string $content, array $schema = array())
    {
        $schema_json = !empty($schema)
            ? wp_json_encode($schema)
            : '{"topics": [], "sentiment": "string", "key_points": [], "entities": []}';

        $prompt = "Analyze the following content and return a JSON object with the analysis.\n\n"
            . "Expected output schema:\n{$schema_json}\n\n"
            . "Content:\n{$content}\n\n"
            . "Return only valid JSON, no additional text.";

        $result = $this->generate($prompt, array(
            'max_tokens'  => 1500,
            'temperature' => 0.3,
        ));

        if (!$result['success']) {
            return $result;
        }

        // Extract JSON from response (may be wrapped in markdown code block)
        $analysis_text = $result['content'];
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*\})\s*```/', $analysis_text, $matches)) {
            $analysis_text = $matches[1];
        }

        $analysis = json_decode($analysis_text, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log('Failed to parse analysis JSON', 'warning', array(
                'error' => json_last_error_msg(),
            ));
            return array(
                'success' => false,
                'error'   => 'Failed to parse analysis response',
                'raw'     => $result['content'],
            );
        }

        return array(
            'success'  => true,
            'analysis' => $analysis,
            'usage'    => $result['usage'] ?? array(),
        );
    }

    // -------------------------------------------------------------------------
    // Generator Interface: get_usage()
    // -------------------------------------------------------------------------

    /**
     * Get usage/quota information
     *
     * Venice does not expose a billing API; usage is tracked per-request.
     *
     * @return array{used: int, limit: int, cost?: float}
     */
    public function get_usage()
    {
        return array(
            'used'  => -1,
            'limit' => -1,
            'cost'  => -1,
            'note'  => 'Venice.ai usage is tracked per-request. Check your dashboard at venice.ai/settings.',
        );
    }

    // -------------------------------------------------------------------------
    // Venice-Specific: Web Search
    // -------------------------------------------------------------------------

    /**
     * Generate response with web search enabled
     *
     * Convenience method that forces web search on.
     *
     * @param string $prompt     User prompt
     * @param array  $options    Standard generation options
     * @return array
     */
    public function generate_with_search(string $prompt, array $options = array())
    {
        // Web search is DISABLED — Venice web search/scraping must never be used
        // This method now behaves identically to generate()
        return $this->generate($prompt, $options);
    }

    /**
     * Chat with web search enabled
     *
     * @param string $system_prompt System instructions
     * @param string $user_prompt   User message
     * @param array  $options       Generation options
     * @return array
     */
    public function chat_with_search(string $system_prompt, string $user_prompt, array $options = array())
    {
        // Web search is DISABLED — Venice web search/scraping must never be used
        // This method now behaves identically to chat()
        return $this->chat($system_prompt, $user_prompt, $options);
    }

    // -------------------------------------------------------------------------
    // Venice-Specific: Tool/Function Calling
    // -------------------------------------------------------------------------

    /**
     * Send a chat request with tool calling support
     *
     * Handles the full tool-call loop: sends request, executes tools,
     * returns final response. Caller provides a callback to execute tools.
     *
     * @param array    $messages       Conversation messages
     * @param array    $tools          Tool definitions (OpenAI format)
     * @param callable $tool_executor  fn(string $name, array $args): string
     * @param array    $options        Additional options
     * @return array{success: bool, content?: string, tool_calls?: array, usage?: array}
     */
    public function chat_with_tools(array $messages, array $tools, callable $tool_executor, array $options = array())
    {
        $validation = $this->validate_config();
        if (is_wp_error($validation)) {
            return array('success' => false, 'error' => $validation->get_error_message());
        }

        $model       = $options['model'] ?? $this->get_config('model', self::DEFAULT_MODEL);
        $max_tokens  = $options['max_tokens'] ?? $this->get_config('max_tokens', 4096);
        $temperature = $options['temperature'] ?? $this->get_config('temperature', 0.7);
        $max_rounds  = $options['max_tool_rounds'] ?? 5;

        if (!$this->model_supports_tools($model)) {
            return array(
                'success' => false,
                'error'   => "Model '{$model}' does not support tool calling. Use one of: "
                    . implode(', ', self::TOOL_CAPABLE_MODELS),
            );
        }

        $all_tool_calls = array();
        $total_usage = array('prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0);

        for ($round = 0; $round < $max_rounds; $round++) {
            $body = array(
                'model'       => $model,
                'messages'    => $messages,
                'tools'       => $this->filter_tools_for_settings($tools),
                'max_tokens'  => intval($max_tokens),
                'temperature' => floatval($temperature),
                'top_p'       => floatval($options['top_p'] ?? $this->get_config('top_p', 0.9)),
            );

            $reasoning_effort = $options['reasoning_effort'] ?? $this->get_config('reasoning_effort', 'off');
            if ($reasoning_effort !== 'off' && $this->model_supports_reasoning($model)) {
                $body['reasoning_effort'] = $reasoning_effort;
            }

            $venice_params = $this->build_venice_parameters($options);
            if (!empty($venice_params)) {
                $body['venice_parameters'] = $venice_params;
            }

            if (!empty($body['tools'])) {
                $body['parallel_tool_calls'] = !empty($options['parallel_tool_calls'])
                    || $this->get_config('parallel_tool_calls', true);
            }

            $response = $this->http_request(self::API_BASE . '/chat/completions', array(
                'method'  => 'POST',
                'headers' => $this->build_headers(),
                'body'    => wp_json_encode($body),
                'timeout' => 120,
            ));

            if (is_wp_error($response)) {
                return array('success' => false, 'error' => $response->get_error_message());
            }

            $code = wp_remote_retrieve_response_code($response);
            $data = json_decode(wp_remote_retrieve_body($response), true);

            if ($code !== 200) {
                $error_msg = $data['error']['message'] ?? "API returned HTTP {$code}";
                return array('success' => false, 'error' => $error_msg);
            }

            // Accumulate token usage
            $usage = $data['usage'] ?? array();
            $total_usage['prompt_tokens']     += $usage['prompt_tokens'] ?? 0;
            $total_usage['completion_tokens'] += $usage['completion_tokens'] ?? 0;
            $total_usage['total_tokens']      += $usage['total_tokens'] ?? 0;

            $choice  = $data['choices'][0] ?? array();
            $message = $choice['message'] ?? array();
            $finish  = $choice['finish_reason'] ?? 'stop';

            // If no tool calls, we have the final response
            if (empty($message['tool_calls'])) {
                return array(
                    'success'    => true,
                    'content'    => $message['content'] ?? '',
                    'tool_calls' => $all_tool_calls,
                    'usage'      => $total_usage,
                    'model'      => $model,
                    'rounds'     => $round + 1,
                );
            }

            // Process tool calls
            $messages[] = $message; // Add assistant message with tool_calls

            foreach ($message['tool_calls'] as $tool_call) {
                $fn_name = $tool_call['function']['name'] ?? '';
                $fn_args = json_decode($tool_call['function']['arguments'] ?? '{}', true) ?: array();
                $call_id = $tool_call['id'] ?? '';

                $this->log("Executing tool: {$fn_name}", 'info', array(
                    'round' => $round + 1,
                    'args'  => array_keys($fn_args),
                ));

                $all_tool_calls[] = array(
                    'name'  => $fn_name,
                    'args'  => $fn_args,
                    'round' => $round + 1,
                );

                // Execute the tool via caller-provided callback
                try {
                    $tool_result = call_user_func($tool_executor, $fn_name, $fn_args);
                } catch (\Exception $e) {
                    $tool_result = wp_json_encode(array(
                        'error' => $e->getMessage(),
                    ));
                }

                // Add tool result message
                $messages[] = array(
                    'role'         => 'tool',
                    'tool_call_id' => $call_id,
                    'content'      => is_string($tool_result) ? $tool_result : wp_json_encode($tool_result),
                );
            }
        }

        // Exhausted max rounds
        return array(
            'success'    => true,
            'content'    => 'Tool calling reached maximum rounds without final response.',
            'tool_calls' => $all_tool_calls,
            'usage'      => $total_usage,
            'model'      => $model,
            'rounds'     => $max_rounds,
            'warning'    => 'max_rounds_reached',
        );
    }

    // -------------------------------------------------------------------------
    // Internal Helpers
    // -------------------------------------------------------------------------

    /**
     * Check if a model supports tool/function calling
     *
     * @param string $model Model ID
     * @return bool
     */
    public function model_supports_tools(string $model)
    {
        return in_array($model, self::TOOL_CAPABLE_MODELS, true);
    }

    /**
     * Check if a model supports vision (image input)
     *
     * @param string $model Model ID
     * @return bool
     */
    public function model_supports_vision(string $model)
    {
        return in_array($model, self::VISION_MODELS, true);
    }

    /**
     * Check if a model supports reasoning/thinking
     *
     * @param string $model Model ID
     * @return bool
     */
    public function model_supports_reasoning(string $model)
    {
        return in_array($model, self::REASONING_MODELS, true);
    }

    /**
     * Parse a chat completions API response
     *
     * @param array|WP_Error $response Raw HTTP response
     * @param string         $model    Model used for the request
     * @return array
     */
    private function parse_chat_response($response, string $model)
    {
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error'   => $response->get_error_message(),
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $error_msg = $data['error']['message'] ?? "API returned HTTP {$code}";
            $this->set_error('api_error', $error_msg);
            return array(
                'success' => false,
                'error'   => $error_msg,
                'code'    => $data['error']['code'] ?? null,
            );
        }

        $choice  = $data['choices'][0] ?? array();
        $message = $choice['message'] ?? array();
        $content = $message['content'] ?? '';
        $usage   = $data['usage'] ?? array();

        $this->log('Venice.ai response received', 'info', array(
            'model'       => $model,
            'tokens_used' => $usage['total_tokens'] ?? 0,
        ));

        $result = array(
            'success'       => true,
            'content'       => $content,
            'usage'         => array(
                'prompt_tokens'     => $usage['prompt_tokens'] ?? 0,
                'completion_tokens' => $usage['completion_tokens'] ?? 0,
                'total_tokens'      => $usage['total_tokens'] ?? 0,
            ),
            'model'         => $data['model'] ?? $model,
            'finish_reason' => $choice['finish_reason'] ?? 'unknown',
        );

        // Include tool calls if present
        if (!empty($message['tool_calls'])) {
            $result['tool_calls'] = $message['tool_calls'];
        }

        // Web search citations disabled — Venice web search is never used

        // Include reasoning content if present
        if (!empty($message['reasoning_content'])) {
            $result['reasoning'] = $message['reasoning_content'];
        }

        return $result;
    }
}
