# PHPStan Baseline Management Strategy

## Overview

PHPStan baselines are a powerful tool for adopting strict static analysis in legacy codebases. They allow you to "ignore" existing issues while preventing new ones from being introduced. However, without proper management, baselines can become permanent technical debt. This document outlines strategies for maintaining baselines effectively across 200 Horde repositories.

## The Baseline Problem

### What Baselines Do
- Capture all current PHPStan errors in a snapshot file (`phpstan-baseline.neon`)
- Allow these errors to be ignored during analysis
- New errors (not in baseline) still fail the build
- Prevents "let's fix everything first" blocking adoption

### The Risk
**Baselines can become permanent if not actively managed:**
- Developers forget baseline exists
- No visibility into baseline size/growth
- No process for reducing baseline
- Baseline errors never get fixed
- Technical debt accumulates

### The Goal
**Baseline should be a temporary scaffolding, not permanent:**
- Baseline size should trend downward over time
- New code should not add to baseline
- Legacy code should be improved incrementally
- Eventually: remove baseline entirely

## Baseline Management Strategies

### Strategy 1: Visibility & Metrics

**Problem:** What you don't measure, you don't improve.

**Solution:** Track baseline size and report on it.

#### Implementation: Baseline Statistics

Add baseline reporting to PHPStan task:

```php
// In Phpstan.php task

private array $baselineStats = [
    'baseline_exists' => false,
    'baseline_errors' => 0,
    'baseline_files' => 0,
];

private function analyzeBaseline(string $componentPath): void
{
    $baselinePath = $componentPath . '/phpstan-baseline.neon';

    if (!file_exists($baselinePath)) {
        return;
    }

    $this->baselineStats['baseline_exists'] = true;

    $content = file_get_contents($baselinePath);

    // Parse NEON baseline format
    // PHPStan baselines look like:
    // parameters:
    //     ignoreErrors:
    //         -
    //             message: "#^Parameter \\$foo.*#"
    //             count: 1
    //             path: src/Example.php

    // Count total errors
    if (preg_match_all('/count:\s*(\d+)/', $content, $matches)) {
        $this->baselineStats['baseline_errors'] = array_sum(array_map('intval', $matches[1]));
    }

    // Count affected files
    if (preg_match_all('/path:\s*(.+)/', $content, $matches)) {
        $files = array_unique($matches[1]);
        $this->baselineStats['baseline_files'] = count($files);
    }
}

private function outputBaselineWarning(): void
{
    if (!$this->baselineStats['baseline_exists']) {
        return;
    }

    $errors = $this->baselineStats['baseline_errors'];
    $files = $this->baselineStats['baseline_files'];

    $this->getOutput()->warn(
        'Baseline active: ' . $errors . ' error' . ($errors !== 1 ? 's' : '') .
        ' ignored in ' . $files . ' file' . ($files !== 1 ? 's' : '')
    );

    if ($errors > 100) {
        $this->getOutput()->warn(
            'Large baseline detected - consider incremental fixes (run: horde-components qc-phpstan-baseline-report)'
        );
    }
}
```

#### Example Output

```
[OK] No problems found. PHPStan results (level 5): 100 files analyzed
[WARN] Baseline active: 247 errors ignored in 34 files
```

This makes baseline visible on every run, creating awareness.

---

### Strategy 2: Baseline Reporting Tool

**Problem:** No easy way to see what's in the baseline.

**Solution:** Create a reporting tool that shows baseline breakdown.

#### Implementation: Baseline Report Command

