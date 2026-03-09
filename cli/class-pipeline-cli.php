<?php

/**
 * WP-CLI Commands for Permit Pipeline
 *
 * Run the permit automation pipeline from the command line.
 *
 * @package RawWire_Dashboard
 * @since   1.0.30
 */

if (!defined('ABSPATH') || !defined('WP_CLI')) {
    return;
}

/**
 * Manage the permit automation pipeline.
 *
 * ## EXAMPLES
 *
 *     # Run the pipeline for 10 permits
 *     wp rawwire pipeline run --limit=10
 *
 *     # Check pipeline status
 *     wp rawwire pipeline status
 *
 *     # Scrape a single permit
 *     wp rawwire pipeline scrape 23010-20000-01607
 *
 * @package RawWire_Dashboard
 */
class RawWire_Pipeline_CLI
{

    /**
     * Run the full permit pipeline.
     *
     * ## OPTIONS
     *
     * [--limit=<number>]
     * : Maximum permits to process.
     * ---
     * default: 10
     * ---
     *
     * [--rescrape]
     * : Re-scrape permits that were already scraped.
     *
     * [--dry-run]
     * : Show what would be processed without actually running.
     *
     * ## EXAMPLES
     *
     *     wp rawwire pipeline run
     *     wp rawwire pipeline run --limit=50
     *     wp rawwire pipeline run --rescrape
     *
     * @param array $args       Positional args.
     * @param array $assoc_args Associative args.
     */
    public function run($args, $assoc_args)
    {
        $limit = intval($assoc_args['limit'] ?? 10);
        $skip_scraped = !isset($assoc_args['rescrape']);
        $dry_run = isset($assoc_args['dry-run']);

        WP_CLI::log("Starting permit pipeline...");

        if (!function_exists('rawwire_permit_pipeline')) {
            WP_CLI::error('Pipeline class not loaded. Make sure plugin is active.');
        }

        $pipeline = rawwire_permit_pipeline();

        if ($dry_run) {
            $pending = $pipeline->get_pending_permits($limit, $skip_scraped);
            WP_CLI::log("Would process " . count($pending) . " permits:");
            foreach ($pending as $p) {
                WP_CLI::log("  - {$p['permit_number']} (post_id: {$p['post_id']})");
            }
            return;
        }

        $result = $pipeline->run_pipeline($limit, $skip_scraped);

        WP_CLI::log("");
        WP_CLI::log("=== Pipeline Results ===");
        WP_CLI::log("Permits found:    {$result['permits_found']}");
        WP_CLI::log("Successfully scraped: {$result['scraped']}");
        WP_CLI::log("Scrape errors:    {$result['scrape_errors']}");
        WP_CLI::log("Inserted to sources: {$result['inserted']}");
        WP_CLI::log("Already existed:  {$result['already_exists']}");
        WP_CLI::log("Insert errors:    {$result['insert_errors']}");
        WP_CLI::log("Records scored:   {$result['scored']}");
        WP_CLI::log("Duration:         {$result['duration']}s");

        foreach ($result['messages'] as $msg) {
            WP_CLI::log($msg);
        }

        if ($result['success']) {
            WP_CLI::success('Pipeline completed.');
        } else {
            WP_CLI::warning('Pipeline completed with issues.');
        }
    }

    /**
     * Show pipeline status.
     *
     * ## EXAMPLES
     *
     *     wp rawwire pipeline status
     *
     * @param array $args       Positional args.
     * @param array $assoc_args Associative args.
     */
    public function status($args, $assoc_args)
    {
        if (!function_exists('rawwire_permit_pipeline')) {
            WP_CLI::error('Pipeline class not loaded. Make sure plugin is active.');
        }

        $pipeline = rawwire_permit_pipeline();

        $pending = $pipeline->count_pending_permits();
        $scraped = $pipeline->count_scraped_permits();
        $unprocessed = $pipeline->count_unprocessed_sources();

        WP_CLI::log("=== Permit Pipeline Status ===");
        WP_CLI::log("Permits pending scrape:  {$pending}");
        WP_CLI::log("Permits already scraped: {$scraped}");
        WP_CLI::log("Awaiting scoring:        {$unprocessed}");
    }

