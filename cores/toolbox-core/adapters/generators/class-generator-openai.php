<?php
/**
 * OpenAI Generator Adapter (Value Tier)
 * Integration with OpenAI GPT models for content generation.
 * 
 * @package RawWire_Dashboard
 * @subpackage Toolbox_Core
 */
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../class-adapter-base.php';
require_once dirname(dirname(__DIR__)) . '/interfaces/interface-generator.php';

class RawWire_Adapter_Generator_OpenAI extends RawWire_Adapter_Base implements RawWire_Generator_Interface {
    
    protected $name = 'OpenAI GPT';
    protected $version = '1.0.0';
    protected $tier = 'value';
    protected $capabilities = array('text_generation', 'summarization', 'analysis', 'function_calling');
    protected $required_fields = array('api_key');

    const API_BASE = 'https://api.openai.com/v1';

    /**
     * Test OpenAI connection
     */
    public function test_connection() {
        $validation = $this->validate_config();
        if (is_wp_error($validation)) {
            return array(
                'success' => false,
                'message' => $validation->get_error_message(),
            );
        }

        // Test with a minimal models list request
        $response = $this->http_request(self::API_BASE . '/models', array(
            'headers' => $this->build_headers(),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => 'OpenAI API connection failed: ' . $response->get_error_message(),
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200) {
            $this->log('OpenAI connection test passed', 'info');
            return array(
                'success' => true,
                'message' => 'OpenAI API connection successful',
                'details' => array(
                    'capabilities' => $this->capabilities,
                    'models_available' => count($body['data'] ?? array()),
                    'configured_model' => $this->get_config('model', 'gpt-4o-mini'),
                ),
            );
        }

        return array(
            'success' => false,
            'message' => $body['error']['message'] ?? "API returned HTTP $code",
        );
    }

    /**
     * Build request headers
     */
    private function build_headers() {
        $headers = array(
            'Authorization' => 'Bearer ' . $this->get_config('api_key'),
            'Content-Type' => 'application/json',
        );

        $org = $this->get_config('organization_id');
        if (!empty($org)) {
            $headers['OpenAI-Organization'] = $org;
        }

        return $headers;
    }

    /**
     * Generate content from prompt
     */
    public function generate(string $prompt, array $options = array()) {
        $validation = $this->validate_config();
        if (is_wp_error($validation)) {
            return array('success' => false, 'error' => $validation->get_error_message());
        }

        $model = $options['model'] ?? $this->get_config('model', 'gpt-4o-mini');
        $max_tokens = $options['max_tokens'] ?? $this->get_config('max_tokens', 2000);
        $temperature = $options['temperature'] ?? $this->get_config('temperature', 0.7);

        $body = array(
            'model' => $model,
            'messages' => array(
                array('role' => 'user', 'content' => $prompt),
            ),
            'max_tokens' => intval($max_tokens),
            'temperature' => floatval($temperature),
        );

        $this->log('OpenAI generation request', 'info', array(
            'model' => $model,
            'max_tokens' => $max_tokens,
            'prompt_length' => strlen($prompt),
        ));

        $response = $this->http_request(self::API_BASE . '/chat/completions', array(
            'method' => 'POST',
            'headers' => $this->build_headers(),
            'body' => json_encode($body),
            'timeout' => 120,
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message(),
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $error_msg = $data['error']['message'] ?? "API returned HTTP $code";
            $this->set_error('api_error', $error_msg);
            return array(
                'success' => false,
                'error' => $error_msg,
                'code' => $data['error']['code'] ?? null,
            );
        }

        $content = $data['choices'][0]['message']['content'] ?? '';
        $usage = $data['usage'] ?? array();

        $this->log('OpenAI generation completed', 'info', array(
            'model' => $model,
            'tokens_used' => $usage['total_tokens'] ?? 0,
        ));

        return array(
            'success' => true,
            'content' => $content,
            'usage' => array(
                'prompt_tokens' => $usage['prompt_tokens'] ?? 0,
                'completion_tokens' => $usage['completion_tokens'] ?? 0,
                'total_tokens' => $usage['total_tokens'] ?? 0,
            ),
            'model' => $model,
            'finish_reason' => $data['choices'][0]['finish_reason'] ?? 'unknown',
        );
    }

    /**
     * Generate with system context
     */
    public function chat(string $system_prompt, string $user_prompt, array $options = array()) {
        $validation = $this->validate_config();
        if (is_wp_error($validation)) {
            return array('success' => false, 'error' => $validation->get_error_message());
        }

        $model = $options['model'] ?? $this->get_config('model', 'gpt-4o-mini');
        $max_tokens = $options['max_tokens'] ?? $this->get_config('max_tokens', 2000);
        $temperature = $options['temperature'] ?? $this->get_config('temperature', 0.7);

        $messages = array(
            array('role' => 'system', 'content' => $system_prompt),
            array('role' => 'user', 'content' => $user_prompt),
        );

        // Add conversation history if provided
        if (!empty($options['history'])) {
            array_splice($messages, 1, 0, $options['history']);
        }

        $body = array(
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => intval($max_tokens),
            'temperature' => floatval($temperature),
        );

        $this->log('OpenAI chat request', 'info', array(
            'model' => $model,
            'messages_count' => count($messages),
        ));

        $response = $this->http_request(self::API_BASE . '/chat/completions', array(
            'method' => 'POST',
            'headers' => $this->build_headers(),
            'body' => json_encode($body),
            'timeout' => 120,
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message(),
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $error_msg = $data['error']['message'] ?? "API returned HTTP $code";
            $this->set_error('api_error', $error_msg);
            return array(
                'success' => false,
                'error' => $error_msg,
            );
        }

        $content = $data['choices'][0]['message']['content'] ?? '';
        $usage = $data['usage'] ?? array();

        return array(
            'success' => true,
            'content' => $content,
            'usage' => array(
                'prompt_tokens' => $usage['prompt_tokens'] ?? 0,
                'completion_tokens' => $usage['completion_tokens'] ?? 0,
                'total_tokens' => $usage['total_tokens'] ?? 0,
            ),
            'model' => $model,
        );
    }

    /**
     * Summarize content
     */
    public function summarize(string $content, array $options = array()) {
        $length = $options['length'] ?? 'medium';
        $style = $options['style'] ?? 'informative';

        $length_instructions = array(
            'short' => 'Provide a brief summary in 2-3 sentences.',
            'medium' => 'Provide a comprehensive summary in one paragraph.',
            'long' => 'Provide a detailed summary covering all main points.',
        );

        $prompt = "Please summarize the following content. {$length_instructions[$length]}\n\nStyle: {$style}\n\nContent:\n{$content}";

        $result = $this->generate($prompt, array_merge($options, array(
            'max_tokens' => array('short' => 150, 'medium' => 400, 'long' => 800)[$length] ?? 400,
            'temperature' => 0.5,
        )));

        if (!$result['success']) {
            return $result;
        }

        return array(
            'success' => true,
            'summary' => $result['content'],
            'usage' => $result['usage'],
        );
    }

    /**
     * Analyze content
     */
    public function analyze(string $content, array $schema = array()) {
        $schema_json = !empty($schema) ? json_encode($schema) : '{"topics": [], "sentiment": "string", "key_points": [], "entities": []}';

        $prompt = "Analyze the following content and return a JSON object with the analysis.\n\nExpected output schema:\n{$schema_json}\n\nContent:\n{$content}\n\nReturn only valid JSON, no additional text.";

        $result = $this->generate($prompt, array(
            'max_tokens' => 1500,
            'temperature' => 0.3,
        ));

        if (!$result['success']) {
            return $result;
        }

        // Try to parse JSON from response
        $analysis_text = $result['content'];
        
        // Extract JSON if wrapped in markdown code block
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
                'error' => 'Failed to parse analysis response',
                'raw' => $result['content'],
            );
        }

        return array(
            'success' => true,
            'analysis' => $analysis,
            'usage' => $result['usage'],
        );
    }

    /**
     * Get usage stats
     */
    public function get_usage() {
        // OpenAI doesn't have a direct usage endpoint in the standard API
        // This would require the billing API which needs different credentials
        return array(
            'used' => -1,
            'limit' => -1,
            'cost' => -1,
            'note' => 'Usage tracking requires OpenAI billing API access',
        );
    }
}
