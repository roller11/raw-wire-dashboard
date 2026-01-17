<?php
/**
 * Workflow Orchestrator Service
 * 
 * Manages modular workflow execution with pluggable adapters.
 * Supports dynamic endpoint switching (scrapers, scorers, generators).
 * 
 * Architecture:
 * - Workflows are defined as sequences of steps
 * - Each step uses an adapter (scraper, scorer, generator, poster)
 * - Adapters are interchangeable at runtime via configuration
 * - Results flow from one step to the next via the context
 * 
 * @package RawWire_Dashboard
 * @subpackage Services
 */

namespace RawWire\Dashboard\Services;

if (!defined('ABSPATH')) {
    exit;
}

class Workflow_Orchestrator {
    
    /**
     * Available scraper adapters
     */
    const SCRAPERS = array(
        'github' => array(
            'class' => 'RawWire_Adapter_Scraper_GitHub',
            'file'  => 'cores/toolbox-core/adapters/scrapers/class-scraper-github.php',
            'label' => 'GitHub API',
            'tier'  => 'free',
        ),
        'native' => array(
            'class' => 'RawWire_Adapter_Scraper_Native',
            'file'  => 'cores/toolbox-core/adapters/scrapers/class-scraper-native.php',
            'label' => 'Native DOM',
            'tier'  => 'free',
        ),
        'brightdata' => array(
            'class' => 'RawWire_Adapter_Scraper_Brightdata',
            'file'  => 'cores/toolbox-core/adapters/scrapers/class-scraper-brightdata.php',
            'label' => 'Bright Data',
            'tier'  => 'value',
        ),
        'api' => array(
            'class' => 'RawWire_Adapter_Scraper_API',
            'file'  => 'cores/toolbox-core/adapters/scrapers/class-scraper-api.php',
            'label' => 'REST API',
            'tier'  => 'free',
        ),
    );
    
    /**
     * Available scorer adapters (for future use)
     */
    const SCORERS = array(
        'ai_relevance' => array(
            'class' => 'RawWire_Scorer_AI_Relevance',
            'file'  => 'cores/toolbox-core/adapters/scorers/class-scorer-ai-relevance.php',
            'label' => 'AI Relevance',
            'tier'  => 'value',
        ),
        'keyword' => array(
            'class' => 'RawWire_Scorer_Keyword',
            'file'  => 'cores/toolbox-core/adapters/scorers/class-scorer-keyword.php',
            'label' => 'Keyword Match',
            'tier'  => 'free',
        ),
    );
    
    /**
     * Workflow execution state
     * @var array
     */
    private static $executions = array();
    
