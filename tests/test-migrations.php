<?php
/**
 * Migration tests
 *
 * @package RawWire_Dashboard
 */

class RawWire_Migrations_Test extends WP_UnitTestCase {
    public function setUp(): void {
        parent::setUp();
        // Ensure migration manager loaded
        require_once plugin_dir_path( __DIR__ ) . 'includes/migrations/class-migration-manager.php';
    }

    public function test_run_pending_migrations_applies_tables() {
        global $wpdb;

        // Run migrations
        $res = RawWire_Migration_Manager::run_pending_migrations();

        // Should have applied at least our initial migration or recorded fail info
        $this->assertTrue(is_array($res));
        if (!empty($res['failed'])) {
            $this->fail('Migrations failed: ' . json_encode($res['failed']));
        }

        // Check primary tables exist
        $this->assertEquals($wpdb->prefix . 'rawwire_content', $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->prefix . 'rawwire_content')));
        $this->assertEquals($wpdb->prefix . 'rawwire_approval_history', $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->prefix . 'rawwire_approval_history')));
        $this->assertEquals($wpdb->prefix . 'rawwire_github_issues', $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->prefix . 'rawwire_github_issues')));
    }
}
