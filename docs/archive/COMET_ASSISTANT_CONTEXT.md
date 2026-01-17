# RawWire Dashboard - Context Brief for AI Assistants
**Version:** 1.0.14 (in development)  
**Last Updated:** January 6, 2026  
**Project Status:** Pre-Production Testing Phase

---

## 🎯 EXECUTIVE SUMMARY

**What is RawWire?**  
RawWire Dashboard is a WordPress plugin that automates the discovery, curation, and approval of "surprising" or "shocking" Federal Register findings for social media content. Think: "The government just spent $800 million on what?!" content that goes viral.

**Core Innovation:**  
Algorithmic relevance scoring (0-100) that identifies Federal Register items with high viral potential based on shock factor, rarity, recency, authority, and public interest.

**Current Phase:**  
Completing error monitoring system and preparing v1.0.14 release for production deployment testing.

---

## 📊 PROJECT VISION

### Mission Statement
Democratize access to surprising government actions by automating the tedious process of Federal Register monitoring and highlighting the most share-worthy findings.

### Target Audience
- Political commentary creators
- Investigative journalists
- Government transparency advocates
- Social media content creators
- Citizen watchdog organizations

### Value Proposition
**Before RawWire:** Manually scan thousands of Federal Register entries, spend hours identifying interesting items, risk missing important stories.

**After RawWire:** Automated daily discovery, relevance-scored findings (0-100), one-click approval workflow, ready-to-share content.

---

## 🏗️ SYSTEM ARCHITECTURE

### Technology Stack
- **Platform:** WordPress 5.8+ (PHP 7.4+)
- **Database:** MySQL 5.7+ (wp_rawwire_content, wp_rawwire_automation_log)
- **Frontend:** Vanilla JavaScript (ES6+), CSS3
- **APIs:** WordPress REST API, Federal Register API (future), GitHub API (current data source)
- **Caching:** WordPress Transients API
- **Logging:** Custom dual-logging system (database + error_log)

### Plugin Structure
```
raw-wire-dashboard/
├── raw-wire-dashboard.php          # Main plugin file, version 1.0.13
├── includes/
│   ├── class-dashboard-core.php    # Core dashboard logic
│   ├── class-logger.php            # Dual logging system (DB + error_log)
│   ├── class-data-processor.php    # Item processing + relevance scoring
│   ├── class-github-fetcher.php    # GitHub data fetching (temporary)
│   ├── class-cache-manager.php     # Caching layer
│   ├── class-rest-api-controller.php  # REST endpoints
│   ├── class-approval-workflow.php # Approval state machine
│   ├── class-search-service.php    # Search + filtering
│   ├── class-settings.php          # Admin settings
│   ├── bootstrap.php               # Module system initialization
│   ├── api/
│   │   └── class-rest-api-controller.php  # REST API
│   ├── search/
│   │   ├── search-module-base.php  # Base search class
│   │   ├── filter-chain.php        # Filter chain pattern
│   │   └── modules/                # Keyword, date, category, relevance modules
│   └── features/
│       └── approval-workflow/      # Future: pluggable workflows
├── dashboard.js                    # Frontend dashboard interactions
├── dashboard.css                   # Dashboard styling (WCAG 2.1 AA compliant)
├── templates/
│   └── raw-wire-default.json       # Default module configuration
└── tests/                          # PHPUnit tests
```

---

## 🎛️ KEY FEATURES IMPLEMENTED

### ✅ Priority 1: Critical Safety (v1.0.13)
1. **CSS Sanitization**
   - All custom CSS sanitized with `wp_strip_all_tags()`
   - XSS protection on user-supplied styles
   - Location: `includes/class-settings.php`

2. **REST API Consolidation**
   - Single controller: `includes/api/class-rest-api-controller.php`
   - 8 endpoints with rate limiting (429 responses)
   - Error handling with try-catch + dual logging

### ✅ Priority 2: Module Architecture (v1.0.13)
**Fully implemented 45+ configurable parameters:**

#### Scoring Configuration (25 params)
- Monetary thresholds (trillion, billion, million amounts → point values)
- Shock keywords ("reversal", "unprecedented", "banned" → +15-25 pts)
- Rarity keywords ("first time", "never before" → +15-20 pts)
- Recency weights (6h/24h/72h/168h → +15/10/5/3 pts)
- Authority sources (DoD, EPA, Treasury → +12-15 pts)
- Public interest keywords ("taxpayer", "medicare" → +10-12 pts)
- Category bonuses ("rule", "notice", "proposed rule" → +5-8 pts)

