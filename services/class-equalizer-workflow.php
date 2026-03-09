<?php
/**
 * Equalizer Workflow Processor
 * Handles the dual-pipeline workflow for the Raw Wire Equalizer template
 * 
 * Post Generation Pipeline:
 * 1. CRAWLER → CR DATA: Crawlomatic pulls stories from industry publications
 * 2. CR DATA → CONTENT: AI extracts facts, condenses into post-sized stories  
 * 3. CONTENT (mirrored for review) → PENDING: Client approves stories
 * 4. PENDING → POSTED: AI formats and publishes to social media
 * 
 * @package RawWire\Dashboard\Services
 */

namespace RawWire\Dashboard\Services;

if (!defined('ABSPATH')) {
    exit;
}

class Equalizer_Workflow {
    
    /**
     * Logger instance
     */
    private static $logger = null;
    
    /**
     * AI Engine integration
     */
    private static $ai_engine = null;
    
    /**
     * Initialize the workflow processor
     */
    public static function init() {
        // Initialize logger
        if (class_exists('RawWire_Logger')) {
            self::$logger = new \RawWire_Logger();
        }
        
        // Register AJAX handlers
        add_action('wp_ajax_rawwire_eq_run_workflow', array(__CLASS__, 'ajax_run_workflow'));
        add_action('wp_ajax_rawwire_eq_process_crdata', array(__CLASS__, 'ajax_process_crdata'));
        add_action('wp_ajax_rawwire_eq_generate_content', array(__CLASS__, 'ajax_generate_content'));
        add_action('wp_ajax_rawwire_eq_approve_content', array(__CLASS__, 'ajax_approve_content'));
        add_action('wp_ajax_rawwire_eq_publish_post', array(__CLASS__, 'ajax_publish_post'));
        add_action('wp_ajax_rawwire_eq_bulk_approve', array(__CLASS__, 'ajax_bulk_approve'));
        add_action('wp_ajax_rawwire_eq_get_content_preview', array(__CLASS__, 'ajax_get_content_preview'));
        
        // Hook into Crawlomatic completion
        add_action('crawlomatic_after_post_insert', array(__CLASS__, 'on_crawlomatic_post'), 10, 3);
        
        // Cron hooks for automated processing
        add_action('rawwire_eq_process_crdata_batch', array(__CLASS__, 'process_crdata_batch'));
        add_action('rawwire_eq_publish_approved', array(__CLASS__, 'publish_approved_batch'));
    }
    
    /**
     * Log a message
     */
    private static function log($message, $level = 'info', $context = array()) {
        if (self::$logger) {
            $context['component'] = 'equalizer-workflow';
            if ($level === 'error') {
                self::$logger->log_error($message, $context, 'error');
            } else {
                self::$logger->log($message, $level, $context);
            }
        }
    }
    
    // =========================================================================
    // STEP 1: CRAWLER → CR DATA
    // =========================================================================
    
    /**
     * Hook into Crawlomatic post insertion to capture crawled data
     */
    public static function on_crawlomatic_post($post_id, $post_data, $rule_index) {
        global $wpdb;
        
        $crdata_table = $wpdb->prefix . 'rawwire_crdata';
        
        // Extract data from Crawlomatic post
        $insert_data = array(
            'title' => sanitize_text_field($post_data['post_title'] ?? ''),
            'content' => wp_kses_post($post_data['post_content'] ?? ''),
            'url' => esc_url_raw($post_data['source_url'] ?? ''),
            'source' => sanitize_text_field($post_data['source_name'] ?? 'crawlomatic'),
            'crawl_rule_id' => sanitize_text_field($rule_index),
            'crawl_date' => current_time('mysql'),
            'raw_html' => $post_data['raw_html'] ?? null,
            'images' => json_encode($post_data['images'] ?? array()),
            'meta_data' => json_encode(array(
                'wp_post_id' => $post_id,
                'categories' => $post_data['categories'] ?? array(),
                'tags' => $post_data['tags'] ?? array(),
            )),
            'status' => 'new',
            'created_at' => current_time('mysql'),
        );
        
        $wpdb->insert($crdata_table, $insert_data);
        
        self::log("Captured crawled content: {$insert_data['title']}", 'info', array(
            'source' => $insert_data['source'],
            'crdata_id' => $wpdb->insert_id,
        ));
        
        return $wpdb->insert_id;
    }
    
