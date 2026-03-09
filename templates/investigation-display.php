<?php

/**
 * Party Investigation Display Template
 * 
 * Renders investigation results in a beautiful, human-friendly format.
 * Used in admin dashboard and can be exported as reports.
 * 
 * @package RawWire_Dashboard
 * @since 1.0.31
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Render a full investigation report
 * 
 * @param array $data Investigation data (7 categories + company_overview)
 * @param array $options Display options
 * @return string HTML output
 */
function rawwire_render_investigation(array $data, array $options = []): string
{
    $defaults = [
        'show_header'      => true,
        'show_entry_points' => true,
        'collapsible'      => true,
        'export_button'    => true,
        'tier_badge'       => true,
    ];
    $options = wp_parse_args($options, $defaults);

    ob_start();
?>
    <div class="rw-investigation" data-investigation-id="<?php echo esc_attr($data['id'] ?? uniqid()); ?>">

        <?php if ($options['show_header'] && !empty($data['company_overview'])): ?>
            <?php rawwire_render_company_header($data['company_overview'], $data, $options); ?>
        <?php endif; ?>

        <div class="rw-investigation__grid">
            <?php
            // Render each category
            $categories = [
                'contacts'     => ['icon' => 'email', 'label' => 'Contacts', 'color' => 'blue'],
                'projects'     => ['icon' => 'building', 'label' => 'Projects', 'color' => 'green'],
                'relationships' => ['icon' => 'groups', 'label' => 'Relationships', 'color' => 'purple'],
                'gatherings'   => ['icon' => 'event', 'label' => 'Gatherings', 'color' => 'orange'],
                'affiliations' => ['icon' => 'verified', 'label' => 'Affiliations', 'color' => 'teal'],
                'community'    => ['icon' => 'favorite', 'label' => 'Community', 'color' => 'pink'],
                'edge_intel'   => ['icon' => 'diamond', 'label' => 'Edge Intel', 'color' => 'gold'],
            ];

            foreach ($categories as $key => $meta):
                if (!empty($data[$key])):
                    rawwire_render_category_card($key, $data[$key], $meta, $options);
                endif;
            endforeach;
            ?>
        </div>

        <?php if ($options['show_entry_points'] && !empty($data['entry_points'])): ?>
            <?php rawwire_render_entry_points($data['entry_points']); ?>
        <?php endif; ?>

        <?php if ($options['export_button']): ?>
            <div class="rw-investigation__actions">
                <button class="rw-btn rw-btn--secondary rw-investigation__export" data-format="pdf">
                    <span class="dashicons dashicons-pdf"></span> Export PDF
                </button>
                <button class="rw-btn rw-btn--secondary rw-investigation__export" data-format="json">
                    <span class="dashicons dashicons-download"></span> Export JSON
                </button>
                <button class="rw-btn rw-btn--primary rw-investigation__copy">
                    <span class="dashicons dashicons-clipboard"></span> Copy Summary
                </button>
            </div>
        <?php endif; ?>

    </div>
<?php
    return ob_get_clean();
}

/**
 * Render company header with tier badge
 */
