# CI Smoke Test

Automated end-to-end validation of the horde-components CI system.

## Purpose

The smoke test validates that the full CI workflow works correctly:
1. Creates a synthetic test component
2. Runs CI setup (creates lanes, installs dependencies)
3. Runs CI tests (executes PHPUnit, PHPStan)
4. Validates results are collected correctly
5. Reports pass/fail

## Usage

### Basic Usage

```bash
cd ~/components
bash test/smoke/run-local-ci-smoke-test.sh
```

### Prerequisites

- **OS:** Linux (Ubuntu 24.04 recommended)
- **PHP:** 8.2+ installed
- **Composer:** Globally installed
- **sudo:** Access (for PHP installation if needed)
- **jq:** Optional but recommended for JSON parsing
  ```bash
  sudo apt-get install jq
  ```

### Expected Duration

- **First run:** 8-12 minutes (installs PHP versions)
- **Subsequent runs:** 3-5 minutes (PHP already installed)

### Expected Output

**Success:**
```
╔═══════════════════════════════════════════════════════════╗
║       Horde Components CI Smoke Test                     ║
╚═══════════════════════════════════════════════════════════╝

ℹ Components directory: /home/user/components
ℹ Test workspace: /tmp/horde-ci-smoke-test-12345
ℹ Timestamp: 2026-03-04 15:30:00

=== Checking Prerequisites ===
✓ Found horde-components
✓ PHP 8.4.2 available
✓ Composer available
✓ jq available (will use for JSON parsing)

=== Creating Test Component ===
ℹ Created workspace: /tmp/horde-ci-smoke-test-12345
✓ Created .horde.yml
✓ Created composer.json
✓ Created src/Example.php
✓ Created test/ExampleTest.php
✓ Created phpunit.xml
✓ Created phpstan.neon
ℹ Test component created successfully

=== Setting Environment Variables ===
ℹ LOCAL_COMPONENTS_PATH=/home/user/components/bin/horde-components
ℹ LOCAL_COMPONENT_PATH=/tmp/horde-ci-smoke-test-12345/test-component
ℹ CI_WORK_DIR=/tmp/horde-ci-smoke-test-12345

=== Running CI Setup ===
[... CI setup output ...]
✓ CI setup completed successfully

=== Validating Lane Structure ===
✓ Lanes directory exists
✓ Found 8 test lanes
ℹ Verifying lane contents...
✓ Lane php8.2-dev: structure valid
✓ Lane php8.2-stable: structure valid
✓ Lane php8.3-dev: structure valid
✓ Lane php8.3-stable: structure valid
✓ Lane php8.4-dev: structure valid
✓ Lane php8.4-stable: structure valid
✓ Lane php8.5-dev: structure valid
✓ Lane php8.5-stable: structure valid

=== Running CI Tests ===
[... CI run output ...]
✓ CI run completed

=== Validating Test Results ===
✓ php8.2-dev: PASSED (7 tests)
✓ php8.2-stable: PASSED (7 tests)
✓ php8.3-dev: PASSED (7 tests)
✓ php8.3-stable: PASSED (7 tests)
✓ php8.4-dev: PASSED (7 tests)
✓ php8.4-stable: PASSED (7 tests)
✓ php8.5-dev: PASSED (7 tests)
✓ php8.5-stable: PASSED (7 tests)

╔═══════════════════════════════════════════════════════════╗
║                    Test Summary                           ║
╚═══════════════════════════════════════════════════════════╝

ℹ Total lanes: 8
ℹ Passed: 8
ℹ Failed: 0
ℹ Duration: 3m 42s

╔═══════════════════════════════════════════════════════════╗
║          ✓ SMOKE TEST PASSED                              ║
╚═══════════════════════════════════════════════════════════╝

✓ All 8 lanes passed successfully
```

**Failure:**
```
=== Running CI Tests ===
[... output ...]

=== Validating Test Results ===
✓ php8.2-dev: PASSED (7 tests)
✗ php8.3-dev: FAILED (failures: 1, errors: 0)
✓ php8.4-dev: PASSED (7 tests)
[...]

╔═══════════════════════════════════════════════════════════╗
║                    Test Summary                           ║
╚═══════════════════════════════════════════════════════════╝

ℹ Total lanes: 8
ℹ Passed: 7
ℹ Failed: 1
ℹ Duration: 4m 15s

╔═══════════════════════════════════════════════════════════╗
║          ✗ SMOKE TEST FAILED                              ║
╚═══════════════════════════════════════════════════════════╝

✗ 1 of 8 lanes failed
```

## What Gets Tested

### Test Component Structure

The script creates a minimal but valid Horde component:

```
test-component/
├── .horde.yml          # Component metadata
├── composer.json       # Composer configuration
├── phpunit.xml         # PHPUnit configuration
├── phpstan.neon        # PHPStan configuration
├── src/
│   └── Example.php     # Simple class with 3 methods
└── test/
    └── ExampleTest.php # PHPUnit tests (7 test methods)
```

### Test Coverage

The smoke test validates:

1. **Prerequisites:**
   - horde-components binary exists
   - PHP is available
   - Composer is available

2. **CI Setup:**
   - Creates 8 test lanes (php8.2-8.5, dev+stable)
   - Copies component to each lane
   - Runs composer install per lane
   - Generates lane execution scripts
   - Downloads QC tools

3. **Lane Structure:**
   - All lane directories exist
   - All lane scripts exist and are executable
   - All lanes have component files (.horde.yml, composer.json)
   - All lanes have vendor/ directories

4. **CI Execution:**
   - Lane scripts execute successfully
   - PHPUnit tests run in each lane
   - PHPStan analysis runs in each lane
   - Results are collected to JSON files

5. **Test Results:**
   - All tests pass in all lanes
   - JSON result files are present and valid
   - No test failures or errors

## Integration with Development Workflow

### During Development

Run the smoke test after making changes to CI code:

```bash
# 1. Make changes to CI implementation
vim src/Ci/Setup/SetupCommand.php

# 2. Run unit tests
vendor/bin/phpunit test/Unit/Ci/

# 3. Run smoke test (validates integration)
bash test/smoke/run-local-ci-smoke-test.sh

# 4. If both pass, changes are likely safe to commit
```

### Pre-Commit Hook

Add to `.git/hooks/pre-commit`:

```bash
#!/bin/bash

echo "Running CI unit tests..."
vendor/bin/phpunit test/Unit/Ci/ || exit 1

echo "Running CI smoke test..."
bash test/smoke/run-local-ci-smoke-test.sh || exit 1

echo "All tests passed!"
```

### In CI/CD

Add to `.github/workflows/ci.yml` (for horde-components itself):

```yaml
name: CI Tests
on: [push, pull_request]

jobs:
  smoke-test:
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: 8.4
          extensions: json, mbstring

      - name: Install Composer dependencies
        run: composer install

      - name: Run unit tests
        run: vendor/bin/phpunit

      - name: Run smoke test
        run: bash test/smoke/run-local-ci-smoke-test.sh
```

## Cleanup

The script automatically cleans up its temporary workspace on exit (success or failure).

Workspace location: `/tmp/horde-ci-smoke-test-{PID}`

To manually clean up stale workspaces:
```bash
rm -rf /tmp/horde-ci-smoke-test-*
```

## Troubleshooting

### "horde-components not found"

**Problem:** Script can't find horde-components binary

**Solution:** Run from components directory:
```bash
cd ~/components
bash test/smoke/run-local-ci-smoke-test.sh
```

### "PHP not found in PATH"

**Problem:** PHP not installed or not in PATH

**Solution:**
```bash
# Install PHP
sudo apt-get install php8.4-cli

# Check it's in PATH
which php
```

### "Composer not found in PATH"

**Problem:** Composer not installed globally

**Solution:**
```bash
# Install composer globally
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
composer --version
```

### "CI setup failed with exit code 1"

**Problem:** CI setup encountered an error

**Solution:** Check the output above the error for details. Common issues:
- PHP versions not installed (will install automatically with sudo)
- Composer install failures (network issues, dependency conflicts)
- Disk space issues

### "jq not available (will use basic parsing)"

**Problem:** jq not installed (warning, not an error)

**Solution:** Install jq for better JSON parsing:
```bash
sudo apt-get install jq
```

The script will work without jq but uses simpler regex parsing.

### Tests fail in PHP 8.5 lanes

**Problem:** PHP 8.5 is in development, some dependencies may not be compatible

**Solution:** This is expected behavior. The test validates that:
- Setup handles incompatible versions gracefully
- Other lanes still pass
- Failures are reported correctly

## Files Created

The smoke test creates these temporary files:

```
/tmp/horde-ci-smoke-test-{PID}/
├── test-component/              # Synthetic test component
│   ├── .horde.yml
│   ├── composer.json
│   ├── phpunit.xml
│   ├── phpstan.neon
│   ├── src/Example.php
│   └── test/ExampleTest.php
├── lanes/                       # CI test lanes
│   ├── php8.2-dev/
│   │   ├── TestComponent/
│   │   └── run-lane.sh
│   ├── php8.2-stable/
│   │   ├── TestComponent/
│   │   └── run-lane.sh
│   └── ...
└── tools/                       # QC tools cache
    ├── phpunit-11.5.2.phar
    ├── phpunit-12.5.1.phar
    ├── phpstan.phar
    └── php-cs-fixer.phar
```

Total size: ~800 MB - 1 GB (8 lanes × ~100 MB each)

## Exit Codes

- **0** - All tests passed
- **1** - Setup failed, tests failed, or results invalid

## See Also

- **Manual Testing Guide:** `~/horde-development/components-ci-manual-testing-guide.md`
- **CI As-Is Analysis:** `~/horde-development/ci-as-is-analysis.md`
- **Smoke Test Specification:** `~/horde-development/ci-smoke-test-specification.md`

## Support

For issues with the smoke test:
1. Check troubleshooting section above
2. Review smoke test output for specific errors
3. Report issues: https://github.com/horde/components/issues
