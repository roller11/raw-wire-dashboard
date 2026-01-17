<?php
/**
 * RawWire Dashboard - Data Seeding & Testing Script
 *
 * Comprehensive script to:
 * - Generate simulated test data
 * - Test all REST API endpoints
 * - Verify approval workflow
 * - Test scoring and statistics
 * - Validate dashboard display
 *
 * Usage:
 *   php seed-test-data.php [--seed-only] [--test-only] [--verbose]
 *
 * @package    RawWire_Dashboard
 * @since      1.0.13
 */

// Determine WordPress root
$wp_root = dirname( dirname( dirname( __DIR__ ) ) );
if ( ! file_exists( $wp_root . '/wp-load.php' ) ) {
	echo "Error: Cannot find WordPress installation.\n";
	echo "Expected at: {$wp_root}/wp-load.php\n";
	exit( 1 );
}

// Load WordPress
define( 'WP_USE_THEMES', false );
require_once $wp_root . '/wp-load.php';

// Parse command line arguments
$args = array(
	'seed_only'  => in_array( '--seed-only', $argv ),
	'test_only'  => in_array( '--test-only', $argv ),
	'verbose'    => in_array( '--verbose', $argv ),
	'clear_data' => in_array( '--clear', $argv ),
);

// Color output helpers
function color_text( $text, $color = 'green' ) {
	$colors = array(
		'green'  => "\033[32m",
		'red'    => "\033[31m",
		'yellow' => "\033[33m",
		'blue'   => "\033[34m",
		'cyan'   => "\033[36m",
		'reset'  => "\033[0m",
	);
	return $colors[ $color ] . $text . $colors['reset'];
}

function log_section( $title ) {
	echo "\n" . str_repeat( '=', 80 ) . "\n";
	echo color_text( strtoupper( $title ), 'cyan' ) . "\n";
	echo str_repeat( '=', 80 ) . "\n";
}

function log_step( $message, $status = 'info' ) {
	$prefix = match ( $status ) {
		'success' => color_text( '[✓]', 'green' ),
		'error'   => color_text( '[✗]', 'red' ),
		'warning' => color_text( '[!]', 'yellow' ),
		default   => color_text( '[•]', 'blue' ),
	};
	echo "$prefix $message\n";
}

function log_detail( $message ) {
	global $args;
	if ( $args['verbose'] ) {
		echo "    " . color_text( $message, 'blue' ) . "\n";
	}
}

// Check dependencies
function check_dependencies() {
	log_section( 'Checking Dependencies' );

	$required_classes = array(
		'RawWire_Data_Simulator'      => 'includes/class-data-simulator.php',
		'RawWire_Data_Processor'      => 'includes/class-data-processor.php',
		'RawWire_REST_API_Controller' => 'includes/api/class-rest-api-controller.php',
	);

	$all_ok = true;
	foreach ( $required_classes as $class => $file ) {
		if ( class_exists( $class ) ) {
			log_step( "$class loaded", 'success' );
		} else {
			log_step( "$class NOT FOUND (expected in $file)", 'error' );
			$all_ok = false;
		}
	}

	// Check database table
	global $wpdb;
	$table = $wpdb->prefix . 'rawwire_content';
	$exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );
	if ( $exists ) {
		log_step( "Database table {$table} exists", 'success' );
	} else {
		log_step( "Database table {$table} NOT FOUND", 'error' );
		$all_ok = false;
	}

	return $all_ok;
}

// Clear existing data
function clear_test_data() {
	global $wpdb;
	$table = $wpdb->prefix . 'rawwire_content';
	
	log_section( 'Clearing Existing Test Data' );
	$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	
	if ( $count > 0 ) {
		$wpdb->query( "TRUNCATE TABLE {$table}" );
		log_step( "Cleared {$count} existing records", 'success' );
	} else {
		log_step( "No existing records to clear", 'info' );
	}
}

// Seed test data
function seed_test_data() {
	log_section( 'Seeding Test Data' );

	$scenarios = array(
		array(
			'label'   => 'High shock recent items',
			'count'   => 15,
			'options' => array(
				'shock_level'   => 'high',
				'date_range'    => 7, // Last week
				'high_value_pct' => 100,
			),
		),
		array(
			'label'   => 'Mixed shock items',
			'count'   => 25,
			'options' => array(
				'shock_level'   => 'mixed',
				'date_range'    => 30, // Last month
				'high_value_pct' => 30,
			),
		),
		array(
			'label'   => 'Low shock older items',
			'count'   => 10,
			'options' => array(
				'shock_level'   => 'low',
				'date_range'    => 60, // 2 months
				'high_value_pct' => 0,
			),
		),
	);

	$total_generated = 0;
	$total_stored    = 0;
	$total_errors    = 0;

	foreach ( $scenarios as $scenario ) {
		log_step( "Generating: {$scenario['label']} ({$scenario['count']} items)", 'info' );

		$result = RawWire_Data_Simulator::populate_database(
			$scenario['count'],
			$scenario['options']
		);

		$total_generated += $scenario['count'];
		$total_stored    += $result['success'];
		$total_errors    += $result['errors'];

		log_detail( "  Stored: {$result['success']}, Errors: {$result['errors']}" );
	}

	log_step( "Total: Generated {$total_generated}, Stored {$total_stored}, Errors {$total_errors}", 
		$total_errors > 0 ? 'warning' : 'success' );

	return array(
		'generated' => $total_generated,
		'stored'    => $total_stored,
		'errors'    => $total_errors,
	);
}

