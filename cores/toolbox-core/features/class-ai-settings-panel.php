<?php
/**
 * AI Settings Panel - Configuration UI for AI Engine integration
 * 
 * Provides admin interface for configuring AI Adapter settings,
 * selecting default environments, and managing MCP server options.
 *
 * @package RawWire\Dashboard\Cores\ToolboxCore\Features
 * @since 1.0.15
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class RawWire_AI_Settings_Panel
 */
class RawWire_AI_Settings_Panel {

    /**
     * Singleton instance
     * @var RawWire_AI_Settings_Panel|null
     */
    private static $instance = null;

    /**
     * Get singleton instance
     * 
     * @return RawWire_AI_Settings_Panel
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
        add_action('admin_menu', [$this, 'add_submenu'], 30);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_rawwire_ai_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_rawwire_ai_get_status', [$this, 'ajax_get_status']);
        add_action('wp_ajax_rawwire_mcp_list_tools', [$this, 'ajax_list_mcp_tools']);
    }

    /**
     * Add submenu page
     */
    public function add_submenu() {
        add_submenu_page(
            'raw-wire-dashboard',
            __('AI Settings', 'raw-wire-dashboard'),
            __('AI Settings', 'raw-wire-dashboard'),
            'manage_options',
            'rawwire-ai-settings',
            [$this, 'render_page']
        );
    }

    /**
     * Enqueue assets for AI settings page
     * 
     * @param string $hook Current page hook
     */
    public function enqueue_assets($hook) {
        if (strpos($hook, 'rawwire-ai-settings') === false) {
            return;
        }

        wp_enqueue_style('rawwire-admin');
        wp_enqueue_script('rawwire-admin');
    }

    /**
     * Render the admin page wrapper
     */
    public function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        
        echo '<div class="wrap rawwire-wrap">';
        $this->render();
        echo '</div>';
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('rawwire_ai_settings', 'rawwire_ai_adapter_settings', [
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);

        register_setting('rawwire_ai_settings', 'rawwire_mcp_settings', [
            'sanitize_callback' => [$this, 'sanitize_mcp_settings'],
        ]);
    }

    /**
     * Sanitize AI adapter settings
     */
    public function sanitize_settings($input) {
        $sanitized = [];
        
        $sanitized['default_env_id'] = sanitize_text_field($input['default_env_id'] ?? '');
        $sanitized['cache_ttl'] = absint($input['cache_ttl'] ?? 3600);
        $sanitized['fallback_enabled'] = !empty($input['fallback_enabled']);
        $sanitized['logging_enabled'] = !empty($input['logging_enabled']);
        
        return $sanitized;
    }

    /**
     * Sanitize MCP settings
     */
    public function sanitize_mcp_settings($input) {
        $sanitized = [];
        
        $sanitized['enabled'] = !empty($input['enabled']);
        $sanitized['require_auth'] = !empty($input['require_auth']);
        $sanitized['allowed_tools'] = array_map('sanitize_text_field', $input['allowed_tools'] ?? []);
        
        return $sanitized;
    }

