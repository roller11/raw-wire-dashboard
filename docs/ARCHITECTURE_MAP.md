# Raw Wire Dashboard - Architecture Reference
**Version:** 1.0.30  
**Last Updated:** March 9, 2026

---

## System Overview

### Current-State Corrections (March 3, 2026)

- `cores/dashboard-core/class-access-control.php` header corruption was repaired (file now starts with a valid PHP open tag).
- Admin-notice suppression in `raw-wire-dashboard.php` is scoped to Raw-Wire admin pages (`raw-wire-dashboard` / `rawwire-*`) instead of globally muting non-dashboard wp-admin pages.
- `includes/integrations/class-groq-engine.php` remains intentionally disabled at load time (`return;`); `require_once` skipped in bootstrap to avoid wasted file include.
- Menu authority is `includes/class-menu-manager.php` — sole source of truth for runtime menu topology.
- Archived services (`scoring-handler`, `sync-service`, `storage-service`) removed from active listings; now in `_archive/deprecated-2026-01-25/`.

### Menu Topology (Source: class-menu-manager.php)

```
raw-wire-dashboard (Main Menu: "Raw Wire")
├── [PRODUCTION — always visible]
│   ├── raw-wire-dashboard      → Dashboard       (render_dashboard_page)
│   ├── rawwire-soothsayer      → Soothsayer      (render_soothsayer_page)
│   └── rawwire-user-options    → User Options    (render_user_options_page)
│
└── [DEVELOPER — when $dev_mode === true]
    ├── rawwire-ai-settings     → AI Settings     (render_ai_settings_page)
    ├── rawwire-tools           → Tools           (render_tools_toggles_page)
    ├── rawwire-lead-sources    → Lead Generator  (render_lead_sources_page)
    ├── rawwire-lead-approvals  → Approvals       (render_lead_approvals_page)
    ├── rawwire-lead-completed  → Leads           (render_lead_completed_page)
    ├── rawwire-setup           → Setup           (render_setup_page)
    ├── rawwire-templates       → Templates       (render_templates_page)
    ├── rawwire-ai-agents       → AI Agents       (render_ai_agents_page)
    └── rawwire-options         → Options         (render_options_page)
```

**Slug format:** All submenus use `rawwire-*` (no second hyphen). The main menu uses `raw-wire-dashboard`.
**Total registrations:** 1 main menu + 3 production + 9 developer = 13.

### AI Settings Ownership Split (March 9, 2026)

- `AI Settings` is now provider-only.
- Active tabs are limited to actual AI providers: `Venice.ai`, `Perplexity`, and `OpenAI`.
- Allowed settings on that page are provider-facing items such as API keys, base URLs, model selection, and provider-level generation defaults.
- Investigation workflow behavior, pipeline selection, browser-lane runtime controls, and MCP/server configuration must live outside `AI Settings`.
- `Lead Generator` remains the canonical home for investigation workflow behavior such as enablement, search depth, cache windows, auto-run rules, and pipeline selection.

Raw Wire Dashboard is a **template-driven modular WordPress plugin** designed for automation, AI integration, and content workflow management. The architecture is built on a **3-core + template layer** design.

