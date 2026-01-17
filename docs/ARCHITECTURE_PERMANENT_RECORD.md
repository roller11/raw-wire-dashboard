# CRITICAL: Template-First Architecture - Permanent Record

## ⚠️ READ THIS FIRST ⚠️

**Date**: January 13, 2026  
**Context**: Complete architectural audit and remediation  
**Status**: ✅ COMPLIANT

## The Fundamental Rule

**MODULES ARE FALLBACKS ONLY**

This is NOT optional. This is NOT a suggestion. This is a **CRITICAL ARCHITECTURAL REQUIREMENT**.

## What Just Happened

We discovered and eliminated **~520 lines of hardcoded violations** in `modules/core/module.php`:

- ❌ Embedded SQL queries
- ❌ Hardcoded HTML table generation  
- ❌ Embedded JavaScript with jQuery handlers
- ❌ Direct database access
- ❌ Hardcoded REST endpoint calls
- ❌ Business logic in modules

**All of this has been REMOVED.**

## The Correct Pattern

### Template Defines Everything:
```json
{
  "approval_queue": {
    "type": "data",
    "dataSource": "db:archives:result=Accepted,status=pending",
    "columns": ["title", "source", "actions"],
    "actions": [{
      "id": "approve",
      "endpoint": "/content/approve",
      "payload": {"content_ids": ["{{id}}"]}
    }]
  }
}
```

### Panel Renderer Executes:
```php
// Reads template config
$dataSource = $panel['dataSource'];
$items = resolve_db_binding($dataSource);
// Renders based on template
```

### Generic Handler Processes:
```javascript
$('.rawwire-action-btn').on('click', function() {
  const endpoint = $(this).data('endpoint');
  // All config from data attributes
});
```

### Module Provides Fallback:
```php
case 'get_approvals':
    return '<div class="notice">Configure template</div>';
```

## Why This Matters for AI

When AI sees old embedded code, it suggests WRONG patterns:
- "Let me add a database query to the module..." ❌
- "I'll embed some JavaScript in the HTML..." ❌  
- "I'll hardcode this table structure..." ❌

With clean architecture, AI suggests RIGHT patterns:
- "Let me add this to the template dataSource..." ✅
- "I'll use the generic handler in dashboard.js..." ✅
- "I'll configure the columns in the template..." ✅

## Verification Commands

Run these to verify compliance:

```powershell
# Check module line count (should be ~180-200)
Get-Content "wordpress-plugins\raw-wire-dashboard\modules\core\module.php" | Measure-Object -Line

# Check for violations (should return NO matches)
Select-String -Path "wordpress-plugins\raw-wire-dashboard\modules\**\*.php" -Pattern "<table"
Select-String -Path "wordpress-plugins\raw-wire-dashboard\modules\**\*.php" -Pattern "<script"
Select-String -Path "wordpress-plugins\raw-wire-dashboard\modules\**\*.php" -Pattern "jQuery|\.ajax"
```

## Files to Reference

1. **docs/TEMPLATE_FIRST_ARCHITECTURE.md** - Complete architecture guide
2. **docs/ARCHITECTURAL_AUDIT_2026-01-13.md** - Detailed audit report  
3. **templates/news-aggregator.template.json** - Example of correct template configuration
4. **cores/template-engine/panel-renderer.php** - Generic renderer driven by template
5. **dashboard.js** (lines 1139-1215) - Generic action button handler

## Red Flags

If you EVER see these in a module file, **STOP IMMEDIATELY**:

- `$wpdb->get_results`
- `$wpdb->get_var`
- `<table>`
- `<script>`
- `$.ajax`
- HTML strings longer than one line
- Direct database table names

## The Only Acceptable Module Code

```php
public function handle_ajax($action, $data) {
    switch ($action) {
        case 'get_approvals':
            return '<div class="notice notice-info">
                <p>Configure template to display approvals.</p>
            </div>';
        // More simple fallbacks...
    }
}
```

That's it. Nothing more.

## Enforcement

**Code reviews MUST reject:**
- Any module code beyond simple fallback messages
- Any hardcoded HTML/SQL/JavaScript in modules
- Any business logic in modules
- Any direct database access in modules

**Every PR MUST verify:**
- All functionality comes from template
- Modules contain only fallback messages
- Generic handlers are used

## Current State

✅ **modules/core/module.php**: 182 lines (was 703)  
✅ **No embedded SQL queries**  
✅ **No embedded HTML tables**  
✅ **No embedded JavaScript**  
✅ **No hardcoded endpoints**  
✅ **Only simple fallback messages**

## Template-Driven Panels

These panels are CORRECTLY configured in template:
- ✅ approval_queue (dataSource, columns, actions defined)
- ✅ Statistics (queries via dataSource)
- ✅ Action buttons (configured in template actions array)

These panels show fallback messages (CORRECT behavior):
- ⚠️ Overview (no template definition yet)
- ⚠️ Sources (no template definition yet)
- ⚠️ Queue (no template definition yet)
- ⚠️ Logs (no template definition yet)
- ⚠️ Insights (no template definition yet)

## If Someone Violates This

1. **Find it early**: Run verification commands
2. **Reject the PR**: Don't merge violations
3. **Educate**: Point to this document
4. **Fix it**: Move logic to template
5. **Delete**: Remove violation completely

## Remember

**If you're writing code in a module that does more than return a simple fallback message, YOU ARE VIOLATING THE ARCHITECTURE.**

Put it in the template instead.

---

**DO NOT DELETE THIS FILE**  
**DO NOT IGNORE THIS PRINCIPLE**  
**DO NOT BYPASS THIS ARCHITECTURE**

This is permanent record of a critical architectural decision.

**Status**: ✅ COMPLIANT (as of January 13, 2026)

