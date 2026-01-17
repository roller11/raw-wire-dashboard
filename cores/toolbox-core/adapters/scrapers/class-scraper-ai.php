<?php
/**
 * AI-Powered Semantic Scraper
 * 
 * Replaces keyword-based scraping with AI semantic analysis.
 * Analyzes content for abstract concepts like "shocking", "newsworthy",
 * "unusual" etc. even when those words don't appear in the text.
 *
 * @package RawWire\Dashboard\Cores\ToolboxCore\Scrapers
 * @since 1.0.21
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class RawWire_Scraper_AI
 * 
 * Semantic scraper using AI to evaluate content relevance
 * based on abstract concepts rather than keyword matching.
 */
class RawWire_Scraper_AI {

    /**
     * Singleton instance
     * @var RawWire_Scraper_AI|null
     */
    private static $instance = null;

    /**
     * AI Adapter reference
     * @var RawWire_AI_Adapter|null
     */
    private $ai = null;

    /**
     * Default abstract concepts to evaluate
     * @var array
     */
    const DEFAULT_CONCEPTS = [
        'shocking'       => 'Content that would surprise or alarm the general public',
        'controversial'  => 'Decisions or policies that could spark significant debate',
        'unusual'        => 'Procedures, exemptions, or actions outside normal operations',
        'high_impact'    => 'Regulations affecting large populations or major industries',
        'hidden_agenda'  => 'Actions that seem to benefit specific parties disproportionately',
        'urgent'         => 'Time-sensitive matters requiring immediate attention',
        'precedent'      => 'First-of-its-kind decisions that set new standards',
        'reversal'       => 'Significant changes to existing policies or regulations',
        'financial'      => 'Large monetary amounts, budget changes, or financial implications',
        'environmental'  => 'Impact on environment, public lands, or natural resources',
    ];

    /**
     * Scoring thresholds
     */
    const THRESHOLD_HIGH = 7;
    const THRESHOLD_MEDIUM = 5;
    const THRESHOLD_LOW = 3;

    /**
     * Get singleton instance
     * 
     * @return RawWire_Scraper_AI
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor
     */
    private function __construct() {
        add_action('init', [$this, 'init']);
        
        // Register with Tool Registry
        add_action('rawwire_register_tools', [$this, 'register_tool']);
        
        // AJAX handlers
        add_action('wp_ajax_rawwire_ai_scraper_run', [$this, 'ajax_run_scraper']);
        add_action('wp_ajax_rawwire_ai_scraper_analyze', [$this, 'ajax_analyze_content']);
    }

    /**
     * Initialize
     */
    public function init() {
        if (function_exists('rawwire_ai')) {
            $this->ai = rawwire_ai();
        }
    }

    /**
     * Register with Tool Registry
     * 
     * @param RawWire_Tool_Registry $registry
     */
    public function register_tool($registry) {
        if ($registry) {
            $registry->register('scraper_ai_semantic', [
                'name'        => 'AI Semantic Scraper',
                'description' => 'Scrape and analyze content using AI for abstract concepts',
                'category'    => 'scraper',
                'callback'    => [$this, 'execute'],
            ]);
        }
    }