```php
// New command: horde-components qc-phpstan-baseline-report

class PhpstanBaselineReport
{
    public function run(): void
    {
        $baselinePath = getcwd() . '/phpstan-baseline.neon';

        if (!file_exists($baselinePath)) {
            $this->output->info('No baseline file found');
            return;
        }

        $analysis = $this->analyzeBaseline($baselinePath);

        $this->output->info('PHPStan Baseline Report');
        $this->output->info('======================');
        $this->output->info('');
        $this->output->info('Total errors: ' . $analysis['total_errors']);
        $this->output->info('Affected files: ' . $analysis['total_files']);
        $this->output->info('');

        // Show breakdown by error type
        $this->output->info('Error breakdown:');
        arsort($analysis['by_type']);
        foreach ($analysis['by_type'] as $type => $count) {
            $this->output->plain('  ' . $count . 'x ' . $type);
        }

        $this->output->info('');

        // Show most problematic files
        $this->output->info('Most problematic files:');
        arsort($analysis['by_file']);
        $top10 = array_slice($analysis['by_file'], 0, 10, true);
        foreach ($top10 as $file => $count) {
            $this->output->plain('  ' . $count . ' errors in ' . $file);
        }
    }

    private function analyzeBaseline(string $path): array
    {
        $content = file_get_contents($path);

        $analysis = [
            'total_errors' => 0,
            'total_files' => 0,
            'by_type' => [],
            'by_file' => [],
        ];

        // Parse NEON format
        // This is simplified - real implementation would use NEON parser

        if (preg_match_all('/message:\s*"#\^(.+?)(\s|\\\\)/', $content, $matches)) {
            foreach ($matches[1] as $type) {
                $type = trim($type);
                if (!isset($analysis['by_type'][$type])) {
                    $analysis['by_type'][$type] = 0;
                }
                $analysis['by_type'][$type]++;
            }
        }

        if (preg_match_all('/count:\s*(\d+)/', $content, $matches)) {
            $analysis['total_errors'] = array_sum(array_map('intval', $matches[1]));
        }

        if (preg_match_all('/path:\s*(.+)/', $content, $matches)) {
            foreach ($matches[1] as $file) {
                $file = trim($file);
                if (!isset($analysis['by_file'][$file])) {
                    $analysis['by_file'][$file] = 0;
                }
                $analysis['by_file'][$file]++;
            }
            $analysis['total_files'] = count(array_unique($matches[1]));
        }

        return $analysis;
    }
}
```

#### Example Output

```
PHPStan Baseline Report
======================

Total errors: 247
Affected files: 34

Error breakdown:
  89x Parameter has invalid type
  54x Property is never written
  38x Variable might not be defined
  29x Method has no return type
  19x Call to undefined method
  18x Access to undefined property

Most problematic files:
  23 errors in src/Legacy/OldClass.php
  18 errors in src/Core/MainService.php
  15 errors in src/Model/User.php
  12 errors in src/Util/Helper.php
  10 errors in src/Data/Repository.php
  9 errors in src/Controller/Admin.php
  8 errors in src/View/Template.php
  7 errors in src/Service/Auth.php
  6 errors in src/Api/Endpoint.php
  5 errors in src/Config/Settings.php
```

This provides actionable intelligence for improvement efforts.

---

### Strategy 3: Baseline Diff Tracking

**Problem:** No way to see if baseline is growing or shrinking.

**Solution:** Track baseline changes over time in CI/CD.

#### Implementation: Baseline Diff Tool

```bash
#!/bin/bash
# scripts/phpstan-baseline-diff.sh

# Compare baseline before and after changes

OLD_BASELINE="phpstan-baseline.neon.old"
NEW_BASELINE="phpstan-baseline.neon"

if [ ! -f "$OLD_BASELINE" ]; then
    echo "No previous baseline found"
    exit 0
fi

# Count errors in old baseline
OLD_COUNT=$(grep -c "count:" "$OLD_BASELINE" 2>/dev/null || echo "0")

# Count errors in new baseline
NEW_COUNT=$(grep -c "count:" "$NEW_BASELINE" 2>/dev/null || echo "0")

DIFF=$((NEW_COUNT - OLD_COUNT))

if [ $DIFF -gt 0 ]; then
    echo "⚠️  Baseline INCREASED by $DIFF errors"
    echo "New errors were added to the baseline!"
    exit 1
elif [ $DIFF -lt 0 ]; then
    echo "✅ Baseline DECREASED by ${DIFF#-} errors"
    echo "Great job fixing baseline errors!"
    exit 0
else
    echo "✓ Baseline unchanged ($NEW_COUNT errors)"
    exit 0
fi
```

#### CI/CD Integration

