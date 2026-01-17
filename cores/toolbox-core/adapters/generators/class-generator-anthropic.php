<?php
/**
 * Anthropic Claude Generator Adapter (Flagship Tier)
 * Integration with Anthropic Claude models for high-quality content generation.
 * 
 * @package RawWire_Dashboard
 * @subpackage Toolbox_Core
 */
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../class-adapter-base.php';
require_once dirname(dirname(__DIR__)) . '/interfaces/interface-generator.php';

class RawWire_Adapter_Generator_Anthropic extends RawWire_Adapter_Base implements RawWire_Generator_Interface {
    
    protected $name = 'Anthropic Claude';
    protected $version = '1.0.0';
    protected $tier = 'flagship';
    protected $capabilities = array('text_generation', 'long_context', 'reasoning', 'analysis', 'coding');
    protected $required_fields = array('api_key');

    const API_BASE = 'https://api.anthropic.com/v1';
    const API_VERSION = '2023-06-01';

    /**
     * Test Anthropic connection
     */
    public function test_connection() {
        $validation = $this->validate_config();
        if (is_wp_error($validation)) {
            return array(
                'success' => false,
                'message' => $validation->get_error_message(),
            );
        }

        // Test with a minimal message request
        $response = $this->http_request(self::API_BASE . '/messages', array(
            'method' => 'POST',
            'headers' => $this->build_headers(),
            'body' => json_encode(array(
                'model' => 'claude-3-haiku-20240307', // Use cheapest model for test
                'max_tokens' => 10,
                'messages' => array(
                    array('role' => 'user', 'content' => 'Hi'),
                ),
            )),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => 'Anthropic API connection failed: ' . $response->get_error_message(),
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200) {
            $this->log('Anthropic connection test passed', 'info');
            return array(
                'success' => true,
                'message' => 'Anthropic Claude API connection successful',
                'details' => array(
                    'capabilities' => $this->capabilities,
                    'configured_model' => $this->get_config('model', 'claude-3-5-sonnet-20241022'),
                ),
            );
        }

        $error_msg = $body['error']['message'] ?? "API returned HTTP $code";
        return array(
            'success' => false,
            'message' => $error_msg,
        );
    }

    /**
     * Build request headers
     */
    private function build_headers() {
        return array(
            'x-api-key' => $this->get_config('api_key'),
            'anthropic-version' => self::API_VERSION,
            'Content-Type' => 'application/json',
        );
    }

    /**
     * Generate content from prompt
     */
    public function generate(string $prompt, array $options = array()) {
        $validation = $this->validate_config();
        if (is_wp_error($validation)) {
            return array('success' => false, 'error' => $validation->get_error_message());
        }

        $model = $options['model'] ?? $this->get_config('model', 'claude-3-5-sonnet-20241022');
        $max_tokens = $options['max_tokens'] ?? $this->get_config('max_tokens', 4096);
        $temperature = $options['temperature'] ?? $this->get_config('temperature', 0.7);

        $body = array(
            'model' => $model,
            'max_tokens' => intval($max_tokens),
            'messages' => array(
                array('role' => 'user', 'content' => $prompt),
            ),
        );

        // Temperature is optional in Claude API
        if ($temperature !== null) {
            $body['temperature'] = floatval($temperature);
        }

        $this->log('Anthropic generation request', 'info', array(
            'model' => $model,
            'max_tokens' => $max_tokens,
            'prompt_length' => strlen($prompt),
        ));

        $response = $this->http_request(self::API_BASE . '/messages', array(
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
            $this->set_error('api_error', $error_msg, array('type' => $data['error']['type'] ?? 'unknown'));
            return array(
                'success' => false,
                'error' => $error_msg,
                'type' => $data['error']['type'] ?? null,
            );
        }

        // Extract content from Claude's response format
        $content = '';
        if (!empty($data['content'])) {
            foreach ($data['content'] as $block) {
                if ($block['type'] === 'text') {
                    $content .= $block['text'];
                }
            }
        }

        $usage = $data['usage'] ?? array();

        $this->log('Anthropic generation completed', 'info', array(
            'model' => $model,
            'input_tokens' => $usage['input_tokens'] ?? 0,
            'output_tokens' => $usage['output_tokens'] ?? 0,
        ));

        return array(
            'success' => true,
            'content' => $content,
            'usage' => array(
                'prompt_tokens' => $usage['input_tokens'] ?? 0,
                'completion_tokens' => $usage['output_tokens'] ?? 0,
                'total_tokens' => ($usage['input_tokens'] ?? 0) + ($usage['output_tokens'] ?? 0),
            ),
            'model' => $data['model'] ?? $model,
            'stop_reason' => $data['stop_reason'] ?? 'unknown',
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

        $model = $options['model'] ?? $this->get_config('model', 'claude-3-5-sonnet-20241022');
        $max_tokens = $options['max_tokens'] ?? $this->get_config('max_tokens', 4096);
        $temperature = $options['temperature'] ?? $this->get_config('temperature', 0.7);

        $messages = array(
            array('role' => 'user', 'content' => $user_prompt),
        );

        // Add conversation history if provided
        if (!empty($options['history'])) {
            array_splice($messages, 0, 0, $options['history']);
        }

        $body = array(
            'model' => $model,
            'max_tokens' => intval($max_tokens),
            'system' => $system_prompt, // Claude uses separate system field
            'messages' => $messages,
        );

        if ($temperature !== null) {
            $body['temperature'] = floatval($temperature);
        }

        $this->log('Anthropic chat request', 'info', array(
            'model' => $model,
            'messages_count' => count($messages),
            'system_length' => strlen($system_prompt),
        ));

        $response = $this->http_request(self::API_BASE . '/messages', array(
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

        $content = '';
        if (!empty($data['content'])) {
            foreach ($data['content'] as $block) {
                if ($block['type'] === 'text') {
                    $content .= $block['text'];
                }
            }
        }

        $usage = $data['usage'] ?? array();

        return array(
            'success' => true,
            'content' => $content,
            'usage' => array(
                'prompt_tokens' => $usage['input_tokens'] ?? 0,
                'completion_tokens' => $usage['output_tokens'] ?? 0,
                'total_tokens' => ($usage['input_tokens'] ?? 0) + ($usage['output_tokens'] ?? 0),
            ),
            'model' => $data['model'] ?? $model,
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

        $system = "You are an expert summarizer. Create clear, accurate summaries that capture the essential information.";
        $prompt = "{$length_instructions[$length]}\n\nStyle: {$style}\n\nContent to summarize:\n{$content}";

        $result = $this->chat($system, $prompt, array_merge($options, array(
            'max_tokens' => array('short' => 200, 'medium' => 500, 'long' => 1000)[$length] ?? 500,
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
        $schema_json = !empty($schema) 
            ? json_encode($schema, JSON_PRETTY_PRINT) 
            : json_encode(array(
                'topics' => array('type' => 'array', 'description' => 'Main topics'),
                'sentiment' => array('type' => 'string', 'enum' => array('positive', 'negative', 'neutral', 'mixed')),
                'key_points' => array('type' => 'array', 'description' => 'Key takeaways'),
                'entities' => array('type' => 'array', 'description' => 'Named entities'),
                'summary' => array('type' => 'string', 'description' => 'Brief summary'),
            ), JSON_PRETTY_PRINT);

        $system = "You are an expert content analyst. Analyze content and return structured JSON analysis. Return only valid JSON, no additional text or markdown code blocks.";
        $prompt = "Analyze the following content and return a JSON object with your analysis.\n\nExpected output schema:\n{$schema_json}\n\nContent:\n{$content}";

        $result = $this->chat($system, $prompt, array(
            'max_tokens' => 2000,
            'temperature' => 0.3,
        ));

        if (!$result['success']) {
            return $result;
        }

        // Try to parse JSON from response
        $analysis_text = trim($result['content']);
        
        // Extract JSON if wrapped in markdown code block
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $analysis_text, $matches)) {
            $analysis_text = trim($matches[1]);
        }

        // Remove any leading/trailing non-JSON content
        $analysis_text = preg_replace('/^[^{]*/', '', $analysis_text);
        $analysis_text = preg_replace('/[^}]*$/', '', $analysis_text);

        $analysis = json_decode($analysis_text, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log('Failed to parse analysis JSON', 'warning', array(
                'error' => json_last_error_msg(),
                'raw_length' => strlen($result['content']),
            ));
            return array(
                'success' => false,
                'error' => 'Failed to parse analysis response: ' . json_last_error_msg(),
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
        // Anthropic doesn't have a public usage API
        return array(
            'used' => -1,
            'limit' => -1,
            'cost' => -1,
            'note' => 'Usage tracking requires Anthropic console access',
        );
    }
}
