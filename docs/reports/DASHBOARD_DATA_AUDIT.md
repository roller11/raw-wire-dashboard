# Dashboard Data Display Audit - Modularization Plan
**Date:** January 5, 2026  
**Purpose:** Identify every data point displayed and categorize for module system

---

## 🎯 Audit Methodology

**Categories:**
- **UNIVERSAL** - Displayed on all dashboards regardless of module (core functionality)
- **MODULE-SPECIFIC** - Defined by module configuration (industry-specific)
- **HYBRID** - Universal display but module defines labels/options

---

## 📊 SECTION 1: HERO HEADER

### Data Points Displayed:
| Item | Current Value | Category | Notes |
|------|---------------|----------|-------|
| Site Name | "Raw-Wire · Findings Control" | **MODULE** | Should be module-configurable |
| Tagline | "Signal-driven curation for raw-wire.com" | **MODULE** | Module-specific messaging |
| Description | "Top 20 findings per source..." | **MODULE** | Module-specific description |
| Template Name | `$template_config['name']` | **HYBRID** | Universal display, module name varies |
| Last Sync Time | `$stats['last_sync']` | **UNIVERSAL** | All dashboards need sync timestamp |

### Module Configuration Needed:
```json
{
  "hero": {
    "site_name": "Raw-Wire · Findings Control",
    "tagline": "Signal-driven curation for raw-wire.com",
    "description": "Top 20 findings per source, ranked, scored...",
    "show_template_badge": true,
    "show_last_sync": true
  }
}
```

---

## 📊 SECTION 2: STATS DECK (5 Cards)

### Data Points Displayed:
| Card | Label | Value Source | Category | Notes |
|------|-------|--------------|----------|-------|
| 1 | "Total Findings" | `$ui_metrics['total']` | **HYBRID** | Label should be module-configurable ("Findings" vs "Properties" vs "Studies") |
| 1 | Subtitle | "Across all sources" | **MODULE** | Module-specific clarification |
| 2 | "Pending Review" | `$ui_metrics['pending']` | **UNIVERSAL** | Approval workflow is universal |
| 2 | Subtitle | "Awaiting human judgment" | **MODULE** | Could vary by industry |
| 3 | "Approved" | `$ui_metrics['approved']` | **UNIVERSAL** | Approval workflow is universal |
| 3 | Subtitle | "Ready for distribution" | **MODULE** | Module defines what happens after approval |
| 4 | "Fresh (24h)" | `$ui_metrics['fresh_24h']` | **HYBRID** | Universal metric but timeframe could be module-configurable |
| 4 | Subtitle | "Recent, higher-signal items" | **MODULE** | Module-specific interpretation |
| 5 | "Avg Score" | `$ui_metrics['avg_score']` | **UNIVERSAL** | All modules use relevance scoring |
| 5 | Subtitle | "Weighted relevance" | **MODULE** | Module defines what "relevance" means |

### Module Configuration Needed:
```json
{
  "stats": {
    "card_1": {
      "label": "Total Findings",
      "field": "total",
      "subtitle": "Across all sources",
      "enabled": true
    },
    "card_2": {
      "label": "Pending Review",
      "field": "pending",
      "subtitle": "Awaiting human judgment",
      "enabled": true
    },
    "card_3": {
      "label": "Approved",
      "field": "approved",
      "subtitle": "Ready for distribution",
      "enabled": true
    },
    "card_4": {
      "label": "Fresh (24h)",
      "field": "fresh_24h",
      "subtitle": "Recent, higher-signal items",
      "timeframe_hours": 24,
      "enabled": true
    },
    "card_5": {
      "label": "Avg Score",
      "field": "avg_score",
      "subtitle": "Weighted relevance",
      "enabled": true
    }
  }
}
```

---

## 📊 SECTION 3: FILTERS BAR

### Data Points Displayed:
| Filter | Options Source | Category | Notes |
|--------|----------------|----------|-------|
| Source | `$template_config['filters']['sources']` | **MODULE** | Module defines available sources (GitHub, Federal Register, MLS, etc.) |
| Category | `$template_config['filters']['categories']` | **MODULE** | Module-specific categories (Rule/Notice vs Condo/House vs Clinical/Observational) |
| Status | `$template_config['filters']['statuses']` | **UNIVERSAL** | Approval statuses are universal |
| Min Score Slider | 0-100 range | **UNIVERSAL** | All modules use 0-100 scoring |

