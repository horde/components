# Code Metrics Tool Research

## Current State: PHPLOC
- **phploc** by Sebastian Bergmann (creator of PHPUnit)
- Last stable release: 7.0.2 (2020)
- Status: Unmaintained, incompatible with PHP 8.2+
- Provides: LOC, CLOC, NCLOC, complexity, structure metrics

## Problems with PHPLOC
1. **Unmaintained**: No updates since 2020
2. **PHP Compatibility**: Issues with PHP 8.2+ (nikic/php-parser dependency)
3. **Limited Insight**: Just counts, no actionable recommendations
4. **No Modern Features**: No JSON output, CI integration, or trend tracking

## Modern Alternatives

### 1. **PHPMetrics** (Recommended)
- **Status**: Actively maintained
- **URL**: https://github.com/phpmetrics/PhpMetrics
- **Latest**: v3.x (2024)
- **PHP Support**: PHP 8.0+

**Features**:
- All PHPLOC metrics + more
- **Maintainability Index** (MI score)
- **Cyclomatic Complexity** per method/class
- **Coupling/Cohesion** metrics (afferent/efferent coupling)
- **Halstead Complexity** metrics
- **Beautiful HTML reports** with charts and graphs
- **JSON/XML output** for CI
- **Violation tracking** (complexity thresholds)
- **Trend analysis** (compare multiple runs)
- **Composer integration**

**Metrics Provided**:
```
Size Metrics:
- Lines of Code (LOC, CLOC, NCLOC, LLOC)
- Physical lines, logical lines
- Average method length

Complexity Metrics:
- Cyclomatic Complexity (per method, per class)
- Maintainability Index (0-100 scale)
- Halstead metrics (volume, difficulty, effort)

OO Metrics:
- Coupling (afferent, efferent, instability)
- LCOM (Lack of Cohesion of Methods)
- Depth of Inheritance Tree (DIT)
- Number of Children (NOC)

Structure Metrics:
- Classes, interfaces, traits
- Methods, functions
- Namespaces
- Dependencies
```

**Advantages over PHPLOC**:
- Actively maintained
- Modern PHP support
- Actionable insights (MI score, violations)
- Beautiful reports
- CI-friendly
- Trend tracking

### 2. **PHP Insights**
- **URL**: https://github.com/nunomaduro/phpinsights
- **Status**: Maintained
- **Focus**: Code quality score + suggestions

**Features**:
- Combined metrics + code quality
- **Quality score** (0-100)
- **Suggestions** for improvements
- Integration with PHPStan, PHP CS Fixer
- Beautiful terminal output

**Note**: More focused on code quality than pure metrics

### 3. **Dephpend**
- **URL**: https://github.com/mihaeu/dephpend
- **Focus**: Dependency metrics specifically
- Status: Less actively maintained

### 4. **cloc** (Language-agnostic)
- **URL**: https://github.com/AlDanial/cloc
- **Status**: Actively maintained
- **Focus**: Pure LOC counting across languages
- **Note**: Not PHP-specific, less detailed OO metrics

## Recommendation: PHPMetrics

**Why PHPMetrics?**
1. ✅ Direct replacement for PHPLOC (all same metrics + more)
2. ✅ Actively maintained (PHP 8.3 support)
3. ✅ Better output (HTML reports, JSON for CI)
4. ✅ More insights (Maintainability Index, violations)
5. ✅ Trend tracking (compare runs)
6. ✅ Easy migration path

**Installation**:
```bash
composer require --dev phpmetrics/phpmetrics
```

**Basic Usage**:
```bash
# Simple metrics (like phploc)
phpmetrics --report-cli=stdout src/

# HTML report
phpmetrics --report-html=build/metrics src/

# JSON output for CI
phpmetrics --report-json=build/metrics.json src/

# With violations (fail on bad MI)
phpmetrics --violations-xml=build/violations.xml src/
```

**Output Example**:
```
Summary
  Total files: 42
  Total lines: 5432

Maintainability
  Maintainability Index: 78.4 (Good)
  Average Complexity: 3.2

Violations
  Complex methods: 2 (>10 cyclomatic complexity)
  Large classes: 1 (>500 LOC)
```

## Migration Strategy

1. **Phase 1**: Make LOC task opt-in (not in default pipeline)
   - Move `loc` to explicit-only like `md` and `cs`
   - Update documentation

2. **Phase 2**: Add PHPMetrics task
   - New `Qc\Task\Metrics.php`
   - Support both CLI and HTML report modes
   - JSON output for CI
   - Make opt-in initially

3. **Phase 3**: Test and validate
   - Run on components codebase
   - Verify reports
   - Document differences

4. **Phase 4**: Promote PHPMetrics to default
   - Add `metrics` to default QC pipeline
   - Deprecate `loc` task
   - Update docs

## Alternative: PHP Insights for Quality Score

If we want a **quality score** rather than raw metrics:
- PHP Insights provides 0-100 score
- Combines metrics + static analysis + code style
- More "executive dashboard" than detailed metrics
- Could complement PHPMetrics

## Conclusion

**Recommended**: Deprecate PHPLOC (make opt-in), add PHPMetrics as new default.

**Benefits**:
- Modern, maintained tool
- All PHPLOC metrics + more
- Better visualization
- CI-friendly
- Actionable insights (MI score)
