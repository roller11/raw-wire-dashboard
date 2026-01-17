<?php
/**
 * PHPUnit Bootstrap File
 *
 * Sets up the WordPress testing environment for Raw-Wire Dashboard plugin tests.
 * This file is loaded before any tests are run.
 *
 * @package    RawWire_Dashboard
 * @subpackage RawWire_Dashboard/tests
 * @since      1.0.0
 */

// Define test environment
define( 'RAWWIRE_TESTS_DIR', dirname( __FILE__ ) );
define( 'RAWWIRE_PLUGIN_DIR', dirname( dirname( __FILE__ ) ) );

// Composer autoloader if available
if ( file_exists( RAWWIRE_PLUGIN_DIR . '/vendor/autoload.php' ) ) {
	require_once RAWWIRE_PLUGIN_DIR . '/vendor/autoload.php';
}

/**
 * WordPress Test Suite Bootstrap
 * 
 * TODO: Configure WordPress test suite path when setting up full test environment
 * 
 * For now, we're creating a minimal bootstrap that allows basic testing.
 * Full WordPress test integration will be configured in Phase 2+.
 */

// Check if WordPress test suite is available
if ( getenv( 'WP_TESTS_DIR' ) ) {
	$_tests_dir = getenv( 'WP_TESTS_DIR' );
} elseif ( file_exists( '/tmp/wordpress-tests-lib/includes/functions.php' ) ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
} else {
	// WordPress test suite not available - use minimal mock
	$_tests_dir = null;
}

if ( $_tests_dir ) {
	// Load WordPress test suite
	require_once $_tests_dir . '/includes/functions.php';

	/**
	 * Manually load the plugin being tested
	 */
	function _manually_load_plugin() {
		require RAWWIRE_PLUGIN_DIR . '/raw-wire-dashboard.php';
	}
	tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

	// Start up the WordPress testing environment
	require $_tests_dir . '/includes/bootstrap.php';

} else {
	// Minimal bootstrap without WordPress test suite
	// This allows syntax checking and basic unit tests
	
	echo "Warning: WordPress test suite not found. Running in minimal test mode.\n";
	echo "To enable full WordPress integration testing:\n";
	echo "1. Install WordPress test suite\n";
	echo "2. Set WP_TESTS_DIR environment variable\n";
	echo "\n";

	// Define WordPress constants if not defined
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', '/tmp/wordpress/' );
	}

	// Minimal hooks API and test helpers
	if ( ! function_exists( 'add_action' ) ) {
		function add_action( $tag, $function_to_add, $priority = 10, $accepted_args = 1 ) {
			return true;
		}
	}

	if ( ! function_exists( 'do_action' ) ) {
		function do_action( $tag, ...$args ) {
			return null;
		}
	}

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $tag, $value ) {
			return $value;
		}
	}

	if ( ! function_exists( 'tests_add_filter' ) ) {
		function tests_add_filter( $tag, $function_to_add ) {
			return true;
		}
	}

	// Mock WordPress functions needed for testing
	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $str ) {
			return strip_tags( $str );
		}
	}

	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $data ) {
			return json_encode( $data );
		}
	}

	if ( ! function_exists( 'current_time' ) ) {
		function current_time( $type ) {
			return date( 'Y-m-d H:i:s' );
		}
	}

	if ( ! function_exists( 'esc_url_raw' ) ) {
		function esc_url_raw( $url ) {
			return filter_var( $url, FILTER_SANITIZE_URL );
		}
	}

	if ( ! function_exists( 'wp_kses_post' ) ) {
		function wp_kses_post( $data ) {
			return strip_tags( $data, '<p><a><strong><em><br><ul><ol><li>' );
		}
	}

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			private $code;
			private $message;
			private $data;

			public function __construct( $code, $message, $data = null ) {
				$this->code    = $code;
				$this->message = $message;
				$this->data    = $data;
			}

			public function get_error_code() {
				return $this->code;
			}

			public function get_error_message() {
				return $this->message;
			}

			public function get_error_data() {
				return $this->data;
			}
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $thing ) {
			return ( $thing instanceof WP_Error );
		}
	}

	// Load plugin files for testing
	require_once RAWWIRE_PLUGIN_DIR . '/includes/class-logger.php';
	require_once RAWWIRE_PLUGIN_DIR . '/includes/class-github-fetcher.php';
	require_once RAWWIRE_PLUGIN_DIR . '/includes/class-data-processor.php';
	require_once RAWWIRE_PLUGIN_DIR . '/includes/class-approval-workflow.php';
	require_once RAWWIRE_PLUGIN_DIR . '/includes/class-cache-manager.php';
	require_once RAWWIRE_PLUGIN_DIR . '/includes/class-dashboard-core.php';
	require_once RAWWIRE_PLUGIN_DIR . '/includes/class-settings.php';
}

// Test utilities
class RawWire_Test_Helpers {
	/**
	 * Create a mock wpdb object for testing
	 */
	public static function get_mock_wpdb() {
		$wpdb = new stdClass();
		$wpdb->prefix = 'wp_';
		$wpdb->insert = function() { return 1; };
		$wpdb->prepare = function( $query, ...$args ) { return $query; };
		$wpdb->get_results = function() { return array(); };
		$wpdb->get_var = function() { return null; };
		return $wpdb;
	}
}

echo "Raw-Wire Dashboard Test Bootstrap Loaded\n";