```yaml
# .github/workflows/quality.yml

name: Quality Checks
on: [push, pull_request]

jobs:
  phpstan:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3

      # Save baseline before changes
      - name: Backup baseline
        run: |
          if [ -f phpstan-baseline.neon ]; then
            cp phpstan-baseline.neon phpstan-baseline.neon.old
          fi

      # Run PHPStan
      - name: Run PHPStan
        run: |
          composer install
          horde-components qc phpstan

      # Check if baseline changed
      - name: Check baseline diff
        run: |
          ./scripts/phpstan-baseline-diff.sh

      # Fail if baseline grew
      - name: Prevent baseline growth
        if: failure()
        run: |
          echo "❌ Baseline cannot grow!"
          echo "Either fix the new errors or get approval to add them."
          exit 1
```

**Policy:** Baseline can shrink (good) or stay same (OK), but cannot grow without explicit approval.

---

### Strategy 4: Incremental Baseline Reduction

**Problem:** 247 errors is overwhelming - where to start?

**Solution:** Fix errors incrementally, starting with easiest wins.

#### Approach A: Fix by File

Pick the file with fewest errors, fix them all, regenerate baseline.

```bash
# 1. Identify easiest file (from baseline report)
horde-components qc-phpstan-baseline-report | grep "errors in"

# Output:
#   5 errors in src/Config/Settings.php  ← Start here!
#   6 errors in src/Api/Endpoint.php
#   ...

# 2. Fix errors in that file
vim src/Config/Settings.php

# 3. Regenerate baseline (excludes fixed file)
vendor/bin/phpstan analyse --level=5 --generate-baseline=phpstan-baseline.neon src/

# 4. Commit the reduced baseline
git add phpstan-baseline.neon
git commit -m "Fix PHPStan errors in Config/Settings.php (5 errors removed from baseline)"
```

**Progress:** 247 errors → 242 errors

#### Approach B: Fix by Error Type

Pick the error type that's easiest to fix systematically.

```bash
# 1. Identify easiest error type
horde-components qc-phpstan-baseline-report

# Output:
#   29x Method has no return type  ← Start here!

# 2. Fix all instances of this error type
# Use IDE or grep to find all methods without return types
# Add return types: public function foo(): void

# 3. Regenerate baseline
vendor/bin/phpstan analyse --level=5 --generate-baseline=phpstan-baseline.neon src/

# 4. Commit
git commit -m "Add return types to all methods (29 errors removed from baseline)"
```

**Progress:** 247 errors → 218 errors

#### Approach C: Fix by Pattern

Some errors can be fixed with automated tools.

```bash
# Example: Fix "Property is never written, only read"
# Often means property should be readonly (PHP 8.1+)

# Use rector or PHP CS Fixer to automatically add readonly
composer require --dev rector/rector

# Run rector with readonly rule
vendor/bin/rector process src/ --config=rector-readonly.php

# Regenerate baseline
vendor/bin/phpstan analyse --level=5 --generate-baseline=phpstan-baseline.neon src/

# Commit
git commit -m "Add readonly to never-written properties (54 errors removed)"
```

**Progress:** 247 errors → 193 errors

---

### Strategy 5: Baseline Ownership

**Problem:** Nobody feels responsible for reducing baseline.

**Solution:** Assign ownership and track progress.

#### Implementation: Baseline Ownership Metadata

Add ownership info to level registry:

```json
{
    "components": {
        "level": 5,
        "last_checked": "2026-02-27",
        "notes": "Main components tool - good type coverage",
        "baseline": {
            "exists": true,
            "errors": 247,
            "last_updated": "2026-02-27",
            "owner": "ralf.lang@ralf-lang.de",
            "target_date": "2026-06-30",
            "reduction_plan": "Fix 10 errors per month, focus on return types first"
        }
    }
}
```

#### Progress Tracking Dashboard

Create a simple dashboard showing baseline progress:

```
Component         | Baseline | Target | Progress | Owner
------------------|----------|--------|----------|-------
components        | 247      | 0      | ▓░░░░░░░ | Ralf
horde-auth        | 156      | 50     | ▓▓▓░░░░░ | Team A
horde-core        | 89       | 0      | ▓▓▓▓░░░░ | Team B
horde-util        | 34       | 0      | ▓▓▓▓▓▓░░ | Team C
```

