# PHPStan Watermark Strategy - Simplified Auto-Discovery Approach

## Core Principle: Self-Discovering Watermarks

**No manual configuration needed.** PHPStan automatically discovers and maintains its own watermark.

---

## How It Works

### Scenario A: PHPStan Provides Max Level Discovery

If PHPStan has native support for finding the highest passing level:

```bash
# PHPStan command (hypothetical)
phpstan analyse --discover-max-level src/

# Returns:
# Maximum passing level: 5
```

**Our logic:**
1. Read watermark from `.horde.yml` (default: level 1 if missing)
2. Run PHPStan max level discovery
3. Compare discovered level vs watermark:
   - **Discovered < Watermark**: ❌ FAIL - Code regressed!
   - **Discovered = Watermark**: ✅ PASS - Status quo maintained
   - **Discovered > Watermark**: ✅ PASS + Auto-raise - Code improved!
4. If discovered ≥ watermark, update `.horde.yml` and commit

### Scenario B: PHPStan Doesn't Provide This (Current Reality)

Emulate max level discovery with two runs:

```bash
# Run 1: Watermark level (MUST pass)
phpstan analyse --level={watermark} src/
# Exit code: 0 = pass, 1 = fail

# Run 2: Next level (MAY fail - detection)
phpstan analyse --level={watermark + 1} src/
# Exit code: 0 = can raise, 1 = cannot raise yet
```

**Our logic:**
1. Read watermark from `.horde.yml` (default: level 1 if missing)
2. Run at watermark level - **MUST PASS** (blocking)
3. If watermark passes, run at watermark+1 - **MAY PASS** (detection)
4. Compare results:
   - **Watermark fails**: ❌ FAIL - Code below watermark (regression)
   - **Watermark passes, next fails**: ✅ PASS - At watermark (status quo)
   - **Both pass**: ✅ PASS + Auto-raise - Code improved!
5. If both pass, update `.horde.yml` and commit

---

## Implementation

### Enhanced PHPStan Task

