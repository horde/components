#!/bin/bash
#
# Horde Components CI Smoke Test
#
# Purpose: Automated end-to-end validation of the CI system
# Target: Local mode testing (not GitHub Actions)
# Usage: bash test/smoke/run-local-ci-smoke-test.sh
#
# This script:
# 1. Creates a synthetic test component with minimal valid files
# 2. Runs CI setup to create test lanes
# 3. Runs CI tests across all lanes
# 4. Validates results were collected correctly
# 5. Reports pass/fail and cleans up
#
# Exit codes:
#   0 = All tests passed
#   1 = Setup or tests failed
#

set -e
set -o pipefail

# Color output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
BOLD='\033[1m'
NC='\033[0m' # No Color

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COMPONENTS_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
WORK_DIR="/tmp/horde-ci-smoke-test-$$"
TEST_COMPONENT="$WORK_DIR/test-component"
START_TIME=$(date +%s)

# Track test results
TOTAL_LANES=0
PASSED_LANES=0
FAILED_LANES=0
SETUP_SUCCESS=false

# Logging functions
log_header() {
    echo -e "\n${BOLD}${BLUE}=== $1 ===${NC}"
}

log_info() {
    echo -e "${BLUE}ℹ${NC} $1"
}

log_ok() {
    echo -e "${GREEN}✓${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}⚠${NC} $1"
}

log_error() {
    echo -e "${RED}✗${NC} $1"
}

log_bold() {
    echo -e "${BOLD}$1${NC}"
}

# Cleanup function
cleanup() {
    if [ -d "$WORK_DIR" ]; then
        log_info "Cleaning up temporary workspace: $WORK_DIR"
        rm -rf "$WORK_DIR"
    fi
}

# Trap cleanup on exit
trap cleanup EXIT

# Print header
echo ""
log_bold "╔═══════════════════════════════════════════════════════════╗"
log_bold "║       Horde Components CI Smoke Test                     ║"
log_bold "╚═══════════════════════════════════════════════════════════╝"
echo ""
log_info "Components directory: $COMPONENTS_DIR"
log_info "Test workspace: $WORK_DIR"
log_info "Timestamp: $(date '+%Y-%m-%d %H:%M:%S')"
echo ""

# Check prerequisites
log_header "Checking Prerequisites"

# Check horde-components exists
if [ ! -f "$COMPONENTS_DIR/bin/horde-components" ]; then
    log_error "horde-components not found at $COMPONENTS_DIR/bin/horde-components"
    exit 1
fi
log_ok "Found horde-components"

# Check PHP is available
if ! command -v php &> /dev/null; then
    log_error "PHP not found in PATH"
    exit 1
fi
PHP_VERSION=$(php -r 'echo PHP_VERSION;')
log_ok "PHP $PHP_VERSION available"

# Check composer is available
if ! command -v composer &> /dev/null; then
    log_error "Composer not found in PATH"
    exit 1
fi
COMPOSER_VERSION=$(composer --version --no-ansi | head -1)
log_ok "Composer available"

# Check jq for JSON parsing (optional but recommended)
if command -v jq &> /dev/null; then
    HAS_JQ=true
    log_ok "jq available (will use for JSON parsing)"
else
    HAS_JQ=false
    log_warn "jq not available (will use basic parsing)"
fi

# Create workspace
log_header "Creating Test Component"

mkdir -p "$TEST_COMPONENT"
log_info "Created workspace: $WORK_DIR"

# Use horde-components init to scaffold the test component
log_info "Scaffolding test component using 'horde-components init library'"
COMPONENTS_BIN="$COMPONENTS_DIR/bin/horde-components"

cd "$TEST_COMPONENT"
if ! "$COMPONENTS_BIN" init library \
    --name="TestComponent" \
    --author="Horde CI Smoke Test" \
    --email="smoketest@example.com" \
    --description="Synthetic test component for CI smoke testing" \
    > /dev/null 2>&1; then
    log_error "Failed to initialize test component with 'horde-components init'"
    exit 1
fi
cd - > /dev/null

log_ok "Test component scaffolded successfully using init command"

# Verify essential files were created
if [ ! -f "$TEST_COMPONENT/.horde.yml" ]; then
    log_error "Missing .horde.yml after init"
    exit 1
fi

if [ ! -f "$TEST_COMPONENT/composer.json" ]; then
    log_error "Missing composer.json after init"
    exit 1
fi

