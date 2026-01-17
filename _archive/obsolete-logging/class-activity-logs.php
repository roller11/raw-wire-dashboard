<?php
/**
 * RawWire Activity Logs
 * 
 * Manages logging and retrieval of activity and error logs
 *
 * @package    RawWire_Dashboard
 * @subpackage RawWire_Dashboard/includes
 * @since      1.0.11
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Activity Logs Handler Class
 */
class RawWire_Activity_Logs {

    /**
     * Initialize activity logs
     */
    public static function init() {
        // Register AJAX handlers
        add_action('wp_ajax_rawwire_get_activity_logs', [__CLASS__, 'ajax_get_logs']);
        add_action('wp_ajax_rawwire_get_activity_info', [__CLASS__, 'ajax_get_info']);
        add_action('wp_ajax_rawwire_clear_activity_logs', [__CLASS__, 'ajax_clear_logs']);
        
        // Register script/style enqueue hook
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    /**
     * Enqueue activity logs JS and localize data
     */
    public static function enqueue_assets($hook) {
        // Check if we're on a Raw-Wire admin page
        if (strpos((string)$hook, 'raw-wire') === false && strpos((string)$hook, 'rawwire') === false) {
            return;
        }

        wp_enqueue_script(
            'rawwire-activity-logs',
            plugin_dir_url(__FILE__) . '../js/activity-logs.js',
            ['jquery'],
            '1.0.11',
            true
        );

        $nonce = wp_create_nonce('rawwire_activity_logs');
        wp_localize_script('rawwire-activity-logs', 'RawWireLogsConfig', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => $nonce,
            'strings' => [
                'loading' => __('Loading logs...', 'raw-wire-dashboard'),
                'error_loading' => __('Error loading logs.', 'raw-wire-dashboard'),
                'no_logs' => __('No logs found.', 'raw-wire-dashboard'),
                'clear_confirm' => __('Are you sure you want to clear all activity logs?', 'raw-wire-dashboard'),
                'clear_success' => __('Logs cleared successfully.', 'raw-wire-dashboard'),
                'clear_error' => __('Error clearing logs.', 'raw-wire-dashboard'),
            ]
        ]);
    }

    /**
     * AJAX: Get activity logs
     */
    public static function ajax_get_logs() {
        
        // Verify nonce (allow for admin users even if invalid)
        if (isset($_REQUEST['nonce'])) {
            $nonce_check = wp_verify_nonce($_REQUEST['nonce'], 'rawwire_activity_logs');
            if (!$nonce_check) {
                // Nonce invalid - but continue if user is admin
                if (!current_user_can('manage_options')) {
                    wp_send_json_error(['message' => 'Nonce verification failed']);
                    return;
                }
            }
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'info';
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 50;

        // Get logs from logger
        $logs = self::get_logs_by_type($type, $page, $per_page);

        wp_send_json_success(['logs' => $logs]);
    }

    /**
     * AJAX: Get activity info
     */
    public static function ajax_get_info() {
        // Verify nonce (warn in logs if invalid but still allow for admin users)
        if (isset($_REQUEST['nonce'])) {
            $nonce_check = wp_verify_nonce($_REQUEST['nonce'], 'rawwire_activity_logs');
            if (!$nonce_check) {
                // Nonce invalid - but continue if user is admin
                if (!current_user_can('manage_options')) {
                    wp_send_json_error(['message' => 'Nonce verification failed']);
                    return;
                }
            }
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        global $wpdb;

        // Get logs table name
        $logs_table = $wpdb->prefix . 'rawwire_logs';
        
        // Count recent errors
        $recent_errors = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$logs_table} 
            WHERE level = 'error' 
            AND timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );

        wp_send_json_success([
            'wp_version' => get_bloginfo('version'),
            'php_version' => phpversion(),
            'db_version' => $wpdb->db_version(),
            'plugin_version' => get_option('rawwire_version', '1.0.0'),
            'last_migration' => get_option('rawwire_last_migration', 'Never'),
            'recent_errors' => intval($recent_errors)
        ]);
    }

