<?php

/**
 * Batch discovery script — score-priority investigation
 *
 * Investigates leads in score-ranked order so your best prospects
 * get deep-dived first. Lower-ranked leads trickle in to build
 * network maps over time.
 *
 * Usage: php batch-discover.php [--limit=N] [--tier=deep|basic|all] [--min-score=N]
 *
 * Tiers:
 *   deep  — Top leads (score >= 6.0): full web research, CSLB/BuildZoom/LADBS lookup
 *   basic — Mid leads (score 4.0-5.99): address-based discovery only, no deep research
 *   all   — Run both tiers in priority order (default)
 *
 * Examples:
 *   php batch-discover.php                    # all pending, score order
 *   php batch-discover.php --limit=10         # top 10 only
 *   php batch-discover.php --tier=deep        # only score >= 6.0
 *   php batch-discover.php --min-score=5.5    # custom threshold
 */

require_once '/var/www/html/wordpress/wp-load.php';

// Parse CLI args
$opts = getopt('', ['limit:', 'tier:', 'min-score:']);
$limit     = (int)($opts['limit'] ?? 0);
$tier      = $opts['tier'] ?? 'all';
$min_score = (float)($opts['min-score'] ?? 0);

// Tier thresholds
$DEEP_THRESHOLD  = 6.0;
$BASIC_THRESHOLD = 4.0;

global $wpdb;
$src_table = $wpdb->prefix . 'rawwire_lead_sources';
$cand_table = $wpdb->prefix . 'rawwire_lead_candidates';

// Build score filter based on tier
$score_filter = '';
if ($tier === 'deep') {
    $score_filter = "AND COALESCE(c.score_composite, 0) >= $DEEP_THRESHOLD";
} elseif ($tier === 'basic') {
    $score_filter = "AND COALESCE(c.score_composite, 0) >= $BASIC_THRESHOLD AND COALESCE(c.score_composite, 0) < $DEEP_THRESHOLD";
}
if ($min_score > 0) {
    $score_filter = "AND COALESCE(c.score_composite, 0) >= $min_score";
}

$limit_clause = $limit > 0 ? "LIMIT $limit" : '';

// Get pending sources ordered by score (highest first)
$pending = $wpdb->get_results(
    "SELECT s.id, s.permit_nbr, LEFT(s.primary_address, 40) as addr,
            COALESCE(c.score_composite, 0) as score
     FROM {$src_table} s
     LEFT JOIN {$cand_table} c ON s.id = c.source_id
     WHERE s.investigation_status IN ('pending', 'scheduled')
     {$score_filter}
     ORDER BY COALESCE(c.score_composite, 0) DESC
     {$limit_clause}"
);

$total = count($pending);
echo "=== Batch Discovery (score-priority) ===\n";
echo "Tier: $tier | Min score: $min_score | Limit: " . ($limit ?: 'all') . "\n";
echo "Found $total pending records\n";

if ($total > 0) {
    echo "\nPriority queue:\n";
    foreach (array_slice($pending, 0, 10) as $p) {
        echo sprintf("  #%-3d  %s  (%.2f)  %s\n", $p->id, $p->permit_nbr, $p->score, $p->addr);
    }
    if ($total > 10) echo "  ... and " . ($total - 10) . " more\n";
    echo "\n";
}

if ($total === 0) {
    echo "Nothing to do.\n";
    exit(0);
}

// Load the party investigator
require_once __DIR__ . '/cores/lead-generator/class-party-investigator.php';
$investigator = rawwire_party_investigator();

$successes = 0;
$failures  = 0;
$no_data   = 0;

foreach ($pending as $i => $record) {
    $source_id = $record->id;
    $score     = (float) $record->score;
    $is_deep   = $score >= $DEEP_THRESHOLD;
    $num       = $i + 1;

    $tier_label = $is_deep ? 'DEEP' : 'basic';
    echo sprintf(
        "\n--- [%d/%d] Source #%d | %.2f pts | %s | %s ---\n",
        $num,
        $total,
        $source_id,
        $score,
        $tier_label,
        $record->addr
    );

    try {
        $result = $investigator->investigate_source_parties((int)$source_id);

        // Check what happened
        $source = $wpdb->get_row(
            $wpdb->prepare("SELECT contractor_name, owner_name, applicant_name, investigation_status FROM {$src_table} WHERE id = %d", $source_id),
            ARRAY_A
        );

        $has_data = !empty($source['contractor_name']) || !empty($source['owner_name']) || !empty($source['applicant_name']);
        $status = $source['investigation_status'] ?? '?';

        if ($has_data) {
            $successes++;
            $parts = array_filter([
                !empty($source['contractor_name']) ? "C={$source['contractor_name']}" : '',
                !empty($source['owner_name']) ? "O={$source['owner_name']}" : '',
                !empty($source['applicant_name']) ? "A={$source['applicant_name']}" : '',
            ]);
            echo "  ✓ Found: " . implode(' | ', $parts) . "\n";
        } elseif ($status === 'no_parties_found') {
            $no_data++;
            echo "  ○ No parties found\n";
        } else {
            $failures++;
            echo "  ✗ Status: $status\n";
        }
    } catch (\Throwable $e) {
        $failures++;
        echo "  ✗ Error: " . $e->getMessage() . "\n";
    }

    // Rate limit: deep investigations get more breathing room
    if ($i < $total - 1) {
        $delay = $is_deep ? 3 : 2;
        sleep($delay);
    }

    // Progress summary every 10 records
    if ($num % 10 === 0) {
        echo "\n--- Progress: $num/$total done ($successes found, $no_data empty, $failures failed) ---\n";
    }
}

echo "\n=== COMPLETE ===\n";
echo "Total: $total | Found data: $successes | No data: $no_data | Failed: $failures\n";
echo "Success rate: " . round(($successes / $total) * 100) . "%\n";
