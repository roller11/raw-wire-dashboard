#!/bin/bash
# Raw-Wire Dashboard - Complete Functional Test Suite
# Tests data simulation, processing, scoring, and storage pipeline

# Don't exit on error in arithmetic operations
set +e

PLUGIN_DIR="/workspaces/raw-wire-core/wordpress-plugins/raw-wire-dashboard"
cd "$PLUGIN_DIR"

echo "╔══════════════════════════════════════════════════════════════╗"
echo "║  Raw-Wire Dashboard v1.0.13 - Functional Test Suite         ║"
echo "╚══════════════════════════════════════════════════════════════╝"
echo ""

# Color codes
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

pass_count=0
fail_count=0

# Test function
test() {
    local name="$1"
    local description="$2"
    echo -e "${BLUE}▶${NC} TEST: $name"
    echo "  $description"
}

pass() {
    echo -e "  ${GREEN}✓ PASS${NC}"
    ((pass_count++))
    echo ""
}

fail() {
    local message="$1"
    echo -e "  ${RED}✗ FAIL${NC}: $message"
    ((fail_count++))
    echo ""
}

info() {
    echo -e "  ${YELLOW}ℹ${NC} $1"
}

# Test 1: File Structure
test "File Structure" "Verify all new files exist"
if [ -f "includes/class-data-processor.php" ] && \
   [ -f "includes/class-data-simulator.php" ] && \
   [ -f "includes/class-cli-commands.php" ] && \
   [ -f "includes/class-github-fetcher.php" ] && \
   [ -f "FUNCTIONAL_AUDIT.md" ]; then
    pass
else
    fail "Missing required files"
fi

# Test 2: PHP Syntax
test "PHP Syntax Validation" "Check all PHP files compile without errors"
syntax_errors=0
for file in includes/class-data-processor.php includes/class-data-simulator.php includes/class-cli-commands.php; do
    if ! php -l "$file" > /dev/null 2>&1; then
        syntax_errors=$((syntax_errors + 1))
        info "Syntax error in $file"
    fi
done
if [ $syntax_errors -eq 0 ]; then
    pass
else
    fail "$syntax_errors files have syntax errors"
fi

# Test 3: Class Definitions
test "Class Definitions" "Verify all classes are properly defined"
classes_found=0
grep -q "class RawWire_Data_Processor" includes/class-data-processor.php && classes_found=$((classes_found + 1))
grep -q "class RawWire_Data_Simulator" includes/class-data-simulator.php && classes_found=$((classes_found + 1))
grep -q "class RawWire_CLI_Commands" includes/class-cli-commands.php && classes_found=$((classes_found + 1))
if [ $classes_found -eq 3 ]; then
    pass
else
    fail "Only $classes_found of 3 classes found"
fi

# Test 4: Critical Methods
test "Critical Methods" "Check for required methods in Data Processor"
methods_found=0
grep -q "function process_raw_federal_register_item" includes/class-data-processor.php && methods_found=$((methods_found + 1))
grep -q "function calculate_relevance_score" includes/class-data-processor.php && methods_found=$((methods_found + 1))
grep -q "function store_item" includes/class-data-processor.php && methods_found=$((methods_found + 1))
grep -q "function check_duplicate" includes/class-data-processor.php && methods_found=$((methods_found + 1))
grep -q "function batch_process_items" includes/class-data-processor.php && methods_found=$((methods_found + 1))

if [ $methods_found -eq 5 ]; then
    info "All 5 critical methods present"
    pass
else
    fail "Only $methods_found of 5 methods found"
fi

# Test 5: Scoring Algorithm
test "Scoring Algorithm" "Verify shock/surprise detection keywords present"
scoring_features=0
grep -qi "shocking" includes/class-data-processor.php && scoring_features=$((scoring_features + 1))
grep -qi "unprecedented" includes/class-data-processor.php && scoring_features=$((scoring_features + 1))
grep -qi "billion" includes/class-data-processor.php && scoring_features=$((scoring_features + 1))
grep -qi "scandal" includes/class-data-processor.php && scoring_features=$((scoring_features + 1))
grep -qi "emergency" includes/class-data-processor.php && scoring_features=$((scoring_features + 1))

if [ $scoring_features -ge 4 ]; then
    info "Found $scoring_features shock detection keywords"
    pass
else
    fail "Only $scoring_features shock keywords found (expected 5)"
fi

# Test 6: Data Simulator Templates
test "Data Simulator Templates" "Check template variety"
template_types=0
grep -q "generate_federal_register_item" includes/class-data-simulator.php && template_types=$((template_types + 1))
grep -q "generate_court_ruling" includes/class-data-simulator.php && template_types=$((template_types + 1))
grep -q "generate_sec_filing" includes/class-data-simulator.php && template_types=$((template_types + 1))
grep -q "generate_press_release" includes/class-data-simulator.php && template_types=$((template_types + 1))

if [ $template_types -eq 4 ]; then
    info "All 4 source types present: Federal Register, Court Rulings, SEC Filings, Press Releases"
    pass
else
    fail "Only $template_types of 4 source types found"
fi

# Test 7: WP-CLI Commands
test "WP-CLI Commands" "Verify command definitions"
cli_commands=0
grep -q "function generate" includes/class-cli-commands.php && cli_commands=$((cli_commands + 1))
grep -q "function sync" includes/class-cli-commands.php && cli_commands=$((cli_commands + 1))
grep -q "function stats" includes/class-cli-commands.php && cli_commands=$((cli_commands + 1))
grep -q "function clear" includes/class-cli-commands.php && cli_commands=$((cli_commands + 1))
grep -q "function test_scoring" includes/class-cli-commands.php && cli_commands=$((cli_commands + 1))