    /**
     * Scrape a single permit.
     *
     * ## OPTIONS
     *
     * <permit_number>
     * : The permit number to scrape.
     *
     * [--show-browser]
     * : Show the browser window while scraping.
     *
     * ## EXAMPLES
     *
     *     wp rawwire pipeline scrape 23010-20000-01607
     *
     * @param array $args       Positional args.
     * @param array $assoc_args Associative args.
     */
    public function scrape($args, $assoc_args)
    {
        if (empty($args[0])) {
            WP_CLI::error('Please provide a permit number.');
        }

        $permit_number = sanitize_text_field($args[0]);

        if (!function_exists('rawwire_permit_pipeline')) {
            WP_CLI::error('Pipeline class not loaded. Make sure plugin is active.');
        }

        $pipeline = rawwire_permit_pipeline();

        WP_CLI::log("Scraping permit: {$permit_number}");

        $result = $pipeline->scrape_ladbs($permit_number);

        if ($result['success']) {
            WP_CLI::success("Scraped successfully!");
            WP_CLI::log("");

            if (!empty($result['owner'])) {
                WP_CLI::log("Owner:");
                foreach ($result['owner'] as $k => $v) {
                    WP_CLI::log("  {$k}: {$v}");
                }
            }

            if (!empty($result['contractor'])) {
                WP_CLI::log("Contractor:");
                foreach ($result['contractor'] as $k => $v) {
                    WP_CLI::log("  {$k}: {$v}");
                }
            }

            if (!empty($result['applicant'])) {
                WP_CLI::log("Applicant:");
                foreach ($result['applicant'] as $k => $v) {
                    WP_CLI::log("  {$k}: {$v}");
                }
            }
        } else {
            WP_CLI::error("Scrape failed: {$result['error']}");
        }
    }

    /**
     * Manually trigger scoring for unprocessed sources.
     *
     * ## OPTIONS
     *
     * [--limit=<number>]
     * : Maximum records to score.
     * ---
     * default: 50
     * ---
     *
     * ## EXAMPLES
     *
     *     wp rawwire pipeline score
     *     wp rawwire pipeline score --limit=100
     *
     * @param array $args       Positional args.
     * @param array $assoc_args Associative args.
     */
    public function score($args, $assoc_args)
    {
        if (!function_exists('rawwire_leads')) {
            WP_CLI::error('Lead generator not loaded. Make sure plugin is active.');
        }

        $limit = intval($assoc_args['limit'] ?? 50);
        $leads = rawwire_leads();

        WP_CLI::log("Scoring up to {$limit} unprocessed sources...");

        $result = $leads->score_unprocessed($limit);

        WP_CLI::log("Total processed: {$result['total']}");
        WP_CLI::log("Successfully scored: {$result['scored']}");
        WP_CLI::log("Failed: {$result['failed']}");

        if (!empty($result['promotion'])) {
            WP_CLI::log("Auto-promoted: {$result['promotion']['promoted']}");
            WP_CLI::log("Auto-archived: {$result['promotion']['archived']}");
        }

        WP_CLI::success('Scoring complete.');
    }

