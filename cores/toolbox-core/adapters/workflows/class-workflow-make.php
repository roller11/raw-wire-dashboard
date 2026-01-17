<?php
/**
 * Make.com Workflow Adapter (Flagship Tier)
 * Enterprise integration with Make.com (formerly Integromat).
 * 
 * @package RawWire_Dashboard
 * @subpackage Toolbox_Core
 */
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../class-adapter-base.php';
require_once dirname(dirname(__DIR__)) . '/interfaces/interface-workflow.php';

class RawWire_Adapter_Workflow_Make extends RawWire_Adapter_Base implements RawWire_Workflow_Interface {
    
    protected $name = 'Make.com';
    protected $version = '1.0.0';
    protected $tier = 'flagship';
    protected $capabilities = array('visual_workflow', '1500_integrations', 'error_handling', 'scheduling', 'data_stores');
    protected $required_fields = array('api_key', 'team_id', 'scenario_id');

    const API_BASE = 'https://hook.us1.make.com';
    const API_V2_BASE = 'https://us1.make.com/api/v2';

    /**
     * Progress callback
     * @var callable|null
     */
    private $progress_callback = null;

    /**
     * Test Make.com connection
     */
    public function test_connection() {
        $validation = $this->validate_config();
        if (is_wp_error($validation)) {
            return array(
                'success' => false,
                'message' => $validation->get_error_message(),
            );
        }

        // Test API access by getting scenario info
        $scenario_id = $this->get_config('scenario_id');
        $url = self::API_V2_BASE . "/scenarios/{$scenario_id}";

        $response = $this->http_request($url, array(
            'headers' => $this->build_headers(),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => 'Make.com API connection failed: ' . $response->get_error_message(),
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200 && isset($body['scenario'])) {
            $this->log('Make.com connection test passed', 'info', array(
                'scenario' => $body['scenario']['name'] ?? 'Unknown',
            ));

            return array(
                'success' => true,
                'message' => 'Make.com connection successful',
                'details' => array(
                    'capabilities' => $this->capabilities,
                    'scenario_name' => $body['scenario']['name'] ?? 'Unknown',
                    'scenario_active' => $body['scenario']['islinked'] ?? false,
                ),
            );
        }

        return array(
            'success' => false,
            'message' => "Make.com API returned HTTP $code",
            'details' => $body,
        );
    }

    /**
     * Build API headers
     */
    private function build_headers() {
        return array(
            'Authorization' => 'Token ' . $this->get_config('api_key'),
            'Content-Type' => 'application/json',
            'User-Agent' => 'RawWire/1.0',
        );
    }

    /**
     * Trigger Make.com scenario
     */
    public function trigger(array $payload, array $options = array()) {
        $validation = $this->validate_config();
        if (is_wp_error($validation)) {
            return array('success' => false, 'error' => $validation->get_error_message());
        }

        $execution_id = wp_generate_uuid4();

        // Check if using webhook or API trigger
        $webhook_url = $this->get_config('webhook_url');

        if (!empty($webhook_url)) {
            return $this->trigger_via_webhook($webhook_url, $payload, $execution_id, $options);
        }

        return $this->trigger_via_api($payload, $execution_id, $options);
    }

    /**
     * Trigger via webhook
     */
    private function trigger_via_webhook(string $webhook_url, array $payload, string $execution_id, array $options) {
        $enriched_payload = array_merge($payload, array(
            '_rawwire_meta' => array(
                'execution_id' => $execution_id,
                'timestamp' => current_time('mysql'),
            ),
        ));

        $args = array(
            'method' => 'POST',
            'timeout' => 60,
            'headers' => array('Content-Type' => 'application/json'),
            'body' => json_encode($enriched_payload),
        );

        if (!empty($options['async'])) {
            $args['blocking'] = false;
        }

        $this->log('Triggering Make.com webhook', 'info', array('execution_id' => $execution_id));

        $response = $this->http_request($webhook_url, $args);

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'execution_id' => $execution_id,
                'error' => $response->get_error_message(),
            );
        }

        if (!empty($options['async'])) {
            return array(
                'success' => true,
                'execution_id' => $execution_id,
                'status' => 'triggered_async',
            );
        }

        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        $code = wp_remote_retrieve_response_code($response);

        if ($code >= 200 && $code < 300) {
            $this->log('Make.com webhook completed', 'info', array('execution_id' => $execution_id));
            return array(
                'success' => true,
                'execution_id' => $execution_id,
                'result' => $result,
                'status' => 'completed',
            );
        }