// Test REST API endpoints
function test_rest_endpoints() {
	log_section( 'Testing REST API Endpoints' );

	$tests = array();

	// Test 1: GET /rawwire/v1/content
	log_step( 'Testing GET /rawwire/v1/content', 'info' );
	$request = new WP_REST_Request( 'GET', '/rawwire/v1/content' );
	$request->set_param( 'limit', 10 );
	$response = rest_do_request( $request );
	$tests['get_content'] = $response->is_error() ? false : true;
	
	if ( $tests['get_content'] ) {
		$data = $response->get_data();
		log_detail( "  Returned {$data['pagination']['total']} total items, showing {$data['pagination']['count']}" );
		log_step( 'GET /content - PASSED', 'success' );
	} else {
		log_step( 'GET /content - FAILED', 'error' );
		log_detail( '  Error: ' . $response->as_error()->get_error_message() );
	}

	// Test 2: GET /rawwire/v1/stats
	log_step( 'Testing GET /rawwire/v1/stats', 'info' );
	$request = new WP_REST_Request( 'GET', '/rawwire/v1/stats' );
	$response = rest_do_request( $request );
	$tests['get_stats'] = $response->is_error() ? false : true;
	
	if ( $tests['get_stats'] ) {
		$stats = $response->get_data();
		log_detail( "  Total: {$stats['total']}, Pending: {$stats['by_status']['pending']}, Approved: {$stats['by_status']['approved']}" );
		log_step( 'GET /stats - PASSED', 'success' );
	} else {
		log_step( 'GET /stats - FAILED', 'error' );
	}

	// Test 3: GET /content with filters
	log_step( 'Testing GET /content with filters', 'info' );
	$filter_tests = array(
		array( 'status' => 'pending' ),
		array( 'limit' => 5 ),
		array( 'min_relevance' => 70 ),
		array( 'after' => date( 'Y-m-d', strtotime( '-7 days' ) ) ),
	);

	$filter_pass = 0;
	foreach ( $filter_tests as $filter ) {
		$request = new WP_REST_Request( 'GET', '/rawwire/v1/content' );
		foreach ( $filter as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$response = rest_do_request( $request );
		if ( ! $response->is_error() ) {
			$filter_pass++;
			$filter_desc = http_build_query( $filter );
			log_detail( "  ✓ Filter: {$filter_desc}" );
		}
	}
	$tests['content_filters'] = ( $filter_pass === count( $filter_tests ) );
	log_step( "Content filters: {$filter_pass}/" . count( $filter_tests ) . ' passed', 
		$tests['content_filters'] ? 'success' : 'warning' );

	// Test 4: Pagination
	log_step( 'Testing pagination', 'info' );
	$request = new WP_REST_Request( 'GET', '/rawwire/v1/content' );
	$request->set_param( 'limit', 5 );
	$request->set_param( 'offset', 0 );
	$page1 = rest_do_request( $request );
	
	$request->set_param( 'offset', 5 );
	$page2 = rest_do_request( $request );
	
	$tests['pagination'] = ( ! $page1->is_error() && ! $page2->is_error() );
	if ( $tests['pagination'] ) {
		$data1 = $page1->get_data();
		$data2 = $page2->get_data();
		log_detail( "  Page 1 count: {$data1['pagination']['count']}, Page 2 count: {$data2['pagination']['count']}" );
		log_step( 'Pagination - PASSED', 'success' );
	} else {
		log_step( 'Pagination - FAILED', 'error' );
	}

	return $tests;
}

// Test approval workflow
function test_approval_workflow() {
	log_section( 'Testing Approval Workflow' );

	global $wpdb;
	$table = $wpdb->prefix . 'rawwire_content';

	// Get a pending item
	$pending_item = $wpdb->get_row( 
		"SELECT id, title FROM {$table} WHERE status = 'pending' LIMIT 1" 
	);

	if ( ! $pending_item ) {
		log_step( 'No pending items found to test approval', 'warning' );
		return false;
	}

	log_step( "Testing approval of item #{$pending_item->id}: " . substr( $pending_item->title, 0, 50 ) . '...', 'info' );

	// Test approve endpoint
	$request = new WP_REST_Request( 'POST', '/rawwire/v1/content/approve' );
	$request->set_param( 'content_id', $pending_item->id );
	$response = rest_do_request( $request );

	if ( $response->is_error() ) {
		log_step( 'Approval endpoint - FAILED', 'error' );
		log_detail( '  Error: ' . $response->as_error()->get_error_message() );
		return false;
	}

	// Verify status changed
	$updated_item = $wpdb->get_row( 
		$wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d", $pending_item->id )
	);

	if ( $updated_item && $updated_item->status === 'approved' ) {
		log_step( 'Item status changed to approved', 'success' );
		log_detail( "  Item #{$pending_item->id} now has status: {$updated_item->status}" );
		return true;
	} else {
		log_step( 'Status did NOT change', 'error' );
		return false;
	}
}

// Test snooze workflow
function test_snooze_workflow() {
	log_section( 'Testing Snooze Workflow' );

	global $wpdb;
	$table = $wpdb->prefix . 'rawwire_content';

	// Get a pending item
	$pending_item = $wpdb->get_row( 
		"SELECT id, title FROM {$table} WHERE status = 'pending' LIMIT 1" 
	);

	if ( ! $pending_item ) {
		log_step( 'No pending items found to test snooze', 'warning' );
		return false;
	}

	log_step( "Testing snooze of item #{$pending_item->id}", 'info' );

	// Test snooze endpoint
	$request = new WP_REST_Request( 'POST', '/rawwire/v1/content/snooze' );
	$request->set_param( 'content_id', $pending_item->id );
	$request->set_param( 'hours', 24 );
	$response = rest_do_request( $request );

	if ( $response->is_error() ) {
		log_step( 'Snooze endpoint - FAILED', 'error' );
		log_detail( '  Error: ' . $response->as_error()->get_error_message() );
		return false;
	}

	$data = $response->get_data();
	log_step( 'Snooze endpoint - PASSED', 'success' );
	log_detail( "  Snoozed until: " . ( $data['snoozed_until'] ?? 'unknown' ) );
	return true;
}

// Test score calculation
function test_score_calculation() {
	log_section( 'Testing Score Calculation' );

	global $wpdb;
	$table = $wpdb->prefix . 'rawwire_content';

	// Get items with various scores
	$score_distribution = $wpdb->get_results(
		"SELECT 
			FLOOR(relevance_score / 10) * 10 as score_bucket,
			COUNT(*) as count
		FROM {$table}
		WHERE relevance_score IS NOT NULL
		GROUP BY score_bucket
		ORDER BY score_bucket DESC"
	);

	if ( empty( $score_distribution ) ) {
		log_step( 'No scored items found', 'warning' );
		return false;
	}

	log_step( 'Score distribution:', 'info' );
	foreach ( $score_distribution as $bucket ) {
		$bar = str_repeat( '█', min( 50, (int) $bucket->count ) );
		log_detail( sprintf( "  %3d-%3d: %s (%d items)", 
			$bucket->score_bucket, 
			$bucket->score_bucket + 9, 
			$bar, 
			$bucket->count 
		) );
	}

	// Get average score
	$avg_score = $wpdb->get_var( 
		"SELECT AVG(relevance_score) FROM {$table} WHERE relevance_score IS NOT NULL" 
	);
	log_step( sprintf( 'Average relevance score: %.2f', $avg_score ), 'success' );

	return true;
}

// Verify dashboard data display
function verify_dashboard_display() {
	log_section( 'Verifying Dashboard Display Data' );

	global $wpdb;
	$table = $wpdb->prefix . 'rawwire_content';

	// Check data completeness
	$checks = array();

	// 1. Items with all required fields
	$complete_items = $wpdb->get_var(
		"SELECT COUNT(*) FROM {$table} 
		WHERE title IS NOT NULL 
		AND content IS NOT NULL 
		AND source_url IS NOT NULL
		AND published_date IS NOT NULL"
	);
	$total_items = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	$checks['complete_fields'] = ( $complete_items === $total_items );
	log_step( "{$complete_items}/{$total_items} items have all required fields", 
		$checks['complete_fields'] ? 'success' : 'warning' );

	// 2. Items with scores
	$scored_items = $wpdb->get_var(
		"SELECT COUNT(*) FROM {$table} WHERE relevance_score IS NOT NULL AND relevance_score > 0"
	);
	$checks['scored_items'] = ( $scored_items > 0 );
	log_step( "{$scored_items} items have relevance scores", 
		$checks['scored_items'] ? 'success' : 'warning' );

	// 3. Recent items (last 7 days)
	$recent_items = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE published_date >= %s",
			date( 'Y-m-d', strtotime( '-7 days' ) )
		)
	);
	log_step( "{$recent_items} items from the last 7 days", 'info' );

	// 4. Source variety
	$source_count = $wpdb->get_var( "SELECT COUNT(DISTINCT source_type) FROM {$table}" );
	$checks['source_variety'] = ( $source_count >= 3 );
	log_step( "{$source_count} different source types", 
		$checks['source_variety'] ? 'success' : 'warning' );

	// 5. Status distribution
	$status_dist = $wpdb->get_results(
		"SELECT status, COUNT(*) as count FROM {$table} GROUP BY status"
	);
	log_step( 'Status distribution:', 'info' );
	foreach ( $status_dist as $status ) {
		log_detail( "  {$status->status}: {$status->count} items" );
	}

	return $checks;
}

