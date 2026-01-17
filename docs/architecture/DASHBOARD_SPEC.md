# Raw-Wire Dashboard - Production Specification

## Executive Summary
Complete functional dashboard for monitoring automation results, approving content, and serving as data source for AI content generation and social media posting.

## Core Requirements

### 1. Data Display & Monitoring
- Real-time feed of Federal Register findings
- Automation execution logs
- Data quality metrics
- Search/filter capabilities (keyword, date, category, relevance)
- Pagination for large datasets

### 2. Content Approval Workflow
- Status indicators: Pending, Approved, Rejected, Published
- Bulk approval/rejection
- Individual item review with preview
- Approval history tracking
- Role-based access control

### 3. API for AI Models
- RESTful endpoints for approved content
- JSON format with metadata
- Filtering by approval status
- Rate limiting
- API authentication

### 4. Automation Monitoring
- GitHub sync status
- Last fetch timestamp
- Error logging
- Success/failure metrics
- Alert system for failures

### 5. Modular Search System
- Base search module class
- Pluggable filters:
  - Keyword search
  - Date range
  - Category
  - Relevance score
  - Custom filters (extensible)

## Technical Architecture

### File Structure
```
raw-wire-dashboard/
├── raw-wire-dashboard.php          # Main plugin file
├── includes/
│   ├── class-dashboard-core.php
│   ├── class-github-fetcher.php
│   ├── class-data-processor.php
│   ├── class-cache-manager.php
│   ├── class-approval-workflow.php
│   ├── search-modules/
│   │   ├── class-search-module-base.php
│   │   ├── class-keyword-filter.php
│   │   ├── class-date-filter.php
│   │   ├── class-category-filter.php
│   │   └── class-relevance-scorer.php
│   └── api/
│       ├── class-rest-api-controller.php
│       └── class-api-auth.php
├── assets/
│   ├── js/
│   │   ├── dashboard.js
│   │   ├── approval-workflow.js
│   │   └── real-time-updates.js
│   └── css/
│       ├── dashboard.css
│       └── approval-workflow.css
├── templates/
│   ├── dashboard-main.php
│   ├── content-review.php
│   ├── automation-logs.php
│   └── system-status.php
└── docs/
    ├── API.md
    └── COPILOT_INSTRUCTIONS.md
```

### Database Schema
```sql
-- Content items table
CREATE TABLE wp_rawwire_content (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    title TEXT,
    content LONGTEXT,
    source_url VARCHAR(500),
    document_number VARCHAR(100),
    publication_date DATE,
    agency VARCHAR(200),
    category VARCHAR(100),
    relevance_score DECIMAL(5,2),
    approval_status ENUM('pending', 'approved', 'rejected', 'published'),
    approved_by BIGINT,
    approved_at DATETIME,
    created_at DATETIME,
    updated_at DATETIME,
    metadata JSON,
    INDEX idx_status (approval_status),
    INDEX idx_date (publication_date),
    INDEX idx_relevance (relevance_score)
);

-- Automation logs table
CREATE TABLE wp_rawwire_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    log_type ENUM('fetch', 'process', 'error', 'api_call'),
    message TEXT,
    details JSON,
    severity ENUM('info', 'warning', 'error', 'critical'),
    created_at DATETIME,
    INDEX idx_type (log_type),
    INDEX idx_severity (severity),
    INDEX idx_date (created_at)
);

-- API keys table
CREATE TABLE wp_rawwire_api_keys (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    key_hash VARCHAR(64),
    name VARCHAR(100),
    permissions JSON,
    last_used DATETIME,
    created_at DATETIME,
    expires_at DATETIME,
    is_active BOOLEAN
);
```

## Implementation Details

### GitHub Data Fetching
```php
class GitHub_Fetcher {
    private $api_url = 'https://api.github.com/repos/raw-wire-dao-llc/raw-wire-core';
    private $token;
    
    public function fetch_latest() {
        // Fetch from GitHub with error handling
        // Parse Federal Register data
        // Store in database
        // Return processed data
    }
    
    public function get_sync_status() {
        // Return last sync time, status, errors
    }
}
```