    /**
     * Backfill party data from Socrata xnhu-aczu for existing records.
     *
     * Fetches contractor/applicant data for records that have permit numbers
     * but are missing party information. This cross-references with the
     * xnhu-aczu dataset which contains party data not in pi9x-tg5x.
     *
     * ## OPTIONS
     *
     * [--limit=<number>]
     * : Maximum records to process.
     * ---
     * default: 100
     * ---
     *
     * [--dry-run]
     * : Preview changes without updating database.
     *
     * ## EXAMPLES
     *
     *     wp rawwire pipeline backfill-parties
     *     wp rawwire pipeline backfill-parties --limit=500
     *     wp rawwire pipeline backfill-parties --dry-run
     *
     * @param array $args       Positional args.
     * @param array $assoc_args Associative args.
     */
    public function backfill_parties($args, $assoc_args)
    {
        global $wpdb;

        if (!function_exists('rawwire_leads')) {
            WP_CLI::error('Lead generator not loaded. Make sure plugin is active.');
        }

        $limit = intval($assoc_args['limit'] ?? 100);
        $dry_run = isset($assoc_args['dry-run']);

        $leads = rawwire_leads();
        $table = $leads->table('sources');
        $client = $leads->get_socrata_client();

        if (is_wp_error($client)) {
            WP_CLI::error('Could not initialize Socrata client: ' . $client->get_error_message());
        }

        // Find records missing party data
        $records = $wpdb->get_results($wpdb->prepare(
            "SELECT id, permit_nbr FROM {$table} 
             WHERE (contractor_name = '' OR contractor_name IS NULL)
               AND (applicant_name = '' OR applicant_name IS NULL)
               AND permit_nbr != ''
             LIMIT %d",
            $limit
        ), ARRAY_A);

        if (empty($records)) {
            WP_CLI::success('No records need party data backfill.');
            return;
        }

        WP_CLI::log(sprintf('Found %d records missing party data.', count($records)));

        if ($dry_run) {
            WP_CLI::log('[DRY RUN] Would fetch party data for:');
            foreach (array_slice($records, 0, 10) as $rec) {
                WP_CLI::log("  - {$rec['permit_nbr']} (ID: {$rec['id']})");
            }
            if (count($records) > 10) {
                WP_CLI::log(sprintf('  ... and %d more', count($records) - 10));
            }
            return;
        }

        $updated = 0;
        $found = 0;
        $not_found = 0;

        $progress = \WP_CLI\Utils\make_progress_bar('Fetching party data', count($records));

        foreach ($records as $record) {
            $party_data = $client->fetch_party_data($record['permit_nbr']);

            if (!empty($party_data)) {
                $found++;

                $update_data = [];
                if (!empty($party_data['contractor_name'])) {
                    $update_data['contractor_name'] = $party_data['contractor_name'];
                }
                if (!empty($party_data['contractor_license'])) {
                    $update_data['contractor_license'] = $party_data['contractor_license'];
                }
                if (!empty($party_data['applicant_name'])) {
                    $update_data['applicant_name'] = $party_data['applicant_name'];
                }
                if (!empty($party_data['applicant_business'])) {
                    $update_data['applicant_business'] = $party_data['applicant_business'];
                }

                if (!empty($update_data)) {
                    $result = $wpdb->update($table, $update_data, ['id' => $record['id']]);
                    if ($result !== false) {
                        $updated++;
                    }
                }
            } else {
                $not_found++;
            }

            $progress->tick();

            // Rate limit Socrata API requests
            usleep(100000); // 100ms delay
        }

        $progress->finish();

        WP_CLI::log("Found in xnhu-aczu: {$found}");
        WP_CLI::log("Not found: {$not_found}");
        WP_CLI::log("Records updated: {$updated}");
        WP_CLI::success('Party data backfill complete.');
    }

    /**
     * Import permits from Socrata API with party data.
     *
     * Imports from xnhu-aczu dataset which includes contractor/applicant info.
     * Filters for commercial high-value permits by default.
     *
     * ## OPTIONS
     *
     * [--limit=<number>]
     * : Maximum records to import.
     * ---
     * default: 50
     * ---
     *
     * [--min-valuation=<amount>]
     * : Minimum permit valuation.
     * ---
     * default: 500000
     * ---
     *
     * [--months=<number>]
     * : Look back months from today for recent permits.
     * ---
     * default: 3
     * ---
     *
     * [--include-owner-builder]
     * : Include OWNER-BUILDER permits (excluded by default).
     *
     * [--dry-run]
     * : Preview import without inserting records.
     *
     * ## EXAMPLES
     *
     *     wp rawwire pipeline import-commercial
     *     wp rawwire pipeline import-commercial --limit=100 --min-valuation=1000000
     *     wp rawwire pipeline import-commercial --dry-run
     *
     * @param array $args       Positional args.
     * @param array $assoc_args Associative args.
     */
    public function import_commercial($args, $assoc_args)
    {
        if (!function_exists('rawwire_leads')) {
            WP_CLI::error('Lead generator not loaded. Make sure plugin is active.');
        }

        $limit = intval($assoc_args['limit'] ?? 50);
        $min_val = intval($assoc_args['min-valuation'] ?? 500000);
        $months = intval($assoc_args['months'] ?? 3);
        $exclude_owner = !isset($assoc_args['include-owner-builder']);
        $dry_run = isset($assoc_args['dry-run']);

        WP_CLI::log("Importing commercial permits from xnhu-aczu...");
        WP_CLI::log(sprintf("  Valuation: >= \$%s", number_format($min_val)));
        WP_CLI::log(sprintf("  Date range: %d months", $months));
        WP_CLI::log(sprintf("  Limit: %d records", $limit));
        WP_CLI::log(sprintf("  Exclude OWNER-BUILDER: %s", $exclude_owner ? 'yes' : 'no'));

        $leads = rawwire_leads();
        $params = array(
            'dataset'             => 'xnhu-aczu',
            'limit'               => $limit,
            'permit_types'        => array('Bldg-New', 'Bldg-Alter/Repair', 'Bldg-Addition'),
            'permit_sub_types'    => array('Commercial', 'Apartment'),
            'min_valuation'       => $min_val,
            'date_months'         => $months,
            'require_party_data'  => true,
            'exclude_owner_builder' => $exclude_owner,
        );

        if ($dry_run) {
            $client = $leads->get_socrata_client();
            if (is_wp_error($client)) {
                WP_CLI::error('Could not initialize Socrata client.');
            }

            $records = $client->fetch_permits($params);
            if (is_wp_error($records)) {
                WP_CLI::error('Fetch failed: ' . $records->get_error_message());
            }

            WP_CLI::log(sprintf('[DRY RUN] Would import %d records:', count($records)));
            foreach (array_slice($records, 0, 10) as $rec) {
                $val = isset($rec['valuation']) ? number_format(floatval($rec['valuation'])) : 'N/A';
                $contractor = $rec['contractors_business_name'] ?? 'N/A';
                WP_CLI::log(sprintf(
                    "  - %s | \$%s | %s",
                    $rec['pcis_permit'] ?? 'N/A',
                    $val,
                    substr($contractor, 0, 40)
                ));
            }
            if (count($records) > 10) {
                WP_CLI::log(sprintf('  ... and %d more', count($records) - 10));
            }
            return;
        }

        $result = $leads->import($params);

        if (!$result['success']) {
            WP_CLI::error('Import failed: ' . ($result['error'] ?? 'Unknown error'));
        }

        WP_CLI::log("");
        WP_CLI::log("=== Import Results ===");
        WP_CLI::log("Fetched from API:  {$result['fetched']}");
        WP_CLI::log("Inserted:          {$result['imported']}");
        WP_CLI::log("Duplicates skipped: {$result['skipped']}");
        WP_CLI::log("Errors:            {$result['errors']}");
        WP_CLI::log("Batch ID:          {$result['batch_id']}");
        WP_CLI::success('Import complete.');
    }

