# PHPStan QC Task Implementation Plan

## Executive Summary

Replace PHPMD as the default static analysis tool with PHPStan while keeping PHPMD available for explicit calls. PHPStan provides more comprehensive static analysis with configurable quality levels and better PHP 8+ support.

## Strategic Overview

### Why PHPStan Over PHPMD

**PHPStan Advantages:**
- ✅ **Modern PHP Support** - Full PHP 8.0, 8.1, 8.2, 8.3 support including union types, attributes, enums
- ✅ **Progressive Analysis** - 10 quality levels (0-9) allow gradual improvement
- ✅ **Type Inference** - Understands PHPDoc types and infers types from code
- ✅ **Extensible** - Rich ecosystem of extensions (PHPUnit, Doctrine, Symfony, etc.)
- ✅ **Active Development** - Actively maintained with frequent releases
- ✅ **Better Error Messages** - More actionable and specific feedback
- ✅ **Baseline Support** - Can ignore existing issues while preventing new ones
- ✅ **JSON Output** - Native machine-readable output via `--error-format=json`

**PHPMD Limitations:**
- ⚠️ **Maintenance Status** - Less active development
- ⚠️ **PHP 8+ Support** - Limited support for modern PHP features
- ⚠️ **Narrow Focus** - Primarily code complexity metrics
- ⚠️ **No Baseline** - Cannot easily ignore legacy issues

### Migration Strategy: 200 Target Repositories

The challenge: Rolling out PHPStan across ~200 Horde component repositories with varying code quality levels.

**Phase-Based Approach:**

```
Phase 1: Foundation (Weeks 1-2)
├── Implement PHPStan QC task in horde/components
├── Add level configuration strategy
├── Test on high-quality components (level 6+)
└── Document patterns and best practices

Phase 2: Baseline Generation (Weeks 3-4)
├── Create baseline generation tool
├── Generate baselines for all 200 repos
├── Commit baselines to each repo
└── Enable PHPStan in CI/CD for new issues only

Phase 3: Progressive Improvement (Months 2-6)
├── Fix issues incrementally (highest priority repos first)
├── Increase levels gradually (0→1→2→3...)
├── Remove baseline entries as issues are fixed
└── Track progress across all repos

Phase 4: Enforcement (Month 6+)
├── Enforce minimum level (3-4) for all repos
├── Make PHPStan failures block releases
├── Remove baselines from high-quality repos
└── Continue gradual improvement to higher levels
```

## Architecture Design

### Class Structure

```php
namespace Horde\Components\Qc\Task;

class Phpstan extends Base
{
    /**
     * Statistics collected during execution.
     */
    private array $stats = [
        'files_analyzed' => 0,
        'errors' => 0,
        'file_errors' => 0,  // Number of files with errors
        'warnings' => 0,
        'level' => 0,
    ];

    /**
     * Native PHPStan JSON results.
     */
    private ?array $nativeResults = null;

    /**
     * PHPStan configuration path.
     */
    private ?string $configPath = null;

    /**
     * PHPStan level used.
     */
    private int $level = 0;

    // Core methods
    public function getName(): string;
    public function validate(array $options = []): array;
    public function run(array &$options = []): int;

    // Tool detection
    private function findPhpStanBinary(): ?string;
    private function detectVersion(string $binary): void;
    private function findConfiguration(string $componentPath): ?string;

    // Configuration management
    private function determineLevel(string $componentPath): int;
    private function getLevelStrategy(string $componentPath): string;

    // Execution
    private function executePhpStan(string $binary, string $componentPath, int $level): int;
    private function extractJson(string $output): ?string;

    // Result processing
    private function parseResults(): void;
    private function writeJsonResults(string $componentPath, int $exitCode, int $level): void;
    private function outputStatistics(): void;
}
```

### Tool Detection Strategy

**Search Order (consistent with other tasks):**
```php
private function findPhpStanBinary(): ?string
{
    $componentPath = $this->_config->getPath();

    if (empty($componentPath)) {
        $componentPath = getcwd();
    }

    // Order of preference:
    // 1. vendor/bin (Composer) - most common
    // 2. tools/ (PHIVE local)
    // 3. ~/.phive/ (PHIVE global)
    // 4. system PATH
    $locations = [
        $componentPath . '/vendor/bin/phpstan',
        $componentPath . '/vendor/bin/phpstan.phar',
        $componentPath . '/tools/phpstan',
        $componentPath . '/tools/phpstan.phar',
        $_SERVER['HOME'] . '/.phive/phpstan',
        $_SERVER['HOME'] . '/.phive/phpstan.phar',
        '/usr/local/bin/phpstan',
        '/usr/bin/phpstan',
    ];

    foreach ($locations as $path) {
        if (file_exists($path) && is_executable($path)) {
            return $path;
        }
    }

    // Fallback: check PATH
    $which = trim((string) shell_exec('which phpstan 2>/dev/null'));
    if (!empty($which) && file_exists($which)) {
        return $which;
    }

    return null;
}
```

