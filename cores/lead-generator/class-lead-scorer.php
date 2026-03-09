<?php

/**
 * RawWire Lead Scorer — AI-Powered Multi-Class Weighted Scoring
 *
 * Evaluates building permit records for feasibility of needing
 * interior design architect services (green/sustainable focus).
 *
 * Scoring Classes:
 * 1. Project Scale      — Valuation, square footage, height
 * 2. Type Fit           — New construction > renovation > repair
 * 3. Green Indicators   — Solar, EV, sustainability keywords
 * 4. Timeline           — How early in pipeline (submitted > issued > finaled)
 * 5. Location Premium   — High-value neighborhoods/zip codes
 * 6. Use Relevance      — Office/hotel/mixed-use > warehouse/industrial
 * 7. Complexity         — Stories, plan check, construction type
 *
 * @package RawWire_Dashboard
 * @since   1.0.27
 */

if (! defined('ABSPATH')) {
    exit;
}

// OpenClaw adapter for AI-enhanced scoring via Venice + Grok with web search
require_once __DIR__ . '/class-openclaw-adapter.php';

class RawWire_Lead_Scorer
{

    /** @var RawWire_Lead_Generator */
    private $generator;

    /** @var array Cached weights */
    private $weights;

    /**
     * Green/sustainability keywords for work description analysis
     */
    const GREEN_KEYWORDS = array(
        'tier_1' => array( // Strong green indicators (score boost: 3-4 points)
            'leed',
            'green building',
            'sustainable',
            'net zero',
            'net-zero',
            'passive house',
            'passivhaus',
            'energy star',
            'solar panel',
            'photovoltaic',
            'geothermal',
            'green roof',
            'living wall',
            'rainwater harvest',
            'greywater',
            'gray water',
            'biophilic',
            'mass timber',
            'cross laminated',
            'clt',
            'carbon neutral',
            'zero emission',
            'renewable energy',
            'title 24',
            'calgreen',
        ),
        'tier_2' => array( // Moderate indicators (score boost: 1-2 points)
            'energy efficient',
            'insulation',
            'hvac upgrade',
            'solar',
            'ev charging',
            'electric vehicle',
            'heat pump',
            'led lighting',
            'low-e glass',
            'double glaz',
            'triple glaz',
            'thermal',
            'ventilation',
            'air quality',
            'ductless',
            'mini-split',
            'water efficient',
            'drought tolerant',
            'xeriscaping',
            'recycled',
            'reclaimed',
            'bamboo',
            'cork flooring',
            'natural light',
            'daylighting',
            'skylights',
        ),
        'tier_3' => array( // Mild indicators (score boost: 0.5-1 points)
            'remodel',
            'renovation',
            'upgrade',
            'retrofit',
            'modernize',
            'interior',
            'tenant improvement',
            'ti',
            'fit-out',
            'buildout',
            'open plan',
            'open floor',
            'workspace',
            'office space',
            'lobby',
            'reception',
            'common area',
            'amenity',
        ),
    );

    /**
     * High-value LA zip codes (premium commercial/mixed-use areas)
     */
    const PREMIUM_ZIPS = array(
        // Downtown LA / Arts District
        '90012',
        '90013',
        '90014',
        '90015',
        '90017',
        '90021',
        '90071',
        // West LA / Century City
        '90024',
        '90025',
        '90034',
        '90035',
        '90064',
        // Beverly Hills / WeHo
        '90048',
        '90069',
        '90210',
        '90211',
        '90212',
        // Santa Monica
        '90401',
        '90402',
        '90403',
        '90404',
        '90405',
        // Hollywood / Silver Lake
        '90028',
        '90029',
        '90038',
        '90039',
        '90046',
        // Koreatown / Mid-Wilshire
        '90004',
        '90005',
        '90006',
        '90010',
        '90019',
        '90020',
        // Pasadena
        '91101',
        '91103',
        '91105',
        '91106',
        // Glendale / Burbank
        '91201',
        '91202',
        '91203',
        '91501',
        '91502',
        '91505',
        // El Segundo / Playa Vista
        '90245',
        '90094',
        // Culver City
        '90230',
        '90232',
    );

    /**
     * Use types ranked by relevance to interior design architect
     */
    const USE_RELEVANCE = array(
        // Tier 1 — Highly relevant (score 8-10)
        'Office'             => 10,
        'Hotel'              => 10,
        'Mixed Use'          => 9,
        'Restaurant'         => 9,
        'Medical Office'     => 9,
        'Retail'             => 8,
        'Clinic'             => 8,
        'Bank'               => 8,
        'Museum'             => 8,
        'Theater'            => 8,
        'School'             => 8,
        'Health Club'        => 8,
        'Day Care'           => 8,
        // Tier 2 — Moderately relevant (score 5-7)
        'Apartment'          => 7,
        'Condominium'        => 7,
        'Church'             => 7,
        'Library'            => 7,
        'Community Center'   => 7,
        'Spa'                => 7,
        'Clubhouse'          => 6,
        'Warehouse'          => 5,
        'Laboratory'         => 5,
        // Tier 3 — Low relevance (score 1-4)
        'Parking'            => 2,
        'Storage'            => 2,
        'Gas Station'        => 1,
        'Car Wash'           => 1,
        'Utility'            => 1,
    );

