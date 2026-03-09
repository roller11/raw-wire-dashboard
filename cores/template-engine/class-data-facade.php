<?php
/**
 * Data Facade - Abstraction layer between modules and data sources
 * Path: cores/template-engine/class-data-facade.php
 *
 * This facade ensures:
 * 1. Modules NEVER access template structure directly
 * 2. All data has sensible defaults when no template is loaded
 * 3. Data sources can come from templates, toolkit, or database
 * 4. The plugin remains functional without any template
 *
 * USAGE:
 *   $sources = RawWire_Data_Facade::get_sources();
 *   $stats = RawWire_Data_Facade::get_stats();
 *   $queue = RawWire_Data_Facade::get_queue_items('candidates');
 *
 * This replaces direct calls like:
 *   $template = RawWire_Template_Engine::get_active_template();
 *   $sources = $template['sources'] ?? [];  // BAD - direct coupling
 */

if (!class_exists('RawWire_Data_Facade')) {
    class RawWire_Data_Facade {

        /** @var array Cached data to prevent repeated lookups */
        private static $cache = array();

        /** @var int Cache TTL in seconds */
        private static $cache_ttl = 60;

        /**
         * Get all configured data sources (from template + toolkit + database)
         * 
         * @param array $filters Optional filters (e.g., ['enabled' => true])
         * @return array Array of source configurations
         */
        public static function get_sources($filters = array()) {
            $cache_key = 'sources_' . md5(serialize($filters));
            
            if (isset(self::$cache[$cache_key]) && self::$cache[$cache_key]['expires'] > time()) {
                return self::$cache[$cache_key]['data'];
            }

            $sources = array();

            // 1. Template-defined sources (if template loaded)
            $sources = array_merge($sources, self::get_template_sources());

            // 2. Toolkit/Scraper sources (always available)
            $sources = array_merge($sources, self::get_toolkit_sources());

            // 3. Database/custom sources
            $sources = array_merge($sources, self::get_custom_sources());

            // Apply filters
            if (!empty($filters)) {
                $sources = self::apply_filters($sources, $filters);
            }

            // Cache result
            self::$cache[$cache_key] = array(
                'data' => $sources,
                'expires' => time() + self::$cache_ttl,
            );

            return $sources;
        }

        /**
         * Get template-defined sources
         */
        private static function get_template_sources() {
            if (!class_exists('RawWire_Template_Engine')) {
                return array();
            }

            $template = RawWire_Template_Engine::get_active_template();
            if (!$template || !isset($template['sources'])) {
                return array();
            }

            $sources = array();
            foreach ($template['sources'] as $source) {
                // Only include valid sources (must have id and url)
                if (isset($source['id']) && isset($source['url'])) {
                    $source['_origin'] = 'template';
                    $sources[$source['id']] = $source;
                }
            }

            return $sources;
        }

        /**
         * Get toolkit/scraper sources
         */
        private static function get_toolkit_sources() {
            if (!class_exists('RawWire_Scraper_Settings')) {
                return array();
            }

            $toolkit_sources = RawWire_Scraper_Settings::get_sources();
            foreach ($toolkit_sources as $id => &$source) {
                $source['_origin'] = 'toolkit';
                $source['id'] = $id;
            }

            return $toolkit_sources;
        }

        /**
         * Get custom/database sources
         */
        private static function get_custom_sources() {
            $custom = get_option('rawwire_custom_sources', array());
            foreach ($custom as &$source) {
                $source['_origin'] = 'database';
            }
            return $custom;
        }

        /**
         * Get dashboard statistics
         * 
         * @return array Stats array with counts and metrics
         */
        public static function get_stats() {
            global $wpdb;
            
            $stats = array(
                'total_processed' => 0,
                'active_workflows' => 0,
                'success_rate' => 0,
                'avg_response_ms' => 0,
                'pending_approval' => 0,
                'pending_release' => 0,
                'published_today' => 0,
            );

            // Get real stats from database if tables exist
            $table_prefix = $wpdb->prefix . 'rawwire_';
            
            // Check if candidates table exists
            $candidates_table = $table_prefix . 'candidates';
            if ($wpdb->get_var("SHOW TABLES LIKE '$candidates_table'") === $candidates_table) {
                // Total processed
                $stats['total_processed'] = (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM $candidates_table"
                );

                // Pending approval
                $stats['pending_approval'] = (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM $candidates_table WHERE status = 'pending'"
                );

                // Pending release  
                $stats['pending_release'] = (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM $candidates_table WHERE status = 'approved'"
                );

                // Published today
                $stats['published_today'] = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $candidates_table WHERE status = 'published' AND DATE(updated_at) = %s",
                    current_time('Y-m-d')
                ));

                // Success rate (published / total * 100)
                if ($stats['total_processed'] > 0) {
                    $published = (int) $wpdb->get_var(
                        "SELECT COUNT(*) FROM $candidates_table WHERE status = 'published'"
                    );
                    $stats['success_rate'] = round(($published / $stats['total_processed']) * 100, 1);
                }
            }

            // Get active workflows from options
            $stats['active_workflows'] = (int) get_option('rawwire_active_workflow_count', 0);

            // Get average response time from recent activity
            $stats['avg_response_ms'] = (int) get_option('rawwire_avg_response_ms', 0);

            return $stats;
        }

        /**
         * Get queue items for a specific queue
         * 
         * @param string $queue Queue name: 'candidates', 'approved', 'published', 'rejected'
         * @param array $args Query arguments (limit, offset, orderby, order)
         * @return array Queue items
         */
        public static function get_queue_items($queue = 'candidates', $args = array()) {
            global $wpdb;
            
            $defaults = array(
                'limit' => 20,
                'offset' => 0,
                'orderby' => 'created_at',
                'order' => 'DESC',
            );
            $args = wp_parse_args($args, $defaults);

            $table = $wpdb->prefix . 'rawwire_candidates';
            
            // Check table exists
            if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
                return array();
            }

            // Map queue to status
            $status_map = array(
                'candidates' => 'pending',
                'pending' => 'pending',
                'approved' => 'approved',
                'published' => 'published',
                'rejected' => 'rejected',
                'all' => null,
            );

            $status = $status_map[$queue] ?? 'pending';
            
            $sql = "SELECT * FROM $table";
            if ($status !== null) {
                $sql .= $wpdb->prepare(" WHERE status = %s", $status);
            }
            $sql .= " ORDER BY {$args['orderby']} {$args['order']}";
            $sql .= $wpdb->prepare(" LIMIT %d OFFSET %d", $args['limit'], $args['offset']);

            return $wpdb->get_results($sql, ARRAY_A) ?: array();
        }

        /**
         * Get activity log entries
         * 
         * @param array $args Query arguments
         * @return array Log entries
         */
        public static function get_activity_log($args = array()) {
            $defaults = array(
                'limit' => 50,
                'level' => null,
                'component' => null,
            );
            $args = wp_parse_args($args, $defaults);

            // Try to get from logger if available
            if (class_exists('RawWire_Activity_Logs')) {
                return RawWire_Activity_Logs::get_recent_logs($args['limit']);
            }

            // Fallback to empty array
            return array();
        }

        /**
         * Get workflow configuration
         * 
         * @return array Workflow settings
         */
        public static function get_workflow_config() {
            return array(
                'auto_sync' => (bool) get_option('rawwire_auto_sync', false),
                'notifications' => (bool) get_option('rawwire_notifications', false),
                'error_reporting' => (bool) get_option('rawwire_error_reporting', true),
                'batch_size' => (int) get_option('rawwire_scoring_batch_size', 10),
                'auto_approve_threshold' => (float) get_option('rawwire_auto_approve_threshold', 0),
                'sync_interval' => (int) get_option('rawwire_sync_interval', 3600),
            );
        }

        /**
         * Get template metadata (or defaults if no template)
         * 
         * @return array Template meta information
         */
        public static function get_template_meta() {
            if (class_exists('RawWire_Template_Engine')) {
                $meta = RawWire_Template_Engine::get_meta();
                if ($meta) {
                    return $meta;
                }
            }

            // Default meta when no template loaded
            return array(
                'id' => 'none',
                'name' => 'Raw Wire Dashboard',
                'version' => '1.0.0',
                'description' => 'Core dashboard without template',
                'author' => 'Raw-Wire DAO LLC',
                'icon' => 'dashicons-admin-generic',
                'variants' => array('default'),
            );
        }

        /**
         * Check if a specific capability is available
         * 
         * @param string $capability Capability to check (e.g., 'scraping', 'ai_generation')
         * @return bool
         */
        public static function has_capability($capability) {
            $capabilities = array(
                'scraping' => class_exists('RawWire_Scraper_Settings') || class_exists('RawWire_Toolbox_Core'),
                'ai_generation' => class_exists('RawWire_Generator_Interface') && self::has_ai_provider(),
                'publishing' => class_exists('RawWire_Poster_Interface'),
                'templates' => class_exists('RawWire_Template_Engine') && RawWire_Template_Engine::get_active_template() !== null,
                'workflow' => class_exists('RawWire_Workflow_Interface'),
            );

            return $capabilities[$capability] ?? false;
        }

        /**
         * Check if an AI provider is configured
         */
        private static function has_ai_provider() {
            // Check for configured AI keys
            if (class_exists('RawWire_Key_Manager')) {
                $providers = array('openai', 'anthropic', 'groq', 'ollama');
                foreach ($providers as $provider) {
                    if (RawWire_Key_Manager::get_key($provider)) {
                        return true;
                    }
                }
            }
            return false;
        }

        /**
         * Apply filters to data array
         */
        private static function apply_filters($data, $filters) {
            return array_filter($data, function($item) use ($filters) {
                foreach ($filters as $key => $value) {
                    if (!isset($item[$key]) || $item[$key] !== $value) {
                        return false;
                    }
                }
                return true;
            });
        }

        /**
         * Clear all cached data
         */
        public static function clear_cache() {
            self::$cache = array();
        }

        /**
         * Get the data origin for debugging
         * 
         * @param string $data_type Type of data
         * @return string Origin description
         */
        public static function get_data_origin($data_type) {
            $origins = array(
                'sources' => self::has_capability('templates') ? 'template + toolkit' : 'toolkit only',
                'stats' => 'database',
                'queue' => 'database',
                'activity' => class_exists('RawWire_Activity_Logs') ? 'activity_logs' : 'unavailable',
                'config' => 'wp_options',
            );

            return $origins[$data_type] ?? 'unknown';
        }
    }
}
