<?php
/**
 * Panel Renderer - Renders template panels dynamically
 * Path: cores/template-engine/panel-renderer.php
 *
 * Handles rendering of all panel types:
 * - status: Metric displays, statistics
 * - control: Buttons, toggles, inputs
 * - data: Tables, lists, grids
 * - settings: Form-based configuration
 * - custom: Custom script execution
 */

if (!class_exists('RawWire_Panel_Renderer')) {
    class RawWire_Panel_Renderer {

        /**
         * Registered custom panel renderers
         * @var array
         */
        protected static $custom_renderers = array();

        /**
         * Render a panel based on its configuration
         * @param array $panel Panel configuration from template
         * @param array $context Additional context data
         * @return string HTML output
         */
        public static function render($panel, $context = array()) {
            if (!is_array($panel) || !isset($panel['type'])) {
                return '';
            }

            $type = $panel['type'];
            $panel_id = $panel['id'] ?? 'panel-' . uniqid();

            // Build panel wrapper
            $css_classes = array('rawwire-panel', 'rawwire-panel-' . $type);
            $css_style = '';

            if (isset($panel['css'])) {
                foreach ($panel['css'] as $prop => $value) {
                    $css_style .= self::camel_to_kebab($prop) . ': ' . esc_attr($value) . '; ';
                }
            }

            ob_start();
            ?>
            <div class="<?php echo esc_attr(implode(' ', $css_classes)); ?>" 
                 data-panel="<?php echo esc_attr($panel_id); ?>"
                 data-panel-type="<?php echo esc_attr($type); ?>"
                 <?php if ($css_style): ?>style="<?php echo esc_attr(trim($css_style)); ?>"<?php endif; ?>>
                
                <?php self::render_panel_header($panel); ?>
                
                <div class="rawwire-panel-body">
                    <?php
                    switch ($type) {
                        case 'status':
                            self::render_status_panel($panel, $context);
                            break;
                        case 'control':
                            self::render_control_panel($panel, $context);
                            break;
                        case 'data':
                            self::render_data_panel($panel, $context);
                            break;
                        case 'settings':
                            self::render_settings_panel($panel, $context);
                            break;
                        case 'custom':
                            self::render_custom_panel($panel, $context);
                            break;
                        case 'log':
                            self::render_log_panel($panel, $context);
                            break;
                        default:
                            // Check for registered custom renderer
                            if (isset(self::$custom_renderers[$type])) {
                                call_user_func(self::$custom_renderers[$type], $panel, $context);
                            }
                            break;
                    }
                    ?>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }

        /**
         * Render panel header
         */
        protected static function render_panel_header($panel) {
            $title = $panel['title'] ?? '';
            $icon = $panel['icon'] ?? '';
            $refresh = isset($panel['refreshInterval']) && $panel['refreshInterval'] > 0;
            ?>
            <div class="rawwire-panel-header">
                <h3>
                    <?php if ($icon): ?>
                        <span class="dashicons <?php echo esc_attr($icon); ?>"></span>
                    <?php endif; ?>
                    <?php echo esc_html($title); ?>
                </h3>
                <?php if ($refresh): ?>
                    <span class="rawwire-refresh-indicator" data-interval="<?php echo intval($panel['refreshInterval']); ?>">
                        <span class="dashicons dashicons-update"></span>
                    </span>
                <?php endif; ?>
            </div>
            <?php
        }

        /**
         * Render STATUS panel (metrics, statistics)
         */
        protected static function render_status_panel($panel, $context) {
            $metrics = $panel['metrics'] ?? array();
            $actions = $panel['actions'] ?? array();
            $layout = $panel['layout'] ?? 'grid-4col';
            $is_horizontal = ($layout === 'horizontal');
            ?>
            <div class="rawwire-stats-bar <?php echo $is_horizontal ? 'rawwire-horizontal' : 'rawwire-grid rawwire-' . esc_attr($layout); ?>">
                <?php foreach ($metrics as $metric): ?>
                    <?php
                    $value = self::resolve_data_binding($metric['source'] ?? '', $context);
                    $highlight = $metric['highlight'] ?? '';
                    $highlight_class = $highlight ? 'rawwire-highlight-' . $highlight : '';
                    ?>
                    <div class="rawwire-stat-card <?php echo esc_attr($highlight_class); ?>">
                        <?php if (isset($metric['icon'])): ?>
                            <span class="dashicons <?php echo esc_attr($metric['icon']); ?>"></span>
                        <?php endif; ?>
                        <h3 data-metric="<?php echo esc_attr($metric['id'] ?? ''); ?>">
                            <?php echo esc_html(self::format_value($value, $metric['format'] ?? 'text')); ?>
                        </h3>
                        <p><?php echo esc_html($metric['label'] ?? ''); ?></p>
                    </div>
                <?php endforeach; ?>
                
                <?php if (!empty($actions)): ?>
                    <div class="rawwire-stats-actions">
                        <?php foreach ($actions as $action): ?>
                            <?php
                            $style = $action['style'] ?? 'secondary';
                            $confirm = isset($action['confirm']) ? 'data-confirm="' . esc_attr($action['confirm']) . '"' : '';
                            ?>
                            <button type="button" 
                                    class="rawwire-btn rawwire-btn-<?php echo esc_attr($style); ?>"
                                    data-action="<?php echo esc_attr($action['action'] ?? ''); ?>"
                                    <?php echo $confirm; ?>>
                                <?php if (isset($action['icon'])): ?>
                                    <span class="dashicons <?php echo esc_attr($action['icon']); ?>"></span>
                                <?php endif; ?>
                                <?php echo esc_html($action['label'] ?? ''); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php
        }

        /**
         * Render CONTROL panel (buttons, toggles, inputs)
         */
        protected static function render_control_panel($panel, $context) {
            $controls = $panel['controls'] ?? array();
            $data_source = $panel['dataSource'] ?? '';
            $items = array();

            if ($data_source) {
                $items = self::resolve_data_binding($data_source, $context);
            }
            ?>
            <div class="rawwire-controls">
                <?php foreach ($controls as $control): ?>
                    <?php self::render_control($control, $context); ?>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($items) && isset($panel['itemTemplate'])): ?>
                <div class="rawwire-items-list">
                    <?php foreach ($items as $item): ?>
                        <div class="rawwire-item" data-item-id="<?php echo esc_attr($item['id'] ?? ''); ?>">
                            <?php self::render_item_from_template($item, $panel['itemTemplate']); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php
        }

        /**
         * Render a single control element
         */
        protected static function render_control($control, $context) {
            $type = $control['type'] ?? 'button';
            $id = $control['id'] ?? 'control-' . uniqid();
            $label = $control['label'] ?? '';
            $icon = $control['icon'] ?? '';
            $action = $control['action'] ?? '';
            $style = $control['style'] ?? 'secondary';
            $confirm = $control['confirm'] ?? '';

            switch ($type) {
                case 'button':
                    ?>
                    <button type="button" 
                            class="rawwire-btn rawwire-btn-<?php echo esc_attr($style); ?>"
                            data-control="<?php echo esc_attr($id); ?>"
                            data-action="<?php echo esc_attr($action); ?>"
                            <?php if ($confirm): ?>data-confirm="<?php echo esc_attr($confirm); ?>"<?php endif; ?>>
                        <?php if ($icon): ?>
                            <span class="dashicons <?php echo esc_attr($icon); ?>"></span>
                        <?php endif; ?>
                        <?php echo esc_html($label); ?>
                    </button>
                    <?php
                    break;

                case 'toggle':
                    $binding = $control['binding'] ?? '';
                    $value = self::resolve_data_binding($binding, $context);
                    $checked = $value || ($control['default'] ?? false);
                    ?>
                    <label class="rawwire-toggle">
                        <input type="checkbox" 
                               data-control="<?php echo esc_attr($id); ?>"
                               data-binding="<?php echo esc_attr($binding); ?>"
                               <?php checked($checked); ?>>
                        <span><?php echo esc_html($label); ?></span>
                    </label>
                    <?php if (isset($control['description'])): ?>
                        <p class="rawwire-form-description"><?php echo esc_html($control['description']); ?></p>
                    <?php endif; ?>
                    <?php
                    break;

                case 'number':
                    $binding = $control['binding'] ?? '';
                    $value = self::resolve_data_binding($binding, $context);
                    $value = $value !== null ? $value : ($control['default'] ?? 0);
                    $min = $control['min'] ?? 0;
                    $max = $control['max'] ?? 100;
                    ?>
                    <div class="rawwire-form-group">
                        <label><?php echo esc_html($label); ?></label>
                        <input type="number" 
                               data-control="<?php echo esc_attr($id); ?>"
                               data-binding="<?php echo esc_attr($binding); ?>"
                               value="<?php echo esc_attr($value); ?>"
                               min="<?php echo esc_attr($min); ?>"
                               max="<?php echo esc_attr($max); ?>">
                    </div>
                    <?php
                    break;

                case 'select':
                    $binding = $control['binding'] ?? '';
                    $value = self::resolve_data_binding($binding, $context);
                    $value = $value !== null ? $value : ($control['default'] ?? '');
                    $options = $control['options'] ?? array();
                    $show_if = $control['showIf'] ?? null;
                    $visibility_attr = '';
                    if ($show_if) {
                        $visibility_attr = sprintf(
                            'data-show-if-field="%s" data-show-if-value="%s"',
                            esc_attr($show_if['field'] ?? ''),
                            esc_attr($show_if['value'] ?? '')
                        );
                    }
                    ?>
                    <div class="rawwire-form-group" <?php echo $visibility_attr; ?>>
                        <label><?php echo esc_html($label); ?></label>
                        <select data-control="<?php echo esc_attr($id); ?>"
                                name="<?php echo esc_attr($binding); ?>"
                                data-binding="<?php echo esc_attr($binding); ?>">
                            <?php foreach ($options as $opt): ?>
                                <?php 
                                // Handle both {value, label} objects and simple strings
                                if (is_array($opt)) {
                                    $opt_value = $opt['value'] ?? '';
                                    $opt_label = $opt['label'] ?? $opt_value;
                                } else {
                                    $opt_value = $opt;
                                    $opt_label = $opt;
                                }
                                ?>
                                <option value="<?php echo esc_attr($opt_value); ?>" 
                                        <?php selected($value, $opt_value); ?>>
                                    <?php echo esc_html($opt_label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php
                    break;

                case 'slider':
                    $binding = $control['binding'] ?? '';
                    $value = self::resolve_data_binding($binding, $context);
                    $value = $value !== null ? $value : ($control['default'] ?? 10);
                    $min = $control['min'] ?? 1;
                    $max = $control['max'] ?? 100;
                    $step = $control['step'] ?? 1;
                    $show_value = $control['showValue'] ?? false;
                    ?>
                    <div class="rawwire-form-group rawwire-slider-group">
                        <label><?php echo esc_html($label); ?>
                            <?php if ($show_value): ?>
                                <span class="rawwire-slider-value"><?php echo esc_html($value); ?></span>
                            <?php endif; ?>
                        </label>
                        <input type="range" 
                               data-control="<?php echo esc_attr($id); ?>"
                               name="<?php echo esc_attr($binding); ?>"
                               data-binding="<?php echo esc_attr($binding); ?>"
                               value="<?php echo esc_attr($value); ?>"
                               min="<?php echo esc_attr($min); ?>"
                               max="<?php echo esc_attr($max); ?>"
                               step="<?php echo esc_attr($step); ?>"
                               class="rawwire-slider">
                    </div>
                    <?php
                    break;

                case 'text':
                    $binding = $control['binding'] ?? '';
                    $value = self::resolve_data_binding($binding, $context);
                    $value = $value !== null ? $value : ($control['default'] ?? '');
                    $placeholder = $control['placeholder'] ?? '';
                    $show_if = $control['showIf'] ?? null;
                    $visibility_attr = '';
                    $visibility_style = '';
                    if ($show_if) {
                        $visibility_attr = sprintf(
                            'data-show-if-field="%s" data-show-if-value="%s"',
                            esc_attr($show_if['field'] ?? ''),
                            esc_attr($show_if['value'] ?? '')
                        );
                        // Start hidden by default if conditional
                        $visibility_style = 'style="display: none;"';
                    }
                    ?>
                    <div class="rawwire-form-group" <?php echo $visibility_attr; ?> <?php echo $visibility_style; ?>>
                        <label><?php echo esc_html($label); ?></label>
                        <input type="text" 
                               data-control="<?php echo esc_attr($id); ?>"
                               name="<?php echo esc_attr($binding); ?>"
                               data-binding="<?php echo esc_attr($binding); ?>"
                               value="<?php echo esc_attr($value); ?>"
                               placeholder="<?php echo esc_attr($placeholder); ?>">
                    </div>
                    <?php
                    break;
            }
        }

        /**
         * Render DATA panel (tables, lists, cards)
         */
        protected static function render_data_panel($panel, $context) {
            $layout = $panel['layout'] ?? 'table';
            $data_source = $panel['dataSource'] ?? '';
            $items = self::resolve_data_binding($data_source, $context);
            $empty_message = $panel['emptyMessage'] ?? 'No items found';
            $max_items = $panel['maxItems'] ?? 0;

            if ($max_items > 0 && is_array($items)) {
                $items = array_slice($items, 0, $max_items);
            }

            if (empty($items)) {
                echo '<p class="rawwire-empty-message">' . esc_html($empty_message) . '</p>';
                return;
            }

            switch ($layout) {
                case 'table':
                    self::render_data_table($panel, $items);
                    break;
                case 'cards':
                    self::render_data_cards($panel, $items);
                    break;
                case 'list':
                    self::render_data_list($panel, $items);
                    break;
            }
        }

        /**
         * Render data as table
         */
        protected static function render_data_table($panel, $items) {
            $columns = $panel['columns'] ?? array();
            $item_actions = $panel['itemActions'] ?? array();
            ?>
            <table class="rawwire-table">
                <thead>
                    <tr>
                        <?php foreach ($columns as $col): ?>
                            <th style="width: <?php echo esc_attr($col['width'] ?? 'auto'); ?>">
                                <?php echo esc_html($col['label'] ?? ''); ?>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr data-item-id="<?php echo esc_attr($item['id'] ?? ''); ?>">
                            <?php foreach ($columns as $col): ?>
                                <td>
                                    <?php
                                    $field = $col['field'] ?? '';
                                    $render = $col['render'] ?? 'text';
                                    $value = $item[$field] ?? '';

                                    if ($field === 'actions') {
                                        self::render_item_actions($item, $item_actions);
                                    } else {
                                        echo self::render_cell_value($value, $render, $item);
                                    }
                                    ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php
        }

        /**
         * Render data as cards
         */
        protected static function render_data_cards($panel, $items) {
            $template = $panel['cardTemplate'] ?? array();
            $bulk_actions = $panel['bulkActions'] ?? array();
            $sort_options = $panel['sortOptions'] ?? array();
            $filter_options = $panel['filterOptions'] ?? array();
            ?>
            <?php if (!empty($sort_options) || !empty($filter_options)): ?>
                <div class="rawwire-card-toolbar">
                    <?php if (!empty($sort_options)): ?>
                        <select class="rawwire-sort-select" data-panel="<?php echo esc_attr($panel['id']); ?>">
                            <?php foreach ($sort_options as $opt): ?>
                                <option value="<?php echo esc_attr($opt['field'] . ':' . $opt['direction']); ?>">
                                    <?php echo esc_html($opt['label']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>

                    <?php if (!empty($filter_options)): ?>
                        <?php foreach ($filter_options as $filter): ?>
                            <select class="rawwire-filter-select" 
                                    data-field="<?php echo esc_attr($filter['field']); ?>">
                                <option value=""><?php echo esc_html($filter['label']); ?>: All</option>
                                <?php 
                                $options = $filter['options'] ?? array();
                                if (is_string($options) && strpos($options, 'template:') === 0) {
                                    // Resolve from template
                                    $options = self::resolve_data_binding($options, array());
                                }
                                foreach ($options as $opt):
                                    $opt_value = is_array($opt) ? ($opt['id'] ?? $opt['label'] ?? '') : $opt;
                                    $opt_label = is_array($opt) ? ($opt['label'] ?? $opt_value) : $opt;
                                ?>
                                    <option value="<?php echo esc_attr($opt_value); ?>">
                                        <?php echo esc_html($opt_label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if (!empty($bulk_actions)): ?>
                        <div class="rawwire-bulk-actions">
                            <?php foreach ($bulk_actions as $action): ?>
                                <button type="button" class="rawwire-btn rawwire-btn-secondary"
                                        data-bulk-action="<?php echo esc_attr($action); ?>">
                                    <?php echo esc_html(ucwords(str_replace('_', ' ', $action))); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="rawwire-cards-grid">
                <?php foreach ($items as $item): ?>
                    <div class="rawwire-card" data-item-id="<?php echo esc_attr($item['id'] ?? ''); ?>">
                        <?php if (isset($template['header'])): ?>
                            <div class="rawwire-card-header">
                                <?php echo esc_html(self::interpolate_template($template['header'], $item)); ?>
                            </div>
                        <?php endif; ?>

                        <?php if (isset($template['image']) && !empty($item[$template['image']])): ?>
                            <div class="rawwire-card-image">
                                <img src="<?php echo esc_url($item[$template['image']]); ?>" alt="">
                            </div>
                        <?php endif; ?>

                        <?php if (isset($template['title'])): ?>
                            <div class="rawwire-card-title">
                                <?php echo esc_html(self::interpolate_template($template['title'], $item)); ?>
                            </div>
                        <?php endif; ?>

                        <?php if (isset($template['body'])): ?>
                            <div class="rawwire-card-body">
                                <?php echo wp_kses_post(self::interpolate_template($template['body'], $item)); ?>
                            </div>
                        <?php endif; ?>

                        <?php if (isset($template['expandable']) && $template['expandable']): ?>
                            <div class="rawwire-card-expand">
                                <button type="button" class="rawwire-btn rawwire-btn-secondary rawwire-expand-btn">
                                    <span class="dashicons dashicons-arrow-down-alt2"></span>
                                    <span class="rawwire-expand-text">Show More</span>
                                </button>
                                <div class="rawwire-card-expanded" style="display: none;">
                                    <?php 
                                    if (isset($template['expandedContent'])) {
                                        echo wp_kses_post(self::interpolate_template($template['expandedContent'], $item));
                                    }
                                    ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (isset($template['footer'])): ?>
                            <div class="rawwire-card-footer">
                                <span><?php echo esc_html(self::interpolate_template($template['footer'], $item)); ?></span>
                                <div class="rawwire-card-actions">
                                    <?php
                                    // Get actions from page config
                                    $page_actions = $panel['_page_actions'] ?? array();
                                    foreach ($page_actions as $action_id => $action):
                                        $btn_style = $action['style'] ?? 'secondary';
                                    ?>
                                        <button type="button" 
                                                class="rawwire-btn rawwire-btn-<?php echo esc_attr($btn_style); ?>"
                                                data-action="<?php echo esc_attr($action['action'] ?? $action_id); ?>"
                                                data-item-id="<?php echo esc_attr($item['id'] ?? ''); ?>">
                                            <?php if (isset($action['icon'])): ?>
                                                <span class="dashicons <?php echo esc_attr($action['icon']); ?>"></span>
                                            <?php endif; ?>
                                            <?php echo esc_html($action['label'] ?? ''); ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php
        }

        /**
         * Render data as list
         */
        protected static function render_data_list($panel, $items) {
            ?>
            <ul class="rawwire-list">
                <?php foreach ($items as $item): ?>
                    <li data-item-id="<?php echo esc_attr($item['id'] ?? ''); ?>">
                        <?php echo esc_html($item['title'] ?? $item['label'] ?? ''); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php
        }

        /**
         * Render SETTINGS panel (forms)
         */
        protected static function render_settings_panel($panel, $context) {
            $sections = $panel['sections'] ?? array();
            $fields = $panel['fields'] ?? array();
            $binding = $panel['binding'] ?? '';

            if (!empty($sections)) {
                // Render tabbed sections
                ?>
                <div class="rawwire-settings-tabs">
                    <div class="rawwire-tabs-nav">
                        <?php foreach ($sections as $i => $section): ?>
                            <button type="button" 
                                    class="rawwire-tab-btn <?php echo $i === 0 ? 'active' : ''; ?>"
                                    data-tab="<?php echo esc_attr($section['id']); ?>">
                                <?php echo esc_html($section['title']); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <div class="rawwire-tabs-content">
                        <?php foreach ($sections as $i => $section): ?>
                            <div class="rawwire-tab-panel <?php echo $i === 0 ? 'active' : ''; ?>"
                                 data-tab-content="<?php echo esc_attr($section['id']); ?>">
                                <?php
                                // Render toolkit config form if binding is toolbox
                                if (isset($section['binding']) && strpos($section['binding'], 'toolbox:') === 0) {
                                    $toolbox_type = str_replace('toolbox:', '', $section['binding']);
                                    self::render_toolbox_section($toolbox_type, $context);
                                }
                                ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php
            } elseif (!empty($fields)) {
                // Render flat form
                self::render_settings_form($fields, $binding, $context);
            }
        }

        /**
         * Render settings form fields
         */
        protected static function render_settings_form($fields, $binding, $context) {
            ?>
            <form class="rawwire-settings-form" data-binding="<?php echo esc_attr($binding); ?>">
                <?php foreach ($fields as $field): ?>
                    <?php
                    $field_binding = $binding ? $binding . '.' . $field['key'] : $field['key'];
                    $value = self::resolve_data_binding($field_binding, $context);
                    $value = $value !== null ? $value : ($field['default'] ?? '');
                    ?>
                    <div class="rawwire-form-group">
                        <label for="<?php echo esc_attr($field['key']); ?>">
                            <?php echo esc_html($field['label']); ?>
                        </label>
                        <?php
                        switch ($field['type'] ?? 'text') {
                            case 'select':
                                ?>
                                <select name="<?php echo esc_attr($field['key']); ?>"
                                        id="<?php echo esc_attr($field['key']); ?>">
                                    <?php foreach ($field['options'] as $opt): ?>
                                        <option value="<?php echo esc_attr($opt); ?>" 
                                                <?php selected($value, $opt); ?>>
                                            <?php echo esc_html(ucfirst($opt)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php
                                break;

                            case 'checkbox':
                                ?>
                                <input type="checkbox" 
                                       name="<?php echo esc_attr($field['key']); ?>"
                                       id="<?php echo esc_attr($field['key']); ?>"
                                       value="1"
                                       <?php checked($value); ?>>
                                <?php
                                break;

                            case 'textarea':
                                ?>
                                <textarea name="<?php echo esc_attr($field['key']); ?>"
                                          id="<?php echo esc_attr($field['key']); ?>"
                                          rows="4"><?php echo esc_textarea($value); ?></textarea>
                                <?php
                                break;

                            default:
                                ?>
                                <input type="<?php echo esc_attr($field['type'] ?? 'text'); ?>"
                                       name="<?php echo esc_attr($field['key']); ?>"
                                       id="<?php echo esc_attr($field['key']); ?>"
                                       value="<?php echo esc_attr($value); ?>">
                                <?php
                                break;
                        }
                        ?>
                        <?php if (isset($field['description'])): ?>
                            <p class="rawwire-form-description"><?php echo esc_html($field['description']); ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <div class="rawwire-form-actions">
                    <button type="submit" class="rawwire-btn rawwire-btn-primary">Save Settings</button>
                </div>
            </form>
            <?php
        }

        /**
         * Render toolbox configuration section
         */
        protected static function render_toolbox_section($type, $context) {
            if (!class_exists('RawWire_Toolbox_Core')) {
                echo '<p>Toolbox Core not available</p>';
                return;
            }

            $registry = RawWire_Toolbox_Core::get_registry();
            $category = $registry['categories'][$type] ?? null;

            if (!$category) {
                echo '<p>Unknown toolbox category: ' . esc_html($type) . '</p>';
                return;
            }

            $adapters = $category['adapters'] ?? array();
            $saved_config = get_option('rawwire_toolkit_' . $type, array());
            $selected_adapter = $saved_config['adapter_id'] ?? '';
            ?>
            <div class="rawwire-toolbox-config" data-category="<?php echo esc_attr($type); ?>">
                <p class="rawwire-form-description"><?php echo esc_html($category['description'] ?? ''); ?></p>

                <div class="rawwire-adapter-selector">
                    <?php foreach ($adapters as $adapter_id => $adapter): ?>
                        <?php
                        $tier = $adapter['tier'] ?? 'free';
                        $tier_class = 'rawwire-tier-' . $tier;
                        $is_selected = $adapter_id === $selected_adapter;
                        ?>
                        <div class="rawwire-adapter-option <?php echo $is_selected ? 'selected' : ''; ?> <?php echo esc_attr($tier_class); ?>"
                             data-adapter="<?php echo esc_attr($adapter_id); ?>">
                            <div class="rawwire-adapter-header">
                                <strong><?php echo esc_html($adapter['label'] ?? $adapter_id); ?></strong>
                                <span class="rawwire-tier-badge"><?php echo esc_html(ucfirst($tier)); ?></span>
                            </div>
                            <p><?php echo esc_html($adapter['description'] ?? ''); ?></p>
                            <button type="button" class="rawwire-btn rawwire-btn-secondary rawwire-configure-adapter"
                                    data-adapter="<?php echo esc_attr($adapter_id); ?>">
                                <?php echo $is_selected ? 'Configure' : 'Select'; ?>
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php
        }

        /**
         * Render CUSTOM panel (script execution or PHP callback)
         */
        protected static function render_custom_panel($panel, $context) {
            // Check if there's a PHP renderer callback
            if (isset($panel['renderer']) && !empty($panel['renderer'])) {
                $renderer = $panel['renderer'];
                
                // Support "Class::method" string format
                if (is_string($renderer) && strpos($renderer, '::') !== false) {
                    list($class, $method) = explode('::', $renderer);
                    if (class_exists($class) && method_exists($class, $method)) {
                        call_user_func(array($class, $method), $panel, $context);
                        return;
                    }
                }
                
                // Support callable arrays and closures
                if (is_callable($renderer)) {
                    call_user_func($renderer, $panel, $context);
                    return;
                }
            }
            
            // Fall back to script-based rendering
            $script = $panel['script'] ?? array();
            $script_type = $script['type'] ?? 'inline';
            ?>
            <div class="rawwire-custom-panel" data-script-type="<?php echo esc_attr($script_type); ?>">
                <?php if (isset($panel['description'])): ?>
                    <p class="rawwire-form-description"><?php echo esc_html($panel['description']); ?></p>
                <?php endif; ?>

                <?php if ($script_type === 'inline' && isset($script['code'])): ?>
                    <script type="text/javascript">
                        (function() {
                            <?php echo $script['code']; ?>
                        })();
                    </script>
                <?php endif; ?>

                <div class="rawwire-custom-content" id="custom-panel-<?php echo esc_attr($panel['id'] ?? uniqid()); ?>">
                    <!-- Custom content rendered by script -->
                </div>
            </div>
            <?php
        }

        /**
         * Render LOG panel (activity and error logs from debug.log)
         */
        protected static function render_log_panel($panel, $context) {
            $max_entries = $panel['maxEntries'] ?? 50;
            $refresh_interval = $panel['refreshInterval'] ?? 30;
            $panel_id = $panel['id'] ?? 'activity_log';
            
            // Read logs from debug.log
            $logs = array();
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                $log_file = WP_CONTENT_DIR . '/debug.log';
                if (file_exists($log_file)) {
                    $lines = file($log_file);
                    $count = 0;
                    
                    foreach (array_reverse($lines) as $line) {
                        // Filter for rawwire-related entries
                        if (stripos($line, 'rawwire') !== false || 
                            stripos($line, 'raw-wire') !== false || 
                            stripos($line, 'raw_wire') !== false ||
                            stripos($line, 'workflow') !== false) {
                            
                            $severity = 'info';
                            if (stripos($line, 'error') !== false || stripos($line, 'fatal') !== false) {
                                $severity = 'error';
                            } elseif (stripos($line, 'warning') !== false) {
                                $severity = 'warning';
                            } elseif (stripos($line, 'debug') !== false) {
                                $severity = 'debug';
                            }
                            
                            // Extract timestamp if present
                            $timestamp = '';
                            if (preg_match('/^\[([^\]]+)\]/', $line, $matches)) {
                                $timestamp = $matches[1];
                                $message = trim(substr($line, strlen($matches[0])));
                            } else {
                                $message = trim($line);
                            }
                            
                            $logs[] = array(
                                'timestamp' => $timestamp,
                                'message' => $message,
                                'severity' => $severity
                            );
                            
                            $count++;
                            if ($count >= $max_entries) break;
                        }
                    }
                }
            }
            
            // Count by severity
            $counts = array('info' => 0, 'warning' => 0, 'error' => 0, 'debug' => 0);
            foreach ($logs as $log) {
                if (isset($counts[$log['severity']])) {
                    $counts[$log['severity']]++;
                }
            }
            ?>
            <div class="rawwire-log-panel" data-panel-id="<?php echo esc_attr($panel_id); ?>" data-refresh="<?php echo intval($refresh_interval); ?>">
                <div class="rawwire-log-controls">
                    <div class="rawwire-log-stats">
                        <span class="rawwire-log-stat info">
                            <span class="dashicons dashicons-info"></span>
                            <span class="count"><?php echo intval($counts['info']); ?></span> Info
                        </span>
                        <span class="rawwire-log-stat warning">
                            <span class="dashicons dashicons-warning"></span>
                            <span class="count"><?php echo intval($counts['warning']); ?></span> Warning
                        </span>
                        <span class="rawwire-log-stat error">
                            <span class="dashicons dashicons-no"></span>
                            <span class="count"><?php echo intval($counts['error']); ?></span> Error
                        </span>
                    </div>
                    <div class="rawwire-log-actions">
                        <select class="rawwire-log-filter">
                            <option value="all">All</option>
                            <option value="info">Info</option>
                            <option value="warning">Warnings</option>
                            <option value="error">Errors</option>
                            <option value="debug">Debug</option>
                        </select>
                        <button type="button" class="rawwire-btn rawwire-btn-sm rawwire-log-refresh">
                            <span class="dashicons dashicons-update"></span>
                        </button>
                    </div>
                </div>
                
                <div class="rawwire-log-entries">
                    <?php if (empty($logs)): ?>
                        <div class="rawwire-log-empty">
                            <span class="dashicons dashicons-info-outline"></span>
                            <p>No log entries found. Run a workflow to see activity here.</p>
                            <small>Logs are read from wp-content/debug.log (WP_DEBUG_LOG must be enabled)</small>
                        </div>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <div class="rawwire-log-entry rawwire-log-<?php echo esc_attr($log['severity']); ?>">
                                <span class="rawwire-log-severity">
                                    <span class="dashicons dashicons-<?php 
                                        echo $log['severity'] === 'error' ? 'no' : 
                                            ($log['severity'] === 'warning' ? 'warning' : 
                                            ($log['severity'] === 'debug' ? 'admin-tools' : 'info')); 
                                    ?>"></span>
                                </span>
                                <?php if ($log['timestamp']): ?>
                                    <span class="rawwire-log-time"><?php echo esc_html($log['timestamp']); ?></span>
                                <?php endif; ?>
                                <span class="rawwire-log-message"><?php echo esc_html(wp_trim_words($log['message'], 30)); ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php
        }

        /**
         * Render item actions
         */
        protected static function render_item_actions($item, $actions) {
            ?>
            <div class="rawwire-item-actions">
                <?php foreach ($actions as $action): ?>
                    <button type="button" class="rawwire-btn-icon"
                            data-action="<?php echo esc_attr($action['action'] ?? $action['id']); ?>"
                            data-item-id="<?php echo esc_attr($item['id'] ?? ''); ?>"
                            title="<?php echo esc_attr($action['label'] ?? ''); ?>">
                        <span class="dashicons <?php echo esc_attr($action['icon'] ?? ''); ?>"></span>
                    </button>
                <?php endforeach; ?>
            </div>
            <?php
        }

        /**
         * Render cell value with formatting
         */
        protected static function render_cell_value($value, $render, $item = array()) {
            switch ($render) {
                case 'badge':
                    return sprintf(
                        '<span class="rawwire-badge rawwire-badge-%s">%s</span>',
                        esc_attr(strtolower($value)),
                        esc_html(ucfirst($value))
                    );

                case 'boolean':
                    $icon = $value ? 'dashicons-yes-alt' : 'dashicons-no-alt';
                    $color = $value ? 'green' : 'gray';
                    $text = $value ? 'Yes' : 'No';
                    return sprintf(
                        '<span class="dashicons %s" style="color: %s;" title="%s"></span>',
                        esc_attr($icon),
                        esc_attr($color),
                        esc_attr($text)
                    );

                case 'relative_time':
                    return esc_html(human_time_diff(strtotime($value), current_time('timestamp')) . ' ago');

                case 'score_bar':
                    $percent = min(100, max(0, floatval($value)));
                    return sprintf(
                        '<div class="rawwire-score-bar"><div class="rawwire-score-bar-fill" style="width: %d%%"></div></div>',
                        $percent
                    );

                case 'link':
                    return sprintf('<a href="%s" target="_blank">%s</a>', esc_url($value), esc_html($value));

                case 'image':
                    return sprintf('<img src="%s" alt="" style="max-width: 50px; height: auto;">', esc_url($value));

                default:
                    return esc_html($value);
            }
        }

        /**
         * Resolve data binding to actual value
         */
        public static function resolve_data_binding($binding, $context) {
            if (empty($binding)) {
                return null;
            }

            // Parse binding format: source:path:filters
            $parts = explode(':', $binding);
            $source = $parts[0] ?? '';

            switch ($source) {
                case 'db':
                    return self::resolve_db_binding($binding);

                case 'template':
                    return self::resolve_template_binding($binding);

                case 'settings':
                    return self::resolve_settings_binding($binding);

                case 'scraper':
                    return self::resolve_scraper_binding($binding);

                case 'context':
                    $path = $parts[1] ?? '';
                    return self::get_nested_value($context, $path);

                default:
                    return $context[$binding] ?? null;
            }
        }

        /**
         * Resolve database binding
         */
        protected static function resolve_db_binding($binding) {
            global $wpdb;

            // Parse: db:table:operation:filters
            $parts = explode(':', $binding);
            $table = $parts[1] ?? '';
            $operation = $parts[2] ?? 'all';

            if (empty($table)) {
                return null;
            }

            $full_table = $wpdb->prefix . 'rawwire_' . $table;

            // Check if table exists
            if ($wpdb->get_var("SHOW TABLES LIKE '$full_table'") !== $full_table) {
                return $operation === 'count' ? 0 : array();
            }

            // Parse filters from remaining parts
            $where_clauses = array();
            $limit = '';

            for ($i = 2; $i < count($parts); $i++) {
                $part = $parts[$i];

                // Check for special directives FIRST (before generic field=value)
                if (strpos($part, 'limit=') === 0) {
                    $limit = ' LIMIT ' . intval(str_replace('limit=', '', $part));
                } elseif ($part === 'count') {
                    $operation = 'count';
                } elseif ($part === 'recent') {
                    $where_clauses[] = '1=1';
                    $limit = $limit ?: ' LIMIT 10';
                } elseif (strpos($part, '=') !== false) {
                    list($field, $value) = explode('=', $part, 2);

                    // Skip reserved SQL keywords used as directives
                    if (in_array(strtolower($field), ['limit', 'order', 'offset', 'group'], true)) {
                        continue;
                    }

                    // Handle special values
                    if ($value === 'today') {
                        $where_clauses[] = $wpdb->prepare("DATE($field) = %s", current_time('Y-m-d'));
                    } else {
                        $where_clauses[] = $wpdb->prepare("$field = %s", $value);
                    }
                }
            }

            $where = empty($where_clauses) ? '' : ' WHERE ' . implode(' AND ', $where_clauses);

            if ($operation === 'count') {
                return (int) $wpdb->get_var("SELECT COUNT(*) FROM $full_table $where");
            }

            return $wpdb->get_results(
                "SELECT * FROM $full_table $where ORDER BY created_at DESC $limit",
                ARRAY_A
            );
        }

        /**
         * Resolve template binding
         */
        protected static function resolve_template_binding($binding) {
            if (!class_exists('RawWire_Template_Engine')) {
                return null;
            }

            // Parse: template:path
            $parts = explode(':', $binding);
            $path = $parts[1] ?? '';

            $template = RawWire_Template_Engine::get_template();
            return self::get_nested_value($template, $path);
        }

        /**
         * Resolve settings binding
         */
        protected static function resolve_settings_binding($binding) {
            // Parse: settings:key
            $parts = explode(':', $binding);
            $key = $parts[1] ?? '';

            if (empty($key)) {
                return null;
            }

            $template_id = 'unknown';
            if (class_exists('RawWire_Template_Engine')) {
                $meta = RawWire_Template_Engine::get_meta();
                $template_id = $meta['id'] ?? 'unknown';
            }

            $settings = get_option('rawwire_template_settings_' . $template_id, array());
            return $settings[$key] ?? null;
        }

        /**
         * Resolve scraper binding - get configured sources from Scraper Toolkit
         */
        protected static function resolve_scraper_binding($binding) {
            // Parse: scraper:sources or scraper:sources:enabled
            $parts = explode(':', $binding);
            $what = $parts[1] ?? 'sources';
            $filter = $parts[2] ?? null;

            if ($what === 'sources') {
                // Get sources from Scraper Toolkit
                if (!class_exists('RawWire_Scraper_Settings')) {
                    return array();
                }

                $sources = RawWire_Scraper_Settings::get_sources();
                
                // Transform sources to dashboard-friendly format
                $formatted = array();
                foreach ($sources as $id => $source) {
                    // Filter by enabled status if specified
                    if ($filter === 'enabled' && empty($source['enabled'])) {
                        continue;
                    }
                    if ($filter === 'disabled' && !empty($source['enabled'])) {
                        continue;
                    }

                    $formatted[] = array(
                        'id' => $id,
                        'label' => $source['name'] ?? $source['label'] ?? $id,
                        'url' => $source['address'] ?? $source['url'] ?? '',
                        'category' => $source['type'] ?? 'custom',
                        'type' => $source['address_type'] ?? $source['type'] ?? 'unknown',
                        'enabled' => !empty($source['enabled']),
                        'output_table' => $source['output_table'] ?? 'candidates',
                        'created_at' => $source['created_at'] ?? '',
                        'updated_at' => $source['updated_at'] ?? '',
                    );
                }

                return $formatted;
            }

            return null;
        }

        /**
         * Get nested value from array using dot notation
         */
        protected static function get_nested_value($array, $path) {
            $keys = explode('.', $path);
            $value = $array;

            foreach ($keys as $key) {
                if (is_array($value) && isset($value[$key])) {
                    $value = $value[$key];
                } else {
                    return null;
                }
            }

            return $value;
        }

        /**
         * Interpolate template string with item data
         */
        protected static function interpolate_template($template, $item) {
            return preg_replace_callback('/\{\{(\w+)\}\}/', function($matches) use ($item) {
                $key = $matches[1];

                // Special handlers
                if ($key === 'relative_time' && isset($item['created_at'])) {
                    return human_time_diff(strtotime($item['created_at']), current_time('timestamp')) . ' ago';
                }

                return $item[$key] ?? '';
            }, $template);
        }

        /**
         * Format value based on format type
         */
        protected static function format_value($value, $format) {
            switch ($format) {
                case 'number':
                    return number_format((int) $value);

                case 'percent':
                    return number_format((float) $value, 1) . '%';

                case 'currency':
                    return '$' . number_format((float) $value, 2);

                case 'date':
                    return date_i18n(get_option('date_format'), strtotime($value));

                case 'datetime':
                    return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($value));

                default:
                    return $value;
            }
        }

        /**
         * Render item from template definition
         */
        protected static function render_item_from_template($item, $template) {
            $fields = $template['fields'] ?? array();
            $actions = $template['actions'] ?? array();
            ?>
            <div class="rawwire-item-content">
                <?php foreach ($fields as $field): ?>
                    <?php if (isset($item[$field])): ?>
                        <span class="rawwire-item-field rawwire-field-<?php echo esc_attr($field); ?>">
                            <?php
                            if ($field === 'enabled') {
                                echo $item[$field] ? '✓ Enabled' : '✗ Disabled';
                            } else {
                                echo esc_html($item[$field]);
                            }
                            ?>
                        </span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php if (!empty($actions)): ?>
                <div class="rawwire-item-actions">
                    <?php foreach ($actions as $action): ?>
                        <button type="button" class="rawwire-btn-icon"
                                data-action="<?php echo esc_attr($action); ?>"
                                data-item-id="<?php echo esc_attr($item['id'] ?? ''); ?>">
                            <span class="dashicons dashicons-<?php echo esc_attr(str_replace('_', '-', $action)); ?>"></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php
        }

        /**
         * Register a custom panel renderer
         */
        public static function register_renderer($type, $callback) {
            if (is_callable($callback)) {
                self::$custom_renderers[$type] = $callback;
            }
        }

        /**
         * Helper: Convert camelCase to kebab-case
         */
        protected static function camel_to_kebab($str) {
            return strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $str));
        }
    }
}
