<?php
/**
 * Dashboard Core Class
 *
 * Main core class that initializes the Raw-Wire Dashboard plugin.
 * Implements singleton pattern and manages plugin activation/deactivation.
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
 * RawWire_Dashboard_Core Class
 *
 * Singleton class that serves as the main entry point for the plugin.
 * Handles plugin initialization, loading of includes, and hook registration.
 * 
 * @since 1.0.0
 */
class RawWire_Dashboard_Core {

	/**
	 * The single instance of the class
	 *
	 * @since  1.0.0
	 * @var    RawWire_Dashboard_Core|null
	 */
	private static $instance = null;

	/**
	 * Mutex lock for thread-safe singleton
	 *
	 * OPTIMIZATION 1: Thread-safe singleton pattern
	 *
	 * @since  1.0.16
	 * @var    bool
	 */
	private static $lock = false;

	/**
	 * Initialization state
	 *
	 * OPTIMIZATION 3: Track initialization state
	 *
	 * @since  1.0.16
	 * @var    string Possible values: 'uninitialized', 'initializing', 'initialized', 'failed'
	 */
	private static $init_state = 'uninitialized';

	/**
	 * Plugin version
	 *
	 * @since  1.0.0
	 * @var    string
	 */
	private $version = '1.0.10';

	/**
	 * Plugin directory path
	 *
	 * @since  1.0.0
	 * @var    string
	 */
	private $plugin_path;

	/**
	 * Get singleton instance
	 *
	 * OPTIMIZATION 1: Thread-safe singleton with mutex lock
	 * OPTIMIZATION 2: Exception handling in singleton creation
	 * OPTIMIZATION 3: Initialization state tracking
	 *
	 * @since  1.0.0
	 * @return RawWire_Dashboard_Core The singleton instance
	 * @throws Exception If initialization fails
	 */
	public static function get_instance() {
		// OPTIMIZATION 1: Mutex lock for thread safety
		if ( self::$lock ) {
			// Another thread is initializing, wait briefly
			$wait_attempts = 0;
			while ( self::$lock && $wait_attempts < 10 ) {
				usleep( 10000 ); // Sleep 10ms
				$wait_attempts++;
			}
			
			if ( self::$lock ) {
				throw new Exception( 'RawWire_Dashboard_Core initialization deadlock detected' );
			}
		}

		if ( null === self::$instance ) {
			self::$lock = true;
			self::$init_state = 'initializing';
			
			try {
				// OPTIMIZATION 2: Exception handling
				self::$instance = new self();
				self::$init_state = 'initialized';
				
				// Log successful initialization
				if ( class_exists( 'RawWire_Logger' ) ) {
					RawWire_Logger::debug(
						'Dashboard Core singleton initialized',
						'init',
						array(
							'state' => self::$init_state,
							'memory' => memory_get_usage( true ),
							'pid' => getmypid()
						)
					);
				}
			} catch ( Exception $e ) {
				self::$init_state = 'failed';
				self::$instance = null;
				
				// Log failure
				if ( class_exists( 'RawWire_Logger' ) ) {
					RawWire_Logger::error(
						'Dashboard Core singleton initialization failed',
						'init',
						array(
							'error' => $e->getMessage(),
							'trace' => $e->getTraceAsString()
						)
					);
				}
				
				throw $e;
			} finally {
				self::$lock = false;
			}
		}
		
		return self::$instance;
	}

	/**
	 * Constructor
	 *
	 * Private constructor to enforce singleton pattern.
	 * OPTIMIZATION 4: Lazy-load classes only when needed
	 *
	 * @since  1.0.0
	 * @throws Exception If required dependencies cannot be loaded
	 */
	private function __construct() {
		$this->plugin_path = plugin_dir_path( dirname( __FILE__ ) );
		
		// OPTIMIZATION 4: Only load critical dependencies in constructor
		// Non-critical classes will be autoloaded on-demand
		$this->load_core_dependencies();
		$this->init_hooks();
	}

