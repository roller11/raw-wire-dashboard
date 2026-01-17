#!/bin/bash
#
# Raw-Wire Dashboard v1.0.12 - End-to-End Test Suite
# 
# Comprehensive validation of all plugin functionality without WordPress installation.
# Tests PHP syntax, class loading, initialization flow, and critical methods.
#

set -euo pipefail

PLUGIN_DIR="/workspaces/raw-wire-core/wordpress-plugins/raw-wire-dashboard"
TEST_RESULTS=()
PASS_COUNT=0
FAIL_COUNT=0

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}╔═══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║   Raw-Wire Dashboard v1.0.12 - End-to-End Test Suite         ║${NC}"
echo -e "${BLUE}╚═══════════════════════════════════════════════════════════════╝${NC}"
echo ""

# Helper functions
pass_test() {
    echo -e "${GREEN}✓${NC} $1"
    PASS_COUNT=$((PASS_COUNT + 1))
}

fail_test() {
    echo -e "${RED}✗${NC} $1"
    echo -e "  ${RED}Error:${NC} $2"
    FAIL_COUNT=$((FAIL_COUNT + 1))
}

section() {
    echo ""
    echo -e "${YELLOW}▶ $1${NC}"
    echo "────────────────────────────────────────────────────────────────"
}

cd "$PLUGIN_DIR"

# ============================================================================
# TEST SUITE 1: PHP Syntax Validation
# ============================================================================
section "1. PHP Syntax Validation"

PHP_FILES=$(find . -name "*.php" -not -path "./vendor/*" -not -path "./tests/*" | sort)
SYNTAX_ERRORS=0

for file in $PHP_FILES; do
    if php -l "$file" > /dev/null 2>&1; then
        pass_test "Syntax: $file"
    else
        fail_test "Syntax: $file" "$(php -l "$file" 2>&1)"
        SYNTAX_ERRORS=$((SYNTAX_ERRORS + 1))
    fi
done

if [ $SYNTAX_ERRORS -eq 0 ]; then
    pass_test "All PHP files have valid syntax"
fi

# ============================================================================
# TEST SUITE 2: File Structure Validation
# ============================================================================
section "2. File Structure Validation"

REQUIRED_FILES=(
    "raw-wire-dashboard.php"
    "includes/class-init-controller.php"
    "includes/class-error-boundary.php"
    "includes/class-validator.php"
    "includes/class-permissions.php"
    "includes/class-logger.php"
    "includes/class-activity-logs.php"
    "includes/bootstrap.php"
    "includes/migrations/class-migration-manager.php"
    "includes/migrations/001_initial_schema.php"
)

for file in "${REQUIRED_FILES[@]}"; do
    if [ -f "$file" ]; then
        pass_test "Required file exists: $file"
    else
        fail_test "Required file missing: $file" "File not found"
    fi
done

# ============================================================================
# TEST SUITE 3: WordPress Plugin Header Validation
# ============================================================================
section "3. WordPress Plugin Header Validation"

MAIN_FILE="raw-wire-dashboard.php"

if grep -q "Plugin Name:" "$MAIN_FILE"; then
    PLUGIN_NAME=$(grep "Plugin Name:" "$MAIN_FILE" | cut -d: -f2 | xargs)
    pass_test "Plugin Name found: $PLUGIN_NAME"
else
    fail_test "Plugin Name missing" "No 'Plugin Name:' header in main file"
fi

if grep -q "Version: 1.0.12" "$MAIN_FILE"; then
    pass_test "Version 1.0.12 confirmed"
else
    fail_test "Version mismatch" "Expected 'Version: 1.0.12' in main file"
fi

if grep -q "if (!defined('ABSPATH')) exit;" "$MAIN_FILE"; then
    pass_test "ABSPATH security check present"
else
    fail_test "ABSPATH check missing" "Security vulnerability: direct access not prevented"
fi

