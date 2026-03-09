<?php

/**
 * Action Controls - Button, Link, Refresh, Dropdown
 *
 * Interactive elements that trigger actions (AJAX calls, navigation, etc.)
 *
 * @package RawWire_Dashboard
 * @subpackage Template_Engine\Controls
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class RawWire_Action_Controls
{

    /**
     * Register all action controls with the registry
     *
     * @param RawWire_Control_Registry $registry
     */
    public static function register(RawWire_Control_Registry $registry)
    {
        $registry->register('button', [
            'label'    => 'Button',
            'icon'     => 'dashicons-button',
            'category' => 'action',
            'renderer' => [__CLASS__, 'render_button'],
        ]);

        $registry->register('link', [
            'label'    => 'Link',
            'icon'     => 'dashicons-admin-links',
            'category' => 'action',
            'renderer' => [__CLASS__, 'render_link'],
        ]);

        $registry->register('refresh', [
            'label'    => 'Refresh Button',
            'icon'     => 'dashicons-update',
            'category' => 'action',
            'renderer' => [__CLASS__, 'render_refresh'],
        ]);

        $registry->register('dropdown', [
            'label'    => 'Dropdown Menu',
            'icon'     => 'dashicons-arrow-down-alt2',
            'category' => 'action',
            'renderer' => [__CLASS__, 'render_dropdown'],
        ]);
    }

    // ==================================================================
    // Renderers
    // ==================================================================

    /**
     * Render button control
     *
     * @param array $config  Button config (id, label, icon, action, action_type, style, size, confirm, css_class)
     * @param array $context Runtime context
     * @return string HTML
     */
    public static function render_button($config, $context)
    {
        $id          = $config['id'] ?? '';
        $label       = $config['label'] ?? '';
        $icon        = $config['icon'] ?? '';
        $action      = $config['action'] ?? '';
        $action_type = $config['action_type'] ?? 'ajax';
        $style       = $config['style'] ?? 'secondary';
        $size        = $config['size'] ?? 'medium';
        $confirm     = $config['confirm'] ?? '';
        $css_class   = $config['css_class'] ?? '';
        $disabled    = !RawWire_Control_Base::check_conditions($config['disabled_conditions'] ?? [], $context);

        // Build class list
        $classes = ['button', 'rawwire-control', 'rawwire-control-button'];
        if ($style === 'primary') $classes[] = 'button-primary';
        if ($style === 'danger')  $classes[] = 'button-link-delete';
        if ($style === 'link')    $classes[] = 'button-link';
        if ($size === 'small')    $classes[] = 'button-small';
        if ($size === 'large')    $classes[] = 'button-large';
        if ($css_class)           $classes[] = $css_class;

        // Data attributes
        $data_attrs = [
            'action'      => $action,
            'action-type' => $action_type,
        ];

        // Add item ID if in row context
        if (!empty($context['item']['id'])) {
            $data_attrs['item-id'] = $context['item']['id'];
        }

        if ($confirm) {
            $data_attrs['confirm'] = $confirm;
        }

        $data_html = RawWire_Control_Base::build_data_attrs($data_attrs);

        $html  = '<button type="button" class="' . esc_attr(implode(' ', $classes)) . '"';
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
     *
     * @param array $config  Link config (label, href, icon, css_class)
     * @param array $context Runtime context (item data for placeholder replacement)
     * @return string HTML
     */
    public static function render_link($config, $context)
    {
        $label     = $config['label'] ?? '';
        $href      = $config['href'] ?? '#';
        $icon      = $config['icon'] ?? '';
        $css_class = $config['css_class'] ?? '';

        // Replace {{field}} placeholders in href
        if (!empty($context['item'])) {
            foreach ($context['item'] as $key => $value) {
                if (is_scalar($value)) {
                    $href = str_replace('{{' . $key . '}}', urlencode($value), $href);
                }
            }
        }

        $html  = '<a href="' . esc_url($href) . '" class="rawwire-control rawwire-control-link ' . esc_attr($css_class) . '">';

        if ($icon) {
            $html .= '<span class="dashicons ' . esc_attr($icon) . '"></span> ';
        }

        $html .= esc_html($label);
        $html .= '</a>';

        return $html;
    }

    /**
     * Render refresh button (delegates to render_button with defaults)
     *
     * @param array $config  Refresh config
     * @param array $context Runtime context
     * @return string HTML
     */
    public static function render_refresh($config, $context)
    {
        $config = wp_parse_args($config, [
            'label'   => '',
            'icon'    => 'dashicons-update',
            'action'  => 'refresh_panel',
            'tooltip' => __('Refresh', 'raw-wire-dashboard'),
        ]);

        return self::render_button($config, $context);
    }

    /**
     * Render dropdown menu control
     *
     * @param array $config  Dropdown config (label, icon, options[])
     * @param array $context Runtime context
     * @return string HTML
     */
    public static function render_dropdown($config, $context)
    {
        $id      = $config['id'] ?? 'dropdown-' . wp_rand();
        $label   = $config['label'] ?? '';
        $icon    = $config['icon'] ?? 'dashicons-arrow-down-alt2';
        $options = $config['options'] ?? [];

        $html  = '<div class="rawwire-control rawwire-control-dropdown" id="' . esc_attr($id) . '">';
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
            $html .= '<a href="#" class="rawwire-dropdown-item"';
            $html .= ' data-action="' . esc_attr($option['action'] ?? '') . '"';
            $html .= ' data-value="' . esc_attr($option['value'] ?? '') . '"';
            $html .= ' style="display:block;padding:8px 16px;text-decoration:none;color:#333;">';
            if (!empty($option['icon'])) {
                $html .= '<span class="dashicons ' . esc_attr($option['icon']) . '"></span> ';
            }
            $html .= esc_html($option['label'] ?? $option['value']);
            $html .= '</a></li>';
        }
        $html .= '</ul></div>';

        return $html;
    }
}
