# AI Assistant Instructions - PERMANENT CONTEXT

**Last Updated**: January 24, 2026  
**Version**: 1.0.30  
**Status**: ✅ COMPLETE - DO NOT REBUILD

This file provides context for AI assistants (Claude, Copilot, Cursor, etc.) working on this codebase.

---

## 📚 DOCUMENTATION INDEX

| Document | Purpose | Audience |
|----------|---------|----------|
| `CLAUDE.md` (this file) | Quick reference for AI assistants | AI/Developers |
| `docs/AI_ARCHITECTURE_MAP.md` | Structured data optimized for LLM parsing | AI Models |
| `docs/ARCHITECTURE_MAP.md` | Human-readable architecture reference | Developers |
| `docs/CODE_AUDIT_2026-01-22.md` | Dead code & cleanup recommendations | Developers |
| `docs/SYNC_FLOW_MAP.md` | Data flow documentation (962 lines) | Developers |

---

## 🧠 AI-OPTIMIZED QUICK REFERENCE (Parse First)

```
CODEBASE_ID: raw-wire-dashboard
VERSION: 1.0.28
ARCHITECTURE: template_driven_modular_wordpress_plugin
PRIMARY_LANGUAGE: PHP_8.0+
FRONTEND: JavaScript_ES6
DATABASE: MySQL_via_wpdb

CORES[3]:
  - dashboard-core: auth/routing/wp-integration/access-control
  - module-core: ui-rendering/template-mounting/panels
  - toolbox-core: apis/scrapers/ai-chat/mcp-server/workflows

ACCESS_TIERS[4]:
  - TIER_DEVELOPER: full_system_access
  - TIER_ADMIN: client_site_management  
  - TIER_EDITOR: content_operations
  - TIER_VIEWER: read_only

MCP_TOOLS[33]:
  - scraper_tools[6]: list_sources,run,add_source,remove_source,get_status,preview
  - workflow_tools[7]: list,trigger,create,get_status,stop,schedule,get_history
  - content_tools[6]: list,get,approve,reject,score,generate
  - ai_tools[4]: query,summarize,sentiment,chat
  - config_tools[3]: get_settings,update_settings,get_info
  - wordpress_tools[7]: system_info,plugins,error_log,database,health_check,cron,options

AI_PROVIDERS[4]:
  1. anthropic/claude-sonnet-4-20250514 (preferred)
  2. groq/llama-3.3-70b-versatile (free_fallback)
  3. openai/gpt-4o
  4. ollama/local (docker:ollama:11434)

DATABASE_TABLES[6]:
  - wp_rawwire_candidates (stage1:scraper_output)
  - wp_rawwire_approvals (stage2:ai_scored)
  - wp_rawwire_content (stage3:human_approved)
  - wp_rawwire_releases (stage4:generated)
  - wp_rawwire_published (stage5:finished)
  - wp_rawwire_archives (rejected_items)

KEY_FILES:
  - raw-wire-dashboard.php: bootstrap/autoload
  - rest-api.php: all_rest_endpoints(~1600_lines)
  - cores/toolbox-core/class-mcp-server.php: mcp_tools(~3000_lines)
  - cores/dashboard-core/class-access-control.php: permissions(~730_lines)
  - includes/integrations/class-ollama-engine.php: local_llm_support
  - cores/toolbox-core/features/class-dashboard-chat-panel.php: ai_chat_ui

DOCKER_SERVICES:
  - wordpress: http://localhost:8000
  - ollama: internal=ollama:11434, external=localhost:8001
  - mysql: db:3306
```

---

## 🔐 ACCESS CONTROL SYSTEM (NEW v1.0.27+)

```php
// 4-Tier Permission Hierarchy
class RawWire_Access_Control {
    const TIER_DEVELOPER = 'developer';  // Full system access
    const TIER_ADMIN     = 'admin';      // Client site admin
    const TIER_EDITOR    = 'editor';     // Content operations
    const TIER_VIEWER    = 'viewer';     // Read-only access
    
    // Deployment Modes
    const MODE_INTERNAL  = 'internal';   // Dev/testing
    const MODE_CLIENT    = 'client';     // Client deployment
    const MODE_DEMO      = 'demo';       // Trial mode
    
    // Custom Capabilities
    const CAP_DEVELOPER  = 'rawwire_developer';
    const CAP_MANAGE     = 'rawwire_manage';
    const CAP_CONFIGURE  = 'rawwire_configure';
    const CAP_VIEW       = 'rawwire_view';
    const CAP_USE_AI     = 'rawwire_use_ai';
}
```

