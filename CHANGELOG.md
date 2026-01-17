# Changelog

## [1.0.25] - 2026-01-17

### Added - Professional UI Redesign

**New Design System**
- Complete CSS design system with 1200+ lines of modern, professional styling
- Light/Dark mode support with automatic system preference detection
- Deep Blue (#1e3a5f) and Bright Gold (#f4b41a) brand accent colors
- Smooth transitions and micro-interactions throughout
- 3D card shadows with hover lift effects
- Glassmorphism touches for premium feel

**Theme Controller**
- New `js/theme-controller.js` for light/dark mode management
- Three-way toggle: Light / System / Dark
- Persistent user preference in localStorage
- Automatic theme application on page load
- Custom events for theme-aware components

**Design Tokens**
- CSS custom properties for all colors, spacing, typography
- Semantic color naming (success, warning, danger, info)
- Responsive typography scale
- Consistent border-radius and shadow system
- Spacing scale following 4px base unit

**Enhanced Components**
- Hero section with gradient backgrounds and decorative orbs
- Stat cards with accent bar animations
- Refined button styles with shine effects
- Modern form controls with focus states
- Improved pills and badges with semantic colors
- Professional panel headers with brand accent bars

**Brand Integration**
- Deep blue primary actions (light mode)
- Bright gold primary actions (dark mode)
- Brand-colored accents on headers and focus states
- Consistent iconography with Dashicons

### Changed

- dashboard.css: Complete rewrite to use design system variables
- page-renderer.php: Hero section with description support
- raw-wire-dashboard.php: Added design system CSS and theme controller enqueue

## [1.0.24] - 2026-01-17

### Added - Workflow Pipeline & API Integration

**Full Workflow Pipeline**
- Complete 4-stage workflow: Candidates → Approvals → Content → Releases
- Archive system for rejected items with restore capability
- Batch processing with configurable limits
- Real-time progress tracking with status indicators

**Multi-Source API Scraping**
- Federal Register API integration (fully functional)
- Regulations.gov API with nested `attributes` flattening
- Congress.gov API support
- Automatic deduplication across sources
- Link column storage in all workflow tables (VARCHAR 2000)

**Centralized Key Manager**
- `class-key-manager.php`: Encrypted API key storage using WordPress salts
- Single source of truth for all external service credentials
- Key status tracking and validation
- Automatic migration from legacy plain-text storage
- AJAX handlers for save/test/delete operations

**Scoring System**
- AI-powered content analysis with configurable weights
- Category scoring: Relevance, Timeliness, Impact, Quality, Uniqueness
- Visual weight adjustment sliders in admin UI
- Score normalization and threshold-based filtering

**Active Sources Toggle**
- Enable/disable template sources from dashboard
- `ajax_toggle_template_source` handler in workflow-handlers.php
- Persistent source state in template configuration

**Card Expansion (Show More)**
- JavaScript click handler for `.rawwire-expand-btn`
- Slide animation for expanded content
- Text toggle between "Show More" / "Show Less"
- Icon rotation on expand/collapse

### Fixed

**API Key Save Error**
- Changed nonce field from `_wpnonce` to `key_manager_nonce` to avoid form collision
- Updated all JavaScript AJAX references in class-ai-scraper-panel.php
- Key Manager class now properly included in plugin initialization

**Nonce Handling**
- Fixed nonce mismatch in source toggle handlers
- Multiple nonce acceptance for backward compatibility
- Form-specific nonce selectors to prevent conflicts

**Regulations.gov Data Parsing**
- Added flattening of nested `attributes` object in API response
- Fixed "Untitled" items caused by title being in `attributes.title`
- Proper extraction of documentId, title, and other metadata

### Changed

**Template Engine**
- `panel-renderer.php`: Added span wrapper for expand button text
- `template-system.js`: New card expand/collapse event delegation
- `workflow-handlers.php`: Added template source toggle handler

**Database Schema**
- All 5 workflow tables now have `link` column (VARCHAR 2000)
- Consistent schema across candidates, approvals, archives, content, releases

### Technical Details

**Files Modified**
```
cores/toolbox-core/class-key-manager.php      # Key management singleton
cores/toolbox-core/class-workflow-orchestrator.php  # Pipeline & scoring
cores/toolbox-core/features/class-ai-scraper-panel.php  # Nonce fixes
cores/template-engine/workflow-handlers.php   # Source toggle handler
cores/template-engine/panel-renderer.php      # Expand button markup
js/template-system.js                         # Expand click handler
```

---

## [1.0.23] - 2026-01-15

### Added - Template Builder System

**Visual Template Builder**
- `admin/class-templates.php`: Comprehensive template management interface
  - 4-tab layout: Overview, Builder, JSON Editor, Import/Export
  - 7-step wizard for guided template creation
  - Visual panel designer with drag-and-drop functionality
  - Real-time template preview and validation
  - Import/export template files

**Wizard-Based Template Creation**
- Step 1: Template Info (name, ID, description, author)
- Step 2: Use Case Selection (6 pre-configured scenarios)
  - Content Aggregation
  - AI Generation
  - Social Monitoring
  - Data Dashboard
  - Workflow Automation
  - Custom (blank slate)
- Step 3: Pages & Layout management
- Step 4: Visual Panels Designer (10 panel types)
- Step 5: Toolbox Configuration (scraper, AI, publisher, workflow)
- Step 6: Styling (color picker for theme customization)
- Step 7: Review & Generate

**Intelligent Defaults**
- Use case-based auto-configuration
- Pre-filled pages based on workflow type
- Recommended toolbox features
- Optimal panel layouts per use case

**Fallback Dashboard**
- Welcome panel when no template active
- "Create New Template" call-to-action
- Feature showcase (4-grid layout)
- Quick links to documentation and resources
- Responsive design for all screen sizes

**JavaScript Framework**
- `js/template-builder.js`: Complete wizard interaction system
  - Step validation and navigation
  - Drag-and-drop panel designer (jQuery UI)
  - Real-time data binding
  - AJAX template save/load
  - JSON validation and formatting
  - Import/export file handling

**Styling System**
- `css/template-builder.css`: 680+ lines of responsive CSS
  - Wizard progress indicators
  - Use case selection cards
  - Panel designer canvas
  - Color picker integration
  - File upload drag-drop zones
  - Mobile-responsive breakpoints

### Changed

**Menu Structure**
- Renamed "Modules" page to "Templates" throughout plugin
- Updated all menu hooks: `raw-wire-modules` → `raw-wire-templates`
- Changed page callbacks: `admin_modules_page()` → `admin_templates_page()`
- Updated admin class references: `class-modules.php` → `class-templates.php`

**Dashboard Behavior**
- Main dashboard now checks for active template
- Shows fallback welcome screen if no template loaded
- Automatic redirect to template builder for first-time users
- Template-based rendering when template is active

**AJAX Integration**
- New endpoint: `rawwire_save_template`
- Nonce verification: `rawwire_template_builder`
- Template file storage in `templates/` directory
- Active template tracking via WordPress options

### Technical Details

**File Structure Changes**
```
wordpress-plugins/raw-wire-dashboard/
├── admin/
│   └── class-templates.php          # NEW (1,100+ lines)
├── css/
│   └── template-builder.css         # NEW (680+ lines)
├── js/
│   └── template-builder.js          # NEW (680+ lines)
├── docs/
│   └── TEMPLATE_BUILDER_IMPLEMENTATION.md  # NEW
└── raw-wire-dashboard.php           # UPDATED
```

**Template JSON Schema**
```json
{
  "meta": { "name", "id", "description", "author", "version" },
  "useCase": "content-aggregation | ai-generation | ...",
  "pages": [{ "id", "title", "icon" }],
  "panels": [{ "id", "type", "title", "position", "width", "config" }],
  "toolbox": { "scraper", "ai_generator", "publisher", "workflow" },
  "styling": { "primaryColor", "secondaryColor", "accentColor", ... }
}
```

**Dependencies**
- jQuery (bundled with WordPress)
- jQuery UI Draggable
- jQuery UI Sortable
- WordPress Dashicons

### Documentation
- Added `TEMPLATE_BUILDER_IMPLEMENTATION.md` with full system documentation
- Includes architecture overview, technical specs, testing checklist
- Future enhancement roadmap (Phases 2-3)
- Development guide for extending panel types and use cases

---

## [1.0.12] - 2026-01-06

### Added - Production-Grade Foundation Layer

**Error Handling**
- `class-error-boundary.php`: Comprehensive exception handling system
  - `wrap_module_call()`: Protects module initialization and method calls
  - `wrap_ajax_call()`: Wraps AJAX handlers with try-catch and logging
  - `wrap_rest_call()`: Protects REST endpoints from exceptions
  - `wrap_db_call()`: Adds error handling to database operations
  - `with_timeout()`: Prevents infinite loops in long-running operations
  - All exceptions logged to activity logs with severity levels

**Input Validation**
- `class-validator.php`: Type-safe input validation system
  - `register_schema()`: Define validation schemas for endpoints
  - `validate()`: Schema-based validation with detailed error messages
  - Sanitizers: `sanitize_int/float/enum/bool/array/slug/email/url/json/date()`
  - Range checking, enum whitelisting, type coercion
  - Prevents SQL injection and invalid data from reaching business logic

**Initialization System**
- `class-init-controller.php`: Deterministic 6-phase boot sequence
  - Phase 1: Core utilities (Logger, Error Boundary, Validator)
  - Phase 1.5: Permissions system
  - Phase 2: Database migrations
  - Phase 3: Module system (Plugin Manager)
  - Phase 4: REST API and Admin UI registration
  - Phase 5: Legacy compatibility layer
  - Phase 6: Bootstrap UI components
  - Health check endpoint: `GET /wp-json/rawwire/v1/health`
  - Eliminates race conditions from fragmented initialization

**Permissions System**
- `class-permissions.php`: Role-based access control
  - Custom capabilities: `rawwire_view_dashboard`, `rawwire_manage_modules`, `rawwire_edit_config`, etc.
  - `check()`: Verify user has capability
  - `require_capability()`: Die with 403 if missing permission (for AJAX)
  - `rest_permission_check()`: Returns WP_Error for REST endpoints
  - `grant_capability()` / `revoke_capability()`: Dynamic permission assignment
  - Administrators granted all capabilities by default
  - Editors granted view-only capabilities

### Changed

**Core Architecture**
- Main plugin file (`raw-wire-dashboard.php`) refactored
  - Replaced 50 lines of fragmented initialization with single `RawWire_Init_Controller::init()` call
  - Single entry point on `plugins_loaded` hook
  - Version bumped to 1.0.12

**AJAX Handlers**
- All activity log AJAX handlers hardened with error boundaries:
  - `ajax_get_logs()`: Wrapped in `wrap_ajax_call()`, added enum/int validation
  - `ajax_clear_logs()`: Protected from exceptions, logs cleared atomically
  - `ajax_get_info()`: Safe info retrieval with error logging
  - Manual try-catch blocks removed (handled by error boundary)
  - Input validation centralized using `RawWire_Validator` methods

**Logger Enhancement**
- `class-logger.php`: Severity now stored in `details` JSON column
  - Enables UI filtering by severity (info, warning, error)
  - Query: `WHERE JSON_EXTRACT(details, '$.severity') = 'error'`

### Security

- All AJAX handlers require nonce verification (existing)
- All AJAX handlers require capability checks (new)
- Input validation prevents type coercion attacks
- Error messages sanitized before logging/display
- Health check endpoint exposes no sensitive data (only counts and status)

### Developer Experience

**New Helper Methods**
```php
// Error handling
RawWire_Error_Boundary::wrap_ajax_call(callable $callback, string $action_name);
RawWire_Error_Boundary::wrap_module_call(string $module_name, callable $callback);

// Input validation
RawWire_Validator::sanitize_enum($value, array $allowed_values, string $default);
RawWire_Validator::sanitize_int($value, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX, int $default = 0);

// Permissions
RawWire_Permissions::require_capability('rawwire_view_logs');
RawWire_Permissions::rest_permission_check('rawwire_manage_modules');
```

**Health Check Endpoint**
```bash
curl https://yoursite.com/wp-json/rawwire/v1/health
```

Response:
```json
{
  "status": "healthy",
  "version": "1.0.12",
  "timestamp": 1704067200,
  "database": "connected",
  "tables": {
    "wp_rawwire_automation_log": "exists",
    "wp_rawwire_content": "exists"
  },
  "modules_loaded": 3
}
```

### Testing

- All error boundaries validated with forced exceptions
- Input validator tested with malformed inputs (SQL injection attempts, type mismatches)
- Init controller tested with missing dependencies
- Permissions system tested with Editor and Administrator roles
- Health endpoint tested on staging environment

### Migration Notes

**For Administrators:**
1. Deactivate plugin in WordPress admin
2. Replace plugin files via FTP/SFTP
3. Reactivate plugin (runs migrations automatically)
4. Verify health endpoint: `/wp-json/rawwire/v1/health`
5. Check activity logs for any initialization errors

**For Developers:**
- All existing AJAX handlers remain compatible (error boundaries are transparent wrappers)
- Modules using `interface-feature.php` require no changes
- New modules should use `RawWire_Error_Boundary::wrap_module_call()` in init methods
- REST endpoints should use `RawWire_Permissions::rest_permission_check()` in `permission_callback`

### Upgrade Path

From v1.0.11 to v1.0.12:
- No database schema changes
- No breaking API changes
- Safe to deploy to production with zero downtime
- Custom modules remain compatible (new safety layer is additive)

---

## [1.0.11] - 2026-01-05

### Fixed
- Logger severity storage: Severity now stored in `details` JSON for UI filtering
- Dashboard Core initialization order
- Activity logs tab not populating on staging site

### Added
- Dashboard Core initialization on admin and frontend contexts
- Enhanced error logging for initialization failures

---

## [1.0.10] - Previous Release

(Earlier versions not documented)
