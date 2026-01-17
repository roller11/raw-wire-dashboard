<?php
/**
 * AI Adapter - Integration layer for AI Engine plugin
 * 
 * Provides a unified interface for AI operations, with graceful
 * fallback when AI Engine is not installed.
 *
 * @package RawWire\Dashboard\Cores\ToolboxCore
 * @since 1.0.15
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class RawWire_AI_Adapter
 * 
 * Wraps Meow AI Engine functionality for Raw Wire Dashboard tools.
 * Detects AI Engine availability and provides fallback behavior.
 */
class RawWire_AI_Adapter {

    /**
     * Singleton instance
     * @var RawWire_AI_Adapter|null
     */
    private static $instance = null;

    /**
     * Whether AI Engine is available
     * @var bool
     */
    private $ai_engine_available = false;

    /**
     * Whether AI Engine Pro is available
     * @var bool
     */
    private $ai_engine_pro = false;

    /**
     * Global mwai object reference
     * @var object|null
     */
    private $mwai = null;

    /**
     * Global mwai_core object reference
     * @var object|null
     */
    private $mwai_core = null;

    /**
     * Default environment ID
     * @var string
     */
    private $default_env_id = '';

    /**
     * Default model
     * @var string
     */
    private $default_model = '';

    /**
     * Default temperature
     * @var float
     */
    private $default_temperature = 0.7;

    /**
     * Default max tokens
     * @var int
     */
    private $default_max_tokens = 2048;

    /**
     * Cache for AI responses
     * @var array
     */
    private $cache = [];

    /**
     * Cache TTL in seconds
     * @var int
     */
    private $cache_ttl = 3600;

    /**
     * Supported AI providers
     */
    const PROVIDERS = [
        'openai'     => 'OpenAI (GPT-4, GPT-3.5)',
        'anthropic'  => 'Anthropic (Claude)',
        'google'     => 'Google (Gemini)',
        'huggingface'=> 'Hugging Face',
        'ollama'     => 'Ollama (Self-hosted)',
        'openrouter' => 'OpenRouter (Multi-model)',
        'groq'       => 'Groq (Fast inference)',
    ];

    /**
     * Query types
     */
    const QUERY_TEXT   = 'text';
    const QUERY_JSON   = 'json';
    const QUERY_VISION = 'vision';
    const QUERY_CHAT   = 'chat';

    /**
     * Get singleton instance
     * 
     * @return RawWire_AI_Adapter
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor
     */
    private function __construct() {
        $this->detect_ai_engine();
        $this->load_settings();
        
        add_action('plugins_loaded', [$this, 'late_init'], 20);
        
        // Add filter to handle custom endpoints for OpenAI-compatible providers (like Groq)
        add_filter('mwai_openai_endpoint', [$this, 'filter_openai_endpoint'], 10, 2);
        
        // Add Groq models to the available models list
        add_filter('mwai_openai_models', [$this, 'add_groq_models'], 10, 1);
    }
    
    /**
     * Add Groq models to the AI Engine's model list
     * 
     * @param array $models Existing models
     * @return array Models with Groq additions
     */
    public function add_groq_models($models) {
        // Groq Llama models
        $groq_models = [
            [
                'model' => 'llama-3.3-70b-versatile',
                'name' => 'Llama 3.3 70B (Groq)',
                'mode' => 'chat',
                'type' => 'token',
                'tags' => ['chat', 'turbo'],
                'unit' => 1000,
                'input' => 0.59,
                'output' => 0.79,
                'maxCompletionTokens' => 32768,
                'maxContextualTokens' => 131072,
            ],
            [
                'model' => 'llama-3.1-8b-instant',
                'name' => 'Llama 3.1 8B Instant (Groq)',
                'mode' => 'chat',
                'type' => 'token',
                'tags' => ['chat', 'turbo'],
                'unit' => 1000,
                'input' => 0.05,
                'output' => 0.08,
                'maxCompletionTokens' => 131072,
                'maxContextualTokens' => 131072,
            ],
            [
                'model' => 'openai/gpt-oss-120b',
                'name' => 'GPT-OSS 120B (Groq)',
                'mode' => 'chat',
                'type' => 'token',
                'tags' => ['chat'],
                'unit' => 1000,
                'input' => 0.15,
                'output' => 0.60,
                'maxCompletionTokens' => 65536,
                'maxContextualTokens' => 131072,
            ],
        ];
        
        return array_merge($models, $groq_models);
    }
    