### Permission Matrices
| Feature | Required Tier |
|---------|---------------|
| AI Model Selection | DEVELOPER |
| MCP Server Config | DEVELOPER |
| Debug Logging | DEVELOPER |
| Tool Toggle | DEVELOPER |
| API Key Config | ADMIN |
| Workflow Management | ADMIN |
| Scraper Management | ADMIN |
| Content Generation | EDITOR |
| Dashboard View | VIEWER |

### Key Methods
```php
RawWire_Access_Control::get_instance()->can_access('feature_name');  // Bool
RawWire_Access_Control::get_instance()->get_user_tier();             // String
RawWire_Access_Control::get_instance()->get_deployment_mode();       // String
```

---

## 🔐 CONFIG AUTHORITY (Signed Configuration System)

**File**: `includes/class-config-authority.php` (~700 lines)

### Purpose
Provides a **cryptographically signed configuration system** that ensures:
- Configuration changes are signed with HMAC-SHA256
- Only authorized changes can be applied
- Complete audit trail of all configuration changes
- License/tier-based feature authorization

### Authorization Tiers
| Tier | Level | Features |
|------|-------|----------|
| `free` | 0 | Dashboard, Templates, Settings |
| `basic` | 1 | + Tools, Workflows, AI Settings |
| `pro` | 2 | + AI Scraper, Workflow DB, Custom Panels/Tools |
| `enterprise` | 3 | + Multi-site, White Label, API Access |
| `developer` | 4 | ALL features + Debug Mode |

### Signed Config Change Flow
```php
$authority = RawWire_Config_Authority::get_instance();

// 1. Sign a config change
$signed = $authority->sign_config_change('rawwire_active_template', $new_value, [
    'reason' => 'User switched template',
    'source' => 'admin_ui',
]);

// 2. Verify the signature (optional - apply_signed_change does this)
$verification = $authority->verify_signed_change($signed);
if (!$verification['valid']) {
    // Handle invalid signature
}

// 3. Apply the signed change (verifies + applies + audits)
$result = $authority->apply_signed_change($signed);
if ($result['success']) {
    // Change applied successfully
}
```

### Feature Authorization
```php
// Check if current user can access a feature
$authority->is_feature_authorized('ai_scraper');  // bool

// Get all features available to current user
$features = $authority->get_authorized_features();  // ['dashboard', 'templates', ...]

// Check template authorization
$result = $authority->check_template_authorization($template_config);
if (!$result['authorized']) {
    echo $result['error'];  // "Missing required features: custom_panels"
}
```

### License Key Format
```
TIER-XXXXXXXX-CHECKSUM
PRO-A1B2C3D4-E5F6G7H8
```

### Audit Log
All config changes are logged with:
- Timestamp, event type, config key
- User ID, user tier, context
- Value hashes (not actual values for security)

```php
$log = $authority->get_audit_log(50);  // Last 50 entries
```

---

## 🤖 MCP SERVER (33 Tools - v1.0.27+)

**File**: `cores/toolbox-core/class-mcp-server.php` (~3000 lines)

### Tool Categories
| Category | Tools | Count |
|----------|-------|-------|
| **Scraper** | list_sources, run, add_source, remove_source, get_status, preview | 6 |
| **Workflow** | list, trigger, create, get_status, stop, schedule, get_history | 7 |
| **Content** | list, get, approve, reject, score, generate | 6 |
| **AI** | query, summarize, sentiment, chat | 4 |
| **Config** | get_settings, update_settings, get_info | 3 |
| **WordPress** | system_info, plugins, error_log, database, health_check, cron, options | 7 |

### MCP Registration Pattern
```php
$this->register_tool([
    'name' => 'rawwire_scraper_run',
    'description' => 'Run a scraper to collect data',
    'parameters' => [
        'type' => 'object',
        'properties' => [
            'source_id' => ['type' => 'string', 'required' => true],
            'limit' => ['type' => 'integer'],
        ],
        'required' => ['source_id'],
    ],
    'callback' => [$this, 'handle_scraper_run'],
]);
```

### AI Engine Integration
```php
// Function calling in chatbots
add_filter('mwai_functions_list', [$this, 'register_mcp_functions']);
add_filter('mwai_functions_execute', [$this, 'execute_mcp_function'], 10, 3);

// MCP protocol for external clients (Claude Desktop)
add_filter('mwai_mcp_tools', [$this, 'register_mcp_protocol_tools']);
add_filter('mwai_mcp_callback', [$this, 'handle_mcp_protocol_call'], 10, 4);
```

---

## ⚠️ DEPRECATED CODE - DO NOT USE

These files/classes are deprecated or contain dead code. Avoid using them:

