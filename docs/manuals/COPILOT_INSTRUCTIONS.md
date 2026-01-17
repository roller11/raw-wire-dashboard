# GitHub Copilot Implementation Guide
## Raw-Wire Dashboard System

## 🎯 Project Overview
You are implementing a production-ready WordPress dashboard plugin for Raw-Wire, a news aggregation platform that:
1. Fetches Federal Register data from GitHub
2. Allows human review and approval of content
3. Provides REST API for AI models to generate posts
4. Integrates with social media automation

## 📁 Current Codebase Structure
```
/home/rawwhbss/raw-wire-core/wordpress-plugins/raw-wire-dashboard/
├── raw-wire-dashboard.php (main plugin - needs enhancement)
├── dashboard.css
├── dashboard.js  
├── dashboard-template.php
├── rest-api.php
├── DASHBOARD_SPEC.md (COMPLETE SPECIFICATION - READ THIS FIRST)
└── COPILOT_INSTRUCTIONS.md (this file)
```

## 🚀 Implementation Priority

### Phase 1: Core Infrastructure (START HERE)
1. **Create `includes/` directory structure**
2. **Implement database schema** (see DASHBOARD_SPEC.md)
3. **Build GitHub data fetcher class**
4. **Create data processor for Federal Register content**

### Phase 2: Modular Search System
1. **Create search-modules/ directory**
2. **Implement Search_Module_Base abstract class**
3. **Build filter modules** (keyword, date, category, relevance)
4. **Add filter chain manager**

### Phase 3: Approval Workflow
1. **Implement Approval_Workflow class**
2. **Add database methods for status updates**
3. **Create bulk approval functions**
4. **Build approval history tracking**

### Phase 4: REST API
1. **Create api/ directory**
2. **Implement REST_API_Controller**
3. **Add authentication system**
4. **Build rate limiting**

### Phase 5: Dashboard UI
1. **Enhance templates/ with new views**
2. **Build real-time JavaScript components**
3. **Add AJAX handlers**
4. **Implement responsive CSS**

## 💻 Coding Standards

### WordPress Conventions
```php
// Use WordPress naming conventions
function rawwire_function_name() { }
class RawWire_Class_Name { }

// Always escape output
echo esc_html($variable);
echo esc_url($url);
echo esc_attr($attribute);

// Use wp_kses for HTML
echo wp_kses_post($html_content);

// Sanitize input
$clean = sanitize_text_field($_POST['field']);
$email = sanitize_email($_POST['email']);

// Use nonces for security
wp_nonce_field('rawwire_action', 'rawwire_nonce');
if (!wp_verify_nonce($_POST['rawwire_nonce'], 'rawwire_action')) {
    wp_die('Security check failed');
}
```

### Database Queries
```php
// ALWAYS use prepared statements
global $wpdb;
$results = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}rawwire_content WHERE status = %s",
        $status
    )
);
```

### Error Handling
```php
try {
    // Operation
    $this->log_activity('Operation succeeded', 'info');
} catch (Exception $e) {
    $this->log_error($e->getMessage());
    return new WP_Error('operation_failed', $e->getMessage());
}
```

## 🔐 Security Checklist
- [ ] All user input sanitized
- [ ] All output escaped
- [ ] Nonces verified on all forms
- [ ] Capability checks (current_user_can('manage_options'))
- [ ] Prepared statements for all SQL
- [ ] API authentication implemented
- [ ] Rate limiting enabled
- [ ] HTTPS enforced

## 📊 Key Classes to Implement

### 1. GitHub_Fetcher
```php
class RawWire_GitHub_Fetcher {
    private $api_url;
    private $token;
    
    public function fetch_latest_data() {
        // Use wp_remote_get() with authentication
        // Parse JSON response
        // Validate data structure
        // Store in database
        // Return WP_Error on failure
    }
}
```

### 2. Approval_Workflow
```php
class RawWire_Approval_Workflow {
    public function approve_content($content_id, $user_id) {
        // Validate permissions
        // Update status
        // Log action
        // Trigger hooks
    }
    
    public function bulk_approve($content_ids, $user_id) {
        // Batch processing
        // Transaction safety
    }
}
```

### 3. Search_Module_Base
```php
abstract class RawWire_Search_Module_Base {
    abstract public function apply_filter($query, $params);
    abstract public function validate_params($params);
    public function get_priority() { return 10; }
}
```

## 🎨 UI Components Needed

### Dashboard Cards
```html
<div class="rawwire-status-card">
    <h3>System Status</h3>
    <div class="status-indicator active">●</div>
    <p>Last Sync: <span id="last-sync-time"></span></p>
</div>
```

### Data Table
```html
<table class="rawwire-content-table">
    <thead>
        <tr>
            <th><input type="checkbox" id="select-all"></th>
            <th>Title</th>
            <th>Date</th>
            <th>Category</th>
            <th>Relevance</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody id="content-tbody">
        <!-- Populated via AJAX -->
    </tbody>
</table>
```

## 🔌 API Endpoints to Create

