<?php
/**
 * REST API endpoints for RawWire Dashboard
 * Provides modern RESTful API access to dashboard functionality
 */

if (!defined('ABSPATH')) exit;

error_log("RawWire REST API file loaded");

class RawWire_REST_API {
    private static $instance = null;
    private $namespace = 'rawwire/v1';

    /**
     * Middleware stack for request/response processing
     *
     * @var array
     */
    private $middleware = array();

    /**
     * Request validation schemas
     *
     * @var array
     */
    private $validation_schemas = array();

    /**
     * API version info
     *
     * @var array
     */
    private $api_version = array(
        'version' => '1.0',
        'deprecated_at' => null,
        'sunset_at' => null,
    );

    public static function get_instance() {
        error_log("RawWire REST API: get_instance called");
        if (null === self::$instance) {
            error_log("RawWire REST API: Creating new instance");
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
        
        // OPTIMIZATION 1: Setup unified request validation
        $this->setup_validation_schemas();
        
        // OPTIMIZATION 2: Add request logging middleware
        $this->add_middleware(array($this, 'log_request'));
        
        // OPTIMIZATION 3: Add CORS headers
        add_action('rest_api_init', array($this, 'add_cors_headers'));

        // Background fetch runner hook
        add_action('rawwire_run_fetch_immediate', array($this, 'background_fetch_runner'));
        
        // OPTIMIZATION 4: Add response compression
        add_filter('rest_pre_serve_request', array($this, 'enable_compression'), 10, 4);
        
        // OPTIMIZATION 5: Add error formatting
        add_filter('rest_request_after_callbacks', array($this, 'format_response'), 10, 3);
    }

    public function init() {
        // Public initialization method
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    /**
     * Setup validation schemas for all endpoints
     *
     * OPTIMIZATION 1: Unified request validation layer
     *
     * @since  1.0.15
     * @return void
     */
    private function setup_validation_schemas() {
        // Register schemas with RawWire_Validator if available
        if (!class_exists('RawWire_Validator')) {
            return;
        }

        // Search endpoint schema
        RawWire_Validator::register_schema('search', array(
            'q' => array('type' => 'string', 'maxLength' => 200),
            'category' => array('type' => 'string', 'maxLength' => 100),
            'status' => array('type' => 'string', 'enum' => array('pending', 'approved', 'rejected', 'published')),
            'page' => array('type' => 'integer', 'min' => 1),
            'per_page' => array('type' => 'integer', 'min' => 1, 'max' => 100),
        ));

        // Relevance update schema
        RawWire_Validator::register_schema('update_relevance', array(
            'score' => array('type' => 'number', 'required' => true, 'min' => 0, 'max' => 100),
        ));

        // Bulk operations schema
        RawWire_Validator::register_schema('bulk_operation', array(
            'ids' => array('type' => 'array', 'required' => true, 'minLength' => 1),
        ));
    }

    /**
     * Add request logging middleware
     *
     * OPTIMIZATION 2: Automatic logging of all API calls
     *
     * @since  1.0.15
     * @param  WP_REST_Request $request Request object
     * @return void
     */
    public function log_request($request) {
        if (!class_exists('RawWire_Logger')) {
            return;
        }

        $route = $request->get_route();
        $method = $request->get_method();
        $params = $request->get_params();

        // Remove sensitive data from logging
        $safe_params = $params;
        $sensitive_keys = array('password', 'token', 'api_key', 'secret');
        foreach ($sensitive_keys as $key) {
            if (isset($safe_params[$key])) {
                $safe_params[$key] = '[REDACTED]';
            }
        }

        RawWire_Logger::log(
            "REST API Request: {$method} {$route}",
            'debug',
            array(
                'method' => $method,
                'route' => $route,
                'params' => $safe_params,
                'user_id' => get_current_user_id(),
                'ip' => $this->get_client_ip(),
            )
        );
    }

    /**
     * Add middleware to processing stack
     *
     * @since  1.0.15
     * @param  callable $callback Middleware function
     * @return void
     */
    private function add_middleware($callback) {
        $this->middleware[] = $callback;
    }

    // Background runner hook for scheduled fetches
    public function background_fetch_runner() {
        try {
            if (!class_exists('RawWire_Dashboard')) return;
            $dashboard = RawWire_Dashboard::get_instance();
            if (!method_exists($dashboard, 'fetch_github_data')) return;
            $result = $dashboard->fetch_github_data();
            update_option('rawwire_last_sync', current_time('mysql'));
            if (class_exists('RawWire_Logger')) {
                RawWire_Logger::info('Background fetch completed', array('result' => $result));
            }
        } catch (Exception $e) {
            $this->log_error('background_fetch_runner exception', array('message' => $e->getMessage()));
        }
    }

    /**
     * Add CORS headers to REST API responses
     *
     * OPTIMIZATION 3: Proper cross-origin support
     *
     * @since  1.0.15
     * @return void
     */
    public function add_cors_headers() {
        // Get allowed origins from settings
        $allowed_origins = apply_filters('rawwire_api_allowed_origins', array(
            get_site_url(),
        ));

        $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

        // Check if origin is allowed
        if (in_array($origin, $allowed_origins, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
        }

        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-WP-Nonce');
        header('Access-Control-Max-Age: 3600');

        // Handle preflight requests
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            status_header(200);
            exit;
        }
    }

    /**
     * Enable response compression
     *
     * OPTIMIZATION 4: Gzip compression for JSON responses
     *
     * @since  1.0.15
     * @param  bool              $served Whether the request has already been served
     * @param  WP_HTTP_Response  $result Result to send to the client
     * @param  WP_REST_Request   $request Request object
     * @param  WP_REST_Server    $server Server instance
     * @return bool
     */
    public function enable_compression($served, $result, $request, $server) {
        // Only apply to our namespace
        $route = $request->get_route();
        if (strpos($route, '/rawwire/') === false) {
            return $served;
        }
        
        // Skip if headers already sent
        if (headers_sent()) {
            return $served;
        }
        
        // Check if client accepts gzip
        $accept_encoding = isset($_SERVER['HTTP_ACCEPT_ENCODING']) ? $_SERVER['HTTP_ACCEPT_ENCODING'] : '';
        
        if (strpos($accept_encoding, 'gzip') !== false && function_exists('gzencode')) {
            // Only compress if response is large enough (> 1KB)
            $data = $result->get_data();
            $json = wp_json_encode($data);
            
            if (strlen($json) > 1024) {
                header('Content-Encoding: gzip');
                echo gzencode($json);
                return true; // Prevent default serving
            }
        }
        
        return $served;
    }

    /**
     * Format all REST responses consistently
     *
     * OPTIMIZATION 5: Consistent error response format
     *
     * @since  1.0.15
     * @param  WP_REST_Response $response Response object
     * @param  WP_REST_Server   $server Server instance
     * @param  WP_REST_Request  $request Request object
     * @return WP_REST_Response
     */
    public function format_response($response, $server, $request) {
        // Handle WP_Error objects
        if (is_wp_error($response)) {
            // Convert WP_Error to WP_REST_Response for consistent formatting
            $response = new WP_REST_Response(array(
                'success' => false,
                'error' => array(
                    'code' => $response->get_error_code(),
                    'message' => $response->get_error_message(),
                    'details' => $response->get_error_data()
                )
            ), 500);
        }

        // Now $response is guaranteed to be a WP_REST_Response
        // Add API version headers
        $response->header('X-API-Version', $this->api_version['version']);
        
        // Add deprecation warnings if applicable
        if ($this->api_version['deprecated_at']) {
            $response->header('Deprecation', 'true');
            $response->header('Sunset', $this->api_version['sunset_at']);
        }

        // Format error responses consistently
        if ($response->is_error()) {
            $data = $response->get_data();
            
            // Ensure consistent error format
            if (!isset($data['error'])) {
                $formatted = array(
                    'success' => false,
                    'error' => array(
                        'code' => isset($data['code']) ? $data['code'] : 'unknown_error',
                        'message' => isset($data['message']) ? $data['message'] : 'An error occurred',
                        'details' => isset($data['data']) ? $data['data'] : null,
                    ),
                    'timestamp' => current_time('mysql'),
                );
                $response->set_data($formatted);
            }
        } else {
            // Add success wrapper if not present
            $data = $response->get_data();
            if (!isset($data['success'])) {
                $data['success'] = true;
                $response->set_data($data);
            }
        }

        // Execute middleware stack
        foreach ($this->middleware as $middleware) {
            call_user_func($middleware, $request);
        }

        return $response;
    }

    /**
     * Get client IP address
     *
     * @since  1.0.15
     * @return string
     */
    private function get_client_ip() {
        $ip_keys = array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
        foreach ($ip_keys as $key) {
            if (isset($_SERVER[$key]) && filter_var($_SERVER[$key], FILTER_VALIDATE_IP)) {
                return $_SERVER[$key];
            }
        }
        return 'unknown';
    }

    public function register_routes() {
        error_log("RawWire REST API: Registering routes for namespace: " . $this->namespace);

        // GET endpoint for fetch progress (OPTIMIZATION 1)
        register_rest_route($this->namespace, '/fetch-progress', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_fetch_progress'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // POST endpoint to clear cache
        register_rest_route($this->namespace, '/clear-cache', array(
            'methods' => 'POST',
            'callback' => array($this, 'clear_cache'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // GET endpoint for system status
        register_rest_route($this->namespace, '/status', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_status'),
            'permission_callback' => array($this, 'check_permission')
        ));
        
        // POST endpoint for advanced search with filters
        register_rest_route($this->namespace, '/search', array(
            'methods' => array('GET', 'POST'),
            'callback' => array($this, 'search_content'),
            'permission_callback' => array($this, 'check_permission')
        ));
        
        // POST endpoint to update relevance score
        register_rest_route($this->namespace, '/content/(?P<id>\d+)/relevance', array(
            'methods' => 'POST',
            'callback' => array($this, 'update_relevance'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'score' => array(
                    'required' => true,
                    'type' => 'number',
                    'minimum' => 0,
                    'maximum' => 100
                )
            )
        ));
        
        // GET endpoint for available filter options
        register_rest_route($this->namespace, '/filters', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_filters'),
            'permission_callback' => '__return_true' // Public endpoint
        ));
        
        // GET approved content (for AI models)
        register_rest_route($this->namespace, '/content', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_content'),
            'permission_callback' => '__return_true', // Public for API consumption
            'args' => array(
                'status' => array(
                    'default' => 'approved',
                    'enum' => array('pending', 'approved', 'rejected', 'published')
                ),
                'limit' => array(
                    'default' => 20,
                    'type' => 'integer'
                )
            )
        ));
        
        // GET single content item
        register_rest_route($this->namespace, '/content/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_single_content'),
            'permission_callback' => '__return_true'
        ));
        
        // POST approve content
        register_rest_route($this->namespace, '/content/(?P<id>\d+)/approve', array(
            'methods' => 'POST',
            'callback' => array($this, 'approve_content'),
            'permission_callback' => array($this, 'check_permission')
        ));
        
        // POST reject content
        register_rest_route($this->namespace, '/content/(?P<id>\d+)/reject', array(
            'methods' => 'POST',
            'callback' => array($this, 'reject_content'),
            'permission_callback' => array($this, 'check_permission')
        ));
        
        // POST bulk approve
        register_rest_route($this->namespace, '/content/bulk-approve', array(
            'methods' => 'POST',
            'callback' => array($this, 'bulk_approve'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'ids' => array(
                    'required' => true,
                    'type' => 'array'
                )
            )
        ));
        
        // POST bulk reject
        register_rest_route($this->namespace, '/content/bulk-reject', array(
            'methods' => 'POST',
            'callback' => array($this, 'bulk_reject'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'ids' => array(
                    'required' => true,
                    'type' => 'array'
                )
            )
        ));
        
        // GET statistics
        register_rest_route($this->namespace, '/stats', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_stats'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // GET table status - shows all 5 workflow tables and their counts
        register_rest_route($this->namespace, '/table-status', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_table_status'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // POST ensure-tables - creates missing workflow tables
        register_rest_route($this->namespace, '/ensure-tables', array(
            'methods' => 'POST',
            'callback' => array($this, 'ensure_workflow_tables'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // POST clear-workflow-tables - truncates all 6 workflow tables
        register_rest_route($this->namespace, '/clear-workflow-tables', array(
            'methods' => 'POST',
            'callback' => array($this, 'clear_workflow_tables'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // =====================================================================
        // WORKFLOW ORCHESTRATION ENDPOINTS
        // =====================================================================
        
        // POST workflow/start - Start a workflow with configuration
        register_rest_route($this->namespace, '/workflow/start', array(
            'methods' => 'POST',
            'callback' => array($this, 'start_workflow'),
            'permission_callback' => array($this, 'check_permission')
        ));
        
        // GET workflow/status - Get workflow execution status
        register_rest_route($this->namespace, '/workflow/status/(?P<id>[a-zA-Z0-9-]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_workflow_status'),
            'permission_callback' => array($this, 'check_permission')
        ));
        
        // GET workflow/config - Get default workflow configuration and available adapters
        register_rest_route($this->namespace, '/workflow/config', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_workflow_config'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // =====================================================================
        // AI STATUS ENDPOINT
        // =====================================================================
        
        // GET ai/status - Get AI Engine availability and configuration
        register_rest_route($this->namespace, '/ai/status', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_ai_status'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // Batch approve items (matches dashboard.js usage)
        register_rest_route($this->namespace, '/content/approve', array(
            'methods' => 'POST',
            'callback' => array($this, 'approve_content_batch'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // Snooze items (basic implementation: mark as pending and touch updated_at)
        register_rest_route($this->namespace, '/content/snooze', array(
            'methods' => 'POST',
            'callback' => array($this, 'snooze_content_batch'),
            'permission_callback' => array($this, 'check_permission')
        ));
        
        // GET automation logs
        register_rest_route($this->namespace, '/logs', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_logs'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'limit' => array(
                    'default' => 50,
                    'type' => 'integer'
                ),
                'severity' => array(
                    'enum' => array('info', 'warning', 'error', 'critical')
                )
            )
        ));
    }

    public function check_permission() {
        // Permission check - requires manage_options capability (admin)
        // Note: For development/testing, you can temporarily return true
        return current_user_can('manage_options');
    }

    /**
     * Add rate limit headers to response
     *
     * @param WP_REST_Response $response Response object
     */
    private function add_rate_limit_headers($response) {
        $user_id = get_current_user_id();
        $rate_limit_key = 'rawwire_rate_limit_' . $user_id;
        $rate_limit = get_transient($rate_limit_key);
        
        if ($rate_limit === false) {
            $rate_limit = array(
                'requests' => 0,
                'reset_at' => time() + HOUR_IN_SECONDS
            );
        }
        
        $rate_limit['requests']++;
        set_transient($rate_limit_key, $rate_limit, HOUR_IN_SECONDS);
        
        $limit = 100; // 100 requests per hour
        $remaining = max(0, $limit - $rate_limit['requests']);
        
        $response->header('X-RateLimit-Limit', $limit);
        $response->header('X-RateLimit-Remaining', $remaining);
        $response->header('X-RateLimit-Reset', $rate_limit['reset_at']);
    }

    /**
     * Get fetch progress
     *
     * OPTIMIZATION 1: Endpoint to check progress of long-running fetch
     *
     * @param WP_REST_Request $request REST request object
     * @return WP_REST_Response Progress information
     */
    public function get_fetch_progress($request) {
        $user_id = get_current_user_id();
        $progress_key = 'rawwire_fetch_progress_' . $user_id;
        $progress = get_option($progress_key, array(
            'status' => 'none',
            'progress' => 0,
            'total' => 0
        ));
        
        return new WP_REST_Response($progress, 200);
    }

    public function search_content($request) {
        $search_service = new Raw_Wire_Search_Service();
        
        $params = array(
            'q' => $request->get_param('q'),
            'category' => $request->get_param('category'),
            'status' => $request->get_param('status'),
            'min_relevance' => $request->get_param('min_relevance'),
            'date_from' => $request->get_param('date_from'),
            'date_to' => $request->get_param('date_to'),
            'page' => $request->get_param('page'),
            'per_page' => $request->get_param('per_page'),
            'order_by' => $request->get_param('order_by'),
            'order' => $request->get_param('order'),
        );
        
        // Remove null values
        $params = array_filter($params, function($v) { return $v !== null; });
        
        $result = $search_service->search($params);
        if (is_wp_error($result)) {
            $this->log_error('Search failed', array('error' => $result->get_error_message()));
            return new WP_REST_Response(array(
                'success' => false,
                'message' => $result->get_error_message()
            ), 500);
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => $result['results'],
            'total' => $result['total'],
            'page' => $result['page'],
            'per_page' => $result['per_page'],
            'filters_applied' => $result['filters_applied']
        ), 200);
    }
    
    public function update_relevance($request) {
        $id = max(0, (int)$request->get_param('id'));
        $score = max(0.0, min(100.0, (float)$request->get_param('score')));
        
        $table_check = $this->ensure_table();
        if ($table_check instanceof WP_REST_Response) {
            return $table_check;
        }
        
        $search_service = new Raw_Wire_Search_Service();
        $result = $search_service->update_relevance($id, $score);
        if (is_wp_error($result)) {
            $this->log_error('Update relevance failed', array('id' => $id, 'error' => $result->get_error_message()));
            return new WP_REST_Response(array(
                'success' => false,
                'message' => $result->get_error_message()
            ), 500);
        }
        
        if ($result === false) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Failed to update relevance score'
            ), 500);
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Relevance score updated',
            'id' => $id,
            'score' => $score
        ), 200);
    }
    
    public function get_filters($request) {
        $search_service = new Raw_Wire_Search_Service();
        
        global $wpdb;
        $table = $wpdb->prefix . 'rawwire_content';
        $table_check = $this->ensure_table($table);
        if ($table_check instanceof WP_REST_Response) {
            return $table_check;
        }
        
        $statuses = $wpdb->get_col("SELECT DISTINCT status FROM {$table} WHERE status IS NOT NULL");
        $categories = $search_service->get_categories();
        
        return new WP_REST_Response(array(
            'success' => true,
            'filters' => array(
                'statuses' => $statuses,
                'categories' => $categories,
                'date_range' => array(
                    'min' => $wpdb->get_var("SELECT MIN(created_at) FROM {$table}"),
                    'max' => $wpdb->get_var("SELECT MAX(created_at) FROM {$table}")
                )
            )
        ), 200);
    }

    public function clear_cache($request) {
        delete_transient('rawwire_data_cache');
        
        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Cache cleared successfully'
        ), 200);
    }

    public function get_status($request) {
        // Deprecated: All feature logic must be in the template. Return stub response.
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Not implemented. All feature logic is now in the dashboard template.',
            'data' => array()
        ), 501);
    }
    