function rawwire_render_company_header(array $company, array $data, array $options): void
{
    $tier = rawwire_calculate_investigation_tier($data);
?>
    <div class="rw-investigation__header">
        <div class="rw-investigation__company">
            <div class="rw-investigation__logo">
                <?php echo esc_html(substr($company['name'] ?? 'C', 0, 1)); ?>
            </div>
            <div class="rw-investigation__company-info">
                <h2 class="rw-investigation__company-name">
                    <?php echo esc_html($company['name'] ?? 'Unknown Company'); ?>
                </h2>
                <div class="rw-investigation__company-meta">
                    <?php if (!empty($company['type'])): ?>
                        <span class="rw-tag rw-tag--outline"><?php echo esc_html($company['type']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($company['headquarters'])): ?>
                        <span class="rw-investigation__location">
                            <span class="dashicons dashicons-location"></span>
                            <?php echo esc_html($company['headquarters']); ?>
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($company['size'])): ?>
                        <span class="rw-investigation__size">
                            <span class="dashicons dashicons-groups"></span>
                            <?php echo esc_html($company['size']); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($options['tier_badge']): ?>
            <div class="rw-investigation__tier rw-investigation__tier--<?php echo esc_attr($tier['level']); ?>">
                <div class="rw-investigation__tier-badge">
                    <span class="rw-investigation__tier-label">TIER <?php echo esc_html($tier['number']); ?></span>
                    <span class="rw-investigation__tier-name"><?php echo esc_html($tier['name']); ?></span>
                </div>
                <div class="rw-investigation__tier-stats">
                    <span><?php echo esc_html($tier['entry_count']); ?> findings</span>
                    <span><?php echo esc_html($tier['category_count']); ?>/7 categories</span>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($company['ownership']) || !empty($company['founded'])): ?>
            <div class="rw-investigation__quick-facts">
                <?php if (!empty($company['ownership'])): ?>
                    <div class="rw-quick-fact">
                        <span class="rw-quick-fact__icon">🏢</span>
                        <span class="rw-quick-fact__value"><?php echo esc_html($company['ownership']); ?></span>
                    </div>
                <?php endif; ?>
                <?php if (!empty($company['founded'])): ?>
                    <div class="rw-quick-fact">
                        <span class="rw-quick-fact__icon">📅</span>
                        <span class="rw-quick-fact__value">Est. <?php echo esc_html($company['founded']); ?></span>
                    </div>
                <?php endif; ?>
                <?php if (!empty($company['revenue'])): ?>
                    <div class="rw-quick-fact">
                        <span class="rw-quick-fact__icon">💰</span>
                        <span class="rw-quick-fact__value"><?php echo esc_html($company['revenue']); ?></span>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
<?php
}

/**
 * Render a category card
 */
function rawwire_render_category_card(string $key, array $entries, array $meta, array $options): void
{
    $icons = [
        'email'    => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>',
        'building' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 7V3H2v18h20V7H12zM6 19H4v-2h2v2zm0-4H4v-2h2v2zm0-4H4V9h2v2zm0-4H4V5h2v2zm4 12H8v-2h2v2zm0-4H8v-2h2v2zm0-4H8V9h2v2zm0-4H8V5h2v2zm10 12h-8v-2h2v-2h-2v-2h2v-2h-2V9h8v10zm-2-8h-2v2h2v-2zm0 4h-2v2h2v-2z"/></svg>',
        'groups'   => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>',
        'event'    => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M17 12h-5v5h5v-5zM16 1v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2h-1V1h-2zm3 18H5V8h14v11z"/></svg>',
        'verified' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z"/></svg>',
        'favorite' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>',
        'diamond'  => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 3H5L2 9l10 12L22 9l-3-6zM9.62 8l1.5-3h1.76l1.5 3H9.62zM11 10v6.68L5.44 10H11zm2 0h5.56L13 16.68V10zm6.26-2h-2.65l-1.5-3h2.65l1.5 3zM6.24 5h2.65l-1.5 3H4.74l1.5-3z"/></svg>',
    ];
?>
    <div class="rw-investigation__card rw-investigation__card--<?php echo esc_attr($meta['color']); ?>"
        data-category="<?php echo esc_attr($key); ?>">

        <div class="rw-investigation__card-header">
            <div class="rw-investigation__card-icon">
                <?php echo $icons[$meta['icon']] ?? ''; ?>
            </div>
            <h3 class="rw-investigation__card-title"><?php echo esc_html($meta['label']); ?></h3>
            <span class="rw-investigation__card-count"><?php echo count($entries); ?></span>
            <?php if ($options['collapsible']): ?>
                <button class="rw-investigation__card-toggle" aria-expanded="true">
                    <span class="dashicons dashicons-arrow-up-alt2"></span>
                </button>
            <?php endif; ?>
        </div>

        <div class="rw-investigation__card-body">
            <ul class="rw-investigation__entries">
                <?php foreach ($entries as $entry): ?>
                    <?php rawwire_render_entry($entry, $key); ?>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php
}

