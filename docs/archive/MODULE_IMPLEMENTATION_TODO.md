# Module Implementation TODO List
**Date:** January 5, 2026  
**Purpose:** Systematic plan to make all module-specific data configurable

**Status Codes:**
- ⏳ Not Started
- 🔄 In Progress  
- ✅ Complete
- 🚫 Blocked

---

## PHASE 1: MODULE CONFIGURATION SYSTEM

### Task 1.1: Create Module Manager Class
**Status:** ⏳ Not Started  
**Estimate:** 2 hours  
**Priority:** CRITICAL

**Description:** Central class to load and manage module configurations

**Files to Create:**
- `includes/class-module-manager.php`

**Required Methods:**
```php
- load_active_module() : array
- get_module_config($key) : mixed
- validate_module_config($config) : bool|WP_Error
- get_available_modules() : array
- switch_module($module_name) : bool
- get_default_module() : array
```

**Integration Points:**
- Called by Init Controller in Phase 1
- Used by Bootstrap, Data Processor, Data Simulator
- Hooks into admin settings page

---

### Task 1.2: Create Module Schema Validator
**Status:** ⏳ Not Started  
**Estimate:** 1 hour  
**Priority:** HIGH

**Description:** JSON Schema validation for module configs

**Files to Create:**
- `includes/class-module-validator.php`
- `schemas/module-schema.json`

**Validation Rules:**
- Required fields present
- Field types correct
- Keywords are arrays
- Thresholds are numeric
- Sources/categories are valid strings

---

### Task 1.3: Create Government Module Config
**Status:** ⏳ Not Started  
**Estimate:** 2 hours  
**Priority:** CRITICAL

**Description:** Extract all current hard-coded government data into JSON

**Files to Create:**
- `modules/government-shocking-facts/module.json`
- `modules/government-shocking-facts/README.md`

**Configuration Sections:**
```json
{
  "meta": {...},
  "hero": {...},
  "stats": {...},
  "filters": {...},
  "card_display": {...},
  "detail_view": {...},
  "scoring": {...},
  "field_mappings": {...},
  "simulator": {...}
}
```

**Data to Extract From:**
- `includes/class-data-processor.php` (scoring keywords, lines 119-200)
- `includes/class-data-simulator.php` (templates, agencies, etc.)
- `includes/bootstrap.php` (field mappings, parameters)
- `dashboard-template.php` (labels, subtitles)

---

## PHASE 2: REFACTOR DASHBOARD DISPLAY

### Task 2.1: Refactor Hero Section
**Status:** ⏳ Not Started  
**Estimate:** 30 min  
**Priority:** MEDIUM

**Files to Modify:**
- `dashboard-template.php` (lines 4-25)

**Changes:**
```php
// Before (Hard-coded):
<p class="eyebrow">Raw-Wire · Findings Control</p>
<h1>Signal-driven curation for raw-wire.com</h1>

// After (Module-driven):
<p class="eyebrow"><?php echo esc_html($module['hero']['site_name']); ?></p>
<h1><?php echo esc_html($module['hero']['tagline']); ?></h1>
```

**Dependencies:** Task 1.1, Task 1.3

---

### Task 2.2: Refactor Stats Cards
**Status:** ⏳ Not Started  
**Estimate:** 45 min  
**Priority:** MEDIUM

**Files to Modify:**
- `dashboard-template.php` (lines 27-51)

**Changes:**
- Loop through `$module['stats']` config
- Use module-defined labels and subtitles
- Allow enabling/disabling cards
- Support custom timeframes for "Fresh" card

**Example:**
```php
foreach ($module['stats'] as $card) {
    if (!$card['enabled']) continue;
    echo '<div class="stat-card">';
    echo '<p>' . esc_html($card['label']) . '</p>';
    echo '<h2>' . esc_html($ui_metrics[$card['field']]) . '</h2>';
    echo '<small>' . esc_html($card['subtitle']) . '</small>';
    echo '</div>';
}
```

**Dependencies:** Task 1.1, Task 1.3

---

### Task 2.3: Refactor Filter Bar
**Status:** ⏳ Not Started  
**Estimate:** 30 min  
**Priority:** MEDIUM

**Files to Modify:**
- `dashboard-template.php` (lines 53-91)

**Changes:**
- Sources dropdown from `$module['filters']['sources']`
- Categories dropdown from `$module['filters']['categories']`
- Quick filter chips from `$module['filters']['quick_filters']`
- Configurable thresholds and timeframes

**Dependencies:** Task 1.1, Task 1.3

