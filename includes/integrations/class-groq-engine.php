<?php
/**
 * Groq Engine for AI Engine
 * 
 * Adds Groq as a provider for AI Engine, enabling access to
 * Llama models with extremely fast inference (200-840 tokens/sec).
 *
 * @package RawWire\Dashboard\Integrations
 * @since 1.0.22
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register Groq engine with AI Engine
 */
add_action('plugins_loaded', function() {
    // Only proceed if AI Engine is active
    if (!class_exists('Meow_MWAI_Engines_ChatML')) {
        return;
    }

    // Define the Groq engine class
    if (!class_exists('Meow_MWAI_Engines_Groq')) {
        
        class Meow_MWAI_Engines_Groq extends Meow_MWAI_Engines_ChatML {
            
            public function __construct($core, $env) {
                parent::__construct($core, $env);
            }

            protected function set_environment() {
                $env = $this->env;
                $this->apiKey = $env['apikey'] ?? '';
            }

            protected function build_url($query, $endpoint = null) {
                $endpoint = apply_filters('mwai_groq_endpoint', 'https://api.groq.com/openai/v1', $this->env);
                return parent::build_url($query, $endpoint);
            }

            protected function build_headers($query) {
                if ($query->apiKey) {
                    $this->apiKey = $query->apiKey;
                }
                if (empty($this->apiKey)) {
                    throw new Exception('No Groq API Key provided. Please visit the Settings.');
                }
                return [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'User-Agent'    => 'AI Engine',
                ];
            }

            protected function get_service_name() {
                return 'Groq';
            }

            public function get_models() {
                // Return Groq's available models
                return apply_filters('mwai_groq_models', [
                    // Llama 3.3 - Best quality
                    [
                        'model'    => 'llama-3.3-70b-versatile',
                        'name'     => 'Llama 3.3 70B Versatile',
                        'family'   => 'llama',
                        'features' => ['chat', 'json'],
                        'price'    => ['in' => 0, 'out' => 0], // Free tier
                        'context'  => 128000,
                        'tags'     => ['chat', 'free'],
                    ],
                    // Llama 3.1 - Fast
                    [
                        'model'    => 'llama-3.1-8b-instant',
                        'name'     => 'Llama 3.1 8B Instant',
                        'family'   => 'llama',
                        'features' => ['chat', 'json'],
                        'price'    => ['in' => 0, 'out' => 0],
                        'context'  => 128000,
                        'tags'     => ['chat', 'free', 'fast'],
                    ],
                    // Llama 3.2 Vision
                    [
                        'model'    => 'llama-3.2-11b-vision-preview',
                        'name'     => 'Llama 3.2 11B Vision',
                        'family'   => 'llama',
                        'features' => ['chat', 'vision'],
                        'price'    => ['in' => 0, 'out' => 0],
                        'context'  => 128000,
                        'tags'     => ['chat', 'vision', 'free'],
                    ],
                    // Llama 3.2 90B Vision  
                    [
                        'model'    => 'llama-3.2-90b-vision-preview',
                        'name'     => 'Llama 3.2 90B Vision',
                        'family'   => 'llama',
                        'features' => ['chat', 'vision'],
                        'price'    => ['in' => 0, 'out' => 0],
                        'context'  => 128000,
                        'tags'     => ['chat', 'vision', 'free'],
                    ],
                    // Mixtral
                    [
                        'model'    => 'mixtral-8x7b-32768',
                        'name'     => 'Mixtral 8x7B',
                        'family'   => 'mixtral',
                        'features' => ['chat'],
                        'price'    => ['in' => 0, 'out' => 0],
                        'context'  => 32768,
                        'tags'     => ['chat', 'free'],
                    ],
                    // Gemma 2
                    [
                        'model'    => 'gemma2-9b-it',
                        'name'     => 'Gemma 2 9B',
                        'family'   => 'gemma',
                        'features' => ['chat'],
                        'price'    => ['in' => 0, 'out' => 0],
                        'context'  => 8192,
                        'tags'     => ['chat', 'free'],
                    ],
                ]);
            }
        }
    }

    // Register Groq as an available engine
    add_filter('mwai_engines_list', function($engines) {
        $engines['groq'] = [
            'name'        => 'Groq',
            'type'        => 'groq',
            'class'       => 'Meow_MWAI_Engines_Groq',
            'description' => 'Ultra-fast Llama inference (200-840 tok/sec)',
            'icon'        => 'dashicons-superhero',
        ];
        return $engines;
    });

    // Register Groq models with AI Engine's model list
    add_filter('mwai_engines_models', function($models, $engine = null) {
        if ($engine === 'groq' || $engine === null) {
            $groq_models = [
                [
                    'model'    => 'llama-3.3-70b-versatile',
                    'name'     => 'Llama 3.3 70B Versatile',
                    'family'   => 'llama',
                    'features' => ['chat', 'json'],
                    'price'    => ['in' => 0, 'out' => 0],
                    'context'  => 128000,
                    'tags'     => ['chat', 'free'],
                ],
                [
                    'model'    => 'llama-3.1-8b-instant',
                    'name'     => 'Llama 3.1 8B Instant',
                    'family'   => 'llama',
                    'features' => ['chat', 'json'],
                    'price'    => ['in' => 0, 'out' => 0],
                    'context'  => 128000,
                    'tags'     => ['chat', 'free', 'fast'],
                ],
                [
                    'model'    => 'llama-3.2-11b-vision-preview',
                    'name'     => 'Llama 3.2 11B Vision',
                    'family'   => 'llama',
                    'features' => ['chat', 'vision'],
                    'price'    => ['in' => 0, 'out' => 0],
                    'context'  => 128000,
                    'tags'     => ['chat', 'vision', 'free'],
                ],
                [
                    'model'    => 'mixtral-8x7b-32768',
                    'name'     => 'Mixtral 8x7B',
                    'family'   => 'mixtral',
                    'features' => ['chat'],
                    'price'    => ['in' => 0, 'out' => 0],
                    'context'  => 32768,
                    'tags'     => ['chat', 'free'],
                ],
            ];
            
            foreach ($groq_models as $model) {
                $models[] = $model;
            }
        }
        return $models;
    }, 10, 2);

    // Add Groq to the environment types
    add_filter('mwai_environments_types', function($types) {
        $types['groq'] = [
            'name'     => 'Groq',
            'endpoint' => 'https://api.groq.com/openai/v1',
            'check'    => false,
        ];
        return $types;
    });

}, 15);

/**
 * Helper to check if Groq is configured
 */
function rawwire_groq_is_configured() {
    $options = get_option('mwai_options', []);
    $envs = $options['ai_envs'] ?? [];
    
    foreach ($envs as $env) {
        if (($env['type'] ?? '') === 'groq' && !empty($env['apikey'])) {
            return true;
        }
    }
    
    // Also check wp-config constant
    return defined('GROQ_API_KEY') && !empty(GROQ_API_KEY);
}

/**
 * Get Groq API key from config or options
 */
function rawwire_get_groq_key() {
    // Check wp-config first
    if (defined('GROQ_API_KEY') && !empty(GROQ_API_KEY)) {
        return GROQ_API_KEY;
    }
    
    // Check AI Engine options
    $options = get_option('mwai_options', []);
    $envs = $options['ai_envs'] ?? [];
    
    foreach ($envs as $env) {
        if (($env['type'] ?? '') === 'groq' && !empty($env['apikey'])) {
            return $env['apikey'];
        }
    }
    
    return '';
}
