# PHPStan Level Management via .horde.yml - Automated Watermark Strategy

## Overview

Instead of manual baseline management, leverage Horde's existing `.horde.yml` infrastructure to:
1. **Store PHPStan watermark** (highest passing level) in `.horde.yml`
2. **Automatically discover** and raise watermark when code improves
3. **Enforce watermark** in CI/CD (must pass current level)
4. **Aspirational checking** at level 9 (allowed to fail, shows potential)
5. **No baselines needed** - progressive level increases instead

This approach aligns with Horde's philosophy of centralized metadata and progressive improvement.

---

## The .horde.yml Watermark Approach

### Key Concept: Watermark vs Baseline

**Old Approach (Baselines):**
```
Level 5 enforced
247 errors ignored in baseline
No visibility into what needs fixing
```

**New Approach (Watermark):**
```
Level 5 enforced (watermark in .horde.yml)
Level 6 fails with 42 errors (visible)
Level 9 shows 247 potential improvements (aspirational)
Auto-raise watermark when level 6 passes
```

### Benefits

✅ **Single source of truth** - `.horde.yml` controls everything
✅ **Progressive improvement** - watermark rises automatically
✅ **Clear targets** - know exactly what needs fixing for next level
✅ **No baseline debt** - errors are visible, not hidden
✅ **Automated** - no manual intervention needed
✅ **Consistent** - same approach across all 200 repos

---

## .horde.yml Schema Extension

### Current .horde.yml Structure

```yaml
---
id: components
name: Components
full: Developer tool for managing Horde components
description: >-
  The package provides utility methods required when preparing a new component
  release for Horde.
list: horde
type: component
authors:
  - name: Ralf Lang
    email: ralf.lang@ralf-lang.de
```

### Proposed Extension: quality Section

```yaml
---
id: components
name: Components
full: Developer tool for managing Horde components
description: >-
  The package provides utility methods required when preparing a new component
  release for Horde.
list: horde
type: component

# NEW: Quality control configuration
quality:
  phpstan:
    # Current enforced level (watermark)
    level: 5

    # History tracking
    level_history:
      - level: 5
        achieved: "2026-02-27"
        errors_at_next: 42

      - level: 4
        achieved: "2026-01-15"
        errors_at_next: 89

      - level: 3
        achieved: "2025-12-01"
        errors_at_next: 156

    # Auto-raise configuration
    auto_raise: true

    # Custom configuration
    config: "phpstan.neon"  # Optional

    # Paths to analyze (default: src/)
    paths:
      - src
      - test

    # Aspirational target (always check, allow failure)
    aspirational_level: 9

  # Other tools can be added later
  phpcs:
    standard: "Horde"

  phpmd:
    ruleset: "data/qc_standards/phpmd.xml"
```

---

## Advantages Over Baseline Approach

### Comparison Table

| Aspect | Baseline Approach | Watermark Approach |
|--------|-------------------|-------------------|
| **Visibility** | Errors hidden in baseline | Errors visible (at next level) |
| **Technical Debt** | Can grow unchecked | Always visible |
| **Progress** | Hard to measure | Clear (watermark level) |
| **Automation** | Manual baseline regeneration | Auto-raise watermark |
| **CI/CD** | Need baseline file in repo | Just .horde.yml |
| **Maintenance** | Baseline can become stale | Watermark always current |
| **Psychology** | "Ignoring problems" | "Progressive improvement" |
| **Tooling** | Need baseline diff tools | Built into .horde.yml |
| **History** | Not tracked | Tracked in level_history |
| **Aspirational** | Not supported | Level 9 check built-in |

### Why Watermark > Baseline for Horde

1. **Existing Infrastructure** - Already using `.horde.yml` as source of truth
2. **200 Repos** - Centralized management > 200 baseline files
3. **Progressive Culture** - Matches Horde's incremental improvement approach
4. **Automation** - Auto-raise reduces manual intervention
5. **Visibility** - Developers see what needs fixing (not hidden)
6. **Metrics** - Easy to track progress across all repos
7. **No Debt** - Can't accumulate "baseline debt"

