<?php
/**
 * API Key Manager
 * 
 * Centralized, encrypted storage for API keys.
 * Single source of truth for all external service credentials.
 * 
 * Features:
 * - Encryption at rest using WordPress salts
 * - Automatic migration from legacy plain-text storage
 * - Single interface for all components (AI Scraper, Scraper Toolkit, etc.)
 * - Key status tracking and validation
 * 
 * @package RawWire_Dashboard
 * @subpackage Toolbox_Core
 * @since 1.0.22
 */

if (!defined('ABSPATH')) {
    exit;
}

class RawWire_Key_Manager {

    /**
     * Singleton instance
     * @var RawWire_Key_Manager|null
     */
    private static $instance = null;

    /**
     * Option key for encrypted keys storage
     */
    const OPTION_KEY = 'rawwire_api_keys_encrypted';

    /**
     * Option key for key metadata (non-sensitive)
     */
    const META_KEY = 'rawwire_api_keys_meta';

    /**
     * Registered key definitions
     * @var array
     */
    private $key_definitions = [];

    /**
     * Cached decrypted keys (runtime only, never persisted)
     * @var array
     */
    private $key_cache = [];

    /**
     * Get singleton instance
     * 
     * @return RawWire_Key_Manager
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->register_default_keys();
        $this->maybe_migrate_legacy_keys();
        
        // AJAX handlers for admin
        add_action('wp_ajax_rawwire_save_api_key', [$this, 'ajax_save_key']);
        add_action('wp_ajax_rawwire_delete_api_key', [$this, 'ajax_delete_key']);
        add_action('wp_ajax_rawwire_test_api_key', [$this, 'ajax_test_key']);
        add_action('wp_ajax_rawwire_get_key_status', [$this, 'ajax_get_status']);
    }

    /**
     * Register default key definitions
     */
    private function register_default_keys() {
        $this->key_definitions = [
            'regulations_gov' => [
                'name'        => 'Regulations.gov',
                'description' => 'U.S. Federal regulatory documents API',
                'signup_url'  => 'https://open.gsa.gov/api/regulationsgov/',
                'test_url'    => 'https://api.regulations.gov/v4/documents?page[size]=1',
                'test_method' => 'header', // 'header', 'query', or 'bearer'
                'test_param'  => 'X-Api-Key',
                'required_by' => ['ai_scraper', 'scraper_toolkit'],
                'legacy_option' => 'rawwire_regulations_gov_key',
            ],
            'congress_gov' => [
                'name'        => 'Congress.gov',
                'description' => 'U.S. Congressional bills and legislation API',
                'signup_url'  => 'https://api.congress.gov/sign-up/',
                'test_url'    => 'https://api.congress.gov/v3/bill?limit=1',
                'test_method' => 'query',
                'test_param'  => 'api_key',
                'required_by' => ['ai_scraper', 'scraper_toolkit'],
                'legacy_option' => 'rawwire_congress_gov_key',
            ],
            'europeana' => [
                'name'        => 'Europeana',
                'description' => 'European cultural heritage API',
                'signup_url'  => 'https://pro.europeana.eu/page/get-api',
                'test_url'    => 'https://api.europeana.eu/record/v2/search.json?query=*&rows=1',
                'test_method' => 'query',
                'test_param'  => 'wskey',
                'required_by' => ['scraper_toolkit'],
                'legacy_option' => null,
            ],
            'openai' => [
                'name'        => 'OpenAI',
                'description' => 'OpenAI API for GPT models',
                'signup_url'  => 'https://platform.openai.com/api-keys',
                'test_url'    => 'https://api.openai.com/v1/models',
                'test_method' => 'bearer',
                'test_param'  => 'Authorization',
                'required_by' => ['ai_engine'],
                'legacy_option' => null,
                'managed_by'  => 'ai_engine', // Indicates AI Engine manages this key
            ],
            'groq' => [
                'name'        => 'Groq',
                'description' => 'Groq fast inference API',
                'signup_url'  => 'https://console.groq.com/keys',
                'test_url'    => 'https://api.groq.com/openai/v1/models',
                'test_method' => 'bearer',
                'test_param'  => 'Authorization',
                'required_by' => ['ai_engine'],
                'legacy_option' => null,
                'managed_by'  => 'ai_engine',
            ],
            'anthropic' => [
                'name'        => 'Anthropic',
                'description' => 'Anthropic Claude API',
                'signup_url'  => 'https://console.anthropic.com/',
                'test_url'    => null, // Cannot test without making a request
                'test_method' => 'header',
                'test_param'  => 'x-api-key',
                'required_by' => ['ai_engine'],
                'legacy_option' => null,
                'managed_by'  => 'ai_engine',
            ],
            'github' => [
                'name'        => 'GitHub',
                'description' => 'GitHub API for repository access',
                'signup_url'  => 'https://github.com/settings/tokens',
                'test_url'    => 'https://api.github.com/user',
                'test_method' => 'bearer',
                'test_param'  => 'Authorization',
                'required_by' => ['scraper_toolkit'],
                'legacy_option' => null,
            ],
        ];
    }

