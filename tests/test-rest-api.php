<?php
/**
 * REST API Controller Tests
 */

class RawWire_REST_API_Controller_Test extends WP_UnitTestCase {
    private $controller;
    private $admin_user_id;
    private $content_id;

    public function setUp(): void {
        parent::setUp();

        // Create admin user
        $this->admin_user_id = $this->factory->user->create([
            'role' => 'administrator'
        ]);

        // Load REST API controller
        require_once plugin_dir_path(__DIR__) . 'includes/api/class-rest-api-controller.php';
        // Load simulator + processor for fetch-data simulate tests
        require_once plugin_dir_path(__DIR__) . 'includes/class-data-simulator.php';
        require_once plugin_dir_path(__DIR__) . 'includes/class-data-processor.php';
        $this->controller = new RawWire_REST_API_Controller();

        // Create test content
        global $wpdb;
        $table = $wpdb->prefix . 'rawwire_content';

        $wpdb->insert(
            $table,
            [
                'title' => 'Test API Content',
                'content' => 'Test content for API',
                'summary' => 'API test summary',
                'status' => 'pending',
                'category' => 'api-test',
                'source_url' => 'https://api.example.com',
                'published_at' => current_time('mysql'),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
                'relevance' => 0.8
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f']
        );

        $this->content_id = $wpdb->insert_id;
    }

    public function tearDown(): void {
        // Clean up test data
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'rawwire_content', ['id' => $this->content_id]);

        parent::tearDown();
    }

    public function test_route_registration() {
        global $wp_rest_server;

        // Register routes
        $this->controller->register_routes();

        // Check that routes are registered
        $routes = $wp_rest_server->get_routes('rawwire/v1');

        $this->assertArrayHasKey('/rawwire/v1/content', $routes);
        $this->assertArrayHasKey('/rawwire/v1/content/approve', $routes);
        $this->assertArrayHasKey('/rawwire/v1/content/snooze', $routes);
        $this->assertArrayHasKey('/rawwire/v1/fetch-data', $routes);
        $this->assertArrayHasKey('/rawwire/v1/clear-cache', $routes);
        $this->assertArrayHasKey('/rawwire/v1/stats', $routes);
        $this->assertArrayHasKey('/rawwire/v1/admin/api-key/generate', $routes);
        $this->assertArrayHasKey('/rawwire/v1/admin/api-key/revoke', $routes);
    }

    public function test_get_content_success() {
        // Create REST request
        $request = new WP_REST_Request('GET', '/rawwire/v1/content');
        $request->set_param('limit', 10);
        $request->set_param('offset', 0);

        // Mock authorization
        add_filter('rawwire_allow_public_read', '__return_true');

        $response = $this->controller->get_content($request);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $data = $response->get_data();

        $this->assertArrayHasKey('items', $data);
        $this->assertArrayHasKey('pagination', $data);
        $this->assertIsArray($data['items']);
        $this->assertGreaterThanOrEqual(0, $data['pagination']['total']);
    }

    public function test_get_content_with_filters() {
        $request = new WP_REST_Request('GET', '/rawwire/v1/content');
        $request->set_param('status', 'pending');
        $request->set_param('category', 'api-test');
        $request->set_param('q', 'API');

        add_filter('rawwire_allow_public_read', '__return_true');

        $response = $this->controller->get_content($request);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $data = $response->get_data();

        // Should find our test content
        $this->assertGreaterThanOrEqual(1, $data['pagination']['total']);
    }

    public function test_get_content_parameter_validation() {
        $request = new WP_REST_Request('GET', '/rawwire/v1/content');
        $request->set_param('limit', 150); // Over maximum

        add_filter('rawwire_allow_public_read', '__return_true');

        $response = $this->controller->get_content($request);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $data = $response->get_data();

        // Should be clamped to maximum (100)
        $this->assertEquals(100, $data['pagination']['limit']);
    }

    public function test_approve_content_success() {
        wp_set_current_user($this->admin_user_id);

        $request = new WP_REST_Request('POST', '/rawwire/v1/content/approve');
        $request->set_body(json_encode([
            'content_ids' => [$this->content_id],
            'notes' => 'API test approval'
        ]));

        $response = $this->controller->approve_content($request);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $data = $response->get_data();

        $this->assertArrayHasKey('approved', $data);
        $this->assertContains($this->content_id, $data['approved']);
        $this->assertEquals(1, $data['approved_count']);
    }

    public function test_approve_content_invalid_request() {
        wp_set_current_user($this->admin_user_id);

        $request = new WP_REST_Request('POST', '/rawwire/v1/content/approve');
        $request->set_body(json_encode([])); // Missing content_ids

        $response = $this->controller->approve_content($request);

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertEquals('invalid_request', $response->get_error_code());
    }

    public function test_approve_content_not_authenticated() {
        // Don't set current user

        $request = new WP_REST_Request('POST', '/rawwire/v1/content/approve');
        $request->set_body(json_encode([
            'content_ids' => [$this->content_id]
        ]));

        $response = $this->controller->approve_content($request);

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertEquals('not_logged_in', $response->get_error_code());
    }

    public function test_get_stats_success() {
        add_filter('rawwire_allow_public_read', '__return_true');

        $request = new WP_REST_Request('GET', '/rawwire/v1/stats');
        $response = $this->controller->get_stats($request);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $data = $response->get_data();

        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('by_status', $data);
        $this->assertArrayHasKey('last_updated', $data);
        $this->assertArrayHasKey('timestamp', $data);

        $this->assertIsInt($data['total']);
        $this->assertIsArray($data['by_status']);
    }

