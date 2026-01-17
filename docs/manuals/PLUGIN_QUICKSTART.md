# Raw Wire Plugin System - Quick Start

## What Changed?

Your Raw Wire Dashboard now has a **plugin-based architecture** designed for SaaS and agentic AI deployments. Features can be dynamically loaded, enabled/disabled, and distributed independently.

## Key Benefits

✅ **Modular** - Add/remove features without touching core code  
✅ **SaaS-Ready** - Different feature sets for Free/Pro/Enterprise tiers  
✅ **Agentic-Friendly** - AI agents can enable features on-demand  
✅ **Marketplace-Ready** - Third-party developers can create plugins  
✅ **Backward Compatible** - Existing code continues working  

## Architecture Summary

### New Files

1. **`includes/class-plugin-manager.php`**
   - Discovers and loads feature plugins
   - Manages dependencies and lifecycle
   - Handles feature flags

2. **`includes/interface-feature.php`**
   - Standard interface for all plugins
   - Base class with common functionality
   - Logging, config management, helpers

3. **`includes/features/`** (new directory)
   - Houses feature plugins
   - Each plugin in its own subdirectory
   - Auto-discovered at runtime

4. **`includes/features/approval-workflow/plugin.php`**
   - Complete example plugin
   - REST API, admin UI, AJAX handlers
   - Production-ready reference implementation

### Updated Files

- **`raw-wire-dashboard.php`** - New 4-phase initialization:
  1. Load core system
  2. Load legacy features (backward compat)
  3. Initialize plugin system
  4. Start main dashboard

## Quick Examples

### Creating a New Feature

```bash
# Create plugin directory
mkdir -p includes/features/my-feature

# Create plugin file
cat > includes/features/my-feature/plugin.php << 'EOF'
<?php
/**
 * Plugin Name: My Feature
 * Plugin Slug: my-feature
 * Description: What this does
 * Version: 1.0.0
 * Category: automation
 */

class Raw_Wire_Feature_My_Feature extends Raw_Wire_Feature_Base {
    
    public function init() {
        // Your initialization code
    }
    
    public function get_metadata() {
        return array(
            'name' => 'My Feature',
            'description' => 'Description',
            'version' => '1.0.0',
            'category' => 'automation',
        );
    }
}
EOF
```

Plugin is automatically discovered and loaded!

### Managing Features via PHP

```php
// Get plugin manager
$manager = Raw_Wire_Plugin_Manager::get_instance();

// List all available plugins
$all_plugins = $manager->get_plugin_registry();

// List loaded plugins
$loaded = $manager->get_loaded_plugins();

// Enable a plugin
$manager->enable_plugin('my-feature');

// Disable a plugin
$manager->disable_plugin('my-feature');

// Get specific plugin instance
$plugin = $manager->get_plugin('approval-workflow');
```

### Managing Features via WP Options

```php
// Enable specific features
update_option('rawwire_enabled_features', array(
    'approval-workflow',
    'github-integration',
    'ai-content-generator',
));

// Free tier (core only)
update_option('rawwire_enabled_features', array());

// Pro tier
update_option('rawwire_enabled_features', array('approval-workflow'));

// Enterprise tier (all features)
// Leave option empty or include all plugin slugs
```

## Feature Discovery

Plugins are discovered from these directories:

1. `includes/features/` - Core bundled features
2. `includes/custom-features/` - Installation-specific
3. `includes/vendor-features/` - Third-party plugins

Add more directories via filter:

```php
add_filter('rawwire_plugin_directories', function($dirs) {
    $dirs['enterprise'] = '/custom/path/';
    return $dirs;
});
```

## Feature Template

Minimal working plugin:

```php
<?php
/**
 * Plugin Name: Feature Name
 * Plugin Slug: feature-slug
 * Version: 1.0.0
 * Category: automation|ai|content|analytics
 * Dependencies: logger, other-plugin
 */

class Raw_Wire_Feature_Feature_Slug extends Raw_Wire_Feature_Base {
    
    public function init() {
        // Setup hooks
        add_action('some_hook', array($this, 'handler'));
    }
    
    public function get_metadata() {
        return array(
            'name' => 'Feature Name',
            'description' => 'What it does',
            'version' => '1.0.0',
            'category' => 'automation',
        );
    }
    
    public function register_rest_routes($server) {
        // Add REST API endpoints
    }
    
    public function register_admin_ui() {
        // Add admin pages
    }
}
```