    /**
     * Run Party Investigator on source records.
     *
     * Uses Brave Search API to research contractor/applicant contact info
     * and AI to extract structured profiles.
     *
     * ## OPTIONS
     *
     * [--limit=<number>]
     * : Maximum sources to investigate.
     * ---
     * default: 10
     * ---
     *
     * [--force]
     * : Re-investigate even if recently checked.
     *
     * [--dry-run]
     * : Preview which records would be investigated.
     *
     * ## EXAMPLES
     *
     *     wp rawwire pipeline investigate
     *     wp rawwire pipeline investigate --limit=50
     *     wp rawwire pipeline investigate --force
     *
     * @param array $args       Positional args.
     * @param array $assoc_args Associative args.
     */
    public function investigate($args, $assoc_args)
    {
        global $wpdb;

        if (!class_exists('RawWire_Party_Investigator')) {
            WP_CLI::error('Party Investigator not loaded. Make sure plugin is active.');
        }

        $limit = intval($assoc_args['limit'] ?? 10);
        $force = isset($assoc_args['force']);
        $dry_run = isset($assoc_args['dry-run']);

        $investigator = RawWire_Party_Investigator::get_instance();
        $settings = RawWire_Party_Investigator::get_settings();

        // Check provider availability
        $provider = $settings['search_provider'] ?? 'duckduckgo';
        if (!$investigator->is_available()) {
            $msg = match ($provider) {
                'brave' => 'Brave API key not configured. Set it in AI Settings > Party Investigator.',
                'openclaw' => 'OpenClaw gateway not available. Make sure it is running.',
                default => 'Party Investigator not available.',
            };
            WP_CLI::error($msg);
        }

        if (!$settings['enabled']) {
            WP_CLI::warning('Party Investigator is disabled. Proceeding anyway.');
        }

        $leads = rawwire_leads();
        $table = $leads->table('sources');

        // Find sources needing investigation:
        // - Status is 'none' or 'pending' (not yet processed)
        // - Or stale (investigated >7 days ago)
        $where = "1=1";
        if (!$force) {
            $where .= " AND (investigation_status IN ('none', 'pending', '') OR investigation_status IS NULL OR parties_investigated_at < DATE_SUB(NOW(), INTERVAL 7 DAY))";
        }

        $records = $wpdb->get_results($wpdb->prepare(
            "SELECT id, permit_nbr, contractor_name, applicant_name 
             FROM {$table} 
             WHERE {$where}
             ORDER BY valuation DESC
             LIMIT %d",
            $limit
        ), ARRAY_A);

        if (empty($records)) {
            WP_CLI::success('No records need investigation.');
            return;
        }

        WP_CLI::log(sprintf('Found %d sources to investigate.', count($records)));

        if ($dry_run) {
            WP_CLI::log('[DRY RUN] Would investigate:');
            foreach (array_slice($records, 0, 10) as $rec) {
                $parties = array_filter([$rec['contractor_name'], $rec['applicant_name']]);
                WP_CLI::log(sprintf('  - %s: %s', $rec['permit_nbr'], implode(', ', $parties)));
            }
            if (count($records) > 10) {
                WP_CLI::log(sprintf('  ... and %d more', count($records) - 10));
            }
            return;
        }

        $investigated = 0;
        $skipped = 0;
        $errors = 0;

        $progress = \WP_CLI\Utils\make_progress_bar('Investigating parties', count($records));

        foreach ($records as $record) {
            $result = $investigator->investigate_source_parties($record['id']);

            if (is_wp_error($result)) {
                $errors++;
                WP_CLI::debug('Error on #' . $record['id'] . ': ' . $result->get_error_message());
            } elseif (!empty($result['skipped'])) {
                $skipped++;
            } else {
                $investigated++;
            }

            $progress->tick();

            // Rate limit API calls
            sleep(2);
        }

        $progress->finish();

        WP_CLI::log("");
        WP_CLI::log("=== Investigation Results ===");
        WP_CLI::log("Investigated: {$investigated}");
        WP_CLI::log("Skipped:      {$skipped}");
        WP_CLI::log("Errors:       {$errors}");
        WP_CLI::success('Investigation complete.');
    }

