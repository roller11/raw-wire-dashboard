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
    class RawWire_Page_Renderer {

        /**
         * Render a page based on template configuration
         * @param string $page_id Page ID from template
         * @param array $context Additional context
         * @return string HTML output
         */
        public static function render($page_id, $context = array()) {
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
            ?>
            <div class="wrap rawwire-dashboard" data-page="<?php echo esc_attr($page_id); ?>">
                <?php self::render_page_header($page, $template_meta); ?>

                <?php if (!empty($actions)): ?>
                    <?php self::render_page_actions($actions); ?>
                <?php endif; ?>

                <div class="rawwire-page-content rawwire-layout-<?php echo esc_attr($layout); ?>">
                    <?php
                    // Render each panel
                    foreach ($panels as $panel_id) {
                        $panel_config = RawWire_Template_Engine::get_panel($panel_id);
                        if ($panel_config) {
                            // Pass page actions to panel for card rendering
                            $panel_config['_page_actions'] = $actions;
                            echo RawWire_Panel_Renderer::render($panel_config, $context);
                        }
                    }
                    ?>
                </div>

                <?php self::render_page_modals($page_id); ?>
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
         * Render page header - now uses rawwire-hero design
         */
        protected static function render_page_header($page, $template_meta) {
            $title = $page['title'] ?? 'Dashboard';
            $icon = $page['icon'] ?? 'dashicons-admin-generic';
            $template_name = $template_meta['name'] ?? 'Unknown Template';
            $variant = RawWire_Template_Engine::get_variant();
            $variants = $template_meta['variants'] ?? array('default');
            $description = $page['description'] ?? 'Your command center for content automation and AI-powered workflows.';
            ?>
            <div class="rawwire-hero">
                <div class="rawwire-hero-content">
                    <span class="eyebrow"><?php echo esc_html($template_name); ?></span>
                    <h1>
                        <span class="dashicons <?php echo esc_attr($icon); ?>"></span>
                        <?php echo esc_html($title); ?>
                    </h1>
                    <p class="lede"><?php echo esc_html($description); ?></p>
                </div>

                <div class="rawwire-hero-actions">
                    <?php if (count($variants) > 1): ?>
                        <select id="rawwire-variant-selector" class="rawwire-variant-select">
                            <?php foreach ($variants as $v): ?>
                                <option value="<?php echo esc_attr($v); ?>" <?php selected($variant, $v); ?>>
                                    <?php echo esc_html(ucfirst($v)); ?> Theme
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>

                    <button type="button" id="rawwire-template-switcher" class="rawwire-btn rawwire-btn-secondary">
                        <span class="dashicons dashicons-admin-appearance"></span>
                        Switch Template
                    </button>
                </div>
            </div>
            <?php
        }

        /**
         * Render page action buttons
         */
        protected static function render_page_actions($actions) {
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
        protected static function render_page_modals($page_id) {
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
            <div id="rawwire-confirm-modal" class="rawwire-modal-overlay" style="display: none;">
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
                            <label>Word Count</label>
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
                            <label>Custom Date/Time</label>
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
        public static function render_dashboard($context = array()) {
            return self::render('dashboard', $context);
        }

        /**
         * Render the approvals page
         */
        public static function render_approvals($context = array()) {
            return self::render('approvals', $context);
        }

        /**
         * Render the release page
         */
        public static function render_release($context = array()) {
            return self::render('release', $context);
        }

        /**
         * Render the settings page
         */
        public static function render_settings($context = array()) {
            return self::render('settings', $context);
        }

        /**
         * Generate admin menu items from template pages
         * @return array Menu configuration
         */
        public static function get_menu_config() {
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
        public static function get_layout_class($layout) {
            $layouts = array(
                'default' => 'rawwire-layout-default',
                'grid-3col' => 'rawwire-layout-grid-3col',
                'grid-2col' => 'rawwire-layout-grid-2col',
                'list' => 'rawwire-layout-list',
                'tabs' => 'rawwire-layout-tabs',
            );

            return $layouts[$layout] ?? 'rawwire-layout-default';
        }
    }
}
