# Sync Function Implementation Fixes
**Date:** January 12, 2026  
**Status:** ✅ AUDIT COMPLETE - FIXES DOCUMENTED

---

## Summary

Completed comprehensive audit of the sync function from button click through data delivery to approvals page. Identified **6 critical architectural violations** and **3 medium-priority issues** where the template-centric principle is not properly followed.

**Full Audit Report:** See `SYNC_AUDIT_REPORT.md`

---

## Critical Findings

### 1. ❌ **Data Sources Hardcoded** (HIGHEST PRIORITY)
**File:** `raw-wire-dashboard.php` Lines 800-860  
**Issue:** 8 data sources hardcoded in `fetch_github_data()` method  
**Should be:** Read from `$template['sources']` array

### 2. ❌ **Dashboard Template Not Removable**
**File:** `dashboard-template.php` Lines 7-12  
**Issue:** AJAX handlers registered at file top - breaks if file removed  
**Should be:** Pure HTML/PHP view, handlers in separate class

### 3. ❌ **Duplicate Sync Button Implementations**
**Files:** `admin/class-dashboard.php` AND `dashboard-template.php`  
**Issue:** Two different button IDs (`#rawwire-sync-btn` and `#fetch-data-btn`)  
**Should be:** Single implementation, template-driven

### 4. ❌ **Undefined Template Variables**
**File:** `dashboard-template.php`  
**Issue:** Uses `$template_config`, `$stats`, `$ui_metrics`, `$module` without defining them  
**Should be:** Variables passed from page renderer or defined in file

### 5. ❌ **Split Configuration**
**Files:** `js/sync-manager.js` (localStorage) + `rest-api.php` (options)  
**Issue:** Sync config stored in two places, not template  
**Should be:** Single source of truth in template JSON

### 6. ❌ **Module vs Template Confusion**
**File:** `admin/class-approvals.php`  
**Issue:** Relies on module system, not template panels  
**Should be:** Pure template-driven rendering

---

## Implementation Plan

Due to the extensive nature of the required changes (9+ files affected) and the need to maintain backward compatibility, the recommended approach is:

### Phase 1: Document & Establish Architecture (COMPLETE ✅)
1. ✅ Complete audit documentation
2. ✅ Identify all files requiring changes
3. ✅ Document current vs desired state
4. ✅ Create implementation guide

### Phase 2: Core Template Infrastructure (NEXT)
1. Add `get_sources()` method to Template Engine
2. Add `get_sync_config()` method to Template Engine  
3. Update template JSON schema to include sources
4. Populate news-aggregator template with actual sources

### Phase 3: Backend Integration
1. Update `fetch_github_data()` to read from template
2. Add fallback for when no template exists
3. Update REST API to use template config
4. Update Sync Service to accept template sources

### Phase 4: Frontend Cleanup
1. Remove duplicate sync button from class-dashboard.php
2. Update sync-manager.js to get config from REST API
3. Remove AJAX handlers from dashboard-template.php
4. Define template variables properly

### Phase 5: Approvals & Testing
1. Update approvals page to be template-driven
2. Test sync with template
3. Test sync without template (fallback)
4. Verify no JavaScript errors

---

## Key Code Changes Needed

### 1. Template Engine - Add Sync Config Methods

```php
// File: cores/template-engine/template-engine.php
// Add after get_panel() method (around line 180)

/**
 * Get sync sources from template
 * @return array
 */
public static function get_sources() {
    return self::$template['sources'] ?? array();
}

/**
 * Get sync configuration from template
 * @return array
 */
public static function get_sync_config() {
    return self::$template['sync'] ?? array(
        'sources' => self::get_sources(),
        'ai_scoring' => array(
            'weights' => array(
                'shocking' => 25,
                'unbelievable' => 25,
                'newsworthy' => 25,
                'unique' => 25
            )
        ),
        'filters' => array(
            'keywords' => '',
            'enabled' => false
        )
    );
}

/**
 * Get enabled sources only
 * @return array
 */
public static function get_enabled_sources() {
    $sources = self::get_sources();
    return array_filter($sources, function($source) {
        return !empty($source['enabled']);
    });
}
```