### Quick Filter Chips:
| Chip | Filter Applied | Category | Notes |
|------|----------------|----------|-------|
| "Fresh 24h" | `data-filter="fresh"` | **HYBRID** | Universal concept but timeframe could vary |
| "Pending" | `data-filter="pending"` | **UNIVERSAL** | Approval workflow |
| "Approved" | `data-filter="approved"` | **UNIVERSAL** | Approval workflow |
| "Score > 80" | `data-filter="highscore"` | **HYBRID** | Universal but threshold could be module-configurable |

### Module Configuration Needed:
```json
{
  "filters": {
    "sources": ["github", "federal-register", "sec", "press-release"],
    "categories": ["rule", "proposed-rule", "notice", "court-ruling"],
    "statuses": ["pending", "approved", "rejected", "published"],
    "quick_filters": [
      {"label": "Fresh 24h", "filter": "fresh", "hours": 24},
      {"label": "Pending", "filter": "pending"},
      {"label": "Approved", "filter": "approved"},
      {"label": "Score > 80", "filter": "highscore", "threshold": 80}
    ]
  }
}
```

---

## 📊 SECTION 4: FINDING CARDS (Main Content)

### Data Points Per Card:
| Field | Display Location | Value Source | Category | Notes |
|-------|------------------|--------------|----------|-------|
| Rank | Top-left badge | `$finding['rank']` | **UNIVERSAL** | All modules rank items |
| Title | Card header | `$finding['title']` | **UNIVERSAL** | All items have titles |
| Source Badge | Meta line | `$finding['source']` | **MODULE** | Badge label varies (GitHub vs MLS vs PubMed) |
| Category Badge | Meta line | `$finding['category']` | **MODULE** | Module-specific categories |
| Score Badge | Meta line | `$finding['score']` | **UNIVERSAL** | All modules score items |
| Freshness Badge | Meta line | `$finding['freshness_label']` | **UNIVERSAL** | All modules track recency |
| Summary | Card body | `$finding['summary']` | **UNIVERSAL** | All items have summaries |
| Tags | Bottom of card | `$finding['tags']` | **MODULE** | Module defines tag taxonomy |
| Confidence % | Right column | `$finding['confidence']` | **UNIVERSAL** | ML confidence is universal |
| Status Badge | Right column | `$finding['status']` | **UNIVERSAL** | Approval workflow |

### Card Actions:
| Action | Category | Notes |
|--------|----------|-------|
| Approve Button | **UNIVERSAL** | Core approval workflow |
| Snooze Button | **UNIVERSAL** | Core workflow feature |

### Module Configuration Needed:
```json
{
  "card_display": {
    "show_rank": true,
    "show_source_badge": true,
    "show_category_badge": true,
    "show_score_badge": true,
    "show_freshness_badge": true,
    "show_tags": true,
    "show_confidence": true,
    "show_status_badge": true,
    "actions": ["approve", "snooze"]
  },
  "badge_labels": {
    "source_prefix": "Source:",
    "category_prefix": "Category:",
    "score_prefix": "Score:",
    "confidence_suffix": "% Confidence"
  }
}
```

---

## 📊 SECTION 5: DETAIL DRAWER (Slide-out)

### Data Points Displayed:
| Field | Display | Category | Notes |
|-------|---------|----------|-------|
| Title | Drawer header | **UNIVERSAL** | |
| Source | Badge | **MODULE** | Module-specific source types |
| Category | Badge | **MODULE** | Module-specific categories |
| Score | Badge | **UNIVERSAL** | |
| Status | Badge | **UNIVERSAL** | |
| Link | External link button | **UNIVERSAL** | All items have source URLs |
| Full Summary | Main content | **UNIVERSAL** | |
| Tags | Tag list | **MODULE** | Module-specific taxonomy |
| Confidence | Percentage | **UNIVERSAL** | |
| Parameters | List | **MODULE** | Module-specific evaluation criteria |
| Rationale | Text block | **MODULE** | Module-specific reasoning |
| Updated At | Timestamp | **UNIVERSAL** | |

