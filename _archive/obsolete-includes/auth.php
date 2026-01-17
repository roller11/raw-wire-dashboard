<?php
/**
 * REST API Authentication Helper
 * 
 * Provides flexible authentication for REST API endpoints:
 * - Browser/admin: WordPress user capabilities and nonces
 * - External clients: Bearer token authentication
 * 
 * @package RawWire_Dashboard
 * @since 1.0.0
 */

if (!defined("ABSPATH")) {
    exit;
}

/**
 * Check if REST API request is authorized
 * 
 * @param WP_REST_Request $request Request object
 * @param string $scope Authorization scope (read|write)
 * @return bool True if authorized, false otherwise
 */
function rawwire_v111_rest_is_authorized(WP_REST_Request $request, string $scope = "read"): bool {
    // Check if user is logged in (admin/dashboard access)
    if (is_user_logged_in()) {
        if ("write" === $scope) {
            // Write operations require manage_options capability
            return current_user_can("manage_options");
        }
        // Read operations allowed for logged-in users
        return true;
    }
    
    // Check Bearer token for external API clients
    $token = rawwire_get_bearer_token();
    
    if (!empty($token)) {
        return rawwire_validate_api_token($token, $scope);
    }
    
    // No authentication provided
    return false;
}

/**
 * Extract Bearer token from request headers
 * 
 * @return string|null Token or null if not found
 */
function rawwire_v111_get_bearer_token() {
    $headers = array();
    
    // Try getallheaders() first
    if (function_exists("getallheaders")) {
        $headers = getallheaders();
    }
    
    // Check Authorization header
    $auth = "";
    if (isset($headers["Authorization"])) {
        $auth = (string)$headers["Authorization"];
    } elseif (isset($_SERVER["HTTP_AUTHORIZATION"])) {
        $auth = (string)$_SERVER["HTTP_AUTHORIZATION"];
    } elseif (isset($_SERVER["REDIRECT_HTTP_AUTHORIZATION"])) {
        $auth = (string)$_SERVER["REDIRECT_HTTP_AUTHORIZATION"];
    }
    
    // Extract Bearer token
    if (0 === strpos($auth, "Bearer ")) {
        return trim(substr($auth, 7));
    }
    
    return null;
}

/**
 * Validate API token against stored tokens
 * 
 * @param string $token Token to validate
 * @param string $scope Authorization scope
 * @return bool True if valid, false otherwise
 */
function rawwire_v111_validate_api_token(string $token, string $scope = "read"): bool {
    // Prefer hashed storage (password_hash)
    $hash = get_option("rawwire_api_key_hash", "");
    $legacy = get_option("rawwire_api_key", ""); // legacy plaintext (migrated on first use)

    if (empty($hash) && empty($legacy)) {
        return false;
    }

    $valid = false;
    if (!empty($hash)) {
        if (password_verify($token, $hash)) {
            $valid = true;
        }
    } elseif (!empty($legacy)) {
        // Legacy plaintext option present
        if (hash_equals((string)$legacy, (string)$token)) {
            $valid = true;
            // Migrate to hashed storage
            $new_hash = password_hash($token, PASSWORD_DEFAULT);
            update_option("rawwire_api_key_hash", $new_hash);
            delete_option("rawwire_api_key");
        }
    }

    if (!$valid) {
        return false;
    }

    // Check if token has required scope
    $token_scope = get_option("rawwire_api_key_scope", "read");
    if ("write" === $scope && "read" === $token_scope) {
        return false;
    }

    // Log successful API authentication (store hashed token hash for privacy)
    rawwire_log_api_access(hash('sha256', $token), $scope, true);

    return true;
}

/**
 * Generate a new API key
 * 
 * @param string $scope Key scope (read|write)
 * @param string $description Optional key description
 * @return string Generated API key
 */
function rawwire_v111_generate_api_key(string $scope = "read", string $description = ""): string {
    // Generate secure random token and store only a hashed representation
    $key = bin2hex(random_bytes(32));
    $hash = password_hash($key, PASSWORD_DEFAULT);

    update_option("rawwire_api_key_hash", $hash);
    update_option("rawwire_api_key_scope", $scope);
    update_option("rawwire_api_key_created", current_time("mysql"));

    if (!empty($description)) {
        update_option("rawwire_api_key_description", sanitize_text_field($description));
    }

    // Provide a preview and log generation (do not store raw key beyond return value)
    do_action("rawwire_api_key_generated", substr($key, 0, 8) . '...' . substr($key, -8), $scope);

    // Return raw key to caller (show once responsibly)
    return $key;
}

/**
 * Revoke current API key
 * 
 * @return bool True if revoked, false if no key existed
 */
function rawwire_v111_revoke_api_key(): bool {
    $existing_hash = get_option("rawwire_api_key_hash", "");
    if (empty($existing_hash) && empty(get_option("rawwire_api_key", ""))) {
        return false;
    }

    // Delete hashed key and metadata
    delete_option("rawwire_api_key_hash");
    delete_option("rawwire_api_key_scope");
    delete_option("rawwire_api_key_created");
    delete_option("rawwire_api_key_description");

    // Log key revocation
    do_action("rawwire_api_key_revoked", true);

    return true;
}

/**
 * Get API key information (without exposing the actual key)
 * 
 * @return array|null Key info or null if no key exists
 */
function rawwire_v111_get_api_key_info() {
    $hash = get_option("rawwire_api_key_hash", "");
    $legacy = get_option("rawwire_api_key", "");

    if (empty($hash) && empty($legacy)) {
        return null;
    }

    // Show limited information without exposing the raw key
    return array(
        "exists" => true,
        "scope" => get_option("rawwire_api_key_scope", "read"),
        "created" => get_option("rawwire_api_key_created", ""),
        "description" => get_option("rawwire_api_key_description", ""),
        "preview" => $legacy ? substr($legacy, 0, 8) . '...' . substr($legacy, -8) : 'Stored (show on generate only)'
    );
}

/**
 * Log API access for security monitoring
 * 
 * @param string $token Token used (hashed)
 * @param string $scope Scope requested
 * @param bool $success Whether authentication succeeded
 * @return void
 */
function rawwire_v111_log_api_access(string $token, string $scope, bool $success): void {
    // Only log if logging is enabled
    if (!get_option("rawwire_log_api_access", false)) {
        return;
    }
    
    global $wpdb;
    $table = $wpdb->prefix . "rawwire_api_access_log";
    
    // Check if table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") != $table) {
        return;
    }
    
    // Hash token for security
    $token_hash = hash("sha256", $token);
    
    $wpdb->insert(
        $table,
        array(
            "token_hash" => $token_hash,
            "scope" => $scope,
            "success" => $success ? 1 : 0,
            "ip_address" => isset($_SERVER["REMOTE_ADDR"]) 
                ? sanitize_text_field($_SERVER["REMOTE_ADDR"]) 
                : "",
            "user_agent" => isset($_SERVER["HTTP_USER_AGENT"]) 
                ? sanitize_text_field($_SERVER["HTTP_USER_AGENT"]) 
                : "",
            "accessed_at" => current_time("mysql")
        ),
        array("%s", "%s", "%d", "%s", "%s", "%s")
    );
}

