<?php
/**
 * Logger class for RawWire Dashboard
 *
 * @since 1.0.18
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * RawWire Logger Class
 */
class RawWire_Logger {

    /**
     * Log levels
     */
    const EMERGENCY = 'emergency';
    const ALERT     = 'alert';
    const CRITICAL  = 'critical';
    const ERROR     = 'error';
    const WARNING   = 'warning';
    const NOTICE    = 'notice';
    const INFO      = 'info';
    const DEBUG     = 'debug';

    /**
     * Log a message
     *
     * @param string $message Log message
     * @param string $level   Log level
     * @param array  $context Additional context
     */
    public static function log($message, $level = self::INFO, $context = array()) {
        if (!self::should_log($level)) {
            return;
        }

        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'level'     => $level,
            'message'   => $message,
            'context'   => $context,
            'user_id'   => get_current_user_id(),
            'ip'        => self::get_client_ip(),
        );

        // Store in database or file
        self::store_log($log_entry);

        // Also log to WordPress debug.log if WP_DEBUG is enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $context_str = !empty($context) ? ' | Context: ' . wp_json_encode($context) : '';
            error_log("RawWire [{$level}]: {$message}{$context_str}");
        }

        // Also write to a plugin-level log file for reliable container access
        try {
            $content_dir = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : ABSPATH . 'wp-content';
            $log_file = trailingslashit($content_dir) . 'raw-wire-dashboard.log';
            $entry = sprintf("[%s] %s: %s %s\n", $log_entry['timestamp'], strtoupper($log_entry['level']), $log_entry['message'], !empty($log_entry['context']) ? wp_json_encode($log_entry['context']) : '');
            @file_put_contents($log_file, $entry, FILE_APPEND | LOCK_EX);
        } catch (Exception $e) {
            // Swallow any filesystem errors to avoid breaking admin pages
        }
    }

    /**
     * Log info message
     *
     * @param string $message Log message
     * @param array  $context Additional context
     */
    public static function info($message, $context = array()) {
        self::log($message, self::INFO, $context);
    }

    /**
     * Log error message
     *
     * @param string $message Log message
     * @param array  $context Additional context
     */
    public static function error($message, $context = array()) {
        self::log($message, self::ERROR, $context);
    }

    /**
     * Log warning message
     *
     * @param string $message Log message
     * @param array  $context Additional context
     */
    public static function warning($message, $context = array()) {
        self::log($message, self::WARNING, $context);
    }

    /**
     * Log debug message
     *
     * @param string $message Log message
     * @param string $type    Log type/category (optional)
     * @param array  $context Additional context
     */
    public static function debug($message, $type = 'debug', $context = array()) {
        if (is_array($type)) {
            $context = $type;
            $type = 'debug';
        }
        $context['log_type'] = $type;
        self::log($message, self::DEBUG, $context);
    }

    /**
     * Log activity message (alias for log with structured type)
     *
     * @param string $message  Log message
     * @param string $type     Log type/category
     * @param array  $details  Additional details
     * @param string $severity Severity level (info, warning, error, critical)
     */
    public static function log_activity($message, $type = 'activity', $details = array(), $severity = 'info') {
        $level_map = array(
            'info'     => self::INFO,
            'warning'  => self::WARNING,
            'error'    => self::ERROR,
            'critical' => self::CRITICAL,
            'debug'    => self::DEBUG,
        );
        $level = isset($level_map[$severity]) ? $level_map[$severity] : self::INFO;
        $context = is_array($details) ? $details : array();
        $context['log_type'] = $type;
        self::log($message, $level, $context);
    }

    /**
     * Log error message with extended context
     *
     * @param string $message Log message
     * @param array  $details Additional details
     * @param string $severity Severity level (error, warning, critical)
     */
    public static function log_error($message, $details = array(), $severity = 'error') {
        $level_map = array(
            'warning'  => self::WARNING,
            'error'    => self::ERROR,
            'critical' => self::CRITICAL,
        );
        $level = isset($level_map[$severity]) ? $level_map[$severity] : self::ERROR;
        $context = is_array($details) ? $details : array();
        $context['log_type'] = 'error';
        self::log($message, $level, $context);
    }

    /**
     * Check if logging is enabled for the given level
     *
     * @param string $level Log level
     * @return bool
     */
    private static function should_log($level) {
        // Default to INFO level to capture most logs
        $min_level = get_option('rawwire_log_level', self::INFO);

        $levels = array(
            self::DEBUG     => 0,
            self::INFO      => 1,
            self::NOTICE    => 2,
            self::WARNING   => 3,
            self::ERROR     => 4,
            self::CRITICAL  => 5,
            self::ALERT     => 6,
            self::EMERGENCY => 7,
        );

        return isset($levels[$level]) && isset($levels[$min_level]) &&
               $levels[$level] >= $levels[$min_level];
    }

    /**
     * Store log entry
     *
     * @param array $log_entry Log entry data
     */
    private static function store_log($log_entry) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'rawwire_logs';

        // Create table if it doesn't exist
        self::create_logs_table();

        $wpdb->insert(
            $table_name,
            array(
                'timestamp'   => $log_entry['timestamp'],
                'level'       => $log_entry['level'],
                'message'     => $log_entry['message'],
                'context'     => wp_json_encode($log_entry['context']),
                'user_id'     => $log_entry['user_id'],
                'ip_address'  => $log_entry['ip'],
            ),
            array('%s', '%s', '%s', '%s', '%d', '%s')
        );
    }

    /**
     * Create logs table if it doesn't exist
     */
    private static function create_logs_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'rawwire_logs';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $charset_collate = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE $table_name (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                timestamp datetime NOT NULL,
                level varchar(20) NOT NULL,
                message text NOT NULL,
                context longtext,
                user_id bigint(20) unsigned DEFAULT 0,
                ip_address varchar(45) DEFAULT '',
                PRIMARY KEY (id),
                KEY timestamp (timestamp),
                KEY level (level),
                KEY user_id (user_id)
            ) $charset_collate;";

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
    }

    /**
     * Get client IP address
     *
     * @return string
     */
    private static function get_client_ip() {
        $ip_headers = array(
            'HTTP_CF_CONNECTING_IP',
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        );

        foreach ($ip_headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];

                // Handle comma-separated IPs (like X-Forwarded-For)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }

                // Validate IP
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return '';
    }

    /**
     * Get recent logs
     *
     * @param int    $limit  Number of logs to retrieve
     * @param string $level  Filter by log level
     * @return array
     */
    public static function get_recent_logs($limit = 50, $level = null) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'rawwire_logs';

        $where = '';
        if ($level) {
            $where = $wpdb->prepare('WHERE level = %s', $level);
        }

        $sql = $wpdb->prepare(
            "SELECT * FROM $table_name $where ORDER BY timestamp DESC LIMIT %d",
            $limit
        );

        $results = $wpdb->get_results($sql, ARRAY_A);

        // Decode context JSON
        foreach ($results as &$result) {
            if (!empty($result['context'])) {
                $result['context'] = json_decode($result['context'], true);
            }
        }

        return $results;
    }

    /**
     * Clear old logs
     *
     * @param int $days_old Delete logs older than this many days
     */
    public static function clear_old_logs($days_old = 30) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'rawwire_logs';

        $wpdb->query($wpdb->prepare(
            "DELETE FROM $table_name WHERE timestamp < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days_old
        ));
    }
}