#### Search Configuration (8 params)
- Keyword search (title/content/notes matching)
- Date range filtering (after/before)
- Category filtering
- Status filtering (pending/approved/snoozed/rejected)
- Minimum relevance threshold
- Sort order (relevance_desc, date_desc, etc.)
- Pagination (limit/offset)

#### Display Configuration (12 params)
- Dashboard title, description, tagline
- Items per page
- Color schemes (primary/secondary/accent/text)
- Date display format
- Status badge labels
- Custom CSS injection
- Show/hide specific columns
- Default view settings

#### Module System Features
- Hot-swappable module loading
- JSON-based configuration (`templates/raw-wire-default.json`)
- Module inheritance (extend base, override specific params)
- Runtime module switching via admin UI
- Validation + fallback to defaults

### ✅ Priority 3: UX Polish (v1.0.13)
1. **WCAG 2.1 AA Compliance**
   - Color contrast ratios: 4.5:1 (normal text), 3:1 (large text)
   - Focus indicators: 2px solid outlines on all interactive elements
   - Semantic HTML: `<nav>`, `<main>`, `<article>`, proper heading hierarchy
   - ARIA labels: All buttons, form controls labeled

2. **Keyboard Navigation**
   - Tab order: Filters → Search → Content cards → Action buttons
   - Enter/Space: Trigger buttons
   - Escape: Close modals/dropdowns
   - Arrow keys: Navigate card lists
   - Skip links: Jump to main content

3. **Loading States**
   - Skeleton screens during data fetch
   - Spinner animations
   - Disabled states for in-progress actions
   - Optimistic UI updates

### ✅ Error Monitoring System (v1.0.14 - NEW)
**Dual Logging Architecture:**
- **Database Logging:** All activity/errors → `wp_rawwire_automation_log` table
- **Error Log Fallback:** Critical/error/warning → WordPress `error_log` (debug.log)
- **Database Failure Protection:** If DB logging fails, write to error_log automatically

**Enhanced Logger Features:**
```php
// Always logs errors/critical to error_log
RawWire_Logger::log_activity($message, $log_type, $details, 'error');
// Output: [RawWire error] [rest_api] Failed to fetch content | Context: {"error":"..."}

// Warnings logged to error_log if WP_DEBUG enabled
RawWire_Logger::log_activity($message, $log_type, $details, 'warning');

// Database failure fallback
// If wp_rawwire_automation_log INSERT fails → automatically writes to error_log
```

**Error Handling Coverage:**
- ✅ REST API endpoints (8/8 with try-catch)
- ✅ Data Processor (process_raw_federal_register_item, store_item, batch_process_items)
- 🔄 GitHub Fetcher (planned)
- 🔄 Cache Manager (planned)
- 🔄 Error Panel widget (planned)

---

## 🔗 REST API ENDPOINTS

**Base URL:** `/wp-json/rawwire/v1/`

| Endpoint | Method | Purpose | Rate Limit |
|----------|--------|---------|------------|
| `/content` | GET | Fetch content with filters/search | 120/min |
| `/fetch-data` | POST | Sync button (fetch from GitHub/simulate) | 20/min |
| `/content/approve` | POST | Approve content items | 60/min |
| `/content/snooze` | POST | Snooze content items | 60/min |
| `/stats` | GET | Dashboard statistics | 120/min |
| `/clear-cache` | POST | Clear all caches | 10/min |
| `/admin/api-key/generate` | POST | Generate API key | 10/min |
| `/admin/api-key/revoke` | POST | Revoke API key | 10/min |

**Common Request Pattern:**
```javascript
fetch('/wp-json/rawwire/v1/content?status=pending&limit=20&offset=0', {
  headers: {
    'X-WP-Nonce': wpApiSettings.nonce
  }
})
.then(res => res.json())
.then(data => {
  // data.items, data.total, data.limit, data.offset
});
```

---

## 📈 RELEVANCE SCORING ALGORITHM

**Score Range:** 0.00 - 100.00

### Score Components (configurable via modules)

1. **Shock Factor (0-30 points)**
   - Trillion-dollar amounts: +30 pts
   - $10B+ amounts: +25 pts
   - $1B+ amounts: +20 pts
   - $500M+ amounts: +15 pts
   - Keywords: "reversal" (+25), "unprecedented" (+20), "banned" (+18), "scandal" (+15)

2. **Rarity (0-25 points)**
   - "first time ever": +20 pts
   - "never before": +18 pts
   - "only instance": +15 pts
   - "unprecedented": +20 pts

