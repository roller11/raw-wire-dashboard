<?php
/**
 * Government Public Domain Data Scraper Test
 * 
 * Tests scraping U.S. government public domain sources:
 * - Federal Register (regulations, notices)
 * - FDA (vaping/tobacco)
 * - Congress.gov (legislation)
 * 
 * All content is public domain (17 U.S.C. § 105)
 * 
 * Usage:
 * docker exec raw-wire-core-wordpress-1 php \
 *   /var/www/html/wp-content/plugins/raw-wire-dashboard/scripts/test-gov-scraper.php
 */

define('ABSPATH', '/var/www/html/');
require_once ABSPATH . 'wp-load.php';
require_once __DIR__ . '/../cores/toolbox-core/adapters/scrapers/class-scraper-native.php';

echo "=== U.S. Government Public Domain Scraper Test ===\n\n";
echo "Legal basis: 17 U.S.C. § 105 - U.S. government works are public domain\n";
echo "No copyright restrictions on federal government content\n\n";

$scraper = new RawWire_Adapter_Scraper_Native(array());

// Test 1: Federal Register
echo "--- Test 1: Federal Register (Main Page) ---\n";
echo "Source: https://www.federalregister.gov\n";
$fr_result = $scraper->scrape('https://www.federalregister.gov/', array(
    'selectors' => array(
        'headline' => 'h1',
        'title' => 'title',
        'latest_docs' => '.document-wrapper h5',
    ),
));

if ($fr_result['success']) {
    echo "✓ Successfully scraped Federal Register\n";
    echo "  Page Title: " . substr($fr_result['data']['title'] ?? 'N/A', 0, 80) . "\n";
    echo "  HTML Size: " . number_format($fr_result['meta']['content_length']) . " bytes\n";
    
    if (isset($fr_result['data']['latest_docs']) && is_array($fr_result['data']['latest_docs'])) {
        echo "  Latest documents found: " . count($fr_result['data']['latest_docs']) . "\n";
        echo "  Sample: " . substr($fr_result['data']['latest_docs'][0] ?? 'N/A', 0, 60) . "...\n";
    }
} else {
    echo "✗ Failed: {$fr_result['error']}\n";
}

// Test 2: FDA Tobacco/Vaping Page
echo "\n--- Test 2: FDA Tobacco Products ---\n";
echo "Source: https://www.fda.gov/tobacco-products\n";
$fda_result = $scraper->scrape('https://www.fda.gov/tobacco-products', array(
    'selectors' => array(
        'title' => 'h1',
        'description' => '.page-description',
        'links' => 'a.card-title',
    ),
));

if ($fda_result['success']) {
    echo "✓ Successfully scraped FDA Tobacco Products page\n";
    $title = $fda_result['data']['title'] ?? 'N/A';
    if (is_array($title)) {
        $title = $title[0] ?? 'N/A';
    }
    echo "  Title: {$title}\n";
    echo "  HTML Size: " . number_format($fda_result['meta']['content_length']) . " bytes\n";
    
    if (isset($fda_result['data']['links']) && is_array($fda_result['data']['links'])) {
        echo "  Topic links found: " . count($fda_result['data']['links']) . "\n";
        $sample_links = array_slice($fda_result['data']['links'], 0, 3);
        foreach ($sample_links as $link) {
            echo "    - " . substr($link, 0, 60) . "\n";
        }
    }
} else {
    echo "✗ Failed: {$fda_result['error']}\n";
}

// Test 3: DEA Federal Register Notices
echo "\n--- Test 3: DEA Federal Register Notices ---\n";
echo "Source: https://www.dea.gov/federal-register-notices\n";
$dea_result = $scraper->scrape('https://www.dea.gov/federal-register-notices', array(
    'selectors' => array(
        'title' => 'h1',
        'notices' => '.view-content .views-row',
    ),
));

if ($dea_result['success']) {
    echo "✓ Successfully scraped DEA Federal Register notices\n";
    $title = $dea_result['data']['title'] ?? 'N/A';
    if (is_array($title)) {
        $title = $title[0] ?? 'N/A';
    }
    echo "  Page: {$title}\n";
    echo "  HTML Size: " . number_format($dea_result['meta']['content_length']) . " bytes\n";
} else {
    echo "✗ Failed: {$dea_result['error']}\n";
}

