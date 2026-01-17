<?php
/**
 * ARCHIVED DEAD CODE from raw-wire-dashboard.php
 * Date: January 14, 2026
 * Reason: This admin_page() method was never called - 
 *         RawWire_Bootstrap::render_dashboard() is the actual menu callback
 */

    /**
     * Admin page callback - DEAD CODE - NEVER CALLED
     * The main menu is registered by RawWire_Bootstrap::register_menu()
     * with callback RawWire_Bootstrap::render_dashboard()
     */
    public function admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Use template-based rendering if available
        if (class_exists('RawWire_Page_Renderer')) {
            $template = class_exists('RawWire_Template_Engine') ? RawWire_Template_Engine::get_active_template() : null;
            
            if ($template) {
                // Render with template
                echo RawWire_Page_Renderer::render_dashboard();
            } else {
                // Show fallback dashboard with template prompt
                $this->render_fallback_dashboard();
            }
        } else {
            // Fallback to original dashboard
            require_once plugin_dir_path(__FILE__) . 'admin/class-dashboard.php';
            $admin = new RawWire_Admin_Dashboard();
            $admin->render();
        }
    }

    /**
     * Render fallback dashboard when no template is active
     * DEAD CODE - only called from admin_page() which is never called
     */
    private function render_fallback_dashboard() {
        ?>
        <div class="wrap rawwire-fallback-dashboard">
            <h1><?php _e('Raw-Wire Dashboard', 'raw-wire-dashboard'); ?></h1>

            <div class="rawwire-welcome-panel">
                <div class="welcome-panel-content">
                    <h2><?php _e('Welcome to Raw-Wire', 'raw-wire-dashboard'); ?></h2>
                    <p class="about-description"><?php _e('Get started by creating or activating a template to customize your dashboard.', 'raw-wire-dashboard'); ?></p>
                    
                    <div class="welcome-panel-column-container">
                        <div class="welcome-panel-column">
                            <h3><?php _e('Create a Template', 'raw-wire-dashboard'); ?></h3>
                            <p><?php _e('Use our step-by-step wizard to build a custom template for your workflow.', 'raw-wire-dashboard'); ?></p>
                            <a href="<?php echo admin_url('admin.php?page=raw-wire-templates&tab=builder'); ?>" class="button button-primary button-hero">
                                <?php _e('Create New Template', 'raw-wire-dashboard'); ?>
                            </a>
                        </div>
                        
                        <div class="welcome-panel-column">
                            <h3><?php _e('Browse Templates', 'raw-wire-dashboard'); ?></h3>
                            <p><?php _e('Explore pre-built templates for common use cases.', 'raw-wire-dashboard'); ?></p>
                            <a href="<?php echo admin_url('admin.php?page=raw-wire-templates'); ?>" class="button button-secondary">
                                <?php _e('View Templates', 'raw-wire-dashboard'); ?>
                            </a>
                        </div>
                        
                        <div class="welcome-panel-column">
                            <h3><?php _e('Learn More', 'raw-wire-dashboard'); ?></h3>
                            <ul>
                                <li><a href="#" class="welcome-icon dashicons-book"><?php _e('Read Documentation', 'raw-wire-dashboard'); ?></a></li>
                                <li><a href="#" class="welcome-icon dashicons-video-alt3"><?php _e('Watch Tutorial Videos', 'raw-wire-dashboard'); ?></a></li>
                                <li><a href="#" class="welcome-icon dashicons-groups"><?php _e('Join Community', 'raw-wire-dashboard'); ?></a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Feature Preview -->
            <div class="rawwire-features-preview">
                <h2><?php _e('What You Can Build', 'raw-wire-dashboard'); ?></h2>
                <div class="features-grid">
                    <div class="feature-preview">
                        <span class="dashicons dashicons-rss"></span>
                        <h3><?php _e('Content Aggregation', 'raw-wire-dashboard'); ?></h3>
                        <p><?php _e('Automatically collect content from RSS feeds, APIs, and web scrapers.', 'raw-wire-dashboard'); ?></p>
                    </div>
                    <div class="feature-preview">
                        <span class="dashicons dashicons-admin-generic"></span>
                        <h3><?php _e('AI Generation', 'raw-wire-dashboard'); ?></h3>
                        <p><?php _e('Generate content using OpenAI, Claude, or other AI models.', 'raw-wire-dashboard'); ?></p>
                    </div>
                    <div class="feature-preview">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <h3><?php _e('Approval Workflows', 'raw-wire-dashboard'); ?></h3>
                        <p><?php _e('Review and approve content before publishing with multi-stage workflows.', 'raw-wire-dashboard'); ?></p>
                    </div>
                    <div class="feature-preview">
                        <span class="dashicons dashicons-share"></span>
                        <h3><?php _e('Multi-Platform Publishing', 'raw-wire-dashboard'); ?></h3>
                        <p><?php _e('Publish to WordPress, social media, and other platforms automatically.', 'raw-wire-dashboard'); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <style>
            .rawwire-fallback-dashboard {
                max-width: 1200px;
            }
            .rawwire-welcome-panel {
                background: white;
                border: 1px solid #c3c4c7;
                box-shadow: 0 1px 1px rgba(0,0,0,0.04);
                margin-top: 20px;
            }
            .welcome-panel-content {
                padding: 23px;
            }
            .welcome-panel-content h2 {
                margin-top: 0;
                font-size: 21px;
                font-weight: 400;
                line-height: 1.2;
            }
            .about-description {
                font-size: 16px;
                margin: 0 0 24px;
            }
            .welcome-panel-column-container {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 20px;
            }
            .welcome-panel-column h3 {
                margin-top: 0;
            }
            .welcome-panel-column ul {
                list-style: none;
                padding: 0;
            }
            .welcome-panel-column li {
                margin-bottom: 8px;
            }
            .welcome-icon::before {
                margin-right: 8px;
                vertical-align: middle;
            }
            .rawwire-features-preview {
                margin-top: 40px;
            }
            .features-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 20px;
                margin-top: 20px;
            }
            .feature-preview {
                background: white;
                padding: 20px;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
            }
            .feature-preview .dashicons {
                font-size: 48px;
                width: 48px;
                height: 48px;
                color: #2271b1;
                margin-bottom: 12px;
            }
            .feature-preview h3 {
                margin: 0 0 8px;
                font-size: 16px;
            }
            .feature-preview p {
                margin: 0;
                color: #646970;
            }
            @media (max-width: 768px) {
                .welcome-panel-column-container {
                    grid-template-columns: 1fr;
                }
            }
        </style>
        <?php
    }