| File | Issue | Use Instead |
|------|-------|-------------|
| `services/class-scoring-handler.php` | **ARCHIVED** (moved to `_archive/deprecated-2026-01-25/`) | `class-workflow-orchestrator.php` |
| `services/class-storage-service.php` | **ARCHIVED** (moved to `_archive/deprecated-2026-01-25/`) | `class-migration-service.php` tables |
| `services/class-sync-service.php` | **ARCHIVED** (moved to `_archive/deprecated-2026-01-25/`) | `class-workflow-orchestrator.php` |
| `services/run-migrations.php` | **ARCHIVED** (moved to `_archive/deprecated-2026-01-25/`) | N/A |
| `cores/ai-discovery/` | Incomplete, no real AI | Archive |
| `includes/integrations/class-groq-engine.php` | Disabled (line 19 `return;`), require_once skipped in bootstrap | Remove or fix |
| `modules/mpc-module/` | Mock data only | Delete |
| `modules/sample/` | Development example, not activated in production | Archive |
| `modules/government-shocking-facts/` | JSON-only module stub, no PHP | Archive |
| `js/control-panels.js` | Never enqueued | Delete |

**See `docs/CODE_AUDIT_2026-01-22.md` for full cleanup recommendations.**

---

## 🎯 ARCHITECTURAL VISION - THE THREE CORES

This software is built on a **dynamic 3-core structure**:

### 1. DASHBOARD CORE (Foundation)
The foundation of the application. Handles:
- All communication and data routing traffic
- Authorization and permissions
- WordPress integration code
- Shell environment access
- Testing infrastructure
- **Anything meant to be available across the whole codebase**

Along with the Module Core, it should activate successfully in WordPress and render a basic shell in the admin dashboard.

### 2. MODULE CORE (Human Interface)
Responsible for the human interface of the application:
- Logic for mounting and editing templates
- UI rendering engine
- Panel and page management
- **All user-facing interactions flow through here**

### 3. TOOLKIT CORE (External Functionality)
Interconnects with Dashboard Core for receiving instructions and returning data. Provides:
- All external functionality (APIs, scrapers, generators)
- Environments, libraries, servers for hosting tools
- Processing API calls
- **Workflow handling system** for complex multi-tool workflows
- **AI Chat Interface** - the heart of the public release
- **MCP Server** - provides AI with tool-calls for natural language operation

The Toolkit must also **dynamically handle additional tools** defined in the template system.

### TEMPLATES (Custom Business Logic)
**All additional functionality resides in the template.** Templates contain:
- Customized code for specific use-cases
- Custom menus and data views
- Admin pages and control panels
- Troubleshooting interfaces
- Styling and chat windows
- **All business customer-specific code and custom functions**

### DESIGN PHILOSOPHY
The news aggregator is being built to:
1. Create real-world, functional tooling and workflows
2. Build permanent, switchable tool offerings as standard software features
3. Establish the foundation for the template-driven architecture
4. Prove the concept before public release

```
┌─────────────────────────────────────────────────────────────────────┐
│                         TEMPLATE LAYER                               │
│   (Custom menus, views, pages, styling, chat, business logic)       │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│   ┌─────────────┐    ┌─────────────┐    ┌─────────────────────┐    │
│   │   MODULE    │    │  DASHBOARD  │    │      TOOLKIT        │    │
│   │    CORE     │◄──►│    CORE     │◄──►│       CORE          │    │
│   │             │    │             │    │                     │    │
│   │ UI Render   │    │ Auth/Route  │    │ Tools/Workflows     │    │
│   │ Templates   │    │ WP/Shell    │    │ AI Chat + MCP       │    │
│   │ Panels      │    │ Testing     │    │ External APIs       │    │
│   └─────────────┘    └─────────────┘    └─────────────────────┘    │
│                                                                      │
└─────────────────────────────────────────────────────────────────────┘
```

---

## ⚠️ CRITICAL: READ BEFORE DOING ANYTHING

**THIS SYSTEM IS ALREADY BUILT.** The code exists. The architecture is complete. Your job is to:
1. **FIND** existing code, not write new code
2. **FIX** bugs in existing code, not rebuild from scratch
3. **ENHANCE** existing features, not duplicate them

If you're about to create a new file or write >50 lines of code, **STOP** and search first.

---

## 🚨 MENU ARCHITECTURE - READ THIS BEFORE TOUCHING MENUS

**File**: `includes/class-menu-manager.php` (SINGLE SOURCE OF TRUTH)

### Hardwired Menu Structure
```
Raw Wire (Dashboard)        ← Main menu, always visible
├── Templates               ← Always visible (to load templates)
├── Tools                   ← Multi-tab page (tabs from activated tools)
├── Workflows               ← Multi-tab page (tabs from template config)
└── Settings                ← General + Developer Tools
```

