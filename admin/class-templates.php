<?php
/**
 * Templates Management Page
 * Comprehensive template builder with wizard, visual designer, and editor
 *
 * @since 1.0.19
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * RawWire Templates Page Class
 */
class RawWire_Templates_Page {

    /**
     * Render the templates page
     */
    public function render() {
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'overview';
        $active_template = $this->get_active_template_info();
        $available_templates = $this->get_available_templates();
        
        ?>
        <div class="wrap rawwire-templates-page">
            <h1 class="rawwire-page-title">
                <span class="dashicons dashicons-admin-appearance"></span>
                <?php _e('Template Management', 'raw-wire-dashboard'); ?>
            </h1>

            <!-- Tab Navigation -->
            <nav class="nav-tab-wrapper rawwire-nav-tabs">
                <a href="?page=raw-wire-templates&tab=overview" 
                   class="nav-tab <?php echo $active_tab === 'overview' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-dashboard"></span>
                    Overview
                </a>
                <a href="?page=raw-wire-templates&tab=builder" 
                   class="nav-tab <?php echo $active_tab === 'builder' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-edit"></span>
                    Builder
                </a>
                <a href="?page=raw-wire-templates&tab=editor" 
                   class="nav-tab <?php echo $active_tab === 'editor' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-editor-code"></span>
                    JSON Editor
                </a>
                <a href="?page=raw-wire-templates&tab=import" 
                   class="nav-tab <?php echo $active_tab === 'import' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-upload"></span>
                    Import/Export
                </a>
            </nav>

            <!-- Tab Content -->
            <div class="rawwire-tab-content">
                <?php
                switch ($active_tab) {
                    case 'builder':
                        $this->render_builder_tab();
                        break;
                    case 'editor':
                        $this->render_editor_tab($active_template);
                        break;
                    case 'import':
                        $this->render_import_tab();
                        break;
                    case 'overview':
                    default:
                        $this->render_overview_tab($active_template, $available_templates);
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render overview tab
     */
    protected function render_overview_tab($active_template, $available_templates) {
        ?>
        <div class="rawwire-overview-tab">
            
            <!-- Active Template Card -->
            <div class="rawwire-section">
                <h2><?php _e('Active Template', 'raw-wire-dashboard'); ?></h2>
                
                <?php if ($active_template): ?>
                    <div class="rawwire-active-template-card">
                        <div class="template-header">
                            <div class="template-icon">
                                <span class="dashicons dashicons-layout"></span>
                            </div>
                            <div class="template-info">
                                <h3><?php echo esc_html($active_template['meta']['name'] ?? 'Unknown'); ?></h3>
                                <p class="template-meta">
                                    Version <?php echo esc_html($active_template['meta']['version'] ?? '1.0.0'); ?> 
                                    by <?php echo esc_html($active_template['meta']['author'] ?? 'Unknown'); ?>
                                </p>
                                <p class="template-description">
                                    <?php echo esc_html($active_template['meta']['description'] ?? ''); ?>
                                </p>
                            </div>
                            <div class="template-actions">
                                <a href="?page=raw-wire-edit-template" class="button button-primary">
                                    <span class="dashicons dashicons-edit"></span>
                                    Edit Active Template
                                </a>
                                <a href="?page=raw-wire-templates&tab=editor" class="button button-secondary">
                                    <span class="dashicons dashicons-editor-code"></span>
                                    JSON Editor
                                </a>
                            </div>
                        </div>
                        
                        <div class="template-stats">
                            <div class="stat-item">
                                <span class="stat-label">Pages</span>
                                <span class="stat-value"><?php echo count($active_template['pageDefinitions'] ?? []); ?></span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-label">Panels</span>
                                <span class="stat-value"><?php echo count($active_template['panels'] ?? []); ?></span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-label">Source Types</span>
                                <span class="stat-value"><?php echo count($active_template['sourceTypes'] ?? []); ?></span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-label">Features</span>
                                <span class="stat-value"><?php echo count($active_template['features'] ?? []); ?></span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-label">Sources</span>
                                <span class="stat-value"><?php echo count($active_template['variants'] ?? ['default']); ?></span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-label">Sources</span>
                                <span class="stat-value"><?php echo count($active_template['sources'] ?? []); ?></span>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="rawwire-no-template">
                        <span class="dashicons dashicons-warning"></span>
                        <h3><?php _e('No Template Active', 'raw-wire-dashboard'); ?></h3>
                        <p><?php _e('Create or activate a template to customize your dashboard.', 'raw-wire-dashboard'); ?></p>
                        <a href="?page=raw-wire-templates&tab=builder" class="button button-primary button-hero">
                            <span class="dashicons dashicons-plus-alt"></span>
                            <?php _e('Create New Template', 'raw-wire-dashboard'); ?>
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Available Templates -->
            <div class="rawwire-section">
                <div class="section-header">
                    <h2><?php _e('Available Templates', 'raw-wire-dashboard'); ?></h2>
                    <a href="?page=raw-wire-templates&tab=builder" class="button button-primary">
                        <span class="dashicons dashicons-plus-alt"></span>
                        Create New
                    </a>
                </div>

                <div class="rawwire-templates-grid">
                    <?php foreach ($available_templates as $template): ?>
                        <?php
                        $is_active = $active_template && isset($active_template['meta']['id']) && $active_template['meta']['id'] === $template['id'];
                        ?>
                        <div class="rawwire-template-card <?php echo $is_active ? 'is-active' : ''; ?>">
                            <div class="template-card-header">
                                <span class="dashicons dashicons-layout"></span>
                                <h4><?php echo esc_html($template['name']); ?></h4>
                                <?php if ($is_active): ?>
                                    <span class="active-badge">Active</span>
                                <?php endif; ?>
                            </div>
                            <p class="template-card-description">
                                <?php echo esc_html($template['description'] ?? 'No description'); ?>
                            </p>
                            <div class="template-card-footer">
                                <?php if (!$is_active): ?>
                                    <button type="button" class="button button-primary activate-template" 
                                            data-template="<?php echo esc_attr($template['id']); ?>"
                                            onclick="alert('Activate: Set rawwire_active_template to <?php echo esc_js($template['id']); ?>');">
                                        Activate
                                    </button>
                                <?php endif; ?>
                                <a href="?page=raw-wire-edit-template" class="button button-secondary">
                                    Edit in GUI
                                </a>
                                <a href="?page=raw-wire-templates&tab=editor" class="button button-secondary">
                                    Edit JSON
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Template Features Guide -->
            <div class="rawwire-section">
                <h2><?php _e('Template System Features', 'raw-wire-dashboard'); ?></h2>
                <div class="rawwire-features-grid">
                    <div class="feature-card">
                        <span class="dashicons dashicons-admin-page"></span>
                        <h3>Multiple Pages</h3>
                        <p>Create custom pages for dashboards, approvals, workflows, and settings</p>
                    </div>
                    <div class="feature-card">
                        <span class="dashicons dashicons-layout"></span>
                        <h3>Panel System</h3>
                        <p>Drag-and-drop panels for stats, controls, data tables, and custom content</p>
                    </div>
                    <div class="feature-card">
                        <span class="dashicons dashicons-art"></span>
                        <h3>Theme Variants</h3>
                        <p>Multiple color schemes (light, dark, minimal) with instant switching</p>
                    </div>
                    <div class="feature-card">
                        <span class="dashicons dashicons-admin-tools"></span>
                        <h3>Toolbox Integration</h3>
                        <p>Configure scrapers, AI generators, and publishers for automation</p>
                    </div>
                    <div class="feature-card">
                        <span class="dashicons dashicons-randomize"></span>
                        <h3>Workflow Engine</h3>
                        <p>Define multi-stage approval and publishing workflows</p>
                    </div>
                    <div class="feature-card">
                        <span class="dashicons dashicons-database"></span>
                        <h3>Data Binding</h3>
                        <p>Connect panels to database tables, settings, and template data</p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render template builder wizard
     */
    protected function render_builder_tab() {
        ?>
        <div class="rawwire-builder-tab">
            <div class="rawwire-wizard-container">
                
                <!-- Progress Steps -->
                <div class="wizard-steps">
                    <div class="wizard-step active" data-step="1">
                        <span class="step-number">1</span>
                        <span class="step-label">Template Info</span>
                    </div>
                    <div class="wizard-step" data-step="2">
                        <span class="step-number">2</span>
                        <span class="step-label">Use Case</span>
                    </div>
                    <div class="wizard-step" data-step="3">
                        <span class="step-number">3</span>
                        <span class="step-label">Pages & Layout</span>
                    </div>
                    <div class="wizard-step" data-step="4">
                        <span class="step-number">4</span>
                        <span class="step-label">Panels</span>
                    </div>
                    <div class="wizard-step" data-step="5">
                        <span class="step-number">5</span>
                        <span class="step-label">Toolbox</span>
                    </div>
                    <div class="wizard-step" data-step="6">
                        <span class="step-number">6</span>
                        <span class="step-label">Styling</span>
                    </div>
                    <div class="wizard-step" data-step="7">
                        <span class="step-number">7</span>
                        <span class="step-label">Review</span>
                    </div>
                </div>

                <!-- Step 1: Template Info -->
                <div class="wizard-content" data-step="1">
                    <h2>Let's Create Your Template</h2>
                    <p class="wizard-description">Start by giving your template a name and description</p>

                    <div class="wizard-form">
                        <div class="form-group">
                            <label for="template-name">Template Name *</label>
                            <input type="text" id="template-name" class="widefat" 
                                   placeholder="e.g., News Aggregator, Content Hub, Social Monitor" required>
                            <p class="description">A descriptive name for your template</p>
                        </div>

                        <div class="form-group">
                            <label for="template-id">Template ID *</label>
                            <input type="text" id="template-id" class="widefat" 
                                   placeholder="e.g., news-aggregator" required>
                            <p class="description">Lowercase, no spaces (auto-generated from name)</p>
                        </div>

                        <div class="form-group">
                            <label for="template-description">Description</label>
                            <textarea id="template-description" class="widefat" rows="3"
                                      placeholder="Brief description of what this template does..."></textarea>
                        </div>

                        <div class="form-group">
                            <label for="template-author">Author</label>
                            <input type="text" id="template-author" class="widefat" 
                                   value="<?php echo esc_attr(wp_get_current_user()->display_name); ?>">
                        </div>
                    </div>
                </div>

                <!-- Step 2: Use Case -->
                <div class="wizard-content" data-step="2" style="display:none;">
                    <h2>What's Your Primary Use Case?</h2>
                    <p class="wizard-description">This helps us recommend the right features and layout</p>

                    <div class="use-case-grid">
                        <label class="use-case-card">
                            <input type="radio" name="use-case" value="content-aggregation">
                            <div class="card-content">
                                <span class="dashicons dashicons-rss"></span>
                                <h3>Content Aggregation</h3>
                                <p>Collect content from RSS feeds, APIs, and scrapers</p>
                                <ul class="feature-list">
                                    <li>Multi-source scraping</li>
                                    <li>Content scoring</li>
                                    <li>Approval workflow</li>
                                </ul>
                            </div>
                        </label>

                        <label class="use-case-card">
                            <input type="radio" name="use-case" value="ai-content-generation">
                            <div class="card-content">
                                <span class="dashicons dashicons-admin-generic"></span>
                                <h3>AI Content Generation</h3>
                                <p>Generate and manage AI-powered content</p>
                                <ul class="feature-list">
                                    <li>Multiple AI models</li>
                                    <li>Prompt templates</li>
                                    <li>Content editing</li>
                                </ul>
                            </div>
                        </label>

                        <label class="use-case-card">
                            <input type="radio" name="use-case" value="social-monitoring">
                            <div class="card-content">
                                <span class="dashicons dashicons-share"></span>
                                <h3>Social Monitoring</h3>
                                <p>Track and manage social media content</p>
                                <ul class="feature-list">
                                    <li>Multi-platform tracking</li>
                                    <li>Engagement metrics</li>
                                    <li>Scheduled posting</li>
                                </ul>
                            </div>
                        </label>

                        <label class="use-case-card">
                            <input type="radio" name="use-case" value="data-dashboard">
                            <div class="card-content">
                                <span class="dashicons dashicons-chart-line"></span>
                                <h3>Data Dashboard</h3>
                                <p>Visualize and analyze data from various sources</p>
                                <ul class="feature-list">
                                    <li>Custom metrics</li>
                                    <li>Data visualizations</li>
                                    <li>Real-time updates</li>
                                </ul>
                            </div>
                        </label>

                        <label class="use-case-card">
                            <input type="radio" name="use-case" value="workflow-automation">
                            <div class="card-content">
                                <span class="dashicons dashicons-randomize"></span>
                                <h3>Workflow Automation</h3>
                                <p>Build custom automation workflows</p>
                                <ul class="feature-list">
                                    <li>Multi-stage workflows</li>
                                    <li>Conditional logic</li>
                                    <li>Task scheduling</li>
                                </ul>
                            </div>
                        </label>

                        <label class="use-case-card">
                            <input type="radio" name="use-case" value="custom">
                            <div class="card-content">
                                <span class="dashicons dashicons-admin-settings"></span>
                                <h3>Custom Setup</h3>
                                <p>Build from scratch with full customization</p>
                                <ul class="feature-list">
                                    <li>Blank canvas</li>
                                    <li>All features available</li>
                                    <li>Maximum flexibility</li>
                                </ul>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Step 3: Pages & Layout -->
                <div class="wizard-content" data-step="3" style="display:none;">
                    <h2>Configure Your Pages</h2>
                    <p class="wizard-description">Add pages to your template. Each page can have its own layout and panels.</p>

                    <div class="pages-builder">
                        <div class="pages-list" id="template-pages-list">
                            <!-- Pages will be added here dynamically -->
                        </div>

                        <button type="button" class="button button-secondary" id="add-page-btn">
                            <span class="dashicons dashicons-plus-alt"></span>
                            Add Page
                        </button>
                    </div>

                    <!-- Page Editor Modal (populated dynamically) -->
                    <div id="page-editor-modal" class="rawwire-modal-overlay" style="display:none;">
                        <div class="rawwire-modal">
                            <div class="rawwire-modal-header">
                                <h3>Edit Page</h3>
                                <button type="button" class="rawwire-modal-close">&times;</button>
                            </div>
                            <div class="rawwire-modal-body">
                                <div class="form-group">
                                    <label>Page Title</label>
                                    <input type="text" id="page-title" class="widefat">
                                </div>
                                <div class="form-group">
                                    <label>Page Slug</label>
                                    <input type="text" id="page-slug" class="widefat">
                                </div>
                                <div class="form-group">
                                    <label>Icon</label>
                                    <select id="page-icon" class="widefat">
                                        <option value="dashicons-dashboard">Dashboard</option>
                                        <option value="dashicons-yes-alt">Approvals</option>
                                        <option value="dashicons-share">Share/Publish</option>
                                        <option value="dashicons-admin-settings">Settings</option>
                                        <option value="dashicons-chart-line">Analytics</option>
                                        <option value="dashicons-admin-tools">Tools</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Layout</label>
                                    <select id="page-layout" class="widefat">
                                        <option value="default">Default Grid</option>
                                        <option value="grid-2col">2 Columns</option>
                                        <option value="grid-3col">3 Columns</option>
                                        <option value="list">List View</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>
                                        <input type="checkbox" id="page-is-main">
                                        Set as main page
                                    </label>
                                </div>
                            </div>
                            <div class="rawwire-modal-footer">
                                <button type="button" class="button button-secondary rawwire-modal-close">Cancel</button>
                                <button type="button" class="button button-primary" id="save-page-btn">Save Page</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 4: Panels -->
                <div class="wizard-content" data-step="4" style="display:none;">
                    <h2>Design Your Panels</h2>
                    <p class="wizard-description">Add panels to display data, controls, and custom content</p>

                    <div class="panels-designer">
                        <div class="panel-types-sidebar">
                            <h3>Panel Types</h3>
                            <div class="panel-type-list">
                                <div class="panel-type-item" draggable="true" data-type="status">
                                    <span class="dashicons dashicons-chart-bar"></span>
                                    <span>Status/Metrics</span>
                                </div>
                                <div class="panel-type-item" draggable="true" data-type="control">
                                    <span class="dashicons dashicons-admin-tools"></span>
                                    <span>Controls</span>
                                </div>
                                <div class="panel-type-item" draggable="true" data-type="data">
                                    <span class="dashicons dashicons-list-view"></span>
                                    <span>Data Table/Cards</span>
                                </div>
                                <div class="panel-type-item" draggable="true" data-type="settings">
                                    <span class="dashicons dashicons-admin-settings"></span>
                                    <span>Settings Form</span>
                                </div>
                                <div class="panel-type-item" draggable="true" data-type="custom">
                                    <span class="dashicons dashicons-editor-code"></span>
                                    <span>Custom HTML</span>
                                </div>
                            </div>
                        </div>

                        <div class="panels-canvas">
                            <div class="page-selector">
                                <label>Select Page:</label>
                                <select id="panel-page-selector"></select>
                            </div>
                            <div class="panels-drop-zone" id="panels-canvas">
                                <p class="empty-message">Drag panel types here to add them</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 5: Toolbox Configuration -->
                <div class="wizard-content" data-step="5" style="display:none;">
                    <h2>Configure Toolbox Features</h2>
                    <p class="wizard-description">Set up scrapers, AI generators, and publishers</p>

                    <div class="toolbox-config">
                        <!-- Scraper Section -->
                        <div class="toolbox-section">
                            <h3>
                                <input type="checkbox" id="enable-scraper" checked>
                                <span class="dashicons dashicons-rss"></span>
                                Content Scraper
                            </h3>
                            <div class="toolbox-fields" id="scraper-fields">
                                <div class="form-group">
                                    <label>Adapter Type</label>
                                    <select class="widefat">
                                        <option value="rss-scraper">RSS Feed Scraper</option>
                                        <option value="api-scraper">API Scraper</option>
                                        <option value="web-scraper">Web Scraper</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Run Schedule (Cron)</label>
                                    <select class="widefat">
                                        <option value="0 */2 * * *">Every 2 hours</option>
                                        <option value="0 */6 * * *">Every 6 hours</option>
                                        <option value="0 0 * * *">Once daily</option>
                                        <option value="0 0 * * 0">Once weekly</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- AI Generator Section -->
                        <div class="toolbox-section">
                            <h3>
                                <input type="checkbox" id="enable-generator">
                                <span class="dashicons dashicons-admin-generic"></span>
                                AI Content Generator
                            </h3>
                            <div class="toolbox-fields" id="generator-fields" style="display:none;">
                                <div class="form-group">
                                    <label>AI Provider</label>
                                    <select class="widefat" id="generator-adapter">
                                        <option value="openai">OpenAI (GPT-4)</option>
                                        <option value="anthropic">Anthropic (Claude)</option>
                                        <option value="local">Local Model</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Default Model</label>
                                    <select class="widefat">
                                        <option value="gpt-4">GPT-4</option>
                                        <option value="gpt-4-turbo">GPT-4 Turbo</option>
                                        <option value="gpt-3.5-turbo">GPT-3.5 Turbo</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Publisher Section -->
                        <div class="toolbox-section">
                            <h3>
                                <input type="checkbox" id="enable-poster">
                                <span class="dashicons dashicons-share"></span>
                                Content Publisher
                            </h3>
                            <div class="toolbox-fields" id="poster-fields" style="display:none;">
                                <div class="form-group">
                                    <label>Publishing Outlets</label>
                                    <div class="checkbox-group">
                                        <label><input type="checkbox" value="wordpress" checked> WordPress</label>
                                        <label><input type="checkbox" value="twitter"> Twitter/X</label>
                                        <label><input type="checkbox" value="linkedin"> LinkedIn</label>
                                        <label><input type="checkbox" value="facebook"> Facebook</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Workflow Section -->
                        <div class="toolbox-section">
                            <h3>
                                <input type="checkbox" id="enable-workflow" checked>
                                <span class="dashicons dashicons-randomize"></span>
                                Workflow Engine
                            </h3>
                            <div class="toolbox-fields" id="workflow-fields">
                                <p class="description">Define stages for content to flow through</p>
                                <div id="workflow-stages">
                                    <!-- Workflow stages added dynamically -->
                                </div>
                                <button type="button" class="button button-secondary" id="add-workflow-stage">
                                    <span class="dashicons dashicons-plus-alt"></span>
                                    Add Stage
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 6: Styling -->
                <div class="wizard-content" data-step="6" style="display:none;">
                    <h2>Choose Your Style</h2>
                    <p class="wizard-description">Customize colors, spacing, and create theme variants</p>

                    <div class="styling-config">
                        <div class="style-section">
                            <h3>Color Scheme</h3>
                            <div class="color-picker-grid">
                                <div class="color-input">
                                    <label>Primary Color</label>
                                    <input type="color" value="#1976d2" id="color-primary">
                                </div>
                                <div class="color-input">
                                    <label>Secondary Color</label>
                                    <input type="color" value="#26c6da" id="color-secondary">
                                </div>
                                <div class="color-input">
                                    <label>Accent Color</label>
                                    <input type="color" value="#ff6f00" id="color-accent">
                                </div>
                                <div class="color-input">
                                    <label>Success Color</label>
                                    <input type="color" value="#43a047" id="color-success">
                                </div>
                                <div class="color-input">
                                    <label>Warning Color</label>
                                    <input type="color" value="#ff8f00" id="color-warning">
                                </div>
                                <div class="color-input">
                                    <label>Danger Color</label>
                                    <input type="color" value="#e53935" id="color-danger">
                                </div>
                            </div>
                        </div>

                        <div class="style-section">
                            <h3>Theme Variants</h3>
                            <div class="variants-list">
                                <label class="variant-option">
                                    <input type="checkbox" value="default" checked disabled>
                                    <span>Default (Light)</span>
                                </label>
                                <label class="variant-option">
                                    <input type="checkbox" value="dark" id="variant-dark">
                                    <span>Dark Mode</span>
                                </label>
                                <label class="variant-option">
                                    <input type="checkbox" value="minimal" id="variant-minimal">
                                    <span>Minimal</span>
                                </label>
                            </div>
                        </div>

                        <div class="style-section">
                            <h3>Spacing & Layout</h3>
                            <div class="form-group">
                                <label>Panel Spacing</label>
                                <select class="widefat">
                                    <option value="compact">Compact</option>
                                    <option value="normal" selected>Normal</option>
                                    <option value="comfortable">Comfortable</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Border Radius</label>
                                <select class="widefat">
                                    <option value="sharp">Sharp (0px)</option>
                                    <option value="normal" selected>Normal (8px)</option>
                                    <option value="rounded">Rounded (12px)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 7: Review & Generate -->
                <div class="wizard-content" data-step="7" style="display:none;">
                    <h2>Review Your Template</h2>
                    <p class="wizard-description">Review your configuration and generate the template</p>

                    <div class="template-review">
                        <div class="review-section">
                            <h3>Template Information</h3>
                            <dl class="review-details">
                                <dt>Name:</dt>
                                <dd id="review-name"></dd>
                                <dt>ID:</dt>
                                <dd id="review-id"></dd>
                                <dt>Use Case:</dt>
                                <dd id="review-use-case"></dd>
                            </dl>
                        </div>

                        <div class="review-section">
                            <h3>Pages</h3>
                            <ul id="review-pages" class="review-list"></ul>
                        </div>

                        <div class="review-section">
                            <h3>Panels</h3>
                            <ul id="review-panels" class="review-list"></ul>
                        </div>

                        <div class="review-section">
                            <h3>Toolbox Features</h3>
                            <ul id="review-toolbox" class="review-list"></ul>
                        </div>

                        <div class="review-actions">
                            <button type="button" class="button button-secondary" id="export-json-btn">
                                <span class="dashicons dashicons-download"></span>
                                Export as JSON
                            </button>
                            <button type="button" class="button button-primary button-hero" id="create-template-btn">
                                <span class="dashicons dashicons-yes"></span>
                                Create Template
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Wizard Navigation -->
                <div class="wizard-navigation">
                    <button type="button" class="button button-secondary" id="wizard-prev" disabled>
                        <span class="dashicons dashicons-arrow-left-alt2"></span>
                        Previous
                    </button>
                    <button type="button" class="button button-primary" id="wizard-next">
                        Next
                        <span class="dashicons dashicons-arrow-right-alt2"></span>
                    </button>
                </div>

            </div>
        </div>
        <?php
    }

    /**
     * Render JSON editor tab
     */
    protected function render_editor_tab($active_template) {
        $template_json = $active_template ? json_encode($active_template, JSON_PRETTY_PRINT) : '';
        ?>
        <div class="rawwire-editor-tab">
            <div class="editor-header">
                <h2>JSON Template Editor</h2>
                <div class="editor-actions">
                    <button type="button" class="button button-secondary" id="validate-json-btn">
                        <span class="dashicons dashicons-yes-alt"></span>
                        Validate
                    </button>
                    <button type="button" class="button button-secondary" id="format-json-btn">
                        <span class="dashicons dashicons-editor-code"></span>
                        Format
                    </button>
                    <button type="button" class="button button-primary" id="save-json-btn">
                        <span class="dashicons dashicons-saved"></span>
                        Save Template
                    </button>
                </div>
            </div>

            <div class="editor-container">
                <textarea id="template-json-editor" class="code-editor"><?php echo esc_textarea($template_json); ?></textarea>
            </div>

            <div id="validation-results" class="validation-results" style="display:none;"></div>
        </div>
        <?php
    }

    /**
     * Render import/export tab
     */
    protected function render_import_tab() {
        ?>
        <div class="rawwire-import-tab">
            <div class="import-export-grid">
                <!-- Import Section -->
                <div class="import-section">
                    <h2><span class="dashicons dashicons-upload"></span> Import Template</h2>
                    <p>Upload a JSON template file to import</p>

                    <div class="file-upload-area" id="template-upload-area">
                        <input type="file" id="template-file-input" accept=".json" style="display:none;">
                        <div class="upload-placeholder">
                            <span class="dashicons dashicons-upload"></span>
                            <p>Drag and drop a JSON file here or click to browse</p>
                        </div>
                    </div>

                    <div class="import-options">
                        <label>
                            <input type="checkbox" id="import-replace">
                            Replace existing template with same ID
                        </label>
                    </div>

                    <button type="button" class="button button-primary" id="import-template-btn" disabled>
                        <span class="dashicons dashicons-download"></span>
                        Import Template
                    </button>
                </div>

                <!-- Export Section -->
                <div class="export-section">
                    <h2><span class="dashicons dashicons-download"></span> Export Template</h2>
                    <p>Download your template as a JSON file</p>

                    <div class="form-group">
                        <label>Select Template to Export</label>
                        <select id="export-template-select" class="widefat">
                            <?php foreach ($this->get_available_templates() as $template): ?>
                                <option value="<?php echo esc_attr($template['id']); ?>">
                                    <?php echo esc_html($template['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="export-options">
                        <label>
                            <input type="checkbox" id="export-with-data">
                            Include template data (sources, settings)
                        </label>
                    </div>

                    <button type="button" class="button button-primary" id="export-template-btn">
                        <span class="dashicons dashicons-download"></span>
                        Export Template
                    </button>
                </div>
            </div>

            <!-- Template Library -->
            <div class="template-library">
                <h2>Template Library</h2>
                <p>Browse and install templates from the community</p>

                <div class="library-grid">
                    <div class="library-template">
                        <h3>News Aggregator Pro</h3>
                        <p>Advanced news aggregation with AI summarization</p>
                        <span class="template-badge">Popular</span>
                        <button type="button" class="button button-secondary">Install</button>
                    </div>
                    <div class="library-template">
                        <h3>Social Media Monitor</h3>
                        <p>Track mentions and engagement across platforms</p>
                        <span class="template-badge">New</span>
                        <button type="button" class="button button-secondary">Install</button>
                    </div>
                    <div class="library-template">
                        <h3>Content Factory</h3>
                        <p>Full AI content generation workflow</p>
                        <span class="template-badge">Premium</span>
                        <button type="button" class="button button-secondary">Install</button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Get active template info
     */
    protected function get_active_template_info() {
        if (!class_exists('RawWire_Template_Engine')) {
            return null;
        }

        $template = RawWire_Template_Engine::get_active_template();
        return $template;
    }

    /**
     * Get available templates
     */
    protected function get_available_templates() {
        $templates_dir = plugin_dir_path(dirname(__FILE__)) . 'templates/';
        
        if (!is_dir($templates_dir)) {
            return array();
        }

        $templates = array();
        $files = glob($templates_dir . '*.template.json');

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && isset($data['meta'])) {
                $templates[] = array_merge(
                    $data['meta'],
                    array('_full_template' => $data)
                );
            }
        }

        return $templates;
    }
}