---

## Implementation Components

### 1. .horde.yml Parser Enhancement

```php
// In Component/Source.php or new QualityConfig.php

class QualityConfig
{
    private array $hordeYml;

    public function __construct(array $hordeYml)
    {
        $this->hordeYml = $hordeYml;
    }

    /**
     * Get current PHPStan watermark level.
     */
    public function getPhpStanLevel(): int
    {
        return $this->hordeYml['quality']['phpstan']['level'] ?? 4;
    }

    /**
     * Get aspirational level for non-blocking checks.
     */
    public function getPhpStanAspirationalLevel(): ?int
    {
        return $this->hordeYml['quality']['phpstan']['aspirational_level'] ?? null;
    }

    /**
     * Check if auto-raise is enabled.
     */
    public function isAutoRaiseEnabled(): bool
    {
        return $this->hordeYml['quality']['phpstan']['auto_raise'] ?? false;
    }

    /**
     * Get PHPStan level history.
     */
    public function getPhpStanHistory(): array
    {
        return $this->hordeYml['quality']['phpstan']['level_history'] ?? [];
    }

    /**
     * Get errors at next level from latest history entry.
     */
    public function getErrorsAtNextLevel(): ?int
    {
        $history = $this->getPhpStanHistory();

        if (empty($history)) {
            return null;
        }

        return $history[0]['errors_at_next'] ?? null;
    }
}
```

### 2. Enhanced PHPStan Task Level Detection

```php
// In Qc/Task/Phpstan.php

private function determineLevel(string $componentPath): int
{
    // Strategy 1: Read from .horde.yml (NEW - highest priority)
    try {
        $component = $this->getComponent();
        $hordeYml = $component->getHordeYml();

        if (isset($hordeYml['quality']['phpstan']['level'])) {
            $level = (int) $hordeYml['quality']['phpstan']['level'];
            $this->getOutput()->info('Using level ' . $level . ' from: .horde.yml (watermark)');
            return $level;
        }
    } catch (\Throwable $e) {
        // Fall through to next strategy
    }

    // Strategy 2: Read from phpstan.neon
    $configPath = $this->findConfiguration($componentPath);
    if ($configPath !== null) {
        $this->configPath = $configPath;
        $level = $this->parseLevelFromConfig($configPath);
        if ($level !== null) {
            $this->getOutput()->info('Using level ' . $level . ' from: ' . basename($configPath));
            return $level;
        }
    }

    // Strategy 3: Safe default
    $this->getOutput()->info('Using default level 4');
    return 4;
}
```

### 3. Auto-Raise Logic

```php
// In Qc/Task/Phpstan.php - called after successful run

private function checkAutoRaise(string $componentPath, int $currentLevel): void
{
    try {
        $component = $this->getComponent();
        $hordeYml = $component->getHordeYml();

        // Check if auto-raise is enabled
        $autoRaise = $hordeYml['quality']['phpstan']['auto_raise'] ?? false;

        if (!$autoRaise) {
            return;
        }

        // Current level passed - check if next level also passes
        $nextLevel = $currentLevel + 1;

        if ($nextLevel > 9) {
            return; // Already at max level
        }

        $this->getOutput()->info("Auto-raise enabled - checking level $nextLevel...");

        $binary = $this->findPhpStanBinary();
        $result = $this->testLevel($binary, $componentPath, $nextLevel);

        if ($result['passed']) {
            $this->getOutput()->ok("Level $nextLevel passes! Auto-raising watermark...");

            // Update .horde.yml would go here
            // (Requires YAML writing capability)

            $this->getOutput()->ok("✓ Watermark raised: $currentLevel → $nextLevel");
            $this->getOutput()->info("Commit .horde.yml to persist this change");

        } else {
            $errors = $result['errors'];
            $this->getOutput()->info(
                "Level $nextLevel would have $errors error" . ($errors !== 1 ? 's' : '') .
                " - watermark stays at $currentLevel"
            );
        }

    } catch (\Throwable $e) {
        // Don't fail the build if auto-raise fails
        $this->getOutput()->warn('Auto-raise check failed: ' . $e->getMessage());
    }
}
```