    /**
     * Filter to redirect OpenAI-compatible environments to their custom endpoints
     * 
     * @param string $endpoint Default endpoint (api.openai.com)
     * @param array  $env      Environment configuration
     * @return string Modified endpoint
     */
    public function filter_openai_endpoint($endpoint, $env) {
        // If environment has a custom endpoint, use it
        if (!empty($env['endpoint'])) {
            $custom_endpoint = $env['endpoint'];
            
            // Add https:// if not present
            if (strpos($custom_endpoint, 'http://') !== 0 && strpos($custom_endpoint, 'https://') !== 0) {
                $custom_endpoint = 'https://' . $custom_endpoint;
            }
            
            // For Groq, use the correct path structure
            // Groq's API: https://api.groq.com/openai/v1/chat/completions
            // AI Engine will append /responses or /chat/completions based on config
            if (strpos($custom_endpoint, 'groq.com') !== false) {
                $custom_endpoint = 'https://api.groq.com/openai/v1';
            } elseif (strpos($custom_endpoint, '/v1') === false) {
                // Add /v1 if not present for other OpenAI-compatible APIs
                $custom_endpoint = rtrim($custom_endpoint, '/') . '/openai/v1';
            }
            
            error_log('[RawWire AI Adapter] filter_openai_endpoint: Redirecting to ' . $custom_endpoint . ' for env: ' . ($env['name'] ?? 'unknown'));
            return $custom_endpoint;
        }
        
        return $endpoint;
    }

    /**
     * Late initialization after all plugins loaded
     */
    public function late_init() {
        $this->detect_ai_engine();
    }

    /**
     * Detect if AI Engine is installed and available
     */
    private function detect_ai_engine() {
        global $mwai, $mwai_core;

        // Check if AI Engine is active
        if (class_exists('Meow_MWAI_Core') || function_exists('mwai_init')) {
            $this->ai_engine_available = true;
            $this->mwai = $mwai;
            $this->mwai_core = $mwai_core;
        }

        // Check for Pro version
        if (class_exists('Meow_MWAI_Pro') || defined('MWAI_PRO_VERSION')) {
            $this->ai_engine_pro = true;
        }
    }

    /**
     * Load adapter settings from WordPress options
     */
    private function load_settings() {
        $settings = get_option('rawwire_ai_adapter_settings', []);
        
        $this->default_env_id = $settings['default_env_id'] ?? '';
        $this->default_model = $settings['default_model'] ?? '';
        $this->default_temperature = $settings['default_temperature'] ?? 0.7;
        $this->default_max_tokens = $settings['default_max_tokens'] ?? 2048;
        $this->cache_ttl = $settings['cache_ttl'] ?? 3600;
    }

    /**
     * Set default environment
     * 
     * @param string $env_id Environment ID
     * @return self
     */
    public function set_default_env($env_id) {
        $this->default_env_id = $env_id;
        return $this;
    }

    /**
     * Set default model
     * 
     * @param string $model Model name
     * @return self
     */
    public function set_default_model($model) {
        $this->default_model = $model;
        return $this;
    }

    /**
     * Configure for Groq (convenience method)
     * 
     * @param string $model Groq model name (default: llama-3.3-70b-versatile)
     * @return self
     */
    public function use_groq($model = 'llama-3.3-70b-versatile') {
        // Find Groq environment by type OR name containing 'groq'
        $envs = $this->get_available_environments();
        foreach ($envs as $env) {
            $type = strtolower($env['type'] ?? '');
            $name = strtolower($env['name'] ?? '');
            $endpoint = strtolower($env['endpoint'] ?? '');
            
            if ($type === 'groq' || 
                strpos($name, 'groq') !== false || 
                strpos($endpoint, 'groq') !== false) {
                $this->default_env_id = $env['id'];
                break;
            }
        }
        $this->default_model = $model;
        return $this;
    }

    /**
     * Configure for fast queries (Llama 3.1 8B on Groq)
     * 
     * @return self
     */
    public function use_fast() {
        return $this->use_groq('llama-3.1-8b-instant');
    }

    /**
     * Configure for best quality (Llama 3.3 70B on Groq)
     * 
     * @return self
     */
    public function use_quality() {
        return $this->use_groq('llama-3.3-70b-versatile');
    }