### Version Detection

```php
private function detectVersion(string $binary): void
{
    $versionOutput = shell_exec(escapeshellarg($binary) . ' --version 2>&1');

    if ($versionOutput === null) {
        $this->getOutput()->info('Using PHPStan from: ' . $binary);
        return;
    }

    // Parse version from output like "PHPStan - PHP Static Analysis Tool 1.12.9"
    if (preg_match('/PHPStan.*?([0-9]+\.[0-9]+\.[0-9]+)/', $versionOutput, $matches)) {
        $version = $matches[1];
        $this->getOutput()->info('Using PHPStan version ' . $version . ' from: ' . $binary);
    } else {
        $this->getOutput()->info('Using PHPStan from: ' . $binary);
    }
}
```

### Configuration Discovery

**Search Order:**
1. `phpstan.neon` (user config, not committed by default)
2. `phpstan.neon.dist` (distributed config)
3. `phpstan.dist.neon` (alternative distributed config)
4. `.phpstan.neon` (hidden config)
5. `.phpstan.neon.dist` (hidden distributed config)

```php
private function findConfiguration(string $componentPath): ?string
{
    $possibleConfigs = [
        $componentPath . '/phpstan.neon',
        $componentPath . '/phpstan.neon.dist',
        $componentPath . '/phpstan.dist.neon',
        $componentPath . '/.phpstan.neon',
        $componentPath . '/.phpstan.neon.dist',
    ];

    foreach ($possibleConfigs as $config) {
        if (file_exists($config)) {
            return $config;
        }
    }

    return null;
}
```

## Level Management Strategy (200 Repos)

### The Challenge

PHPStan has 10 levels (0-9), each progressively stricter:
- **Level 0**: Basic checks (undefined variables, unknown classes)
- **Level 1-3**: Gradual increase in type checking
- **Level 4-5**: Medium strictness (recommended for most projects)
- **Level 6-7**: Strict type checking
- **Level 8-9**: Maximum strictness (requires excellent type coverage)

**Problem:** 200 repositories with varying code quality. Some may pass level 6+, others may fail even at level 0.

### Solution: Multi-Strategy Level Determination

#### Strategy A: Configuration-First (Respects Existing Setup)

**Default Behavior:** Read level from existing `phpstan.neon` if present.

```php
private function determineLevel(string $componentPath): int
{
    $configPath = $this->findConfiguration($componentPath);

    if ($configPath !== null) {
        $this->configPath = $configPath;

        // Parse level from config
        $level = $this->parseLevelFromConfig($configPath);
        if ($level !== null) {
            $this->getOutput()->info('Using PHPStan level ' . $level . ' from: ' . $configPath);
            return $level;
        }
    }

    // No config or no level specified - use progressive strategy
    return $this->determineProgressiveLevel($componentPath);
}

private function parseLevelFromConfig(string $configPath): ?int
{
    $content = file_get_contents($configPath);

    // Parse NEON format: "level: 5"
    if (preg_match('/^\s*level:\s*([0-9])/m', $content, $matches)) {
        return (int) $matches[1];
    }

    return null;
}
```

#### Strategy B: Progressive Level Discovery (No Config)

**Concept:** Automatically determine the highest level a component can pass.

**Approach 1: Metadata-Based (Recommended)**

Store per-component level metadata in a central registry:

```php
// File: data/phpstan-levels.json
{
    "horde/components": {"level": 5, "last_checked": "2026-02-27"},
    "horde/auth": {"level": 6, "last_checked": "2026-02-15"},
    "horde/core": {"level": 4, "last_checked": "2026-02-20"},
    // ... 200 repositories
}
```