/**
 * Render a single entry
 */
function rawwire_render_entry(array $entry, string $category): void
{
    $confidence = $entry['confidence'] ?? 'confirmed';
    $confidence_icons = [
        'confirmed'   => '✓',
        'inferred'    => '~',
        'speculative' => '?',
    ];
?>
    <li class="rw-entry rw-entry--<?php echo esc_attr($confidence); ?>">
        <span class="rw-entry__confidence" title="<?php echo esc_attr(ucfirst($confidence)); ?>">
            <?php echo $confidence_icons[$confidence] ?? '•'; ?>
        </span>
        <div class="rw-entry__content">
            <span class="rw-entry__title"><?php echo esc_html($entry['title'] ?? ''); ?></span>
            <span class="rw-entry__value"><?php echo esc_html($entry['value'] ?? ''); ?></span>
        </div>
        <?php if (!empty($entry['value']) && (strpos($entry['value'], '@') !== false || strpos($entry['value'], 'linkedin') !== false)): ?>
            <button class="rw-entry__copy" data-copy="<?php echo esc_attr($entry['value']); ?>" title="Copy">
                <span class="dashicons dashicons-clipboard"></span>
            </button>
        <?php endif; ?>
    </li>
<?php
}

/**
 * Render entry points section
 */
function rawwire_render_entry_points(array $entry_points): void
{
?>
    <div class="rw-investigation__entry-points">
        <h3 class="rw-investigation__section-title">
            <span class="dashicons dashicons-yes-alt"></span>
            Recommended Entry Points
        </h3>
        <div class="rw-entry-points__grid">
            <?php foreach ($entry_points as $i => $point): ?>
                <div class="rw-entry-point">
                    <div class="rw-entry-point__number"><?php echo $i + 1; ?></div>
                    <div class="rw-entry-point__content">
                        <h4 class="rw-entry-point__approach"><?php echo esc_html($point['approach'] ?? ''); ?></h4>
                        <p class="rw-entry-point__target">
                            <strong>Target:</strong> <?php echo esc_html($point['target'] ?? ''); ?>
                        </p>
                        <?php if (!empty($point['timing'])): ?>
                            <p class="rw-entry-point__timing">
                                <strong>When:</strong> <?php echo esc_html($point['timing']); ?>
                            </p>
                        <?php endif; ?>
                        <p class="rw-entry-point__angle">
                            <em><?php echo esc_html($point['angle'] ?? ''); ?></em>
                        </p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php
}

/**
 * Calculate investigation tier
 */
function rawwire_calculate_investigation_tier(array $data): array
{
    $categories = ['contacts', 'projects', 'relationships', 'gatherings', 'affiliations', 'community', 'edge_intel'];

    $entry_count = 0;
    $category_count = 0;

    foreach ($categories as $cat) {
        if (!empty($data[$cat]) && is_array($data[$cat])) {
            $entry_count += count($data[$cat]);
            $category_count++;
        }
    }

    // Add entry points to count
    if (!empty($data['entry_points'])) {
        $entry_count += count($data['entry_points']);
    }

    // Determine tier
    if ($entry_count >= 15 && $category_count >= 5) {
        return [
            'number'         => 3,
            'level'          => 'excellent',
            'name'           => 'Ecosystem Mapped',
            'entry_count'    => $entry_count,
            'category_count' => $category_count,
        ];
    } elseif ($entry_count >= 10 && $category_count >= 3) {
        return [
            'number'         => 2,
            'level'          => 'solid',
            'name'           => 'Solid Intel',
            'entry_count'    => $entry_count,
            'category_count' => $category_count,
        ];
    } else {
        return [
            'number'         => 1,
            'level'          => 'minimal',
            'name'           => 'Minimal',
            'entry_count'    => $entry_count,
            'category_count' => $category_count,
        ];
    }
}
