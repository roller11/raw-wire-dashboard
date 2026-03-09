# RAW_WIRE_DASHBOARD_AI_MAP
# Optimized for LLM context parsing - January 24, 2026

## METADATA
VERSION: 1.0.31
LANGUAGE: PHP_8.0+
FRAMEWORK: WordPress_6.0+
DATABASE: MySQL_8.0
FRONTEND: ES6_JavaScript
ARCHITECTURE: template_driven_modular

## SYSTEM_IDENTITY
```
PLUGIN_NAME: raw-wire-dashboard
ENTRY_POINT: raw-wire-dashboard.php
REST_BASE: /wp-json/rawwire/v1/
ADMIN_SLUG: raw-wire-dashboard
```

## MENU_ARCHITECTURE
```
MANAGER_FILE: includes/class-menu-manager.php

PRODUCTION_STRUCTURE (always visible):
  raw-wire-dashboard:    {label: "Raw Wire",      type: main_menu}
  raw-wire-dashboard:    {label: "Dashboard",     type: submenu, always_visible: true}
  rawwire-soothsayer:    {label: "Soothsayer",    type: submenu, always_visible: true}
  rawwire-user-options:  {label: "User Options",  type: submenu, always_visible: true}

DEVELOPER_STRUCTURE (dev_mode only):
  rawwire-ai-settings:    {label: "AI Settings",     type: submenu}
  rawwire-tools:          {label: "Tools",           type: submenu}
  rawwire-lead-sources:   {label: "Lead Generator",  type: submenu}
  rawwire-lead-approvals: {label: "Approvals",       type: submenu}
  rawwire-lead-completed: {label: "Leads",           type: submenu}
  rawwire-setup:          {label: "Setup",           type: submenu}
  rawwire-templates:      {label: "Templates",       type: submenu}
  rawwire-ai-agents:      {label: "AI Agents",       type: submenu}
  rawwire-options:        {label: "Options",         type: submenu}

SLUG_FORMAT: rawwire-* (no second hyphen). Main menu: raw-wire-dashboard.
TOTAL: 1 main + 3 production + 9 developer = 13 registrations.

TOOL_TAB_REGISTRATION:
  action: rawwire_register_tool_tabs
  method: RawWire_Menu_Manager::register_tool_tab($tool_id, $config)
  config_keys: [label, icon, required_feature, render_callback, priority]

WORKFLOW_TAB_REGISTRATION:
  action: rawwire_register_workflow_tabs
  method: RawWire_Menu_Manager::register_workflow_tab($workflow_id, $config)
  config_keys: [label, icon, render_callback, priority]

REGISTERED_TOOLS:
  ai_settings: {label: "AI Settings", feature: ai_settings, file: class-ai-settings-panel.php}
  ai_scraper: {label: "AI Scraper", feature: collection_workflow, file: class-ai-scraper-panel.php}
  workflow_db: {label: "Workflow DB", feature: collection_workflow, file: class-workflow-db-panel.php}

FORBIDDEN_PATTERNS:
  - add_submenu_page() outside class-menu-manager.php
  - menu registration outside class-menu-manager.php
  - template-defined menu structure
```