### HOW TOOLS REGISTER (TABS, NOT SUBMENUS!)
**Tools do NOT create submenus!** They register TABS within the Tools page:

```php
// In your tool class constructor:
add_action('rawwire_register_tool_tabs', [$this, 'register_tool_tab']);

// The registration method:
public function register_tool_tab() {
    RawWire_Menu_Manager::register_tool_tab('my_tool_id', [
        'label'           => __('My Tool', 'raw-wire-dashboard'),
        'icon'            => 'dashicons-admin-tools',
        'required_feature'=> 'feature_name',  // Tool only shows if feature enabled
        'render_callback' => [$this, 'render_tab_content'],
        'priority'        => 10,
    ]);
}
```

### HOW WORKFLOWS REGISTER
Workflows are defined in templates and register as tabs:

```php
RawWire_Menu_Manager::register_workflow_tab('my_workflow', [
    'label'           => __('My Workflow', 'raw-wire-dashboard'),
    'icon'            => 'dashicons-randomize',
    'render_callback' => [$this, 'render_workflow'],
    'priority'        => 10,
]);
```

### DEFAULT STATE (No Template)
- Dashboard shows only hardcoded defaults
- Templates submenu visible (to load one)
- Settings visible for developers
- Tools page: empty (no tools activated)
- Workflows page: empty (no workflows defined)

### ❌ FORBIDDEN - DO NOT:
- Use `add_submenu_page()` for new tools
- Create separate menu items per tool
- Bypass RawWire_Menu_Manager
- Let templates define menu structure

### ✅ DO:
- Register tool tabs via `register_tool_tab()`
- Register workflow tabs via `register_workflow_tab()`
- Check `is_tool_activated()` before showing content
- Follow the multi-tab pattern

### Key Files
| File | Purpose |
|------|---------|
| `includes/class-menu-manager.php` | All menu registration |
| `modules/core/module.php` | Panel registry & renderers |
| `includes/bootstrap.php` | Loads Menu Manager, REST API |
| `raw-wire-dashboard.php` | Plugin header (NO menu code) |

---

## Architecture Overview

This is a **TEMPLATE-DRIVEN MODULAR WORDPRESS PLUGIN** designed for resale to small businesses.

**ALL LOGIC IS IN TEMPLATES. CODE IS STATIC.**

---

## 🗄️ DATABASE TABLES (6 Tables - All Created)

```
STAGE 1          STAGE 2           STAGE 3          STAGE 4          STAGE 5
─────────────────────────────────────────────────────────────────────────────────
candidates  →    approvals    →    content     →    releases    →   published
(scraper)        (AI top 2)        (human OK)       (generated)      (finished)
                                                                          │
                                                            archives ←────┘
                                                            (rejected)
```

| Table | Stage | Purpose | Created By |
|-------|-------|---------|------------|
| `wp_rawwire_candidates` | 1 | Temporary staging from scraper | `services/class-migration-service.php` |
| `wp_rawwire_approvals` | 2 | AI-approved awaiting human review | `services/class-migration-service.php` |
| `wp_rawwire_content` | 3 | Human-approved in AI generation queue | `services/class-migration-service.php` |
| `wp_rawwire_releases` | 4 | Generated content ready to publish | `services/class-migration-service.php` |
| `wp_rawwire_published` | 5 | Published content (finished products) | `services/class-migration-service.php` |
| `wp_rawwire_archives` | 0 | All rejected items (permanent archive) | `services/class-migration-service.php` |

### REST Endpoints for Table Monitoring
- `GET /wp-json/rawwire/v1/stats` - Real-time counts for all 6 tables
- `GET /wp-json/rawwire/v1/table-status` - Detailed table status with stage numbers
- `POST /wp-json/rawwire/v1/ensure-tables` - Create any missing tables

---

## 📁 EXISTING FILE LOCATIONS

### Services Layer
| File | Purpose | Status |
|------|---------|--------|
| `services/class-scraper-service.php` | Scrapes gov sources -> candidates table | ✅ Active |
| `services/class-workflow-orchestrator.php` | Orchestrates workflow pipeline | ✅ Active |
| `services/class-migration-service.php` | Creates all database tables | ✅ Active |
| `services/class-equalizer-tables.php` | Equalizer table definitions | ✅ Active |
| `services/class-equalizer-workflow.php` | Equalizer workflow engine | ✅ Active |
| ~~`services/class-scoring-handler.php`~~ | ~~AI scores~~ | ❌ **ARCHIVED** to `_archive/deprecated-2026-01-25/` |
| ~~`services/class-sync-service.php`~~ | ~~Orchestrates sync~~ | ❌ **ARCHIVED** to `_archive/deprecated-2026-01-25/` |
| ~~`services/class-storage-service.php`~~ | ~~Data persistence~~ | ❌ **ARCHIVED** to `_archive/deprecated-2026-01-25/` |