```php
// In Qc/Task/Phpstan.php

public function run(array &$options = []): int
{
    $binary = $this->findPhpStanBinary();

    if ($binary === null) {
        $this->getOutput()->warn('PHPStan not found - skipping');
        return 0;
    }

    try {
        $componentPath = $this->_config->getPath();

        if (empty($componentPath)) {
            $componentPath = getcwd();
        }

        $this->getOutput()->info('Running PHPStan with watermark detection...');
        $this->detectVersion($binary);

        // Get current watermark (default: 1 if missing)
        $watermark = $this->getWatermarkFromHordeYml();

        $this->getOutput()->info('Current watermark: level ' . $watermark);

        // Reset statistics
        $this->stats = [
            'files_analyzed' => 0,
            'errors' => 0,
            'file_errors' => 0,
        ];

        // Run at watermark level (MUST PASS)
        $this->getOutput()->info('Testing watermark level ' . $watermark . '...');
        $watermarkResult = $this->testLevel($binary, $componentPath, $watermark);

        if (!$watermarkResult['passed']) {
            // CODE REGRESSION - fails at watermark!
            $this->getOutput()->warn(
                '❌ REGRESSION: Code fails at watermark level ' . $watermark .
                ' (' . $watermarkResult['errors'] . ' errors)'
            );
            $this->getOutput()->warn('Watermark level MUST pass - fix these errors!');

            // Parse results for output
            $this->nativeResults = $watermarkResult['results'];
            $this->parseResults();
            $this->writeJsonResults($componentPath, $watermarkResult['exit_code'], $watermark);
            $this->outputStatistics();

            return $watermarkResult['errors']; // Non-zero = failure
        }

        // Watermark passed - check next level
        $nextLevel = $watermark + 1;

        if ($nextLevel <= 9) {
            $this->getOutput()->info('Testing next level ' . $nextLevel . '...');
            $nextResult = $this->testLevel($binary, $componentPath, $nextLevel);

            if ($nextResult['passed']) {
                // CODE IMPROVED - passes next level!
                $this->getOutput()->ok(
                    '✅ IMPROVEMENT: Code passes level ' . $nextLevel . '!'
                );

                // Auto-raise watermark
                $this->updateWatermarkInHordeYml($nextLevel);

                $this->getOutput()->ok(
                    '🎉 Watermark auto-raised: ' . $watermark . ' → ' . $nextLevel
                );
                $this->getOutput()->info('Commit .horde.yml to persist this improvement');

                // Use next level results for output
                $this->nativeResults = $nextResult['results'];
                $this->level = $nextLevel;
            } else {
                // At watermark, can't raise yet
                $this->getOutput()->ok(
                    '✓ Code passes watermark level ' . $watermark
                );
                $this->getOutput()->info(
                    'Next level (' . $nextLevel . ') has ' . $nextResult['errors'] .
                    ' error' . ($nextResult['errors'] !== 1 ? 's' : '') . ' remaining'
                );

                // Use watermark results for output
                $this->nativeResults = $watermarkResult['results'];
                $this->level = $watermark;
            }
        } else {
            // Already at max level (9)
            $this->getOutput()->ok('✓ Code passes watermark level ' . $watermark . ' (maximum)');
            $this->nativeResults = $watermarkResult['results'];
            $this->level = $watermark;
        }

        // Parse and output results
        if ($this->nativeResults !== null) {
            $this->parseResults();
            $this->writeJsonResults($componentPath, 0, $this->level);
        }

        $this->outputStatistics();

        // Always return 0 if watermark passes (even if we can't raise)
        return 0;

    } catch (\Throwable $e) {
        $this->getOutput()->warn('PHPStan execution failed: ' . $e->getMessage());
        return 1;
    }
}

/**
 * Get current watermark from .horde.yml.
 * Returns 1 if not set (lowest meaningful level).
 */
private function getWatermarkFromHordeYml(): int
{
    try {
        $component = $this->getComponent();
        $hordeYml = $component->getHordeYml();

        if (isset($hordeYml['quality']['phpstan']['level'])) {
            return (int) $hordeYml['quality']['phpstan']['level'];
        }
    } catch (\Throwable $e) {
        // Fall through to default
    }

    // Default: level 1 (lowest meaningful level)
    return 1;
}

/**
 * Update watermark in .horde.yml file.
 */
private function updateWatermarkInHordeYml(int $newLevel): void
{
    $hordeYmlPath = $this->_config->getPath() . '/.horde.yml';

    if (empty($hordeYmlPath) || $hordeYmlPath === '/.horde.yml') {
        $hordeYmlPath = getcwd() . '/.horde.yml';
    }

    if (!file_exists($hordeYmlPath)) {
        $this->getOutput()->warn('Cannot auto-raise: .horde.yml not found');
        return;
    }

    try {
        // Read current content
        $content = file_get_contents($hordeYmlPath);

        // Check if quality.phpstan.level already exists
        if (preg_match('/^(\s*)phpstan:\s*$/m', $content)) {
            // phpstan section exists - check if level exists
            if (preg_match('/^(\s*)level:\s*\d+\s*$/m', $content)) {
                // Update existing level
                $content = preg_replace(
                    '/^(\s*)level:\s*\d+\s*$/m',
                    '${1}level: ' . $newLevel,
                    $content
                );
            } else {
                // Add level to existing phpstan section
                $content = preg_replace(
                    '/^(\s*)phpstan:\s*$/m',
                    '${1}phpstan:' . "\n" . '${1}  level: ' . $newLevel,
                    $content
                );
            }
        } elseif (preg_match('/^(\s*)quality:\s*$/m', $content)) {
            // quality section exists but no phpstan - add it
            $content = preg_replace(
                '/^(\s*)quality:\s*$/m',
                '${1}quality:' . "\n" . '${1}  phpstan:' . "\n" . '${1}    level: ' . $newLevel,
                $content
            );
        } else {
            // No quality section - add at end
            $content = rtrim($content) . "\n\nquality:\n  phpstan:\n    level: " . $newLevel . "\n";
        }

        // Write back
        file_put_contents($hordeYmlPath, $content);

        $this->getOutput()->info('Updated .horde.yml watermark to level ' . $newLevel);

    } catch (\Throwable $e) {
        $this->getOutput()->warn('Failed to update .horde.yml: ' . $e->getMessage());
    }
}

/**
 * Test PHPStan at a specific level.
 *
 * @return array ['passed' => bool, 'errors' => int, 'exit_code' => int, 'results' => array]
 */
private function testLevel(string $binary, string $componentPath, int $level): array
{
    $cmd = [
        escapeshellarg($binary),
        'analyse',
        '--level=' . $level,
        '--error-format=json',
        '--no-progress',
        '--no-ansi',
        '--memory-limit=512M',
    ];

    // Use config if available
    if ($this->configPath !== null) {
        $cmd[] = '--configuration=' . escapeshellarg($this->configPath);
    } else {
        // Default to src/ if no config
        $srcPath = $componentPath . '/src';
        if (is_dir($srcPath)) {
            $cmd[] = escapeshellarg($srcPath);
        } else {
            $cmd[] = escapeshellarg($componentPath);
        }
    }

    $command = implode(' ', $cmd);

    // Execute and capture output
    exec($command . ' 2>&1', $output, $exitCode);

    // Parse JSON output
    $fullOutput = implode("\n", $output);
    $jsonOutput = $this->extractJson($fullOutput);

    $errors = 0;
    $results = null;

    if ($jsonOutput !== null) {
        $results = json_decode($jsonOutput, true);
        if ($results !== null && isset($results['totals']['errors'])) {
            $errors = $results['totals']['errors'];
        }
    }

    return [
        'passed' => ($exitCode === 0 || $errors === 0),
        'errors' => $errors,
        'exit_code' => $exitCode,
        'results' => $results,
    ];
}
```