	/**
	 * Load core dependencies (critical only)
	 *
	 * OPTIMIZATION 4: Lazy-loading pattern - only essential classes
	 *
	 * @since  1.0.16
	 * @throws Exception If critical dependencies cannot be loaded
	 */
	private function load_core_dependencies() {
		$critical_files = array(
			'includes/class-logger.php' => 'RawWire_Logger',
		);
		
		foreach ( $critical_files as $file => $class ) {
			$path = $this->plugin_path . $file;
			if ( ! file_exists( $path ) ) {
				throw new Exception( "Critical dependency missing: {$file}" );
			}
			require_once $path;
			
			if ( ! class_exists( $class ) ) {
				throw new Exception( "Critical class not found after loading: {$class}" );
			}
		}
	}

	/**
	 * Load required dependencies
	 *
	 * Include all class files needed for the plugin to function.
	 * OPTIMIZATION 4: Now called lazily, not in constructor
	 * TODO: Add additional includes as Phase 2-5 modules are implemented.
	 *
	 * @since  1.0.0
	 */
	private function load_dependencies() {
		// Core classes (lazy-loaded when first accessed)
		$dependencies = array(
			'includes/class-github-fetcher.php',
			'includes/class-data-processor.php',
			'includes/class-cache-manager.php',
			'includes/class-approval-workflow.php',
		);
		
		foreach ( $dependencies as $file ) {
			$path = $this->plugin_path . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
			} else {
				if ( class_exists( 'RawWire_Logger' ) ) {
					RawWire_Logger::warning(
						'Optional dependency not found',
						'init',
						array( 'file' => $file )
					);
				}
			}
		}

		// TODO: Phase 2 - Load search modules
		// require_once $this->plugin_path . 'includes/search-modules/class-search-module-base.php';
		// require_once $this->plugin_path . 'includes/search-modules/class-keyword-filter.php';
		// require_once $this->plugin_path . 'includes/search-modules/class-date-filter.php';
		// require_once $this->plugin_path . 'includes/search-modules/class-category-filter.php';
		// require_once $this->plugin_path . 'includes/search-modules/class-relevance-scorer.php';