    /**
     * Build query options with defaults
     * 
     * @param array $params User-provided params
     * @return array Merged options
     */
    private function build_options($params = []) {
        $options = [];
        
        // Environment
        if (!empty($params['envId']) || !empty($params['env_id'])) {
            $options['envId'] = $params['envId'] ?? $params['env_id'];
        } elseif (!empty($this->default_env_id)) {
            $options['envId'] = $this->default_env_id;
        }
        
        // Model
        if (!empty($params['model'])) {
            $options['model'] = $params['model'];
        } elseif (!empty($this->default_model)) {
            $options['model'] = $this->default_model;
        }
        
        // Temperature
        if (isset($params['temperature'])) {
            $options['temperature'] = (float) $params['temperature'];
        } elseif ($this->default_temperature !== 0.7) {
            $options['temperature'] = $this->default_temperature;
        }
        
        // Max tokens
        if (isset($params['maxTokens']) || isset($params['max_tokens'])) {
            $options['maxTokens'] = (int) ($params['maxTokens'] ?? $params['max_tokens']);
        } elseif ($this->default_max_tokens !== 2048) {
            $options['maxTokens'] = $this->default_max_tokens;
        }
        
        // Pass through other params
        foreach (['instructions', 'context', 'scope'] as $key) {
            if (!empty($params[$key])) {
                $options[$key] = $params[$key];
            }
        }
        
        return $options;
    }

    /**
     * Check if AI Engine is available
     * 
     * @return bool
     */
    public function is_available() {
        return $this->ai_engine_available;
    }

    /**
     * Check if AI Engine Pro is available
     * 
     * @return bool
     */
    public function is_pro() {
        return $this->ai_engine_pro;
    }

    /**
     * Get AI Engine status information
     * 
     * @return array
     */
    public function get_status() {
        return [
            'available'     => $this->ai_engine_available,
            'pro'           => $this->ai_engine_pro,
            'version'       => defined('MWAI_VERSION') ? MWAI_VERSION : 'N/A',
            'environments'  => $this->get_available_environments(),
            'default_env'   => $this->default_env_id,
        ];
    }

    /**
     * Get available AI environments
     * 
     * @return array
     */
    public function get_available_environments() {
        if (!$this->ai_engine_available) {
            return [];
        }

        $environments = [];
        
        // Try to get environments from AI Engine settings
        $ai_settings = get_option('mwai_options', []);
        if (isset($ai_settings['ai_envs']) && is_array($ai_settings['ai_envs'])) {
            foreach ($ai_settings['ai_envs'] as $env) {
                $environments[] = [
                    'id'       => $env['id'] ?? '',
                    'name'     => $env['name'] ?? '',
                    'type'     => $env['type'] ?? '',
                    'model'    => $env['model'] ?? '',
                ];
            }
        }

        return $environments;
    }

    // =========================================================================
    // CORE QUERY METHODS
    // =========================================================================

    /**
     * Simple text query
     * 
     * @param string $prompt The prompt to send
     * @param array  $params Optional parameters:
     *                       - envId: Environment ID (e.g., 'groq')
     *                       - model: Model name (e.g., 'llama-3.3-70b-versatile')
     *                       - temperature: Creativity (0-2, default 0.7)
     *                       - maxTokens: Max response length
     *                       - instructions: System instructions
     * @return string|WP_Error
     */
    public function text_query($prompt, $params = []) {
        if (!$this->ai_engine_available) {
            return new WP_Error('ai_unavailable', 'AI Engine is not installed or activated.');
        }

        // Build options with defaults
        $options = $this->build_options($params);
        
        // Debug: Log what we're passing to AI Engine
        error_log('[RawWire AI Adapter] text_query options: ' . json_encode($options));
        error_log('[RawWire AI Adapter] default_env_id: ' . $this->default_env_id);

        // Check cache
        $cache_key = $this->get_cache_key($prompt, $options);
        if (isset($this->cache[$cache_key])) {
            return $this->cache[$cache_key];
        }

        try {
            global $mwai;
            
            if ($mwai && method_exists($mwai, 'simpleTextQuery')) {
                error_log('[RawWire AI Adapter] Calling mwai->simpleTextQuery with envId: ' . ($options['envId'] ?? 'not set'));
                $result = $mwai->simpleTextQuery($prompt, $options);
                
                // Cache result
                $this->cache[$cache_key] = $result;
                
                return $result;
            }

            // Fallback: use query class directly
            return $this->execute_query($prompt, self::QUERY_TEXT, $options);

        } catch (Exception $e) {
            return new WP_Error('ai_error', $e->getMessage());
        }
    }