### 4. Aspirational Level Checking

```php
// In Qc/Task/Phpstan.php - run after main check

private function runAspirationalCheck(string $componentPath, int $currentLevel): void
{
    try {
        $component = $this->getComponent();
        $hordeYml = $component->getHordeYml();

        $aspirationalLevel = $hordeYml['quality']['phpstan']['aspirational_level'] ?? null;

        if ($aspirationalLevel === null || $aspirationalLevel <= $currentLevel) {
            return; // No aspirational level or already at it
        }

        $this->getOutput()->info('');
        $this->getOutput()->info('Running aspirational check at level ' . $aspirationalLevel . '...');

        $binary = $this->findPhpStanBinary();
        $result = $this->testLevel($binary, $componentPath, $aspirationalLevel);

        if ($result['passed']) {
            $this->getOutput()->ok(
                "✨ AMAZING! Code passes level $aspirationalLevel! " .
                "Consider updating watermark in .horde.yml"
            );
        } else {
            $errors = $result['errors'];
            $this->getOutput()->info(
                "Aspirational level $aspirationalLevel: $errors error" .
                ($errors !== 1 ? 's' : '') . ' remaining (not blocking)'
            );

            // Show progress toward aspirational level
            $progressBar = $this->generateProgressBar($currentLevel, $aspirationalLevel);
            $this->getOutput()->plain($progressBar);
        }

    } catch (\Throwable $e) {
        // Don't fail the build if aspirational check fails
        $this->getOutput()->warn('Aspirational check failed: ' . $e->getMessage());
    }
}

private function generateProgressBar(int $current, int $target): string
{
    $bar = 'Progress to level ' . $target . ': [';

    for ($i = 0; $i <= $target; $i++) {
        if ($i < $current) {
            $bar .= '█';
        } elseif ($i === $current) {
            $bar .= '▓';
        } else {
            $bar .= '░';
        }
    }

    $bar .= '] ' . $current . '/' . $target;

    return $bar;
}
```

---

## New Commands

### 1. Discover Watermark
```bash
horde-components qc-phpstan-discover
```
Tests levels 9→0, finds highest passing, updates `.horde.yml`

### 2. Show Status
```bash
horde-components qc-phpstan-status
```
Shows current watermark, history, progress to aspirational level

### 3. Test Specific Level
```bash
horde-components qc-phpstan-test --level=7
```
Tests a specific level without updating watermark

### 4. Organization Progress
```bash
horde-components qc-phpstan-progress --all
```
Shows watermark distribution across all 200 repos

---

## CI/CD Integration

### GitHub Actions Example

```yaml
name: PHPStan Quality Check
on: [push, pull_request]

jobs:
  phpstan-watermark:
    name: PHPStan (Enforce Watermark)
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v3

      - name: Install dependencies
        run: composer install

      - name: Run PHPStan (watermark level)
        run: horde-components qc phpstan

      - name: Check for auto-raised level
        id: check-auto-raise
        run: |
          if git diff --quiet .horde.yml; then
            echo "changed=false" >> $GITHUB_OUTPUT
          else
            echo "changed=true" >> $GITHUB_OUTPUT
          fi

      - name: Create PR for watermark increase
        if: steps.check-auto-raise.outputs.changed == 'true'
        uses: peter-evans/create-pull-request@v5
        with:
          title: "🎉 Auto-raise PHPStan watermark"
          body: |
            PHPStan detected that the code now passes a higher level!
            This PR updates `.horde.yml` to reflect the new watermark.
          branch: phpstan-auto-raise

  phpstan-aspirational:
    name: PHPStan (Level 9 - Aspirational)
    runs-on: ubuntu-latest
    continue-on-error: true  # Allow failure

    steps:
      - uses: actions/checkout@v3
      - name: Install dependencies
        run: composer install
      - name: Run PHPStan at level 9
        run: vendor/bin/phpstan analyse --level=9 src/
```