3. **Recency (0-15 points)**
   - < 6 hours: +15 pts
   - < 24 hours: +10 pts
   - < 72 hours: +5 pts
   - < 168 hours (1 week): +3 pts

4. **Authority (0-15 points)**
   - Department of Defense: +15 pts
   - EPA, Treasury, Justice: +12 pts
   - Federal courts: +12 pts
   - Executive orders: +15 pts

5. **Public Interest (0-15 points)**
   - "taxpayer money": +12 pts
   - "medicare/medicaid": +12 pts
   - "social security": +12 pts
   - "affects millions": +10 pts

6. **Category Bonuses (0-8 points)**
   - "rule" (final rule): +8 pts
   - "proposed rule": +6 pts
   - "notice": +5 pts

**Example Scoring:**
```
Title: "Treasury Issues Unprecedented $2.3 Trillion Bond Reversal"
- Shock Factor: +30 (trillion-dollar + "unprecedented" + "reversal")
- Rarity: +20 ("unprecedented")
- Recency: +15 (published 2 hours ago)
- Authority: +12 (Treasury)
- Public Interest: +12 ("taxpayer" implied in bonds)
- Category: +8 (final rule)
= 97.00 relevance score → High Priority
```

---

## 🗄️ DATABASE SCHEMA

### wp_rawwire_content
Primary content storage table.

```sql
CREATE TABLE wp_rawwire_content (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  issue_number INT,                  -- GitHub issue number (temporary)
  title VARCHAR(500) NOT NULL,       -- Item title
  url VARCHAR(500),                  -- Federal Register URL
  published_at DATETIME,             -- Publication date
  category VARCHAR(100),             -- "rule", "notice", "proposed rule"
  relevance DECIMAL(5,2),            -- Relevance score (0.00-100.00)
  status VARCHAR(50) DEFAULT 'pending',  -- pending/approved/snoozed/rejected
  notes TEXT,                        -- Abstract/content text
  source_data LONGTEXT,              -- JSON metadata
  created_at DATETIME,               -- Record creation timestamp
  updated_at DATETIME,               -- Last modification timestamp
  INDEX idx_status (status),
  INDEX idx_relevance (relevance),
  INDEX idx_published (published_at),
  INDEX idx_category (category)
);
```

### wp_rawwire_automation_log
Activity and error logging table.

```sql
CREATE TABLE wp_rawwire_automation_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  log_type VARCHAR(50),              -- "activity", "error", "api", "sync"
  severity VARCHAR(20),              -- "info", "warning", "error", "critical"
  message TEXT,                      -- Log message
  details LONGTEXT,                  -- JSON context data
  created_at DATETIME,               -- Timestamp
  INDEX idx_log_type (log_type),
  INDEX idx_severity (severity),
  INDEX idx_created_at (created_at)
);
```

---

## 🛠️ DEVELOPMENT WORKFLOW

### Current Environment
- **Dev Container:** Ubuntu 24.04.3 LTS in GitHub Codespaces
- **Git Branch:** `main` (local changes only, nothing pushed to GitHub yet)
- **Modified Files:** 22 PHP/CSS/JS files
- **New Files:** 40+ documentation, test scripts, tools

### Testing Tools
1. **seed-test-data.php**
   - Generates 50+ test items with varying relevance scores
   - Simulates Federal Register data structures
   - Tests approval workflow, filtering, search

2. **validate-code.php**
   - PHP syntax validation (all 55 files)
   - WordPress coding standards checks
   - Outputs: Valid/Invalid status per file

3. **PHPUnit Tests** (wordpress-plugins/raw-wire-dashboard/tests/)
   - `test-logger.php`: Logger functionality
   - `test-settings.php`: Settings validation
   - `test-sample.php`: Basic plugin loading

### Git Workflow (Pending)
```bash
# 1. Create feature branch
git checkout -b feat/error-monitoring-v1.0.14

# 2. Stage all changes
git add .

# 3. Commit with descriptive message
git commit -m "feat: Add dual logging + comprehensive error handling

- Enhanced RawWire_Logger with database + error_log fallback
- Added try-catch to all REST API endpoints (8/8)
- Added try-catch to Data Processor methods
- Created ERROR_MONITORING_ENHANCEMENTS.md guide
- Updated version to 1.0.14
"

# 4. Push to GitHub
git push origin feat/error-monitoring-v1.0.14

# 5. Create Pull Request
gh pr create --title "v1.0.14: Error Monitoring System" --body "See PR_DESCRIPTION.md"

# 6. Test on staging environment
# 7. Merge to main after approval
```

