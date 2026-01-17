<?php
/**
 * AI Relevance Scorer Adapter
 * 
 * Scores content using AI for semantic relevance analysis.
 * This is the VALUE tier scorer - uses AI Engine API.
 *
 * @package RawWire\Dashboard\Cores\ToolboxCore\Adapters\Scorers
 * @since 1.0.22
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-scorer-base.php';

/**
 * Class RawWire_Scorer_AI_Relevance
 * 
 * AI-powered semantic scoring adapter (Value Tier)
 */
class RawWire_Scorer_AI_Relevance extends RawWire_Scorer_Base {

    /**
     * Scorer ID
     * @var string
     */
    protected $id = 'ai_relevance';

    /**
     * Scorer label
     * @var string
     */
    protected $label = 'AI Relevance Scorer';

    /**
     * AI adapter instance
     * @var RawWire_AI_Adapter|null
     */
    private $ai_adapter = null;

    /**
     * Campaign context for AI prompts
     * @var array
     */
    private $campaign_context = array();

    /**
     * Constructor
     * 
     * @param array $config Configuration options
     */
    public function __construct($config = array()) {
        parent::__construct($config);

        // Adjusted weights for AI scoring
        $this->weights = array(
            'relevance'  => 0.35,
            'quality'    => 0.25,
            'timeliness' => 0.15,
            'uniqueness' => 0.15,
            'engagement' => 0.10,
        );

        // Initialize AI adapter
        $this->init_ai_adapter();
    }

    /**
     * Initialize AI adapter
     */
    private function init_ai_adapter() {
        // AI Adapter is at toolbox-core level, not in adapters/ai
        $ai_adapter_file = dirname(dirname(dirname(__FILE__))) . '/class-ai-adapter.php';
        
        if (file_exists($ai_adapter_file)) {
            require_once $ai_adapter_file;
            if (class_exists('RawWire_AI_Adapter')) {
                $this->ai_adapter = RawWire_AI_Adapter::get_instance();
                // Directly set Groq environment ID (69uakao7 based on AI Engine config)
                $this->ai_adapter->set_default_env('69uakao7');
            }
        }
    }

    /**
     * Score a batch of items using AI
     * 
     * @param array $items Items to score
     * @return array Scored items
     */
    public function score_batch($items) {
        $this->load_campaign_context();

        // If no AI adapter, fall back to keyword scoring
        if (!$this->ai_adapter) {
            return $this->score_batch_fallback($items);
        }

        $scored = array();
        
        // Process in smaller batches to avoid token limits
        $batch_size = 5;
        $batches = array_chunk($items, $batch_size);

        foreach ($batches as $batch) {
            $batch_scored = $this->score_batch_with_ai($batch);
            $scored = array_merge($scored, $batch_scored);
        }

        return $scored;
    }

    /**
     * Score a single item using AI
     * 
     * @param array $item Item to score
     * @return array Item with score and reasoning
     */
    public function score_item($item) {
        $this->load_campaign_context();

        if (!$this->ai_adapter) {
            return $this->score_item_fallback($item);
        }

        // Build prompt for single item scoring
        $prompt = $this->build_single_score_prompt($item);

        try {
            // Use json_query for structured response
            $response = $this->ai_adapter->json_query($prompt, array(
                'maxTokens' => 500,
                'temperature' => 0.3,
            ));

            if (is_wp_error($response)) {
                error_log('[RawWire] AI Scorer error: ' . $response->get_error_message());
                return $this->score_item_fallback($item);
            }

            // json_query returns parsed array directly
            $parsed = $this->parse_ai_score_response_data($response, $item);
            return $parsed;

        } catch (Exception $e) {
            error_log('[RawWire] AI Scorer error: ' . $e->getMessage());
            return $this->score_item_fallback($item);
        }
    }

    /**
     * Score a batch with AI
     * 
     * @param array $items Items batch
     * @return array Scored items
     */
    private function score_batch_with_ai($items) {
        $prompt = $this->build_batch_score_prompt($items);

        try {
            // Use text_query and parse JSON manually for batch
            // Specify Groq model explicitly since envId alone doesn't set the model
            // Using llama-3.1-8b-instant as it's faster and more widely available
            $response = $this->ai_adapter->text_query($prompt, array(
                'maxTokens' => 2000,
                'temperature' => 0.3,
                'model' => 'llama-3.1-8b-instant',  // Groq Llama model
            ));

            if (is_wp_error($response)) {
                error_log('[RawWire] AI Batch Scorer error: ' . $response->get_error_message());
                return $this->score_batch_fallback($items);
            }

            return $this->parse_batch_ai_response($response, $items);

        } catch (Exception $e) {
            error_log('[RawWire] AI Batch Scorer error: ' . $e->getMessage());
            return $this->score_batch_fallback($items);
        }
    }

