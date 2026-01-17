# Raw-Wire Sync Workflow Specification

## Overview
The sync workflow is a multi-stage pipeline that scrapes sources, scores findings with AI, and prepares content for publishing.

## Database Tables

### 1. `rawwire_candidates`
**Purpose**: Raw findings from scraper (temporary staging)
- Populated by: Scraper
- Consumed by: AI Scoring
- Lifecycle: Records deleted after AI scoring completes

### 2. `rawwire_approvals`  
**Purpose**: AI-approved items awaiting human review
- Populated by: AI Scoring (top 2 per source marked "approved")
- Consumed by: Human approval on Approvals page
- Actions: Approve+Generate → moves to `content`, Reject → moves to `archives`

### 3. `rawwire_content`
**Purpose**: Human-approved items in AI generation queue
- Populated by: Human clicking "Approve + Generate" 
- Consumed by: Generative AI
- Lifecycle: Records move to `releases` after generation complete

### 4. `rawwire_releases`
**Purpose**: Generated content ready for publishing
- Populated by: Generative AI after completing content
- Consumed by: Human clicking "Publish" on Releases page
- Actions: Publish → creates WordPress post, removes from table

### 5. `rawwire_archives`
**Purpose**: Historical record of all rejected items
- Populated by: AI rejections + Human rejections
- Lifecycle: Permanent storage for audit trail

---

## Workflow Stages

### Stage 1: Sync Initiated
**Trigger**: User clicks Sync button on dashboard
**Actions**:
1. Lock sync button (disable, show spinner)
2. Send toast notification: "Starting sync..."
3. Initialize progress bar at 0%
4. Fire AJAX request to REST API `/wp-json/rawwire/v1/sync`

### Stage 2: Scraper Execution
**Progress**: 0% → 30%
**Actions**:
1. REST API triggers scraper for each enabled source
2. Progress updates via polling: "Scraping {source_name}..."
3. Scraper inserts findings into `candidates` table
4. On completion, fire hook: `do_action('rawwire_scraper_complete')`

### Stage 3: AI Scoring
**Progress**: 30% → 60%
**Trigger**: `rawwire_scraper_complete` hook
**Actions**:
1. AI service reads all records from `candidates` table
2. Groups by source
3. Scores each record (relevance, quality, etc.)
4. Progress updates: "Scoring candidates..."

### Stage 4: AI Approval Decision
**Progress**: 60% → 80%
**Actions**:
1. For each source, rank candidates by score
2. Top 2 per source: mark `status = 'approved'`, move to `approvals` table
3. Others: mark `status = 'rejected'`, move to `archives` table
4. Clear `candidates` table
5. Fire hook: `do_action('rawwire_scoring_complete')`
6. Progress updates: "Processing approvals..."

### Stage 5: Sync Complete
**Progress**: 80% → 100%
**Actions**:
1. Progress bar shows "Sync complete!"
2. Toast notification: "Found {n} items for review"
3. Unlock sync button
4. Fire hook: `do_action('rawwire_sync_complete')`

---

## Human Approval Flow (Approvals Page)

### Display
- List all records from `approvals` table where `status = 'approved'`
- Show: title, source, score, AI reason
- Buttons: [Approve + Generate] [Reject]

### Approve + Generate Action
1. Move record from `approvals` to `content` table
2. Set `status = 'queued'` in content table
3. Fire hook: `do_action('rawwire_content_queued', $record_id)`
4. Toast: "Added to generation queue"

### Reject Action
1. Update record: `status = 'rejected'`
2. Move record from `approvals` to `archives` table
3. Toast: "Item rejected"

---

## Content Generation Flow

### Trigger
Hook: `rawwire_content_queued` OR background cron check

### Process
1. Check `content` table for records with `status = 'queued'`
2. For each queued record:
   a. Set `status = 'generating'`
   b. Call generative AI API
   c. Store generated content in `generated_content` column
   d. Set `status = 'complete'`
   e. Move record to `releases` table
3. Fire hook: `do_action('rawwire_generation_complete')`

---

## Release Flow (Releases Page)

### Display
- List all records from `releases` table
- Show: title, source, generated content preview
- Button: [Publish]

### Publish Action
1. Create WordPress post with generated content
2. Set post status based on config (draft/publish)
3. Remove record from `releases` table
4. Log to activity log
5. Toast: "Published: {title}"

---

## Progress Bar System

### States
- `idle`: Hidden or showing "Ready"
- `syncing`: Active with percentage and stage message
- `complete`: Shows "Complete!" then fades

### Polling
- AJAX polls `/wp-json/rawwire/v1/sync/status` every 2 seconds
- Returns: `{ stage: string, progress: int, message: string }`

### Stages
| Stage | Progress | Message |
|-------|----------|---------|
| starting | 0% | "Initializing..." |
| scraping | 5-30% | "Scraping {source}..." |
| scoring | 30-60% | "AI scoring candidates..." |
| approving | 60-80% | "Processing approvals..." |
| complete | 100% | "Sync complete!" |

---

## REST API Endpoints

### POST `/wp-json/rawwire/v1/sync`
Starts the sync process. Returns sync job ID.

### GET `/wp-json/rawwire/v1/sync/status`
Returns current sync status for progress bar.

### POST `/wp-json/rawwire/v1/approvals/{id}/approve`
Approves item and moves to generation queue.

### POST `/wp-json/rawwire/v1/approvals/{id}/reject`
Rejects item and moves to archives.

### POST `/wp-json/rawwire/v1/releases/{id}/publish`
Publishes item as WordPress post.

---

## Hooks Reference

| Hook | Fired When | Typical Handler |
|------|-----------|-----------------|
| `rawwire_scraper_complete` | All sources scraped | AI Scoring Service |
| `rawwire_scoring_complete` | All candidates scored | Progress updater |
| `rawwire_sync_complete` | Full sync finished | UI unlock |
| `rawwire_content_queued` | Human approves item | Generation service |
| `rawwire_generation_complete` | AI content done | Progress updater |
