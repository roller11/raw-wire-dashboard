<?php
/**
 * Data Source Registry
 * 
 * Manages registration and resolution of data sources for panels.
 * Data sources define WHERE data comes from (database, API, module, etc.)
 *
 * @package RawWire_Dashboard
 * @subpackage Template_Engine
 * @since 1.0.30
 */

if (!defined('ABSPATH')) {
    exit;
}

class RawWire_Data_Source_Registry {
    
    /**
     * Singleton instance
     * @var RawWire_Data_Source_Registry
     */
    private static $instance = null;
    
    /**
     * Registered data sources
     * @var array
     */
    private $sources = [];
    
    /**
     * Registered source providers (prefixes)
     * @var array
     */
    private $providers = [];
    
    /**
     * Data cache
     * @var array
     */
    private $cache = [];
    
    /**
     * Get singleton instance
     * @return RawWire_Data_Source_Registry
     */
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->register_builtin_providers();
        $this->register_builtin_sources();
        
        // Allow modules to register sources
        do_action('rawwire_register_data_sources', $this);
    }
    
    /**
     * Register a data source provider (handles a prefix like "scraper:", "module:", etc.)
     *
     * @param string $prefix Provider prefix (e.g., "scraper", "module")
     * @param callable $resolver Callback to resolve data for this provider
     * @param array $config Provider configuration
     * @return bool
     */
    public function register_provider($prefix, callable $resolver, array $config = []) {
        $defaults = [
            'label'       => ucfirst($prefix),
            'description' => '',
            'cacheable'   => true,
            'cache_ttl'   => 300, // 5 minutes default
        ];
        
        $this->providers[$prefix] = [
            'resolver' => $resolver,
            'config'   => wp_parse_args($config, $defaults),
        ];
        
        return true;
    }
    
    /**
     * Register a specific data source
     *
     * @param string $source_id Unique source identifier (e.g., "scraper:sources", "leads:all")
     * @param callable|array $config Either a callback or configuration array
     * @return bool
     */
    public function register($source_id, $config) {
        if (is_callable($config)) {
            $config = ['callback' => $config];
        }
        
        $defaults = [
            'label'       => $source_id,
            'description' => '',
            'callback'    => null,
            'params'      => [],
            'cacheable'   => true,
            'cache_ttl'   => 300,
            'schema'      => [], // Expected data structure
        ];
        
        $this->sources[$source_id] = wp_parse_args($config, $defaults);
        
        return true;
    }
    
    /**
     * Get data from a source
     *
     * @param string $source_id Source identifier
     * @param array $params Optional parameters
     * @param bool $force_fresh Skip cache
     * @return mixed
     */
    public function get_data($source_id, array $params = [], $force_fresh = false) {
        // Check cache first
        $cache_key = $this->build_cache_key($source_id, $params);
        
        if (!$force_fresh && isset($this->cache[$cache_key])) {
            $cached = $this->cache[$cache_key];
            if ($cached['expires'] > time()) {
                return $cached['data'];
            }
        }
        
        // Resolve the data
        $data = $this->resolve($source_id, $params);
        
        // Cache the result
        $source = $this->get_source_config($source_id);
        if ($source && $source['cacheable']) {
            $this->cache[$cache_key] = [
                'data'    => $data,
                'expires' => time() + $source['cache_ttl'],
            ];
        }
        
        return $data;
    }
    
    /**
     * Resolve data from a source
     *
     * @param string $source_id
     * @param array $params
     * @return mixed
     */
    private function resolve($source_id, array $params = []) {
        // Check if it's a registered source
        if (isset($this->sources[$source_id])) {
            $source = $this->sources[$source_id];
            if (is_callable($source['callback'])) {
                return call_user_func($source['callback'], $params);
            }
        }
        
        // Try to resolve via provider prefix
        if (strpos($source_id, ':') !== false) {
            list($prefix, $path) = explode(':', $source_id, 2);
            
            if (isset($this->providers[$prefix])) {
                $provider = $this->providers[$prefix];
                return call_user_func($provider['resolver'], $path, $params);
            }
        }
        
        // Apply filter for custom resolution
        $data = apply_filters('rawwire_resolve_data_source', null, $source_id, $params);
        
        if ($data !== null) {
            return $data;
        }
        
        // Return empty array if source not found
        error_log("RawWire: Unknown data source: {$source_id}");
        return [];
    }
    
    /**
     * Get source configuration
     *
     * @param string $source_id
     * @return array|null
     */
    public function get_source_config($source_id) {
        if (isset($this->sources[$source_id])) {
            return $this->sources[$source_id];
        }
        
        // Check provider
        if (strpos($source_id, ':') !== false) {
            list($prefix, $path) = explode(':', $source_id, 2);
            if (isset($this->providers[$prefix])) {
                return $this->providers[$prefix]['config'];
            }
        }
        
        return null;
    }
    
    /**
     * Get all registered sources
     *
     * @return array
     */
    public function get_all_sources() {
        return $this->sources;
    }
    
    /**
     * Get all registered providers
     *
     * @return array
     */
    public function get_all_providers() {
        return $this->providers;
    }
    
    /**
     * Build cache key
     *
     * @param string $source_id
     * @param array $params
     * @return string
     */
    private function build_cache_key($source_id, array $params) {
        return 'rawwire_ds_' . md5($source_id . serialize($params));
    }
    
    /**
     * Clear cache for a source
     *
     * @param string|null $source_id Specific source or null for all
     */
    public function clear_cache($source_id = null) {
        if ($source_id === null) {
            $this->cache = [];
            return;
        }
        
        foreach ($this->cache as $key => $data) {
            if (strpos($key, md5($source_id)) !== false) {
                unset($this->cache[$key]);
            }
        }
    }
    
    /**
     * Register built-in providers
     */
    private function register_builtin_providers() {
        // Module provider - calls module methods
        $this->register_provider('module', function($path, $params) {
            // Format: module:module_name/method_name
            $parts = explode('/', $path);
            $module_name = $parts[0] ?? '';
            $method = $parts[1] ?? 'get_data';
            
            // Try to get module instance
            if (class_exists('RawWire_Module_Core')) {
                $module = RawWire_Module_Core::get_module($module_name);
                if ($module && method_exists($module, $method)) {
                    return $module->$method($params);
                }
            }
            
            return [];
        }, [
            'label' => 'Module Data',
            'description' => 'Data from registered modules',
        ]);
        
        // Scraper provider
        $this->register_provider('scraper', function($path, $params) {
            if (!class_exists('RawWire_Scraper_Settings')) {
                return [];
            }
            
            switch ($path) {
                case 'sources':
                    return RawWire_Scraper_Settings::get_sources();
                case 'rules':
                    return RawWire_Scraper_Settings::get_rules();
                case 'stats':
                    return RawWire_Scraper_Settings::get_stats();
                default:
                    return [];
            }
        }, [
            'label' => 'Scraper Data',
            'description' => 'Content scraper sources and data',
        ]);
        
        // Content provider
        $this->register_provider('content', function($path, $params) {
            global $wpdb;
            $table = $wpdb->prefix . 'rawwire_content';
            
            switch ($path) {
                case 'all':
                    $limit = $params['limit'] ?? 50;
                    return $wpdb->get_results(
                        $wpdb->prepare("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d", $limit),
                        ARRAY_A
                    );
                case 'pending':
                    return $wpdb->get_results(
                        "SELECT * FROM {$table} WHERE status = 'pending' ORDER BY created_at DESC",
                        ARRAY_A
                    );
                case 'approved':
                    return $wpdb->get_results(
                        "SELECT * FROM {$table} WHERE status = 'approved' ORDER BY created_at DESC",
                        ARRAY_A
                    );
                case 'stats':
                    return [
                        'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
                        'pending' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'pending'"),
                        'approved' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'approved'"),
                        'rejected' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'rejected'"),
                    ];
                default:
                    return [];
            }
        }, [
            'label' => 'Content Data',
            'description' => 'Aggregated content items',
        ]);
        
        // WordPress provider
        $this->register_provider('wp', function($path, $params) {
            switch ($path) {
                case 'posts':
                    $args = wp_parse_args($params, [
                        'post_type'      => 'post',
                        'posts_per_page' => 10,
                        'post_status'    => 'publish',
                    ]);
                    $query = new WP_Query($args);
                    return array_map(function($post) {
                        return [
                            'id'       => $post->ID,
                            'title'    => $post->post_title,
                            'content'  => $post->post_content,
                            'excerpt'  => $post->post_excerpt,
                            'date'     => $post->post_date,
                            'status'   => $post->post_status,
                            'author'   => get_the_author_meta('display_name', $post->post_author),
                            'url'      => get_permalink($post),
                            'thumbnail'=> get_the_post_thumbnail_url($post, 'medium'),
                        ];
                    }, $query->posts);
                    
                case 'users':
                    $args = wp_parse_args($params, ['number' => 50]);
                    $users = get_users($args);
                    return array_map(function($user) {
                        return [
                            'id'      => $user->ID,
                            'name'    => $user->display_name,
                            'email'   => $user->user_email,
                            'role'    => implode(', ', $user->roles),
                            'avatar'  => get_avatar_url($user->ID),
                        ];
                    }, $users);
                    
                case 'options':
                    $option = $params['option'] ?? '';
                    return get_option($option, $params['default'] ?? null);
                    
                default:
                    return [];
            }
        }, [
            'label' => 'WordPress Data',
            'description' => 'WordPress core data (posts, users, etc.)',
        ]);
        
        // API provider - for external API calls
        $this->register_provider('api', function($path, $params) {
            // Get API configuration
            $apis = get_option('rawwire_external_apis', []);
            
            if (!isset($apis[$path])) {
                return [];
            }
            
            $api = $apis[$path];
            $url = $api['endpoint'] ?? '';
            $method = $api['method'] ?? 'GET';
            $headers = $api['headers'] ?? [];
            
            // Add any query params
            if (!empty($params) && $method === 'GET') {
                $url = add_query_arg($params, $url);
            }
            
            $response = wp_remote_request($url, [
                'method'  => $method,
                'headers' => $headers,
                'body'    => $method !== 'GET' ? $params : null,
                'timeout' => 30,
            ]);
            
            if (is_wp_error($response)) {
                error_log("RawWire API Error ({$path}): " . $response->get_error_message());
                return [];
            }
            
            return json_decode(wp_remote_retrieve_body($response), true) ?: [];
        }, [
            'label' => 'External API',
            'description' => 'Data from configured external APIs',
            'cacheable' => true,
            'cache_ttl' => 600, // 10 minutes
        ]);
        
        // Static provider - for predefined data
        $this->register_provider('static', function($path, $params) {
            $static_data = apply_filters('rawwire_static_data', [
                'industries' => [
                    ['value' => 'interior-design', 'label' => 'Interior Design / Architecture'],
                    ['value' => 'real-estate', 'label' => 'Real Estate'],
                    ['value' => 'consulting', 'label' => 'Consulting'],
                    ['value' => 'e-commerce', 'label' => 'E-Commerce'],
                    ['value' => 'news-media', 'label' => 'News / Media'],
                    ['value' => 'healthcare', 'label' => 'Healthcare'],
                    ['value' => 'legal', 'label' => 'Legal Services'],
                    ['value' => 'finance', 'label' => 'Finance'],
                ],
                'statuses' => [
                    ['value' => 'pending', 'label' => 'Pending', 'color' => '#dba617'],
                    ['value' => 'approved', 'label' => 'Approved', 'color' => '#00a32a'],
                    ['value' => 'rejected', 'label' => 'Rejected', 'color' => '#d63638'],
                    ['value' => 'draft', 'label' => 'Draft', 'color' => '#666'],
                ],
                'priorities' => [
                    ['value' => 'low', 'label' => 'Low', 'color' => '#72aee6'],
                    ['value' => 'medium', 'label' => 'Medium', 'color' => '#dba617'],
                    ['value' => 'high', 'label' => 'High', 'color' => '#d63638'],
                ],
            ]);
            
            return $static_data[$path] ?? [];
        }, [
            'label' => 'Static Data',
            'description' => 'Predefined static data lists',
            'cacheable' => false,
        ]);
    }
    
    /**
     * Register built-in specific sources
     */
    private function register_builtin_sources() {
        // Quick access sources that don't fit the provider pattern
        
        $this->register('dashboard:stats', [
            'label' => 'Dashboard Statistics',
            'callback' => function($params) {
                return [
                    'content_total'    => rawwire_data_sources()->get_data('content:stats')['total'] ?? 0,
                    'content_pending'  => rawwire_data_sources()->get_data('content:stats')['pending'] ?? 0,
                    'sources_active'   => count(rawwire_data_sources()->get_data('scraper:sources')),
                    'last_sync'        => get_option('rawwire_last_sync', 'Never'),
                ];
            },
        ]);
        
        $this->register('activity:recent', [
            'label' => 'Recent Activity',
            'callback' => function($params) {
                global $wpdb;
                $limit = $params['limit'] ?? 20;
                $table = $wpdb->prefix . 'rawwire_activity_log';
                
                // Check if table exists
                if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
                    return [];
                }
                
                return $wpdb->get_results(
                    $wpdb->prepare("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d", $limit),
                    ARRAY_A
                );
            },
        ]);
    }
}