```php
private function determineProgressiveLevel(string $componentPath): int
{
    $componentName = $this->getComponentName($componentPath);
    $registry = $this->loadLevelRegistry();

    if (isset($registry[$componentName])) {
        $level = $registry[$componentName]['level'];
        $this->getOutput()->info('Using PHPStan level ' . $level . ' from registry');
        return $level;
    }

    // Default for unknown components: conservative level 3
    $this->getOutput()->info('Using default PHPStan level 3 (no registry entry)');
    return 3;
}

private function loadLevelRegistry(): array
{
    $registryPath = Constants::getDataDirectory() . '/phpstan-levels.json';

    if (!file_exists($registryPath)) {
        return [];
    }

    $json = file_get_contents($registryPath);
    return json_decode($json, true) ?: [];
}
```

**Approach 2: Auto-Discovery (Fallback)**

Run PHPStan at multiple levels until one passes:

```php
private function autoDiscoverLevel(string $binary, string $componentPath): int
{
    $this->getOutput()->info('Auto-discovering appropriate PHPStan level...');

    // Try levels in descending order: 5, 4, 3, 2, 1, 0
    foreach ([5, 4, 3, 2, 1, 0] as $level) {
        $cmd = [
            escapeshellarg($binary),
            'analyse',
            '--level=' . $level,
            '--no-progress',
            '--quiet',
            escapeshellarg($componentPath . '/src'),
        ];

        exec(implode(' ', $cmd) . ' 2>&1', $output, $exitCode);

        if ($exitCode === 0) {
            $this->getOutput()->info('Component passes PHPStan level ' . $level);
            return $level;
        }
    }

    // Even level 0 fails - use it anyway but warn
    $this->getOutput()->warn('Component has issues even at level 0 - using level 0');
    return 0;
}
```

**Performance Note:** Auto-discovery is expensive (runs PHPStan multiple times). Only use during initial setup or with `--discover-level` flag.

#### Strategy C: Baseline-Based (Legacy Code)

**Concept:** Use PHPStan baseline to ignore existing issues while preventing new ones.

```php
private function ensureBaseline(string $componentPath, int $desiredLevel): string
{
    $baselinePath = $componentPath . '/phpstan-baseline.neon';

    if (!file_exists($baselinePath)) {
        $this->getOutput()->info('Generating PHPStan baseline for level ' . $desiredLevel . '...');
        $this->generateBaseline($componentPath, $desiredLevel);
    }

    return $baselinePath;
}

private function generateBaseline(string $componentPath, int $level): void
{
    $binary = $this->findPhpStanBinary();
    $baselinePath = $componentPath . '/phpstan-baseline.neon';

    $cmd = [
        escapeshellarg($binary),
        'analyse',
        '--level=' . $level,
        '--generate-baseline=' . escapeshellarg($baselinePath),
        escapeshellarg($componentPath . '/src'),
    ];

    exec(implode(' ', $cmd) . ' 2>&1', $output, $exitCode);

    if (file_exists($baselinePath)) {
        $this->getOutput()->ok('Baseline generated: ' . $baselinePath);
    } else {
        $this->getOutput()->warn('Failed to generate baseline');
    }
}
```

#### Strategy D: Unified Approach (Recommended Implementation)

**Decision Tree:**

```
1. Does component have phpstan.neon with level?
   YES → Use that level
   NO  → Continue to 2

2. Does component have phpstan-baseline.neon?
   YES → Use level from baseline config (or default 5)
   NO  → Continue to 3

3. Is component in data/phpstan-levels.json registry?
   YES → Use registered level
   NO  → Continue to 4

4. Use safe default level (3 or 4)
   - Log recommendation to run --discover-level
```

**Implementation:**

```php
private function determineLevel(string $componentPath): int
{
    // Strategy 1: Explicit config
    $configPath = $this->findConfiguration($componentPath);
    if ($configPath !== null) {
        $this->configPath = $configPath;
        $level = $this->parseLevelFromConfig($configPath);
        if ($level !== null) {
            $this->getOutput()->info('Using level ' . $level . ' from: ' . basename($configPath));
            return $level;
        }
    }

    // Strategy 2: Baseline exists (implies higher level desired)
    if (file_exists($componentPath . '/phpstan-baseline.neon')) {
        $this->getOutput()->info('Using level 5 (baseline present)');
        return 5;
    }

    // Strategy 3: Registry lookup
    $componentName = $this->getComponentName();
    $registry = $this->loadLevelRegistry();
    if (isset($registry[$componentName]['level'])) {
        $level = $registry[$componentName]['level'];
        $this->getOutput()->info('Using level ' . $level . ' from registry');
        return $level;
    }

    // Strategy 4: Safe default
    $this->getOutput()->info('Using default level 4 (run with --discover-phpstan-level to optimize)');
    return 4;
}
```