    /**
     * Render AI settings panel
     */
    public function render() {
        $ai = rawwire_ai();
        $status = $ai->get_status();
        $settings = get_option('rawwire_ai_adapter_settings', []);
        $mcp_settings = get_option('rawwire_mcp_settings', []);
        
        ?>
        <div class="rawwire-ai-settings-panel">
            <h2><?php esc_html_e('AI Engine Integration', 'raw-wire-dashboard'); ?></h2>
            
            <!-- Status Card -->
            <div class="rawwire-ai-status-card">
                <h3><?php esc_html_e('AI Engine Status', 'raw-wire-dashboard'); ?></h3>
                
                <div class="status-grid">
                    <div class="status-item">
                        <span class="status-label"><?php esc_html_e('Status', 'raw-wire-dashboard'); ?></span>
                        <span class="status-value <?php echo $status['available'] ? 'status-active' : 'status-inactive'; ?>">
                            <?php echo $status['available'] 
                                ? '<span class="dashicons dashicons-yes-alt"></span> ' . esc_html__('Active', 'raw-wire-dashboard')
                                : '<span class="dashicons dashicons-warning"></span> ' . esc_html__('Not Installed', 'raw-wire-dashboard'); 
                            ?>
                        </span>
                    </div>
                    
                    <?php if ($status['available']): ?>
                    <div class="status-item">
                        <span class="status-label"><?php esc_html_e('Version', 'raw-wire-dashboard'); ?></span>
                        <span class="status-value"><?php echo esc_html($status['version']); ?></span>
                    </div>
                    
                    <div class="status-item">
                        <span class="status-label"><?php esc_html_e('Pro Features', 'raw-wire-dashboard'); ?></span>
                        <span class="status-value <?php echo $status['pro'] ? 'status-active' : ''; ?>">
                            <?php echo $status['pro'] 
                                ? '<span class="dashicons dashicons-yes-alt"></span> ' . esc_html__('Available', 'raw-wire-dashboard')
                                : esc_html__('Free Version', 'raw-wire-dashboard'); 
                            ?>
                        </span>
                    </div>
                    
                    <div class="status-item">
                        <span class="status-label"><?php esc_html_e('Environments', 'raw-wire-dashboard'); ?></span>
                        <span class="status-value"><?php echo count($status['environments']); ?> configured</span>
                    </div>
                    <?php endif; ?>
                </div>
                
                <?php if (!$status['available']): ?>
                <div class="install-notice">
                    <p><?php echo wp_kses_post(rawwire_ai()->get_unavailable_message()); ?></p>
                    <a href="<?php echo esc_url(admin_url('plugin-install.php?s=ai+engine&tab=search&type=term')); ?>" 
                       class="button button-primary">
                        <?php esc_html_e('Install AI Engine', 'raw-wire-dashboard'); ?>
                    </a>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ($status['available']): ?>
            
            <!-- AI Adapter Settings -->
            <div class="rawwire-settings-section">
                <h3><?php esc_html_e('AI Adapter Settings', 'raw-wire-dashboard'); ?></h3>
                
                <form method="post" action="options.php" id="ai-adapter-settings-form">
                    <?php settings_fields('rawwire_ai_settings'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="default_env_id"><?php esc_html_e('Default Environment', 'raw-wire-dashboard'); ?></label>
                            </th>
                            <td>
                                <select name="rawwire_ai_adapter_settings[default_env_id]" id="default_env_id">
                                    <option value=""><?php esc_html_e('— Use AI Engine Default —', 'raw-wire-dashboard'); ?></option>
                                    <?php foreach ($status['environments'] as $env): ?>
                                    <option value="<?php echo esc_attr($env['id']); ?>" 
                                            <?php selected($settings['default_env_id'] ?? '', $env['id']); ?>>
                                        <?php echo esc_html($env['name'] . ' (' . $env['type'] . ')'); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('Select which AI environment to use by default for Raw Wire tools.', 'raw-wire-dashboard'); ?>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="cache_ttl"><?php esc_html_e('Cache Duration', 'raw-wire-dashboard'); ?></label>
                            </th>
                            <td>
                                <input type="number" name="rawwire_ai_adapter_settings[cache_ttl]" id="cache_ttl" 
                                       value="<?php echo esc_attr($settings['cache_ttl'] ?? 3600); ?>" 
                                       min="0" max="86400" step="60">
                                <span class="description"><?php esc_html_e('seconds (0 to disable caching)', 'raw-wire-dashboard'); ?></span>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php esc_html_e('Options', 'raw-wire-dashboard'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="rawwire_ai_adapter_settings[logging_enabled]" value="1" 
                                           <?php checked($settings['logging_enabled'] ?? false); ?>>
                                    <?php esc_html_e('Enable AI query logging', 'raw-wire-dashboard'); ?>
                                </label>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <button type="submit" class="button button-primary"><?php esc_html_e('Save Settings', 'raw-wire-dashboard'); ?></button>
                        <button type="button" class="button" id="test-ai-connection"><?php esc_html_e('Test Connection', 'raw-wire-dashboard'); ?></button>
                    </p>
                </form>
                
                <div id="ai-test-result" class="notice" style="display: none;"></div>
            </div>
            
            <!-- MCP Server Settings -->
            <div class="rawwire-settings-section">
                <h3><?php esc_html_e('MCP Server (Model Context Protocol)', 'raw-wire-dashboard'); ?></h3>
                <p class="description">
                    <?php esc_html_e('Enable MCP to allow AI agents (ChatGPT, Claude) to execute Raw Wire tools directly.', 'raw-wire-dashboard'); ?>
                </p>
                
                <form method="post" action="options.php" id="mcp-settings-form">
                    <?php settings_fields('rawwire_ai_settings'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('MCP Server', 'raw-wire-dashboard'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="rawwire_mcp_settings[enabled]" value="1" 
                                           <?php checked($mcp_settings['enabled'] ?? true); ?>>
                                    <?php esc_html_e('Enable MCP Server', 'raw-wire-dashboard'); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e('When enabled, AI agents can call Raw Wire functions through AI Engine.', 'raw-wire-dashboard'); ?>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php esc_html_e('Security', 'raw-wire-dashboard'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="rawwire_mcp_settings[require_auth]" value="1" 
                                           <?php checked($mcp_settings['require_auth'] ?? true); ?>>
                                    <?php esc_html_e('Require authentication for MCP calls', 'raw-wire-dashboard'); ?>
                                </label>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <button type="submit" class="button button-primary"><?php esc_html_e('Save MCP Settings', 'raw-wire-dashboard'); ?></button>
                    </p>
                </form>
                
                <!-- MCP Tools List -->
                <div class="mcp-tools-section">
                    <h4><?php esc_html_e('Registered MCP Tools', 'raw-wire-dashboard'); ?></h4>
                    <p class="description"><?php esc_html_e('These tools are available to AI agents:', 'raw-wire-dashboard'); ?></p>
                    
                    <div id="mcp-tools-list">
                        <p class="loading"><?php esc_html_e('Loading tools...', 'raw-wire-dashboard'); ?></p>
                    </div>
                </div>
            </div>
            
            <!-- Quick Test Panel -->
            <div class="rawwire-settings-section">
                <h3><?php esc_html_e('Quick AI Test', 'raw-wire-dashboard'); ?></h3>
                
                <div class="ai-test-panel">
                    <textarea id="ai-test-prompt" rows="3" 
                              placeholder="<?php esc_attr_e('Enter a test prompt...', 'raw-wire-dashboard'); ?>"></textarea>
                    <div class="test-actions">
                        <button type="button" class="button" id="run-ai-test"><?php esc_html_e('Send Test Query', 'raw-wire-dashboard'); ?></button>
                        <span class="ai-test-status"></span>
                    </div>
                    <div id="ai-test-output" class="ai-output-box" style="display: none;"></div>
                </div>
            </div>
            
            <?php endif; ?>
        </div>
        
        <style>
            .rawwire-ai-settings-panel {
                max-width: 900px;
            }
            .rawwire-ai-status-card {
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                padding: 20px;
                margin-bottom: 20px;
            }
            .rawwire-ai-status-card h3 {
                margin-top: 0;
                border-bottom: 1px solid #eee;
                padding-bottom: 10px;
            }
            .status-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 15px;
            }
            .status-item {
                display: flex;
                flex-direction: column;
            }
            .status-label {
                font-weight: 600;
                color: #666;
                font-size: 12px;
                text-transform: uppercase;
                margin-bottom: 5px;
            }
            .status-value {
                font-size: 14px;
            }
            .status-active {
                color: #46b450;
            }
            .status-inactive {
                color: #dc3232;
            }
            .install-notice {
                margin-top: 20px;
                padding: 15px;
                background: #fff8e5;
                border-left: 4px solid #ffb900;
            }
            .rawwire-settings-section {
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                padding: 20px;
                margin-bottom: 20px;
            }
            .rawwire-settings-section h3 {
                margin-top: 0;
            }
            .mcp-tools-section {
                margin-top: 20px;
                padding-top: 20px;
                border-top: 1px solid #eee;
            }
            .mcp-tool-item {
                background: #f6f7f7;
                padding: 10px 15px;
                margin: 5px 0;
                border-radius: 4px;
            }
            .mcp-tool-item .tool-name {
                font-weight: 600;
                color: #0073aa;
            }
            .mcp-tool-item .tool-desc {
                color: #666;
                font-size: 13px;
                margin-top: 5px;
            }
            .ai-test-panel textarea {
                width: 100%;
                padding: 10px;
                border: 1px solid #ddd;
                border-radius: 4px;
            }
            .ai-test-panel .test-actions {
                margin: 10px 0;
            }
            .ai-output-box {
                background: #f6f7f7;
                padding: 15px;
                border-radius: 4px;
                margin-top: 10px;
                white-space: pre-wrap;
                font-family: monospace;
                font-size: 13px;
                max-height: 300px;
                overflow-y: auto;
            }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // Load MCP tools
            function loadMcpTools() {
                $.post(ajaxurl, {
                    action: 'rawwire_mcp_list_tools',
                    _wpnonce: '<?php echo wp_create_nonce('rawwire_mcp_nonce'); ?>'
                }, function(response) {
                    if (response.success) {
                        var html = '';
                        response.data.tools.forEach(function(tool) {
                            html += '<div class="mcp-tool-item">';
                            html += '<div class="tool-name">' + tool.name + '</div>';
                            html += '<div class="tool-desc">' + tool.description + '</div>';
                            html += '</div>';
                        });
                        $('#mcp-tools-list').html(html || '<p>No tools registered.</p>');
                    }
                });
            }
            loadMcpTools();
            
            // Test connection
            $('#test-ai-connection').on('click', function() {
                var $btn = $(this);
                var $result = $('#ai-test-result');
                
                $btn.prop('disabled', true).text('Testing...');
                $result.removeClass('notice-error notice-success').html('<p>Connecting to AI provider...</p>').show();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'rawwire_ai_test_connection',
                        _wpnonce: '<?php echo wp_create_nonce('rawwire_ai_nonce'); ?>'
                    },
                    timeout: 60000, // 60 second timeout for AI calls
                    success: function(response) {
                        $btn.prop('disabled', false).text('Test Connection');
                        
                        if (response.success) {
                            $result.removeClass('notice-error').addClass('notice-success')
                                   .html('<p>' + response.data.message + '</p>').show();
                        } else {
                            $result.removeClass('notice-success').addClass('notice-error')
                                   .html('<p>Error: ' + (response.data ? response.data.message : 'Unknown error') + '</p>').show();
                        }
                    },
                    error: function(xhr, status, error) {
                        $btn.prop('disabled', false).text('Test Connection');
                        var msg = status === 'timeout' ? 'Request timed out. The AI provider may be slow.' : ('AJAX Error: ' + error);
                        $result.removeClass('notice-success').addClass('notice-error')
                               .html('<p>' + msg + '</p>').show();
                    }
                });
            });
            
            // Quick AI test
            $('#run-ai-test').on('click', function() {
                var prompt = $('#ai-test-prompt').val();
                if (!prompt) return;
                
                var $btn = $(this);
                var $output = $('#ai-test-output');
                var $status = $('.ai-test-status');
                
                $btn.prop('disabled', true);
                $status.text('Processing...');
                $output.hide();
                
                $.post(ajaxurl, {
                    action: 'rawwire_ai_test_connection',
                    prompt: prompt,
                    _wpnonce: '<?php echo wp_create_nonce('rawwire_ai_nonce'); ?>'
                }, function(response) {
                    $btn.prop('disabled', false);
                    $status.text('');
                    
                    if (response.success && response.data.result) {
                        $output.text(response.data.result).show();
                    } else {
                        $output.text('Error: ' + (response.data.message || 'Unknown error')).show();
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX: Test AI connection
     */
    public function ajax_test_connection() {
        check_ajax_referer('rawwire_ai_nonce', '_wpnonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        try {
            $ai = rawwire_ai();
            
            if (!$ai->is_available()) {
                wp_send_json_error(['message' => 'AI Engine is not installed or activated.']);
            }
            
            // Get current provider info for debugging
            $status = $ai->get_status();
            $provider = $status['default_env']['type'] ?? 'unknown';

            $prompt = isset($_POST['prompt']) ? sanitize_textarea_field($_POST['prompt']) : 'Say "Hello from Raw Wire!" in exactly 5 words.';
            
            // Set execution time limit for long AI calls
            if (!ini_get('safe_mode')) {
                set_time_limit(60);
            }
            
            $result = $ai->text_query($prompt);
            
            if (is_wp_error($result)) {
                wp_send_json_error([
                    'message' => 'AI Error: ' . $result->get_error_message(),
                    'provider' => $provider
                ]);
            }
            
            if (empty($result)) {
                wp_send_json_error([
                    'message' => 'AI returned empty response. Check your API key and model settings.',
                    'provider' => $provider
                ]);
            }

            wp_send_json_success([
                'message' => 'Connection successful! (' . ucfirst($provider) . ')',
                'result'  => $result,
                'provider' => $provider
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => 'Exception: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * AJAX: Get AI status
     */
    public function ajax_get_status() {
        check_ajax_referer('rawwire_ai_nonce', '_wpnonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $ai = rawwire_ai();
        wp_send_json_success($ai->get_status());
    }

    /**
     * AJAX: List MCP tools
     */
    public function ajax_list_mcp_tools() {
        check_ajax_referer('rawwire_mcp_nonce', '_wpnonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $mcp = RawWire_MCP_Server::get_instance();
        $tools = $mcp->get_tools();
        
        $tool_list = [];
        foreach ($tools as $name => $tool) {
            $tool_list[] = [
                'name'        => $name,
                'description' => $tool['description'] ?? '',
            ];
        }

        wp_send_json_success(['tools' => $tool_list]);
    }
}

// Initialize
RawWire_AI_Settings_Panel::get_instance();
