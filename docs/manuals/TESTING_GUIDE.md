# RawWire Dashboard Testing Guide

## Quick Start

### Run Complete Test Suite
```bash
cd /path/to/wordpress/wp-content/plugins/raw-wire-dashboard
php seed-test-data.php
```

### Command Options
- `--seed-only` - Only generate test data, skip API tests
- `--test-only` - Only run tests on existing data
- `--verbose` - Show detailed output
- `--clear` - Clear existing data before seeding

### Examples
```bash
# Seed data only
php seed-test-data.php --seed-only

# Test existing data
php seed-test-data.php --test-only

# Fresh start with verbose output
php seed-test-data.php --clear --verbose

# Seed without clearing existing data
php seed-test-data.php --seed-only
```

---

## REST API Endpoints Reference

### Base URL
```
/wp-json/rawwire/v1/
```

### 1. GET /content
**Purpose:** Retrieve filtered content items

**Parameters:**
- `status` (string) - Filter by status: `pending`, `approved`, `rejected`, `published`
- `limit` (int) - Number of items per page (default: 20, max: 100)
- `offset` (int) - Pagination offset (default: 0)
- `category` (string) - Filter by category
- `q` (string) - Search query for title/content
- `after` (date) - Published after date (YYYY-MM-DD)
- `before` (date) - Published before date (YYYY-MM-DD)
- `min_relevance` (int) - Minimum relevance score (0-100)

**Response:**
```json
{
  "items": [
    {
      "id": 123,
      "title": "SEC Announces $2.5B Penalty",
      "content": "Full text...",
      "source_url": "https://...",
      "relevance_score": 95,
      "status": "pending",
      "published_date": "2024-01-15",
      "created_at": "2024-01-15 10:30:00"
    }
  ],
  "pagination": {
    "total": 150,
    "count": 20,
    "limit": 20,
    "offset": 0
  }
}
```

**Examples:**
```bash
# Get all pending items
curl "https://yoursite.com/wp-json/rawwire/v1/content?status=pending"

# Get high-scoring recent items
curl "https://yoursite.com/wp-json/rawwire/v1/content?min_relevance=80&after=2024-01-01&limit=10"

# Search for "SEC" in approved items
curl "https://yoursite.com/wp-json/rawwire/v1/content?q=SEC&status=approved"

# Pagination - page 2
curl "https://yoursite.com/wp-json/rawwire/v1/content?limit=20&offset=20"
```

---

### 2. GET /stats
**Purpose:** Get dashboard statistics

**Response:**
```json
{
  "total": 150,
  "by_status": {
    "pending": 75,
    "approved": 50,
    "rejected": 15,
    "published": 10
  },
  "last_updated": "2024-01-15T10:30:00Z",
  "timestamp": "2024-01-15T12:00:00Z"
}
```

**Example:**
```bash
curl "https://yoursite.com/wp-json/rawwire/v1/stats"
```

---

### 3. POST /content/approve
**Purpose:** Approve content item(s)

**Authentication:** Required (admin/editor capability)

**Parameters:**
- `content_id` (int|array) - Single ID or array of IDs to approve

**Response:**
```json
{
  "success": true,
  "approved": [123, 124, 125],
  "failed": [],
  "message": "3 items approved successfully"
}
```

**Examples:**
```bash
# Approve single item
curl -X POST "https://yoursite.com/wp-json/rawwire/v1/content/approve" \
  -H "Content-Type: application/json" \
  -u admin:password \
  -d '{"content_id": 123}'

# Approve multiple items
curl -X POST "https://yoursite.com/wp-json/rawwire/v1/content/approve" \
  -H "Content-Type: application/json" \
  -u admin:password \
  -d '{"content_id": [123, 124, 125]}'
```

---

### 4. POST /content/snooze
**Purpose:** Snooze content item to hide temporarily

**Authentication:** Required

**Parameters:**
- `content_id` (int) - Content ID to snooze
- `hours` (int) - Hours to snooze (default: 24)

