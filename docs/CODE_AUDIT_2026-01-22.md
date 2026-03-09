# Raw Wire Dashboard - Code Audit Report
**Date:** January 22, 2026  
**Version Audited:** 1.0.28  
**Total Active PHP Files:** 99 (excluding _archive, vendor)

---

## Executive Summary

This audit identified **~2,500 lines of dead/redundant code** across the codebase, with opportunities to reduce complexity by **~25%**. Key findings include:

- **3 parallel workflow implementations** (should be 1)
- **1 incomplete feature** (AI Discovery - no real AI)
- **1 redundant system** (Tool Registry vs MCP Server)
- **15+ mock AJAX handlers** returning fake data
- **7 console.log debug statements** in JS
- **2 files that can be deleted**

---

## 🔴 HIGH PRIORITY - Delete/Archive

### 1. AI Discovery Feature (ARCHIVE)
**Location:** `cores/ai-discovery/ai-discovery.php` (1,266 lines)

**Issues:**
- Name says "AI Discovery" but uses keyword matching, NOT AI
- Missing methods referenced in switch statement (`discover_from_noaa`, `discover_from_nasa`, `discover_from_bls`)
- Empty API keys for Congress.gov
- Incomplete source implementations

**Action:** Move to `_archive/incomplete-features/ai-discovery/`

### 2. MPC Module (DELETE)
**Location:** `modules/mpc-module/` (entire directory)

**Issues:**
- `class-mpc-client.php` returns only hardcoded mock data
- No real MPC connection functionality
- Provides zero value

**Action:** Delete directory

### 3. control-panels.js (DELETE)
**Location:** `js/control-panels.js` (409 lines)

**Issues:**
- Never enqueued anywhere in codebase
- Contains console.log statement
- Functionality may overlap with dashboard.js

**Action:** Delete file

### 4. run-migrations.php (DELETE)
**Location:** `services/run-migrations.php`

**Issues:**
- CLI script with hardcoded wp-load.php path
- Not integrated with WP-CLI or admin
- Non-functional in most environments

**Action:** Delete file

---

## 🟡 MEDIUM PRIORITY - Deprecate/Consolidate

### 5. Service Layer Redundancy
Three services duplicate workflow functionality:

| Service | Lines | Issue |
|---------|-------|-------|
| `class-scoring-handler.php` | 246 | Duplicates Workflow_Orchestrator scoring |
| `class-storage-service.php` | ~200 | Uses obsolete single-table design |
| `class-sync-service.php` | ~300 | Duplicates Workflow_Orchestrator entirely |

**Action:** Deprecate these three services, route all operations through `class-workflow-orchestrator.php`

### 6. Tool Registry Redundancy
**Location:** `cores/toolbox-core/class-tool-registry.php` (623 lines)

**Issue:** Duplicates functionality in `class-mcp-server.php`:
- Both have `register_tool()` / `register()`
- Both have `execute_tool()` / `execute()`
- Both have `get_tools()` / `get_all()`

**Action:** Consolidate into MCP Server or convert to thin wrapper

### 7. Mock AJAX Handlers in class-admin.php
**Location:** `includes/class-admin.php`

**Dead Methods (never registered with add_action):**
- `ajax_sync()` - never hooked
- `ajax_get_stats()` - returns mock data
- `ajax_get_overview()` - returns mock data
- `ajax_get_sources()` - returns mock data
- `ajax_get_queue()` - returns mock data
- `ajax_get_insights()` - returns mock data
- `ajax_ai_chat()` - returns mock responses

**Action:** Remove dead methods or implement properly

### 8. Disabled Groq Engine
**Location:** `includes/integrations/class-groq-engine.php`

**Issue:** Line 20 contains `return;` that completely disables the class

**Action:** Either delete file or remove the `return;` statement

---

## 🟢 LOW PRIORITY - Cleanup

### 9. Console.log Statements to Remove

