<?php
/**
 * Template Engine Loader
 * 
 * Loads and initializes all template engine components in the correct order.
 * Include this file from the main plugin bootstrap.
 *
 * @package RawWire_Dashboard
 * @subpackage Template_Engine
 * @since 1.0.30
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Template Engine Directory
 */
define('RAWWIRE_TEMPLATE_ENGINE_DIR', dirname(__FILE__));
define('RAWWIRE_TEMPLATE_ENGINE_URL', plugin_dir_url(__FILE__));

/**
 * Load template engine components
 */
function rawwire_template_engine_load() {
    // Core utilities (order matters)
    require_once RAWWIRE_TEMPLATE_ENGINE_DIR . '/class-panel-type-registry.php';
    require_once RAWWIRE_TEMPLATE_ENGINE_DIR . '/class-data-source-registry.php';
    require_once RAWWIRE_TEMPLATE_ENGINE_DIR . '/class-control-registry.php';
    require_once RAWWIRE_TEMPLATE_ENGINE_DIR . '/class-data-facade.php';
    
    // Existing components
    require_once RAWWIRE_TEMPLATE_ENGINE_DIR . '/template-engine.php';
    require_once RAWWIRE_TEMPLATE_ENGINE_DIR . '/panel-renderer.php';
    require_once RAWWIRE_TEMPLATE_ENGINE_DIR . '/page-renderer.php';
    require_once RAWWIRE_TEMPLATE_ENGINE_DIR . '/workflow-handlers.php';
}

/**
 * Initialize template engine
 * Call this after all files are loaded
 */
function rawwire_template_engine_init() {
    // Initialize main template engine
    if (class_exists('RawWire_Template_Engine')) {
        RawWire_Template_Engine::init();
    }
    
    // Initialize registries (triggers builtin registration via singleton)
    rawwire_panel_types();
    rawwire_data_sources();
    rawwire_controls();
    
    // Enqueue assets
    add_action('admin_enqueue_scripts', 'rawwire_template_engine_enqueue_assets');
    
    // Trigger hook for modules to register custom panel types, data sources, etc.
    do_action('rawwire_template_engine_ready');
}

/**
 * Enqueue template engine assets
 */
function rawwire_template_engine_enqueue_assets($hook) {
    // Only on dashboard pages
    if (strpos($hook, 'rawwire') === false) {
        return;
    }
    
    wp_enqueue_style(
        'rawwire-grid',
        RAWWIRE_TEMPLATE_ENGINE_URL . 'css/grid.css',
        [],
        RAWWIRE_DASHBOARD_VERSION
    );
}

/**
 * Helper: Get panel type registry instance
 * @return RawWire_Panel_Type_Registry
 */
function rawwire_panel_types() {
    return RawWire_Panel_Type_Registry::instance();
}

/**
 * Helper: Get data source registry instance
 * @return RawWire_Data_Source_Registry
 */
function rawwire_data_sources() {
    return RawWire_Data_Source_Registry::instance();
}

/**
 * Helper: Get control registry instance
 * @return RawWire_Control_Registry
 */
function rawwire_controls() {
    return RawWire_Control_Registry::instance();
}

/**
 * Helper: Render a panel using the Panel Type Registry
 * 
 * @param string $type Panel type (table, cards, metrics, etc.)
 * @param array $config Panel configuration
 * @param mixed $data Optional data or data source identifier
 * @return string HTML
 */
function rawwire_render_panel($type, array $config, $data = null) {
    // If data is a string, treat it as a data source identifier
    if (is_string($data)) {
        $data = rawwire_data_sources()->get_data($data);
    }
    
    return rawwire_panel_types()->render($type, $config, $data);
}

/**
 * Helper: Render a control
 * 
 * @param array $config Control configuration
 * @param array $context Optional context
 * @return string HTML
 */
function rawwire_render_control(array $config, array $context = []) {
    return rawwire_controls()->render($config, $context);
}

/**
 * Helper: Render a grid row with panels
 * 
 * @param array $panels Array of panel configs with 'width' key (1-12)
 * @param array $context Optional shared context
 * @return string HTML
 */