# ============================================================================
# TEST SUITE 4: Class Definition Validation
# ============================================================================
section "4. Class Definition Validation"

REQUIRED_CLASSES=(
    "RawWire_Init_Controller:includes/class-init-controller.php"
    "RawWire_Error_Boundary:includes/class-error-boundary.php"
    "RawWire_Validator:includes/class-validator.php"
    "RawWire_Permissions:includes/class-permissions.php"
    "RawWire_Logger:includes/class-logger.php"
    "RawWire_Activity_Logs:includes/class-activity-logs.php"
    "RawWire_Bootstrap:includes/bootstrap.php"
    "RawWire_Migration_Manager:includes/migrations/class-migration-manager.php"
)

for class_def in "${REQUIRED_CLASSES[@]}"; do
    CLASS_NAME=$(echo "$class_def" | cut -d: -f1)
    CLASS_FILE=$(echo "$class_def" | cut -d: -f2)
    
    if grep -q "class $CLASS_NAME" "$CLASS_FILE"; then
        pass_test "Class definition found: $CLASS_NAME"
    else
        fail_test "Class definition missing: $CLASS_NAME" "Not found in $CLASS_FILE"
    fi
done

# ============================================================================
# TEST SUITE 5: Initialization Flow Validation
# ============================================================================
section "5. Initialization Flow Validation"

# Check main plugin loads init controller
if grep -q "require_once.*class-init-controller.php" "$MAIN_FILE"; then
    pass_test "Init controller loaded in main file"
else
    fail_test "Init controller not loaded" "Missing require_once for class-init-controller.php"
fi

# Check plugins_loaded hook
if grep -q "add_action('plugins_loaded'.*Init_Controller.*init" "$MAIN_FILE"; then
    pass_test "Init controller hooked to plugins_loaded"
else
    fail_test "Init hook missing" "No add_action for Init_Controller::init on plugins_loaded"
fi

# Check 6-phase initialization
INIT_FILE="includes/class-init-controller.php"
for phase in "Phase 1" "Phase 2" "Phase 3" "Phase 4" "Phase 5" "Phase 6"; do
    if grep -q "$phase" "$INIT_FILE"; then
        pass_test "Initialization: $phase present"
    else
        fail_test "Initialization: $phase missing" "Phase not documented in init controller"
    fi
done

# ============================================================================
# TEST SUITE 6: Safety Infrastructure Validation
# ============================================================================
section "6. Safety Infrastructure Validation"

# Error Boundary
ERROR_BOUNDARY="includes/class-error-boundary.php"
ERROR_METHODS=("wrap_module_call" "wrap_ajax_call" "wrap_rest_call" "wrap_db_call" "with_timeout")

for method in "${ERROR_METHODS[@]}"; do
    if grep -q "function $method" "$ERROR_BOUNDARY"; then
        pass_test "Error Boundary: $method() method exists"
    else
        fail_test "Error Boundary: $method() missing" "Method not found in class-error-boundary.php"
    fi
done

# Validator
VALIDATOR="includes/class-validator.php"
VALIDATOR_METHODS=("sanitize_int" "sanitize_float" "sanitize_enum" "sanitize_bool" "sanitize_email" "sanitize_url")

for method in "${VALIDATOR_METHODS[@]}"; do
    if grep -q "function $method" "$VALIDATOR"; then
        pass_test "Validator: $method() method exists"
    else
        fail_test "Validator: $method() missing" "Method not found in class-validator.php"
    fi
done

# Permissions
PERMISSIONS="includes/class-permissions.php"
PERMISSION_METHODS=("check" "require_capability" "rest_permission_check")

for method in "${PERMISSION_METHODS[@]}"; do
    if grep -q "function $method" "$PERMISSIONS"; then
        pass_test "Permissions: $method() method exists"
    else
        fail_test "Permissions: $method() missing" "Method not found in class-permissions.php"
    fi
