# RawWire Sync Flow Map
*Comprehensive data flow diagram for AI context and debugging*

## System Architecture Overview

```
USER INTERFACE → AJAX/REST → SERVICES → DATABASE → HOOKS → HANDLERS → UI UPDATE
```

---

## 1. USER TRIGGERS SYNC

### Entry Points
- **Button**: `#rawwire-sync-btn` in [admin/class-dashboard.php](admin/class-dashboard.php#L40)
- **Click Handler (Primary)**: Inline script in [admin/class-dashboard.php](admin/class-dashboard.php#L499)
- **Click Handler (Secondary)**: dashboard.js line 11 (REST API - not used currently)

### Handler Flow
```
User clicks "Sync Sources"
  ↓
Inline jQuery handler fires (.on('click'))
  ↓
window.RawWireProgress.start() - Initialize progress tracking
  ↓
AJAX POST to ajaxurl
  action: 'rawwire_sync'
  nonce: rawwire_ajax.nonce
  ↓
WordPress routes to: includes/class-admin.php::ajax_sync()
```

---

## 2. AJAX SYNC HANDLER (ASYNC)

### File: `includes/class-admin.php`
**Method**: `ajax_sync()` (Line 64)

**Process (Non-Blocking)**:
```
ajax_sync()
  ↓
Check: get_transient('rawwire_scraping_in_progress')
  ↓
If already in progress:
  └─ Return: {in_progress: true}
  ↓
Set transient: 'rawwire_scraping_in_progress' = true (TTL: 30 min)
  ↓
Set transient: 'rawwire_workflow_status'
  - stage: 'scraping'
  - message: 'Starting scrape...'
  - TTL: 30 minutes
  ↓
Schedule: wp_schedule_single_event(time(), 'rawwire_run_background_scrape')
  ↓
Spawn cron: spawn_cron() - Force immediate execution
  ↓
Return IMMEDIATELY: wp_send_json_success({started: true})
```

**Hook Registration**: Line 23
- `wp_ajax_rawwire_sync` → `ajax_sync()`

**Background Hook**: Line 40
- `rawwire_run_background_scrape` → `run_background_scrape()`

**Key Features**:
- ⚡ Returns immediately (< 1 second)
- 🔒 Prevents duplicate scraping with transient lock
- ⏱️ 30-minute execution timeout
- 💾 512MB memory limit
- 📊 Progress tracked via transients

---

## 2B. BACKGROUND SCRAPE HANDLER

### File: `includes/class-admin.php`
**Method**: `run_background_scrape()` (Line ~112)

**Process (Long-Running)**:
```
run_background_scrape() - RUNS IN WP CRON
  ↓
Set PHP limits:
  - set_time_limit(1800) - 30 minutes
  - memory_limit = 512M
  ↓
Load: services/class-scraper-service.php
  ↓
Instantiate: RawWire_Scraper_Service
  ↓
Call: $scraper->scrape_all() - BLOCKS HERE (10-20 minutes)
  ↓
Update: rawwire_last_sync option
  ↓
Delete: 'rawwire_scraping_in_progress' transient
  ↓
Log completion
```

**Execution Context**: WordPress Cron (pseudo-cron, HTTP-triggered)
**Timeout Protection**: PHP time limit extended to 30 minutes
**Memory Protection**: Increased to 512MB
**Error Handling**: Sets workflow_status to 'error' on failure

---

## 3. SCRAPER SERVICE (STAGE 1: SCRAPING)

### File: `services/class-scraper-service.php`
**Class**: `RawWire_Scraper_Service`

### Method: `scrape_all()` (Line 138)

**Process Flow**:
```
scrape_all($config)
  ↓
Set transient: 'rawwire_workflow_status'
  - stage: 'scraping'
  - message: 'Scraping government sources...'
  - TTL: 300 seconds
  ↓
Get sources from DB: wp_rawwire_sources
  ↓
FOR EACH source:
  ↓
  scrape_source($source) → Returns items array
  ↓
  store_to_candidates($items, $source_name)
    ↓
    CHECK DUPLICATES:
      - Query: wp_rawwire_candidates WHERE title + source
      - Query: wp_rawwire_archives WHERE title + source
      - Skip if exists in either
    ↓
    INSERT into wp_rawwire_candidates
  ↓
NEXT source
  ↓
Fire hook: do_action('rawwire_scrape_complete', $results)
  ↓
Return: $results array
```

**Database Write**: `wp_rawwire_candidates`
**Hook Fired**: `rawwire_scrape_complete`
**Transient Set**: `rawwire_workflow_status`

---

## 4. SCORING HANDLER (STAGE 2: AI SCORING)

### File: `services/class-scoring-handler.php`
**Class**: `RawWire_Scoring_Handler`

### Instantiation
**Location**: raw-wire-dashboard.php line 124 ⚠️ **CRITICAL**
```php
new RawWire_Scoring_Handler();
```
⚠️ **BUG FOUND**: This was missing - scoring never ran!

### Hook Registration (Constructor, Line 23)
```php
add_action('rawwire_scrape_complete', array($this, 'process_candidates'), 10, 1);
```

### Method: `process_candidates()` (Line 36)

**Process Flow**:
```
process_candidates($scrape_results) - TRIGGERED BY HOOK
  ↓
Set transient: 'rawwire_workflow_status'
  - stage: 'scoring'
  - message: 'AI scoring candidates...'
  - TTL: 300 seconds
  ↓
Get unique sources: SELECT DISTINCT source FROM wp_rawwire_candidates
  ↓
FOR EACH source:
  ↓
  Get candidates: SELECT * FROM wp_rawwire_candidates WHERE source = $source
  ↓
  score_candidates($candidates)
    ↓
    Call: $analyzer->analyze_batch($candidates, $batch_size)
    ↓
    Returns: Sorted array with 'score' and 'ai_reason' fields
  ↓
  move_to_archives($scored_items, $source)
    ↓
    FOR EACH scored item:
      ↓
      result = (index < 2) ? 'Accepted' : 'Rejected'
      ↓
      Check duplicates in wp_rawwire_archives
      ↓
      INSERT INTO wp_rawwire_archives:
        - All candidate fields
        - score (float)
        - ai_reason (text)
        - result ('Accepted' or 'Rejected')
        - scored_at (timestamp)
  ↓
  DELETE FROM wp_rawwire_candidates WHERE source = $source
  ↓
NEXT source
  ↓
Set transient: 'rawwire_workflow_status'
  - stage: 'complete'
  - message: 'Complete! X items accepted.'
  - TTL: 60 seconds
```

**Database Reads**: `wp_rawwire_candidates`
**Database Writes**: `wp_rawwire_archives`
**Database Deletes**: `wp_rawwire_candidates` (clears after processing)

---

## 5. PROGRESS TRACKING (CONCURRENT)

### File: `admin/class-dashboard.php` (Inlined)
**Location**: Inline `<script>` in render() method, lines ~497-630
**Reason**: Inlined to avoid race condition with dashboard.js loading in footer

### Functions (All Inlined)
- `startWorkflowProgress()` - Initialize progress tracking
- `pollWorkflowStatus()` - AJAX polling every 2 seconds
- `updateProgressBar(stage, message)` - Update visual stages
- `clearWorkflowProgress()` - Cleanup and hide

### Initialization
**Trigger**: `startWorkflowProgress()` called when sync button clicked

**Storage**: `localStorage.setItem('rawwire_workflow_progress', JSON.stringify({...}))`
- Key: `'rawwire_workflow_progress'`
- Structure: `{active, stage, message, startTime}`
- Persistence: Survives page navigation, browser refresh
- TTL: Auto-cleared if > 5 minutes old

### Polling Flow
```
Click "Sync Sources"
  ↓
startWorkflowProgress()
  ↓
  Save to localStorage: {active: true, stage: 'scraping', ...}
  ↓
  Show container: $('#rawwire-progress-container').show()
  ↓
  updateProgressBar('scraping', 'Scraping sources...')
  ↓
  Start polling: setInterval(pollWorkflowStatus, 2000)
  ↓
Every 2 seconds:
  pollWorkflowStatus()
    ↓
    AJAX POST: ajaxurl
      action: 'rawwire_get_workflow_status'
      nonce: rawwire_ajax.nonce
    ↓
    includes/class-admin.php::ajax_get_workflow_status()
      ↓
      get_transient('rawwire_workflow_status')
      ↓
      Return: {active, stage, message, startTime}
    ↓
    updateProgressBar(stage, message)
      ↓
      Update CSS classes: .progress-stage
      ↓
      Stages: 'scraping' → 'scoring' → 'complete'
      ↓
      Update text: .stage-status
  ↓
If stage === 'complete':
  ↓
  Show completion (2 seconds)
  ↓
  clearWorkflowProgress()
  ↓
  location.reload() - Refresh to show updated stats
```

### Visual States
**HTML Container**: `#rawwire-progress-container` (Line 64 in class-dashboard.php)
**Stage Elements**: `.progress-stage[data-stage="scraping|scoring|complete"]`

**CSS Classes**:
- `.progress-stage.active` - Currently processing (blue, pulsing)
- `.progress-stage.completed` - Finished (green, checkmark)
- Default - Waiting (gray)

**Stage Icons**:
- Scraping: `dashicons-download`
- AI Scoring: `dashicons-star-filled`
- Complete: `dashicons-yes`

### Page Load Recovery
```
On document.ready:
  ↓
Read: localStorage.getItem('rawwire_workflow_progress')
  ↓
If exists AND active:
  ↓
  Check age: (now - startTime) / 1000 / 60
  ↓
  If <= 5 minutes:
    ↓
    Resume: Show progress bar
    ↓
    Restore state: updateProgressBar(stage, message)
    ↓
    Resume polling: setInterval(pollWorkflowStatus, 2000)
  Else (stale):
    ↓
    clearWorkflowProgress() - Clean up
```

**Data Dependencies**:
1. **Transient**: `rawwire_workflow_status` (set by scraper/scorer)
2. **AJAX Endpoint**: `wp_ajax_rawwire_get_workflow_status`
3. **localStorage**: Browser-side persistence
4. **DOM Element**: `#rawwire-progress-container` must exist
5. **CSS**: `.rawwire-progress-bar` styles must be loaded

**AJAX Endpoint**: `wp_ajax_rawwire_get_workflow_status`
**Transient Read**: `rawwire_workflow_status`
**UI Update**: `.rawwire-progress-bar`, `.progress-stage`
**Refresh Trigger**: `location.reload()` after completion

---

## 6. STATISTICS CARDS

### File: `admin/class-dashboard.php`
**Method**: `get_stats()` (Line 595)

### Queries
```sql
-- Candidates awaiting scoring
SELECT COUNT(*) FROM wp_rawwire_candidates

-- Accepted items (top 2 per source)
SELECT COUNT(*) FROM wp_rawwire_archives WHERE result = 'Accepted'

-- Rejected items
SELECT COUNT(*) FROM wp_rawwire_archives WHERE result = 'Rejected'

-- Pending approval
SELECT COUNT(*) FROM wp_rawwire_archives 
WHERE result = 'Accepted' AND status = 'pending'

-- Approved items
SELECT COUNT(*) FROM wp_rawwire_archives WHERE status = 'approved'

-- Final content
SELECT COUNT(*) FROM wp_rawwire_content

-- Queue
SELECT COUNT(*) FROM wp_rawwire_queue
```

**Rendered**: Lines 84-122 (stat cards HTML)

### Module API
**File**: `modules/core/module.php`
**Case**: `get_stats` (Line 81)
- Same queries as dashboard

---

## 7. APPROVALS PAGE (STAGE 3: HUMAN REVIEW)

### File: `modules/core/module.php`
**Method**: `get_approvals` (Line 548)

### Query
```sql
SELECT * FROM wp_rawwire_archives 
WHERE result = 'Accepted' AND status = 'pending' 
ORDER BY scored_at DESC 
LIMIT 20
```

**Database Read**: `wp_rawwire_archives` (WHERE result='Accepted' AND status='pending')

### UI Display
**Table Columns**:
- Title
- Source  
- Created Date
- Status (always "Pending")
- Actions: **"Approve & Generate"** button, "Reject" button

### Approve & Generate Button
**File**: `modules/core/module.php` (Line 550-580)
**Class**: `.rawwire-approve`
**Action**: Inline JavaScript handler

**Flow**:
```
User clicks "Approve & Generate"
  ↓
Confirmation dialog: "Approve this item, copy to content table, and trigger AI content generation?"
  ↓
AJAX POST: RawWireCfg.rest + '/content/approve'
  Method: POST
  Headers: X-WP-Nonce
  Body: JSON.stringify({ content_ids: [id] })
  ↓
rest-api.php::approve_content_batch() (Line 1144)
  ↓
Query archives: SELECT * FROM wp_rawwire_archives 
                WHERE id IN (...) 
                AND result = 'Accepted' 
                AND status = 'pending'
  ↓
FOR EACH item:
  ↓
  INSERT INTO wp_rawwire_content:
    - title, content, summary, url, source, category
    - status = 'approved'
    - created_at = NOW()
    - updated_at = NOW()
  ↓
  UPDATE wp_rawwire_archives:
    SET status = 'approved', updated_at = NOW()
    WHERE id = X
  ↓
  Fire hook: do_action('rawwire_content_approved', item_id, item_data)
  ↓
NEXT item
  ↓
Return: success, count
  ↓
Alert: "Item approved! Copied to content table and AI generation triggered. Check Release page for results."
  ↓
location.reload() - Refresh page
```

**Database Reads**: `wp_rawwire_archives`
**Database Writes**: 
- `wp_rawwire_content` (INSERT new item)
- `wp_rawwire_archives` (UPDATE status='approved')
**Hook Fired**: `rawwire_content_approved` (for generative AI)

### Legacy Template System Support
**File**: `cores/template-engine/panel-renderer.php`
**Method**: `resolve_db_binding()` (Line 773)

**Auto-Migration**:
```
Template uses: db:findings
  ↓
Intercepted at line 777-790
  ↓
Redirected to: db:archives:result=Accepted:status=pending
  ↓
Query: SELECT * FROM wp_rawwire_archives 
       WHERE result = 'Accepted' AND status = 'pending'
```

---

## 8. RELEASE PAGE (STAGE 4: CONTENT TABLE & PUBLISHING)

### File: `raw-wire-dashboard.php`
**Method**: `admin_release_page()` (Line 626)

**Renderer**: `RawWire_Page_Renderer::render_release()`

### Template Configuration
**File**: `templates/news-aggregator.template.json`
**Page**: `release` (Line 318)

**Panels**:
1. **content_table_status** - NEW! Shows content table items
2. **release_queue** - Items ready for publishing

### Content Table Status Panel
**File**: `modules/core/module.php`
**Method**: `get_content_table` (Line 512)

**Query**:
```sql
SELECT * FROM wp_rawwire_content 
ORDER BY created_at DESC 
LIMIT 50
```

**Display Table**:
- ID, Title, Source, Status, Created, Updated
- Shows ALL items in content table (approved items from archives)
- Helps track that records successfully moved from archives → content

**Purpose**: Visibility into approved items awaiting AI generation

### Release Queue Panel
**Data Source**: `db:findings:status=approved`
**Card Template**:
- Header: `{{generated_headline}}`
- Body: `{{generated_content}}`
- Footer: Outlets, scheduling

**Publishing Options**:
- Schedule: now, 1h, 3h, 6h, 12h, 24h, custom
- Outlets: Configurable

---

## 9. DATABASE SCHEMA (UPDATED)

### Table: `wp_rawwire_archives` (Permanent Storage)
**Created by**: `services/class-migration-service.php::create_archives_table()` (Line 48)

**Columns**:
- id (PK, auto_increment)
- title (varchar 500)
- content (longtext)
- summary (longtext)
- link/url (varchar 2000)
- source (varchar 200)
- category (varchar 100)
- copyright_status (varchar 50)
- copyright_info (longtext)
- attribution (varchar 500)
- publication_date (varchar 100)
- document_number (varchar 100)
- **score** (float) - AI relevance score
- **ai_reason** (longtext) - AI explanation
- **result** (varchar 20) - 'Accepted' or 'Rejected'
- **status** (varchar 20) - 'pending' or 'approved' ⚠️ **CRITICAL COLUMN**
- created_at (datetime)
- scored_at (datetime)
- **updated_at** (datetime) - ⚠️ **NEW COLUMN**

**Indexes**:
- PRIMARY KEY (id)
- KEY source_idx (source)
- KEY result_idx (result)
- KEY status_idx (status) - ⚠️ **CRITICAL INDEX**
- KEY score_idx (score)
- KEY created_idx (created_at)
- UNIQUE KEY title_source_idx (title, source)

**Lifecycle**: Permanent audit log

**⚠️ CRITICAL FIX APPLIED**:
- status column was missing from table (CREATE TABLE IF NOT EXISTS doesn't add columns)
- Added manually via: `ALTER TABLE wp_rawwire_archives ADD COLUMN status varchar(20) DEFAULT 'pending' AFTER result`
- Added manually via: `ALTER TABLE wp_rawwire_archives ADD COLUMN updated_at datetime AFTER scored_at`
- Added index: `CREATE INDEX status_idx ON wp_rawwire_archives(status)`

---

### Table: `wp_rawwire_content` (Final Approved Content)
**Created by**: `services/class-migration-service.php`

**Columns**:
- id (PK, auto_increment)
- title (varchar 500)
- content (longtext)
- summary (longtext)
- url (varchar 2000)
- source (varchar 200)
- category (varchar 100)
- status (varchar 20) - 'approved', 'published'
- created_at (datetime)
- updated_at (datetime)

**Lifecycle**: Items copied from archives after human approval

---

## 10. STATISTICS CARDS (SIMPLIFIED)

### File: `admin/class-dashboard.php`
**Method**: `get_stats()` (Line 721)

### Queries (Simplified for Clarity)
```sql
-- 1. Candidates (awaiting AI scoring)
SELECT COUNT(*) FROM wp_rawwire_candidates

-- 2. Archives (total scored items)
SELECT COUNT(*) FROM wp_rawwire_archives

-- 3. Awaiting Approval (Accepted + pending status)
SELECT COUNT(*) FROM wp_rawwire_archives 
WHERE result = 'Accepted' AND status = 'pending'

-- 4. Content (approved items)
SELECT COUNT(*) FROM wp_rawwire_content

-- 5. Queue (if > 0)
SELECT COUNT(*) FROM wp_rawwire_queue
```

**Rendered**: Lines 86-118

### Display (5-6 cards)
1. **Candidates** (conditional, if > 0) - Blue border
   - Count from candidates table
   - "Awaiting AI scoring"
   
2. **Archives** 
   - Total count from archives table
   - "Total scored items"
   
3. **Awaiting Approval** - Green border
   - Count of Accepted + pending items
   - "Ready for human review"
   
4. **Content**
   - Count from content table
   - "Approved items"
   
5. **Queue** (conditional, if > 0) - Orange border
   - Count from queue table
   - "Processing"
   
6. **Last Sync** - Small card
   - Timestamp from option

**Changes from Previous**:
- ❌ Removed: "Accepted by AI", "Approved Content", "Rejected by AI" (duplicates)
- ✅ Simplified: Direct table counts instead of complex filtering
- ✅ Clear: Labels match actual database tables
- ✅ Workflow: Candidates → Archives → Approval → Content

---

## 11. PROGRESS BAR (DARK MODE FIX)

### File: `admin/class-dashboard.php`
**CSS Location**: Inline `<style>` tag (Lines 404-490)

### Critical Fix Applied
**Problem**: Progress bar invisible in dark mode
- Used hardcoded colors: `#fff`, `#333`, `#666`, `#f5f5f5`
- White backgrounds on dark theme = invisible

**Solution**: Use CSS variables
```css
/* Before */
background: #fff;
color: #333;
border: 1px solid #ddd;

/* After */
background: var(--rw-card, #fff);
color: var(--rw-fg-primary, #333);
border: var(--rw-border, #ddd);
```

### CSS Variables Used
| Variable | Light Mode | Dark Mode | Usage |
|----------|-----------|-----------|-------|
| --rw-card | #ffffff | #1a1d21 | Progress container background |
| --rw-surface | #ffffff | #16181c | Inner background |
| --rw-border | #dee2e6 | #2a2d33 | Border colors |
| --rw-fg-primary | #212529 | #e9ecef | Main text (labels) |
| --rw-fg-secondary | #495057 | #ced4da | Secondary text (status) |
| --rw-fg-muted | #6c757d | #adb5bd | Icon colors |

**Visibility**: Now works in both light and dark modes

---

### File: `includes/class-admin.php`
**Method**: `ajax_clear_content()` (Line 497)

### Tables Truncated
```sql
TRUNCATE TABLE wp_rawwire_candidates
TRUNCATE TABLE wp_rawwire_archives
TRUNCATE TABLE wp_rawwire_content
TRUNCATE TABLE wp_rawwire_queue
```

**Hook**: `wp_ajax_rawwire_clear_content`

---

## 9. DATABASE SCHEMA

### Table: `wp_rawwire_candidates` (Temporary Staging)
**Created by**: `services/class-migration-service.php::create_candidates_table()` (Line 14)

**Columns**:
- id (PK, auto_increment)
- title (varchar 500)
- content (longtext)
- link (varchar 2000)
- source (varchar 200)
- copyright_status (varchar 50)
- copyright_info (longtext)
- attribution (varchar 500)
- publication_date (varchar 100)
- document_number (varchar 100)
- created_at (datetime)

**Indexes**:
- PRIMARY KEY (id)
- KEY source_idx (source)
- KEY created_idx (created_at)
- UNIQUE KEY title_source_idx (title, source)

**Lifecycle**: Cleared after scoring completes

---

### Table: `wp_rawwire_archives` (Permanent Storage)
**Created by**: `services/class-migration-service.php::create_archives_table()` (Line 48)

**Columns**:
- id (PK, auto_increment)
- title (varchar 500)
- content (longtext)
- link (varchar 2000)
- source (varchar 200)
- copyright_status (varchar 50)
- copyright_info (longtext)
- attribution (varchar 500)
- publication_date (varchar 100)
- document_number (varchar 100)
- **score** (float) - AI relevance score
- **ai_reason** (longtext) - AI explanation
- **result** (varchar 20) - 'Accepted' or 'Rejected'
- **status** (varchar 20) - 'pending' or 'approved'
- created_at (datetime)
- scored_at (datetime)
- updated_at (datetime)

**Indexes**:
- PRIMARY KEY (id)
- KEY source_idx (source)
- KEY result_idx (result)
- KEY status_idx (status)
- KEY score_idx (score)
- KEY created_idx (created_at)
- UNIQUE KEY title_source_idx (title, source)

**Lifecycle**: Permanent audit log

---

## DEBUGGING CHECKLIST

When sync doesn't work:

1. **Scoring Handler Not Running?**
   - ✓ Check: `new RawWire_Scoring_Handler()` instantiated in raw-wire-dashboard.php:124
   - ✓ Check: Hook registered in constructor
   - ✓ Check: `rawwire_scrape_complete` fires in scraper
   - ✓ Check: AI analyzer class available

2. **Statistics Showing Zero?**
   - ✓ Check: Candidates table has records (`SELECT COUNT(*) FROM wp_rawwire_candidates`)
   - ✓ Check: Archives table populated after scoring
   - ✓ Check: get_stats() in class-dashboard.php queries correct tables
   - ✓ Check: Page refreshes after workflow completion

3. **Progress Bar Not Showing?**
   - ✓ Check: `#rawwire-progress-container` element exists in DOM
   - ✓ Check: Inline script functions defined (startWorkflowProgress, etc.)
   - ✓ Check: localStorage not blocked by browser
   - ✓ Check: Transient `rawwire_workflow_status` being set/updated
   - ✓ Check: AJAX endpoint `rawwire_get_workflow_status` responding
   - ✓ Check: CSS styles for `.rawwire-progress-bar` loaded
   - ⚠️ **FIXED**: Progress functions now inlined in class-dashboard.php (not dashboard.js)

4. **Items Not Moving to Archives?**
   - ✓ Check: Scoring handler instantiated (LINE 124!)
   - ✓ Check: AI analyzer class exists (RawWire_AI_Content_Analyzer)
   - ✓ Check: analyze_batch() method available
   - ✓ Check: Hook firing after scraper completes
   - ✓ Check: Candidates table not empty when scoring runs

5. **Progress Bar Stuck on "Scraping"?**
   - ✓ Check: Background scrape actually running (check error log)
   - ✓ Check: WP Cron spawned correctly (spawn_cron() called)
   - ✓ Check: Transient 'rawwire_scraping_in_progress' exists
   - ✓ Check: Scraper completes and fires hook
   - ✓ Check: Scoring handler runs and updates transient
   - ✓ Check: Transient updates reflected in AJAX polling
   - ✓ Check: No PHP errors in error log
   - ✓ Check: PHP time limit not exceeded (set to 30 min)

6. **Statistics Cards Still Zero After Workflow?**
   - ✓ Check: Page auto-reloads after completion
   - ✓ Check: get_stats() queries right tables (candidates, archives, content)
   - ✓ Check: Archives table has result='Accepted' records
   - ✓ Check: Manual page refresh updates numbers

7. **Timeout Issues? ⚠️ NEW**
   - ✓ Check: AJAX returns immediately (< 1 sec) with "started" message
   - ✓ Check: Background scrape runs via WP Cron
   - ✓ Check: PHP time limit increased to 1800 seconds (30 min)
   - ✓ Check: Memory limit increased to 512M
   - ✓ Check: Transient lock prevents duplicate scrapes
   - ✓ Check: Error log for timeout or memory errors

8. **Scrape Not Starting in Background?**
   - ✓ Check: WP Cron enabled (DISABLE_WP_CRON not true)
   - ✓ Check: spawn_cron() triggers immediately
   - ✓ Check: Hook 'rawwire_run_background_scrape' registered
   - ✓ Check: run_background_scrape() method exists
   - ✓ Check: Error log for background execution

---

## DATA DEPENDENCIES MAP

### Progress Bar Dependencies
```
Progress Bar Display
  ├─ HTML: #rawwire-progress-container (class-dashboard.php:64)
  ├─ CSS: .rawwire-progress-bar styles (class-dashboard.php:404-475)
  ├─ JavaScript: Inline functions (class-dashboard.php:497-630)
  ├─ localStorage: 'rawwire_workflow_progress' key
  ├─ Transient: 'rawwire_workflow_status' (read every 2s)
  └─ AJAX: 'rawwire_get_workflow_status' endpoint

Scraper → Transient
  └─ services/class-scraper-service.php:141-149
     Sets: stage='scraping', message='Scraping...'

Scoring Handler → Transient
  ├─ services/class-scoring-handler.php:34-42
  │  Sets: stage='scoring', message='AI scoring...'
  └─ services/class-scoring-handler.php:94-100
     Sets: stage='complete', message='Complete! X accepted'

Progress Polling → Transient
  └─ includes/class-admin.php:562-577 (ajax_get_workflow_status)
     Reads: get_transient('rawwire_workflow_status')
```

### Statistics Dependencies
```
Dashboard Stat Cards
  ├─ HTML: admin/class-dashboard.php:84-122
  ├─ Data: admin/class-dashboard.php:595-640 (get_stats method)
  └─ Queries:
      ├─ wp_rawwire_candidates (awaiting scoring)
      ├─ wp_rawwire_archives (accepted, rejected, pending)
      └─ wp_rawwire_content (final approved)

Module Stats API
  ├─ modules/core/module.php:81-92
  └─ Same queries as dashboard

Refresh Trigger
  └─ Inline script in class-dashboard.php
     Calls: location.reload() when stage='complete'
```

### Workflow Data Flow
```
User Click
  ↓
ajax_sync() [includes/class-admin.php:64] - RETURNS IMMEDIATELY
  ↓
wp_schedule_single_event('rawwire_run_background_scrape')
  ↓
spawn_cron() - Triggers background execution
  ↓
[AJAX RETURNS HERE - User sees "started" message]
  ↓
run_background_scrape() [includes/class-admin.php:~112] - RUNS IN BACKGROUND
  ↓
RawWire_Scraper_Service::scrape_all() [services/class-scraper-service.php:138]
  ↓
FOR EACH source (10-20 minutes total):
  ↓
  Scrape external website
  ↓
  INSERT INTO wp_rawwire_candidates
  ↓
NEXT source
  ↓
do_action('rawwire_scrape_complete')
  ↓
RawWire_Scoring_Handler::process_candidates() [services/class-scoring-handler.php:36]
  ↓
RawWire_AI_Content_Analyzer::analyze_batch() [includes/class-ai-content-analyzer.php:50]
  ↓
INSERT INTO wp_rawwire_archives (result='Accepted'/'Rejected')
  ↓
DELETE FROM wp_rawwire_candidates
  ↓
set_transient('rawwire_workflow_status', stage='complete')
  ↓
delete_transient('rawwire_scraping_in_progress') - Release lock
  ↓
Progress Bar polls, sees complete
  ↓
location.reload()
  ↓
get_stats() reads updated archives table
  ↓
Statistics show new numbers
```

**⚠️ CRITICAL**: Scraping now runs asynchronously in WordPress Cron
- AJAX request returns in < 1 second
- Scraping happens in background (10-20+ minutes)
- Progress bar polls transient every 2 seconds for updates
- No timeout issues

---

**Last Updated**: January 13, 2026
**Critical Fix Applied**: Added `new RawWire_Scoring_Handler()` instantiation
**Critical Fixes Applied**: 
- Added `new RawWire_Scoring_Handler()` instantiation (Line 124)
- Added status column to archives table (ALTER TABLE)
- Implemented async scraping architecture (no timeouts)
- Fixed progress bar dark mode visibility (CSS variables)
- Implemented "Approve & Generate" button workflow
- Added Content Table Status panel to Release page
- Simplified statistics cards to show clear table counts

---

## 12. FILES MODIFIED IN LATEST SESSION

1. **admin/class-dashboard.php**
   - Fixed progress bar CSS for dark mode visibility (Lines 404-490)
   - Simplified statistics to show table counts (Lines 86-118, 721-747)

2. **modules/core/module.php**
   - Updated approvals query to use archives table (Line 548)
   - Changed button to "Approve & Generate" (Line 533)
   - Added JavaScript handler for approve button (Lines 550-580)
   - Added get_content_table handler (Lines 512-544)

3. **rest-api.php**
   - Rewrote approve_content_batch to implement proper workflow (Lines 1144-1195)
   - Copies from archives to content
   - Updates archives status to 'approved'
   - Fires rawwire_content_approved hook

4. **templates/news-aggregator.template.json**
   - Added content_table_status panel to release page (Line 325)
   - Added panel definition (Lines 604-611)

5. **Database Schema**
   - ALTER TABLE: Added status column to archives
   - ALTER TABLE: Added updated_at column to archives  
   - CREATE INDEX: Added status_idx on archives(status)
