<?php
/**
 * Scorer Base Class
 * 
 * Abstract base class for all scoring adapters.
 *
 * @package RawWire\Dashboard\Cores\ToolboxCore\Adapters\Scorers
 * @since 1.0.22
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Abstract Class RawWire_Scorer_Base
 */
abstract class RawWire_Scorer_Base {

    /**
     * Scorer ID
     * @var string
     */
    protected $id = '';

    /**
     * Scorer label
     * @var string
     */
    protected $label = '';

    /**
     * Scoring criteria weights
     * @var array
     */
    protected $weights = array(
        'relevance'  => 0.30,
        'quality'    => 0.25,
        'timeliness' => 0.20,
        'uniqueness' => 0.15,
        'engagement' => 0.10,
    );

    /**
     * Constructor
     * 
     * @param array $config Configuration options
     */
    public function __construct($config = array()) {
        if (!empty($config['weights'])) {
            $this->weights = array_merge($this->weights, $config['weights']);
        }
    }

    /**
     * Score a batch of items
     * 
     * @param array $items Items to score
     * @return array Scored items with 'score' and 'reasoning' added
     */
    abstract public function score_batch($items);

    /**
     * Score a single item
     * 
     * @param array $item Item to score
     * @return array Item with 'score' and 'reasoning' added
     */
    abstract public function score_item($item);

    /**
     * Get scorer ID
     * 
     * @return string
     */
    public function get_id() {
        return $this->id;
    }

    /**
     * Get scorer label
     * 
     * @return string
     */
    public function get_label() {
        return $this->label;
    }

    /**
     * Calculate weighted score from criteria scores
     * 
     * @param array $scores Associative array of criteria => score (0-100)
     * @return int Final weighted score (0-100)
     */
    protected function calculate_weighted_score($scores) {
        $total = 0;
        $weight_sum = 0;

        foreach ($this->weights as $criteria => $weight) {
            if (isset($scores[$criteria])) {
                $total += $scores[$criteria] * $weight;
                $weight_sum += $weight;
            }
        }

        return $weight_sum > 0 ? round($total / $weight_sum) : 0;
    }

    /**
     * Normalize score to 0-100 range
     * 
     * @param float $score Raw score
     * @param float $min   Minimum possible score
     * @param float $max   Maximum possible score
     * @return int Normalized score
     */
    protected function normalize_score($score, $min = 0, $max = 100) {
        if ($max == $min) {
            return 50;
        }
        $normalized = (($score - $min) / ($max - $min)) * 100;
        return max(0, min(100, round($normalized)));
    }
}
