<?php
/**
 * Scoring Handler
 * Processes candidates and moves top 2 to archives with Accepted status
 */

if (!defined('ABSPATH')) {
    exit;
}

class RawWire_Scoring_Handler {
    
    /**
     * AI Analyzer instance
     */
    private $analyzer;
    
    /**
     * Constructor
     */
    public function __construct() {
        // Hook into scraper complete event
        add_action('rawwire_scrape_complete', array($this, 'process_candidates'), 10, 1);
        
        // Initialize AI analyzer
        if (class_exists('RawWire_AI_Content_Analyzer')) {
            $this->analyzer = new RawWire_AI_Content_Analyzer();
        }
    }
    
    /**
     * Process candidates after scraping completes
     * 
     * @param array $scrape_results Results from scraper
     */
    public function process_candidates($scrape_results) {
        error_log('RawWire: Processing candidates after scrape');
        
        // Update workflow status
        set_transient('rawwire_workflow_status', array(
            'active' => true,
            'stage' => 'scoring',
            'message' => 'AI scoring candidates...',
            'progress' => 40,
            'startTime' => current_time('mysql')
        ), 300); // 5 minutes
        
        if (!$this->analyzer) {
            error_log('RawWire: AI analyzer not available, skipping scoring');
            return;
        }
        
        global $wpdb;
        $candidates_table = $wpdb->prefix . 'rawwire_candidates';
        $archives_table = $wpdb->prefix . 'rawwire_archives';
        
        // Get unique sources from candidates table
        $sources = $wpdb->get_col("SELECT DISTINCT source FROM {$candidates_table}");
        
        if (empty($sources)) {
            error_log('RawWire: No sources found in candidates table');
            return;
        }
        
        $total_processed = 0;
        $total_accepted = 0;
        
        foreach ($sources as $source) {
            // Get all candidates for this source
            $candidates = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$candidates_table} WHERE source = %s ORDER BY created_at DESC",
                $source
            ), ARRAY_A);
            
            if (empty($candidates)) {
                continue;
            }
            
            error_log("RawWire: Processing " . count($candidates) . " candidates from {$source}");
            
            // Score all candidates using AI
            $scored_items = $this->score_candidates($candidates);
            
            // Move all to archives with Accepted/Rejected status
            $accepted_count = $this->move_to_archives($scored_items, $source);
            
            // Delete processed candidates
            $wpdb->delete($candidates_table, array('source' => $source), array('%s'));
            
            $total_processed += count($candidates);
            $total_accepted += $accepted_count;
            
            error_log("RawWire: Processed {$source}: {$accepted_count} accepted, " . (count($candidates) - $accepted_count) . " rejected");
        }
        
        error_log("RawWire: Scoring complete. Processed {$total_processed} items, {$total_accepted} accepted");
        
        // Mark workflow as complete
        set_transient('rawwire_workflow_status', array(
            'active' => false,
            'stage' => 'complete',
            'message' => "Complete! {$total_accepted} items accepted.",
            'progress' => 100,
            'startTime' => current_time('mysql')
        ), 60); // Keep for 1 minute to show completion
    }
    
    /**
     * Score candidates using AI
     * 
     * @param array $candidates Array of candidate items
     * @return array Scored items with rankings
     */
    private function score_candidates($candidates) {
        $scored = array();
        
        try {
            // Use analyzer's batch scoring (returns top items)
            $batch_size = count($candidates);
            $analyzed = $this->analyzer->analyze_batch($candidates, $batch_size);
            
            foreach ($analyzed as $analysis) {
                $original = $analysis['original'];
                $original['score'] = $analysis['score'] ?? 0;
                $original['ai_reason'] = $analysis['reasoning'] ?? '';
                $scored[] = $original;
            }
            
        } catch (Exception $e) {
            error_log('RawWire: AI scoring failed: ' . $e->getMessage());
            
            // Fallback: assign scores based on order
            foreach ($candidates as $index => $candidate) {
                $candidate['score'] = 100 - ($index * 10); // Descending scores
                $candidate['ai_reason'] = 'Fallback scoring (AI unavailable)';
                $scored[] = $candidate;
            }
        }
        
        return $scored;
    }
    
    /**
     * Move scored items to appropriate tables
     * Top 2 go to approvals table, others go to archives table
     * 
     * @param array $scored_items Scored items
     * @param string $source Source identifier
     * @return int Number of accepted items
     */
    private function move_to_archives($scored_items, $source) {
        global $wpdb;
        $approvals_table = $wpdb->prefix . 'rawwire_approvals';
        $archives_table = $wpdb->prefix . 'rawwire_archives';
        
        // Sort by score descending
        usort($scored_items, function($a, $b) {
            return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
        });
        
        $accepted_count = 0;
        
        foreach ($scored_items as $index => $item) {
            // Top 2 go to approvals, rest go to archives
            $is_approved = ($index < 2);
            
            if ($is_approved) {
                $accepted_count++;
                
                // Check for duplicates in approvals
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$approvals_table} WHERE title = %s AND source = %s",
                    $item['title'],
                    $source
                ));
                
                if ($exists) {
                    continue;
                }
                
                // Insert into approvals table
                $wpdb->insert(
                    $approvals_table,
                    array(
                        'title' => sanitize_text_field($item['title']),
                        'content' => wp_kses_post($item['content'] ?? ''),
                        'link' => esc_url_raw($item['link'] ?? ''),
                        'source' => $source,
                        'copyright_status' => $item['copyright_status'] ?? 'unknown',
                        'copyright_info' => $item['copyright_info'] ?? '',
                        'attribution' => $item['attribution'] ?? '',
                        'publication_date' => $item['publication_date'] ?? '',
                        'document_number' => $item['document_number'] ?? '',
                        'score' => $item['score'] ?? 0,
                        'ai_reason' => $item['ai_reason'] ?? '',
                        'status' => 'pending',
                        'created_at' => $item['created_at'] ?? current_time('mysql'),
                        'scored_at' => current_time('mysql')
                    ),
                    array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s')
                );
            } else {
                // Check for duplicates in archives
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$archives_table} WHERE title = %s AND source = %s",
                    $item['title'],
                    $source
                ));
                
                if ($exists) {
                    continue;
                }
                
                // Insert into archives table (rejected items)
                $wpdb->insert(
                    $archives_table,
                    array(
                        'title' => sanitize_text_field($item['title']),
                        'content' => wp_kses_post($item['content'] ?? ''),
                        'link' => esc_url_raw($item['link'] ?? ''),
                        'source' => $source,
                        'copyright_status' => $item['copyright_status'] ?? 'unknown',
                        'copyright_info' => $item['copyright_info'] ?? '',
                        'attribution' => $item['attribution'] ?? '',
                        'publication_date' => $item['publication_date'] ?? '',
                        'document_number' => $item['document_number'] ?? '',
                        'score' => $item['score'] ?? 0,
                        'ai_reason' => $item['ai_reason'] ?? '',
                        'result' => 'Rejected',
                        'rejection_reason' => 'ai_rejected',
                        'status' => 'archived',
                        'created_at' => $item['created_at'] ?? current_time('mysql'),
                        'scored_at' => current_time('mysql'),
                        'archived_at' => current_time('mysql')
                    ),
                    array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
                );
            }
        }
        
        return $accepted_count;
    }
}

// Initialize handler
new RawWire_Scoring_Handler();