## CORE_FILES
```
BOOTSTRAP:
  - raw-wire-dashboard.php (1441 lines) [MAIN PLUGIN CLASS]
  - includes/bootstrap.php [ASSET_LOADING_REST_API]
  - includes/class-menu-manager.php [MENU_REGISTRATION]
  - includes/class-config-authority.php [SIGNED_CONFIG_SYSTEM]
  - rest-api.php (1737 lines) [ALL REST ENDPOINTS]

DASHBOARD_CORE:
  - cores/dashboard-core/class-access-control.php (730 lines) [PERMISSIONS]

MODULE_CORE:
  - cores/module-core/module-core.php (870 lines) [MODULE LOADER]

TOOLBOX_CORE:
  - cores/toolbox-core/class-mcp-server.php (3026 lines) [MCP_TOOLS]
  - cores/toolbox-core/class-tool-registry.php (623 lines) [SCHEDULING]
  - cores/toolbox-core/class-ai-adapter.php (855 lines) [AI_BRIDGE]
  - cores/toolbox-core/class-key-manager.php [API_KEYS]
  - cores/toolbox-core/class-tool-toggle-manager.php [TOOL_ENABLE_DISABLE]

TEMPLATE_ENGINE:
  - cores/template-engine/template-engine.php (1079 lines) [JSON_LOADER]
  - cores/template-engine/page-renderer.php (390 lines) [PAGE_HTML]
  - cores/template-engine/panel-renderer.php (1271 lines) [PANEL_HTML]
  - cores/template-engine/workflow-handlers.php (1436 lines) [ACTIONS]

SERVICES:
  - services/class-workflow-orchestrator.php [PRIMARY_WORKFLOW]
  - services/class-migration-service.php [DB_TABLES]
  - services/class-scraper-service.php [SCRAPE_COORD]

AI_INTEGRATIONS:
  - includes/integrations/class-ollama-engine.php [LOCAL_LLM]
  - includes/integrations/class-groq-engine.php [GROQ_LLM_DISABLED]
```

## ACCESS_CONTROL
```
TIERS:
  TIER_DEVELOPER: [ai_model_selection, mcp_server_config, debug_logging, tool_toggle, system_diagnostics, template_management]
  TIER_ADMIN: [api_key_config, workflow_management, scraper_management, schedule_management, user_management]
  TIER_EDITOR: [workflow_trigger, content_generation, content_scoring, data_export]
  TIER_VIEWER: [dashboard_view, reports_view, activity_view]

DEPLOYMENT_MODES: [internal, client, demo]

CAPABILITIES:
  rawwire_developer: full_access
  rawwire_manage: workflow_scraper_access
  rawwire_configure: settings_access
  rawwire_view: read_only_access
  rawwire_use_ai: ai_features_access

CHECK_PERMISSION:
  RawWire_Access_Control::get_instance()->can_access($feature);
```

## CONFIG_AUTHORITY
```
FILE: includes/class-config-authority.php

SIGNATURE_SYSTEM:
  algorithm: HMAC-SHA256
  ttl: 300_seconds
  secret: site-specific_auto-generated
  components: [config_key, value_hash, timestamp, nonce]

AUTHORIZATION_TIERS:
  free: 0 [dashboard, templates, settings]
  basic: 1 [+tools, workflows, ai_settings]
  pro: 2 [+ai_scraper, workflow_db, custom_panels, custom_tools, collection_workflow]
  enterprise: 3 [+multi_site, white_label, api_access]
  developer: 4 [ALL + debug_mode, raw_config_edit]

PROTECTED_CONFIGS:
  - rawwire_active_template
  - rawwire_template_settings_*
  - rawwire_license_key
  - rawwire_enabled_features
  - rawwire_tool_toggles
  - rawwire_api_keys
  - rawwire_mcp_settings
  - rawwire_ai_adapter_settings
  - rawwire_custom_tools
  - rawwire_custom_panels

SIGNED_CHANGE_FLOW:
  1. sign_config_change($key, $value, $context) → signed_payload
  2. verify_signed_change($payload) → {valid: bool, error: string}
  3. apply_signed_change($payload) → {success: bool, error: string}

LICENSE_FORMAT: TIER-XXXXXXXX-CHECKSUM

KEY_METHODS:
  RawWire_Config_Authority::get_instance()->sign_config_change($key, $value, $context)
  RawWire_Config_Authority::get_instance()->apply_signed_change($signed_payload)
  RawWire_Config_Authority::get_instance()->is_feature_authorized($feature_id)
  RawWire_Config_Authority::get_instance()->get_user_tier()
  RawWire_Config_Authority::get_instance()->get_audit_log($limit)
  RawWire_Access_Control::get_instance()->get_user_tier();
```

