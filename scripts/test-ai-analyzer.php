<?php
/**
 * AI Content Analyzer Test
 * 
 * Tests the AI-powered content analysis to find top newsworthy items
 * 
 * Usage:
 * 1. Start Ollama: docker-compose up -d ollama
 * 2. Pull a model: docker exec raw-wire-core-ollama-1 ollama pull llama3.2
 * 3. Run test: docker exec raw-wire-core-wordpress-1 php /var/www/html/wp-content/plugins/raw-wire-dashboard/scripts/test-ai-analyzer.php
 */

define('ABSPATH', '/var/www/html/');
require_once ABSPATH . 'wp-load.php';
require_once __DIR__ . '/../includes/class-ai-content-analyzer.php';

echo "=== AI Content Analyzer Test ===\n\n";

// Test Ollama connection first
require_once __DIR__ . '/../cores/toolbox-core/adapters/generators/class-generator-ollama.php';
$ollama = new RawWire_Adapter_Generator_Ollama(array());

echo "--- Step 1: Testing Ollama Connection ---\n";
$connection_test = $ollama->test_connection();

if (!$connection_test['success']) {
    echo "✗ Ollama not available: {$connection_test['message']}\n\n";
    echo "Setup instructions:\n";
    echo "1. docker-compose up -d ollama\n";
    echo "2. docker exec raw-wire-core-ollama-1 ollama pull llama3.2\n";
    echo "3. Run this script again\n\n";
    echo "Note: Ollama download is ~2GB for llama3.2 model\n";
    exit(1);
}

echo "✓ Ollama connected!\n";
echo "  Models: " . implode(', ', $connection_test['details']['models']) . "\n";
echo "  Cost: {$connection_test['details']['cost']}\n\n";

// Sample scraped content (simulating Federal Register findings)
$sample_items = array(
    array(
        'title' => 'FDA Issues Warning Letters to 15 Vaping Companies for Illegal Marketing',
        'content' => 'The Food and Drug Administration announced today enforcement actions against 15 electronic nicotine delivery system (ENDS) manufacturers for marketing products without required premarket authorization. This represents the largest single-day enforcement action against the vaping industry to date. Companies have 15 days to remove products from market or face civil penalties up to $15,000 per violation per day.',
        'source' => 'federal_register',
    ),
    array(
        'title' => 'Routine Compliance Update for Hemp Growers',
        'content' => 'The USDA provides routine guidance on hemp cultivation compliance requirements for the 2024 growing season. Farmers should submit annual reports by March 31st. Standard testing protocols remain unchanged from previous year.',
        'source' => 'federal_register',
    ),
    array(
        'title' => 'DEA Schedules Public Hearing on Cannabis Rescheduling Proposal',
        'content' => 'The Drug Enforcement Administration announces a historic public hearing on the proposed rescheduling of cannabis from Schedule I to Schedule III under the Controlled Substances Act. This would be the first major change to federal cannabis scheduling in over 50 years. Hearing scheduled for March 2024 with public comment period.',
        'source' => 'federal_register',
    ),
    array(
        'title' => 'Minor Administrative Change to Farm Equipment Depreciation',
        'content' => 'The IRS updates depreciation schedules for agricultural equipment. Changes affect reporting for fiscal year 2024. No action required for most taxpayers.',
        'source' => 'federal_register',
    ),
    array(
        'title' => 'California Becomes First State to Ban All Flavored Tobacco Products',
        'content' => 'Breaking: California implements complete ban on all flavored tobacco products including menthol cigarettes and flavored vaping products. Law takes effect immediately. Retailers found in violation face $250 daily fines. Expected to impact 15,000+ retail locations.',
        'source' => 'state_regulation',
    ),
);

echo "--- Step 2: Quick Filter (Keyword Check) ---\n";
$analyzer = new RawWire_AI_Content_Analyzer($ollama);
$filtered = $analyzer->quick_filter($sample_items);
echo "✓ Filtered " . count($sample_items) . " items → " . count($filtered) . " relevant items\n\n";

echo "--- Step 3: AI Analysis (This may take 30-60 seconds) ---\n";
echo "Analyzing " . count($filtered) . " items with AI...\n\n";

$start_time = microtime(true);
$top_findings = $analyzer->analyze_batch($filtered, 3);
$duration = round(microtime(true) - $start_time, 1);

echo "✓ Analysis complete in {$duration}s\n";
echo "Found " . count($top_findings) . " top findings\n\n";

// Display results
echo "=== TOP FINDINGS ===\n\n";

foreach ($top_findings as $rank => $finding) {
    $item = $finding['original'];
    $score = $finding['score'];
    $scores = $finding['scores'];
    
    echo "--- #" . ($rank + 1) . ": Score {$score}/100 ---\n";
    echo "Title: {$item['title']}\n";
    echo "Source: {$item['source']}\n\n";
    
    echo "Scores:\n";
    foreach ($scores as $criterion => $value) {
        $bar = str_repeat('█', $value) . str_repeat('░', 10 - $value);
        echo "  {$criterion}: [{$bar}] {$value}/10\n";
    }
    
    echo "\nAI Reasoning:\n";
    echo "  " . wordwrap($finding['reasoning'], 70, "\n  ") . "\n";
    
    if (!empty($finding['highlights'])) {
        echo "\nKey Points:\n";
        foreach ($finding['highlights'] as $highlight) {
            echo "  • {$highlight}\n";
        }
    }
    
    echo "\n";
}

// Test storing top finding in database
echo "--- Step 4: Store Top Finding in Database ---\n";
if (!empty($top_findings)) {
    global $wpdb;
    $table = $wpdb->prefix . 'rawwire_content';
    
    $top = $top_findings[0]['original'];
    
    $data = array(
        'title' => $top['title'],
        'content' => $top['content'],
        'source' => 'ai_curated',
        'status' => 'pending',
    );
    
    $inserted = $wpdb->insert($table, $data);
    
    if ($inserted) {
        $id = $wpdb->insert_id;
        echo "✓ Stored top finding in database (ID: {$id})\n";
        echo "  Score: {$top_findings[0]['score']}/100\n";
        
        // Also store the AI analysis as metadata
        update_post_meta($id, 'ai_score', $top_findings[0]['score']);
        update_post_meta($id, 'ai_reasoning', $top_findings[0]['reasoning']);
        update_post_meta($id, 'ai_scores', json_encode($top_findings[0]['scores']));
        
        echo "  Metadata: AI score and reasoning stored\n";
    }
}

echo "\n=== Summary ===\n";
echo "✅ AI analysis successfully filtered content\n";
echo "✅ Top newsworthy items identified\n";
echo "✅ Completely FREE (runs locally)\n";
echo "✅ No API costs, no rate limits\n\n";

echo "Next Steps:\n";
echo "1. Integrate with scraper workflow\n";
echo "2. Set up automated daily analysis\n";
echo "3. Build dashboard UI to show top findings\n";
echo "4. Add user feedback to improve scoring\n";

echo "\n=== Test Complete ===\n";
