# Strategic Logging Implementation Plan
**Version:** 1.0.15  
**Date:** January 6, 2026  
**Status:** In Progress

## Overview

This document outlines the strategic placement of logging statements throughout the RawWire Dashboard plugin to enable comprehensive debugging, monitoring, and error tracking in production.

## Logging Principles

### 1. Strategic Placement
- Log at **decision points**, not every line
- Log **entry/exit** of major operations
- Log **state changes** (status updates, approvals, etc.)
- Log **external interactions** (API calls, database queries)
- Log **errors and warnings** with full context

### 2. Appropriate Severity Levels

```php
// Debug: Development/troubleshooting only (WP_DEBUG required)
RawWire_Logger::debug('Cache hit for key: ' . $key, 'cache', $details);

// Info: Normal operations, successful completions
RawWire_Logger::info('User approved content', 'approval', $details);

// Warning: Recoverable issues, potential problems
RawWire_Logger::warning('API rate limit approaching', 'api_call', $details);

// Error: Operation failures, exceptions
RawWire_Logger::log_error('Database query failed', $details, 'error');

// Critical: System-wide failures (always logged to error_log)
RawWire_Logger::critical('Out of memory', 'error', $details);
```

### 3. Rich Context

Always include relevant context:

```php
RawWire_Logger::info('Content approved', 'approval', array(
    'content_id' => $content_id,
    'user_id' => $user_id,
    'previous_status' => $old_status,
    'notes' => $notes,
    'timestamp' => time()
));
```

## Files Requiring Strategic Logging

### Priority 1: Critical Business Logic (HIGH)

#### class-approval-workflow.php (NO LOGGING CURRENTLY!)
**Lines to add logging:**
- Line ~28: Log approval attempt start
- Line ~54: Log already approved scenario
- Line ~66: Log successful approval
- Line ~73: Log approval history recorded
- Line ~95: Log rejection start
- Line ~118: Log successful rejection
- Line ~143: Log bulk approval summary
- Line ~175: Log snooze operation

**Example Implementation:**
```php
// Line 28 - After capability check
RawWire_Logger::info('Approval requested', 'approval', array(
    'content_id' => $content_id,
    'user_id' => $user_id
));

// Line 54 - Already approved
RawWire_Logger::warning('Duplicate approval attempt', 'approval', array(
    'content_id' => $content_id,
    'current_status' => 'approved'
));

// Line 66 - Success
RawWire_Logger::info('Content approved', 'approval', array(
    'content_id' => $content_id,
    'user_id' => $user_id,
    'notes' => $notes
));
```

#### rest-api.php (NEEDS MORE LOGGING)
**Lines to add logging:**
- Each REST endpoint entry: Log request with parameters
- Before database operations: Log query details
- After operations: Log success/failure with results
- Error conditions: Log full error context

### Priority 2: Data Flow (MEDIUM)

#### class-data-processor.php (HAS SOME LOGGING, NEEDS MORE)
**Additional logging needed:**
- Line ~50: Log processor initialization
- Line ~100: Log validation results
- Line ~200: Log deduplication checks
- Line ~300: Log enrichment steps

#### class-github-fetcher.php (HAS GOOD LOGGING, MINOR ADDITIONS)
**Additional logging needed:**
- Line ~85: Log cache check result
- Line ~130: Log rate limit status
- Line ~180: Log pagination handling

### Priority 3: Initialization & Configuration (MEDIUM)

#### class-init-controller.php (HAS MINIMAL LOGGING)
**Additional logging needed:**
- Each phase start/end
- Module loading success/failure
- Migration results
- Configuration validation

#### class-settings.php (NO LOGGING)
**Lines to add logging:**
- Settings save operations
- Settings validation failures
- Default value fallbacks

### Priority 4: User Interface (LOW)

#### class-admin.php (NO LOGGING)
**Lines to add logging:**
- Dashboard page load
- Admin actions
- Menu registration

#### class-activity-logs.php (HAS GOOD LOGGING)
**Status:** Adequate logging already in place

## Implementation Checklist

### Phase 1: Critical Business Logic (Items 1-3)

- [ ] **1. class-approval-workflow.php**
  - [ ] Add logging to approve_content()
  - [ ] Add logging to reject_content()
  - [ ] Add logging to bulk_approve()
  - [ ] Add logging to snooze_content()
  - [ ] Test all approval scenarios
  - [ ] Verify logs appear in database
  - [ ] Review checks (8-point)

- [ ] **2. rest-api.php**
  - [ ] Add logging to /fetch-data endpoint
  - [ ] Add logging to /approve endpoint
  - [ ] Add logging to /reject endpoint
  - [ ] Add logging to /snooze endpoint
  - [ ] Add logging to /clear-cache endpoint
  - [ ] Test all REST endpoints
  - [ ] Verify nonce in logs
  - [ ] Review checks (8-point)

