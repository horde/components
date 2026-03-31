# PHPStan Custom Rules for Horde

This directory contains custom PHPStan rules to enforce Horde coding standards and architecture patterns.

## How It Works

When running `horde-components qc phpstan` on any component:

1. **Custom rules are automatically applied** - no component configuration needed
2. **Rules are loaded** via `phpstan-bootstrap.php` using PHPStan's `--autoload-file` parameter
3. **Rules are embedded** in the generated temporary config
4. **Both standard and custom rules** run together

Components don't need to do anything special - the architecture rules are automatically enforced.

## Architecture

- **Rule classes:** `src/PhpStan/Rules/*.php`
- **Bootstrap loader:** `phpstan-bootstrap.php` (root of components)
- **Shared config:** `phpstan-rules.neon` (for reference, not required)
- **Integration:** `src/Qc/Task/Phpstan.php` generates configs with embedded rules

## Active Rules

These rules are currently enabled and will fail builds:

### 1. NoDirectGlobalAccessRule
**Purpose:** Prevent direct global variable access in favor of dependency injection.

**Detects:**
```php
// ❌ Forbidden
global $registry;
global $injector;
global $conf;
global $notification;
global $session;
```

**Fix:**
```php
// ✅ Use constructor injection
class Controller {
    public function __construct(
        private readonly Horde_Registry $registry,
    ) {}
}
```

### 2. RequireImmutableUriUsageRule
**Purpose:** Ensure immutable Uri objects are used correctly (return values not discarded).

**Detects:**
```php
// ❌ Forbidden - return value discarded!
$uri = new Uri('/path');
$uri->withQuery('foo=bar');  // Bug: returns new instance, not mutating
```

**Fix:**
```php
// ✅ Assign the return value
$uri = new Uri('/path');
$uri = $uri->withQuery('foo=bar');  // Correct: new instance assigned
```

**Applies to:**
- `Horde\Http\Uri`
- `Psr\Http\Message\UriInterface`
- `GuzzleHttp\Psr7\Uri`
- `Laminas\Diactoros\Uri`

**Exception handling:**
To add exceptions (methods that have side effects), edit the `$allowedContexts` property in the rule class.

### 3. NoDeprecatedHordeUtilRule
**Purpose:** Flag deprecated `Horde_Util` method calls that should use modern alternatives.

**Detects:**
```php
// ❌ Deprecated
Horde_Util::getPathInfo();
Horde_Util::addParameter($url, $params);
Horde_Util::getFormData();
```

**Fix:**
```php
// ✅ Modern alternatives
$_SERVER['PATH_INFO'];
$uri = $uri->withQuery(http_build_query($params));
filter_input(INPUT_POST, 'field');
```

## Draft Rules (Inactive)

These rules are implemented but not yet enabled. To activate them, uncomment the relevant sections in `phpstan.neon`.

### Draft/NoExitInLibraryCodeRule
**Purpose:** Prevent exit()/die() in library code (src/, lib/).

**Reason for draft status:** Needs baseline of existing violations before activation.

### Draft/RequireCoversClassAttributeRule
**Purpose:** Require `#[CoversClass(ClassName::class)]` on all test classes.

**Reason for draft status:** Existing test suite needs updates first.

### Draft/RequireDependencyInjectionRule
**Purpose:** Flag direct instantiation of service classes in controllers.

**Reason for draft status:** Requires architectural changes across components.

## Adding New Rules

1. Create a new class in `src/PhpStan/Rules/` (active) or `src/PhpStan/Rules/Draft/` (inactive)
2. Implement `PHPStan\Rules\Rule` interface
3. Register in `phpstan.neon` (see configuration section below)

### Rule Template

```php
<?php

declare(strict_types=1);

namespace Horde\Components\PhpStan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<Node\Expr\YourNodeType>
 */
class YourCustomRule implements Rule
{
    public function getNodeType(): string
    {
        return Node\Expr\YourNodeType::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        // Your logic here

        if ($violationDetected) {
            return [
                RuleErrorBuilder::message('Error message')
                    ->tip('Optional helpful tip')
                    ->build()
            ];
        }

        return [];
    }
}
```

## Configuration

Rules are registered in `phpstan.neon`. See that file for the current configuration.

## Testing Rules

Test your custom rules against components:

```bash
# Test on a specific component
cd ~/php/git/horde/Date
phpstan analyze

# Test with horde-components
cd ~/php/git/horde/Date
~/php/components/bin/horde-components qc phpstan
```

## Resources

- [PHPStan Rule API](https://phpstan.org/developing-extensions/rules)
- [AST Node Types](https://github.com/nikic/PHP-Parser/tree/master/lib/PhpParser/Node)
- [PHPStan Type System](https://phpstan.org/developing-extensions/type-system)
