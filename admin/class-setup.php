<?php

/**
 * Setup Page - Integration Configuration
 *
 * Provides configuration forms for:
 * - Calendar integration
 * - Email client setup
 * - Vector database (Pinecone) settings
 * - Context Engine connection
 * - Investigation settings
 *
 * Note: OpenClaw settings are managed in AI Settings panel.
 *
 * @package RawWire_Dashboard
 * @subpackage Admin
 * @since 1.0.30
 */

if (!defined('ABSPATH')) {
    exit;
}

class RawWire_Setup_Page
{
    /**
     * Option key for storing setup configuration
     */
    const OPTION_KEY = 'rawwire_setup_config';

    /**
     * Get stored configuration
     *
     * @return array
     */
    public static function get_config()
    {
        $defaults = array(
            // Calendar Integration
            'calendar_enabled'     => false,
            'calendar_provider'    => 'google',
            'calendar_api_key'     => '',
            'calendar_client_id'   => '',
            'calendar_client_secret' => '',

            // Email Client
            'email_enabled'        => false,
            'email_provider'       => 'smtp',
            'email_smtp_host'      => '',
            'email_smtp_port'      => 587,
            'email_smtp_user'      => '',
            'email_smtp_pass'      => '',
            'email_from_name'      => '',
            'email_from_address'   => '',
            'email_reply_to'       => '',

            // Vector Database (Pinecone)
            'pinecone_enabled'     => false,
            'pinecone_api_key'     => '',
            'pinecone_environment' => '',
            'pinecone_index'       => '',
            'pinecone_namespace'   => 'investigations',

            // Context Engine
            'context_engine_enabled' => false,
            'context_engine_url'     => 'http://localhost:8000',
            'context_engine_api_key' => '',

            // Investigation Settings
            'investigation_auto_store' => true,
            'investigation_retention'  => 90,
            'investigation_min_tier'   => 2,
        );

        $stored = get_option(self::OPTION_KEY, array());
        return wp_parse_args($stored, $defaults);
    }

    /**
     * Save configuration
     *
     * @param array $config
     * @return bool
     */
    public static function save_config($config)
    {
        return update_option(self::OPTION_KEY, $config);
    }

    /**
     * Constructor - register AJAX handlers
     */
    public function __construct()
    {
        add_action('wp_ajax_rawwire_save_setup', array($this, 'ajax_save_setup'));
        add_action('wp_ajax_rawwire_test_connection', array($this, 'ajax_test_connection'));
    }

    /**
     * AJAX handler for saving setup
     */
    public function ajax_save_setup()
    {
        check_ajax_referer('rawwire_setup_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $config = array();
        $fields = array(
            'calendar_enabled',
            'calendar_provider',
            'calendar_api_key',
            'calendar_client_id',
            'calendar_client_secret',
            'email_enabled',
            'email_provider',
            'email_smtp_host',
            'email_smtp_port',
            'email_smtp_user',
            'email_smtp_pass',
            'email_from_name',
            'email_from_address',
            'email_reply_to',
            'pinecone_enabled',
            'pinecone_api_key',
            'pinecone_environment',
            'pinecone_index',
            'pinecone_namespace',
            'context_engine_enabled',
            'context_engine_url',
            'context_engine_api_key',
            'investigation_auto_store',
            'investigation_retention',
            'investigation_min_tier',
        );

        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                $value = sanitize_text_field($_POST[$field]);
                // Handle boolean fields
                if (strpos($field, '_enabled') !== false || $field === 'investigation_auto_store') {
                    $value = ($value === '1' || $value === 'true' || $value === 'on');
                }
                // Handle numeric fields
                if (in_array($field, array('email_smtp_port', 'investigation_retention', 'investigation_min_tier'))) {
                    $value = intval($value);
                }
                $config[$field] = $value;
            }
        }

        $saved = self::save_config($config);