**Response:**
```json
{
  "success": true,
  "content_id": 123,
  "snoozed_until": "2024-01-16T10:30:00Z",
  "message": "Content snoozed for 24 hours"
}
```

**Example:**
```bash
curl -X POST "https://yoursite.com/wp-json/rawwire/v1/content/snooze" \
  -H "Content-Type: application/json" \
  -u admin:password \
  -d '{"content_id": 123, "hours": 48}'
```

---

### 5. POST /fetch-data
**Purpose:** Trigger data sync from GitHub or simulate test data

**Authentication:** Required

**Parameters:**
- `simulate` (bool) - Use simulator instead of real GitHub data (default: false)
- `count` (int) - Number of items to generate (simulator mode only, default: 20)
- `shock_level` (string) - Shock level for simulated data: `low`, `medium`, `high`, `mixed`

**Response:**
```json
{
  "success": true,
  "items_processed": 20,
  "items_stored": 18,
  "errors": 2,
  "source": "simulator",
  "message": "Data sync completed"
}
```

**Examples:**
```bash
# Generate 50 simulated items with mixed shock levels
curl -X POST "https://yoursite.com/wp-json/rawwire/v1/fetch-data" \
  -H "Content-Type: application/json" \
  -u admin:password \
  -d '{"simulate": true, "count": 50, "shock_level": "mixed"}'

# Fetch real data from GitHub
curl -X POST "https://yoursite.com/wp-json/rawwire/v1/fetch-data" \
  -u admin:password
```

---

### 6. POST /clear-cache
**Purpose:** Clear all cached data

**Authentication:** Required (admin only)

**Response:**
```json
{
  "success": true,
  "message": "Cache cleared successfully"
}
```

**Example:**
```bash
curl -X POST "https://yoursite.com/wp-json/rawwire/v1/clear-cache" \
  -u admin:password
```

---

### 7. POST /admin/api-key/generate
**Purpose:** Generate new API key for external access

**Authentication:** Required (admin only)

**Parameters:**
- `description` (string) - Key description/label

**Response:**
```json
{
  "success": true,
  "api_key": "rwk_abc123def456...",
  "description": "Mobile App Access",
  "created_at": "2024-01-15T10:30:00Z"
}
```

**Example:**
```bash
curl -X POST "https://yoursite.com/wp-json/rawwire/v1/admin/api-key/generate" \
  -H "Content-Type: application/json" \
  -u admin:password \
  -d '{"description": "Mobile App Access"}'
```

---

### 8. POST /admin/api-key/revoke
**Purpose:** Revoke an API key

**Authentication:** Required (admin only)

**Parameters:**
- `api_key` (string) - Key to revoke

**Response:**
```json
{
  "success": true,
  "message": "API key revoked successfully"
}
```

**Example:**
```bash
curl -X POST "https://yoursite.com/wp-json/rawwire/v1/admin/api-key/revoke" \
  -H "Content-Type: application/json" \
  -u admin:password \
  -d '{"api_key": "rwk_abc123def456..."}'
```

---

## Test Scenarios

### Scenario 1: Fresh Installation Testing
```bash
# 1. Clear any existing test data
php seed-test-data.php --clear --seed-only

# 2. Verify data was created
php seed-test-data.php --test-only

# 3. Check stats via API
curl "https://yoursite.com/wp-json/rawwire/v1/stats"
```

### Scenario 2: Approval Workflow Testing
```bash
# 1. Get pending items
curl "https://yoursite.com/wp-json/rawwire/v1/content?status=pending&limit=5"

# 2. Approve first item (replace 123 with actual ID)
curl -X POST "https://yoursite.com/wp-json/rawwire/v1/content/approve" \
  -H "Content-Type: application/json" \
  -u admin:password \
  -d '{"content_id": 123}'

# 3. Verify status changed
curl "https://yoursite.com/wp-json/rawwire/v1/content?status=approved"

# 4. Check updated stats
curl "https://yoursite.com/wp-json/rawwire/v1/stats"
```

