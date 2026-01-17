# Raw Wire Dashboard - AI Knowledge Base
## Vector Store Context Document (Internal)

**Version**: 1.0.23  
**Last Updated**: January 15, 2026  
**Purpose**: Comprehensive context for AI Engine chatbot and MCP tools

---

## 1. SYSTEM IDENTITY

**Raw Wire Dashboard** is a WordPress plugin for automated content aggregation, AI-powered analysis, and publishing workflows. It integrates with **AI Engine Pro** to provide MCP (Model Context Protocol) tools that AI agents can use to manage the entire content pipeline.

### Core Capabilities
- Multi-source content scraping (GitHub, RSS, APIs, HTML)
- AI-powered relevance scoring
- Human-in-the-loop approval workflow
- Automated content generation
- WordPress publishing integration

---

## 2. ARCHITECTURE OVERVIEW

### 2.1 Template-First Architecture
**CRITICAL**: All UI and data display is driven by JSON templates, NOT hardcoded in modules.

```
┌─────────────────────────────────────────────────────────────┐
│                    TEMPLATE (JSON)                          │
│  Defines: panels, data sources, columns, actions, styling   │
└─────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────┐
│                  TEMPLATE ENGINE (PHP)                      │
│  Reads template → Resolves data bindings → Renders HTML     │
└─────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────┐
│                   DASHBOARD.JS                              │
│  Generic handlers for actions, AJAX, UI interactions        │
└─────────────────────────────────────────────────────────────┘
```

**Rules**:
- ✅ Add features via template JSON configuration
- ✅ Use generic handlers in dashboard.js
- ✅ Data comes from REST API or db bindings
- ❌ NEVER embed SQL, HTML tables, or JavaScript in modules
- ❌ NEVER hardcode business logic in PHP

### 2.2 Core Directory Structure
```
raw-wire-dashboard/
├── cores/
│   ├── toolbox-core/           # MCP Server, AI Adapter, Scrapers, Scorers
│   │   ├── class-ai-adapter.php        # AI Engine integration
│   │   ├── class-mcp-server.php        # MCP tools for AI agents
│   │   ├── class-tool-registry.php     # Tool management
│   │   ├── adapters/
│   │   │   ├── scrapers/               # Content scrapers
│   │   │   └── scorers/                # Scoring algorithms
│   │   ├── engines/
│   │   │   └── class-groq-engine.php   # Groq LLM integration
│   │   └── features/
│   │       ├── class-ai-settings-panel.php
│   │       ├── class-ai-scraper-panel.php
│   │       └── class-chatbot-context.php
│   ├── template-engine/        # JSON template rendering
│   └── module-core/            # Module system (fallbacks only)
├── services/
│   └── class-workflow-orchestrator.php  # Pipeline orchestration
├── templates/
│   └── news-aggregator.template.json    # Default template
├── rest-api.php                # All REST endpoints
└── dashboard.js                # Frontend handlers
```

---

## 3. CONTENT PIPELINE

### 3.1 Six-Stage Workflow
```
┌──────────────────────────────────────────────────────────────────────────┐
│                         CONTENT PIPELINE                                  │
├──────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│   [SOURCES]                                                              │
│       ↓                                                                  │
│   ┌─────────────┐    Scraper     ┌─────────────────┐                    │
│   │  External   │ ─────────────→ │  1. CANDIDATES  │ (staging)          │
│   │   APIs      │                │    0 records    │                    │
│   └─────────────┘                └────────┬────────┘                    │
│                                           ↓                              │
│                                    AI Scoring                            │
│                                           ↓                              │
│                        ┌──────────────────┴──────────────────┐          │
│                        ↓                                      ↓          │
│               ┌─────────────────┐                  ┌─────────────────┐  │
│               │  2. APPROVALS   │                  │  0. ARCHIVES    │  │
│               │   (AI approved) │                  │   (rejected)    │  │
│               │    0 records    │                  │   18 records    │  │
│               └────────┬────────┘                  └─────────────────┘  │
│                        ↓                                                 │
│                 Human Review                                             │
│                        ↓                                                 │
│               ┌─────────────────┐                                       │
│               │   3. CONTENT    │                                       │
│               │ (generation Q)  │                                       │
│               │    4 records    │                                       │
│               └────────┬────────┘                                       │
│                        ↓                                                 │
│                 AI Generation                                            │
│                        ↓                                                 │
│               ┌─────────────────┐                                       │
│               │   4. RELEASES   │                                       │
│               │ (ready publish) │                                       │
│               │    0 records    │                                       │
│               └────────┬────────┘                                       │
│                        ↓                                                 │
│                   Publish                                                │
│                        ↓                                                 │
│               ┌─────────────────┐                                       │
│               │  5. PUBLISHED   │                                       │
│               │   (complete)    │                                       │
│               │    0 records    │                                       │
│               └─────────────────┘                                       │
│                                                                          │
└──────────────────────────────────────────────────────────────────────────┘
```