#### Monthly Review Meeting

Schedule a monthly "baseline review" where teams report:
- How many errors were removed this month?
- What blockers exist?
- What help is needed?
- Updated target date if needed

---

### Strategy 6: Prevent Baseline Growth

**Problem:** New code adds to baseline instead of fixing issues.

**Solution:** Make baseline growth visible and discouraged.

#### Implementation: PR Checks

Add a CI check that fails if baseline grows:

```php
// In Phpstan task

private function checkBaselineGrowth(string $componentPath): void
{
    $baselinePath = $componentPath . '/phpstan-baseline.neon';
    $previousPath = $componentPath . '/.phpstan-baseline-previous.neon';

    if (!file_exists($baselinePath) || !file_exists($previousPath)) {
        return;
    }

    $current = $this->countBaselineErrors($baselinePath);
    $previous = $this->countBaselineErrors($previousPath);

    $diff = $current - $previous;

    if ($diff > 0) {
        $this->getOutput()->warn(
            '❌ BASELINE GREW: ' . $diff . ' new error' . ($diff !== 1 ? 's' : '') . ' added to baseline!'
        );
        $this->getOutput()->warn('This indicates new code with type issues.');
        $this->getOutput()->warn('Please fix these errors rather than adding to baseline.');

        // Optional: fail the build
        if (getenv('PHPSTAN_FAIL_ON_BASELINE_GROWTH') === '1') {
            throw new \RuntimeException('Baseline growth detected');
        }
    } elseif ($diff < 0) {
        $this->getOutput()->ok(
            '✅ BASELINE SHRUNK: ' . abs($diff) . ' error' . (abs($diff) !== 1 ? 's' : '') . ' removed!'
        );
    }
}

private function countBaselineErrors(string $path): int
{
    $content = file_get_contents($path);

    if (preg_match_all('/count:\s*(\d+)/', $content, $matches)) {
        return array_sum(array_map('intval', $matches[1]));
    }

    return 0;
}
```

#### Git Hook

Add a pre-commit hook that warns about baseline changes:

```bash
#!/bin/bash
# .git/hooks/pre-commit

if git diff --cached --name-only | grep -q "phpstan-baseline.neon"; then
    echo "⚠️  You are modifying phpstan-baseline.neon"
    echo ""
    echo "Baseline changes should be rare. Valid reasons:"
    echo "  ✅ Fixing errors (baseline should shrink)"
    echo "  ✅ Increasing PHPStan level (baseline may grow temporarily)"
    echo "  ❌ Adding new errors to baseline (discouraged!)"
    echo ""
    echo -n "Continue? [y/N] "
    read -r response
    if [ "$response" != "y" ]; then
        echo "Commit cancelled"
        exit 1
    fi
fi
```

---

### Strategy 7: Baseline Expiration

**Problem:** Baselines exist forever without review.

**Solution:** Add expiration dates to force periodic review.

#### Implementation: Baseline Age Warning

```php
// In Phpstan task

private function checkBaselineAge(string $componentPath): void
{
    $baselinePath = $componentPath . '/phpstan-baseline.neon';

    if (!file_exists($baselinePath)) {
        return;
    }

    $age = time() - filemtime($baselinePath);
    $daysOld = floor($age / 86400);

    if ($daysOld > 180) { // 6 months
        $this->getOutput()->warn(
            'Baseline is ' . $daysOld . ' days old (last updated: ' .
            date('Y-m-d', filemtime($baselinePath)) . ')'
        );
        $this->getOutput()->warn(
            'Consider reviewing and updating baseline (target: reduce or remove)'
        );
    } elseif ($daysOld > 90) { // 3 months
        $this->getOutput()->info(
            'Baseline is ' . $daysOld . ' days old - consider reviewing'
        );
    }
}
```

#### Automatic Review Issues

Create GitHub issues automatically for old baselines:

```bash
#!/bin/bash
# scripts/create-baseline-review-issues.sh

for repo in repos/*/; do
    cd "$repo"

    if [ -f phpstan-baseline.neon ]; then
        age_days=$(( ($(date +%s) - $(stat -f%m phpstan-baseline.neon)) / 86400 ))

        if [ $age_days -gt 180 ]; then
            # Create GitHub issue
            gh issue create \
                --title "Review PHPStan baseline (180+ days old)" \
                --body "The PHPStan baseline is $age_days days old. Please review and reduce/remove if possible." \
                --label "technical-debt,phpstan"
        fi
    fi

    cd ..
done
```

---

### Strategy 8: Level Increases Without Baseline Growth

**Problem:** Increasing PHPStan level finds new errors, forcing baseline regeneration.

**Solution:** Plan level increases carefully.

#### Approach: Staged Level Increases

Instead of jumping from level 5 to level 7:

```bash
# ❌ BAD: Jump multiple levels
vendor/bin/phpstan analyse --level=7 --generate-baseline src/
# Result: Baseline grows by 500 errors!

# ✅ GOOD: Increase one level at a time
# Step 1: Increase to level 6
vendor/bin/phpstan analyse --level=6 src/
# Fix NEW errors at level 6 (don't regenerate baseline yet)

# Step 2: Once level 6 passes clean, regenerate baseline
vendor/bin/phpstan analyse --level=6 --generate-baseline src/

# Step 3: Update config
echo "level: 6" > phpstan.neon

# Step 4: Commit
git commit -m "Increase PHPStan to level 6 (all new errors fixed)"
```

#### Approach: Targeted Level Increases

Increase level only for specific directories:

```yaml
# phpstan.neon
parameters:
    level: 5  # Default level
    paths:
        - src

    levelPaths:
        # New code at higher level
        level6:
            - src/New
            - src/Api

        # Legacy code stays at level 5
        level5:
            - src/Legacy
```

This allows new code to have higher standards while legacy code remains at current level.

---

## Rollout Strategy for 200 Repositories

### Phase 1: Baseline Generation (Month 1)

**Goal:** Establish baselines for all 200 repos.

```bash
#!/bin/bash
# scripts/generate-all-baselines.sh

for repo in repos/*/; do
    cd "$repo"
    component=$(basename "$repo")

    echo "Processing: $component"

    # Determine appropriate level
    level=5  # Default

    # Check if component has config
    if [ -f phpstan.neon ]; then
        level=$(grep "level:" phpstan.neon | awk '{print $2}')
    fi

    # Generate baseline
    if [ -d src/ ]; then
        vendor/bin/phpstan analyse \
            --level=$level \
            --generate-baseline=phpstan-baseline.neon \
            src/

        # Count baseline errors
        errors=$(grep -c "count:" phpstan-baseline.neon)

        # Record in registry
        echo "$component: level $level, $errors errors in baseline"

        # Commit
        git add phpstan-baseline.neon
        git commit -m "Add PHPStan baseline at level $level ($errors errors)"
    fi

    cd ..
done
```

### Phase 2: Categorization (Month 1-2)

Categorize repos by baseline size:

- **Green (0-20 errors)**: 45 repos - Easy wins, can eliminate baseline quickly
- **Yellow (21-100 errors)**: 89 repos - Moderate effort, reduce over 3-6 months
- **Orange (101-500 errors)**: 52 repos - Significant effort, reduce over 6-12 months
- **Red (500+ errors)**: 14 repos - Major refactoring needed, keep baseline long-term

### Phase 3: Green Zone Blitz (Month 2-3)

**Goal:** Eliminate baselines from all Green repos (quick wins).

Dedicate sprint to fixing errors in repos with <20 errors:
- These are mostly minor issues
- Can be fixed in 1-2 hours per repo
- Immediate impact: 45 repos baseline-free

### Phase 4: Yellow Zone Campaign (Month 3-9)

**Goal:** Reduce Yellow repos to <20 errors.

- Fix 10-15 errors per repo per month
- Focus on systematic error types (return types, property types)
- After 6 months, most Yellow repos move to Green

### Phase 5: Orange Zone Steady Progress (Month 6-18)

**Goal:** Reduce Orange repos by 50%.

- Slower pace: 20-30 errors per month
- May require architecture changes
- Some repos may need to stay in Orange zone