### Scenario 3: Score Distribution Testing
```bash
# 1. Seed data with various shock levels
php seed-test-data.php --clear

# 2. Get high-scoring items
curl "https://yoursite.com/wp-json/rawwire/v1/content?min_relevance=80"

# 3. Get medium-scoring items
curl "https://yoursite.com/wp-json/rawwire/v1/content?min_relevance=50&limit=100"

# 4. Run score analysis
php seed-test-data.php --test-only --verbose
```

### Scenario 4: Pagination Testing
```bash
# Page 1
curl "https://yoursite.com/wp-json/rawwire/v1/content?limit=10&offset=0"

# Page 2
curl "https://yoursite.com/wp-json/rawwire/v1/content?limit=10&offset=10"

# Page 3
curl "https://yoursite.com/wp-json/rawwire/v1/content?limit=10&offset=20"
```

### Scenario 5: Date Range Filtering
```bash
# Items from last 7 days
curl "https://yoursite.com/wp-json/rawwire/v1/content?after=$(date -d '7 days ago' +%Y-%m-%d)"

# Items from specific date range
curl "https://yoursite.com/wp-json/rawwire/v1/content?after=2024-01-01&before=2024-01-31"
```

---

## Expected Test Results

When running `php seed-test-data.php`, you should see:

### ✓ Passing Tests
- **Dependency Checks:** All required classes and database tables exist
- **Data Seeding:** 50 items generated and stored (15 high shock, 25 mixed, 10 low shock)
- **GET /content:** Returns items with proper pagination
- **GET /stats:** Returns accurate counts by status
- **Content Filters:** All filter types work (status, relevance, date, search)
- **Pagination:** Multiple pages return different items
- **Approval Workflow:** Status changes from pending to approved
- **Snooze Workflow:** Items are snoozed with proper timestamp
- **Score Distribution:** Items have scores distributed across ranges
- **Dashboard Display:** All required fields populated, multiple source types

### Test Output Example
```
================================================================================
                           CHECKING DEPENDENCIES
================================================================================
[✓] RawWire_Data_Simulator loaded
[✓] RawWire_Data_Processor loaded
[✓] RawWire_REST_API_Controller loaded
[✓] Database table wp_rawwire_content exists

================================================================================
                           SEEDING TEST DATA
================================================================================
[•] Generating: High shock recent items (15 items)
[•] Generating: Mixed shock items (25 items)
[•] Generating: Low shock older items (10 items)
[✓] Total: Generated 50, Stored 50, Errors 0

================================================================================
                       TESTING REST API ENDPOINTS
================================================================================
[•] Testing GET /rawwire/v1/content
[✓] GET /content - PASSED
[•] Testing GET /rawwire/v1/stats
[✓] GET /stats - PASSED
[•] Testing GET /content with filters
[✓] Content filters: 4/4 passed
[•] Testing pagination
[✓] Pagination - PASSED

================================================================================
                         TESTING APPROVAL WORKFLOW
================================================================================
[•] Testing approval of item #123: SEC Announces Unprecedented $2.5 Billion...
[✓] Item status changed to approved

================================================================================
                          TESTING SNOOZE WORKFLOW
================================================================================
[•] Testing snooze of item #124
[✓] Snooze endpoint - PASSED

================================================================================
                         TESTING SCORE CALCULATION
================================================================================
[•] Score distribution:
     90-99: ████████████████ (16 items)
     80-89: ██████████ (10 items)
     70-79: ████████ (8 items)
     60-69: ██████ (6 items)
     50-59: ████ (4 items)
     40-49: ██ (2 items)
     30-39: ██ (2 items)
     20-29: █ (1 items)
     10-19: █ (1 items)
[✓] Average relevance score: 74.32

================================================================================
                      VERIFYING DASHBOARD DISPLAY DATA
================================================================================
[✓] 50/50 items have all required fields
[✓] 50 items have relevance scores
[•] 28 items from the last 7 days
[✓] 4 different source types
[•] Status distribution:
  pending: 38 items
  approved: 10 items
  rejected: 2 items

================================================================================
                             TEST SUMMARY REPORT
================================================================================

Data Seeding:
  Generated: 50
  Stored: 50
  Errors: 0

REST API Tests:
  ✓ get_content
  ✓ get_stats
  ✓ content_filters
  ✓ pagination

[✓] Approval Workflow: PASSED
[✓] Snooze Workflow: PASSED

Dashboard Display Checks:
  ✓ complete_fields
  ✓ scored_items
  ✓ source_variety

================================================================================
FINAL RESULT: 11/11 tests passed (100.0%)
================================================================================

✓ Testing complete! Check the results above.
```

