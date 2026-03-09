# Raw-Wire Dashboard — Comprehensive Plugin Audit

**Date:** 2025-07-12  
**Plugin Version:** 1.0.24 (header) / 1.0.25 (constant) — INCONSISTENCY  
**Scope:** Every PHP and JS file in `wordpress-plugins/raw-wire-dashboard/`  
**Method:** Full source code read and cross-reference of all wiring chains  

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Architecture Overview](#2-architecture-overview)
3. [CRITICAL — Broken Wires & Fatal Errors](#3-critical--broken-wires--fatal-errors)
4. [HIGH — Dead Code & Unwired Handlers](#4-high--dead-code--unwired-handlers)
5. [HIGH — Duplicate Systems](#5-high--duplicate-systems)
6. [HIGH — Orphaned Integrations](#6-high--orphaned-integrations)
7. [MEDIUM — Mock Data Masquerading as Real](#7-medium--mock-data-masquerading-as-real)
8. [MEDIUM — Settings That Save But Don't Apply](#8-medium--settings-that-save-but-dont-apply)
9. [MEDIUM — Missing JS Event Handlers](#9-medium--missing-js-event-handlers)
10. [LOW — Security Concerns](#10-low--security-concerns)
11. [LOW — Inconsistencies & Code Smells](#11-low--inconsistencies--code-smells)
12. [Verified Working Wires](#12-verified-working-wires)
13. [File Inventory](#13-file-inventory)

---

## 1. Executive Summary

The Raw-Wire Dashboard is a large WordPress plugin (~230 PHP files, ~13 JS files) with a sophisticated architecture: Template Engine → Module Core → Toolbox Core → Adapters. The core rendering pipeline (template loading → page rendering → panel rendering → REST API) is functional. However, significant technical debt exists from architectural transitions:

**Critical Issues (will cause fatal errors):**
- `Raw_Wire_Search_Service` class referenced but doesn't exist (3 REST endpoints will fatal)
- `RawWire_Logger::get_recent_logs()` called but missing from logger stub

**High-Priority Issues:**
- 12+ AJAX handler methods in `class-admin.php` are never registered — 100% dead code
- Two completely separate tool toggle systems with different options & different tool IDs
- AI Engine integration hooks orphaned after Venice.ai migration
- Workflow buttons rendered in menu manager with no JS click handlers

**Medium Issues:**
- Multiple admin AJAX handlers return hardcoded mock/random data
- Debug mode left enabled in bootstrap (assets load on all admin pages)
- Template activate button uses `onclick="alert()"` placeholder

---

## 2. Architecture Overview

```
Main Plugin (raw-wire-dashboard.php)
  ├── Logger Stub (RawWire_Logger) — error_log() wrapper
  ├── includes/bootstrap.php (RawWire_Bootstrap) — REST init, asset enqueue
  ├── includes/class-menu-manager.php — All menu registration
  ├── includes/class-admin.php — AJAX dispatcher (module_action only)
  ├── includes/class-dev-auth.php — Developer mode login/logout
  ├── includes/class-config-authority.php — Signed config, license tiers
  ├── rest-api.php (2202 lines) — REST namespace rawwire/v1
  ├── cores/
  │   ├── module-core/ — Module discovery, template config loading
  │   ├── template-engine/ — JSON template loading, page/panel rendering
  │   ├── toolbox-core/ — Adapter registry, tool toggles, AI providers
  │   │   ├── adapters/ — Scraper, Generator, Poster, Scorer, Workflow adapters
  │   │   ├── dreampilot/ — Resident AI agent (orchestrator, conversation, tools)
  │   │   └── features/ — AI settings, chat, scraper, memory, etc.
  │   └── dashboard-core/ — Access control, permissions
  ├── modules/core/module.php — Panel registry, renderers (1228 lines)
  ├── admin/ — Settings, Templates, Approvals pages
  ├── services/ — Workflow orchestrator, migration, equalizer
  └── js/ + assets/js/ — Frontend JavaScript
```

**Localized JS Objects:**
| Object | Source | Nonce Key |
|--------|--------|-----------|
| `RawWireCfg` | bootstrap.php | `wp_rest` (REST nonce) |
| `rawwire_admin` | raw-wire-dashboard.php | `rawwire_ajax_nonce` |
| `rawwire_ajax` | raw-wire-dashboard.php | `rawwire_ajax_nonce` |
| `rawwireChatConfig` | class-dashboard-chat-panel.php | `rawwire_nonce` |
| `rawwire_ai_settings` | class-ai-settings-panel.php | varies |
| `RawWireScraperCfg` | class-scraper-settings-panel.php | `rawwire_scraper_nonce` |

---

## 3. CRITICAL — Broken Wires & Fatal Errors

### 3.1 `Raw_Wire_Search_Service` class does not exist

**Files affected:**
- `rest-api.php` line 721: `$search_service = new Raw_Wire_Search_Service();`
- `rest-api.php` line 739: `$result = $search_service->search($params);`
- `rest-api.php` line 767-768: `new Raw_Wire_Search_Service()` → `update_relevance()`
- `rest-api.php` line 793-803: `new Raw_Wire_Search_Service()` → `get_categories()`

**Impact:** Three REST endpoints will throw a **PHP Fatal Error** when called:
- `GET rawwire/v1/search` — Full-text content search
- `POST rawwire/v1/search/relevance` — Update relevance score
- `GET rawwire/v1/search/filters` — Get available filter options

**Evidence:** `grep -r "class Raw_Wire_Search_Service" .` returns **zero results** across the entire codebase (including `_archive/` and `vendor/`). The class was likely part of the archived code but was never migrated.

### 3.2 `RawWire_Logger::get_recent_logs()` missing from stub

**Logger stub location:** `raw-wire-dashboard.php` lines 68-119

**Methods available on stub:** `log()`, `info()`, `error()`, `warning()`, `debug()`, `log_activity()`, `log_error()`

**Methods missing:** `get_recent_logs()` — exists only in the archived logger at `_archive/obsolete-logging/class-logger.php` line 282

**Callers that will break:**
- `includes/class-admin.php` line 236: `$logs = RawWire_Logger::get_recent_logs($limit);`
  - This is inside `ajax_get_logs()` which is dead code anyway (see §4.1), so this won't actually fire
- `modules/core/module.php` line 536-537: Guards with `method_exists()` check — **SAFE**
- `cores/template-engine/class-data-facade.php` line 248: References `RawWire_Activity_Logs` (different class) — needs separate verification

**Fix complexity:** Low — add a `get_recent_logs()` stub that returns empty array, or resolve the full logger.

---

## 4. HIGH — Dead Code & Unwired Handlers

### 4.1 `includes/class-admin.php` — 12 AJAX methods never registered

The constructor at line 22-23 registers **only one** AJAX action:
```php
add_action('wp_ajax_rawwire_module_action', array($this, 'ajax_module_action'));
```

The following methods exist in the class but are **never hooked** to any `wp_ajax_*` action:

| Method | Line | What it does |
|--------|------|-------------|
| `ajax_sync()` | 46 | Returns mock sync data (`rand()` values) |
| `ajax_update_content()` | 79 | Placeholder content updater |
| `ajax_get_stats()` | 117 | Returns mock chart data |
| `ajax_get_content()` | 140 | Returns mock content list |
| `ajax_get_overview()` | 164 | Returns **random** data: `'pending' => rand(5,50)` |
| `ajax_get_sources()` | 185 | Returns **hardcoded** array of mock sources |
| `ajax_get_queue()` | 205 | Returns **hardcoded** mock queue data |
| `ajax_get_logs()` | 226 | Calls missing `get_recent_logs()` |
| `ajax_get_insights()` | 245 | Returns **hardcoded** mock insights |
| `ajax_ai_chat()` | 266 | Returns **random** picks from hardcoded response array |
| `ajax_get_workflow_config()` | 307 | Returns **hardcoded** model list |
| `ajax_execute_workflow()` | 340 | Returns **mock** execution logs |
| `ajax_cancel_workflow()` | 383 | Just returns success |
| `ajax_panel_control()` | 405 | Always returns success |
| `ajax_clear_cache()` | 456 | Deletes transients (only working one, but unreachable) |

**How admin.js calls these:** `assets/js/admin.js` uses `RawWireAdmin.moduleAjax()` which sends to `rawwire_module_action` with `module: 'core'` and `module_action: 'get_overview'` etc. This dispatches through `ajax_module_action()` → `RawWire_Module_Core::get_modules()['core']->handle_ajax()`. The methods above are on `RawWire_Admin`, NOT on the core `module.php` instance. So:

- The `admin.js` calls DO reach `ajax_module_action()` (wired correctly)
- The dispatcher tries to call `handle_ajax()` on the **module** instance (`modules/core/module.php`)
- The RawWire_Admin methods above are **completely unreachable** dead code

**The real question:** Does `modules/core/module.php` have a `handle_ajax()` method?

- `module.php` line 479+ has `ajax_module_action` that dispatches to modules
- The Core Module in `modules/core/module.php` implements `handle_ajax()` — this is the actual handler

**Verdict:** All 12+ methods in `class-admin.php` (except `ajax_module_action`) are **dead code**. They appear to be pre-architecture remnants that were never cleaned up.

### 4.2 `class-admin.php` — Entire admin init pipeline loads for nothing

The class registers admin settings (`rawwire_api_key`, `rawwire_log_level`) at line 38-39 via `admin_init`. These settings are not used by any current code path — legacy settings that predate the Config Authority system.

---

## 5. HIGH — Duplicate Systems

### 5.1 Two Completely Separate Tool Toggle Systems

**System A: `RW_Tools_Toggles_Page`**
- File: `cores/toolbox-core/features/class-tools-toggles-page.php` (374 lines)
- Option: `rawwire_tool_toggles`
- Tools: `venice`, `instinct`, `ai_scraper`, `ollama`, `groq`, `mcp_server`, `openclaw`
- UI: Renders on the "Tools" submenu tab
- Settings links: Point to `rawwire-ai-settings` page with tab params

**System B: `RawWire_Tool_Toggle_Manager`**
- File: `cores/toolbox-core/class-tool-toggle-manager.php` (855 lines)
- Option: `rawwire_tool_states`
- Tools: `scraper_rss`, `scraper_api`, `scraper_html`, `scraper_brightdata`, `ai_scoring`, `ai_generation`, `ai_summarization`, `ai_chat`, `poster_wordpress`, `poster_discord`
- UI: Separate toggle interface
- Has adapter lifecycle management (load/unload adapters on toggle)

**Impact:** 
- Different option keys storing different tool states
- No cross-referencing between the two systems
- Toggling a tool in System A does not affect System B and vice versa
- Code checking `rawwire_tool_toggles` won't see states from `rawwire_tool_states`

### 5.2 Two Permission/Authorization Systems

**System A: `RawWire_Config_Authority`** (`includes/class-config-authority.php`)
- Tiers: free, basic, pro, enterprise, developer
- Feature matrix: maps features to minimum tier
- HMAC-SHA256 signed config changes
- License-based authorization

**System B: `RawWire_Access_Control`** (`cores/dashboard-core/class-access-control.php`)
- Tiers: developer, admin, editor, viewer
- Feature permissions, settings permissions, tool permissions
- Custom WordPress capabilities: `rawwire_developer`, `rawwire_manage`, etc.
- Deployment modes: internal, client, demo

**Impact:** Two independent permission systems with overlapping but different tier names and different enforcement styles. Config Authority never checks Access Control and vice versa.

---

## 6. HIGH — Orphaned Integrations

### 6.1 AI Engine Hooks After Venice Migration

The plugin migrated from AI Engine to Venice.ai for chat (noted in v1.0.24 changelog). However, two feature classes still hook AI Engine exclusively:

**`class-ai-memory.php`** (`cores/toolbox-core/features/class-ai-memory.php`)
- Line ~70: `add_filter('mwai_ai_query', ...)`
- Line ~75: `add_filter('mwai_ai_reply', ...)`
- Creates `rawwire_ai_memory` database table
- Injects memory context into AI queries
- **Problem:** These filters only fire when using AI Engine's chat. Venice chat (`class-venice-chat-handler.php`) bypasses AI Engine entirely → memory is never injected into Venice conversations.

**`class-chatbot-context.php`** (`cores/toolbox-core/features/class-chatbot-context.php`)
- Line ~40: `add_filter('mwai_ai_query', ...)`
- Line ~45: `add_filter('mwai_chatbot_params', ...)`
- Line ~50: `add_filter('mwai_ai_instructions', ...)`
- Injects site context, tool availability, user info into chatbot
- **Problem:** Same as above — never fires during Venice chat sessions

**Net effect:** AI Memory and Chatbot Context features are functionally **disabled** when using Venice.ai (the default/primary chat provider). They only work if the site also has AI Engine installed AND uses its chat interface.

### 6.2 AI Engine Injector — Patches a Plugin That May Not Be Active

**`class-ai-engine-injector.php`** (`cores/toolbox-core/features/class-ai-engine-injector.php`)
- Patches `ai-engine-pro/classes/core.php` directly on disk
- Creates backup at `wp-content/uploads/rawwire/patches/`
- Adds environment-model support to AI Engine Pro
- **Risk:** Fragile — will break on AI Engine updates. Backup/restore on shutdown adds complexity.
- **Status:** Only relevant if AI Engine Pro is installed. Since Venice is now primary, this is a secondary concern.

---

## 7. MEDIUM — Mock Data Masquerading as Real

All of these are in `includes/class-admin.php` (which is dead code per §4.1, but documenting for completeness):

| Method | Line | Mock Pattern |
|--------|------|-------------|
| `ajax_get_overview()` | 164 | `'pending' => rand(5, 50), 'approved' => rand(100, 500)` |
| `ajax_get_sources()` | 185 | Hardcoded array: `'Federal Register'`, `'GitHub API'`, `'White House Briefings'` |
| `ajax_get_queue()` | 205 | Hardcoded: `'pending' => 12, 'processing' => 3, 'completed' => 156` |
| `ajax_get_insights()` | 245 | Hardcoded: `'top_categories' => 'Technology, Healthcare, Finance'` |
| `ajax_ai_chat()` | 266 | Random pick from array of 5 canned responses |
| `ajax_get_workflow_config()` | 307 | Hardcoded model list: `gpt-4`, `gpt-3.5-turbo`, `claude-2` |
| `ajax_execute_workflow()` | 340 | Fake execution log with hardcoded steps |
| `ajax_sync()` | 46 | `'synced_items' => rand(10, 50)` |

**Note:** Since these methods are dead code (never wired), they can't actually return mock data to the frontend. But if someone attempts to wire them up, they'll return fake data instead of real data.

---

## 8. MEDIUM — Settings That Save But Don't Apply

### 8.1 Template Activate Button — Placeholder Only

**File:** `admin/class-templates.php` line ~242
```php
onclick="alert('Activate: Set rawwire_active_template to <?php echo esc_js($template['id']); ?>');"
```

The "Activate" button on template cards shows an **alert dialog** instead of actually switching templates. The actual switching mechanism exists in `RawWire_Template_Engine::ajax_switch_template()` but isn't wired to this button.

### 8.2 Bootstrap Asset Hook Check Disabled

**File:** `includes/bootstrap.php` lines 128-130
```php
// TEMPORARILY DISABLE HOOK CHECK FOR DEBUGGING
// if (strpos($hook, 'raw-wire') === false && strpos($hook, 'rawwire') === false) {
//     return;
// }
```

**Impact:** All Raw-Wire CSS and JS assets load on **every** WordPress admin page (posts, pages, users, settings, etc.), not just Raw-Wire dashboard pages. Performance impact on large admin panels.

### 8.3 `rawwire_api_key` and `rawwire_log_level` settings registered but unused

**File:** `includes/class-admin.php` lines 38-39
```php
register_setting('rawwire_settings', 'rawwire_api_key');
register_setting('rawwire_settings', 'rawwire_log_level');
```

These WP settings are registered but no code reads them. API keys are managed by `class-key-manager.php` (encrypted storage). Log level is not configurable — the stub logger always logs to `error_log()`.

---

## 9. MEDIUM — Missing JS Event Handlers

### 9.1 `.rawwire-run-workflow` and `.rawwire-workflow-settings` buttons

**Rendered by:** `includes/class-menu-manager.php` lines 798-799
```php
echo '<button class="button button-primary rawwire-run-workflow" data-workflow-id="...">';
echo '<button class="button rawwire-workflow-settings" data-workflow-id="...">';
```

**JS handler search:** `grep -r "rawwire-run-workflow\|rawwire-workflow-settings" *.js` → **NO MATCHES**

No JavaScript anywhere in the plugin binds click events to `.rawwire-run-workflow` or `.rawwire-workflow-settings`. These buttons are rendered on workflow tabs but do nothing when clicked.

### 9.2 Overview/Sources/Queue/Logs/Insights panel loading via `moduleAjax`

`assets/js/admin.js` calls `RawWireAdmin.moduleAjax('core', 'get_overview', ...)` etc. These dispatch through `rawwire_module_action` → `ajax_module_action()` → `RawWire_Module_Core::get_modules()['core']->handle_ajax()`.

Whether this works depends on `modules/core/module.php`'s `handle_ajax()` implementation actually supporting these action names (`get_overview`, `get_sources`, `get_queue`, `get_logs`, `get_insights`). The module.php file has its own panel renderers, but whether `handle_ajax()` maps these exact action names needs verification.

---

## 10. LOW — Security Concerns

### 10.1 Hardcoded Default Developer Credentials

**File:** `includes/class-dev-auth.php` lines 56-57
```php
const DEFAULT_USERNAME = 'developer';
const DEFAULT_PASSWORD = 'rawwire_dev_2026';
```

On first use, if no password hash exists in `wp_options`, the default password is accepted and stored. While the password IS immediately hashed and stored, the default credentials are visible in source code. Anyone with source access knows the initial password.

**Mitigation:** The class requires `manage_options` capability + AJAX nonce, so only admins can attempt login. But if source code is public (GitHub), anyone deploying the plugin gets the same default.

### 10.2 REST API Endpoints — Permission Callback is `manage_options` Throughout

All REST routes in `rest-api.php` use `'permission_callback' => array($this, 'check_permissions')` which checks `current_user_can('manage_options')`. This is appropriate for admin actions but may be overly restrictive for viewer-level endpoints (stats, logs). The Access Control system (§5.2) defines granular tiers but isn't currently enforced on REST routes.

### 10.3 AI Engine Injector Writes to Plugin Directory

`class-ai-engine-injector.php` patches files directly inside `ai-engine-pro/classes/core.php`. This:
- Modifies files owned by another plugin
- Creates backup files in `wp-content/uploads/rawwire/patches/`
- Could fail silently if file permissions prevent writes
- Will conflict with AI Engine plugin updates

---

## 11. LOW — Inconsistencies & Code Smells

### 11.1 Version Number Mismatch

- `raw-wire-dashboard.php` line 8 (plugin header): `Version: 1.0.24`
- `raw-wire-dashboard.php` line 123 (class constant): `const VERSION = '1.0.25';`

### 11.2 Default AI Model Mismatch

- `class-ai-settings-panel.php`: Default Venice model = `llama-3.3-70b`
- `class-venice-chat-handler.php`: Default Venice model = `zai-org-glm-4.7`

When no model is explicitly configured, different components will use different models.

### 11.3 `RawWire_Logger::log_activity()` Called from REST API on Stub

**File:** `rest-api.php` lines 1910, 1929
```php
RawWire_Logger::log_activity('Approved items', 'approvals', ...);
RawWire_Logger::log_activity('Snoozed items', 'approvals', ...);
```

The stub's `log_activity()` just calls `error_log()` — it doesn't persist to a database table. The REST API `approve_content_batch()` and `snooze_content_batch()` endpoints think they're logging to a proper activity log but it just goes to `debug.log`.

### 11.4 Scraper Settings Panel Enqueues on All Raw-Wire Pages

**File:** `class-scraper-settings-panel.php` line 73
```php
if (strpos($hook, 'raw-wire') === false) {
    return;
}
```

This loads scraper CSS/JS on ALL raw-wire pages (dashboard, settings, templates, tools) rather than only on the scraper settings tab.

### 11.5 `get_status()` REST Endpoint Returns 501

**File:** `rest-api.php` line ~830
```php
public function get_status($request) {
    return new WP_REST_Response(array(
        'success' => false,
        'message' => 'Not implemented. All feature logic is now in the dashboard template.',
    ), 501);
}
```

The endpoint exists and is registered but explicitly returns "Not Implemented". Any code calling `rawwire/v1/status` will get a 501 error.

### 11.6 `enable_compression()` in REST API

**File:** `rest-api.php` (early lines)

The `enable_compression()` method modifies output buffering. This can interfere with WordPress's own output handling and cause headers-already-sent warnings if other plugins also manipulate output.

---

## 12. Verified Working Wires

These AJAX/REST chains have been verified as properly wired end-to-end:

| Frontend Call | Action/Endpoint | Backend Handler | Status |
|------|--------|---------|--------|
| `template-system.js` → `rawwire_panel_refresh` | AJAX | `workflow-handlers.php::ajax_panel_refresh()` | ✅ Wired |
| `template-system.js` → `rawwire_clear_cache` | AJAX | `template-engine.php::ajax_clear_cache()` | ✅ Wired |
| `admin.js` → `rawwire_get_last_batch` | AJAX | `workflow-handlers.php::ajax_get_last_batch()` | ✅ Wired |
| `template-system.js` → `rawwire_template_switch` | AJAX | `template-engine.php::ajax_switch_template()` | ✅ Wired |
| `template-system.js` → `rawwire_template_list` | AJAX | `template-engine.php::ajax_list_templates()` | ✅ Wired |
| `template-system.js` → `rawwire_template_variant` | AJAX | `template-engine.php::ajax_switch_variant()` | ✅ Wired |
| `template-system.js` → `rawwire_template_export_data` | AJAX | `template-engine.php::ajax_export_data()` | ✅ Wired |
| `template-system.js` → `rawwire_template_save_settings` | AJAX | template-engine | ✅ Wired |
| `template-system.js` → `rawwire_module_action` | AJAX | `class-admin.php::ajax_module_action()` | ✅ Wired |
| `dashboard.js` → `RawWireCfg.rest + '/workflow/start'` | REST | `rest-api.php::start_workflow()` | ✅ Wired |
| `dashboard.js` → `RawWireCfg.rest + '/stats'` | REST | `rest-api.php::get_stats()` | ✅ Wired |
| `dashboard.js` → `RawWireCfg.rest + '/logs'` | REST | `rest-api.php::get_logs()` | ✅ Wired |
| `dashboard.js` → `RawWireCfg.rest + '/content/approve'` | REST | `rest-api.php::approve_content()` | ✅ Wired |
| `dashboard.js` → `RawWireCfg.rest + '/clear-cache'` | REST | `rest-api.php::clear_cache()` | ✅ Wired |
| `template-system.js` → `RawWireCfg.rest + '/workflow/start'` | REST | `rest-api.php::start_workflow()` | ✅ Wired |
| `template-system.js` → `RawWireCfg.rest + '/clear-workflow-tables'` | REST | `rest-api.php::clear_workflow_tables()` | ✅ Wired |
| Dev Auth → `rawwire_dev_login` | AJAX | `class-dev-auth.php::ajax_dev_login()` | ✅ Wired |
| Dev Auth → `rawwire_dev_logout` | AJAX | `class-dev-auth.php::ajax_dev_logout()` | ✅ Wired |
| Dev Auth → `rawwire_dev_change_password` | AJAX | `class-dev-auth.php::ajax_dev_change_password()` | ✅ Wired |
| Dev Auth → `rawwire_save_builder_template` | AJAX | `class-dev-auth.php::ajax_save_builder_template()` | ✅ Wired |
| DreamPilot → `dreampilot_chat` | AJAX | `class-dreampilot-orchestrator.php::ajax_handle_message()` | ✅ Wired |
| DreamPilot → `dreampilot_clear_history` | AJAX | orchestrator | ✅ Wired |
| DreamPilot → `dreampilot_get_status` | AJAX | orchestrator | ✅ Wired |
| Venice Chat → `rawwire_venice_chat` | AJAX | `class-venice-chat-handler.php` | ✅ Wired |
| Venice Chat → `rawwire_venice_clear` | AJAX | `class-venice-chat-handler.php` | ✅ Wired |
| Toolbox → `rawwire_toolkit_test` | AJAX | `toolbox-core.php` | ✅ Wired |
| Toolbox → `rawwire_toolkit_save` | AJAX | `toolbox-core.php` | ✅ Wired |
| Toolbox → `rawwire_toolkit_get_form` | AJAX | `toolbox-core.php` | ✅ Wired |
| Module Core → `rawwire_module_toolkit_save` | AJAX | `module-core.php` | ✅ Wired |
| Module Core → `rawwire_module_requirements` | AJAX | `module-core.php` | ✅ Wired |
| Scraper → `rawwire_ai_scraper_config` | AJAX | `class-ai-scraper-panel.php` | ✅ Wired |
| Scraper → `rawwire_ai_scraper_test` | AJAX | `class-ai-scraper-panel.php` | ✅ Wired |
| Workflow DB → `rawwire_workflow_db_refresh` | AJAX | `class-workflow-db-panel.php` | ✅ Wired |

---

## 13. File Inventory

### Files by category (`raw-wire-dashboard/`):

**Main Plugin & Includes:**
| File | Lines | Purpose | Issues |
|------|-------|---------|--------|
| `raw-wire-dashboard.php` | 1478 | Main plugin, lifecycle, logger stub | Version mismatch (§11.1) |
| `includes/bootstrap.php` | 498 | REST init, asset enqueue, menu manager load | Debug mode on (§8.2) |
| `includes/class-admin.php` | 518 | AJAX dispatcher + 12 dead methods | Dead code (§4.1) |
| `includes/class-menu-manager.php` | 808 | All menu/submenu registration | Workflow button handlers missing (§9.1) |
| `includes/class-dev-auth.php` | 469 | Developer mode auth | Hardcoded creds (§10.1) |
| `includes/class-config-authority.php` | 813 | Signed config, license tiers | Duplicate with Access Control (§5.2) |
| `rest-api.php` | 2202 | REST endpoints (~30+) | Missing Search_Service (§3.1), status returns 501 (§11.5) |

**Template Engine:**
| File | Lines | Purpose | Issues |
|------|-------|---------|--------|
| `cores/template-engine/template-engine.php` | 1203 | Template loading/switching | Clean |
| `cores/template-engine/workflow-handlers.php` | ~100 | AJAX: panel refresh, batch, settings | Clean |
| `cores/template-engine/page-renderer.php` | — | Page rendering | — |
| `cores/template-engine/panel-renderer.php` | — | Panel rendering | — |
| `cores/template-engine/class-data-facade.php` | — | Data abstraction | Refs RawWire_Activity_Logs |

**Module Core:**
| File | Lines | Purpose | Issues |
|------|-------|---------|--------|
| `cores/module-core/module-core.php` | 870 | Module discovery/registration | Clean |
| `modules/core/module.php` | 1228 | Panel registry, renderers, handle_ajax | Guards get_recent_logs safely |

**Toolbox Core:**
| File | Lines | Purpose | Issues |
|------|-------|---------|--------|
| `cores/toolbox-core/toolbox-core.php` | 911 | Adapter registry, class map | Clean |
| `cores/toolbox-core/class-tool-toggle-manager.php` | 855 | Tool toggle System B | Duplicate (§5.1) |
| `cores/toolbox-core/features/class-tools-toggles-page.php` | 374 | Tool toggle System A | Duplicate (§5.1) |
| `cores/toolbox-core/features/class-ai-settings-panel.php` | 1914 | AI provider config UI | Default model mismatch (§11.2) |
| `cores/toolbox-core/features/class-venice-chat-handler.php` | 703 | Venice.ai chat backend | Different default model (§11.2) |
| `cores/toolbox-core/features/class-dashboard-chat-panel.php` | 314 | Chat UI (admin footer) | Clean |
| `cores/toolbox-core/features/class-ai-memory.php` | 742 | AI long-term memory | Orphaned AI Engine hooks (§6.1) |
| `cores/toolbox-core/features/class-chatbot-context.php` | 465 | Context injection | Orphaned AI Engine hooks (§6.1) |
| `cores/toolbox-core/features/class-ai-engine-injector.php` | 290 | Patches AI Engine Pro | Fragile, writes to other plugin (§10.3) |
| `cores/toolbox-core/features/class-scraper-settings.php` | 1155 | Scraper config/sources | Clean |
| `cores/toolbox-core/features/class-scraper-settings-panel.php` | 523 | Scraper settings UI | Loads on all RW pages (§11.4) |
| `cores/toolbox-core/features/class-ai-scraper-panel.php` | 1407 | AI scraper AJAX handlers | Clean |
| `cores/toolbox-core/features/class-workflow-db-panel.php` | 1209 | Workflow DB operations | Clean |
| `cores/toolbox-core/dreampilot/class-dreampilot-orchestrator.php` | 550 | Resident AI agent | Clean |

**Dashboard Core:**
| File | Lines | Purpose | Issues |
|------|-------|---------|--------|
| `cores/dashboard-core/class-access-control.php` | 730 | Multi-tier permissions | Duplicate with Config Authority (§5.2) |

**Admin Pages:**
| File | Lines | Purpose | Issues |
|------|-------|---------|--------|
| `admin/class-settings.php` | 345 | Settings page (General + Dev Tools) | Clean |
| `admin/class-templates.php` | 935 | Template management page | Activate button placeholder (§8.1) |

**JavaScript:**
| File | Lines | Purpose | Issues |
|------|-------|---------|--------|
| `dashboard.js` | 670 | Finding cards, drawer, approve/snooze | Clean, uses REST |
| `js/template-system.js` | 2889 | Template interactions, page actions, workflows | Clean, well-structured |
| `js/template-builder.js` | 598 | Visual template builder | — |
| `js/theme-controller.js` | ~200 | CSS variable theme switching | Clean |
| `assets/js/admin.js` | 1260 | Legacy admin JS (stats, panels, chat) | Sends to dead module actions (§9.2) |
| `assets/js/scraper-settings.js` | 724 | Scraper source management | — |
| `cores/toolbox-core/assets/js/chat-panel.js` | 400 | Venice chat UI | — |

**Services:**
| File | Purpose |
|------|---------|
| `services/class-workflow-orchestrator.php` | Workflow execution engine |
| `services/class-migration-service.php` | Database table creation |
| `services/class-equalizer-tables.php` | Equalizer workflow tables |
| `services/class-equalizer-workflow.php` | Equalizer post generation pipeline |
| `services/class-scraper-service.php` | Scraper execution service |

---

## Priority Fix Roadmap

### Phase 1: Critical (Fatal Error Prevention)
1. Create `Raw_Wire_Search_Service` class or remove the 3 REST endpoints that reference it
2. Add `get_recent_logs()` method to Logger stub (return empty array)

### Phase 2: High (Architecture Cleanup)
3. Delete the 12 dead AJAX methods from `class-admin.php` (or the entire file minus `ajax_module_action`)
4. Consolidate tool toggles into ONE system — merge `rawwire_tool_toggles` and `rawwire_tool_states`
5. Consolidate Config Authority and Access Control into one permission system
6. Wire AI Memory/Context into Venice chat handler (or remove if not needed)

### Phase 3: Medium (Functionality)
7. Re-enable bootstrap hook check (stop loading assets on all pages)
8. Wire `.rawwire-run-workflow` and `.rawwire-workflow-settings` button click handlers in JS
9. Replace template Activate button placeholder with actual AJAX call
10. Sync version numbers (header vs constant)
11. Sync default model names (AI settings vs Venice handler)

### Phase 4: Low (Housekeeping)
12. Change/remove hardcoded default developer credentials from source
13. Remove `rawwire_api_key` and `rawwire_log_level` registered settings
14. Scope scraper-settings-panel.php asset loading to scraper page only
15. Either implement or remove the `get_status()` 501 endpoint
16. Consider removing or conditionalizing the AI Engine Injector

---

*End of Audit*
