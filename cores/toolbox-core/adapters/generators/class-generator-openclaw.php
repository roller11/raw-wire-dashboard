<?php

/**
 * OpenClaw Gateway Adapter
 *
 * Connects to a local OpenClaw agent gateway (OpenAI-compatible API).
 * OpenClaw acts as a unified proxy: it routes requests to whichever
 * backend provider is configured (Venice.ai, Ollama, OpenAI, etc.)
 * while providing auth, model aliasing, and local tool execution.
 *
 * Default endpoint: http://localhost:18789/v1
 * Auth: Bearer token configured in OpenClaw's openclaw.json
 *
 * @package    RawWire_Dashboard
 * @subpackage Toolbox_Core
 * @since      1.0.28
 * @see        https://openclaw.ai
 */
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../class-adapter-base.php';
require_once dirname(dirname(__DIR__)) . '/interfaces/interface-generator.php';

class RawWire_Adapter_Generator_OpenClaw extends RawWire_Adapter_Base implements RawWire_Generator_Interface
{

    protected $name = 'OpenClaw';
    protected $version = '1.0.0';
    protected $tier = 'local';
    protected $capabilities = array(
        'text_generation',
        'summarization',
        'analysis',
        'function_calling',
        'privacy',
    );
    protected $required_fields = array('auth_token');

    /**
     * WordPress option key for OpenClaw settings
     */
    const OPTION_KEY = 'rawwire_openclaw_settings';

    /**
     * Default gateway host
     */
    const DEFAULT_HOST = 'http://localhost:18789';

    /**
     * Default model - GLM 4.7 Flash Heretic (cheap & fast)
     */
    const DEFAULT_MODEL = 'olafangensan-glm-4.7-flash-heretic';

    // -------------------------------------------------------------------------
    // Settings Helpers
    // -------------------------------------------------------------------------

    /**
     * Get saved OpenClaw settings from wp_options
     *
     * Falls back to Venice settings for model if not explicitly set.
     *
     * @return array
     */
    public static function get_settings()
    {
        $settings = get_option(self::OPTION_KEY, array(
            'host'       => self::DEFAULT_HOST,
            'auth_token' => '',
            'model'      => '',
        ));

        // Fall back to Venice model if OpenClaw model not set
        if (empty($settings['model']) || $settings['model'] === 'venice') {
            $venice = get_option('rawwire_venice_settings', array());
            $settings['model'] = !empty($venice['model']) ? $venice['model'] : self::DEFAULT_MODEL;
        }

        return $settings;
    }

    /**
     * Build the base URL for the OpenAI-compatible API
     *
     * @return string e.g. http://localhost:18789/v1
     */
    private function get_api_base()
    {
        $host = rtrim($this->get_config('host', self::DEFAULT_HOST), '/');
        return $host . '/v1';
    }

    // -------------------------------------------------------------------------
    // Connection Test
    // -------------------------------------------------------------------------

    /**
     * Test OpenClaw gateway connectivity
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

        $url = $this->get_api_base() . '/models';

        $response = $this->http_request($url, array(
            'headers' => $this->build_headers(),
            'timeout' => 10,
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => 'OpenClaw gateway unreachable: ' . $response->get_error_message(),
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200) {
            $models = $body['data'] ?? array();
            $model_count = count($models);
            $configured = $this->get_config('model', self::DEFAULT_MODEL);

            return array(
                'success' => true,
                'message' => "OpenClaw connected - {$model_count} models available",
                'details' => array(
                    'host'             => $this->get_config('host', self::DEFAULT_HOST),
                    'models_available' => $model_count,
                    'configured_model' => $configured,
                    'gateway'          => 'OpenClaw v2026',
                ),
            );
        }

        if ($code === 401) {
            return array(
                'success' => false,
                'message' => 'Authentication failed - check your auth token.',
            );
        }

        $error_msg = $body['error']['message'] ?? "Gateway returned HTTP {$code}";
        return array(
            'success' => false,
            'message' => $error_msg,
        );
    }

    // -------------------------------------------------------------------------
    // HTTP Helpers
    // -------------------------------------------------------------------------

    /**
     * Build authorization headers
     *
     * @return array
     */
    private function build_headers()
    {
        return array(
            'Authorization' => 'Bearer ' . $this->get_config('auth_token'),
            'Content-Type'  => 'application/json',
        );
    }

    // -------------------------------------------------------------------------
    // Generator Interface: generate()
    // -------------------------------------------------------------------------

