# Archived Obsolete Files

**Date:** January 14, 2026  
**Reason:** Codebase cleanup to eliminate confusion from duplicate/unused code

## Active Dashboard Architecture

The plugin now uses a **template-driven architecture**:

```
ACTIVE FLOW:
1. raw-wire-dashboard.php → loads bootstrap.php
2. RawWire_Bootstrap::register_menu() → registers "Raw-Wire" menu
3. RawWire_Bootstrap::render_dashboard() → entry point
4. RawWire_Page_Renderer::render_dashboard() → renders from template
5. templates/news-aggregator.template.json → defines UI structure
```

### Required Files (DO NOT ARCHIVE):
- `raw-wire-dashboard.php` - Main plugin file (includes stub logger)
- `includes/bootstrap.php` - Menu registration & render entry
- `includes/class-admin.php` - AJAX handlers
- `includes/class-ai-content-analyzer.php` - AI integration
- `includes/interface-module.php` - Required by module system
- `cores/template-engine/*.php` - Template system
- `cores/module-core/*.php` - Module system
- `admin/class-templates.php` - Template management UI

### Logging & Progress Bar:
**ARCHIVED for rebuild from scratch** - See `obsolete-logging/` and `obsolete-progress-bar/`

A stub `RawWire_Logger` class is in `raw-wire-dashboard.php` to prevent fatal errors.

---

## Archived Files

### obsolete-logging/ (January 14, 2026)

| File | Description |
|------|-------------|
| `class-activity-logs.php` | PHP class for activity logs AJAX handlers |
| `class-logger.php` | Full PHP Logger class with database storage |
| `activity-logs.js` | Standalone JS activity logs manager |
| `dashboard-js-activityLogsModule.js` | Extracted activityLogsModule from dashboard.js |
| `dashboard-css-activity-logs.css` | Extracted activity logs CSS from dashboard.css |

**Why archived:** Multiple conflicting implementations existed. Will rebuild unified logging system from scratch.

### obsolete-progress-bar/ (January 14, 2026)

| File | Description |
|------|-------------|
| `sync-manager.js` | Full sync manager with progress UI |
| `dashboard-js-progressCounter.js` | Extracted progress counter from dashboard.js |

**Why archived:** Multiple conflicting implementations. Will rebuild as part of module core.

### obsolete-includes/

| File | Reason Archived |
|------|-----------------|
| `class-dashboard-core.php` | Old singleton pattern, replaced by `RawWire_Dashboard` in main file |
| `class-rest-api.php` | Duplicate - `rest-api.php` in root is used |
| `class-settings.php` | Never loaded, has own menu registration that conflicts |
| `class-github-crawler.php` | Replaced by `cores/toolbox-core/adapters/scrapers/` |
| `class-github-fetcher.php` | Replaced by scraper system |
| `class-data-processor.php` | Never loaded by main plugin |
| `class-approval-workflow.php` | Never loaded, workflow in template-engine |
| `class-cache-manager.php` | Never instantiated |
| `class-permissions.php` | Never initialized |
| `class-plugin-manager.php` | Replaced by module-core system |
| `class-public.php` | Frontend dashboard never used |
| `helpers.php` | Utility functions never included |
| `logger.php` | Duplicate - archived with full logger system |
| `auth.php` | Only in tests, never in production |
| `rate-limit.php` | Never loaded |
| `schema.sql` | Activation uses inline SQL |

**NOTE:** `interface-module.php` was initially archived but restored - it's still required by `modules/core/module.php`

### obsolete-admin/

| File | Reason Archived |
|------|-----------------|
| `class-candidates.php` | Never loaded anywhere |
| `class-modules.php` | Never loaded anywhere |

---

## Restoration

If any file needs to be restored:
```bash
mv _archive/obsolete-includes/filename.php includes/
# Then add require_once to raw-wire-dashboard.php
```

## What Was Removed From Active Files

### dashboard.js
- `activityLogsModule` object (lines 608-857)
- Progress counter functions (lines 298-371)
- Activity logs initialization (lines 904-906)
- Fallback log button handlers (lines 917-940)

Placeholder stubs added to prevent errors if old code calls these functions.

### dashboard.css
- Activity logs styles (lines 252-550)
- Log modal styles
- Responsive log styles

### raw-wire-dashboard.php
- Removed require for `class-logger.php`
- Removed `RawWire_Activity_Logs::enqueue_assets()` call
- Added stub `RawWire_Logger` class with basic error_log fallback

## Testing After Cleanup

Verify dashboard still works:
1. Go to WordPress admin → Raw-Wire
2. Dashboard should render with Statistics, Sources, Queue, Recent Findings panels
3. Check browser console for JavaScript errors
4. Check `wp-content/debug.log` for PHP errors
5. Sync button should still work (progress UI temporarily disabled)
