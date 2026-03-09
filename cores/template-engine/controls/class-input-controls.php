<?php

/**
 * Input Controls - Toggle, Select, Multi-Select, Text Input, Checkbox, Date Picker
 *
 * Form-style elements for capturing user input within panels.
 *
 * @package RawWire_Dashboard
 * @subpackage Template_Engine\Controls
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class RawWire_Input_Controls
{

    /**
     * Register all input controls with the registry
     *
     * @param RawWire_Control_Registry $registry
     */
    public static function register(RawWire_Control_Registry $registry)
    {
        $registry->register('toggle', [
            'label'    => 'Toggle Switch',
            'icon'     => 'dashicons-controls-repeat',
            'category' => 'input',
            'renderer' => [__CLASS__, 'render_toggle'],
        ]);

        $registry->register('select', [
            'label'    => 'Select Dropdown',
            'icon'     => 'dashicons-list-view',
            'category' => 'input',
            'renderer' => [__CLASS__, 'render_select'],
        ]);

        $registry->register('multi-select', [
            'label'    => 'Multi-Select',
            'icon'     => 'dashicons-forms',
            'category' => 'input',
            'renderer' => [__CLASS__, 'render_multi_select'],
        ]);

        $registry->register('text-input', [
            'label'    => 'Text Input',
            'icon'     => 'dashicons-editor-textcolor',
            'category' => 'input',
            'renderer' => [__CLASS__, 'render_text_input'],
        ]);

        $registry->register('checkbox', [
            'label'    => 'Checkbox',
            'icon'     => 'dashicons-yes',
            'category' => 'input',
            'renderer' => [__CLASS__, 'render_checkbox'],
        ]);

        $registry->register('date-picker', [
            'label'    => 'Date Picker',
            'icon'     => 'dashicons-calendar',
            'category' => 'input',
            'renderer' => [__CLASS__, 'render_date_picker'],
        ]);
    }

    // ==================================================================
    // Renderers
    // ==================================================================

    /**
     * Render toggle switch control
     *
     * @param array $config  Toggle config (id, label, action, value_field)
     * @param array $context Runtime context
     * @return string HTML
     */
    public static function render_toggle($config, $context)
    {
        $id      = $config['id'] ?? 'toggle-' . wp_rand();
        $label   = $config['label'] ?? '';
        $action  = $config['action'] ?? '';
        $checked = false;

        // Determine checked state from context data
        if (!empty($config['value_field']) && !empty($context['item'])) {
            $checked = !empty($context['item'][$config['value_field']]);
        }

        $html  = '<label class="rawwire-control rawwire-control-toggle">';
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
     * Render select dropdown control
     *
     * @param array $config  Select config (id, name, label, options, default_value, required, placeholder, options_source)
     * @param array $context Runtime context
     * @return string HTML
     */
    public static function render_select($config, $context)
    {
        $id       = $config['id'] ?? 'select-' . wp_rand();
        $name     = $config['name'] ?? $id;
        $label    = $config['label'] ?? '';
        $options  = $config['options'] ?? [];
        $default  = $config['default_value'] ?? '';
        $required = !empty($config['required']);

        // Dynamic options from data source
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
            $value    = is_array($option) ? ($option['value'] ?? '') : $option;
            $optLabel = is_array($option) ? ($option['label'] ?? $value) : $option;
            $selected = ($value == $default) ? ' selected' : '';
            $html .= '<option value="' . esc_attr($value) . '"' . $selected . '>' . esc_html($optLabel) . '</option>';
        }

        $html .= '</select></div>';

        return $html;
    }

    /**
     * Render multi-select control
     *
     * @param array $config  Multi-select config (id, name, label, options, default_value)
     * @param array $context Runtime context
     * @return string HTML
     */
    public static function render_multi_select($config, $context)
    {
        $id       = $config['id'] ?? 'multi-select-' . wp_rand();
        $name     = $config['name'] ?? $id;
        $label    = $config['label'] ?? '';
        $options  = $config['options'] ?? [];
        $selected = (array) ($config['default_value'] ?? []);

        $html = '<div class="rawwire-control rawwire-control-multi-select">';
        if ($label) {
            $html .= '<label>' . esc_html($label) . '</label>';
        }
        $html .= '<select id="' . esc_attr($id) . '" name="' . esc_attr($name) . '[]" class="widefat" multiple style="height:120px;">';

        foreach ($options as $option) {
            $value    = is_array($option) ? ($option['value'] ?? '') : $option;
            $optLabel = is_array($option) ? ($option['label'] ?? $value) : $option;
            $sel      = in_array($value, $selected) ? ' selected' : '';
            $html .= '<option value="' . esc_attr($value) . '"' . $sel . '>' . esc_html($optLabel) . '</option>';
        }

        $html .= '</select></div>';

        return $html;
    }

    /**
     * Render text input control
     *
     * @param array $config  Text input config (id, name, label, default_value, placeholder)
     * @param array $context Runtime context
     * @return string HTML
     */
    public static function render_text_input($config, $context)
    {
        $id          = $config['id'] ?? 'text-' . wp_rand();
        $name        = $config['name'] ?? $id;
        $label       = $config['label'] ?? '';
        $value       = $config['default_value'] ?? '';
        $placeholder = $config['placeholder'] ?? '';

        $html = '<div class="rawwire-control rawwire-control-text-input">';
        if ($label) {
            $html .= '<label for="' . esc_attr($id) . '">' . esc_html($label) . '</label>';
        }
        $html .= '<input type="text" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" class="widefat"';
        $html .= ' value="' . esc_attr($value) . '" placeholder="' . esc_attr($placeholder) . '">';
        $html .= '</div>';

        return $html;
    }

    /**
     * Render checkbox control
     *
     * @param array $config  Checkbox config (id, name, label, default_value)
     * @param array $context Runtime context
     * @return string HTML
     */
    public static function render_checkbox($config, $context)
    {
        $id      = $config['id'] ?? 'checkbox-' . wp_rand();
        $name    = $config['name'] ?? $id;
        $label   = $config['label'] ?? '';
        $checked = !empty($config['default_value']);

        $html  = '<div class="rawwire-control rawwire-control-checkbox">';
        $html .= '<label>';
        $html .= '<input type="checkbox" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="1"';
        if ($checked) $html .= ' checked';
        $html .= '> ';
        $html .= esc_html($label);
        $html .= '</label></div>';

        return $html;
    }

    /**
     * Render date picker control
     *
     * @param array $config  Date picker config (id, name, label, default_value)
     * @param array $context Runtime context
     * @return string HTML
     */
    public static function render_date_picker($config, $context)
    {
        $id    = $config['id'] ?? 'date-' . wp_rand();
        $name  = $config['name'] ?? $id;
        $label = $config['label'] ?? '';
        $value = $config['default_value'] ?? '';

        $html = '<div class="rawwire-control rawwire-control-date-picker">';
        if ($label) {
            $html .= '<label for="' . esc_attr($id) . '">' . esc_html($label) . '</label>';
        }
        $html .= '<input type="date" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" class="widefat"';
        $html .= ' value="' . esc_attr($value) . '">';
        $html .= '</div>';

        return $html;
    }
}