## MCP_TOOLS_REGISTRY
```
SCRAPER_TOOLS:
  rawwire_scraper_list_sources: {tier: ADMIN, params: [status?]}
  rawwire_scraper_run: {tier: ADMIN, params: [source_id!, limit?]}
  rawwire_scraper_add_source: {tier: ADMIN, params: [name!, url!, type!, auth?]}
  rawwire_scraper_remove_source: {tier: ADMIN, params: [source_id!]}
  rawwire_scraper_get_status: {tier: ADMIN, params: [source_id!]}
  rawwire_scraper_preview: {tier: EDITOR, params: [source_id!, limit?]}

WORKFLOW_TOOLS:
  rawwire_workflow_list: {tier: VIEWER, params: []}
  rawwire_workflow_trigger: {tier: EDITOR, params: [workflow_id!, params?]}
  rawwire_workflow_create: {tier: ADMIN, params: [name!, steps!, trigger?]}
  rawwire_workflow_get_status: {tier: VIEWER, params: [workflow_id!]}
  rawwire_workflow_stop: {tier: ADMIN, params: [workflow_id!]}
  rawwire_workflow_schedule: {tier: ADMIN, params: [workflow_id!, cron!]}
  rawwire_workflow_get_history: {tier: VIEWER, params: [workflow_id?, limit?]}

CONTENT_TOOLS:
  rawwire_content_list: {tier: VIEWER, params: [status?, limit?, offset?]}
  rawwire_content_get: {tier: VIEWER, params: [content_id!]}
  rawwire_content_approve: {tier: EDITOR, params: [content_id!]}
  rawwire_content_reject: {tier: EDITOR, params: [content_id!, reason?]}
  rawwire_content_score: {tier: EDITOR, params: [content_id!]}
  rawwire_content_generate: {tier: EDITOR, params: [content_id!, prompt?]}

AI_TOOLS:
  rawwire_ai_query: {tier: EDITOR, params: [query!, context?, model?]}
  rawwire_ai_summarize: {tier: EDITOR, params: [content!, max_length?]}
  rawwire_ai_sentiment: {tier: EDITOR, params: [content!]}
  rawwire_ai_chat: {tier: EDITOR, params: [message!, conversation_id?]}

CONFIG_TOOLS:
  rawwire_config_get_settings: {tier: ADMIN, params: [section?]}
  rawwire_config_update_settings: {tier: ADMIN, params: [settings!]}
  rawwire_config_get_info: {tier: VIEWER, params: []}

WORDPRESS_TOOLS:
  rawwire_wp_system_info: {tier: DEVELOPER, params: []}
  rawwire_wp_plugins: {tier: DEVELOPER, params: [status?]}
  rawwire_wp_error_log: {tier: DEVELOPER, params: [lines?, filter?]}
  rawwire_wp_database: {tier: DEVELOPER, params: [query_type!, table?]}
  rawwire_wp_health_check: {tier: DEVELOPER, params: []}
  rawwire_wp_cron: {tier: DEVELOPER, params: [action?]}
  rawwire_wp_options: {tier: DEVELOPER, params: [option_name?, action?]}
```

## DATABASE_SCHEMA
```
TABLES:
  wp_rawwire_candidates:
    columns: [id, title, url, content, source, source_type, scraped_at, status]
    indexes: [source, status, scraped_at]
    stage: 1

  wp_rawwire_approvals:
    columns: [id, candidate_id, title, url, content, score, analysis, approved_by, created_at]
    indexes: [score, approved_by, created_at]
    stage: 2

  wp_rawwire_content:
    columns: [id, approval_id, title, content, status, generation_prompt, created_at]
    indexes: [status, created_at]
    stage: 3

  wp_rawwire_releases:
    columns: [id, content_id, title, generated_content, generated_at, status]
    indexes: [status, generated_at]
    stage: 4

  wp_rawwire_published:
    columns: [id, release_id, post_id, title, published_at]
    indexes: [post_id, published_at]
    stage: 5

  wp_rawwire_archives:
    columns: [id, original_id, original_table, title, content, rejection_reason, archived_at]
    indexes: [original_table, archived_at]
    stage: 0

WORKFLOW_FLOW: candidates → approvals → content → releases → published
REJECTION_FLOW: any_stage → archives
```

