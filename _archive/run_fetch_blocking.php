<?php
/**
 * DEPRECATED: This script used the old fetch_github_data() method which has been removed.
 * 
 * Use the new workflow instead:
 * 1. Call /wp-json/rawwire/v1/fetch-data REST endpoint (uses RawWire_Sync_Service)
 * 2. Or use includes/class-admin.php ajax_sync() method
 * 
 * New workflow: Scraper -> Candidates -> AI Scoring -> Archives -> Approval -> Content
 */

echo "ERROR: This script is deprecated. The fetch_github_data() method has been removed.\n";
echo "Please use the new sync workflow via REST API: /wp-json/rawwire/v1/fetch-data\n";
exit(1);