    /**
     * Reset (truncate) all lead generator tables.
     *
     * Clears all data from sources, candidates, archive, content,
     * network_entities, and network_projects tables.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Skip confirmation prompt.
     *
     * [--keep-network]
     * : Preserve network_entities and network_projects tables.
     *
     * ## EXAMPLES
     *
     *     wp rawwire pipeline reset-tables --yes
     *     wp rawwire pipeline reset-tables --keep-network
     *
     * @param array $args       Positional args.
     * @param array $assoc_args Associative args.
     */
    public function reset_tables($args, $assoc_args)
    {
        global $wpdb;

        $skip_confirm = isset($assoc_args['yes']);
        $keep_network = isset($assoc_args['keep-network']);

        $tables = array(
            'rawwire_lead_sources',
            'rawwire_lead_candidates',
            'rawwire_lead_archive',
            'rawwire_lead_content',
        );

        if (!$keep_network) {
            $tables[] = 'rawwire_network_entities';
            $tables[] = 'rawwire_network_projects';
        }

        if (!$skip_confirm) {
            WP_CLI::confirm('This will DELETE ALL DATA in the lead generator tables. Continue?');
        }

        WP_CLI::log('Truncating lead generator tables...');

        foreach ($tables as $table) {
            $full_table = $wpdb->prefix . $table;

            // Check if table exists
            $exists = $wpdb->get_var("SHOW TABLES LIKE '{$full_table}'");
            if (!$exists) {
                WP_CLI::warning("Table {$table} does not exist, skipping.");
                continue;
            }

            $wpdb->query("TRUNCATE TABLE {$full_table}");
            WP_CLI::log("  ✓ Truncated {$table}");
        }

        // Force schema upgrade to add any new columns
        delete_option('rawwire_lead_db_version');

        if (function_exists('rawwire_leads')) {
            rawwire_leads()->maybe_create_tables();
            WP_CLI::log("  ✓ Schema verified/upgraded");
        }

        WP_CLI::success('All lead generator tables have been reset.');
    }