### 3.2 Database Tables
| Table | Stage | Purpose | Populated By |
|-------|-------|---------|--------------|
| `wp_rawwire_candidates` | 1 | Raw scraped items | Scraper |
| `wp_rawwire_approvals` | 2 | AI-approved for human review | AI Scorer |
| `wp_rawwire_content` | 3 | Queued for generation | Human approval |
| `wp_rawwire_releases` | 4 | Ready to publish | AI generation |
| `wp_rawwire_published` | 5 | Published to WordPress | Publish action |
| `wp_rawwire_archives` | 0 | Rejected items (audit trail) | AI/Human rejection |

---

## 4. MCP TOOLS REFERENCE

### 4.1 Raw Wire MCP Tools (13 functions)
Registered via `mwai_functions_list` filter, available to AI agents:

#### Scraper Tools
| Function | Description | Key Args |
|----------|-------------|----------|
| `rawwire_scraper_list_sources` | List configured scraper sources | `status`: active/paused/all |
| `rawwire_scraper_run` | Execute a scraper | `source_id`, `limit` |
| `rawwire_scraper_add_source` | Add new scraper source | `name`, `type`, `url`, `auth` |

#### Content Pipeline Tools
| Function | Description | Key Args |
|----------|-------------|----------|
| `rawwire_content_list` | Query pipeline tables | `table`, `status`, `limit` |
| `rawwire_content_move` | Move items between stages | `ids`, `from_table`, `to_table` |
| `rawwire_content_score` | Score items with AI | `ids`, `criteria` |

#### Data & Stats Tools
| Function | Description | Key Args |
|----------|-------------|----------|
| `rawwire_data_query` | Query custom tables | `table`, `filters`, `limit` |
| `rawwire_stats_get` | Get dashboard statistics | `period`: today/week/month/all |

#### Workflow Tools
| Function | Description | Key Args |
|----------|-------------|----------|
| `rawwire_workflow_create` | Create automation workflow | `name`, `steps[]`, `trigger` |

### 4.2 AI Engine Core MCP Tools (37 functions)
WordPress CRUD operations:

| Category | Functions |
|----------|-----------|
| Posts | `wp_get_posts`, `wp_create_post`, `wp_update_post`, `wp_delete_post`, `wp_get_post_snapshot` |
| Media | `wp_get_media`, `wp_upload_media`, `wp_update_media`, `wp_delete_media` |
| Users | `wp_get_users`, `wp_create_user`, `wp_update_user` |
| Terms | `wp_get_terms`, `wp_create_term`, `wp_add_post_terms` |
| Options | `wp_get_option`, `wp_update_option` |
| AI | `mwai_vision`, `mwai_image` |

### 4.3 AI Engine Plugin Tools (13 functions)
Create and edit plugins directly:

| Function | Description |
|----------|-------------|
| `wp_create_plugin` | Create new plugin |
| `wp_plugin_put_file` | Write files to plugin |
| `wp_plugin_alter_file` | Search/replace in files |
| `wp_plugin_get_file` | Read plugin files |

---

## 5. REST API ENDPOINTS

