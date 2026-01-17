<?php
/**
 * Abstract Base Adapter
 * Common functionality shared across all adapter types.
 * 
 * @package RawWire_Dashboard
 * @subpackage Toolbox_Core
 */
if (!defined('ABSPATH')) {
    exit;
}

abstract class RawWire_Adapter_Base {
    /**
     * Adapter configuration
     * @var array
     */
    protected $config = array();

    /**
     * Last error that occurred
     * @var WP_Error|null
     */
    protected $last_error = null;

    /**
     * Adapter name
     * @var string
     */
    protected $name = 'base';

    /**
     * Adapter version
     * @var string
     */
    protected $version = '1.0.0';

    /**
     * Adapter tier (free/value/flagship)
     * @var string
     */
    protected $tier = 'free';

    /**
     * Supported capabilities
     * @var array
     */
    protected $capabilities = array();

    /**
     * Required config fields
     * @var array
     */
    protected $required_fields = array();

    /**
     * Logger instance
     * @var RawWire_Logger|null
     */
    protected $logger = null;

    /**
     * Constructor
     * 
     * @param array $config Configuration array
     */
    public function __construct(array $config) {
        $this->config = $this->sanitize_config($config);
        $this->init_logger();
    }

    /**
     * Initialize logger if available
     */
    protected function init_logger() {
        if (class_exists('RawWire_Logger')) {
            $this->logger = new RawWire_Logger();
        }
    }

    /**
     * Log a message safely
     * 
     * @param string $message
     * @param string $level info|debug|warning|error
     * @param array $context
     */
    protected function log(string $message, string $level = 'info', array $context = array()) {
        if ($this->logger) {
            $context['adapter'] = $this->name;
            $context['tier'] = $this->tier;
            
            switch ($level) {
                case 'error':
                    $this->logger->log_error($message, $context, 'error');
                    break;
                case 'warning':
                    $this->logger->log_error($message, $context, 'warning');
                    break;
                case 'debug':
                    $this->logger->log($message, 'debug', $context);
                    break;
                default:
                    $this->logger->log($message, 'info', $context);
            }
        }
    }

    /**
     * Sanitize configuration values
     * 
     * @param array $config
     * @return array
     */
    protected function sanitize_config(array $config) {
        $sanitized = array();
        foreach ($config as $key => $value) {
            $key = sanitize_key($key);
            if (is_string($value)) {
                // Don't sanitize passwords/keys too aggressively
                if (strpos($key, 'key') !== false || strpos($key, 'password') !== false || strpos($key, 'secret') !== false || strpos($key, 'token') !== false) {
                    $sanitized[$key] = trim($value);
                } else {
                    $sanitized[$key] = sanitize_text_field($value);
                }
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitize_config($value);
            } elseif (is_bool($value)) {
                $sanitized[$key] = (bool) $value;
            } elseif (is_numeric($value)) {
                $sanitized[$key] = is_float($value) ? (float) $value : (int) $value;
            } else {
                $sanitized[$key] = $value;
            }
        }
        return $sanitized;
    }

    /**
     * Validate the adapter configuration
     * 
     * @return bool|WP_Error
     */
    public function validate_config() {
        $missing = array();
        foreach ($this->required_fields as $field) {
            if (empty($this->config[$field])) {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            $this->last_error = new WP_Error(
                'missing_config',
                sprintf('Missing required configuration fields: %s', implode(', ', $missing)),
                array('missing_fields' => $missing)
            );
            $this->log('Configuration validation failed', 'error', array('missing' => $missing));
            return $this->last_error;
        }

        return true;
    }

    /**
     * Get adapter info
     * 
     * @return array
     */
    public function get_info() {
        return array(
            'name' => $this->name,
            'version' => $this->version,
            'tier' => $this->tier,
            'capabilities' => $this->capabilities,
        );
    }

    /**
     * Check if a capability is supported
     * 
     * @param string $capability
     * @return bool
     */
    public function supports(string $capability) {
        return in_array($capability, $this->capabilities, true);
    }

    /**
     * Get last error
     * 
     * @return WP_Error|null
     */
    public function get_last_error() {
        return $this->last_error;
    }

    /**
     * Set last error
     * 
     * @param string $code
     * @param string $message
     * @param array $data
     */
    protected function set_error(string $code, string $message, array $data = array()) {
        $this->last_error = new WP_Error($code, $message, $data);
        $this->log($message, 'error', array('code' => $code, 'data' => $data));
    }

    /**
     * Make a safe HTTP request with error handling
     * 
     * @param string $url
     * @param array $args
     * @return array|WP_Error
     */
    protected function http_request(string $url, array $args = array()) {
        $defaults = array(
            'timeout' => 30,
            'retries' => 1,
            'redirection' => 5,
            'httpversion' => '1.1',
            'blocking' => true,
            'headers' => array(),
            'cookies' => array(),
        );

        $args = wp_parse_args($args, $defaults);

        $this->log('HTTP request initiated', 'debug', array('url' => $url, 'method' => $args['method'] ?? 'GET'));

        $attempt = 0;
        $maxAttempts = max(1, (int)($args['retries'] ?? 1));
        $response = null;
        while ($attempt < $maxAttempts) {
            $attempt++;
            $response = wp_remote_request($url, $args);
            if (!is_wp_error($response)) break;

            // On transient network errors, retry after a short backoff
            $err = $response->get_error_message();
            $this->log('HTTP request error: ' . $err, 'warning', array('attempt' => $attempt, 'url' => $url));
            if ($attempt < $maxAttempts) {
                sleep(min(2, $attempt));
            }
        }

        if (is_wp_error($response)) {
            $this->set_error(
                'http_error',
                sprintf('HTTP request failed: %s', $response->get_error_message()),
                array('url' => $url)
            );
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 400) {
            $body = wp_remote_retrieve_body($response);
            $this->set_error(
                'http_' . $code,
                sprintf('HTTP %d error: %s', $code, substr($body, 0, 200)),
                array('url' => $url, 'code' => $code)
            );
            return new WP_Error('http_' . $code, 'Request failed', array('response' => $response));
        }

        $this->log('HTTP request completed', 'debug', array('url' => $url, 'code' => $code));

        return $response;
    }

    /**
     * Get config value with default
     * 
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    protected function get_config(string $key, $default = null) {
        return $this->config[$key] ?? $default;
    }
}
