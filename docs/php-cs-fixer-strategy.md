# PHP CS Fixer QC Task - Research Summary & Implementation Strategy

## Research Findings

### Comprehensive Codebase Analysis
I've thoroughly analyzed the PHP CS Fixer v3.94.2 codebase located in the Composer vendor directory. Here are the key findings:

## Key Discovery: Two Integration Approaches

### Approach 1: CLI Subprocess (RECOMMENDED for v1)

**Pros:**
- ✅ **Stable Public API** - CLI interface is the official public API
- ✅ **Simple Implementation** - No complex dependency management
- ✅ **JSON Support Built-in** - `--format=json` provides structured output
- ✅ **No Version Lock-in** - Works across PHP CS Fixer versions
- ✅ **Battle-tested** - Used by all major CI/CD systems
- ✅ **Parallel Processing** - Automatically handled by PHP CS Fixer CLI
- ✅ **Configuration Merging** - CLI handles all config resolution

**Cons:**
- ❌ External process overhead (minimal for typical use)
- ❌ Less tight integration for real-time progress

**Implementation Pattern:**
```php
$binary = $this->findPhpCsFixerBinary();
$cmd = [
    $binary,
    'fix',
    escapeshellarg($componentPath),
    $isDryRun ? '--dry-run' : '',
    '--format=json',
    '--using-cache=no',  // For consistent results
    '--allow-risky=yes',  // If needed for Horde rules
];

exec(implode(' ', array_filter($cmd)), $output, $exitCode);
$result = json_decode(implode("\n", $output), true);
```

### Approach 2: Programmatic API (Consider for v2)

**Pros:**
- ✅ In-process execution
- ✅ Direct event listener integration
- ✅ Fine-grained control over fixers and rules
- ✅ Access to detailed error information

**Cons:**
- ❌ **Marked @internal** - Not guaranteed stable
- ❌ **Complex Setup** - Requires ~10 dependencies (EventDispatcher, Finder, ConfigurationResolver, etc.)
- ❌ **Version Sensitive** - Breaking changes in v4.0 expected
- ❌ **Symfony Input Required** - v3.x requires InputInterface parameter
- ❌ **More Code** - Significantly more complex implementation

**Risk Assessment:**
The PHP CS Fixer maintainers explicitly mark the Runner class as `@internal` and note in comments:
> "TODO for v4: decide if marking Runner as internal or making it dependencies public"

This indicates uncertainty about the programmatic API's future.

## Recommended Implementation Strategy

### Phase 1: CLI-Based Implementation (Current Priority)

**Why CLI First:**
1. **Stability** - The CLI is the official interface and won't break
2. **Simplicity** - Less code, fewer dependencies, easier to maintain
3. **Proven** - How all CI/CD tools integrate with PHP CS Fixer
4. **JSON Output** - Already perfect for our needs
5. **Quick Win** - Can be implemented quickly and reliably

**Example from Industry:**
- GitHub Actions PHP CS Fixer integrations use CLI
- PHPStorm PHP CS Fixer integration uses CLI
- GitLab CI PHP CS Fixer templates use CLI
- Most PHP quality tools use CLI for stability

### Phase 2: Event System Integration (Future Enhancement)

If we need real-time progress or tighter integration later:
```php
// Only if PHP CS Fixer stabilizes the programmatic API
use PhpCsFixer\Runner\Runner;
use PhpCsFixer\Runner\Event\FileProcessed;
use Symfony\Component\EventDispatcher\EventDispatcher;

$dispatcher = new EventDispatcher();
$dispatcher->addListener(FileProcessed::NAME, function($event) {
    $this->stats['files_checked']++;
    if ($event->getStatus() === FileProcessed::STATUS_FIXED) {
        $this->stats['files_fixed']++;
    }
});
```

## Comparison with PHPUnit Task

| Aspect | PHPUnit Task | PHP CS Fixer Task |
|--------|--------------|-------------------|
| **API Stability** | ✅ Public API (TextUI\Application) | ⚠️ CLI stable, programmatic @internal |
| **Integration Method** | Programmatic (Application::run()) | **CLI recommended** |
| **Event System** | Event Facade (public) | EventDispatcher (internal use) |
| **JSON Output** | Custom generation | Native --format=json |
| **Tool Detection** | PHAR + class_exists() | Same pattern |
| **Version Detection** | Version::id() | ToolInfo::getVersion() |

**Key Difference:** PHPUnit's programmatic API is public and stable, while PHP CS Fixer's is internal and may change.

## Tool Detection Strategy (Matches PHPUnit)