    /**
     * Generate content from a prompt
     *
     * @param string $prompt User prompt text
     * @param array  $options {model, max_tokens, temperature, ...}
     * @return array{success: bool, content?: string, usage?: array, error?: string}
     */
    public function generate(string $prompt, array $options = array())
    {
        $validation = $this->validate_config();
        if (is_wp_error($validation)) {
            return array('success' => false, 'error' => $validation->get_error_message());
        }

        $model       = $options['model'] ?? $this->get_config('model', self::DEFAULT_MODEL);
        $max_tokens  = $options['max_tokens'] ?? $this->get_config('max_tokens', 2000);
        $temperature = $options['temperature'] ?? $this->get_config('temperature', 0.7);

        $body = array(
            'model'       => $model,
            'messages'    => array(
                array('role' => 'user', 'content' => $prompt),
            ),
            'max_tokens'  => intval($max_tokens),
            'temperature' => floatval($temperature),
        );

        // Tool definitions if provided
        if (!empty($options['tools'])) {
            $body['tools'] = $options['tools'];
            $body['tool_choice'] = $options['tool_choice'] ?? 'auto';
        }

        $url = $this->get_api_base() . '/chat/completions';

        $response = $this->http_request($url, array(
            'method'  => 'POST',
            'headers' => $this->build_headers(),
            'body'    => wp_json_encode($body),
            'timeout' => 120,
        ));

        if (is_wp_error($response)) {
            $this->last_error = $response;
            return array('success' => false, 'error' => $response->get_error_message());
        }

        return $this->parse_response($response);
    }

    // -------------------------------------------------------------------------
    // Generator Interface: chat()
    // -------------------------------------------------------------------------

    /**
     * Generate with system + user messages
     *
     * @param string $system_prompt System context
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
        $max_tokens  = $options['max_tokens'] ?? $this->get_config('max_tokens', 2000);
        $temperature = $options['temperature'] ?? $this->get_config('temperature', 0.7);

        $messages = array();
        if (!empty($system_prompt)) {
            $messages[] = array('role' => 'system', 'content' => $system_prompt);
        }
        $messages[] = array('role' => 'user', 'content' => $user_prompt);

        // Append conversation history if provided
        if (!empty($options['messages']) && is_array($options['messages'])) {
            // Insert history between system and user
            $system_msg = !empty($system_prompt) ? array(array_shift($messages)) : array();
            $user_msg   = array(array_pop($messages));
            $messages   = array_merge($system_msg, $options['messages'], $user_msg);
        }

        $body = array(
            'model'       => $model,
            'messages'    => $messages,
            'max_tokens'  => intval($max_tokens),
            'temperature' => floatval($temperature),
        );

        // Tool definitions if provided
        if (!empty($options['tools'])) {
            $body['tools'] = $options['tools'];
            $body['tool_choice'] = $options['tool_choice'] ?? 'auto';
        }

        $url = $this->get_api_base() . '/chat/completions';

        $response = $this->http_request($url, array(
            'method'  => 'POST',
            'headers' => $this->build_headers(),
            'body'    => wp_json_encode($body),
            'timeout' => 120,
        ));

        if (is_wp_error($response)) {
            $this->last_error = $response;
            return array('success' => false, 'error' => $response->get_error_message());
        }

        return $this->parse_response($response);
    }

    // -------------------------------------------------------------------------
    // Generator Interface: summarize()
    // -------------------------------------------------------------------------

    /**
     * Summarize content
     *
     * @param string $content Content to summarize
     * @param array  $options Summary options
     * @return array{success: bool, summary?: string, error?: string}
     */
    public function summarize(string $content, array $options = array())
    {
        $length = $options['length'] ?? 'medium';
        $style  = $options['style'] ?? 'paragraph';

        $system = "You are a precise summarization assistant. Create a {$length}-length {$style} summary.";
        $result = $this->chat($system, "Summarize the following:\n\n{$content}", $options);

        if ($result['success']) {
            $result['summary'] = $result['content'];
        }
        return $result;
    }

    // -------------------------------------------------------------------------
    // Generator Interface: analyze()
    // -------------------------------------------------------------------------

    /**
     * Analyze content and extract structured data
     *
     * @param string $content Content to analyze
     * @param array  $schema  Expected output schema
     * @return array{success: bool, analysis?: array, error?: string}
     */
    public function analyze(string $content, array $schema = array())
    {
        $schema_desc = !empty($schema) ? "\n\nReturn JSON matching this schema:\n" . wp_json_encode($schema) : '';
        $system = "You are an analysis assistant. Analyze the content and return structured JSON.{$schema_desc}";

        $opts = array('temperature' => 0.3);
        $result = $this->chat($system, "Analyze:\n\n{$content}", $opts);

        if ($result['success'] && !empty($result['content'])) {
            $parsed = json_decode($result['content'], true);
            if ($parsed !== null) {
                $result['analysis'] = $parsed;
            }
        }
        return $result;
    }

