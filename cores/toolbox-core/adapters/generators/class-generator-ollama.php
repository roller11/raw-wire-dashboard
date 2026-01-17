<?php
/**
 * Ollama Generator Adapter (Free Tier)
 * Local AI using Ollama - completely free, no API costs.
 * 
 * @package RawWire_Dashboard
 * @subpackage Toolbox_Core
 */
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../class-adapter-base.php';
require_once dirname(dirname(__DIR__)) . '/interfaces/interface-generator.php';

class RawWire_Adapter_Generator_Ollama extends RawWire_Adapter_Base implements RawWire_Generator_Interface {
    
    protected $name = 'Ollama (Local AI)';
    protected $version = '1.0.0';
    protected $tier = 'free';
    protected $capabilities = array('text_generation', 'summarization', 'analysis', 'json_mode');
    protected $required_fields = array(); // host is optional, defaults to localhost

    /**
     * Get Ollama API base URL
     */
    private function get_api_base() {
        $cfg = $this->get_config('host', '');
        if (!empty($cfg)) return rtrim($cfg, '/');
        $opt = get_option('rawwire_ollama_host', '');
        if (!empty($opt)) return rtrim($opt, '/');
        // Default to Docker container hostname (ollama:11434) or fallback to localhost
        return getenv('OLLAMA_HOST') ?: (getenv('DOCKER_CONTAINER') ? 'http://ollama:11434' : 'http://127.0.0.1:8001');
    }

    /**
     * Test Ollama connection
     */
    public function test_connection() {
        $api_base = $this->get_api_base();
        
        // Check if Ollama is running
        $response = wp_remote_get($api_base . '/api/tags', array('timeout' => 5));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => 'Ollama not accessible: ' . $response->get_error_message(),
                'hint' => 'Run: docker run -d -p 11434:11434 --name ollama ollama/ollama OR docker run -d -p 8001:11434 --name ollama ollama/ollama',
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return array(
                'success' => false,
                'message' => "Ollama API returned HTTP {$code}",
            );
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $models = $body['models'] ?? array();

        return array(
            'success' => true,
            'message' => 'Ollama connection successful',
            'details' => array(
                'api_base' => $api_base,
                'models_installed' => count($models),
                'models' => array_column($models, 'name'),
                'default_model' => $this->get_config('model', 'llama3.2:latest'),
                'cost' => 'FREE (runs locally)',
            ),
        );
    }

    /**
     * Generate content from a prompt
     */
    public function generate(string $prompt, array $options = array()) {
        $model = $options['model'] ?? $this->get_config('model', 'llama3.2:latest');
        
        $request_body = array(
            'model' => $model,
            'prompt' => $prompt,
            'stream' => false,
            'options' => array(
                'temperature' => $options['temperature'] ?? 0.7,
                'top_p' => $options['top_p'] ?? 0.9,
            ),
        );

        if (!empty($options['system'])) {
            $request_body['system'] = $options['system'];
        }

        if (!empty($options['format']) && $options['format'] === 'json') {
            $request_body['format'] = 'json';
        }

        $this->log('Generating with Ollama', 'info', array('model' => $model));

        $response = wp_remote_post($this->get_api_base() . '/api/generate', array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => json_encode($request_body),
            'timeout' => $options['timeout'] ?? 60,
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message(),
            );
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!isset($body['response'])) {
            return array(
                'success' => false,
                'error' => 'Invalid response from Ollama',
            );
        }

        return array(
            'success' => true,
            'content' => trim($body['response']),
            'model' => $body['model'] ?? $model,
            'usage' => array(
                'total_duration' => $body['total_duration'] ?? 0,
                'load_duration' => $body['load_duration'] ?? 0,
                'prompt_eval_count' => $body['prompt_eval_count'] ?? 0,
            ),
        );
    }

    /**
     * Generate with system message context (chat format)
     */
    public function chat(string $system_prompt, string $user_prompt, array $options = array()) {
        $model = $options['model'] ?? $this->get_config('model', 'llama3.2:latest');
        
        $request_body = array(
            'model' => $model,
            'messages' => array(
                array('role' => 'system', 'content' => $system_prompt),
                array('role' => 'user', 'content' => $user_prompt),
            ),
            'stream' => false,
            'options' => array(
                'temperature' => $options['temperature'] ?? 0.7,
            ),
        );

        if (!empty($options['format']) && $options['format'] === 'json') {
            $request_body['format'] = 'json';
        }

        $response = wp_remote_post($this->get_api_base() . '/api/chat', array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => json_encode($request_body),
            'timeout' => $options['timeout'] ?? 60,
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message(),
            );
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!isset($body['message']['content'])) {
            return array(
                'success' => false,
                'error' => 'Invalid response from Ollama',
            );
        }

        return array(
            'success' => true,
            'content' => trim($body['message']['content']),
            'model' => $body['model'] ?? $model,
        );
    }

    /**
     * Summarize content
     */
    public function summarize(string $content, array $options = array()) {
        $max_length = $options['max_length'] ?? 200;
        $style = $options['style'] ?? 'concise';
        
        $prompt = "Summarize the following content in {$max_length} words or less. Style: {$style}.\n\nContent:\n{$content}";
        
        return $this->generate($prompt, $options);
    }

    /**
     * Analyze content and extract structured insights
     */
    public function analyze(string $content, array $schema = array()) {
        $prompt = "Analyze the following content and extract key information.\n\nContent:\n{$content}";
        
        if (!empty($schema)) {
            $schema_desc = json_encode($schema, JSON_PRETTY_PRINT);
            $prompt .= "\n\nProvide the analysis in this JSON structure:\n{$schema_desc}";
        }
        
        $options = array(
            'format' => 'json',
            'temperature' => 0.3, // Lower temp for structured output
        );
        
        $result = $this->generate($prompt, $options);
        
        if ($result['success'] && isset($result['content'])) {
            $parsed = json_decode($result['content'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $result['analysis'] = $parsed;
            }
        }
        
        return $result;
    }

    /**
     * List available models
     */
    public function list_models() {
        $response = wp_remote_get($this->get_api_base() . '/api/tags', array('timeout' => 10));
        
        if (is_wp_error($response)) {
            return array();
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body['models'] ?? array();
    }

    /**
     * Pull a model (download if not installed)
     */
    public function pull_model($model_name) {
        $this->log("Pulling model: {$model_name}", 'info');
        
        $response = wp_remote_post($this->get_api_base() . '/api/pull', array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => json_encode(array('name' => $model_name)),
            'timeout' => 300, // 5 minutes for download
        ));

        return !is_wp_error($response);
    }

    /**
     * Get usage statistics
     * Ollama is free and runs locally, so no API costs
     */
    public function get_usage() {
        return array(
            'success' => true,
            'cost' => 0.00,
            'currency' => 'USD',
            'requests' => 0,
            'message' => 'Ollama runs locally - completely free with no API costs',
            'tier' => 'free',
            'limits' => array(
                'requests_per_day' => 'unlimited',
                'rate_limit' => 'depends on local hardware',
            ),
        );
    }
}
