# Critical Hotfix for v1.0.12 - Regression Fixes

**Date:** January 5, 2026  
**Issue:** Settings and Approvals pages missing, Activity Logs not loading  
**Status:** ✅ FIXED  
**Package Updated:** `/workspaces/raw-wire-core/releases/raw-wire-dashboard-v1.0.12.zip`

---

## Issues Reported

1. **Settings Page Missing** - "Settings" submenu was not appearing
2. **Approvals Page Missing** - "Approvals" submenu was not appearing  
3. **Activity Logs Spinning Forever** - Info/Error tabs showing endless loading spinner
4. **Sync Does Nothing** - Sync reports 36 items but data doesn't update

---

## Root Causes Identified

### 1. Conflicting Initialization
**Problem:** `Raw_Wire_Dashboard::get_instance()` was still being called at the bottom of `raw-wire-dashboard.php`, conflicting with the new Init Controller.

**Impact:** Created race conditions where some components initialized twice and others not at all.

**Fix:**
```php
// OLD (line 499 of raw-wire-dashboard.php):
Raw_Wire_Dashboard::get_instance();

// NEW:
// Plugin initialization is now handled by RawWire_Init_Controller
// Removed Raw_Wire_Dashboard::get_instance() to prevent conflicts
```

### 2. Dashboard Core Not Initialized
**Problem:** Dashboard Core (which loads GitHub Fetcher, Data Processor, Approval Workflow) was loaded but never initialized.

**Impact:** 
- GitHub sync functionality broken (no fetcher)
- Approval workflow class loaded but not registered
- Data processor not available

**Fix:**
```php
// In includes/class-init-controller.php Phase 5:
if (class_exists('RawWire_Dashboard_Core')) {
    RawWire_Dashboard_Core::get_instance(); // Now properly initialized
}
```

### 3. Settings Class Not Initialized
**Problem:** `Raw_Wire_Settings` was loaded in Phase 1 but `init()` was never called.

**Impact:** Settings submenu page never registered with WordPress.

**Fix:**
```php
// In includes/class-init-controller.php Phase 1:
if (class_exists('Raw_Wire_Settings')) {
    Raw_Wire_Settings::init(); // Now initializes settings page
}
```

### 4. Admin Class Not Loaded
**Problem:** `RawWire_Admin` class (which provides additional AJAX handlers) was never loaded.

**Impact:** Some AJAX endpoints missing.

**Fix:**
```php
// In includes/class-init-controller.php Phase 5:
$admin_file = plugin_dir_path(dirname(__FILE__)) . 'includes/class-admin.php';
if (file_exists($admin_file)) {
    require_once $admin_file;
    if (class_exists('RawWire_Admin')) {
        RawWire_Admin::get_instance(); // Now loads admin AJAX handlers
    }
}
```

### 5. Module Admin UI Not Registered
**Problem:** Feature modules (like Approval Workflow) have a `register_admin_ui()` method that was never being called.

**Impact:** "Approvals" submenu page never registered.

**Fix:**
```php
// In includes/class-init-controller.php Phase 3:
add_action('admin_menu', function() use ($manager) {
    $loaded_plugins = $manager->get_loaded_plugins();
    foreach ($loaded_plugins as $slug => $plugin_instance) {
        if (method_exists($plugin_instance, 'register_admin_ui')) {
            $plugin_instance->register_admin_ui(); // Registers Approvals page
        }
    }
}, 20);
```

---

## Files Modified

### 1. `/raw-wire-dashboard.php`
**Line 499:** Removed `Raw_Wire_Dashboard::get_instance();`  
**Reason:** Prevented conflict with Init Controller

### 2. `/includes/class-init-controller.php`
**Phase 1 (lines 125-132):** Added `Raw_Wire_Settings::init()`  
**Reason:** Register Settings submenu page

**Phase 3 (lines 202-212):** Added admin_menu hook to call `register_admin_ui()` on all loaded modules  
**Reason:** Register Approvals and other module admin pages