    /**
     * Main execution method
     * 
     * @param array $params Scraper parameters
     * @return array Results
     */
    public function execute($params = []) {
        $source_type = $params['source_type'] ?? 'federal_register';
        $concepts = $params['concepts'] ?? array_keys(self::DEFAULT_CONCEPTS);
        $limit = $params['limit'] ?? 50;
        $threshold = $params['threshold'] ?? self::THRESHOLD_MEDIUM;
        $date_range = $params['date_range'] ?? 7; // days
        $output_table = $params['output_table'] ?? 'candidates'; // Default to candidates for workflow

        // Step 1: Fetch raw data from source
        $raw_data = $this->fetch_from_source($source_type, $limit, $date_range);
        
        if (is_wp_error($raw_data)) {
            return [
                'success' => false,
                'error'   => $raw_data->get_error_message(),
            ];
        }

        if (empty($raw_data)) {
            return [
                'success' => true,
                'message' => 'No new records found',
                'count'   => 0,
                'items'   => [],
            ];
        }

        // Step 2: AI semantic analysis
        $analyzed = $this->analyze_batch($raw_data, $concepts);

        // Step 3: Filter by threshold
        $filtered = array_filter($analyzed, function($item) use ($threshold) {
            return ($item['ai_score'] ?? 0) >= $threshold;
        });

        // Step 4: Sort by score descending
        usort($filtered, function($a, $b) {
            return ($b['ai_score'] ?? 0) - ($a['ai_score'] ?? 0);
        });

        // Step 5: Store results in specified workflow table
        $stored = $this->store_results($filtered, $output_table);

        // Log activity
        $this->log_activity('ai_scraper_run', [
            'source'       => $source_type,
            'raw_count'    => count($raw_data),
            'filtered'     => count($filtered),
            'stored'       => $stored,
            'threshold'    => $threshold,
            'output_table' => $output_table,
        ]);

        return [
            'success'      => true,
            'source'       => $source_type,
            'raw_count'    => count($raw_data),
            'analyzed'     => count($analyzed),
            'passed'       => count($filtered),
            'stored'       => $stored,
            'output_table' => $output_table,
            'top_items'    => array_slice($filtered, 0, 10),
        ];
    }

    /**
     * Fetch data from source
     * 
     * @param string $source_type Source type identifier
     * @param int    $limit       Max records to fetch
     * @param int    $days        Date range in days
     * @return array|WP_Error
     */
    public function fetch_from_source($source_type, $limit = 50, $days = 7) {
        switch ($source_type) {
            case 'federal_register':
                return $this->fetch_federal_register($limit, $days);
                
            case 'regulations_gov':
                return $this->fetch_regulations_gov($limit, $days);
                
            case 'congress_gov':
                return $this->fetch_congress_gov($limit, $days);
                
            case 'custom':
                return $this->fetch_custom_sources($limit, $days);
                
            default:
                return new WP_Error('invalid_source', 'Unknown source type: ' . $source_type);
        }
    }