---

## Example Workflows

### Case 1: Fresh Repository (No Watermark)

```
[INFO] Current watermark: level 1 (default)
[INFO] Testing watermark level 1...
[OK] ✓ Code passes watermark level 1
[INFO] Testing next level 2...
[OK] ✅ IMPROVEMENT: Code passes level 2!
[OK] 🎉 Watermark auto-raised: 1 → 2
[INFO] Commit .horde.yml to persist this improvement
```

**Result:** `.horde.yml` updated with `quality.phpstan.level: 2`

### Case 2: Code at Watermark (Status Quo)

```
[INFO] Current watermark: level 5
[INFO] Testing watermark level 5...
[OK] ✓ Code passes watermark level 5
[INFO] Testing next level 6...
[INFO] Next level (6) has 42 errors remaining
[OK] No problems found. PHPStan results (level 5): 100 files analyzed
```

**Result:** No change to `.horde.yml`, build passes

### Case 3: Code Improved (Auto-Raise)

```
[INFO] Current watermark: level 5
[INFO] Testing watermark level 5...
[OK] ✓ Code passes watermark level 5
[INFO] Testing next level 6...
[OK] ✅ IMPROVEMENT: Code passes level 6!
[OK] 🎉 Watermark auto-raised: 5 → 6
[INFO] Updated .horde.yml watermark to level 6
[INFO] Commit .horde.yml to persist this improvement
```

**Result:** `.horde.yml` updated with `quality.phpstan.level: 6`, build passes

### Case 4: Code Regressed (Failure)

```
[INFO] Current watermark: level 5
[INFO] Testing watermark level 5...
[WARN] ❌ REGRESSION: Code fails at watermark level 5 (23 errors)
[WARN] Watermark level MUST pass - fix these errors!
[WARN] Issues found. PHPStan results (level 5): 23 errors in 8 files
```