    /**
     * JSON query - returns structured data
     * 
     * @param string $prompt The prompt describing desired JSON structure
     * @param array  $params Optional parameters (same as text_query)
     * @return array|WP_Error
     */
    public function json_query($prompt, $params = []) {
        if (!$this->ai_engine_available) {
            return new WP_Error('ai_unavailable', 'AI Engine is not installed or activated.');
        }

        // Build options with defaults
        $options = $this->build_options($params);

        try {
            // For non-OpenAI providers (like Groq), use text_query with JSON instruction
            // because simpleJsonQuery may use the Responses API which they don't support
            $env_id = $options['envId'] ?? $this->default_env_id;
            $envs = $this->get_available_environments();
            $use_text_fallback = false;
            
            foreach ($envs as $env) {
                if ($env['id'] === $env_id) {
                    // Check if this is a non-OpenAI provider (Groq, etc.)
                    $name = strtolower($env['name'] ?? '');
                    if (strpos($name, 'groq') !== false || 
                        strpos($name, 'llama') !== false ||
                        strpos($name, 'anthropic') !== false) {
                        $use_text_fallback = true;
                    }
                    break;
                }
            }

            if (!$use_text_fallback) {
                global $mwai;
                if ($mwai && method_exists($mwai, 'simpleJsonQuery')) {
                    $result = $mwai->simpleJsonQuery($prompt, null, null, $options);
                    return is_string($result) ? json_decode($result, true) : $result;
                }
            }

            // Fallback: text query with JSON instruction
            $json_prompt = $prompt . "\n\nRespond ONLY with valid JSON, no other text or markdown.";
            $result = $this->text_query($json_prompt, $options);
            
            if (is_wp_error($result)) {
                return $result;
            }

            // Clean up response - remove markdown code blocks if present
            $result = trim($result);
            if (strpos($result, '```json') === 0) {
                $result = substr($result, 7);
            } elseif (strpos($result, '```') === 0) {
                $result = substr($result, 3);
            }
            if (substr($result, -3) === '```') {
                $result = substr($result, 0, -3);
            }
            $result = trim($result);

            $decoded = json_decode($result, true);
            return $decoded !== null ? $decoded : new WP_Error('json_parse', 'Failed to parse JSON response: ' . $result);

        } catch (Exception $e) {
            return new WP_Error('ai_error', $e->getMessage());
        }
    }

    /**
     * Vision query - analyze images
     * 
     * @param string $prompt Description of what to analyze
     * @param string $image_url URL to the image
     * @param array  $params Optional parameters (same as text_query)
     * @return string|WP_Error
     */
    public function vision_query($prompt, $image_url, $params = []) {
        if (!$this->ai_engine_available) {
            return new WP_Error('ai_unavailable', 'AI Engine is not installed or activated.');
        }

        // Build options with defaults
        $options = $this->build_options($params);

        try {
            global $mwai;
            
            if ($mwai && method_exists($mwai, 'simpleVisionQuery')) {
                return $mwai->simpleVisionQuery($prompt, $image_url, null, $options);
            }

            return new WP_Error('vision_unavailable', 'Vision queries require AI Engine with vision support.');

        } catch (Exception $e) {
            return new WP_Error('ai_error', $e->getMessage());
        }
    }

    /**
     * Chatbot query - use a configured chatbot
     * 
     * @param string $bot_id   The chatbot ID
     * @param string $message  User message
     * @param array  $params   Optional parameters (same as text_query)
     * @return string|WP_Error
     */
    public function chatbot_query($bot_id, $message, $params = []) {
        if (!$this->ai_engine_available) {
            return new WP_Error('ai_unavailable', 'AI Engine is not installed or activated.');
        }

        // Build options with defaults
        $options = $this->build_options($params);

        try {
            global $mwai;
            
            if ($mwai && method_exists($mwai, 'simpleChatbotQuery')) {
                return $mwai->simpleChatbotQuery($bot_id, $message, $options, true);
            }

            return new WP_Error('chatbot_unavailable', 'Chatbot functionality requires AI Engine.');

        } catch (Exception $e) {
            return new WP_Error('ai_error', $e->getMessage());
        }
    }

    /**
     * Chatbot query with memory - maintains conversation context
     * 
     * Returns full response including chatId for follow-up queries.
     * 
     * @param string $bot_id   The chatbot ID (e.g., 'default')
     * @param string $message  User message
     * @param array  $params   Optional parameters:
     *                         - chatId: Previous conversation ID for context
     *                         - envId, model, temperature, maxTokens (same as text_query)
     * @return array|WP_Error  Returns ['reply' => string, 'chatId' => string] or WP_Error
     */
    public function chat($bot_id, $message, $params = []) {
        if (!$this->ai_engine_available) {
            return new WP_Error('ai_unavailable', 'AI Engine is not installed or activated.');
        }

        // Build options with defaults
        $options = $this->build_options($params);
        
        // Pass through chatId for conversation memory
        if (!empty($params['chatId'])) {
            $options['chatId'] = $params['chatId'];
        }

        try {
            global $mwai;
            
            if ($mwai && method_exists($mwai, 'simpleChatbotQuery')) {
                // Get full response (not just text)
                $result = $mwai->simpleChatbotQuery($bot_id, $message, $options, false);
                
                // Normalize response
                if (is_array($result)) {
                    return [
                        'reply'   => $result['reply'] ?? $result['result'] ?? '',
                        'chatId'  => $result['chatId'] ?? '',
                        'raw'     => $result,
                    ];
                }
                
                // String response (no chatId)
                return [
                    'reply'  => $result,
                    'chatId' => '',
                ];
            }

            return new WP_Error('chatbot_unavailable', 'Chatbot functionality requires AI Engine.');

        } catch (Exception $e) {
            return new WP_Error('ai_error', $e->getMessage());
        }
    }