    // Content Management Endpoints
    
    public function get_content($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'rawwire_content';
        $table_check = $this->ensure_table($table);
        if ($table_check instanceof WP_REST_Response) {
            return $table_check;
        }
        
        $status = sanitize_text_field((string)$request->get_param('status'));
        if ($status === '') {
            $status = 'approved';
        }
        $limit = max(1, min(100, (int)$request->get_param('limit')));
        
        $sql = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE status = %s ORDER BY created_at DESC LIMIT %d",
            $status,
            $limit
        );
        
        $content = $wpdb->get_results($sql, ARRAY_A);
        
        return new WP_REST_Response(array(
            'success' => true,
            'count' => count($content),
            'data' => $content,
            'status_filter' => $status
        ), 200);
    }
    
    public function get_single_content($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'rawwire_content';
        $table_check = $this->ensure_table($table);
        if ($table_check instanceof WP_REST_Response) {
            return $table_check;
        }
        
        $id = (int)$request->get_param('id');
        
        $content = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $id
        ), ARRAY_A);
        
        if (!$content) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Content not found'
            ), 404);
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => $content
        ), 200);
    }
    
    public function approve_content($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'rawwire_content';
        $table_check = $this->ensure_table($table);
        if ($table_check instanceof WP_REST_Response) {
            return $table_check;
        }
        
        $id = (int)$request->get_param('id');
        $user_id = get_current_user_id();
        
        $result = $wpdb->update(
            $table,
            array(
                'status' => 'approved',
                'updated_at' => current_time('mysql')
            ),
            array('id' => $id),
            array('%s', '%s'),
            array('%d')
        );
        
        if ($result === false) {
            $this->log_error('Approve failed', array('id' => $id));
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Failed to approve content'
            ), 500);
        }
        if ($result === 0) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Content not found'
            ), 404);
        }
        
        // Log approval action
        $this->log_action('approve', $id, $user_id);
        
        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Content approved',
            'id' => $id
        ), 200);
    }
    
    public function reject_content($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'rawwire_content';
        $table_check = $this->ensure_table($table);
        if ($table_check instanceof WP_REST_Response) {
            return $table_check;
        }
        
        $id = (int)$request->get_param('id');
        $user_id = get_current_user_id();
        
        $result = $wpdb->update(
            $table,
            array(
                'status' => 'rejected',
                'updated_at' => current_time('mysql')
            ),
            array('id' => $id),
            array('%s', '%s'),
            array('%d')
        );
        
        if ($result === false) {
            $this->log_error('Reject failed', array('id' => $id));
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Failed to reject content'
            ), 500);
        }
        if ($result === 0) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Content not found'
            ), 404);
        }
        
        // Log rejection action
        $this->log_action('reject', $id, $user_id);
        
        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Content rejected',
            'id' => $id
        ), 200);
    }
    
    public function bulk_approve($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'rawwire_content';
        $table_check = $this->ensure_table($table);
        if ($table_check instanceof WP_REST_Response) {
            return $table_check;
        }
        
        $ids = array_values(array_filter(array_map('intval', (array)$request->get_param('ids')), function($id) { return $id > 0; }));
        $user_id = get_current_user_id();
        
        if (empty($ids) || !is_array($ids)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'No IDs provided'
            ), 400);
        }
        
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = 'approved', updated_at = %s WHERE id IN ($placeholders)",
            array_merge(array(current_time('mysql')), $ids)
        ));
        if ($result === false) {
            $this->log_error('Bulk approve failed', array('ids' => $ids));
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Failed to approve items'
            ), 500);
        }
        
        foreach ($ids as $id) {
            $this->log_action('bulk_approve', $id, $user_id);
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'message' => "Approved {$result} items",
            'count' => $result
        ), 200);
    }
    
    public function bulk_reject($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'rawwire_content';
        $table_check = $this->ensure_table($table);
        if ($table_check instanceof WP_REST_Response) {
            return $table_check;
        }
        
        $ids = array_values(array_filter(array_map('intval', (array)$request->get_param('ids')), function($id) { return $id > 0; }));
        $user_id = get_current_user_id();
        
        if (empty($ids) || !is_array($ids)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'No IDs provided'
            ), 400);
        }
        
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = 'rejected', updated_at = %s WHERE id IN ($placeholders)",
            array_merge(array(current_time('mysql')), $ids)
        ));
        if ($result === false) {
            $this->log_error('Bulk reject failed', array('ids' => $ids));
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Failed to reject items'
            ), 500);
        }
        
        foreach ($ids as $id) {
            $this->log_action('bulk_reject', $id, $user_id);
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'message' => "Rejected {$result} items",
            'count' => $result
        ), 200);
    }
    
    public function get_stats($request) {
        global $wpdb;
        
        // Define all 6 workflow tables
        $tables = array(
            'candidates' => $wpdb->prefix . 'rawwire_candidates',
            'approvals'  => $wpdb->prefix . 'rawwire_approvals',
            'content'    => $wpdb->prefix . 'rawwire_content',
            'releases'   => $wpdb->prefix . 'rawwire_releases',
            'archives'   => $wpdb->prefix . 'rawwire_archives',
            'published'  => $wpdb->prefix . 'rawwire_published',
        );
        
        // Build table counts with full table names for workflow visibility
        $table_counts = array();
        $total_items = 0;
        
        foreach ($tables as $name => $table) {
            $exists = $this->table_exists($table);
            $count = 0;
            if ($exists) {
                $count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}");
            }
            // Include full table name for display
            $table_counts[$name] = array(
                'table' => $table,
                'count' => $count,
                'exists' => $exists,
            );
            $total_items += $count;
        }
        
        // Get specific status counts from approvals table (pending review)
        $pending_review = 0;
        if ($this->table_exists($tables['approvals'])) {
            $pending_review = (int)$wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM {$tables['approvals']} WHERE status = %s", 'pending')
            );
        }
        
        // Get ready to publish count from releases table
        $ready_to_publish = 0;
        if ($this->table_exists($tables['releases'])) {
            $ready_to_publish = (int)$wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM {$tables['releases']} WHERE status = %s", 'ready')
            );
        }
        
        // Get published count from published table
        $published_count = 0;
        if ($this->table_exists($tables['published'])) {
            $published_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$tables['published']}");
        }
        
        // Get published today count from published table
        $published_today = 0;
        if ($this->table_exists($tables['published'])) {
            $published_today = (int)$wpdb->get_var(
                "SELECT COUNT(*) FROM {$tables['published']} WHERE DATE(published_at) = CURDATE()"
            );
        }
        
        // Build stats response - real-time counts for workflow visibility
        $stats = array(
            'total'            => $total_items,
            'pending'          => $pending_review,
            'approved'         => $ready_to_publish,
            'rejected'         => $table_counts['archives']['count'],
            'published'        => $published_count,
            'published_today'  => $published_today,
            'ready_to_publish' => $ready_to_publish,
            'last_sync'        => get_option('rawwire_last_sync', 'Never'),
        );

        // Real-time table counts response for workflow monitoring
        $response = array(
            'success'      => true,
            'timestamp'    => current_time('mysql'),
            'last_sync'    => $stats['last_sync'],
            'total_items'  => $stats['total'],
            'stats'        => $stats,
            'tables'       => $table_counts,
        );
        return new WP_REST_Response($response, 200);
    }

    /**
     * Get status of all 6 workflow tables with counts
     * Real-time view for workflow monitoring
     */
    public function get_table_status($request) {
        global $wpdb;
        
        // Define all 6 workflow tables
        $tables = array(
            'candidates' => array(
                'name'        => $wpdb->prefix . 'rawwire_candidates',
                'stage'       => 1,
                'description' => 'Scraped items pending scoring',
            ),
            'approvals' => array(
                'name'        => $wpdb->prefix . 'rawwire_approvals',
                'stage'       => 2,
                'description' => 'Top-scoring items awaiting human review',
            ),
            'content' => array(
                'name'        => $wpdb->prefix . 'rawwire_content',
                'stage'       => 3,
                'description' => 'Human-approved items in AI generation queue',
            ),
            'releases' => array(
                'name'        => $wpdb->prefix . 'rawwire_releases',
                'stage'       => 4,
                'description' => 'Generated content ready for publishing',
            ),
            'published' => array(
                'name'        => $wpdb->prefix . 'rawwire_published',
                'stage'       => 5,
                'description' => 'Published content (finished products)',
            ),
            'archives' => array(
                'name'        => $wpdb->prefix . 'rawwire_archives',
                'stage'       => 0,
                'description' => 'Lower-scoring items (permanent archive)',
            ),
        );
        
        $status = array();
        $total = 0;
        foreach ($tables as $key => $info) {
            $exists = $this->table_exists($info['name']);
            $count = 0;
            if ($exists) {
                $count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$info['name']}");
            }
            $status[$key] = array(
                'table'       => $info['name'],
                'stage'       => $info['stage'],
                'description' => $info['description'],
                'exists'      => $exists,
                'count'       => $count,
            );
            $total += $count;
        }
        
        return new WP_REST_Response(array(
            'success'   => true,
            'timestamp' => current_time('mysql'),
            'total'     => $total,
            'tables'    => $status,
        ), 200);
    }

    /**
     * Ensure all 6 workflow tables exist
     */
    public function ensure_workflow_tables($request) {
        require_once plugin_dir_path(__FILE__) . 'services/class-migration-service.php';
        
        try {
            \RawWire\Dashboard\Services\Migration_Service::run_migrations();
            return new WP_REST_Response(array(
                'success' => true,
                'message' => 'All workflow tables created/verified',
            ), 200);
        } catch (Exception $e) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Failed to create tables: ' . $e->getMessage(),
            ), 500);
        }
    }

    /**
     * Clear all workflow tables (TRUNCATE)
     * Only clears workflow tables, not WordPress core tables
     */
    public function clear_workflow_tables($request) {
        global $wpdb;
        
        // Define ALL workflow-related tables to clear
        $workflow_tables = array(
            'candidates' => $wpdb->prefix . 'rawwire_candidates',
            'approvals'  => $wpdb->prefix . 'rawwire_approvals',
            'content'    => $wpdb->prefix . 'rawwire_content',
            'releases'   => $wpdb->prefix . 'rawwire_releases',
            'published'  => $wpdb->prefix . 'rawwire_published',
            'archives'   => $wpdb->prefix . 'rawwire_archives',
            'queue'      => $wpdb->prefix . 'rawwire_queue',
        );
        
        $results = array();
        $total_deleted = 0;
        
        foreach ($workflow_tables as $name => $table) {
            if ($this->table_exists($table)) {
                $count_before = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}");
                $wpdb->query("TRUNCATE TABLE {$table}");
                $results[$name] = array(
                    'table' => $table,
                    'deleted' => $count_before,
                );
                $total_deleted += $count_before;
            } else {
                $results[$name] = array(
                    'table' => $table,
                    'deleted' => 0,
                    'note' => 'Table does not exist',
                );
            }
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'message' => "Cleared {$total_deleted} records from all workflow tables",
            'total_deleted' => $total_deleted,
            'tables' => $results,
        ), 200);
    }

    // =========================================================================
    // WORKFLOW ORCHESTRATION METHODS
    // =========================================================================
    
    /**
     * Start a workflow with given configuration
     * 
     * Expected payload:
     * {
     *   "scraper": "github",
     *   "max_records": 10,
     *   "target_table": "candidates",
     *   "sources": [
     *     { "url": "https://api.github.com/repos/owner/repo", "name": "My Repo" }
     *   ],
     *   "async": false
     * }
     */
    public function start_workflow($request) {
        error_log('RawWire: start_workflow called');
        
        $orchestrator_path = plugin_dir_path(__FILE__) . 'services/class-workflow-orchestrator.php';
        if (!file_exists($orchestrator_path)) {
            error_log('RawWire: Workflow orchestrator file not found: ' . $orchestrator_path);
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Workflow orchestrator not found',
            ), 500);
        }
        require_once $orchestrator_path;
        
        $config = $request->get_json_params();
        if (!is_array($config)) {
            $config = array();
        }
        error_log('RawWire: Workflow config: ' . print_r($config, true));
        
        // Merge with defaults
        $defaults = \RawWire\Dashboard\Services\Workflow_Orchestrator::get_default_config();
        $config = array_merge($defaults, $config);
        
        // If no sources provided, load from template settings
        if (empty($config['sources'])) {
            $config['sources'] = $this->get_sources_from_template();
            // Use native scraper for non-GitHub sources (default is github)
            $config['scraper'] = 'native';
        }
        
        // Validate required fields
        if (empty($config['sources'])) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'No sources configured. Please enable at least one source in Settings.',
            ), 400);
        }
        
        try {
            $orchestrator = new \RawWire\Dashboard\Services\Workflow_Orchestrator();
            $result = $orchestrator->start($config);
            
            $status_code = $result['success'] ? 200 : 500;
            if (!empty($result['status']) && $result['status'] === 'scheduled') {
                $status_code = 202; // Accepted for async
            }
            
            return new WP_REST_Response($result, $status_code);
        } catch (\Exception $e) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => $e->getMessage(),
            ), 500);
        }
    }
    
    /**
     * Get workflow execution status
     */
    public function get_workflow_status($request) {
        require_once plugin_dir_path(__FILE__) . 'services/class-workflow-orchestrator.php';
        
        $execution_id = $request->get_param('id');
        
        $status = \RawWire\Dashboard\Services\Workflow_Orchestrator::get_status($execution_id);
        
        if (!$status) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Workflow execution not found',
            ), 404);
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'execution' => $status,
        ), 200);
    }
    
    /**
     * Get workflow configuration options and available adapters
     */
    public function get_workflow_config($request) {
        require_once plugin_dir_path(__FILE__) . 'services/class-workflow-orchestrator.php';
        
        $scrapers = \RawWire\Dashboard\Services\Workflow_Orchestrator::get_available_scrapers();
        $scorers = \RawWire\Dashboard\Services\Workflow_Orchestrator::get_available_scorers();
        $defaults = \RawWire\Dashboard\Services\Workflow_Orchestrator::get_default_config();
        
        // Get available target tables
        $tables = array(
            'candidates' => array('label' => '1. Candidates', 'description' => 'Initial intake'),
            'approvals'  => array('label' => '2. Approvals', 'description' => 'Ready for review'),
            'content'    => array('label' => '3. Content', 'description' => 'AI generation'),
            'releases'   => array('label' => '4. Releases', 'description' => 'Ready to publish'),
            'published'  => array('label' => '5. Published', 'description' => 'Finished products'),
            'archives'   => array('label' => '0. Archives', 'description' => 'Historical data'),
        );
        
        // GitHub-specific source presets
        $github_presets = array(
            array(
                'name' => 'Trending Repositories',
                'url' => 'https://api.github.com/search/repositories',
                'params' => array(
                    'q' => 'stars:>1000 pushed:>2024-01-01',
                    'sort' => 'stars',
                    'order' => 'desc',
                ),
            ),
            array(
                'name' => 'AI/ML Projects',
                'url' => 'https://api.github.com/search/repositories',
                'params' => array(
                    'q' => 'topic:machine-learning OR topic:artificial-intelligence stars:>500',
                    'sort' => 'updated',
                ),
            ),
            array(
                'name' => 'WordPress Plugins',
                'url' => 'https://api.github.com/search/repositories',
                'params' => array(
                    'q' => 'topic:wordpress-plugin stars:>100',
                    'sort' => 'updated',
                ),
            ),
        );
        
        return new WP_REST_Response(array(
            'success' => true,
            'config' => array(
                'defaults' => $defaults,
                'scrapers' => $scrapers,
                'scorers' => $scorers,
                'target_tables' => $tables,
                'presets' => array(
                    'github' => $github_presets,
                ),
                'limits' => array(
                    'max_records' => array('min' => 1, 'max' => 100, 'default' => 10),
                ),
            ),
        ), 200);
    }

    /**
     * Get AI Engine status and configuration
     * 
     * @return WP_REST_Response
     */
    public function get_ai_status($request) {
        global $mwai, $mwai_core;
        
        $status = array(
            'available' => false,
            'pro' => false,
            'version' => 'N/A',
            'environments' => array(),
            'default_env' => null,
            'mcp_server' => false,
            'groq_engine' => false,
            'debug' => array(),
        );
        
        // Direct AI Engine detection
        $status['debug']['mwai_class_exists'] = class_exists('Meow_MWAI_Core');
        $status['debug']['mwai_init_exists'] = function_exists('mwai_init');
        $status['debug']['mwai_version_defined'] = defined('MWAI_VERSION');
        $status['debug']['mwai_global'] = !empty($mwai);
        $status['debug']['mwai_core_global'] = !empty($mwai_core);
        
        // Check AI Engine availability directly
        if (class_exists('Meow_MWAI_Core') || function_exists('mwai_init')) {
            $status['available'] = true;
            
            if (defined('MWAI_VERSION')) {
                $status['version'] = MWAI_VERSION;
            }
        }
        
        // Check for Pro version
        if (class_exists('Meow_MWAI_Pro') || defined('MWAI_PRO_VERSION') || defined('MWAI_ITEM_ID')) {
            $status['pro'] = true;
        }
        
        // Get environments from AI Engine settings
        $ai_settings = get_option('mwai_options', array());
        if (isset($ai_settings['ai_envs']) && is_array($ai_settings['ai_envs'])) {
            foreach ($ai_settings['ai_envs'] as $env) {
                $status['environments'][] = array(
                    'id'    => $env['id'] ?? '',
                    'name'  => $env['name'] ?? '',
                    'type'  => $env['type'] ?? '',
                    'model' => $env['model'] ?? '',
                );
            }
        }
        
        // Try to get AI Adapter instance for additional info
        $adapter_path = plugin_dir_path(__FILE__) . 'cores/toolbox-core/class-ai-adapter.php';
        if (file_exists($adapter_path)) {
            require_once $adapter_path;
            if (class_exists('\\RawWire\\Dashboard\\Cores\\ToolboxCore\\AI_Adapter')) {
                $ai_adapter = \RawWire\Dashboard\Cores\ToolboxCore\AI_Adapter::get_instance();
                $adapter_status = $ai_adapter->get_status();
                $status['default_env'] = $adapter_status['default_env'];
            }
        }
        
        // Check MCP Server availability
        $mcp_path = plugin_dir_path(__FILE__) . 'cores/toolbox-core/class-mcp-server.php';
        if (file_exists($mcp_path)) {
            require_once $mcp_path;
            if (class_exists('RawWire_MCP_Server')) {
                $status['mcp_server'] = true;
            }
        }
        
        // Check Groq Engine availability
        // Note: Groq integration is in includes/integrations and hooks into AI Engine Pro
        $groq_path = plugin_dir_path(__FILE__) . 'includes/integrations/class-groq-engine.php';
        if (file_exists($groq_path)) {
            // The Groq engine integrates with AI Engine Pro via filters
            // Check if the integration class is loaded
            $status['groq_engine'] = class_exists('Meow_MWAI_Engines_Groq');
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'ai_status' => $status,
        ), 200);
    }

    /**
     * Approve content items in batch: expects { content_ids: [id, id, ...] }
     */
    public function approve_content_batch($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'rawwire_content';
        $payload = $request->get_json_params();
        $ids = isset($payload['content_ids']) && is_array($payload['content_ids']) ? array_filter(array_map('intval', $payload['content_ids'])) : array();
        if (empty($ids)) {
            return new WP_REST_Response(array('success' => false, 'message' => 'No IDs provided'), 400);
        }
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $result = $wpdb->query($wpdb->prepare("UPDATE {$table} SET status = 'approved', updated_at = %s WHERE id IN ({$placeholders})", array_merge(array(current_time('mysql')), $ids)));
        if ($result === false) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Failed to approve items'), 500);
        }
        RawWire_Logger::log_activity('Approved items', 'approvals', array('ids' => $ids, 'count' => $result), 'info');
        return new WP_REST_Response(array('success' => true, 'message' => 'Approved items', 'count' => $result), 200);
    }

    /**
     * Snooze content items: currently updates updated_at and logs action
     */
    public function snooze_content_batch($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'rawwire_content';
        $payload = $request->get_json_params();
        $ids = isset($payload['content_ids']) && is_array($payload['content_ids']) ? array_filter(array_map('intval', $payload['content_ids'])) : array();
        $minutes = isset($payload['minutes']) ? max(1, intval($payload['minutes'])) : 60;
        if (empty($ids)) {
            return new WP_REST_Response(array('success' => false, 'message' => 'No IDs provided'), 400);
        }
        // For now, touch updated_at and leave status as-is. Future: add snooze_until meta column.
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $result = $wpdb->query($wpdb->prepare("UPDATE {$table} SET updated_at = %s WHERE id IN ({$placeholders})", array_merge(array(current_time('mysql')), $ids)));
        if ($result === false) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Failed to snooze items'), 500);
        }
        RawWire_Logger::log_activity('Snoozed items', 'approvals', array('ids' => $ids, 'minutes' => $minutes, 'count' => $result), 'info');
        return new WP_REST_Response(array('success' => true, 'message' => 'Snoozed items', 'count' => $result), 200);
    }
    
    public function get_logs($request) {
        $limit = min((int)$request->get_param('limit'), 500);
        $severity = $request->get_param('severity');
        
        // For now, return WordPress error log entries related to rawwire
        // In production, you'd have a dedicated logs table
        $logs = array();
        
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            $log_file = WP_CONTENT_DIR . '/debug.log';
            if (file_exists($log_file)) {
                $lines = file($log_file);
                $count = 0;
                
                foreach (array_reverse($lines) as $line) {
                    if (stripos($line, 'rawwire') !== false || stripos($line, 'raw-wire') !== false || stripos($line, 'raw_wire') !== false) {
                        $logs[] = array(
                            'timestamp' => substr($line, 0, 25),
                            'message' => trim(substr($line, 25)),
                            'severity' => $this->detect_severity($line)
                        );
                        $count++;
                        if ($count >= $limit) break;
                    }
                }
            }
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'logs' => $logs,
            'count' => count($logs)
        ), 200);
    }
    
    // Helper functions

    private function ensure_table($table = null) {
        if ($table === null) {
            global $wpdb;
            $table = $wpdb->prefix . 'rawwire_content';
        }
        if ($this->table_exists($table)) {
            return null;
        }
        $this->log_error('Missing table', array('table' => $table));
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Required data table is missing'
        ), 500);
    }

    private function table_exists($table) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    /**
     * Get sources for workflow execution
     * 
     * Priority:
     * 1. User-configured sources from Scraper Toolkit (RawWire_Scraper_Settings)
     * 2. Built-in template sourceTypes (legacy fallback)
     * 3. Federal Register as default test source
     * 
     * @return array Array of source configs
     */
    private function get_sources_from_template() {
        $sources = array();
        
        // PRIMARY: Get user-configured sources from Scraper Toolkit
        if (class_exists('RawWire_Scraper_Settings')) {
            $scraper_sources = \RawWire_Scraper_Settings::get_sources();
            if (!empty($scraper_sources)) {
                foreach ($scraper_sources as $source) {
                    // Only include enabled sources
                    if (empty($source['enabled'])) {
                        continue;
                    }
                    
                    $url = $source['url'] ?? $source['address'] ?? '';
                    $params = array();
                    
                    // Parse existing params from source or URL
                    if (!empty($source['params'])) {
                        if (is_string($source['params'])) {
                            parse_str($source['params'], $params);
                        } elseif (is_array($source['params'])) {
                            $params = $source['params'];
                        }
                    }
                    
                    // Add default required params for known APIs
                    if (strpos($url, 'api.github.com/search') !== false) {
                        // GitHub search API requires 'q' parameter
                        if (empty($params['q'])) {
                            $params['q'] = 'stars:>100 pushed:>' . date('Y-m-d', strtotime('-30 days'));
                        }
                        if (empty($params['sort'])) {
                            $params['sort'] = 'updated';
                        }
                        if (empty($params['per_page'])) {
                            $params['per_page'] = min($source['records_limit'] ?? 10, 100);
                        }
                    } elseif (strpos($url, 'api.regulations.gov') !== false) {
                        // Regulations.gov defaults
                        if (empty($params['page[size]'])) {
                            $params['page[size]'] = min($source['records_limit'] ?? 20, 250);
                        }
                    } elseif (strpos($url, 'api.congress.gov') !== false) {
                        // Congress.gov defaults  
                        if (empty($params['limit'])) {
                            $params['limit'] = min($source['records_limit'] ?? 20, 250);
                        }
                    } elseif (strpos($url, 'commons.wikimedia.org') !== false) {
                        // Wikimedia Commons defaults
                        if (empty($params['action'])) {
                            $params['action'] = 'query';
                            $params['list'] = 'allimages';
                            $params['format'] = 'json';
                            $params['ailimit'] = min($source['records_limit'] ?? 10, 500);
                        }
                    }
                    
                    // Add auth key as API key header/param if provided
                    $headers = array();
                    if (!empty($source['auth_key']) && $source['auth_type'] === 'api_key') {
                        // Different APIs use different methods
                        if (strpos($url, 'api.regulations.gov') !== false) {
                            $params['api_key'] = $source['auth_key'];
                        } elseif (strpos($url, 'api.congress.gov') !== false) {
                            $params['api_key'] = $source['auth_key'];
                        } else {
                            $headers['Authorization'] = 'Bearer ' . $source['auth_key'];
                        }
                    }
                    
                    $sources[] = array(
                        'id' => $source['id'] ?? sanitize_title($source['name'] ?? 'source'),
                        'url' => $url,
                        'type' => $source['type'] ?? 'rest_api',
                        'label' => $source['name'] ?? $source['url'] ?? 'Unknown',
                        'adapter' => 'native',
                        'params' => $params,
                        'headers' => $headers,
                    );
                }
                error_log('RawWire: get_sources_from_template using ' . count($sources) . ' scraper sources');
                return $sources;
            }
        }
        
        // FALLBACK: Try template sourceTypes (legacy built-in sources)
        if (class_exists('RawWire_Template_Engine')) {
            $template = \RawWire_Template_Engine::get_active_template();
            if ($template && !empty($template['sourceTypes'])) {
                foreach ($template['sourceTypes'] as $source_id => $source_config) {
                    if (!empty($source_config['enabled'])) {
                        $url = $source_config['config']['endpoint'] ?? null;
                        
                        if (!$url) {
                            switch ($source_id) {
                                case 'federal_register':
                                    $url = 'https://www.federalregister.gov/api/v1/documents?per_page=20&order=newest';
                                    break;
                                case 'github':
                                    $url = 'https://api.github.com/search/repositories?q=wordpress+plugin&sort=updated&per_page=10';
                                    break;
                                default:
                                    continue 2;
                            }
                        }
                        
                        $sources[] = array(
                            'id' => $source_id,
                            'url' => $url,
                            'type' => $source_config['type'] ?? 'api',
                            'label' => $source_config['label'] ?? $source_id,
                            'adapter' => $source_config['adapter'] ?? 'native',
                            'params' => $source_config['config'] ?? array(),
                        );
                    }
                }
            }
        }
        
        // DEFAULT: Use Federal Register as test source if nothing configured
        if (empty($sources)) {
            $sources[] = array(
                'id' => 'federal_register',
                'url' => 'https://www.federalregister.gov/api/v1/documents?per_page=20&order=newest',
                'type' => 'api',
                'label' => 'Federal Register',
                'adapter' => 'native',
                'params' => array(
                    'publicDomain' => true,
                ),
            );
        }
        
        error_log('RawWire: get_sources_from_template returning ' . count($sources) . ' sources');
        
        return $sources;
    }

    private function log_error($message, $context = array()) {
        $payload = $context ? ' ' . json_encode($context) : '';
        error_log('[RawWire REST] ' . $message . $payload);
    }
    
    private function log_action($action, $content_id, $user_id) {
        // Log to database table (preferred) or transient (fallback)
        global $wpdb;
        $logs_table = $wpdb->prefix . 'rawwire_logs';
        
        // Try to use the proper logs table
        if ($wpdb->get_var("SHOW TABLES LIKE '{$logs_table}'") === $logs_table) {
            $wpdb->insert($logs_table, array(
                'level' => 'info',
                'message' => $action,
                'context' => json_encode(array(
                    'content_id' => $content_id,
                    'user_id' => $user_id
                )),
                'timestamp' => current_time('mysql')
            ));
            return;
        }
        
        // Fallback to transient with shorter lifespan (24 hours)
        $log_entry = array(
            'action' => $action,
            'content_id' => $content_id,
            'user_id' => $user_id,
            'timestamp' => current_time('mysql')
        );
        
        $existing_log = get_transient('rawwire_action_log');
        if (!is_array($existing_log)) {
            $existing_log = array();
        }
        $existing_log[] = $log_entry;
        
        // Keep only last 500 entries (reduced from 1000)
        if (count($existing_log) > 500) {
            $existing_log = array_slice($existing_log, -500);
        }
        
        // Store with 24-hour expiration
        set_transient('rawwire_action_log', $existing_log, DAY_IN_SECONDS);
    }
    
    private function detect_severity($log_line) {
        $line_lower = strtolower($log_line);
        
        if (strpos($line_lower, 'critical') !== false || strpos($line_lower, 'fatal') !== false) {
            return 'critical';
        } elseif (strpos($line_lower, 'error') !== false) {
            return 'error';
        } elseif (strpos($line_lower, 'warning') !== false || strpos($line_lower, 'warn') !== false) {
            return 'warning';
        } else {
            return 'info';
        }
    }
}

// Initialize REST API
RawWire_REST_API::get_instance();
