<?php
/**
 * Configuration Authority - Signed Configuration Management
 * 
 * ╔═══════════════════════════════════════════════════════════════════════════╗
 * ║  STOP! THIS IS THE AUTHORIZATION SYSTEM FOR PERSISTENT CONFIG CHANGES     ║
 * ╠═══════════════════════════════════════════════════════════════════════════╣
 * ║                                                                           ║
 * ║  This class provides a SIGNED CONFIGURATION SYSTEM that ensures:          ║
 * ║  • Configuration changes are cryptographically signed                     ║
 * ║  • Only authorized changes can be applied                                 ║
 * ║  • Complete audit trail of all configuration changes                      ║
 * ║  • License/tier-based feature authorization                               ║
 * ║                                                                           ║
 * ║  ARCHITECTURE:                                                            ║
 * ║  ┌─────────────────────────────────────────────────────────────────────┐  ║
 * ║  │ Config Change Request                                               │  ║
 * ║  │   ↓                                                                 │  ║
 * ║  │ Sign with HMAC-SHA256 (site secret + nonce + timestamp)             │  ║
 * ║  │   ↓                                                                 │  ║
 * ║  │ Verify signature before applying                                    │  ║
 * ║  │   ↓                                                                 │  ║
 * ║  │ Check authorization (tier, license, feature gates)                  │  ║
 * ║  │   ↓                                                                 │  ║
 * ║  │ Apply change + record in audit log                                  │  ║
 * ║  └─────────────────────────────────────────────────────────────────────┘  ║
 * ║                                                                           ║
 * ║  USAGE:                                                                   ║
 * ║  $authority = RawWire_Config_Authority::get_instance();                   ║
 * ║  $signed = $authority->sign_config_change($config_key, $value, $context); ║
 * ║  $result = $authority->apply_signed_change($signed);                      ║
 * ║                                                                           ║
 * ╚═══════════════════════════════════════════════════════════════════════════╝
 *
 * @package RawWire_Dashboard
 * @subpackage Core
 * @since 1.0.30
 */

if (!defined('ABSPATH')) {
    exit;
}

class RawWire_Config_Authority {

    /**
     * Singleton instance
     * @var RawWire_Config_Authority|null
     */
    private static $instance = null;

    /**
     * Configuration namespace prefix
     */
    const CONFIG_PREFIX = 'rawwire_';

    /**
     * Audit log option key
     */
    const AUDIT_LOG_KEY = 'rawwire_config_audit_log';

    /**
     * License key option
     */
    const LICENSE_KEY = 'rawwire_license_key';

    /**
     * Site secret option (auto-generated on first use)
     */
    const SITE_SECRET_KEY = 'rawwire_site_secret';

    /**
     * Signature algorithm
     */
    const SIGNATURE_ALGO = 'sha256';

    /**
     * Signature validity window (seconds)
     */
    const SIGNATURE_TTL = 300; // 5 minutes

    /**
     * Authorization tiers (from lowest to highest)
     */
    const TIER_FREE = 'free';
    const TIER_BASIC = 'basic';
    const TIER_PRO = 'pro';
    const TIER_ENTERPRISE = 'enterprise';
    const TIER_DEVELOPER = 'developer';

    /**
     * Tier hierarchy (higher index = more permissions)
     */
    private static $tier_hierarchy = array(
        self::TIER_FREE       => 0,
        self::TIER_BASIC      => 1,
        self::TIER_PRO        => 2,
        self::TIER_ENTERPRISE => 3,
        self::TIER_DEVELOPER  => 4,
    );

    /**
     * Feature authorization matrix
     * Maps features to minimum required tier
     */
    private static $feature_tiers = array(
        // Core features - available to all
        'dashboard'          => self::TIER_FREE,
        'templates'          => self::TIER_FREE,
        'settings'           => self::TIER_FREE,
        
        // Basic features
        'tools'              => self::TIER_BASIC,
        'workflows'          => self::TIER_BASIC,
        'ai_settings'        => self::TIER_BASIC,
        
        // Pro features
        'ai_scraper'         => self::TIER_PRO,
        'workflow_db'        => self::TIER_PRO,
        'collection_workflow'=> self::TIER_PRO,
        'custom_panels'      => self::TIER_PRO,
        'custom_tools'       => self::TIER_PRO,
        
        // Enterprise features
        'multi_site'         => self::TIER_ENTERPRISE,
        'white_label'        => self::TIER_ENTERPRISE,
        'api_access'         => self::TIER_ENTERPRISE,
        
        // Developer features (all access)
        'developer_tools'    => self::TIER_DEVELOPER,
        'debug_mode'         => self::TIER_DEVELOPER,
        'raw_config_edit'    => self::TIER_DEVELOPER,
    );