### Level Registry Management Tool

**New Command:** `horde-components qc-phpstan-discover`

```bash
# Discover and register level for current component
horde-components qc-phpstan-discover

# Discover for all components in a directory
horde-components qc-phpstan-discover --all /path/to/repos

# Update existing registry entry
horde-components qc-phpstan-discover --update
```

**Implementation Sketch:**

```php
// In Module/Qc.php or new Module/PhpstanDiscover.php

public function discoverAndRegister(): void
{
    $componentPath = getcwd();
    $componentName = $this->getComponentName($componentPath);

    // Discover highest passing level
    $level = $this->autoDiscoverLevel($binary, $componentPath);

    // Update registry
    $registry = $this->loadLevelRegistry();
    $registry[$componentName] = [
        'level' => $level,
        'last_checked' => date('Y-m-d'),
        'phpstan_version' => $this->getVersion(),
    ];

    $this->saveRegistry($registry);

    $this->getOutput()->ok('Registered ' . $componentName . ' at level ' . $level);
}
```

## CLI Execution Pattern

### Command Structure

```php
private function executePhpStan(string $binary, string $componentPath, int $level): int
{
    $cmd = [
        escapeshellarg($binary),
        'analyse',
        '--level=' . $level,
        '--error-format=json',
        '--no-progress',
        '--no-ansi',
    ];

    // Use config if available
    if ($this->configPath !== null) {
        $cmd[] = '--configuration=' . escapeshellarg($this->configPath);
    }

    // Add paths to analyze (from config or default to src/)
    $paths = $this->getPathsToAnalyze($componentPath);
    foreach ($paths as $path) {
        $cmd[] = escapeshellarg($path);
    }

    // Memory limit (PHPStan can be memory-intensive)
    $cmd[] = '--memory-limit=512M';

    $command = implode(' ', $cmd);

    // Execute and capture output
    exec($command . ' 2>&1', $output, $exitCode);

    // Parse JSON output (may have warnings before JSON)
    $fullOutput = implode("\n", $output);
    $jsonOutput = $this->extractJson($fullOutput);

    if ($jsonOutput !== null) {
        $this->nativeResults = json_decode($jsonOutput, true);

        if ($this->nativeResults === null && json_last_error() !== JSON_ERROR_NONE) {
            $this->getOutput()->warn('Failed to parse PHPStan JSON output');
            if ($this->getOutput()->isVerbose()) {
                $this->getOutput()->plain('JSON portion: ' . $jsonOutput);
            }
        }
    } else {
        if ($this->getOutput()->isVerbose()) {
            $this->getOutput()->plain('Full output: ' . $fullOutput);
        }
    }

    return $exitCode;
}
```

### JSON Output Handling

**PHPStan Native JSON Format:**

```json
{
    "totals": {
        "errors": 42,
        "file_errors": 15
    },
    "files": {
        "src/Example.php": {
            "errors": 3,
            "messages": [
                {
                    "message": "Parameter $foo of method Example::test() has invalid type Foo.",
                    "line": 25,
                    "ignorable": true
                },
                {
                    "message": "Property Example::$bar is never written, only read.",
                    "line": 10,
                    "ignorable": true
                }
            ]
        }
    },
    "errors": []
}
```

**Custom Summary Format:**

```json
{
    "timestamp": "2026-02-27T10:30:45+00:00",
    "phpstan_version": "1.12.9",
    "tool_source": "/home/i567442/components/vendor/bin/phpstan",
    "level": 5,
    "configuration": "phpstan.neon",
    "baseline_used": false,
    "exit_code": 1,
    "success": false,
    "statistics": {
        "files_analyzed": 47,
        "errors": 42,
        "file_errors": 15,
        "warnings": 0
    },
    "time_seconds": 8.234,
    "memory_mb": 245.5
}
```

### Result Parsing

```php
private function parseResults(): void
{
    if ($this->nativeResults === null) {
        return;
    }

    // Extract totals
    if (isset($this->nativeResults['totals'])) {
        $this->stats['errors'] = $this->nativeResults['totals']['errors'] ?? 0;
        $this->stats['file_errors'] = $this->nativeResults['totals']['file_errors'] ?? 0;
    }

    // Count files analyzed
    if (isset($this->nativeResults['files'])) {
        $this->stats['files_analyzed'] = count($this->nativeResults['files']);
    }
}
```