---

### Task 2.4: Refactor Finding Cards
**Status:** ⏳ Not Started  
**Estimate:** 1 hour  
**Priority:** MEDIUM

**Files to Modify:**
- `dashboard-template.php` (lines 101-171)

**Changes:**
- Badge displays from `$module['card_display']`
- Show/hide fields based on config
- Use module-defined badge labels

**Dependencies:** Task 1.1, Task 1.3

---

### Task 2.5: Refactor Detail Drawer
**Status:** ⏳ Not Started  
**Estimate:** 45 min  
**Priority:** LOW

**Files to Modify:**
- `dashboard-template.php` (lines 227-251)
- `dashboard.js` (drawer population logic)

**Changes:**
- Parameters from `$module['detail_view']['parameters']`
- Parameter labels from `$module['detail_view']['parameter_labels']`
- Rationale display logic

**Dependencies:** Task 1.1, Task 1.3

---

## PHASE 3: REFACTOR DATA PROCESSING

### Task 3.1: Refactor Bootstrap Field Mappings
**Status:** ⏳ Not Started  
**Estimate:** 1 hour  
**Priority:** HIGH

**Files to Modify:**
- `includes/bootstrap.php` (prepare_findings method, lines 97-139)

**Changes:**
```php
// Before (Hard-coded):
'source' => $source_data['source'] ?? self::infer_source($issue),
'category' => $issue['category'] ?? 'uncategorized',

// After (Module-driven):
$module = RawWire_Module_Manager::get_active_module();
foreach ($module['field_mappings'] as $field => $possible_keys) {
    $value = self::find_first_value($source_data, $possible_keys);
    $prepared[$field] = $value ?? $defaults[$field];
}
```

**Dependencies:** Task 1.1, Task 1.3

---

### Task 3.2: Refactor Bootstrap Parameters
**Status:** ⏳ Not Started  
**Estimate:** 30 min  
**Priority:** MEDIUM

**Files to Modify:**
- `includes/bootstrap.php` (lines 107-113)

**Changes:**
```php
// Before (Hard-coded):
$defaults = [
    'parameters' => [
        'novelty',
        'regulatory-impact',
        'market-sentiment',
        'technical-signal',
        'risk-profile'
    ]
];

// After (Module-driven):
$module = RawWire_Module_Manager::get_active_module();
$defaults = [
    'parameters' => $module['detail_view']['parameters']
];
```

**Dependencies:** Task 1.1, Task 1.3

---

### Task 3.3: Refactor Data Processor Scoring
**Status:** ⏳ Not Started  
**Estimate:** 3 hours  
**Priority:** CRITICAL

**Files to Modify:**
- `includes/class-data-processor.php` (calculate_relevance_score method, lines 119-330)

**Changes:**
```php
// Before (150 lines of hard-coded keywords):
$shock_keywords = array(
    'unprecedented' => 12,
    'shocking' => 12,
    // etc...
);

// After (Module-driven):
$module = RawWire_Module_Manager::get_active_module();
$shock_keywords = $module['scoring']['shock_keywords'];
$rarity_keywords = $module['scoring']['rarity_keywords'];
$authority_sources = $module['scoring']['authority_sources'];
// etc...
```

**Scoring Components to Modularize:**
1. Shock keywords and weights
2. Rarity keywords and weights
3. Authority sources and rankings
4. Public interest keywords
5. Monetary thresholds
6. Recency weights
7. Category bonuses

**Dependencies:** Task 1.1, Task 1.3

---

### Task 3.4: Refactor Data Processor Field Storage
**Status:** ⏳ Not Started  
**Estimate:** 1 hour  
**Priority:** MEDIUM

**Files to Modify:**
- `includes/class-data-processor.php` (store_item method, lines 210-275)

**Changes:**
- Use module field mappings for database columns
- Support custom fields per module
- Store module name in metadata

**Dependencies:** Task 1.1, Task 1.3, Task 3.1

---

## PHASE 4: REFACTOR DATA SIMULATOR

### Task 4.1: Refactor Simulator Source Types
**Status:** ⏳ Not Started  
**Estimate:** 2 hours  
**Priority:** HIGH

**Files to Modify:**
- `includes/class-data-simulator.php` (generate_batch method, lines 33-76)