## REST_ENDPOINTS
```
PUBLIC_ENDPOINTS:
  GET /filters: returns_status_options
  GET /content: returns_content_list

AUTHENTICATED_ENDPOINTS:
  GET /sync/status: workflow_progress
  GET /stats: table_counts
  GET /table-status: detailed_table_info
  POST /ensure-tables: create_missing_tables
  POST /clear-workflow-tables: truncate_all

  GET /content/{id}: single_item
  POST /content/{id}/approve: approve_item
  POST /content/{id}/reject: reject_item
  POST /content/bulk-approve: batch_approve
  POST /content/bulk-reject: batch_reject

  POST /workflow/start: start_workflow
  GET /workflow/status/{id}: workflow_progress
  GET /workflow/config: workflow_configuration

  GET /ai/status: ai_provider_status
  POST /ai/chat: chat_with_ai

PERMISSION_CHECK: manage_options_capability
```

## AI_PROVIDERS
```
PRIORITY_ORDER:
  1. anthropic: model=claude-sonnet-4-20250514
  2. groq: model=llama-3.3-70b-versatile [FREE]
  3. openai: model=gpt-4o
  4. ollama: model=llama3.1:8b [LOCAL]

OLLAMA_CONFIG:
  docker_internal: http://ollama:11434
  host_external: http://localhost:8001
  default_models: [qwen2.5-coder:14b, llama3.1:8b, llama3.2:latest]

AI_ENGINE_HOOKS:
  mwai_functions_list: register_tools_for_chatbot
  mwai_functions_execute: execute_tool_call
  mwai_mcp_tools: register_for_external_mcp
  mwai_mcp_callback: handle_external_mcp_call
  mwai_engines: register_custom_engine
  mwai_init_engine: instantiate_custom_engine
  option_mwai_options: inject_models_into_env

SETTINGS_OPTION: mwai_options
```

## TEMPLATE_SYSTEM
```
TEMPLATE_LOCATION: templates/*.template.json
ACTIVE_TEMPLATE_OPTION: rawwire_active_module

TEMPLATE_STRUCTURE:
  meta: {name, version, description}
  features: {feature_id: {label, default, dependencies}}
  pageDefinitions: {page_id: {label, slug, panels, requiredFeatures}}
  panelDefinitions: {panel_id: {type, config}}
  workflows: {workflow_id: {steps, triggers}}
  sourceTypes: {type_id: {label, parser, config}}

DATASOURCE_SYNTAX:
  db:table_name:field=value
  api:endpoint_name:params
  static:array_data

PANEL_TYPES: [stats, table, form, chart, list, custom, action_buttons]
```

## ADAPTERS
```
SCRAPERS:
  class-scraper-github.php: github_api_scraper
  class-scraper-native.php: wordpress_native_scraper
  class-scraper-api.php: generic_api_scraper
  class-scraper-brightdata.php: brightdata_web_scraper
  class-scraper-ai.php: ai_powered_scraper

GENERATORS:
  class-generator-ollama.php: local_ollama_generation
  class-generator-openai.php: openai_api_generation
  class-generator-anthropic.php: claude_api_generation

WORKFLOWS:
  class-workflow-internal.php: wordpress_cron_workflow
  class-workflow-n8n.php: n8n_external_workflow
  class-workflow-make.php: make_external_workflow

POSTERS:
  class-poster-wordpress.php: wordpress_post_creation
  class-poster-twitter.php: twitter_api_posting
  class-poster-discord.php: discord_webhook_posting

SCORERS:
  class-scorer-keyword.php: keyword_matching_scorer
  class-scorer-ai-relevance.php: ai_relevance_scorer
```