## Base Class Features

All plugins extending `Raw_Wire_Feature_Base` get:

```php
// Configuration
$this->get_config('key', 'default');
$this->save_config(array('key' => 'value'));

// Logging
$this->log('message', 'info', array('context' => 'data'));

// Dashboard access
$dashboard = $this->get_dashboard();

// Metadata
$this->slug // Auto-derived from class name
$this->config // Current configuration
```

## REST API Integration

Plugins can register endpoints:

```php
public function register_rest_routes($server) {
    register_rest_route('rawwire/v1', '/my-feature/action', array(
        'methods' => 'POST',
        'callback' => array($this, 'rest_handler'),
        'permission_callback' => array($this, 'check_permission'),
    ));
}
```

Endpoints become:  
`POST /wp-json/rawwire/v1/my-feature/action`

## Admin UI Integration

Plugins can add menu items:

```php
public function register_admin_ui() {
    add_submenu_page(
        'rawwire-dashboard',  // Parent slug
        'My Feature',         // Page title
        'My Feature',         // Menu title
        'manage_options',     // Capability
        'rawwire-my-feature', // Slug
        array($this, 'render_page')
    );
}
```

## SaaS Deployment Example

```php
// In your SaaS platform code:

// Provision new site
function provision_rawwire_site($tier) {
    $features = array(
        'free' => array(),
        'pro' => array('approval-workflow', 'github-integration'),
        'enterprise' => array('approval-workflow', 'github-integration', 
                              'ai-content-generator', 'social-publisher'),
    );
    
    update_option('rawwire_enabled_features', $features[$tier]);
}

// Upgrade site
function upgrade_rawwire_site($from_tier, $to_tier) {
    // Enable additional features
    $manager = Raw_Wire_Plugin_Manager::get_instance();
    $new_features = get_new_features($from_tier, $to_tier);
    
    foreach ($new_features as $feature) {
        $manager->enable_plugin($feature);
    }
}
```

## Debugging

Enable WordPress debug mode:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Check `/wp-content/debug.log` for:
- `[Raw Wire] Loaded plugin: {slug} v{version}`
- `[Raw Wire] Failed to load plugin '{slug}': {error}`

## Migration from Old System

**Nothing breaks!** Old code in `includes/` still works:

- ✅ `includes/class-admin.php` - Still loaded
- ✅ `includes/logger.php` - Still loaded (from main branch)
- ✅ `includes/search/` - Still loaded

New features should use plugin system. Old features can be migrated gradually.

## Example: Approval Workflow Plugin

See `includes/features/approval-workflow/plugin.php` for:
- ✅ Complete REST API (4 endpoints)
- ✅ Admin UI integration
- ✅ AJAX handlers
- ✅ Database operations
- ✅ Configuration schema
- ✅ Permission checking
- ✅ Bulk operations
- ✅ Lifecycle hooks

Use as template for new features.

## Next Steps

1. **Test current setup** - Existing features work unchanged
2. **Create first plugin** - Use approval-workflow as template
3. **Implement feature flags** - Control what's enabled
4. **Build SaaS tier logic** - Free/Pro/Enterprise
5. **Extend REST API** - Add plugin-specific endpoints
6. **Package for distribution** - Create plugin marketplace

## Documentation

- **Full Guide**: `PLUGIN_ARCHITECTURE.md`
- **API Docs**: See inline comments in `class-plugin-manager.php`
- **Examples**: `includes/features/approval-workflow/plugin.php`

## Support

This architecture is designed for:
- Multi-tenant SaaS platforms
- AI agent automation systems
- Plugin marketplaces
- White-label deployments
- Feature flag systems
- A/B testing frameworks
- Progressive rollouts

Your dashboard is now enterprise-ready! 🚀