### Human-Readable Output

```php
private function outputStatistics(): void
{
    $parts = [];

    if ($this->stats['files_analyzed'] > 0) {
        $parts[] = $this->stats['files_analyzed'] . ' file' .
                   ($this->stats['files_analyzed'] !== 1 ? 's' : '') . ' analyzed';
    }

    if ($this->stats['errors'] > 0) {
        $parts[] = $this->stats['errors'] . ' error' .
                   ($this->stats['errors'] !== 1 ? 's' : '');
        $parts[] = 'in ' . $this->stats['file_errors'] . ' file' .
                   ($this->stats['file_errors'] !== 1 ? 's' : '');
    }

    if (!empty($parts)) {
        if ($this->stats['errors'] > 0) {
            $message = 'Issues found. PHPStan results (level ' . $this->level . '): ' .
                      implode(', ', $parts);
            $this->getOutput()->warn($message);
        } else {
            $message = 'No problems found. PHPStan results (level ' . $this->level . '): ' .
                      implode(', ', $parts);
            $this->getOutput()->ok($message);
        }
    }
}
```

## QC Pipeline Integration

### Current Pipeline (Before PHPStan)

```
1. gitignore    - VCS configuration
2. lint         - Syntax validation
3. phpcsfixer   - Code style fixing
4. unit         - PHPUnit tests
5. md           - PHPMD mess detection (default)
6. cs           - PHPCS (explicit-only)
7. loc          - PHPLOC metrics
```

### Proposed Pipeline (With PHPStan)

```
1. gitignore    - VCS configuration
2. lint         - Syntax validation
3. phpcsfixer   - Code style fixing
4. unit         - PHPUnit tests
5. phpstan      - Static analysis (NEW - default)
6. md           - PHPMD (explicit-only)
7. cs           - PHPCS (explicit-only)
8. loc          - PHPLOC metrics
```

**Rationale:**
- Run PHPStan after unit tests (ensures working code)
- PHPStan before LOC (static analysis before metrics)
- PHPMD becomes explicit-only (legacy tool)

### Runner/Qc.php Changes

```php
public function run(Config $config): void
{
    $arguments = $config->getArguments();
    $options = $config->getOptions();

    $sequence = [];

    // ... existing tasks ...

    if ($this->_doTask('unit', $arguments)) {
        $sequence[] = 'unit';
    }

    // PHPStan in default pipeline (NEW)
    if ($this->_doTask('phpstan', $arguments)) {
        $sequence[] = 'phpstan';
    }

    // PHPMD is only run when explicitly requested (CHANGED)
    if ($this->_doTask('md', $arguments, false)) {
        $sequence[] = 'md';
    }

    // PHPCS remains explicit-only
    if ($this->_doTask('cs', $arguments, false)) {
        $sequence[] = 'cs';
    }

    if ($this->_doTask('loc', $arguments)) {
        $sequence[] = 'loc';
    }

    // ... execute sequence ...
}
```

### Module/Qc.php Help Text Addition

```
phpstan    Run PHPStan static analysis
           Requires: phpstan command available
           Checks: type errors, dead code, undefined variables, invalid types
           Levels: 0-9 (configurable, auto-detected from phpstan.neon)
           Supports: baseline files for legacy code
           Outputs: JSON results to build/ directory
           Note: Default static analysis tool (PHPMD available via explicit call)
```

## Implementation Checklist

### Phase 1: Core Implementation
- [ ] Create `src/Qc/Task/Phpstan.php`
- [ ] Implement tool detection (findPhpStanBinary)
- [ ] Implement version detection
- [ ] Implement configuration discovery
- [ ] Implement level determination (config-first strategy)
- [ ] Implement CLI execution with proper escaping
- [ ] Parse native JSON output
- [ ] Generate custom summary JSON
- [ ] Collect and output statistics
- [ ] Handle JSON extraction from mixed output
- [ ] Add to Runner\Qc task sequence
- [ ] Update Module\Qc help text
- [ ] Make PHPMD explicit-only (like PHPCS)

### Phase 2: Level Management
- [ ] Create `data/phpstan-levels.json` registry file
- [ ] Implement registry loading/saving
- [ ] Add baseline detection logic
- [ ] Test with components at various levels

