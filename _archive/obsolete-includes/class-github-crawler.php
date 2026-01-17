<?php
/**
 * GitHub API Crawler Class
 *
 * Handles GitHub API integration with robust error handling,
 * rate limiting, and deduplication logic.
 *
 * @package Raw_Wire_Dashboard
 * @subpackage Includes
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Class Raw_Wire_GitHub_Crawler
 *
 * Manages GitHub API interactions with caching, rate limiting,
 * and deduplication capabilities.
 */
class Raw_Wire_GitHub_Crawler {

	/**
	 * GitHub API base URL
	 *
	 * @var string
	 */
	private $api_base_url = 'https://api.github.com';

	/**
	 * GitHub personal access token
	 *
	 * @var string
	 */
	private $access_token;

	/**
	 * Current rate limit status
	 *
	 * @var array
	 */
	private $rate_limit_status = array(
		'limit'     => 60,
		'remaining' => 60,
		'reset'     => 0,
	);

	/**
	 * Request queue with priority support
	 *
	 * OPTIMIZATION 2: Request queue
	 *
	 * @var array
	 */
	private $request_queue = array(
		'high' => array(),
		'normal' => array(),
		'low' => array(),
	);

	/**
	 * Cache for API responses
	 *
	 * @var array
	 */
	private $cache = array();

	/**
	 * Cache expiration time in seconds
	 *
	 * @var int
	 */
	private $cache_ttl = 3600;

	/**
	 * Deduplication hash store
	 *
	 * @var array
	 */
	private $seen_hashes = array();

	/**
	 * Request timeout in seconds
	 *
	 * @var int
	 */
	private $request_timeout = 15;

	/**
	 * Request retry count
	 *
	 * @var int
	 */
	private $max_retries = 3;

	/**
	 * Current retry attempt
	 *
	 * @var int
	 */
	private $retry_count = 0;

	/**
	 * Webhook secret for validation
	 *
	 * OPTIMIZATION 5: Webhook integration
	 *
	 * @var string
	 */
	private $webhook_secret;

	/**
	 * Constructor
	 *
	 * @param string $access_token GitHub personal access token.
	 */
	public function __construct( $access_token = '' ) {
		$this->access_token = $access_token ?: $this->get_stored_token();
		$this->webhook_secret = get_option('rawwire_github_webhook_secret', '');
		$this->load_seen_hashes();
		$this->load_request_queue();
	}

	/**
	 * Get stored GitHub token from WordPress options
	 *
	 * @return string
	 */
	private function get_stored_token() {
		$token = get_option( 'raw_wire_github_token', '' );
		
		if ( empty( $token ) ) {
			$this->log_error( 'No GitHub token configured' );
			return '';
		}

		return sanitize_text_field( $token );
	}

	/**
	 * Set GitHub access token
	 *
	 * @param string $token GitHub personal access token.
	 * @return bool
	 */
	public function set_access_token( $token ) {
		if ( empty( $token ) || ! is_string( $token ) ) {
			$this->log_error( 'Invalid token provided' );
			return false;
		}

		$this->access_token = sanitize_text_field( $token );
		return update_option( 'raw_wire_github_token', $this->access_token );
	}

	/**
	 * Load request queue from persistent storage
	 *
	 * OPTIMIZATION 2: Request queue with priority
	 *
	 * @since  1.0.15
	 * @return void
	 */
	private function load_request_queue() {
		$queue = get_option('rawwire_github_request_queue', array(
			'high' => array(),
			'normal' => array(),
			'low' => array(),
		));
		$this->request_queue = $queue;
	}

	/**
	 * Save request queue to persistent storage
	 *
	 * @since  1.0.15
	 * @return void
	 */
	private function save_request_queue() {
		update_option('rawwire_github_request_queue', $this->request_queue, false);
	}

	/**
	 * Add request to queue with priority
	 *
	 * OPTIMIZATION 2: Request queue with priority
	 *
	 * @since  1.0.15
	 * @param  string $endpoint API endpoint
	 * @param  array  $args Request arguments
	 * @param  string $priority Priority level: high, normal, low
	 * @return int Queue position
	 */
	public function queue_request($endpoint, $args = array(), $priority = 'normal') {
		// OPTIMIZATION 7: Request deduplication
		$request_hash = md5($endpoint . serialize($args));
		
		// Check if request already in queue
		foreach ($this->request_queue as $pri => $requests) {
			foreach ($requests as $request) {
				if ($request['hash'] === $request_hash) {
					return -1; // Already queued
				}
			}
		}
		
		$this->request_queue[$priority][] = array(
			'endpoint' => $endpoint,
			'args' => $args,
			'hash' => $request_hash,
			'queued_at' => time(),
		);
		
		$this->save_request_queue();
		return count($this->request_queue[$priority]) - 1;
	}