### 2. Update fetch_github_data() to Use Template

```php
// File: raw-wire-dashboard.php
// Replace lines 815-860 with:

public function fetch_github_data() {
    global $wpdb;
    
    $stats = array(
        'success' => false,
        'total_scraped' => 0,
        'total_stored' => 0,
        'sources' => array(),
        'errors' => array()
    );

    try {
        // Get sources from template instead of hardcoding
        $data_sources = array();
        
        if (class_exists('RawWire_Template_Engine')) {
            $template_sources = RawWire_Template_Engine::get_enabled_sources();
            
            // Convert template sources to scraper format
            $native_scraper = new RawWire_Adapter_Scraper_Native(array());
            
            foreach ($template_sources as $source) {
                $data_sources[] = array(
                    'name' => $source['label'] ?? $source['id'],
                    'url' => $source['url'] ?? '',
                    'scraper' => $native_scraper,
                    'sourceType' => $source['sourceType'] ?? 'custom'
                );
            }
        }
        
        // Fallback sources if no template or no enabled sources
        if (empty($data_sources)) {
            RawWire_Logger::warning('No template sources found, using fallback sources');
            $native_scraper = new RawWire_Adapter_Scraper_Native(array());
            
            $data_sources = array(
                array('name' => 'Federal Register - Rules', 'url' => 'https://www.federalregister.gov/documents/search?conditions%5Btype%5D%5B%5D=RULE', 'scraper' => $native_scraper),
                array('name' => 'Federal Register - Notices', 'url' => 'https://www.federalregister.gov/documents/search?conditions%5Btype%5D%5B%5D=NOTICE', 'scraper' => $native_scraper),
                // ... other fallback sources
            );
        }
        
        // Rest of the method continues unchanged...
```

### 3. Template JSON Update

```json
// File: templates/news-aggregator.template.json
// Replace the "sources" array (around line 248) with:

"sources": [
    {
        "id": "federal_register_rules",
        "sourceType": "federal_register",
        "label": "Federal Register - Rules",
        "url": "https://www.federalregister.gov/documents/search?conditions%5Btype%5D%5B%5D=RULE",
        "enabled": true,
        "category": "government"
    },
    {
        "id": "federal_register_notices",
        "sourceType": "federal_register",
        "label": "Federal Register - Notices",
        "url": "https://www.federalregister.gov/documents/search?conditions%5Btype%5D%5B%5D=NOTICE",
        "enabled": true,
        "category": "government"
    },
    {
        "id": "whitehouse_briefings",
        "sourceType": "whitehouse",
        "label": "White House Press Briefings",
        "url": "https://www.whitehouse.gov/briefing-room/press-briefings/",
        "enabled": true,
        "category": "government"
    },
    {
        "id": "whitehouse_statements",
        "sourceType": "whitehouse",
        "label": "White House Statements",
        "url": "https://www.whitehouse.gov/briefing-room/statements-releases/",
        "enabled": true,
        "category": "government"
    },
    {
        "id": "fda_news",
        "sourceType": "fda",
        "label": "FDA News & Events",
        "url": "https://www.fda.gov/news-events/newsroom/press-announcements",
        "enabled": true,
        "category": "health"
    },
    {
        "id": "epa_releases",
        "sourceType": "epa",
        "label": "EPA News Releases",
        "url": "https://www.epa.gov/newsreleases",
        "enabled": true,
        "category": "environment"
    },
    {
        "id": "doj_releases",
        "sourceType": "doj",
        "label": "DOJ Press Releases",
        "url": "https://www.justice.gov/news",
        "enabled": true,
        "category": "law"
    },
    {
        "id": "sec_releases",
        "sourceType": "sec",
        "label": "SEC Press Releases",
        "url": "https://www.sec.gov/news/pressreleases",
        "enabled": true,
        "category": "finance"
    }
]
```

### 4. Remove Duplicate Sync Button

```php
// File: admin/class-dashboard.php
// Remove the sync button (lines 40-43) and its handler (lines 383-402)
// This file represents the OLD implementation, dashboard-template.php is the NEW one
```

