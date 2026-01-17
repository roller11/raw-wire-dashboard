<?php
/**
 * Raw Wire Plugin Manager
 * 
 * Modular plugin architecture for SaaS and agentic deployments.
 * Enables dynamic feature loading, dependency management, and lifecycle control.
 * 
 * @package RawWire\Dashboard
 * @since 4.2.0
 */

if (!defined('ABSPATH')) exit;

/**
 * Plugin Manager - Core orchestration for modular features
 */
class Raw_Wire_Plugin_Manager {
    private static $instance = null;
    private $plugins = array();
    private $loaded_plugins = array();
    private $failed_plugins = array();
    private $plugin_directories = array();
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Define plugin search directories
        $base_path = plugin_dir_path(dirname(__FILE__));
        $this->plugin_directories = array(
            'core'     => $base_path . 'includes/features/',
            'custom'   => $base_path . 'includes/custom-features/',
            'vendor'   => $base_path . 'includes/vendor-features/',
        );
        
        // Allow external code to register additional plugin directories
        $this->plugin_directories = apply_filters('rawwire_plugin_directories', $this->plugin_directories);
    }
    
    /**
     * Discover all available plugins in registered directories
     */
    public function discover_plugins() {
        foreach ($this->plugin_directories as $type => $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            
            $files = glob($directory . '*/plugin.php');
            foreach ($files as $file) {
                $this->register_plugin_file($file, $type);
            }
        }
        
        // Allow plugins to be registered programmatically
        do_action('rawwire_register_plugins', $this);
        
        return $this->plugins;
    }
    
    /**
     * Register a plugin file
     */
    public function register_plugin_file($file, $type = 'custom') {
        if (!file_exists($file)) {
            return false;
        }
        
        // Extract plugin metadata
        $metadata = $this->parse_plugin_header($file);
        
        if (empty($metadata['slug'])) {
            $metadata['slug'] = basename(dirname($file));
        }
        
        $metadata['file'] = $file;
        $metadata['type'] = $type;
        $metadata['directory'] = dirname($file);
        
        $this->plugins[$metadata['slug']] = $metadata;
        
        return true;
    }
    
    /**
     * Parse plugin header for metadata
     */
    private function parse_plugin_header($file) {
        $file_data = file_get_contents($file, false, null, 0, 8192);
        
        $headers = array(
            'name'         => 'Plugin Name',
            'slug'         => 'Plugin Slug',
            'version'      => 'Version',
            'description'  => 'Description',
            'author'       => 'Author',
            'requires'     => 'Requires',
            'dependencies' => 'Dependencies',
            'api_version'  => 'API Version',
            'category'     => 'Category',
        );
        
        $metadata = array();
        foreach ($headers as $key => $header) {
            if (preg_match('/^[ \t\/*#@]*' . preg_quote($header, '/') . ':(.*)$/mi', $file_data, $match)) {
                $metadata[$key] = trim($match[1]);
            }
        }
        
        // Parse dependencies as array
        if (!empty($metadata['dependencies'])) {
            $metadata['dependencies'] = array_map('trim', explode(',', $metadata['dependencies']));
        } else {
            $metadata['dependencies'] = array();
        }
        
        return $metadata;
    }
    
    /**
     * Load all enabled plugins
     */
    public function load_plugins() {
        // Sort plugins by dependencies
        $sorted = $this->sort_by_dependencies($this->plugins);
        
        foreach ($sorted as $slug => $plugin) {
            // Check if plugin is enabled
            if (!$this->is_plugin_enabled($slug)) {
                continue;
            }
            
            // Check dependencies
            if (!$this->check_dependencies($plugin)) {
                $this->failed_plugins[$slug] = 'Missing dependencies: ' . implode(', ', $plugin['dependencies']);
                continue;
            }
            
            // Load the plugin
            $result = $this->load_plugin($plugin);
            
            if (is_wp_error($result)) {
                $this->failed_plugins[$slug] = $result->get_error_message();
            }
        }
        
        // Trigger lifecycle hook
        do_action('rawwire_plugins_loaded', $this->loaded_plugins);
        
        return $this->loaded_plugins;
    }
    
    /**
     * Load a single plugin
     */
    private function load_plugin($plugin) {
        try {
            // Include the plugin file
            require_once $plugin['file'];
            
            // Look for plugin class
            $class_name = $this->get_plugin_class_name($plugin);
            
            if (class_exists($class_name)) {
                // Instantiate plugin class
                $instance = new $class_name();
                
                // Register with system
                $this->loaded_plugins[$plugin['slug']] = array(
                    'metadata' => $plugin,
                    'instance' => $instance,
                );
                
                // Call plugin init hook
                if (method_exists($instance, 'init')) {
                    $instance->init();
                }
                
                // Log successful load
                if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                    error_log("[Raw Wire] Loaded plugin: {$plugin['slug']} v{$plugin['version']}");
                }
                
                return true;
            }
            
            return new WP_Error('no_class', "Plugin class {$class_name} not found");
            
        } catch (Exception $e) {
            return new WP_Error('load_error', $e->getMessage());
        }
    }
    
    /**
     * Get expected plugin class name
     */
    private function get_plugin_class_name($plugin) {
        // Convert slug to class name: github-integration -> Raw_Wire_Feature_Github_Integration
        $parts = explode('-', $plugin['slug']);
        $class_parts = array_map('ucfirst', $parts);
        return 'Raw_Wire_Feature_' . implode('_', $class_parts);
    }
    
    /**
     * Check if plugin is enabled via feature flags
     */
    private function is_plugin_enabled($slug) {
        // Check WordPress options
        $enabled_features = get_option('rawwire_enabled_features', array());
        
        // If no explicit settings, enable all core plugins by default
        if (empty($enabled_features)) {
            $plugin = $this->plugins[$slug];
            return ($plugin['type'] === 'core');
        }
        
        return in_array($slug, $enabled_features);
    }
    
    /**
     * Check plugin dependencies
     */
    private function check_dependencies($plugin) {
        if (empty($plugin['dependencies'])) {
            return true;
        }
        
        foreach ($plugin['dependencies'] as $dependency) {
            if (!isset($this->plugins[$dependency])) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Sort plugins by dependency order
     */
    private function sort_by_dependencies($plugins) {
        $sorted = array();
        $processed = array();
        
        $resolve = function($slug) use (&$resolve, &$sorted, &$processed, $plugins) {
            if (isset($processed[$slug])) {
                return;
            }
            
            if (!isset($plugins[$slug])) {
                return;
            }
            
            $plugin = $plugins[$slug];
            
            // Resolve dependencies first
            if (!empty($plugin['dependencies'])) {
                foreach ($plugin['dependencies'] as $dependency) {
                    $resolve($dependency);
                }
            }
            
            $sorted[$slug] = $plugin;
            $processed[$slug] = true;
        };
        
        foreach (array_keys($plugins) as $slug) {
            $resolve($slug);
        }
        
        return $sorted;
    }
    
    /**
     * Get loaded plugin instance
     */
    public function get_plugin($slug) {
        if (isset($this->loaded_plugins[$slug])) {
            return $this->loaded_plugins[$slug]['instance'];
        }
        return null;
    }
    
    /**
     * Get all loaded plugins
     */
    public function get_loaded_plugins() {
        return $this->loaded_plugins;
    }
    
    /**
     * Get failed plugins with error messages
     */
    public function get_failed_plugins() {
        return $this->failed_plugins;
    }
    
    /**
     * Enable a plugin
     */
    public function enable_plugin($slug) {
        $enabled = get_option('rawwire_enabled_features', array());
        if (!in_array($slug, $enabled)) {
            $enabled[] = $slug;
            update_option('rawwire_enabled_features', $enabled);
        }
        return true;
    }
    
    /**
     * Disable a plugin
     */
    public function disable_plugin($slug) {
        $enabled = get_option('rawwire_enabled_features', array());
        $enabled = array_diff($enabled, array($slug));
        update_option('rawwire_enabled_features', array_values($enabled));
        return true;
    }
    
    /**
     * Get plugin registry (all discovered plugins)
     */
    public function get_plugin_registry() {
        return $this->plugins;
    }
}