    /**
     * Import crawled posts from Crawlomatic into CR DATA table
     * This is for importing existing posts that were created before the hook was active
     */
    public static function import_from_crawlomatic($rule_index = null, $limit = 50) {
        global $wpdb;
        
        $crdata_table = $wpdb->prefix . 'rawwire_crdata';
        
        // Query recent posts created by Crawlomatic
        $query = "SELECT ID, post_title, post_content, post_date 
                  FROM {$wpdb->posts} 
                  WHERE post_status = 'publish' 
                  AND post_type = 'post'";
        
        if ($rule_index !== null) {
            // Filter by rule if specified (would need meta lookup)
        }
        
        $query .= " ORDER BY post_date DESC LIMIT %d";
        
        $posts = $wpdb->get_results($wpdb->prepare($query, $limit));
        $imported = 0;
        
        foreach ($posts as $post) {
            // Check if already imported
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$crdata_table} WHERE meta_data LIKE %s",
                '%"wp_post_id":' . $post->ID . '%'
            ));
            
            if ($exists) {
                continue;
            }
            
            // Get source URL from post meta
            $source_url = get_post_meta($post->ID, 'crawlomatic_source_url', true) 
                       ?: get_post_meta($post->ID, 'source_url', true);
            
            $insert_data = array(
                'title' => $post->post_title,
                'content' => $post->post_content,
                'url' => $source_url,
                'source' => 'crawlomatic-import',
                'crawl_date' => $post->post_date,
                'meta_data' => json_encode(array('wp_post_id' => $post->ID)),
                'status' => 'new',
                'created_at' => current_time('mysql'),
            );
            
            $wpdb->insert($crdata_table, $insert_data);
            $imported++;
        }
        
        self::log("Imported {$imported} posts from Crawlomatic", 'info');
        
        return $imported;
    }
    
    // =========================================================================
    // STEP 2: CR DATA → CONTENT (AI Processing)
    // =========================================================================
    
    /**
     * Process CR DATA items with AI to create condensed content
     */
    public static function process_crdata_item($crdata_id) {
        global $wpdb;
        
        $crdata_table = $wpdb->prefix . 'rawwire_crdata';
        $content_table = $wpdb->prefix . 'rawwire_eq_content';
        
        // Get the CR DATA item
        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$crdata_table} WHERE id = %d AND status = 'new'",
            $crdata_id
        ), ARRAY_A);
        
        if (!$item) {
            return array('success' => false, 'message' => 'Item not found or already processed');
        }
        
        // Mark as processing
        $wpdb->update($crdata_table, array('status' => 'processing'), array('id' => $crdata_id));
        
        // Build the AI prompt for news condensation
        $prompt = self::build_condensation_prompt($item);
        
        // Call AI to process the content
        $processed = self::call_ai_for_condensation($prompt, $item);
        
        if (!$processed['success']) {
            $wpdb->update($crdata_table, array('status' => 'error'), array('id' => $crdata_id));
            return $processed;
        }
        
        // Insert into CONTENT table
        $content_data = array(
            'crdata_id' => $crdata_id,
            'title' => sanitize_text_field($processed['title'] ?? $item['title']),
            'original_content' => $item['content'],
            'processed_content' => wp_kses_post($processed['content']),
            'url' => $item['url'],
            'source' => $item['source'],
            'category' => sanitize_text_field($processed['category'] ?? 'general'),
            'tags' => sanitize_text_field($processed['tags'] ?? ''),
            'score' => floatval($processed['relevance_score'] ?? 50),
            'status' => 'queued',
            'created_at' => current_time('mysql'),
            'processed_at' => current_time('mysql'),
        );
        
        $wpdb->insert($content_table, $content_data);
        $content_id = $wpdb->insert_id;
        
        // Mark CR DATA item as processed
        $wpdb->update($crdata_table, array('status' => 'processed'), array('id' => $crdata_id));
        
        self::log("Processed CR DATA #{$crdata_id} → Content #{$content_id}", 'info');
        
        return array(
            'success' => true,
            'content_id' => $content_id,
            'title' => $content_data['title'],
        );
    }
    
    /**
     * Build the AI prompt for condensing news content
     */
    private static function build_condensation_prompt($item) {
        $template = get_option('rawwire_condensation_prompt', '');
        
        if (empty($template)) {
            $template = <<<PROMPT
You are a news aggregator assistant. Analyze the following article and create a condensed, social-media-ready post.

**ARTICLE:**
Title: {{title}}
Source: {{source}}
Content: {{content}}

**INSTRUCTIONS:**
1. Extract the most important facts and key information
2. Condense into a 2-3 paragraph summary suitable for social media
3. Maintain factual accuracy - do not add information not in the original
4. Write in an engaging, professional tone
5. Include attribution to the original source

**OUTPUT FORMAT (JSON):**
{
    "title": "Condensed headline (max 100 characters)",
    "content": "Your condensed summary (150-300 words)",
    "category": "business|technology|politics|entertainment|sports|other",
    "tags": "comma,separated,relevant,tags",
    "relevance_score": 0-100,
    "key_facts": ["fact1", "fact2", "fact3"]
}
PROMPT;
        }
        
        // Replace placeholders
        $prompt = str_replace(
            array('{{title}}', '{{source}}', '{{content}}'),
            array($item['title'], $item['source'], wp_trim_words($item['content'], 1000)),
            $template
        );
        
        return $prompt;
    }
    
    /**
     * Call AI service to condense content
     */
    private static function call_ai_for_condensation($prompt, $item) {
        // Check for AI Engine plugin
        if (class_exists('Meow_MWAI_Core')) {
            return self::call_ai_engine($prompt);
        }
        
        // Check for direct API key
        $api_key = get_option('rawwire_openai_api_key', '') 
                ?: get_option('rawwire_generator_api_key', '');
        
        if (!empty($api_key)) {
            return self::call_openai_direct($prompt, $api_key);
        }
        
        // Fallback to mock processing for testing
        return self::mock_condensation($item);
    }
    
    /**
     * Call AI Engine plugin for processing
     */
    private static function call_ai_engine($prompt) {
        try {
            global $mwai_core;
            
            if (!$mwai_core) {
                $mwai_core = \Meow_MWAI_Core::instance();
            }
            
            $query = new \Meow_MWAI_Query_Text($prompt);
            $query->set_scope('assistant');
            
            $response = $mwai_core->run_query($query);
            
            if ($response && $response->result) {
                $result = json_decode($response->result, true);
                
                if (json_last_error() === JSON_ERROR_NONE && isset($result['content'])) {
                    $result['success'] = true;
                    return $result;
                }
                
                // If not valid JSON, treat as plain text content
                return array(
                    'success' => true,
                    'content' => $response->result,
                    'title' => '',
                    'category' => 'general',
                    'tags' => '',
                    'relevance_score' => 50,
                );
            }
            
            return array('success' => false, 'message' => 'AI Engine returned no response');
            
        } catch (\Exception $e) {
            self::log("AI Engine error: " . $e->getMessage(), 'error');
            return array('success' => false, 'message' => $e->getMessage());
        }
    }
    
    /**
     * Call OpenAI API directly
     */
    private static function call_openai_direct($prompt, $api_key) {
        $model = get_option('rawwire_ai_model', 'gpt-4o-mini');
        
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode(array(
                'model' => $model,
                'messages' => array(
                    array('role' => 'system', 'content' => 'You are a professional news editor and content curator. Always respond with valid JSON.'),
                    array('role' => 'user', 'content' => $prompt),
                ),
                'max_tokens' => 1000,
                'temperature' => 0.7,
            )),
            'timeout' => 60,
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            return array('success' => false, 'message' => $body['error']['message'] ?? 'API error');
        }
        
        $content = $body['choices'][0]['message']['content'] ?? '';
        
        // Try to parse as JSON
        $result = json_decode($content, true);
        
        if (json_last_error() === JSON_ERROR_NONE && isset($result['content'])) {
            $result['success'] = true;
            return $result;
        }
        
        // Return as plain text
        return array(
            'success' => true,
            'content' => $content,
            'category' => 'general',
            'tags' => '',
            'relevance_score' => 50,
        );
    }
    
    /**
     * Mock condensation for testing without AI
     */
    private static function mock_condensation($item) {
        // Simple extraction of first paragraphs
        $content = strip_tags($item['content']);
        $sentences = preg_split('/(?<=[.!?])\s+/', $content, 5);
        $condensed = implode(' ', array_slice($sentences, 0, 3));
        
        return array(
            'success' => true,
            'title' => wp_trim_words($item['title'], 10),
            'content' => "[Auto-condensed]\n\n" . $condensed . "\n\nSource: " . $item['source'],
            'category' => 'general',
            'tags' => 'news,aggregated',
            'relevance_score' => 50,
        );
    }
    
    /**
     * Process a batch of CR DATA items
     */
    public static function process_crdata_batch($limit = 10) {
        global $wpdb;
        
        $crdata_table = $wpdb->prefix . 'rawwire_crdata';
        
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM {$crdata_table} WHERE status = 'new' ORDER BY created_at ASC LIMIT %d",
            $limit
        ));
        
        $processed = 0;
        $errors = 0;
        
        foreach ($items as $item) {
            $result = self::process_crdata_item($item->id);
            
            if ($result['success']) {
                $processed++;
            } else {
                $errors++;
            }
            
            // Rate limiting
            usleep(500000); // 0.5 second between API calls
        }
        
        self::log("Batch processed: {$processed} items, {$errors} errors", 'info');
        
        return array(
            'processed' => $processed,
            'errors' => $errors,
        );
    }
    
    // =========================================================================
    // STEP 3: CONTENT → PENDING (Approval)
    // =========================================================================
    
    /**
     * Get content items for review
     */
    public static function get_content_for_review($status = 'queued', $limit = 20) {
        global $wpdb;
        
        $content_table = $wpdb->prefix . 'rawwire_eq_content';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$content_table} WHERE status = %s ORDER BY score DESC, created_at DESC LIMIT %d",
            $status,
            $limit
        ), ARRAY_A);
    }
    
    /**
     * Approve a content item (move to PENDING)
     */
    public static function approve_content($content_id, $generate_full_post = true) {
        global $wpdb;
        
        $content_table = $wpdb->prefix . 'rawwire_eq_content';
        $pending_table = $wpdb->prefix . 'rawwire_pending';
        
        // Get the content item
        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$content_table} WHERE id = %d",
            $content_id
        ), ARRAY_A);
        
        if (!$item) {
            return array('success' => false, 'message' => 'Content not found');
        }
        
        // Generate full post content if requested
        $generated_content = $item['processed_content'];
        $ai_model = 'none';
        $generation_prompt = '';
        
        if ($generate_full_post) {
            $gen_result = self::generate_full_post($item);
            if ($gen_result['success']) {
                $generated_content = $gen_result['content'];
                $ai_model = $gen_result['model'] ?? 'ai-engine';
                $generation_prompt = $gen_result['prompt'] ?? '';
            }
        }
        
        // Insert into PENDING table
        $pending_data = array(
            'content_id' => $content_id,
            'title' => $item['title'],
            'generated_content' => $generated_content,
            'category' => $item['category'],
            'tags' => $item['tags'],
            'post_type' => 'post',
            'post_status' => 'draft',
            'source' => $item['source'],
            'source_url' => $item['url'],
            'ai_model' => $ai_model,
            'generation_prompt' => $generation_prompt,
            'status' => 'pending_review',
            'created_at' => current_time('mysql'),
            'generated_at' => current_time('mysql'),
        );
        
        $wpdb->insert($pending_table, $pending_data);
        $pending_id = $wpdb->insert_id;
        
        // Update content status
        $wpdb->update($content_table, array('status' => 'approved'), array('id' => $content_id));
        
        self::log("Content #{$content_id} approved → Pending #{$pending_id}", 'info');
        
        return array(
            'success' => true,
            'pending_id' => $pending_id,
            'title' => $pending_data['title'],
        );
    }
    
    /**
     * Reject a content item
     */
    public static function reject_content($content_id, $reason = '') {
        global $wpdb;
        
        $content_table = $wpdb->prefix . 'rawwire_eq_content';
        
        $wpdb->update(
            $content_table,
            array(
                'status' => 'rejected',
                'processed_at' => current_time('mysql'),
            ),
            array('id' => $content_id)
        );
        
        self::log("Content #{$content_id} rejected: {$reason}", 'info');
        
        return array('success' => true);
    }
    
    /**
     * Generate full post content from condensed version
     */
    private static function generate_full_post($item) {
        $template = get_option('rawwire_post_template', 'standard');
        
        $prompt = self::build_post_generation_prompt($item, $template);
        
        // Check for AI Engine
        if (class_exists('Meow_MWAI_Core')) {
            $result = self::call_ai_engine($prompt);
            if ($result['success']) {
                return array(
                    'success' => true,
                    'content' => $result['content'] ?? $item['processed_content'],
                    'model' => 'ai-engine',
                    'prompt' => $prompt,
                );
            }
        }
        
        // Check for direct API
        $api_key = get_option('rawwire_openai_api_key', '');
        if (!empty($api_key)) {
            $result = self::call_openai_direct($prompt, $api_key);
            if ($result['success']) {
                return array(
                    'success' => true,
                    'content' => $result['content'] ?? $item['processed_content'],
                    'model' => get_option('rawwire_ai_model', 'gpt-4o-mini'),
                    'prompt' => $prompt,
                );
            }
        }
        
        // Return original content if no AI available
        return array(
            'success' => true,
            'content' => $item['processed_content'],
            'model' => 'none',
            'prompt' => '',
        );
    }
    
    /**
     * Build post generation prompt based on template type
     */
    private static function build_post_generation_prompt($item, $template_type) {
        $word_count = get_option('rawwire_word_count', 800);
        $tone = get_option('rawwire_post_tone', 'professional');
        
        $templates = array(
            'standard' => "Write a {$word_count}-word blog post in a {$tone} tone based on this content:\n\n{{content}}\n\nInclude:\n- Engaging introduction\n- Key points with supporting details\n- Conclusion with takeaways\n- Attribution to source: {{source}}",
            
            'listicle' => "Create a listicle blog post (Top 5-10 format) in a {$tone} tone based on this content:\n\n{{content}}\n\nFormat as numbered list with descriptions for each point. Target {$word_count} words. Source: {{source}}",
            
            'howto' => "Write a how-to guide in a {$tone} tone based on this content:\n\n{{content}}\n\nInclude step-by-step instructions, tips, and practical advice. Target {$word_count} words. Source: {{source}}",
            
            'news' => "Write a news article summary in a {$tone} tone based on this content:\n\n{{content}}\n\nFollow inverted pyramid style: most important info first. Target 300-400 words. Source: {{source}}",
            
            'deep_dive' => "Write an in-depth analysis article in a {$tone} tone based on this content:\n\n{{content}}\n\nInclude background context, expert analysis, and implications. Target {$word_count} words or more. Source: {{source}}",
        );
        
        $prompt_template = $templates[$template_type] ?? $templates['standard'];
        
        return str_replace(
            array('{{content}}', '{{source}}'),
            array($item['processed_content'], $item['source']),
            $prompt_template
        );
    }
    
    // =========================================================================
    // STEP 4: PENDING → POSTED (Publishing)
    // =========================================================================
    
    /**
     * Get pending items for final review
     */
    public static function get_pending_for_review($limit = 20) {
        global $wpdb;
        
        $pending_table = $wpdb->prefix . 'rawwire_pending';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$pending_table} WHERE status = 'pending_review' ORDER BY created_at DESC LIMIT %d",
            $limit
        ), ARRAY_A);
    }
    
    /**
     * Publish a pending item to WordPress and social media
     */
    public static function publish_post($pending_id, $options = array()) {
        global $wpdb;
        
        $pending_table = $wpdb->prefix . 'rawwire_pending';
        $posted_table = $wpdb->prefix . 'rawwire_posted';
        
        // Get the pending item
        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$pending_table} WHERE id = %d",
            $pending_id
        ), ARRAY_A);
        
        if (!$item) {
            return array('success' => false, 'message' => 'Pending item not found');
        }
        
        // Create WordPress post
        $post_data = array(
            'post_title' => $item['title'],
            'post_content' => $item['generated_content'],
            'post_status' => $options['post_status'] ?? 'publish',
            'post_type' => $item['post_type'] ?? 'post',
            'post_category' => self::get_or_create_category($item['category']),
            'tags_input' => explode(',', $item['tags']),
        );
        
        $wp_post_id = wp_insert_post($post_data);
        
        if (is_wp_error($wp_post_id)) {
            return array('success' => false, 'message' => $wp_post_id->get_error_message());
        }
        
        // Store source attribution in post meta
        update_post_meta($wp_post_id, '_rawwire_source', $item['source']);
        update_post_meta($wp_post_id, '_rawwire_source_url', $item['source_url']);
        update_post_meta($wp_post_id, '_rawwire_pending_id', $pending_id);
        
        // Insert into POSTED table
        $posted_data = array(
            'pending_id' => $pending_id,
            'wp_post_id' => $wp_post_id,
            'title' => $item['title'],
            'post_url' => get_permalink($wp_post_id),
            'category' => $item['category'],
            'tags' => $item['tags'],
            'source' => $item['source'],
            'source_url' => $item['source_url'],
            'published_at' => current_time('mysql'),
            'status' => 'published',
            'created_at' => current_time('mysql'),
        );
        
        $wpdb->insert($posted_table, $posted_data);
        $posted_id = $wpdb->insert_id;
        
        // Update pending status
        $wpdb->update($pending_table, array('status' => 'published'), array('id' => $pending_id));
        
        // Publish to social media if enabled
        if ($options['publish_social'] ?? false) {
            self::publish_to_social($posted_id, $item);
        }
        
        self::log("Published Pending #{$pending_id} → WP Post #{$wp_post_id}", 'info');
        
        return array(
            'success' => true,
            'posted_id' => $posted_id,
            'wp_post_id' => $wp_post_id,
            'post_url' => get_permalink($wp_post_id),
        );
    }
    
    /**
     * Publish to social media platforms
     */
    private static function publish_to_social($posted_id, $item) {
        global $wpdb;
        
        $posted_table = $wpdb->prefix . 'rawwire_posted';
        $social_platforms = get_option('rawwire_social_platforms', array());
        
        if (empty($social_platforms)) {
            return;
        }
        
        // Generate social media formatted content
        $social_content = self::generate_social_content($item);
        
        $published_to = array();
        
        foreach ($social_platforms as $platform => $config) {
            if (!($config['enabled'] ?? false)) {
                continue;
            }
            
            $result = self::post_to_platform($platform, $social_content, $item, $config);
            
            if ($result['success']) {
                $published_to[] = $platform;
            }
        }
        
        // Update posted record with social publish info
        if (!empty($published_to)) {
            $posted_item = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$posted_table} WHERE id = %d",
                $posted_id
            ), ARRAY_A);
            
            // Store social publish info in a meta field or separate table
            update_post_meta($posted_item['wp_post_id'], '_rawwire_social_published', $published_to);
            
            self::log("Published to social: " . implode(', ', $published_to), 'info');
        }
    }
    
    /**
     * Generate social media formatted content
     */
    private static function generate_social_content($item) {
        $prompt = <<<PROMPT
Create social media posts for the following content:

Title: {$item['title']}
Content: {$item['generated_content']}
Source: {$item['source']}

Generate:
1. Twitter/X post (280 characters max, include relevant hashtags)
2. LinkedIn post (professional tone, 1-2 paragraphs)
3. Facebook post (engaging, 1-2 paragraphs)

Format as JSON:
{
    "twitter": "...",
    "linkedin": "...",
    "facebook": "..."
}
PROMPT;
        
        // Try AI processing
        if (class_exists('Meow_MWAI_Core')) {
            $result = self::call_ai_engine($prompt);
            if ($result['success']) {
                $parsed = json_decode($result['content'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $parsed;
                }
            }
        }
        
        // Fallback: manual formatting
        $title = wp_trim_words($item['title'], 15);
        $excerpt = wp_trim_words(strip_tags($item['generated_content']), 30);
        
        return array(
            'twitter' => $title . ' ' . ($item['source_url'] ?? '') . ' #news',
            'linkedin' => $title . "\n\n" . $excerpt . "\n\nRead more: " . ($item['source_url'] ?? ''),
            'facebook' => $title . "\n\n" . $excerpt,
        );
    }
    
    /**
     * Post to a specific social platform
     */
    private static function post_to_platform($platform, $content, $item, $config) {
        // This is a placeholder - integrate with actual social APIs
        // Consider using plugins like Social Auto Poster, Jetpack Social, etc.
        
        switch ($platform) {
            case 'twitter':
                // Twitter/X API integration
                return array('success' => false, 'message' => 'Twitter integration not configured');
                
            case 'linkedin':
                // LinkedIn API integration
                return array('success' => false, 'message' => 'LinkedIn integration not configured');
                
            case 'facebook':
                // Facebook API integration
                return array('success' => false, 'message' => 'Facebook integration not configured');
                
            default:
                return array('success' => false, 'message' => 'Unknown platform');
        }
    }
    
    /**
     * Get or create category by name
     */
    private static function get_or_create_category($category_name) {
        if (empty($category_name) || $category_name === 'general') {
            return array(get_option('default_category'));
        }
        
        $term = get_term_by('name', $category_name, 'category');
        
        if ($term) {
            return array($term->term_id);
        }
        
        // Create the category
        $result = wp_insert_term($category_name, 'category');
        
        if (!is_wp_error($result)) {
            return array($result['term_id']);
        }
        
        return array(get_option('default_category'));
    }
    
    /**
     * Publish approved items in batch
     */
    public static function publish_approved_batch($limit = 5) {
        global $wpdb;
        
        $pending_table = $wpdb->prefix . 'rawwire_pending';
        
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM {$pending_table} WHERE status = 'approved' ORDER BY created_at ASC LIMIT %d",
            $limit
        ));
        
        $published = 0;
        
        foreach ($items as $item) {
            $result = self::publish_post($item->id);
            
            if ($result['success']) {
                $published++;
            }
        }
        
        return $published;
    }
    
    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================
    
    /**
     * Verify AJAX nonce
     */
    private static function verify_ajax() {
        if (!check_ajax_referer('rawwire_template_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
    }
    
    /**
     * AJAX: Run full workflow
     */
    public static function ajax_run_workflow() {
        self::verify_ajax();
        
        $workflow = sanitize_text_field($_POST['workflow'] ?? 'post_generator');
        $step = sanitize_text_field($_POST['step'] ?? 'all');
        
        $results = array();
        
        switch ($step) {
            case 'crawl':
                // Trigger Crawlomatic or import existing
                $results['imported'] = self::import_from_crawlomatic(null, 20);
                break;
                
            case 'process':
                $batch = self::process_crdata_batch(10);
                $results = $batch;
                break;
                
            case 'all':
                $results['imported'] = self::import_from_crawlomatic(null, 20);
                $results['processed'] = self::process_crdata_batch(10);
                break;
                
            default:
                wp_send_json_error(array('message' => 'Invalid step'));
                return;
        }
        
        wp_send_json_success($results);
    }
    
    /**
     * AJAX: Process CR DATA items
     */
    public static function ajax_process_crdata() {
        self::verify_ajax();
        
        $crdata_id = intval($_POST['crdata_id'] ?? 0);
        $batch = isset($_POST['batch']) && $_POST['batch'];
        
        if ($batch) {
            $limit = intval($_POST['limit'] ?? 10);
            $result = self::process_crdata_batch($limit);
        } elseif ($crdata_id) {
            $result = self::process_crdata_item($crdata_id);
        } else {
            wp_send_json_error(array('message' => 'No item or batch specified'));
            return;
        }
        
        if ($result['success'] ?? isset($result['processed'])) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * AJAX: Generate content from approved item
     */
    public static function ajax_generate_content() {
        self::verify_ajax();
        
        $content_id = intval($_POST['content_id'] ?? 0);
        
        if (!$content_id) {
            wp_send_json_error(array('message' => 'Invalid content ID'));
        }
        
        $result = self::approve_content($content_id, true);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * AJAX: Approve content item
     */
    public static function ajax_approve_content() {
        self::verify_ajax();
        
        $content_id = intval($_POST['content_id'] ?? 0);
        $action = sanitize_text_field($_POST['action_type'] ?? 'approve');
        $reason = sanitize_text_field($_POST['reason'] ?? '');
        
        if (!$content_id) {
            wp_send_json_error(array('message' => 'Invalid content ID'));
        }
        
        if ($action === 'reject') {
            $result = self::reject_content($content_id, $reason);
        } else {
            $generate = isset($_POST['generate']) && $_POST['generate'];
            $result = self::approve_content($content_id, $generate);
        }
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * AJAX: Publish pending post
     */
    public static function ajax_publish_post() {
        self::verify_ajax();
        
        $pending_id = intval($_POST['pending_id'] ?? 0);
        
        if (!$pending_id) {
            wp_send_json_error(array('message' => 'Invalid pending ID'));
        }
        
        $options = array(
            'post_status' => sanitize_text_field($_POST['post_status'] ?? 'publish'),
            'publish_social' => isset($_POST['publish_social']) && $_POST['publish_social'],
        );
        
        $result = self::publish_post($pending_id, $options);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * AJAX: Bulk approve content
     */
    public static function ajax_bulk_approve() {
        self::verify_ajax();
        
        $content_ids = isset($_POST['content_ids']) ? array_map('intval', $_POST['content_ids']) : array();
        $action = sanitize_text_field($_POST['action_type'] ?? 'approve');
        
        if (empty($content_ids)) {
            wp_send_json_error(array('message' => 'No items selected'));
        }
        
        $processed = 0;
        $errors = 0;
        
        foreach ($content_ids as $id) {
            if ($action === 'reject') {
                $result = self::reject_content($id);
            } else {
                $result = self::approve_content($id, true);
            }
            
            if ($result['success']) {
                $processed++;
            } else {
                $errors++;
            }
        }
        
        wp_send_json_success(array(
            'processed' => $processed,
            'errors' => $errors,
        ));
    }
    
    /**
     * AJAX: Get content preview
     */
    public static function ajax_get_content_preview() {
        self::verify_ajax();
        
        $table = sanitize_text_field($_POST['table'] ?? 'content');
        $item_id = intval($_POST['item_id'] ?? 0);
        
        if (!$item_id) {
            wp_send_json_error(array('message' => 'Invalid item ID'));
        }
        
        global $wpdb;
        
        $table_map = array(
            'crdata' => $wpdb->prefix . 'rawwire_crdata',
            'content' => $wpdb->prefix . 'rawwire_eq_content',
            'pending' => $wpdb->prefix . 'rawwire_pending',
            'posted' => $wpdb->prefix . 'rawwire_posted',
        );
        
        $table_name = $table_map[$table] ?? $table_map['content'];
        
        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $item_id
        ), ARRAY_A);
        
        if (!$item) {
            wp_send_json_error(array('message' => 'Item not found'));
        }
        
        wp_send_json_success($item);
    }
}

// Initialize the workflow processor
add_action('init', array('RawWire\Dashboard\Services\Equalizer_Workflow', 'init'));
