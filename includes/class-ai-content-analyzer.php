<?php
/**
 * AI Content Analyzer
 * Uses AI to analyze scraped content and identify newsworthy items
 * 
 * @package RawWire_Dashboard
 */

if (!defined('ABSPATH')) {
    exit;
}

class RawWire_AI_Content_Analyzer {
    
    private $ai_generator;
    
    /**
     * Scoring criteria weights
     */
    private $criteria = array(
        'shocking' => 25,
        'unbelievable' => 25,
        'newsworthy' => 25,
        'unique' => 25,
    );

    public function __construct($ai_generator = null) {
        if ($ai_generator) {
            $this->ai_generator = $ai_generator;
        } else {
            // Default to Ollama (free)
            require_once __DIR__ . '/../cores/toolbox-core/adapters/generators/class-generator-ollama.php';
            $this->ai_generator = new RawWire_Adapter_Generator_Ollama(array());
        }
        
        // Load scoring weights from options if available
        $saved_weights = get_option('rawwire_scoring_weights', false);
        if ($saved_weights && is_array($saved_weights)) {
            $this->criteria = $saved_weights;
        }
    }

    /**
     * Analyze a batch of scraped content and return top findings
     * 
     * @param array $items Array of items with title, content, source
     * @param int $top_n Number of top items to return (default 10)
     * @return array Top findings with scores and reasoning
     */
    public function analyze_batch(array $items, $top_n = 10) {
        if (empty($items)) {
            return array();
        }

        $scored_items = array();
        
        foreach ($items as $index => $item) {
            $analysis = $this->analyze_single_item($item);
            
            if ($analysis['success']) {
                $scored_items[] = array(
                    'original' => $item,
                    'score' => $analysis['total_score'],
                    'scores' => $analysis['scores'],
                    'reasoning' => $analysis['reasoning'] ?? '',
                    'highlights' => $analysis['highlights'] ?? array(),
                );
            } elseif (isset($analysis['queued']) && $analysis['queued']) {
                // Include queued items so caller knows about them
                $scored_items[] = array(
                    'original' => $item,
                    'queued' => true,
                    'score' => 0,
                    'error' => $analysis['error'] ?? 'unknown',
                );
            }
            
            // Log progress
            if (($index + 1) % 10 === 0) {
                RawWire_Logger::info("Analyzed {$index}/{count} items", array(
                    'progress' => round((($index + 1) / count($items)) * 100, 1),
                ));
            }
        }

        // Sort by score descending (queued items have score 0 so will be last)
        usort($scored_items, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        // Return top N
        return array_slice($scored_items, 0, $top_n);
    }

    /**
     * Analyze a single content item
     */
    public function analyze_single_item(array $item) {
        $title = $item['title'] ?? '';
        $content = $item['content'] ?? '';
        $source = $item['source'] ?? '';

        // Truncate content if too long
        $content_preview = substr($content, 0, 2000);

        $prompt = $this->build_analysis_prompt($title, $content_preview, $source);
        
        $result = $this->ai_generator->generate($prompt, array(
            'format' => 'json',
            'temperature' => 0.3,
            // Increase timeout for slower local or remote models
            'timeout' => 60,
        ));
        $analysis = null;
        if (!$result['success']) {
            // Queue item for later processing instead of fallback scoring
            $this->queue_failed_item($item, $result['error'] ?? 'unknown');
            RawWire_Logger::error('🔴 AI ANALYSIS FAILED - Item queued for manual retry', array(
                'title' => $title,
                'error' => $result['error'] ?? 'unknown',
                'action' => 'Item added to retry queue',
                'queue_table' => 'wp_rawwire_queue'
            ));
            return array('success' => false, 'queued' => true, 'error' => $result['error'] ?? 'unknown');
        } else {
            // Parse JSON response
            $analysis = json_decode($result['content'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                // Queue item for retry if JSON parsing fails
                $this->queue_failed_item($item, 'JSON parse error: ' . json_last_error_msg());
                RawWire_Logger::error('🔴 AI RESPONSE INVALID - Item queued for manual retry', array(
                    'title' => $title,
                    'error' => 'JSON parse error: ' . json_last_error_msg(),
                    'action' => 'Item added to retry queue',
                    'raw_response' => substr($result['content'], 0, 200)
                ));
                return array('success' => false, 'queued' => true, 'error' => 'JSON parse error');
            }
        }

        // Calculate total weighted score
        $total_score = 0;
        foreach ($this->criteria as $criterion => $weight) {
            $score = $analysis['scores'][$criterion] ?? 5;
            $total_score += ($score / 10) * $weight;
        }

        $analysis['total_score'] = round($total_score, 1);
        $analysis['success'] = true;

        return $analysis;
    }

    /**
     * Build the AI analysis prompt
     */
    private function build_analysis_prompt($title, $content, $source) {
        return <<<PROMPT
You are analyzing news and regulatory content to identify the most compelling stories.

Analyze this content and rate it on the following criteria (scale 1-10):

**Content:**
Title: {$title}
Source: {$source}
Content: {$content}

**Criteria:**
1. **shocking** (1-10): How shocking or surprising is this? Would it make people say "wow" or "no way"?
2. **unbelievable** (1-10): How hard to believe is this? Is it something that seems almost too crazy to be true?
3. **newsworthy** (1-10): How newsworthy is this? Would major publications cover this?
4. **unique** (1-10): How unique or rare is this type of action? Has anything like this happened before?

Provide your analysis in this JSON format:
{
  "scores": {
    "shocking": 8,
    "unbelievable": 6,
    "newsworthy": 9,
    "unique": 7
  },
  "reasoning": "Brief 1-2 sentence explanation of why this is significant",
  "highlights": ["Key point 1", "Key point 2", "Key point 3"],
  "category": "enforcement" | "legislation" | "court_ruling" | "policy_change" | "warning" | "other"
}

Focus on finding content that is shocking, unbelievable, newsworthy, and unique - the kind of stuff that makes people stop and take notice.
PROMPT;
    }

    /**
     * Fallback scoring when AI fails
     */
    private function fallback_scoring($title, $content) {
        $title_lower = strtolower($title);
        $content_lower = strtolower($content . ' ' . $title);
        
        // Keyword-based scoring
        $scores = array(
            'shocking' => 5,
            'unbelievable' => 5,
            'newsworthy' => 5,
            'unique' => 5,
        );

        // High-impact keywords (shocking/unbelievable)
        $high_impact = array('ban', 'prohibition', 'illegal', 'seized', 'arrested', 'unprecedented', 'first time', 'historic', 'shocking', 'major');
        $newsworthy = array('enforcement', 'penalty', 'fine', 'lawsuit', 'court', 'ruling', 'regulation', 'law', 'policy');
        $unique_indicators = array('unprecedented', 'first', 'historic', 'landmark', 'groundbreaking', 'novel');

        foreach ($high_impact as $keyword) {
            if (strpos($content_lower, $keyword) !== false) {
                $scores['shocking'] += 2;
                $scores['unbelievable'] += 1;
            }
        }

        foreach ($newsworthy as $keyword) {
            if (strpos($content_lower, $keyword) !== false) {
                $scores['newsworthy'] += 1;
            }
        }

        foreach ($unique_indicators as $keyword) {
            if (strpos($content_lower, $keyword) !== false) {
                $scores['unique'] += 2;
            }
        }

        // Cap at 10
        foreach ($scores as $key => $value) {
            $scores[$key] = min(10, $value);
        }

        return array(
            'scores' => $scores,
            'reasoning' => 'Automatic keyword-based scoring (AI unavailable)',
            'highlights' => array('Contains relevant regulatory content'),
            'category' => 'other',
        );
    }

    /**
     * Quick filter - fast keyword check before AI analysis
     * Reduces AI calls by filtering obvious non-relevant content
     */
    public function quick_filter(array $items, array $keywords = array()) {
        // If no keywords provided, return all items
        if (empty($keywords)) {
            return $items;
        }

        $filtered = array();
        foreach ($items as $item) {
            $text = strtolower($item['title'] . ' ' . $item['content']);
            
            foreach ($keywords as $keyword) {
                if (strpos($text, $keyword) !== false) {
                    $filtered[] = $item;
                    break;
                }
            }
        }

        return $filtered;
    }

    /**
     * Queue failed item for later processing
     */
    private function queue_failed_item($item, $error) {
        global $wpdb;
        
        $queue_table = $wpdb->prefix . 'rawwire_queue';
        
        $wpdb->insert($queue_table, array(
            'stage' => 'ai_scoring',
            'status' => 'pending',
            'priority' => 5,
            'payload' => json_encode($item),
            'error' => $error,
            'attempts' => 0,
            'created_at' => current_time('mysql'),
        ), array('%s', '%s', '%d', '%s', '%s', '%d', '%s'));
        
        RawWire_Logger::log('WARNING: Item queued for retry - Will be processed when AI is available', 'info', array(
            'title' => $item['title'] ?? 'Unknown',
            'error' => $error,
            'queue_id' => $wpdb->insert_id
        ));
    }
}