	/**
	 * Process next request from queue
	 *
	 * OPTIMIZATION 2: Process queue with priority order
	 *
	 * @since  1.0.15
	 * @return array|false Request result or false if queue empty
	 */
	public function process_next_queued_request() {
		// Process in priority order: high -> normal -> low
		foreach (array('high', 'normal', 'low') as $priority) {
			if (!empty($this->request_queue[$priority])) {
				$request = array_shift($this->request_queue[$priority]);
				$this->save_request_queue();
				
				return $this->request($request['endpoint'], $request['args']);
			}
		}
		
		return false;
	}

	/**
	 * Get queue size
	 *
	 * @since  1.0.15
	 * @return int Total requests in queue
	 */
	public function get_queue_size() {
		return count($this->request_queue['high']) +
		       count($this->request_queue['normal']) +
		       count($this->request_queue['low']);
	}

	/**
	 * Process GitHub webhook
	 *
	 * OPTIMIZATION 5: Webhook integration for real-time updates
	 *
	 * @since  1.0.15
	 * @param  array  $payload Webhook payload
	 * @param  string $signature Webhook signature for validation
	 * @return bool|WP_Error True if processed, error otherwise
	 */
	public function process_webhook($payload, $signature) {
		// Validate webhook signature
		$valid = $this->validate_webhook_signature($payload, $signature);
		if (is_wp_error($valid)) {
			return $valid;
		}
		
		$event = isset($payload['action']) ? $payload['action'] : 'unknown';
		
		if (class_exists('RawWire_Logger')) {
			RawWire_Logger::log_activity(
				'GitHub webhook received',
				'github_webhook',
				array('event' => $event),
				'info'
			);
		}
		
		// Handle different webhook events
		switch ($event) {
			case 'push':
				// Queue data refresh with high priority
				$this->queue_request('/repos/' . $payload['repository']['full_name'] . '/contents', array(), 'high');
				break;
				
			case 'release':
				// Handle new release
				break;
				
			default:
				// Log unknown event
				break;
		}
		
		return true;
	}

	/**
	 * Validate webhook signature
	 *
	 * OPTIMIZATION 5: Webhook security validation
	 *
	 * @since  1.0.15
	 * @param  mixed  $payload Webhook payload
	 * @param  string $signature Signature from GitHub
	 * @return bool|WP_Error
	 */
	public function validate_webhook_signature($payload, $signature) {
		if (empty($this->webhook_secret)) {
			return new WP_Error(
				'missing_webhook_secret',
				'Webhook secret not configured'
			);
		}
		
		$payload_json = is_string($payload) ? $payload : json_encode($payload);
		$expected_signature = 'sha256=' . hash_hmac('sha256', $payload_json, $this->webhook_secret);
		
		if (!hash_equals($expected_signature, $signature)) {
			return new WP_Error(
				'invalid_signature',
				'Webhook signature validation failed'
			);
		}
		
		return true;
	}

	/**
	 * Make API request to GitHub
	 *
	 * @param string $endpoint GitHub API endpoint (without base URL).
	 * @param array  $args Optional request arguments.
	 * @param string $method HTTP method (GET, POST, etc).
	 * @return array|WP_Error
	 */
	public function request( $endpoint, $args = array(), $method = 'GET' ) {
		if ( empty( $this->access_token ) ) {
			return new WP_Error( 'missing_token', 'GitHub API token not configured' );
		}

		// Check rate limiting before making request
		if ( ! $this->check_rate_limit() ) {
			return new WP_Error( 'rate_limit_exceeded', 'GitHub API rate limit exceeded' );
		}

		// Check cache
		$cache_key = $this->get_cache_key( $endpoint, $args, $method );
		if ( isset( $this->cache[ $cache_key ] ) ) {
			$cached = $this->cache[ $cache_key ];
			if ( time() < $cached['expires'] ) {
				return $cached['data'];
			}
			unset( $this->cache[ $cache_key ] );
		}

		$url = $this->api_base_url . $endpoint;

		$request_args = array(
			'method'    => $method,
			'timeout'   => $this->request_timeout,
			'headers'   => $this->get_request_headers(),
			'sslverify' => true,
		);

		if ( 'GET' !== $method && ! empty( $args ) ) {
			$request_args['body'] = wp_json_encode( $args );
		} elseif ( 'GET' === $method && ! empty( $args ) ) {
			$url = add_query_arg( $args, $url );
		}

		$response = $this->make_request_with_retry( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			$this->log_error( 'API Request failed: ' . $response->get_error_message() );
			return $response;
		}

		// Cache the response
		$this->cache[ $cache_key ] = array(
			'data'    => $response,
			'expires' => time() + $this->cache_ttl,
		);

		return $response;
	}