    /**
     * Protected configuration keys that require signing
     */
    private static $protected_configs = array(
        'rawwire_active_template',
        'rawwire_template_settings_*',
        'rawwire_license_key',
        'rawwire_enabled_features',
        'rawwire_tool_toggles',
        'rawwire_api_keys',
        'rawwire_mcp_settings',
        'rawwire_ai_adapter_settings',
        'rawwire_custom_tools',
        'rawwire_custom_panels',
    );

    /**
     * Get singleton instance
     * 
     * @return RawWire_Config_Authority
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor
     */
    private function __construct() {
        $this->ensure_site_secret();
        
        // Hook into option updates to enforce signing for protected configs
        add_filter('pre_update_option', array($this, 'intercept_option_update'), 10, 3);
    }

    /**
     * Ensure site secret exists (generates on first use)
     */
    private function ensure_site_secret() {
        $secret = get_option(self::SITE_SECRET_KEY);
        if (empty($secret)) {
            $secret = $this->generate_site_secret();
            update_option(self::SITE_SECRET_KEY, $secret, false);
        }
    }

    /**
     * Generate a cryptographically secure site secret
     * 
     * @return string
     */
    private function generate_site_secret() {
        // Combine multiple entropy sources
        $entropy = array(
            wp_generate_password(64, true, true),
            AUTH_KEY ?? '',
            SECURE_AUTH_KEY ?? '',
            get_site_url(),
            microtime(true),
            random_bytes(32),
        );
        
        return hash('sha512', implode('|', $entropy));
    }

    /**
     * Get the site secret
     * 
     * @return string
     */
    private function get_site_secret() {
        return get_option(self::SITE_SECRET_KEY, '');
    }

    /**
     * ╔═══════════════════════════════════════════════════════════════════════╗
     * ║  SIGN A CONFIGURATION CHANGE                                          ║
     * ║                                                                       ║
     * ║  Creates a signed payload that can be verified before applying.       ║
     * ║  The signature includes: config key, value hash, timestamp, nonce.    ║
     * ╚═══════════════════════════════════════════════════════════════════════╝
     * 
     * @param string $config_key The configuration key to change
     * @param mixed  $value      The new value
     * @param array  $context    Additional context (user_id, reason, etc.)
     * @return array Signed change payload
     */
    public function sign_config_change($config_key, $value, $context = array()) {
        $timestamp = time();
        $nonce = wp_create_nonce('rawwire_config_' . $config_key);
        $value_hash = hash(self::SIGNATURE_ALGO, serialize($value));
        
        // Build the payload
        $payload = array(
            'config_key'  => $config_key,
            'value'       => $value,
            'value_hash'  => $value_hash,
            'timestamp'   => $timestamp,
            'nonce'       => $nonce,
            'user_id'     => get_current_user_id(),
            'user_role'   => $this->get_user_tier(),
            'context'     => wp_parse_args($context, array(
                'reason'   => '',
                'source'   => 'admin_ui',
                'ip'       => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            )),
        );
        
        // Generate signature
        $signature_data = $config_key . '|' . $value_hash . '|' . $timestamp . '|' . $nonce;
        $signature = hash_hmac(self::SIGNATURE_ALGO, $signature_data, $this->get_site_secret());
        
        $payload['signature'] = $signature;
        
        return $payload;
    }