    /**
     * Build prompt for single item scoring
     * 
     * @param array $item Item to score
     * @return string Prompt
     */
    private function build_single_score_prompt($item) {
        $content = $this->extract_content($item);
        $context = $this->get_context_string();

        return <<<PROMPT
You are a content relevance scorer for an affiliate marketing content pipeline.

CAMPAIGN CONTEXT:
{$context}

SCORING CRITERIA (rate each 0-100):
1. RELEVANCE: How well does this content align with the campaign keywords and niche?
2. QUALITY: Is the content well-written, informative, and professional?
3. TIMELINESS: Is the information current and relevant to today's market?
4. UNIQUENESS: Does this offer a fresh perspective or unique angle?
5. ENGAGEMENT: Would this content engage and convert the target audience?

CONTENT TO SCORE:
Title: {$item['title']}
Content: {$content}

Respond in this exact JSON format:
{
    "scores": {
        "relevance": <0-100>,
        "quality": <0-100>,
        "timeliness": <0-100>,
        "uniqueness": <0-100>,
        "engagement": <0-100>
    },
    "final_score": <0-100>,
    "reasoning": "<2-3 sentence explanation>",
    "recommendation": "<approve|review|reject>"
}
PROMPT;
    }

    /**
     * Build prompt for batch scoring
     * 
     * @param array $items Items to score
     * @return string Prompt
     */
    private function build_batch_score_prompt($items) {
        $context = $this->get_context_string();
        $items_text = '';

        foreach ($items as $index => $item) {
            $content = $this->extract_content($item);
            $id = $item['id'] ?? $index;
            $items_text .= "\n--- ITEM {$id} ---\n";
            $items_text .= "Title: " . ($item['title'] ?? 'Untitled') . "\n";
            $items_text .= "Content: " . substr($content, 0, 500) . "...\n";
        }

        return <<<PROMPT
You are a content relevance scorer for an affiliate marketing content pipeline.

CAMPAIGN CONTEXT:
{$context}

SCORING CRITERIA (rate each 0-100):
1. RELEVANCE: Campaign keyword alignment
2. QUALITY: Writing quality and professionalism
3. TIMELINESS: Current and market-relevant
4. UNIQUENESS: Fresh perspective
5. ENGAGEMENT: Audience appeal

ITEMS TO SCORE:
{$items_text}

Respond with a JSON array. Each item should have:
{
    "id": <item_id>,
    "final_score": <0-100>,
    "relevance": <0-100>,
    "quality": <0-100>,
    "reasoning": "<brief explanation>",
    "recommendation": "<approve|review|reject>"
}

Return ONLY valid JSON array.
PROMPT;
    }

    /**
     * Parse AI response for single item (from json_query parsed data)
     * 
     * @param array $data Parsed JSON data from AI
     * @param array $item Original item
     * @return array Scored item
     */
    private function parse_ai_score_response_data($data, $item) {
        if (!is_array($data) || !isset($data['final_score'])) {
            return $this->score_item_fallback($item);
        }

        $item['score'] = intval($data['final_score']);
        $item['reasoning'] = $data['reasoning'] ?? 'AI scored based on relevance criteria.';
        $item['scorer'] = $this->id;
        $item['recommendation'] = $data['recommendation'] ?? 'review';
        
        if (isset($data['scores'])) {
            $item['score_breakdown'] = $data['scores'];
        }

        return $item;
    }

    /**
     * Parse AI response for single item (from text string)
     * 
     * @param string $response AI response text
     * @param array  $item     Original item
     * @return array Scored item
     */
    private function parse_ai_score_response($response, $item) {
        // Try to extract JSON from response
        $json_match = preg_match('/\{[\s\S]*\}/', $response, $matches);
        
        if (!$json_match) {
            return $this->score_item_fallback($item);
        }

        $data = json_decode($matches[0], true);

        if (!$data || !isset($data['final_score'])) {
            return $this->score_item_fallback($item);
        }

        $item['score'] = intval($data['final_score']);
        $item['reasoning'] = $data['reasoning'] ?? 'AI scored based on relevance criteria.';
        $item['scorer'] = $this->id;
        $item['recommendation'] = $data['recommendation'] ?? 'review';
        
        if (isset($data['scores'])) {
            $item['score_breakdown'] = $data['scores'];
        }

        return $item;
    }