    /**
     * Register a custom key definition
     * 
     * @param string $key_id    Unique identifier
     * @param array  $definition Key definition
     */
    public function register_key($key_id, $definition) {
        $this->key_definitions[$key_id] = wp_parse_args($definition, [
            'name'        => $key_id,
            'description' => '',
            'signup_url'  => '',
            'test_url'    => null,
            'test_method' => 'header',
            'test_param'  => 'X-Api-Key',
            'required_by' => [],
            'legacy_option' => null,
            'managed_by'  => null,
        ]);
    }

    /**
     * Get all key definitions
     * 
     * @param string|null $filter_by Filter by component (ai_scraper, scraper_toolkit, etc.)
     * @return array
     */
    public function get_key_definitions($filter_by = null) {
        if ($filter_by === null) {
            return $this->key_definitions;
        }

        return array_filter($this->key_definitions, function($def) use ($filter_by) {
            return in_array($filter_by, $def['required_by'] ?? []);
        });
    }

    /**
     * Migrate legacy plain-text keys to encrypted storage
     */
    private function maybe_migrate_legacy_keys() {
        $migrated = get_option('rawwire_keys_migrated', false);
        if ($migrated) {
            return;
        }

        foreach ($this->key_definitions as $key_id => $def) {
            if (empty($def['legacy_option'])) {
                continue;
            }

            $legacy_value = get_option($def['legacy_option'], '');
            if (!empty($legacy_value)) {
                // Save to encrypted storage
                $this->set_key($key_id, $legacy_value);
                
                // Delete legacy plain-text option
                delete_option($def['legacy_option']);
                
                error_log("[RawWire Key Manager] Migrated key: {$key_id}");
            }
        }

        update_option('rawwire_keys_migrated', true);
    }

    // =========================================================================
    // ENCRYPTION / DECRYPTION
    // =========================================================================

    /**
     * Get encryption key derived from WordPress salts
     * 
     * @return string
     */
    private function get_encryption_key() {
        // Use multiple WordPress salts for strong key derivation
        $salts = '';
        
        if (defined('AUTH_KEY')) {
            $salts .= AUTH_KEY;
        }
        if (defined('SECURE_AUTH_KEY')) {
            $salts .= SECURE_AUTH_KEY;
        }
        if (defined('LOGGED_IN_KEY')) {
            $salts .= LOGGED_IN_KEY;
        }
        
        // Fallback if salts aren't defined (shouldn't happen in production)
        if (empty($salts)) {
            $salts = 'rawwire-fallback-' . ABSPATH;
        }

        // Derive a 32-byte key using SHA-256
        return hash('sha256', $salts, true);
    }