    /**
     * ╔═══════════════════════════════════════════════════════════════════════╗
     * ║  VERIFY A SIGNED CONFIGURATION CHANGE                                 ║
     * ║                                                                       ║
     * ║  Checks signature validity, timestamp freshness, and authorization.   ║
     * ╚═══════════════════════════════════════════════════════════════════════╝
     * 
     * @param array $signed_payload The signed payload from sign_config_change()
     * @return array ['valid' => bool, 'error' => string|null]
     */
    public function verify_signed_change($signed_payload) {
        // Check required fields
        $required = array('config_key', 'value', 'value_hash', 'timestamp', 'nonce', 'signature');
        foreach ($required as $field) {
            if (!isset($signed_payload[$field])) {
                return array('valid' => false, 'error' => "Missing required field: {$field}");
            }
        }
        
        $config_key = $signed_payload['config_key'];
        $value = $signed_payload['value'];
        $value_hash = $signed_payload['value_hash'];
        $timestamp = $signed_payload['timestamp'];
        $nonce = $signed_payload['nonce'];
        $signature = $signed_payload['signature'];
        
        // Verify timestamp (within TTL window)
        if (abs(time() - $timestamp) > self::SIGNATURE_TTL) {
            return array('valid' => false, 'error' => 'Signature expired');
        }
        
        // Verify nonce
        if (!wp_verify_nonce($nonce, 'rawwire_config_' . $config_key)) {
            return array('valid' => false, 'error' => 'Invalid nonce');
        }
        
        // Verify value hash
        $computed_hash = hash(self::SIGNATURE_ALGO, serialize($value));
        if (!hash_equals($value_hash, $computed_hash)) {
            return array('valid' => false, 'error' => 'Value hash mismatch - data may have been tampered');
        }
        
        // Verify signature
        $signature_data = $config_key . '|' . $value_hash . '|' . $timestamp . '|' . $nonce;
        $expected_signature = hash_hmac(self::SIGNATURE_ALGO, $signature_data, $this->get_site_secret());
        
        if (!hash_equals($expected_signature, $signature)) {
            return array('valid' => false, 'error' => 'Invalid signature');
        }
        
        // Check authorization (user has permission for this config)
        $auth_result = $this->check_config_authorization($config_key, $signed_payload['user_role'] ?? null);
        if (!$auth_result['authorized']) {
            return array('valid' => false, 'error' => $auth_result['error']);
        }
        
        return array('valid' => true, 'error' => null);
    }

    /**
     * ╔═══════════════════════════════════════════════════════════════════════╗
     * ║  APPLY A SIGNED CONFIGURATION CHANGE                                  ║
     * ║                                                                       ║
     * ║  Verifies and applies the change, recording it in the audit log.      ║
     * ╚═══════════════════════════════════════════════════════════════════════╝
     * 
     * @param array $signed_payload The signed payload
     * @return array ['success' => bool, 'error' => string|null]
     */
    public function apply_signed_change($signed_payload) {
        // Verify first
        $verification = $this->verify_signed_change($signed_payload);
        if (!$verification['valid']) {
            $this->log_audit_event('change_rejected', $signed_payload, $verification['error']);
            return array('success' => false, 'error' => $verification['error']);
        }
        
        $config_key = $signed_payload['config_key'];
        $value = $signed_payload['value'];
        
        // Store the old value for audit
        $old_value = get_option($config_key);
        
        // Apply the change (bypass our own filter)
        remove_filter('pre_update_option', array($this, 'intercept_option_update'), 10);
        $result = update_option($config_key, $value);
        add_filter('pre_update_option', array($this, 'intercept_option_update'), 10, 3);
        
        if ($result) {
            $this->log_audit_event('change_applied', $signed_payload, null, $old_value);
            return array('success' => true, 'error' => null);
        } else {
            $this->log_audit_event('change_failed', $signed_payload, 'update_option returned false');
            return array('success' => false, 'error' => 'Failed to update option');
        }
    }

    /**
     * Check if a configuration change is authorized
     * 
     * @param string      $config_key The configuration key
     * @param string|null $user_tier  The user's tier (or auto-detect)
     * @return array ['authorized' => bool, 'error' => string|null]
     */
    public function check_config_authorization($config_key, $user_tier = null) {
        if ($user_tier === null) {
            $user_tier = $this->get_user_tier();
        }
        
        // Developer tier can do anything
        if ($user_tier === self::TIER_DEVELOPER) {
            return array('authorized' => true, 'error' => null);
        }
        
        // Check if config requires a specific tier
        $required_tier = $this->get_required_tier_for_config($config_key);
        
        if (!$this->tier_meets_requirement($user_tier, $required_tier)) {
            return array(
                'authorized' => false,
                'error' => "Tier '{$user_tier}' does not have permission for this configuration. Required: {$required_tier}",
            );
        }
        
        return array('authorized' => true, 'error' => null);
    }