    // -------------------------------------------------------------------------
    // Generator Interface: get_usage()
    // -------------------------------------------------------------------------

    /**
     * Get usage information
     *
     * OpenClaw does not expose a usage endpoint; return local tracking data.
     *
     * @return array{used: int, limit: int, cost?: float}
     */
    public function get_usage()
    {
        $stats = get_option('rawwire_openclaw_usage', array(
            'requests' => 0,
            'tokens'   => 0,
        ));

        return array(
            'used'  => intval($stats['tokens']),
            'limit' => 0, // Unlimited via local gateway
            'cost'  => 0.0,
        );
    }

    // -------------------------------------------------------------------------
    // Response Parsing
    // -------------------------------------------------------------------------

    /**
     * Parse a chat completions response
     *
     * @param array|WP_Error $response wp_remote_* response
     * @return array{success: bool, content?: string, usage?: array, error?: string}
     */
    private function parse_response($response)
    {
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $error = $body['error']['message'] ?? "Gateway returned HTTP {$code}";
            $this->log("OpenClaw error: {$error}", 'error', array('http_code' => $code));
            return array('success' => false, 'error' => $error);
        }

        $choice  = $body['choices'][0] ?? null;
        $content = $choice['message']['content'] ?? '';
        $usage   = $body['usage'] ?? array();

        // Track usage locally
        $this->track_usage($usage);

        // Check for tool calls
        $tool_calls = $choice['message']['tool_calls'] ?? array();

        $result = array(
            'success' => true,
            'content' => $content,
            'usage'   => array(
                'prompt_tokens'     => $usage['prompt_tokens'] ?? 0,
                'completion_tokens' => $usage['completion_tokens'] ?? 0,
                'total_tokens'      => $usage['total_tokens'] ?? 0,
            ),
            'model'       => $body['model'] ?? '',
            'finish'      => $choice['finish_reason'] ?? 'stop',
        );

        if (!empty($tool_calls)) {
            $result['tool_calls'] = $tool_calls;
        }

        return $result;
    }

    /**
     * Track token usage in wp_options
     *
     * @param array $usage Usage data from response
     */
    private function track_usage(array $usage)
    {
        $stats = get_option('rawwire_openclaw_usage', array(
            'requests' => 0,
            'tokens'   => 0,
        ));
        $stats['requests'] = intval($stats['requests']) + 1;
        $stats['tokens']   = intval($stats['tokens']) + intval($usage['total_tokens'] ?? 0);
        update_option('rawwire_openclaw_usage', $stats, false);
    }

    // -------------------------------------------------------------------------
    // Available Models (fetched from gateway)
    // -------------------------------------------------------------------------

    /**
     * Fetch available models from the OpenClaw gateway
     *
     * @return array Model list keyed by ID
     */
    public static function get_available_models()
    {
        $cached = get_transient('rawwire_openclaw_models');
        if ($cached !== false) {
            return $cached;
        }

        $settings   = self::get_settings();
        $auth_token = $settings['auth_token'] ?? '';
        $host       = rtrim($settings['host'] ?? self::DEFAULT_HOST, '/');

        if (empty($auth_token)) {
            return self::get_fallback_models();
        }

        $response = wp_remote_get($host . '/v1/models', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $auth_token,
                'Content-Type'  => 'application/json',
            ),
            'timeout' => 10,
        ));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return self::get_fallback_models();
        }

        $body   = json_decode(wp_remote_retrieve_body($response), true);
        $models = array();

        foreach ($body['data'] ?? array() as $m) {
            $id = $m['id'] ?? '';
            if (empty($id)) {
                continue;
            }
            $models[$id] = array(
                'label'   => ucwords(str_replace(array('-', '_', '/'), ' ', $id)),
                'context' => $m['context_length'] ?? 128000,
            );
        }

        if (!empty($models)) {
            set_transient('rawwire_openclaw_models', $models, 2 * HOUR_IN_SECONDS);
        }

        return !empty($models) ? $models : self::get_fallback_models();
    }

    /**
     * Fallback model list when gateway is unreachable
     *
     * @return array
     */
    private static function get_fallback_models()
    {
        return array(
            'openai/llama-3.3-70b' => array(
                'label'   => 'Llama 3.3 70B (via Venice)',
                'context' => 128000,
            ),
        );
    }
}