### Phase 3: Advanced Features
- [ ] Implement `--discover-phpstan-level` flag
- [ ] Add auto-discovery tool for bulk registration
- [ ] Create baseline generation helper
- [ ] Add memory limit handling
- [ ] Support custom paths from config

### Phase 4: Testing & Documentation
- [ ] Test with no phpstan installed → expect skip
- [ ] Test with explicit level in config → respect it
- [ ] Test with baseline file → handle correctly
- [ ] Test with registry entry → use registered level
- [ ] Test with no config → use default level
- [ ] Test JSON output → verify structure
- [ ] Document level strategy in help text
- [ ] Create migration guide for 200 repos

## Rollout Strategy for 200 Repositories

### Step 1: Baseline Generation (All Repos)

**Script:** `scripts/phpstan-baseline-all.sh`

```bash
#!/bin/bash
# Generate baselines for all Horde components

REPOS_DIR="/path/to/horde/repos"
TARGET_LEVEL=5

for repo in "$REPOS_DIR"/*; do
    if [ -d "$repo" ]; then
        cd "$repo"

        echo "Processing: $(basename $repo)"

        # Check if PHPStan is available
        if [ ! -f "vendor/bin/phpstan" ]; then
            echo "  Skipping: PHPStan not installed"
            continue
        fi

        # Generate baseline at target level
        vendor/bin/phpstan analyse \
            --level=$TARGET_LEVEL \
            --generate-baseline=phpstan-baseline.neon \
            src/

        # Commit baseline
        git add phpstan-baseline.neon
        git commit -m "Add PHPStan baseline at level $TARGET_LEVEL"

        echo "  ✓ Baseline generated and committed"
    fi
done
```

### Step 2: Level Discovery (Sample Repos)

**Run discovery on a representative sample:**

```bash
# Discover levels for 20 sample repos
for repo in horde-core horde-auth horde-util horde-support ...; do
    cd "$REPOS_DIR/$repo"
    horde-components qc-phpstan-discover --update
done
```

**Analyze results:**
- Distribution of levels (how many at 0, 1, 2, ... 9)
- Common error patterns
- Estimate effort for improvement

### Step 3: Categorize Repositories

**High Quality (Level 6+):**
- Can remove baseline immediately
- Enforce strict checking

**Medium Quality (Level 3-5):**
- Keep baseline initially
- Progressive improvement over 3-6 months

**Low Quality (Level 0-2):**
- Keep baseline long-term
- Focus on preventing new issues
- Major refactoring needed for improvement

### Step 4: Progressive Rollout

**Week 1-2: Pilot (10 repos)**
- High-quality components
- Test QC integration
- Gather feedback
- Refine documentation

**Week 3-4: Phase 1 (50 repos)**
- Medium/high quality
- Enable in CI/CD
- Monitor for issues

**Month 2: Phase 2 (100 repos)**
- Broader rollout
- Include lower quality repos
- Provide support for common issues

**Month 3: Phase 3 (Remaining repos)**
- Complete rollout
- All repos have PHPStan enabled
- Focus shifts to improvement

### Step 5: Continuous Improvement

**Monthly Tasks:**
- Review baselines (how many errors remain?)
- Fix issues in high-priority repos
- Increase levels where possible
- Remove baseline entries as issues are resolved

**Quarterly Goals:**
- 25% reduction in baseline errors
- Average level increase by 1
- Top 10 components at level 8+

**Annual Goals:**
- All components at level 4+
- 50% of components at level 6+
- Top 25% at level 8+

## Configuration File Templates

### Minimal phpstan.neon

```yaml
parameters:
    level: 5
    paths:
        - src
        - test
```

### Standard Horde phpstan.neon

```yaml
parameters:
    level: 5
    paths:
        - src
        - test

    # Exclude generated files
    excludePaths:
        - src/Migration/*
        - test/fixtures/*

    # Horde-specific settings
    treatPhpDocTypesAsCertain: false
    checkMissingIterableValueType: false

    # Use baseline if present
    includes:
        - phpstan-baseline.neon
```

### Advanced phpstan.neon (With Extensions)