    /**
     * Get required tier for a configuration key
     * 
     * @param string $config_key
     * @return string
     */
    private function get_required_tier_for_config($config_key) {
        // Map config keys to features
        $config_to_feature = array(
            'rawwire_active_template'     => 'templates',
            'rawwire_template_settings_*' => 'templates',
            'rawwire_license_key'         => 'settings',
            'rawwire_enabled_features'    => 'settings',
            'rawwire_tool_toggles'        => 'developer_tools',
            'rawwire_api_keys'            => 'ai_settings',
            'rawwire_mcp_settings'        => 'developer_tools',
            'rawwire_ai_adapter_settings' => 'ai_settings',
            'rawwire_custom_tools'        => 'custom_tools',
            'rawwire_custom_panels'       => 'custom_panels',
        );
        
        // Check exact match first
        if (isset($config_to_feature[$config_key])) {
            $feature = $config_to_feature[$config_key];
            return self::$feature_tiers[$feature] ?? self::TIER_PRO;
        }
        
        // Check wildcard patterns
        foreach ($config_to_feature as $pattern => $feature) {
            if (strpos($pattern, '*') !== false) {
                $regex = '/^' . str_replace('*', '.*', preg_quote($pattern, '/')) . '$/';
                if (preg_match($regex, $config_key)) {
                    return self::$feature_tiers[$feature] ?? self::TIER_PRO;
                }
            }
        }
        
        // Default to PRO tier for unknown protected configs
        return self::TIER_PRO;
    }

    /**
     * Check if a tier meets a requirement
     * 
     * @param string $user_tier
     * @param string $required_tier
     * @return bool
     */
    private function tier_meets_requirement($user_tier, $required_tier) {
        $user_level = self::$tier_hierarchy[$user_tier] ?? 0;
        $required_level = self::$tier_hierarchy[$required_tier] ?? 0;
        return $user_level >= $required_level;
    }

    /**
     * Get current user's tier
     * 
     * @return string
     */
    public function get_user_tier() {
        // Check if Access Control class exists
        if (class_exists('RawWire_Access_Control')) {
            $tier = RawWire_Access_Control::get_instance()->get_user_tier();
            // Map Access Control tiers to Config Authority tiers
            $tier_map = array(
                'developer' => self::TIER_DEVELOPER,
                'admin'     => self::TIER_ENTERPRISE,
                'editor'    => self::TIER_PRO,
                'viewer'    => self::TIER_BASIC,
            );
            return $tier_map[$tier] ?? self::TIER_FREE;
        }
        
        // Fallback: check WordPress capabilities
        if (current_user_can('manage_options')) {
            // Check for developer flag
            if (get_user_meta(get_current_user_id(), 'rawwire_developer', true)) {
                return self::TIER_DEVELOPER;
            }
            return self::TIER_ENTERPRISE;
        }
        
        if (current_user_can('edit_posts')) {
            return self::TIER_PRO;
        }
        
        if (current_user_can('read')) {
            return self::TIER_BASIC;
        }
        
        return self::TIER_FREE;
    }

    /**
     * Check if a feature is authorized for the current user
     * 
     * @param string $feature_id
     * @return bool
     */
    public function is_feature_authorized($feature_id) {
        $user_tier = $this->get_user_tier();
        $required_tier = self::$feature_tiers[$feature_id] ?? self::TIER_PRO;
        return $this->tier_meets_requirement($user_tier, $required_tier);
    }

    /**
     * Get all authorized features for current user
     * 
     * @return array
     */
    public function get_authorized_features() {
        $user_tier = $this->get_user_tier();
        $authorized = array();
        
        foreach (self::$feature_tiers as $feature => $required_tier) {
            if ($this->tier_meets_requirement($user_tier, $required_tier)) {
                $authorized[] = $feature;
            }
        }
        
        return $authorized;
    }

