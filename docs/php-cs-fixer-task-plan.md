# PHP CS Fixer QC Task Implementation Plan

## Overview
Create a comprehensive PHP CS Fixer QC task following the patterns established in the PHPUnit task, with support for check mode, fix mode, machine-readable output, and tool detection.

## Comparison: PHPUnit Task vs Planned PHP CS Fixer Task

| Feature | PHPUnit Task | PHP CS Fixer Task (Planned) |
|---------|--------------|---------------------------|
| **Tool Detection** | ✅ Vendor, tools/, Phive, system | ✅ Same search order |
| **PHAR Support** | ✅ Via `loadPhpUnit()` | ✅ Via `loadPhpCsFixer()` |
| **Version Output** | ✅ Via `PHPUnit\Runner\Version::id()` | ✅ Via `ToolInfo::getVersion()` |
| **Programmatic API** | ✅ `PHPUnit\TextUI\Application` | ✅ `PhpCsFixer\Runner\Runner` |
| **Event System** | ✅ Event Facade + Subscribers | ✅ Symfony EventDispatcher |
| **Statistics Collection** | ✅ Custom event subscribers | ✅ `FileProcessed` event listener |
| **JSON Output** | ✅ Custom summary JSON | ✅ `JsonReporter` + custom summary |
| **Check Mode** | N/A (tests don't modify) | ✅ `isDryRun = true` |
| **Fix Mode** | N/A | ✅ `isDryRun = false` with `--fix-qc-issues` |
| **Output Formatting** | ✅ Custom stats with ok/warn | ✅ Similar stats with ok/warn |

## Architecture Design

### Class Structure

```php
namespace Horde\Components\Qc\Task;

class PhpCsFixer extends Base
{
    private array $stats = [
        'files_checked' => 0,
        'files_fixed' => 0,
        'files_invalid' => 0,
        'files_skipped' => 0,
        'errors' => 0,
    ];

    public function getName(): string;
    public function validate(array $options = []): array;
    public function run(array &$options = []): int;

    // Tool detection
    private function loadPhpCsFixer(): void;
    private function findPhpCsFixerBinary(): ?string;
    private function isPhar(string $path): bool;
    private function detectPhpCsFixerSource(): void;
    private function getPhpCsFixerVersion(): string;

    // Execution
    private function runProgrammatic(array $options): int;
    private function registerEventListeners(): void;

    // Output
    private function writeJsonResults(string $componentPath, array $changed, int $exitCode): void;
    private function outputStatistics(bool $isDryRun): void;
}
```

### Tool Detection Strategy

**Search Order (matching PHPUnit):**
1. Component vendor/bin/php-cs-fixer or vendor/bin/php-cs-fixer.phar
2. Component tools/php-cs-fixer or tools/php-cs-fixer.phar
3. Global Phive ~/.phive/php-cs-fixer.phar or ~/.phive/php-cs-fixer
4. System PATH /usr/local/bin/php-cs-fixer, /usr/bin/php-cs-fixer

**Detection Methods:**
```php
// For Composer-installed
if (class_exists('PhpCsFixer\\Console\\Application')) {
    $toolInfo = new \PhpCsFixer\ToolInfo();
    $version = $toolInfo->getVersion();
    $isPhar = $toolInfo->isInstalledAsPhar();
    $isComposer = $toolInfo->isInstalledByComposer();
}

// For PHAR files
foreach ($possibleLocations as $path) {
    if ($this->isPhar($path)) {
        require_once 'phar://' . $path . '/vendor/autoload.php';
        // Now classes are available
    }
}
```

### Execution Strategy

**Option 1: Programmatic API (Preferred for in-process)**
```php
use PhpCsFixer\Runner\Runner;
use PhpCsFixer\Console\ConfigurationResolver;
use PhpCsFixer\Error\ErrorsManager;
use Symfony\Component\EventDispatcher\EventDispatcher;

// Setup
$eventDispatcher = new EventDispatcher();
$eventDispatcher->addListener(
    'fixer.file_processed',
    [$this, 'onFileProcessed']
);

$resolver = new ConfigurationResolver(...);
$errorsManager = new ErrorsManager();

$runner = new Runner(
    $resolver->getFinder(),
    $resolver->getFixers(),
    $resolver->getDiffer(),
    $eventDispatcher,
    $errorsManager,
    $resolver->getLinter(),
    $isDryRun,  // Check mode vs fix mode
    $resolver->getCacheManager(),
    null,  // directory
    false, // stopOnViolation
    null,  // parallelConfig
    $input, // Symfony InputInterface (required v3+)
    $configFile,
    null   // ruleCustomisationPolicy
);

$changed = $runner->fix();
```

**Option 2: CLI Subprocess (Fallback if programmatic fails)**
```php
// Simpler but less integrated
$cmd = escapeshellarg($binary) . ' fix ' . escapeshellarg($path);
if ($isDryRun) {
    $cmd .= ' --dry-run';
}
$cmd .= ' --format=json --using-cache=no';

exec($cmd, $output, $exitCode);
$result = json_decode(implode("\n", $output), true);
```

**Recommendation:** Start with **CLI subprocess** approach for v1, then consider programmatic API for v2 because:
- CLI is simpler and more stable (public API)
- Programmatic API is marked `@internal` and may change
- CLI provides clean JSON output already
- CLI handles all edge cases (parallel processing, config resolution, etc.)

### Check Mode vs Fix Mode

**Implementation:**
```php
public function run(array &$options = []): int
{
    $isDryRun = empty($options['fix_qc_issues']);

    if ($isDryRun) {
        $this->getOutput()->info('Running in CHECK mode (use --fix-qc-issues to auto-fix)');
    } else {
        $this->getOutput()->info('Running in FIX mode (will modify files)');
    }

    // Execute with appropriate flag
    $changed = $this->execute($isDryRun);

    return count($changed); // Return number of files with issues
}
```

**Behavior:**
- **Check mode (default):** Reports issues, doesn't modify files
- **Fix mode (`--fix-qc-issues`):** Reports and fixes issues, modifies files
- **Integration:** Respects QC `--fix-qc-issues` flag globally

### JSON Output Format

**Output File:** `build/php-cs-fixer-results.json`

**Structure:**
```json
{
    "timestamp": "2026-02-27T10:30:45+00:00",
    "php_cs_fixer_version": "3.94.2",
    "mode": "check",
    "exit_code": 0,
    "success": true,
    "statistics": {
        "files_checked": 42,
        "files_fixed": 5,
        "files_invalid": 0,
        "files_skipped": 2,
        "errors": 0
    },
    "files": [
        {
            "name": "src/Example.php",
            "applied_fixers": ["no_unused_imports", "array_syntax"],
            "diff": "--- Original\n+++ New\n..."
        }
    ],
    "time_ms": 1234,
    "memory_mb": 12.5
}
```

**Native PHP CS Fixer JSON** (also saved to `build/php-cs-fixer-native.json`):
```json
{
    "about": "PHP CS Fixer 3.94.2 by Fabien Potencier...",
    "files": [...],
    "time": {"total": 1.234},
    "memory": 12.345
}
```

### Configuration Detection

**Search Order:**
1. `.php-cs-fixer.php` (user config)
2. `.php-cs-fixer.dist.php` (dist config)
3. Use built-in defaults if neither exists

**Default Rules (if no config):**
```php
// Use PSR-12 ruleset as default for Horde components
$rules = '@PSR12';
```

## Implementation Steps

### Phase 1: Basic Structure (Similar to GitIgnore task)
1. ✅ Create `src/Qc/Task/PhpCsFixer.php`
2. ✅ Implement `getName()`, `validate()`, `run()`
3. ✅ Add basic tool detection (CLI only)
4. ✅ Support check mode (default)

### Phase 2: Fix Mode Integration
1. ✅ Detect `--fix-qc-issues` flag
2. ✅ Toggle between `--dry-run` and fix mode
3. ✅ Output appropriate messages

### Phase 3: Statistics & JSON Output
1. ✅ Parse JSON output from CLI
2. ✅ Create custom summary JSON
3. ✅ Collect statistics (files checked/fixed/invalid)
4. ✅ Output formatted statistics

### Phase 4: Advanced Tool Detection
1. ✅ Implement search order (vendor/tools/phive/system)
2. ✅ Support PHAR detection
3. ✅ Output version and source information

### Phase 5: Integration & Documentation
1. ✅ Add to `Runner\Qc` task sequence
2. ✅ Update `Module\Qc` help text
3. ✅ Add to QC pipeline (after lint, before cs)
4. ✅ Update release pipeline if needed

## CLI Execution Pattern

```bash
# Check mode (default)
horde-components qc phpcsfixer

# Fix mode
horde-components qc phpcsfixer --fix-qc-issues

# Part of full QC
horde-components qc --fix-qc-issues

# During release (auto-fix enabled)
horde-components release h6
```

## Error Handling

**Error Types:**
1. **Tool not found:** Return validation error, skip task
2. **Configuration invalid:** Report and exit with error
3. **Files invalid (lint errors):** Count as errors, continue
4. **Fixing errors:** Count as errors, report file path
5. **Unexpected exceptions:** Catch, report, continue

**Error Reporting:**
```php
if ($stats['errors'] > 0 || $stats['files_invalid'] > 0) {
    $this->getOutput()->warn(
        'Found issues. PHP CS Fixer results: ' .
        $stats['files_fixed'] . ' files fixed, ' .
        $stats['errors'] . ' errors'
    );
} else {
    $this->getOutput()->ok(
        'No problems found. PHP CS Fixer results: ' .
        $stats['files_checked'] . ' files checked'
    );
}
```

## Integration with Existing Tasks

**Task Order in QC Pipeline:**
1. gitignore (VCS config)
2. lint (syntax check)
3. **phpcsfixer** (code style fixing) ← NEW
4. cs (code style check - PHPCS)
5. unit (tests)
6. md (mess detection)
7. loc (metrics)

**Rationale:** Run after lint (ensures valid PHP) but before cs (php-cs-fixer fixes many PHPCS issues).

## Testing Strategy

**Manual Testing:**
1. Test with no php-cs-fixer installed → expect skip
2. Test in check mode → expect issues reported
3. Test in fix mode → expect files modified
4. Test JSON output → verify structure
5. Test with custom config → respect rules
6. Test with PHAR → detect and use
7. Test version detection → output version

**Test Files:**
```php
// test/fixtures/bad-style.php
<?php
namespace Foo;
use UnusedClass;
class Example {
    public function test( ) {
        $array = array(1,2,3);
    }
}
```

## Future Enhancements (v2)

1. **Programmatic API:** Switch from CLI to `Runner` class for tighter integration
2. **Event Listeners:** Use `FileProcessed` events for real-time progress
3. **Parallel Processing:** Leverage PHP CS Fixer's parallel config
4. **Custom Fixers:** Allow Horde-specific custom fixers
5. **Progressive Fixing:** Fix only changed files (git diff based)
6. **Rule Profiles:** Provide predefined Horde rule profiles
7. **Cache Support:** Respect and manage `.php-cs-fixer.cache`

## Risks & Mitigations

| Risk | Mitigation |
|------|-----------|
| PHP CS Fixer not installed | Validate in `validate()`, skip gracefully |
| Internal API changes in v4 | Use CLI approach, not programmatic |
| Config file parsing errors | Catch exceptions, report clearly |
| Large codebase performance | Use CLI with parallel config, cache enabled |
| PHAR loading conflicts | Isolate autoloading, test thoroughly |
| Breaking existing workflow | Make check mode default, fix opt-in |

## Success Criteria

- ✅ Detects PHP CS Fixer in all standard locations
- ✅ Runs in check mode by default
- ✅ Fixes issues when `--fix-qc-issues` is set
- ✅ Outputs JSON results to build/ directory
- ✅ Shows version and source information
- ✅ Reports statistics clearly (files checked/fixed)
- ✅ Integrates seamlessly with QC pipeline
- ✅ Respects existing `.php-cs-fixer.php` config
- ✅ Handles errors gracefully

## Conclusion

The PHP CS Fixer task will follow PHPUnit's proven patterns while adapting to the unique characteristics of a code style fixer tool. Starting with CLI execution provides stability and simplicity, with a clear path to programmatic API integration in the future if needed.

Key advantages:
- **Consistency:** Matches PHPUnit task patterns
- **Flexibility:** Check vs fix modes
- **Transparency:** Version, source, and statistics output
- **Integration:** Works with existing QC infrastructure
- **Reliability:** Uses stable public CLI API
