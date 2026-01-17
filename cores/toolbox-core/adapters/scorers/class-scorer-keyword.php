<?php
/**
 * Keyword Scorer Adapter
 * 
 * Scores content based on keyword matching against campaign keywords.
 * This is the FREE tier scorer - no AI API calls required.
 *
 * @package RawWire\Dashboard\Cores\ToolboxCore\Adapters\Scorers
 * @since 1.0.22
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-scorer-base.php';

/**
 * Class RawWire_Scorer_Keyword
 * 
 * Keyword-based scoring adapter (Free Tier)
 */
class RawWire_Scorer_Keyword extends RawWire_Scorer_Base {

    /**
     * Scorer ID
     * @var string
     */
    protected $id = 'keyword';

    /**
     * Scorer label
     * @var string
     */
    protected $label = 'Keyword Scorer';

    /**
     * Minimum score threshold
     * @var int
     */
    protected $min_score = 30;

    /**
     * Campaign keywords cache
     * @var array
     */
    private $campaign_keywords = array();

    /**
     * Constructor - load weights from options
     * 
     * @param array $config Configuration options
     */
    public function __construct($config = array()) {
        // Load saved weights from WordPress options
        $saved_weights = get_option('rawwire_scoring_weights', array());
        
        // Normalize saved weights to decimals (stored as percentages)
        if (!empty($saved_weights)) {
            $total = array_sum($saved_weights);
            if ($total > 0) {
                $config['weights'] = array(
                    'relevance'  => ($saved_weights['relevance'] ?? 30) / $total,
                    'timeliness' => ($saved_weights['timeliness'] ?? 20) / $total,
                    'quality'    => ($saved_weights['quality'] ?? 25) / $total,
                );
            }
        }
        
        parent::__construct($config);
    }

    /**
     * Score a batch of items
     * 
     * @param array $items Items to score (with 'content' or 'title' fields)
     * @return array Scored items
     */
    public function score_batch($items) {
        // Load campaign keywords once for batch
        $this->load_campaign_keywords();

        $scored = array();
        foreach ($items as $item) {
            $scored[] = $this->score_item($item);
        }

        return $scored;
    }

    /**
     * Score a single item
     * 
     * @param array $item Item with 'content', 'title', 'description' fields
     * @return array Item with 'score' and 'reasoning' added
     */
    public function score_item($item) {
        $this->load_campaign_keywords();

        // Build text to analyze
        $text = $this->build_analyzable_text($item);

        // Score components
        $keyword_score = $this->score_keyword_matches($text);
        $freshness_score = $this->score_freshness($item);
        $length_score = $this->score_content_length($text);

        // Calculate weighted final score
        $final_score = $this->calculate_weighted_score(array(
            'relevance'  => $keyword_score['score'],
            'timeliness' => $freshness_score,
            'quality'    => $length_score,
        ));

        // Build reasoning
        $reasoning = sprintf(
            'Keyword matches: %d/%d (%.0f%%). Freshness: %.0f%%. Content quality: %.0f%%. %s',
            $keyword_score['matched'],
            $keyword_score['total'],
            $keyword_score['score'],
            $freshness_score,
            $length_score,
            $keyword_score['details']
        );

        $item['score'] = $final_score;
        $item['reasoning'] = $reasoning;
        $item['scorer'] = $this->id;
        $item['score_breakdown'] = array(
            'keyword_relevance' => $keyword_score['score'],
            'freshness'         => $freshness_score,
            'content_quality'   => $length_score,
            'keywords_matched'  => $keyword_score['matched'],
            'keywords_total'    => $keyword_score['total'],
        );

        return $item;
    }

    /**
     * Load campaign keywords from database
     */
    private function load_campaign_keywords() {
        if (!empty($this->campaign_keywords)) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'rw_campaigns';

        // Check if campaigns table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            // Fallback to default keywords
            $this->campaign_keywords = array(
                'primary'   => array('affiliate', 'marketing', 'digital', 'SEO', 'content'),
                'secondary' => array('traffic', 'conversion', 'leads', 'revenue', 'strategy'),
            );
            return;
        }

        // Load active campaign keywords
        $campaigns = $wpdb->get_results(
            "SELECT keywords FROM $table WHERE status = 'active'",
            ARRAY_A
        );

        $primary = array();
        $secondary = array();

        foreach ($campaigns as $campaign) {
            $keywords = json_decode($campaign['keywords'], true);
            if (is_array($keywords)) {
                if (isset($keywords['primary'])) {
                    $primary = array_merge($primary, (array) $keywords['primary']);
                }
                if (isset($keywords['secondary'])) {
                    $secondary = array_merge($secondary, (array) $keywords['secondary']);
                }
            }
        }

        $this->campaign_keywords = array(
            'primary'   => array_unique(array_filter($primary)),
            'secondary' => array_unique(array_filter($secondary)),
        );