```
GET  /wp-json/rawwire/v1/content
     ?status=approved&limit=10&offset=0&category=regulation
     
POST /wp-json/rawwire/v1/content/approve
     Body: {"content_ids": [1,2,3], "notes": "..."}
     
GET  /wp-json/rawwire/v1/stats
     Returns: {"total": 100, "pending": 25, "approved": 75}
```

## 🧪 Testing Requirements

### Unit Tests (PHPUnit)
```php
class Test_GitHub_Fetcher extends WP_UnitTestCase {
    public function test_fetch_validates_response() {
        $fetcher = new RawWire_GitHub_Fetcher();
        $result = $fetcher->fetch_latest_data();
        $this->assertNotWPError($result);
    }
}
```

### JavaScript Tests (Jest)
```javascript
describe('Dashboard', () => {
    test('loads content on init', async () => {
        const dashboard = new RawWireDashboard();
        await dashboard.init();
        expect(dashboard.content.length).toBeGreaterThan(0);
    });
});
```

## 📝 Documentation Standards

### PHP DocBlocks
```php
/**
 * Fetches latest Federal Register data from GitHub
 * 
 * @since 1.0.0
 * @param bool $force_refresh Force cache refresh
 * @return array|WP_Error Array of content items or error
 */
public function fetch_latest_data($force_refresh = false) {
```

### JavaScript JSDoc
```javascript
/**
 * Initializes the dashboard with real-time updates
 * @param {Object} config - Configuration options
 * @param {number} config.refreshInterval - Refresh interval in ms
 * @returns {Promise<void>}
 */
async function initDashboard(config) {
```

## 🎯 Current Implementation Status

### ✅ Complete
- Basic plugin structure
- Initial dashboard template
- REST API skeleton
- Comprehensive specification (DASHBOARD_SPEC.md)

### 🚧 Needs Implementation
- Database schema and tables
- GitHub data fetching
- Modular search system
- Approval workflow
- Real-time UI updates
- API authentication
- Error logging system

### 🔄 Needs Enhancement
- Current dashboard.js (add real-time features)
- Current dashboard.css (make responsive)
- Current rest-api.php (add authentication)
- Main plugin file (add hooks and filters)

## 🛠️ Development Commands

```bash
# Navigate to plugin directory
cd /home/rawwhbss/raw-wire-core/wordpress-plugins/raw-wire-dashboard

# Check WordPress installation
wp core version

# Activate plugin
wp plugin activate raw-wire-dashboard

# View error logs
tail -f /home/rawwhbss/public_html/home/wp-content/debug.log

# Database operations
wp db query "SELECT COUNT(*) FROM wp_rawwire_content"
```

## 🎨 Design Guidelines

### Colors (WordPress Admin)
- Primary: #0073aa (WordPress blue)
- Success: #46b450 (green)
- Warning: #ffb900 (amber)
- Error: #dc3232 (red)
- Pending: #826eb4 (purple)

### Typography
- Font: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto
- Headings: 600 weight
- Body: 400 weight
- Code: "Monaco", "Menlo", monospace

## 🚀 Performance Targets

- Dashboard load: < 2 seconds
- API response: < 500ms
- Database queries: < 100ms
- AJAX requests: < 300ms
- Page size: < 500KB

## 🔗 Integration Points

### GitHub Repository
```
https://github.com/raw-wire-dao-llc/raw-wire-core
Token stored in: wp_options as 'rawwire_github_token'
```

### WordPress Hooks
```php
// Actions
do_action('rawwire_content_approved', $content_id, $user_id);
do_action('rawwire_sync_complete', $result);

// Filters  
apply_filters('rawwire_search_results', $results, $params);
apply_filters('rawwire_relevance_score', $score, $content);
```

## 📦 Dependencies

### Required
- WordPress 6.0+
- PHP 7.4+
- MySQL 5.7+

### Optional
- Redis (for caching)
- Elasticsearch (for advanced search)

## 🎯 Next Steps for Implementation

1. **READ DASHBOARD_SPEC.md completely**
2. **Create database tables** using WordPress dbDelta
3. **Implement GitHub_Fetcher class**
4. **Build modular search system**
5. **Create approval workflow**
6. **Enhance UI with real-time updates**
7. **Add comprehensive error handling**
8. **Write unit tests**
9. **Optimize performance**
10. **Deploy to production**

## 💡 Copilot Tips

When you ask Copilot for help, reference:
- "According to DASHBOARD_SPEC.md..."
- "Following WordPress coding standards..."
- "Implementing the modular search system..."
- "Building the approval workflow as specified..."

Copilot will generate code that:
- Follows WordPress conventions
- Implements security best practices
- Uses proper error handling
- Includes comprehensive documentation
- Matches the architectural patterns in DASHBOARD_SPEC.md

## 📞 Support

For questions about:
- **Architecture**: See DASHBOARD_SPEC.md
- **WordPress Standards**: https://developer.wordpress.org/coding-standards/
- **Security**: https://developer.wordpress.org/plugins/security/
- **REST API**: https://developer.wordpress.org/rest-api/

---

**Remember**: This is a production system. Every line of code should be:
- Secure by default
- Well-documented
- Error-handled
- Performance-optimized
- User-friendly

Let's build something amazing! 🚀
