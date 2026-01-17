<?php
/**
 * Auth tests
 */

class RawWire_Auth_Test extends WP_UnitTestCase {
    public function setUp(): void {
        parent::setUp();
        // Ensure auth functions are available
        require_once plugin_dir_path(__DIR__) . 'includes/auth.php';
    }

    public function test_generate_and_validate_key() {
        // Generate key
        $key = rawwire_generate_api_key('read', 'test');
        $this->assertNotEmpty($key);

        // Info should now indicate exists
        $info = rawwire_get_api_key_info();
        $this->assertNotNull($info);
        $this->assertEquals('read', $info['scope']);

        // Validate should succeed
        $this->assertTrue(rawwire_validate_api_token($key, 'read'));

        // Wrong key fails
        $this->assertFalse(rawwire_validate_api_token('wrong-key', 'read'));

        // Revoke
        $this->assertTrue(rawwire_revoke_api_key());
        $this->assertNull(rawwire_get_api_key_info());
    }

    public function test_legacy_plaintext_migration() {
        // Write a legacy plaintext key and ensure validate migrates to hashed storage
        update_option('rawwire_api_key', 'legacy-test-key');
        update_option('rawwire_api_key_scope', 'read');

        $this->assertTrue(rawwire_validate_api_token('legacy-test-key', 'read'));

        // After validation, legacy option should be removed and hash present
        $this->assertEmpty(get_option('rawwire_api_key', ''));
        $this->assertNotEmpty(get_option('rawwire_api_key_hash', ''));
    }
}
