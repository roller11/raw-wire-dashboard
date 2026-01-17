<?php
/**
 * REST API class for RawWire Dashboard
 *
 * @since 1.0.18
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * RawWire REST API Class
 */
class RawWire_REST_API {

    /**
     * Namespace for REST API routes
     */
    const NAMESPACE = 'rawwire/v1';

    /**
     * Register REST API routes
     */
    public function register_routes() {
        // Delegate REST routes to modules via a generic dispatch endpoint.
        register_rest_route(self::NAMESPACE, '/modules/(?P<module>[a-zA-Z0-9-_]+)/(?P<action>[a-zA-Z0-9-_\/]+)', array(
            'methods'             => WP_REST_Server::READABLE | WP_REST_Server::EDITABLE,
            'callback'            => array($this, 'dispatch_module_rest'),
            'permission_callback' => array($this, 'check_permissions'),
        ));
        // List available modules
        register_rest_route(self::NAMESPACE, '/modules', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'list_modules'),
            'permission_callback' => array($this, 'check_permissions'),
        ));

        // Get panels for a module
        register_rest_route(self::NAMESPACE, '/modules/(?P<module>[a-zA-Z0-9-_]+)/panels', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'module_panels'),
            'permission_callback' => array($this, 'check_permissions'),
        ));

        // Panel-specific dispatch: map a panel id to its provider module/action and dispatch
        register_rest_route(self::NAMESPACE, '/panels/(?P<panel_id>[a-zA-Z0-9-_]+)/?', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'dispatch_panel_request'),
                'permission_callback' => array($this, 'check_permissions'),
            ),
            array(
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => array($this, 'dispatch_panel_request'),
                'permission_callback' => array($this, 'check_permissions'),
            ),
        ));
    }

    /**
     * Dispatch a panel request by resolving which module owns the panel and forwarding the request.
     */
    public function dispatch_panel_request($request) {
        $panel_id = $request->get_param('panel_id');

        if (empty($panel_id) || ! class_exists('RawWire_Module_Core')) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Invalid panel'), 400);
        }

        $mods = RawWire_Module_Core::get_modules();
        if (empty($mods)) {
            return new WP_REST_Response(array('success' => false, 'message' => 'No modules available'), 500);
        }

        // Find the panel in modules
        foreach ($mods as $slug => $instance) {
            if (! method_exists($instance, 'get_admin_panels')) continue;
            try {
                $panels = $instance->get_admin_panels();
            } catch (Exception $e) {
                continue;
            }
            if (!is_array($panels)) continue;
            foreach ($panels as $p) {
                if (isset($p['panel_id']) && $p['panel_id'] === $panel_id) {
                    // Found owner module; determine action
                    $action = isset($p['action']) ? $p['action'] : 'panel';
                    if (! method_exists($instance, 'handle_rest_request')) {
                        return new WP_REST_Response(array('success' => false, 'message' => 'Module cannot handle panel requests'), 500);
                    }
                    try {
                        $result = $instance->handle_rest_request($action, $request);
                        if ($result instanceof WP_REST_Response) return $result;
                        return new WP_REST_Response($result, 200);
                    } catch (Exception $e) {
                        RawWire_Logger::error('Panel dispatch error', array('panel' => $panel_id, 'error' => $e->getMessage()));
                        return new WP_REST_Response(array('success' => false, 'message' => $e->getMessage()), 500);
                    }
                }
            }
        }

        return new WP_REST_Response(array('success' => false, 'message' => 'Panel not found'), 404);
    }

    /**
     * Dispatch REST request to a registered module
     */
    public function dispatch_module_rest($request) {
        $module = $request->get_param('module');
        $action = $request->get_param('action');

        if (empty($module) || empty($action)) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Invalid module or action'), 400);
        }

        if (! class_exists('RawWire_Module_Core')) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Module core not available'), 500);
        }

        $modules = RawWire_Module_Core::get_modules();
        if (empty($modules) || ! isset($modules[$module])) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Module not found'), 404);
        }

        $instance = $modules[$module];
        if (! method_exists($instance, 'handle_rest_request')) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Module cannot handle REST requests'), 500);
        }

        try {
            $result = $instance->handle_rest_request($action, $request);
            if ($result instanceof WP_REST_Response) {
                return $result;
            }
            return new WP_REST_Response($result, 200);
        } catch (Exception $e) {
            RawWire_Logger::error('Module REST dispatch error', array('module' => $module, 'error' => $e->getMessage()));
            return new WP_REST_Response(array('success' => false, 'message' => $e->getMessage()), 500);
        }
    }

    /**
     * Return list of registered modules
     */
    public function list_modules($request) {
        if (! class_exists('RawWire_Module_Core')) {
            return new WP_REST_Response(array(), 200);
        }
        $mods = RawWire_Module_Core::get_modules();
        $out = array();
        foreach ($mods as $slug => $instance) {
            $meta = method_exists($instance, 'get_metadata') ? $instance->get_metadata() : array('slug' => $slug);
            $out[] = $meta;
        }
        return new WP_REST_Response($out, 200);
    }

    /**
     * Return admin panels exposed by a module
     */
    public function module_panels($request) {
        $module = $request->get_param('module');
        if (empty($module) || ! class_exists('RawWire_Module_Core')) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Invalid module'), 400);
        }

        $mods = RawWire_Module_Core::get_modules();
        if (empty($mods) || ! isset($mods[$module])) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Module not found'), 404);
        }

        $instance = $mods[$module];
        if (! method_exists($instance, 'get_admin_panels')) {
            return new WP_REST_Response(array(), 200);
        }

        try {
            $panels = $instance->get_admin_panels();
            return new WP_REST_Response($panels, 200);
        } catch (Exception $e) {
            return new WP_REST_Response(array('success' => false, 'message' => $e->getMessage()), 500);
        }
    }

    /**
     * Check if user has permission to access API
     *
     * @return bool
     */
    public function check_permissions() {
        return current_user_can('manage_options');
    }

    /**
     * Sync data endpoint
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function sync_data($request) {
        try {
            $source = $request->get_param('source') ?: 'all';

            // Log sync start
            RawWire_Logger::info('Starting data sync', array('source' => $source));

            // Perform sync operation
            $result = $this->perform_sync($source);

            // Update last sync time
            update_option('rawwire_last_sync', current_time('mysql'));

            RawWire_Logger::info('Data sync completed', array(
                'source' => $source,
                'result' => $result
            ));

            return new WP_REST_Response(array(
                'success' => true,
                'message' => 'Sync completed successfully',
                'data'    => $result,
            ), 200);

        } catch (Exception $e) {
            RawWire_Logger::error('Sync failed', array(
                'error' => $e->getMessage(),
                'source' => $source
            ));

            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Sync failed: ' . $e->getMessage(),
            ), 500);
        }
    }

    /**
     * Perform the actual sync operation
     *
     * @param string $source Sync source
     * @return array
     */
    private function perform_sync($source) {
        // This is a placeholder - implement actual sync logic
        // For now, just return mock data
        return array(
            'synced_items' => rand(10, 50),
            'new_items'    => rand(5, 25),
            'updated_items' => rand(0, 10),
            'source'       => $source,
        );
    }

    /**
     * Get content endpoint
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function get_content($request) {
        global $wpdb;

        $page     = $request->get_param('page');
        $per_page = $request->get_param('per_page');
        $status   = $request->get_param('status');

        $offset = ($page - 1) * $per_page;

        $table_name = $wpdb->prefix . 'rawwire_content';

        $where = '';
        if ($status) {
            $where = $wpdb->prepare('WHERE status = %s', $status);
        }

        $sql = $wpdb->prepare(
            "SELECT * FROM $table_name $where ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        );

        $items = $wpdb->get_results($sql, ARRAY_A);

        $total_sql = "SELECT COUNT(*) FROM $table_name $where";
        $total = $wpdb->get_var($total_sql);

        return new WP_REST_Response(array(
            'items'       => $items,
            'total'       => (int) $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => ceil($total / $per_page),
        ), 200);
    }

    /**
     * Update content endpoint
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function update_content($request) {
        global $wpdb;

        $id     = $request->get_param('id');
        $status = $request->get_param('status');

        $table_name = $wpdb->prefix . 'rawwire_content';

        $updated = $wpdb->update(
            $table_name,
            array(
                'status'     => $status,
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $id),
            array('%s', '%s'),
            array('%d')
        );

        if ($updated === false) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Failed to update content',
            ), 500);
        }

        RawWire_Logger::info('Content status updated', array(
            'id'     => $id,
            'status' => $status,
        ));

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Content updated successfully',
        ), 200);
    }

    /**
     * Get stats endpoint
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function get_stats($request) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'rawwire_content';

        $stats = array(
            'total'     => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_name"),
            'pending'   => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE status = %s", 'pending')),
            'approved'  => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE status = %s", 'approved')),
            'rejected'  => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE status = %s", 'rejected')),
            'last_sync' => get_option('rawwire_last_sync', 'Never'),
        );

        return new WP_REST_Response($stats, 200);
    }
}