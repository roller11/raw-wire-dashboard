<?php
/**
 * Admin REST API tests for API keys
 */

class RawWire_Admin_APIKey_REST_Test extends WP_UnitTestCase {
    public function setUp(): void {
        parent::setUp();
        // Ensure routes are registered
        require_once plugin_dir_path(__DIR__) . 'includes/api/class-rest-api-controller.php';
        // Fire REST init to register routes
        do_action('rest_api_init');
    }

    public function test_generate_and_revoke_api_key_via_rest() {
        // Create admin user
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);

        // Generate key
        $req = new WP_REST_Request('POST', '/rawwire/v1/admin/api-key/generate');
        $req->set_body_params(['scope' => 'read', 'description' => 'test']);
        $resp = rest_do_request($req);
        $this->assertEquals(200, $resp->get_status());
        $data = $resp->get_data();
        $this->assertArrayHasKey('key', $data);
        $this->assertNotEmpty($data['key']);

        // Revoke key
        $req2 = new WP_REST_Request('POST', '/rawwire/v1/admin/api-key/revoke');
        $resp2 = rest_do_request($req2);
        $this->assertEquals(200, $resp2->get_status());
        $this->assertTrue($resp2->get_data()['success']);
    }
}
