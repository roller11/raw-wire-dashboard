<?php
/**
 * Base Adapter Interface
 * All toolbox adapters must implement this interface.
 * 
 * @package RawWire_Dashboard
 * @subpackage Toolbox_Core
 */
if (!defined('ABSPATH')) {
    exit;
}

interface RawWire_Adapter_Interface {
    /**
     * Initialize the adapter with configuration
     * 
     * @param array $config Configuration from template/user settings
     * @return void
     */
    public function __construct(array $config);

    /**
     * Validate the adapter configuration
     * 
     * @return bool|WP_Error True if valid, WP_Error with details if not
     */
    public function validate_config();

    /**
     * Test the adapter connection/credentials
     * 
     * @return array{success: bool, message: string, details?: array}
     */
    public function test_connection();

    /**
     * Get adapter metadata
     * 
     * @return array{name: string, version: string, tier: string, capabilities: array}
     */
    public function get_info();

    /**
     * Check if a specific capability is supported
     * 
     * @param string $capability The capability to check
     * @return bool
     */
    public function supports(string $capability);

    /**
     * Get the last error that occurred
     * 
     * @return WP_Error|null
     */
    public function get_last_error();
}
