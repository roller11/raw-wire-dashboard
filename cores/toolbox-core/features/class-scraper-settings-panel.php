<?php
/**
 * Scraper Settings Admin Panel
 * 
 * Renders the scraper configuration UI in WordPress admin settings.
 * Full-featured horizontal form for adding and managing data sources.
 * 
 * @package RawWire_Dashboard
 * @subpackage Toolbox_Core
 */

if (!defined('ABSPATH')) {
    exit;
}

class RawWire_Scraper_Settings_Panel {
    
    /**
     * Singleton instance
     */
    private static $instance = null;
    
    /**
     * Source types with definitive list
     */
    const SOURCE_TYPES = array(
        'rest_api' => 'REST API',
        'graphql' => 'GraphQL API',
        'rss_feed' => 'RSS/Atom Feed',
        'html_scrape' => 'HTML Web Page',
        'xml_sitemap' => 'XML Sitemap',
        'json_file' => 'JSON File',
        'csv_file' => 'CSV File',
        'database' => 'External Database',
    );
    
    /**
     * Authentication types
     */
    const AUTH_TYPES = array(
        'none' => 'No Authentication',
        'api_key' => 'API Key',
        'bearer_token' => 'Bearer Token',
        'basic_auth' => 'Basic Auth (User/Pass)',
        'oauth2' => 'OAuth 2.0',
    );
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_assets($hook) {
        if (strpos($hook, 'raw-wire') === false) {
            return;
        }
        
        wp_enqueue_style(
            'rawwire-scraper-settings',
            plugins_url('assets/css/scraper-settings.css', dirname(dirname(dirname(__FILE__)))),
            array(),
            '1.0.1'
        );
        
        wp_enqueue_script(
            'rawwire-scraper-settings',
            plugins_url('assets/js/scraper-settings.js', dirname(dirname(dirname(__FILE__)))),
            array('jquery'),
            '1.0.1',
            true
        );
        
        wp_localize_script('rawwire-scraper-settings', 'RawWireScraperCfg', array(
            'nonce' => wp_create_nonce('rawwire_scraper_nonce'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'sourceTypes' => self::SOURCE_TYPES,
            'authTypes' => self::AUTH_TYPES,
            'protocols' => RawWire_Scraper_Settings::get_protocols(),
            'presets' => RawWire_Scraper_Settings::get_presets(),
        ));
    }
    
    /**
     * Render the settings panel
     * 
     * @param array $panel Panel configuration (optional, passed by template engine)
     * @param array $context Template context (optional, passed by template engine)
     */
    public static function render($panel = array(), $context = array()) {
        $settings = RawWire_Scraper_Settings::get_settings();
        $sources = RawWire_Scraper_Settings::get_sources();
        $fields = RawWire_Scraper_Settings::get_fields();
        $presets = RawWire_Scraper_Settings::get_presets();
        
        ?>
        <div class="rawwire-scraper-settings" id="scraper-settings-panel">
            
            <!-- Enable/Disable Toggle -->
            <div class="rawwire-scraper-header">
                <div class="rawwire-enable-toggle">
                    <label class="rawwire-switch">
                        <input type="checkbox" 
                               id="scraper-enabled"
                               <?php checked($settings['enabled']); ?>>
                        <span class="rawwire-switch-slider"></span>
                    </label>
                    <div class="rawwire-toggle-info">
                        <strong>Scraper Toolkit</strong>
                        <span class="rawwire-status-badge <?php echo $settings['enabled'] ? 'active' : 'inactive'; ?>">
                            <?php echo $settings['enabled'] ? 'Active' : 'Disabled'; ?>
                        </span>
                    </div>
                </div>
                <p class="rawwire-description">Configure data sources for collecting public domain content. Add multiple sources with custom output tables and field mappings.</p>
            </div>
            
            <!-- Main Configuration (shown when enabled) -->
            <div class="rawwire-scraper-config" id="scraper-config" style="<?php echo $settings['enabled'] ? '' : 'display:none;'; ?>">
                
                <!-- Quick Add Presets -->
                <div class="rawwire-section rawwire-presets-section">
                    <div class="rawwire-section-header">
                        <h3><span class="dashicons dashicons-star-filled"></span> Quick Add Public Domain Sources</h3>
                    </div>
                    <div class="rawwire-preset-buttons">
                        <?php foreach ($presets as $key => $preset): ?>
                            <button type="button" 
                                    class="rawwire-preset-btn" 
                                    data-preset="<?php echo esc_attr($key); ?>"
                                    title="<?php echo esc_attr($preset['description']); ?>">
                                <?php echo esc_html($preset['name']); ?>
                                <?php if (!empty($preset['requires_key'])): ?>
                                    <span class="rawwire-key-icon">🔑</span>
                                <?php endif; ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- Add Source Form - Horizontal Layout -->
                <div class="rawwire-section rawwire-add-source-section">
                    <div class="rawwire-section-header">
                        <h3><span class="dashicons dashicons-plus-alt2"></span> Add Data Source</h3>
                    </div>
                    
                    <div class="rawwire-source-form" id="source-form">
                        <!-- Row 1: Basic Info -->
                        <div class="rawwire-form-row rawwire-form-row-inline">
                            <div class="rawwire-field rawwire-field-name">
                                <label>Source Name <span class="required">*</span></label>
                                <input type="text" id="source-name" placeholder="My Data Source" required>
                            </div>
                            
                            <div class="rawwire-field rawwire-field-type">
                                <label>Type <span class="required">*</span></label>
                                <select id="source-type">
                                    <?php foreach (self::SOURCE_TYPES as $key => $label): ?>
                                        <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="rawwire-field rawwire-field-address-type">
                                <label>Address Type</label>
                                <div class="rawwire-toggle-group">
                                    <label class="rawwire-radio-btn active" data-value="url">
                                        <input type="radio" name="address-type" value="url" checked> URL
                                    </label>
                                    <label class="rawwire-radio-btn" data-value="api">
                                        <input type="radio" name="address-type" value="api"> API Endpoint
                                    </label>
                                </div>
                            </div>
                            
                            <div class="rawwire-field rawwire-field-address">
                                <label><span id="address-label">URL</span> <span class="required">*</span></label>
                                <input type="text" id="source-address" placeholder="https://example.com/data" required>
                            </div>
                        </div>
                        
                        <!-- Row 2: Authentication -->
                        <div class="rawwire-form-row rawwire-form-row-inline">
                            <div class="rawwire-field rawwire-field-auth">
                                <label>Authentication</label>
                                <select id="source-auth-type">
                                    <?php foreach (self::AUTH_TYPES as $key => $label): ?>
                                        <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="rawwire-field rawwire-field-auth-key" id="auth-key-field" style="display:none;">
                                <label>API Key / Token</label>
                                <input type="password" id="source-auth-key" placeholder="Enter key or token">
                            </div>
                            
                            <div class="rawwire-field rawwire-field-auth-user" id="auth-user-field" style="display:none;">
                                <label>Username</label>
                                <input type="text" id="source-auth-user" placeholder="Username">
                            </div>
                            
                            <div class="rawwire-field rawwire-field-auth-pass" id="auth-pass-field" style="display:none;">
                                <label>Password</label>
                                <input type="password" id="source-auth-pass" placeholder="Password">
                            </div>
                            
                            <div class="rawwire-field rawwire-field-records">
                                <label>Records to Collect</label>
                                <input type="number" id="source-records" value="10" min="1" max="1000">
                            </div>
                            
                            <div class="rawwire-field rawwire-field-copyright">
                                <label>Copyright Status</label>
                                <select id="source-copyright">
                                    <option value="public_domain">Public Domain</option>
                                    <option value="open_access">Open Access</option>
                                    <option value="creative_commons">Creative Commons</option>
                                    <option value="open_source">Open Source</option>
                                    <option value="fair_use">Fair Use</option>
                                    <option value="unknown">Unknown</option>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Row 3: Output Configuration -->
                        <div class="rawwire-form-row rawwire-form-row-inline">
                            <div class="rawwire-field rawwire-field-table">
                                <label>Target Workflow Table <span class="required">*</span></label>
                                <select id="source-table" required>
                                    <option value="candidates" selected>Candidates (default - new scraped items)</option>
                                    <option value="approvals">Approvals (pre-approved content)</option>
                                    <option value="content">Content (ready for editing)</option>
                                    <option value="releases">Releases (scheduled content)</option>
                                    <option value="published">Published (archive reference)</option>
                                    <option value="archives">Archives (historical data)</option>
                                </select>
                                <p class="rawwire-hint">Scraped content flows: Candidates → Approvals → Content → Releases → Published → Archives</p>
                            </div>
                            
                            <div class="rawwire-field rawwire-field-columns">
                                <label>Table Columns (comma-separated) <span class="required">*</span></label>
                                <input type="text" id="source-columns" 
                                       placeholder="title, summary, source_url, published_date, copyright_status" 
                                       value="title, summary, source_url, published_date, author, copyright_status"
                                       required>
                                <p class="rawwire-hint">Available: title, summary, full_content, source_url, published_date, author, categories, images, metadata, copyright_status</p>
                            </div>
                            
                            <div class="rawwire-field rawwire-field-action">
                                <label>&nbsp;</label>
                                <button type="button" class="rawwire-btn rawwire-btn-primary" id="add-source-btn">
                                    <span class="dashicons dashicons-plus-alt2"></span> ADD SOURCE
                                </button>
                            </div>
                        </div>
                        
                        <!-- Advanced Options (collapsed) -->
                        <details class="rawwire-advanced">
                            <summary><span class="dashicons dashicons-admin-tools"></span> Advanced Options</summary>
                            <div class="rawwire-form-row rawwire-form-row-inline">
                                <div class="rawwire-field">
                                    <label>Request Timeout (sec)</label>
                                    <input type="number" id="source-timeout" value="30" min="5" max="120">
                                </div>
                                <div class="rawwire-field">
                                    <label>Request Delay (sec)</label>
                                    <input type="number" id="source-delay" value="1" min="0" max="30" step="0.5">
                                </div>
                                <div class="rawwire-field">
                                    <label>Custom Headers (JSON)</label>
                                    <input type="text" id="source-headers" placeholder='{"Accept": "application/json"}'>
                                </div>
                                <div class="rawwire-field">
                                    <label>Query Parameters</label>
                                    <input type="text" id="source-params" placeholder="per_page=20&sort=date">
                                </div>
                            </div>
                            <div class="rawwire-form-row rawwire-form-row-inline rawwire-html-selectors" style="display:none;">
                                <div class="rawwire-field">
                                    <label>Title Selector</label>
                                    <input type="text" id="selector-title" placeholder="h1.title, .article-title">
                                </div>
                                <div class="rawwire-field">
                                    <label>Content Selector</label>
                                    <input type="text" id="selector-content" placeholder=".article-body, #content">
                                </div>
                                <div class="rawwire-field">
                                    <label>Date Selector</label>
                                    <input type="text" id="selector-date" placeholder="time, .publish-date">
                                </div>
                                <div class="rawwire-field">
                                    <label>Link Selector</label>
                                    <input type="text" id="selector-link" placeholder="a.read-more">
                                </div>
                            </div>
                        </details>
                    </div>
                </div>
                
                <!-- Configured Sources List -->
                <div class="rawwire-section rawwire-sources-section">
                    <div class="rawwire-section-header">
                        <h3><span class="dashicons dashicons-list-view"></span> Configured Sources</h3>
                        <span class="rawwire-count" id="source-count"><?php echo count($sources); ?></span>
                    </div>
                    
                    <div class="rawwire-sources-table-wrap">
                        <table class="rawwire-sources-table" id="sources-table">
                            <thead>
                                <tr>
                                    <th class="col-enabled">On</th>
                                    <th class="col-name">Source Name</th>
                                    <th class="col-type">Type</th>
                                    <th class="col-address">Address</th>
                                    <th class="col-auth">Auth</th>
                                    <th class="col-records">Records</th>
                                    <th class="col-table">Output Table</th>
                                    <th class="col-columns">Columns</th>
                                    <th class="col-copyright">Copyright</th>
                                    <th class="col-actions">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="sources-tbody">
                                <?php if (empty($sources)): ?>
                                    <tr class="rawwire-empty-row" id="empty-row">
                                        <td colspan="10">
                                            <div class="rawwire-empty-state">
                                                <span class="dashicons dashicons-database-add"></span>
                                                <p>No sources configured yet. Add a preset or custom source above.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($sources as $id => $source): ?>
                                        <?php echo self::render_source_row($id, $source); ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="rawwire-bulk-actions">
                        <button type="button" class="rawwire-btn rawwire-btn-secondary" id="test-all-btn">
                            <span class="dashicons dashicons-yes-alt"></span> Test All
                        </button>
                        <button type="button" class="rawwire-btn rawwire-btn-secondary" id="enable-all-btn">
                            <span class="dashicons dashicons-visibility"></span> Enable All
                        </button>
                        <button type="button" class="rawwire-btn rawwire-btn-secondary" id="disable-all-btn">
                            <span class="dashicons dashicons-hidden"></span> Disable All
                        </button>
                        <button type="button" class="rawwire-btn rawwire-btn-danger" id="clear-all-btn">
                            <span class="dashicons dashicons-trash"></span> Clear All
                        </button>
                    </div>
                </div>
                
                <!-- Collection Settings -->
                <div class="rawwire-section rawwire-settings-section">
                    <div class="rawwire-section-header">
                        <h3><span class="dashicons dashicons-admin-generic"></span> Collection Settings</h3>
                    </div>
                    
                    <div class="rawwire-form-row rawwire-form-row-inline">
                        <div class="rawwire-field">
                            <label>Default Records Per Source</label>
                            <input type="number" id="default-records" value="<?php echo esc_attr($settings['default_records_per_source']); ?>" min="1" max="100">
                        </div>
                        <div class="rawwire-field">
                            <label>Copyright Filter</label>
                            <select id="copyright-filter">
                                <option value="public_only" <?php selected($settings['copyright_filter'], 'public_only'); ?>>Public Domain Only</option>
                                <option value="open_license" <?php selected($settings['copyright_filter'], 'open_license'); ?>>Open Licenses (CC, MIT)</option>
                                <option value="all" <?php selected($settings['copyright_filter'], 'all'); ?>>All (Manual Review)</option>
                            </select>
                        </div>
                        <div class="rawwire-field">
                            <label>User Agent</label>
                            <input type="text" id="user-agent" value="<?php echo esc_attr($settings['user_agent']); ?>">
                        </div>
                        <div class="rawwire-field rawwire-field-checkbox">
                            <label>
                                <input type="checkbox" id="respect-robots" <?php checked($settings['respect_robots_txt']); ?>>
                                Respect robots.txt
                            </label>
                        </div>
                    </div>
                    
                    <div class="rawwire-form-row rawwire-form-row-inline">
                        <div class="rawwire-field rawwire-field-checkbox">
                            <label>
                                <input type="checkbox" id="auto-schedule" <?php checked($settings['auto_schedule']); ?>>
                                Auto-schedule collection
                            </label>
                        </div>
                        <div class="rawwire-field rawwire-schedule-field" style="<?php echo $settings['auto_schedule'] ? '' : 'display:none;'; ?>">
                            <label>Schedule Interval</label>
                            <select id="schedule-interval">
                                <option value="hourly" <?php selected($settings['schedule_interval'], 'hourly'); ?>>Hourly</option>
                                <option value="twicedaily" <?php selected($settings['schedule_interval'], 'twicedaily'); ?>>Twice Daily</option>
                                <option value="daily" <?php selected($settings['schedule_interval'], 'daily'); ?>>Daily</option>
                                <option value="weekly" <?php selected($settings['schedule_interval'], 'weekly'); ?>>Weekly</option>
                            </select>
                        </div>
                        <div class="rawwire-field rawwire-field-checkbox">
                            <label>
                                <input type="checkbox" id="store-raw" <?php checked($settings['store_raw_response']); ?>>
                                Store raw responses
                            </label>
                        </div>
                    </div>
                    
                    <div class="rawwire-form-actions">
                        <button type="button" class="rawwire-btn rawwire-btn-primary" id="save-settings-btn">
                            <span class="dashicons dashicons-saved"></span> Save Settings
                        </button>
                        <button type="button" class="rawwire-btn rawwire-btn-success" id="run-now-btn">
                            <span class="dashicons dashicons-update"></span> Run Collection Now
                        </button>
                    </div>
                </div>
                
            </div>
        </div>
        <?php
    }
    
    /**
     * Render a single source row for the table
     */
    public static function render_source_row($id, $source) {
        $type_label = self::SOURCE_TYPES[$source['type'] ?? 'rest_api'] ?? 'Unknown';
        $auth_label = self::AUTH_TYPES[$source['auth_type'] ?? 'none'] ?? 'None';
        
        ob_start();
        ?>
        <tr data-source-id="<?php echo esc_attr($id); ?>">
            <td class="col-enabled">
                <label class="rawwire-mini-switch">
                    <input type="checkbox" class="source-toggle" <?php checked($source['enabled'] ?? true); ?>>
                    <span class="slider"></span>
                </label>
            </td>
            <td class="col-name">
                <strong><?php echo esc_html($source['name']); ?></strong>
            </td>
            <td class="col-type">
                <span class="rawwire-type-badge type-<?php echo esc_attr($source['type'] ?? 'rest_api'); ?>">
                    <?php echo esc_html($type_label); ?>
                </span>
            </td>
            <td class="col-address">
                <span class="rawwire-address" title="<?php echo esc_attr($source['address'] ?? $source['url'] ?? ''); ?>">
                    <?php echo esc_html(substr($source['address'] ?? $source['url'] ?? '', 0, 40)); ?>...
                </span>
            </td>
            <td class="col-auth">
                <?php if (($source['auth_type'] ?? 'none') !== 'none'): ?>
                    <span class="rawwire-auth-badge">
                        <span class="dashicons dashicons-lock"></span>
                        <?php echo esc_html($auth_label); ?>
                    </span>
                <?php else: ?>
                    <span class="rawwire-auth-none">None</span>
                <?php endif; ?>
            </td>
            <td class="col-records">
                <span class="rawwire-records"><?php echo intval($source['records_limit'] ?? 10); ?></span>
            </td>
            <td class="col-table">
                <code><?php echo esc_html($source['output_table'] ?? 'scraped_data'); ?></code>
            </td>
            <td class="col-columns">
                <span class="rawwire-columns" title="<?php echo esc_attr($source['columns'] ?? ''); ?>">
                    <?php 
                    $cols = $source['columns'] ?? '';
                    $col_count = count(array_filter(array_map('trim', explode(',', $cols))));
                    echo esc_html($col_count . ' fields');
                    ?>
                </span>
            </td>
            <td class="col-copyright">
                <span class="rawwire-copyright-badge copyright-<?php echo esc_attr($source['copyright'] ?? 'unknown'); ?>">
                    <?php echo esc_html(ucwords(str_replace('_', ' ', $source['copyright'] ?? 'unknown'))); ?>
                </span>
            </td>
            <td class="col-actions">
                <div class="rawwire-action-btns">
                    <button type="button" class="rawwire-icon-btn test-source" title="Test Connection">
                        <span class="dashicons dashicons-yes-alt"></span>
                    </button>
                    <button type="button" class="rawwire-icon-btn edit-source" title="Edit">
                        <span class="dashicons dashicons-edit"></span>
                    </button>
                    <button type="button" class="rawwire-icon-btn delete-source" title="Delete">
                        <span class="dashicons dashicons-trash"></span>
                    </button>
                </div>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }
}

// Initialize
RawWire_Scraper_Settings_Panel::get_instance();
