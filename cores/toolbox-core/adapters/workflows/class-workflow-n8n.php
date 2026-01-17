<?php
/**
 * n8n Webhook Workflow Adapter (Value Tier)
 * Integrates with n8n workflow automation via webhooks.
 * 
 * @package RawWire_Dashboard
 * @subpackage Toolbox_Core
 */
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../class-adapter-base.php';
require_once dirname(dirname(__DIR__)) . '/interfaces/interface-workflow.php';

class RawWire_Adapter_Workflow_N8n extends RawWire_Adapter_Base implements RawWire_Workflow_Interface {
    
    protected $name = 'n8n Webhook';
    protected $version = '1.0.0';
    protected $tier = 'value';
    protected $capabilities = array('visual_workflow', 'integrations', 'error_handling', 'webhooks');
    protected $required_fields = array('webhook_url');

    /**
     * Progress callback
     * @var callable|null
     */
    private $progress_callback = null;

    /**
     * Test n8n connection
     */
    public function test_connection() {
        $validation = $this->validate_config();
        if (is_wp_error($validation)) {
            return array(
                'success' => false,
                'message' => $validation->get_error_message(),
            );
        }

        $webhook_url = $this->get_config('webhook_url');

        // Send test payload
        $args = array(
            'method' => 'POST',
            'timeout' => $this->get_config('timeout', 30),
            'headers' => $this->build_headers(),
            'body' => json_encode(array(
                'test' => true,
                'source' => 'rawwire',
                'timestamp' => current_time('mysql'),
            )),
        );

        $this->log('Testing n8n connection', 'info', array('webhook' => $this->mask_url($webhook_url)));

        $response = $this->http_request($webhook_url, $args);

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => 'n8n connection failed: ' . $response->get_error_message(),
            );
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code >= 200 && $code < 300) {
            $this->log('n8n connection test passed', 'info');
            return array(
                'success' => true,
                'message' => 'n8n webhook is responding',
                'details' => array(
                    'capabilities' => $this->capabilities,
                    'response_code' => $code,
                ),
            );
        }

        return array(
            'success' => false,
            'message' => "n8n webhook returned HTTP $code",
        );
    }

    /**
     * Build request headers
     */
    private function build_headers() {
        $headers = array(
            'Content-Type' => 'application/json',
            'User-Agent' => 'RawWire/1.0',
        );

        $auth = $this->get_config('auth_header');
        if (!empty($auth)) {
            // Support both "Bearer xxx" and just "xxx" format
            if (stripos($auth, 'Bearer ') !== 0 && stripos($auth, 'Basic ') !== 0) {
                $headers['Authorization'] = 'Bearer ' . $auth;
            } else {
                $headers['Authorization'] = $auth;
            }
        }

        return $headers;
    }

    /**
     * Mask URL for logging
     */
    private function mask_url(string $url) {
        $parts = parse_url($url);
        $path = $parts['path'] ?? '';
        // Mask last 8 chars of path
        if (strlen($path) > 16) {
            $path = substr($path, 0, -8) . '********';
        }
        return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . $path;
    }

    /**
     * Trigger n8n workflow
     */
    public function trigger(array $payload, array $options = array()) {
        $validation = $this->validate_config();
        if (is_wp_error($validation)) {
            return array('success' => false, 'error' => $validation->get_error_message());
        }

        $webhook_url = $this->get_config('webhook_url');
        $execution_id = wp_generate_uuid4();

        // Enrich payload
        $enriched_payload = array_merge($payload, array(
            '_meta' => array(
                'execution_id' => $execution_id,
                'source' => 'rawwire',
                'timestamp' => current_time('mysql'),
                'callback_url' => $options['callback_url'] ?? null,
            ),
        ));

        $args = array(
            'method' => 'POST',
            'timeout' => $this->get_config('timeout', 30),
            'headers' => $this->build_headers(),
            'body' => json_encode($enriched_payload),
        );

        $this->log('Triggering n8n workflow', 'info', array(
            'execution_id' => $execution_id,
            'payload_keys' => array_keys($payload),
        ));

        // Async mode - fire and forget
        if (!empty($options['async'])) {
            $args['blocking'] = false;
            $response = wp_remote_post($webhook_url, $args);

            return array(
                'success' => true,
                'execution_id' => $execution_id,
                'status' => 'triggered_async',
                'message' => 'Workflow triggered asynchronously',
            );
        }

        // Synchronous execution
        $response = $this->http_request($webhook_url, $args);

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'execution_id' => $execution_id,
                'error' => $response->get_error_message(),
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        if ($code >= 200 && $code < 300) {
            $this->log('n8n workflow completed', 'info', array('execution_id' => $execution_id));
            return array(
                'success' => true,
                'execution_id' => $execution_id,
                'result' => $result,
                'status' => 'completed',
            );
        }

        $this->log('n8n workflow failed', 'error', array(
            'execution_id' => $execution_id,
            'code' => $code,
        ));

        return array(
            'success' => false,
            'execution_id' => $execution_id,
            'error' => "Workflow returned HTTP $code",
            'details' => $result,
        );
    }

    /**
     * Get workflow status
     * Note: n8n doesn't have a standard status endpoint, this relies on callbacks
     */
    public function get_status(string $execution_id) {
        // Check transient for callback status
        $status = get_transient("rawwire_n8n_status_$execution_id");

        if ($status) {
            return $status;
        }

        return array(
            'status' => 'unknown',
            'progress' => 0,
            'note' => 'n8n status requires webhook callback. Configure your n8n workflow to POST status updates.',
        );
    }

    /**
     * Cancel workflow
     * Note: n8n doesn't support remote cancellation via webhook
     */
    public function cancel(string $execution_id) {
        $this->log('Cancel requested but n8n webhooks do not support remote cancellation', 'warning', array(
            'execution_id' => $execution_id,
        ));

        return false;
    }

    /**
     * Define steps - not applicable for n8n (steps are defined in n8n UI)
     */
    public function define_steps(array $steps) {
        $this->log('Steps defined but will be sent as workflow_steps in payload', 'debug');
        // Store steps to be sent as payload hint
        $this->config['workflow_steps'] = $steps;
        return true;
    }

    /**
     * Register progress callback
     */
    public function on_progress(callable $callback) {
        $this->progress_callback = $callback;

        // Register webhook endpoint for n8n to call back
        add_action('rest_api_init', function() {
            register_rest_route('rawwire/v1', '/n8n-callback', array(
                'methods' => 'POST',
                'callback' => array($this, 'handle_callback'),
                'permission_callback' => '__return_true',
            ));
        });
    }

    /**
     * Handle callback from n8n
     */
    public function handle_callback($request) {
        $data = $request->get_json_params();
        $execution_id = $data['execution_id'] ?? '';

        if (empty($execution_id)) {
            return new WP_REST_Response(array('error' => 'Missing execution_id'), 400);
        }

        // Store status
        set_transient("rawwire_n8n_status_$execution_id", array(
            'status' => $data['status'] ?? 'unknown',
            'progress' => $data['progress'] ?? 0,
            'result' => $data['result'] ?? null,
            'updated_at' => current_time('mysql'),
        ), HOUR_IN_SECONDS);

        // Call progress callback if set
        if (is_callable($this->progress_callback)) {
            call_user_func($this->progress_callback, $data);
        }

        $this->log('n8n callback received', 'debug', array(
            'execution_id' => $execution_id,
            'status' => $data['status'] ?? 'unknown',
        ));

        return new WP_REST_Response(array('success' => true), 200);
    }
}