function rawwire_render_row(array $panels, array $context = []) {
    $html = '<div class="rawwire-row">';
    
    foreach ($panels as $panel_config) {
        $width = $panel_config['width'] ?? 12;
        $width_md = $panel_config['width_md'] ?? $width;
        $width_lg = $panel_config['width_lg'] ?? $width_md;
        $type = $panel_config['type'] ?? 'html';
        $data_source = $panel_config['data_source'] ?? null;
        
        // Build column classes
        $col_class = 'rawwire-col-12';
        if ($width_md < 12) {
            $col_class .= ' rawwire-col-md-' . $width_md;
        }
        if ($width_lg < 12 && $width_lg !== $width_md) {
            $col_class .= ' rawwire-col-lg-' . $width_lg;
        }
        
        // Get data
        $data = null;
        if ($data_source) {
            $data = rawwire_data_sources()->get_data($data_source);
        }
        
        $html .= '<div class="' . esc_attr($col_class) . '">';
        $html .= rawwire_panel_types()->render($type, $panel_config, $data);
        $html .= '</div>';
    }
    
    $html .= '</div>';
    
    return $html;
}

/**
 * Helper: Build a page from a page template JSON
 * 
 * @param array $page_template Page template configuration
 * @param array $context Optional context data
 * @return string HTML
 */
function rawwire_render_page(array $page_template, array $context = []) {
    $html = '';
    
    // Page wrapper
    $page_id = $page_template['id'] ?? 'page-' . uniqid();
    $page_class = 'rawwire-page';
    
    if (!empty($page_template['css_class'])) {
        $page_class .= ' ' . $page_template['css_class'];
    }
    
    $html .= '<div class="' . esc_attr($page_class) . '" data-page-id="' . esc_attr($page_id) . '">';
    
    // Header
    if (!empty($page_template['header']) && !empty($page_template['header']['enabled'])) {
        $html .= rawwire_render_page_header($page_template['header'], $context);
    }
    
    // Body (sidebar + content)
    $html .= '<div class="rawwire-page-body">';
    
    // Sidebar (left)
    if (!empty($page_template['sidebar']) && 
        !empty($page_template['sidebar']['enabled']) &&
        ($page_template['sidebar']['position'] ?? 'left') === 'left') {
        $html .= rawwire_render_sidebar($page_template['sidebar'], $context);
    }
    
    // Main content
    $html .= '<div class="rawwire-page-content">';
    
    // Render layout rows
    if (!empty($page_template['layout'])) {
        foreach ($page_template['layout'] as $row) {
            if (!empty($row['panels'])) {
                $html .= rawwire_render_row($row['panels'], $context);
            }
        }
    }
    
    $html .= '</div>'; // .rawwire-page-content
    
    // Sidebar (right)
    if (!empty($page_template['sidebar']) && 
        !empty($page_template['sidebar']['enabled']) &&
        ($page_template['sidebar']['position'] ?? 'left') === 'right') {
        $html .= rawwire_render_sidebar($page_template['sidebar'], $context);
    }
    
    $html .= '</div>'; // .rawwire-page-body
    
    // Footer
    if (!empty($page_template['footer']) && !empty($page_template['footer']['enabled'])) {
        $html .= rawwire_render_page_footer($page_template['footer'], $context);
    }
    
    $html .= '</div>'; // .rawwire-page
    
    return $html;
}

/**
 * Render page header
 */
function rawwire_render_page_header(array $config, array $context = []) {
    $html = '<div class="rawwire-page-header">';
    
    // Left section
    $html .= '<div class="rawwire-page-header-left">';
    if (!empty($config['title'])) {
        $html .= '<h1 class="rawwire-page-title">' . esc_html($config['title']) . '</h1>';
    }
    if (!empty($config['left_controls'])) {
        $html .= rawwire_controls()->render_group($config['left_controls'], $context);
    }
    $html .= '</div>';
    
    // Center section (often search)
    if (!empty($config['center_controls'])) {
        $html .= '<div class="rawwire-page-header-center">';
        $html .= rawwire_controls()->render_group($config['center_controls'], $context);
        $html .= '</div>';
    }
    
    // Right section
    $html .= '<div class="rawwire-page-header-right">';
    if (!empty($config['right_controls'])) {
        $html .= rawwire_controls()->render_group($config['right_controls'], $context);
    }
    $html .= '</div>';
    
    $html .= '</div>';
    
    return $html;
}

