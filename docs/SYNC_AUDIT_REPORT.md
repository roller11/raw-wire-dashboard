# Sync Function Audit Report
**Date:** January 12, 2026  
**Audit Scope:** Complete sync flow from button click to approvals page data delivery  
**Status:** 🔴 CRITICAL ISSUES FOUND

---

## Executive Summary

The sync function is **NOT properly template-centric**. Multiple critical architectural violations were found where hardcoded values, duplicate implementations, and non-template variables are used throughout the sync flow.

### Critical Issues (Must Fix)
1. ❌ **Data sources hardcoded** in `fetch_github_data()` - should come from template
2. ❌ **Duplicate sync button implementations** - exists in both `class-dashboard.php` AND `dashboard-template.php`
3. ❌ **dashboard-template.php violates template principle** - contains AJAX handlers and hardcoded logic
4. ❌ **Variables not from template** - `$stats`, `$ui_metrics`, `$module` appear without template source
5. ❌ **Fallback shell incomplete** - non-template code won't cleanly fall back

### Medium Priority Issues
6. ⚠️ **Split configuration** - sync config stored in both localStorage and WP options
7. ⚠️ **Module vs Template confusion** - approvals relies on module system, not template panels
8. ⚠️ **Inconsistent data flow** - some data from REST API, some from modules, some hardcoded

---

## Detailed Flow Analysis

### 1. SYNC BUTTON (Entry Point)

**Location:** Two implementations found (VIOLATION)

#### Implementation A: `admin/class-dashboard.php` (Lines 40-42)
```php
<button id="rawwire-sync-btn" class="button button-primary">
    <span class="dashicons dashicons-update"></span>
    <?php _e('Sync Sources', 'raw-wire-dashboard'); ?>
</button>
```
- ✅ Clean button markup
- ❌ Handler in same file (lines 383-402) uses old AJAX pattern
- ❌ Not template-driven

#### Implementation B: `dashboard-template.php` (Lines 138-139)
```php
<button id="fetch-data-btn" class="button button-primary">
    <span class="dashicons dashicons-update"></span> Sync Sources
</button>
```
- ✅ Button markup clean
- ❌ File name suggests it's template-specific but contains AJAX handlers
- ❌ Should be removed when template is removed - NOT HAPPENING