	/**
	 * Make request with retry logic
	 *
	 * @param string $url Request URL.
	 * @param array  $args Request arguments.
	 * @return array|WP_Error
	 */
	private function make_request_with_retry( $url, $args ) {
		$this->retry_count = 0;

		while ( $this->retry_count < $this->max_retries ) {
			$response = wp_remote_request( $url, $args );

			if ( ! is_wp_error( $response ) ) {
				return $this->process_response( $response );
			}

			$this->retry_count++;
			if ( $this->retry_count < $this->max_retries ) {
				sleep( 2 ** $this->retry_count ); // Exponential backoff
			}
		}

		return $response;
	}

	/**
	 * Process HTTP response
	 *
	 * @param array $response HTTP response.
	 * @return array|WP_Error
	 */
	private function process_response( $response ) {
		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$headers     = wp_remote_retrieve_headers( $response );

		// Update rate limit information
		$this->update_rate_limit( $headers );

		// Handle different status codes
		if ( $status_code >= 200 && $status_code < 300 ) {
			return json_decode( $body, true );
		}

		if ( 401 === $status_code ) {
			return new WP_Error( 'auth_failed', 'GitHub authentication failed. Invalid or expired token.' );
		}

		if ( 403 === $status_code ) {
			return new WP_Error( 'forbidden', 'Access forbidden. Check token permissions.' );
		}

		if ( 404 === $status_code ) {
			return new WP_Error( 'not_found', 'GitHub resource not found' );
		}

		if ( 422 === $status_code ) {
			return new WP_Error( 'validation_error', 'GitHub API validation error: ' . $body );
		}

		if ( $status_code >= 500 ) {
			return new WP_Error( 'server_error', 'GitHub API server error (HTTP ' . $status_code . ')' );
		}

		return new WP_Error( 'http_error', 'GitHub API request failed (HTTP ' . $status_code . ')' );
	}

	/**
	 * Get request headers
	 *
	 * @return array
	 */
	private function get_request_headers() {
		return array(
			'Authorization' => 'Bearer ' . $this->access_token,
			'Accept'        => 'application/vnd.github.v3+json',
			'User-Agent'    => 'Raw-Wire-Dashboard/1.0',
			'Content-Type'  => 'application/json',
		);
	}

	/**
	 * Update rate limit status from response headers
	 *
	 * @param array $headers Response headers.
	 */
	private function update_rate_limit( $headers ) {
		if ( isset( $headers['x-ratelimit-limit'] ) ) {
			$this->rate_limit_status['limit'] = (int) $headers['x-ratelimit-limit'];
		}

		if ( isset( $headers['x-ratelimit-remaining'] ) ) {
			$this->rate_limit_status['remaining'] = (int) $headers['x-ratelimit-remaining'];
		}

		if ( isset( $headers['x-ratelimit-reset'] ) ) {
			$this->rate_limit_status['reset'] = (int) $headers['x-ratelimit-reset'];
		}
	}

	/**
	 * Check if we're within rate limits
	 *
	 * @return bool
	 */
	private function check_rate_limit() {
		// If remaining requests is more than 0, we're good
		if ( $this->rate_limit_status['remaining'] > 0 ) {
			return true;
		}

		// Check if rate limit window has passed
		if ( time() > $this->rate_limit_status['reset'] ) {
			return true;
		}

		return false;
	}

	/**
	 * Get current rate limit status
	 *
	 * @return array
	 */
	public function get_rate_limit_status() {
		return $this->rate_limit_status;
	}

	/**
	 * Get cache key for request
	 *
	 * @param string $endpoint API endpoint.
	 * @param array  $args Request arguments.
	 * @param string $method HTTP method.
	 * @return string
	 */
	private function get_cache_key( $endpoint, $args, $method ) {
		return md5( $endpoint . wp_json_encode( $args ) . $method );
	}