/**
 * Render page footer
 */
function rawwire_render_page_footer(array $config, array $context = []) {
    $html = '<div class="rawwire-page-footer">';
    
    if (!empty($config['text'])) {
        $html .= '<p>' . esc_html($config['text']) . '</p>';
    }
    
    if (!empty($config['controls'])) {
        $html .= rawwire_controls()->render_group($config['controls'], $context);
    }
    
    $html .= '</div>';
    
    return $html;
}

/**
 * Render sidebar
 */
function rawwire_render_sidebar(array $config, array $context = []) {
    $position = $config['position'] ?? 'left';
    $collapsed = !empty($config['collapsed']);
    
    $classes = ['rawwire-sidebar', 'rawwire-sidebar--' . $position];
    if ($collapsed) {
        $classes[] = 'rawwire-sidebar--collapsed';
    }
    
    $html = '<div class="' . esc_attr(implode(' ', $classes)) . '">';
    
    // Navigation items
    if (!empty($config['navigation'])) {
        $html .= '<nav class="rawwire-sidebar-nav">';
        $html .= '<ul>';
        
        foreach ($config['navigation'] as $item) {
            $active = !empty($item['active']) ? ' rawwire-nav-active' : '';
            $html .= '<li class="rawwire-nav-item' . $active . '">';
            $html .= '<a href="' . esc_url($item['url'] ?? '#') . '">';
            
            if (!empty($item['icon'])) {
                $html .= '<span class="dashicons ' . esc_attr($item['icon']) . '"></span> ';
            }
            
            $html .= '<span class="rawwire-nav-label">' . esc_html($item['label'] ?? '') . '</span>';
            $html .= '</a></li>';
        }
        
        $html .= '</ul></nav>';
    }
    
    // Widgets/panels in sidebar
    if (!empty($config['panels'])) {
        foreach ($config['panels'] as $panel_config) {
            $type = $panel_config['type'] ?? 'html';
            $data_source = $panel_config['data_source'] ?? null;
            
            $data = null;
            if ($data_source) {
                $data = rawwire_data_sources()->get_data($data_source);
            }
            
            $html .= rawwire_panel_types()->render($type, $panel_config, $data);
        }
    }
    
    $html .= '</div>';
    
    return $html;
}

/**
 * Validate a template JSON against schema
 * 
 * @param array $template Template configuration
 * @param string $schema_type 'page' or 'panel'
 * @return array ['valid' => bool, 'errors' => array]
 */
function rawwire_validate_template(array $template, $schema_type = 'page') {
    $schema_file = RAWWIRE_TEMPLATE_ENGINE_DIR . '/schema/' . $schema_type . '-template.schema.json';
    
    if (!file_exists($schema_file)) {
        return [
            'valid' => false,
            'errors' => ['Schema file not found: ' . $schema_type],
        ];
    }
    
    // Basic validation without full JSON Schema library
    // In production, use something like justinrainbow/json-schema
    $errors = [];
    
    if ($schema_type === 'page') {
        if (empty($template['id'])) {
            $errors[] = 'Page template must have an id';
        }
        if (empty($template['title'])) {
            $errors[] = 'Page template must have a title';
        }
    } elseif ($schema_type === 'panel') {
        if (empty($template['id'])) {
            $errors[] = 'Panel template must have an id';
        }
        if (empty($template['type'])) {
            $errors[] = 'Panel template must have a type';
        } else {
            $valid_types = array_keys(rawwire_panel_types()->get_all());
            if (!in_array($template['type'], $valid_types)) {
                $errors[] = 'Invalid panel type: ' . $template['type'];
            }
        }
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors,
    ];
}

// Auto-load if this file is included directly
if (defined('RAWWIRE_DASHBOARD_VERSION')) {
    rawwire_template_engine_load();
    add_action('plugins_loaded', 'rawwire_template_engine_init', 15);
}
