Dashboard Core (DC) Feature Inventory

Scope
- This inventory covers the existing WordPress plugin at `wordpress-plugins/raw-wire-dashboard/`.
- Goal: list what is already implemented, then decide what stays in DC vs what moves to TC (toolbox-core) or MC (module-core).

DC: Features currently in place (by area)

1) Initialization + Safety
- Deterministic init controller: `includes/class-init-controller.php`
  - Phased loading order (core utilities → permissions → migrations → modules → endpoints → legacy → bootstrap)
  - Tracks init errors and logs system start
- Error boundary system: `includes/class-error-boundary.php`
  - Wrap helpers for module calls, REST, AJAX, DB, and timeouts
- Input validation/sanitization: `includes/class-validator.php`
  - Schemas + typed validation + sanitizers

2) Permissions / RBAC
- Capabilities and role grants: `includes/class-permissions.php`
  - Custom caps: view dashboard, manage modules, edit config, view/clear logs, approve content, manage API keys
  - REST + AJAX permission helpers

3) Database / Migrations
- Activation migration manager (loaded from main plugin): `includes/migrations/class-migration-manager.php`
- Legacy schema loader exists for compatibility: `includes/db/schema.php`

4) Admin UI (Dashboard)
- Main dashboard page registration + asset loading: `includes/bootstrap.php`
  - Enqueues CSS/JS for dashboard + activity logs
  - Prepares findings from DB rows (`prepare_findings`) and summarises metrics
- Template-based dashboard UI: `dashboard-template.php`
  - Hero header (template name, last sync)
  - Stats deck (5 cards)
  - Filters and quick filter chips
  - Findings list (cards) + detail drawer
  - Activity logs section UI container
- Activity logs UI + AJAX: `includes/class-activity-logs.php`
  - Tabbed info/errors, stats panel, refresh/clear actions, system info panel

5) Settings
- GitHub integration settings UI: `includes/class-settings.php`
  - Token + repo options via WP Settings API

6) Content Lifecycle / Approval
- Approval workflow: `includes/class-approval-workflow.php`
  - Approve/reject single item, bulk approve/reject
  - Approval history recording (if table exists)
  - Stats and history retrieval

7) Data Ingestion / Fetching
- GitHub fetcher: `includes/class-github-fetcher.php`
  - Fetches repo content via GitHub API and hands off to processor
  - Updates cache + last sync timestamp
- GitHub crawler (exists): `includes/class-github-crawler.php`

8) Data Processing + Scoring
- Data processor: `includes/class-data-processor.php`
  - Duplicate detection, scoring, persistence to `rawwire_content`
  - Batch processing entrypoint used by fetcher

9) Simulation / Test Data
- Data simulator: `includes/class-data-simulator.php`
  - Generates sample items for development / validation

10) Search & Filtering
- Search service and filter chain:
  - `includes/class-search-service.php`
  - `includes/search/filter-chain.php`
  - Search modules: category/date/keyword/relevance

11) REST API
- Two REST controller implementations exist:
  - `includes/class-rest-api-controller.php` (large, includes content/stats/logs/automation/meow-ai)
  - `includes/api/class-rest-api-controller.php` (used by init controller)
- Health endpoint: added by init controller at `rawwire/v1/health`

12) Caching
- Cache manager: `includes/class-cache-manager.php` (RWD_Cache_Manager)
- Some legacy callers refer to `RawWire_Cache_Manager` (needs reconciliation)

13) WP-CLI
- CLI commands: `includes/class-cli-commands.php`
  - Generate data, sync, stats, clear, test scoring

14) Legacy / Duplicates to Watch
- Older core singleton: `includes/class-dashboard-core.php` (pre-init-controller architecture)
- Main plugin file also includes a legacy `Raw_Wire_Dashboard` class in `raw-wire-dashboard.php`
  - Multiple architectures coexist; init controller is intended as the single entrypoint

Initial DC→TC/MC Move Candidates (high confidence)

Move to MC (module-core)
- Template/module config loader (currently `includes/bootstrap.php::load_template_config`)
- Field mappings + default parameters (currently `includes/bootstrap.php::prepare_findings` defaults)
- Domain scoring config (currently hard-coded in `includes/class-data-processor.php`)
- Simulator templates + source types (currently `includes/class-data-simulator.php`)

Move to TC (toolbox-core)
- External integrations/adapters:
  - GitHub fetch/crawl (currently `includes/class-github-fetcher.php`, `includes/class-github-crawler.php`)
- Orchestration actions (future): n8n workflow creation/execution, email sending, social posting

Stay in DC (dashboard-core)
- Dashboard presentation and admin menu wiring (`includes/bootstrap.php`, `dashboard-template.php`, assets)
- RBAC and validation (keep centralized)
- Activity logs UI containers + rendering (but TC should own execution/audit events over time)
- Approval workflow UI triggers + persistence

Next step
- Decide the first extraction target. Recommended first move: "template config loader" because it’s small, low-risk, and immediately makes DC more module-driven.