**JavaScript Handler:** `js/sync-manager.js` (Lines 366-373)
```javascript
$(document).on('click', '#rawwire-sync-btn, #fetch-data-btn', function(e) {
    e.preventDefault();
    window.rawwireSyncManager.startSync(this);
});
```
- ✅ Properly delegated event
- ✅ Uses enhanced sync manager
- ⚠️ Handles BOTH button IDs (shouldn't need to)

**ISSUE:** Why are there two different button IDs? This suggests duplicate/conflicting implementations.

---

### 2. SYNC MANAGER (`js/sync-manager.js`)

#### Configuration Loading (Lines 39-74)
```javascript
loadConfig() {
    const saved = localStorage.getItem('rawwire_sync_config');
    // Returns config with sources, limits, keywords, ai settings
}
```

**ISSUE:** ❌ Configuration stored in localStorage, not from template
- Sources list is hardcoded in the default config
- Should be: `RawWire_Template_Engine::get_config('sync.sources')`

#### Sync Execution (Lines 78-145)
- ✅ Good stage-based execution
- ✅ Proper error handling and retry logic
- ❌ Config passed to REST API, but not sourced from template

---

### 3. REST API ENDPOINT (`rest-api.php`)

#### Fetch Data Handler (Lines 529-610)
```php
public function fetch_data($request) {
    $params = $request->get_json_params();
    $config = isset($params['config']) ? $params['config'] : array();
    
    // Store config for reference
    if (!empty($config)) {
        update_option('rawwire_sync_config', $config, false);
        update_option('rawwire_scoring_weights', $config['ai']['weights']);
        // ...
    }
    
    // Use Sync Service or fall back to fetch_github_data()
}
```

**ISSUES:**
- ❌ Stores frontend config to options - splits truth source
- ❌ Falls back to `fetch_github_data()` which has hardcoded sources
- ⚠️ Sync Service exists but isn't consistently used

---

### 4. DATA FETCHING (`raw-wire-dashboard.php`)

#### fetch_github_data() Method (Lines 800-1010)
```php
public function fetch_github_data() {
    // Hard-coded sources array
    $data_sources = array(
        array('name' => 'Federal Register - Rules', 'url' => 'https://...'),
        array('name' => 'Federal Register - Notices', 'url' => 'https://...'),
        array('name' => 'White House Press Briefings', 'url' => 'https://...'),
        array('name' => 'White House Statements', 'url' => 'https://...'),
        array('name' => 'FDA News & Events', 'url' => 'https://...'),
        array('name' => 'EPA News Releases', 'url' => 'https://...'),
        array('name' => 'DOJ Press Releases', 'url' => 'https://...'),
        array('name' => 'SEC Press Releases', 'url' => 'https://...'),
    );
    // ...
}
```

**CRITICAL ISSUE:** ❌ **All data sources are hardcoded!**

**Should be:**
```php
$data_sources = RawWire_Template_Engine::get_config('sync.sources', array());
```

**Template should define:**
```json
{
  "sync": {
    "sources": [
      {"name": "Federal Register - Rules", "url": "https://...", "enabled": true},
      // ...
    ]
  }
}
```

---

### 5. SYNC SERVICE (`services/class-sync-service.php`)

#### run_sync() Method (Lines 76-209)
```php
public function run_sync($config = array()) {
    // PHASE 1: SCRAPE
    $scrape_result = $this->scraper->scrape_all($config);
    
    // PHASE 2: ANALYZE
    if ($this->analyzer) {
        // AI analysis per source
    }
    
    // PHASE 3: STORE
    $store_result = $this->storage->store_items($items_to_store);
}
```

**ANALYSIS:**
- ✅ Clean separation of concerns
- ✅ Accepts config parameter
- ❌ Config still comes from frontend, not template
- ⚠️ Scraper service delegates to hardcoded sources

---

### 6. APPROVALS PAGE DATA DELIVERY

#### Page Renderer (`cores/template-engine/page-renderer.php`)
```php
public static function render_approvals($context = array()) {
    return self::render('approvals', $context);
}
```
- ✅ Uses template system
- ✅ Renders panels from template config

#### Approvals Class (`admin/class-approvals.php`)
```php
public function render() {
    // Attempts to get panels from modules
    if (class_exists('RawWire_Module_Core')) {
        $mods = RawWire_Module_Core::get_modules();
        $panels = $mods['core']->get_admin_panels();
    }
    
    // Looks for panels with role => 'approvals'
    foreach ($panels as $key => $p) {
        if ($p['role'] === 'approvals') {
            // Render panel
        }
    }
}
```

**ISSUES:**
- ⚠️ Uses module system, not pure template system
- ⚠️ Fallback creates hardcoded table markup
- ✅ Data comes from database (`wp_rawwire_content` table)

#### Core Module (`modules/core/module.php` - Lines 520-563)
```php
case 'get_approvals':
    $table = $wpdb->prefix . 'rawwire_content';
    $pending_items = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table WHERE status = %s ORDER BY created_at DESC LIMIT %d",
        'pending', 20
    ), ARRAY_A);
    
    // Returns HTML table with approve/reject buttons
    return $html;
```

**ANALYSIS:**
- ✅ Retrieves synced data from database
- ✅ Filters by 'pending' status
- ❌ Returns HTML string, not data object (tight coupling)
- ⚠️ Module system should be optional

---

### 7. DASHBOARD PANELS

#### Dashboard Template (`dashboard-template.php`)

**Variables Used:**
- `$template_config` - ❌ Not defined in file, assumed from include context
- `$stats` - ❌ Not defined in file
- `$ui_metrics` - ❌ Not defined in file  
- `$module` - ❌ Not defined in file

**AJAX Handlers Defined:**
- `rawwire_save_scoring_weights` (Lines 15-54)
- `rawwire_get_scoring_weights` (Lines 59-73)
- `rawwire_save_filter_settings` (Lines 78-103)
- `rawwire_get_filter_settings` (Lines 108-120)

**CRITICAL ISSUE:** ❌ **This file is NOT removable as claimed!**

The file header states:
```php
/**
 * Dashboard Template - All custom functionality and AJAX handlers
 * This file is completely removable - dashboard will fall back to basic display
 */
```

**BUT:** The AJAX handlers are registered with `add_action()` at the top of the file. If the file is removed, these handlers won't exist and the dashboard will break.

---

## Template Architecture Violations

### What SHOULD Happen (Template-Centric)

1. **Template defines everything:**
```json
{
  "sync": {
    "sources": [...],
    "ai_scoring": {...},
    "filters": {...}
  },
  "panels": [...],
  "pages": [...]
}
```

2. **Backend reads from template:**
```php
$sources = RawWire_Template_Engine::get_config('sync.sources');
$ai_config = RawWire_Template_Engine::get_config('sync.ai_scoring');
```

3. **Frontend gets config from REST:**
```javascript
const config = await fetch('/wp-json/rawwire/v1/template/config');
```

4. **When template is removed:**
- All template-specific UI disappears
- Fallback shell shows: "Create a template to get started"
- No broken functionality

### What IS Happening (Current State)

1. ❌ **Sources hardcoded** in `fetch_github_data()`
2. ❌ **Config split** between localStorage, options, and hardcoded defaults
3. ❌ **AJAX handlers** in dashboard-template.php (not removable)
4. ❌ **Variables** like `$stats`, `$ui_metrics` undefined
5. ❌ **Duplicate implementations** (two sync buttons, two dashboard files)

---

## Recommended Fixes

### Phase 1: Critical Architectural Fixes

1. **Move data sources to template**
   - Add `sync.sources` to template JSON schema
   - Update `fetch_github_data()` to read from template
   - Fallback to empty array if no template

2. **Remove dashboard-template.php AJAX handlers**
   - Move handlers to proper REST API endpoints
   - Or move to a proper class file
   - dashboard-template.php should ONLY contain HTML

3. **Consolidate sync button**
   - Remove one implementation (probably class-dashboard.php)
   - Keep dashboard-template.php button ONLY if template exists
   - Update sync-manager.js to handle single button ID

4. **Define template variables properly**
   - Pass `$template_config`, `$stats`, `$ui_metrics`, `$module` from renderer
   - Or fetch from REST API in JavaScript

### Phase 2: Configuration Unification

5. **Single source of truth for config**
   - Template JSON is master source
   - Backend reads from template engine
   - Frontend gets from REST API (not localStorage)

6. **Approvals page cleanup**
   - Move from module system to pure template system
   - Decouple data retrieval from HTML rendering
   - Support fallback when template is removed

### Phase 3: Fallback Shell

7. **Implement true fallback**
   - Check if template exists before rendering
   - Show "Create Template" UI if none active
   - All template-specific code wrapped in conditionals

---

## Testing Checklist

- [ ] Sync works with active template
- [ ] Sync sources come from template config
- [ ] AI scoring weights come from template
- [ ] Approvals page displays synced data
- [ ] Remove template → dashboard shows fallback shell
- [ ] Remove template → no JavaScript errors
- [ ] Remove template → no broken AJAX handlers
- [ ] Re-add template → all functionality restored

---

## Files Requiring Changes

1. `raw-wire-dashboard.php` (fetch_github_data method)
2. `dashboard-template.php` (remove AJAX handlers, define variables)
3. `admin/class-dashboard.php` (remove duplicate sync button?)
4. `js/sync-manager.js` (read config from REST, not localStorage)
5. `rest-api.php` (fetch_data should use template config)
6. `services/class-sync-service.php` (accept template config)
7. `cores/template-engine/template-engine.php` (add sync config methods)
8. `admin/class-approvals.php` (template-driven rendering)
9. `modules/core/module.php` (decouple from approvals rendering)

---

## Priority Order

1. **Move data sources to template** (blocking everything else)
2. **Fix dashboard-template.php violations** (architectural debt)
3. **Consolidate sync button** (UX confusion)
4. **Unify configuration** (split truth source)
5. **Implement true fallback** (template-centric principle)
6. **Clean up approvals** (module vs template confusion)

---

**Audit Completed:** January 12, 2026  
**Auditor:** GitHub Copilot  
**Status:** Ready for implementation