```yaml
includes:
    - vendor/phpstan/phpstan-phpunit/extension.neon
    - vendor/phpstan/phpstan-strict-rules/rules.neon
    - phpstan-baseline.neon

parameters:
    level: 7
    paths:
        - src
        - test

    excludePaths:
        - src/Migration/*
        - test/fixtures/*

    ignoreErrors:
        # Ignore specific error patterns if needed
        - '#Call to an undefined method Horde\\.*#'

    treatPhpDocTypesAsCertain: false
    checkMissingIterableValueType: true
    checkGenericClassInNonGenericObjectType: true
    reportUnmatchedIgnoredErrors: true
```

## Error Handling

### Validation Errors

```php
public function validate(array $options = []): array
{
    $binary = $this->findPhpStanBinary();

    if ($binary === null) {
        return ['PHPStan is not installed!'];
    }

    return [];
}
```

### Runtime Errors

```php
public function run(array &$options = []): int
{
    $binary = $this->findPhpStanBinary();

    if ($binary === null) {
        $this->getOutput()->warn('PHPStan not found - skipping');
        return 0;  // Not an error, just skip
    }

    try {
        $componentPath = $this->_config->getPath();
        if (empty($componentPath)) {
            $componentPath = getcwd();
        }

        $this->detectVersion($binary);

        $level = $this->determineLevel($componentPath);
        $this->level = $level;

        $exitCode = $this->executePhpStan($binary, $componentPath, $level);

        if ($this->nativeResults !== null) {
            $this->parseResults();
            $this->writeJsonResults($componentPath, $exitCode, $level);
        }

        $this->outputStatistics();

        // Return number of errors (0 = success)
        return $this->stats['errors'];

    } catch (\Throwable $e) {
        $this->getOutput()->warn('PHPStan execution failed: ' . $e->getMessage());
        return 1;
    }
}
```

## Usage Examples

```bash
# Run default QC pipeline (includes PHPStan)
horde-components qc

# Run only PHPStan
horde-components qc phpstan

# Run PHPStan explicitly at a different level
horde-components qc phpstan --phpstan-level=6

# Discover and register optimal level
horde-components qc-phpstan-discover

# Explicitly run PHPMD (no longer default)
horde-components qc md

# Run both static analysis tools
horde-components qc phpstan md

# Generate baseline for current component
horde-components qc phpstan --generate-baseline

# Run with custom config
horde-components qc phpstan --phpstan-config=phpstan-strict.neon
```

## Future Enhancements (v2)

1. **Parallel Analysis** - Support PHPStan's parallel processing
2. **Extension Management** - Auto-detect and use PHPStan extensions
3. **Trend Tracking** - Track error count over time
4. **Baseline Diff** - Show what errors were added/removed from baseline
5. **Level Suggestion** - AI-based suggestions for achievable level increases
6. **Custom Rules** - Horde-specific PHPStan rules
7. **IDE Integration** - Export results in formats for PHPStorm/VSCode
8. **Incremental Analysis** - Only analyze changed files (git diff based)

## Success Criteria

- ✅ PHPStan task integrated into QC pipeline
- ✅ Detects PHPStan in all standard locations
- ✅ Respects existing phpstan.neon configuration
- ✅ Supports baseline files for legacy code
- ✅ Auto-determines appropriate level when config absent
- ✅ Outputs JSON results to build/ directory
- ✅ Shows version and source information
- ✅ Reports statistics clearly
- ✅ PHPMD moved to explicit-only
- ✅ Level registry management tools available
- ✅ Documentation complete
- ✅ Ready for 200-repo rollout

## Risk Mitigation

| Risk | Mitigation |
|------|-----------|
| PHPStan not installed | Validate in validate(), skip gracefully |
| Memory exhaustion | Set --memory-limit=512M by default |
| Slow analysis | Use --no-progress, consider caching strategy |
| Level too strict | Use progressive level strategy, baselines |
| Config parsing errors | Catch exceptions, fall back to defaults |
| JSON parsing issues | Use extractJson() method (proven pattern) |
| Breaking CI/CD | Make non-blocking initially, add baseline support |
| Resistance to adoption | Provide baseline generation, clear migration path |

## Conclusion

This plan provides a comprehensive strategy for:
1. ✅ **Technical Implementation** - PHPStan QC task with all features
2. ✅ **Level Management** - Flexible strategy for varying code quality
3. ✅ **Scalability** - Handles 200 repositories with different quality levels
4. ✅ **Migration Path** - Clear rollout strategy with baseline support
5. ✅ **Future Growth** - Progressive improvement over time

The combination of baseline support, level registry, and progressive discovery makes PHPStan adoption practical even for legacy code, while encouraging continuous improvement.
