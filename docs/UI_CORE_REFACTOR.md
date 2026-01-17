Dashboard Core (DC) Refactor Mapping

Purpose: Review current Dashboard Core (dashboard-template, bootstrap, etc.) and indicate what should stay in Dashboard Core (DC), what should move to Toolbox Core (TC), and what should move to Module Core (MC).

Legend:
- DC: Dashboard Core (presentational, user-facing code + menu wiring)
- TC: Toolbox Core (adapters, orchestration, credentials, job runner)
- MC: Module Core (module data, scoring rules, templates, field mappings)

Sections

1) Hero / Header
- Current: `dashboard-template.php` shows site name, tagline, last sync.
- Action: STAY in DC — presentation. Move dynamic content sources (tagline text, module description) to MC config.

2) Stats Deck
- Current: Hard-coded 5 cards (Total, Pending, Approved, Fresh_24h, Avg Score)
- Action: Move card definitions (labels, field, enabled, subtitle) to MC. DC keeps rendering loop.

3) Filters Bar
- Current: Source/category/status/min-score slider
- Action: MC provides `filters` options (allowed sources, categories). DC renders and sends filter state. TC may provide dynamic source discovery adapter if needed.

4) Finding Cards
- Current: Card layout and field rendering in UC.
- Action: DC stays for rendering. MC provides `card_display` config (which fields to show, labels). Data mapping from raw payload -> normalized fields should be in MC or prepared by Bootstrap using MC mappings.

5) Detail Drawer
- Current: Renders parameters (novelty, regulatory-impact...)
- Action: Parameter list and labels -> MC. DC renders drawer. Calculation of parameter values -> TC or processor but configuration in MC.

6) Activity Logs
- Current: Logs shown in dashboard UI.
- Action: DC stays. TC manages log storage and retrieval APIs; logs include orchestrator traces stored by TC.

7) Bootstrap & Data Preparation (`includes/bootstrap.php`)
- Current: Hard-coded field mappings and default parameters.
- Action: Move field mapping rules and default parameter lists to MC. Bootstrap should call MC APIs to get mappings and use TC for any external enrichment.

8) Data Processor (`includes/class-data-processor.php`)
- Current: Scoring algorithm with hard-coded keywords and authority lists.
- Action: Move all domain-specific scoring keywords, authority scores, monetary thresholds to MC. The scoring engine code stays as part of processor (could be in TC) but reads configuration from MC.

9) Data Simulator (`includes/class-data-simulator.php`)
- Current: Hard-coded templates for federal register, SEC, courts, etc.
- Action: Move simulator templates to MC. DC may expose simulator controls; TC may offer run-time generation if simulator needs adapter integrations.

10) CLI Commands (`includes/class-cli-commands.php`)
- Current: WP-CLI commands for generate, sync, stats, clear, test-scoring
- Action: CLI command registrations stay in DC/plugin, but the heavy lifting (generate, sync) should call TC (or processor/MC) depending on the action. Keep commands where they are but refactor internals to use new cores.

11) Templates (`templates/raw-wire-default.json`)
- Current: Partial template exists but not used consistently.
- Action: Convert to MC `module.json` format. DC reads MC module config to render UI.

Summary Recommendations
- Minimize UI changes: keep rendering code in DC and convert hard-coded strings to configuration reads from MC.
- Implement `Module Core` quickly to extract configs from existing code.
- Implement `Toolbox Core` to host connectors and orchestrator; progressively move network/adapter code there.

Small immediate steps
1. Create `modules/government-shocking-facts/module.json` by extracting current hard-coded lists.
2. Update `includes/bootstrap.php` to read field mappings from `RawWire_Module_Core::get_active_module()`.
3. Point `class-data-processor.php` to load scoring config from module core.

Files to edit next
- `includes/bootstrap.php`
- `includes/class-data-processor.php`
- `includes/class-data-simulator.php`
- `dashboard-template.php`
- `templates/raw-wire-default.json` -> convert to `modules/*.json`

