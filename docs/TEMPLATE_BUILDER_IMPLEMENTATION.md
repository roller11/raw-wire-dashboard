# Template Builder Implementation

## Overview
Comprehensive template management system with visual wizard-based builder for creating, editing, and managing dashboard templates.

## Key Features

### 1. **Template Management Page** (`admin/class-templates.php`)
- **4-Tab Interface**:
  - Overview: Active template display, available templates grid, features showcase
  - Builder: 7-step wizard for creating templates
  - JSON Editor: Direct JSON editing with validation
  - Import/Export: Template file management

### 2. **7-Step Wizard Builder**
Questionnaire-style interface guiding users through template creation:

1. **Template Info**: Name, ID, description, author, version
2. **Use Case Selection**: 6 pre-configured scenarios:
   - Content Aggregation (RSS, scrapers)
   - AI Generation (GPT, Claude)
   - Social Monitoring (feeds, analytics)
   - Data Dashboard (reports, visualizations)
   - Workflow Automation (multi-stage processes)
   - Custom (blank slate)
3. **Pages & Layout**: Add/edit dashboard pages with icons
4. **Panels Designer**: Drag-and-drop interface for panel types:
   - Stat Card
   - Data Table
   - Chart
   - Feed List
   - Content Queue
   - Activity Log
   - AI Generator
   - Approval Panel
   - Scheduler
   - Settings
5. **Toolbox Configuration**: Enable features:
   - Web Scraper
   - AI Generator
   - Publisher
   - Workflow Engine
6. **Styling**: Theme customization with color pickers:
   - Primary Color
   - Secondary Color
   - Accent Color
   - Background Color
   - Text Color
7. **Review & Generate**: Summary and final template creation

### 3. **Visual Panel Designer**
- Drag-and-drop panel types from sidebar to canvas
- Sortable panels with visual previews
- Edit/delete individual panels
- Real-time canvas updates

### 4. **Intelligent Defaults**
Based on use case selection, automatically configures:
- Suggested pages (Dashboard, Feeds, Items, etc.)
- Recommended toolbox features
- Optimal panel layouts

### 5. **Fallback Dashboard**
When no template is active, displays:
- Welcome panel with quick actions
- "Create New Template" CTA
- "Browse Templates" link
- Documentation and resources
- Feature showcase (4-grid preview)

## File Structure

```
wordpress-plugins/raw-wire-dashboard/
├── admin/
│   └── class-templates.php          # Template management page (1,100+ lines)
├── css/
│   └── template-builder.css         # Builder UI styles (680+ lines)
├── js/
│   └── template-builder.js          # Wizard interactions (680+ lines)
├── templates/
│   └── *.template.json              # User-created templates
└── raw-wire-dashboard.php           # Main plugin file (updated)
```

## Technical Implementation

### Template Data Structure
```json
{
  "meta": {
    "name": "Template Name",
    "id": "template-slug",
    "description": "Template description",
    "author": "Author Name",
    "version": "1.0.0"
  },
  "useCase": "content-aggregation",
  "pages": [
    {
      "id": "dashboard",
      "title": "Dashboard",
      "icon": "dashboard"
    }
  ],
  "panels": [
    {
      "id": "panel_123",
      "type": "stat-card",
      "title": "Statistics",
      "position": 0,
      "width": "half",
      "config": {}
    }
  ],
  "toolbox": {
    "scraper": true,
    "ai_generator": false,
    "publisher": true,
    "workflow": true
  },
  "styling": {
    "primaryColor": "#2271b1",
    "secondaryColor": "#72aee6",
    "accentColor": "#00a32a",
    "backgroundColor": "#f0f0f1",
    "textColor": "#1d2327"
  }
}
```

### JavaScript Architecture
- **Object-oriented design** with `TemplateBuilder` singleton
- **Step validation** before navigation
- **Real-time data binding** between UI and template data
- **AJAX integration** for server-side operations
- **jQuery UI** for drag-and-drop functionality

### WordPress Integration
- **AJAX endpoint**: `rawwire_save_template`
- **Nonce verification**: `rawwire_template_builder`
- **Capability check**: `manage_options`
- **File system**: Templates saved to `templates/` directory
- **Options API**: Active template stored as `rawwire_active_template`

## UI/UX Features

### Responsive Design
- Desktop: Full 3-column layouts
- Tablet (< 1200px): 2-column layouts
- Mobile (< 768px): Single column, stacked panels

### Visual Feedback
- Step progress indicator with completion states
- Active/hover states on all interactive elements
- Drag-over highlighting for drop zones
- Color-coded validation messages