- [ ] **3. class-cache-manager.php**
  - [ ] Add logging to get()
  - [ ] Add logging to set()
  - [ ] Add logging to delete()
  - [ ] Add logging to flush()
  - [ ] Test cache operations
  - [ ] Verify debug logs only when WP_DEBUG
  - [ ] Review checks (8-point)

### Phase 2: Data Flow (Items 4-6)

- [ ] **4. class-data-processor.php (Enhancement)**
  - [ ] Add entry/exit logging for process_item()
  - [ ] Add validation step logging
  - [ ] Add enrichment step logging
  - [ ] Test with various item types
  - [ ] Review checks (8-point)

- [ ] **5. class-github-fetcher.php (Enhancement)**
  - [ ] Add cache hit/miss logging
  - [ ] Add rate limit logging
  - [ ] Add pagination logging
  - [ ] Test with various scenarios
  - [ ] Review checks (8-point)

- [ ] **6. class-plugin-manager.php**
  - [ ] Add plugin load logging
  - [ ] Add plugin activation logging
  - [ ] Add plugin error logging
  - [ ] Test plugin lifecycle
  - [ ] Review checks (8-point)

### Phase 3: Initialization (Items 7-9)

- [ ] **7. class-init-controller.php (Enhancement)**
  - [ ] Add phase start/end logging
  - [ ] Add module loading logging
  - [ ] Add migration logging
  - [ ] Test initialization sequence
  - [ ] Review checks (8-point)

- [ ] **8. class-settings.php**
  - [ ] Add settings save logging
  - [ ] Add validation failure logging
  - [ ] Add default fallback logging
  - [ ] Test settings operations
  - [ ] Review checks (8-point)

- [ ] **9. Database migrations**
  - [ ] Add migration start logging
  - [ ] Add migration success logging
  - [ ] Add migration rollback logging
  - [ ] Test migrations
  - [ ] Review checks (8-point)

### Phase 4: User Interface (Items 10-11)

- [ ] **10. class-admin.php**
  - [ ] Add dashboard load logging
  - [ ] Add menu registration logging
  - [ ] Add admin action logging
  - [ ] Test admin interface
  - [ ] Review checks (8-point)

- [ ] **11. class-dashboard-core.php**
  - [ ] Add core initialization logging
  - [ ] Add hook registration logging
  - [ ] Add error boundary logging
  - [ ] Test dashboard operations
  - [ ] Review checks (8-point)

## Testing Plan

### Unit Tests

Create test file for each enhanced class:

```bash
tests/
├── test-approval-workflow-logging.php
├── test-rest-api-logging.php
├── test-cache-manager-logging.php
├── test-data-processor-logging.php
├── test-github-fetcher-logging.php
└── test-init-controller-logging.php
```

### Integration Tests

Verify logging in realistic scenarios:

1. **Approval Flow Test:**
   - Fetch content from GitHub
   - Process and store content
   - Approve content
   - Verify all steps logged

2. **Error Scenario Test:**
   - Trigger API failure
   - Trigger database failure
   - Verify errors logged with full context

3. **Performance Test:**
   - Process 100 items
   - Verify logging doesn't impact performance
   - Check log rotation working

### Manual Testing

1. Enable WP_DEBUG
2. Perform all major operations
3. Check database for logs
4. Check error_log for critical errors
5. Verify log details modal shows full context

## Success Criteria

### Quantitative
- [ ] All 19 class files reviewed
- [ ] At least 50 strategic log statements added
- [ ] 100% of critical operations logged
- [ ] 0 sensitive data logged (passwords, API keys)
- [ ] < 5ms overhead per log statement

### Qualitative
- [ ] Logs provide actionable information
- [ ] Logs include sufficient context for debugging
- [ ] Log severity levels appropriate
- [ ] Log messages clear and concise
- [ ] Log details structured (JSON)

## Documentation Updates

After implementation:

1. **Create STRATEGIC_LOGGING_GUIDE.md:**
   - How to add logging to new features
   - Best practices and examples
   - Common pitfalls to avoid

2. **Update LOGGER_DOCUMENTATION.md:**
   - Add section on strategic placement
   - Add real-world examples from codebase
   - Add troubleshooting guide

3. **Update PLUGIN_ARCHITECTURE.md:**
   - Document logging architecture
   - Document log flow diagram
   - Document log storage strategy

## Timeline

- **Phase 1 (Critical):** 2-3 hours
- **Phase 2 (Data Flow):** 2 hours
- **Phase 3 (Initialization):** 1-2 hours
- **Phase 4 (UI):** 1 hour
- **Testing:** 2 hours
- **Documentation:** 1 hour

**Total:** 9-11 hours

## Next Steps

1. Start with Phase 1, Item 1 (class-approval-workflow.php)
2. Complete 8-point review for each item
3. Create test script for each enhanced file
4. Update this document with progress
5. Create final summary documentation

---

**Current Status:** Planning complete, ready to implement Phase 1, Item 1
