# Dashboard Cleanup Summary

## Overview
This document summarizes the cleanup and consolidation work done on the raw-wire-dashboard WordPress plugin to streamline the workflow and remove deprecated code.

## Changes Made

### 1. Consolidated Sync to Workflow Panel
**Files Changed:**
- `templates/news-aggregator.template.json`
- `js/template-system.js`
- `dashboard.js`

**What Changed:**
- Removed "Sync All Sources" button from `sources` panel (was using deprecated `/fetch-data` endpoint)
- The `sources` panel now only displays configured sources (changed type from `control` to `data`)
- `triggerSync()` in template-system.js now redirects to `startWorkflow()` instead of calling `/fetch-data`
- Legacy sync handler in dashboard.js now redirects to `/workflow/start` endpoint

### 2. Updated Template DataSources
**Files Changed:**
- `templates/news-aggregator.template.json`

**What Changed:**
- `queue` panel: `db:queue` → `db:candidates` (renamed to "Candidates Queue")
- `recent_findings` panel: `db:findings` → `db:candidates` (renamed to "Recent Candidates")
- Both panels now have helpful descriptions

### 3. Migrated rawwire_findings References
**Files Changed:**
- `cores/template-engine/workflow-handlers.php`
- `raw-wire-dashboard.php`
- `cores/ai-discovery/ai-discovery.php`
- `scripts/cleanup_test_data.php`

**What Changed:**
- `ajax_workflow_update()`: Uses workflow tables (candidates, approvals, etc.) with `source_table` parameter
- `ajax_bulk_action()`: Uses specified workflow table instead of hardcoded findings
- `ajax_item_detail()`: Searches across all workflow tables
- `run_scraper()`: Writes to candidates table instead of findings
- `process_scoring_batch()`: Writes to approvals table instead of findings
- `process_discovered_facts()`: Writes to approvals table instead of findings
- `get_stats()`: Queries approvals table instead of findings
- Cleanup script updated to clean all workflow tables

### 4. Re-enabled Permission Checks
**Files Changed:**
- `rest-api.php`

**What Changed:**
- `check_permission()` now properly returns `current_user_can('manage_options')` instead of always `true`

### 5. Fixed Action Log Storage
**Files Changed:**
- `rest-api.php`

**What Changed:**
- `log_action()` now:
  1. First tries to use proper `rawwire_logs` database table
  2. Falls back to transient with 24-hour expiration (instead of wp_options)
  3. Reduced max entries from 1000 to 500

## Database Tables

### Current Workflow Tables (6-stage workflow)
| Table | Stage | Purpose |
|-------|-------|---------|
| `wp_rawwire_candidates` | 1 | Raw items from scraper |
| `wp_rawwire_approvals` | 2 | Items awaiting human review |
| `wp_rawwire_content` | 3 | Items in AI generation queue |
| `wp_rawwire_releases` | 4 | Generated content ready for publish |
| `wp_rawwire_published` | 5 | Published items |
| `wp_rawwire_archives` | 0 | Rejected/archived items |

### Legacy Tables (Deprecated)
| Table | Status |
|-------|--------|
| `wp_rawwire_findings` | Deprecated - kept for clearing only |
| `wp_rawwire_queue` | Deprecated - kept for clearing only |

## Workflow Flow

```
Source → /workflow/start → Native Scraper → candidates table
                                ↓
                         AI Scoring (optional)
                                ↓
                         approvals table → Human Review
                                ↓
                         content table → AI Generation
                                ↓
                         releases table → Ready to Publish
                                ↓
                         published table → Done
```

## Testing Notes

1. **Start Workflow Button**: Uses `/workflow/start` endpoint, writes to candidates
2. **Clear All Tables Button**: Clears all 6 workflow tables + 2 legacy tables
3. **Permission Check**: REST API endpoints require admin (`manage_options`) capability
4. **Settings**: Saved with `rawwire_` prefix using `update_option()`

## Known Issues / Future Work

1. Source preset dropdown should be tested in browser to verify sources load correctly
2. Legacy tables can be removed in a future version (after migration period)
3. Consider adding a migration wizard for users with data in legacy tables

## Date
Generated: 2025-01-14