### Parameters Display:
**Current (Hard-Coded for Government):**
- novelty
- regulatory-impact
- market-sentiment
- technical-signal
- risk-profile

**Should Be (Module-Configurable):**
```json
{
  "detail_view": {
    "parameters": [
      "novelty",
      "regulatory-impact",
      "market-sentiment",
      "technical-signal",
      "risk-profile"
    ],
    "parameter_labels": {
      "novelty": "Novelty Factor",
      "regulatory-impact": "Regulatory Impact",
      "market-sentiment": "Market Sentiment",
      "technical-signal": "Technical Signal",
      "risk-profile": "Risk Profile"
    }
  }
}
```

---

## 📊 SECTION 6: ACTIVITY LOGS TAB

### Data Points Displayed:
| Field | Category | Notes |
|-------|----------|-------|
| Timestamp | **UNIVERSAL** | All logs need timestamps |
| Action Type | **UNIVERSAL** | fetch, process, store, etc. |
| Message | **UNIVERSAL** | Log message text |
| Severity | **UNIVERSAL** | info, warning, error |
| Details JSON | **UNIVERSAL** | Structured log data |

**Status:** Already universal, no module configuration needed.

---

## 📊 SECTION 7: DATA PROCESSING (Bootstrap.php)

### Hard-Coded Values Needing Modularization:

#### A. Field Mappings (prepare_findings)
**Current:**
```php
'source' => $source_data['source'] ?? self::infer_source($issue),
'category' => $issue['category'] ?? ($source_data['category'] ?? 'uncategorized'),
'summary' => $source_data['summary'] ?? ($issue['notes'] ?? ''),
```

**Should Be Module-Driven:**
```json
{
  "field_mappings": {
    "title": ["title", "name", "headline"],
    "summary": ["summary", "abstract", "description", "notes"],
    "source": ["source", "source_name", "publisher"],
    "category": ["category", "type", "classification"],
    "url": ["url", "link", "html_url"]
  }
}
```

#### B. Default Parameters
**Current:**
```php
'parameters' => [
    'novelty',
    'regulatory-impact',
    'market-sentiment',
    'technical-signal',
    'risk-profile'
]
```

**Should Be Module-Driven:** (see Section 5 above)

#### C. Tag Derivation (derive_tags)
**Current:** Inferred from content automatically

**Should Be Module-Driven:**
```json
{
  "tag_sources": {
    "auto_extract_keywords": true,
    "use_predefined_taxonomy": true,
    "taxonomy": [
      "regulation",
      "enforcement",
      "compliance",
      "fraud",
      "penalty"
    ]
  }
}
```

---

## 🔧 SECTION 8: DATA PROCESSOR (Class)

### Hard-Coded Logic Needing Modularization:

#### A. Scoring Keywords (calculate_relevance_score)

**Current (Hard-Coded):**
```php
$shock_keywords = array(
    'unprecedented' => 12,
    'shocking' => 12,
    'scandal' => 10,
    'fraud' => 10,
    // etc...
);
```

**Should Be Module-Driven:**
```json
{
  "scoring": {
    "shock_keywords": {
      "unprecedented": 12,
      "shocking": 12,
      "scandal": 10,
      "fraud": 10,
      "emergency": 8
    },
    "rarity_keywords": {
      "first time": 15,
      "never before": 15,
      "historic": 10
    },
    "authority_sources": {
      "Supreme Court": 15,
      "Federal Reserve": 13,
      "SEC": 12,
      "FBI": 12
    },
    "public_interest_keywords": {
      "consumer": 10,
      "taxpayer": 10,
      "privacy": 9
    }
  }
}
```

#### B. Dollar Amount Detection
**Current (Hard-Coded):**
```php
if (preg_match('/\$\s*([0-9]+(?:\.[0-9]+)?)\s*(billion|trillion)/i', $text)) {
    // Fixed thresholds
}
```

