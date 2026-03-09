<?php
/**
 * Embedded MPC module inside RawWire Dashboard plugin
 */
if (!defined('ABSPATH')) { exit; }

require_once plugin_dir_path(__FILE__) . '../../includes/interface-module.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-mpc-client.php';

class RawWire_MPC_Internal_Module implements RawWire_Module_Interface {
    protected $meta = array('name'=>'MPC Internal','slug'=>'mpc','version'=>'0.1.0');
    protected $client;
    public function init() { $this->client = new RawWire_MPC_Client(get_option('rawwire_mpc_endpoint','')); }
    public function register_rest_routes() {}
    public function register_ajax_handlers() {}
    public function get_admin_panels() {
        return array(
            'mpc_frame_overview' => array('title'=>'MPC Frame','panel_id'=>'mpc-frame-panel','module'=>'mpc','action'=>'mpc_render_panel','description'=>'MPC frame connected panel'),
        );
    }
    public function get_metadata() { return $this->meta; }
    public function handle_rest_request($action,$request){ return $this->handle_ajax($action, $request instanceof WP_REST_Request ? $request->get_params() : (array)$request); }
    public function handle_ajax($action,$data) {
        switch($action) {
            case 'mpc_render_panel':
                $type = $data['type'] ?? 'overview';
                $content = $this->client->request_content($type);
                // Render via templates
                ob_start();
                $tpl = plugin_dir_path(__FILE__) . 'templates/panel.php';
                if (file_exists($tpl)) { $content_for_tpl = $content; include $tpl; }
                return ob_get_clean();
            default:
                return array('success'=>false,'message'=>'Unknown action');
        }
    }
}

if (class_exists('RawWire_Module_Core')) {
    try { RawWire_Module_Core::register_module('mpc', new RawWire_MPC_Internal_Module()); } catch (Exception $e) {}
}
