<?php
/**
 * Ollama Engine for AI Engine (Durable Integration)
 * 
 * Provides local LLM support via Ollama using OpenAI-compatible API.
 * This integration lives in Raw Wire Dashboard and uses filter hooks
 * to extend AI Engine - no direct modifications to AI Engine files.
 * 
 * DURABILITY:
 * - Survives AI Engine updates (uses filter hooks only)
 * - If Raw Wire is uninstalled, AI Engine works normally (Ollama just unavailable)
 * - No file patches or injections needed
 * - Configurable via AI Settings → Custom Engine Extensions
 *
 * @package RawWire\Dashboard\Integrations
 * @since 1.0.26
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register Ollama engine with AI Engine on plugins_loaded
 * 
 * NOTE: If this causes AI Engine crashes, disable via:
 * - Settings → AI Settings → Custom Engine Extensions → Ollama: Disabled
 * - Or define RAWWIRE_DISABLE_OLLAMA in wp-config.php
 */
add_action('plugins_loaded', function() {
    // Allow disabling via constant
    if (defined('RAWWIRE_DISABLE_OLLAMA') && RAWWIRE_DISABLE_OLLAMA) {
        return;
    }
    // Check if extension is enabled in settings
    $engine_settings = get_option('rawwire_engine_extensions', []);
    if (isset($engine_settings['ollama_enabled']) && !$engine_settings['ollama_enabled']) {
        return; // Extension disabled in settings
    }

    // Only proceed if AI Engine is active and ChatML class exists
    if (!class_exists('Meow_MWAI_Engines_ChatML')) {
        return;
    }

    // Get endpoint from settings
    $ollama_endpoint = $engine_settings['ollama_endpoint'] ?? 'http://ollama:11434';
    $dynamic_models = $engine_settings['ollama_dynamic_models'] ?? true;

    // Define the Ollama engine class
    if (!class_exists('Meow_MWAI_Engines_Ollama')) {
        
        class Meow_MWAI_Engines_Ollama extends Meow_MWAI_Engines_ChatML {
            
            protected $endpoint = null;
            protected $ollamaModels = [];

            public function __construct($core, $env) {
                parent::__construct($core, $env);
                $this->envType = 'ollama';
                
                // Set endpoint - use Docker internal network by default
                $this->endpoint = isset($env['endpoint']) ? rtrim($env['endpoint'], '/') : 'http://ollama:11434';
                
                // Load dynamic models if enabled
                if (!empty($env['dynamicModels'])) {
                    $this->load_dynamic_models();
                } elseif (!empty($env['models'])) {
                    $this->ollamaModels = $env['models'];
                }
            }

            protected function set_environment() {
                $env = $this->env;
                $this->apiKey = $env['apikey'] ?? 'ollama'; // Ollama doesn't need real API key
            }

            /**
             * Fetch available models from Ollama API
             */
            protected function load_dynamic_models() {
                $cached = get_transient('rawwire_ollama_models');
                if ($cached !== false) {
                    $this->ollamaModels = $cached;
                    return;
                }

                try {
                    $response = wp_remote_get($this->endpoint . '/api/tags', [
                        'timeout' => 10,
                        'headers' => ['Content-Type' => 'application/json'],
                    ]);

                    if (is_wp_error($response)) {
                        return;
                    }

                    $body = json_decode(wp_remote_retrieve_body($response), true);
                    
                    if (!empty($body['models'])) {
                        $this->ollamaModels = [];
                        foreach ($body['models'] as $model) {
                            $this->ollamaModels[] = [
                                'model' => $model['name'],
                                'name' => $model['name'],
                                'family' => 'ollama',
                                'features' => ['chat'],
                                'tags' => ['local', 'free'],
                            ];
                        }
                        // Cache for 5 minutes
                        set_transient('rawwire_ollama_models', $this->ollamaModels, 300);
                    }
                } catch (Exception $e) {
                    error_log('Ollama model fetch failed: ' . $e->getMessage());
                }
            }

            /**
             * Get list of available models
             */
            public function get_models() {
                return apply_filters('mwai_ollama_models', $this->ollamaModels);
            }

            /**
             * Retrieve models from Ollama API - called by "Refresh Models" button
             * This is the CRITICAL method that AI Engine calls to populate model dropdowns
             */
            public function retrieve_models() {
                // Clear the cache first
                delete_transient('rawwire_ollama_models');
                
                $response = wp_remote_get($this->endpoint . '/api/tags', [
                    'timeout' => 15,
                    'headers' => ['Content-Type' => 'application/json'],
                ]);

                if (is_wp_error($response)) {
                    throw new Exception('Failed to connect to Ollama: ' . $response->get_error_message());
                }

                $code = wp_remote_retrieve_response_code($response);
                if ($code !== 200) {
                    throw new Exception('Ollama API returned status ' . $code);
                }

                $body = json_decode(wp_remote_retrieve_body($response), true);
                
                if (!isset($body['models']) || !is_array($body['models'])) {
                    throw new Exception('Invalid response from Ollama API');
                }

                $models = [];
                foreach ($body['models'] as $model) {
                    // Determine features based on model name
                    $features = ['chat'];
                    $tags = ['local', 'free'];
                    
                    // Code models
                    if (stripos($model['name'], 'code') !== false || stripos($model['name'], 'coder') !== false) {
                        $features[] = 'code';
                        $tags[] = 'code';
                    }
                    
                    // Vision models
                    if (stripos($model['name'], 'vision') !== false || stripos($model['name'], 'llava') !== false) {
                        $features[] = 'vision';
                        $tags[] = 'vision';
                    }

                    $models[] = [
                        'model'    => $model['name'],
                        'name'     => $model['name'],
                        'family'   => 'ollama',
                        'features' => $features,
                        'tags'     => $tags,
                        'type'     => 'ollama',
                        'mode'     => 'chat',
                    ];
                }

                // Cache the models
                set_transient('rawwire_ollama_models', $models, 300);
                $this->ollamaModels = $models;

                return $models;
            }

            /**
             * Get the service name for logging
             */
            protected function get_service_name() {
                return 'Ollama';
            }

            /**
             * Build headers for Ollama requests
             */
            protected function build_headers($query) {
                return [
                    'Content-Type' => 'application/json',
                ];
            }

            /**
             * Build the API URL - use OpenAI-compatible endpoint
             */
            protected function build_url($query, $endpoint = null) {
                $endpoint = apply_filters('mwai_ollama_endpoint', $this->endpoint . '/v1', $this->env);
                return $endpoint . '/chat/completions';
            }
        }
    }

    // Register Ollama as an available engine type in the admin UI
    // This is the CRITICAL filter that makes the engine show up in dropdowns
    // IMPORTANT: We need to populate models HERE because AI Engine doesn't use a 
    // generic filter for custom engine types - it reads models from the engine config
    add_filter('mwai_engines', function($engines) {
        // Fetch Ollama models from cache or API
        $ollama_models = get_transient('rawwire_ollama_models');
        
        if ($ollama_models === false) {
            // Try to fetch from Ollama
            $endpoint = get_option('rawwire_ollama_host', 'http://ollama:11434');
            $response = wp_remote_get($endpoint . '/api/tags', ['timeout' => 5]);
            
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                $ollama_models = [];
                foreach ($body['models'] ?? [] as $model) {
                    $ollama_models[] = [
                        'model'    => $model['name'],
                        'name'     => $model['name'],
                        'family'   => 'ollama',
                        'features' => ['chat'],
                        'tags'     => ['local', 'free'],
                        'type'     => 'ollama',
                    ];
                }
                // Cache for 5 minutes
                set_transient('rawwire_ollama_models', $ollama_models, 300);
            } else {
                // Fallback to common models
                $ollama_models = [
                    ['model' => 'llama3.2:latest', 'name' => 'Llama 3.2', 'family' => 'llama', 'features' => ['chat'], 'tags' => ['local'], 'type' => 'ollama'],
                    ['model' => 'llama3.1:8b', 'name' => 'Llama 3.1 8B', 'family' => 'llama', 'features' => ['chat'], 'tags' => ['local'], 'type' => 'ollama'],
                    ['model' => 'qwen2.5-coder:14b', 'name' => 'Qwen 2.5 Coder 14B', 'family' => 'qwen', 'features' => ['chat', 'code'], 'tags' => ['local', 'code'], 'type' => 'ollama'],
                ];
            }
        }

        $engines[] = [
            'name'     => 'Ollama (Local)',
            'type'     => 'ollama',
            'inputs'   => ['endpoint', 'dynamicModels'],
            'internal' => false,
            'models'   => $ollama_models,  // Populate models directly!
        ];
        return $engines;
    }, 10);

    // Register Ollama models with AI Engine's model list
    add_filter('mwai_engines_models', function($models, $engine = null) {
        if ($engine === 'ollama' || $engine === null) {
            // Try to get cached models
            $ollama_models = get_transient('rawwire_ollama_models');
            if ($ollama_models === false) {
                // Default fallback models
                $ollama_models = [
                    [
                        'model'    => 'qwen2.5-coder:14b',
                        'name'     => 'Qwen 2.5 Coder 14B',
                        'family'   => 'qwen',
                        'features' => ['chat', 'code'],
                        'tags'     => ['local', 'free', 'code'],
                    ],
                    [
                        'model'    => 'llama3.1:8b',
                        'name'     => 'Llama 3.1 8B',
                        'family'   => 'llama',
                        'features' => ['chat'],
                        'tags'     => ['local', 'free'],
                    ],
                    [
                        'model'    => 'llama3.2:latest',
                        'name'     => 'Llama 3.2',
                        'family'   => 'llama',
                        'features' => ['chat'],
                        'tags'     => ['local', 'free'],
                    ],
                ];
            }
            
            foreach ($ollama_models as $model) {
                $models[] = $model;
            }
        }
        return $models;
    }, 10, 2);

    // Add Ollama to the environment types
    add_filter('mwai_environments_types', function($types) {
        $types['ollama'] = [
            'name'     => 'Ollama (Local)',
            'endpoint' => 'http://ollama:11434',
            'check'    => false,
        ];
        return $types;
    });

    // Hook into engine factory to create Ollama engine instances
    // This is the KEY hook that makes it work without modifying AI Engine
    add_filter('mwai_init_engine', function($engine, $core, $env) {
        if ($engine !== null) {
            return $engine; // Already handled by another provider
        }
        
        if (($env['type'] ?? '') === 'ollama') {
            if (class_exists('Meow_MWAI_Engines_Ollama')) {
                return new Meow_MWAI_Engines_Ollama($core, $env);
            }
        }
        
        return $engine;
    }, 10, 3);

    // CRITICAL: Inject Ollama models into the mwai_options when it's read
    // This populates $env['models'] which AI Engine uses for custom engine dropdowns
    add_filter('option_mwai_options', function($options) {
        if (!is_array($options) || empty($options['ai_envs'])) {
            return $options;
        }

        // Get Ollama models from cache or fetch them
        $ollama_models = get_transient('rawwire_ollama_models');
        
        if ($ollama_models === false) {
            // Try to fetch from Ollama
            $endpoint = get_option('rawwire_ollama_host', 'http://ollama:11434');
            $response = wp_remote_get($endpoint . '/api/tags', ['timeout' => 5]);
            
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                $ollama_models = [];
                foreach ($body['models'] ?? [] as $model) {
                    $ollama_models[] = [
                        'model'    => $model['name'],
                        'name'     => $model['name'],
                        'family'   => 'ollama',
                        'features' => ['chat'],
                        'tags'     => ['local', 'free'],
                        'type'     => 'ollama',
                    ];
                }
                // Cache for 5 minutes
                set_transient('rawwire_ollama_models', $ollama_models, 300);
            } else {
                // Fallback models
                $ollama_models = [
                    ['model' => 'llama3.2:latest', 'name' => 'Llama 3.2', 'family' => 'llama', 'features' => ['chat'], 'tags' => ['local'], 'type' => 'ollama'],
                    ['model' => 'llama3.1:8b', 'name' => 'Llama 3.1 8B', 'family' => 'llama', 'features' => ['chat'], 'tags' => ['local'], 'type' => 'ollama'],
                    ['model' => 'qwen2.5-coder:14b', 'name' => 'Qwen 2.5 Coder 14B', 'family' => 'qwen', 'features' => ['chat', 'code'], 'tags' => ['local', 'code'], 'type' => 'ollama'],
                ];
            }
        }

        // Inject models into each Ollama environment
        foreach ($options['ai_envs'] as &$env) {
            if (($env['type'] ?? '') === 'ollama') {
                $env['models'] = $ollama_models;
            }
        }
        unset($env); // Break reference

        return $options;
    }, 10);

}, 15); // Priority 15 to load after AI Engine

/**
 * Helper to check if Ollama is available
 */
function rawwire_ollama_is_available() {
    $endpoint = get_option('rawwire_ollama_host', 'http://ollama:11434');
    
    $response = wp_remote_get($endpoint . '/api/tags', [
        'timeout' => 5,
    ]);
    
    return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
}

/**
 * Get available Ollama models
 */
function rawwire_get_ollama_models() {
    $endpoint = get_option('rawwire_ollama_host', 'http://ollama:11434');
    
    $response = wp_remote_get($endpoint . '/api/tags', [
        'timeout' => 10,
    ]);
    
    if (is_wp_error($response)) {
        return [];
    }
    
    $body = json_decode(wp_remote_retrieve_body($response), true);
    return $body['models'] ?? [];
}

/**
 * Clear Ollama models cache
 */
function rawwire_clear_ollama_cache() {
    delete_transient('rawwire_ollama_models');
}
