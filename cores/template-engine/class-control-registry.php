<?php
/**
 * Control Registry
 * 
 * Manages registration and rendering of UI controls for panels.
 * Controls are interactive elements (buttons, dropdowns, toggles, etc.)
 *
 * @package RawWire_Dashboard
 * @subpackage Template_Engine
 * @since 1.0.30
 */

if (!defined('ABSPATH')) {
    exit;
}

class RawWire_Control_Registry {
    
    /**
     * Singleton instance
     * @var RawWire_Control_Registry
     */
    private static $instance = null;
    
    /**
     * Registered control types
     * @var array
     */
    private $controls = [];
    
    /**
     * Get singleton instance
     * @return RawWire_Control_Registry
     */
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->register_builtin_controls();
        
        // Allow modules to register custom controls
        do_action('rawwire_register_controls', $this);
    }
    
    /**
     * Register a control type
     *
     * @param string $type_id Control type identifier
     * @param array $config Control configuration
     * @return bool
     */
    public function register($type_id, array $config) {
        $defaults = [
            'label'       => ucfirst(str_replace('-', ' ', $type_id)),
            'icon'        => 'dashicons-admin-generic',
            'category'    => 'general',
            'renderer'    => null,
            'config_schema' => [],
        ];
        
        $config = wp_parse_args($config, $defaults);
        
        if (empty($config['renderer'])) {
            $config['renderer'] = [$this, 'render_' . str_replace('-', '_', $type_id)];
        }
        
        $this->controls[$type_id] = $config;
        
        return true;
    }
    
    /**
     * Get a registered control
     *
     * @param string $type_id
     * @return array|null
     */
    public function get($type_id) {
        return $this->controls[$type_id] ?? null;
    }
    
    /**
     * Get all controls
     *
     * @return array
     */
    public function get_all() {
        return $this->controls;
    }
    
    /**
     * Render a control
     *
     * @param array $config Control configuration from template
     * @param array $context Additional context (item data, panel config, etc.)
     * @return string HTML
     */
    public function render(array $config, array $context = []) {
        $type = $config['type'] ?? 'button';
        $control = $this->get($type);
        
        if (!$control) {
            // GRACEFUL DEGRADATION: Render unknown controls as basic buttons
            return $this->render_fallback_control($config, $context);
        }
        
        // Check visibility conditions
        if (!$this->check_conditions($config['visible_conditions'] ?? [], $context)) {
            return '';
        }
        
        // Merge defaults
        $config = wp_parse_args($config, [
            'id'    => '',
            'label' => '',
            'icon'  => '',
            'css_class' => '',
            'style' => 'secondary',
            'size'  => 'medium',
        ]);
        
        $renderer = $control['renderer'];
        
        if (is_callable($renderer)) {
            return call_user_func($renderer, $config, $context);
        }
        
        return '';
    }

    /**
     * Render a fallback control for unknown types
     * This ensures the UI never breaks due to missing control types
     *
     * @param array $config Control configuration
     * @param array $context Additional context
     * @return string HTML
     */
    private function render_fallback_control(array $config, array $context = []) {
        $label = $config['label'] ?? 'Action';
        $icon = $config['icon'] ?? '';
        $style = $config['style'] ?? 'secondary';
        $action = $config['action'] ?? '';
        $id = $config['id'] ?? 'control-' . wp_rand();
        
        $css_class = 'rawwire-btn rawwire-btn-' . esc_attr($style) . ' rawwire-control-fallback';
        
        $html = '<button type="button" class="' . $css_class . '"';
        $html .= ' id="' . esc_attr($id) . '"';
        if ($action) {
            $html .= ' data-action="' . esc_attr($action) . '"';
        }
        $html .= ' title="Control type not registered">';
        
        if ($icon) {
            $html .= '<span class="dashicons ' . esc_attr($icon) . '"></span> ';
        }
        
        $html .= esc_html($label);
        $html .= '</button>';
        
        return $html;
    }
    
    /**
     * Render multiple controls
     *
     * @param array $controls Array of control configs
     * @param array $context
     * @return string HTML
     */
    public function render_group(array $controls, array $context = []) {
        $html = '<div class="rawwire-control-group">';
        
        foreach ($controls as $config) {
            $html .= $this->render($config, $context);
        }
        
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Check visibility/enable conditions
     *
     * @param array $conditions
     * @param array $context
     * @return bool
     */
    private function check_conditions(array $conditions, array $context) {
        if (empty($conditions)) {
            return true;
        }
        
        // Check capability
        if (!empty($conditions['capability'])) {
            if (!current_user_can($conditions['capability'])) {
                return false;
            }
        }
        
        // Check option equals
        if (!empty($conditions['option_equals'])) {
            foreach ($conditions['option_equals'] as $option => $expected) {
                if (get_option($option) != $expected) {
                    return false;
                }
            }
        }
        
        // Check data field equals
        if (!empty($conditions['data_field_equals']) && !empty($context['item'])) {
            foreach ($conditions['data_field_equals'] as $field => $expected) {
                $value = $context['item'][$field] ?? null;
                if ($value != $expected) {
                    return false;
                }
            }
        }
        
        // Check data exists
        if (!empty($conditions['data_exists']) && !empty($context['item'])) {
            $field = $conditions['data_exists'];
            if (empty($context['item'][$field])) {
                return false;
            }
        }
        
        // Custom callback
        if (!empty($conditions['custom']) && is_callable($conditions['custom'])) {
            if (!call_user_func($conditions['custom'], $context)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Register built-in controls
     */
    private function register_builtin_controls() {
        // Button
        $this->register('button', [
            'label'    => 'Button',
            'icon'     => 'dashicons-button',
            'category' => 'action',
            'renderer' => [$this, 'render_button'],
        ]);
        
        // Link
        $this->register('link', [
            'label'    => 'Link',
            'icon'     => 'dashicons-admin-links',
            'category' => 'action',
            'renderer' => [$this, 'render_link'],
        ]);
        
        // Toggle
        $this->register('toggle', [
            'label'    => 'Toggle Switch',
            'icon'     => 'dashicons-controls-repeat',
            'category' => 'input',
            'renderer' => [$this, 'render_toggle'],
        ]);
        
        // Dropdown
        $this->register('dropdown', [
            'label'    => 'Dropdown Menu',
            'icon'     => 'dashicons-arrow-down-alt2',
            'category' => 'action',
            'renderer' => [$this, 'render_dropdown'],
        ]);
        
        // Search
        $this->register('search', [
            'label'    => 'Search Box',
            'icon'     => 'dashicons-search',
            'category' => 'filter',
            'renderer' => [$this, 'render_search'],
        ]);
        
        // Filter
        $this->register('filter', [
            'label'    => 'Filter Dropdown',
            'icon'     => 'dashicons-filter',
            'category' => 'filter',
            'renderer' => [$this, 'render_filter'],
        ]);
        
        // Select
        $this->register('select', [
            'label'    => 'Select Dropdown',
            'icon'     => 'dashicons-list-view',
            'category' => 'input',
            'renderer' => [$this, 'render_select'],
        ]);
        
        // Multi-select
        $this->register('multi-select', [
            'label'    => 'Multi-Select',
            'icon'     => 'dashicons-forms',
            'category' => 'input',
            'renderer' => [$this, 'render_multi_select'],
        ]);
        
        // Date picker
        $this->register('date-picker', [
            'label'    => 'Date Picker',
            'icon'     => 'dashicons-calendar',
            'category' => 'input',
            'renderer' => [$this, 'render_date_picker'],
        ]);
        
        // Date range
        $this->register('date-range', [
            'label'    => 'Date Range',
            'icon'     => 'dashicons-calendar-alt',
            'category' => 'filter',
            'renderer' => [$this, 'render_date_range'],
        ]);
        
        // Text input
        $this->register('text-input', [
            'label'    => 'Text Input',
            'icon'     => 'dashicons-editor-textcolor',
            'category' => 'input',
            'renderer' => [$this, 'render_text_input'],
        ]);
        
        // Checkbox
        $this->register('checkbox', [
            'label'    => 'Checkbox',
            'icon'     => 'dashicons-yes',
            'category' => 'input',
            'renderer' => [$this, 'render_checkbox'],
        ]);
        
        // Refresh button
        $this->register('refresh', [
            'label'    => 'Refresh Button',
            'icon'     => 'dashicons-update',
            'category' => 'action',
            'renderer' => [$this, 'render_refresh'],
        ]);
        
        // Export button
        $this->register('export', [
            'label'    => 'Export Button',
            'icon'     => 'dashicons-download',
            'category' => 'action',
            'renderer' => [$this, 'render_export'],
        ]);
        
        // Import button
        $this->register('import', [
            'label'    => 'Import Button',
            'icon'     => 'dashicons-upload',
            'category' => 'action',
            'renderer' => [$this, 'render_import'],
        ]);
        
        // Bulk action selector
        $this->register('bulk-action', [
            'label'    => 'Bulk Action',
            'icon'     => 'dashicons-editor-ul',
            'category' => 'action',
            'renderer' => [$this, 'render_bulk_action'],
        ]);
    }
    
    // =======================================================================
    // Built-in Renderers
    // =======================================================================
    
    /**
     * Render button control
     */
    public function render_button($config, $context) {
        $id = $config['id'] ?? '';
        $label = $config['label'] ?? '';
        $icon = $config['icon'] ?? '';
        $action = $config['action'] ?? '';
        $action_type = $config['action_type'] ?? 'ajax';
        $style = $config['style'] ?? 'secondary';
        $size = $config['size'] ?? 'medium';
        $confirm = $config['confirm'] ?? '';
        $css_class = $config['css_class'] ?? '';
        $disabled = !$this->check_conditions($config['disabled_conditions'] ?? [], $context);
        
        // Build class list
        $classes = ['button', 'rawwire-control', 'rawwire-control-button'];
        if ($style === 'primary') $classes[] = 'button-primary';
        if ($style === 'danger') $classes[] = 'button-link-delete';
        if ($style === 'link') $classes[] = 'button-link';
        if ($size === 'small') $classes[] = 'button-small';
        if ($size === 'large') $classes[] = 'button-large';
        if ($css_class) $classes[] = $css_class;
        
        // Data attributes
        $data_attrs = [
            'action' => $action,
            'action-type' => $action_type,
        ];
        
        // Add item ID if in row context
        if (!empty($context['item']['id'])) {
            $data_attrs['item-id'] = $context['item']['id'];
        }
        
        if ($confirm) {
            $data_attrs['confirm'] = $confirm;
        }
        
        $data_html = '';
        foreach ($data_attrs as $key => $value) {
            $data_html .= ' data-' . esc_attr($key) . '="' . esc_attr($value) . '"';
        }
        
        $html = '<button type="button" class="' . esc_attr(implode(' ', $classes)) . '"';
        if ($id) $html .= ' id="' . esc_attr($id) . '"';
        if ($disabled) $html .= ' disabled';
        $html .= $data_html . '>';
        
        if ($icon) {
            $html .= '<span class="dashicons ' . esc_attr($icon) . '"></span> ';
        }
        
        $html .= esc_html($label);
        $html .= '</button>';
        
        return $html;
    }
    
    /**
     * Render link control
     */
    public function render_link($config, $context) {
        $label = $config['label'] ?? '';
        $href = $config['href'] ?? '#';
        $icon = $config['icon'] ?? '';
        $css_class = $config['css_class'] ?? '';
        
        // Replace placeholders in href
        if (!empty($context['item'])) {
            foreach ($context['item'] as $key => $value) {
                if (is_scalar($value)) {
                    $href = str_replace('{{' . $key . '}}', urlencode($value), $href);
                }
            }
        }
        
        $html = '<a href="' . esc_url($href) . '" class="rawwire-control rawwire-control-link ' . esc_attr($css_class) . '">';
        
        if ($icon) {
            $html .= '<span class="dashicons ' . esc_attr($icon) . '"></span> ';
        }
        
        $html .= esc_html($label);
        $html .= '</a>';
        
        return $html;
    }
    
    /**
     * Render toggle control
     */
    public function render_toggle($config, $context) {
        $id = $config['id'] ?? 'toggle-' . wp_rand();
        $label = $config['label'] ?? '';
        $action = $config['action'] ?? '';
        $checked = false;
        
        // Determine checked state from context
        if (!empty($config['value_field']) && !empty($context['item'])) {
            $checked = !empty($context['item'][$config['value_field']]);
        }
        
        $html = '<label class="rawwire-control rawwire-control-toggle">';
        $html .= '<input type="checkbox" id="' . esc_attr($id) . '"';
        $html .= ' data-action="' . esc_attr($action) . '"';
        if (!empty($context['item']['id'])) {
            $html .= ' data-item-id="' . esc_attr($context['item']['id']) . '"';
        }
        if ($checked) $html .= ' checked';
        $html .= '>';
        $html .= '<span class="rawwire-toggle-slider"></span>';
        if ($label) {
            $html .= ' <span class="rawwire-toggle-label">' . esc_html($label) . '</span>';
        }
        $html .= '</label>';
        
        return $html;
    }
    
    /**
     * Render dropdown control
     */
    public function render_dropdown($config, $context) {
        $id = $config['id'] ?? 'dropdown-' . wp_rand();
        $label = $config['label'] ?? '';
        $icon = $config['icon'] ?? 'dashicons-arrow-down-alt2';
        $options = $config['options'] ?? [];
        
        $html = '<div class="rawwire-control rawwire-control-dropdown" id="' . esc_attr($id) . '">';
        $html .= '<button type="button" class="button rawwire-dropdown-trigger">';
        if ($icon) {
            $html .= '<span class="dashicons ' . esc_attr($icon) . '"></span> ';
        }
        $html .= esc_html($label);
        $html .= ' <span class="dashicons dashicons-arrow-down-alt2"></span>';
        $html .= '</button>';
        
        $html .= '<ul class="rawwire-dropdown-menu" style="display:none;position:absolute;background:#fff;border:1px solid #ddd;padding:8px 0;margin:0;list-style:none;min-width:150px;z-index:100;">';
        foreach ($options as $option) {
            $html .= '<li>';
            $html .= '<a href="#" class="rawwire-dropdown-item" data-action="' . esc_attr($option['action'] ?? '') . '" data-value="' . esc_attr($option['value'] ?? '') . '" style="display:block;padding:8px 16px;text-decoration:none;color:#333;">';
            if (!empty($option['icon'])) {
                $html .= '<span class="dashicons ' . esc_attr($option['icon']) . '"></span> ';
            }
            $html .= esc_html($option['label'] ?? $option['value']);
            $html .= '</a></li>';
        }
        $html .= '</ul></div>';
        
        return $html;
    }
    
    /**
     * Render search control
     */
    public function render_search($config, $context) {
        $id = $config['id'] ?? 'search-' . wp_rand();
        $placeholder = $config['placeholder'] ?? __('Search...', 'raw-wire-dashboard');
        $action = $config['action'] ?? '';
        
        $html = '<div class="rawwire-control rawwire-control-search">';
        $html .= '<input type="search" id="' . esc_attr($id) . '" class="rawwire-search-input" placeholder="' . esc_attr($placeholder) . '" data-action="' . esc_attr($action) . '">';
        $html .= '<span class="dashicons dashicons-search rawwire-search-icon"></span>';
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Render filter dropdown control
     */
    public function render_filter($config, $context) {
        $id = $config['id'] ?? 'filter-' . wp_rand();
        $label = $config['label'] ?? __('Filter', 'raw-wire-dashboard');
        $options = $config['options'] ?? [];
        $action = $config['action'] ?? '';
        $default = $config['default_value'] ?? '';
        
        // Get options from source if specified
        if (!empty($config['options_source'])) {
            $options = rawwire_data_sources()->get_data($config['options_source']);
        }
        
        $html = '<div class="rawwire-control rawwire-control-filter">';
        $html .= '<label for="' . esc_attr($id) . '">' . esc_html($label) . ': </label>';
        $html .= '<select id="' . esc_attr($id) . '" class="rawwire-filter-select" data-action="' . esc_attr($action) . '">';
        $html .= '<option value="">' . __('All', 'raw-wire-dashboard') . '</option>';
        
        foreach ($options as $option) {
            $value = is_array($option) ? ($option['value'] ?? '') : $option;
            $optLabel = is_array($option) ? ($option['label'] ?? $value) : $option;
            $selected = ($value == $default) ? ' selected' : '';
            $html .= '<option value="' . esc_attr($value) . '"' . $selected . '>' . esc_html($optLabel) . '</option>';
        }
        
        $html .= '</select></div>';
        
        return $html;
    }
    
    /**
     * Render select control
     */
    public function render_select($config, $context) {
        $id = $config['id'] ?? 'select-' . wp_rand();
        $name = $config['name'] ?? $id;
        $label = $config['label'] ?? '';
        $options = $config['options'] ?? [];
        $default = $config['default_value'] ?? '';
        $required = !empty($config['required']);
        
        // Get options from source if specified
        if (!empty($config['options_source'])) {
            $options = rawwire_data_sources()->get_data($config['options_source']);
        }
        
        $html = '<div class="rawwire-control rawwire-control-select">';
        if ($label) {
            $html .= '<label for="' . esc_attr($id) . '">' . esc_html($label) . '</label>';
        }
        $html .= '<select id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" class="widefat"';
        if ($required) $html .= ' required';
        $html .= '>';
        
        if (!empty($config['placeholder'])) {
            $html .= '<option value="">' . esc_html($config['placeholder']) . '</option>';
        }
        
        foreach ($options as $option) {
            $value = is_array($option) ? ($option['value'] ?? '') : $option;
            $optLabel = is_array($option) ? ($option['label'] ?? $value) : $option;
            $selected = ($value == $default) ? ' selected' : '';
            $html .= '<option value="' . esc_attr($value) . '"' . $selected . '>' . esc_html($optLabel) . '</option>';
        }
        
        $html .= '</select></div>';
        
        return $html;
    }
    
    /**
     * Render multi-select control
     */
    public function render_multi_select($config, $context) {
        $id = $config['id'] ?? 'multi-select-' . wp_rand();
        $name = $config['name'] ?? $id;
        $label = $config['label'] ?? '';
        $options = $config['options'] ?? [];
        $selected = (array) ($config['default_value'] ?? []);
        
        $html = '<div class="rawwire-control rawwire-control-multi-select">';
        if ($label) {
            $html .= '<label>' . esc_html($label) . '</label>';
        }
        $html .= '<select id="' . esc_attr($id) . '" name="' . esc_attr($name) . '[]" class="widefat" multiple style="height:120px;">';
        
        foreach ($options as $option) {
            $value = is_array($option) ? ($option['value'] ?? '') : $option;
            $optLabel = is_array($option) ? ($option['label'] ?? $value) : $option;
            $sel = in_array($value, $selected) ? ' selected' : '';
            $html .= '<option value="' . esc_attr($value) . '"' . $sel . '>' . esc_html($optLabel) . '</option>';
        }
        
        $html .= '</select></div>';
        
        return $html;
    }
    
    /**
     * Render date picker control
     */
    public function render_date_picker($config, $context) {
        $id = $config['id'] ?? 'date-' . wp_rand();
        $name = $config['name'] ?? $id;
        $label = $config['label'] ?? '';
        $value = $config['default_value'] ?? '';
        
        $html = '<div class="rawwire-control rawwire-control-date-picker">';
        if ($label) {
            $html .= '<label for="' . esc_attr($id) . '">' . esc_html($label) . '</label>';
        }
        $html .= '<input type="date" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" class="widefat" value="' . esc_attr($value) . '">';
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Render date range control
     */
    public function render_date_range($config, $context) {
        $id = $config['id'] ?? 'date-range-' . wp_rand();
        $label = $config['label'] ?? __('Date Range', 'raw-wire-dashboard');
        $action = $config['action'] ?? '';
        
        $html = '<div class="rawwire-control rawwire-control-date-range" data-action="' . esc_attr($action) . '">';
        if ($label) {
            $html .= '<label>' . esc_html($label) . ': </label>';
        }
        $html .= '<input type="date" name="' . esc_attr($id) . '_start" class="rawwire-date-start">';
        $html .= ' <span>' . __('to', 'raw-wire-dashboard') . '</span> ';
        $html .= '<input type="date" name="' . esc_attr($id) . '_end" class="rawwire-date-end">';
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Render text input control
     */
    public function render_text_input($config, $context) {
        $id = $config['id'] ?? 'text-' . wp_rand();
        $name = $config['name'] ?? $id;
        $label = $config['label'] ?? '';
        $value = $config['default_value'] ?? '';
        $placeholder = $config['placeholder'] ?? '';
        
        $html = '<div class="rawwire-control rawwire-control-text-input">';
        if ($label) {
            $html .= '<label for="' . esc_attr($id) . '">' . esc_html($label) . '</label>';
        }
        $html .= '<input type="text" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" class="widefat" value="' . esc_attr($value) . '" placeholder="' . esc_attr($placeholder) . '">';
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Render checkbox control
     */
    public function render_checkbox($config, $context) {
        $id = $config['id'] ?? 'checkbox-' . wp_rand();
        $name = $config['name'] ?? $id;
        $label = $config['label'] ?? '';
        $checked = !empty($config['default_value']);
        
        $html = '<div class="rawwire-control rawwire-control-checkbox">';
        $html .= '<label>';
        $html .= '<input type="checkbox" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="1"';
        if ($checked) $html .= ' checked';
        $html .= '> ';
        $html .= esc_html($label);
        $html .= '</label></div>';
        
        return $html;
    }
    
    /**
     * Render refresh button
     */
    public function render_refresh($config, $context) {
        $config = wp_parse_args($config, [
            'label' => '',
            'icon'  => 'dashicons-update',
            'action' => 'refresh_panel',
            'tooltip' => __('Refresh', 'raw-wire-dashboard'),
        ]);
        
        return $this->render_button($config, $context);
    }
    
    /**
     * Render export button
     */
    public function render_export($config, $context) {
        $config = wp_parse_args($config, [
            'label' => __('Export', 'raw-wire-dashboard'),
            'icon'  => 'dashicons-download',
        ]);
        
        return $this->render_dropdown([
            'label' => $config['label'],
            'icon'  => $config['icon'],
            'options' => [
                ['value' => 'csv', 'label' => 'CSV', 'action' => 'export_csv'],
                ['value' => 'json', 'label' => 'JSON', 'action' => 'export_json'],
                ['value' => 'xlsx', 'label' => 'Excel', 'action' => 'export_xlsx'],
            ],
        ], $context);
    }
    
    /**
     * Render import button
     */
    public function render_import($config, $context) {
        $id = $config['id'] ?? 'import-' . wp_rand();
        $action = $config['action'] ?? 'import_data';
        
        $html = '<div class="rawwire-control rawwire-control-import">';
        $html .= '<input type="file" id="' . esc_attr($id) . '-file" style="display:none;" accept=".csv,.json,.xlsx">';
        $html .= '<button type="button" class="button rawwire-import-trigger" data-file-input="' . esc_attr($id) . '-file" data-action="' . esc_attr($action) . '">';
        $html .= '<span class="dashicons dashicons-upload"></span> ';
        $html .= esc_html($config['label'] ?? __('Import', 'raw-wire-dashboard'));
        $html .= '</button></div>';
        
        return $html;
    }
    
    /**
     * Render bulk action control
     */
    public function render_bulk_action($config, $context) {
        $id = $config['id'] ?? 'bulk-action-' . wp_rand();
        $options = $config['options'] ?? [];
        
        $html = '<div class="rawwire-control rawwire-control-bulk-action">';
        $html .= '<select id="' . esc_attr($id) . '" class="rawwire-bulk-action-select">';
        $html .= '<option value="">' . __('Bulk Actions', 'raw-wire-dashboard') . '</option>';
        
        foreach ($options as $option) {
            $value = is_array($option) ? ($option['value'] ?? $option['action'] ?? '') : $option;
            $label = is_array($option) ? ($option['label'] ?? $value) : $option;
            $html .= '<option value="' . esc_attr($value) . '">' . esc_html($label) . '</option>';
        }
        
        $html .= '</select>';
        $html .= '<button type="button" class="button rawwire-bulk-action-apply" disabled>';
        $html .= __('Apply', 'raw-wire-dashboard');
        $html .= '</button></div>';
        
        return $html;
    }
}