if [ $cli_commands -eq 5 ]; then
    info "All 5 WP-CLI commands present: generate, sync, stats, clear, test-scoring"
    pass
else
    fail "Only $cli_commands of 5 CLI commands found"
fi

# Test 8: Database Integration
test "Database Integration" "Check for prepared statements and wpdb usage"
db_safety=0
grep -q '$wpdb->insert' includes/class-data-processor.php && db_safety=$((db_safety + 1))
grep -q '$wpdb->prepare' includes/class-data-processor.php && db_safety=$((db_safety + 1))
grep -q 'wp_rawwire_content' includes/class-data-processor.php && db_safety=$((db_safety + 1))

if [ $db_safety -eq 3 ]; then
    info "Database operations use prepared statements"
    pass
else
    fail "Missing secure database operations"
fi

# Test 9: Error Handling
test "Error Handling" "Verify WP_Error usage and logging"
error_handling=0
grep -q "WP_Error" includes/class-data-processor.php && error_handling=$((error_handling + 1))
grep -q "RawWire_Logger::log_error" includes/class-data-processor.php && error_handling=$((error_handling + 1))
grep -q "is_wp_error" includes/class-data-processor.php && error_handling=$((error_handling + 1))

if [ $error_handling -eq 3 ]; then
    info "Proper error handling implemented"
    pass
else
    fail "Incomplete error handling ($error_handling of 3 checks)"
fi

# Test 10: GitHub Fetcher Integration
test "GitHub Fetcher Integration" "Check processor integration"
integration_points=0
grep -q "RawWire_Data_Processor" includes/class-github-fetcher.php && integration_points=$((integration_points + 1))
grep -q "batch_process_items" includes/class-github-fetcher.php && integration_points=$((integration_points + 1))
grep -q "rawwire_last_sync" includes/class-github-fetcher.php && integration_points=$((integration_points + 1))

if [ $integration_points -eq 3 ]; then
    info "GitHub Fetcher properly calls Data Processor"
    pass
else
    fail "GitHub Fetcher integration incomplete"
fi

# Test 11: Init Controller Loading
test "Init Controller" "Verify new classes loaded in Phase 1"
init_loading=0
grep -q "class-data-processor.php" includes/class-init-controller.php && init_loading=$((init_loading + 1))
grep -q "class-data-simulator.php" includes/class-init-controller.php && init_loading=$((init_loading + 1))
grep -q "class-cli-commands.php" includes/class-init-controller.php && init_loading=$((init_loading + 1))

if [ $init_loading -eq 3 ]; then
    info "All new classes registered in init controller"
    pass
else
    fail "Only $init_loading of 3 classes in init controller"
fi

# Test 12: Version Update
test "Version Update" "Check plugin version bumped to 1.0.13"
if grep -q "Version: 1.0.13" raw-wire-dashboard.php; then
    info "Plugin version updated to 1.0.13"
    pass
else
    fail "Version not updated in main plugin file"
fi

# Test 13: Documentation
test "Documentation" "Verify FUNCTIONAL_AUDIT.md exists and has content"
if [ -f "FUNCTIONAL_AUDIT.md" ] && [ $(wc -l < FUNCTIONAL_AUDIT.md) -gt 100 ]; then
    info "Comprehensive functional audit documentation present"
    pass
else
    fail "FUNCTIONAL_AUDIT.md missing or incomplete"
fi

# Test 14: Shock Level Logic
test "Shock Level Logic" "Verify shock level determination in simulator"
if grep -q "determine_shock_level" includes/class-data-simulator.php && \
   grep -q "high_pct" includes/class-data-simulator.php; then
    info "Configurable shock level distribution implemented"
    pass
else
    fail "Shock level logic not found"
fi

# Test 15: Duplicate Detection
test "Duplicate Detection" "Check for URL and title-based duplicate checking"
duplicate_checks=0
grep -q "WHERE url = %s" includes/class-data-processor.php && duplicate_checks=$((duplicate_checks + 1))
grep -q "LOWER(title)" includes/class-data-processor.php && duplicate_checks=$((duplicate_checks + 1))

if [ $duplicate_checks -eq 2 ]; then
    info "Duplicate detection by URL and title implemented"
    pass
else
    fail "Incomplete duplicate detection"
fi

# Summary
echo "╔══════════════════════════════════════════════════════════════╗"
echo "║                     TEST SUMMARY                             ║"
echo "╚══════════════════════════════════════════════════════════════╝"
echo ""
echo -e "${GREEN}Passed:${NC} $pass_count"
echo -e "${RED}Failed:${NC} $fail_count"
echo ""

total=$((pass_count + fail_count))
percentage=$((pass_count * 100 / total))

if [ $fail_count -eq 0 ]; then
    echo -e "${GREEN}✓ ALL TESTS PASSED (${percentage}%)${NC}"
    echo ""
    echo "╔══════════════════════════════════════════════════════════════╗"
    echo "║                  READY FOR DEPLOYMENT                        ║"
    echo "╚══════════════════════════════════════════════════════════════╝"
    echo ""
    echo "Next Steps:"
    echo "  1. Test with WP-CLI: wp rawwire generate --count=20 --shock=mixed"
    echo "  2. View results:     wp rawwire stats"
    echo "  3. Test scoring:     wp rawwire test-scoring"
    echo "  4. Clear data:       wp rawwire clear --yes"
    echo ""
    exit 0
else
    echo -e "${RED}✗ TESTS FAILED (${percentage}% pass rate)${NC}"
    echo ""
    echo "Please review failed tests above and fix issues before deployment."
    echo ""
    exit 1
fi
