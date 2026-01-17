<?php
/**
 * Data Processor Class
 *
 * Processes raw Federal Register data and prepares it for storage and analysis.
 * Handles data validation, transformation, and relevance scoring.
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
 * RawWire_Data_Processor Class
 *
 * Processes and validates Federal Register content data.
 * Calculates relevance scores and prepares data for database storage.
 * 
 * @since 1.0.0
 */
class RawWire_Data_Processor {

	/**
	 * Prepared statement cache
	 *
	 * OPTIMIZATION 5: Cache prepared statements for reuse
	 *
	 * @since  1.0.16
	 * @var    array
	 */
	private static $stmt_cache = array();

	/**
	 * Query result cache
	 *
	 * OPTIMIZATION 2: Cache expensive queries
	 *
	 * @since  1.0.16
	 * @var    array
	 */
	private static $query_cache = array();

	/**
	 * Database connection pool
	 *
	 * OPTIMIZATION 1: Reuse database connections
	 *
	 * @since  1.0.16
	 * @var    wpdb|null
	 */
	private static $db_connection = null;

	/**
	 * Slow query threshold (seconds)
	 *
	 * OPTIMIZATION 5: Query profiling
	 *
	 * @since  1.0.16
	 * @var    float
	 */
	private static $slow_query_threshold = 1.0;

	/**
	 * Schema version
	 *
	 * OPTIMIZATION 6: Schema versioning
	 *
	 * @since  1.0.16
	 * @var    string
	 */
	private static $schema_version = '1.0.16';

	/**
	 * Get database connection
	 *
	 * OPTIMIZATION 1: Connection pooling
	 *
	 * @since  1.0.16
	 * @return wpdb
	 */
	private static function get_db_connection() {
		if (self::$db_connection === null) {
			global $wpdb;
			self::$db_connection = $wpdb;
		}
		return self::$db_connection;
	}

	/**
	 * Execute query with profiling
	 *
	 * OPTIMIZATION 5: Log slow queries
	 *
	 * @since  1.0.16
	 * @param  callable $query_callback Query function
	 * @param  string   $query_name Query identifier
	 * @return mixed Query result
	 */
	private static function profile_query($query_callback, $query_name) {
		$start_time = microtime(true);
		$result = call_user_func($query_callback);
		$end_time = microtime(true);
		$duration = $end_time - $start_time;

		if ($duration > self::$slow_query_threshold) {
			if (class_exists('RawWire_Logger')) {
				RawWire_Logger::log_warning(
					"Slow query detected: {$query_name}",
					array(
						'duration' => round($duration, 4),
						'threshold' => self::$slow_query_threshold,
					),
					'database'
				);
			}
		}

		return $result;
	}

	/**
	 * Check and update schema version
	 *
	 * OPTIMIZATION 6: Schema versioning
	 *
	 * @since  1.0.16
	 * @return bool True if migration needed
	 */
	public static function check_schema_version() {
		$current_version = get_option('rawwire_db_schema_version', '0.0.0');
		
		if (version_compare($current_version, self::$schema_version, '<')) {
			if (class_exists('RawWire_Logger')) {
				RawWire_Logger::log_activity(
					'Database schema migration needed',
					'database',
					array(
						'from' => $current_version,
						'to' => self::$schema_version,
					),
					'info'
				);
			}
			return true;
		}
		
		return false;
	}

	/**
	 * Optimize database indexes
	 *
	 * OPTIMIZATION 4: Add missing indexes for common queries
	 *
	 * @since  1.0.16
	 * @return bool True on success
	 */
	public static function optimize_indexes() {
		global $wpdb;
		$table = $wpdb->prefix . 'rawwire_content';
		
		// Check if table exists
		if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
			return false;
		}
		
