# AI Assistant Instructions - PERMANENT CONTEXT

**Last Updated**: January 15, 2026  
**Version**: 1.0.23  
**Status**: ✅ COMPLETE - DO NOT REBUILD

This file provides context for AI assistants (Claude, Copilot, Cursor, etc.) working on this codebase.

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

### Services Layer (YOUR CODE - ALREADY BUILT)
| File | Purpose | Status |
|------|---------|--------|
| `services/class-scraper-service.php` | Scrapes gov sources → candidates table | ✅ Complete |
| `services/class-scoring-handler.php` | AI scores → top 2 to approvals, rest to archives | ✅ Complete |
| `services/class-migration-service.php` | Creates all 6 database tables | ✅ Complete |
| `services/class-sync-service.php` | Orchestrates sync workflow | ✅ Complete |
| `services/class-storage-service.php` | Data persistence utilities | ✅ Complete |

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
class-scoring-handler.php::process_candidates()
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
- Create a new service file → **IT PROBABLY EXISTS in services/**
- Write database queries → **CHECK class-scoring-handler.php first**
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

### ⏳ TODO
- AI Chat Interface integration
- MCP Server for tool-calls
- Workflow orchestration system

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
        │   ├── module-core/              # UI/Template rendering
        │   ├── template-engine/          # JSON→HTML engine
        │   └── toolbox-core/             # External functionality
        │       ├── class-mcp-server.php  # AI tool-calls
        │       ├── class-ai-adapter.php  # AI Engine integration
        │       ├── adapters/
        │       │   ├── scrapers/         # GitHub, Native, API, Brightdata
        │       │   └── scorers/          # Keyword, AI Relevance
        │       └── interfaces/
        │
        ├── 📁 services/                  # Business logic layer
        │   ├── class-scraper-service.php
        │   ├── class-scoring-handler.php
        │   ├── class-migration-service.php
        │   └── class-workflow-orchestrator.php
        │
        ├── 📁 includes/                  # Shared classes
        │   ├── class-admin.php
        │   ├── class-ai-content-analyzer.php
        │   └── bootstrap.php
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

**DO NOT DELETE THIS FILE**  
**DO NOT IGNORE THIS CONTEXT**  
**SEARCH BEFORE WRITING NEW CODE**