if [ ! -f "$TEST_COMPONENT/src/Example.php" ]; then
    log_error "Missing src/Example.php after init"
    exit 1
fi

if [ ! -f "$TEST_COMPONENT/test/ExampleTest.php" ]; then
    log_error "Missing test/ExampleTest.php after init"
    exit 1
fi

log_ok "Verified all essential component files present"

# Set environment variables
log_header "Setting Environment Variables"

export LOCAL_COMPONENTS_PATH="$COMPONENTS_DIR/bin/horde-components"
export LOCAL_COMPONENT_PATH="$TEST_COMPONENT"
export CI_WORK_DIR="$WORK_DIR"
export CI_MODE="local"

log_info "LOCAL_COMPONENTS_PATH=$LOCAL_COMPONENTS_PATH"
log_info "LOCAL_COMPONENT_PATH=$LOCAL_COMPONENT_PATH"
log_info "CI_WORK_DIR=$CI_WORK_DIR"

# Run CI setup
log_header "Running CI Setup"

COMPONENTS_PATH="$COMPONENTS_DIR/bin/horde-components"

log_info "Executing: $COMPONENTS_PATH ci setup --ci-mode=local --work-dir=$WORK_DIR --local-path=$LOCAL_COMPONENT_PATH"
echo ""

# Run setup and capture exit code
set +e
"$COMPONENTS_PATH" ci setup \
    --ci-mode=local \
    --work-dir="$WORK_DIR" \
    --local-path="$LOCAL_COMPONENT_PATH"
SETUP_EXIT=$?
set -e

echo ""
if [ $SETUP_EXIT -ne 0 ]; then
    log_error "CI setup failed with exit code $SETUP_EXIT"
    log_error "Check the output above for errors"
    exit 1
fi

log_ok "CI setup completed successfully"
SETUP_SUCCESS=true

# Validate lane structure
log_header "Validating Lane Structure"

# Check lanes directory exists
if [ ! -d "$WORK_DIR/lanes" ]; then
    log_error "Lanes directory not found: $WORK_DIR/lanes"
    exit 1
fi
log_ok "Lanes directory exists"