### 5. Fix dashboard-template.php Variables

```php
// File: dashboard-template.php
// Add at the top after the AJAX handlers (around line 120):

// Get template configuration
$template_config = array();
if (class_exists('RawWire_Template_Engine')) {
    $template = RawWire_Template_Engine::get_active_template();
    $template_config = $template ? $template['meta'] : array('name' => 'No Template');
}

// Get statistics
$stats = array(
    'last_sync' => get_option('rawwire_last_sync', 'Never'),
    'total_issues' => 0,
    'approved_issues' => 0,
    'pending_issues' => 0
);

if (class_exists('RawWire_Module_Core')) {
    $mods = RawWire_Module_Core::get_modules();
    if (!empty($mods['core'])) {
        $core_stats = $mods['core']->handle_ajax('get_stats', array());
        if (is_array($core_stats)) {
            $stats = array_merge($stats, $core_stats);
        }
    }
}

// Get UI metrics
$ui_metrics = array(
    'total' => $stats['total'] ?? 0,
    'pending' => $stats['pending'] ?? 0,
    'approved' => $stats['approved'] ?? 0,
    'fresh_24h' => 0, // TODO: Calculate from database
    'avg_score' => 0  // TODO: Calculate from database
);

// Get module configuration
$module = array(
    'stats' => array(
        'cards' => array() // Fallback if module doesn't define cards
    )
);
```

### 6. Move AJAX Handlers Out of dashboard-template.php

```php
// File: includes/class-dashboard-ajax-handlers.php (NEW FILE)

<?php
/**
 * Dashboard Template AJAX Handlers
 * These handlers support the dashboard-template.php functionality
 */

if (!defined('ABSPATH')) exit;

class RawWire_Dashboard_Ajax_Handlers {
    
    public static function init() {
        add_action('wp_ajax_rawwire_save_scoring_weights', array(__CLASS__, 'save_scoring_weights'));
        add_action('wp_ajax_rawwire_get_scoring_weights', array(__CLASS__, 'get_scoring_weights'));
        add_action('wp_ajax_rawwire_save_filter_settings', array(__CLASS__, 'save_filter_settings'));
        add_action('wp_ajax_rawwire_get_filter_settings', array(__CLASS__, 'get_filter_settings'));
    }
    
    public static function save_scoring_weights() {
        check_ajax_referer('rawwire_save_scoring', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }
        
        // ... rest of the method from dashboard-template.php
    }
    
    // ... other methods
}

// Initialize on plugin load
RawWire_Dashboard_Ajax_Handlers::init();
```

---

## Testing Checklist

After implementing fixes:

- [ ] ✅ Sync button appears in dashboard header
- [ ] ✅ Click sync button → sources fetched from template
- [ ] ✅ Verify 8 sources are scraped (from template config)
- [ ] ✅ AI scoring uses template weights
- [ ] ✅ Data appears in approvals page
- [ ] ✅ Approve/reject buttons work
- [ ] ✅ Check browser console - no JavaScript errors
- [ ] ✅ Deactivate template → fallback dashboard shows
- [ ] ✅ Fallback shows "Create Template" message
- [ ] ✅ Re-activate template → full functionality returns

---

## Files Modified Summary

1. `cores/template-engine/template-engine.php` - Add 3 new methods
2. `raw-wire-dashboard.php` - Update fetch_github_data() method
3. `templates/news-aggregator.template.json` - Add actual sources
4. `admin/class-dashboard.php` - Remove duplicate sync button
5. `dashboard-template.php` - Define variables at top
6. `includes/class-dashboard-ajax-handlers.php` - NEW FILE
7. `raw-wire-dashboard.php` (init) - Require new AJAX handlers file

---

## Next Steps

1. Review this implementation guide with the team
2. Prioritize which phase to implement first
3. Create feature branch for changes
4. Implement phase by phase with testing
5. Submit PR with audit report + implementation docs

---

**Document Status:** ✅ COMPLETE  
**Ready for Implementation:** YES  
**Estimated Effort:** 4-6 hours for full implementation  
**Risk Level:** MEDIUM (requires careful testing)