    /**
     * Plugin base path
     * @var string
     */
    private $base_path;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->base_path = plugin_dir_path(dirname(__FILE__));
    }
    
    /**
     * Get available scrapers for control panel
     * @return array
     */
    public static function get_available_scrapers() {
        return self::SCRAPERS;
    }
    
    /**
     * Get available scorers for control panel
     * @return array
     */
    public static function get_available_scorers() {
        return self::SCORERS;
    }
    
    /**
     * Start a workflow with given configuration
     * 
     * @param array $config {
     *     @type string $scraper      Scraper adapter key (github, native, etc.)
     *     @type string $scorer       Scorer adapter key (optional)
     *     @type int    $max_records  Maximum records to collect
     *     @type string $target_table Target table for results (candidates, content, etc.)
     *     @type array  $sources      Array of source URLs/configs
     *     @type array  $filters      Content filters to apply
     *     @type bool   $async        Run asynchronously via WP Cron
     * }
     * @return array Execution result
     */
    public function start(array $config) {
        $execution_id = wp_generate_uuid4();
        $start_time = microtime(true);
        
        // Validate config
        $validation = $this->validate_config($config);
        if (!$validation['valid']) {
            return array(
                'success' => false,
                'error' => $validation['error'],
            );
        }
        
        // Initialize execution state
        self::$executions[$execution_id] = array(
            'id'           => $execution_id,
            'status'       => 'running',
            'stage'        => 'initializing',
            'started_at'   => current_time('mysql'),
            'config'       => $config,
            'progress'     => 0,
            'total_steps'  => 0,
            'current_step' => 0,
            'results'      => array(),
            'errors'       => array(),
        );
        
        // Store in transient for persistence across requests
        set_transient('rawwire_workflow_' . $execution_id, self::$executions[$execution_id], HOUR_IN_SECONDS);
        
        // Check if async execution requested
        if (!empty($config['async'])) {
            return $this->schedule_async($execution_id, $config);
        }
        
        // Synchronous execution
        return $this->execute($execution_id, $config);
    }
    
    /**
     * Execute workflow synchronously
     */
    private function execute($execution_id, array $config) {
        global $wpdb;
        
        $scraper_key = $config['scraper'] ?? 'github';
        $scorer_key = $config['scorer'] ?? null;
        $max_records_per_source = intval($config['max_records'] ?? 10);
        $target_table = $config['target_table'] ?? 'candidates';
        $sources = $config['sources'] ?? array();
        $top_per_source = intval($config['top_per_source'] ?? get_option('rawwire_top_per_source', 2));
        
        error_log('[RawWire Workflow] Starting execution: ' . $execution_id);
        error_log('[RawWire Workflow] Config: scraper=' . $scraper_key . ', scorer=' . $scorer_key . ', max_per_source=' . $max_records_per_source . ', target=' . $target_table . ', top_per_source=' . $top_per_source);
        error_log('[RawWire Workflow] Sources count: ' . count($sources));
        
        $this->update_execution($execution_id, array(
            'stage' => 'loading_adapter',
            'progress' => 5,
        ));
        
        // Load scraper adapter
        $scraper = $this->load_scraper($scraper_key, $config);
        if (!$scraper) {
            return $this->fail_execution($execution_id, 'Failed to load scraper: ' . $scraper_key);
        }
        
        $this->update_execution($execution_id, array(
            'stage' => 'scraping',
            'progress' => 10,
        ));
        
        // Execute scraping - collect max_records PER SOURCE, not total
        $all_items = array();
        $total_sources = count($sources);
        
        foreach ($sources as $index => $source) {
            $progress = 10 + (($index / max($total_sources, 1)) * 50);
            $source_name = $source['label'] ?? $source['url'] ?? 'Source ' . ($index + 1);
            
            $this->update_execution($execution_id, array(
                'stage' => 'scraping',
                'progress' => $progress,
                'message' => 'Scraping ' . $source_name . ' (' . ($index + 1) . ' of ' . $total_sources . ')',
            ));
            
            error_log('[RawWire Workflow] Scraping source: ' . $source_name);
            
            try {
                // Build URL with params if provided
                $url = $source['url'] ?? $source;
                $params = $source['params'] ?? array();
                $headers = $source['headers'] ?? array();
                
                // For GitHub search API, add per_page parameter if not set
                if (strpos($url, '/search/') !== false && !isset($params['per_page'])) {
                    $params['per_page'] = min($max_records_per_source, 100);
                }
                
                $result = $scraper->scrape($url, array(
                    'max_results' => $max_records_per_source,
                    'params' => $params,
                    'headers' => $headers,
                ));
                
                if ($result['success'] && !empty($result['data'])) {
                    $data = $result['data'];
                    
                    // Handle different API response formats
                    // GitHub search API uses 'items', Federal Register uses 'results', Congress.gov uses 'bills'
                    if (isset($data['items']) && is_array($data['items'])) {
                        $items = $data['items'];
                    } elseif (isset($data['results']) && is_array($data['results'])) {
                        $items = $data['results'];
                    } elseif (isset($data['bills']) && is_array($data['bills'])) {
                        $items = $data['bills'];
                    } elseif (isset($data['data']) && is_array($data['data'])) {
                        // Regulations.gov uses 'data'
                        $items = $data['data'];
                    } else {
                        $items = is_array($data) ? $data : array($data);
                    }
                    
                    // If data is a single repo, wrap it
                    if (isset($data['full_name']) && !isset($data['items'])) {
                        $items = array($data);
                    }
                    
                    // Limit items from this source to max_records_per_source
                    $items = array_slice($items, 0, $max_records_per_source);
                    
                    $source_label = $source['label'] ?? $source['name'] ?? $source['url'] ?? 'Unknown';
                    error_log('[RawWire Workflow] Source "' . $source_label . '" returned ' . count($items) . ' items');
                    
                    foreach ($items as $item) {
                        $item['source'] = $source['label'] ?? $source['name'] ?? $source['url'] ?? $source;
                        $all_items[] = $item;
                    }
                } else {
                    $error = $result['error'] ?? 'Unknown error';
                    error_log('[RawWire Workflow] Source failed: ' . $error);
                }
            } catch (\Exception $e) {
                $this->log_error($execution_id, 'Scraper error: ' . $e->getMessage());
                error_log('[RawWire Workflow] Exception: ' . $e->getMessage());
            }
        }
        
        error_log('[RawWire Workflow] Total items collected: ' . count($all_items));
        
        // Score items if scorer specified
        $scored_items = $all_items;
        $score_results = array();
        
        if ($scorer_key && !empty($all_items)) {
            error_log('[RawWire Workflow] Running scorer: ' . $scorer_key);
            $this->update_execution($execution_id, array(
                'stage' => 'scoring',
                'progress' => 65,
                'message' => 'Scoring ' . count($all_items) . ' items with ' . $scorer_key,
            ));
            
            $scorer = $this->load_scorer($scorer_key, $config);
            if ($scorer) {
                error_log('[RawWire Workflow] Scorer loaded successfully');
                try {
                    $scored_items = $scorer->score_batch($all_items);
                    $score_results = array(
                        'scorer' => $scorer_key,
                        'items_scored' => count($scored_items),
                        'avg_score' => $this->calculate_avg_score($scored_items),
                    );
                    error_log('[RawWire Workflow] Scoring complete. Items scored: ' . count($scored_items) . ', avg score: ' . $score_results['avg_score']);
                } catch (\Exception $e) {
                    $this->log_error($execution_id, 'Scorer error: ' . $e->getMessage());
                    error_log('[RawWire Workflow] Scorer exception: ' . $e->getMessage());
                }
            } else {
                $this->log_error($execution_id, 'Failed to load scorer: ' . $scorer_key);
                error_log('[RawWire Workflow] Failed to load scorer: ' . $scorer_key);
            }
        } else {
            error_log('[RawWire Workflow] Skipping scoring - scorer_key: ' . ($scorer_key ?: 'empty') . ', items: ' . count($all_items));
        }
        
        $this->update_execution($execution_id, array(
            'stage' => 'storing',
            'progress' => 80,
            'message' => 'Storing ' . count($scored_items) . ' items',
        ));
        
        // Store results in target table
        $stored = $this->store_items($scored_items, $target_table);
        error_log('[RawWire Workflow] Items stored to ' . $target_table . ': ' . $stored . ' of ' . count($scored_items));
        
        // Process pipeline: top N per source to approvals, rest to archives
        $pipeline_results = array('approved' => 0, 'archived' => 0);
        if ($scorer_key && $target_table === 'candidates' && $top_per_source > 0) {
            // Verify candidates count matches what we just stored
            $candidates_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rawwire_candidates");
            error_log('[RawWire Workflow] Candidates in DB before pipeline: ' . $candidates_count . ' (stored: ' . $stored . ')');
            
            $this->update_execution($execution_id, array(
                'stage' => 'processing_pipeline',
                'progress' => 90,
                'message' => 'Processing pipeline: top ' . $top_per_source . ' per source to approvals, rest to archives',
            ));
            
            $pipeline_results = $this->auto_approve_candidates($scored_items, $top_per_source);
            
            // Verify candidates are now empty
            $remaining = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rawwire_candidates");
            error_log('[RawWire Workflow] Candidates remaining after pipeline: ' . $remaining);
        }
        
        $this->update_execution($execution_id, array(
            'stage' => 'complete',
            'status' => 'completed',
            'progress' => 100,
            'results' => array(
                'items_scraped' => count($all_items),
                'items_scored' => !empty($score_results) ? $score_results['items_scored'] : 0,
                'avg_score' => !empty($score_results) ? $score_results['avg_score'] : null,
                'items_stored' => $stored,
                'items_approved' => $pipeline_results['approved'],
                'items_archived' => $pipeline_results['archived'],
                'target_table' => $wpdb->prefix . 'rawwire_' . $target_table,
            ),
        ));
        
        return array(
            'success' => true,
            'execution_id' => $execution_id,
            'items_scraped' => count($all_items),
            'items_scored' => !empty($score_results) ? $score_results['items_scored'] : 0,
            'avg_score' => !empty($score_results) ? $score_results['avg_score'] : null,
            'items_stored' => $stored,
            'items_approved' => $pipeline_results['approved'],
            'items_archived' => $pipeline_results['archived'],
            'target_table' => $target_table,
        );
    }
    
    /**
     * Load a scraper adapter
     */
    private function load_scraper($key, array $config) {
        if (!isset(self::SCRAPERS[$key])) {
            return null;
        }
        
        $scraper_info = self::SCRAPERS[$key];
        $file_path = $this->base_path . $scraper_info['file'];
        
        if (!file_exists($file_path)) {
            return null;
        }
        
        require_once $file_path;
        
        $class = $scraper_info['class'];
        if (!class_exists($class)) {
            return null;
        }
        
        // Pass adapter-specific config
        $adapter_config = $config['adapter_config'] ?? array();
        
        return new $class($adapter_config);
    }
    
    /**
     * Load a scorer adapter
     * 
     * @param string $key    Scorer key (ai_relevance, keyword)
     * @param array  $config Workflow config
     * @return object|null Scorer instance
     */
    private function load_scorer($key, array $config) {
        if (!isset(self::SCORERS[$key])) {
            return null;
        }
        
        $scorer_info = self::SCORERS[$key];
        $file_path = $this->base_path . $scorer_info['file'];
        
        if (!file_exists($file_path)) {
            error_log('[RawWire] Scorer file not found: ' . $file_path);
            return null;
        }
        
        require_once $file_path;
        
        $class = $scorer_info['class'];
        if (!class_exists($class)) {
            error_log('[RawWire] Scorer class not found: ' . $class);
            return null;
        }
        
        // Pass scorer-specific config
        $scorer_config = $config['scorer_config'] ?? array();
        
        return new $class($scorer_config);
    }
    
    /**
     * Calculate average score from scored items
     * 
     * @param array $items Scored items with 'score' field
     * @return float Average score
     */
    private function calculate_avg_score(array $items) {
        if (empty($items)) {
            return 0;
        }
        
        $total = 0;
        $count = 0;
        
        foreach ($items as $item) {
            if (isset($item['score'])) {
                $total += $item['score'];
                $count++;
            }
        }
        
        return $count > 0 ? round($total / $count, 1) : 0;
    }
    
    /**
     * Process candidates: top N per source to approvals, rest to archives
     * Works directly with database records to ensure consistency.
     * 
     * @param array $scored_items     Items with scores (for reference)
     * @param int   $top_per_source   Number of top items per source to approve (default 2)
     * @return array {approved: int, archived: int}
     */
    private function auto_approve_candidates(array $scored_items, $top_per_source = 2) {
        global $wpdb;
        
        $candidates_table = $wpdb->prefix . 'rawwire_candidates';
        $approvals_table = $wpdb->prefix . 'rawwire_approvals';
        $archives_table = $wpdb->prefix . 'rawwire_archives';
        $approved = 0;
        $archived = 0;
        
        // Get all candidates from database, grouped by source
        // This ensures we only process records that were actually stored
        $candidates = $wpdb->get_results(
            "SELECT * FROM {$candidates_table} ORDER BY source ASC, score DESC, id DESC",
            ARRAY_A
        );
        
        if (empty($candidates)) {
            error_log('[RawWire Workflow] No candidates found in database');
            return array('approved' => 0, 'archived' => 0);
        }
        
        // Group by source
        $by_source = array();
        foreach ($candidates as $candidate) {
            $source = $candidate['source'] ?? 'unknown';
            if (!isset($by_source[$source])) {
                $by_source[$source] = array();
            }
            $by_source[$source][] = $candidate;
        }
        
        error_log('[RawWire Workflow] Processing candidates: ' . count($candidates) . ' items from ' . count($by_source) . ' sources');
        error_log('[RawWire Workflow] Top per source: ' . $top_per_source);
        
        // Process each source
        foreach ($by_source as $source => $items) {
            error_log('[RawWire Workflow] Source "' . $source . '": ' . count($items) . ' items');
            
            foreach ($items as $index => $candidate) {
                $title = $candidate['title'] ?? '';
                $score = $candidate['score'] ?? 0;
                $reasoning = $candidate['reasoning'] ?? '';
                
                if ($index < $top_per_source) {
                    // Top N items go to approvals
                    $approval_data = array(
                        'title'            => $candidate['title'],
                        'content'          => $candidate['content'],
                        'link'             => $candidate['link'],
                        'source'           => $candidate['source'],
                        'score'            => $score,
                        'ai_reason'        => $reasoning ?: 'Top ' . ($index + 1) . ' for source (score: ' . $score . ')',
                        'copyright_status' => $candidate['copyright_status'],
                        'status'           => 'pending',
                        'created_at'       => current_time('mysql'),
                    );
                    
                    $result = $wpdb->insert($approvals_table, $approval_data);
                    
                    if ($result) {
                        $wpdb->delete($candidates_table, array('id' => $candidate['id']));
                        $approved++;
                        error_log('[RawWire Workflow] Approved: "' . substr($title, 0, 50) . '" (score: ' . $score . ', rank: ' . ($index + 1) . ')');
                    } else {
                        error_log('[RawWire Workflow] Failed to insert into approvals: ' . $wpdb->last_error);
                    }
                } else {
                    // Remaining items go to archives
                    $archive_data = array(
                        'title'            => $candidate['title'],
                        'content'          => $candidate['content'],
                        'link'             => $candidate['link'],
                        'source'           => $candidate['source'],
                        'score'            => $score,
                        'ai_reason'        => $reasoning ?: 'Archived (rank ' . ($index + 1) . ' for source, score: ' . $score . ')',
                        'copyright_status' => $candidate['copyright_status'],
                        'result'           => 'Below Threshold',
                        'status'           => 'archived',
                        'created_at'       => current_time('mysql'),
                    );
                    
                    $result = $wpdb->insert($archives_table, $archive_data);
                    
                    if ($result) {
                        $wpdb->delete($candidates_table, array('id' => $candidate['id']));
                        $archived++;
                        error_log('[RawWire Workflow] Archived: "' . substr($title, 0, 50) . '" (score: ' . $score . ', rank: ' . ($index + 1) . ')');
                    } else {
                        error_log('[RawWire Workflow] Failed to insert into archives: ' . $wpdb->last_error);
                    }
                }
            }
        }
        
        error_log('[RawWire Workflow] Pipeline complete: ' . $approved . ' approved, ' . $archived . ' archived');
        
        return array('approved' => $approved, 'archived' => $archived);
    }
    
    /**
     * Store items in the target workflow table
     * Dedup checks against ALL workflow tables (candidates, approvals, archives, content, releases)
     */
    private function store_items(array $items, string $table_key) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'rawwire_' . $table_key;
        $stored = 0;
        $skipped_dedup = 0;
        $skipped_insert_failed = 0;
        
        // All tables to check for dedup - full 5-table workflow
        $dedup_tables = array(
            $wpdb->prefix . 'rawwire_candidates',
            $wpdb->prefix . 'rawwire_approvals',
            $wpdb->prefix . 'rawwire_archives',
            $wpdb->prefix . 'rawwire_content',
            $wpdb->prefix . 'rawwire_releases',
        );
        
        // Track what we've stored in THIS batch to avoid inserting same item twice
        $batch_keys = array();
        
        error_log('[RawWire Workflow] store_items: Processing ' . count($items) . ' items for table ' . $table_key);
        
        foreach ($items as $idx => $item) {
            // Handle APIs that nest data in 'attributes' (e.g., Regulations.gov)
            // Structure: { id: ..., type: ..., attributes: { title: ..., ... } }
            if (isset($item['attributes']) && is_array($item['attributes'])) {
                // Flatten attributes into the item, preserving 'source'
                $source_backup = $item['source'] ?? null;
                $item = array_merge($item, $item['attributes']);
                if ($source_backup) {
                    $item['source'] = $source_backup;
                }
            }
            
            // Map various API field names to our schema
            $title = $item['title'] 
                ?? $item['name'] 
                ?? $item['full_name'] 
                ?? $item['documentId']  // Regulations.gov fallback to document ID
                ?? 'Untitled';
            
            $content = $item['content'] 
                ?? $item['description'] 
                ?? $item['body'] 
                ?? $item['summary']      // Congress.gov uses 'summary'
                ?? $item['docAbstract']  // Regulations.gov uses 'docAbstract'
                ?? '';
            
            $link = $item['url'] 
                ?? $item['html_url'] 
                ?? $item['link'] 
                ?? $item['objectId']     // Regulations.gov link fallback
                ?? '';
            
            // Regulations.gov: build proper link from documentId if we have it
            if (empty($link) && isset($item['documentId'])) {
                $link = 'https://www.regulations.gov/document/' . $item['documentId'];
            }
            
            $source = $item['source'] ?? 'unknown';
            
            // Debug log first few items to understand structure
            if ($idx < 3) {
                error_log('[RawWire Workflow] Item ' . $idx . ' keys: ' . implode(', ', array_keys($item)));
                error_log('[RawWire Workflow] Item ' . $idx . ' mapped: title="' . substr($title, 0, 40) . '", link="' . substr($link, 0, 60) . '", source="' . $source . '"');
            }
            
            // Handle license (GitHub repos have license object)
            $license = 'unknown';
            if (isset($item['license'])) {
                if (is_array($item['license']) && isset($item['license']['spdx_id'])) {
                    $license = $item['license']['spdx_id'];
                } elseif (is_string($item['license'])) {
                    $license = $item['license'];
                }
            }
            
            $data = array(
                'title'            => $title,
                'content'          => $content,
                'link'             => $link,
                'source'           => $source,
                'copyright_status' => $license,
                'created_at'       => current_time('mysql'),
            );
            
            // Include score data if present
            if (isset($item['score'])) {
                $data['score'] = intval($item['score']);
            }
            if (isset($item['reasoning'])) {
                $data['reasoning'] = $item['reasoning'];
            }
            if (isset($item['scorer'])) {
                $data['scorer'] = $item['scorer'];
            }
            
            // Check for duplicates across ALL workflow tables (database only)
            $is_duplicate = false;
            $found_in_table = '';
            
            // First check batch dedup (same title+link in current batch)
            $batch_key = md5($data['title'] . '|' . $data['link']);
            if (isset($batch_keys[$batch_key])) {
                $is_duplicate = true;
                $found_in_table = 'current_batch';
                $skipped_dedup++;
            } else {
                // Check database tables
                foreach ($dedup_tables as $check_table) {
                    // Check if table exists first
                    if ($wpdb->get_var("SHOW TABLES LIKE '$check_table'") !== $check_table) {
                        continue;
                    }
                    
                    $exists = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$check_table} WHERE title = %s AND link = %s",
                        $data['title'],
                        $data['link']
                    ));
                    
                    if ($exists) {
                        $is_duplicate = true;
                        $found_in_table = str_replace($wpdb->prefix . 'rawwire_', '', $check_table);
                        $skipped_dedup++;
                        break;
                    }
                }
            }
            
            if ($is_duplicate) {
                error_log('[RawWire Workflow] Skipped duplicate: "' . substr($data['title'], 0, 50) . '" (found in ' . $found_in_table . ')');
            } else {
                $result = $wpdb->insert($table, $data);
                if ($result) {
                    $stored++;
                    // Track in batch to prevent re-inserting same item
                    $batch_keys[$batch_key] = true;
                } else {
                    $skipped_insert_failed++;
                    error_log('[RawWire Workflow] Failed to insert: "' . substr($data['title'], 0, 50) . '" - ' . $wpdb->last_error);
                }
            }
        }
        
        error_log('[RawWire Workflow] store_items complete: ' . $stored . ' stored, ' . $skipped_dedup . ' duplicates, ' . $skipped_insert_failed . ' failed');
        
        return $stored;
    }
    
    /**
     * Update execution state
     */
    private function update_execution($execution_id, array $updates) {
        if (isset(self::$executions[$execution_id])) {
            self::$executions[$execution_id] = array_merge(
                self::$executions[$execution_id],
                $updates
            );
            set_transient('rawwire_workflow_' . $execution_id, self::$executions[$execution_id], HOUR_IN_SECONDS);
        }
    }
    
    /**
     * Fail execution with error
     */
    private function fail_execution($execution_id, $error) {
        $this->update_execution($execution_id, array(
            'status' => 'failed',
            'stage' => 'error',
            'error' => $error,
        ));
        
        return array(
            'success' => false,
            'execution_id' => $execution_id,
            'error' => $error,
        );
    }
    
    /**
     * Log error to execution
     */
    private function log_error($execution_id, $error) {
        if (isset(self::$executions[$execution_id])) {
            self::$executions[$execution_id]['errors'][] = array(
                'message' => $error,
                'time' => current_time('mysql'),
            );
        }
    }
    
    /**
     * Schedule async execution
     */
    private function schedule_async($execution_id, array $config) {
        $this->update_execution($execution_id, array(
            'status' => 'scheduled',
            'stage' => 'queued',
        ));
        
        wp_schedule_single_event(time(), 'rawwire_workflow_execute', array($execution_id, $config));
        
        return array(
            'success' => true,
            'execution_id' => $execution_id,
            'status' => 'scheduled',
            'message' => 'Workflow scheduled for background execution',
        );
    }
    
    /**
     * Get execution status
     */
    public static function get_status($execution_id) {
        // Check memory first
        if (isset(self::$executions[$execution_id])) {
            return self::$executions[$execution_id];
        }
        
        // Check transient
        $execution = get_transient('rawwire_workflow_' . $execution_id);
        if ($execution) {
            return $execution;
        }
        
        return null;
    }
    
    /**
     * Validate workflow config
     */
    private function validate_config(array $config) {
        if (empty($config['scraper'])) {
            return array('valid' => false, 'error' => 'No scraper specified');
        }
        
        if (!isset(self::SCRAPERS[$config['scraper']])) {
            return array('valid' => false, 'error' => 'Unknown scraper: ' . $config['scraper']);
        }
        
        if (empty($config['sources'])) {
            return array('valid' => false, 'error' => 'No sources specified');
        }
        
        return array('valid' => true);
    }
    
    /**
     * Get default workflow configuration
     */
    public static function get_default_config() {
        return array(
            'scraper'        => 'github',
            'scorer'         => 'keyword',  // Enable scoring by default
            'max_records'    => 10,
            'top_per_source' => 2,          // Top N items per source go to approvals
            'target_table'   => 'candidates',
            'sources'        => array(),
            'filters'        => array(),
            'async'          => false,
        );
    }
}
