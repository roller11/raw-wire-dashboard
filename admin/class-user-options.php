<?php

/**
 * User Options - End-User Preferences & Settings
 *
 * Production page for end-user configuration:
 * - Display preferences
 * - Notification settings
 * - Account preferences
 *
 * Styled with Soothsayer dark theme (140px hero header).
 *
 * @package RawWire_Dashboard
 * @since 1.0.32
 */

defined('ABSPATH') || exit;

/**
 * Class RawWire_User_Options
 */
class RawWire_User_Options
{
    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        // AJAX handlers for user options
        add_action('wp_ajax_rawwire_save_user_options', array($this, 'ajax_save_user_options'));
    }

    /**
     * Enqueue assets
     */
    private function enqueue_assets()
    {
        $plugin_url = plugin_dir_url(__FILE__) . '../';
        $version = defined('RAWWIRE_DASHBOARD_VERSION') ? RAWWIRE_DASHBOARD_VERSION : '1.0.32';

        // Shared dark theme CSS (Soothsayer design system)
        wp_enqueue_style(
            'rawwire-soothsayer-v2',
            $plugin_url . 'css/soothsayer-v2.css',
            array(),
            $version
        );

        // User Options page-specific CSS
        wp_enqueue_style(
            'rawwire-user-options',
            $plugin_url . 'css/user-options.css',
            array('rawwire-soothsayer-v2'),
            $version
        );

        // User Options JS
        wp_enqueue_script(
            'rawwire-user-options',
            $plugin_url . 'js/user-options.js',
            array('jquery'),
            $version,
            true
        );

        wp_localize_script('rawwire-user-options', 'userOptionsData', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rawwire_user_options'),
        ));
    }

    /**
     * Render the main page
     */
    public function render()
    {
        $this->enqueue_assets();
        $current_user = wp_get_current_user();
        $options = $this->get_user_options($current_user->ID);
?>
        <div class="soothsayer-app">
            <!-- Hero Section -->
            <div class="soothsayer-hero">
                <div class="soothsayer-hero-content">
                    <div class="soothsayer-hero-icon">
                        <span class="dashicons dashicons-admin-users"></span>
                        <div class="soothsayer-icon-pulse"></div>
                    </div>
                    <h1>USER OPTIONS</h1>
                    <p class="soothsayer-hero-subtitle">Preferences & Settings</p>
                </div>
                <div class="soothsayer-hero-particles" aria-hidden="true">
                    <?php for ($i = 0; $i < 20; $i++): ?>
                        <div class="particle particle-<?php echo $i; ?>"></div>
                    <?php endfor; ?>
                </div>
            </div>

            <!-- Options Navigation Tabs -->
            <nav class="soothsayer-tabs">
                <button class="tab-btn active" data-tab="display">
                    <span class="dashicons dashicons-visibility"></span>
                    Display
                </button>
                <button class="tab-btn" data-tab="notifications">
                    <span class="dashicons dashicons-bell"></span>
                    Notifications
                </button>
                <button class="tab-btn" data-tab="account">
                    <span class="dashicons dashicons-admin-users"></span>
                    Account
                </button>
            </nav>

            <main class="soothsayer-content">
                <!-- Tab 1: Display Preferences -->
                <section class="tab-panel active" id="panel-display">
                    <?php $this->render_display_panel($options); ?>
                </section>

                <!-- Tab 2: Notifications -->
                <section class="tab-panel" id="panel-notifications">
                    <?php $this->render_notifications_panel($options); ?>
                </section>

                <!-- Tab 3: Account -->
                <section class="tab-panel" id="panel-account">
                    <?php $this->render_account_panel($options, $current_user); ?>
                </section>
            </main>

            <!-- Save Status Toast -->
            <div id="user-options-toast" class="user-options-toast" style="display:none;">
                <span class="dashicons dashicons-saved"></span>
                <span class="toast-message">Settings saved successfully</span>
            </div>
        </div>
    <?php
    }

    /**
     * Render Display Preferences panel
     */
    private function render_display_panel($options)
    {
    ?>
        <div class="user-options-section">
            <div class="user-options-card">
                <div class="user-options-card-header">
                    <span class="dashicons dashicons-art"></span>
                    <h3>Theme & Appearance</h3>
                </div>
                <div class="user-options-card-body">
                    <div class="option-row">
                        <div class="option-info">
                            <label for="opt-dashboard-density">Dashboard Density</label>
                            <p class="option-desc">Controls the spacing and layout density of dashboard elements.</p>
                        </div>
                        <div class="option-control">
                            <select id="opt-dashboard-density" name="dashboard_density" class="user-option-input">
                                <option value="comfortable" <?php selected($options['dashboard_density'] ?? 'comfortable', 'comfortable'); ?>>Comfortable</option>
                                <option value="compact" <?php selected($options['dashboard_density'] ?? 'comfortable', 'compact'); ?>>Compact</option>
                                <option value="spacious" <?php selected($options['dashboard_density'] ?? 'comfortable', 'spacious'); ?>>Spacious</option>
                            </select>
                        </div>
                    </div>

                    <div class="option-row">
                        <div class="option-info">
                            <label for="opt-animations">Animations</label>
                            <p class="option-desc">Enable or disable interface animations and transitions.</p>
                        </div>
                        <div class="option-control">
                            <label class="toggle-switch">
                                <input type="checkbox" id="opt-animations" name="animations_enabled" class="user-option-input"
                                    <?php checked($options['animations_enabled'] ?? true); ?> />
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                    </div>

                    <div class="option-row">
                        <div class="option-info">
                            <label for="opt-items-per-page">Items Per Page</label>
                            <p class="option-desc">Number of items shown in data tables and lists.</p>
                        </div>
                        <div class="option-control">
                            <select id="opt-items-per-page" name="items_per_page" class="user-option-input">
                                <option value="10" <?php selected($options['items_per_page'] ?? '20', '10'); ?>>10</option>
                                <option value="20" <?php selected($options['items_per_page'] ?? '20', '20'); ?>>20</option>
                                <option value="50" <?php selected($options['items_per_page'] ?? '20', '50'); ?>>50</option>
                                <option value="100" <?php selected($options['items_per_page'] ?? '20', '100'); ?>>100</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php
    }

    /**
     * Render Notifications panel
     */
    private function render_notifications_panel($options)
    {
    ?>
        <div class="user-options-section">
            <div class="user-options-card">
                <div class="user-options-card-header">
                    <span class="dashicons dashicons-bell"></span>
                    <h3>Notification Preferences</h3>
                </div>
                <div class="user-options-card-body">
                    <div class="option-row">
                        <div class="option-info">
                            <label for="opt-email-notifications">Email Notifications</label>
                            <p class="option-desc">Receive email notifications for important events and updates.</p>
                        </div>
                        <div class="option-control">
                            <label class="toggle-switch">
                                <input type="checkbox" id="opt-email-notifications" name="email_notifications" class="user-option-input"
                                    <?php checked($options['email_notifications'] ?? true); ?> />
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                    </div>

                    <div class="option-row">
                        <div class="option-info">
                            <label for="opt-lead-alerts">New Lead Alerts</label>
                            <p class="option-desc">Get notified when new high-value leads are discovered.</p>
                        </div>
                        <div class="option-control">
                            <label class="toggle-switch">
                                <input type="checkbox" id="opt-lead-alerts" name="lead_alerts" class="user-option-input"
                                    <?php checked($options['lead_alerts'] ?? true); ?> />
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                    </div>

                    <div class="option-row">
                        <div class="option-info">
                            <label for="opt-digest-frequency">Digest Frequency</label>
                            <p class="option-desc">How often to receive summary digests of activity.</p>
                        </div>
                        <div class="option-control">
                            <select id="opt-digest-frequency" name="digest_frequency" class="user-option-input">
                                <option value="daily" <?php selected($options['digest_frequency'] ?? 'daily', 'daily'); ?>>Daily</option>
                                <option value="weekly" <?php selected($options['digest_frequency'] ?? 'daily', 'weekly'); ?>>Weekly</option>
                                <option value="never" <?php selected($options['digest_frequency'] ?? 'daily', 'never'); ?>>Never</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php
    }

    /**
     * Render Account panel
     */
    private function render_account_panel($options, $current_user)
    {
    ?>
        <div class="user-options-section">
            <div class="user-options-card">
                <div class="user-options-card-header">
                    <span class="dashicons dashicons-id-alt"></span>
                    <h3>Account Information</h3>
                </div>
                <div class="user-options-card-body">
                    <div class="option-row">
                        <div class="option-info">
                            <label>Display Name</label>
                            <p class="option-desc">Your name as displayed across the platform.</p>
                        </div>
                        <div class="option-control">
                            <span class="option-value"><?php echo esc_html($current_user->display_name); ?></span>
                        </div>
                    </div>

                    <div class="option-row">
                        <div class="option-info">
                            <label>Email Address</label>
                            <p class="option-desc">Your registered email address.</p>
                        </div>
                        <div class="option-control">
                            <span class="option-value"><?php echo esc_html($current_user->user_email); ?></span>
                        </div>
                    </div>

                    <div class="option-row">
                        <div class="option-info">
                            <label>Role</label>
                            <p class="option-desc">Your access level in the system.</p>
                        </div>
                        <div class="option-control">
                            <span class="option-value option-badge"><?php echo esc_html(implode(', ', $current_user->roles)); ?></span>
                        </div>
                    </div>

                    <div class="option-row">
                        <div class="option-info">
                            <label for="opt-timezone">Timezone</label>
                            <p class="option-desc">Your preferred timezone for dates and scheduling.</p>
                        </div>
                        <div class="option-control">
                            <select id="opt-timezone" name="timezone" class="user-option-input">
                                <option value="America/New_York" <?php selected($options['timezone'] ?? 'America/New_York', 'America/New_York'); ?>>Eastern Time</option>
                                <option value="America/Chicago" <?php selected($options['timezone'] ?? 'America/New_York', 'America/Chicago'); ?>>Central Time</option>
                                <option value="America/Denver" <?php selected($options['timezone'] ?? 'America/New_York', 'America/Denver'); ?>>Mountain Time</option>
                                <option value="America/Los_Angeles" <?php selected($options['timezone'] ?? 'America/New_York', 'America/Los_Angeles'); ?>>Pacific Time</option>
                                <option value="UTC" <?php selected($options['timezone'] ?? 'America/New_York', 'UTC'); ?>>UTC</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="user-options-actions">
                <button type="button" class="btn-save-options" id="save-user-options">
                    <span class="dashicons dashicons-saved"></span>
                    Save Preferences
                </button>
            </div>
        </div>
<?php
    }

    /**
     * Get user options from database
     */
    private function get_user_options($user_id)
    {
        $defaults = array(
            'dashboard_density' => 'comfortable',
            'animations_enabled' => true,
            'items_per_page' => '20',
            'email_notifications' => true,
            'lead_alerts' => true,
            'digest_frequency' => 'daily',
            'timezone' => 'America/New_York',
        );

        $saved = get_user_meta($user_id, 'rawwire_user_options', true);
        if (!is_array($saved)) {
            $saved = array();
        }

        return wp_parse_args($saved, $defaults);
    }

    /**
     * AJAX: Save user options
     */
    public function ajax_save_user_options()
    {
        check_ajax_referer('rawwire_user_options', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $user_id = get_current_user_id();
        $options = array(
            'dashboard_density' => sanitize_text_field($_POST['dashboard_density'] ?? 'comfortable'),
            'animations_enabled' => !empty($_POST['animations_enabled']),
            'items_per_page' => sanitize_text_field($_POST['items_per_page'] ?? '20'),
            'email_notifications' => !empty($_POST['email_notifications']),
            'lead_alerts' => !empty($_POST['lead_alerts']),
            'digest_frequency' => sanitize_text_field($_POST['digest_frequency'] ?? 'daily'),
            'timezone' => sanitize_text_field($_POST['timezone'] ?? 'America/New_York'),
        );

        update_user_meta($user_id, 'rawwire_user_options', $options);

        wp_send_json_success(array(
            'message' => 'User options saved successfully',
            'options' => $options,
        ));
    }
}