### Accessibility
- Semantic HTML structure
- ARIA labels on interactive elements
- Keyboard navigation support
- Focus indicators

## Use Case Defaults

### Content Aggregation
- **Pages**: Dashboard, Feeds, Items
- **Toolbox**: Scraper ✓, Publisher ✓, Workflow ✓
- **Panels**: Feed List, Content Queue, Activity Log

### AI Generation
- **Pages**: Dashboard, Generator, Library
- **Toolbox**: AI Generator ✓, Publisher ✓, Workflow ✓
- **Panels**: AI Generator, Content Queue, Settings

### Social Monitoring
- **Pages**: Dashboard, Streams, Analytics
- **Toolbox**: Scraper ✓, AI Generator ✓, Publisher ✓
- **Panels**: Feed List, Chart, Activity Log

### Data Dashboard
- **Pages**: Dashboard, Reports, Sources
- **Toolbox**: Scraper ✓
- **Panels**: Stat Card, Data Table, Chart

### Workflow Automation
- **Pages**: Dashboard, Workflows, Queue
- **Toolbox**: All features enabled
- **Panels**: Content Queue, Approval Panel, Scheduler

## Installation & Activation

1. **Activate Plugin**: Templates menu automatically appears
2. **First Visit**: Fallback dashboard displays with "Create Template" prompt
3. **Wizard Launch**: Click "Create New Template" → 7-step wizard begins
4. **Template Creation**: Complete wizard → Template saved and activated
5. **Dashboard Reload**: Full template-based interface renders

## Development Notes

### Extending Panel Types
Add new panel types in 3 locations:
1. `class-templates.php`: Add to panel types sidebar HTML
2. `template-builder.js`: Add to `getPanelDefaultTitle()` method
3. `panel-renderer.php`: Implement rendering logic

### Custom Use Cases
Add new use case in 2 locations:
1. `class-templates.php`: Add use-case card HTML in step 2
2. `template-builder.js`: Add to `applyUseCaseDefaults()` method

### Styling Customization
Override CSS variables in `template-builder.css`:
```css
:root {
  --rwt-primary: #2271b1;
  --rwt-secondary: #72aee6;
  --rwt-success: #00a32a;
  --rwt-danger: #d63638;
}
```

## Future Enhancements

### Phase 2 Roadmap
- [ ] Template library with remote templates
- [ ] One-click template installation
- [ ] Template preview before activation
- [ ] Template versioning and updates
- [ ] Multi-language template support
- [ ] Template marketplace integration

### Phase 3 Features
- [ ] Visual page layout editor (grid system)
- [ ] Real-time template preview
- [ ] Template inheritance and parent/child relationships
- [ ] Conditional panel rendering rules
- [ ] Advanced workflow builders
- [ ] Template analytics and usage tracking

## Testing Checklist

- [x] Wizard navigation (next/prev buttons)
- [x] Step validation enforcement
- [x] Use case selection and defaults application
- [x] Panel drag-and-drop functionality
- [x] Panel delete with confirmation
- [x] Color picker integration
- [x] JSON validation and formatting
- [x] Template import/export
- [x] AJAX save with error handling
- [x] Fallback dashboard display
- [x] Template activation on creation
- [x] Responsive layout breakpoints

## Known Issues & Limitations

1. **Drag-drop**: Requires jQuery UI (bundled with WordPress)
2. **JSON Editor**: No syntax highlighting (could add CodeMirror)
3. **File Upload**: Basic HTML5 implementation (could enhance with Dropzone.js)
4. **Template Validation**: Basic validation (could add JSON schema validation)

## Changelog

### Version 1.0.20 (Current)
- ✅ Added comprehensive template builder wizard
- ✅ Implemented visual panel designer
- ✅ Created fallback dashboard for no-template state
- ✅ Renamed "Modules" to "Templates" throughout
- ✅ Added 6 pre-configured use cases
- ✅ Built responsive CSS for builder UI
- ✅ Implemented AJAX save functionality
- ✅ Added import/export capabilities

## References

- **WordPress Dashicons**: https://developer.wordpress.org/resource/dashicons/
- **jQuery UI Draggable**: https://jqueryui.com/draggable/
- **jQuery UI Sortable**: https://jqueryui.com/sortable/
- **WordPress Admin CSS**: Core WordPress styles for consistency

## Credits

Inspired by modern plugin builders including:
- Meow AI Engine (questionnaire-style setup)
- Elementor (visual drag-and-drop)
- Gravity Forms (step-based wizard)
- WordPress Customizer (real-time preview)

---

**Maintained by**: Raw-Wire Development Team  
**Last Updated**: 2024  
**License**: GPL-2.0+