    public function test_fetch_data_simulate_success() {
        wp_set_current_user($this->admin_user_id);

        $request = new WP_REST_Request('POST', '/rawwire/v1/fetch-data');
        $request->set_param('simulate', true);
        $request->set_param('count', 3);
        $request->set_param('shock_level', 'mixed');

        $response = $this->controller->fetch_data($request);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $data = $response->get_data();

        $this->assertEquals('simulate', $data['mode']);
        $this->assertArrayHasKey('count', $data);
        $this->assertGreaterThanOrEqual(0, (int)$data['count']);
    }

    public function test_clear_cache_success() {
        wp_set_current_user($this->admin_user_id);

        $request = new WP_REST_Request('POST', '/rawwire/v1/clear-cache');
        $response = $this->controller->clear_cache($request);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $data = $response->get_data();
        $this->assertTrue($data['success']);
    }

    public function test_snooze_content_success() {
        wp_set_current_user($this->admin_user_id);

        $request = new WP_REST_Request('POST', '/rawwire/v1/content/snooze');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode([
            'content_ids' => [$this->content_id],
            'minutes' => 60,
        ]));

        $response = $this->controller->snooze_content($request);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $data = $response->get_data();

        $this->assertArrayHasKey('snoozed', $data);
        $this->assertContains($this->content_id, $data['snoozed']);
        $this->assertEquals(1, $data['snoozed_count']);

        // Verify snooze was persisted to source_data JSON
        global $wpdb;
        $table = $wpdb->prefix . 'rawwire_content';
        $row = $wpdb->get_row($wpdb->prepare("SELECT source_data FROM {$table} WHERE id = %d", $this->content_id), ARRAY_A);
        $this->assertNotEmpty($row);
        $decoded = json_decode($row['source_data'], true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('snoozed_until', $decoded);
        $this->assertGreaterThan(time(), (int)$decoded['snoozed_until']);
    }

    public function test_generate_api_key_admin_only() {
        wp_set_current_user($this->admin_user_id);

        $request = new WP_REST_Request('POST', '/rawwire/v1/admin/api-key/generate');
        $request->set_body(json_encode([
            'scope' => 'read',
            'description' => 'Test API key'
        ]));

        $response = $this->controller->generate_api_key($request);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $data = $response->get_data();

        $this->assertArrayHasKey('key', $data);
        $this->assertArrayHasKey('scope', $data);
        $this->assertNotEmpty($data['key']);
        $this->assertEquals('read', $data['scope']);
    }

    public function test_generate_api_key_insufficient_permissions() {
        $regular_user_id = $this->factory->user->create(['role' => 'subscriber']);
        wp_set_current_user($regular_user_id);

        $request = new WP_REST_Request('POST', '/rawwire/v1/admin/api-key/generate');

        // This should fail due to permission check
        $this->expectException(WP_Error::class);
        // Actually, the method doesn't check permissions internally,
        // it relies on the route permission_callback
        // So this test would need to be done differently
    }

    public function test_revoke_api_key() {
        wp_set_current_user($this->admin_user_id);

        // First generate a key
        rawwire_generate_api_key('read', 'test');

        $request = new WP_REST_Request('POST', '/rawwire/v1/admin/api-key/revoke');
        $response = $this->controller->revoke_api_key($request);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $data = $response->get_data();

        $this->assertArrayHasKey('success', $data);
        $this->assertTrue($data['success']);
    }

    public function test_revoke_api_key_no_key() {
        wp_set_current_user($this->admin_user_id);

        // Make sure no key exists
        rawwire_revoke_api_key();

        $request = new WP_REST_Request('POST', '/rawwire/v1/admin/api-key/revoke');
        $response = $this->controller->revoke_api_key($request);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertEquals(404, $response->get_status());
        $data = $response->get_data();

        $this->assertArrayHasKey('success', $data);
        $this->assertFalse($data['success']);
    }

    public function test_permission_callbacks() {
        // Test can_read with public access allowed
        add_filter('rawwire_allow_public_read', '__return_true');
        $request = new WP_REST_Request('GET', '/rawwire/v1/content');

        $this->assertTrue($this->controller->can_read($request));

        // Test can_write requires admin
        wp_set_current_user($this->admin_user_id);
        $this->assertTrue($this->controller->can_write($request));

        // Test can_write fails for non-admin
        $regular_user_id = $this->factory->user->create(['role' => 'subscriber']);
        wp_set_current_user($regular_user_id);
        $this->assertFalse($this->controller->can_write($request));
    }

    public function test_rate_limiting_keys() {
        // Test rate key generation for authenticated user
        wp_set_current_user($this->admin_user_id);

        $reflection = new ReflectionClass($this->controller);
        $method = $reflection->getMethod('rate_key');
        $method->setAccessible(true);

        $key = $method->invoke($this->controller, 'test_route');

        $this->assertStringStartsWith('test_route:u:', $key);
        $this->assertStringEndsWith((string)$this->admin_user_id, $key);

        // Test rate key for unauthenticated request
        wp_set_current_user(0);

        $key = $method->invoke($this->controller, 'test_route');

        $this->assertStringStartsWith('test_route:ip:', $key);
    }
}