### Phase 6: Red Zone Containment (Ongoing)

**Goal:** Prevent Red repos from growing, accept baseline for now.

- These are legacy repos with significant technical debt
- Keep baseline stable (don't let it grow)
- Major refactoring may be needed (separate project)
- Accept that baseline may be permanent for these

---

## Baseline Maintenance Checklist

### Weekly
- [ ] Review baseline growth in PRs
- [ ] Celebrate baseline reductions in team chat
- [ ] Fix 2-3 easy errors in a file

### Monthly
- [ ] Run baseline report for all repos
- [ ] Update baseline statistics in registry
- [ ] Review progress toward reduction targets
- [ ] Identify and fix systematic error patterns

### Quarterly
- [ ] Baseline review meeting with teams
- [ ] Update ownership assignments
- [ ] Adjust reduction targets based on progress
- [ ] Consider level increases for clean repos

### Annually
- [ ] Evaluate overall baseline health across 200 repos
- [ ] Reward teams with biggest baseline reductions
- [ ] Set new baseline reduction goals for next year
- [ ] Consider deprecating repos that refuse to improve

---

## Baseline Anti-Patterns to Avoid

### ❌ Anti-Pattern 1: "Baseline is Fine"
**Symptom:** Team treats baseline as permanent solution.
**Fix:** Make baseline visible, track age, set expiration dates.

### ❌ Anti-Pattern 2: "Regenerate to Pass"
**Symptom:** When PHPStan fails, regenerate baseline instead of fixing.
**Fix:** Block baseline regeneration in CI without approval.

### ❌ Anti-Pattern 3: "Not My Problem"
**Symptom:** Nobody owns baseline reduction.
**Fix:** Assign owners, track in sprint planning, set targets.

### ❌ Anti-Pattern 4: "Too Many Errors"
**Symptom:** Overwhelming baseline paralyzes action.
**Fix:** Incremental approach, fix easiest errors first, celebrate small wins.

### ❌ Anti-Pattern 5: "New Code, New Baseline"
**Symptom:** Every feature adds to baseline.
**Fix:** Strict policy against baseline growth, PR checks, code review focus.

---

## Success Metrics

### Lagging Indicators (Results)
- Total baseline errors across all 200 repos
- Number of repos without baselines
- Average baseline age
- Baseline error count trending down

### Leading Indicators (Activities)
- Number of baseline errors fixed this month
- Number of PRs that reduced baseline
- Number of repos that moved Green→Yellow→Orange
- Baseline report runs per week

### Target Metrics (12 Months)
- Reduce total baseline errors by 50%
- 50% of repos baseline-free
- No baseline older than 12 months
- 100% of repos with baseline reduction plan

---

## Tools to Build

### High Priority
1. ✅ Baseline statistics in PHPStan task output
2. ✅ Baseline report command (show breakdown)
3. ⏳ Baseline diff tracking (prevent growth)
4. ⏳ CI integration (fail on baseline growth)

### Medium Priority
5. ⏳ Baseline age warnings
6. ⏳ Registry integration (track ownership)
7. ⏳ Dashboard for progress tracking
8. ⏳ Automated issue creation for old baselines

### Low Priority
9. ⏳ Baseline reduction suggestions (AI-powered)
10. ⏳ Automated fixing tools (for common patterns)
11. ⏳ Gamification (leaderboard, badges)
12. ⏳ Integration with sprint planning tools

---

## Conclusion

Baseline management is not about accepting technical debt—it's about **strategically deferring fixes while preventing new debt**. The key is:

1. **Visibility** - Make baseline size and age visible
2. **Accountability** - Assign owners and track progress
3. **Prevention** - Block baseline growth in CI/CD
4. **Incremental Progress** - Fix errors systematically, celebrate wins
5. **Expiration** - Baselines should be temporary, not permanent

With proper baseline management, you can:
- ✅ Adopt strict static analysis without blocking development
- ✅ Improve code quality incrementally
- ✅ Prevent new technical debt
- ✅ Eventually eliminate baselines entirely

The baseline is scaffolding for improvement, not a permanent structure. Treat it accordingly.