    /**
     * Fetch from Federal Register API
     * 
     * @param int $limit Max records
     * @param int $days  Date range
     * @return array|WP_Error
     */
    private function fetch_federal_register($limit, $days) {
        $start_date = date('Y-m-d', strtotime("-{$days} days"));
        $end_date = date('Y-m-d');

        $url = add_query_arg([
            'per_page'       => min($limit, 100),
            'order'          => 'newest',
            'conditions[publication_date][gte]' => $start_date,
            'conditions[publication_date][lte]' => $end_date,
            'fields[]' => [
                'title',
                'abstract',
                'document_number',
                'type',
                'agencies',
                'publication_date',
                'html_url',
                'pdf_url',
            ],
        ], 'https://www.federalregister.gov/api/v1/documents.json');

        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data['results'])) {
            return [];
        }

        // Normalize to standard format
        $items = [];
        foreach ($data['results'] as $doc) {
            $items[] = [
                'id'          => $doc['document_number'] ?? '',
                'title'       => $doc['title'] ?? '',
                'abstract'    => $doc['abstract'] ?? '',
                'content'     => $doc['abstract'] ?? '', // Use abstract as content
                'type'        => $doc['type'] ?? '',
                'source'      => 'federal_register',
                'source_url'  => $doc['html_url'] ?? '',
                'pdf_url'     => $doc['pdf_url'] ?? '',
                'date'        => $doc['publication_date'] ?? '',
                'agencies'    => array_column($doc['agencies'] ?? [], 'name'),
                'raw_data'    => $doc,
            ];
        }

        return $items;
    }

    /**
     * Fetch from Regulations.gov API
     * 
     * @param int $limit Max records
     * @param int $days  Date range
     * @return array|WP_Error
     */
    private function fetch_regulations_gov($limit, $days) {
        // Use centralized key manager
        $api_key = function_exists('rawwire_keys') 
            ? rawwire_keys()->get_key('regulations_gov') 
            : get_option('rawwire_regulations_gov_key', '');
        
        if (empty($api_key)) {
            return new WP_Error('no_api_key', 'Regulations.gov API key not configured. Go to AI Scraper settings to add your key.');
        }

        $start_date = date('Y-m-d', strtotime("-{$days} days"));

        $url = add_query_arg([
            'api_key'           => $api_key,
            'filter[postedDate][ge]' => $start_date,
            'page[size]'        => min($limit, 250),
            'sort'              => '-postedDate',
        ], 'https://api.regulations.gov/v4/documents');

        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data['data'])) {
            return [];
        }

        $items = [];
        foreach ($data['data'] as $doc) {
            $attrs = $doc['attributes'] ?? [];
            $items[] = [
                'id'          => $doc['id'] ?? '',
                'title'       => $attrs['title'] ?? '',
                'abstract'    => $attrs['summary'] ?? '',
                'content'     => $attrs['summary'] ?? '',
                'type'        => $attrs['documentType'] ?? '',
                'source'      => 'regulations_gov',
                'source_url'  => "https://www.regulations.gov/document/{$doc['id']}",
                'date'        => $attrs['postedDate'] ?? '',
                'agencies'    => [$attrs['agencyId'] ?? ''],
                'raw_data'    => $doc,
            ];
        }

        return $items;
    }

    /**
     * Fetch from Congress.gov
     * 
     * @param int $limit Max records
     * @param int $days  Date range
     * @return array|WP_Error
     */
    private function fetch_congress_gov($limit, $days) {
        // Use centralized key manager
        $api_key = function_exists('rawwire_keys') 
            ? rawwire_keys()->get_key('congress_gov') 
            : get_option('rawwire_congress_gov_key', '');
        
        if (empty($api_key)) {
            return new WP_Error('no_api_key', 'Congress.gov API key not configured. Go to AI Scraper settings to add your key.');
        }

        $url = add_query_arg([
            'api_key' => $api_key,
            'limit'   => min($limit, 250),
            'sort'    => 'updateDate desc',
        ], 'https://api.congress.gov/v3/bill');

        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data['bills'])) {
            return [];
        }

        $items = [];
        foreach ($data['bills'] as $bill) {
            $items[] = [
                'id'          => $bill['number'] ?? '',
                'title'       => $bill['title'] ?? '',
                'abstract'    => $bill['latestAction']['text'] ?? '',
                'content'     => ($bill['title'] ?? '') . '. ' . ($bill['latestAction']['text'] ?? ''),
                'type'        => $bill['type'] ?? '',
                'source'      => 'congress_gov',
                'source_url'  => $bill['url'] ?? '',
                'date'        => $bill['updateDate'] ?? '',
                'agencies'    => [],
                'raw_data'    => $bill,
            ];
        }

        return $items;
    }

    /**
     * Fetch from custom configured sources
     * 
     * @param int $limit Max records
     * @param int $days  Date range
     * @return array
     */
    private function fetch_custom_sources($limit, $days) {
        // Use central getter for fresh data from database
        $sources = class_exists('RawWire_Scraper_Settings') 
            ? RawWire_Scraper_Settings::get_sources() 
            : [];
        $all_items = [];

        foreach ($sources as $source) {
            // Check for enabled status (new field) or legacy status field
            $is_enabled = isset($source['enabled']) ? !empty($source['enabled']) : (($source['status'] ?? 'active') === 'active');
            if (!$is_enabled) {
                continue;
            }

            $items = $this->fetch_single_source($source, $limit, $days);
            if (!is_wp_error($items)) {
                $all_items = array_merge($all_items, $items);
            }
        }

        return array_slice($all_items, 0, $limit);
    }

    /**
     * Fetch from a single custom source
     * 
     * @param array $source Source configuration
     * @param int   $limit  Max records
     * @param int   $days   Date range
     * @return array|WP_Error
     */
    private function fetch_single_source($source, $limit, $days) {
        $url = $source['url'] ?? '';
        $type = $source['type'] ?? 'api';

        if (empty($url)) {
            return new WP_Error('no_url', 'Source URL not configured');
        }

        $headers = [];
        
        // Add authentication
        switch ($source['auth_type'] ?? 'none') {
            case 'api_key':
                $headers['X-API-Key'] = $source['auth_credentials'] ?? '';
                break;
            case 'bearer':
                $headers['Authorization'] = 'Bearer ' . ($source['auth_credentials'] ?? '');
                break;
            case 'basic':
                $headers['Authorization'] = 'Basic ' . base64_encode($source['auth_credentials'] ?? '');
                break;
        }

        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => array_merge(['Accept' => 'application/json'], $headers),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        
        // Parse based on type
        switch ($type) {
            case 'rss':
                return $this->parse_rss($body, $source);
            case 'json':
            case 'api':
                return $this->parse_json($body, $source);
            default:
                return [];
        }
    }

    /**
     * Parse RSS feed
     * 
     * @param string $body   Response body
     * @param array  $source Source config
     * @return array
     */
    private function parse_rss($body, $source) {
        $items = [];
        
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        
        if ($xml === false) {
            return [];
        }

        $channel = $xml->channel ?? $xml;
        $rss_items = $channel->item ?? [];

        foreach ($rss_items as $item) {
            $items[] = [
                'id'          => md5((string)($item->link ?? $item->guid ?? '')),
                'title'       => (string)($item->title ?? ''),
                'abstract'    => strip_tags((string)($item->description ?? '')),
                'content'     => strip_tags((string)($item->description ?? '')),
                'type'        => 'rss_item',
                'source'      => $source['name'] ?? 'custom',
                'source_url'  => (string)($item->link ?? ''),
                'date'        => (string)($item->pubDate ?? ''),
                'agencies'    => [],
                'raw_data'    => json_decode(json_encode($item), true),
            ];
        }

        return $items;
    }

    /**
     * Parse JSON response
     * 
     * @param string $body   Response body
     * @param array  $source Source config
     * @return array
     */
    private function parse_json($body, $source) {
        $data = json_decode($body, true);
        
        if (!is_array($data)) {
            return [];
        }

        // Try to find the items array
        $items_data = $data;
        
        // Common patterns for API responses
        foreach (['results', 'data', 'items', 'documents', 'records'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $items_data = $data[$key];
                break;
            }
        }

        if (!is_array($items_data) || empty($items_data)) {
            return [];
        }

        // Map columns if specified
        $column_map = [];
        if (!empty($source['columns'])) {
            $cols = array_map('trim', explode(',', $source['columns']));
            foreach ($cols as $col) {
                if (strpos($col, ':') !== false) {
                    list($from, $to) = explode(':', $col, 2);
                    $column_map[trim($from)] = trim($to);
                }
            }
        }

        $items = [];
        foreach ($items_data as $record) {
            if (!is_array($record)) {
                continue;
            }

            // Apply column mapping
            if (!empty($column_map)) {
                $mapped = [];
                foreach ($column_map as $from => $to) {
                    $mapped[$to] = $record[$from] ?? '';
                }
                $record = array_merge($record, $mapped);
            }

            $items[] = [
                'id'          => $record['id'] ?? $record['document_number'] ?? md5(json_encode($record)),
                'title'       => $record['title'] ?? $record['name'] ?? '',
                'abstract'    => $record['abstract'] ?? $record['summary'] ?? $record['description'] ?? '',
                'content'     => $record['content'] ?? $record['abstract'] ?? $record['summary'] ?? '',
                'type'        => $record['type'] ?? 'document',
                'source'      => $source['name'] ?? 'custom',
                'source_url'  => $record['url'] ?? $record['link'] ?? '',
                'date'        => $record['date'] ?? $record['published'] ?? '',
                'agencies'    => (array)($record['agencies'] ?? []),
                'raw_data'    => $record,
            ];
        }

        return $items;
    }

    /**
     * Analyze batch of items using AI
     * 
     * @param array $items    Items to analyze
     * @param array $concepts Concepts to evaluate
     * @return array
     */
    public function analyze_batch($items, $concepts = null) {
        if (!$this->ai || !$this->ai->is_available()) {
            // Fallback to basic scoring if AI not available
            return $this->basic_score_batch($items);
        }

        if ($concepts === null) {
            $concepts = array_keys(self::DEFAULT_CONCEPTS);
        }

        $analyzed = [];
        $batch_size = get_option('rawwire_ai_batch_size', 5);

        // Process in batches to avoid timeouts
        $batches = array_chunk($items, $batch_size);

        foreach ($batches as $batch) {
            $batch_results = $this->analyze_batch_chunk($batch, $concepts);
            $analyzed = array_merge($analyzed, $batch_results);
        }

        return $analyzed;
    }

    /**
     * Analyze a chunk of items
     * 
     * @param array $items    Items to analyze
     * @param array $concepts Concepts to evaluate
     * @return array
     */
    private function analyze_batch_chunk($items, $concepts) {
        $results = [];

        // Build concept descriptions
        $concept_descriptions = [];
        foreach ($concepts as $concept) {
            $concept_descriptions[$concept] = self::DEFAULT_CONCEPTS[$concept] ?? $concept;
        }

        foreach ($items as $item) {
            $analysis = $this->analyze_single_item($item, $concept_descriptions);
            $results[] = array_merge($item, $analysis);
        }

        return $results;
    }

    /**
     * Analyze a single item with AI
     * 
     * @param array $item                Item to analyze
     * @param array $concept_descriptions Concept definitions
     * @return array
     */
    private function analyze_single_item($item, $concept_descriptions) {
        $title = $item['title'] ?? '';
        $content = $item['abstract'] ?? $item['content'] ?? '';
        $agencies = implode(', ', $item['agencies'] ?? []);

        // Build the analysis prompt
        $concepts_text = "";
        foreach ($concept_descriptions as $concept => $description) {
            $concepts_text .= "- **{$concept}**: {$description}\n";
        }

        $prompt = <<<PROMPT
Analyze this government document for newsworthiness. Score each concept from 0-10 based on how strongly the content exhibits that quality, even if the exact words don't appear.

## Document
**Title:** {$title}
**Agency:** {$agencies}
**Content:** {$content}

## Concepts to Evaluate
{$concepts_text}

## Response Format
Return ONLY valid JSON with this exact structure:
{
    "scores": {
        "concept_name": {"score": 0-10, "reason": "brief explanation"}
    },
    "overall_score": 0-10,
    "headline_potential": "One sentence describing why this might be newsworthy",
    "key_concerns": ["concern1", "concern2"],
    "affected_parties": ["party1", "party2"],
    "recommendation": "include|review|skip"
}
PROMPT;

        $result = $this->ai->json_query($prompt, [
            'temperature' => 0.3,
            'max_tokens'  => 1000,
        ]);

        if (is_wp_error($result) || !is_array($result)) {
            // Return basic analysis on failure
            return [
                'ai_score'          => 5,
                'ai_analysis'       => null,
                'ai_error'          => is_wp_error($result) ? $result->get_error_message() : 'Parse error',
                'recommendation'    => 'review',
            ];
        }

        // Calculate overall score from individual concept scores
        $total_score = $result['overall_score'] ?? 0;
        
        if (isset($result['scores']) && is_array($result['scores'])) {
            $concept_scores = array_column($result['scores'], 'score');
            if (!empty($concept_scores)) {
                $avg_score = array_sum($concept_scores) / count($concept_scores);
                // Weight overall score 60% AI-provided, 40% average
                $total_score = ($total_score * 0.6) + ($avg_score * 0.4);
            }
        }

        return [
            'ai_score'          => round($total_score, 1),
            'ai_analysis'       => $result,
            'ai_headline'       => $result['headline_potential'] ?? '',
            'ai_concerns'       => $result['key_concerns'] ?? [],
            'ai_affected'       => $result['affected_parties'] ?? [],
            'recommendation'    => $result['recommendation'] ?? 'review',
            'concept_scores'    => $result['scores'] ?? [],
        ];
    }

    /**
     * Basic scoring fallback when AI is unavailable
     * 
     * @param array $items Items to score
     * @return array
     */
    private function basic_score_batch($items) {
        // Keywords that often indicate noteworthy content
        $high_impact_keywords = [
            'unprecedented', 'emergency', 'billion', 'million', 'suspend',
            'terminate', 'override', 'exempt', 'waive', 'immediate',
            'classified', 'secret', 'restricted', 'violation', 'penalty',
        ];

        $medium_impact_keywords = [
            'amendment', 'revision', 'new rule', 'proposed', 'final rule',
            'enforcement', 'investigation', 'settlement', 'fine', 'sanction',
        ];

        foreach ($items as &$item) {
            $text = strtolower($item['title'] . ' ' . ($item['abstract'] ?? ''));
            $score = 3; // Base score

            foreach ($high_impact_keywords as $keyword) {
                if (strpos($text, $keyword) !== false) {
                    $score += 2;
                }
            }

            foreach ($medium_impact_keywords as $keyword) {
                if (strpos($text, $keyword) !== false) {
                    $score += 1;
                }
            }

            $item['ai_score'] = min($score, 10);
            $item['ai_analysis'] = null;
            $item['recommendation'] = $score >= 7 ? 'include' : ($score >= 5 ? 'review' : 'skip');
        }

        return $items;
    }

    /**
     * Store results in database
     * 
     * @param array  $items        Items to store
     * @param string $output_table Target workflow table (candidates, approvals, content, etc.)
     * @return int Number stored
     */
    private function store_results($items, $output_table = 'candidates') {
        global $wpdb;
        
        // Valid workflow tables
        $valid_tables = ['candidates', 'approvals', 'content', 'releases', 'published', 'archives'];
        if (!in_array($output_table, $valid_tables)) {
            $output_table = 'candidates';
        }
        
        $table = $wpdb->prefix . 'rawwire_' . $output_table;
        $stored = 0;

        foreach ($items as $item) {
            // Check if already exists
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE external_id = %s AND source = %s",
                $item['id'],
                $item['source']
            ));

            if ($exists) {
                // Update score
                $wpdb->update(
                    $table,
                    [
                        'relevance_score' => $item['ai_score'] ?? 0,
                        'ai_analysis'     => json_encode($item['ai_analysis'] ?? null),
                        'updated_at'      => current_time('mysql'),
                    ],
                    ['id' => $exists]
                );
            } else {
                // Build insert data - schema varies by table
                $insert_data = [
                    'external_id'     => $item['id'],
                    'title'           => $item['title'],
                    'source'          => $item['source'],
                    'source_url'      => $item['source_url'] ?? '',
                    'created_at'      => current_time('mysql'),
                    'updated_at'      => current_time('mysql'),
                ];
                
                // Common fields for most tables
                if ($output_table === 'candidates') {
                    // Candidates table - minimal for workflow entry
                    $insert_data['content'] = $item['abstract'] ?? $item['content'] ?? '';
                    $insert_data['link'] = $item['source_url'] ?? '';
                    $insert_data['copyright_status'] = 'public_domain';
                    $insert_data['score'] = $item['ai_score'] ?? 0;
                } else {
                    // Other tables use expanded schema
                    $insert_data['summary'] = $item['abstract'] ?? '';
                    $insert_data['content'] = $item['content'] ?? '';
                    $insert_data['category'] = $item['type'] ?? 'document';
                    $insert_data['relevance_score'] = $item['ai_score'] ?? 0;
                    $insert_data['ai_analysis'] = json_encode($item['ai_analysis'] ?? null);
                    $insert_data['status'] = ($item['recommendation'] ?? '') === 'include' ? 'pending' : 'new';
                }

                $result = $wpdb->insert($table, $insert_data);

                if ($result) {
                    $stored++;
                    
                    // Fire action for workflow integration when adding to candidates
                    if ($output_table === 'candidates') {
                        do_action('rawwire_candidate_added', $wpdb->insert_id, $insert_data);
                    }
                }
            }
        }

        return $stored;
    }

    /**
     * Log activity
     * 
     * @param string $action  Action performed
     * @param array  $details Details to log
     */
    private function log_activity($action, $details = []) {
        // Update daily stats
        $stats = get_option('rawwire_dashboard_stats', []);
        $today = date('Y-m-d');
        
        if (!isset($stats[$today])) {
            $stats[$today] = [];
        }
        
        $stats[$today]['scraper_runs'] = ($stats[$today]['scraper_runs'] ?? 0) + 1;
        $stats[$today]['ai_queries'] = ($stats[$today]['ai_queries'] ?? 0) + ($details['filtered'] ?? 0);
        
        update_option('rawwire_dashboard_stats', $stats);

        // Log to error log in debug mode
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("RawWire AI Scraper [{$action}]: " . json_encode($details));
        }
    }

    /**
     * AJAX: Run AI scraper
     */
    public function ajax_run_scraper() {
        check_ajax_referer('rawwire_ai_scraper', '_wpnonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $source_type = sanitize_text_field($_POST['source_type'] ?? 'federal_register');
        $threshold = intval($_POST['threshold'] ?? self::THRESHOLD_MEDIUM);
        $limit = intval($_POST['limit'] ?? 50);
        $days = intval($_POST['days'] ?? 7);
        $output_table = sanitize_key($_POST['output_table'] ?? 'candidates');

        $result = $this->execute([
            'source_type'  => $source_type,
            'threshold'    => $threshold,
            'limit'        => $limit,
            'date_range'   => $days,
            'output_table' => $output_table,
        ]);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX: Analyze single content
     */
    public function ajax_analyze_content() {
        check_ajax_referer('rawwire_ai_analyze', '_wpnonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $title = sanitize_text_field($_POST['title'] ?? '');
        $content = sanitize_textarea_field($_POST['content'] ?? '');

        if (empty($content)) {
            wp_send_json_error(['message' => 'Content is required']);
        }

        $item = [
            'id'       => md5($content),
            'title'    => $title,
            'abstract' => $content,
            'content'  => $content,
            'agencies' => [],
        ];

        $concept_descriptions = [];
        foreach (self::DEFAULT_CONCEPTS as $concept => $description) {
            $concept_descriptions[$concept] = $description;
        }

        $analysis = $this->analyze_single_item($item, $concept_descriptions);

        wp_send_json_success($analysis);
    }

    /**
     * Get available sources
     * 
     * @return array
     */
    public function get_available_sources() {
        return [
            'federal_register' => [
                'name'        => 'Federal Register',
                'description' => 'U.S. Federal Register - rules, regulations, executive orders',
                'requires_key'=> false,
            ],
            'regulations_gov' => [
                'name'        => 'Regulations.gov',
                'description' => 'U.S. regulatory docket system',
                'requires_key'=> true,
                'key_option'  => 'rawwire_regulations_gov_key',
            ],
            'congress_gov' => [
                'name'        => 'Congress.gov',
                'description' => 'U.S. Congressional bills and legislation',
                'requires_key'=> true,
                'key_option'  => 'rawwire_congress_gov_key',
            ],
            'custom' => [
                'name'        => 'Custom Sources',
                'description' => 'Your configured scraper sources',
                'requires_key'=> false,
            ],
        ];
    }

    /**
     * Get scoring concepts
     * 
     * @return array
     */
    public function get_concepts() {
        return self::DEFAULT_CONCEPTS;
    }
}

// Initialize
RawWire_Scraper_AI::get_instance();

/**
 * Helper function to get AI Scraper instance
 * 
 * @return RawWire_Scraper_AI
 */
function rawwire_ai_scraper() {
    return RawWire_Scraper_AI::get_instance();
}