    /**
     * Continue a conversation - convenience wrapper for chat()
     * 
     * @param string $chat_id  Previous conversation ID
     * @param string $message  Follow-up message
     * @param string $bot_id   The chatbot ID (default: 'default')
     * @return array|WP_Error
     */
    public function continue_chat($chat_id, $message, $bot_id = 'default') {
        return $this->chat($bot_id, $message, ['chatId' => $chat_id]);
    }

    /**
     * Start a new conversation
     * 
     * @param string $message  Initial message
     * @param string $bot_id   The chatbot ID (default: 'default')
     * @param array  $params   Optional parameters
     * @return array|WP_Error
     */
    public function start_chat($message, $bot_id = 'default', $params = []) {
        return $this->chat($bot_id, $message, $params);
    }

    /**
     * Execute a query using the query class directly (more control)
     * 
     * @param string $prompt     The prompt
     * @param string $query_type Query type constant
     * @param array  $params     Parameters
     * @return mixed
     */
    public function execute_query($prompt, $query_type = self::QUERY_TEXT, $params = []) {
        if (!$this->ai_engine_available) {
            return new WP_Error('ai_unavailable', 'AI Engine is not installed or activated.');
        }

        try {
            global $mwai_core;
            
            if (!$mwai_core || !class_exists('Meow_MWAI_Query_Text')) {
                return new WP_Error('ai_core_unavailable', 'AI Engine core not available.');
            }

            $query = new Meow_MWAI_Query_Text($prompt);

            // Set environment
            if (!empty($params['env_id'])) {
                $query->set_env_id($params['env_id']);
            } elseif (!empty($this->default_env_id)) {
                $query->set_env_id($this->default_env_id);
            }

            // Set model
            if (!empty($params['model'])) {
                $query->set_model($params['model']);
            }

            // Set temperature
            if (isset($params['temperature'])) {
                $query->set_temperature($params['temperature']);
            }

            // Set max tokens
            if (isset($params['max_tokens'])) {
                $query->set_max_tokens($params['max_tokens']);
            }

            // Set instructions/context
            if (!empty($params['instructions'])) {
                $query->set_instructions($params['instructions']);
            }

            // Execute
            $reply = $mwai_core->run_query($query);
            
            return $reply->result;

        } catch (Exception $e) {
            return new WP_Error('ai_error', $e->getMessage());
        }
    }

    /**
     * Moderation check
     * 
     * @param string $text Text to check
     * @return bool|WP_Error True if flagged as unsafe
     */
    public function moderation_check($text) {
        if (!$this->ai_engine_available) {
            return new WP_Error('ai_unavailable', 'AI Engine is not installed or activated.');
        }

        try {
            global $mwai;
            
            if ($mwai && method_exists($mwai, 'moderationCheck')) {
                return $mwai->moderationCheck($text);
            }

            return false; // Default to safe if check unavailable

        } catch (Exception $e) {
            return new WP_Error('ai_error', $e->getMessage());
        }
    }

    // =========================================================================
    // EMBEDDING METHODS
    // =========================================================================

    /**
     * Create embedding for text
     * 
     * Converts text into a vector representation for semantic search.
     * 
     * @param string $text   Text to embed
     * @param array  $params Optional parameters:
     *                       - dimensions: Vector dimensions (default: 1536)
     *                       - model: Embedding model
     * @return array|WP_Error Returns ['vector' => array, 'model' => string] or WP_Error
     */
    public function create_embedding($text, $params = []) {
        if (!$this->ai_engine_available) {
            return new WP_Error('ai_unavailable', 'AI Engine is not installed or activated.');
        }

        if (!class_exists('Meow_MWAI_Query_Embed')) {
            return new WP_Error('embeddings_unavailable', 'Embeddings require AI Engine Pro.');
        }

        try {
            global $mwai_core;
            
            $query = new Meow_MWAI_Query_Embed($text);
            
            // Set dimensions if provided
            if (!empty($params['dimensions'])) {
                $query->set_dimensions((int) $params['dimensions']);
            }
            
            // Set model if provided
            if (!empty($params['model'])) {
                $query->set_model($params['model']);
            }
            
            // Execute
            $reply = $mwai_core->run_query($query);
            
            return [
                'vector' => $reply->result,
                'model'  => $query->model,
                'dimensions' => count($reply->result),
            ];

        } catch (Exception $e) {
            return new WP_Error('ai_error', $e->getMessage());
        }
    }

