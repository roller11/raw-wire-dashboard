<?php
/**
 * Approval Workflow Tests
 */

class RawWire_Approval_Workflow_Test extends WP_UnitTestCase {
    private $admin_user_id;
    private $content_id;

    public function setUp(): void {
        parent::setUp();

        // Create admin user
        $this->admin_user_id = $this->factory->user->create([
            'role' => 'administrator'
        ]);

        // Load approval workflow class
        require_once plugin_dir_path(__DIR__) . 'includes/class-approval-workflow.php';

        // Create test content in database
        global $wpdb;
        $table = $wpdb->prefix . 'rawwire_content';

        $wpdb->insert(
            $table,
            [
                'title' => 'Test Content',
                'content' => 'Test content body',
                'summary' => 'Test summary',
                'status' => 'pending',
                'category' => 'test',
                'source_url' => 'https://example.com',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        $this->content_id = $wpdb->insert_id;
    }

    public function tearDown(): void {
        // Clean up test data
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'rawwire_content', ['id' => $this->content_id]);
        $wpdb->delete($wpdb->prefix . 'rawwire_approval_history', ['content_id' => $this->content_id]);

        parent::tearDown();
    }

    public function test_approve_content_success() {
        wp_set_current_user($this->admin_user_id);

        $result = RawWire_Approval_Workflow::approve_content($this->content_id, $this->admin_user_id, 'Test approval');

        $this->assertTrue($result);

        // Verify status was updated
        global $wpdb;
        $content = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}rawwire_content WHERE id = %d", $this->content_id),
            ARRAY_A
        );

        $this->assertEquals('approved', $content['status']);
    }

    public function test_approve_content_insufficient_permissions() {
        // Create regular user without admin permissions
        $regular_user_id = $this->factory->user->create(['role' => 'subscriber']);
        wp_set_current_user($regular_user_id);

        $result = RawWire_Approval_Workflow::approve_content($this->content_id, $regular_user_id);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('forbidden', $result->get_error_code());
    }

    public function test_approve_content_not_found() {
        wp_set_current_user($this->admin_user_id);

        $result = RawWire_Approval_Workflow::approve_content(99999, $this->admin_user_id);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('not_found', $result->get_error_code());
    }

    public function test_approve_content_already_approved() {
        wp_set_current_user($this->admin_user_id);

        // First approval
        RawWire_Approval_Workflow::approve_content($this->content_id, $this->admin_user_id);

        // Try to approve again
        $result = RawWire_Approval_Workflow::approve_content($this->content_id, $this->admin_user_id);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('already_approved', $result->get_error_code());
    }

    public function test_reject_content_success() {
        wp_set_current_user($this->admin_user_id);

        $result = RawWire_Approval_Workflow::reject_content($this->content_id, $this->admin_user_id, 'Test rejection');

        $this->assertTrue($result);

        // Verify status was updated
        global $wpdb;
        $content = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}rawwire_content WHERE id = %d", $this->content_id),
            ARRAY_A
        );

        $this->assertEquals('rejected', $content['status']);
    }

    public function test_bulk_approve() {
        wp_set_current_user($this->admin_user_id);

        // Create another content item
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'rawwire_content',
            [
                'title' => 'Test Content 2',
                'content' => 'Test content body 2',
                'summary' => 'Test summary 2',
                'status' => 'pending',
                'category' => 'test',
                'source_url' => 'https://example.com/2',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        $content_id_2 = $wpdb->insert_id;

        $result = RawWire_Approval_Workflow::bulk_approve([$this->content_id, $content_id_2], $this->admin_user_id, 'Bulk test');

        $this->assertCount(2, $result);
        $this->assertContains($this->content_id, $result);
        $this->assertContains($content_id_2, $result);

        // Clean up second content
        $wpdb->delete($wpdb->prefix . 'rawwire_content', ['id' => $content_id_2]);
        $wpdb->delete($wpdb->prefix . 'rawwire_approval_history', ['content_id' => $content_id_2]);
    }

    public function test_bulk_reject() {
        wp_set_current_user($this->admin_user_id);

        // Create another content item
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'rawwire_content',
            [
                'title' => 'Test Content 2',
                'content' => 'Test content body 2',
                'summary' => 'Test summary 2',
                'status' => 'pending',
                'category' => 'test',
                'source_url' => 'https://example.com/2',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        $content_id_2 = $wpdb->insert_id;

        $result = RawWire_Approval_Workflow::bulk_reject([$this->content_id, $content_id_2], $this->admin_user_id, 'Bulk rejection test');

        $this->assertCount(2, $result);
        $this->assertContains($this->content_id, $result);
        $this->assertContains($content_id_2, $result);

        // Clean up second content
        $wpdb->delete($wpdb->prefix . 'rawwire_content', ['id' => $content_id_2]);
        $wpdb->delete($wpdb->prefix . 'rawwire_approval_history', ['content_id' => $content_id_2]);
    }

    public function test_get_history() {
        wp_set_current_user($this->admin_user_id);

        // Approve content to create history
        RawWire_Approval_Workflow::approve_content($this->content_id, $this->admin_user_id, 'History test');

        $history = RawWire_Approval_Workflow::get_history($this->content_id);

        $this->assertCount(1, $history);
        $this->assertEquals('approved', $history[0]['action']);
        $this->assertEquals('History test', $history[0]['notes']);
        $this->assertEquals($this->admin_user_id, (int)$history[0]['user_id']);
    }

    public function test_get_user_stats() {
        wp_set_current_user($this->admin_user_id);

        // Create some approval history
        RawWire_Approval_Workflow::approve_content($this->content_id, $this->admin_user_id, 'Stats test');

        // Create another content for rejection
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'rawwire_content',
            [
                'title' => 'Test Content 3',
                'content' => 'Test content body 3',
                'summary' => 'Test summary 3',
                'status' => 'pending',
                'category' => 'test',
                'source_url' => 'https://example.com/3',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        $content_id_3 = $wpdb->insert_id;
        RawWire_Approval_Workflow::reject_content($content_id_3, $this->admin_user_id, 'Stats rejection test');

        $stats = RawWire_Approval_Workflow::get_user_stats($this->admin_user_id);

        $this->assertEquals(2, $stats['total']);
        $this->assertEquals(1, $stats['approved']);
        $this->assertEquals(1, $stats['rejected']);

        // Clean up
        $wpdb->delete($wpdb->prefix . 'rawwire_content', ['id' => $content_id_3]);
        $wpdb->delete($wpdb->prefix . 'rawwire_approval_history', ['content_id' => $content_id_3]);
    }

    public function test_record_approval_without_table() {
        wp_set_current_user($this->admin_user_id);

        // Drop the approval history table temporarily
        global $wpdb;
        $table = $wpdb->prefix . 'rawwire_approval_history';
        $wpdb->query("DROP TABLE IF EXISTS {$table}");

        // This should not throw an error
        RawWire_Approval_Workflow::record_approval($this->content_id, $this->admin_user_id, 'Test');

        $this->assertTrue(true); // If we get here, no exception was thrown
    }
}