		// TODO: Phase 4 - Load API classes
		// require_once $this->plugin_path . 'includes/api/class-rest-api-controller.php';
		// require_once $this->plugin_path . 'includes/api/class-api-auth.php';
	}

	/**
	 * Lazy-load dependencies when first needed
	 *
	 * OPTIMIZATION 4: On-demand loading of heavy classes
	 *
	 * @since  1.0.16
	 * @return void
	 */
	public function ensure_dependencies_loaded() {
		static $loaded = false;
		if ( ! $loaded ) {
			$this->load_dependencies();
			$loaded = true;
		}
	}

	/**
	 * Initialize hooks
	 *
	 * Register WordPress action and filter hooks.
	 *
	 * @since  1.0.0
	 */
	private function init_hooks() {
		// Activation hook only - deactivation handled at plugin level
		register_activation_hook( $this->plugin_path . 'raw-wire-dashboard.php', array( $this, 'activate' ) );

		// TODO: Add admin menu hooks (Phase 5 - Dashboard UI)
		// add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );

		// TODO: Add AJAX hooks for dashboard interactions
		// add_action( 'wp_ajax_rawwire_fetch_data', array( $this, 'ajax_fetch_data' ) );
		// add_action( 'wp_ajax_rawwire_approve_content', array( $this, 'ajax_approve_content' ) );

		// TODO: Add REST API initialization (Phase 4)
		// add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Log initialization
		RawWire_Logger::log_activity( 'Dashboard core initialized', 'activity', array( 'version' => $this->version ), 'info' );
	}

	/**
	 * Plugin activation
	 *
	 * Creates database tables and sets up initial plugin configuration.
	 * Follows WordPress best practices for database schema installation.
	 *
	 * @since  1.0.0
	 */
	public function activate() {
		global $wpdb;

		// Include WordPress upgrade library for dbDelta
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table_prefix    = $wpdb->prefix;

		// Check if wp_rawwire_content table exists
		$content_table = $table_prefix . 'rawwire_content';
		$content_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $content_table ) );

		if ( $content_exists !== $content_table ) {
			// Read schema from SQL file
			$schema_file = $this->plugin_path . 'includes/schema.sql';
			
			if ( file_exists( $schema_file ) ) {
				$schema_sql = file_get_contents( $schema_file );
				
				// Replace generic wp_ prefix with actual prefix
				$schema_sql = str_replace( 'wp_rawwire_', $table_prefix . 'rawwire_', $schema_sql );
				
				// Add charset collate if not present
				if ( strpos( $schema_sql, 'CHARSET=' ) === false ) {
					$schema_sql = str_replace( ');', " $charset_collate );", $schema_sql );
				}

				// Split and execute each CREATE TABLE statement
				$statements = array_filter( array_map( 'trim', explode( ';', $schema_sql ) ) );
				
				foreach ( $statements as $statement ) {
					if ( ! empty( $statement ) && stripos( $statement, 'CREATE TABLE' ) !== false ) {
						dbDelta( $statement );
					}
				}

				RawWire_Logger::log_activity( 'Database tables created successfully', 'activity', array(), 'info' );
			}
		}

		// Check if wp_rawwire_logs table exists
		$logs_table = $table_prefix . 'rawwire_logs';
		$logs_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $logs_table ) );

		if ( $logs_exists !== $logs_table ) {
			// If schema.sql didn't create it, create manually
			$sql = "CREATE TABLE IF NOT EXISTS $logs_table (
				id BIGINT AUTO_INCREMENT PRIMARY KEY,
				log_type ENUM('fetch', 'process', 'error', 'api_call', 'activity') DEFAULT 'activity',
				message TEXT,
				details JSON,
				severity ENUM('info', 'warning', 'error', 'critical') DEFAULT 'info',
				created_at DATETIME,
				INDEX idx_type (log_type),
				INDEX idx_severity (severity),
				INDEX idx_date (created_at)
			) $charset_collate;";
			
			dbDelta( $sql );
		}

		// Set plugin version option
		update_option( 'rawwire_dashboard_version', $this->version );

		// Log activation
		RawWire_Logger::log_activity( 'Raw-Wire Dashboard plugin activated', 'activity', array( 'version' => $this->version ), 'info' );

		// TODO: Schedule cron jobs for automated data fetching (Phase 2)
		// if ( ! wp_next_scheduled( 'rawwire_fetch_data_cron' ) ) {
		//     wp_schedule_event( time(), 'hourly', 'rawwire_fetch_data_cron' );
		// }
	}

	/**
	 * Plugin deactivation
	 *
	 * Clean up scheduled events and temporary data.
	 * Note: Does NOT drop database tables to preserve data.
	 *
	 * @since  1.0.0
	 */
	public function deactivate() {
		// Clear scheduled events
		// TODO: Uncomment when cron jobs are implemented (Phase 2)
		// $timestamp = wp_next_scheduled( 'rawwire_fetch_data_cron' );
		// if ( $timestamp ) {
		//     wp_unschedule_event( $timestamp, 'rawwire_fetch_data_cron' );
		// }

		// Clear transient cache
		// RawWire_Cache_Manager::clear_all_cache();

		// Log deactivation
		RawWire_Logger::log_activity( 'Raw-Wire Dashboard plugin deactivated', 'activity', array(), 'info' );

		// Note: Database tables are intentionally preserved on deactivation
		// To completely remove data, user must manually delete tables or use uninstall.php
	}

	/**
	 * Get plugin version
	 *
	 * @since  1.0.0
	 * @return string Plugin version
	 */
	public function get_version() {
		return $this->version;
	}

	/**
	 * Get plugin path
	 *
	 * @since  1.0.0
	 * @return string Plugin directory path
	 */
	public function get_plugin_path() {
		return $this->plugin_path;
	}

	/**
	 * Get initialization state
	 *
	 * OPTIMIZATION 3: Public accessor for initialization state
	 *
	 * @since  1.0.16
	 * @return string Current initialization state
	 */
	public static function get_init_state() {
		return self::$init_state;
	}

	/**
	 * Destructor - Cleanup resources
	 *
	 * OPTIMIZATION 5: Proper resource cleanup on object destruction
	 *
	 * @since  1.0.16
	 */
	public function __destruct() {
		// Log destruction for debugging
		if ( class_exists( 'RawWire_Logger' ) ) {
			RawWire_Logger::debug(
				'Dashboard Core singleton destroyed',
				'shutdown',
				array(
					'memory_peak' => memory_get_peak_usage( true ),
					'state' => self::$init_state
				)
			);
		}
		
		// Clear instance reference
		self::$instance = null;
		self::$init_state = 'uninitialized';
	}
}