    /**
     * Create embeddings for multiple texts (batch)
     * 
     * @param array $texts Array of texts to embed
     * @param array $params Optional parameters
     * @return array|WP_Error Returns array of embedding results
     */
    public function create_embeddings_batch($texts, $params = []) {
        $results = [];
        
        foreach ($texts as $key => $text) {
            $embedding = $this->create_embedding($text, $params);
            
            if (is_wp_error($embedding)) {
                $results[$key] = ['error' => $embedding->get_error_message()];
            } else {
                $results[$key] = $embedding;
            }
        }
        
        return $results;
    }

    /**
     * Calculate similarity between two texts using embeddings
     * 
     * @param string $text1 First text
     * @param string $text2 Second text
     * @return float|WP_Error Similarity score (0-1) or WP_Error
     */
    public function calculate_similarity($text1, $text2) {
        $embedding1 = $this->create_embedding($text1);
        if (is_wp_error($embedding1)) {
            return $embedding1;
        }
        
        $embedding2 = $this->create_embedding($text2);
        if (is_wp_error($embedding2)) {
            return $embedding2;
        }
        
        return $this->cosine_similarity($embedding1['vector'], $embedding2['vector']);
    }

    /**
     * Calculate cosine similarity between two vectors
     * 
     * @param array $vec1 First vector
     * @param array $vec2 Second vector
     * @return float Similarity score (0-1)
     */
    private function cosine_similarity($vec1, $vec2) {
        $dot_product = 0;
        $norm1 = 0;
        $norm2 = 0;
        
        $length = min(count($vec1), count($vec2));
        
        for ($i = 0; $i < $length; $i++) {
            $dot_product += $vec1[$i] * $vec2[$i];
            $norm1 += $vec1[$i] * $vec1[$i];
            $norm2 += $vec2[$i] * $vec2[$i];
        }
        
        if ($norm1 == 0 || $norm2 == 0) {
            return 0;
        }
        
        return $dot_product / (sqrt($norm1) * sqrt($norm2));
    }

    /**
     * Find most similar text from a collection
     * 
     * @param string $query   Query text
     * @param array  $corpus  Array of texts to search
     * @param int    $top_k   Number of results to return
     * @return array|WP_Error Top matches with similarity scores
     */
    public function semantic_search($query, $corpus, $top_k = 5) {
        // Get query embedding
        $query_embedding = $this->create_embedding($query);
        if (is_wp_error($query_embedding)) {
            return $query_embedding;
        }
        
        // Get embeddings for corpus
        $corpus_embeddings = [];
        foreach ($corpus as $key => $text) {
            $emb = $this->create_embedding($text);
            if (!is_wp_error($emb)) {
                $corpus_embeddings[$key] = $emb['vector'];
            }
        }
        
        // Calculate similarities
        $similarities = [];
        foreach ($corpus_embeddings as $key => $vector) {
            $similarities[$key] = [
                'text'       => $corpus[$key],
                'similarity' => $this->cosine_similarity($query_embedding['vector'], $vector),
            ];
        }
        
        // Sort by similarity
        usort($similarities, function($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });
        
        return array_slice($similarities, 0, $top_k);
    }

    /**
     * Create knowledge base embedding for the Raw Wire project
     * 
     * Embeds project documentation, goals, and vision for AI context.
     * 
     * @param array $documents Array of ['title' => string, 'content' => string]
     * @return array|WP_Error Embedded documents with vectors
     */
    public function embed_knowledge_base($documents) {
        $results = [];
        
        foreach ($documents as $doc) {
            $title = $doc['title'] ?? 'Untitled';
            $content = $doc['content'] ?? '';
            
            // Combine title and content for better embedding
            $text = "{$title}\n\n{$content}";
            
            $embedding = $this->create_embedding($text);
            
            if (is_wp_error($embedding)) {
                $results[] = [
                    'title'  => $title,
                    'error'  => $embedding->get_error_message(),
                ];
            } else {
                $results[] = [
                    'title'      => $title,
                    'vector'     => $embedding['vector'],
                    'dimensions' => $embedding['dimensions'],
                ];
            }
        }
        
        return $results;
    }

