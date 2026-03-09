<?php

/**
 * Base Control - Shared utilities for all control renderers
 *
 * Provides common helpers used across control categories:
 * - Data attribute building
 * - Condition checking (visibility, disabled states)
 * - CSS class building
 * - Fallback rendering for unknown types
 *
 * @package RawWire_Dashboard
 * @subpackage Template_Engine\Controls
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class RawWire_Control_Base
{

    /**
     * Check visibility/enable conditions
     *
     * Evaluates capability, option, data-field, and custom callback conditions.
     *
     * @param array $conditions Condition config from template JSON
     * @param array $context    Runtime context (item data, panel config, etc.)
     * @return bool True if all conditions pass
     */
    public static function check_conditions(array $conditions, array $context)
    {
        if (empty($conditions)) {
            return true;
        }

        // WordPress capability check
        if (!empty($conditions['capability'])) {
            if (!current_user_can($conditions['capability'])) {
                return false;
            }
        }

        // WordPress option equals
        if (!empty($conditions['option_equals'])) {
            foreach ($conditions['option_equals'] as $option => $expected) {
                if (get_option($option) != $expected) {
                    return false;
                }
            }
        }

        // Data field equals (row-level context)
        if (!empty($conditions['data_field_equals']) && !empty($context['item'])) {
            foreach ($conditions['data_field_equals'] as $field => $expected) {
                $value = $context['item'][$field] ?? null;
                if ($value != $expected) {
                    return false;
                }
            }
        }

        // Data field exists
        if (!empty($conditions['data_exists']) && !empty($context['item'])) {
            $field = $conditions['data_exists'];
            if (empty($context['item'][$field])) {
                return false;
            }
        }

        // Custom callable
        if (!empty($conditions['custom']) && is_callable($conditions['custom'])) {
            if (!call_user_func($conditions['custom'], $context)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Build HTML data-* attributes string
     *
     * @param array $attrs Key-value pairs (keys without 'data-' prefix)
     * @return string HTML attribute string
     */
    public static function build_data_attrs(array $attrs)
    {
        $html = '';
        foreach ($attrs as $key => $value) {
            if ($value !== '' && $value !== null) {
                $html .= ' data-' . esc_attr($key) . '="' . esc_attr($value) . '"';
            }
        }
        return $html;
    }

    /**
     * Merge control config with defaults
     *
     * @param array $config  Control configuration from template
     * @param array $defaults Default values
     * @return array Merged config
     */
    public static function merge_defaults(array $config, array $defaults = [])
    {
        $base_defaults = [
            'id'        => '',
            'label'     => '',
            'icon'      => '',
            'css_class' => '',
            'style'     => 'secondary',
            'size'      => 'medium',
        ];
        return wp_parse_args($config, wp_parse_args($defaults, $base_defaults));
    }

    /**
     * Render a fallback control for unknown/unregistered types
     *
     * Ensures the UI never breaks due to missing control types.
     * Renders as a basic button with a tooltip warning.
     *
     * @param array $config  Control configuration
     * @param array $context Additional context
     * @return string HTML
     */
    public static function render_fallback(array $config, array $context = [])
    {
        $label  = $config['label'] ?? 'Action';
        $icon   = $config['icon'] ?? '';
        $style  = $config['style'] ?? 'secondary';
        $action = $config['action'] ?? '';
        $id     = $config['id'] ?? 'control-' . wp_rand();

        $css_class = 'rawwire-btn rawwire-btn-' . esc_attr($style) . ' rawwire-control-fallback';

        $html  = '<button type="button" class="' . $css_class . '"';
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
}