	/**
	 * Clear API response cache
	 *
	 * @param string $endpoint Optional specific endpoint to clear.
	 */
	public function clear_cache( $endpoint = '' ) {
		if ( empty( $endpoint ) ) {
			$this->cache = array();
		} else {
			foreach ( $this->cache as $key => $cached ) {
				if ( strpos( $key, $endpoint ) !== false ) {
					unset( $this->cache[ $key ] );
				}
			}
		}
	}

	/**
	 * Add item to deduplication tracking
	 *
	 * @param string $identifier Unique identifier (e.g., issue ID).
	 * @param array  $data Item data.
	 * @return bool
	 */
	public function add_item( $identifier, $data = array() ) {
		$hash = $this->generate_hash( $identifier, $data );

		if ( isset( $this->seen_hashes[ $hash ] ) ) {
			return false; // Item already seen
		}

		$this->seen_hashes[ $hash ] = array(
			'identifier' => $identifier,
			'timestamp'  => time(),
			'data_hash'  => $this->hash_data( $data ),
		);

		$this->save_seen_hashes();
		return true;
	}

	/**
	 * Check if item has been seen before
	 *
	 * @param string $identifier Unique identifier.
	 * @param array  $data Item data.
	 * @return bool
	 */
	public function is_duplicate( $identifier, $data = array() ) {
		$hash = $this->generate_hash( $identifier, $data );
		return isset( $this->seen_hashes[ $hash ] );
	}

	/**
	 * Generate hash for item
	 *
	 * @param string $identifier Unique identifier.
	 * @param array  $data Item data.
	 * @return string
	 */
	private function generate_hash( $identifier, $data ) {
		return md5( $identifier . $this->hash_data( $data ) );
	}

	/**
	 * Hash data array
	 *
	 * @param array $data Data to hash.
	 * @return string
	 */
	private function hash_data( $data ) {
		return wp_json_encode( $data );
	}

	/**
	 * Load seen hashes from database
	 */
	private function load_seen_hashes() {
		$hashes = get_option( 'raw_wire_github_seen_hashes', array() );
		$this->seen_hashes = is_array( $hashes ) ? $hashes : array();

		// Clean up old entries (older than 30 days)
		$this->cleanup_old_hashes( 30 * DAY_IN_SECONDS );
	}

	/**
	 * Save seen hashes to database
	 */
	private function save_seen_hashes() {
		update_option( 'raw_wire_github_seen_hashes', $this->seen_hashes );
	}

	/**
	 * Clean up old deduplication hashes
	 *
	 * @param int $max_age Maximum age in seconds.
	 */
	private function cleanup_old_hashes( $max_age ) {
		$current_time = time();
		$cleaned      = false;

		foreach ( $this->seen_hashes as $hash => $entry ) {
			if ( isset( $entry['timestamp'] ) && ( $current_time - $entry['timestamp'] ) > $max_age ) {
				unset( $this->seen_hashes[ $hash ] );
				$cleaned = true;
			}
		}

		if ( $cleaned ) {
			$this->save_seen_hashes();
		}
	}

	/**
	 * Clear all deduplication hashes
	 */
	public function clear_deduplication_cache() {
		$this->seen_hashes = array();
		delete_option( 'raw_wire_github_seen_hashes' );
	}

	/**
	 * Get repositories for a user or organization
	 *
	 * @param string $owner Repository owner (user or org).
	 * @param int    $per_page Results per page.
	 * @return array|WP_Error
	 */
	public function get_repositories( $owner, $per_page = 30 ) {
		if ( empty( $owner ) ) {
			return new WP_Error( 'invalid_owner', 'Owner parameter is required' );
		}

		$endpoint = '/users/' . sanitize_text_field( $owner ) . '/repos';
		$args     = array(
			'per_page' => min( $per_page, 100 ),
			'sort'     => 'updated',
			'direction' => 'desc',
		);

		return $this->request( $endpoint, $args );
	}

	/**
	 * Get issues for a repository
	 *
	 * @param string $owner Repository owner.
	 * @param string $repo Repository name.
	 * @param array  $filters Optional filters.
	 * @return array|WP_Error
	 */
	public function get_issues( $owner, $repo, $filters = array() ) {
		if ( empty( $owner ) || empty( $repo ) ) {
			return new WP_Error( 'invalid_params', 'Owner and repo parameters are required' );
		}

		$endpoint = '/repos/' . sanitize_text_field( $owner ) . '/' . sanitize_text_field( $repo ) . '/issues';
		$args     = wp_parse_args(
			$filters,
			array(
				'state'    => 'all',
				'per_page' => 30,
				'sort'     => 'updated',
			)
		);

		return $this->request( $endpoint, $args );
	}