### Admin Pages (YOUR CODE - ALREADY BUILT)
| File | Purpose | Route |
|------|---------|-------|
| `admin/class-dashboard.php` | Main dashboard with progress bar | `raw-wire-dashboard` |
| `admin/class-approvals.php` | Review queue page | `raw-wire-approvals` |
| `admin/class-candidates.php` | Candidates page | `raw-wire-candidates` |
| `admin/class-settings.php` | Settings page | `raw-wire-settings` |
| `admin/class-templates.php` | Template management | `raw-wire-templates` |

### Template Engine (CORE INFRASTRUCTURE)
| File | Purpose |
|------|---------|
| `cores/template-engine/page-renderer.php` | Renders pages from template config |
| `cores/template-engine/panel-renderer.php` | Renders panels from template config |
| `cores/template-engine/workflow-handlers.php` | Generic workflow action handlers |
| `templates/news-aggregator.template.json` | Active template with all page/panel definitions |

### JavaScript (YOUR CODE - ALREADY BUILT)
| File | Purpose |
|------|---------|
| `dashboard.js` | Sync button, progress bar, REST polling, action handlers |
| `js/sync-manager.js` | Sync state management |
| `js/control-panels.js` | Panel interactions |
| `js/template-system.js` | Template engine JS |

### Documentation (YOUR DOCS - REFERENCE THESE)
| File | Purpose |
|------|---------|
| `SYNC_FLOW_MAP.md` | Complete data flow diagram (962 lines) |
| `ARCHITECTURE_PERMANENT_RECORD.md` | Template-first architecture rules |
| `docs/WORKFLOW_SPEC.md` | Workflow stages specification |
| `docs/TEMPLATE_FIRST_ARCHITECTURE.md` | Architecture guide |

---

## 🔄 SYNC WORKFLOW (ALREADY IMPLEMENTED)

### User Clicks Sync Button
```
dashboard.js → AJAX → class-admin.php::ajax_sync()
                     → schedules background cron
                     → returns immediately
```

### Background Scraper (Stage 1: 0-30%)
```
class-admin.php::run_background_scrape()
  → class-scraper-service.php::scrape_all()
  → inserts into wp_rawwire_candidates
  → fires do_action('rawwire_scrape_complete')
```

### AI Scoring (Stage 2: 30-60%)
```
NOTE: class-scoring-handler.php was ARCHIVED (Jan 2026).
Scoring is now handled by class-workflow-orchestrator.php:
  → hooked to 'rawwire_scrape_complete'
  → scores with class-ai-content-analyzer.php
  → TOP 2 per source → wp_rawwire_approvals
  → Others → wp_rawwire_archives
  → fires do_action('rawwire_scoring_complete')
```

### Progress Bar (ALREADY BUILT)
```
dashboard.js::pollWorkflowStatus()
  → REST: /wp-json/rawwire/v1/sync/status
  → Updates .progress-fill width
  → Updates .progress-percentage text
  → Shows stage icons (scraping/scoring/approving/complete)
```

---

## 🤖 AI CHAT INTEGRATION (v1.0.26)

### Architecture
```
┌─────────────────────────────────────────────────────────────────────┐
│                      AI CHAT INTERFACE                               │
├─────────────────────────────────────────────────────────────────────┤
│  class-dashboard-chat-panel.php                                      │
│    └─ get_preferred_env_id()   → Selects best AI provider           │
│    └─ get_preferred_model()    → Returns optimal model              │
│    └─ get_chatbot_config()     → Full chatbot configuration         │
├─────────────────────────────────────────────────────────────────────┤
│                      AI ENGINE PRO                                   │
│  engines/factory.php           → Creates engine instances            │
│  engines/ollama.php (CUSTOM)   → Local Ollama support               │
├─────────────────────────────────────────────────────────────────────┤
│                      PROVIDERS (Priority Order)                      │
│  1. Claude (Anthropic)  → claude-sonnet-4-20250514                   │
│  2. Groq               → llama-3.3-70b-versatile (FREE)             │
│  3. OpenAI             → gpt-4o                                      │
│  4. Ollama (Local)     → llama3.1:8b (12GB VRAM limit)              │
└─────────────────────────────────────────────────────────────────────┘
```