### Modular Search System
```php
abstract class Search_Module_Base {
    abstract public function apply_filter($query, $params);
    abstract public function validate_params($params);
    public function get_priority() { return 10; }
}

class Keyword_Filter extends Search_Module_Base {
    public function apply_filter($query, $params) {
        if (isset($params['keyword'])) {
            $query->where('title LIKE', "%{$params['keyword']}%");
        }
        return $query;
    }
}
```

### Approval Workflow
```php
class Approval_Workflow {
    public function approve_content($content_id, $user_id) {
        // Update status to approved
        // Log approval action
        // Trigger notifications
        // Make available to API
    }
    
    public function bulk_approve($content_ids, $user_id) {
        // Batch approve multiple items
    }
    
    public function get_pending_count() {
        // Return count of pending items
    }
}
```

### REST API Endpoints
```
GET  /wp-json/rawwire/v1/content          # List approved content
GET  /wp-json/rawwire/v1/content/{id}     # Get single item
POST /wp-json/rawwire/v1/content/approve  # Approve content
GET  /wp-json/rawwire/v1/stats            # Get statistics
GET  /wp-json/rawwire/v1/logs             # Get automation logs
```

### Dashboard UI Components

#### Main Dashboard View
- System status cards (sync status, pending items, errors)
- Recent activity feed
- Quick actions (Fetch New Data, View Logs)
- Statistics charts

#### Content Review Screen
- Data table with:
  - Title
  - Source
  - Date
  - Category
  - Relevance Score
  - Status
  - Actions (Approve/Reject/View)
- Bulk selection
- Advanced filters sidebar
- Preview modal

#### Automation Logs
- Filterable log viewer
- Severity indicators
- Export functionality
- Real-time updates via AJAX

#### System Status
- GitHub sync indicator
- API health check
- Database statistics
- Performance metrics

## Integration Points

### For AI Content Models
```javascript
// Example API call for content generation
fetch('/wp-json/rawwire/v1/content?status=approved&limit=10', {
  headers: {
    'Authorization': 'Bearer YOUR_API_KEY',
    'Content-Type': 'application/json'
  }
})
.then(res => res.json())
.then(data => {
  // Process approved content
  // Generate social media posts
  // Create blog articles
});
```

### For Social Media Automation
- Zapier/n8n webhooks
- Direct API integration
- Content formatting helpers
- Image generation triggers

## Security Considerations
- Nonce verification for all AJAX calls
- Capability checks (manage_options)
- Input sanitization
- Output escaping
- Rate limiting on API
- API key encryption
- SQL injection prevention
- XSS protection

## Performance Optimization
- Database indexing
- Transient caching
- Lazy loading for large datasets
- AJAX pagination
- Minified assets
- CDN for static files

## Testing Requirements
- Unit tests for all classes
- Integration tests for API endpoints
- UI/UX testing
- Load testing
- Security auditing

## Deployment Checklist
1. Database tables created
2. File permissions set
3. API keys generated
4. GitHub token configured
5. Cache directories writable
6. Cron jobs scheduled
7. SSL certificate active
8. Error logging enabled

## GitHub Copilot Instructions
When implementing this system:
1. Follow WordPress coding standards
2. Use prepared statements for all database queries
3. Implement comprehensive error handling
4. Add inline documentation for all functions
5. Create modular, reusable components
6. Ensure mobile responsiveness
7. Add loading states for async operations
8. Implement progressive enhancement
9. Use semantic HTML
10. Follow accessibility guidelines (WCAG 2.1)

## Success Metrics
- Dashboard loads in < 2 seconds
- API response time < 500ms
- 99.9% uptime
- Zero data loss
- Intuitive UI (< 5min learning curve)
- Full mobile compatibility