    /**
     * Parse batch AI response
     * 
     * @param string $response AI response
     * @param array  $items    Original items
     * @return array Scored items
     */
    private function parse_batch_ai_response($response, $items) {
        // Try to extract JSON array
        $json_match = preg_match('/\[[\s\S]*\]/', $response, $matches);
        
        if (!$json_match) {
            return $this->score_batch_fallback($items);
        }

        $data = json_decode($matches[0], true);

        if (!is_array($data)) {
            return $this->score_batch_fallback($items);
        }

        // Map AI scores back to items
        $scored = array();
        $scores_by_id = array();

        foreach ($data as $score_data) {
            if (isset($score_data['id'])) {
                $scores_by_id[$score_data['id']] = $score_data;
            }
        }

        foreach ($items as $index => $item) {
            $id = $item['id'] ?? $index;
            
            if (isset($scores_by_id[$id])) {
                $ai_score = $scores_by_id[$id];
                $item['score'] = intval($ai_score['final_score'] ?? 50);
                $item['reasoning'] = $ai_score['reasoning'] ?? 'AI scored.';
                $item['scorer'] = $this->id;
                $item['recommendation'] = $ai_score['recommendation'] ?? 'review';
                
                if (isset($ai_score['relevance'])) {
                    $item['score_breakdown'] = array(
                        'relevance' => $ai_score['relevance'],
                        'quality'   => $ai_score['quality'] ?? 50,
                    );
                }
            } else {
                // Fallback for missing scores
                $item = $this->score_item_fallback($item);
            }

            $scored[] = $item;
        }

        return $scored;
    }

    /**
     * Fallback scoring when AI is unavailable
     * 
     * @param array $items Items to score
     * @return array Scored items
     */
    private function score_batch_fallback($items) {
        // Use keyword scorer as fallback
        require_once __DIR__ . '/class-scorer-keyword.php';
        $keyword_scorer = new RawWire_Scorer_Keyword();
        
        $scored = $keyword_scorer->score_batch($items);
        
        // Mark as fallback
        foreach ($scored as &$item) {
            $item['scorer'] = $this->id . '_fallback';
            $item['reasoning'] = '[AI unavailable - keyword fallback] ' . ($item['reasoning'] ?? '');
        }

        return $scored;
    }

    /**
     * Fallback scoring for single item
     * 
     * @param array $item Item to score
     * @return array Scored item
     */
    private function score_item_fallback($item) {
        require_once __DIR__ . '/class-scorer-keyword.php';
        $keyword_scorer = new RawWire_Scorer_Keyword();
        
        $scored = $keyword_scorer->score_item($item);
        $scored['scorer'] = $this->id . '_fallback';
        $scored['reasoning'] = '[AI unavailable - keyword fallback] ' . ($scored['reasoning'] ?? '');

        return $scored;
    }

    /**
     * Load campaign context for AI prompts
     */
    private function load_campaign_context() {
        if (!empty($this->campaign_context)) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'rw_campaigns';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            $this->campaign_context = array(
                'niche'    => 'affiliate marketing and digital content',
                'keywords' => array('affiliate', 'marketing', 'SEO', 'content', 'digital'),
                'audience' => 'content creators and affiliate marketers',
            );
            return;
        }

        $campaign = $wpdb->get_row(
            "SELECT * FROM $table WHERE status = 'active' LIMIT 1",
            ARRAY_A
        );

        if ($campaign) {
            $keywords = json_decode($campaign['keywords'] ?? '{}', true);
            $this->campaign_context = array(
                'niche'    => $campaign['niche'] ?? 'affiliate marketing',
                'keywords' => array_merge(
                    $keywords['primary'] ?? array(),
                    $keywords['secondary'] ?? array()
                ),
                'audience' => $campaign['target_audience'] ?? 'affiliate marketers',
            );
        } else {
            $this->campaign_context = array(
                'niche'    => 'affiliate marketing and digital content',
                'keywords' => array('affiliate', 'marketing', 'SEO', 'content'),
                'audience' => 'affiliate marketers',
            );
        }
    }

    /**
     * Get campaign context as string for prompts
     * 
     * @return string
     */
    private function get_context_string() {
        return sprintf(
            "Niche: %s\nTarget Keywords: %s\nTarget Audience: %s",
            $this->campaign_context['niche'] ?? 'affiliate marketing',
            implode(', ', $this->campaign_context['keywords'] ?? array()),
            $this->campaign_context['audience'] ?? 'content creators'
        );
    }

    /**
     * Extract content from item
     * 
     * @param array $item Item data
     * @return string Content text
     */
    private function extract_content($item) {
        $fields = array('content', 'description', 'body', 'summary', 'excerpt');
        
        foreach ($fields as $field) {
            if (!empty($item[$field]) && is_string($item[$field])) {
                return substr($item[$field], 0, 2000);
            }
        }

        return '';
    }
}
