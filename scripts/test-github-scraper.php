<?php
/**
 * GitHub Scraper Test Script
 * 
 * This script demonstrates using the GitHub scraper adapter to fetch real data
 * and process it through the Raw-Wire plugin workflow.
 * 
 * Usage (from WordPress container):
 * php /var/www/html/wp-content/plugins/raw-wire-dashboard/scripts/test-github-scraper.php
 */

// Load WordPress
define('ABSPATH', '/var/www/html/');
require_once ABSPATH . 'wp-load.php';

// Load the GitHub scraper
require_once __DIR__ . '/../cores/toolbox-core/adapters/scrapers/class-scraper-github.php';

echo "=== GitHub Scraper Test ===\n\n";

// Get GitHub token from WordPress options (if configured)
$token = get_option('rawwire_github_token', '');

// Initialize scraper with config
$config = array();
if (!empty($token)) {
    $config['token'] = $token;
    echo "✓ Using authenticated GitHub API\n\n";
} else {
    echo "ℹ Using unauthenticated GitHub API (60 req/hr limit)\n\n";
}

$scraper = new RawWire_Adapter_Scraper_GitHub($config);

echo "--- Test 1: Connection Test ---\n";
$test_result = $scraper->test_connection();
if ($test_result['success']) {
    echo "✓ Connection successful\n";
    echo "  Rate Limit: {$test_result['details']['remaining']}/{$test_result['details']['rate_limit']}\n";
    echo "  Authenticated: " . ($test_result['details']['authenticated'] ? 'Yes' : 'No') . "\n";
} else {
    echo "✗ Connection failed: {$test_result['message']}\n";
    exit(1);
}

echo "\n--- Test 2: Fetch Repository Info ---\n";
// Use a known public repository for testing
$repo_result = $scraper->get_repository('wordpress', 'wordpress-develop');
if ($repo_result['success']) {
    $repo = $repo_result['data'];
    echo "✓ Repository: {$repo['full_name']}\n";
    echo "  Description: " . substr($repo['description'] ?? 'N/A', 0, 80) . "\n";
    echo "  Stars: {$repo['stargazers_count']}\n";
    echo "  Language: " . ($repo['language'] ?? 'N/A') . "\n";
    echo "  Created: {$repo['created_at']}\n";
    echo "  Updated: {$repo['updated_at']}\n";
} else {
    echo "✗ Failed to fetch repository: {$repo_result['error']}\n";
    if (isset($repo_result['body'])) {
        echo "  Response: " . substr($repo_result['body'], 0, 200) . "\n";
    }
}

echo "\n--- Test 3: Fetch Recent Issues ---\n";
$issues_result = $scraper->get_issues('wordpress', 'wordpress-develop', array(
    'state' => 'all',
    'per_page' => 5,
    'sort' => 'updated',
));

if ($issues_result['success']) {
    $issues = $issues_result['data'];
    echo "✓ Found " . count($issues) . " issues\n\n";
    
    foreach ($issues as $issue) {
        echo "  Issue #{$issue['number']}: {$issue['title']}\n";
        echo "    State: {$issue['state']}\n";
        echo "    Author: {$issue['user']['login']}\n";
        echo "    Created: {$issue['created_at']}\n";
        
        // Count labels
        $label_count = count($issue['labels'] ?? array());
        if ($label_count > 0) {
            $labels = array_map(function($l) { return $l['name']; }, $issue['labels']);
            echo "    Labels: " . implode(', ', $labels) . "\n";
        }
        echo "\n";
    }
} else {
    echo "✗ Failed to fetch issues: {$issues_result['error']}\n";
}

echo "--- Test 4: Store Issue Data in Database ---\n";

if (!empty($issues)) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'rawwire_content';
    
    // Store first issue as example using actual schema
    $first_issue = $issues[0];
    $data = array(
        'title' => $first_issue['title'],
        'content' => $first_issue['body'] ?? '',
        'source' => 'github',
        'status' => $first_issue['state'], // open/closed
    );
    
    $inserted = $wpdb->insert($table_name, $data);
    
    if ($inserted) {
        $insert_id = $wpdb->insert_id;
        echo "✓ Stored issue #{$first_issue['number']} in database (ID: {$insert_id})\n";
        echo "  Title: {$first_issue['title']}\n";
        echo "  URL: {$first_issue['html_url']}\n";
        echo "  Status: {$first_issue['state']}\n";
        
        // Log to activity logs
        RawWire_Logger::info('GitHub issue imported via scraper', array(
            'issue_number' => $first_issue['number'],
            'content_id' => $insert_id,
            'repo' => 'wordpress/wordpress-develop',
            'source' => 'github_scraper_test',
        ));
        
    } else {
        echo "✗ Failed to insert into database: {$wpdb->last_error}\n";
    }
} else {
    echo "⚠ No issues to store\n";
}

echo "\n--- Rate Limit Status ---\n";
$rate_limit = $scraper->get_rate_limit_status();
echo "Remaining: {$rate_limit['remaining']}/{$rate_limit['limit']}\n";
echo "Resets at: " . date('Y-m-d H:i:s', $rate_limit['reset_at']) . "\n";

echo "\n=== Test Complete ===\n";