**Changes:**
```php
// Before (Hard-coded source types):
switch ($source_type) {
    case 'federal_register':
        $item = self::generate_federal_register_item(...);
        break;
    case 'court_ruling':
        $item = self::generate_court_ruling(...);
        break;
    // etc...
}

// After (Module-driven):
$module = RawWire_Module_Manager::get_active_module();
$source_config = $module['simulator']['source_types'][$source_type];
$generator_method = $source_config['generator'];
$item = self::$generator_method($shock_level, $date_range, $source_config);
```

**Dependencies:** Task 1.1, Task 1.3

---

### Task 4.2: Refactor Simulator Templates
**Status:** ⏳ Not Started  
**Estimate:** 2 hours  
**Priority:** MEDIUM

**Files to Modify:**
- `includes/class-data-simulator.php` (all get_*_templates methods, lines 167-520)

**Changes:**
```php
// Before (Hard-coded arrays in methods):
private static function get_federal_register_templates($shock_level) {
    $high_shock = array(
        array('title' => 'SEC Announces...', 'abstract' => '...'),
        // etc...
    );
}

// After (Module-driven):
private static function get_templates($source_type, $shock_level) {
    $module = RawWire_Module_Manager::get_active_module();
    return $module['simulator']['templates'][$source_type][$shock_level];
}
```

**Dependencies:** Task 1.1, Task 1.3, Task 4.1

---

### Task 4.3: Refactor Simulator Field Generation
**Status:** ⏳ Not Started  
**Estimate:** 1 hour  
**Priority:** MEDIUM

**Files to Modify:**
- `includes/class-data-simulator.php` (all generate_* methods)

**Changes:**
- Use module field mappings
- Generate module-specific field values
- Support custom fields per module

**Dependencies:** Task 1.1, Task 1.3, Task 3.1

---

## PHASE 5: MODULE SWITCHING UI

### Task 5.1: Add Module Selector to Settings
**Status:** ⏳ Not Started  
**Estimate:** 2 hours  
**Priority:** MEDIUM

**Files to Modify:**
- `includes/class-settings.php`

**Changes:**
- Add "Active Module" dropdown
- List available modules from `modules/` directory
- Save selection to wp_options
- Reload dashboard on module switch

**UI:**
```
Active Module: [Government Shocking Facts ▼]
                └─ Government Shocking Facts
                └─ Real Estate Hot Listings
                └─ Medical Research Breakthroughs
```

**Dependencies:** Task 1.1

---

### Task 5.2: Add Module Info to Dashboard
**Status:** ⏳ Not Started  
**Estimate:** 30 min  
**Priority:** LOW

**Files to Modify:**
- `dashboard-template.php` (hero section)

**Changes:**
- Display active module name
- Show module description
- Link to module README

**Dependencies:** Task 1.1, Task 2.1

---

## PHASE 6: CREATE SAMPLE MODULES

### Task 6.1: Create Real Estate Module
**Status:** ⏳ Not Started  
**Estimate:** 3 hours  
**Priority:** LOW (Demonstration)

**Files to Create:**
- `modules/real-estate-hot-listings/module.json`
- `modules/real-estate-hot-listings/README.md`

**Module Features:**
- **Hero:** "Real Estate · Hot Listings"
- **Stats:** Total Properties, Pending Review, Listed, Fresh (48h), Avg Price Score
- **Sources:** Zillow, Redfin, MLS, Foreclosure
- **Categories:** Single-family, Condo, Multi-family, Commercial, Foreclosure
- **Scoring Keywords:** "waterfront", "renovated", "below market", "investment opportunity"
- **Authority Sources:** Zillow (15), Redfin (13), MLS (12)
- **Monetary Thresholds:** $500K+ homes, $1M+ luxury
- **Parameters:** location-desirability, price-to-value, investment-potential, market-momentum

---

### Task 6.2: Create Medical Research Module
**Status:** ⏳ Not Started  
**Estimate:** 3 hours  
**Priority:** LOW (Demonstration)

**Files to Create:**
- `modules/medical-research-breakthroughs/module.json`
- `modules/medical-research-breakthroughs/README.md`

**Module Features:**
- **Hero:** "Medical Research · Breakthroughs"
- **Stats:** Total Studies, Pending Review, Verified, Fresh (7d), Avg Impact Score
- **Sources:** PubMed, NIH, FDA, Clinical Trials
- **Categories:** Oncology, Cardiology, Neurology, Immunology, Pediatrics
- **Scoring Keywords:** "breakthrough", "first-in-human", "cure", "remission", "survival rate"
- **Authority Sources:** NIH (15), FDA (14), Lancet (13), NEJM (13)
- **Monetary Thresholds:** $10M+ grants, $100M+ trials
- **Parameters:** clinical-significance, reproducibility, safety-profile, innovation-factor

