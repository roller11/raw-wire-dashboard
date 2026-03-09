<?php
/**
 * Sync Orchestrator Service
 * 
 * Coordinates the entire sync workflow: scrape → analyze → store
 * This is the main entry point for sync operations.
 * 
 * @package RawWire_Dashboard
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Include dependencies
require_once __DIR__ . '/class-scraper-service.php';
require_once __DIR__ . '/class-storage-service.php';

class RawWire_Sync_Service {
    
    /**
     * Scraper service
     */
    private $scraper;
    
    /**
     * Storage service
     */
    private $storage;
    
    /**
     * AI analyzer (optional)
     */
    private $analyzer;
    
    /**
     * Logger
     */
    private $logger;
    
    /**
     * Last sync results
     */
    private $last_results = array();
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->scraper = new RawWire_Scraper_Service();
        $this->storage = new RawWire_Storage_Service();
        $this->logger = class_exists('RawWire_Logger') ? 'RawWire_Logger' : null;
        
        // Load AI analyzer if available
        if (class_exists('RawWire_AI_Content_Analyzer')) {
            $this->analyzer = new RawWire_AI_Content_Analyzer();
        }
    }
    
    /**
     * Log message
     */
    private function log($message, $level = 'info', $context = array()) {
        if ($this->logger && method_exists($this->logger, $level)) {
            call_user_func(array($this->logger, $level), "[SyncService] " . $message, $context);
        }
        error_log("[RawWire SyncService] [{$level}] {$message}");
    }
    
    /**
     * Run full sync operation
     * 
     * @param array $config Configuration options
     * @return array Results
     */
    public function run_sync($config = array()) {
        $this->log('=== STARTING SYNC ===', 'info', array('config' => $config));
        
        $results = array(
            'success' => false,
            'phase' => 'init',
            'scraped' => 0,
            'analyzed' => 0,
            'stored' => 0,
            'queued' => 0,
            'errors' => array(),
            'started_at' => current_time('mysql'),
        );
        
        try {
            // PHASE 1: SCRAPE
            $results['phase'] = 'scraping';
            $this->log('PHASE 1: Scraping sources...', 'info');
            
            $scrape_result = $this->scraper->scrape_all($config);
            
            if (!$scrape_result['success']) {
                $results['errors'][] = 'Scraping failed';
                $results['errors'] = array_merge($results['errors'], $scrape_result['errors']);
                $this->log('Scraping failed', 'error', array('errors' => $scrape_result['errors']));
                return $results;
            }
            
            $results['scraped'] = $scrape_result['total_scraped'];
            $this->log("Scraped {$results['scraped']} items from " . count($scrape_result['items_by_source']) . " sources", 'info');
            
            // Flatten items from all sources
            $all_items = array();
            foreach ($scrape_result['items_by_source'] as $source_name => $items) {
                foreach ($items as $item) {
                    $all_items[] = $item;
                }
            }

            // Preload existing titles to prefer fresh items during selection
            $existing_titles = $this->storage->get_existing_titles_by_titles(array_column($all_items, 'title'));
            
            if (empty($all_items)) {
                $results['errors'][] = 'No items retrieved from any source';
                $this->log('No items to process', 'warning');
                return $results;
            }
            
            // PHASE 2: ANALYZE (if AI available)
            $items_to_store = $all_items;
            
            if ($this->analyzer) {
                $results['phase'] = 'analyzing';
                $this->log('PHASE 2: Analyzing with AI...', 'info');
                
                // Apply config-based settings (check method exists first)
                if (!empty($config['ai']['weights']) && method_exists($this->analyzer, 'set_weights')) {
                    $this->analyzer->set_weights($config['ai']['weights']);
                }
                if (!empty($config['ai']['custom_instructions']) && method_exists($this->analyzer, 'set_custom_instructions')) {
                    $this->analyzer->set_custom_instructions($config['ai']['custom_instructions']);
                }
                
                // Get top items per source (max 5 each)
                $items_to_store = array();
                foreach ($scrape_result['items_by_source'] as $source_name => $items) {
                    // Filter out already-stored titles before analysis to get fresh picks
                    if (!empty($existing_titles)) {
                        $items = array_values(array_filter($items, function($item) use ($existing_titles) {
                            return !in_array($item['title'], $existing_titles, true);
                        }));
                    }

                    if (empty($items)) {
                        $this->log("Skipping {$source_name} (all items already stored)", 'info');
                        continue;
                    }

                    // Limit to the latest 10 per source before scoring
                    $items = array_slice($items, 0, 10);

                    $this->log("Analyzing items from {$source_name}...", 'debug');

                    $analyzed = array();
                    try {
                        // Analyze and get top 2 fresh items from this source
                        $analyzed = $this->analyzer->analyze_batch($items, 2);
                        
                        // Track queued items
                        $queued_count = 0;
                        foreach ($analyzed as $analysis) {
                            if (isset($analysis['queued']) && $analysis['queued']) {
                                $queued_count++;
                            }
                        }
                        if ($queued_count > 0) {
                            $results['queued'] += $queued_count;
                            $this->log("🔴 WARNING: {$queued_count} items from {$source_name} queued for retry due to AI failures", 'error');
                        }
                    } catch (Exception $e) {
                        $this->log('AI analysis failed for ' . $source_name . ', using fallback scoring', 'warning', array('error' => $e->getMessage()));
                        // Fallback: take top 2 by order provided
                        $fallback = array_slice($items, 0, 2);
                        foreach ($fallback as $fb) {
                            $analyzed[] = array('original' => $fb, 'score' => 0, 'reasoning' => 'fallback');
                        }
                    }

                    foreach ($analyzed as $analyzed_item) {
                        $item = $analyzed_item['original'];
                        $item['score'] = $analyzed_item['score'];
                        $item['reasoning'] = $analyzed_item['reasoning'] ?? '';
                        $items_to_store[] = $item;
                    }
                }
                
                $results['analyzed'] = count($items_to_store);
                $this->log("AI selected {$results['analyzed']} top items", 'info');
                
            } else {
                $this->log('PHASE 2: Skipping AI (not available), using all items', 'warning');
                // Limit to top 40 items (5 per source)
                $items_to_store = array_slice($all_items, 0, 40);
            }
            
            // Filter out items already stored (by title) so we always pick fresh ones
            $existing_titles = $this->storage->get_existing_titles_by_titles(array_column($items_to_store, 'title'));
            if (!empty($existing_titles)) {
                $items_to_store = array_values(array_filter($items_to_store, function($item) use ($existing_titles) {
                    return !in_array($item['title'], $existing_titles, true);
                }));
                $this->log('Filtered already-stored items: ' . count($existing_titles), 'info');
            }

            // PHASE 3: STORE
            $results['phase'] = 'storing';
            $this->log('PHASE 3: Storing items...', 'info');
            
            $store_result = $this->storage->store_items($items_to_store);
            
            $results['stored'] = $store_result['stored'];
            $results['duplicates'] = $store_result['duplicates'];
            $this->log("Stored {$results['stored']} items, skipped {$results['duplicates']} duplicates", 'info');
            
            // SUCCESS: treat run as successful even if all were duplicates
            $results['success'] = true;
            $results['phase'] = 'complete';
            $results['completed_at'] = current_time('mysql');
            $results['count'] = $results['stored']; // For frontend compatibility
            
            // Build message with queued items warning if applicable
            $message = "Sync finished: stored {$results['stored']}, duplicates {$results['duplicates']}";
            if ($results['queued'] > 0) {
                $message .= ", {$results['queued']} queued for AI retry";
            }
            $results['message'] = $message;
            
            // Update last sync timestamp
            update_option('rawwire_last_sync', current_time('mysql'));
            
            $this->log('=== SYNC COMPLETE ===', 'info', array(
                'scraped' => $results['scraped'],
                'analyzed' => $results['analyzed'],
                'stored' => $results['stored'],
                'queued' => $results['queued'],
            ));
            
            // Log warning if items were queued
            if ($results['queued'] > 0) {
                $this->log("🔴 ATTENTION: {$results['queued']} items could not be analyzed and were queued for manual retry", 'error');
            }
            
        } catch (Exception $e) {
            $results['errors'][] = 'Exception: ' . $e->getMessage();
            $this->log('Sync exception: ' . $e->getMessage(), 'error');
        }
        
        $this->last_results = $results;
        return $results;
    }
    
    /**
     * Test sync (scrape only, no storage)
     */
    public function test_scrape($config = array()) {
        $this->log('Running test scrape...', 'info');
        return $this->scraper->scrape_all($config);
    }
    
    /**
     * Test single source
     */
    public function test_source($source_key) {
        return $this->scraper->test_source($source_key);
    }
    
    /**
     * Get available sources
     */
    public function get_sources() {
        return $this->scraper->get_sources();
    }
    
    /**
     * Get storage counts
     */
    public function get_counts() {
        return $this->storage->get_counts();
    }
    
    /**
     * Get pending items
     */
    public function get_pending($limit = 50) {
        return $this->storage->get_pending($limit);
    }
    
    /**
     * Get last results
     */
    public function get_last_results() {
        return $this->last_results;
    }
}