    /**
     * Search the Raw Wire knowledge base using semantic similarity
     * 
     * Queries the embedded documentation to find relevant context.
     * 
     * @param string $query  The search query
     * @param int    $top_k  Number of results to return
     * @param string $domain Optional domain filter (vision, architecture, api, workflow, features, ai, setup)
     * @return array|WP_Error Search results with content and similarity scores
     */
    public function search_knowledge_base($query, $top_k = 5, $domain = null) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'rawwire_knowledge_base';
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return new WP_Error('no_knowledge_base', 'Knowledge base not yet created. Run: wp eval-file scripts/embed-knowledge-base.php');
        }
        
        // Get query embedding
        $query_embedding = $this->create_embedding($query);
        if (is_wp_error($query_embedding)) {
            return $query_embedding;
        }
        
        // Build query
        $sql = "SELECT doc_id, domain, title, path, content, vector, priority FROM {$table_name}";
        if ($domain) {
            $sql .= $wpdb->prepare(" WHERE domain = %s", $domain);
        }
        
        $documents = $wpdb->get_results($sql, ARRAY_A);
        
        if (empty($documents)) {
            return new WP_Error('empty_knowledge_base', 'No documents in knowledge base');
        }
        
        // Calculate similarities
        $results = [];
        foreach ($documents as $doc) {
            $doc_vector = json_decode($doc['vector'], true);
            if (!is_array($doc_vector)) {
                continue;
            }
            
            $similarity = $this->cosine_similarity($query_embedding['vector'], $doc_vector);
            
            // Boost by priority (1-10 scale, max 10% boost)
            $priority_boost = ($doc['priority'] / 100);
            $similarity = min(1.0, $similarity + $priority_boost);
            
            $results[] = [
                'doc_id'     => $doc['doc_id'],
                'domain'     => $doc['domain'],
                'title'      => $doc['title'],
                'path'       => $doc['path'],
                'content'    => $doc['content'],
                'similarity' => $similarity,
                'priority'   => (int) $doc['priority'],
            ];
        }
        
        // Sort by similarity
        usort($results, function($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });
        
        // Return top results
        return array_slice($results, 0, $top_k);
    }

    /**
     * Get context from knowledge base for a given query
     * 
     * Helper method that returns just the content for use as AI context.
     * 
     * @param string $query The search query
     * @param int    $max_tokens Approximate max tokens to return
     * @return string|WP_Error Concatenated relevant content
     */
    public function get_knowledge_context($query, $max_tokens = 2000) {
        $results = $this->search_knowledge_base($query, 10);
        
        if (is_wp_error($results)) {
            return $results;
        }
        
        $context = "# Relevant Raw Wire Documentation\n\n";
        $char_count = strlen($context);
        $max_chars = $max_tokens * 4; // Rough token to char ratio
        
        foreach ($results as $result) {
            if ($result['similarity'] < 0.5) {
                continue; // Skip low relevance
            }
            
            $section = "## {$result['title']}\n";
            $section .= "(Domain: {$result['domain']}, Relevance: " . round($result['similarity'] * 100) . "%)\n\n";
            $section .= $result['content'] . "\n\n---\n\n";
            
            if ($char_count + strlen($section) > $max_chars) {
                break;
            }
            
            $context .= $section;
            $char_count += strlen($section);
        }
        
        return $context;
    }

    // =========================================================================
    // HIGH-LEVEL TOOL METHODS
    // =========================================================================

    /**
     * Score content for relevance and quality
     * 
     * @param string $content   Content to score
     * @param array  $criteria  Scoring criteria
     * @return array|WP_Error
     */
    public function score_content($content, $criteria = []) {
        $default_criteria = ['relevance', 'quality', 'seo', 'readability'];
        $criteria = array_merge($default_criteria, $criteria);
        
        $criteria_list = implode(', ', $criteria);
        
        $prompt = "Analyze the following content and score it on these criteria: {$criteria_list}. 
        For each criterion, provide a score from 1-10 and a brief explanation.
        
        Return as JSON with this structure:
        {
            \"scores\": {
                \"criterion_name\": {\"score\": X, \"explanation\": \"...\"}
            },
            \"overall_score\": X,
            \"summary\": \"Brief overall summary\",
            \"suggestions\": [\"improvement suggestion 1\", \"improvement suggestion 2\"]
        }
        
        Content to analyze:
        {$content}";

        return $this->json_query($prompt, ['temperature' => 0.3]);
    }

    /**
     * Generate article from topic/keywords
     * 
     * @param string $topic      Article topic
     * @param array  $options    Generation options
     * @return string|WP_Error
     */
    public function generate_article($topic, $options = []) {
        $word_count = $options['word_count'] ?? 800;
        $tone = $options['tone'] ?? 'professional';
        $keywords = $options['keywords'] ?? [];
        $format = $options['format'] ?? 'blog post';

        $keywords_text = !empty($keywords) ? "Include these keywords naturally: " . implode(', ', $keywords) . "." : "";

        $prompt = "Write a {$word_count}-word {$format} about: {$topic}

Tone: {$tone}
{$keywords_text}

Include:
- Engaging introduction
- Clear structure with headings
- Actionable insights
- Strong conclusion

Format the output with proper HTML headings (h2, h3) and paragraphs.";

        return $this->text_query($prompt, [
            'temperature' => 0.7,
            'max_tokens' => $word_count * 2,
        ]);
    }

    /**
     * Summarize content
     * 
     * @param string $content Content to summarize
     * @param int    $length  Target length in words
     * @return string|WP_Error
     */
    public function summarize($content, $length = 150) {
        $prompt = "Summarize the following content in approximately {$length} words. 
        Maintain the key points and main message.
        
        Content:
        {$content}";

        return $this->text_query($prompt, ['temperature' => 0.3]);
    }

    /**
     * Extract structured data from content
     * 
     * @param string $content Content to extract from
     * @param array  $fields  Fields to extract
     * @return array|WP_Error
     */
    public function extract_data($content, $fields = []) {
        $default_fields = ['title', 'summary', 'keywords', 'entities', 'sentiment'];
        $fields = array_merge($default_fields, $fields);
        
        $fields_list = implode(', ', $fields);
        
        $prompt = "Extract the following information from this content: {$fields_list}

Return as JSON with each field as a key.
For 'keywords', return an array of relevant keywords.
For 'entities', return an array of named entities (people, places, organizations).
For 'sentiment', return 'positive', 'negative', or 'neutral' with a confidence score.

Content:
{$content}";

        return $this->json_query($prompt, ['temperature' => 0.2]);
    }

    /**
     * Translate content
     * 
     * @param string $content        Content to translate
     * @param string $target_language Target language
     * @param string $source_language Source language (optional)
     * @return string|WP_Error
     */
    public function translate($content, $target_language, $source_language = null) {
        $source_text = $source_language ? "from {$source_language} " : "";
        
        $prompt = "Translate the following content {$source_text}to {$target_language}. 
        Maintain the original tone and meaning. Only return the translated text.
        
        Content:
        {$content}";

        return $this->text_query($prompt, ['temperature' => 0.2]);
    }

    /**
     * Classify content into categories
     * 
     * @param string $content    Content to classify
     * @param array  $categories Available categories
     * @return array|WP_Error
     */
    public function classify($content, $categories) {
        $categories_list = implode(', ', $categories);
        
        $prompt = "Classify the following content into one or more of these categories: {$categories_list}

Return as JSON:
{
    \"primary_category\": \"category name\",
    \"secondary_categories\": [\"category1\", \"category2\"],
    \"confidence\": 0.95,
    \"reasoning\": \"Brief explanation\"
}

Content:
{$content}";

        return $this->json_query($prompt, ['temperature' => 0.2]);
    }

    // =========================================================================
    // UTILITY METHODS
    // =========================================================================

    /**
     * Generate cache key for query
     * 
     * @param string $prompt
     * @param array  $params
     * @return string
     */
    private function get_cache_key($prompt, $params) {
        return md5($prompt . serialize($params));
    }

    /**
     * Clear cache
     * 
     * @param string|null $key Specific key to clear, or null for all
     */
    public function clear_cache($key = null) {
        if ($key === null) {
            $this->cache = [];
        } else {
            unset($this->cache[$key]);
        }
    }

    /**
     * Set default environment
     * 
     * @param string $env_id
     */
    public function set_default_environment($env_id) {
        $this->default_env_id = $env_id;
        
        $settings = get_option('rawwire_ai_adapter_settings', []);
        $settings['default_env_id'] = $env_id;
        update_option('rawwire_ai_adapter_settings', $settings);
    }

    /**
     * Get fallback message when AI is unavailable
     * 
     * @return string
     */
    public function get_unavailable_message() {
        return sprintf(
            __('AI features require the %s plugin. Please install and configure it to enable AI capabilities.', 'raw-wire-dashboard'),
            '<a href="https://wordpress.org/plugins/ai-engine/" target="_blank">AI Engine</a>'
        );
    }
}

/**
 * Get AI Adapter instance
 * 
 * @return RawWire_AI_Adapter
 */
function rawwire_ai() {
    return RawWire_AI_Adapter::get_instance();
}
