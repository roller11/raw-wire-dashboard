# Template-First Architecture

## Critical Design Principle

**MODULES ARE FALLBACKS ONLY**

This is not a suggestion - it's a FUNDAMENTAL ARCHITECTURAL REQUIREMENT that must NEVER be violated.

## What This Means

### ❌ NEVER Put in Modules:
- Database queries (use template `dataSource`)
- HTML rendering (use template config + panel-renderer.php)
- JavaScript handlers (use generic handlers in dashboard.js)
- Action endpoints (use template `actions` array)
- Business logic (use template configuration)
- Table definitions (use template `columns` array)
- Button configurations (use template `actions` with `endpoint`, `payload`, etc.)
- Embedded `<script>` tags
- Hardcoded SQL queries
- Direct wpdb access

### ✅ ALWAYS Put in Template:
- All database queries via `dataSource`: `"db:archives:result=Accepted,status=pending"`
- All table columns via `columns` array
- All actions/buttons via `actions` array with full configuration:
  ```json
  "actions": [{
    "id": "approve",
    "label": "Approve & Generate",
    "endpoint": "/content/approve",
    "method": "POST",
    "payload": {"content_ids": ["{{id}}"]},
    "confirm": "Approve this item?",
    "successMessage": "Item approved!"
  }]
  ```
- All panel definitions
- All page layouts
- All metrics and stats sources

### ✅ Put in Panel Renderer:
- Generic rendering logic driven by template config
- Execute database queries from template `dataSource`
- Render tables/lists based on template `columns`
- Generate action buttons based on template `actions`

### ✅ Put in dashboard.js:
- Generic handlers driven by data attributes
- Example: `.rawwire-action-btn` handler reads endpoint, payload, confirm, etc. from data attributes
- NO hardcoded business logic
- NO specific action implementations

## The Module's ONLY Job

When template is removed or unavailable, modules return simple empty panels:

```php
case 'get_approvals':
    return '<div class="notice notice-info">
        <p>Configure template to display approvals panel.</p>
    </div>';
```

**That's it.** No more, no less.

## Why This Matters

1. **Prevents AI Confusion**: Old embedded code makes AI suggest wrong patterns
2. **Maintainability**: One source of truth (template) vs scattered logic
3. **Flexibility**: Change behavior by editing JSON, not PHP
4. **Testing**: Generic handlers are testable, hardcoded logic is not
5. **Debugging**: Clear data flow from template → renderer → handler

## How to Audit Code

Ask these questions:

1. Does this module method return HTML beyond a simple notice? **❌ VIOLATION**
2. Does this module method query the database? **❌ VIOLATION**
3. Does this module method contain embedded JavaScript? **❌ VIOLATION**
4. Does this module method define action buttons? **❌ VIOLATION**
5. Does this module method have business logic? **❌ VIOLATION**

If you answered YES to ANY question, the code is VIOLATING THE ARCHITECTURE.

## Data Flow (Correct)

```
Template JSON
    ↓
Panel Renderer (reads template config)
    ↓
Execute dataSource query
    ↓
Render based on template columns/actions
    ↓
Output HTML with data attributes
    ↓
Generic JavaScript handler (driven by data attributes)
    ↓
REST endpoint (template-defined)
```

## Data Flow (WRONG - DO NOT DO THIS)

```
Module.php
    ↓
Hardcoded SQL query
    ↓
Hardcoded HTML generation
    ↓
Embedded <script> with specific handlers
    ↓
Hardcoded REST endpoints
```

## Example: Approvals Page (Correct Way)

**Template (news-aggregator.template.json):**
```json
{
  "approval_queue": {
    "type": "data",
    "dataSource": "db:archives:result=Accepted,status=pending",
    "columns": ["title", "source", "score", "created_at", "actions"],
    "actions": [{
      "id": "approve",
      "label": "Approve & Generate",
      "endpoint": "/content/approve",
      "method": "POST",
      "payload": {"content_ids": ["{{id}}"]},
      "confirm": "Approve this item?",
      "successMessage": "Item approved!"
    }]
  }
}
```

**Panel Renderer (panel-renderer.php):**
```php
// Reads template config
$dataSource = $panel['dataSource']; // "db:archives:result=Accepted,status=pending"
$columns = $panel['columns'];
$actions = $panel['actions'];

// Executes query based on dataSource
$items = resolve_db_binding($dataSource);

// Renders table based on template columns
foreach ($items as $item) {
    foreach ($columns as $col) {
        // Render column
    }
    // Render actions with data attributes from template
    render_item_actions($item, $actions);
}
```

**JavaScript (dashboard.js):**
```javascript
// Generic handler - works for ALL actions
$(document).on('click', '.rawwire-action-btn', function() {
    const endpoint = $(this).data('endpoint');
    const payload = $(this).data('payload');
    const confirm = $(this).data('confirm');
    // ... execute based on data attributes
});
```

**Module (module.php):**
```php
case 'get_approvals':
    // ONLY fallback message
    return '<div class="notice">Configure template</div>';
```

## Enforcement

**Code reviews must reject:**
- Any module code beyond simple fallback messages
- Any hardcoded HTML/SQL/JavaScript in modules
- Any business logic in modules

**Every PR must verify:**
- All functionality comes from template configuration
- Modules contain only fallback messages
- Generic handlers are used, not specific implementations

## Migration Checklist

When you find code that violates this architecture:

- [ ] Extract database query → add to template `dataSource`
- [ ] Extract HTML table → add to template `columns`
- [ ] Extract action buttons → add to template `actions`
- [ ] Extract JavaScript handler → add generic handler to dashboard.js
- [ ] Replace module method with simple fallback message
- [ ] Test that template-driven version works
- [ ] Delete old code completely (don't comment out)
- [ ] Update documentation

## Red Flags

If you see ANY of these in a module file, **STOP IMMEDIATELY** - you're looking at a violation:

- `$wpdb->get_results`
- `$wpdb->get_var`
- `$wpdb->prepare`
- `<table>`
- `<script>`
- `$.ajax`
- `$(document).on`
- `fetch()`
- Embedded HTML strings longer than one line
- Direct database table names

## Remember

**If you're writing code in a module that does more than return a simple fallback message, YOU ARE DOING IT WRONG.**

Put it in the template instead.