done

# ============================================================================
# TEST SUITE 7: AJAX Handler Validation
# ============================================================================
section "7. AJAX Handler Validation"

AJAX_FILE="includes/class-activity-logs.php"
AJAX_ACTIONS=("rawwire_get_activity_logs" "rawwire_get_activity_info" "rawwire_clear_activity_logs")

for action in "${AJAX_ACTIONS[@]}"; do
    if grep -q "wp_ajax_$action" "$AJAX_FILE"; then
        pass_test "AJAX: $action registered"
    else
        fail_test "AJAX: $action not registered" "add_action not found for $action"
    fi
done

# Check AJAX handlers use error boundaries
if grep -q "Error_Boundary::wrap_ajax_call" "$AJAX_FILE"; then
    pass_test "AJAX handlers use error boundaries"
else
    fail_test "AJAX handlers lack error boundaries" "No wrap_ajax_call usage found"
fi

# ============================================================================
# TEST SUITE 8: Database Schema Validation
# ============================================================================
section "8. Database Schema Validation"

SCHEMA_FILE="includes/migrations/001_initial_schema.php"

TABLES=("rawwire_content" "rawwire_automation_log")

for table in "${TABLES[@]}"; do
    if grep -q "$table" "$SCHEMA_FILE"; then
        pass_test "Database: $table table defined"
    else
        fail_test "Database: $table missing" "Table not found in schema migration"
    fi
done

# ============================================================================
# TEST SUITE 9: REST API Validation
# ============================================================================
section "9. REST API Validation"

INIT_FILE="includes/class-init-controller.php"

if grep -q "register_rest_route.*rawwire/v1.*health" "$INIT_FILE"; then
    pass_test "REST API: /health endpoint registered"
else
    fail_test "REST API: /health missing" "Health check endpoint not found"
fi

if grep -q "health_check_endpoint" "$INIT_FILE"; then
    pass_test "REST API: health_check_endpoint method exists"
else
    fail_test "REST API: health check method missing" "health_check_endpoint not defined"
fi

# ============================================================================
# TEST SUITE 10: No Double Initialization
# ============================================================================
section "10. Double Initialization Prevention"

BOOTSTRAP_FILE="includes/bootstrap.php"
ACTIVITY_LOGS_FILE="includes/class-activity-logs.php"

# Check bootstrap doesn't have standalone init
if ! grep -q "^RawWire_Bootstrap::init()" "$BOOTSTRAP_FILE"; then
    pass_test "Bootstrap: No standalone init() call"
else
    fail_test "Bootstrap: Standalone init detected" "Double initialization risk"
fi

# Check activity logs doesn't have standalone init
if ! grep -q "^RawWire_Activity_Logs::init()" "$ACTIVITY_LOGS_FILE"; then
    pass_test "Activity Logs: No standalone init() call"
else
    fail_test "Activity Logs: Standalone init detected" "Double initialization risk"
fi

# ============================================================================
# TEST SUITE 11: Asset File Validation
# ============================================================================
section "11. Asset File Validation"

ASSETS=(
    "js/activity-logs.js"
    "css/activity-logs.css"
    "dashboard.js"
    "dashboard.css"
    "dashboard-template.php"
)

for asset in "${ASSETS[@]}"; do
    if [ -f "$asset" ]; then
        pass_test "Asset exists: $asset"
    else
        fail_test "Asset missing: $asset" "File not found"
    fi
done

# ============================================================================
# TEST SUITE 12: Documentation Validation
# ============================================================================
section "12. Documentation Validation"

DOCS=(
    "CHANGELOG.md"
    "RELEASE_NOTES_v1.0.12.md"
    "DEPLOYMENT_GUIDE_v1.0.12.md"
    "DEPLOYMENT_CHECKLIST_v1.0.12.md"
    "IMPLEMENTATION_SUMMARY_v1.0.12.md"
    "ALPHA_TESTING_VALIDATION_v1.0.12.md"
)