        if ($saved) {
            wp_send_json_success(array('message' => 'Configuration saved successfully'));
        } else {
            wp_send_json_error(array('message' => 'Failed to save configuration'));
        }
    }

    /**
     * AJAX handler for testing connections
     */
    public function ajax_test_connection()
    {
        check_ajax_referer('rawwire_setup_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $service = sanitize_key($_POST['service'] ?? '');
        $result = array('success' => false, 'message' => 'Unknown service');

        switch ($service) {
            case 'pinecone':
                $result = $this->test_pinecone_connection();
                break;
            case 'context_engine':
                $result = $this->test_context_engine_connection();
                break;
            case 'email':
                $result = $this->test_email_connection();
                break;
        }

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Test Pinecone connection
     */
    private function test_pinecone_connection()
    {
        $config = self::get_config();

        if (empty($config['pinecone_api_key']) || empty($config['pinecone_index'])) {
            return array('success' => false, 'message' => 'Pinecone not fully configured');
        }

        $url = sprintf(
            'https://%s-%s.svc.%s.pinecone.io/describe_index_stats',
            $config['pinecone_index'],
            $config['pinecone_environment'],
            $config['pinecone_environment']
        );

        $response = wp_remote_post($url, array(
            'headers' => array(
                'Api-Key' => $config['pinecone_api_key'],
                'Content-Type' => 'application/json',
            ),
            'body' => '{}',
            'timeout' => 10,
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code === 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $vectors = $body['totalVectorCount'] ?? 0;
            return array('success' => true, 'message' => "Connected. $vectors vectors in index.");
        }

        return array('success' => false, 'message' => 'Pinecone returned HTTP ' . $code);
    }

    /**
     * Test Context Engine connection
     */
    private function test_context_engine_connection()
    {
        $config = self::get_config();

        if (empty($config['context_engine_url'])) {
            return array('success' => false, 'message' => 'Context Engine URL not configured');
        }

        $response = wp_remote_get($config['context_engine_url'] . '/health', array(
            'timeout' => 10,
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code === 200) {
            return array('success' => true, 'message' => 'Context Engine is healthy');
        }

        return array('success' => false, 'message' => 'Context Engine returned HTTP ' . $code);
    }

    /**
     * Test email configuration
     */
    private function test_email_connection()
    {
        $config = self::get_config();

        if (!$config['email_enabled']) {
            return array('success' => false, 'message' => 'Email not enabled');
        }

        // For now, just validate the configuration exists
        if (empty($config['email_smtp_host']) || empty($config['email_from_address'])) {
            return array('success' => false, 'message' => 'Email configuration incomplete');
        }

        return array('success' => true, 'message' => 'Email configuration appears valid');
    }

    /**
     * Render the setup page
     */
    public function render()
    {
        $config = self::get_config();
        $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'email';

        $tabs = array(
            'email'      => array('label' => 'Email Client', 'icon' => 'dashicons-email-alt'),
            'calendar'   => array('label' => 'Calendar', 'icon' => 'dashicons-calendar-alt'),
            'vectors'    => array('label' => 'Vector Storage', 'icon' => 'dashicons-database'),
            'context'    => array('label' => 'Context Engine', 'icon' => 'dashicons-admin-generic'),
            'investigation' => array('label' => 'Investigation', 'icon' => 'dashicons-search'),
        );

        wp_enqueue_style('rawwire-setup', plugin_dir_url(__FILE__) . '../css/setup.css', array(), '1.0.0');
        wp_enqueue_script('rawwire-setup', plugin_dir_url(__FILE__) . '../js/setup.js', array('jquery'), '1.0.0', true);
        wp_localize_script('rawwire-setup', 'rawwireSetup', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('rawwire_setup_nonce'),
        ));

?>
        <div class="wrap rawwire-dashboard rawwire-setup-page">
            <div class="rawwire-hero rawwire-hero--setup">
                <div class="rawwire-hero-content">
                    <span class="eyebrow"><?php esc_html_e('Configuration', 'raw-wire-dashboard'); ?></span>
                    <h1>
                        <span class="dashicons dashicons-admin-settings"></span>
                        <?php esc_html_e('Soothsayer Setup', 'raw-wire-dashboard'); ?>
                    </h1>
                    <p class="lede"><?php esc_html_e('Configure integrations for intelligence collection and outreach.', 'raw-wire-dashboard'); ?></p>
                </div>
                <div class="rawwire-hero-actions">
                    <button type="button" class="button button-hero" id="save-all-setup">
                        <span class="dashicons dashicons-saved"></span>
                        <?php esc_html_e('Save All Settings', 'raw-wire-dashboard'); ?>
                    </button>
                </div>
            </div>

            <nav class="nav-tab-wrapper rawwire-tabs">
                <?php foreach ($tabs as $tab_id => $tab) : ?>
                    <?php
                    $active = ($current_tab === $tab_id) ? 'nav-tab-active' : '';
                    $url = add_query_arg('tab', $tab_id, admin_url('admin.php?page=rawwire-setup'));
                    ?>
                    <a href="<?php echo esc_url($url); ?>" class="nav-tab <?php echo esc_attr($active); ?>">
                        <span class="dashicons <?php echo esc_attr($tab['icon']); ?>"></span>
                        <?php echo esc_html($tab['label']); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <form id="setup-form" class="rawwire-setup-form" data-tab="<?php echo esc_attr($current_tab); ?>">
                <?php wp_nonce_field('rawwire_setup_nonce', 'rawwire_setup_nonce_field'); ?>

                <?php
                switch ($current_tab) {
                    case 'email':
                        $this->render_email_tab($config);
                        break;
                    case 'calendar':
                        $this->render_calendar_tab($config);
                        break;
                    case 'vectors':
                        $this->render_vectors_tab($config);
                        break;
                    case 'context':
                        $this->render_context_tab($config);
                        break;
                    case 'investigation':
                        $this->render_investigation_tab($config);
                        break;
                }
                ?>
            </form>

            <div id="setup-toast" class="rawwire-toast"></div>
        </div>
    <?php
    }

    /**
     * Render Email Client tab
     */
    private function render_email_tab($config)
    {
    ?>
        <div class="rawwire-setup-section">
            <div class="rawwire-setup-header">
                <div class="rawwire-setup-header-icon">
                    <span class="dashicons dashicons-email-alt"></span>
                </div>
                <div class="rawwire-setup-header-text">
                    <h2><?php esc_html_e('Email Client', 'raw-wire-dashboard'); ?></h2>
                    <p><?php esc_html_e('Configure email sending for targeted outreach campaigns.', 'raw-wire-dashboard'); ?></p>
                </div>
                <div class="rawwire-setup-header-toggle">
                    <label class="rawwire-toggle">
                        <input type="checkbox" name="email_enabled" value="1" <?php checked($config['email_enabled']); ?>>
                        <span class="rawwire-toggle-slider"></span>
                    </label>
                </div>
            </div>

            <div class="rawwire-setup-body">
                <div class="rawwire-form-group">
                    <label for="email_provider"><?php esc_html_e('Email Provider', 'raw-wire-dashboard'); ?></label>
                    <select id="email_provider" name="email_provider" class="rawwire-select">
                        <option value="smtp" <?php selected($config['email_provider'], 'smtp'); ?>>SMTP</option>
                        <option value="sendgrid" <?php selected($config['email_provider'], 'sendgrid'); ?>>SendGrid</option>
                        <option value="mailgun" <?php selected($config['email_provider'], 'mailgun'); ?>>Mailgun</option>
                        <option value="ses" <?php selected($config['email_provider'], 'ses'); ?>>Amazon SES</option>
                    </select>
                </div>

                <div class="rawwire-form-row">
                    <div class="rawwire-form-group">
                        <label for="email_smtp_host"><?php esc_html_e('SMTP Host', 'raw-wire-dashboard'); ?></label>
                        <input type="text" id="email_smtp_host" name="email_smtp_host"
                            value="<?php echo esc_attr($config['email_smtp_host']); ?>"
                            placeholder="smtp.example.com" class="rawwire-input">
                    </div>
                    <div class="rawwire-form-group">
                        <label for="email_smtp_port"><?php esc_html_e('SMTP Port', 'raw-wire-dashboard'); ?></label>
                        <input type="number" id="email_smtp_port" name="email_smtp_port"
                            value="<?php echo esc_attr($config['email_smtp_port']); ?>"
                            placeholder="587" class="rawwire-input">
                    </div>
                </div>

                <div class="rawwire-form-row">
                    <div class="rawwire-form-group">
                        <label for="email_smtp_user"><?php esc_html_e('SMTP Username', 'raw-wire-dashboard'); ?></label>
                        <input type="text" id="email_smtp_user" name="email_smtp_user"
                            value="<?php echo esc_attr($config['email_smtp_user']); ?>" class="rawwire-input">
                    </div>
                    <div class="rawwire-form-group">
                        <label for="email_smtp_pass"><?php esc_html_e('SMTP Password', 'raw-wire-dashboard'); ?></label>
                        <input type="password" id="email_smtp_pass" name="email_smtp_pass"
                            value="<?php echo esc_attr($config['email_smtp_pass']); ?>" class="rawwire-input rawwire-input--secret">
                    </div>
                </div>

                <div class="rawwire-form-row">
                    <div class="rawwire-form-group">
                        <label for="email_from_name"><?php esc_html_e('From Name', 'raw-wire-dashboard'); ?></label>
                        <input type="text" id="email_from_name" name="email_from_name"
                            value="<?php echo esc_attr($config['email_from_name']); ?>"
                            placeholder="Your Company" class="rawwire-input">
                    </div>
                    <div class="rawwire-form-group">
                        <label for="email_from_address"><?php esc_html_e('From Address', 'raw-wire-dashboard'); ?></label>
                        <input type="email" id="email_from_address" name="email_from_address"
                            value="<?php echo esc_attr($config['email_from_address']); ?>"
                            placeholder="outreach@company.com" class="rawwire-input">
                    </div>
                </div>

                <div class="rawwire-form-group">
                    <label for="email_reply_to"><?php esc_html_e('Reply-To Address', 'raw-wire-dashboard'); ?></label>
                    <input type="email" id="email_reply_to" name="email_reply_to"
                        value="<?php echo esc_attr($config['email_reply_to']); ?>"
                        placeholder="hello@company.com" class="rawwire-input">
                </div>

                <div class="rawwire-setup-actions">
                    <button type="button" class="button button-secondary test-connection" data-service="email">
                        <span class="dashicons dashicons-admin-plugins"></span>
                        <?php esc_html_e('Test Configuration', 'raw-wire-dashboard'); ?>
                    </button>
                    <span class="connection-status" id="email-status"></span>
                </div>
            </div>
        </div>
    <?php
    }

    /**
     * Render Calendar tab
     */
    private function render_calendar_tab($config)
    {
    ?>
        <div class="rawwire-setup-section">
            <div class="rawwire-setup-header">
                <div class="rawwire-setup-header-icon">
                    <span class="dashicons dashicons-calendar-alt"></span>
                </div>
                <div class="rawwire-setup-header-text">
                    <h2><?php esc_html_e('Calendar Integration', 'raw-wire-dashboard'); ?></h2>
                    <p><?php esc_html_e('Connect your calendar for event scheduling and industry gathering tracking.', 'raw-wire-dashboard'); ?></p>
                </div>
                <div class="rawwire-setup-header-toggle">
                    <label class="rawwire-toggle">
                        <input type="checkbox" name="calendar_enabled" value="1" <?php checked($config['calendar_enabled']); ?>>
                        <span class="rawwire-toggle-slider"></span>
                    </label>
                </div>
            </div>

            <div class="rawwire-setup-body">
                <div class="rawwire-form-group">
                    <label for="calendar_provider"><?php esc_html_e('Calendar Provider', 'raw-wire-dashboard'); ?></label>
                    <select id="calendar_provider" name="calendar_provider" class="rawwire-select">
                        <option value="google" <?php selected($config['calendar_provider'], 'google'); ?>>Google Calendar</option>
                        <option value="outlook" <?php selected($config['calendar_provider'], 'outlook'); ?>>Microsoft Outlook</option>
                        <option value="caldav" <?php selected($config['calendar_provider'], 'caldav'); ?>>CalDAV</option>
                    </select>
                </div>

                <div class="rawwire-form-group">
                    <label for="calendar_client_id"><?php esc_html_e('OAuth Client ID', 'raw-wire-dashboard'); ?></label>
                    <input type="text" id="calendar_client_id" name="calendar_client_id"
                        value="<?php echo esc_attr($config['calendar_client_id']); ?>" class="rawwire-input">
                </div>

                <div class="rawwire-form-group">
                    <label for="calendar_client_secret"><?php esc_html_e('OAuth Client Secret', 'raw-wire-dashboard'); ?></label>
                    <input type="password" id="calendar_client_secret" name="calendar_client_secret"
                        value="<?php echo esc_attr($config['calendar_client_secret']); ?>" class="rawwire-input rawwire-input--secret">
                </div>

                <div class="rawwire-notice rawwire-notice--info">
                    <span class="dashicons dashicons-info"></span>
                    <p><?php esc_html_e('Calendar integration will allow Soothsayer to suggest industry events and schedule follow-ups automatically.', 'raw-wire-dashboard'); ?></p>
                </div>
            </div>
        </div>
    <?php
    }

    /**
     * Render Vector Storage tab
     */
    private function render_vectors_tab($config)
    {
    ?>
        <div class="rawwire-setup-section">
            <div class="rawwire-setup-header">
                <div class="rawwire-setup-header-icon">
                    <span class="dashicons dashicons-database"></span>
                </div>
                <div class="rawwire-setup-header-text">
                    <h2><?php esc_html_e('Vector Storage (Pinecone)', 'raw-wire-dashboard'); ?></h2>
                    <p><?php esc_html_e('Store and retrieve investigation embeddings for semantic search.', 'raw-wire-dashboard'); ?></p>
                </div>
                <div class="rawwire-setup-header-toggle">
                    <label class="rawwire-toggle">
                        <input type="checkbox" name="pinecone_enabled" value="1" <?php checked($config['pinecone_enabled']); ?>>
                        <span class="rawwire-toggle-slider"></span>
                    </label>
                </div>
            </div>

            <div class="rawwire-setup-body">
                <div class="rawwire-form-group">
                    <label for="pinecone_api_key"><?php esc_html_e('Pinecone API Key', 'raw-wire-dashboard'); ?></label>
                    <input type="password" id="pinecone_api_key" name="pinecone_api_key"
                        value="<?php echo esc_attr($config['pinecone_api_key']); ?>" class="rawwire-input rawwire-input--secret">
                </div>

                <div class="rawwire-form-row">
                    <div class="rawwire-form-group">
                        <label for="pinecone_environment"><?php esc_html_e('Environment', 'raw-wire-dashboard'); ?></label>
                        <input type="text" id="pinecone_environment" name="pinecone_environment"
                            value="<?php echo esc_attr($config['pinecone_environment']); ?>"
                            placeholder="us-east1-gcp" class="rawwire-input">
                    </div>
                    <div class="rawwire-form-group">
                        <label for="pinecone_index"><?php esc_html_e('Index Name', 'raw-wire-dashboard'); ?></label>
                        <input type="text" id="pinecone_index" name="pinecone_index"
                            value="<?php echo esc_attr($config['pinecone_index']); ?>"
                            placeholder="soothsayer-investigations" class="rawwire-input">
                    </div>
                </div>

                <div class="rawwire-form-group">
                    <label for="pinecone_namespace"><?php esc_html_e('Namespace', 'raw-wire-dashboard'); ?></label>
                    <input type="text" id="pinecone_namespace" name="pinecone_namespace"
                        value="<?php echo esc_attr($config['pinecone_namespace']); ?>"
                        placeholder="investigations" class="rawwire-input">
                </div>

                <div class="rawwire-setup-actions">
                    <button type="button" class="button button-secondary test-connection" data-service="pinecone">
                        <span class="dashicons dashicons-admin-plugins"></span>
                        <?php esc_html_e('Test Connection', 'raw-wire-dashboard'); ?>
                    </button>
                    <span class="connection-status" id="pinecone-status"></span>
                </div>
            </div>
        </div>
    <?php
    }

    /**
     * Render Context Engine tab
     */
    private function render_context_tab($config)
    {
    ?>
        <div class="rawwire-setup-section">
            <div class="rawwire-setup-header">
                <div class="rawwire-setup-header-icon">
                    <span class="dashicons dashicons-admin-generic"></span>
                </div>
                <div class="rawwire-setup-header-text">
                    <h2><?php esc_html_e('Context Engine', 'raw-wire-dashboard'); ?></h2>
                    <p><?php esc_html_e('Connect to your local Context Engine for enhanced memory and retrieval.', 'raw-wire-dashboard'); ?></p>
                </div>
                <div class="rawwire-setup-header-toggle">
                    <label class="rawwire-toggle">
                        <input type="checkbox" name="context_engine_enabled" value="1" <?php checked($config['context_engine_enabled']); ?>>
                        <span class="rawwire-toggle-slider"></span>
                    </label>
                </div>
            </div>

            <div class="rawwire-setup-body">
                <div class="rawwire-form-group">
                    <label for="context_engine_url"><?php esc_html_e('Context Engine URL', 'raw-wire-dashboard'); ?></label>
                    <input type="url" id="context_engine_url" name="context_engine_url"
                        value="<?php echo esc_attr($config['context_engine_url']); ?>"
                        placeholder="http://localhost:8000" class="rawwire-input">
                </div>

                <div class="rawwire-form-group">
                    <label for="context_engine_api_key"><?php esc_html_e('API Key (optional)', 'raw-wire-dashboard'); ?></label>
                    <input type="password" id="context_engine_api_key" name="context_engine_api_key"
                        value="<?php echo esc_attr($config['context_engine_api_key']); ?>" class="rawwire-input rawwire-input--secret">
                </div>

                <div class="rawwire-setup-actions">
                    <button type="button" class="button button-secondary test-connection" data-service="context_engine">
                        <span class="dashicons dashicons-admin-plugins"></span>
                        <?php esc_html_e('Test Connection', 'raw-wire-dashboard'); ?>
                    </button>
                    <span class="connection-status" id="context_engine-status"></span>
                </div>
            </div>
        </div>
    <?php
    }

    /**
     * Render Investigation Settings tab
     */
    private function render_investigation_tab($config)
    {
    ?>
        <div class="rawwire-setup-section">
            <div class="rawwire-setup-header">
                <div class="rawwire-setup-header-icon">
                    <span class="dashicons dashicons-search"></span>
                </div>
                <div class="rawwire-setup-header-text">
                    <h2><?php esc_html_e('Investigation Settings', 'raw-wire-dashboard'); ?></h2>
                    <p><?php esc_html_e('Configure how investigations are processed and stored.', 'raw-wire-dashboard'); ?></p>
                </div>
            </div>

            <div class="rawwire-setup-body">
                <div class="rawwire-form-group rawwire-form-group--checkbox">
                    <label class="rawwire-checkbox">
                        <input type="checkbox" name="investigation_auto_store" value="1" <?php checked($config['investigation_auto_store']); ?>>
                        <span class="rawwire-checkbox-label"><?php esc_html_e('Automatically store investigations in vector database', 'raw-wire-dashboard'); ?></span>
                    </label>
                </div>

                <div class="rawwire-form-row">
                    <div class="rawwire-form-group">
                        <label for="investigation_retention"><?php esc_html_e('Data Retention (days)', 'raw-wire-dashboard'); ?></label>
                        <input type="number" id="investigation_retention" name="investigation_retention"
                            value="<?php echo esc_attr($config['investigation_retention']); ?>"
                            min="30" max="365" class="rawwire-input">
                        <p class="description"><?php esc_html_e('How long to keep investigation data before cleanup.', 'raw-wire-dashboard'); ?></p>
                    </div>

                    <div class="rawwire-form-group">
                        <label for="investigation_min_tier"><?php esc_html_e('Minimum Quality Tier', 'raw-wire-dashboard'); ?></label>
                        <select id="investigation_min_tier" name="investigation_min_tier" class="rawwire-select">
                            <option value="1" <?php selected($config['investigation_min_tier'], 1); ?>>Tier 1 - Minimal</option>
                            <option value="2" <?php selected($config['investigation_min_tier'], 2); ?>>Tier 2 - Solid</option>
                            <option value="3" <?php selected($config['investigation_min_tier'], 3); ?>>Tier 3 - Excellent</option>
                        </select>
                        <p class="description"><?php esc_html_e('Investigations below this tier will be flagged for review.', 'raw-wire-dashboard'); ?></p>
                    </div>
                </div>
            </div>
        </div>
<?php
    }
}

// Initialize AJAX handlers
add_action('init', function () {
    new RawWire_Setup_Page();
});