| File | Line | Statement |
|------|------|-----------|
| `dashboard.js` | 2 | `console.log('Raw-Wire loaded');` |
| `dashboard.js` | 3 | `console.log('jQuery ready handler...');` |
| `dashboard.js` | 6 | `console.log('templateBuilderElements...');` |
| `dashboard.js` | 7 | `console.log('Feature checkboxes...');` |
| `dashboard.js` | 8 | `console.log('Page checkboxes...');` |
| `dashboard.js` | 40 | `console.log('Workflow action:', action);` |
| `template-system.js` | 500 | `console.log('Init complete');` |

### 10. Legacy Table References
**Location:** `cores/template-engine/workflow-handlers.php` (lines 81-84)

```php
// DEPRECATED - remove these
'findings' => $wpdb->prefix . 'rawwire_findings',
'queue'    => $wpdb->prefix . 'rawwire_queue',
```

### 11. Dead Method in module-core.php
**Location:** `cores/module-core/module-core.php` (lines 213-215)

```php
protected static function load_legacy_template_file() {
    // Legacy method - no longer used
    return null;
}
```

### 12. Unused Custom Renderers Array
**Location:** `cores/template-engine/panel-renderer.php`

```php
protected static $custom_renderers = array(); // Never populated or used
```

### 13. Mock Data in Core Module
**Location:** `modules/core/module.php`

Replace `rand()` calls with real database queries:
- Lines 96-107: `get_overview()` uses random numbers
- Lines 252-268: `get_queue()` uses random numbers
- Lines 342-356: `get_insights()` uses random numbers

### 14. Incomplete uninstall.php
**Location:** `uninstall.php`

**Missing table cleanup:**
```php
// Add these:
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}rawwire_candidates");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}rawwire_approvals");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}rawwire_releases");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}rawwire_published");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}rawwire_archives");
```

**Missing option cleanup:**
```php
delete_option('rawwire_active_module');
delete_option('rawwire_ollama_host');
delete_option('rawwire_scoring_batch_size');
delete_option('rawwire_auto_approve_threshold');
delete_option('rawwire_ollama_ai_engine_configured');
delete_option('rawwire_engine_extensions');
delete_option('rawwire_chat_visibility');
delete_option('rawwire_caps_registered');
```

### 15. Security: refresh_ollama.php
**Location:** `refresh_ollama.php`

Add CLI-only protection:
```php
if (php_sapi_name() !== 'cli') {
    die('CLI access only');
}
```

---

## Security Concerns

### 1. Public REST Endpoints
**Location:** `rest-api.php` (lines 387-388)

```php
'/filters' => '__return_true'   // Exposes status values
'/content' => '__return_true'   // Exposes content to public!
```

**Recommendation:** Add rate limiting or require read capability

### 2. Missing isset() Checks
**Location:** `includes/class-admin.php` (line 82)

```php
$status = sanitize_text_field($_POST['status']); // Should check isset() first
```

---

## Estimated Impact After Cleanup

| Metric | Before | After | Change |
|--------|--------|-------|--------|
| PHP Files | 99 | 93 | -6% |
| Total Lines | ~15,000 | ~11,500 | -23% |
| Dead Code | ~2,500 | 0 | -100% |
| Duplicate Services | 3 | 0 | -100% |

---

## Recommended Execution Order

1. **Phase 1 - Quick Wins (30 min)**
   - Remove console.log statements
   - Delete control-panels.js
   - Delete run-migrations.php
   - Delete mpc-module/

2. **Phase 2 - Archive (1 hour)**
   - Move ai-discovery to _archive
   - Remove from main plugin initialization

3. **Phase 3 - Consolidation (2-3 hours)**
   - Deprecate redundant services
   - Update uninstall.php
   - Add security fixes

4. **Phase 4 - Polish (1-2 hours)**
   - Remove mock data
   - Clean up dead methods
   - Remove legacy references