### Key Files
| File | Purpose |
|------|---------|
| `cores/toolbox-core/features/class-dashboard-chat-panel.php` | Chat UI + smart provider selection + visibility control |
| `includes/integrations/class-ollama-engine.php` | Custom Ollama engine with `retrieve_models()` |
| `ai-engine-pro/classes/engines/factory.php` | Engine instantiation (modified for Ollama) |
| `raw-wire-dashboard.php` | `setup_ai_engine_ollama()` auto-config |

### Ollama Integration (Fixed v1.0.28)
```php
// class-ollama-engine.php key methods:
public function retrieve_models() {
    // Called by AI Engine "Refresh Models" button
    // Fetches from Ollama API, returns array
    $response = wp_remote_get('http://ollama:11434/api/tags');
    // Processes and returns models array
}

// Filter to inject models into environment config
add_filter('option_mwai_options', function($options) {
    // Injects models array into Ollama environments
    // Required because AI Engine uses $env['models'] for custom engines
});
```

### Chat Panel Visibility (Fixed v1.0.28)
```php
// class-dashboard-chat-panel.php
private function should_show_chat() {
    $visibility = get_option('rawwire_chat_visibility', 'rawwire_only');
    // 'rawwire_only' - Only Raw Wire admin pages
    // 'everywhere' - All WordPress admin pages  
    // 'disabled' - Chat hidden
}
```

### Docker Network (Local Dev)
- WordPress reaches Ollama at: `http://ollama:11434`
- Host machine accesses Ollama at: `http://localhost:8001`
- AI Engine environments stored in: `wp_options.mwai_options`

---

## ✅ TEMPLATE DATASOURCE SYNTAX

```json
"dataSource": "db:approvals:status=pending"
"dataSource": "db:releases:status=ready"
"dataSource": "db:archives:result=Rejected"
```

---

## 🚫 MODULES ARE FALLBACKS ONLY

```php
// ✅ CORRECT - Fallback message only
case 'get_approvals':
    return '<div class="notice">Configure template</div>';

// ❌ WRONG - Never put business logic in modules
case 'get_approvals':
    $items = $wpdb->get_results("SELECT * FROM...");
    return '<table>...' . generate_html($items) . '</table>';
```

---

## 🔧 BEFORE MAKING ANY CHANGES

1. **SEARCH FIRST**: Use grep_search, file_search, semantic_search
2. **READ EXISTING CODE**: The file probably already exists
3. **CHECK GIT STATUS**: See what's modified vs committed
4. **READ SYNC_FLOW_MAP.md**: Complete documentation of the system
5. **ASK USER**: If unsure, ask "Do you already have X implemented?"

---

## 📋 REST API ENDPOINTS (ALREADY BUILT)

| Endpoint | Handler | Purpose |
|----------|---------|---------|
| `GET /sync/status` | `rest-api.php::get_sync_status()` | Progress bar polling |
| `POST /fetch-data` | `rest-api.php` | Trigger sync |
| `GET /approvals` | `rest-api.php` | Get approval queue |
| `POST /approvals/{id}/approve` | `rest-api.php` | Approve item |
| `POST /approvals/{id}/reject` | `rest-api.php` | Reject item |
| `GET /releases` | `rest-api.php` | Get release queue |
| `POST /releases/{id}/publish` | `rest-api.php` | Publish to WordPress |

---

## 🐳 DOCKER SYNC COMMAND

```powershell
docker cp "d:\00-EQUALIZER\raw-wire-equalizer\wordpress-plugins\raw-wire-dashboard" raw-wire-equalizer-wordpress-1:/var/www/html/wp-content/plugins/
```

---

## ⚠️ RED FLAGS - STOP IF YOU SEE THESE

If you're about to:
- Create a new service file → **Check services/ first — scoring-handler, sync-service, and storage-service were ARCHIVED (Jan 2026)**
- Write database queries → **CHECK class-workflow-orchestrator.php and class-migration-service.php first**
- Add inline JavaScript → **IT'S IN dashboard.js**
- Create admin page → **IT'S IN admin/ folder**
- Build progress bar → **IT'S IN class-dashboard.php + dashboard.js**

**SEARCH THE CODEBASE FIRST.**

---

## 📊 CURRENT STATE (January 15, 2026)

### Core Structure Status
| Core | Location | Status |
|------|----------|--------|
| Dashboard Core | `raw-wire-dashboard.php`, `includes/`, `rest-api.php` | ✅ Foundation working |
| Module Core | `cores/module-core/` | ✅ Basic shell renders |
| Toolkit Core | `cores/toolbox-core/` | ✅ Scrapers/Generators working |
| Template Engine | `cores/template-engine/` | ✅ Rendering templates |