    /**
     * @param RawWire_Lead_Generator $generator
     */
    public function __construct($generator)
    {
        $this->generator = $generator;
        $this->weights   = $generator->get_weights();
    }

    /**
     * Score a single source record and insert into candidates table
     *
     * @param array $record          Source record (from rawwire_lead_sources)
     * @param bool  $skip_ai         Skip AI-enhanced analysis (heuristic-only scoring)
     * @param array $ai_adjustments  Pre-computed AI adjustments from triage (optional)
     * @return array|WP_Error Score results
     */
    public function score($record, $skip_ai = false, $ai_adjustments = array())
    {
        $scores = array(
            'project_scale'    => $this->score_project_scale($record),
            'type_fit'         => $this->score_type_fit($record),
            'green_indicators' => $this->score_green_indicators($record),
            'timeline'         => $this->score_timeline($record),
            'location'         => $this->score_location($record),
            'use_relevance'    => $this->score_use_relevance($record),
            'complexity'       => $this->score_complexity($record),
        );

        // AI-enhanced scoring — use pre-computed triage adjustments or call ai_analyze
        if (! $skip_ai) {
            if (! empty($ai_adjustments)) {
                // Pre-computed from combined triage+scoring — Grok returns absolute
                // scores (0-10), so override heuristic scores directly
                foreach ($ai_adjustments as $class => $value) {
                    if (isset($scores[$class])) {
                        $scores[$class] = max(0, min(10, floatval($value)));
                    }
                }
            } else {
                // Fallback: separate AI call returns relative adjustments (+/-)
                $ai_boost = $this->ai_analyze($record, $scores);
                if (! is_wp_error($ai_boost) && ! empty($ai_boost)) {
                    foreach ($ai_boost as $class => $adjustment) {
                        if (isset($scores[$class])) {
                            $scores[$class] = max(0, min(10, $scores[$class] + $adjustment));
                        }
                    }
                }
            }
        }

        // Calculate weighted composite
        $composite = $this->calculate_composite($scores);

        // Apply blacklist keyword penalty
        $blacklist_result = $this->apply_blacklist_penalty($record, $composite);
        $composite = $blacklist_result['adjusted'];

        // Build reasoning string
        $reasoning = $this->build_reasoning($record, $scores, $composite);

        // Append blacklist info to reasoning if any matches
        if (! empty($blacklist_result['matches'])) {
            $reasoning .= sprintf(
                "\nBLACKLIST: -%s pts [%s]",
                $blacklist_result['penalty'] * 10,
                implode(', ', $blacklist_result['matches'])
            );
        }

        // Check if candidate already exists for this source — UPDATE instead of INSERT
        global $wpdb;
        $candidates_table = $this->generator->table('candidates');
        $existing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$candidates_table} WHERE source_id = %d",
            $record['id']
        ));

        $candidate_data = array(
            'source_id'             => $record['id'],
            'permit_nbr'            => $record['permit_nbr'],
            'primary_address'       => $record['primary_address'],
            'zip_code'              => $record['zip_code'],
            'permit_type'           => $record['permit_type'],
            'permit_sub_type'       => $record['permit_sub_type'],
            'use_desc'              => $record['use_desc'],
            'work_desc'             => $record['work_desc'],
            'valuation'             => $record['valuation'],
            'square_footage'        => $record['square_footage'],
            'issue_date'            => $record['issue_date'],
            'contractor_name'       => $record['contractor_name'],
            'contractor_license'    => $record['contractor_license'],
            'applicant_name'        => $record['applicant_name'],
            'applicant_business'    => $record['applicant_business'],
            'lat'                   => $record['lat'],
            'lon'                   => $record['lon'],
            'solar'                 => $record['solar'],
            'ev'                    => $record['ev'],
            'score_project_scale'   => round($scores['project_scale'], 1),
            'score_type_fit'        => round($scores['type_fit'], 1),
            'score_green_indicators' => round($scores['green_indicators'], 1),
            'score_timeline'        => round($scores['timeline'], 1),
            'score_location'        => round($scores['location'], 1),
            'score_use_relevance'   => round($scores['use_relevance'], 1),
            'score_complexity'      => round($scores['complexity'], 1),
            'score_composite'       => round($composite, 2),
            'score_reasoning'       => $reasoning,
            'status'                => 'scored_pending',
            'scored_at'             => current_time('mysql'),
        );

        // Insert or Update based on whether candidate already exists
        if ($existing_id) {
            // Candidate exists - UPDATE scores only (preserve status, etc.)
            $result = $wpdb->update(
                $candidates_table,
                $candidate_data,
                array('id' => $existing_id)
            );
            $result = ($result !== false); // update returns rows affected or false
        } else {
            // New candidate - INSERT
            $result = $wpdb->insert($candidates_table, $candidate_data);
        }

        if (false === $result) {
            return new WP_Error('insert_failed', $wpdb->last_error);
        }

        return array(
            'scores'    => $scores,
            'composite' => $composite,
            'reasoning' => $reasoning,
        );
    }

    // =========================================================================
    // SCORING CLASSES
    // =========================================================================

    /**
     * Class 1: Project Scale (valuation, sqft, height)
     */
    private function score_project_scale($record)
    {
        $score = 0;
        $val   = floatval($record['valuation']);
        $sqft  = intval($record['square_footage']);

        // Valuation scoring (0-5 points)
        if ($val >= 10000000)      $score += 5.0;    // $10M+
        elseif ($val >= 5000000)   $score += 4.0;    // $5M+
        elseif ($val >= 2000000)   $score += 3.5;    // $2M+
        elseif ($val >= 1000000)   $score += 3.0;    // $1M+
        elseif ($val >= 500000)    $score += 2.0;    // $500K+
        elseif ($val >= 250000)    $score += 1.5;    // $250K+
        elseif ($val >= 100000)    $score += 1.0;    // $100K+
        else                         $score += 0.5;

        // Square footage scoring (0-3 points)
        if ($sqft >= 100000)       $score += 3.0;
        elseif ($sqft >= 50000)    $score += 2.5;
        elseif ($sqft >= 20000)    $score += 2.0;
        elseif ($sqft >= 10000)    $score += 1.5;
        elseif ($sqft >= 5000)     $score += 1.0;
        elseif ($sqft > 0)         $score += 0.5;

        // Height/stories bonus (0-2 points)
        $height = intval($record['height']);
        if ($height >= 100)        $score += 2.0;
        elseif ($height >= 50)     $score += 1.5;
        elseif ($height >= 30)     $score += 1.0;
        elseif ($height > 0)       $score += 0.5;

        return min(10, $score);
    }

    /**
     * Class 2: Type Fit (new construction > major renovation > minor repair)
     * Also rewards records with contractor/company data (critical for Soothsayer outreach)
     */
    private function score_type_fit($record)
    {
        $type = strtolower($record['permit_type']);
        $sub  = strtolower($record['permit_sub_type']);

        // Permit type scoring
        $type_score = 3; // default
        if (strpos($type, 'new') !== false)             $type_score = 8;
        elseif (strpos($type, 'addition') !== false)    $type_score = 7;
        elseif (strpos($type, 'alter') !== false)       $type_score = 5;
        elseif (strpos($type, 'demolition') !== false)  $type_score = 4; // demo often precedes new build
        elseif (strpos($type, 'grading') !== false)     $type_score = 3;

        // Sub-type modifier
        $sub_modifier = 0;
        if ($sub === 'commercial')                        $sub_modifier = 2;
        elseif ($sub === 'apartment')                     $sub_modifier = 1;
        elseif (strpos($sub, 'family') !== false)       $sub_modifier = -1;

        // Contractor/company data bonus — critical for Soothsayer outreach
        $contractor_bonus = 0;
        if (! empty($record['contractor_name']) && strtoupper($record['contractor_name']) !== 'OWNER-BUILDER') {
            $contractor_bonus += 1.5; // Has a real contractor name
        }
        if (! empty($record['applicant_business'])) {
            $contractor_bonus += 1.0; // Has company/business name
        }
        if (! empty($record['contractor_license'])) {
            $contractor_bonus += 0.5; // Has license — easier to research
        }

        return min(10, max(0, $type_score + $sub_modifier + $contractor_bonus));
    }

    /**
     * Class 3: Green/Sustainability Indicators
     * This is the highest-weighted class for our client.
     */
    private function score_green_indicators($record)
    {
        $score = 0;
        $desc  = strtolower($record['work_desc'] . ' ' . $record['use_desc']);

        // Check tier 1 keywords (strong green signals)
        $tier1_hits = 0;
        foreach (self::GREEN_KEYWORDS['tier_1'] as $kw) {
            if (strpos($desc, $kw) !== false) {
                $tier1_hits++;
            }
        }
        $score += min(5, $tier1_hits * 2.5); // Up to 5 points

        // Check tier 2 keywords
        $tier2_hits = 0;
        foreach (self::GREEN_KEYWORDS['tier_2'] as $kw) {
            if (strpos($desc, $kw) !== false) {
                $tier2_hits++;
            }
        }
        $score += min(3, $tier2_hits * 1.0); // Up to 3 points

        // Tier 3 keywords
        $tier3_hits = 0;
        foreach (self::GREEN_KEYWORDS['tier_3'] as $kw) {
            if (strpos($desc, $kw) !== false) {
                $tier3_hits++;
            }
        }
        $score += min(1.5, $tier3_hits * 0.5); // Up to 1.5 points

        // Solar/EV flags
        if (strtolower($record['solar']) === 'y') $score += 1.0;
        if (strtolower($record['ev']) === 'y')    $score += 0.5;

        // Baseline: even without keywords, new commercial has some green potential
        if ($score < 2 && strtolower($record['permit_type']) === 'bldg-new') {
            $score = max($score, 2.0); // New builds in LA must comply with CalGreen / Title 24
        }

        return min(10, $score);
    }

    /**
     * Class 4: Timeline Opportunity (how early in the pipeline)
     */
    private function score_timeline($record)
    {
        $now = time();
        $score = 5; // default

        // Status-based scoring
        $status = strtolower($record['status_desc']);
        if (strpos($status, 'pre-planning') !== false || strpos($status, 'application') !== false) {
            $score = 10; // Pre-planning — golden window for interior design engagement
        } elseif (strpos($status, 'submitted') !== false) {
            $score = 9.5; // Just submitted — excellent timing
        } elseif (strpos($status, 'plan check') !== false || strpos($status, 'plan review') !== false) {
            $score = 9; // In plan check — still early enough
        } elseif (strpos($status, 'ready to issue') !== false || strpos($status, 'corrections') !== false) {
            $score = 8; // Almost issued — good timing, decisions being made
        } elseif (strpos($status, 'issued') !== false) {
            $score = 6; // Issued — may still need interior design
        } elseif (strpos($status, 'inspection') !== false) {
            $score = 3; // Under construction — probably too late
        } elseif (strpos($status, 'finaled') !== false || strpos($status, 'cofo') !== false) {
            $score = 1; // Done — too late
        } elseif (strpos($status, 'expired') !== false) {
            $score = 1; // Expired — dead lead
        }

        // Recency bonus: newer permits are more actionable
        $issue_date = strtotime($record['issue_date']);
        if ($issue_date) {
            $days_old = ($now - $issue_date) / 86400;
            if ($days_old <= 30)        $score = min(10, $score + 1.5);
            elseif ($days_old <= 90)    $score = min(10, $score + 1.0);
            elseif ($days_old <= 180)   $score = min(10, $score + 0.5);
            elseif ($days_old > 365)    $score = max(1, $score - 2);
        }

        return min(10, max(0, $score));
    }

    /**
     * Class 5: Location Premium
     */
    private function score_location($record)
    {
        $zip = trim($record['zip_code']);
        $score = 5; // default

        if (in_array($zip, self::PREMIUM_ZIPS, true)) {
            $score = 8;
        }

        // Zone-based bonus
        $zone = strtoupper($record['zone']);
        if (preg_match('/^C[2-5]/', $zone))          $score = min(10, $score + 1); // Commercial zones
        if (preg_match('/^(CR|CW|RAS)/', $zone))     $score = min(10, $score + 1); // Mixed-use zones
        if (preg_match('/^M[1-3]/', $zone))           $score = max(3, $score - 1);  // Industrial

        return min(10, max(0, $score));
    }

    /**
     * Class 6: Use Type Relevance
     */
    private function score_use_relevance($record)
    {
        $use = trim($record['use_desc']);

        // Direct match
        if (isset(self::USE_RELEVANCE[$use])) {
            return self::USE_RELEVANCE[$use];
        }

        // Partial match
        $use_lower = strtolower($use);
        foreach (self::USE_RELEVANCE as $key => $val) {
            if (strpos($use_lower, strtolower($key)) !== false) {
                return $val;
            }
        }

        // Work description analysis for use type signals
        $desc = strtolower($record['work_desc']);
        if (strpos($desc, 'office') !== false)       return 8;
        if (strpos($desc, 'hotel') !== false)        return 9;
        if (strpos($desc, 'restaurant') !== false)   return 8;
        if (strpos($desc, 'retail') !== false)       return 7;
        if (strpos($desc, 'medical') !== false)      return 8;
        if (strpos($desc, 'clinic') !== false)       return 7;
        if (strpos($desc, 'apartment') !== false)    return 6;
        if (strpos($desc, 'mixed') !== false)        return 8;
        if (strpos($desc, 'tenant improvement') !== false) return 8;
        if (strpos($desc, 'ti ') !== false)          return 7;

        return 5; // Unknown — neutral
    }

    /**
     * Class 7: Complexity Score
     */
    private function score_complexity($record)
    {
        $score = 5;

        // Stories
        $stories = intval($record['stories']);
        if ($stories >= 20)       $score += 3;
        elseif ($stories >= 10)   $score += 2;
        elseif ($stories >= 5)    $score += 1;
        elseif ($stories >= 3)    $score += 0.5;

        // Construction type (fire-resistant types indicate larger/more complex builds)
        $const = strtoupper($record['construction_type']);
        if (strpos($const, 'TYPE I') !== false)    $score += 2;    // Fire-resistive (high-rises)
        if (strpos($const, 'TYPE II') !== false)   $score += 1.5;  // Non-combustible
        if (strpos($const, 'TYPE III') !== false)  $score += 1;    // Ordinary
        if (strpos($const, 'TYPE IV') !== false)   $score += 1.5;  // Heavy timber / mass timber

        // Business unit (Plan Check = more complex projects)
        $unit = strtolower($record['business_unit']);
        if (strpos($unit, 'regular plan check') !== false)    $score += 1;
        if (strpos($unit, 'plan check') !== false)            $score += 0.5;
        // Express permits are typically simpler
        if (strpos($unit, 'express') !== false)               $score -= 1;

        return min(10, max(0, $score));
    }

    // =========================================================================
    // COMPOSITE & AI
    // =========================================================================

    /**
     * Triage a batch of records via Grok (no web search — fast & cheap)
     *
     * Sends all records in a single API call for quick ranking.
     * Grok evaluates which records are worth full AI + web search scoring.
     *
     * @param array $records    Array of source records
     * @param int   $batch_size Records per chunk (default 10)
     * @param int   $picks_per_batch Top N per chunk (default 2)
     * @return array Associative array [record_id => [adjustments]] for triage-selected records.
     *               Adjustments are score class => float (e.g. ['green_indicators' => 3, 'timeline' => -2])
     */
    public function triage_batch(array $records, int $batch_size = 10, int $picks_per_batch = 2): array
    {
        if (empty($records)) {
            return array();
        }

        // If fewer records than one batch worth of picks, all get full scoring (no adjustments from triage)
        if (count($records) <= $picks_per_batch) {
            $result = array();
            foreach ($records as $r) {
                $result[(int) $r['id']] = array();
            }
            return $result;
        }

        // OpenClaw required for triage
        if (! class_exists('RawWire_OpenClaw_Adapter')) {
            rawwire_log('lead_scorer', 'OpenClaw not available for triage — all records get heuristic-only', 'warning');
            return array();
        }

        try {
            $openclaw = new RawWire_OpenClaw_Adapter();
        } catch (\Exception $e) {
            rawwire_log('lead_scorer', 'OpenClaw init failed for triage: ' . $e->getMessage(), 'warning');
            return array();
        }

        if (! $openclaw->is_available()) {
            rawwire_log('lead_scorer', 'OpenClaw unavailable for triage (cooldown or no API key)', 'warning');
            return array();
        }

        // ── Chunk records into small batches to stay under Venice ~2KB payload limit ──
        $chunks = array_chunk($records, $batch_size);
        $all_selected = array(); // [id => adjustments]
        $total_elapsed = 0;
        $chunk_results = array();

        rawwire_log('lead_scorer', sprintf(
            'Triage+Score: %d records → %d chunks of ≤%d, picking %d per chunk',
            count($records),
            count($chunks),
            $batch_size,
            $picks_per_batch
        ), 'info');

        $valid_classes = array('project_scale', 'type_fit', 'green_indicators', 'timeline', 'location', 'use_relevance', 'complexity');

        foreach ($chunks as $chunk_idx => $chunk) {
            // Recalculate picks for last chunk if it's smaller
            $picks = min($picks_per_batch, count($chunk));

            // Build compact record summaries — keep lines short (<100 chars)
            $record_lines = array();
            $chunk_valid_ids = array();
            foreach ($chunk as $record) {
                $chunk_valid_ids[] = (int) $record['id'];
                $val_k = round($record['valuation'] / 1000);
                $contractor = $record['contractor_name'] ?: '-';
                if (strlen($contractor) > 20) {
                    $contractor = substr($contractor, 0, 20);
                }
                $desc = substr(preg_replace('/\s+/', ' ', $record['work_desc']), 0, 60);

                $record_lines[] = sprintf(
                    "%d|%s|%s|$%dK|%ssf|%s|%s",
                    $record['id'],
                    $record['permit_type'],
                    $record['use_desc'] ?: '?',
                    $val_k,
                    $record['square_footage'] ?: '?',
                    $contractor,
                    $desc
                );
            }

            // COMPACT prompt — triage + score in one call to avoid Venice rate limits
            // Asks Grok to pick best N AND provide score adjustments for them
            $prompt = sprintf(
                "Pick the top %d best leads for a green interior design contractor in LA and adjust scores.\n" .
                    "Good: commercial, hotel, mixed-use, high-value, contractor name, green/sustainable, new construction\n" .
                    "Skip: low-income housing, parking, residential, demolition, minor repairs, re-roofing, signage\n" .
                    "Categories: project_scale,type_fit,green_indicators,timeline,location,use_relevance,complexity\n" .
                    "ID|Type|Use|Val|SqFt|Contractor|Desc\n%s\n" .
                    "Return JSON: [{\"id\":N,\"adj\":{\"cat\":val,...}},...]  %d items only.",
                $picks,
                implode("\n", $record_lines),
                $picks
            );

            $messages = array(
                array(
                    'role'    => 'user',
                    'content' => $prompt,
                ),
            );

            $start = microtime(true);

            // NO web search — keep payload small and fast
            $response = $openclaw->chat($messages, array(
                'temperature'         => 0.1,
                'max_tokens'          => 250,
                'enable_web_search'   => false,
                'enable_web_scraping' => false,
                'enable_web_citations' => false,
            ));

            $elapsed = round(microtime(true) - $start, 1);
            $total_elapsed += $elapsed;

            if (empty($response)) {
                rawwire_log('lead_scorer', sprintf(
                    'Triage chunk %d/%d returned empty after %ss — skipping',
                    $chunk_idx + 1,
                    count($chunks),
                    $elapsed
                ), 'warning');
                continue;
            }

            // Debug: log raw response
            rawwire_log('lead_scorer', sprintf(
                'Triage chunk %d/%d raw response (%d bytes): %s',
                $chunk_idx + 1,
                count($chunks),
                strlen($response),
                substr($response, 0, 500)
            ), 'debug');

            // Parse JSON from response — strip markdown fences if present
            $cleaned = trim($response);
            if (strpos($cleaned, '```json') === 0) {
                $cleaned = substr($cleaned, 7);
            } elseif (strpos($cleaned, '```') === 0) {
                $cleaned = substr($cleaned, 3);
            }
            if (substr($cleaned, -3) === '```') {
                $cleaned = substr($cleaned, 0, -3);
            }
            $cleaned = trim($cleaned);

            $result = json_decode($cleaned, true);
            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($result)) {
                rawwire_log('lead_scorer', sprintf(
                    'Triage chunk %d/%d JSON parse failed: %s',
                    $chunk_idx + 1,
                    count($chunks),
                    substr($response, 0, 200)
                ), 'warning');
                continue;
            }

            // Parse result — supports both formats:
            // New: [{"id":8,"adj":{"green_indicators":3}}, ...]
            // Legacy: [8, 3] (plain ID array — no adjustments)
            $chunk_selected_ids = array();
            foreach ($result as $item) {
                if (is_array($item) && isset($item['id'])) {
                    // New format: {id, adj}
                    $id = intval($item['id']);
                    if (in_array($id, $chunk_valid_ids, true) && ! isset($all_selected[$id])) {
                        $adj = array();
                        if (isset($item['adj']) && is_array($item['adj'])) {
                            foreach ($item['adj'] as $class => $val) {
                                if (in_array($class, $valid_classes, true) && is_numeric($val)) {
                                    $adj[$class] = floatval($val);
                                }
                            }
                        }
                        $all_selected[$id] = $adj;
                        $chunk_selected_ids[] = $id;
                    }
                } elseif (is_int($item) || is_string($item)) {
                    // Legacy format: plain ID
                    $id = intval($item);
                    if (in_array($id, $chunk_valid_ids, true) && ! isset($all_selected[$id])) {
                        $all_selected[$id] = array();
                        $chunk_selected_ids[] = $id;
                    }
                }
                if (count($chunk_selected_ids) >= $picks) {
                    break;
                }
            }

            $chunk_results[] = sprintf('chunk %d: [%s]', $chunk_idx + 1, implode(',', $chunk_selected_ids));

            rawwire_log('lead_scorer', sprintf(
                'Triage chunk %d/%d: picked %d/%d in %ss (IDs: %s)',
                $chunk_idx + 1,
                count($chunks),
                count($chunk_selected_ids),
                count($chunk),
                $elapsed,
                implode(', ', $chunk_selected_ids)
            ), 'debug');

            // Check if OpenClaw went into cooldown between chunks
            if (! $openclaw->is_available()) {
                rawwire_log('lead_scorer', 'OpenClaw entered cooldown during triage — stopping after chunk ' . ($chunk_idx + 1), 'warning');
                break;
            }
        }

        rawwire_log('lead_scorer', sprintf(
            'Triage+Score complete: %d/%d records selected in %ss (%s)',
            count($all_selected),
            count($records),
            round($total_elapsed, 1),
            implode('; ', $chunk_results)
        ), 'info');

        return $all_selected;
    }

    /**
     * Calculate weighted composite score
     */
    private function calculate_composite($scores)
    {
        $weighted_sum   = 0;
        $total_weight   = 0;

        $weight_map = array(
            'project_scale'    => $this->weights['project_scale'],
            'type_fit'         => $this->weights['type_fit'],
            'green_indicators' => $this->weights['green_indicators'],
            'timeline'         => $this->weights['timeline'],
            'location'         => $this->weights['location_premium'],
            'use_relevance'    => $this->weights['use_relevance'],
            'complexity'       => $this->weights['complexity'],
        );

        foreach ($scores as $class => $value) {
            $weight = isset($weight_map[$class]) ? $weight_map[$class] : 1.0;
            $weighted_sum += $value * $weight;
            $total_weight += $weight;
        }

        return $total_weight > 0 ? $weighted_sum / $total_weight : 0;
    }

    /**
     * Apply blacklist keyword penalty to composite score
     *
     * Scans work_desc, permit_type, permit_sub_type, and use_desc for blacklist keywords.
     * First match: -10 points penalty. After 2 matches: -20 points each additional.
     *
     * @param array $record Source/candidate record
     * @param float $composite Current composite score
     * @return array ['adjusted' => float, 'penalty' => float, 'matches' => string[]]
     */
    private function apply_blacklist_penalty($record, $composite)
    {
        $blacklist = $this->generator->get_blacklist();
        if (empty($blacklist)) {
            return array('adjusted' => $composite, 'penalty' => 0, 'matches' => array());
        }

        // Build searchable text from relevant fields
        $text = strtolower(implode(' ', array_filter(array(
            isset($record['work_desc']) ? $record['work_desc'] : '',
            isset($record['permit_type']) ? $record['permit_type'] : '',
            isset($record['permit_sub_type']) ? $record['permit_sub_type'] : '',
            isset($record['use_desc']) ? $record['use_desc'] : '',
        ))));

        $matches = array();
        foreach ($blacklist as $keyword) {
            if (false !== strpos($text, $keyword)) {
                $matches[] = $keyword;
            }
        }

        if (empty($matches)) {
            return array('adjusted' => $composite, 'penalty' => 0, 'matches' => array());
        }

        $count = count($matches);

        // First keyword: -10pts. Up to 2 keywords: -10pts each.
        // Beyond 2 keywords: -20pts each additional.
        if ($count <= 2) {
            $penalty = $count * 10;
        } else {
            $penalty = (2 * 10) + (($count - 2) * 20);
        }

        // Penalty is in raw points — scale to 0-10 composite range (divide by 10 since composite is 0-10 scale)
        $scaled_penalty = $penalty / 10;
        $adjusted = max(0, $composite - $scaled_penalty);

        rawwire_log('lead_scorer', sprintf(
            'Blacklist penalty: %d keyword(s) matched [%s] → -%s pts (%.2f → %.2f)',
            $count,
            implode(', ', $matches),
            $penalty,
            $composite,
            $adjusted
        ), 'debug');

        return array('adjusted' => $adjusted, 'penalty' => $scaled_penalty, 'matches' => $matches);
    }

    /**
     * AI-enhanced analysis via OpenClaw (Venice + Grok with web search)
     *
     * Uses OpenClaw to leverage Venice.ai's web search + scraping capabilities,
     * giving Grok browser-level access to look up contractor data, CSLB licenses,
     * and project details that aren't in the permit record itself.
     *
     * @param array $record     Source record
     * @param array $current_scores Heuristic scores
     * @return array Adjustments keyed by scoring class
     */
    private function ai_analyze($record, $current_scores)
    {
        // OpenClaw required — it provides Venice direct with web search + scraping
        if (! class_exists('RawWire_OpenClaw_Adapter')) {
            rawwire_log('lead_scorer', 'OpenClaw not available — using heuristic scores only', 'warning');
            return array();
        }

        // Only use AI for records with substantial work descriptions
        if (strlen($record['work_desc']) < 20) {
            return array();
        }

        try {
            $openclaw = new RawWire_OpenClaw_Adapter();
        } catch (\Exception $e) {
            rawwire_log('lead_scorer', 'OpenClaw init failed: ' . $e->getMessage(), 'warning');
            return array();
        }

        if (! $openclaw->is_available()) {
            rawwire_log('lead_scorer', 'OpenClaw unavailable (cooldown or no API key)', 'warning');
            return array();
        }

        // COMPACT prompt — must stay under ~1.5KB total payload for Venice API stability
        // Venice/Cloudflare drops connections when POST body exceeds ~2KB
        $desc = substr(preg_replace('/\s+/', ' ', $record['work_desc']), 0, 200);

        $prompt = sprintf(
            "Score adjustments for a green interior design contractor lead in LA.\n" .
                "Priorities: contractor data, early pipeline (pre-planning>issued), commercial/mixed-use, green/sustainable\n" .
                "Skip: low-income housing, parking, residential-only, demolition, minor repairs\n\n" .
                "%s (%s)|%s|%s %s|$%s|%ssf\n" .
                "Contractor: %s Lic:%s | Applicant: %s (%s)\n" .
                "Desc: %s\n\n" .
                "Heuristic: scale=%.1f type=%.1f green=%.1f time=%.1f loc=%.1f use=%.1f cmplx=%.1f\n\n" .
                "Return JSON adjustments: {\"green_indicators\":4,\"timeline\":-5}\nIf none: {}",
            $record['permit_type'],
            $record['permit_sub_type'],
            $record['use_desc'],
            $record['primary_address'],
            $record['zip_code'],
            number_format($record['valuation']),
            $record['square_footage'],
            $record['contractor_name'] ?: '-',
            $record['contractor_license'] ?: '-',
            $record['applicant_name'] ?: '-',
            $record['applicant_business'] ?: '-',
            $desc,
            $current_scores['project_scale'],
            $current_scores['type_fit'],
            $current_scores['green_indicators'],
            $current_scores['timeline'],
            $current_scores['location'],
            $current_scores['use_relevance'],
            $current_scores['complexity']
        );

        $messages = array(
            array(
                'role'    => 'user',
                'content' => $prompt,
            ),
        );

        $chat_options = array(
            'temperature'         => 0.3,
            'max_tokens'          => 150,
            'enable_web_search'   => false,
            'enable_web_scraping' => false,
            'enable_web_citations' => false,
        );

        // Retry logic for Venice API intermittent cURL 52 errors
        // Venice/Cloudflare occasionally drops connections after many rapid requests
        $response = '';
        $max_retries = 3;
        for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
            $response = $openclaw->chat($messages, $chat_options);
            if (! empty($response)) {
                break;
            }
            if ($attempt < $max_retries) {
                rawwire_log('lead_scorer', sprintf(
                    'AI scoring attempt %d/%d empty for #%d — retrying in 5s',
                    $attempt,
                    $max_retries,
                    $record['id']
                ), 'debug');
                // Reset provider cooldown so retry can proceed
                RawWire_Provider_Status::record_success('openclaw');
                sleep(5);
            }
        }

        if (empty($response)) {
            rawwire_log('lead_scorer', 'OpenClaw returned empty response for #' . $record['id'] . ' after ' . $max_retries . ' attempts', 'warning');
            return array();
        }

        // Parse JSON from response — strip markdown fences if present
        $cleaned = trim($response);
        if (strpos($cleaned, '```json') === 0) {
            $cleaned = substr($cleaned, 7);
        } elseif (strpos($cleaned, '```') === 0) {
            $cleaned = substr($cleaned, 3);
        }
        if (substr($cleaned, -3) === '```') {
            $cleaned = substr($cleaned, 0, -3);
        }
        $cleaned = trim($cleaned);

        $result = json_decode($cleaned, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($result)) {
            rawwire_log('lead_scorer', 'OpenClaw JSON parse failed for #' . $record['id'] . ': ' . substr($response, 0, 200), 'warning');
            return array();
        }

        // Validate adjustments — no clamp, AI has full authority
        $adjustments = array();
        $valid_classes = array('project_scale', 'type_fit', 'green_indicators', 'timeline', 'location', 'use_relevance', 'complexity');

        foreach ($result as $class => $adj) {
            if (in_array($class, $valid_classes, true) && is_numeric($adj)) {
                $adjustments[$class] = floatval($adj);
            }
        }

        if (! empty($adjustments)) {
            rawwire_log('lead_scorer', 'OpenClaw adjustments for #' . $record['id'] . ': ' . wp_json_encode($adjustments), 'info');
        }

        return $adjustments;
    }

    /**
     * Build human-readable reasoning for the score
     */
    private function build_reasoning($record, $scores, $composite)
    {
        $lines = array();

        // Top signals
        arsort($scores);
        $top = array_slice($scores, 0, 3, true);
        $bottom = array_slice($scores, -2, 2, true);

        $lines[] = sprintf('Composite: %.1f/10', $composite);
        $lines[] = '';

        // Strengths
        $lines[] = 'STRENGTHS:';
        foreach ($top as $class => $val) {
            $w = isset($this->weights[$class]) ? $this->weights[$class] : ($class === 'location' ? $this->weights['location_premium'] : 1);
            $lines[] = sprintf('  %s: %.1f (weight: %.1f)', ucwords(str_replace('_', ' ', $class)), $val, $w);
        }

        // Weaknesses
        $lines[] = 'WEAKNESSES:';
        foreach ($bottom as $class => $val) {
            $lines[] = sprintf('  %s: %.1f', ucwords(str_replace('_', ' ', $class)), $val);
        }

        // Key data points
        $lines[] = '';
        $lines[] = sprintf('Project: %s | %s | %s', $record['permit_type'], $record['permit_sub_type'], $record['use_desc']);
        $lines[] = sprintf('Value: $%s | Sqft: %s', number_format($record['valuation']), $record['square_footage'] ?: 'N/A');

        if (! empty($record['work_desc'])) {
            $lines[] = 'Desc: ' . substr($record['work_desc'], 0, 200);
        }

        return implode("\n", $lines);
    }

    /**
     * Re-score a batch with updated weights (does not call AI again)
     *
     * @param array $candidate_ids
     * @return int Number re-scored
     */
    public function rescore_candidates($candidate_ids = array())
    {
        global $wpdb;
        $cand_table = $this->generator->table('candidates');
        $src_table  = $this->generator->table('sources');

        $where = "c.status IN ('pending', 'scored_pending')";
        if (! empty($candidate_ids)) {
            $ids = implode(',', array_map('intval', $candidate_ids));
            $where .= " AND c.id IN ({$ids})";
        }

        $records = $wpdb->get_results(
            "SELECT c.*, s.height, s.stories, s.construction_type, s.business_unit, s.zone,
                    s.status_desc as source_status, s.submitted_date
             FROM {$cand_table} c
             LEFT JOIN {$src_table} s ON s.id = c.source_id
             WHERE {$where}",
            ARRAY_A
        );

        $rescored = 0;
        foreach ($records as $r) {
            // Merge source data for scoring functions
            $merged = array_merge($r, array(
                'height'           => isset($r['height']) ? $r['height'] : '',
                'stories'          => isset($r['stories']) ? $r['stories'] : '',
                'construction_type' => isset($r['construction_type']) ? $r['construction_type'] : '',
                'business_unit'    => isset($r['business_unit']) ? $r['business_unit'] : '',
                'zone'             => isset($r['zone']) ? $r['zone'] : '',
                'status_desc'      => isset($r['source_status']) ? $r['source_status'] : $r['status'],
            ));

            $scores = array(
                'project_scale'    => $this->score_project_scale($merged),
                'type_fit'         => $this->score_type_fit($merged),
                'green_indicators' => $this->score_green_indicators($merged),
                'timeline'         => $this->score_timeline($merged),
                'location'         => $this->score_location($merged),
                'use_relevance'    => $this->score_use_relevance($merged),
                'complexity'       => $this->score_complexity($merged),
            );

            $composite = $this->calculate_composite($scores);

            // Apply blacklist keyword penalty
            $blacklist_result = $this->apply_blacklist_penalty($merged, $composite);
            $composite = $blacklist_result['adjusted'];

            $wpdb->update($cand_table, array(
                'score_project_scale'    => round($scores['project_scale'], 1),
                'score_type_fit'         => round($scores['type_fit'], 1),
                'score_green_indicators' => round($scores['green_indicators'], 1),
                'score_timeline'         => round($scores['timeline'], 1),
                'score_location'         => round($scores['location'], 1),
                'score_use_relevance'    => round($scores['use_relevance'], 1),
                'score_complexity'       => round($scores['complexity'], 1),
                'score_composite'        => round($composite, 2),
                'scored_at'              => current_time('mysql'),
            ), array('id' => $r['id']));

            $rescored++;
        }

        return $rescored;
    }
}
