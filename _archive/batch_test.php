<?php
/**
 * DEPRECATED: This script used the old process_scoring_batch() method which has been removed.
 * 
 * Use the new workflow instead:
 * 1. Scraper writes to candidates table (services/class-scraper-service.php)
 * 2. Scoring handler processes candidates -> archives (services/class-scoring-handler.php)
 * 3. Human approval on Approvals page copies archives -> content
 * 
 * To test the new workflow:
 * - Run sync via dashboard or REST API
 * - Check candidates table for scraped items
 * - Check archives table for scored items (Accepted/Rejected)
 * - Check Approvals page for items ready for approval
 */

echo "ERROR: This script is deprecated. The process_scoring_batch() method has been removed.\n";
echo "Please use the new 4-stage workflow: Candidates -> Archives -> Content\n";
echo "Check SYNC_FLOW_MAP.md for complete documentation.\n";
exit(1);
$last_ids = json_decode(get_option('rawwire_last_batch_ids', '[]'), true);
echo "last_batch_time: " . intval($last_time) . "\n";
echo "last_batch_ids: "; var_export($last_ids); echo "\n";

// Show any auto-approved findings (status = approved) recently created
$findings_table = $wpdb->prefix . 'rawwire_findings';
$approved = $wpdb->get_results($wpdb->prepare("SELECT id, title, score, status FROM {$findings_table} WHERE created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE) ORDER BY created_at DESC LIMIT 50"), ARRAY_A);
echo "Recent findings (last 10m):\n";
foreach ($approved as $f) {
    echo "- [{$f['id']}] {$f['title']} (score: {$f['score']}, status: {$f['status']})\n";
}

return 0;
