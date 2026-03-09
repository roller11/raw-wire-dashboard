<?php
/**
 * Panel Type Registry
 * 
 * Manages registration and rendering of different panel types.
 * Panel types define HOW data is displayed (table, cards, chart, etc.)
 *
 * @package RawWire_Dashboard
 * @subpackage Template_Engine
 * @since 1.0.30
 */

if (!defined('ABSPATH')) {
    exit;
}

class RawWire_Panel_Type_Registry {
    
    /**
     * Singleton instance
     * @var RawWire_Panel_Type_Registry
     */
    private static $instance = null;
    
    /**
     * Registered panel types
     * @var array
     */
    private $types = [];
    
    /**
     * Get singleton instance
     * @return RawWire_Panel_Type_Registry
     */
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor - register built-in types
     */
    private function __construct() {
        $this->register_builtin_types();
        
        // Allow modules to register custom types
        do_action('rawwire_register_panel_types', $this);
    }
    
    /**
     * Register a panel type
     *
     * @param string $type_id Unique type identifier
     * @param array $config Type configuration
     * @return bool
     */
    public function register($type_id, array $config) {
        $defaults = [
            'label'       => ucfirst(str_replace('-', ' ', $type_id)),
            'description' => '',
            'icon'        => 'dashicons-editor-table',
            'category'    => 'general',
            'renderer'    => null,  // Callback or class name
            'supports'    => [
                'pagination' => true,
                'sorting'    => true,
                'filtering'  => true,
                'search'     => true,
                'export'     => true,
                'refresh'    => true,
            ],
            'config_schema' => [],  // JSON Schema for type-specific config
            'scripts'     => [],    // JS dependencies
            'styles'      => [],    // CSS dependencies
        ];
        
        $config = wp_parse_args($config, $defaults);
        
        // Validate renderer exists
        if (empty($config['renderer'])) {
            $config['renderer'] = [$this, 'render_' . str_replace('-', '_', $type_id)];
        }
        
        $this->types[$type_id] = $config;
        
        return true;
    }
    
    /**
     * Get a registered panel type
     *
     * @param string $type_id
     * @return array|null
     */
    public function get($type_id) {
        return $this->types[$type_id] ?? null;
    }
    
    /**
     * Get all registered panel types
     *
     * @param string|null $category Filter by category
     * @return array
     */
    public function get_all($category = null) {
        if ($category === null) {
            return $this->types;
        }
        
        return array_filter($this->types, function($type) use ($category) {
            return $type['category'] === $category;
        });
    }
    
    /**
     * Get panel types grouped by category
     *
     * @return array
     */
    public function get_grouped() {
        $grouped = [];
        
        foreach ($this->types as $id => $type) {
            $cat = $type['category'];
            if (!isset($grouped[$cat])) {
                $grouped[$cat] = [];
            }
            $grouped[$cat][$id] = $type;
        }
        
        return $grouped;
    }
    
    /**
     * Render a panel of a given type
     *
     * @param string $type_id Panel type
     * @param array $panel_config Panel configuration from template
     * @param mixed $data Data to display
     * @return string HTML output
     */
    public function render($type_id, array $panel_config, $data = null) {
        $type = $this->get($type_id);
        
        if (!$type) {
            // GRACEFUL DEGRADATION: Render unknown types with generic fallback
            return $this->render_fallback_panel($type_id, $panel_config, $data);
        }
        
        // Enqueue required assets
        $this->enqueue_assets($type);
        
        // Call the renderer
        $renderer = $type['renderer'];
        
        if (is_callable($renderer)) {
            return call_user_func($renderer, $panel_config, $data, $type);
        }
        
        // If renderer is a class name, instantiate it
        if (is_string($renderer) && class_exists($renderer)) {
            $instance = new $renderer();
            if (method_exists($instance, 'render')) {
                return $instance->render($panel_config, $data, $type);
            }
        }
        
        // Renderer exists but is invalid - use fallback
        return $this->render_fallback_panel($type_id, $panel_config, $data);
    }