---

## Migration from Hardcoded Levels

### Extract from CI

```bash
#!/bin/bash
# Extract current levels from GitHub Actions

for repo in repos/*/; do
    cd "$repo"

    if [ -f .github/workflows/phpstan.yml ]; then
        level=$(grep -oP 'level[=: ]+\K\d+' .github/workflows/phpstan.yml | head -1)
        echo "$(basename $repo): level $level"
    fi

    cd ..
done
```

### Initialize .horde.yml

```bash
# For each repo
horde-components migrate-quality-config

# This will:
# 1. Extract level from CI config
# 2. Add quality section to .horde.yml
# 3. Commit changes
```

### Update CI to Read from .horde.yml

```yaml
- name: Extract PHPStan level
  id: level
  run: |
    level=$(yq eval '.quality.phpstan.level' .horde.yml)
    echo "level=$level" >> $GITHUB_OUTPUT

- name: Run PHPStan
  run: |
    vendor/bin/phpstan analyse --level=${{ steps.level.outputs.level }} src/
```

---

## Rollout Timeline

### Phase 1: Foundation (Week 1-2)
- [ ] Implement `.horde.yml` quality section parsing
- [ ] Update PHPStan task to read watermark
- [ ] Create discovery command
- [ ] Test on pilot repos

### Phase 2: Migration (Week 3-4)
- [ ] Extract levels from all 200 CI configs
- [ ] Initialize quality sections in all `.horde.yml`
- [ ] Update CI workflows
- [ ] Commit changes

### Phase 3: Auto-Raise (Week 5-6)
- [ ] Implement auto-raise logic
- [ ] Add CI integration
- [ ] Enable on high-quality repos
- [ ] Monitor results

### Phase 4: Aspirational (Week 7-8)
- [ ] Implement aspirational checking
- [ ] Add level 9 CI jobs
- [ ] Create progress dashboards
- [ ] Enable everywhere

### Phase 5: Full Rollout (Week 9-12)
- [ ] Enable auto-raise on all 200 repos
- [ ] Create org-wide dashboard
- [ ] Schedule periodic checks
- [ ] Document for maintainers

---

## Success Metrics

### Month 1
- ✅ All 200 repos have watermark in `.horde.yml`
- ✅ CI reads from `.horde.yml`
- ✅ Discovery tool working

### Month 3
- ✅ 10% of repos auto-raised
- ✅ Average watermark +0.5 levels
- ✅ Aspirational checks running

### Month 6
- ✅ 25% of repos auto-raised
- ✅ Average watermark +1 level
- ✅ 10% of repos at level 9

### Year 1
- ✅ 50% of repos auto-raised
- ✅ Average watermark at level 6+
- ✅ 25% of repos at level 9

---

## Conclusion

The watermark approach via `.horde.yml` is **superior to baselines** for Horde because:

1. ✅ **Aligns with existing infrastructure** - `.horde.yml` is already source of truth
2. ✅ **Scales to 200 repos** - Centralized, consistent, automated
3. ✅ **Progressive by design** - Watermark rises automatically
4. ✅ **Visible progress** - Current level + next goal always clear
5. ✅ **No hidden debt** - Errors visible at next level, not hidden in baseline
6. ✅ **Automated** - Auto-raise eliminates manual intervention
7. ✅ **Aspirational built-in** - Level 9 checks show ultimate potential

**Recommendation:** Implement watermark approach. Skip baselines entirely for Horde's use case.

PHPStan itself can determine highest passing level via testing each level from 9→0 until one passes. This integrates perfectly with the watermark strategy - discover once, auto-raise as code improves, aspirational check shows ultimate goal.
