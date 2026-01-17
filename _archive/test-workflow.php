<?php
/**
 * Workflow Testing Script
 * Tests the complete 4-stage pipeline for bugs
 * 
 * Usage: Run from WordPress root with: wp eval-file wordpress-plugins/raw-wire-dashboard/test-workflow.php
 */

if (!defined('ABSPATH')) {
    // Load WordPress if not already loaded
    require_once dirname(__FILE__) . '/../../../wp-load.php';
}

echo "\n=== RawWire Workflow Testing ===\n\n";

// Test 1: Database Tables Exist
echo "TEST 1: Checking Database Tables...\n";
global $wpdb;
$tables = array(
    'candidates' => $wpdb->prefix . 'rawwire_candidates',
    'archives' => $wpdb->prefix . 'rawwire_archives',
    'content' => $wpdb->prefix . 'rawwire_content',
    'queue' => $wpdb->prefix . 'rawwire_queue'
);

foreach ($tables as $name => $table) {
    $exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
    if ($exists) {
        echo "  ✓ {$name} table exists: {$table}\n";
    } else {
        echo "  ✗ MISSING: {$name} table: {$table}\n";
    }
}

// Test 2: Table Schemas
echo "\nTEST 2: Checking Table Schemas...\n";
$archives_columns = $wpdb->get_results("SHOW COLUMNS FROM {$tables['archives']}");
$has_result = false;
$has_score = false;
$has_ai_reason = false;

foreach ($archives_columns as $col) {
    if ($col->Field === 'result') $has_result = true;
    if ($col->Field === 'score') $has_score = true;
    if ($col->Field === 'ai_reason') $has_ai_reason = true;
}

echo "  " . ($has_result ? "✓" : "✗") . " Archives table has 'result' column\n";
echo "  " . ($has_score ? "✓" : "✗") . " Archives table has 'score' column\n";
echo "  " . ($has_ai_reason ? "✓" : "✗") . " Archives table has 'ai_reason' column\n";

// Test 3: Class Files Exist
echo "\nTEST 3: Checking Class Files...\n";
$files = array(
    'Migration Service' => dirname(__FILE__) . '/services/class-migration-service.php',
    'Scoring Handler' => dirname(__FILE__) . '/services/class-scoring-handler.php',
    'Scraper Service' => dirname(__FILE__) . '/services/class-scraper-service.php',
    'Candidates Page' => dirname(__FILE__) . '/admin/class-candidates.php'
);

foreach ($files as $name => $file) {
    if (file_exists($file)) {
        echo "  ✓ {$name}: " . basename($file) . "\n";
    } else {
        echo "  ✗ MISSING: {$name}: {$file}\n";
    }
}

// Test 4: Hooks Registered
echo "\nTEST 4: Checking Hooks...\n";
$hooks_to_check = array(
    'rawwire_scrape_complete',
    'rawwire_content_approved'
);

foreach ($hooks_to_check as $hook) {
    global $wp_filter;
    if (isset($wp_filter[$hook])) {
        $count = count($wp_filter[$hook]->callbacks);
        echo "  ✓ Hook '{$hook}' has {$count} callback(s)\n";
    } else {
        echo "  ✗ Hook '{$hook}' has no callbacks registered\n";
    }
}

// Test 5: AJAX Actions Registered
echo "\nTEST 5: Checking AJAX Actions...\n";
$ajax_actions = array(
    'wp_ajax_rawwire_get_workflow_status',
    'wp_ajax_rawwire_clear_content'
);

foreach ($ajax_actions as $action) {
    if (has_action($action)) {
        echo "  ✓ AJAX action registered: {$action}\n";
    } else {
        echo "  ✗ MISSING AJAX action: {$action}\n";
    }
}

// Test 6: Check AI Analyzer
echo "\nTEST 6: Checking AI Components...\n";
if (class_exists('RawWire_AI_Content_Analyzer')) {
    echo "  ✓ RawWire_AI_Content_Analyzer class exists\n";
    $analyzer = new RawWire_AI_Content_Analyzer();
    if (method_exists($analyzer, 'analyze_batch')) {
        echo "  ✓ analyze_batch method exists\n";
    } else {
        echo "  ✗ analyze_batch method missing\n";
    }
} else {
    echo "  ✗ RawWire_AI_Content_Analyzer class not found\n";
}