    /**
     * Render a generic fallback panel for unknown or broken types
     * This ensures the dashboard never breaks due to missing panel types
     *
     * @param string $type_id The requested type
     * @param array $panel_config Panel configuration
     * @param mixed $data Any data passed
     * @return string HTML output
     */
    private function render_fallback_panel($type_id, array $panel_config, $data = null) {
        $title = $panel_config['title'] ?? ucfirst(str_replace('_', ' ', $panel_config['id'] ?? 'Panel'));
        $icon = $panel_config['icon'] ?? 'dashicons-admin-generic';
        $description = $panel_config['description'] ?? '';
        
        ob_start();
        ?>
        <div class="rawwire-panel rawwire-panel-fallback" data-panel-type="<?php echo esc_attr($type_id); ?>">
            <div class="rawwire-panel-header">
                <span class="dashicons <?php echo esc_attr($icon); ?>"></span>
                <h3><?php echo esc_html($title); ?></h3>
            </div>
            <div class="rawwire-panel-body">
                <?php if ($description): ?>
                    <p class="description"><?php echo esc_html($description); ?></p>
                <?php endif; ?>
                
                <?php if ($data && is_array($data)): ?>
                    <div class="rawwire-fallback-data">
                        <p><small>Data available (<?php echo count($data); ?> items)</small></p>
                    </div>
                <?php elseif ($data): ?>
                    <div class="rawwire-fallback-data">
                        <p><small>Data available</small></p>
                    </div>
                <?php else: ?>
                    <p class="rawwire-panel-empty">
                        <span class="dashicons dashicons-info"></span>
                        Panel type "<code><?php echo esc_html($type_id); ?></code>" is not registered.
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Enqueue assets for a panel type
     *
     * @param array $type Type configuration
     */
    private function enqueue_assets(array $type) {
        foreach ($type['scripts'] as $handle => $src) {
            if (is_numeric($handle)) {
                // Just a handle name, assume it's already registered
                wp_enqueue_script($src);
            } else {
                wp_enqueue_script($handle, $src, ['jquery'], RAWWIRE_VERSION, true);
            }
        }
        
        foreach ($type['styles'] as $handle => $src) {
            if (is_numeric($handle)) {
                wp_enqueue_style($src);
            } else {
                wp_enqueue_style($handle, $src, [], RAWWIRE_VERSION);
            }
        }
    }
    
    /**
     * Render an error state
     *
     * @param string $message
     * @return string
     */
    private function render_error($message) {
        return '<div class="rawwire-panel-error notice notice-error"><p>' . 
               esc_html($message) . '</p></div>';
    }
    
    /**
     * Register all built-in panel types
     * 
     * Strategy: For legacy types (status, control, data, settings, custom, log),
     * delegate to RawWire_Panel_Renderer to preserve existing functionality.
     * New types get fresh implementations here.
     */
    private function register_builtin_types() {
        // =======================================================================
        // LEGACY TYPES - Delegate to existing RawWire_Panel_Renderer
        // These preserve 100% backward compatibility
        // =======================================================================
        
        $this->register('status', [
            'label'       => __('Status Panel', 'raw-wire-dashboard'),
            'description' => __('Display metrics, statistics, and action buttons', 'raw-wire-dashboard'),
            'icon'        => 'dashicons-chart-pie',
            'category'    => 'legacy',
            'renderer'    => [$this, 'render_legacy_panel'],
        ]);
        
        $this->register('control', [
            'label'       => __('Control Panel', 'raw-wire-dashboard'),
            'description' => __('Buttons, toggles, and input controls', 'raw-wire-dashboard'),
            'icon'        => 'dashicons-admin-settings',
            'category'    => 'legacy',
            'renderer'    => [$this, 'render_legacy_panel'],
        ]);
        
        $this->register('data', [
            'label'       => __('Data Panel', 'raw-wire-dashboard'),
            'description' => __('Tables, lists, and card grids', 'raw-wire-dashboard'),
            'icon'        => 'dashicons-editor-table',
            'category'    => 'legacy',
            'renderer'    => [$this, 'render_legacy_panel'],
        ]);
        
        $this->register('settings', [
            'label'       => __('Settings Panel', 'raw-wire-dashboard'),
            'description' => __('Form-based configuration panels', 'raw-wire-dashboard'),
            'icon'        => 'dashicons-admin-generic',
            'category'    => 'legacy',
            'renderer'    => [$this, 'render_legacy_panel'],
        ]);
        
        $this->register('custom', [
            'label'       => __('Custom Panel', 'raw-wire-dashboard'),
            'description' => __('Custom PHP or JavaScript rendering', 'raw-wire-dashboard'),
            'icon'        => 'dashicons-editor-code',
            'category'    => 'legacy',
            'renderer'    => [$this, 'render_legacy_panel'],
        ]);
        
        $this->register('log', [
            'label'       => __('Log Panel', 'raw-wire-dashboard'),
            'description' => __('Activity and error log display', 'raw-wire-dashboard'),
            'icon'        => 'dashicons-text-page',
            'category'    => 'legacy',
            'renderer'    => [$this, 'render_legacy_panel'],
        ]);
        
        // =======================================================================
        // NEW DATA DISPLAY TYPES
        // =======================================================================
        
        $this->register('table', [
            'label'       => __('Data Table', 'raw-wire-dashboard'),
            'description' => __('Display data in sortable, filterable rows and columns', 'raw-wire-dashboard'),
            'icon'        => 'dashicons-editor-table',
            'category'    => 'data',
            'renderer'    => [$this, 'render_table'],
            'supports'    => [
                'pagination' => true,
                'sorting'    => true,
                'filtering'  => true,
                'search'     => true,
                'export'     => true,
                'refresh'    => true,
                'selection'  => true,
                'bulk_actions' => true,
            ],
        ]);
        
        $this->register('cards', [
            'label'       => __('Card Grid', 'raw-wire-dashboard'),
            'description' => __('Display items as visual cards in a responsive grid', 'raw-wire-dashboard'),
            'icon'        => 'dashicons-grid-view',
            'category'    => 'data',
            'renderer'    => [$this, 'render_cards'],
            'supports'    => [
                'pagination' => true,
                'filtering'  => true,
                'search'     => true,
                'refresh'    => true,
            ],
        ]);
        
        $this->register('list', [
            'label'       => __('Simple List', 'raw-wire-dashboard'),
            'description' => __('Display items in a simple vertical list', 'raw-wire-dashboard'),
            'icon'        => 'dashicons-list-view',
            'category'    => 'data',
            'renderer'    => [$this, 'render_list'],
        ]);
        
        // Analytics Types
        $this->register('metrics', [
            'label'       => __('Metric Boxes', 'raw-wire-dashboard'),
            'description' => __('Display KPIs and statistics as prominent number boxes', 'raw-wire-dashboard'),
            'icon'        => 'dashicons-chart-bar',
            'category'    => 'analytics',
            'renderer'    => [$this, 'render_metrics'],
            'supports'    => [
                'refresh' => true,
            ],
        ]);
        
        $this->register('chart', [
            'label'       => __('Chart', 'raw-wire-dashboard'),
            'description' => __('Visualize data with various chart types', 'raw-wire-dashboard'),
            'icon'        => 'dashicons-chart-line',
            'category'    => 'analytics',
            'renderer'    => [$this, 'render_chart'],
            'scripts'     => ['chart-js'],
        ]);
        
        // Content Types
        $this->register('timeline', [
            'label'       => __('Timeline', 'raw-wire-dashboard'),
            'description' => __('Display events or activities chronologically', 'raw-wire-dashboard'),
            'icon'        => 'dashicons-backup',
            'category'    => 'content',
            'renderer'    => [$this, 'render_timeline'],
        ]);
        
        $this->register('calendar', [
            'label'       => __('Calendar', 'raw-wire-dashboard'),
            'description' => __('Display events in a calendar view', 'raw-wire-dashboard'),
            'icon'        => 'dashicons-calendar-alt',
            'category'    => 'content',
            'renderer'    => [$this, 'render_calendar'],
            'scripts'     => ['fullcalendar'],
        ]);
        
        $this->register('kanban', [
            'label'       => __('Kanban Board', 'raw-wire-dashboard'),
            'description' => __('Drag-and-drop workflow columns', 'raw-wire-dashboard'),
            'icon'        => 'dashicons-columns',
            'category'    => 'content',
            'renderer'    => [$this, 'render_kanban'],
        ]);
        
        // Input Types
        $this->register('form', [
            'label'       => __('Form', 'raw-wire-dashboard'),
            'description' => __('Input form with various field types', 'raw-wire-dashboard'),
            'icon'        => 'dashicons-feedback',
            'category'    => 'input',
            'renderer'    => [$this, 'render_form'],
            'supports'    => [
                'validation' => true,
            ],
        ]);
        
        // Specialized Types
        $this->register('map', [
            'label'       => __('Map', 'raw-wire-dashboard'),
            'description' => __('Display locations on an interactive map', 'raw-wire-dashboard'),
            'icon'        => 'dashicons-location-alt',
            'category'    => 'specialized',
            'renderer'    => [$this, 'render_map'],
        ]);
        
        $this->register('chat', [
            'label'       => __('Chat/AI Assistant', 'raw-wire-dashboard'),
            'description' => __('Interactive chat interface with optional AI', 'raw-wire-dashboard'),
            'icon'        => 'dashicons-format-chat',
            'category'    => 'specialized',
            'renderer'    => [$this, 'render_chat'],
        ]);
        
        $this->register('file-browser', [
            'label'       => __('File Browser', 'raw-wire-dashboard'),
            'description' => __('Browse and manage files/documents', 'raw-wire-dashboard'),
            'icon'        => 'dashicons-portfolio',
            'category'    => 'specialized',
            'renderer'    => [$this, 'render_file_browser'],
        ]);
        
        $this->register('status-monitor', [
            'label'       => __('Status Monitor', 'raw-wire-dashboard'),
            'description' => __('Display system/service status indicators', 'raw-wire-dashboard'),
            'icon'        => 'dashicons-dashboard',
            'category'    => 'specialized',
            'renderer'    => [$this, 'render_status_monitor'],
            'supports'    => [
                'refresh' => true,
            ],
        ]);
        
        // Raw/Custom Types
        $this->register('html', [
            'label'       => __('HTML/Shortcode', 'raw-wire-dashboard'),
            'description' => __('Raw HTML content or WordPress shortcode', 'raw-wire-dashboard'),
            'icon'        => 'dashicons-html',
            'category'    => 'custom',
            'renderer'    => [$this, 'render_html'],
        ]);
        
        $this->register('iframe', [
            'label'       => __('Iframe Embed', 'raw-wire-dashboard'),
            'description' => __('Embed external content via iframe', 'raw-wire-dashboard'),
            'icon'        => 'dashicons-embed-generic',
            'category'    => 'custom',
            'renderer'    => [$this, 'render_iframe'],
        ]);
        
        $this->register('gallery', [
            'label'       => __('Image Gallery', 'raw-wire-dashboard'),
            'description' => __('Display images in a gallery format', 'raw-wire-dashboard'),
            'icon'        => 'dashicons-format-gallery',
            'category'    => 'content',
            'renderer'    => [$this, 'render_gallery'],
        ]);
    }
    
    // =======================================================================
    // Built-in Renderers (Stub implementations - to be expanded)
    // =======================================================================
    
    /**
     * Render table panel type
     */
    public function render_table($config, $data, $type) {
        // Delegate to existing panel-renderer.php logic
        if (class_exists('RawWire_Panel_Renderer')) {
            return RawWire_Panel_Renderer::render_table_panel($config, $data);
        }
        
        // Fallback basic table
        $html = '<table class="wp-list-table widefat fixed striped">';
        
        // Header
        if (!empty($config['type_config']['columns'])) {
            $html .= '<thead><tr>';
            foreach ($config['type_config']['columns'] as $col) {
                $html .= '<th>' . esc_html($col['label']) . '</th>';
            }
            $html .= '</tr></thead>';
        }
        
        // Body
        $html .= '<tbody>';
        if (is_array($data)) {
            foreach ($data as $row) {
                $html .= '<tr>';
                foreach ($config['type_config']['columns'] as $col) {
                    $value = $row[$col['key']] ?? '';
                    $html .= '<td>' . esc_html($value) . '</td>';
                }
                $html .= '</tr>';
            }
        }
        $html .= '</tbody></table>';
        
        return $html;
    }
    
    /**
     * Render cards panel type
     */
    public function render_cards($config, $data, $type) {
        $cols = $config['type_config']['columns'] ?? 3;
        $html = '<div class="rawwire-cards-grid" style="display:grid;grid-template-columns:repeat(' . intval($cols) . ',1fr);gap:16px;">';
        
        if (is_array($data)) {
            foreach ($data as $item) {
                $title = $item[$config['type_config']['title_field'] ?? 'title'] ?? '';
                $subtitle = $item[$config['type_config']['subtitle_field'] ?? ''] ?? '';
                $content = $item[$config['type_config']['content_field'] ?? 'content'] ?? '';
                $image = $item[$config['type_config']['image_field'] ?? ''] ?? '';
                
                $html .= '<div class="rawwire-card" style="background:#fff;border:1px solid #ddd;border-radius:4px;overflow:hidden;">';
                if ($image) {
                    $html .= '<img src="' . esc_url($image) . '" style="width:100%;height:150px;object-fit:cover;">';
                }
                $html .= '<div style="padding:12px;">';
                $html .= '<h4 style="margin:0 0 8px;">' . esc_html($title) . '</h4>';
                if ($subtitle) {
                    $html .= '<p style="color:#666;margin:0 0 8px;font-size:12px;">' . esc_html($subtitle) . '</p>';
                }
                if ($content) {
                    $html .= '<p style="margin:0;">' . esc_html(wp_trim_words($content, 20)) . '</p>';
                }
                $html .= '</div></div>';
            }
        }
        
        $html .= '</div>';
        return $html;
    }
    
    /**
     * Render metrics panel type
     */
    public function render_metrics($config, $data, $type) {
        $metrics = $config['type_config']['metrics'] ?? [];
        $html = '<div class="rawwire-metrics-grid" style="display:flex;gap:16px;flex-wrap:wrap;">';
        
        foreach ($metrics as $metric) {
            $value = is_array($data) ? ($data[$metric['key']] ?? 0) : 0;
            $label = $metric['label'] ?? $metric['key'];
            $icon = $metric['icon'] ?? 'dashicons-chart-bar';
            $color = $metric['color'] ?? '#2271b1';
            $prefix = $metric['prefix'] ?? '';
            $suffix = $metric['suffix'] ?? '';
            
            // Format value
            if (($metric['format'] ?? '') === 'currency') {
                $value = '$' . number_format($value, 2);
            } elseif (($metric['format'] ?? '') === 'percent') {
                $value = number_format($value, 1) . '%';
            } else {
                $value = number_format($value);
            }
            
            $html .= '<div class="rawwire-metric-box" style="flex:1;min-width:150px;background:#fff;border:1px solid #ddd;border-radius:4px;padding:16px;border-left:4px solid ' . esc_attr($color) . ';">';
            $html .= '<span class="dashicons ' . esc_attr($icon) . '" style="color:' . esc_attr($color) . ';"></span>';
            $html .= '<div class="rawwire-metric-value" style="font-size:24px;font-weight:600;margin:8px 0;">' . 
                     esc_html($prefix) . esc_html($value) . esc_html($suffix) . '</div>';
            $html .= '<div class="rawwire-metric-label" style="color:#666;">' . esc_html($label) . '</div>';
            $html .= '</div>';
        }
        
        $html .= '</div>';
        return $html;
    }
    
    /**
     * Render timeline panel type
     */
    public function render_timeline($config, $data, $type) {
        $date_field = $config['type_config']['date_field'] ?? 'date';
        $title_field = $config['type_config']['title_field'] ?? 'title';
        $content_field = $config['type_config']['content_field'] ?? 'content';
        
        $html = '<div class="rawwire-timeline" style="position:relative;padding-left:30px;">';
        $html .= '<div class="rawwire-timeline-line" style="position:absolute;left:10px;top:0;bottom:0;width:2px;background:#ddd;"></div>';
        
        if (is_array($data)) {
            foreach ($data as $item) {
                $date = $item[$date_field] ?? '';
                $title = $item[$title_field] ?? '';
                $content = $item[$content_field] ?? '';
                
                $html .= '<div class="rawwire-timeline-item" style="position:relative;padding-bottom:20px;">';
                $html .= '<div class="rawwire-timeline-dot" style="position:absolute;left:-24px;width:12px;height:12px;background:#2271b1;border-radius:50%;"></div>';
                $html .= '<div class="rawwire-timeline-date" style="font-size:12px;color:#666;">' . esc_html($date) . '</div>';
                $html .= '<div class="rawwire-timeline-title" style="font-weight:600;">' . esc_html($title) . '</div>';
                if ($content) {
                    $html .= '<div class="rawwire-timeline-content" style="color:#444;margin-top:4px;">' . esc_html($content) . '</div>';
                }
                $html .= '</div>';
            }
        }
        
        $html .= '</div>';
        return $html;
    }
    
    /**
     * Render form panel type
     */
    public function render_form($config, $data, $type) {
        $fields = $config['type_config']['fields'] ?? [];
        $action = $config['type_config']['submit_action'] ?? '';
        $submit_label = $config['type_config']['submit_label'] ?? __('Save', 'raw-wire-dashboard');
        
        $html = '<form class="rawwire-panel-form" data-action="' . esc_attr($action) . '">';
        $html .= wp_nonce_field('rawwire_form', '_wpnonce', true, false);
        
        foreach ($fields as $field) {
            $name = $field['name'];
            $type = $field['type'] ?? 'text';
            $label = $field['label'] ?? $name;
            $required = !empty($field['required']);
            $value = $data[$name] ?? ($field['default_value'] ?? '');
            $width = $field['width'] ?? 'full';
            
            $width_style = 'width:100%;';
            if ($width === 'half') $width_style = 'width:48%;display:inline-block;';
            if ($width === 'third') $width_style = 'width:31%;display:inline-block;';
            
            $html .= '<div class="rawwire-form-field" style="margin-bottom:16px;' . $width_style . '">';
            $html .= '<label style="display:block;margin-bottom:4px;font-weight:500;">';
            $html .= esc_html($label);
            if ($required) $html .= ' <span style="color:red;">*</span>';
            $html .= '</label>';
            
            switch ($type) {
                case 'textarea':
                    $html .= '<textarea name="' . esc_attr($name) . '" class="widefat" rows="4"' . ($required ? ' required' : '') . '>' . esc_textarea($value) . '</textarea>';
                    break;
                case 'select':
                    $html .= '<select name="' . esc_attr($name) . '" class="widefat"' . ($required ? ' required' : '') . '>';
                    foreach (($field['options'] ?? []) as $opt) {
                        $selected = ($value == $opt['value']) ? ' selected' : '';
                        $html .= '<option value="' . esc_attr($opt['value']) . '"' . $selected . '>' . esc_html($opt['label']) . '</option>';
                    }
                    $html .= '</select>';
                    break;
                case 'checkbox':
                    $checked = $value ? ' checked' : '';
                    $html .= '<input type="checkbox" name="' . esc_attr($name) . '" value="1"' . $checked . '>';
                    break;
                default:
                    $html .= '<input type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" class="widefat" value="' . esc_attr($value) . '"' . ($required ? ' required' : '') . '>';
            }
            
            if (!empty($field['help_text'])) {
                $html .= '<p class="description">' . esc_html($field['help_text']) . '</p>';
            }
            
            $html .= '</div>';
        }
        
        $html .= '<div class="rawwire-form-actions" style="margin-top:20px;">';
        $html .= '<button type="submit" class="button button-primary">' . esc_html($submit_label) . '</button>';
        $html .= '</div>';
        $html .= '</form>';
        
        return $html;
    }
    
    /**
     * Render list panel type
     */
    public function render_list($config, $data, $type) {
        $html = '<ul class="rawwire-simple-list" style="margin:0;padding:0;list-style:none;">';
        
        if (is_array($data)) {
            foreach ($data as $item) {
                $title = is_array($item) ? ($item['title'] ?? $item['name'] ?? '') : $item;
                $html .= '<li style="padding:8px 0;border-bottom:1px solid #eee;">' . esc_html($title) . '</li>';
            }
        }
        
        $html .= '</ul>';
        return $html;
    }
    
    /**
     * Render chart panel type
     */
    public function render_chart($config, $data, $type) {
        $chart_id = 'rawwire-chart-' . wp_rand();
        $chart_type = $config['type_config']['chart_type'] ?? 'line';
        
        $html = '<div class="rawwire-chart-container" style="position:relative;height:300px;">';
        $html .= '<canvas id="' . esc_attr($chart_id) . '"></canvas>';
        $html .= '</div>';
        
        // Chart initialization script
        $html .= '<script>
            jQuery(document).ready(function($) {
                if (typeof Chart !== "undefined") {
                    var ctx = document.getElementById("' . esc_js($chart_id) . '").getContext("2d");
                    new Chart(ctx, {
                        type: "' . esc_js($chart_type) . '",
                        data: ' . wp_json_encode($data) . ',
                        options: ' . wp_json_encode($config['type_config']['options'] ?? []) . '
                    });
                }
            });
        </script>';
        
        return $html;
    }
    
    /**
     * Render HTML panel type
     */
    public function render_html($config, $data, $type) {
        $content = $config['type_config']['content'] ?? '';
        
        // Process shortcodes
        return do_shortcode($content);
    }
    
    /**
     * Render iframe panel type
     */
    public function render_iframe($config, $data, $type) {
        $src = $config['type_config']['src'] ?? '';
        $height = $config['type_config']['height'] ?? '400px';
        
        return '<iframe src="' . esc_url($src) . '" style="width:100%;height:' . esc_attr($height) . ';border:none;"></iframe>';
    }
    
    /**
     * Placeholder renderers for types that need more complex implementation
     */
    public function render_calendar($config, $data, $type) {
        return '<div class="rawwire-calendar-placeholder" style="padding:40px;text-align:center;background:#f0f0f0;border-radius:4px;">' .
               '<span class="dashicons dashicons-calendar-alt" style="font-size:48px;color:#999;"></span>' .
               '<p>' . __('Calendar view - requires FullCalendar library', 'raw-wire-dashboard') . '</p></div>';
    }
    
    public function render_kanban($config, $data, $type) {
        return '<div class="rawwire-kanban-placeholder" style="padding:40px;text-align:center;background:#f0f0f0;border-radius:4px;">' .
               '<span class="dashicons dashicons-columns" style="font-size:48px;color:#999;"></span>' .
               '<p>' . __('Kanban board - implementation pending', 'raw-wire-dashboard') . '</p></div>';
    }
    
    public function render_map($config, $data, $type) {
        return '<div class="rawwire-map-placeholder" style="padding:40px;text-align:center;background:#f0f0f0;border-radius:4px;">' .
               '<span class="dashicons dashicons-location-alt" style="font-size:48px;color:#999;"></span>' .
               '<p>' . __('Map view - requires mapping library', 'raw-wire-dashboard') . '</p></div>';
    }
    
    public function render_chat($config, $data, $type) {
        // This could integrate with the existing AI chatbot
        return '<div class="rawwire-chat-placeholder" style="padding:40px;text-align:center;background:#f0f0f0;border-radius:4px;">' .
               '<span class="dashicons dashicons-format-chat" style="font-size:48px;color:#999;"></span>' .
               '<p>' . __('Chat interface - use AI Engine integration', 'raw-wire-dashboard') . '</p></div>';
    }
    
    public function render_file_browser($config, $data, $type) {
        return '<div class="rawwire-filebrowser-placeholder" style="padding:40px;text-align:center;background:#f0f0f0;border-radius:4px;">' .
               '<span class="dashicons dashicons-portfolio" style="font-size:48px;color:#999;"></span>' .
               '<p>' . __('File browser - implementation pending', 'raw-wire-dashboard') . '</p></div>';
    }
    
    public function render_gallery($config, $data, $type) {
        $html = '<div class="rawwire-gallery" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px;">';
        
        if (is_array($data)) {
            foreach ($data as $item) {
                $src = is_array($item) ? ($item['url'] ?? $item['src'] ?? '') : $item;
                $title = is_array($item) ? ($item['title'] ?? '') : '';
                
                $html .= '<div class="rawwire-gallery-item" style="aspect-ratio:1;overflow:hidden;border-radius:4px;">';
                $html .= '<img src="' . esc_url($src) . '" alt="' . esc_attr($title) . '" style="width:100%;height:100%;object-fit:cover;">';
                $html .= '</div>';
            }
        }
        
        $html .= '</div>';
        return $html;
    }
    
    // =======================================================================
    // Legacy Panel Support - Backward Compatibility
    // =======================================================================
    
    /**
     * Render legacy panel type by delegating to existing RawWire_Panel_Renderer
     * 
     * This ensures 100% backward compatibility with existing dashboard pages.
     * Legacy types: status, control, data, settings, custom, log
     * 
     * @param array $config Panel configuration
     * @param mixed $data Panel data
     * @param string $type Panel type
     * @return string Rendered HTML
     */
    public function render_legacy_panel($config, $data, $type) {
        // Include the legacy panel renderer if not already loaded
        if (!class_exists('RawWire_Panel_Renderer')) {
            $renderer_path = dirname(__DIR__) . '/panel-renderer.php';
            if (file_exists($renderer_path)) {
                require_once $renderer_path;
            }
        }
        
        // Delegate to the existing RawWire_Panel_Renderer
        if (class_exists('RawWire_Panel_Renderer')) {
            // The legacy renderer expects the full panel config array
            return RawWire_Panel_Renderer::render($config, $data ?? []);
        }
        
        // Fallback error if legacy renderer not available
        return '<div class="rawwire-panel-error" style="padding:20px;background:#fef7f1;border:1px solid #d63638;border-radius:4px;color:#d63638;">' .
               '<strong>' . __('Error:', 'raw-wire-dashboard') . '</strong> ' .
               __('Legacy panel renderer not available. Please ensure panel-renderer.php exists.', 'raw-wire-dashboard') .
               '</div>';
    }
    
    /**
     * Render status monitor panel type
     * 
     * This is the NEW status-monitor panel type for service health monitoring.
     * Not to be confused with the legacy 'status' type which uses RawWire_Panel_Renderer.
     */
    public function render_status_monitor($config, $data, $type) {
        $html = '<div class="rawwire-status-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;">';
        
        if (is_array($data)) {
            foreach ($data as $service => $status) {
                $is_ok = $status['status'] ?? $status === 'ok' || $status === true;
                $color = $is_ok ? '#00a32a' : '#d63638';
                $icon = $is_ok ? 'dashicons-yes-alt' : 'dashicons-dismiss';
                $label = is_array($status) ? ($status['label'] ?? $service) : $service;
                $message = is_array($status) ? ($status['message'] ?? '') : '';
                
                $html .= '<div class="rawwire-status-item" style="display:flex;align-items:center;padding:12px;background:#fff;border:1px solid #ddd;border-radius:4px;">';
                $html .= '<span class="dashicons ' . esc_attr($icon) . '" style="color:' . esc_attr($color) . ';margin-right:8px;"></span>';
                $html .= '<div>';
                $html .= '<strong>' . esc_html($label) . '</strong>';
                if ($message) {
                    $html .= '<br><small style="color:#666;">' . esc_html($message) . '</small>';
                }
                $html .= '</div></div>';
            }
        }
        
        $html .= '</div>';
        return $html;
    }
}