### 5.1 Workflow Endpoints
```
POST /wp-json/rawwire/v1/workflow/start
  Body: { scraper, max_records, target_table, sources[], async }
  
GET  /wp-json/rawwire/v1/workflow/config
  Returns: { scrapers, scorers, target_tables, presets, limits }
  
GET  /wp-json/rawwire/v1/workflow/status/{id}
  Returns: { stage, progress, message }
```

### 5.2 AI Status Endpoint
```
GET  /wp-json/rawwire/v1/ai/status
  Returns: {
    available: true,
    pro: true,
    version: "3.3.1",
    environments: [...],
    mcp_server: true,
    groq_engine: false
  }
```

### 5.3 Content Endpoints
```
GET  /wp-json/rawwire/v1/stats
GET  /wp-json/rawwire/v1/table-status
POST /wp-json/rawwire/v1/content/approve
POST /wp-json/rawwire/v1/content/snooze
POST /wp-json/rawwire/v1/clear-workflow-tables
```

---

## 6. AVAILABLE ADAPTERS

### 6.1 Scrapers
| ID | Class | Description | Tier |
|----|-------|-------------|------|
| `github` | RawWire_Adapter_Scraper_GitHub | GitHub API scraping | Free |
| `native` | RawWire_Adapter_Scraper_Native | DOM parsing | Free |
| `api` | RawWire_Adapter_Scraper_API | Generic REST API | Free |
| `brightdata` | RawWire_Adapter_Scraper_Brightdata | Bright Data proxy | Value |
| `ai` | RawWire_Adapter_Scraper_AI | AI semantic scraping | Value |

### 6.2 Scorers
| ID | Class | Description | Tier |
|----|-------|-------------|------|
| `keyword` | RawWire_Scorer_Keyword | Keyword matching | Free |
| `ai_relevance` | RawWire_Scorer_AI_Relevance | AI semantic scoring | Value |

#### Keyword Scorer (Free Tier)
**Location**: `cores/toolbox-core/adapters/scorers/class-scorer-keyword.php`

Scores content based on keyword matching against campaign keywords:
- **Primary Keywords**: Weighted 70% (e.g., 'affiliate', 'marketing')
- **Secondary Keywords**: Weighted 30% (e.g., 'traffic', 'conversion')
- **Freshness Score**: Based on publication date (0-100)
- **Content Quality**: Based on word count/depth (0-100)

**Scoring Formula**:
```
final_score = (keyword_relevance * 0.30) + (quality * 0.25) + 
              (timeliness * 0.20) + (uniqueness * 0.15) + (engagement * 0.10)
```

**Example Output**:
```json
{
  "score": 21,
  "reasoning": "Keyword matches: 2/10 (28%). Freshness: 10%. Content quality: 20%.",
  "scorer": "keyword",
  "score_breakdown": {
    "keyword_relevance": 28,
    "freshness": 10,
    "content_quality": 20
  }
}
```

#### AI Relevance Scorer (Value Tier)
**Location**: `cores/toolbox-core/adapters/scorers/class-scorer-ai-relevance.php`

Uses AI Engine to perform semantic relevance scoring:
- Analyzes content against campaign context
- Provides detailed reasoning
- Recommends: approve/review/reject
- Falls back to keyword scorer if AI unavailable

**Scoring Criteria** (sent to AI):
1. RELEVANCE: Campaign keyword/niche alignment
2. QUALITY: Writing quality, professionalism
3. TIMELINESS: Market relevance
4. UNIQUENESS: Fresh perspective
5. ENGAGEMENT: Audience appeal

### 6.3 AI Environments (Configured)
| ID | Name | Type | Use Case |
|----|------|------|----------|
| `69uakao7` | groq | openai | Fast inference (Llama) |
| `hr915hqs` | Claude | anthropic | Complex analysis |
| `orymghco` | New Environment | openai | General purpose |

---

## 7. WORKFLOW EXAMPLES