// Test 7: Scraper Service File
echo "\nTEST 7: Checking Scraper Service...\n";
$scraper_file = dirname(__FILE__) . '/services/class-scraper-service.php';
if (file_exists($scraper_file)) {
    echo "  ✓ Scraper service file exists\n";
    
    // Load the file to check class
    require_once $scraper_file;
    
    if (class_exists('RawWire_Scraper_Service')) {
        echo "  ✓ RawWire_Scraper_Service class loaded\n";
        
        $methods = array('scrape_all', 'get_results');
        foreach ($methods as $method) {
            if (method_exists('RawWire_Scraper_Service', $method)) {
                echo "  ✓ Method '{$method}' exists\n";
            } else {
                echo "  ✗ Method '{$method}' missing\n";
            }
        }
    } else {
        echo "  ✗ RawWire_Scraper_Service class not found in file\n";
    }
} else {
    echo "  ✗ Scraper service file not found\n";
}

// Test 8: Scoring Handler
echo "\nTEST 8: Checking Scoring Handler...\n";
if (class_exists('RawWire_Scoring_Handler')) {
    echo "  ✓ RawWire_Scoring_Handler class exists\n";
    
    // Check if it's hooked
    $handler = new RawWire_Scoring_Handler();
    if (has_action('rawwire_scrape_complete', array($handler, 'process_candidates'))) {
        echo "  ✓ process_candidates hooked to rawwire_scrape_complete\n";
    } else {
        echo "  ⚠ process_candidates not hooked (may be hooked in constructor)\n";
    }
} else {
    echo "  ✗ RawWire_Scoring_Handler class not found\n";
}

// Test 9: Check Transient Storage
echo "\nTEST 9: Testing Transient Storage...\n";
$test_data = array(
    'active' => true,
    'stage' => 'test',
    'message' => 'Testing...',
    'startTime' => current_time('mysql')
);

set_transient('rawwire_workflow_test', $test_data, 60);
$retrieved = get_transient('rawwire_workflow_test');

if ($retrieved && $retrieved['stage'] === 'test') {
    echo "  ✓ Transient storage working\n";
    delete_transient('rawwire_workflow_test');
} else {
    echo "  ✗ Transient storage failed\n";
}

// Test 10: Check for JavaScript File
echo "\nTEST 10: Checking JavaScript Files...\n";
$js_file = dirname(__FILE__) . '/dashboard.js';
if (file_exists($js_file)) {
    echo "  ✓ dashboard.js exists\n";
    
    $js_content = file_get_contents($js_file);
    if (strpos($js_content, 'checkWorkflowProgress') !== false) {
        echo "  ✓ Progress tracking functions found\n";
    } else {
        echo "  ✗ Progress tracking functions missing\n";
    }
    
    if (strpos($js_content, 'rawwire_get_workflow_status') !== false) {
        echo "  ✓ AJAX endpoint call found\n";
    } else {
        echo "  ✗ AJAX endpoint call missing\n";
    }
} else {
    echo "  ✗ dashboard.js not found\n";
}

// Test 11: Data Flow Simulation
echo "\nTEST 11: Simulating Data Flow...\n";

// Insert test candidate
$test_candidate = array(
    'title' => 'Test Candidate ' . time(),
    'content' => 'Test content',
    'link' => 'https://example.com/test',
    'source' => 'test_source',
    'created_at' => current_time('mysql')
);

$inserted = $wpdb->insert($tables['candidates'], $test_candidate, array('%s', '%s', '%s', '%s', '%s'));

if ($inserted) {
    echo "  ✓ Test candidate inserted successfully\n";
    
    // Check if it exists
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$tables['candidates']} WHERE title = %s",
        $test_candidate['title']
    ));
    
    if ($exists) {
        echo "  ✓ Test candidate retrieved from database\n";
        
        // Clean up
        $wpdb->delete($tables['candidates'], array('id' => $exists), array('%d'));
        echo "  ✓ Test candidate cleaned up\n";
    } else {
        echo "  ✗ Test candidate not found after insert\n";
    }
} else {
    echo "  ✗ Failed to insert test candidate: " . $wpdb->last_error . "\n";
}

// Summary
echo "\n=== Test Summary ===\n";
echo "All critical components checked.\n";
echo "Review any ✗ marks above for issues.\n";
echo "⚠ marks indicate warnings that may not be critical.\n\n";