### ✅ Complete
- All 6 database tables
- Scraper service with 10+ gov sources
- AI scoring handler (Ollama integration)
- Template engine with JSON-driven rendering
- REST API endpoints
- Basic dashboard shell
- Template builder system

### ✅ Complete (Added 1.0.26)
- AI Chat Interface with smart provider selection (Claude > Groq > OpenAI > Ollama)
- Custom Ollama engine for AI Engine Pro (bypasses paid addon)
- Auto-configuration of AI environments on plugin activation

### ✅ Complete (Added 1.0.27+)
- **MCP Server with 33 Tools** - Full tool-call capability for AI agents
- **4-Tier Access Control** - Developer/Admin/Editor/Viewer permission hierarchy
- **Tool Toggle System** - Enable/disable MCP tools via admin UI
- **Ollama retrieve_models()** - "Refresh Models" button now works
- **Chat Panel Visibility** - Control where chat appears (rawwire_only/everywhere/disabled)

### ⏳ TODO
- Workflow orchestration system (multi-tool pipelines)
- Tool execution logging
- Rate limiting per tier

---

## 📂 COMPLETE REPOSITORY STRUCTURE

```
raw-wire-equalizer/
├── 📋 Root Config & Testing
│   ├── docker-compose.yml         # Local dev environment
│   ├── composer.json              # PHP dependencies
│   ├── phpunit.xml                # Test config
│   ├── run-tests.bat/.sh          # Test runners
│   ├── TESTING_README.md          # Testing guide
│   └── test_*.php                 # Various test scripts
│
├── 📁 .github/
│   ├── ci/                        # E2E test suite
│   │   ├── docker-compose.wp.yml
│   │   ├── install-wordpress.sh
│   │   ├── run-all-tests.sh
│   │   └── README.md
│   └── workflows/                 # GitHub Actions
│       ├── ci.yml
│       └── wordpress-plugin-e2e-test.yml
│
├── 📁 archive/                    # Historical records
│   ├── DEVELOPMENT_NOTES.md       # Consolidated session notes
│   └── [version release docs]
│
├── 📁 docs/                       # Project-level docs
│   ├── PR_GUIDELINES.md           # PR process & approval
│   └── SECRETS.md                 # Credentials guide
│
├── 📁 releases/                   # Packaged plugin zips
├── 📁 scripts/                    # local-setup.sh, package-plugin.sh
│
└── 📁 wordpress-plugins/
    └── 📁 raw-wire-dashboard/     # ⭐ MAIN PLUGIN (this folder)
        │
        ├── 🔧 Core Files
        │   ├── raw-wire-dashboard.php    # Plugin bootstrap
        │   ├── rest-api.php              # All REST endpoints
        │   ├── dashboard.js              # Frontend handlers
        │   └── dashboard.css             # Styling
        │
        ├── 📖 Key Documentation
        │   ├── CLAUDE.md                 # ⬅ THIS FILE
        │   ├── CHANGELOG.md              # Version history
        │   ├── README.md                 # Installation guide
        │   └── AI-SETUP-GUIDE.md         # Ollama setup
        │
        ├── 📁 docs/                      # Detailed documentation
        │   ├── AI_KNOWLEDGE_BASE.md      # Vector store context
        │   ├── SYNC_FLOW_MAP.md          # 962-line data flow
        │   ├── ARCHITECTURE_PERMANENT_RECORD.md
        │   ├── architecture/             # Specs & diagrams
        │   ├── api/                      # REST API docs
        │   └── manuals/                  # User guides
        │
        ├── 📁 cores/                     # THREE CORE ARCHITECTURE
        │   ├── dashboard-core/           # Auth/Routing/WP Integration
        │   │   └── class-access-control.php  # 4-tier permission system
        │   ├── module-core/              # UI/Template rendering
        │   ├── template-engine/          # JSON→HTML engine
        │   └── toolbox-core/             # External functionality
        │       ├── class-mcp-server.php  # 33 MCP tools (~3000 lines)
        │       ├── class-ai-adapter.php  # AI Engine integration
        │       ├── features/
        │       │   └── class-dashboard-chat-panel.php  # AI Chat UI
        │       ├── adapters/
        │       │   ├── scrapers/         # GitHub, Native, API, Brightdata
        │       │   └── scorers/          # Keyword, AI Relevance
        │       └── interfaces/
        │
        ├── 📁 services/                  # Business logic layer
        │   ├── class-scraper-service.php
        │   ├── class-migration-service.php
        │   ├── class-workflow-orchestrator.php
        │   ├── class-equalizer-tables.php
        │   └── class-equalizer-workflow.php
        │   # ARCHIVED: scoring-handler, sync-service, storage-service → _archive/deprecated-2026-01-25/
        │
        ├── 📁 includes/                  # Shared classes
        │   ├── class-admin.php
        │   ├── class-ai-content-analyzer.php
        │   ├── bootstrap.php
        │   └── integrations/
        │       └── class-ollama-engine.php  # Custom Ollama support
        │
        ├── 📁 admin/                     # Admin page classes
        │   ├── class-approvals.php
        │   ├── class-settings.php
        │   └── class-templates.php
        │
        ├── 📁 modules/                   # Module fallbacks only
        │   ├── core/
        │   └── sample/
        │
        ├── 📁 templates/                 # JSON templates
        │   └── news-aggregator.template.json
        │
        └── 📁 tests/                     # PHPUnit tests
```