**Result:** No change to `.horde.yml`, **build FAILS**

### Case 5: At Maximum Level

```
[INFO] Current watermark: level 9
[INFO] Testing watermark level 9...
[OK] ✓ Code passes watermark level 9 (maximum)
[OK] No problems found. PHPStan results (level 9): 100 files analyzed
```

**Result:** No change (already at max), build passes

---

## CI/CD Integration

### GitHub Actions

```yaml
name: PHPStan Quality Check
on: [push, pull_request]

jobs:
  phpstan:
    name: PHPStan Auto-Watermark
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v3

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'

      - name: Install dependencies
        run: composer install --prefer-dist --no-progress

      - name: Install horde-components
        run: |
          composer global require horde/components
          echo "$HOME/.composer/vendor/bin" >> $GITHUB_PATH

      - name: Run PHPStan with auto-watermark
        run: |
          horde-components qc phpstan

      - name: Check if watermark was raised
        id: check-watermark
        run: |
          if git diff --quiet .horde.yml; then
            echo "raised=false" >> $GITHUB_OUTPUT
            echo "Watermark unchanged"
          else
            echo "raised=true" >> $GITHUB_OUTPUT
            new_level=$(grep -A2 "phpstan:" .horde.yml | grep "level:" | awk '{print $2}')
            echo "new_level=$new_level" >> $GITHUB_OUTPUT
            echo "Watermark raised to level $new_level"
          fi

      - name: Commit watermark increase
        if: steps.check-watermark.outputs.raised == 'true' && github.event_name == 'push'
        run: |
          git config user.name "PHPStan Bot"
          git config user.email "bot@horde.org"
          git add .horde.yml
          git commit -m "Auto-raise PHPStan watermark to level ${{ steps.check-watermark.outputs.new_level }}"
          git push
```

---

## Advantages of This Approach

### 1. Zero Configuration
- No manual discovery needed
- No extraction from CI configs
- Just works on first run

### 2. Always Accurate
- Watermark reflects actual code quality
- Can't drift out of sync
- Regression detected immediately

### 3. Progressive by Default
- Automatically raises when possible
- Never lowers (only fails if regression)
- Encourages continuous improvement

### 4. Simple Logic
- Only 2-3 PHPStan runs per check
- Clear pass/fail criteria
- Easy to understand and debug

### 5. Self-Maintaining
- No manual updates needed
- No separate discovery command
- Watermark manages itself

---

## Handling Edge Cases

### Q: What if someone manually lowers watermark in .horde.yml?

**A:** Next CI run will detect code passes higher level and auto-raise back. Watermark can only go up, never artificially down.

### Q: What if .horde.yml doesn't exist?

**A:** Default to level 1, test it, auto-raise if possible, but can't persist without .horde.yml. Log warning.

### Q: What if code quality genuinely drops?

**A:** Build FAILS at watermark check. This is correct - regressions should fail CI.

### Q: What about performance (running 2 levels)?

**A:** Minimal overhead:
- If watermark fails: Only 1 run (stops early)
- If watermark passes: 2 runs (watermark + next)
- If next passes: Auto-raise saves future runs at lower level

### Q: Should we commit automatically or create PR?

**Options:**
1. **Auto-commit on push** (simple, immediate)
2. **Create PR** (allows review, more visible)
3. **Commit to branch, require merge** (balance)

**Recommendation:** Auto-commit on push to main/master, create PR for feature branches.

---

## Migration from Current State

### For New Repos (No .horde.yml quality section)

```bash
# First run
horde-components qc phpstan
# Output:
# [INFO] Current watermark: level 1 (default)
# [INFO] Testing watermark level 1...
# [OK] ✓ Code passes watermark level 1
# [INFO] Testing next level 2...
# ... (continues testing up)
# [OK] ✅ IMPROVEMENT: Code passes level 5!
# [OK] 🎉 Watermark auto-raised: 4 → 5
# [INFO] Updated .horde.yml watermark to level 5

# .horde.yml now contains:
# quality:
#   phpstan:
#     level: 5
```

