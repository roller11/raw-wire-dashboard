# RawWire Template System Architecture

**Version:** 1.0.0  
**Status:** Foundation Complete (Phase 1)

## Overview

The RawWire Template System provides a modular, declarative approach to building dashboard interfaces. It separates **structure** (templates) from **logic** (registries) from **presentation** (CSS), enabling rapid customization for different industries and use cases.

### Key Principles

1. **Declarative Configuration** - Dashboards defined in JSON, not code
2. **Registry Pattern** - Extensible panel types, data sources, and controls
3. **Industry Agnostic** - Core system adapts to any vertical via templates
4. **Progressive Enhancement** - Works without JavaScript, enhanced with it

---

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                        Page Template (JSON)                      │
│  ┌─────────┐ ┌─────────────────────────────────────┐ ┌────────┐ │
│  │ Header  │ │              Layout                  │ │ Footer │ │
│  └─────────┘ │  ┌───────┐ ┌───────┐ ┌───────────┐  │ └────────┘ │
│              │  │ Panel │ │ Panel │ │   Panel   │  │            │
│  ┌────────┐  │  │ 4-col │ │ 4-col │ │   4-col   │  │            │
│  │Sidebar │  │  └───────┘ └───────┘ └───────────┘  │            │
│  │        │  │  ┌─────────────────┐ ┌───────────┐  │            │
│  │        │  │  │     Panel       │ │   Panel   │  │            │
│  │        │  │  │     8-col       │ │   4-col   │  │            │
│  └────────┘  │  └─────────────────┘ └───────────┘  │            │
│              └─────────────────────────────────────┘            │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                          Registries                              │
│  ┌──────────────────┐ ┌─────────────────┐ ┌──────────────────┐  │
│  │ Panel Type       │ │ Data Source     │ │ Control          │  │
│  │ Registry         │ │ Registry        │ │ Registry         │  │
│  │                  │ │                 │ │                  │  │
│  │ • table          │ │ • module:       │ │ • button         │  │
│  │ • cards          │ │ • scraper:      │ │ • toggle         │  │
│  │ • metrics        │ │ • content:      │ │ • dropdown       │  │
│  │ • chart          │ │ • wp:           │ │ • search         │  │
│  │ • kanban         │ │ • api:          │ │ • filter         │  │
│  │ • calendar       │ │ • static:       │ │ • date-picker    │  │
│  │ • map            │ │                 │ │ • bulk-action    │  │
│  │ • chat           │ │                 │ │                  │  │
│  └──────────────────┘ └─────────────────┘ └──────────────────┘  │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                    CSS Grid System (12-column)                   │
│  .rawwire-col-1 through .rawwire-col-12                         │
│  Responsive: sm, md, lg, xl, xxl breakpoints                    │
└─────────────────────────────────────────────────────────────────┘
```

---

## Panel Types

The Panel Type Registry (`class-panel-type-registry.php`) provides 16 built-in panel types:

### Data Display Panels

| Type | Description | Use Case |
|------|-------------|----------|
| `table` | Sortable, filterable data tables | Lead lists, content inventory, logs |
| `cards` | Card grid or list layout | Portfolio items, team members, products |
| `list` | Simple list display | Recent activity, notifications |
| `gallery` | Image/media gallery | Portfolio, property photos |

### Analytics Panels

| Type | Description | Use Case |
|------|-------------|----------|
| `metrics` | Single or multi-value KPIs | Conversion rates, totals, trends |
| `chart` | Charts via Chart.js | Performance over time, distribution |

### Workflow Panels

| Type | Description | Use Case |
|------|-------------|----------|
| `kanban` | Drag-and-drop columns | Lead pipeline, project stages |
| `timeline` | Chronological events | Activity feed, project history |
| `calendar` | Event calendar | Appointments, deadlines, scheduling |

### Input Panels

| Type | Description | Use Case |
|------|-------------|----------|
| `form` | Dynamic forms | Settings, lead capture, data entry |

### Specialized Panels

| Type | Description | Use Case |
|------|-------------|----------|
| `map` | Interactive maps (Leaflet) | Lead locations, service areas |
| `chat` | AI chatbot interface | Client support, FAQ automation |
| `file-browser` | File management | Documents, project files |
| `status` | Status indicators | Service health, integration status |

### Custom Panels

| Type | Description | Use Case |
|------|-------------|----------|
| `html` | Raw HTML content | Custom widgets, embeds |
| `iframe` | External content | Third-party integrations |

---

## Data Sources

The Data Source Registry (`class-data-source-registry.php`) resolves data from various providers:

### Provider Prefixes

| Prefix | Description | Example |
|--------|-------------|---------|
| `module:` | Module method calls | `module:leads.get_recent` |
| `scraper:` | Scraper data | `scraper:sources`, `scraper:stats` |
| `content:` | Aggregated content table | `content:recent?limit=50` |
| `wp:` | WordPress data | `wp:posts.portfolio`, `wp:users` |
| `api:` | External API calls | `api:https://api.example.com/data` |
| `static:` | Template-defined lists | `static:date_ranges` |