// Generate summary report
function generate_summary( $results ) {
	log_section( 'Test Summary Report' );

	$total_tests = 0;
	$passed_tests = 0;

	// Seed results
	if ( isset( $results['seed'] ) ) {
		log_step( "Data Seeding:", 'info' );
		log_detail( "  Generated: {$results['seed']['generated']}" );
		log_detail( "  Stored: {$results['seed']['stored']}" );
		log_detail( "  Errors: {$results['seed']['errors']}" );
	}

	// REST API tests
	if ( isset( $results['rest'] ) ) {
		echo "\n";
		log_step( "REST API Tests:", 'info' );
		foreach ( $results['rest'] as $test => $passed ) {
			$total_tests++;
			if ( $passed ) {
				$passed_tests++;
			}
			log_detail( ( $passed ? '  ✓' : '  ✗' ) . " $test" );
		}
	}

	// Workflow tests
	if ( isset( $results['approval'] ) ) {
		echo "\n";
		$total_tests++;
		if ( $results['approval'] ) {
			$passed_tests++;
		}
		log_step( 'Approval Workflow: ' . ( $results['approval'] ? 'PASSED' : 'FAILED' ),
			$results['approval'] ? 'success' : 'error' );
	}

	if ( isset( $results['snooze'] ) ) {
		$total_tests++;
		if ( $results['snooze'] ) {
			$passed_tests++;
		}
		log_step( 'Snooze Workflow: ' . ( $results['snooze'] ? 'PASSED' : 'FAILED' ),
			$results['snooze'] ? 'success' : 'error' );
	}

	// Display checks
	if ( isset( $results['display'] ) ) {
		echo "\n";
		log_step( "Dashboard Display Checks:", 'info' );
		foreach ( $results['display'] as $check => $passed ) {
			$total_tests++;
			if ( $passed ) {
				$passed_tests++;
			}
			log_detail( ( $passed ? '  ✓' : '  ✗' ) . " $check" );
		}
	}

	// Final summary
	echo "\n" . str_repeat( '=', 80 ) . "\n";
	$pass_rate = $total_tests > 0 ? ( $passed_tests / $total_tests * 100 ) : 0;
	$summary_text = sprintf( 
		"FINAL RESULT: %d/%d tests passed (%.1f%%)", 
		$passed_tests, 
		$total_tests, 
		$pass_rate 
	);

	if ( $pass_rate === 100 ) {
		echo color_text( $summary_text, 'green' ) . "\n";
	} elseif ( $pass_rate >= 80 ) {
		echo color_text( $summary_text, 'yellow' ) . "\n";
	} else {
		echo color_text( $summary_text, 'red' ) . "\n";
	}
	echo str_repeat( '=', 80 ) . "\n\n";
}