        return array(
            'success' => false,
            'execution_id' => $execution_id,
            'error' => "Webhook returned HTTP $code",
        );
    }

    /**
     * Trigger via Make.com API
     */
    private function trigger_via_api(array $payload, string $execution_id, array $options) {
        $scenario_id = $this->get_config('scenario_id');
        $url = self::API_V2_BASE . "/scenarios/{$scenario_id}/run";

        $args = array(
            'method' => 'POST',
            'timeout' => 60,
            'headers' => $this->build_headers(),
            'body' => json_encode(array(
                'data' => $payload,
            )),
        );

        $this->log('Triggering Make.com scenario via API', 'info', array(
            'scenario_id' => $scenario_id,
            'execution_id' => $execution_id,
        ));

        $response = $this->http_request($url, $args);

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'execution_id' => $execution_id,
                'error' => $response->get_error_message(),
            );
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $code = wp_remote_retrieve_response_code($response);

        if ($code >= 200 && $code < 300) {
            $make_execution_id = $body['executionId'] ?? null;

            // Store mapping for status lookup
            if ($make_execution_id) {
                set_transient("rawwire_make_exec_$execution_id", $make_execution_id, HOUR_IN_SECONDS);
            }

            $this->log('Make.com scenario triggered', 'info', array(
                'execution_id' => $execution_id,
                'make_execution_id' => $make_execution_id,
            ));

            return array(
                'success' => true,
                'execution_id' => $execution_id,
                'make_execution_id' => $make_execution_id,
                'status' => 'triggered',
            );
        }

        return array(
            'success' => false,
            'execution_id' => $execution_id,
            'error' => "API returned HTTP $code",
            'details' => $body,
        );
    }

    /**
     * Get execution status
     */
    public function get_status(string $execution_id) {
        // Get Make.com execution ID from mapping
        $make_execution_id = get_transient("rawwire_make_exec_$execution_id");

        if (!$make_execution_id) {
            return array(
                'status' => 'unknown',
                'error' => 'No Make.com execution ID found',
            );
        }

        $scenario_id = $this->get_config('scenario_id');
        $url = self::API_V2_BASE . "/scenarios/{$scenario_id}/executions/{$make_execution_id}";

        $response = $this->http_request($url, array(
            'headers' => $this->build_headers(),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return array(
                'status' => 'error',
                'error' => $response->get_error_message(),
            );
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['execution'])) {
            $exec = $body['execution'];
            return array(
                'status' => $exec['status'] ?? 'unknown',
                'progress' => $exec['status'] === 'finished' ? 1 : 0.5,
                'operations' => $exec['operations'] ?? 0,
                'data_transfer' => $exec['transfer'] ?? 0,
                'started_at' => $exec['startedAt'] ?? null,
                'finished_at' => $exec['finishedAt'] ?? null,
            );
        }

        return array(
            'status' => 'unknown',
            'error' => 'Could not parse execution status',
        );
    }

    /**
     * Cancel execution
     */
    public function cancel(string $execution_id) {
        $make_execution_id = get_transient("rawwire_make_exec_$execution_id");

        if (!$make_execution_id) {
            $this->log('Cannot cancel - no Make.com execution ID found', 'warning', array(
                'execution_id' => $execution_id,
            ));
            return false;
        }

        $scenario_id = $this->get_config('scenario_id');
        $url = self::API_V2_BASE . "/scenarios/{$scenario_id}/executions/{$make_execution_id}/stop";

        $response = $this->http_request($url, array(
            'method' => 'POST',
            'headers' => $this->build_headers(),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            $this->log('Failed to cancel Make.com execution', 'error', array(
                'execution_id' => $execution_id,
                'error' => $response->get_error_message(),
            ));
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code >= 200 && $code < 300) {
            $this->log('Make.com execution cancelled', 'info', array('execution_id' => $execution_id));
            return true;
        }

        return false;
    }

    /**
     * Define steps - stored as configuration hints
     */
    public function define_steps(array $steps) {
        $this->log('Steps defined - will be sent as payload metadata', 'debug', array(
            'count' => count($steps),
        ));
        $this->config['workflow_steps_hint'] = $steps;
        return true;
    }

    /**
     * Register progress callback
     */
    public function on_progress(callable $callback) {
        $this->progress_callback = $callback;

        // Register webhook endpoint for Make.com callbacks
        add_action('rest_api_init', function() {
            register_rest_route('rawwire/v1', '/make-callback', array(
                'methods' => 'POST',
                'callback' => array($this, 'handle_callback'),
                'permission_callback' => '__return_true',
            ));
        });
    }

    /**
     * Handle callback from Make.com
     */
    public function handle_callback($request) {
        $data = $request->get_json_params();
        $execution_id = $data['execution_id'] ?? '';

        if (empty($execution_id)) {
            return new WP_REST_Response(array('error' => 'Missing execution_id'), 400);
        }

        // Store status
        set_transient("rawwire_make_status_$execution_id", array(
            'status' => $data['status'] ?? 'unknown',
            'progress' => $data['progress'] ?? 0,
            'result' => $data['result'] ?? null,
            'updated_at' => current_time('mysql'),
        ), HOUR_IN_SECONDS);

        if (is_callable($this->progress_callback)) {
            call_user_func($this->progress_callback, $data);
        }

        $this->log('Make.com callback received', 'debug', array(
            'execution_id' => $execution_id,
            'status' => $data['status'] ?? 'unknown',
        ));

        return new WP_REST_Response(array('success' => true), 200);
    }
}