```
┌─────────────────────────────────────────────────────────────────────────┐
│                           TEMPLATE LAYER                                 │
│     JSON configurations that define features, pages, workflows          │
│     Location: templates/*.template.json                                  │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  ┌──────────────┐   ┌──────────────┐   ┌────────────────────────────┐  │
│  │   MODULE     │   │  DASHBOARD   │   │        TOOLBOX             │  │
│  │    CORE      │◄─►│    CORE      │◄─►│         CORE               │  │
│  │              │   │              │   │                            │  │
│  │  UI Render   │   │  Auth/Perms  │   │  MCP Server (33 tools)    │  │
│  │  Modules     │   │  WP Hooks    │   │  AI Adapters              │  │
│  │  Panels      │   │  Access Ctrl │   │  Scrapers/Generators      │  │
│  └──────────────┘   └──────────────┘   └────────────────────────────┘  │
│                                                                          │
│  ┌──────────────────────────────────────────────────────────────────┐   │
│  │                     TEMPLATE ENGINE                               │   │
│  │        JSON → PHP rendering, workflow handling                    │   │
│  └──────────────────────────────────────────────────────────────────┘   │
│                                                                          │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## Directory Structure

```
raw-wire-dashboard/
├── raw-wire-dashboard.php      # Plugin bootstrap (1,441 lines)
├── rest-api.php                # REST endpoints (1,737 lines)
├── dashboard.js                # Main frontend JS (800+ lines)
├── dashboard.css               # Main styles (1,884 lines)
│
├── cores/                      # THREE CORE ARCHITECTURE
│   ├── dashboard-core/
│   │   └── class-access-control.php    # 4-tier permission system (730 lines)
│   │
│   ├── module-core/
│   │   ├── module-core.php             # Module loader/registry (870 lines)
│   │   └── README.md
│   │
│   ├── toolbox-core/
│   │   ├── class-mcp-server.php        # MCP Server - 33 AI tools (3,026 lines)
│   │   ├── class-tool-registry.php     # Tool scheduling (623 lines)
│   │   ├── class-ai-adapter.php        # AI Engine bridge (855 lines)
│   │   ├── class-key-manager.php       # API key management
│   │   ├── class-tool-toggle-manager.php
│   │   │
│   │   ├── features/                   # UI Features
│   │   │   ├── class-dashboard-chat-panel.php
│   │   │   ├── class-ai-settings-panel.php
│   │   │   ├── class-scraper-settings-panel.php
│   │   │   ├── class-ai-scraper-panel.php
│   │   │   ├── class-workflow-db-panel.php
│   │   │   ├── class-chatbot-context.php
│   │   │   ├── class-ai-memory.php
│   │   │   └── class-ai-engine-injector.php
│   │   │
│   │   ├── adapters/                   # External Integrations
│   │   │   ├── scrapers/               # 5 scraper adapters
│   │   │   ├── generators/             # 3 AI generators
│   │   │   ├── workflows/              # 3 workflow engines
│   │   │   ├── posters/                # 3 posting targets
│   │   │   └── scorers/                # 2 scoring methods
│   │   │
│   │   └── interfaces/                 # Adapter contracts
│   │
│   └── template-engine/
│       ├── template-engine.php         # Template loader (1,079 lines)
│       ├── page-renderer.php           # Page generation (390 lines)
│       ├── panel-renderer.php          # Panel generation (1,271 lines)
│       └── workflow-handlers.php       # Workflow actions (1,436 lines)
│
├── services/                           # Business Logic Layer
│   ├── class-workflow-orchestrator.php # Primary workflow engine ✓
│   ├── class-migration-service.php     # Database migrations ✓
│   ├── class-scraper-service.php       # Scraper coordination
│   ├── class-equalizer-tables.php      # Equalizer table definitions
│   └── class-equalizer-workflow.php    # Equalizer workflow engine
│   # ARCHIVED (Jan 2026): scoring-handler, sync-service, storage-service → _archive/deprecated-2026-01-25/
│
├── includes/                           # Shared Classes
│   ├── bootstrap.php                   # Plugin bootstrap (NOT menu registration — see class-menu-manager.php)
│   ├── class-menu-manager.php          # Menu topology SOT — all add_menu/submenu_page calls
│   ├── class-admin.php                 # Admin AJAX handlers
│   ├── class-ai-content-analyzer.php   # Content analysis
│   ├── interface-module.php            # Module contract
│   └── integrations/
│       ├── class-ollama-engine.php     # Ollama AI Engine ✓
│       └── class-groq-engine.php       # Groq integration (DISABLED — return; at line 19, require_once skipped)
│
├── admin/                              # Admin Page Classes
│   ├── class-approvals.php
│   ├── class-settings.php
│   └── class-templates.php             # Template builder (935 lines)
│
├── modules/                            # Loadable Modules
│   ├── core/module.php                 # Core stats/UI module
│   ├── sample/module.php               # Development example
│   └── government-shocking-facts/      # News aggregator template
│
├── templates/                          # JSON Templates
│   ├── news-aggregator.template.json   # Active template
│   ├── ai-discovery.template.json
│   ├── raw-wire-default.json
│   └── template.schema.json            # JSON schema
│
├── js/                                 # JavaScript
│   ├── template-system.js              # Template UI (1,137 lines)
│   ├── template-builder.js             # Builder UI
│   └── theme-controller.js             # Dark/light mode
│
├── tests/                              # PHPUnit Tests
├── scripts/                            # Utility Scripts
├── docs/                               # Documentation
└── _archive/                           # Archived Code
```

---

## Core Systems Detail

### Lead Generator (Contractor-First Two-Phase Gate)

**Active runtime path**

1. `RawWire_Party_Investigator::investigate_source_parties()`
2. Phase 1: `get_contractor()`
    - Validates/scrubs stale placeholder contractor anchors
    - Calls `discover_parties_from_permit()`
    - Uses OpenClaw agent path with strict JSON parse acceptance (invalid JSON-mode output is treated as failure)
    - Persists contractor identity fields before deep dive
3. Phase 2: `get_details()`
    - Runs only when contractor anchor is valid in DB
    - Provider-aware deep dive (`investigate_party_via_agent`)
    - If `pipeline_mode=perplexity_direct`: direct dossier request via `chat_with_metadata()` using provider identity/model defaults from `rawwire_perplexity_settings` plus Lead Generator workflow overrides from `rawwire_party_investigator_settings`, native web research, citation injection into `EVIDENCE LOG`, optional thinking-strip cleanup, Perplexity `/models`-backed model selection, and request controls for `top_p`, `reasoning_effort`, and investigation-specific `web_search_options`
    - If `pipeline_mode=veniceclaw`: OpenClaw agent-first deep dive using `rawwire_openclaw_settings` plus `rawwire_openai_settings` for OpenAI-compatible auth/runtime, with mandatory browser-lane evidence/URL validation, `/models`-backed OpenAI model selection, and request controls for `top_p`, `reasoning_effort`, `tool_choice`, and filtered tool availability
    - Parsing/list extraction may use non-browser tools, but browser-lane social/profile details requiring navigation must be browser-verified

**Provider capability control surface**

- `AI Settings -> Venice.ai`
    - Refreshes models from Venice `/models`
    - Saves provider request defaults including `top_p`, `reasoning_effort`, `enable_web_search`, `enable_web_scraping`, `enable_web_citations`, `disable_thinking`, and optional tool exposure flags
    - Runtime consumer: `RawWire_Adapter_Generator_Venice`
- `AI Settings -> Perplexity`
    - Refreshes models from Perplexity `/models` with `/v1/models` fallback for provider base URLs that omit the version segment
    - Saves provider request defaults including `top_p`, `reasoning_effort`, `search_mode`, `disable_search`, `enable_search_classifier`, `return_related_questions`, and `return_images`
    - Runtime consumer: `RawWire_OpenClaw_Adapter::chat_with_metadata()` when direct Perplexity is active; Lead Gen runtime now honors the provider-selected Perplexity model for both passes instead of a hidden investigation override
- `Lead Generator -> Investigation -> Perplexity`
    - Saves direct-lane workflow overrides including pass count, search-mode/search-result toggles, think-strip cleanup, and editable pass-specific model/prompt settings for passes 1-3
    - Runtime consumer: `RawWire_Party_Investigator::investigate_party_via_direct_provider()` which now applies pass-specific model overrides with fallback to the provider default from `rawwire_perplexity_settings`, normalizes `perplexity/*` catalog IDs for native chat requests, avoids carrying Pass 1 overrides into Pass 2 fallback, and only enables gap-fill retry when three passes are configured
- `AI Settings -> OpenAI`
    - Refreshes models from OpenAI-compatible `/models` with `/v1/models` fallback for endpoints that were saved without the version segment
    - Saves provider request defaults including `top_p`, `reasoning_effort`, `tool_choice`, `parallel_tool_calls`, `allow_tool_calls`, `allow_mcp_tools`, and `allow_openclaw_tools`
    - Runtime consumer: `RawWire_OpenClaw_Adapter::chat_with_metadata()` for OpenAI-compatible requests; Lead Gen no longer lets `rawwire_party_investigator_settings[investigation_model]` override the provider-selected OpenAI-compatible model

**Quarantined legacy paths (retained, not deleted)**

- `extract_parties_from_ladbs()`
- `build_discovery_research_prompt()`
- `build_ladbs_lookup_prompt()`
- `build_soothsayer_prompt()` and related prompt builders

These methods are currently not called by active runtime flow. They are intentionally retained for rollback and forensic comparison with prior working behavior.

### SoothSayer Investigation Runtime (Current)

**UI trigger path**

1. SoothSayer button `.btn-investigate` sends AJAX `action=rawwire_lead_investigate`
2. `class-lead-generator.php` resolves `source_id`, runs `investigate_source_parties()`
3. JSON response includes `success|message|source_id|failure_reasons`

**Backend orchestration gates**

- Availability gate: investigator provider must be configured/reachable
- Pipeline gate: runtime availability follows the saved `pipeline_mode`, so Perplexity Direct no longer depends on the legacy OpenAI/OpenClaw-only readiness check
- Perplexity Direct runtime now targets Perplexity `POST /v1/responses` for the research lane instead of defaulting to `POST /chat/completions`; the direct lane can send `preset`, `instructions`, `input`, and optional `max_steps`
- Recency gate: skip if already investigated recently (unless forced)
- Discovery gate: if no named parties, run permit discovery before deep dive
- Owner-builder permits are not auto-skipped; permit-context investigation continues because owner-side targets are valid
- Quality gate: placeholder-only outputs are filtered and not persisted
- Agent evidence gate: reject raw agent outputs with failure signatures, no evidence section, or too few URLs
- Decision-maker gate: prompt contract requires a per-decision-maker drill-down (contact details, role, importance, access route)
- Failure gate: if all parties fail, source status becomes `failed`

**Persistence + status semantics**

- Successful save path writes `party_profiles`, sets `investigation_status` to `completed` or `incomplete`, then triggers source scoring
- Empty/no-party path can yield `no_parties_found`
- Contractor/discovery hard-fail path sets `investigation_status=failed` with `investigator_notes`
- `failure_reasons` may appear on both success (warnings/partial failures) and error (terminal failure)
- Aggregated findings are structured to support downstream network-map modeling of industry actors, influence paths, and procurement relationships
- Lead Generator Perplexity workflow settings now expose preset selection and max-step override in addition to pass count, pass prompts, and optional per-pass model overrides

**Frontend render behavior**

- `success=true` and `failure_reasons` present: warning toast, then lead detail reload
- `success=true` and no reasons: success toast, then lead detail reload
- `success=false`: error toast, badge set to `Failed`, lead detail reload to render persistent failure banner
- Failure banner is dismissible in UI, but dismissal does not mutate underlying source status

### Instinct Interconnect Endpoint Contract (Toolbox ↔ Context Engine)

Caller: `cores/toolbox-core/adapters/context/class-context-instinct.php`  
Server: `context-engine/instinct/api.py`

- `GET /health` → service availability check
- `POST /context` → prioritized context retrieval
- `GET /context/mandatory` → mandatory-only context retrieval
- `POST /segments` → save memory segment
- `POST /segments/bulk` → bulk segment injection
- `POST /search` → semantic search
- `GET /stats` → store statistics

Response-shape notes used by adapter:
- search results key: `segments` (not `results`)
- bulk create count key: `created` (not `count`)

### 1. Dashboard Core - Access Control

**File:** `cores/dashboard-core/class-access-control.php`

Implements a 4-tier permission hierarchy:

```
TIER_DEVELOPER (highest)
    │
    ├── Full system access
    ├── AI model/provider configuration
    ├── MCP server configuration
    ├── Debug logging, diagnostics
    └── Template management
    │
TIER_ADMIN
    │
    ├── API key configuration
    ├── Workflow management
    ├── Scraper management
    └── User management
    │
TIER_EDITOR
    │
    ├── Trigger workflows
    ├── Generate content
    ├── Score content
    └── Export data
    │
TIER_VIEWER (lowest)
    │
    ├── View dashboard
    ├── View reports
    └── View activity logs
```

**Deployment Modes:**
- `MODE_INTERNAL` - Development/testing
- `MODE_CLIENT` - Client deployment
- `MODE_DEMO` - Trial/demo mode

### 2. Toolbox Core - MCP Server

**File:** `cores/toolbox-core/class-mcp-server.php`

Exposes 33 tools to AI agents via Model Context Protocol:

| Category | Tools | Count |
|----------|-------|-------|
| **Scraper** | list_sources, run, add_source, remove_source, get_status, preview | 6 |
| **Workflow** | list, trigger, create, get_status, stop, schedule, get_history | 7 |
| **Content** | list, get, approve, reject, score, generate | 6 |
| **AI** | query, summarize, sentiment, chat | 4 |
| **Config** | get_settings, update_settings, get_info | 3 |
| **WordPress** | system_info, plugins, error_log, database, health_check, cron, options | 7 |

**Integration Points:**
```php
// AI Engine function calling
add_filter('mwai_functions_list', [$this, 'register_mcp_functions']);
add_filter('mwai_functions_execute', [$this, 'execute_mcp_function'], 10, 3);

// External MCP clients (Claude Desktop)
add_filter('mwai_mcp_tools', [$this, 'register_mcp_protocol_tools']);
add_filter('mwai_mcp_callback', [$this, 'handle_mcp_protocol_call'], 10, 4);
```

### 3. Template Engine

**Files:** `cores/template-engine/`

Converts JSON templates to rendered admin pages:

```
JSON Template
     │
     ▼
┌─────────────────┐
│ template-engine │  ← Loads and caches templates
└────────┬────────┘
         │
    ┌────┴────┐
    ▼         ▼
┌────────┐ ┌────────┐
│  page  │ │ panel  │  ← Renderers
└────────┘ └────────┘
         │
         ▼
┌─────────────────┐
│workflow-handlers│  ← Action processing
└─────────────────┘
```

### 4. Module Core

**File:** `cores/module-core/module-core.php`

Discovers and loads modules implementing `RawWire_Module_Interface`:

```php
interface RawWire_Module_Interface {
    public function get_id();
    public function get_name();
    public function render_panel($panel_id, $context);
    public function get_panels();
    public function handle_action($action, $data);
}
```

---

## Data Flow - 6-Table Workflow

```
STAGE 1          STAGE 2           STAGE 3          STAGE 4          STAGE 5
─────────────────────────────────────────────────────────────────────────────────
candidates  →    approvals    →    content     →    releases    →   published
(scraper)        (AI top N)        (human OK)       (generated)      (finished)
                                                                          │
                                                          archives ←──────┘
                                                          (rejected)
```

**Table Schema:**
| Table | Purpose | Key Fields |
|-------|---------|------------|
| `wp_rawwire_candidates` | Raw scraped items | title, url, source, scraped_at |
| `wp_rawwire_approvals` | AI-scored pending review | score, analysis, approved_by |
| `wp_rawwire_content` | Human-approved for generation | status, generation_prompt |
| `wp_rawwire_releases` | Generated content ready | generated_content, generated_at |
| `wp_rawwire_published` | Published to WordPress | post_id, published_at |
| `wp_rawwire_archives` | Rejected items | rejection_reason, archived_at |

---

## REST API Endpoints

**Base:** `/wp-json/rawwire/v1/`

| Endpoint | Method | Auth | Purpose |
|----------|--------|------|---------|
| `/sync/status` | GET | Yes | Workflow progress |
| `/stats` | GET | Yes | Table counts |
| `/table-status` | GET | Yes | Detailed table status |
| `/content` | GET | Public | List content |
| `/content/{id}` | GET | Yes | Get single item |
| `/content/{id}/approve` | POST | Yes | Approve item |
| `/content/{id}/reject` | POST | Yes | Reject item |
| `/content/bulk-approve` | POST | Yes | Batch approve |
| `/workflow/start` | POST | Yes | Start workflow |
| `/workflow/status/{id}` | GET | Yes | Workflow status |
| `/ai/status` | GET | Yes | AI provider status |

---

## AI Integration

### Providers (Priority Order)

1. **Claude (Anthropic)** - `claude-sonnet-4-20250514`
2. **Groq** - `llama-3.3-70b-versatile` (free)
3. **OpenAI** - `gpt-4o`
4. **Ollama** - Local models (Docker)

### Docker Network

```
┌─────────────────────────────────────────┐
│            Docker Network               │
│                                         │
│  ┌───────────┐       ┌───────────┐     │
│  │ WordPress │──────►│  Ollama   │     │
│  │  :8000    │       │  :11434   │     │
│  └───────────┘       └───────────┘     │
│        │                   │            │
└────────┼───────────────────┼────────────┘
         │                   │
    localhost:8000      localhost:8001
         │                   │
    ┌────┴───────────────────┴────┐
    │         Host Machine         │
    └─────────────────────────────┘
```

**Key Configuration:**
- WordPress internal: `http://ollama:11434`
- Host external: `http://localhost:8001`
- AI Engine settings: `wp_options.mwai_options`

---

## Key Files Quick Reference

| Need To... | File |
|------------|------|
| Add REST endpoint | `rest-api.php` |
| Add MCP tool | `cores/toolbox-core/class-mcp-server.php` |
| Add permission | `cores/dashboard-core/class-access-control.php` |
| Add admin page | `includes/class-menu-manager.php` (SOT for all menu registration) |
| Add module | `modules/your-module/module.php` |
| Edit workflow | `cores/template-engine/workflow-handlers.php` |
| Add AI feature | `cores/toolbox-core/features/` |
| Add scraper | `cores/toolbox-core/adapters/scrapers/` |
| Create template | `templates/*.template.json` |
| Add JS behavior | `assets/js/admin.js` (main dashboard) or `assets/js/scraper-settings.js` (toolkit) |
| Style components | `dashboard.css` (main) or `assets/css/scraper-settings.css` (toolkit) |

---

## Frontend JavaScript Architecture

**Main Admin Script:** `assets/js/admin.js`
- Centralized RawWireAdmin object pattern
- Event delegation for dynamic content
- AJAX handlers with error recovery and safe state fallback
- Toast notification system via `showToast(message, type)`
- Source toggle handlers: `handleSourceToggle()`, `handleToolkitSourceToggle()`

**Scraper Settings:** `assets/js/scraper-settings.js`
- Dedicated handlers for scraper toolkit page
- Source CRUD operations with visual feedback
- Test button integration with workflow triggers
- Bulk operations: test all, enable/disable all, clear all

**Key Patterns:**
```javascript
// Event binding
$(document).on('change', '.source-checkbox', this.handleSourceToggle.bind(this));

// AJAX with error handling
$.ajax({
    success: function(response) {
        if (response.success) {
            // Update UI
            showToast('Success message', 'success');
        } else {
            // Revert to safe state
            showToast('Error: ' + response.data.message, 'error');
        }
    },
    error: function() {
        // Always revert to safe state on network errors
    }
});
```

---

## Configuration Options

| Option | Purpose | Default |
|--------|---------|---------|
| `rawwire_active_module` | Active template | `news-aggregator` |
| `rawwire_ollama_host` | Ollama endpoint | `http://127.0.0.1:8001` |
| `rawwire_scoring_batch_size` | Items per AI batch | `10` |
| `rawwire_auto_approve_threshold` | Auto-approve score | `0` (disabled) |
| `rawwire_chat_visibility` | Chat panel location | `rawwire_only` |
| `rawwire_engine_extensions` | AI engine settings | `[]` |

---

## Version History

- **1.0.29** - Source Toggle Integration, Test Workflow Buttons, Safe State Error Handling, JS Architecture Refactor
- **1.0.28** - MCP Tools (33), Access Control, Ollama fixes
- **1.0.26** - AI Chat Integration, Smart Provider Selection
- **1.0.25** - Professional UI Redesign, Dark/Light Mode
- **1.0.24** - Template Builder, Workflow System
- **1.0.18** - Initial modular architecture