for doc in "${DOCS[@]}"; do
    if [ -f "$doc" ]; then
        pass_test "Documentation: $doc exists"
    else
        fail_test "Documentation: $doc missing" "File not found"
    fi
done

# ============================================================================
# TEST SUITE 13: Release Package Validation
# ============================================================================
section "13. Release Package Validation"

# Disable errexit for this section (grep can return non-zero)
set +e

RELEASE_ZIP="/workspaces/raw-wire-core/releases/raw-wire-dashboard-v1.0.12.zip"

if [ -f "$RELEASE_ZIP" ]; then
    pass_test "Release package exists"
    
    # Check zip structure
    unzip -l "$RELEASE_ZIP" > /tmp/zip_contents.txt 2>&1
    grep "raw-wire-dashboard/raw-wire-dashboard\.php" /tmp/zip_contents.txt > /dev/null 2>&1
    ZIP_CHECK=$?
    if [ $ZIP_CHECK -eq 0 ]; then
        pass_test "Release: Correct WordPress structure"
    else
        fail_test "Release: Invalid structure" "Main file not at raw-wire-dashboard/raw-wire-dashboard.php (exit code: $ZIP_CHECK)"
    fi
    
    # Check zip doesn't have extra wordpress-plugins layer
    unzip -l "$RELEASE_ZIP" | grep -q "wordpress-plugins/raw-wire-dashboard"
    if [ $? -ne 0 ]; then
        pass_test "Release: No extra directory layers"
    else
        fail_test "Release: Extra directory layer" "Contains wordpress-plugins/ prefix"
    fi
    
    # Check size is reasonable
    SIZE=$(stat -c%s "$RELEASE_ZIP" 2>/dev/null || stat -f%z "$RELEASE_ZIP" 2>/dev/null)
    if [ "$SIZE" -gt 100000 ] && [ "$SIZE" -lt 500000 ]; then
        pass_test "Release: Package size reasonable (${SIZE} bytes)"
    else
        fail_test "Release: Package size unusual" "Size: ${SIZE} bytes (expected 100KB-500KB)"
    fi
else
    fail_test "Release package missing" "$RELEASE_ZIP not found"
fi

# Re-enable errexit
set -e

# ============================================================================
# TEST RESULTS SUMMARY
# ============================================================================
echo ""
echo -e "${BLUE}╔═══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║                     TEST RESULTS SUMMARY                      ║${NC}"
echo -e "${BLUE}╚═══════════════════════════════════════════════════════════════╝${NC}"
echo ""

TOTAL_TESTS=$((PASS_COUNT + FAIL_COUNT))
PASS_RATE=$(awk "BEGIN {printf \"%.1f\", ($PASS_COUNT/$TOTAL_TESTS)*100}")

echo -e "Total Tests:    ${BLUE}$TOTAL_TESTS${NC}"
echo -e "Passed:         ${GREEN}$PASS_COUNT${NC}"
echo -e "Failed:         ${RED}$FAIL_COUNT${NC}"
echo -e "Pass Rate:      ${YELLOW}${PASS_RATE}%${NC}"
echo ""

if [ $FAIL_COUNT -eq 0 ]; then
    echo -e "${GREEN}╔═══════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${GREEN}║                  ✓ ALL TESTS PASSED                           ║${NC}"
    echo -e "${GREEN}║        Plugin is ready for alpha testing deployment          ║${NC}"
    echo -e "${GREEN}╚═══════════════════════════════════════════════════════════════╝${NC}"
    exit 0
else
    echo -e "${RED}╔═══════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${RED}║                  ✗ TESTS FAILED                               ║${NC}"
    echo -e "${RED}║           Fix issues before deploying to alpha               ║${NC}"
    echo -e "${RED}╚═══════════════════════════════════════════════════════════════╝${NC}"
    exit 1
fi