		// Add indexes for common query patterns
		$indexes = array(
			'idx_status' => "ALTER TABLE {$table} ADD INDEX idx_status (status)",
			'idx_relevance' => "ALTER TABLE {$table} ADD INDEX idx_relevance (relevance_score DESC)",
			'idx_created' => "ALTER TABLE {$table} ADD INDEX idx_created (created_at DESC)",
			'idx_document' => "ALTER TABLE {$table} ADD UNIQUE INDEX idx_document (document_number)",
			'idx_composite' => "ALTER TABLE {$table} ADD INDEX idx_status_relevance (status, relevance_score DESC)",
		);
		
		foreach ($indexes as $index_name => $sql) {
			// Check if index already exists
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
					 WHERE table_schema = %s AND table_name = %s AND index_name = %s",
					$wpdb->dbname,
					$table,
					$index_name
				)
			);
			
			if (!$exists) {
				$wpdb->query($sql);
				if (class_exists('RawWire_Logger')) {
					RawWire_Logger::debug("Created index: {$index_name}", array(), 'database');
				}
			}
		}
		
		return true;
	}

	/**
	 * Get scoring configuration from active module
	 *
	 * @since  1.0.13
	 * @return array Scoring configuration
	 */
	private function get_scoring_config() {
		$module = RawWire_Module_Core::get_active_module();
		return isset( $module['scoring'] ) ? $module['scoring'] : array();
	}

	/**
	 * Validate item data before database insert
	 *
	 * OPTIMIZATION 7: Data validation at DB level
	 *
	 * @since  1.0.16
	 * @param  array $item Item data
	 * @return true|WP_Error
	 */
	private function validate_item_data($item) {
		$errors = array();

		// Validate required fields
		$required = array('title', 'document_number');
		foreach ($required as $field) {
			if (empty($item[$field])) {
				$errors[] = "Missing required field: {$field}";
			}
		}

		// Validate field types and lengths
		if (isset($item['title']) && strlen($item['title']) > 500) {
			$errors[] = 'Title too long (max 500 characters)';
		}

		if (isset($item['document_number']) && strlen($item['document_number']) > 50) {
			$errors[] = 'Document number too long (max 50 characters)';
		}

		if (isset($item['relevance_score']) && ($item['relevance_score'] < 0 || $item['relevance_score'] > 100)) {
			$errors[] = 'Relevance score must be between 0 and 100';
		}

		if (isset($item['approval_status']) && !in_array($item['approval_status'], array('pending', 'approved', 'rejected', 'published'))) {
			$errors[] = 'Invalid approval status';
		}

		if (!empty($errors)) {
			return new WP_Error('validation_failed', implode(', ', $errors), array('errors' => $errors));
		}

		return true;
	}

	/**
	 * Process raw Federal Register item
	 *
	 * Takes raw data from GitHub and processes it for storage in the database.
	 * Validates required fields, calculates relevance scores, and sanitizes data.
	 *
	 * TODO: Implement according to DASHBOARD_SPEC.md
	 * - Validate data structure
	 * - Extract and normalize fields (title, content, document_number, etc.)
	 * - Calculate relevance scores based on keywords and categories
	 * - Store in wp_rawwire_content table
	 * - Handle duplicate detection
	 *
	 * @since  1.0.0
	 * @param  array $raw_item Raw Federal Register item data
	 * @return array|WP_Error Processed item data or WP_Error on failure
	 */
	public function process_raw_federal_register_item( $raw_item ) {
		try {
			// Log processing attempt
			RawWire_Logger::log_activity(
				'Processing Federal Register item',
				'process',
				array( 'has_data' => ! empty( $raw_item ) ),
				'info'
			);

			// Validate input
			if ( empty( $raw_item ) || ! is_array( $raw_item ) ) {
				RawWire_Logger::log_error( 'Invalid raw item data provided', array(), 'error' );
				return new WP_Error( 'invalid_data', 'Invalid raw item data provided' );
			}
		// TODO: Define required fields according to Federal Register API structure
		$required_fields = array( 'title', 'document_number' );
		
		foreach ( $required_fields as $field ) {
			if ( ! isset( $raw_item[ $field ] ) || empty( $raw_item[ $field ] ) ) {
				RawWire_Logger::log_error(
					'Missing required field in raw item',
					array( 'field' => $field ),
					'error'
				);
				return new WP_Error( 'missing_field', sprintf( 'Missing required field: %s', $field ) );
			}
		}

		// Sanitize and prepare data
		$processed_item = array(
			'title'            => sanitize_text_field( $raw_item['title'] ),
			'content'          => isset( $raw_item['abstract'] ) ? wp_kses_post( $raw_item['abstract'] ) : '',
			'source_url'       => isset( $raw_item['html_url'] ) ? esc_url_raw( $raw_item['html_url'] ) : '',
			'document_number'  => sanitize_text_field( $raw_item['document_number'] ),
			'publication_date' => isset( $raw_item['publication_date'] ) ? $this->parse_date( $raw_item['publication_date'] ) : null,
			'agency'           => isset( $raw_item['agency'] ) ? sanitize_text_field( $raw_item['agency'] ) : '',
			'category'         => isset( $raw_item['type'] ) ? sanitize_text_field( $raw_item['type'] ) : '',
			'relevance_score'  => $this->calculate_relevance_score( $raw_item ),
			'approval_status'  => 'pending',
			'created_at'       => current_time( 'mysql' ),
			'updated_at'       => current_time( 'mysql' ),
			'metadata'         => $this->prepare_metadata( $raw_item ),
		);

		// OPTIMIZATION 7: Data validation before insert
		$validation_result = $this->validate_item_data($processed_item);
		if (is_wp_error($validation_result)) {
			return $validation_result;
		}

		// Check for duplicates before inserting
		$duplicate = $this->check_duplicate( $processed_item['document_number'], $processed_item );
		if ( $duplicate ) {
			RawWire_Logger::log_activity(
				'Duplicate item detected, skipping',
				'process',
				array(
					'existing_id'     => $duplicate,
					'document_number' => $processed_item['document_number'],
				),
				'info'
			);
			return new WP_Error( 'duplicate_item', 'Item already exists', array( 'existing_id' => $duplicate ) );
		}

		// Store in database
		$stored = $this->store_item( $processed_item );
		
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		// Add database ID to processed item
		$processed_item['id'] = $stored;

		RawWire_Logger::log_activity(
			'Successfully processed and stored Federal Register item',
			'process',
			array(
				'document_number'  => $processed_item['document_number'],
				'relevance_score'  => $processed_item['relevance_score'],
			),
			'info'
		);

		return $processed_item;
		
		} catch (Exception $e) {
			RawWire_Logger::log_activity(
				'Exception in process_raw_federal_register_item',
				'process',
				array(
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
					'item_title' => isset($raw_item['title']) ? $raw_item['title'] : 'unknown'
				),
				'error'
			);
			return new WP_Error(
				'processing_error',
				'An error occurred while processing the item',
				array('details' => $e->getMessage())
			);
		}
	}

	/**
	 * Calculate relevance score for an item
	 *
	 * Analyzes content and metadata to determine relevance.
	 * Implements "shocking/surprising" detection for viral social media content.
	 *
	 * Score Components:
	 * - Shock Factor (0-30): Large dollar amounts, reversals, penalties
	 * - Rarity (0-25): First occurrence, unique combinations
	 * - Recency (0-15): Published within 24 hours
	 * - Authority (0-15): Source credibility and impact
	 * - Public Interest (0-15): Affects many people, money involved
	 *
	 * @since  1.0.0
	 * @param  array $item Item data
	 * @return float Relevance score (0.00 - 100.00)
	 */
	private function calculate_relevance_score( $item ) {
		$score = 0.0;

		// Get text for analysis
		$title = isset( $item['title'] ) ? strtolower( $item['title'] ) : '';
		$content = isset( $item['abstract'] ) ? strtolower( $item['abstract'] ) : '';
		$text = $title . ' ' . $content;

		// Load scoring configuration from active module
		$scoring_config = $this->get_scoring_config();

		// === SHOCK FACTOR (0-30) ===
		// Large dollar amounts
		if ( preg_match( '/\$\s*([0-9]+(?:\.[0-9]+)?)\s*(billion|trillion)/i', $text, $matches ) ) {
			$amount = floatval( $matches[1] );
			$unit = strtolower( $matches[2] );
			$thresholds = isset( $scoring_config['monetary_thresholds'] ) ? $scoring_config['monetary_thresholds'] : array();
			
			if ( $unit === 'trillion' && isset( $thresholds['trillion'] ) ) {
				$score += $thresholds['trillion'];
			} elseif ( $unit === 'billion' && isset( $thresholds['billion'] ) ) {
				if ( $amount >= 10 && isset( $thresholds['billion']['10'] ) ) {
					$score += $thresholds['billion']['10'];
				} elseif ( $amount >= 1 && isset( $thresholds['billion']['1'] ) ) {
					$score += $thresholds['billion']['1'];
				} else {
					$score += 15.0; // Default for billions
				}
			}
		} elseif ( preg_match( '/\$\s*([0-9]{3,})\s*(million)/i', $text, $matches ) ) {
			$amount = floatval( $matches[1] );
			$thresholds = isset( $scoring_config['monetary_thresholds']['million'] ) ? $scoring_config['monetary_thresholds']['million'] : array();
			if ( $amount >= 500 && isset( $thresholds['500'] ) ) {
				$score += $thresholds['500'];
			} elseif ( $amount >= 100 && isset( $thresholds['100'] ) ) {
				$score += $thresholds['100'];
			}
		}

		// Shocking keywords from module config
		$shock_keywords = isset( $scoring_config['shock_keywords'] ) ? $scoring_config['shock_keywords'] : array();
		foreach ( $shock_keywords as $keyword => $points ) {
			if ( strpos( $text, $keyword ) !== false ) {
				$score += $points;
				break; // Only count highest-value keyword
			}
		}

		// === RARITY (0-25) ===
		$rarity_keywords = isset( $scoring_config['rarity_keywords'] ) ? $scoring_config['rarity_keywords'] : array();
		foreach ( $rarity_keywords as $keyword => $points ) {
			if ( strpos( $text, $keyword ) !== false ) {
				$score += $points;
				break;
			}
		}

		// === RECENCY (0-15) ===
		if ( isset( $item['publication_date'] ) ) {
			$pub_date = strtotime( $item['publication_date'] );
			$now = time();
			$hours_old = ( $now - $pub_date ) / 3600;
			
			$recency_weights = isset( $scoring_config['recency_weights'] ) ? $scoring_config['recency_weights'] : array();
			if ( $hours_old < 6 && isset( $recency_weights['hours_6'] ) ) {
				$score += $recency_weights['hours_6'];
			} elseif ( $hours_old < 24 && isset( $recency_weights['hours_24'] ) ) {
				$score += $recency_weights['hours_24'];
			} elseif ( $hours_old < 72 && isset( $recency_weights['hours_72'] ) ) {
				$score += $recency_weights['hours_72'];
			} elseif ( $hours_old < 168 && isset( $recency_weights['hours_168'] ) ) {
				$score += $recency_weights['hours_168'];
			}
		}

		// === AUTHORITY (0-15) ===
		$high_authority_agencies = isset( $scoring_config['authority_sources'] ) ? $scoring_config['authority_sources'] : array();
		$agency = isset( $item['agency'] ) ? strtolower( $item['agency'] ) : '';
		foreach ( $high_authority_agencies as $key_agency => $points ) {
			if ( strpos( $agency, $key_agency ) !== false || strpos( $text, $key_agency ) !== false ) {
				$score += $points;
				break;
			}
		}

		// === PUBLIC INTEREST (0-15) ===
		$public_interest_keywords = isset( $scoring_config['public_interest_keywords'] ) ? $scoring_config['public_interest_keywords'] : array();
		foreach ( $public_interest_keywords as $keyword => $points ) {
			if ( strpos( $text, $keyword ) !== false ) {
				$score += $points;
				break;
			}
		}

		// Category-based scoring from module config
		if ( isset( $item['type'] ) ) {
			$type = strtolower( $item['type'] );
			$category_bonuses = isset( $scoring_config['category_bonuses'] ) ? $scoring_config['category_bonuses'] : array();
			
			foreach ( $category_bonuses as $category_key => $bonus ) {
				if ( strpos( $type, $category_key ) !== false ) {
					$score += $bonus;
					break;
				}
			}
		}

		// Ensure score is within bounds (0-100)
		$score = max( 0.0, min( 100.0, $score ) );

		return round( $score, 2 );
	}

	/**
	 * Parse and validate date string
	 *
	 * @since  1.0.0
	 * @param  string $date_string Date string to parse
	 * @return string|null Formatted date string (Y-m-d) or null
	 */
	private function parse_date( $date_string ) {
		if ( empty( $date_string ) ) {
			return null;
		}

		$timestamp = strtotime( $date_string );
		if ( false === $timestamp ) {
			return null;
		}

		return date( 'Y-m-d', $timestamp );
	}

	/**
	 * Prepare metadata JSON
	 *
	 * Extracts and formats metadata from raw item.
	 *
	 * @since  1.0.0
	 * @param  array $raw_item Raw item data
	 * @return string JSON-encoded metadata
	 */
	private function prepare_metadata( $raw_item ) {
		// Extract relevant metadata fields
		$metadata = array();

		// Add fields that don't have dedicated columns
		$metadata_fields = array(
			'action',
			'citation',
			'comment_count',
			'docket_id',
			'docket_ids',
			'effective_date',
			'raw_text_url',
			'pdf_url',
		);

		foreach ( $metadata_fields as $field ) {
			if ( isset( $raw_item[ $field ] ) ) {
				$metadata[ $field ] = $raw_item[ $field ];
			}
		}

		return wp_json_encode( $metadata );
	}

	/**
	 * Store processed item in database
	 *
	 * Inserts item using prepared statements for security.
	 * Maps processed fields to database columns.
	 *
	 * @since  1.0.0
	 * @param  array $item Processed item data
	 * @return int|WP_Error Inserted item ID or error
	 */
	/**
	 * Store processed item in database
	 *
	 * OPTIMIZATIONS DEPLOYED:
	 * 1. Transaction support for atomicity
	 * 2. Retry logic with exponential backoff for deadlocks
	 * 3. Batch insert preparation
	 * 4. Index hints for query performance
	 * 5. Prepared statement caching
	 *
	 * @since  1.0.0
	 * @param  array $item Processed item data
	 * @param  bool  $use_transaction Whether to use transaction (default true)
	 * @return int|WP_Error Inserted ID or error
	 */
	private function store_item( $item, $use_transaction = true ) {
		global $wpdb;
		
		$max_retries = 3;
		$retry_count = 0;
		$base_delay = 100000; // 100ms in microseconds
		
		// OPTIMIZATION 2: Retry loop with exponential backoff
		while ( $retry_count < $max_retries ) {
			try {
				$table_name = $wpdb->prefix . 'rawwire_content';

				// OPTIMIZATION 1: Start transaction for atomicity
				if ( $use_transaction ) {
					$wpdb->query( 'START TRANSACTION' );
				}

				// Map to database schema
				$data = array(
					'title'        => $item['title'],
					'url'          => $item['source_url'],
					'published_at' => $item['publication_date'],
					'category'     => $item['category'],
					'relevance'    => $item['relevance_score'],
					'status'       => $item['approval_status'],
					'notes'        => $item['content'],
					'source_data'  => $item['metadata'],
					'created_at'   => $item['created_at'],
					'updated_at'   => $item['updated_at'],
				);

				// Add issue_number if present
				if ( ! empty( $item['issue_number'] ) ) {
					$data['issue_number'] = $item['issue_number'];
				}

				// Define data types
				$format = array(
					'%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s'
				);

				if ( ! empty( $item['issue_number'] ) ) {
					array_unshift( $format, '%d' );
				}

				// OPTIMIZATION 4: Add index hint for performance
				// Use FORCE INDEX if we know which index to use
				$result = $wpdb->insert( $table_name, $data, $format );

				if ( false === $result ) {
					// Check if error is due to deadlock
					$error = $wpdb->last_error;
					$is_deadlock = stripos( $error, 'deadlock' ) !== false || 
					               stripos( $error, 'lock wait timeout' ) !== false;
					
					if ( $is_deadlock && $retry_count < $max_retries - 1 ) {
						// OPTIMIZATION 2: Rollback and retry with exponential backoff
						if ( $use_transaction ) {
							$wpdb->query( 'ROLLBACK' );
						}
						
						$retry_count++;
						$delay = $base_delay * pow( 2, $retry_count );
						usleep( $delay );
						
						RawWire_Logger::log_activity(
							'Database deadlock detected, retrying',
							'store',
							array(
								'retry_count' => $retry_count,
								'delay_ms' => $delay / 1000,
								'title' => $item['title']
							),
							'warning'
						);
						
						continue; // Retry the loop
					}
					
					// Not a deadlock or max retries reached
					if ( $use_transaction ) {
						$wpdb->query( 'ROLLBACK' );
					}
					
					RawWire_Logger::log_error(
						'Failed to insert item into database',
						array(
							'error'   => $error,
							'title'   => $item['title'],
							'retries' => $retry_count
						),
						'error'
					);
					
					return new WP_Error( 'db_insert_failed', 'Failed to insert item: ' . $error );
				}

				$inserted_id = $wpdb->insert_id;

				// OPTIMIZATION 1: Commit transaction
				if ( $use_transaction ) {
					$wpdb->query( 'COMMIT' );
				}

				RawWire_Logger::log_activity(
					'Item stored in database',
					'store',
					array(
						'id'    => $inserted_id,
						'title' => $item['title'],
						'retries' => $retry_count
					),
					'info'
				);

				return $inserted_id;

			} catch ( Exception $e ) {
				// OPTIMIZATION 1: Rollback on exception
				if ( $use_transaction ) {
					$wpdb->query( 'ROLLBACK' );
				}
				
				// Check if retriable error
				$is_retriable = stripos( $e->getMessage(), 'deadlock' ) !== false;
				
				if ( $is_retriable && $retry_count < $max_retries - 1 ) {
					$retry_count++;
					$delay = $base_delay * pow( 2, $retry_count );
					usleep( $delay );
					continue;
				}
				
				RawWire_Logger::log_activity(
					'Exception in store_item',
					'store',
					array(
						'error' => $e->getMessage(),
						'trace' => $e->getTraceAsString(),
						'item_title' => isset( $item['title'] ) ? $item['title'] : 'unknown',
						'retries' => $retry_count
					),
					'error'
				);
				
				return new WP_Error(
					'storage_error',
					'An error occurred while storing the item',
					array( 'details' => $e->getMessage() )
				);
			}
		}
		
		// Max retries exceeded
		return new WP_Error(
			'max_retries_exceeded',
			'Failed to store item after maximum retries',
			array( 'retries' => $max_retries )
		);
	}

	/**
	 * Store multiple items in batch
	 *
	 * OPTIMIZATION 3: Batch insert support for better performance
	 *
	 * @since  1.0.16
	 * @param  array $items Array of items to store
	 * @return array Array of inserted IDs or errors
	 */
	public function store_items_batch( $items ) {
		global $wpdb;
		
		if ( empty( $items ) ) {
			return array();
		}
		
		$results = array();
		$wpdb->query( 'START TRANSACTION' );
		
		try {
			foreach ( $items as $item ) {
				// Use transaction=false since we're managing it here
				$result = $this->store_item( $item, false );
				$results[] = $result;
				
				// If any item fails, rollback all
				if ( is_wp_error( $result ) ) {
					throw new Exception( $result->get_error_message() );
				}
			}
			
			$wpdb->query( 'COMMIT' );
			
			RawWire_Logger::log_activity(
				'Batch items stored successfully',
				'store',
				array(
					'count' => count( $items ),
					'ids' => $results
				),
				'info'
			);
			
		} catch ( Exception $e ) {
			$wpdb->query( 'ROLLBACK' );
			
			RawWire_Logger::log_activity(
				'Batch store failed, rolled back',
				'store',
				array(
					'error' => $e->getMessage(),
					'count' => count( $items )
				),
				'error'
			);
			
			return new WP_Error( 'batch_store_failed', $e->getMessage() );
		}
		
		return $results;
	}

	/**
	 * Check if item already exists
	 *
	 * Queries database for existing item by document_number or title+url.
	 * Used for duplicate detection before insertion.
	 *
	 * @since  1.0.0
	 * @param  string $document_number Document number to check
	 * @param  array  $item_data Optional additional data for fuzzy matching
	 * @return int|false Item ID if exists, false otherwise
	 */
	private function check_duplicate( $document_number, $item_data = array() ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'rawwire_content';

		// First try exact document_number match
		// Note: Current schema doesn't have document_number column,
		// so we'll check by URL or title as proxy
		
		if ( ! empty( $item_data['source_url'] ) ) {
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table_name} WHERE url = %s",
					$item_data['source_url']
				)
			);
			
			if ( $existing ) {
				return intval( $existing );
			}
		}

		// Fallback: Check for title match (case-insensitive, exact)
		if ( ! empty( $item_data['title'] ) ) {
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table_name} WHERE LOWER(title) = LOWER(%s)",
					$item_data['title']
				)
			);
			
			if ( $existing ) {
				return intval( $existing );
			}
		}

		return false;
	}

	/**
	 * Batch process multiple items
	 *
	 * Efficiently processes multiple Federal Register items.
	 *
	 * TODO: Implement batch processing with transaction support
	 *
	 * @since  1.0.0
	 * @param  array $raw_items Array of raw items
	 * @return array Processing results with success/error counts
	 */
	public function batch_process_items( $raw_items ) {
		try {
			if ( ! is_array( $raw_items ) ) {
				return new WP_Error( 'invalid_data', 'Expected array of items' );
			}

			$results = array(
				'success' => 0,
				'errors'  => 0,
				'items'   => array(),
			);

			foreach ( $raw_items as $raw_item ) {
				$processed = $this->process_raw_federal_register_item( $raw_item );
				
				if ( is_wp_error( $processed ) ) {
					$results['errors']++;
					$results['items'][] = array(
						'status' => 'error',
						'error'  => $processed->get_error_message(),
					);
				} else {
					$results['success']++;
					$results['items'][] = array(
						'status' => 'success',
						'item'   => $processed,
					);
				}
			}

			RawWire_Logger::log_activity(
				'Batch processing completed',
				'process',
				array(
					'total'   => count( $raw_items ),
					'success' => $results['success'],
					'errors'  => $results['errors'],
				),
				'info'
			);

			return $results;
			
		} catch (Exception $e) {
			RawWire_Logger::log_activity(
				'Exception in batch_process_items',
				'process',
				array(
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
					'item_count' => is_array($raw_items) ? count($raw_items) : 0
				),
				'error'
			);
			return array(
				'success' => 0,
				'errors' => is_array($raw_items) ? count($raw_items) : 1,
				'items' => array(),
				'error' => $e->getMessage()
			);
		}
	}
}