### Caching

Data sources are cached by default with configurable TTL:

```php
rawwire_data_sources()->get_data('module:leads.get_stats', 300); // 5-minute cache
```

---

## Controls

The Control Registry (`class-control-registry.php`) provides interactive elements:

### Action Controls

- `button` - Click actions (AJAX or navigation)
- `link` - Navigation links with placeholder support
- `dropdown` - Menu with multiple actions
- `refresh` - Panel refresh button
- `export` - Export dropdown (CSV, JSON, Excel)
- `import` - File upload for import

### Input Controls

- `toggle` - On/off switch
- `select` - Single selection dropdown
- `multi-select` - Multiple selection
- `text-input` - Text field
- `checkbox` - Boolean checkbox
- `date-picker` - Single date
- `date-range` - Start/end date

### Filter Controls

- `search` - Search box with debounce
- `filter` - Dropdown filter
- `bulk-action` - Multi-select actions

---

## Template Structure

### Page Template (JSON Schema)

```json
{
  "$schema": "../cores/template-engine/schema/page-template.schema.json",
  "id": "my-dashboard",
  "title": "My Dashboard",
  "header": {
    "enabled": true,
    "title": "Dashboard Title",
    "left_controls": [...],
    "right_controls": [...]
  },
  "sidebar": {
    "enabled": true,
    "position": "left",
    "navigation": [...]
  },
  "layout": [
    {
      "id": "row-1",
      "panels": [
        { "type": "metrics", "width": 3, ... },
        { "type": "metrics", "width": 3, ... },
        { "type": "metrics", "width": 3, ... },
        { "type": "metrics", "width": 3, ... }
      ]
    },
    {
      "id": "row-2",
      "panels": [
        { "type": "table", "width": 8, ... },
        { "type": "cards", "width": 4, ... }
      ]
    }
  ],
  "footer": { "enabled": true, "text": "..." }
}
```

### Panel Template (Inline or Reference)

```json
{
  "id": "leads-table",
  "type": "table",
  "title": "Recent Leads",
  "width": 12,
  "data_source": "module:leads.get_recent",
  "table_config": {
    "columns": [
      { "key": "name", "label": "Name", "sortable": true },
      { "key": "email", "label": "Email", "type": "email" },
      { "key": "stage", "label": "Stage", "type": "badge", "filterable": true }
    ],
    "row_actions": [
      { "id": "view", "label": "View", "action": "view_lead" }
    ],
    "pagination": { "enabled": true, "per_page": 15 }
  }
}
```

---

## Usage Examples

### Rendering a Panel Programmatically

```php
// Simple panel render
$html = rawwire_render_panel('metrics', [
    'title' => 'Total Leads',
    'metrics_config' => [
        'layout' => 'single',
        'format' => 'number'
    ]
], 'module:leads.count_total');

// Full page from template
$template = json_decode(file_get_contents($template_path), true);
$html = rawwire_render_page($template);
```