## CHAT_PANEL_CONFIG
```
VISIBILITY_OPTIONS:
  rawwire_only: only_on_rawwire_admin_pages
  everywhere: all_wordpress_admin_pages
  disabled: hidden

SETTINGS_OPTION: rawwire_chat_visibility

PROVIDER_SELECTION:
  method: get_preferred_env_id()
  fallback_chain: [anthropic, groq, openai, ollama]
```

## OPTIONS_REGISTRY
```
rawwire_active_module: active_template_name
rawwire_ollama_host: ollama_endpoint_url
rawwire_scoring_batch_size: items_per_ai_batch (default: 10)
rawwire_auto_approve_threshold: auto_approve_score (default: 0)
rawwire_chat_visibility: chat_panel_location
rawwire_engine_extensions: ai_engine_settings
rawwire_ollama_ai_engine_configured: setup_complete_flag
rawwire_caps_registered: capabilities_installed_flag
rawwire_tool_toggle_settings: enabled_disabled_tools
```

## HOOKS_REFERENCE
```
ACTIONS:
  rawwire_scrape_complete: fired_after_scraper_finishes
  rawwire_scoring_complete: fired_after_ai_scoring
  rawwire_workflow_complete: fired_after_workflow_finishes
  rawwire_content_approved: fired_when_content_approved
  rawwire_content_rejected: fired_when_content_rejected

FILTERS:
  rawwire_feature_permissions: modify_feature_tier_requirements
  rawwire_settings_permissions: modify_settings_tier_requirements
  rawwire_tool_permissions: modify_tool_tier_requirements
  rawwire_scraper_sources: modify_available_sources
  rawwire_ai_providers: modify_ai_provider_list
```

## COMMON_PATTERNS
```
SINGLETON_ACCESS:
  RawWire_Access_Control::get_instance()
  RawWire_MCP_Server::get_instance()
  RawWire_Tool_Registry::get_instance()
  RawWire_Tool_Toggle_Manager::get_instance()

PERMISSION_CHECK:
  $access = RawWire_Access_Control::get_instance();
  if (!$access->can_access('feature_name')) { return error; }

ADD_MCP_TOOL:
  $this->register_tool([
    'name' => 'rawwire_tool_name',
    'description' => 'Tool description for AI',
    'parameters' => ['type' => 'object', 'properties' => [...], 'required' => [...]],
    'callback' => [$this, 'handle_tool_name']
  ]);

DATABASE_QUERY:
  global $wpdb;
  $table = $wpdb->prefix . 'rawwire_tablename';
  $results = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));

REST_RESPONSE:
  return new WP_REST_Response(['success' => true, 'data' => $data], 200);
  return new WP_Error('error_code', 'Error message', ['status' => 400]);
```

## FILE_LOCATIONS_BY_TASK
```
add_rest_endpoint: rest-api.php
add_mcp_tool: cores/toolbox-core/class-mcp-server.php
add_permission: cores/dashboard-core/class-access-control.php
add_admin_page: includes/bootstrap.php, admin/
add_module: modules/your-module/module.php
edit_workflow: cores/template-engine/workflow-handlers.php
add_ai_feature: cores/toolbox-core/features/
add_scraper: cores/toolbox-core/adapters/scrapers/
create_template: templates/*.template.json
add_database_table: services/class-migration-service.php
```

## DEPRECATED_CODE_WARNING
```
AVOID_THESE_FILES:
  - services/class-scoring-handler.php [REDUNDANT with workflow-orchestrator]
  - services/class-storage-service.php [USES obsolete single-table design]
  - services/class-sync-service.php [DUPLICATES workflow-orchestrator]
  - cores/ai-discovery/ [INCOMPLETE, no real AI]
  - includes/integrations/class-groq-engine.php [DISABLED]

ALWAYS_USE:
  - services/class-workflow-orchestrator.php for workflow operations
  - cores/toolbox-core/class-mcp-server.php for tool registration
  - cores/dashboard-core/class-access-control.php for permissions
```

## END_AI_MAP