---

## 🎯 SHORT-TERM GOALS (Next 2 Weeks)

### Immediate (This Session)
- ✅ Complete error handling implementation (REST API + Data Processor)
- ✅ Create Comet Assistant context file
- 🔄 Test error logging system in WordPress
- 🔄 Build v1.0.14 release package (.zip)
- 🔄 Validate all functionality end-to-end

### Week 1
- [ ] Deploy v1.0.14 to staging WordPress instance
- [ ] Test data fetching (GitHub → Database → Dashboard)
- [ ] Test approval workflow (pending → approved/snoozed/rejected)
- [ ] Test error logging (trigger errors, verify dual logging)
- [ ] Test search/filter combinations (20+ scenarios)
- [ ] Performance testing (100+ items, cache behavior)

### Week 2
- [ ] User acceptance testing with stakeholders
- [ ] Fix any bugs discovered in staging
- [ ] Write user documentation (setup guide, troubleshooting)
- [ ] Prepare deployment checklist for production
- [ ] Create backup/rollback plan

---

## 🚀 LONG-TERM GOALS (Next 3-6 Months)

### Phase 1: Production Hardening (Month 1-2)
- [ ] Switch from GitHub data source → Federal Register API
- [ ] Implement retry logic + circuit breakers for API calls
- [ ] Add webhook support for real-time Federal Register updates
- [ ] Build admin dashboard error panel widget
- [ ] Implement automated daily sync (WP-Cron)
- [ ] Add email/Slack notifications for high-relevance findings

### Phase 2: Advanced Features (Month 3-4)
- [ ] Machine learning relevance model (train on approval history)
- [ ] Multi-user collaboration (assign reviewers, commenting)
- [ ] Scheduled publishing (queue approved items for future posting)
- [ ] Social media integration (auto-post to Twitter/Facebook/LinkedIn)
- [ ] Browser extension (highlight high-relevance items on FederalRegister.gov)
- [ ] Mobile app (approve content on-the-go)

### Phase 3: Scaling & Monetization (Month 5-6)
- [ ] Multi-site support (WordPress Multisite compatible)
- [ ] White-label options for agencies/organizations
- [ ] Premium modules marketplace (custom scoring algorithms)
- [ ] API access for third-party integrations
- [ ] Analytics dashboard (trending topics, approval rates, viral predictions)
- [ ] SaaS offering (hosted version for non-WordPress users)

---

## 📚 KEY DOCUMENTATION FILES

### User Documentation
- **PLUGIN_QUICKSTART.md**: 5-minute setup guide
- **REST_API_GUIDE.md**: API endpoint reference
- **DASHBOARD_SPEC.md**: Feature specifications

### Developer Documentation
- **PLUGIN_ARCHITECTURE.md**: System design overview
- **DATA_FLOW.md**: Data pipeline walkthrough
- **API_DOCUMENTATION.md**: Internal API reference
- **COPILOT_INSTRUCTIONS.md**: AI assistant guidance

### Testing & Deployment
- **TESTING_GUIDE.md**: Test scenarios + validation
- **ERROR_MONITORING_ENHANCEMENTS.md**: Error handling implementation guide
- **DEPLOYMENT_SUMMARY_v1.0.09.md**: Previous deployment notes
- **PRODUCTION_VALIDATION.md**: Pre-deployment checklist

### Configuration
- **templates/raw-wire-default.json**: Default module configuration
- **includes/db/schema.php**: Database schema definitions

---

## ⚡ QUICK TROUBLESHOOTING

### Common Issues & Solutions

**Issue:** Dashboard shows "No content found"  
**Solution:** 
1. Check `wp_rawwire_content` table has data
2. Run `seed-test-data.php` to generate test data
3. Verify REST API endpoint: `/wp-json/rawwire/v1/content`
4. Check browser console for JavaScript errors

**Issue:** Relevance scores are all 0.00  
**Solution:**
1. Verify module loaded: Check `RawWire_Module_Core::get_active_module()`
2. Check `templates/raw-wire-default.json` exists and is valid JSON
3. Test scoring: Call `RawWire_Data_Processor::calculate_relevance_score()` with sample data
4. Check error logs for scoring failures

**Issue:** Errors not appearing in error_log  
**Solution:**
1. Enable WordPress debugging in `wp-config.php`:
   ```php
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   define('WP_DEBUG_DISPLAY', false);
   ```