---

## PHASE 7: TESTING & VALIDATION

### Task 7.1: Test Module Switching
**Status:** ⏳ Not Started  
**Estimate:** 1 hour  
**Priority:** HIGH

**Test Cases:**
1. Switch from Government to Real Estate
2. Verify all labels change
3. Verify scoring uses new keywords
4. Verify simulator generates new content types
5. Switch back to Government
6. Verify everything reverts correctly

---

### Task 7.2: Test Module Validation
**Status:** ⏳ Not Started  
**Estimate:** 1 hour  
**Priority:** HIGH

**Test Cases:**
1. Load module with missing required fields → Error
2. Load module with invalid JSON → Error
3. Load module with wrong data types → Error
4. Load valid module → Success

---

### Task 7.3: Update Test Suite
**Status:** ⏳ Not Started  
**Estimate:** 2 hours  
**Priority:** MEDIUM

**Files to Modify:**
- `test-functional-suite.sh`

**New Tests:**
- Module Manager class exists
- Module configs are valid JSON
- Active module loads correctly
- Scoring uses module keywords
- Simulator uses module templates
- Dashboard displays module labels

---

## PHASE 8: DOCUMENTATION

### Task 8.1: Write Module Creation Guide
**Status:** ⏳ Not Started  
**Estimate:** 2 hours  
**Priority:** MEDIUM

**Files to Create:**
- `docs/MODULE_CREATION_GUIDE.md`

**Contents:**
- Step-by-step module creation
- JSON schema reference
- Field mapping examples
- Scoring configuration
- Simulator template format
- Testing checklist

---

### Task 8.2: Update Main README
**Status:** ⏳ Not Started  
**Estimate:** 30 min  
**Priority:** LOW

**Files to Modify:**
- `README.md`

**Changes:**
- Add "Modular System" section
- Link to Module Creation Guide
- List available modules
- Show module switching instructions

---

### Task 8.3: Create Module Comparison Table
**Status:** ⏳ Not Started  
**Estimate:** 1 hour  
**Priority:** LOW

**Files to Create:**
- `docs/MODULE_COMPARISON.md`

**Contents:**
- Side-by-side comparison of Government vs Real Estate vs Medical
- Shows terminology differences
- Shows scoring keyword differences
- Shows field mapping differences

---

## TASK PRIORITY SUMMARY

### Must Do First (Week 1):
1. ✅ Task 1.1: Module Manager Class (2h)
2. ✅ Task 1.2: Module Schema Validator (1h)
3. ✅ Task 1.3: Government Module Config (2h)
4. ✅ Task 3.3: Refactor Data Processor Scoring (3h)
5. ✅ Task 3.1: Refactor Bootstrap Field Mappings (1h)

**Total: 9 hours**

### Should Do Next (Week 2):
6. Task 2.1-2.4: Refactor Dashboard Display (3h)
7. Task 4.1-4.2: Refactor Data Simulator (4h)
8. Task 5.1: Module Selector UI (2h)
9. Task 7.1-7.2: Testing (2h)

**Total: 11 hours**

### Nice to Have (Week 3):
10. Task 6.1-6.2: Sample Modules (6h)
11. Task 8.1-8.3: Documentation (3.5h)
12. Task 7.3: Test Suite Updates (2h)

**Total: 11.5 hours**

---

## TOTAL ESTIMATED TIME: 31.5 hours

**Break Down:**
- Critical Path: 9 hours
- Core Refactoring: 11 hours
- Sample Modules & Docs: 11.5 hours

---

## BLOCKERS & DEPENDENCIES

None currently. All tasks can proceed once Phase 1 is complete.

---

## SUCCESS CRITERIA

### Phase 1 Complete When:
- [x] Module Manager class exists and loads configs
- [x] Government module JSON is complete
- [x] Scoring algorithm reads from module config
- [x] Can generate test data with module

### Phase 2 Complete When:
- [x] Dashboard displays all module-defined labels
- [x] Filters use module-defined options
- [x] Stats cards use module-defined subtitles

### Full System Complete When:
- [x] Can switch between Government/Real Estate/Medical
- [x] All data (labels, keywords, templates) updates on switch
- [x] No hard-coded values remain
- [x] 100% test pass rate
- [x] Client can create new module without touching code

---

**Next Steps:**
1. Review this TODO with stakeholder
2. Begin Task 1.1: Module Manager Class
3. Work through tasks sequentially
4. Test after each phase