**Should Be Module-Driven:**
```json
{
  "scoring": {
    "monetary_thresholds": {
      "enabled": true,
      "pattern": "\\$\\s*([0-9]+(?:\\.[0-9]+)?)\\s*(billion|trillion)",
      "weights": {
        "trillion": 30,
        "billion_10plus": 25,
        "billion_1plus": 20
      }
    }
  }
}
```

#### C. Authority Scoring
**Current (Hard-Coded for US Government):**
```php
$high_authority_agencies = array(
    'supreme court' => 15,
    'federal reserve' => 13,
    // etc...
);
```

**Should Be Module-Driven:** (see Section 8A above)

#### D. Recency Weights
**Current (Fixed Hours):**
```php
if ($hours_old < 6) {
    $score += 15.0;
} elseif ($hours_old < 24) {
    $score += 12.0;
}
```

**Should Be Module-Driven:**
```json
{
  "scoring": {
    "recency_weights": {
      "0-6h": 15,
      "6-24h": 12,
      "24-72h": 8,
      "72-168h": 4
    }
  }
}
```

---

## 🔧 SECTION 9: DATA SIMULATOR (Class)

### Hard-Coded Templates Needing Modularization:

#### A. Source Types
**Current (Government-Specific):**
- `generate_federal_register_item()`
- `generate_court_ruling()`
- `generate_sec_filing()`
- `generate_press_release()`

**Should Be Module-Driven:**
```json
{
  "simulator": {
    "source_types": [
      {
        "type": "federal_register",
        "generator": "generate_federal_register_item",
        "enabled": true
      },
      {
        "type": "court_ruling",
        "generator": "generate_court_ruling",
        "enabled": true
      }
    ]
  }
}
```

#### B. Content Templates
**Current (Hard-Coded in Methods):**
- High shock: "$2.5B SEC Penalty..."
- Medium shock: "$450M FTC Fine..."
- Low shock: "USDA Proposes Updates..."

**Should Be Module-Driven:**
```json
{
  "simulator": {
    "templates": {
      "high_shock": [
        {
          "title": "SEC Announces Unprecedented $2.5 Billion Penalty...",
          "abstract": "The Securities and Exchange Commission...",
          "category": "enforcement"
        }
      ],
      "medium_shock": [...],
      "low_shock": [...]
    }
  }
}
```

---

## 📋 SUMMARY: UNIVERSAL vs MODULE-SPECIFIC

### ✅ UNIVERSAL (Same Across All Modules):
1. **Approval Workflow** - pending/approved/rejected statuses
2. **Relevance Scoring** - 0-100 scale
3. **Timestamps** - last_sync, created_at, updated_at
4. **Confidence Score** - ML confidence percentage
5. **Activity Logging** - timestamp, action, severity
6. **Rank Display** - numerical ordering
7. **Core Actions** - approve, reject, snooze buttons
8. **Sync Functionality** - fetch/process/store pipeline

### 🔧 MODULE-SPECIFIC (Varies by Industry):
1. **Terminology** - "Findings" vs "Properties" vs "Studies"
2. **Source Types** - GitHub vs MLS vs PubMed
3. **Categories** - Rule/Notice vs Condo/House vs Clinical/Observational
4. **Scoring Keywords** - Government terms vs Real estate terms vs Medical terms
5. **Authority Sources** - SEC/FBI vs Zillow/Redfin vs NIH/FDA
6. **Parameters** - regulatory-impact vs location-desirability vs clinical-significance
7. **Tags Taxonomy** - enforcement/fraud vs luxury/foreclosure vs oncology/cardiology
8. **Monetary Thresholds** - $1B penalties vs $500K properties vs $10M grants
9. **Content Templates** - Government filings vs Property listings vs Research papers
10. **Field Mappings** - document_number vs property_id vs study_id

### 🔀 HYBRID (Universal Display, Module Labels):
1. **Stats Cards** - Same 5 cards but labels differ
2. **Filter Bar** - Same structure but options differ
3. **Freshness** - Same concept but timeframes may differ
4. **Quick Filters** - Same pattern but thresholds may differ
5. **Badge Display** - Same UI but content differs

---

## 🎯 MODULARIZATION TODO LIST

Created in separate file: `MODULE_IMPLEMENTATION_TODO.md`

---

**Next Step:** Review this audit, then proceed with systematic implementation from the TODO list.
