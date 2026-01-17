<?php
/**
 * Module interface for RawWire Dashboard
 */
if (!defined('ABSPATH')) {
    exit;
}

interface RawWire_Module_Interface {
    /** Initialize module; register hooks */
    public function init();

    /** Register REST routes on `rest_api_init` */
    public function register_rest_routes();

    /** Register AJAX handlers (admin-ajax) */
    public function register_ajax_handlers();

    /** Return module-provided admin panels configuration array */
    public function get_admin_panels();

    /** Return metadata: name, slug, version, description */
    public function get_metadata();

    /**
     * Handle an incoming REST request dispatched to this module.
     * @param string $action
     * @param WP_REST_Request $request
     * @return WP_REST_Response|array
     */
    public function handle_rest_request($action, $request);

    /**
     * Handle an incoming AJAX request dispatched to this module.
     * @param string $action
     * @param array $data
     * @return mixed
     */
    public function handle_ajax($action, $data);
}