// Main execution
function main() {
	global $args;

	echo "\n";
	echo color_text( '╔════════════════════════════════════════════════════════════════════════════╗', 'cyan' ) . "\n";
	echo color_text( '║         RawWire Dashboard - Data Seeding & Testing Script v1.0.13         ║', 'cyan' ) . "\n";
	echo color_text( '╚════════════════════════════════════════════════════════════════════════════╝', 'cyan' ) . "\n";

	$results = array();

	// Check dependencies
	if ( ! check_dependencies() ) {
		echo "\n" . color_text( 'FATAL: Required dependencies not found. Exiting.', 'red' ) . "\n\n";
		exit( 1 );
	}

	// Clear data if requested
	if ( $args['clear_data'] ) {
		clear_test_data();
	}

	// Seed data
	if ( ! $args['test_only'] ) {
		$results['seed'] = seed_test_data();
	}

	// Run tests
	if ( ! $args['seed_only'] ) {
		$results['rest'] = test_rest_endpoints();
		$results['approval'] = test_approval_workflow();
		$results['snooze'] = test_snooze_workflow();
		$results['score'] = test_score_calculation();
		$results['display'] = verify_dashboard_display();
	}

	// Generate summary
	generate_summary( $results );

	echo color_text( "✓ Testing complete! Check the results above.\n\n", 'green' );
}

// Run script
main();
