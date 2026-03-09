<?php
/**
 * AI Memory Manager
 * 
 * Provides long-term memory storage for AI chatbots using local database.
 * Supports semantic search via embeddings for memory retrieval.
 * 
 * Memory Types:
 * - Facts: User-provided information ("My name is John", "I prefer dark mode")
 * - Preferences: Learned behaviors and settings
 * - Context: Important conversation summaries
 * - Tasks: Ongoing tasks and their status
 * 
 * @package RawWire\Dashboard\Cores\ToolboxCore\Features
 * @since 1.0.22
 */

if (!defined('ABSPATH')) {
    exit;
}

class RawWire_AI_Memory {

    /**
     * Singleton instance
     * @var RawWire_AI_Memory|null
     */
    private static $instance = null;

    /**
     * Database table name
     * @var string
     */
    private $table_name;

    /**
     * Memory types
     */
    const TYPE_FACT = 'fact';
    const TYPE_PREFERENCE = 'preference';
    const TYPE_CONTEXT = 'context';
    const TYPE_TASK = 'task';
    const TYPE_ENTITY = 'entity';

    /**
     * Maximum memories to inject into context
     */
    const MAX_CONTEXT_MEMORIES = 15;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'rawwire_ai_memory';
        
        // Create table on init
        add_action('init', [$this, 'create_table']);
        
        // Hook into AI queries to inject relevant memories
        add_filter('mwai_ai_query', [$this, 'inject_memories'], 5, 2);
        
        // Hook into AI replies to extract and store memories
        add_filter('mwai_ai_reply', [$this, 'process_reply_for_memories'], 10, 3);
        
        // Register REST endpoints for memory management
        add_action('rest_api_init', [$this, 'register_routes']);
        