---

## Troubleshooting

### Problem: "Cannot find WordPress installation"
**Solution:** Run the script from within the plugin directory:
```bash
cd wp-content/plugins/raw-wire-dashboard
php seed-test-data.php
```

### Problem: "Required dependencies not found"
**Solution:** Ensure plugin is properly installed and activated:
```bash
wp plugin activate raw-wire-dashboard
```

### Problem: "Database table NOT FOUND"
**Solution:** Plugin activation should create tables. Try:
```bash
wp plugin deactivate raw-wire-dashboard
wp plugin activate raw-wire-dashboard
```

### Problem: "Permission denied" errors in API tests
**Solution:** Ensure WordPress user credentials have proper capabilities or use admin account

### Problem: Zero items generated
**Solution:** Check PHP error logs and verify `RawWire_Data_Simulator` class is loaded

---

## Manual Testing Checklist

- [ ] Install plugin in fresh WordPress instance
- [ ] Run seed script: `php seed-test-data.php --clear`
- [ ] Verify 50 items created in database
- [ ] Access dashboard at `/wp-admin/admin.php?page=rawwire-dashboard`
- [ ] Verify items display in dashboard
- [ ] Test filtering by status (pending/approved/rejected)
- [ ] Test search functionality
- [ ] Test approve button on pending item
- [ ] Test snooze button
- [ ] Verify stats counter updates
- [ ] Test pagination (if >20 items)
- [ ] Test date range filters
- [ ] Test relevance score sorting
- [ ] Test bulk actions (select multiple, approve all)
- [ ] Test refresh data button
- [ ] Verify module configuration loads
- [ ] Test responsive design (mobile/tablet)
- [ ] Test keyboard navigation
- [ ] Verify WCAG 2.1 AA compliance
- [ ] Test API endpoints with curl/Postman
- [ ] Verify cache clearing works
- [ ] Test API key generation/revocation

---

## Performance Benchmarks

Expected performance for REST API endpoints:

| Endpoint | Expected Response Time | Items |
|----------|----------------------|-------|
| GET /content | < 100ms | 20 items |
| GET /content | < 500ms | 100 items |
| GET /stats | < 50ms | All |
| POST /approve | < 100ms | 1 item |
| POST /approve | < 500ms | 10 items |
| POST /fetch-data (simulate) | < 2s | 50 items |

Database query optimization:
- Indexes on: `status`, `relevance_score`, `published_date`, `created_at`
- Transient caching for stats (5 minute TTL)
- Query limit: 100 items max per request

---

## Next Steps

After successful testing:

1. **Staging Deployment:**
   - Upload `raw-wire-dashboard-v1.0.13.zip` to staging server
   - Install and activate plugin
   - Run seed script to populate test data
   - Perform manual testing checklist

2. **Production Deployment:**
   - Follow deployment guide in `DEPLOYMENT_READY_v1.0.13.md`
   - Do NOT run seed script on production
   - Configure GitHub sync for real data
   - Set up monitoring and logging

3. **Monitoring:**
   - Watch error logs: `wp-content/plugins/raw-wire-dashboard/logs/`
   - Monitor API response times
   - Track approval workflow usage
   - Monitor relevance score distribution

4. **Optimization:**
   - Adjust module scoring weights based on real data
   - Fine-tune shock level thresholds
   - Optimize database queries if needed
   - Consider adding more granular filters

---

## Support

For issues or questions:
- Check logs: `wp-content/plugins/raw-wire-dashboard/logs/`
- Review `PLUGIN_ARCHITECTURE.md` for system design
- See `API_DOCUMENTATION.md` for API details
- Check `README.md` for general plugin info