### 7.1 Start a Scraping + Scoring Workflow
```javascript
// Via REST API
fetch('/wp-json/rawwire/v1/workflow/start', {
  method: 'POST',
  headers: { 'X-WP-Nonce': nonce },
  body: JSON.stringify({
    scraper: 'github',
    scorer: 'keyword',              // or 'ai_relevance' for AI scoring
    max_records: 10,
    target_table: 'candidates',
    auto_approve_threshold: 70,     // Auto-approve scores >= 70
    sources: [{
      url: 'https://api.github.com/search/repositories',
      params: { q: 'topic:wordpress-plugin stars:>100' }
    }]
  })
});

// Response:
{
  "success": true,
  "execution_id": "f2544d1d-ebaa-4825-8c11-b7cc3927c2ab",
  "items_scraped": 10,
  "items_scored": 10,
  "avg_score": 45,
  "items_stored": 10,
  "items_auto_approved": 3,
  "target_table": "candidates"
}
```

### 7.2 Move Items Through Pipeline
```javascript
// Approve items (move candidates → approvals)
fetch('/wp-json/rawwire/v1/content/approve', {
  method: 'POST',
  body: JSON.stringify({ content_ids: [1, 2, 3] })
});
```

### 7.3 AI-Powered Analysis (via Chatbot)
```
User: "Scrape the latest WordPress plugins and score them for relevance"

AI uses: 
1. rawwire_scraper_run({ source_id: 'github', limit: 20 })
2. rawwire_content_score({ ids: [...], criteria: 'wordpress_relevance' })
3. rawwire_content_list({ table: 'approvals', status: 'approved' })
```

---

## 8. CONFIGURATION

### 8.1 Key Options
| Option Key | Description |
|------------|-------------|
| `rawwire_ai_adapter_settings` | AI adapter defaults (env, model, temperature) |
| `rawwire_mcp_settings` | MCP server configuration |
| `rawwire_scraper_sources` | Configured scraper sources |
| `rawwire_workflows` | Saved workflow definitions |

### 8.2 JavaScript Config Object
```javascript
window.RawWireCfg = {
  nonce: 'wp_nonce_value',
  rest: '/wp-json/rawwire/v1',
  ajaxurl: '/wp-admin/admin-ajax.php',
  hasApiKey: true,
  template: 'news-aggregator',
  userCaps: { manage_options: true }
};
```

---

## 9. ERROR HANDLING & FALLBACKS

### 9.1 AI Unavailable Fallback
When AI Engine is not available or API fails:
```php
// AI Scraper falls back to keyword scoring
if (!$this->ai || !$this->ai->is_available()) {
    return $this->basic_score_batch($items);  // Keyword-based
}
```

### 9.2 Scraper Error Handling
```php
// Scrapers return WP_Error on failure
$result = $scraper->scrape($source);
if (is_wp_error($result)) {
    RawWire_Logger::log_activity('Scraper failed', 'error', [
        'source' => $source['url'],
        'error' => $result->get_error_message()
    ]);
}
```

---

## 10. COMMON TASKS

### 10.1 "Run a workflow to get new content"
1. Call `rawwire_scraper_run` or POST `/workflow/start`
2. Check `/workflow/status/{id}` for progress
3. Items appear in candidates table
4. AI scores move top items to approvals

### 10.2 "Show me what needs review"
1. Call `rawwire_content_list({ table: 'approvals' })`
2. Or check dashboard Approvals panel

### 10.3 "Publish approved content"
1. Call `wp_create_post` with content from releases table
2. Or use `rawwire_content_move({ from: 'releases', to: 'published' })`

### 10.4 "Get system status"
1. Call GET `/ai/status` for AI availability
2. Call GET `/stats` for pipeline counts
3. Call GET `/table-status` for detailed table info

---

## 11. INTEGRATION NOTES

### 11.1 AI Engine Integration
- Uses `Meow_MWAI_Query_Function` and `Meow_MWAI_Query_Parameter` classes
- Hooks: `mwai_functions_list`, `mwai_functions_execute`, `mwai_ai_query`
- Chatbot context injected via `RawWire_Chatbot_Context` class

### 11.2 WordPress Integration
- REST API with X-WP-Nonce authentication
- Uses standard WP database tables with `$wpdb->prefix`
- Cron jobs for background processing

---

*This document is optimized for vector store embedding. Each section is self-contained with clear headers for semantic search.*
