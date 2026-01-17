<?php
/**
 * GitHub Fetcher Class
 *
 * Handles fetching Federal Register data from the GitHub repository.
 * Manages API authentication, rate limiting, and error handling.
 *
 * @package    RawWire_Dashboard
 * @subpackage RawWire_Dashboard/includes
 * @since      1.0.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RawWire_GitHub_Fetcher Class
 *
 * Fetches and processes Federal Register findings from GitHub repository.
 * Implements caching and error handling for API calls.
 * 
 * @since 1.0.0
 */
class RawWire_GitHub_Fetcher {

	/**
	 * GitHub API base URL
	 *
	 * @since  1.0.0
	 * @var    string
	 */
	private $api_url = 'https://api.github.com';

	/**
	 * GitHub repository owner
	 *
	 * @since  1.0.0
	 * @var    string
	 */
	private $repo_owner = 'raw-wire-dao-llc';

	/**
	 * GitHub repository name
	 *
	 * @since  1.0.0
	 * @var    string
	 */
	private $repo_name = 'raw-wire-core';

	/**
	 * GitHub API token
	 *
	 * @since  1.0.0
	 * @var    string
	 */
	private $token;

	/**
	 * Circuit breaker state
	 *
	 * @since  1.0.15
	 * @var    string
	 */
	private $circuit_state = 'closed';

	/**
	 * Circuit breaker failure threshold
	 *
	 * @since  1.0.15
	 * @var    int
	 */
	private $failure_threshold = 5;

	/**
	 * Circuit breaker timeout (seconds)
	 *
	 * @since  1.0.15
	 * @var    int
	 */
	private $circuit_timeout = 60;

	/**
	 * Multiple token pool for rotation
	 *
	 * @since  1.0.15
	 * @var    array
	 */
	private $token_pool = array();

	/**
	 * Current token index
	 *
	 * @since  1.0.15
	 * @var    int
	 */
	private $current_token_index = 0;

	/**
	 * Constructor
	 *
	 * Initializes the fetcher with GitHub credentials.
	 *
	 * @since  1.0.0
	 */
	public function __construct() {
		$this->token = $this->get_github_token();
		$this->token_pool = $this->get_token_pool();
		$this->load_circuit_breaker_state();
	}

	/**
	 * Get token pool for rotation
	 *
	 * OPTIMIZATION 6: Token rotation support
	 *
	 * @since  1.0.15
	 * @return array
	 */
	private function get_token_pool() {
		$tokens = array();
		
		// Primary token
		if (!empty($this->token)) {
			$tokens[] = $this->token;
		}
		
		// Additional tokens from option
		$additional = get_option('rawwire_github_tokens_pool', array());
		if (is_array($additional)) {
			$tokens = array_merge($tokens, $additional);
		}
		
		return array_filter($tokens);
	}

	/**
	 * Load circuit breaker state
	 *
	 * OPTIMIZATION 1: Circuit breaker pattern
	 *
	 * @since  1.0.15
	 * @return void
	 */
	private function load_circuit_breaker_state() {
		$state = get_transient('rawwire_github_circuit_breaker');
		if ($state !== false) {
			$this->circuit_state = $state['state'];
			
			// Check if timeout has passed for 'open' state
			if ($this->circuit_state === 'open' && time() > $state['open_until']) {
				$this->circuit_state = 'half-open';
				$this->save_circuit_breaker_state();
			}
		}
	}

	/**
	 * Save circuit breaker state
	 *
	 * @since  1.0.15
	 * @return void
	 */
	private function save_circuit_breaker_state() {
		$state = array(
			'state' => $this->circuit_state,
			'failures' => get_option('rawwire_github_failures', 0),
			'open_until' => time() + $this->circuit_timeout,
		);
		set_transient('rawwire_github_circuit_breaker', $state, $this->circuit_timeout + 60);
	}

	/**
	 * Check if circuit breaker allows request
	 *
	 * @since  1.0.15
	 * @return bool|WP_Error
	 */
	private function check_circuit_breaker() {
		if ($this->circuit_state === 'open') {
			return new WP_Error(
				'circuit_breaker_open',
				'GitHub API circuit breaker is open due to repeated failures. Please try again later.',
				array('retry_after' => $this->circuit_timeout)
			);
		}
		return true;
	}