2. Check file permissions on `wp-content/debug.log`
3. Verify `RawWire_Logger::log_activity()` called with 'error' or 'critical' severity
4. Test database logging: Check `wp_rawwire_automation_log` table

**Issue:** Rate limit errors (429 Too Many Requests)  
**Solution:**
1. Adjust rate limits in `includes/api/class-rest-api-controller.php`
2. Clear rate limit transients: `DELETE FROM wp_options WHERE option_name LIKE '_transient_rawwire_%'`
3. Wait 60 seconds and retry
4. Consider implementing per-user rate limiting instead of global

**Issue:** Cache not clearing after data fetch  
**Solution:**
1. Manually clear via dashboard: Click "Clear Cache" button
2. Use REST endpoint: `POST /wp-json/rawwire/v1/clear-cache`
3. Check `RawWire_Cache_Manager::clear_all()` implementation
4. Verify transient deletion in database

---

## 🔒 SECURITY CONSIDERATIONS

### Implemented Protections
1. **Input Validation**
   - All user inputs sanitized (`sanitize_text_field`, `esc_url_raw`)
   - REST API parameters type-checked
   - SQL queries use `$wpdb->prepare()`

2. **Authentication & Authorization**
   - REST endpoints require WordPress nonce verification
   - Admin pages check `manage_options` capability
   - API keys stored as hashed values (future feature)

3. **Output Escaping**
   - All HTML output escaped (`esc_html`, `esc_attr`, `wp_kses_post`)
   - JSON responses validated before rendering
   - Custom CSS sanitized with `wp_strip_all_tags()`

4. **Rate Limiting**
   - Per-endpoint rate limits (10-120 requests/minute)
   - IP-based tracking via transients
   - Exponential backoff on repeated violations

5. **Error Handling**
   - No sensitive data in error messages
   - Stack traces only logged, never displayed to users
   - Database errors sanitized before logging

### Remaining Security TODOs
- [ ] Implement Content Security Policy (CSP) headers
- [ ] Add CSRF protection to admin forms
- [ ] Encrypt sensitive configuration values at rest
- [ ] Implement audit logging for admin actions
- [ ] Add two-factor authentication for API key generation

---

## 📞 WHEN TO USE THIS CONTEXT

### Share with Comet Assistant when:
1. **Starting WordPress admin work**
   - Copy entire context into Comet prompt
   - Comet will understand plugin structure, data flow, API endpoints

2. **Debugging errors in production**
   - Reference "Quick Troubleshooting" section
   - Share relevant error logs with context for diagnosis

3. **Making configuration changes**
   - Reference "Module Architecture" section
   - Understand impact of parameter changes on scoring

4. **Working on frontend (dashboard.js/css)**
   - Reference "REST API Endpoints" section
   - Understand data structures and response formats

5. **Database queries or schema changes**
   - Reference "Database Schema" section
   - Understand relationships between tables

### Don't share when:
- Working on completely unrelated WordPress projects
- General WordPress questions not specific to RawWire
- Non-technical discussions (billing, project management, etc.)

---

## 🤝 COLLABORATION WITH COMET ASSISTANT

### Best Practices
1. **Provide focused context**: If working on specific feature, highlight that section
2. **Include error logs**: Share actual error messages from WordPress debug.log
3. **Describe environment**: Mention if testing locally, staging, or production
4. **State your goal**: "I'm trying to..." helps Comet understand intent
5. **Mention user role**: Admin user vs. end-user perspective changes recommendations

### Example Prompts for Comet
```
"I'm in the RawWire Dashboard WordPress admin (see context below). 
The relevance scores are not calculating correctly. All items show 0.00. 
Here's what I see in the dashboard: [screenshot]
And here's an error from debug.log: [error message]

[PASTE COMET_ASSISTANT_CONTEXT.md here]

Can you help me debug why scoring isn't working?"
```

```
"Using RawWire Dashboard (context below), I need to change the module 
configuration to increase the weight of 'taxpayer money' keyword from 
12 points to 20 points. Where do I make this change in WordPress?

[PASTE COMET_ASSISTANT_CONTEXT.md here]

Walk me through the steps."
```

---

## 📊 PROJECT METRICS (Current State)

- **Lines of PHP Code:** ~8,500
- **Lines of JavaScript:** ~1,200
- **Lines of CSS:** ~800
- **Database Tables:** 2 (content, logs)
- **REST Endpoints:** 8
- **Configurable Parameters:** 45+
- **Test Files:** 3 PHPUnit tests + 2 validation scripts
- **Documentation Files:** 20+
- **Version:** 1.0.14 (in development)
- **WordPress Compatibility:** 5.8 - 6.5+
- **PHP Compatibility:** 7.4 - 8.3
- **Last Audit Date:** January 6, 2026