# Count lanes
LANE_DIRS=("$WORK_DIR/lanes"/php*)
ACTUAL_LANE_COUNT=${#LANE_DIRS[@]}

# Check for expected lanes (8: php8.2-8.5, dev+stable)
EXPECTED_LANE_COUNT=8
if [ $ACTUAL_LANE_COUNT -lt $EXPECTED_LANE_COUNT ]; then
    log_warn "Expected at least $EXPECTED_LANE_COUNT lanes, found $ACTUAL_LANE_COUNT"
    log_warn "This may be expected if min PHP version is higher"
else
    log_ok "Found $ACTUAL_LANE_COUNT test lanes"
fi

# Verify each lane has required files
log_info "Verifying lane contents..."
for lane_dir in "$WORK_DIR/lanes"/php*; do
    if [ ! -d "$lane_dir" ]; then
        continue
    fi

    lane_name=$(basename "$lane_dir")
    component_dir="$lane_dir/test-component"

    # Check lane script exists
    script="$lane_dir/run-lane.sh"
    if [ ! -f "$script" ]; then
        log_error "Lane script not found: $script"
        exit 1
    fi

    if [ ! -x "$script" ]; then
        log_error "Lane script not executable: $script"
        exit 1
    fi

    # Check component files
    if [ ! -f "$component_dir/.horde.yml" ]; then
        log_error ".horde.yml not found in $lane_name"
        exit 1
    fi

    if [ ! -f "$component_dir/composer.json" ]; then
        log_error "composer.json not found in $lane_name"
        exit 1
    fi

    if [ ! -d "$component_dir/vendor" ]; then
        log_error "vendor/ directory not found in $lane_name (composer failed?)"
        exit 1
    fi

    log_ok "Lane $lane_name: structure valid"
done

# Run CI tests
log_header "Running CI Tests"

log_info "Executing: $COMPONENTS_PATH ci run --work-dir=$WORK_DIR"
echo ""

# Run tests and capture exit code
set +e
"$COMPONENTS_PATH" ci run \
    --work-dir="$WORK_DIR"
RUN_EXIT=$?
set -e

echo ""
if [ $RUN_EXIT -ne 0 ]; then
    log_error "CI run failed with exit code $RUN_EXIT"
    log_error "Some tests may have failed - checking results..."
else
    log_ok "CI run completed"
fi

# Validate results
log_header "Validating Test Results"

TOTAL_LANES=0
PASSED_LANES=0
FAILED_LANES=0
MISSING_RESULTS=0

for lane_dir in "$WORK_DIR/lanes"/php*; do
    if [ ! -d "$lane_dir" ]; then
        continue
    fi

    lane_name=$(basename "$lane_dir")
    component_dir="$lane_dir/test-component"
    build_dir="$component_dir/build"
    TOTAL_LANES=$((TOTAL_LANES + 1))

    # Check if build directory exists
    if [ ! -d "$build_dir" ]; then
        log_warn "$lane_name: No build directory found"
        FAILED_LANES=$((FAILED_LANES + 1))
        continue
    fi

    # Check for PHPUnit results
    phpunit_result="$build_dir/phpunit-results-summary.json"
    if [ ! -f "$phpunit_result" ]; then
        log_warn "$lane_name: PHPUnit results missing"
        MISSING_RESULTS=$((MISSING_RESULTS + 1))
        FAILED_LANES=$((FAILED_LANES + 1))
        continue
    fi

    # Parse PHPUnit results
    if [ "$HAS_JQ" = true ]; then
        # Use jq for robust JSON parsing
        success=$(jq -r '.success // false' "$phpunit_result" 2>/dev/null || echo "false")
        tests=$(jq -r '.statistics.tests // 0' "$phpunit_result" 2>/dev/null || echo "0")
        failures=$(jq -r '.statistics.failures // 0' "$phpunit_result" 2>/dev/null || echo "0")
        errors=$(jq -r '.statistics.errors // 0' "$phpunit_result" 2>/dev/null || echo "0")
    else
        # Basic grep parsing (less robust)
        if grep -q '"success"[[:space:]]*:[[:space:]]*true' "$phpunit_result"; then
            success="true"
        else
            success="false"
        fi
        tests=$(grep -o '"tests"[[:space:]]*:[[:space:]]*[0-9]*' "$phpunit_result" | grep -o '[0-9]*' || echo "0")
        failures=$(grep -o '"failures"[[:space:]]*:[[:space:]]*[0-9]*' "$phpunit_result" | grep -o '[0-9]*' || echo "0")
        errors=$(grep -o '"errors"[[:space:]]*:[[:space:]]*[0-9]*' "$phpunit_result" | grep -o '[0-9]*' || echo "0")
    fi

    if [ "$success" = "true" ]; then
        log_ok "$lane_name: PASSED ($tests tests)"
        PASSED_LANES=$((PASSED_LANES + 1))
    else
        log_error "$lane_name: FAILED (failures: $failures, errors: $errors)"
        FAILED_LANES=$((FAILED_LANES + 1))
    fi
done

# Check for missing result files
if [ $MISSING_RESULTS -gt 0 ]; then
    log_warn "$MISSING_RESULTS lanes missing result files"
fi

# Calculate duration
END_TIME=$(date +%s)
DURATION=$((END_TIME - START_TIME))
MINUTES=$((DURATION / 60))
SECONDS=$((DURATION % 60))

# Print summary
echo ""
log_bold "╔═══════════════════════════════════════════════════════════╗"
log_bold "║                    Test Summary                           ║"
log_bold "╚═══════════════════════════════════════════════════════════╝"
echo ""
log_info "Total lanes: $TOTAL_LANES"
log_info "Passed: $PASSED_LANES"
log_info "Failed: $FAILED_LANES"
log_info "Duration: ${MINUTES}m ${SECONDS}s"
echo ""

# Determine final result
if [ $FAILED_LANES -gt 0 ]; then
    log_bold "${RED}╔═══════════════════════════════════════════════════════════╗${NC}"
    log_bold "${RED}║          ✗ SMOKE TEST FAILED                              ║${NC}"
    log_bold "${RED}╚═══════════════════════════════════════════════════════════╝${NC}"
    echo ""
    log_error "$FAILED_LANES of $TOTAL_LANES lanes failed"
    exit 1
else
    log_bold "${GREEN}╔═══════════════════════════════════════════════════════════╗${NC}"
    log_bold "${GREEN}║          ✓ SMOKE TEST PASSED                              ║${NC}"
    log_bold "${GREEN}╚═══════════════════════════════════════════════════════════╝${NC}"
    echo ""
    log_ok "All $TOTAL_LANES lanes passed successfully"
    exit 0
fi