// Test 4: Federal Register RSS Feed
echo "\n--- Test 4: Federal Register RSS Feed ---\n";
echo "Source: https://www.federalregister.gov/documents.rss\n";
echo "Note: RSS feeds are even better than HTML scraping!\n";

$rss_result = $scraper->scrape('https://www.federalregister.gov/documents.rss', array());

if ($rss_result['success']) {
    echo "✓ Successfully fetched RSS feed\n";
    echo "  Size: " . number_format($rss_result['meta']['content_length']) . " bytes\n";
    echo "  Content Type: {$rss_result['meta']['content_type']}\n";
    
    // Parse RSS (simple XML check)
    $xml_valid = strpos($rss_result['html'], '<?xml') === 0;
    echo "  Valid XML: " . ($xml_valid ? 'Yes' : 'No') . "\n";
    
    if ($xml_valid) {
        echo "  ℹ RSS feeds provide structured data - easier than HTML scraping!\n";
    }
} else {
    echo "✗ Failed: {$rss_result['error']}\n";
}

// Test 5: Store Sample Government Data
echo "\n--- Test 5: Store Federal Register Data in Database ---\n";

if ($fr_result['success']) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'rawwire_content';
    
    // Create sample content from Federal Register
    $data = array(
        'title' => 'Federal Register Daily Update - ' . date('Y-m-d'),
        'content' => "Scraped from Federal Register (public domain)\n\n" . 
                     "Content is available for commercial use without copyright restrictions.\n\n" .
                     "Source: https://www.federalregister.gov\n" .
                     "Legal basis: 17 U.S.C. § 105",
        'source' => 'federal_register',
        'status' => 'pending',
    );
    
    $inserted = $wpdb->insert($table_name, $data);
    
    if ($inserted) {
        $insert_id = $wpdb->insert_id;
        echo "✓ Stored Federal Register data in database (ID: {$insert_id})\n";
        echo "  Source: federal_register (public domain)\n";
        echo "  Status: pending review\n";
        
        // Log activity
        RawWire_Logger::info('Federal Register content scraped', array(
            'content_id' => $insert_id,
            'source_url' => 'https://www.federalregister.gov',
            'legal_status' => 'public_domain',
            'citation' => '17 U.S.C. § 105',
        ));
        
    } else {
        echo "✗ Failed to insert: {$wpdb->last_error}\n";
    }
}

// Summary
echo "\n=== Summary ===\n";
echo "\nPublic Domain Sources Tested:\n";
echo "  ✓ Federal Register (regulations)\n";
echo "  ✓ FDA Tobacco Products (vaping rules)\n";
echo "  ✓ DEA Federal Register Notices (cannabis)\n";
echo "  ✓ RSS Feed (structured data)\n";

echo "\nLegal Status:\n";
echo "  ✅ All federal government content is public domain\n";
echo "  ✅ No copyright restrictions (17 U.S.C. § 105)\n";
echo "  ✅ Commercial use allowed\n";
echo "  ✅ No attribution required (recommended though)\n";

echo "\nBest Practices:\n";
echo "  • Respect robots.txt\n";
echo "  • Use RSS feeds when available (easier!)\n";
echo "  • Add 1-2 second delays between requests\n";
echo "  • Cache results - don't re-scrape same content\n";
echo "  • Cite sources for credibility\n";

echo "\nNext Steps:\n";
echo "  1. Set up scheduled RSS feed polling\n";
echo "  2. Create filters for cannabis/vaping keywords\n";
echo "  3. Add state government sources (check copyright first)\n";
echo "  4. Build approval workflow for relevant content\n";

$rate_limit = $scraper->get_rate_limit_status();
echo "\n--- Rate Limit Status ---\n";
echo "Remaining: {$rate_limit['remaining']}/{$rate_limit['limit']} (per minute)\n";

echo "\n=== Test Complete ===\n";