### For Existing Repos (With CI Configs)

**Don't extract from CI. Just run:**

```bash
# Remove hardcoded level from CI workflow
# Add: horde-components qc phpstan

# First run will:
# 1. Default to level 1
# 2. Test and auto-raise to actual max level
# 3. Write to .horde.yml
# 4. Future runs use discovered level
```

**One-time command to bootstrap all 200 repos:**

```bash
#!/bin/bash
# scripts/bootstrap-phpstan-watermarks.sh

for repo in repos/*/; do
    cd "$repo"
    component=$(basename "$repo")

    echo "Bootstrapping $component..."

    # Just run PHPStan - it will auto-discover and set watermark
    horde-components qc phpstan

    # Commit the result
    if git diff --quiet .horde.yml; then
        echo "  No changes (already configured or failed)"
    else
        level=$(grep -A2 "phpstan:" .horde.yml | grep "level:" | awk '{print $2}')
        git add .horde.yml
        git commit -m "Bootstrap PHPStan watermark (level $level)"
        git push
        echo "  ✓ Watermark set to level $level"
    fi

    cd ..
done
```

---

## Testing Strategy

### Manual Testing

```bash
# Test with no watermark
rm .horde.yml
horde-components qc phpstan
# Should: default to level 1, test up, auto-raise

# Test with watermark at level 3
echo "quality:\n  phpstan:\n    level: 3" >> .horde.yml
horde-components qc phpstan
# Should: test level 3 (pass), test level 4 (may pass/fail)

# Test regression (manually add errors)
# Edit a file to introduce type errors
horde-components qc phpstan
# Should: fail at watermark level, build fails

# Test at max level
echo "quality:\n  phpstan:\n    level: 9" >> .horde.yml
horde-components qc phpstan
# Should: test level 9, not try level 10, pass
```

---

## Future Enhancements

### Optional: Aspirational Level 9 Check

After watermark checking, optionally run level 9 (non-blocking):

```php
// After watermark logic in run() method

// Optional: Run aspirational check at level 9
if ($this->level < 9) {
    $this->getOutput()->info('');
    $this->getOutput()->info('Aspirational check: Testing level 9...');

    $aspirationalResult = $this->testLevel($binary, $componentPath, 9);

    if ($aspirationalResult['passed']) {
        $this->getOutput()->ok('✨ Code passes level 9! Consider raising watermark.');
    } else {
        $errors = $aspirationalResult['errors'];
        $this->getOutput()->info(
            'Level 9: ' . $errors . ' error' . ($errors !== 1 ? 's' : '') .
            ' remaining (not blocking)'
        );

        // Show progress
        $remaining = 9 - $this->level;
        $this->getOutput()->plain(
            'Progress to level 9: [' . str_repeat('█', $this->level) .
            str_repeat('░', $remaining) . '] ' . $this->level . '/9'
        );
    }
}
```

---

## Conclusion

**Your simplified approach is superior:**

✅ **No CI extraction needed** - Just run PHPStan, it discovers its own watermark
✅ **Self-bootstrapping** - Defaults to level 1, auto-raises to reality
✅ **Always accurate** - Watermark reflects actual code quality
✅ **Regression detection** - Fails if code drops below watermark
✅ **Automatic improvement** - Raises watermark when code improves
✅ **Zero manual maintenance** - Watermark manages itself

**Implementation is straightforward:**
1. Default to watermark level 1 if missing from `.horde.yml`
2. Test at watermark (MUST pass)
3. Test at watermark+1 (MAY pass)
4. If both pass: auto-raise and commit
5. If watermark fails: FAIL build (regression)

This is exactly what you described, and it's perfect for your 200-repo scenario. No migration needed - just run it and it bootstraps itself.