    /**
     * Intercept option updates for protected configs
     * 
     * @param mixed  $value
     * @param string $option
     * @param mixed  $old_value
     * @return mixed
     */
    public function intercept_option_update($value, $option, $old_value) {
        // Check if this is a protected config
        if (!$this->is_protected_config($option)) {
            return $value;
        }
        
        // Check if this is an authorized change (signed)
        // If the value is an array with a __signed_change key, it's going through our system
        if (is_array($value) && isset($value['__signed_change'])) {
            // Already processed by apply_signed_change
            return $value['__signed_change'];
        }
        
        // For direct updates, check authorization
        $auth = $this->check_config_authorization($option);
        if (!$auth['authorized']) {
            // Log unauthorized attempt
            $this->log_audit_event('unauthorized_attempt', array(
                'config_key' => $option,
                'value'      => $value,
                'user_id'    => get_current_user_id(),
            ), $auth['error']);
            
            // Return old value to prevent change
            return $old_value;
        }
        
        // Log the direct (but authorized) change
        $this->log_audit_event('direct_change', array(
            'config_key' => $option,
            'value'      => $value,
            'user_id'    => get_current_user_id(),
            'context'    => array('source' => 'direct_update'),
        ), null, $old_value);
        
        return $value;
    }

    /**
     * Check if a config key is protected
     * 
     * @param string $config_key
     * @return bool
     */
    private function is_protected_config($config_key) {
        foreach (self::$protected_configs as $pattern) {
            if ($pattern === $config_key) {
                return true;
            }
            if (strpos($pattern, '*') !== false) {
                $regex = '/^' . str_replace('*', '.*', preg_quote($pattern, '/')) . '$/';
                if (preg_match($regex, $config_key)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * ╔═══════════════════════════════════════════════════════════════════════╗
     * ║  AUDIT LOG                                                            ║
     * ║                                                                       ║
     * ║  All configuration changes are logged for security and debugging.     ║
     * ╚═══════════════════════════════════════════════════════════════════════╝
     */
    
    /**
     * Log an audit event
     * 
     * @param string      $event_type  Event type (change_applied, change_rejected, etc.)
     * @param array       $payload     The change payload
     * @param string|null $error       Error message if any
     * @param mixed       $old_value   Previous value (for changes)
     */
    private function log_audit_event($event_type, $payload, $error = null, $old_value = null) {
        $log = get_option(self::AUDIT_LOG_KEY, array());
        
        // Keep only last 100 entries
        if (count($log) >= 100) {
            $log = array_slice($log, -99);
        }
        
        $entry = array(
            'timestamp'   => current_time('mysql'),
            'event_type'  => $event_type,
            'config_key'  => $payload['config_key'] ?? 'unknown',
            'user_id'     => $payload['user_id'] ?? get_current_user_id(),
            'user_tier'   => $payload['user_role'] ?? $this->get_user_tier(),
            'context'     => $payload['context'] ?? array(),
            'error'       => $error,
            'value_hash'  => $payload['value_hash'] ?? null,
        );
        
        // Don't log actual values (security) - just hashes
        if ($old_value !== null) {
            $entry['old_value_hash'] = hash(self::SIGNATURE_ALGO, serialize($old_value));
        }
        
        $log[] = $entry;
        
        update_option(self::AUDIT_LOG_KEY, $log, false);
        
        // Also write to error log for immediate visibility
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[RawWire Config Authority] %s: %s (user: %d, tier: %s)%s',
                $event_type,
                $entry['config_key'],
                $entry['user_id'],
                $entry['user_tier'],
                $error ? " - Error: {$error}" : ''
            ));
        }
    }

    /**
     * Get audit log
     * 
     * @param int $limit Number of entries to return
     * @return array
     */
    public function get_audit_log($limit = 50) {
        $log = get_option(self::AUDIT_LOG_KEY, array());
        return array_slice(array_reverse($log), 0, $limit);
    }

    /**
     * Clear audit log (developer only)
     * 
     * @return bool
     */
    public function clear_audit_log() {
        if ($this->get_user_tier() !== self::TIER_DEVELOPER) {
            return false;
        }
        return delete_option(self::AUDIT_LOG_KEY);
    }

    /**
     * ╔═══════════════════════════════════════════════════════════════════════╗
     * ║  LICENSE VERIFICATION                                                 ║
     * ║                                                                       ║
     * ║  Validates license keys and determines feature entitlements.          ║
     * ╚═══════════════════════════════════════════════════════════════════════╝
     */
    
    /**
     * Verify a license key
     * 
     * @param string $license_key
     * @return array ['valid' => bool, 'tier' => string, 'features' => array, 'expires' => string|null]
     */
    public function verify_license($license_key) {
        // For now, use a simple format: TIER-XXXXXXXX-CHECKSUM
        // In production, this would call a license server
        
        if (empty($license_key)) {
            return array(
                'valid'    => true,
                'tier'     => self::TIER_FREE,
                'features' => $this->get_features_for_tier(self::TIER_FREE),
                'expires'  => null,
            );
        }
        
        // Parse license format: TIER-XXXXXXXX-CHECKSUM
        $parts = explode('-', $license_key);
        if (count($parts) < 3) {
            return array('valid' => false, 'error' => 'Invalid license format');
        }
        
        $tier_code = strtoupper($parts[0]);
        $license_id = $parts[1];
        $checksum = end($parts);
        
        // Map tier codes
        $tier_codes = array(
            'FREE' => self::TIER_FREE,
            'BASIC' => self::TIER_BASIC,
            'PRO' => self::TIER_PRO,
            'ENT' => self::TIER_ENTERPRISE,
            'DEV' => self::TIER_DEVELOPER,
        );
        
        $tier = $tier_codes[$tier_code] ?? self::TIER_FREE;
        
        // Verify checksum (simple implementation)
        $expected_checksum = substr(hash('sha256', $tier_code . '-' . $license_id . '-' . AUTH_KEY), 0, 8);
        
        if (!hash_equals($expected_checksum, $checksum)) {
            return array('valid' => false, 'error' => 'Invalid license checksum');
        }
        
        return array(
            'valid'    => true,
            'tier'     => $tier,
            'features' => $this->get_features_for_tier($tier),
            'expires'  => null, // Could add expiration logic
        );
    }

    /**
     * Get features available for a tier
     * 
     * @param string $tier
     * @return array
     */
    public function get_features_for_tier($tier) {
        $features = array();
        
        foreach (self::$feature_tiers as $feature => $required_tier) {
            if ($this->tier_meets_requirement($tier, $required_tier)) {
                $features[] = $feature;
            }
        }
        
        return $features;
    }

    /**
     * Generate a license key (developer utility)
     * 
     * @param string $tier
     * @return string
     */
    public function generate_license_key($tier = self::TIER_PRO) {
        if ($this->get_user_tier() !== self::TIER_DEVELOPER) {
            return '';
        }
        
        $tier_codes = array(
            self::TIER_FREE       => 'FREE',
            self::TIER_BASIC      => 'BASIC',
            self::TIER_PRO        => 'PRO',
            self::TIER_ENTERPRISE => 'ENT',
            self::TIER_DEVELOPER  => 'DEV',
        );
        
        $tier_code = $tier_codes[$tier] ?? 'FREE';
        $license_id = strtoupper(wp_generate_password(8, false));
        $checksum = substr(hash('sha256', $tier_code . '-' . $license_id . '-' . AUTH_KEY), 0, 8);
        
        return $tier_code . '-' . $license_id . '-' . strtoupper($checksum);
    }

    /**
     * ╔═══════════════════════════════════════════════════════════════════════╗
     * ║  TEMPLATE AUTHORIZATION                                               ║
     * ║                                                                       ║
     * ║  Check if a template's requirements are met by the current license.   ║
     * ╚═══════════════════════════════════════════════════════════════════════╝
     */
    
    /**
     * Check if a template is authorized for the current user/license
     * 
     * @param array $template The template configuration
     * @return array ['authorized' => bool, 'missing_features' => array]
     */
    public function check_template_authorization($template) {
        $user_tier = $this->get_user_tier();
        $authorized_features = $this->get_authorized_features();
        
        // Get required features from template
        $required_features = $template['authorization']['required_features'] ?? array();
        $required_tier = $template['authorization']['required_tier'] ?? self::TIER_FREE;
        
        // Check tier requirement
        if (!$this->tier_meets_requirement($user_tier, $required_tier)) {
            return array(
                'authorized' => false,
                'error' => "This template requires tier '{$required_tier}' or higher. Your tier: '{$user_tier}'",
                'missing_features' => array(),
            );
        }
        
        // Check feature requirements
        $missing = array_diff($required_features, $authorized_features);
        
        if (!empty($missing)) {
            return array(
                'authorized' => false,
                'error' => 'Missing required features: ' . implode(', ', $missing),
                'missing_features' => $missing,
            );
        }
        
        return array(
            'authorized' => true,
            'error' => null,
            'missing_features' => array(),
        );
    }
}

// Initialize on plugins_loaded
add_action('plugins_loaded', function() {
    RawWire_Config_Authority::get_instance();
}, 5);
