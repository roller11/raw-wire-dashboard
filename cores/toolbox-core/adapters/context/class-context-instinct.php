<?php

/**
 * Instinct Context Adapter
 * 
 * Integration with Context Engine's Instinct priority context system.
 * Provides weighted memory storage, priority retrieval, and semantic search.
 * 
 * Features:
 *   - Priority scoring (0-100)
 *   - Mandatory guardrails (score >= 95)
 *   - Semantic search via ChromaDB
 *   - REST API communication
 * 
 * @package    RawWire_Dashboard
 * @subpackage Toolbox_Core
 * @since      1.0.25
 * @see        https://github.com/roller11/context-engine
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__) . '/class-adapter-base.php';

class RawWire_Adapter_Context_Instinct extends RawWire_Adapter_Base
{
    protected $name = 'Instinct';
    protected $version = '1.0.0';
    protected $tier = 'local';
    protected $capabilities = array(
        'context_retrieval',
        'memory_storage',
        'semantic_search',
        'priority_scoring',
        'mandatory_guardrails',
    );
    protected $required_fields = array();

    /**
     * Default API configuration
     */
    const DEFAULT_HOST = '127.0.0.1';
    const DEFAULT_PORT = 8080;
    const DEFAULT_TIMEOUT = 10;

    /**
     * Get base URL for Instinct API
     *
     * @return string
     */
    private function get_base_url()
    {
        $host = $this->get_config('host', self::DEFAULT_HOST);
        $port = $this->get_config('port', self::DEFAULT_PORT);
        return "http://{$host}:{$port}";
    }

    /**
     * Make HTTP request to Instinct API
     *
     * @param string $endpoint API endpoint (e.g., '/context')
     * @param string $method HTTP method (GET, POST)
     * @param array $data Request data for POST
     * @return array|WP_Error
     */
    private function api_request($endpoint, $method = 'GET', $data = array())
    {
        $url = $this->get_base_url() . $endpoint;
        $timeout = $this->get_config('timeout', self::DEFAULT_TIMEOUT);

        $args = array(
            'timeout' => $timeout,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ),
        );

        if ($method === 'POST') {
            $args['method'] = 'POST';
            $args['body'] = wp_json_encode($data);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $this->log('Instinct API error: ' . $response->get_error_message(), 'error', array(
                'endpoint' => $endpoint,
            ));
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 400) {
            $error_msg = $body['detail'] ?? $body['error'] ?? "HTTP {$code}";
            $this->log("Instinct API HTTP {$code}: {$error_msg}", 'error', array(
                'endpoint' => $endpoint,
            ));
            return new WP_Error('instinct_api_error', $error_msg, array('code' => $code));
        }

        return $body;
    }

    // =========================================================================
    // CONNECTION
    // =========================================================================

    /**
     * Test connection to Instinct service
     *
     * @return array{success: bool, message: string, details?: array}
     */
    public function test_connection()
    {
        $response = $this->api_request('/health');

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => 'Instinct service not reachable: ' . $response->get_error_message(),
            );
        }

        if (($response['status'] ?? '') === 'healthy') {
            return array(
                'success' => true,
                'message' => 'Instinct context service connected',
                'details' => array(
                    'store_available' => $response['store_available'] ?? false,
                    'service' => $response['service'] ?? 'instinct',
                ),
            );
        }

        return array(
            'success' => false,
            'message' => 'Instinct service unhealthy',
        );
    }

    /**
     * Check if Instinct service is available
     *
     * @return bool
     */
    public function is_available()
    {
        $result = $this->test_connection();
        return $result['success'] ?? false;
    }

    // =========================================================================
    // CONTEXT RETRIEVAL
    // =========================================================================

    /**
     * Get context for a query
     *
     * Retrieves prioritized context from Instinct memory store.
     * Returns mandatory items (score >= 95) plus relevant items.
     *
     * @param string $query Query to find relevant context for
     * @param array $options {
     *     @type int    $max_tokens     Maximum tokens to return (default: 8000)
     *     @type int    $min_importance Minimum importance score 0-100 (default: 30)
     *     @type bool   $include_mandatory Include mandatory items (default: true)
     * }
     * @return array{success: bool, context?: string, mandatory_count?: int, relevant_count?: int, error?: string}
     */
    public function get_context($query, $options = array())
    {
        $data = array(
            'query' => $query,
            'max_tokens' => $options['max_tokens'] ?? 8000,
            'min_importance' => $options['min_importance'] ?? 30,
            'include_mandatory' => $options['include_mandatory'] ?? true,
        );

        $response = $this->api_request('/context', 'POST', $data);

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message(),
            );
        }

        return array(
            'success' => true,
            'context' => $response['context'] ?? '',
            'mandatory_count' => $response['mandatory_count'] ?? 0,
            'relevant_count' => $response['relevant_count'] ?? 0,
            'total_tokens' => $response['total_tokens'] ?? 0,
        );
    }

    /**
     * Get mandatory context only
     *
     * Returns only items with score >= 95 (mandatory guardrails).
     *
     * @return array{success: bool, context?: string, count?: int, error?: string}
     */
    public function get_mandatory_context()
    {
        $response = $this->api_request('/context/mandatory');

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message(),
            );
        }

        $segments = $response['segments'] ?? array();
        $context_parts = array();
        foreach ($segments as $seg) {
            $title = (string) ($seg['title'] ?? '');
            $content = (string) ($seg['content'] ?? '');
            $score = (int) ($seg['importance_score'] ?? 100);
            $context_parts[] = "[{$score}] {$title}\n{$content}";
        }

        return array(
            'success' => true,
            'context' => implode("\n\n---\n\n", $context_parts),
            'count' => $response['count'] ?? 0,
        );
    }

    // =========================================================================
    // MEMORY STORAGE
    // =========================================================================

    /**
     * Save a memory segment
     *
     * Stores a piece of context with an importance score.
     *
     * @param string $content The content to store
     * @param string $title Brief title/label for the segment
     * @param array $options {
     *     @type int    $importance_score Score 0-100 (default: 70)
     *     @type string $segment_type     Type: context, standing_instruction, 
     *                                    architectural_decision, etc. (default: context)
     *     @type string $source           Source identifier (default: wordpress)
     * }
     * @return array{success: bool, id?: string, error?: string}
     */
    public function save_memory($content, $title, $options = array())
    {
        $data = array(
            'content' => $content,
            'concept_title' => $title,
            'importance_score' => $options['importance_score'] ?? 70,
            'segment_type' => $options['segment_type'] ?? 'context',
            'source' => $options['source'] ?? 'wordpress',
        );

        $response = $this->api_request('/segments', 'POST', $data);

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message(),
            );
        }

        $this->log('Memory saved: ' . $title, 'info', array(
            'importance' => $data['importance_score'],
            'type' => $data['segment_type'],
        ));

        return array(
            'success' => true,
            'id' => $response['id'] ?? null,
        );
    }

    /**
     * Save a standing instruction (mandatory context)
     *
     * Convenience method that saves with score 100 as standing_instruction type.
     *
     * @param string $content Instruction content
     * @param string $title Brief title
     * @return array
     */
    public function save_standing_instruction($content, $title)
    {
        return $this->save_memory($content, $title, array(
            'importance_score' => 100,
            'segment_type' => 'standing_instruction',
        ));
    }

    /**
     * Save an architectural decision
     *
     * Stores high-priority architectural context.
     *
     * @param string $content Decision content
     * @param string $title Brief title
     * @param int $importance Score 0-100 (default: 90)
     * @return array
     */
    public function save_architectural_decision($content, $title, $importance = 90)
    {
        return $this->save_memory($content, $title, array(
            'importance_score' => $importance,
            'segment_type' => 'architectural_decision',
        ));
    }

    // =========================================================================
    // SEARCH
    // =========================================================================

    /**
     * Search stored memories
     *
     * Semantic search across all stored context.
     *
     * @param string $query Search query
     * @param array $options {
     *     @type int    $limit       Max results (default: 10)
     *     @type int    $min_score   Minimum importance score (default: 0)
     *     @type string $segment_type Filter by type (optional)
     * }
     * @return array{success: bool, results?: array, error?: string}
     */
    public function search($query, $options = array())
    {
        $data = array(
            'query' => $query,
            'limit' => $options['limit'] ?? 10,
            'min_score' => $options['min_score'] ?? 0,
        );

        if (!empty($options['segment_type'])) {
            $data['segment_type'] = $options['segment_type'];
        }

        $response = $this->api_request('/search', 'POST', $data);

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message(),
            );
        }

        return array(
            'success' => true,
            'results' => $response['segments'] ?? array(),
        );
    }

    // =========================================================================
    // STATISTICS
    // =========================================================================

    /**
     * Get store statistics
     *
     * @return array{success: bool, stats?: array, error?: string}
     */
    public function get_stats()
    {
        $response = $this->api_request('/stats');

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message(),
            );
        }

        return array(
            'success' => true,
            'stats' => $response,
        );
    }

    // =========================================================================
    // BULK OPERATIONS
    // =========================================================================

    /**
     * Bulk inject segments
     *
     * @param array $segments Array of segment data arrays
     * @return array{success: bool, count?: int, error?: string}
     */
    public function bulk_inject($segments)
    {
        $data = array('segments' => $segments);

        $response = $this->api_request('/segments/bulk', 'POST', $data);

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message(),
            );
        }

        $this->log('Bulk inject completed', 'info', array(
            'count' => $response['created'] ?? count($segments),
        ));

        return array(
            'success' => true,
            'count' => $response['created'] ?? count($segments),
        );
    }
}