    /**
     * Encrypt a value
     * 
     * @param string $value Plain text value
     * @return string Base64-encoded encrypted value
     */
    private function encrypt($value) {
        if (empty($value)) {
            return '';
        }

        $key = $this->get_encryption_key();
        
        // Generate random IV
        $iv_length = openssl_cipher_iv_length('aes-256-cbc');
        $iv = openssl_random_pseudo_bytes($iv_length);
        
        // Encrypt
        $encrypted = openssl_encrypt($value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        
        if ($encrypted === false) {
            error_log('[RawWire Key Manager] Encryption failed');
            return '';
        }

        // Prepend IV and base64 encode
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt a value
     * 
     * @param string $encrypted_value Base64-encoded encrypted value
     * @return string Plain text value
     */
    private function decrypt($encrypted_value) {
        if (empty($encrypted_value)) {
            return '';
        }

        $key = $this->get_encryption_key();
        
        // Base64 decode
        $data = base64_decode($encrypted_value);
        if ($data === false) {
            return '';
        }

        // Extract IV
        $iv_length = openssl_cipher_iv_length('aes-256-cbc');
        if (strlen($data) < $iv_length) {
            return '';
        }
        
        $iv = substr($data, 0, $iv_length);
        $encrypted = substr($data, $iv_length);

        // Decrypt
        $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        
        if ($decrypted === false) {
            error_log('[RawWire Key Manager] Decryption failed');
            return '';
        }

        return $decrypted;
    }

    // =========================================================================
    // KEY CRUD OPERATIONS
    // =========================================================================

    /**
     * Get an API key
     * 
     * @param string $key_id Key identifier
     * @return string Decrypted key value (empty if not set)
     */
    public function get_key($key_id) {
        // Check runtime cache
        if (isset($this->key_cache[$key_id])) {
            return $this->key_cache[$key_id];
        }

        $stored = get_option(self::OPTION_KEY, []);
        
        if (!isset($stored[$key_id])) {
            return '';
        }

        $decrypted = $this->decrypt($stored[$key_id]);
        
        // Cache for this request
        $this->key_cache[$key_id] = $decrypted;
        
        return $decrypted;
    }

    /**
     * Set an API key
     * 
     * @param string $key_id Key identifier
     * @param string $value  Key value (will be encrypted)
     * @return bool Success
     */
    public function set_key($key_id, $value) {
        $stored = get_option(self::OPTION_KEY, []);
        
        if (empty($value)) {
            unset($stored[$key_id]);
        } else {
            $stored[$key_id] = $this->encrypt($value);
        }
        
        // Update storage
        $result = update_option(self::OPTION_KEY, $stored);
        
        // Update metadata
        $this->update_key_meta($key_id, [
            'updated_at' => current_time('mysql'),
            'has_value'  => !empty($value),
        ]);
        
        // Clear cache
        unset($this->key_cache[$key_id]);
        
        return $result;
    }

    /**
     * Delete an API key
     * 
     * @param string $key_id Key identifier
     * @return bool Success
     */
    public function delete_key($key_id) {
        return $this->set_key($key_id, '');
    }

    /**
     * Check if a key is configured
     * 
     * @param string $key_id Key identifier
     * @return bool
     */
    public function has_key($key_id) {
        $meta = $this->get_key_meta($key_id);
        return !empty($meta['has_value']);
    }

    /**
     * Get masked version of key for display
     * 
     * @param string $key_id Key identifier
     * @return string Masked key (e.g., "sk-***abc123")
     */
    public function get_masked_key($key_id) {
        $key = $this->get_key($key_id);
        
        if (empty($key)) {
            return '';
        }

        $length = strlen($key);
        
        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        // Show first 3 and last 4 characters
        return substr($key, 0, 3) . str_repeat('*', $length - 7) . substr($key, -4);
    }

    // =========================================================================
    // KEY METADATA
    // =========================================================================

    /**
     * Get key metadata
     * 
     * @param string $key_id Key identifier
     * @return array
     */
    public function get_key_meta($key_id) {
        $all_meta = get_option(self::META_KEY, []);
        return $all_meta[$key_id] ?? [
            'has_value'   => false,
            'updated_at'  => null,
            'last_tested' => null,
            'test_result' => null,
        ];
    }

    /**
     * Update key metadata
     * 
     * @param string $key_id Key identifier
     * @param array  $meta   Metadata to merge
     */
    private function update_key_meta($key_id, $meta) {
        $all_meta = get_option(self::META_KEY, []);
        $all_meta[$key_id] = array_merge($this->get_key_meta($key_id), $meta);
        update_option(self::META_KEY, $all_meta);
    }

    /**
     * Get status of all keys
     * 
     * @param string|null $filter_by Filter by component
     * @return array
     */
    public function get_all_key_status($filter_by = null) {
        $definitions = $this->get_key_definitions($filter_by);
        $status = [];

        foreach ($definitions as $key_id => $def) {
            $meta = $this->get_key_meta($key_id);
            
            $status[$key_id] = [
                'key_id'      => $key_id,
                'name'        => $def['name'],
                'description' => $def['description'],
                'signup_url'  => $def['signup_url'],
                'configured'  => $meta['has_value'],
                'masked_key'  => $meta['has_value'] ? $this->get_masked_key($key_id) : '',
                'updated_at'  => $meta['updated_at'],
                'last_tested' => $meta['last_tested'],
                'test_result' => $meta['test_result'],
                'managed_by'  => $def['managed_by'] ?? null,
                'required_by' => $def['required_by'],
            ];
        }

        return $status;
    }

    // =========================================================================
    // KEY TESTING
    // =========================================================================

    /**
     * Test an API key
     * 
     * @param string $key_id Key identifier
     * @return array Test result
     */
    public function test_key($key_id) {
        $def = $this->key_definitions[$key_id] ?? null;
        
        if (!$def) {
            return ['success' => false, 'message' => 'Unknown key type'];
        }

        $key = $this->get_key($key_id);
        
        if (empty($key)) {
            return ['success' => false, 'message' => 'Key not configured'];
        }

        if (empty($def['test_url'])) {
            // Can't test, assume valid
            $this->update_key_meta($key_id, [
                'last_tested' => current_time('mysql'),
                'test_result' => 'untestable',
            ]);
            return ['success' => true, 'message' => 'Key saved (no test endpoint available)'];
        }

        // Build test request
        $args = [
            'timeout' => 15,
            'headers' => ['Accept' => 'application/json'],
        ];

        $url = $def['test_url'];

        switch ($def['test_method']) {
            case 'header':
                $args['headers'][$def['test_param']] = $key;
                break;
            
            case 'bearer':
                $args['headers']['Authorization'] = 'Bearer ' . $key;
                break;
            
            case 'query':
                $url = add_query_arg($def['test_param'], $key, $url);
                break;
        }

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            $result = [
                'success' => false,
                'message' => 'Connection failed: ' . $response->get_error_message(),
            ];
        } else {
            $code = wp_remote_retrieve_response_code($response);
            
            if ($code >= 200 && $code < 300) {
                $result = [
                    'success' => true,
                    'message' => 'Key validated successfully',
                ];
            } elseif ($code === 401 || $code === 403) {
                $result = [
                    'success' => false,
                    'message' => 'Invalid or expired API key',
                ];
            } else {
                $result = [
                    'success' => false,
                    'message' => "API returned status {$code}",
                ];
            }
        }

        // Update metadata
        $this->update_key_meta($key_id, [
            'last_tested' => current_time('mysql'),
            'test_result' => $result['success'] ? 'valid' : 'invalid',
        ]);

        return $result;
    }

    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================

    /**
     * AJAX: Save API key
     */
    public function ajax_save_key() {
        check_ajax_referer('rawwire_key_manager', '_wpnonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $key_id = sanitize_key($_POST['key_id'] ?? '');
        $value = sanitize_text_field($_POST['value'] ?? '');

        if (empty($key_id)) {
            wp_send_json_error(['message' => 'Invalid key ID']);
        }

        $this->set_key($key_id, $value);

        // Optionally test the key
        $test_result = null;
        if (!empty($value) && !empty($_POST['test'])) {
            $test_result = $this->test_key($key_id);
        }

        wp_send_json_success([
            'message'     => empty($value) ? 'Key removed' : 'Key saved',
            'masked_key'  => $this->get_masked_key($key_id),
            'test_result' => $test_result,
        ]);
    }

    /**
     * AJAX: Delete API key
     */
    public function ajax_delete_key() {
        check_ajax_referer('rawwire_key_manager', '_wpnonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $key_id = sanitize_key($_POST['key_id'] ?? '');

        if (empty($key_id)) {
            wp_send_json_error(['message' => 'Invalid key ID']);
        }

        $this->delete_key($key_id);

        wp_send_json_success(['message' => 'Key removed']);
    }

    /**
     * AJAX: Test API key
     */
    public function ajax_test_key() {
        check_ajax_referer('rawwire_key_manager', '_wpnonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $key_id = sanitize_key($_POST['key_id'] ?? '');

        if (empty($key_id)) {
            wp_send_json_error(['message' => 'Invalid key ID']);
        }

        $result = $this->test_key($key_id);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX: Get status of all keys
     */
    public function ajax_get_status() {
        check_ajax_referer('rawwire_key_manager', '_wpnonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $filter = sanitize_key($_POST['filter'] ?? '');
        $status = $this->get_all_key_status($filter ?: null);

        wp_send_json_success($status);
    }
}

// Initialize singleton
RawWire_Key_Manager::get_instance();

/**
 * Helper function to get Key Manager instance
 * 
 * @return RawWire_Key_Manager
 */
function rawwire_keys() {
    return RawWire_Key_Manager::get_instance();
}