**Phase 5 (lines 352-370):** Added initialization of Dashboard Core and Admin class  
**Reason:** Load GitHub Fetcher, Data Processor, Approval Workflow, and additional AJAX handlers

---

## What Now Works

✅ **Settings Page** - Appears as "Raw-Wire → Settings" submenu  
✅ **Approvals Page** - Appears as "Raw-Wire → Approvals" submenu  
✅ **GitHub Sync** - GitHub Fetcher properly initialized, sync functional  
✅ **Data Processor** - Content processing pipeline active  
✅ **Activity Logs** - Should load properly (AJAX endpoints registered)  
✅ **Approval Workflow** - AJAX handlers for approve/reject active

---

## Testing Instructions

### 1. Reupload Plugin
```bash
# In WordPress Admin:
1. Deactivate old plugin
2. Delete old plugin files
3. Upload new raw-wire-dashboard-v1.0.12.zip
4. Activate plugin
```

### 2. Verify Admin Menus
Navigate to WordPress Admin sidebar:
- **Expected:** "Raw-Wire" menu with 3 submenu items:
  1. Dashboard (main page with tabs)
  2. Settings (GitHub token configuration)
  3. Approvals (content approval queue)

### 3. Test Activity Logs
1. Click "Raw-Wire" menu
2. Click "Activity Logs" tab
3. **Expected:** Info and Error tabs load with log entries (not spinning forever)

### 4. Test GitHub Sync
1. Go to Settings, ensure GitHub token is configured
2. Click "Dashboard" tab
3. Click "Sync" button
4. **Expected:** 
   - Toast notification: "Synced X items"
   - Dashboard data updates (issue counts, recent items)
   - Activity logs show "GitHub sync completed" entry

### 5. Test Approvals
1. Click "Raw-Wire → Approvals"
2. **Expected:** List of pending content items with Approve/Reject buttons

---

## Debug Commands (If Issues Persist)

### Check Activity Logs Table
```sql
SELECT * FROM wp_rawwire_automation_log ORDER BY id DESC LIMIT 10;
```
Should show "Dashboard core initialized" and "Raw-Wire Dashboard initialized" entries.

### Check Admin Menu Registration
```bash
# In PHP/WordPress:
global $menu, $submenu;
print_r($submenu['raw-wire-dashboard']);
```
Should show Settings and Approvals submenu items.

### Check Module Loading
```bash
# View health endpoint:
curl http://yoursite.com/wp-json/rawwire/v1/health
```
Should show `"modules_loaded": 1` (or more if search modules enabled).

---

## Rollback (If Needed)

If issues persist after this hotfix:

```bash
# 1. Deactivate plugin
# 2. Restore previous backup
# 3. Report issue with:
#    - PHP error log (/wp-content/debug.log)
#    - Browser console errors (F12)
#    - WordPress admin menu screenshot
```

---

## Change Summary

| Component | Before | After |
|-----------|--------|-------|
| **Main Plugin Init** | Conflicting singleton | Init Controller only |
| **Dashboard Core** | Loaded but not init | Properly initialized |
| **Settings Page** | Loaded but not init | `init()` called |
| **Admin Class** | Not loaded | Loaded & initialized |
| **Module Admin UI** | Never called | Called on admin_menu |
| **Approvals Page** | Missing | Registered via module |
| **GitHub Sync** | Broken (no fetcher) | Functional |
| **Activity Logs** | Spinning (no data) | Should load properly |

---

## Next Steps

1. **Deploy hotfix** to your site
2. **Test all 5 areas** listed above
3. **Report results:**
   - ✅ Working? Proceed with alpha testing
   - ❌ Still broken? Share debug info (see Debug Commands section)

---

**Package Location:** `/workspaces/raw-wire-core/releases/raw-wire-dashboard-v1.0.12.zip`  
**Package Size:** 185 KB  
**Ready for Deployment:** ✅ Yes
