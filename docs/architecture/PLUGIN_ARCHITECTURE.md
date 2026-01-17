# Raw Wire Plugin Architecture

## SaaS & Agentic Deployment System

The Raw Wire Dashboard implements a sophisticated plugin-based architecture designed for flexible SaaS and agentic AI deployments. This system allows features to be dynamically loaded, configured, and managed independently.

## Architecture Overview

### Core Components

1. **Plugin Manager** (`class-plugin-manager.php`)
   - Discovers feature plugins from multiple directories
   - Manages plugin lifecycle (load, init, activate, deactivate)
   - Handles dependency resolution
   - Provides feature flag management

2. **Feature Interface** (`interface-feature.php`)
   - Standard interface all plugins implement
   - Base class with common functionality
   - Ensures consistent API across features

3. **Initialization System** (in main plugin file)
   - 4-phase boot sequence
   - Backward compatibility with legacy code
   - Graceful degradation for missing features

## Boot Sequence

### Phase 1: Core System Load
- Essential WordPress integration files
- Plugin manager and feature interface
- Database schema and core utilities

### Phase 2: Legacy Features
- Backward-compatible traditional includes
- Search modules, logging, etc.
- No breaking changes for existing deployments

### Phase 3: Plugin System Initialization
- Discover available feature plugins
- Load enabled plugins with dependency resolution
- Register REST routes and admin UI

### Phase 4: Main Dashboard Init
- Initialize main dashboard instance
- Fire initialization hooks for external integrations

## Creating Feature Plugins

### Directory Structure

```
includes/features/
├── approval-workflow/
│   ├── plugin.php           # Main plugin file
│   ├── templates/           # UI templates (optional)
│   └── assets/              # CSS/JS (optional)
├── ai-content-generator/
│   └── plugin.php
└── social-media-publisher/
    └── plugin.php
```

### Plugin Template

```php
<?php
/**
 * Plugin Name: My Feature Name
 * Plugin Slug: my-feature-slug
 * Description: What this feature does
 * Version: 1.0.0
 * Author: Your Name
 * Category: automation|ai|content|analytics
 * Dependencies: logger, another-plugin
 * API Version: 1.0
 */

class Raw_Wire_Feature_My_Feature extends Raw_Wire_Feature_Base {
    
    public function init() {
        // Initialize feature
        // Register hooks, actions, filters
    }
    
    public function get_metadata() {
        return array(
            'name'        => 'My Feature Name',
            'description' => 'Feature description',
            'version'     => '1.0.0',
            'category'    => 'automation',
        );
    }
    
    public function is_available() {
        // Check requirements (API keys, licenses, etc.)
        return true;
    }
    
    public function register_rest_routes($server) {
        // Register REST API endpoints
        register_rest_route('rawwire/v1', '/my-feature', array(
            'methods'  => 'GET',
            'callback' => array($this, 'rest_handler'),
            'permission_callback' => '__return_true',
        ));
    }
    
    public function register_admin_ui() {
        // Add admin menu pages
        add_submenu_page(
            'rawwire-dashboard',
            'My Feature',
            'My Feature',
            'manage_options',
            'rawwire-my-feature',
            array($this, 'render_page')
        );
    }
    
    public function get_config_schema() {
        // Define configuration options
        return array(
            'api_key' => array(
                'type'    => 'text',
                'label'   => 'API Key',
                'required' => true,
            ),
        );
    }
    
    public function activate() {
        // Run when feature is enabled
    }
    
    public function deactivate() {
        // Run when feature is disabled
    }
}
```

## Feature Management

### Enabling/Disabling Features

```php
// Get plugin manager
$manager = Raw_Wire_Plugin_Manager::get_instance();

// Enable a feature
$manager->enable_plugin('my-feature-slug');

// Disable a feature
$manager->disable_plugin('my-feature-slug');

// Get loaded plugins
$loaded = $manager->get_loaded_plugins();

// Get failed plugins
$failed = $manager->get_failed_plugins();
```

### Accessing Features

```php
// Get a specific plugin instance
$manager = Raw_Wire_Plugin_Manager::get_instance();
$approval = $manager->get_plugin('approval-workflow');

if ($approval) {
    // Use plugin methods
}
```

## Plugin Discovery

The system searches for plugins in multiple directories:

1. **Core Features** (`includes/features/`)
   - Bundled features
   - Enabled by default
   - Part of standard distribution

2. **Custom Features** (`includes/custom-features/`)
   - User-developed features
   - Installation-specific
   - Not in version control

3. **Vendor Features** (`includes/vendor-features/`)
   - Third-party integrations
   - Commercial plugins
   - External marketplace

### Register Additional Directories

```php
add_filter('rawwire_plugin_directories', function($dirs) {
    $dirs['enterprise'] = '/path/to/enterprise/features/';
    return $dirs;
});
```

## Dependency Management

### Declaring Dependencies

```php
/**
 * Dependencies: logger, cache-manager, approval-workflow
 */
```

Features are loaded in dependency order. If a required dependency is missing, the plugin will not load.

### Checking Dependencies in Code