	/**
	 * Record request success
	 *
	 * @since  1.0.15
	 * @return void
	 */
	private function record_success() {
		if ($this->circuit_state === 'half-open') {
			$this->circuit_state = 'closed';
			update_option('rawwire_github_failures', 0);
			delete_transient('rawwire_github_circuit_breaker');
			RawWire_Logger::log_activity('Circuit breaker closed', 'github', array(), 'info');
		}
	}

	/**
	 * Record request failure
	 *
	 * @since  1.0.15
	 * @return void
	 */
	private function record_failure() {
		$failures = get_option('rawwire_github_failures', 0) + 1;
		update_option('rawwire_github_failures', $failures);
		
		if ($failures >= $this->failure_threshold) {
			$this->circuit_state = 'open';
			$this->save_circuit_breaker_state();
			RawWire_Logger::log_error(
				'Circuit breaker opened due to repeated failures',
				array('failures' => $failures),
				'critical'
			);
		}
	}

	/**
	 * Rotate to next available token
	 *
	 * OPTIMIZATION 6: Token rotation
	 *
	 * @since  1.0.15
	 * @return string|null
	 */
	private function rotate_token() {
		if (empty($this->token_pool)) {
			return null;
		}
		
		$this->current_token_index = ($this->current_token_index + 1) % count($this->token_pool);
		$this->token = $this->token_pool[$this->current_token_index];
		
		RawWire_Logger::debug(
			'Rotated to token index ' . $this->current_token_index,
			array(),
			'github'
		);
		
		return $this->token;
	}

	/**
	 * Get GitHub token from configuration
	 *
	 * Retrieves token from constant or WordPress option.
	 * TODO: Implement secure token storage and rotation (see SECRETS.md)
	 *
	 * @since  1.0.0
	 * @return string GitHub API token
	 */
	private function get_github_token() {
		// Check for constant first (recommended for production)
		if ( defined( 'RAWWIRE_GITHUB_TOKEN' ) && RAWWIRE_GITHUB_TOKEN ) {
			return RAWWIRE_GITHUB_TOKEN;
		}

		// Fall back to WordPress option
		$token = get_option( 'rawwire_github_token', '' );
		return trim( $token );
	}

	/**
	 * Fetch Federal Register findings from GitHub
	 *
	 * Main method to retrieve findings data from the GitHub repository.
	 * Implements caching to reduce API calls and respect rate limits.
	 *
	 * TODO: Implement according to DASHBOARD_SPEC.md
	 * - Parse Federal Register JSON data structure
	 * - Handle pagination for large datasets
	 * - Store findings in wp_rawwire_content table
	 * - Update relevance scores
	 *
	 * @since  1.0.0
	 * @param  bool  $force_refresh Force bypass cache and fetch fresh data
	 * @return array|WP_Error Array of findings or WP_Error on failure
	 */
	public function fetch_findings( $force_refresh = false ) {
		// Log fetch attempt
		RawWire_Logger::log_activity( 'Fetching findings from GitHub', 'fetch', array( 'force_refresh' => $force_refresh ), 'info' );

		// TODO: Check cache first if not forcing refresh
		// if ( ! $force_refresh ) {
		//     $cached = RawWire_Cache_Manager::get_cache( 'github_findings' );
		//     if ( $cached !== false ) {
		//         RawWire_Logger::log_activity( 'Returned findings from cache', 'fetch', array(), 'info' );
		//         return $cached;
		//     }
		// }

		// Validate token
		if ( empty( $this->token ) ) {
			$error_message = 'GitHub token not configured. Please set RAWWIRE_GITHUB_TOKEN constant or configure in settings.';
			RawWire_Logger::log_error( $error_message, array(), 'error' );
			return new WP_Error( 'missing_token', $error_message );
		}

		// TODO: Build API request to fetch repository contents
		// Example path: /repos/{owner}/{repo}/contents/federal-register-findings
		// See DASHBOARD_SPEC.md for expected data structure

		$endpoint = sprintf(
			'%s/repos/%s/%s/contents/federal-register-findings',
			$this->api_url,
			$this->repo_owner,
			$this->repo_name
		);

		// Prepare request
		$args = array(
			'headers' => array(
				'Authorization' => 'token ' . $this->token,
				'User-Agent'    => 'RawWire-Dashboard/1.0',
				'Accept'        => 'application/vnd.github.v3+json',
			),
			'timeout' => 30,
		);

		// OPTIMIZATION 1: Check circuit breaker
		$circuit_check = $this->check_circuit_breaker();
		if (is_wp_error($circuit_check)) {
			return $circuit_check;
		}

		// OPTIMIZATION 3: Add ETag support for conditional requests
		$etag_key = 'rawwire_github_etag_' . md5($endpoint);
		$stored_etag = get_transient($etag_key);
		if ($stored_etag && !$force_refresh) {
			$args['headers']['If-None-Match'] = $stored_etag;
		}

		// OPTIMIZATION 8: Add compression support
		$args['headers']['Accept-Encoding'] = 'gzip';

		// OPTIMIZATION 4: Add streaming for large responses
		$args['stream'] = true;
		$args['filename'] = wp_tempnam('github-response-');

		// Make API request
		$response = wp_remote_get( $endpoint, $args );

		// Check for errors
		if ( is_wp_error( $response ) ) {
			RawWire_Logger::log_error(
				'GitHub API request failed',
				array(
					'endpoint' => $endpoint,
					'error'    => $response->get_error_message(),
				),
				'error'
			);
			return $response;
		}

		// Check response code
		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			$error_message = sprintf( 'GitHub API returned status code %d', $response_code );
			RawWire_Logger::log_error(
				$error_message,
				array(
					'endpoint'      => $endpoint,
					'response_code' => $response_code,
					'response_body' => wp_remote_retrieve_body( $response ),
				),
				'error'
			);
			return new WP_Error( 'api_error', $error_message, array( 'status' => $response_code ) );
		}