---

## 🎓 LEARNING RESOURCES

If you're new to the codebase, read in this order:

1. **PLUGIN_QUICKSTART.md** - Get plugin running in 5 minutes
2. **DASHBOARD_SPEC.md** - Understand what the plugin does
3. **PLUGIN_ARCHITECTURE.md** - Understand how it works
4. **DATA_FLOW.md** - Follow data from API to dashboard
5. **REST_API_GUIDE.md** - Learn endpoint contracts
6. **This file** - Full context for AI assistance

---

## 🏆 SUCCESS CRITERIA

### Definition of "Done" for v1.0.14
- [ ] All error handling implemented (REST API + Data Processor + Fetcher + Cache)
- [ ] Error logging verified in both database and error_log
- [ ] All PHPUnit tests pass
- [ ] Manual testing completed (50+ test scenarios)
- [ ] No PHP errors/warnings in debug.log during normal operation
- [ ] Performance acceptable (< 2s page load, < 500ms API response)
- [ ] Documentation updated (README, CHANGELOG)
- [ ] Deployment package created (.zip file)
- [ ] Code reviewed by stakeholder
- [ ] Deployed to staging and tested for 24 hours

### Definition of "Production Ready" (Future)
- [ ] 99.9% uptime over 7-day period
- [ ] Zero critical errors in production logs
- [ ] User acceptance testing with 5+ users
- [ ] Load testing with 1,000+ concurrent requests
- [ ] Security audit completed
- [ ] Backup/restore procedures tested
- [ ] Monitoring/alerting configured (Sentry, New Relic, etc.)
- [ ] Rollback plan documented and tested

---

## 💡 DESIGN PHILOSOPHY

### Core Principles
1. **User Intent Over Clicks**: Anticipate what users want, minimize steps
2. **Fail Gracefully**: Every error should have a recovery path
3. **Performance by Default**: Cache aggressively, fetch lazily
4. **Accessibility First**: WCAG 2.1 AA is a requirement, not a nice-to-have
5. **Modularity**: Every feature should be pluggable, configurable, testable
6. **Data Integrity**: Log everything, audit everything, trust nothing

### Code Style Guidelines
- **PHP:** WordPress Coding Standards (WPCS)
- **JavaScript:** ES6+, no jQuery (vanilla JS only)
- **CSS:** BEM naming convention, mobile-first responsive
- **Comments:** Explain "why", not "what" (code should be self-documenting)
- **Functions:** Single responsibility, max 50 lines
- **Classes:** Max 500 lines, split into focused concerns

---

## 🙏 ACKNOWLEDGMENTS

This project is built with:
- WordPress REST API
- PHP's Exception handling
- MySQL's JSON column support
- Modern JavaScript (ES6+)
- CSS Grid and Flexbox
- GitHub API (temporary data source)
- Federal Register API (future integration)

Special thanks to:
- WordPress core team for an extensible platform
- Codespaces for dev container support
- GitHub Copilot & Comet Assistant for AI-assisted development

---

## 📝 VERSION HISTORY

- **v1.0.14** (In Development): Error monitoring system, dual logging, comprehensive try-catch
- **v1.0.13** (Dec 2025): Module architecture, WCAG 2.1 AA compliance, REST API consolidation
- **v1.0.09** (Nov 2025): Initial deployment, basic dashboard, GitHub data source
- **v1.0.0** (Oct 2025): Prototype, proof of concept

---

## 📬 GETTING HELP

### When Working in WordPress Admin with Comet Assistant:
1. Copy this entire context file into your Comet prompt
2. Describe what you're trying to do
3. Include any error messages or screenshots
4. Mention which admin page you're on (Dashboard, Settings, etc.)

### When Working in VS Code with GitHub Copilot:
1. Open relevant PHP/JS/CSS file
2. Reference specific functions/classes in your question
3. Use inline comments to guide code generation
4. Run `validate-code.php` after changes

### When Debugging in Dev Container:
1. Check `wp-content/debug.log` for errors
2. Query `wp_rawwire_automation_log` table for activity logs
3. Use browser DevTools → Network tab for REST API responses
4. Run `seed-test-data.php` to reset test environment

---

**END OF CONTEXT BRIEF**

*This document is automatically updated with each release. Last sync: v1.0.14 development (January 6, 2026)*