```php
private function findPhpCsFixerBinary(): ?string
{
    $componentPath = $this->_config->getPath();

    // Order of preference:
    // 1. vendor/bin (Composer)
    // 2. tools/ (local tools)
    // 3. ~/.phive/ (Phive)
    // 4. system PATH

    $locations = [
        $componentPath . '/vendor/bin/php-cs-fixer',
        $componentPath . '/vendor/bin/php-cs-fixer.phar',
        $componentPath . '/tools/php-cs-fixer',
        $componentPath . '/tools/php-cs-fixer.phar',
        $_SERVER['HOME'] . '/.phive/php-cs-fixer',
        $_SERVER['HOME'] . '/.phive/php-cs-fixer.phar',
        '/usr/local/bin/php-cs-fixer',
        '/usr/bin/php-cs-fixer',
    ];

    foreach ($locations as $path) {
        if (file_exists($path) && is_executable($path)) {
            return $path;
        }
    }

    // Fallback: check PATH
    $which = trim(shell_exec('which php-cs-fixer 2>/dev/null'));
    return $which ?: null;
}
```

## JSON Output Design

### Our Custom Summary Format
```json
{
    "timestamp": "2026-02-27T10:30:45+00:00",
    "php_cs_fixer_version": "3.94.2",
    "tool_source": "/path/to/vendor/bin/php-cs-fixer",
    "mode": "check",
    "exit_code": 0,
    "success": true,
    "statistics": {
        "files_checked": 42,
        "files_with_issues": 5,
        "files_fixed": 0,
        "errors": 0
    },
    "time_ms": 1234,
    "memory_mb": 12.5
}
```

### PHP CS Fixer Native JSON (also saved)
The CLI already provides:
```json
{
    "about": "PHP CS Fixer 3.94.2 ...",
    "files": [
        {
            "name": "src/Example.php",
            "appliedFixers": ["no_unused_imports"],
            "diff": "..."
        }
    ],
    "time": {"total": 1.234},
    "memory": 12.345
}
```

## Error Handling Pattern

```php
public function run(array &$options = []): int
{
    $binary = $this->findPhpCsFixerBinary();
    if (!$binary) {
        $this->getOutput()->warn('PHP CS Fixer not found - skipping');
        return 0;  // Not an error, just skip
    }

    $isDryRun = empty($options['fix_qc_issues']);
    $mode = $isDryRun ? 'CHECK' : 'FIX';

    $this->getOutput()->info("Running PHP CS Fixer in $mode mode...");
    $this->detectVersion($binary);

    // Execute
    $exitCode = $this->executePhpCsFixer($binary, $isDryRun);

    // Parse results
    $this->parseJsonResults();
    $this->writeJsonSummary();
    $this->outputStatistics($isDryRun);

    return $exitCode === 0 ? 0 : $this->stats['files_with_issues'];
}
```

## Configuration Handling

PHP CS Fixer automatically searches for:
1. `.php-cs-fixer.php`
2. `.php-cs-fixer.dist.php`

We don't need to handle config detection - the tool does it.

**For components without config:**
```bash
# We can provide a default config argument
php-cs-fixer fix --rules=@PSR12 src/
```

## Integration Points

### QC Pipeline Position
```
1. gitignore  (VCS config)
2. lint       (syntax check)
3. phpcsfixer (code style auto-fix) ← NEW
4. cs         (PHPCS code style check)
5. unit       (tests)
6. md         (mess detection)
7. loc        (metrics)
```

**Rationale:** After lint (valid PHP required) but before cs (php-cs-fixer fixes many PHPCS issues).

### Release Pipeline Integration
Option 1: Run in fix mode before version bumps
Option 2: Run in check mode as validation (fail if issues)

## Implementation Checklist

- [ ] Create `src/Qc/Task/PhpCsFixer.php`
- [ ] Implement tool detection (findPhpCsFixerBinary)
- [ ] Implement version detection
- [ ] Implement CLI execution with proper escaping
- [ ] Parse native JSON output
- [ ] Generate custom summary JSON
- [ ] Collect and output statistics
- [ ] Support check mode (default)
- [ ] Support fix mode (--fix-qc-issues)
- [ ] Add to Runner\Qc task list
- [ ] Update Module\Qc help text
- [ ] Test with various installation methods
- [ ] Test with/without config file
- [ ] Document in plan

## Conclusion

**Recommended Approach: CLI-based implementation**

This provides:
- ✅ Maximum stability and forward compatibility
- ✅ Simplest implementation
- ✅ Best practices alignment (how everyone uses PHP CS Fixer)
- ✅ Native JSON output support
- ✅ All features we need (check/fix modes, statistics, errors)

The programmatic API is interesting but marked internal and not worth the complexity/risk for our use case. We can always add it later if the API becomes public and stable in v4.

**Next Step:** Implement the PhpCsFixer QC task using CLI approach with the patterns established in this plan.