        // Register memory extraction as a tool
        add_filter('mwai_chatbot_params', [$this, 'add_memory_functions'], 10, 2);
    }

    /**
     * Create memory table
     */
    public function create_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            memory_type VARCHAR(50) NOT NULL DEFAULT 'fact',
            content TEXT NOT NULL,
            embedding LONGTEXT NULL,
            importance TINYINT(3) UNSIGNED NOT NULL DEFAULT 5,
            access_count INT(11) UNSIGNED NOT NULL DEFAULT 0,
            last_accessed DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            expires_at DATETIME NULL,
            metadata JSON NULL,
            PRIMARY KEY (id),
            KEY user_type (user_id, memory_type),
            KEY importance (importance DESC),
            KEY last_accessed (last_accessed DESC),
            KEY expires_at (expires_at),
            FULLTEXT KEY content_search (content)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Store a memory
     * 
     * @param string $content The memory content
     * @param string $type Memory type (fact, preference, context, task, entity)
     * @param int $importance 1-10 scale
     * @param array $metadata Additional metadata
     * @param int|null $user_id User ID (null for current user)
     * @return int|false Memory ID or false on failure
     */
    public function store($content, $type = self::TYPE_FACT, $importance = 5, $metadata = [], $user_id = null) {
        global $wpdb;
        
        if (empty($content)) {
            return false;
        }
        
        $user_id = $user_id ?? get_current_user_id();
        
        // Check for duplicate/similar memory
        $existing = $this->find_similar($content, $user_id, $type);
        if ($existing) {
            // Update existing memory instead
            return $this->update($existing->id, [
                'content' => $content,
                'importance' => max($existing->importance, $importance),
                'access_count' => $existing->access_count + 1,
            ]);
        }
        
        // Generate embedding if AI adapter is available
        $embedding = $this->generate_embedding($content);
        
        $result = $wpdb->insert(
            $this->table_name,
            [
                'user_id' => $user_id,
                'memory_type' => $type,
                'content' => $content,
                'embedding' => $embedding ? wp_json_encode($embedding) : null,
                'importance' => min(10, max(1, $importance)),
                'metadata' => wp_json_encode($metadata),
            ],
            ['%d', '%s', '%s', '%s', '%d', '%s']
        );
        
        if ($result) {
            do_action('rawwire_memory_stored', $wpdb->insert_id, $content, $type);
            return $wpdb->insert_id;
        }
        
        return false;
    }

    /**
     * Find similar existing memory
     */
    private function find_similar($content, $user_id, $type) {
        global $wpdb;
        
        // Simple similarity check using LIKE for now
        // In production, use embedding similarity
        $words = array_slice(explode(' ', $content), 0, 5);
        $search_terms = implode('%', $words);
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             WHERE user_id = %d 
             AND memory_type = %s 
             AND content LIKE %s
             LIMIT 1",
            $user_id,
            $type,
            '%' . $wpdb->esc_like($search_terms) . '%'
        ));
    }

    /**
     * Update a memory
     */
    public function update($id, $data) {
        global $wpdb;
        
        $allowed = ['content', 'importance', 'access_count', 'metadata', 'expires_at'];
        $update_data = array_intersect_key($data, array_flip($allowed));
        
        if (isset($update_data['metadata']) && is_array($update_data['metadata'])) {
            $update_data['metadata'] = wp_json_encode($update_data['metadata']);
        }
        
        // Regenerate embedding if content changed
        if (isset($update_data['content'])) {
            $embedding = $this->generate_embedding($update_data['content']);
            if ($embedding) {
                $update_data['embedding'] = wp_json_encode($embedding);
            }
        }
        
        return $wpdb->update($this->table_name, $update_data, ['id' => $id]);
    }

    /**
     * Delete a memory
     */
    public function delete($id) {
        global $wpdb;
        return $wpdb->delete($this->table_name, ['id' => $id], ['%d']);
    }

    /**
     * Retrieve memories for a user
     * 
     * @param int|null $user_id
     * @param array $args Query arguments
     * @return array
     */
    public function get_memories($user_id = null, $args = []) {
        global $wpdb;
        
        $user_id = $user_id ?? get_current_user_id();
        
        $defaults = [
            'type' => null,
            'limit' => 50,
            'offset' => 0,
            'orderby' => 'importance',
            'order' => 'DESC',
            'search' => null,
            'include_expired' => false,
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        $where = ["user_id = %d"];
        $params = [$user_id];
        
        if ($args['type']) {
            $where[] = "memory_type = %s";
            $params[] = $args['type'];
        }
        
        if (!$args['include_expired']) {
            $where[] = "(expires_at IS NULL OR expires_at > NOW())";
        }
        
        if ($args['search']) {
            $where[] = "MATCH(content) AGAINST(%s IN NATURAL LANGUAGE MODE)";
            $params[] = $args['search'];
        }
        
        $where_sql = implode(' AND ', $where);
        $order_sql = sanitize_sql_orderby("{$args['orderby']} {$args['order']}") ?: 'importance DESC';
        
        $params[] = $args['limit'];
        $params[] = $args['offset'];
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             WHERE {$where_sql} 
             ORDER BY {$order_sql} 
             LIMIT %d OFFSET %d",
            $params
        ));
    }

    /**
     * Search memories by semantic similarity
     * 
     * @param string $query Search query
     * @param int|null $user_id
     * @param int $limit
     * @return array
     */
    public function search($query, $user_id = null, $limit = 10) {
        global $wpdb;
        
        $user_id = $user_id ?? get_current_user_id();
        
        // Try embedding-based search first
        $query_embedding = $this->generate_embedding($query);
        
        if ($query_embedding) {
            // Get all memories with embeddings
            $memories = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->table_name} 
                 WHERE user_id = %d 
                 AND embedding IS NOT NULL
                 AND (expires_at IS NULL OR expires_at > NOW())",
                $user_id
            ));
            
            // Calculate cosine similarity
            $scored = [];
            foreach ($memories as $memory) {
                $mem_embedding = json_decode($memory->embedding, true);
                if ($mem_embedding) {
                    $similarity = $this->cosine_similarity($query_embedding, $mem_embedding);
                    $memory->similarity = $similarity;
                    $scored[] = $memory;
                }
            }
            
            // Sort by similarity
            usort($scored, function($a, $b) {
                return $b->similarity <=> $a->similarity;
            });
            
            return array_slice($scored, 0, $limit);
        }
        
        // Fallback to fulltext search
        return $wpdb->get_results($wpdb->prepare(
            "SELECT *, MATCH(content) AGAINST(%s IN NATURAL LANGUAGE MODE) as relevance
             FROM {$this->table_name} 
             WHERE user_id = %d 
             AND MATCH(content) AGAINST(%s IN NATURAL LANGUAGE MODE)
             AND (expires_at IS NULL OR expires_at > NOW())
             ORDER BY relevance DESC
             LIMIT %d",
            $query,
            $user_id,
            $query,
            $limit
        ));
    }

    /**
     * Calculate cosine similarity between two vectors
     */
    private function cosine_similarity($a, $b) {
        if (count($a) !== count($b)) {
            return 0;
        }
        
        $dot = 0;
        $mag_a = 0;
        $mag_b = 0;
        
        for ($i = 0; $i < count($a); $i++) {
            $dot += $a[$i] * $b[$i];
            $mag_a += $a[$i] * $a[$i];
            $mag_b += $b[$i] * $b[$i];
        }
        
        $magnitude = sqrt($mag_a) * sqrt($mag_b);
        
        return $magnitude > 0 ? $dot / $magnitude : 0;
    }

    /**
     * Generate embedding for content
     */
    private function generate_embedding($content) {
        // Use AI Engine's embedding if available
        global $mwai_core;
        
        if (!$mwai_core || !method_exists($mwai_core, 'run_query')) {
            return null;
        }
        
        try {
            $query = new Meow_MWAI_Query_Embed($content);
            $reply = $mwai_core->run_query($query);
            
            if ($reply && property_exists($reply, 'result')) {
                return $reply->result;
            }
        } catch (Exception $e) {
            error_log('[RawWire Memory] Embedding generation failed: ' . $e->getMessage());
        }
        
        return null;
    }

    /**
     * Inject relevant memories into AI query context
     */
    public function inject_memories($query, $params = []) {
        error_log('[RawWire Memory] inject_memories called');
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            error_log('[RawWire Memory] No user ID, skipping');
            return $query;
        }
        
        // Get the user's message
        $message = method_exists($query, 'get_message') ? $query->get_message() : '';
        
        if (empty($message)) {
            error_log('[RawWire Memory] No message found');
            return $query;
        }
        
        error_log('[RawWire Memory] Processing message: ' . substr($message, 0, 50));
        
        // Search for relevant memories
        $relevant_memories = $this->search($message, $user_id, self::MAX_CONTEXT_MEMORIES);
        
        // Also get high-importance memories
        $important_memories = $this->get_memories($user_id, [
            'limit' => 5,
            'orderby' => 'importance',
            'order' => 'DESC',
        ]);
        
        // Merge and dedupe
        $all_memories = array_merge($relevant_memories, $important_memories);
        $seen = [];
        $unique_memories = [];
        
        foreach ($all_memories as $memory) {
            if (!isset($seen[$memory->id])) {
                $seen[$memory->id] = true;
                $unique_memories[] = $memory;
            }
        }
        
        if (empty($unique_memories)) {
            error_log('[RawWire Memory] No memories found for user ' . $user_id);
            return $query;
        }
        
        error_log('[RawWire Memory] Found ' . count($unique_memories) . ' memories to inject');
        
        // Build memory context
        $memory_context = $this->format_memories_for_context($unique_memories);
        
        // Inject into instructions
        if (method_exists($query, 'set_instructions') && method_exists($query, 'get_instructions')) {
            $current = $query->get_instructions() ?: '';
            $enhanced = $memory_context . "\n\n" . $current;
            $query->set_instructions($enhanced);
            error_log('[RawWire Memory] Injected memories into instructions');
        } else {
            error_log('[RawWire Memory] Query does not support instructions injection');
        }
        
        // Update access counts
        foreach ($unique_memories as $memory) {
            $this->update($memory->id, [
                'access_count' => $memory->access_count + 1,
            ]);
            global $wpdb;
            $wpdb->update(
                $this->table_name,
                ['last_accessed' => current_time('mysql')],
                ['id' => $memory->id]
            );
        }
        
        return $query;
    }

    /**
     * Format memories for injection into context
     */
    private function format_memories_for_context($memories) {
        $lines = ["## User Memory (Things you remember about this user):\n"];
        
        $by_type = [];
        foreach ($memories as $memory) {
            $type = $memory->memory_type;
            if (!isset($by_type[$type])) {
                $by_type[$type] = [];
            }
            $by_type[$type][] = $memory->content;
        }
        
        $type_labels = [
            'fact' => 'Known Facts',
            'preference' => 'User Preferences', 
            'context' => 'Important Context',
            'task' => 'Ongoing Tasks',
            'entity' => 'Known Entities',
        ];
        
        foreach ($by_type as $type => $contents) {
            $label = $type_labels[$type] ?? ucfirst($type);
            $lines[] = "### {$label}:";
            foreach ($contents as $content) {
                $lines[] = "- {$content}";
            }
            $lines[] = "";
        }
        
        return implode("\n", $lines);
    }

    /**
     * Process AI reply to extract memories
     */
    public function process_reply_for_memories($reply, $query = null, $params = null) {
        // Check if memory extraction is enabled
        if (!apply_filters('rawwire_memory_auto_extract', true)) {
            return $reply;
        }
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            return $reply;
        }
        
        // Get the conversation
        $user_message = '';
        if ($query !== null) {
            $user_message = method_exists($query, 'get_message') ? $query->get_message() : '';
        }
        $ai_response = is_object($reply) && property_exists($reply, 'result') ? $reply->result : '';
        
        // Look for explicit memory storage patterns
        $this->extract_explicit_memories($user_message, $user_id);
        
        return $reply;
    }

    /**
     * Extract explicit memories from user message
     */
    private function extract_explicit_memories($message, $user_id) {
        // Patterns for facts/preferences
        $patterns = [
            // "My name is X"
            '/(?:my name is|i\'?m called|call me)\s+([a-z]+)/i' => [self::TYPE_FACT, 'User\'s name is: %s', 9],
            
            // "I prefer X"
            '/i (?:prefer|like|want|need)\s+(.+?)(?:\.|$)/i' => [self::TYPE_PREFERENCE, 'User prefers: %s', 6],
            
            // "Remember that X"
            '/(?:remember that|don\'?t forget|keep in mind)\s+(.+?)(?:\.|$)/i' => [self::TYPE_FACT, '%s', 8],
            
            // "I work at/for X"
            '/i (?:work at|work for|am employed by)\s+(.+?)(?:\.|$)/i' => [self::TYPE_FACT, 'User works at: %s', 7],
            
            // "I'm a X" (profession)
            '/i\'?m a(?:n)?\s+(developer|designer|manager|admin|owner|ceo|cto|engineer|marketer|writer|analyst)/i' => [self::TYPE_FACT, 'User is a: %s', 7],
        ];
        
        foreach ($patterns as $pattern => $config) {
            if (preg_match($pattern, $message, $matches)) {
                list($type, $template, $importance) = $config;
                $content = sprintf($template, trim($matches[1]));
                $this->store($content, $type, $importance, ['source' => 'auto_extract'], $user_id);
            }
        }
    }

    /**
     * Add memory functions to chatbot
     */
    public function add_memory_functions($params, $chatbot = null) {
        // This allows the AI to explicitly store memories via function calling
        if (!is_array($params)) {
            return $params;
        }
        
        if (!isset($params['functions'])) {
            $params['functions'] = [];
        }
        
        $params['functions'][] = [
            'name' => 'remember',
            'description' => 'Store something important about the user for future conversations',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'memory' => [
                        'type' => 'string',
                        'description' => 'The information to remember',
                    ],
                    'type' => [
                        'type' => 'string',
                        'enum' => ['fact', 'preference', 'task'],
                        'description' => 'Type of memory',
                    ],
                    'importance' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 10,
                        'description' => 'How important (1-10)',
                    ],
                ],
                'required' => ['memory'],
            ],
        ];
        
        return $params;
    }

    /**
     * Register REST API routes
     */
    public function register_routes() {
        register_rest_route('rawwire/v1', '/memory', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'rest_get_memories'],
                'permission_callback' => function() {
                    return current_user_can('edit_posts');
                },
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'rest_store_memory'],
                'permission_callback' => function() {
                    return current_user_can('edit_posts');
                },
            ],
        ]);
        
        register_rest_route('rawwire/v1', '/memory/(?P<id>\d+)', [
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'rest_delete_memory'],
                'permission_callback' => function() {
                    return current_user_can('edit_posts');
                },
            ],
        ]);
        
        register_rest_route('rawwire/v1', '/memory/search', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_search_memories'],
            'permission_callback' => function() {
                return current_user_can('edit_posts');
            },
        ]);
    }

    /**
     * REST: Get memories
     */
    public function rest_get_memories($request) {
        $memories = $this->get_memories(null, [
            'type' => $request->get_param('type'),
            'limit' => $request->get_param('limit') ?: 50,
            'search' => $request->get_param('search'),
        ]);
        
        return new WP_REST_Response(['memories' => $memories], 200);
    }

    /**
     * REST: Store memory
     */
    public function rest_store_memory($request) {
        $content = $request->get_param('content');
        $type = $request->get_param('type') ?: self::TYPE_FACT;
        $importance = $request->get_param('importance') ?: 5;
        
        if (empty($content)) {
            return new WP_Error('missing_content', 'Memory content is required', ['status' => 400]);
        }
        
        $id = $this->store($content, $type, $importance);
        
        if ($id) {
            return new WP_REST_Response(['id' => $id, 'success' => true], 201);
        }
        
        return new WP_Error('store_failed', 'Failed to store memory', ['status' => 500]);
    }

    /**
     * REST: Delete memory
     */
    public function rest_delete_memory($request) {
        $id = $request->get_param('id');
        $result = $this->delete($id);
        
        return new WP_REST_Response(['success' => (bool) $result], 200);
    }

    /**
     * REST: Search memories
     */
    public function rest_search_memories($request) {
        $query = $request->get_param('query');
        $limit = $request->get_param('limit') ?: 10;
        
        $results = $this->search($query, null, $limit);
        
        return new WP_REST_Response(['results' => $results], 200);
    }

    /**
     * Get memory statistics
     */
    public function get_stats($user_id = null) {
        global $wpdb;
        
        $user_id = $user_id ?? get_current_user_id();
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN memory_type = 'fact' THEN 1 ELSE 0 END) as facts,
                SUM(CASE WHEN memory_type = 'preference' THEN 1 ELSE 0 END) as preferences,
                SUM(CASE WHEN memory_type = 'context' THEN 1 ELSE 0 END) as contexts,
                SUM(CASE WHEN memory_type = 'task' THEN 1 ELSE 0 END) as tasks,
                AVG(importance) as avg_importance
             FROM {$this->table_name}
             WHERE user_id = %d
             AND (expires_at IS NULL OR expires_at > NOW())",
            $user_id
        ));
    }

    /**
     * Cleanup expired memories
     */
    public function cleanup_expired() {
        global $wpdb;
        
        return $wpdb->query(
            "DELETE FROM {$this->table_name} 
             WHERE expires_at IS NOT NULL 
             AND expires_at < NOW()"
        );
    }
}

// Initialize
add_action('plugins_loaded', function() {
    RawWire_AI_Memory::get_instance();
}, 15);