---

## 📚 DOCUMENTATION PRIORITY

| Priority | File | Purpose |
|----------|------|---------|
| **1** | `CLAUDE.md` | AI assistant primary context (this file) |
| **2** | `docs/AI_KNOWLEDGE_BASE.md` | Vector store/chatbot context |
| **3** | `docs/SYNC_FLOW_MAP.md` | Complete data flow (962 lines) |
| **4** | `README.md` | Human installation guide |

---

## � EMBEDDING DATA (For Vector Stores)

Location: `d:\00-EQUALIZER\raw-wire-equalizer\EMBEDDING_DATA\`

| File | Content | Lines |
|------|---------|-------|
| `rawwire_01_architecture.txt` | System overview, access control, Ollama integration | ~350 |
| `rawwire_02_tools_reference.txt` | All 33 MCP tools with parameters and tiers | ~450 |
| `rawwire_03_code_patterns.txt` | Implementation examples and patterns | ~500 |
| `rawwire_ai_reference_v1.1.txt` | Complete combined reference | ~800 |

These files are optimized for chunking and semantic search retrieval.

---

## 🔍 HOW TO FIND CODE

```powershell
# Find any file
grep_search("function name or class name")

# Find specific file
file_search("**/class-scoring*.php")

# Check what's already modified
git status --short

# See your uncommitted work
git diff --name-only
```

---

## 🧩 CODE PATTERNS - SECURE EXECUTION

### MCP Tool Execution (with Access Control)
```php
private function execute_tool_with_access_check($tool_name, $params, $user_id = null) {
    $access = RawWire_Access_Control::get_instance();
    
    // Check if tool is enabled
    if (!$this->is_tool_enabled($tool_name)) {
        return ['error' => 'Tool disabled', 'code' => 'TOOL_DISABLED'];
    }
    
    // Check user tier
    $user_tier = $access->get_user_tier($user_id);
    $required_tier = $access->get_tool_tier($tool_name);
    
    if (!$access->tier_can_access($user_tier, $required_tier)) {
        return ['error' => 'Access denied', 'code' => 'ACCESS_DENIED', 
                'user_tier' => $user_tier, 'required_tier' => $required_tier];
    }
    
    // Execute tool
    return $this->execute_tool($tool_name, $params);
}
```

### Adding New MCP Tool
```php
// In class-mcp-server.php::register_default_tools()
$this->register_tool([
    'name' => 'rawwire_my_new_tool',
    'description' => 'Clear description for AI agents',
    'parameters' => [
        'type' => 'object',
        'properties' => [
            'required_param' => [
                'type' => 'string',
                'description' => 'What this param does',
                'required' => true,
            ],
            'optional_param' => [
                'type' => 'integer',
                'description' => 'Optional parameter',
            ],
        ],
        'required' => ['required_param'],
    ],
    'callback' => [$this, 'handle_my_new_tool'],
]);

// Add to access control in class-access-control.php::init_permissions()
$this->tool_permissions['my_new_tool'] = self::TIER_ADMIN; // or appropriate tier
```

### Ollama Engine Model Fetching
```php
// In class-ollama-engine.php
public function retrieve_models() {
    $base_url = $this->get_option('apiBase', 'http://ollama:11434');
    $response = wp_remote_get($base_url . '/api/tags', [
        'timeout' => 15,
        'sslverify' => false,
    ]);
    
    if (is_wp_error($response)) {
        return [];
    }
    
    $body = json_decode(wp_remote_retrieve_body($response), true);
    $models = [];
    
    foreach ($body['models'] ?? [] as $model) {
        $models[] = [
            'model' => $model['name'],
            'name' => $model['name'],
        ];
    }
    
    return $models;
}
```

---

**DO NOT DELETE THIS FILE**  
**DO NOT IGNORE THIS CONTEXT**  
**SEARCH BEFORE WRITING NEW CODE**
