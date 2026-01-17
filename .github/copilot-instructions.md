# AI Instructions for Raw-Wire Dashboard Development

## CRITICAL ARCHITECTURE - READ FIRST

This is a **TEMPLATE-DRIVEN MODULAR SYSTEM** designed for resale to small businesses. The architecture allows customers to adapt workflows without modifying code.

## The Golden Rule

**ALL LOGIC LIVES IN TEMPLATES. CODE IS STATIC.**

### What This Means

```
✅ CORRECT: Template defines query → Panel Renderer executes → Generic Handler processes
❌ WRONG: Module contains database query → Module renders HTML → Module has embedded JavaScript
```

## Core Architectural Principles

### 1. Templates Are King
- ALL functionality is defined in JSON template files (`templates/*.template.json`)
- Templates define: pages, panels, data sources, columns, actions, endpoints
- Code reads templates and executes based on configuration
- Removing a template should result in empty panels, NOT errors

### 2. Modules Are Fallbacks ONLY
- Module methods return simple fallback messages when template unavailable
- NO database queries in modules
- NO HTML generation in modules (beyond simple notices)
- NO embedded JavaScript in modules
- NO business logic in modules

### 3. Sub-Pages Are Template-Registered
- Each sub-page (Dashboard, Review Queue, Release, Settings) is defined in template
- Sub-pages have their own panel configurations
- Features are toggled via Settings page switches
- Toggle state persists in WordPress options

### 4. Generic Handlers
- JavaScript handlers are driven by data attributes, not hardcoded logic
- REST endpoints are generic, configured by template
- Panel renderer executes queries from template `dataSource` property

## Database Tables (4-Table Workflow)

```
SCRAPING                    SCORING                     APPROVAL                    PUBLISHING
   ↓                           ↓                           ↓                           ↓
wp_rawwire_candidates  →  wp_rawwire_archives  →  wp_rawwire_content  →  wp_rawwire_queue
(temporary staging)       (scored + result)        (approved items)        (publish queue)
```

### Table Purposes
- **candidates**: Temporary staging during scrape, cleared after scoring
- **archives**: Permanent storage with score, result (Accepted/Rejected), status (pending/approved)
- **content**: Items approved for AI generation
- **queue**: Items queued for publishing

### Key Queries
```sql
-- Candidates count
SELECT COUNT(*) FROM wp_rawwire_candidates

-- Accepted items pending approval
SELECT * FROM wp_rawwire_archives WHERE result = 'Accepted' AND status = 'pending'

-- Approved content ready for generation
SELECT * FROM wp_rawwire_content WHERE status = 'approved'
```

## Template DataSource Syntax

```json
"dataSource": "db:table_name:filter1=value1,filter2=value2"
```

Examples:
- `"db:archives"` - All archives
- `"db:archives:result=Accepted"` - Accepted items only
- `"db:archives:result=Accepted,status=pending"` - Awaiting approval
- `"db:content"` - Content table
- `"db:queue:status=pending"` - Queue items pending

## Template Action Buttons

### Control Panel Actions
Control panels define buttons with `data-action` attributes:
```json
{
  "type": "button",
  "label": "Sync All Sources",
  "action": "trigger_sync"
}
```

The `RawWireAdmin.Controls.handleAction()` in `template-system.js` dispatches these actions.

Built-in actions:
- `trigger_sync` - Runs scraper/sync
- `clear_cache` - Clears WordPress cache

### Item Actions (REST or AJAX)
For table row buttons, use either REST endpoint or AJAX:

```json
// REST endpoint approach (preferred)
{
  "id": "approve",
  "label": "Approve",
  "endpoint": "/content/approve",
  "method": "POST",
  "payload": {"content_ids": ["{{id}}"]}
}

// AJAX action approach
{
  "id": "reject",
  "label": "Reject",
  "action": "rawwire_reject_item"
}
```

The `.rawwire-action-btn` click handler in `dashboard.js` processes item actions.

## Template Panel Types

| Type | Purpose | Data Source |
|------|---------|-------------|
| `status` | Metrics/statistics display | Template metrics array |
| `control` | Buttons, toggles, inputs | Template controls array |
| `data` | Tables, lists, grids | `dataSource` query |
| `settings` | Form-based configuration | Template fields |
| `custom` | Custom script execution | Template script |

## Feature Toggle System

All features are toggleable from Settings page:
```json
{
  "features": {
    "ai_scoring": {"enabled": true, "toggle": true},
    "auto_approve": {"enabled": false, "threshold": 80},
    "auto_publish": {"enabled": false, "outlets": []}
  }
}
```

Toggles persist as WordPress options: `rawwire_{feature_name}_enabled`

## File Structure

```
cores/
  module-core/      - Module discovery, registration (static code)
  template-engine/  - Template loading, panel rendering (static code)
  toolbox-core/     - Adapters for scraper, generator, poster (static code)

templates/
  news-aggregator.template.json  - THE source of truth for all behavior

modules/
  core/module.php   - Fallback messages ONLY
  sample/           - Example module structure

services/
  class-scraper-service.php     - Scraping logic
  class-scoring-service.php     - AI scoring logic
  class-sync-service.php        - Orchestrates sync flow
```

## When Making Changes

### ✅ DO:
- Add new panel to template JSON
- Add new action to template actions array
- Add generic handler to dashboard.js
- Add REST endpoint that reads template config

### ❌ DON'T:
- Add database queries to modules
- Add HTML generation to modules
- Add embedded JavaScript to modules
- Hardcode panel behavior in PHP

## Red Flags - Stop Immediately If You See:

In any module file:
- `$wpdb->get_results`
- `$wpdb->get_var`
- `<table>` tags
- `<script>` tags
- `$.ajax` or jQuery code
- HTML strings longer than one line

These belong in templates or generic handlers, NOT modules.

## Quick Reference: Where Code Goes

| Need | Location |
|------|----------|
| Database query | Template `dataSource` |
| Table columns | Template `columns` array |
| Action buttons | Template `actions` array |
| Button click handler | dashboard.js generic handler |
| REST endpoint | rest-api.php + template config |
| Panel layout | Template panel definition |
| Feature toggle | Template `features` + Settings page |

## Testing Changes

Always verify:
1. Feature works with template active
2. Removing template shows clean fallback (no errors)
3. Toggle switches enable/disable functionality
4. All database queries use correct tables per SYNC_FLOW_MAP.md

---

**Remember: If you're writing logic in a module, you're doing it wrong. Put it in the template.**