    /**
     * Get logs by type from database
     */
    public static function get_logs_by_type($type = 'info', $page = 1, $per_page = 50) {
        global $wpdb;

        $logs_table = $wpdb->prefix . 'rawwire_logs';
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$logs_table}'") !== $logs_table) {
            return [];
        }

        $offset = ($page - 1) * $per_page;

        // Map type to log levels
        $levels = [];
        switch ($type) {
            case 'error':
                $levels = ['error', 'fatal'];
                break;
            case 'warning':
                $levels = ['warning'];
                break;
            case 'info':
            default:
                $levels = ['info', 'debug'];
                break;
        }

        // Build query with proper escaping for IN clause
        $escaped_levels = array_map(function($level) { return "'" . esc_sql($level) . "'"; }, $levels);
        $levels_str = implode(',', $escaped_levels);
        
        $query = $wpdb->prepare(
            "SELECT id, level, message, context, timestamp FROM {$logs_table} 
            WHERE level IN ({$levels_str}) 
            ORDER BY timestamp DESC 
            LIMIT %d OFFSET %d",
            $per_page,
            $offset
        );

        $logs = $wpdb->get_results($query, ARRAY_A);

        // Fallback: if no DB logs and requesting errors, parse plugin log file for recent errors
        if (empty($logs) && $type === 'error') {
            $file_path = (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : ABSPATH . 'wp-content') . '/raw-wire-dashboard.log';
            if (file_exists($file_path) && is_readable($file_path)) {
                $file_lines = @file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if (is_array($file_lines)) {
                    $lines = array_reverse(array_slice($file_lines, -200)); // last 200 lines
                    foreach ($lines as $line) {
                        // Example: [2026-01-10 12:34:56] ERROR: message {json}
                        if (stripos($line, 'ERROR:') !== false || stripos($line, 'CRITICAL:') !== false || stripos($line, 'FATAL:') !== false) {
                            // Extract timestamp and message crudely
                            $ts = substr($line, 1, 19);
                            $parts = explode(': ', $line, 2);
                            $msg = isset($parts[1]) ? $parts[1] : $line;
                            $logs[] = array(
                                'id' => 0,
                                'level' => (stripos($line, 'CRITICAL') !== false || stripos($line, 'FATAL') !== false) ? 'CRITICAL' : 'ERROR',
                                'message' => $msg,
                                'context' => null,
                                'timestamp' => $ts,
                            );
                            if (count($logs) >= $per_page) { break; }
                        }
                    }
                }
            }
        }

        if (empty($logs)) {
            return [];
        }

        // Format logs for display
        return array_map(function($log) {
            return [
                'id' => $log['id'],
                'level' => strtoupper($log['level']),
                'message' => $log['message'],
                'context' => is_string($log['context']) ? json_decode($log['context'], true) : $log['context'],
                'timestamp' => $log['timestamp'],
                'time_ago' => self::time_ago($log['timestamp'])
            ];
        }, $logs);
    }

    /**
     * Format time elapsed
     */
    private static function time_ago($timestamp) {
        if (empty($timestamp)) {
            return 'Never';
        }

        $time = strtotime($timestamp);
        if (!$time) {
            return 'Unknown';
        }

        if (function_exists('human_time_diff')) {
            return human_time_diff($time, current_time('timestamp')) . ' ago';
        }

        $diff = current_time('timestamp') - $time;
        if ($diff < 60) return $diff . 's ago';
        if ($diff < 3600) return floor($diff / 60) . 'm ago';
        if ($diff < 86400) return floor($diff / 3600) . 'h ago';
        return floor($diff / 86400) . 'd ago';
    }

    /**
     * AJAX: Clear activity logs
     */
    public static function ajax_clear_logs() {
        // Verify nonce
        if (!isset($_REQUEST['nonce']) || !wp_verify_nonce($_REQUEST['nonce'], 'rawwire_activity_logs')) {
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => 'Nonce verification failed']);
                return;
            }
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
            return;
        }

        // Get optional parameters
        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'all';
        $older_than_days = isset($_POST['older_than_days']) ? intval($_POST['older_than_days']) : 0;

        $result = self::clear_logs($type, $older_than_days);

        if ($result !== false) {
            // Log the clear action
            if (class_exists('RawWire_Logger')) {
                RawWire_Logger::info('Activity logs cleared', [
                    'type' => $type,
                    'older_than_days' => $older_than_days,
                    'rows_deleted' => $result,
                    'user_id' => get_current_user_id()
                ]);
            }
            wp_send_json_success([
                'message' => sprintf('Cleared %d log entries', $result),
                'rows_deleted' => $result
            ]);
        } else {
            wp_send_json_error(['message' => 'Failed to clear logs']);
        }
    }

    /**
     * Clear logs from database
     *
     * @param string $type Level type to clear: 'all', 'error', 'warning', 'info', 'debug'
     * @param int $older_than_days Only clear logs older than X days (0 = all)
     * @return int|false Number of rows deleted or false on failure
     */
    public static function clear_logs($type = 'all', $older_than_days = 0) {
        global $wpdb;

        $logs_table = $wpdb->prefix . 'rawwire_logs';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$logs_table}'") !== $logs_table) {
            return false;
        }

        $where_clauses = [];
        
        // Filter by type/level
        if ($type !== 'all') {
            $levels = [];
            switch ($type) {
                case 'error':
                    $levels = ['error', 'fatal', 'critical'];
                    break;
                case 'warning':
                    $levels = ['warning'];
                    break;
                case 'info':
                    $levels = ['info', 'notice'];
                    break;
                case 'debug':
                    $levels = ['debug'];
                    break;
            }
            if (!empty($levels)) {
                $escaped_levels = array_map(function($level) { return "'" . esc_sql($level) . "'"; }, $levels);
                $where_clauses[] = "level IN (" . implode(',', $escaped_levels) . ")";
            }
        }

        // Filter by age
        if ($older_than_days > 0) {
            $where_clauses[] = $wpdb->prepare(
                "timestamp < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $older_than_days
            );
        }

        // Build and execute query
        $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
        $query = "DELETE FROM {$logs_table} {$where_sql}";
        
        $result = $wpdb->query($query);
        
        return $result;
    }

    /**
     * Log a message to the database
     */
    public static function log($message, $level = 'info', $context = []) {
        global $wpdb;

        $logs_table = $wpdb->prefix . 'rawwire_logs';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$logs_table}'") !== $logs_table) {
            return false;
        }

        $context_json = is_array($context) ? json_encode($context) : $context;

        return $wpdb->insert(
            $logs_table,
            [
                'level' => $level,
                'message' => $message,
                'context' => $context_json,
                'timestamp' => current_time('mysql')
            ],
            ['%s', '%s', '%s', '%s']
        );
    }
}