```php
public function is_available() {
    $manager = Raw_Wire_Plugin_Manager::get_instance();
    
    if (!$manager->get_plugin('required-feature')) {
        return new WP_Error(
            'missing_dependency',
            'Required feature not available'
        );
    }
    
    return true;
}
```

## Feature Categories

Organize plugins by category for SaaS packaging:

- **automation** - GitHub crawlers, schedulers, data fetchers
- **ai** - AI content generation, analysis, summarization
- **content** - Approval workflows, publishing, formatting
- **analytics** - Metrics, reporting, dashboards
- **integration** - Social media, external APIs, webhooks
- **compliance** - Audit logs, GDPR, data retention

## SaaS Deployment Strategies

### Tier-Based Feature Access

```php
// Free tier: Only core features
update_option('rawwire_enabled_features', array());

// Pro tier: Core + automation
update_option('rawwire_enabled_features', array(
    'github-crawler',
    'scheduled-fetcher',
));

// Enterprise tier: All features
update_option('rawwire_enabled_features', array(
    'github-crawler',
    'scheduled-fetcher',
    'approval-workflow',
    'ai-content-generator',
    'social-publisher',
));
```

### License Key Integration

```php
class Raw_Wire_Feature_Premium extends Raw_Wire_Feature_Base {
    
    public function is_available() {
        $license = get_option('rawwire_license_key');
        
        if (!$this->validate_license($license)) {
            return new WP_Error(
                'invalid_license',
                'Valid license required for this feature'
            );
        }
        
        return true;
    }
}
```

## REST API Extension

Features can register their own API endpoints:

```
POST   /wp-json/rawwire/v1/approvals/{id}/approve
GET    /wp-json/rawwire/v1/ai/generate-content
POST   /wp-json/rawwire/v1/social/publish
DELETE /wp-json/rawwire/v1/cache/clear
```

All endpoints follow WordPress REST API conventions with proper authentication and permissions.

## Admin UI Integration

Features automatically integrate into the dashboard:

```
WordPress Admin
└── Raw-Wire
    ├── Dashboard (core)
    ├── Approvals (approval-workflow plugin)
    ├── AI Generator (ai-content plugin)
    ├── Social Publisher (social plugin)
    └── Settings (core)
```

## Configuration Management

### Per-Feature Settings

Each feature stores its config independently:

```php
// In plugin
$this->save_config(array(
    'api_key' => 'secret',
    'enabled' => true,
));

// Stored as WordPress option
// rawwire_feature_my-feature-slug = {api_key: '...', enabled: true}
```

### Schema-Driven UI

The config schema automatically generates settings forms:

```php
public function get_config_schema() {
    return array(
        'api_key' => array(
            'type'        => 'password',
            'label'       => 'API Key',
            'description' => 'Your service API key',
            'required'    => true,
        ),
        'auto_publish' => array(
            'type'    => 'checkbox',
            'label'   => 'Auto-publish approved content',
            'default' => false,
        ),
        'threshold' => array(
            'type'    => 'number',
            'label'   => 'Relevance Threshold',
            'min'     => 0,
            'max'     => 1,
            'step'    => 0.1,
            'default' => 0.7,
        ),
    );
}
```

## Logging and Monitoring

All features have access to structured logging:

```php
$this->log('Operation completed', 'info', array(
    'items_processed' => 42,
    'duration' => 1.5,
));
```

Logs can be viewed in the admin interface and integrated with external monitoring tools.

## Best Practices

1. **Keep plugins focused** - One feature per plugin
2. **Declare dependencies** - Explicit is better than implicit
3. **Fail gracefully** - Return WP_Error for problems
4. **Use config schema** - Auto-generated UI is better than custom
5. **Log important events** - Debugging is easier with good logs
6. **Follow WordPress standards** - Hooks, filters, nonces, sanitization
7. **Test in isolation** - Plugin should work independent of others (except dependencies)

## Migration Path

### From Legacy Code

1. Existing files in `includes/` continue working (Phase 2)
2. Gradually move features to plugin system
3. No breaking changes for deployments
4. Old and new systems coexist

### Example Migration

**Before:**
```
includes/class-approval-workflow.php (direct include)
```

**After:**
```
includes/features/approval-workflow/plugin.php (plugin system)
```

Both can exist simultaneously during transition.

## Advanced: Programmatic Registration

For complex deployments, register plugins programmatically:

```php
add_action('rawwire_register_plugins', function($manager) {
    // Register custom plugin location
    $manager->register_plugin_file(
        '/custom/path/to/plugin.php',
        'enterprise'
    );
});
```

## Debugging

Enable debug mode to see plugin loading:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Check logs for:
- `[Raw Wire] Loaded plugin: {slug} v{version}`
- `[Raw Wire] Failed to load plugin '{slug}': {error}`

## Example: Complete Feature Plugin

See `includes/features/approval-workflow/plugin.php` for a complete, production-ready example implementing:
- REST API endpoints
- Admin UI integration
- Database interactions
- Configuration management
- Lifecycle hooks
- Permission checking
- Bulk operations

## Questions?

See existing plugins in `includes/features/` for reference implementations.