    /**
     * Bulk reset investigation status on all investigated records.
     *
     * Clears investigation data and returns leads to pristine state.
     * Optionally purges child records (network entities, company profiles,
     * contacts, events, relationships) linked to reset sources.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Skip confirmation prompt.
     *
     * [--purge-children]
     * : Also delete child records (network_entities, company_profiles,
     *   contacts, events, relationships) linked to reset sources.
     *
     * [--dry-run]
     * : Show what would be reset without making changes.
     *
     * [--status=<statuses>]
     * : Comma-separated investigation statuses to reset.
     * ---
     * default: completed,no_parties_found,failed,in_progress
     * ---
     *
     * ## EXAMPLES
     *
     *     wp rawwire pipeline reset-investigations --dry-run
     *     wp rawwire pipeline reset-investigations --yes
     *     wp rawwire pipeline reset-investigations --yes --purge-children
     *     wp rawwire pipeline reset-investigations --status=completed
     *
     * @param array $args       Positional args.
     * @param array $assoc_args Associative args.
     */
    public function reset_investigations($args, $assoc_args)
    {
        global $wpdb;

        $dry_run        = isset($assoc_args['dry-run']);
        $skip_confirm   = isset($assoc_args['yes']);
        $purge_children = isset($assoc_args['purge-children']);
        $statuses_raw   = $assoc_args['status'] ?? 'completed,no_parties_found,failed,in_progress';
        $statuses       = array_map('trim', explode(',', $statuses_raw));

        $sources_table = $wpdb->prefix . 'rawwire_lead_sources';

        // Build placeholders for IN clause
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));

        // Count affected records
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$sources_table}
                 WHERE investigation_status IN ({$placeholders})",
                ...$statuses
            )
        );

        if ($count === 0) {
            WP_CLI::success('No investigated records found. Everything is already pristine.');
            return;
        }

        WP_CLI::log(sprintf(
            'Found %d source(s) with investigation_status IN (%s).',
            $count,
            implode(', ', $statuses)
        ));

        if ($dry_run) {
            // Show a sample
            $sample = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, permit_nbr, investigation_status, contractor_name
                     FROM {$sources_table}
                     WHERE investigation_status IN ({$placeholders})
                     ORDER BY id DESC
                     LIMIT 10",
                    ...$statuses
                ),
                ARRAY_A
            );

            WP_CLI::log('[DRY RUN] Sample records that would be reset:');
            foreach ($sample as $row) {
                WP_CLI::log(sprintf(
                    '  #%d  %s  [%s]  %s',
                    $row['id'],
                    $row['permit_nbr'],
                    $row['investigation_status'],
                    $row['contractor_name']
                ));
            }
            if ($count > 10) {
                WP_CLI::log(sprintf('  ... and %d more', $count - 10));
            }

            if ($purge_children) {
                // Count children
                $source_ids_sql = $wpdb->prepare(
                    "SELECT id FROM {$sources_table}
                     WHERE investigation_status IN ({$placeholders})",
                    ...$statuses
                );

                $child_tables = array(
                    'rawwire_network_entities'  => 'source_id',
                    'rawwire_company_profiles'  => 'source_id',
                    'rawwire_contacts'          => 'source_id',
                    'rawwire_events'            => 'source_id',
                    'rawwire_relationships'     => 'from_id',
                );

                foreach ($child_tables as $child_table => $fk_col) {
                    $full_table = $wpdb->prefix . $child_table;
                    $exists = $wpdb->get_var("SHOW TABLES LIKE '{$full_table}'");
                    if (!$exists) {
                        continue;
                    }
                    $child_count = (int) $wpdb->get_var(
                        "SELECT COUNT(*) FROM {$full_table}
                         WHERE {$fk_col} IN ({$source_ids_sql})"
                    );
                    WP_CLI::log(sprintf(
                        '  Would delete %d row(s) from %s',
                        $child_count,
                        $child_table
                    ));
                }
            }

            return;
        }

        if (!$skip_confirm) {
            $msg = sprintf(
                'This will reset %d investigated source(s) to pristine state.',
                $count
            );
            if ($purge_children) {
                $msg .= ' Child records (entities, profiles, contacts, events, relationships) will also be DELETED.';
            }
            WP_CLI::confirm($msg);
        }

        // Collect source IDs before reset (needed for child purge)
        $source_ids = array();
        if ($purge_children) {
            $source_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT id FROM {$sources_table}
                     WHERE investigation_status IN ({$placeholders})",
                    ...$statuses
                )
            );
        }

        // Bulk reset investigation fields
        $reset_result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$sources_table} SET
                    party_profiles          = NULL,
                    parties_investigated_at  = NULL,
                    investigation_status     = 'pending',
                    investigated_by          = '',
                    investigator_notes       = NULL,
                    investigator_findings    = NULL,
                    investigator_flags       = NULL,
                    investigator_opportunities = NULL,
                    investigator_confidence  = NULL,
                    investigator_summary     = NULL
                 WHERE investigation_status IN ({$placeholders})",
                ...$statuses
            )
        );

        if ($reset_result === false) {
            WP_CLI::error('Failed to reset sources: ' . $wpdb->last_error);
        }

        WP_CLI::log(sprintf('  ✓ Reset %d source(s) to pristine state.', $reset_result));

        // Purge child records if requested
        if ($purge_children && !empty($source_ids)) {
            $ids_placeholder = implode(',', array_map('intval', $source_ids));

            $child_tables = array(
                'rawwire_contacts'          => 'source_id',
                'rawwire_company_profiles'  => 'source_id',
                'rawwire_network_entities'  => 'source_id',
                'rawwire_events'            => 'source_id',
                'rawwire_relationships'     => 'from_id',
            );

            foreach ($child_tables as $child_table => $fk_col) {
                $full_table = $wpdb->prefix . $child_table;
                $exists = $wpdb->get_var("SHOW TABLES LIKE '{$full_table}'");
                if (!$exists) {
                    continue;
                }

                $deleted = $wpdb->query(
                    "DELETE FROM {$full_table} WHERE {$fk_col} IN ({$ids_placeholder})"
                );

                if ($deleted === false) {
                    WP_CLI::warning("Failed to purge {$child_table}: " . $wpdb->last_error);
                } else {
                    WP_CLI::log(sprintf('  ✓ Deleted %d row(s) from %s', $deleted, $child_table));
                }
            }
        }

        // Also reset candidate scoring flags tied to these sources
        $candidates_table = $wpdb->prefix . 'rawwire_lead_candidates';
        $candidates_exist = $wpdb->get_var("SHOW TABLES LIKE '{$candidates_table}'");
        if ($candidates_exist && !empty($source_ids)) {
            $ids_placeholder = implode(',', array_map('intval', $source_ids));
            $wpdb->query(
                "UPDATE {$candidates_table} SET status = 'pending', scored_at = NULL
                 WHERE source_id IN ({$ids_placeholder}) AND status = 'scored'"
            );
        }

        rawwire_log('pipeline-cli', sprintf(
            'Bulk investigation reset: %d sources returned to pristine state%s',
            $reset_result,
            $purge_children ? ' (children purged)' : ''
        ), 'info');

        WP_CLI::success(sprintf(
            'Reset complete. %d source(s) returned to pristine state.',
            $reset_result
        ));
    }

    /**
     * Deduplicate lead sources by permit number.
     *
     * Finds duplicate records in rawwire_lead_sources sharing the same
     * permit_nbr (or source_dataset + source_record_id) and removes extras,
     * keeping the newest record (highest ID). Cascades deletes to child tables.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Skip confirmation prompt.
     *
     * [--dry-run]
     * : Show duplicates without deleting anything.
     *
     * [--by=<field>]
     * : Dedup key: 'permit' (permit_nbr) or 'source' (source_dataset+source_record_id).
     * ---
     * default: permit
     * options:
     *   - permit
     *   - source
     * ---
     *
     * [--keep=<strategy>]
     * : Which duplicate to keep: 'newest' (highest ID) or 'oldest' (lowest ID).
     * ---
     * default: newest
     * options:
     *   - newest
     *   - oldest
     * ---
     *
     * ## EXAMPLES
     *
     *     wp rawwire pipeline dedup --dry-run
     *     wp rawwire pipeline dedup --yes
     *     wp rawwire pipeline dedup --by=source --keep=oldest --yes
     *
     * @param array $args       Positional args.
     * @param array $assoc_args Associative args.
     */
    public function dedup($args, $assoc_args)
    {
        global $wpdb;

        $dry_run      = isset($assoc_args['dry-run']);
        $skip_confirm = isset($assoc_args['yes']);
        $dedup_by     = $assoc_args['by'] ?? 'permit';
        $keep         = $assoc_args['keep'] ?? 'newest';

        $sources_table = $wpdb->prefix . 'rawwire_lead_sources';

        // Build grouping query
        if ($dedup_by === 'source') {
            $group_cols = 'source_dataset, source_record_id';
            $label      = 'source_dataset + source_record_id';
        } else {
            $group_cols = 'permit_nbr';
            $label      = 'permit_nbr';
        }

        $keep_func = ($keep === 'oldest') ? 'MIN' : 'MAX';

        // Find duplicate groups
        $dupes = $wpdb->get_results(
            "SELECT {$group_cols}, COUNT(*) AS cnt, {$keep_func}(id) AS keep_id
             FROM {$sources_table}
             WHERE {$group_cols} != ''
             GROUP BY {$group_cols}
             HAVING cnt > 1
             ORDER BY cnt DESC",
            ARRAY_A
        );

        if (empty($dupes)) {
            WP_CLI::success("No duplicate records found (grouped by {$label}).");
            return;
        }

        $total_dupes = array_sum(array_column($dupes, 'cnt')) - count($dupes);

        WP_CLI::log(sprintf(
            'Found %d duplicate group(s) with %d extra record(s) (grouped by %s, keeping %s).',
            count($dupes),
            $total_dupes,
            $label,
            $keep
        ));

        if ($dry_run) {
            WP_CLI::log('[DRY RUN] Duplicate groups:');
            foreach (array_slice($dupes, 0, 20) as $dupe) {
                $key = ($dedup_by === 'source')
                    ? $dupe['source_dataset'] . ':' . $dupe['source_record_id']
                    : $dupe['permit_nbr'];
                WP_CLI::log(sprintf(
                    '  %s — %d copies (keeping ID #%d)',
                    $key,
                    $dupe['cnt'],
                    $dupe['keep_id']
                ));
            }
            if (count($dupes) > 20) {
                WP_CLI::log(sprintf('  ... and %d more groups', count($dupes) - 20));
            }
            return;
        }

        if (!$skip_confirm) {
            WP_CLI::confirm(sprintf(
                'This will DELETE %d duplicate source record(s) and cascade to child tables. Continue?',
                $total_dupes
            ));
        }

        $deleted_sources  = 0;
        $deleted_children = 0;

        $child_tables = array(
            'rawwire_lead_candidates'   => 'source_id',
            'rawwire_lead_archive'      => 'source_id',
            'rawwire_lead_content'      => 'source_id',
            'rawwire_network_entities'  => 'source_id',
            'rawwire_company_profiles'  => 'source_id',
            'rawwire_contacts'          => 'source_id',
            'rawwire_events'            => 'source_id',
            'rawwire_relationships'     => 'from_id',
        );

        $progress = \WP_CLI\Utils\make_progress_bar('Deduplicating', count($dupes));

        foreach ($dupes as $dupe) {
            $keep_id = (int) $dupe['keep_id'];

            // Build WHERE to find the duplicates (excluding the kept record)
            if ($dedup_by === 'source') {
                $dup_ids = $wpdb->get_col($wpdb->prepare(
                    "SELECT id FROM {$sources_table}
                     WHERE source_dataset = %s AND source_record_id = %s AND id != %d",
                    $dupe['source_dataset'],
                    $dupe['source_record_id'],
                    $keep_id
                ));
            } else {
                $dup_ids = $wpdb->get_col($wpdb->prepare(
                    "SELECT id FROM {$sources_table}
                     WHERE permit_nbr = %s AND id != %d",
                    $dupe['permit_nbr'],
                    $keep_id
                ));
            }

            if (empty($dup_ids)) {
                $progress->tick();
                continue;
            }

            $ids_placeholder = implode(',', array_map('intval', $dup_ids));

            // Cascade delete child records first
            foreach ($child_tables as $child_table => $fk_col) {
                $full_table = $wpdb->prefix . $child_table;
                $exists = $wpdb->get_var("SHOW TABLES LIKE '{$full_table}'");
                if (!$exists) {
                    continue;
                }

                $child_del = $wpdb->query(
                    "DELETE FROM {$full_table} WHERE {$fk_col} IN ({$ids_placeholder})"
                );
                if ($child_del > 0) {
                    $deleted_children += $child_del;
                }
            }

            // Delete duplicate source records
            $src_del = $wpdb->query(
                "DELETE FROM {$sources_table} WHERE id IN ({$ids_placeholder})"
            );
            if ($src_del > 0) {
                $deleted_sources += $src_del;
            }

            $progress->tick();
        }

        $progress->finish();

        rawwire_log('pipeline-cli', sprintf(
            'Dedup complete: deleted %d duplicate sources + %d child records (by %s, kept %s)',
            $deleted_sources,
            $deleted_children,
            $label,
            $keep
        ), 'info');

        WP_CLI::log('');
        WP_CLI::log('=== Deduplication Results ===');
        WP_CLI::log("Groups found:     " . count($dupes));
        WP_CLI::log("Sources deleted:  {$deleted_sources}");
        WP_CLI::log("Children deleted: {$deleted_children}");
        WP_CLI::success('Deduplication complete.');
    }
}

WP_CLI::add_command('rawwire pipeline', 'RawWire_Pipeline_CLI');
