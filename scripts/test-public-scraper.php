<?php
/**
 * Public Website Scraper Test
 * 
 * Demonstrates using the Native PHP scraper to extract data from public sources
 * like news sites, RSS feeds, and public data portals.
 * 
 * Usage:
 * docker exec raw-wire-core-wordpress-1 php \
 *   /var/www/html/wp-content/plugins/raw-wire-dashboard/scripts/test-public-scraper.php
 */

define('ABSPATH', '/var/www/html/');
require_once ABSPATH . 'wp-load.php';

// Load the Native scraper
require_once __DIR__ . '/../cores/toolbox-core/adapters/scrapers/class-scraper-native.php';

echo "=== Public Website Scraper Test ===\n\n";

// Initialize the native scraper (no config needed)
$scraper = new RawWire_Adapter_Scraper_Native(array());

// Test 1: Connection test
echo "--- Test 1: Connection Test ---\n";
$test_result = $scraper->test_connection();
if ($test_result['success']) {
    echo "✓ Scraper operational\n";
    echo "  Capabilities: " . implode(', ', $test_result['details']['capabilities']) . "\n";
    echo "  Rate Limit: {$test_result['details']['rate_limit']}\n";
} else {
    echo "✗ Connection failed: {$test_result['message']}\n";
    exit(1);
}

// Test 2: Scrape a simple public website (Wikipedia)
echo "\n--- Test 2: Scrape Wikipedia Article ---\n";
$wiki_url = 'https://en.wikipedia.org/wiki/Web_scraping';
$wiki_result = $scraper->scrape($wiki_url, array(
    'selectors' => array(
        'title' => 'h1.firstHeading',
        'summary' => 'p',
        'links' => 'a',
    ),
));

if ($wiki_result['success']) {
    echo "✓ Successfully scraped Wikipedia\n";
    echo "  Title: " . substr($wiki_result['data']['title'] ?? 'N/A', 0, 80) . "\n";
    
    $summary = $wiki_result['data']['summary'] ?? 'N/A';
    if (is_array($summary)) {
        $summary = $summary[0] ?? 'N/A';
    }
    echo "  Summary: " . substr($summary, 0, 150) . "...\n";
    echo "  HTML Size: " . number_format($wiki_result['meta']['content_length']) . " bytes\n";
    echo "  Content Type: {$wiki_result['meta']['content_type']}\n";
} else {
    echo "✗ Failed to scrape: {$wiki_result['error']}\n";
}

// Test 3: Scrape a news/blog site (Example.com for demo)
echo "\n--- Test 3: Scrape Example.com (Test Site) ---\n";
$example_result = $scraper->scrape('https://example.com', array(
    'selectors' => array(
        'title' => 'h1',
        'content' => 'p',
        'links' => 'a',
    ),
));

if ($example_result['success']) {
    echo "✓ Successfully scraped example.com\n";
    echo "  Title: {$example_result['data']['title']}\n";
    
    $content = $example_result['data']['content'];
    if (is_array($content)) {
        $content = implode(' ', array_slice($content, 0, 2));
    }
    echo "  Content: " . substr($content, 0, 100) . "...\n";
    echo "  Links found: " . (is_array($example_result['data']['links']) ? count($example_result['data']['links']) : 0) . "\n";
} else {
    echo "✗ Failed: {$example_result['error']}\n";
}

// Test 4: Extract structured data with specific selectors
echo "\n--- Test 4: Custom Selector Extraction ---\n";
echo "Testing with httpbin.org/html...\n";

$httpbin_result = $scraper->scrape('https://httpbin.org/html', array(
    'selectors' => array(
        'page_title' => 'h1',
        'paragraphs' => 'p',
    ),
));

if ($httpbin_result['success']) {
    echo "✓ Successfully extracted data\n";
    $data = $httpbin_result['data'];
    if (isset($data['page_title'])) {
        echo "  Page Title: {$data['page_title']}\n";
    }
    if (isset($data['paragraphs'])) {
        if (is_array($data['paragraphs'])) {
            echo "  Found " . count($data['paragraphs']) . " paragraphs\n";
            echo "  First paragraph: " . substr($data['paragraphs'][0], 0, 80) . "...\n";
        } else {
            echo "  Paragraph: " . substr($data['paragraphs'], 0, 80) . "...\n";
        }
    }
} else {
    echo "✗ Failed: {$httpbin_result['error']}\n";
}

// Test 5: Store scraped data in database
echo "\n--- Test 5: Store Scraped Content in Database ---\n";

if ($example_result['success']) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'rawwire_content';
    
    $data = array(
        'title' => $example_result['data']['title'] ?? 'Scraped Content',
        'content' => is_array($example_result['data']['content']) 
            ? implode("\n\n", $example_result['data']['content'])
            : ($example_result['data']['content'] ?? ''),
        'source' => 'web_scrape',
        'status' => 'pending',
    );
    
    $inserted = $wpdb->insert($table_name, $data);
    
    if ($inserted) {
        $insert_id = $wpdb->insert_id;
        echo "✓ Stored scraped content in database (ID: {$insert_id})\n";
        echo "  Title: {$data['title']}\n";
        echo "  Source: web_scrape\n";
        
        // Log to activity
        RawWire_Logger::info('Public website content scraped', array(
            'content_id' => $insert_id,
            'url' => 'https://example.com',
            'source' => 'native_scraper_test',
        ));
        
    } else {
        echo "✗ Failed to insert: {$wpdb->last_error}\n";
    }
}

// Test 6: Batch scraping multiple URLs
echo "\n--- Test 6: Batch Scraping (Multiple URLs) ---\n";
$urls_to_scrape = array(
    'https://httpbin.org/html',
    'https://example.com',
);

echo "Scraping " . count($urls_to_scrape) . " URLs...\n";
$batch_results = $scraper->scrape_batch($urls_to_scrape, array(
    'delay' => 500, // 500ms between requests
    'selectors' => array(
        'title' => 'h1',
    ),
));

$success_count = 0;
foreach ($batch_results as $url => $result) {
    if ($result['success']) {
        $success_count++;
        $title = $result['data']['title'] ?? 'No title';
        echo "  ✓ {$url}: {$title}\n";
    } else {
        echo "  ✗ {$url}: {$result['error']}\n";
    }
}

echo "Completed: {$success_count}/" . count($urls_to_scrape) . " successful\n";

// Rate limit status
echo "\n--- Rate Limit Status ---\n";
$rate_limit = $scraper->get_rate_limit_status();
echo "Remaining: {$rate_limit['remaining']}/{$rate_limit['limit']} (per minute)\n";
echo "Resets at: " . date('H:i:s', $rate_limit['reset_at']) . "\n";

echo "\n=== Test Complete ===\n";
echo "\nWhat the Native Scraper CAN do:\n";
echo "  ✓ Scrape public websites (HTML)\n";
echo "  ✓ Extract data using CSS selectors\n";
echo "  ✓ Handle multiple URLs in batch\n";
echo "  ✓ Parse and structure content\n";
echo "  ✓ No external dependencies (100% free)\n";
echo "\nLimitations:\n";
echo "  ✗ Cannot handle JavaScript-heavy sites\n";
echo "  ✗ Cannot bypass CAPTCHAs or anti-bot measures\n";
echo "  ✗ No proxy rotation (uses your server's IP)\n";
echo "  ✗ Rate limited to 60 requests/minute\n";
echo "\nFor sites with JavaScript or anti-bot protection, use:\n";
echo "  - ScraperAPI adapter (tier: value)\n";
echo "  - BrightData adapter (tier: flagship)\n";
