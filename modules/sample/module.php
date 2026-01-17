<?php
/**
 * Sample module for RawWire Dashboard
 * Provides sample settings and approvals panels and an MPC client example.
 */
if (!defined('ABSPATH')) { if (!defined('WP_CLI')) { /* allow CLI tests */ } }

require_once plugin_dir_path(__FILE__) . '../../includes/interface-module.php';

class RawWire_Sample_Module implements RawWire_Module_Interface {
    protected $meta = array(
        'name' => 'Sample Module',
        'slug' => 'sample',
        'version' => '0.1.0',
        'description' => 'Example module demonstrating panels and MPC connector'
    );

    public function init() {
        // Safe init: only call WP hooks if available
        if (function_exists('add_action')) {
            // nothing for now
        }
    }

    public function register_rest_routes() {
        // Modules may register custom REST routes; this sample uses module-core dispatch
    }

    public function register_ajax_handlers() {
        // Handled via module dispatcher
    }

    public function get_admin_panels() {
        return array(
            'sample_settings' => array(
                'title' => 'Sample Settings',
                'panel_id' => 'sample-settings-panel',
                'module' => 'sample',
                'action' => 'panel_settings',
                'description' => 'Controls for the sample module',
            ),
            'sample_approvals' => array(
                'title' => 'Sample Approvals',
                'panel_id' => 'sample-approvals-panel',
                'module' => 'sample',
                'action' => 'panel_approvals',
                'role' => 'approvals',
                'description' => 'Approval queue provided by sample module',
            ),
        );
    }

    public function get_metadata() {
        return $this->meta;
    }

    public function handle_rest_request($action, $request) {
        // Map to ajax handlers for simplicity
        return $this->handle_ajax($action, $request instanceof WP_REST_Request ? $request->get_params() : (array)$request);
    }

    public function handle_ajax($action, $data) {
        switch ($action) {
            case 'panel_settings':
                // Return HTML fragment for settings
                $html = '<div class="sample-settings">'
                      . '<label>Enable feature: <input type="checkbox" id="sample-enable" ' . (get_option('sample_enable', 0) ? 'checked' : '') . '></label>'
                      . '<p class="description">Toggle sample module feature.</p>'
                      . '<button class="button" id="sample-save">Save</button>'
                      . '</div>';
                return array('html' => $html);

            case 'panel_approvals':
                // Return a small approvals list
                $items = array(
                    array('id' => 101, 'title' => 'Sample Item 1', 'source' => 'sample', 'created_at' => date('c'), 'status' => 'pending'),
                    array('id' => 102, 'title' => 'Sample Item 2', 'source' => 'sample', 'created_at' => date('c'), 'status' => 'pending'),
                );
                return array('items' => $items);

            case 'panel_control':
                $key = sanitize_text_field($data['control_action'] ?? '');
                $val = isset($data['value']) ? intval($data['value']) : 0;
                if (!empty($key)) update_option('sample_' . $key, $val);
                return array('success' => true, 'key' => $key, 'value' => $val);

            case 'ai_chat':
                $msg = sanitize_textarea_field($data['message'] ?? '');
                return 'Sample module received: ' . substr($msg, 0, 200);

            case 'get_workflow_config':
                return array('models' => array(array('id'=>'sample-m', 'name'=>'Sample Model')),
                             'parameters' => array('temperature' => 0.5));

            default:
                return array('success' => false, 'message' => 'Unknown action');
        }
    }

    /** Simple MPC client example */
    public function get_mpc_client() {
        return new RawWire_Sample_MPC_Client(get_option('rawwire_mpc_endpoint', ''));
    }
}

/** Minimal example MPC client class (connects to WebSocket if possible) */
class RawWire_Sample_MPC_Client {
    protected $endpoint;
    public function __construct($endpoint) {
        $this->endpoint = $endpoint;
    }
    public function send($message) {
        if (empty($this->endpoint)) {
            if (class_exists('RawWire_Logger')) {
                $logger = new RawWire_Logger();
                $logger->log('MPC client: no endpoint configured', 'warning');
            }
            return false;
        }

        // Try basic stream connection for ws:// (this is illustrative; use a proper WS client in production)
        $url = parse_url($this->endpoint);
        if ($url === false || !isset($url['host'])) return false;

        $host = $url['host'];
        $port = $url['port'] ?? ($url['scheme'] === 'wss' ? 443 : 80);
        $transport = ($url['scheme'] === 'wss') ? 'ssl' : 'tcp';
        $addr = sprintf('%s://%s:%d', $transport, $host, $port);

        $ctx = stream_context_create();
        $socket = @stream_socket_client($addr, $errno, $errstr, 2, STREAM_CLIENT_CONNECT, $ctx);
        if (! $socket) {
            if (class_exists('RawWire_Logger')) {
                $logger = new RawWire_Logger();
                $logger->log('MPC client connect failed', 'error', array('err' => $errstr));
            }
            return false;
        }

        // Very naive write - real WS handshake omitted
        fwrite($socket, $message);
        fclose($socket);
        return true;
    }
}

// Register the module safely
if (class_exists('RawWire_Module_Core')) {
    try {
        $mod = new RawWire_Sample_Module();
        RawWire_Module_Core::register_module('sample', $mod);
    } catch (Exception $e) {
        // ignore
    }
}