### Registering Custom Panel Type

```php
add_action('rawwire_register_panel_types', function($registry) {
    $registry->register('my-widget', [
        'label' => 'My Custom Widget',
        'icon' => 'dashicons-chart-pie',
        'category' => 'custom',
        'renderer' => function($config, $data) {
            return '<div class="my-widget">' . esc_html($data) . '</div>';
        }
    ]);
});
```

### Registering Custom Data Source Provider

```php
add_action('rawwire_register_data_sources', function($registry) {
    $registry->register_provider('myapi', function($endpoint) {
        $response = wp_remote_get('https://myapi.com/' . $endpoint);
        return json_decode(wp_remote_retrieve_body($response), true);
    });
});

// Usage: data_source: "myapi:users"
```

---

## Client Use Case: Interior Design

The `interior-design.template.json` demonstrates adapting the system for a sustainable interior design practice in Boise, ID:

### Mapped Features

| Client Need | Panel Types Used | Data Sources |
|-------------|------------------|--------------|
| Lead Generation | `kanban`, `table`, `metrics` | `module:leads.*` |
| Geographic Focus | `map` | `module:leads.get_map_data` |
| Appointments | `calendar` | `module:calendar.get_events` |
| Social Media | `cards`, `timeline` | `module:social.get_queue` |
| Client Support | `chat` | AI Engine integration |
| Analytics | `metrics`, `chart` | Various module methods |

### Key Customizations

1. **Pipeline Stages** - Interior design sales funnel (New → Qualified → Proposal → Won)
2. **Map Center** - Treasure Valley coordinates (43.615, -116.2023)
3. **Project Types** - Residential, Commercial, Staging
4. **AI Context** - Sustainable design focus, local area knowledge

---

## File Structure

```
cores/template-engine/
├── loader.php                     # Bootstrap and helpers
├── template-engine.php            # Legacy template loading
├── panel-renderer.php             # Legacy panel rendering
├── page-renderer.php              # Page assembly
├── workflow-handlers.php          # Action processing
├── class-panel-type-registry.php  # Panel type management
├── class-data-source-registry.php # Data source providers
├── class-control-registry.php     # UI control rendering
├── schema/
│   ├── page-template.schema.json  # Page structure schema
│   └── panel-template.schema.json # Panel config schema
└── css/
    └── grid.css                   # 12-column grid system

templates/
├── news-aggregator.template.json  # Default template
└── interior-design.template.json  # Industry example
```

---

## Next Steps (Phases 2-5)

### Phase 2: Panel Type Library (3-5 days)
- Complete renderers for all 16 panel types
- Add configuration validation
- Asset management per panel type

### Phase 3: Builder UI Foundation (5-7 days)
- Page Builder drag-and-drop
- Visual row/column layout
- Panel placement interface

### Phase 4: Panel Builder (5-7 days)
- Panel configuration forms
- Data source selector
- Control configuration
- Live preview

### Phase 5: Template Management (3-5 days)
- Template import/export
- Version history
- Template marketplace foundation

---

## API Reference

### Global Functions

```php
rawwire_panel_types()     // Get Panel Type Registry instance
rawwire_data_sources()    // Get Data Source Registry instance
rawwire_controls()        // Get Control Registry instance

rawwire_render_panel($type, $config, $data)  // Render single panel
rawwire_render_control($config, $context)     // Render control
rawwire_render_row($panels, $context)         // Render grid row
rawwire_render_page($template, $context)      // Render full page

rawwire_validate_template($template, $type)   // Validate against schema
```

### Hooks

```php
// Register custom panel types
add_action('rawwire_register_panel_types', function($registry) { ... });

// Register custom data sources
add_action('rawwire_register_data_sources', function($registry) { ... });

// Register custom controls
add_action('rawwire_register_controls', function($registry) { ... });

// Template engine fully initialized
add_action('rawwire_template_engine_ready', function() { ... });
```