		// Parse response
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			RawWire_Logger::log_error(
				'Failed to parse GitHub API response',
				array(
					'json_error' => json_last_error_msg(),
				),
				'error'
			);
			return new WP_Error( 'parse_error', 'Failed to parse API response' );
		}

		// TODO: Process and store findings
		// $processor = new RawWire_Data_Processor();
		// $processed = $processor->process_findings( $data );

		// TODO: Cache the results
		// RawWire_Cache_Manager::set_cache( 'github_findings', $data, 3600 ); // 1 hour

		RawWire_Logger::log_activity(
			'Successfully fetched findings from GitHub',
			'fetch',
			array( 'count' => is_array( $data ) ? count( $data ) : 0 ),
			'info'
		);

		return $data;
	}

	/**
	 * Get GitHub sync status
	 *
	 * Returns information about the last sync operation.
	 *
	 * TODO: Implement according to DASHBOARD_SPEC.md
	 * - Track last sync timestamp
	 * - Count successful/failed operations
	 * - Return error details if applicable
	 *
	 * @since  1.0.0
	 * @return array Sync status information
	 */
	public function get_sync_status() {
		// TODO: Implement sync status tracking
		// Query last log entry of type 'fetch'
		// Return: last_sync_time, status, error_count, success_count

		return array(
			'last_sync'     => get_option( 'rawwire_last_sync', null ),
			'status'        => 'not_implemented',
			'message'       => 'Sync status tracking pending implementation',
			'error_count'   => 0,
			'success_count' => 0,
		);
	}

	/**
	 * Validate GitHub token
	 *
	 * Checks if the configured token is valid by making a test API call.
	 *
	 * @since  1.0.0
	 * @return bool|WP_Error True if valid, WP_Error if invalid
	 */
	public function validate_token() {
		if ( empty( $this->token ) ) {
			return new WP_Error( 'missing_token', 'No GitHub token configured' );
		}

		$args = array(
			'headers' => array(
				'Authorization' => 'token ' . $this->token,
				'User-Agent'    => 'RawWire-Dashboard/1.0',
			),
			'timeout' => 15,
		);

		$response = wp_remote_get( $this->api_url . '/user', $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			return new WP_Error( 'invalid_token', 'GitHub token validation failed', array( 'status' => $response_code ) );
		}

		return true;
	}

	/**
	 * Get rate limit information
	 *
	 * Retrieves current GitHub API rate limit status.
	 *
	 * @since  1.0.0
	 * @return array|WP_Error Rate limit information or error
	 */
	public function get_rate_limit() {
		if ( empty( $this->token ) ) {
			return new WP_Error( 'missing_token', 'No GitHub token configured' );
		}

		$args = array(
			'headers' => array(
				'Authorization' => 'token ' . $this->token,
				'User-Agent'    => 'RawWire-Dashboard/1.0',
			),
			'timeout' => 15,
		);

		$response = wp_remote_get( $this->api_url . '/rate_limit', $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		return $data;
	}
}
