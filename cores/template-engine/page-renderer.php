<?php

/**
 * Page Renderer - Renders template pages with panels
 * Path: cores/template-engine/page-renderer.php
 *
 * Handles rendering of complete pages from template configuration:
 * - Dashboard main page
 * - Workflow pages (Approvals, Release, etc.)
 * - Settings pages
 * - Custom pages defined in template
 */

if (!class_exists('RawWire_Page_Renderer')) {
    class RawWire_Page_Renderer
    {

        /**
         * Render a page based on template configuration
         * @param string $page_id Page ID from template
         * @param array $context Additional context
         * @return string HTML output
         */
        public static function render($page_id, $context = array())
        {
            if (!class_exists('RawWire_Template_Engine')) {
                return '<div class="error"><p>Template Engine not available</p></div>';
            }

            $page = RawWire_Template_Engine::get_page($page_id);

            if (!$page) {
                return '<div class="error"><p>Page not found: ' . esc_html($page_id) . '</p></div>';
            }

            $layout = $page['layout'] ?? 'default';
            $panels = $page['panels'] ?? array();
            $actions = $page['actions'] ?? array();
            $title = $page['title'] ?? '';
            $template_meta = RawWire_Template_Engine::get_meta();

            ob_start();

            // Get available tools from template
            $tools = RawWire_Template_Engine::get_template()['tools'] ?? array();

            // Check developer mode
            $dev_mode = class_exists('RawWire_Dev_Auth') ? RawWire_Dev_Auth::is_dev_mode_active() : false;
?>
            <!-- DEBUG: page-renderer.php is executing, dev_mode=<?php echo $dev_mode ? 'true' : 'false'; ?> -->
            <script>
                console.log('[RW DEBUG] page-renderer.php loaded');
                console.log('[RW DEBUG] RawWireCfg available:', typeof RawWireCfg !== 'undefined');
                console.log('[RW DEBUG] rawwire_admin available:', typeof rawwire_admin !== 'undefined');
                jQuery(document).ready(function($) {
                    console.log('[RW DEBUG] Document ready');
                    console.log('[RW DEBUG] RawWireAdmin exists:', typeof window.RawWireAdmin !== 'undefined');
                    console.log('[RW DEBUG] Builder exists:', typeof window.RawWireAdmin !== 'undefined' && typeof window.RawWireAdmin.Builder !== 'undefined');
                    console.log('[RW DEBUG] Button found:', $('#rawwire-builder-toggle').length);
                    console.log('[RW DEBUG] Modal found:', $('#rawwire-dev-login-modal').length);
                    // Test click directly
                    $('#rawwire-builder-toggle').on('click.debug', function() {
                        console.log('[RW DEBUG] Button clicked via debug handler!');
                        console.log('[RW DEBUG] Builder.isDevMode:', window.RawWireAdmin && window.RawWireAdmin.Builder ? window.RawWireAdmin.Builder.isDevMode : 'N/A');
                        var $modal = $('#rawwire-dev-login-modal');
                        console.log('[RW DEBUG] Modal display after click:', $modal.css('display'));
                        console.log('[RW DEBUG] Modal visibility:', $modal.css('visibility'));
                        console.log('[RW DEBUG] Modal opacity:', $modal.css('opacity'));
                        console.log('[RW DEBUG] Modal z-index:', $modal.css('z-index'));
                        console.log('[RW DEBUG] Modal dimensions:', $modal.width(), 'x', $modal.height());
                        // Force show for testing
                        setTimeout(function() {
                            console.log('[RW DEBUG] Modal display 500ms later:', $modal.css('display'));
                            if ($modal.css('display') === 'none') {
                                console.log('[RW DEBUG] FORCING modal visible');
                                $modal.css({
                                    'display': 'flex',
                                    'opacity': 1,
                                    'visibility': 'visible',
                                    'z-index': 999999
                                });
                            }
                        }, 500);
                    });
                });
            </script>
            <div class="wrap rawwire-dashboard" data-page="<?php echo esc_attr($page_id); ?>" data-dev-mode="<?php echo $dev_mode ? 'true' : 'false'; ?>">
                <?php if (empty($context['skip_dev_bar'])): ?>
                    <!-- Developer Toggle Bar -->
                    <div class="rawwire-builder-toggle-bar">
                        <button type="button" class="rawwire-builder-toggle" id="rawwire-builder-toggle">
                            <span class="dashicons dashicons-lock"></span>
                            <span class="btn-text"><?php echo $dev_mode ? 'Developer' : 'Developer'; ?></span>
                            <?php if ($dev_mode): ?>
                                <span class="rawwire-dev-badge">ON</span>
                            <?php endif; ?>
                            <span class="dashicons dashicons-arrow-down-alt2 toggle-arrow"></span>
                        </button>
                        <?php if ($dev_mode): ?>
                            <button type="button" class="rawwire-dev-logout-btn" id="rawwire-dev-logout" title="Lock developer mode">
                                <span class="dashicons dashicons-dismiss"></span>
                                Lock
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endif; /* skip_dev_bar */ ?>

                <!-- Developer Login Modal (shown when not authenticated) -->
                <div id="rawwire-dev-login-modal" class="rawwire-modal-overlay">
                    <div class="rawwire-modal rawwire-modal-sm">
                        <div class="rawwire-modal-header">
                            <h2>
                                <span class="dashicons dashicons-lock"></span>
                                Developer Access
                            </h2>
                            <button type="button" class="rawwire-modal-close">&times;</button>
                        </div>
                        <div class="rawwire-modal-body">
                            <p class="rawwire-dev-login-desc">Enter developer credentials to unlock the builder toolbar and developer tools.</p>
                            <div class="rawwire-dev-login-form">
                                <div class="rawwire-form-field">
                                    <label for="rawwire-dev-username">Username</label>
                                    <input type="text" id="rawwire-dev-username" autocomplete="off" placeholder="Developer username">
                                </div>
                                <div class="rawwire-form-field">
                                    <label for="rawwire-dev-password">Password</label>
                                    <input type="password" id="rawwire-dev-password" autocomplete="off" placeholder="Developer password">
                                </div>
                                <div class="rawwire-dev-login-error" id="rawwire-dev-login-error" style="display: none;"></div>
                                <div class="rawwire-form-actions">
                                    <button type="button" class="rawwire-btn rawwire-btn-primary" id="rawwire-dev-login-submit">
                                        <span class="dashicons dashicons-unlock"></span>
                                        Unlock Developer Mode
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Builder Toolbar (hidden by default, only available in dev mode) -->
                <div class="rawwire-builder-toolbar" id="rawwire-builder-toolbar" style="display: none;" <?php if (!$dev_mode) echo ' data-locked="true"'; ?>>
                    <div class="rawwire-builder-section rawwire-builder-actions">
                        <button type="button" class="rawwire-builder-btn" id="rawwire-clear-cache" title="Clear Cache">
                            <span class="dashicons dashicons-trash"></span>
                            Clear Cache
                        </button>
                        <button type="button" class="rawwire-builder-btn" id="rawwire-reload-page" title="Reload Page">
                            <span class="dashicons dashicons-update"></span>
                            Reload
                        </button>
                    </div>

                    <?php if (!empty($tools)): ?>
                        <div class="rawwire-builder-section rawwire-builder-tools">
                            <span class="rawwire-builder-label">Tools:</span>
                            <?php foreach ($tools as $tool_id => $tool): ?>
                                <label class="rawwire-builder-toggle-label">
                                    <input type="checkbox"
                                        class="rawwire-tool-toggle"
                                        data-tool="<?php echo esc_attr($tool_id); ?>"
                                        checked>
                                    <span class="dashicons <?php echo esc_attr($tool['icon'] ?? 'dashicons-admin-generic'); ?>"></span>
                                    <?php echo esc_html($tool['label'] ?? $tool_id); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="rawwire-builder-section rawwire-sidebar-toggles">
                        <button type="button" class="rawwire-builder-btn" data-builder-action="toggle-sidebar-left">
                            <span class="dashicons dashicons-align-pull-left"></span>
                            Left
                        </button>
                        <button type="button" class="rawwire-builder-btn" data-builder-action="toggle-sidebar-right">
                            <span class="dashicons dashicons-align-pull-right"></span>
                            Right
                        </button>
                    </div>
                    <div class="rawwire-builder-section rawwire-row-controls">
                        <button type="button" class="rawwire-builder-btn rawwire-builder-btn-accent" data-builder-action="add-row">
                            <span class="dashicons dashicons-plus-alt2"></span>
                            Add Row
                        </button>
                        <button type="button" class="rawwire-builder-btn" data-builder-action="remove-row">
                            <span class="dashicons dashicons-trash"></span>
                            Remove Row
                        </button>
                    </div>
                    <div class="rawwire-builder-section rawwire-template-controls">
                        <button type="button" class="rawwire-builder-btn rawwire-builder-btn-success" data-builder-action="save-template">
                            <span class="dashicons dashicons-cloud-saved"></span>
                            Save Template
                        </button>
                    </div>
                </div>

                <?php if (empty($context['skip_hero'])): ?>
                    <?php self::render_page_header($page, $template_meta); ?>
                <?php endif; ?>

                <?php if (!empty($actions)): ?>
                    <?php self::render_page_actions($actions); ?>
                <?php endif; ?>

                <!-- Main Content Area with Sidebars -->
                <div class="rawwire-dashboard-body">
                    <!-- Left Sidebar (hidden by default) -->
                    <aside class="rawwire-sidebar rawwire-sidebar-left" data-sidebar="left" style="display: none;">
                        <div class="rawwire-sidebar-content">
                            <div class="rawwire-sidebar-header">
                                <h3>Left Sidebar</h3>
                                <button type="button" class="rawwire-sidebar-close" data-builder-action="toggle-sidebar-left">
                                    <span class="dashicons dashicons-no-alt"></span>
                                </button>
                            </div>
                            <div class="rawwire-sidebar-panels">
                                <!-- Sidebar panels will be added here -->
                            </div>
                        </div>
                    </aside>

                    <!-- Main Content -->
                    <main class="rawwire-main-content">
                        <div class="rawwire-page-content rawwire-layout-<?php echo esc_attr($layout); ?>">
                            <?php
                            // Render each panel with collapsible wrapper
                            // Insert the builder drop zone after the last progress-type panel
                            $builder_zone_inserted = false;
                            $last_index = count($panels) - 1;
                            foreach ($panels as $idx => $panel_id) {
                                $panel_config = RawWire_Template_Engine::get_panel($panel_id);
                                if ($panel_config) {
                                    // Pass page actions to panel for card rendering
                                    $panel_config['_page_actions'] = $actions;
                                    echo RawWire_Panel_Renderer::render($panel_config, $context);

                                    // Insert builder zone after progress panel (before log/remaining panels)
                                    $panel_type = $panel_config['type'] ?? '';
                                    if (!$builder_zone_inserted && $panel_type === 'progress' && $idx < $last_index) {
                            ?>
                                        <!-- Row Builder Drop Zone -->
                                        <div class="rawwire-row-builder" id="rawwire-row-builder" style="display: none;">
                                            <div class="rawwire-row-placeholder">
                                                <span class="dashicons dashicons-plus-alt2"></span>
                                                <p>Click "Add Row" to add panel rows</p>
                                            </div>
                                        </div>
                                <?php
                                        $builder_zone_inserted = true;
                                    }
                                }
                            }
                            // Fallback: if no progress panel found, put it at the end
                            if (!$builder_zone_inserted) {
                                ?>
                                <div class="rawwire-row-builder" id="rawwire-row-builder" style="display: none;">
                                    <div class="rawwire-row-placeholder">
                                        <span class="dashicons dashicons-plus-alt2"></span>
                                        <p>Click "Add Row" to add panel rows</p>
                                    </div>
                                </div>
                            <?php
                            }
                            ?>
                        </div>
                    </main>

                    <!-- Right Sidebar (hidden by default) -->
                    <aside class="rawwire-sidebar rawwire-sidebar-right" data-sidebar="right" style="display: none;">
                        <div class="rawwire-sidebar-content">
                            <div class="rawwire-sidebar-header">
                                <h3>Right Sidebar</h3>
                                <button type="button" class="rawwire-sidebar-close" data-builder-action="toggle-sidebar-right">
                                    <span class="dashicons dashicons-no-alt"></span>
                                </button>
                            </div>
                            <div class="rawwire-sidebar-panels">
                                <!-- Sidebar panels will be added here -->
                            </div>
                        </div>
                    </aside>
                </div>

                <?php self::render_page_modals($page_id); ?>
                <?php self::render_row_builder_modal(); ?>
                <?php self::render_remove_row_modal(); ?>
                <?php self::render_slot_picker_modal(); ?>
                <?php self::render_save_template_modal(); ?>
            </div>

            <script type="text/javascript">
                // Initialize page-specific JavaScript
                (function($) {
                    $(document).ready(function() {
                        RawWireAdmin.initPage('<?php echo esc_js($page_id); ?>');
                    });
                })(jQuery);
            </script>
        <?php
            return ob_get_clean();
        }

        /**
         * Render page header - uses rawwire-hero design with logo and builder controls
         */
        protected static function render_page_header($page, $template_meta)
        {
            $title = $page['title'] ?? 'Dashboard';
            $icon = $page['icon'] ?? 'dashicons-admin-generic';
            $template_name = $template_meta['name'] ?? 'Unknown Template';
            $description = $page['description'] ?? 'Professional Ai Powered Solutions';

            // Get logo URL - check uploads folder first, then fallback to plugin assets
            $upload_dir = wp_upload_dir();
            $logo_file = 'RW_IN_CIRCLE_GOLD_BLUE_CROP2-256.png';
            $logo_url = '';

            if (file_exists($upload_dir['basedir'] . '/' . $logo_file)) {
                $logo_url = $upload_dir['baseurl'] . '/' . $logo_file;
            } elseif (defined('RAWWIRE_DASHBOARD_URL')) {
                $logo_url = RAWWIRE_DASHBOARD_URL . 'assets/images/' . $logo_file;
            }
        ?>
            <div class="rawwire-hero">
                <div class="rawwire-hero-content">
                    <?php if ($logo_url): ?>
                        <img src="<?php echo esc_url($logo_url); ?>"
                            alt="Raw Wire Logo"
                            class="rawwire-hero-logo"
                            style="height: 64px; width: auto; margin-bottom: 12px;">
                    <?php endif; ?>
                    <span class="eyebrow"><?php echo esc_html($template_name); ?></span>
                    <h1>
                        <span class="dashicons <?php echo esc_attr($icon); ?>"></span>
                        <?php echo esc_html($title); ?>
                    </h1>
                    <p class="lede"><?php echo esc_html($description); ?></p>
                </div>
                <div class="rawwire-hero-actions">
                    <!-- Theme toggle will be inserted here by JS -->
                </div>
            </div>
        <?php
        }

        /**
         * Render page action buttons
         */
        protected static function render_page_actions($actions)
        {
        ?>
            <div class="rawwire-page-actions">
                <?php foreach ($actions as $action_id => $action): ?>
                    <?php
                    $style = $action['style'] ?? 'secondary';
                    $icon = $action['icon'] ?? '';
                    $label = $action['label'] ?? ucfirst($action_id);
                    ?>
                    <button type="button"
                        class="rawwire-btn rawwire-btn-<?php echo esc_attr($style); ?>"
                        data-page-action="<?php echo esc_attr($action_id); ?>"
                        data-action="<?php echo esc_attr($action['action'] ?? ''); ?>">
                        <?php if ($icon): ?>
                            <span class="dashicons <?php echo esc_attr($icon); ?>"></span>
                        <?php endif; ?>
                        <?php echo esc_html($label); ?>
                    </button>
                <?php endforeach; ?>
            </div>
        <?php
        }

        /**
         * Render page modals
         */
        protected static function render_page_modals($page_id)
        {
        ?>
            <!-- Template Switcher Modal -->
            <div id="rawwire-template-modal" class="rawwire-modal-overlay" style="display: none;">
                <div class="rawwire-modal">
                    <div class="rawwire-modal-header">
                        <h2>Switch Template</h2>
                        <button type="button" class="rawwire-modal-close">&times;</button>
                    </div>
                    <div class="rawwire-modal-body">
                        <div class="rawwire-template-warning">
                            <span class="dashicons dashicons-warning"></span>
                            <p><strong>Warning:</strong> Switching templates will reset your configuration.
                                Would you like to download your current data first?</p>
                        </div>
                        <div class="rawwire-template-options">
                            <label class="rawwire-toggle">
                                <input type="checkbox" id="rawwire-backup-data" checked>
                                <span>Backup current data before switching</span>
                            </label>
                        </div>
                        <div id="rawwire-template-list" class="rawwire-template-grid">
                            <!-- Populated via AJAX -->
                            <p class="loading">Loading templates...</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Item Detail Modal -->
            <div id="rawwire-item-modal" class="rawwire-modal-overlay" style="display: none;">
                <div class="rawwire-modal rawwire-modal-lg">
                    <div class="rawwire-modal-header">
                        <h2 id="rawwire-item-title">Item Details</h2>
                        <button type="button" class="rawwire-modal-close">&times;</button>
                    </div>
                    <div class="rawwire-modal-body" id="rawwire-item-content">
                        <!-- Populated dynamically -->
                    </div>
                    <div class="rawwire-modal-footer" id="rawwire-item-actions">
                        <!-- Action buttons populated dynamically -->
                    </div>
                </div>
            </div>

            <!-- Confirm Modal -->
            <div id="rawwire-confirm-modal" class="rawwire-modal-overlay">
                <div class="rawwire-modal rawwire-modal-sm">
                    <div class="rawwire-modal-header">
                        <h2>Confirm Action</h2>
                        <button type="button" class="rawwire-modal-close">&times;</button>
                    </div>
                    <div class="rawwire-modal-body">
                        <p id="rawwire-confirm-message">Are you sure?</p>
                    </div>
                    <div class="rawwire-modal-footer">
                        <button type="button" class="rawwire-btn rawwire-btn-secondary rawwire-confirm-cancel">Cancel</button>
                        <button type="button" class="rawwire-btn rawwire-btn-danger rawwire-confirm-ok">Confirm</button>
                    </div>
                </div>
            </div>

            <!-- Generator Modal -->
            <div id="rawwire-generator-modal" class="rawwire-modal-overlay" style="display: none;">
                <div class="rawwire-modal rawwire-modal-lg">
                    <div class="rawwire-modal-header">
                        <h2>AI Content Generator</h2>
                        <button type="button" class="rawwire-modal-close">&times;</button>
                    </div>
                    <div class="rawwire-modal-body">
                        <div class="rawwire-form-group">
                            <label>Generation Mode</label>
                            <select id="rawwire-gen-mode">
                                <option value="rewrite">Rewrite for Audience</option>
                                <option value="summarize">Summarize</option>
                                <option value="generate_headline">Generate Headlines</option>
                                <option value="expand">Expand Article</option>
                            </select>
                        </div>

                        <div class="rawwire-form-group rawwire-gen-options" data-mode="rewrite">
                            <label>Target Audience</label>
                            <select id="rawwire-gen-audience">
                                <option value="general">General</option>
                                <option value="technical">Technical</option>
                                <option value="business">Business</option>
                                <option value="casual">Casual</option>
                            </select>
                        </div>

                        <div class="rawwire-form-group rawwire-gen-options" data-mode="expand" style="display:none;">
                            <label for="rawwire-gen-wordcount">Word Count</label>
                            <input type="number" id="rawwire-gen-wordcount" value="500" min="200" max="2000">
                        </div>

                        <div class="rawwire-form-group">
                            <label>Original Content</label>
                            <textarea id="rawwire-gen-input" rows="6" readonly></textarea>
                        </div>

                        <div class="rawwire-form-group" id="rawwire-gen-output-group" style="display:none;">
                            <label>Generated Content</label>
                            <textarea id="rawwire-gen-output" rows="8"></textarea>
                        </div>
                    </div>
                    <div class="rawwire-modal-footer">
                        <button type="button" class="rawwire-btn rawwire-btn-secondary rawwire-modal-close">Cancel</button>
                        <button type="button" class="rawwire-btn rawwire-btn-primary" id="rawwire-gen-run">
                            <span class="dashicons dashicons-admin-generic"></span>
                            Generate
                        </button>
                        <button type="button" class="rawwire-btn rawwire-btn-success" id="rawwire-gen-save" style="display:none;">
                            <span class="dashicons dashicons-yes"></span>
                            Save & Continue
                        </button>
                    </div>
                </div>
            </div>

            <!-- Publisher Modal -->
            <div id="rawwire-publish-modal" class="rawwire-modal-overlay" style="display: none;">
                <div class="rawwire-modal">
                    <div class="rawwire-modal-header">
                        <h2>Publish Content</h2>
                        <button type="button" class="rawwire-modal-close">&times;</button>
                    </div>
                    <div class="rawwire-modal-body">
                        <div class="rawwire-form-group">
                            <label>Select Outlets</label>
                            <div id="rawwire-publish-outlets">
                                <!-- Populated from template config -->
                            </div>
                        </div>

                        <div class="rawwire-form-group">
                            <label>Schedule</label>
                            <select id="rawwire-publish-schedule">
                                <option value="now">Publish Now</option>
                                <option value="1h">In 1 Hour</option>
                                <option value="3h">In 3 Hours</option>
                                <option value="6h">In 6 Hours</option>
                                <option value="12h">In 12 Hours</option>
                                <option value="24h">In 24 Hours</option>
                                <option value="custom">Custom Time...</option>
                            </select>
                        </div>

                        <div class="rawwire-form-group" id="rawwire-publish-custom-time" style="display:none;">
                            <label for="rawwire-publish-datetime">Custom Date/Time</label>
                            <input type="datetime-local" id="rawwire-publish-datetime">
                        </div>
                    </div>
                    <div class="rawwire-modal-footer">
                        <button type="button" class="rawwire-btn rawwire-btn-secondary rawwire-modal-close">Cancel</button>
                        <button type="button" class="rawwire-btn rawwire-btn-success" id="rawwire-publish-confirm">
                            <span class="dashicons dashicons-share"></span>
                            Publish
                        </button>
                    </div>
                </div>
            </div>
        <?php
        }

        /**
         * Render the dashboard main page
         */
        public static function render_dashboard($context = array())
        {
            return self::render('dashboard', $context);
        }

        /**
         * Render the approvals page
         */
        public static function render_approvals($context = array())
        {
            return self::render('approvals', $context);
        }

        /**
         * Render the release page
         */
        public static function render_release($context = array())
        {
            return self::render('release', $context);
        }

        /**
         * Render the settings page
         */
        public static function render_settings($context = array())
        {
            return self::render('settings', $context);
        }

        /**
         * Render the lead sources page
         */
        public static function render_lead_sources($context = array())
        {
            return self::render('lead_sources', $context);
        }

        /**
         * Render the lead approvals page
         */
        public static function render_lead_approvals($context = array())
        {
            return self::render('lead_approvals', $context);
        }

        /**
         * Render the completed leads page
         */
        public static function render_lead_completed($context = array())
        {
            return self::render('lead_completed', $context);
        }

        /**
         * Generate admin menu items from template pages
         * @return array Menu configuration
         */
        public static function get_menu_config()
        {
            if (!class_exists('RawWire_Template_Engine')) {
                return array();
            }

            $pages = RawWire_Template_Engine::get_pages();
            $menu_items = array();
            $main_page = null;

            foreach ($pages as $page_id => $page) {
                $item = array(
                    'id' => $page_id,
                    'title' => $page['title'] ?? ucfirst($page_id),
                    'slug' => $page['slug'] ?? 'raw-wire-' . $page_id,
                    'icon' => $page['icon'] ?? 'dashicons-admin-generic',
                    'capability' => 'manage_options',
                );

                if (isset($page['isMain']) && $page['isMain']) {
                    $main_page = $item;
                } else {
                    $menu_items[] = $item;
                }
            }

            return array(
                'main' => $main_page,
                'submenus' => $menu_items,
            );
        }

        /**
         * Get the layout CSS class
         */
        public static function get_layout_class($layout)
        {
            $layouts = array(
                'default' => 'rawwire-layout-default',
                'grid-3col' => 'rawwire-layout-grid-3col',
                'grid-2col' => 'rawwire-layout-grid-2col',
                'list' => 'rawwire-layout-list',
                'tabs' => 'rawwire-layout-tabs',
            );

            return $layouts[$layout] ?? 'rawwire-layout-default';
        }

        /**
         * Render the row builder modal for adding panel rows
         */
        protected static function render_row_builder_modal()
        {
        ?>
            <!-- Row Builder Modal -->
            <div id="rawwire-row-builder-modal" class="rawwire-modal-overlay">
                <div class="rawwire-modal rawwire-modal-md">
                    <div class="rawwire-modal-header">
                        <h2>Add Panel Row</h2>
                    </div>
                    <div class="rawwire-modal-body">
                        <p class="rawwire-modal-description">Select the number of columns for this row:</p>

                        <div class="rawwire-row-options">
                            <button type="button" class="rawwire-row-option" data-columns="1">
                                <div class="rawwire-row-preview rawwire-row-preview-1">
                                    <div class="rawwire-preview-col"></div>
                                </div>
                                <span>1 Column</span>
                            </button>

                            <button type="button" class="rawwire-row-option" data-columns="2">
                                <div class="rawwire-row-preview rawwire-row-preview-2">
                                    <div class="rawwire-preview-col"></div>
                                    <div class="rawwire-preview-col"></div>
                                </div>
                                <span>2 Columns</span>
                            </button>

                            <button type="button" class="rawwire-row-option" data-columns="3">
                                <div class="rawwire-row-preview rawwire-row-preview-3">
                                    <div class="rawwire-preview-col"></div>
                                    <div class="rawwire-preview-col"></div>
                                    <div class="rawwire-preview-col"></div>
                                </div>
                                <span>3 Columns</span>
                            </button>

                            <button type="button" class="rawwire-row-option" data-columns="4">
                                <div class="rawwire-row-preview rawwire-row-preview-4">
                                    <div class="rawwire-preview-col"></div>
                                    <div class="rawwire-preview-col"></div>
                                    <div class="rawwire-preview-col"></div>
                                    <div class="rawwire-preview-col"></div>
                                </div>
                                <span>4 Columns</span>
                            </button>
                        </div>

                        <div class="rawwire-row-custom">
                            <label for="rawwire-custom-columns">Or enter custom column count (1-6):</label>
                            <input type="number" id="rawwire-custom-columns" min="1" max="6" value="2">
                        </div>
                    </div>
                    <div class="rawwire-modal-footer">
                        <button type="button" class="rawwire-btn rawwire-btn-secondary rawwire-modal-close">Cancel</button>
                        <button type="button" class="rawwire-btn rawwire-btn-primary" id="rawwire-add-row-confirm">
                            <span class="dashicons dashicons-plus-alt"></span>
                            Add Row
                        </button>
                    </div>
                </div>
            </div>
        <?php
        }

        /**
         * Render the Remove Row Modal
         */
        protected static function render_remove_row_modal()
        {
        ?>
            <!-- Remove Row Modal -->
            <div id="rawwire-remove-row-modal" class="rawwire-modal-overlay">
                <div class="rawwire-modal rawwire-modal-md">
                    <div class="rawwire-modal-header">
                        <h2>Remove Panel Row</h2>
                    </div>
                    <div class="rawwire-modal-body">
                        <div class="rawwire-alert rawwire-alert-warning" style="margin-bottom: var(--rw-space-4);">
                            <span class="dashicons dashicons-warning"></span>
                            <div>
                                <strong>Warning</strong>
                                <p>This will permanently remove the selected row and all panels within it.</p>
                            </div>
                        </div>

                        <div class="rawwire-form-group">
                            <label for="rawwire-row-select">Select row to remove:</label>
                            <select id="rawwire-row-select" class="rawwire-select">
                                <option value="">-- No rows added yet --</option>
                            </select>
                        </div>
                    </div>
                    <div class="rawwire-modal-footer">
                        <button type="button" class="rawwire-btn rawwire-btn-secondary rawwire-modal-close">Cancel</button>
                        <button type="button" class="rawwire-btn rawwire-btn-danger" id="rawwire-remove-row-confirm" disabled>
                            <span class="dashicons dashicons-trash"></span>
                            Remove Row
                        </button>
                    </div>
                </div>
            </div>
        <?php
        }

        /**
         * Render the Slot Picker Modal
         * Shown when user clicks an empty panel slot.
         * For rows with ≤2 columns: Tool / Workflow / Info options
         * For rows with >2 columns: Info-only options (Card, Button, Spacer)
         */
        protected static function render_slot_picker_modal()
        {
            // Gather available tools from template
            $tools = array();
            $workflows = array();

            if (class_exists('RawWire_Template_Engine')) {
                $template = RawWire_Template_Engine::get_template();
                if ($template) {
                    $tools = $template['tools'] ?? array();
                    $workflows = $template['workflows'] ?? array();
                }
            }

            // Gather registered tool tabs - these represent installed tools
            if (class_exists('RawWire_Menu_Manager')) {
                $tool_tabs = RawWire_Menu_Manager::get_tool_tabs();
                foreach ($tool_tabs as $tool_id => $tab) {
                    if (!isset($tools[$tool_id])) {
                        $tools[$tool_id] = array(
                            'id' => $tool_id,
                            'label' => $tab['label'] ?? ucfirst($tool_id),
                            'icon' => $tab['icon'] ?? 'dashicons-admin-tools',
                            'description' => $tab['description'] ?? '',
                        );
                    }
                }
            }

            // Built-in panel types available for info slots
            $info_panels = array(
                'status' => array('label' => 'Status Metrics', 'icon' => 'dashicons-chart-bar', 'description' => 'Display key metrics and counts'),
                'log' => array('label' => 'Activity Log', 'icon' => 'dashicons-list-view', 'description' => 'Show activity and event logs'),
                'control' => array('label' => 'Control Panel', 'icon' => 'dashicons-admin-settings', 'description' => 'Buttons, toggles, and controls'),
                'data' => array('label' => 'Data Table', 'icon' => 'dashicons-editor-table', 'description' => 'Display data in tables or cards'),
                'custom' => array('label' => 'Custom HTML', 'icon' => 'dashicons-editor-code', 'description' => 'Custom content block'),
            );

            // Info-only items for >2 column rows
            $info_only = array(
                'card' => array('label' => 'Info Card', 'icon' => 'dashicons-id-alt', 'description' => 'Styled info card with title and content'),
                'button' => array('label' => 'Action Button', 'icon' => 'dashicons-button', 'description' => 'A clickable action button'),
                'spacer' => array('label' => 'Spacer', 'icon' => 'dashicons-minus', 'description' => 'Empty space to fill the gap'),
                'stat' => array('label' => 'Stat Counter', 'icon' => 'dashicons-performance', 'description' => 'Single metric display'),
                'notice' => array('label' => 'Notice', 'icon' => 'dashicons-megaphone', 'description' => 'Alert or notification banner'),
            );
        ?>
            <!-- Slot Picker Modal -->
            <div id="rawwire-slot-picker-modal" class="rawwire-modal-overlay">
                <div class="rawwire-modal rawwire-modal-md">
                    <div class="rawwire-modal-header">
                        <h2>
                            <span class="dashicons dashicons-plus-alt2"></span>
                            Add Panel
                        </h2>
                    </div>
                    <div class="rawwire-modal-body">
                        <!-- Category tabs (shown for ≤2 column rows) -->
                        <div class="rawwire-slot-categories" id="rawwire-slot-categories">
                            <button type="button" class="rawwire-slot-category active" data-category="tool">
                                <span class="dashicons dashicons-admin-tools"></span> Tools
                            </button>
                            <button type="button" class="rawwire-slot-category" data-category="workflow">
                                <span class="dashicons dashicons-randomize"></span> Workflows
                            </button>
                            <button type="button" class="rawwire-slot-category" data-category="info">
                                <span class="dashicons dashicons-info-outline"></span> Info Panels
                            </button>
                        </div>

                        <!-- Tool list -->
                        <div class="rawwire-slot-list" data-category="tool">
                            <?php if (empty($tools)): ?>
                                <div class="rawwire-slot-empty">
                                    <span class="dashicons dashicons-info-outline"></span>
                                    <p>No tools available. Tools are activated by the template configuration.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($tools as $tool_id => $tool): ?>
                                    <button type="button" class="rawwire-slot-item" data-slot-type="tool" data-slot-item="<?php echo esc_attr($tool_id); ?>">
                                        <span class="dashicons <?php echo esc_attr($tool['icon'] ?? 'dashicons-admin-tools'); ?>"></span>
                                        <div class="rawwire-slot-item-info">
                                            <strong><?php echo esc_html($tool['label'] ?? $tool_id); ?></strong>
                                            <small><?php echo esc_html($tool['description'] ?? ''); ?></small>
                                        </div>
                                    </button>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <!-- Workflow list -->
                        <div class="rawwire-slot-list" data-category="workflow" style="display: none;">
                            <?php if (empty($workflows)): ?>
                                <div class="rawwire-slot-empty">
                                    <span class="dashicons dashicons-info-outline"></span>
                                    <p>No workflows defined. Workflows are configured in the template.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($workflows as $wf_id => $workflow): ?>
                                    <button type="button" class="rawwire-slot-item" data-slot-type="workflow" data-slot-item="<?php echo esc_attr($wf_id); ?>">
                                        <span class="dashicons <?php echo esc_attr($workflow['icon'] ?? 'dashicons-randomize'); ?>"></span>
                                        <div class="rawwire-slot-item-info">
                                            <strong><?php echo esc_html($workflow['name'] ?? $wf_id); ?></strong>
                                            <small><?php echo esc_html($workflow['description'] ?? ''); ?></small>
                                        </div>
                                    </button>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <!-- Info panel list -->
                        <div class="rawwire-slot-list" data-category="info" style="display: none;">
                            <?php foreach ($info_panels as $panel_type => $panel): ?>
                                <button type="button" class="rawwire-slot-item" data-slot-type="info" data-slot-item="<?php echo esc_attr($panel_type); ?>">
                                    <span class="dashicons <?php echo esc_attr($panel['icon']); ?>"></span>
                                    <div class="rawwire-slot-item-info">
                                        <strong><?php echo esc_html($panel['label']); ?></strong>
                                        <small><?php echo esc_html($panel['description']); ?></small>
                                    </div>
                                </button>
                            <?php endforeach; ?>
                        </div>

                        <!-- Info-only list (shown for >2 column rows instead of categories) -->
                        <div class="rawwire-slot-list" id="rawwire-slot-info-only" style="display: none;">
                            <?php foreach ($info_only as $item_type => $item): ?>
                                <button type="button" class="rawwire-slot-item" data-slot-type="info-only" data-slot-item="<?php echo esc_attr($item_type); ?>">
                                    <span class="dashicons <?php echo esc_attr($item['icon']); ?>"></span>
                                    <div class="rawwire-slot-item-info">
                                        <strong><?php echo esc_html($item['label']); ?></strong>
                                        <small><?php echo esc_html($item['description']); ?></small>
                                    </div>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php
        }

        /**
         * Render the Save Template Modal
         * Prompts for template name before saving layout
         */
        protected static function render_save_template_modal()
        {
        ?>
            <!-- Save Template Modal -->
            <div id="rawwire-save-template-modal" class="rawwire-modal-overlay">
                <div class="rawwire-modal rawwire-modal-sm">
                    <div class="rawwire-modal-header">
                        <h2>
                            <span class="dashicons dashicons-cloud-saved"></span>
                            Save Template
                        </h2>
                    </div>
                    <div class="rawwire-modal-body">
                        <div class="rawwire-form-field">
                            <label for="rawwire-template-name">Template Name</label>
                            <input type="text" id="rawwire-template-name" placeholder="e.g. My Custom Dashboard" autocomplete="off">
                        </div>
                        <div class="rawwire-form-field">
                            <label for="rawwire-template-id">Template ID (slug)</label>
                            <input type="text" id="rawwire-template-id" placeholder="auto-generated from name" autocomplete="off">
                            <small class="rawwire-field-hint">Leave blank to auto-generate from the name</small>
                        </div>
                        <div class="rawwire-form-field">
                            <label for="rawwire-template-desc">Description</label>
                            <textarea id="rawwire-template-desc" rows="2" placeholder="Brief description of this template"></textarea>
                        </div>
                        <div class="rawwire-dev-login-error" id="rawwire-save-template-error" style="display: none;"></div>
                    </div>
                    <div class="rawwire-modal-footer">
                        <button type="button" class="rawwire-btn rawwire-btn-secondary rawwire-modal-close">Cancel</button>
                        <button type="button" class="rawwire-btn rawwire-btn-primary" id="rawwire-save-template-confirm">
                            <span class="dashicons dashicons-cloud-saved"></span>
                            Save Template
                        </button>
                    </div>
                </div>
            </div>
<?php
        }
    }
}