        // Fallback if no keywords found
        if (empty($this->campaign_keywords['primary'])) {
            $this->campaign_keywords['primary'] = array('affiliate', 'marketing', 'digital', 'SEO', 'content');
        }
    }

    /**
     * Build analyzable text from item fields
     * 
     * @param array $item Item data
     * @return string Combined text
     */
    private function build_analyzable_text($item) {
        $parts = array();

        // Priority order for text fields
        $fields = array('content', 'description', 'title', 'name', 'summary', 'body');

        foreach ($fields as $field) {
            if (!empty($item[$field])) {
                $parts[] = is_string($item[$field]) ? $item[$field] : '';
            }
        }

        return strtolower(implode(' ', $parts));
    }

    /**
     * Score based on keyword matches
     * 
     * @param string $text Text to analyze
     * @return array Score data with score, matched, total, details
     */
    private function score_keyword_matches($text) {
        $matched_primary = array();
        $matched_secondary = array();

        // Check primary keywords (weighted 2x)
        foreach ($this->campaign_keywords['primary'] as $keyword) {
            if ($this->keyword_exists($text, $keyword)) {
                $matched_primary[] = $keyword;
            }
        }

        // Check secondary keywords (weighted 1x)
        foreach ($this->campaign_keywords['secondary'] as $keyword) {
            if ($this->keyword_exists($text, $keyword)) {
                $matched_secondary[] = $keyword;
            }
        }

        // Calculate score
        $primary_total = count($this->campaign_keywords['primary']);
        $secondary_total = count($this->campaign_keywords['secondary']);
        $total_keywords = $primary_total + $secondary_total;

        $primary_matches = count($matched_primary);
        $secondary_matches = count($matched_secondary);
        $total_matches = $primary_matches + $secondary_matches;

        if ($total_keywords === 0) {
            return array(
                'score'   => 50,
                'matched' => 0,
                'total'   => 0,
                'details' => 'No campaign keywords configured.',
            );
        }

        // Primary keywords count double
        $weighted_score = $primary_total > 0 
            ? (($primary_matches / $primary_total) * 100 * 0.7) 
            : 0;
        $weighted_score += $secondary_total > 0 
            ? (($secondary_matches / $secondary_total) * 100 * 0.3) 
            : 0;

        $details = '';
        if (!empty($matched_primary)) {
            $details .= 'Primary: ' . implode(', ', $matched_primary) . '. ';
        }
        if (!empty($matched_secondary)) {
            $details .= 'Secondary: ' . implode(', ', $matched_secondary) . '.';
        }
        if (empty($details)) {
            $details = 'No keyword matches found.';
        }

        return array(
            'score'   => round($weighted_score),
            'matched' => $total_matches,
            'total'   => $total_keywords,
            'details' => trim($details),
        );
    }

    /**
     * Check if keyword exists in text (word boundary aware)
     * 
     * @param string $text    Text to search
     * @param string $keyword Keyword to find
     * @return bool
     */
    private function keyword_exists($text, $keyword) {
        $keyword = strtolower(trim($keyword));
        if (empty($keyword)) {
            return false;
        }

        // Use word boundary matching
        $pattern = '/\b' . preg_quote($keyword, '/') . '\b/i';
        return preg_match($pattern, $text) === 1;
    }

    /**
     * Score content freshness
     * 
     * @param array $item Item with potential date field
     * @return int Score 0-100
     */
    private function score_freshness($item) {
        $date_fields = array('published_at', 'created_at', 'date', 'scraped_at');
        $date = null;

        foreach ($date_fields as $field) {
            if (!empty($item[$field])) {
                $date = strtotime($item[$field]);
                if ($date !== false) {
                    break;
                }
            }
        }

        if (!$date) {
            return 70; // Default score if no date
        }

        $age_days = (time() - $date) / DAY_IN_SECONDS;

        // Scoring: newer is better
        if ($age_days <= 1) {
            return 100;
        } elseif ($age_days <= 7) {
            return 90;
        } elseif ($age_days <= 30) {
            return 75;
        } elseif ($age_days <= 90) {
            return 50;
        } elseif ($age_days <= 180) {
            return 30;
        }

        return 10; // Over 6 months old
    }

    /**
     * Score content length/quality
     * 
     * @param string $text Text to analyze
     * @return int Score 0-100
     */
    private function score_content_length($text) {
        $word_count = str_word_count($text);

        // Optimal length: 100-500 words
        if ($word_count < 20) {
            return 20; // Too short
        } elseif ($word_count < 50) {
            return 40;
        } elseif ($word_count < 100) {
            return 60;
        } elseif ($word_count <= 500) {
            return 100; // Optimal
        } elseif ($word_count <= 1000) {
            return 80;
        }

        return 60; // Very long
    }
}