	/**
	 * Get pull requests for a repository
	 *
	 * @param string $owner Repository owner.
	 * @param string $repo Repository name.
	 * @param array  $filters Optional filters.
	 * @return array|WP_Error
	 */
	public function get_pull_requests( $owner, $repo, $filters = array() ) {
		if ( empty( $owner ) || empty( $repo ) ) {
			return new WP_Error( 'invalid_params', 'Owner and repo parameters are required' );
		}

		$endpoint = '/repos/' . sanitize_text_field( $owner ) . '/' . sanitize_text_field( $repo ) . '/pulls';
		$args     = wp_parse_args(
			$filters,
			array(
				'state'    => 'all',
				'per_page' => 30,
				'sort'     => 'updated',
			)
		);

		return $this->request( $endpoint, $args );
	}

	/**
	 * Get commits for a repository
	 *
	 * @param string $owner Repository owner.
	 * @param string $repo Repository name.
	 * @param array  $filters Optional filters.
	 * @return array|WP_Error
	 */
	public function get_commits( $owner, $repo, $filters = array() ) {
		if ( empty( $owner ) || empty( $repo ) ) {
			return new WP_Error( 'invalid_params', 'Owner and repo parameters are required' );
		}

		$endpoint = '/repos/' . sanitize_text_field( $owner ) . '/' . sanitize_text_field( $repo ) . '/commits';
		$args     = wp_parse_args(
			$filters,
			array(
				'per_page' => 30,
			)
		);

		return $this->request( $endpoint, $args );
	}

	/**
	 * Get repository details
	 *
	 * @param string $owner Repository owner.
	 * @param string $repo Repository name.
	 * @return array|WP_Error
	 */
	public function get_repository( $owner, $repo ) {
		if ( empty( $owner ) || empty( $repo ) ) {
			return new WP_Error( 'invalid_params', 'Owner and repo parameters are required' );
		}

		$endpoint = '/repos/' . sanitize_text_field( $owner ) . '/' . sanitize_text_field( $repo );

		return $this->request( $endpoint );
	}

	/**
	 * Get authenticated user information
	 *
	 * @return array|WP_Error
	 */
	public function get_authenticated_user() {
		return $this->request( '/user' );
	}

	/**
	 * Validate GitHub token
	 *
	 * @param string $token Token to validate.
	 * @return bool
	 */
	public function validate_token( $token ) {
		$original_token = $this->access_token;
		$this->access_token = $token;

		$user = $this->get_authenticated_user();
		$this->access_token = $original_token;

		return ! is_wp_error( $user );
	}

	/**
	 * Log error message
	 *
	 * @param string $message Error message.
	 */
	private function log_error( $message ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[Raw Wire GitHub Crawler] ' . $message );
		}

		// Store in WordPress options for admin review
		$errors = get_option( 'raw_wire_github_errors', array() );
		$errors[] = array(
			'timestamp' => current_time( 'mysql' ),
			'message'   => $message,
		);

		// Keep only last 100 errors
		if ( count( $errors ) > 100 ) {
			$errors = array_slice( $errors, -100 );
		}

		update_option( 'raw_wire_github_errors', $errors );
	}

	/**
	 * Get logged errors
	 *
	 * @param int $limit Number of errors to retrieve.
	 * @return array
	 */
	public function get_errors( $limit = 10 ) {
		$errors = get_option( 'raw_wire_github_errors', array() );
		return array_slice( $errors, -$limit );
	}

	/**
	 * Clear logged errors
	 */
	public function clear_errors() {
		delete_option( 'raw_wire_github_errors' );
	}

	/**
	 * Set cache TTL
	 *
	 * @param int $ttl Cache time-to-live in seconds.
	 */
	public function set_cache_ttl( $ttl ) {
		if ( is_int( $ttl ) && $ttl > 0 ) {
			$this->cache_ttl = $ttl;
		}
	}

	/**
	 * Set request timeout
	 *
	 * @param int $timeout Timeout in seconds.
	 */
	public function set_request_timeout( $timeout ) {
		if ( is_int( $timeout ) && $timeout > 0 ) {
			$this->request_timeout = $timeout;
		}
	}

	/**
	 * Set max retries
	 *
	 * @param int $retries Maximum number of retries.
	 */
	public function set_max_retries( $retries ) {
		if ( is_int( $retries ) && $retries >= 0 ) {
			$this->max_retries = $retries;
		}
	}

	/**
	 * Destructor - cleanup
	 */
	public function __destruct() {
		$this->cache = array();
	}
}
