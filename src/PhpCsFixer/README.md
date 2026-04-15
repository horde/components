# Horde Custom PHP CS Fixer Rules

This directory contains custom PHP CS Fixer rules for the Horde Components tool.

## Custom Fixers

### RemovePhpVersionCommentFixer

**Rule name:** `Horde/remove_php_version_comment`

**Purpose:** Removes legacy "PHP Version X" comments from docblocks.

**What it does:**
- Identifies lines containing "PHP Version 5", "PHP Version 7", or "PHP Version 8"
- Removes the entire line from file-level and class-level docblocks
- Also removes the empty line that follows (if present)
- Preserves all other docblock content

**Example:**

Before:
```php
/**
 * Represents a component.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 */
```

After:
```php
/**
 * Represents a component.
 *
 * @category Horde
 * @package  Components
 */
```

### UpdateCopyrightYearFixer

**Rule name:** `Horde/update_copyright_year`

**Purpose:** Updates copyright year ranges to include the current year dynamically.

**What it does:**
- Detects copyright statements in docblocks
- Updates single year (e.g., "Copyright 2025") to range ending with current year ("Copyright 2025-2026")
- Updates year ranges (e.g., "Copyright 2020-2024") to end with current year ("Copyright 2020-2026")
- Uses `date('Y')` to determine current year dynamically (no hardcoded years)
- Does not modify copyright statements already ending with current year

**Examples:**

Before:
```php
/**
 * Copyright 2025 The Horde Project
 */
```

After (in 2026):
```php
/**
 * Copyright 2025-2026 The Horde Project
 */
```

Before:
```php
/**
 * Copyright 2020-2024 Horde LLC (http://www.horde.org/)
 */
```

After (in 2026):
```php
/**
 * Copyright 2020-2026 Horde LLC (http://www.horde.org/)
 */
```

## Usage

These custom fixers are automatically loaded and applied when running PHP CS Fixer with the project configuration:

```bash
# Dry-run (preview changes)
php-cs-fixer fix --dry-run --diff

# Apply fixes
php-cs-fixer fix

# Fix specific file
php-cs-fixer fix src/Component.php
```

## Configuration

The custom fixers are configured in `.php-cs-fixer.dist.php`:

```php
// Load custom fixers
require_once __DIR__ . '/src/PhpCsFixer/RemovePhpVersionCommentFixer.php';
require_once __DIR__ . '/src/PhpCsFixer/UpdateCopyrightYearFixer.php';

$config = (new PhpCsFixer\Config())
    ->setRules([
        // ... other rules
        'Horde/remove_php_version_comment' => true,
        'Horde/update_copyright_year' => true,
    ])
    ->setFinder($finder)
;

// Register custom fixers
$config->registerCustomFixers([
    new \Horde\Components\PhpCsFixer\RemovePhpVersionCommentFixer(),
    new \Horde\Components\PhpCsFixer\UpdateCopyrightYearFixer(),
]);
```

## Scope

The PHP CS Fixer configuration applies to:
- `src/` directory (PSR-4 namespaced code)
- `test/` directory (test files)

The `lib/` directory is **excluded** as it contains legacy PSR-0 code that will be handled separately.

## Priority

- `RemovePhpVersionCommentFixer` runs with priority 10 (before most fixers)
- `UpdateCopyrightYearFixer` runs with priority 5 (after RemovePhpVersionCommentFixer)

This ensures PHP Version comments are removed before copyright years are updated.

## Extending to Other Components

### RewriteHordeToPsr4Fixer

**Rule name:** `Horde/rewrite_horde_to_psr4`  
**Risky:** Yes (disabled by default)

**Purpose:** Rewrites `Horde::method()` calls to `\Horde\Core\Horde::method()` for methods
that the legacy global Horde class now forwards to the namespaced PSR-4 class.

**Affected methods:** `signQueryString`, `verifySignedQueryString`, `verifySignedUrl`,
`escapeJson`, `isConnectionSecure`, `requireSecureConnection`, `getDriverConfig`,
`assertDriverConfig`, `externalUrl`, `link`, `linkTooltip`, `widget`, `getTempDir`,
`getTempFile`, `webServerID`, `getAccessKey`, `stripAccessKey`, `highlightAccessKey`,
`getAccessKeyAndTitle`, `label`, `wrapInlineScript`, `popupJs`, `startBuffer`,
`endBuffer`, `contentSent`, `sidebar`, `permissionDeniedError`

**What it does:**
- If all `Horde::` calls in a namespaced file are forwarded methods: adds
  `use Horde\Core\Horde;` (the bare `Horde::` calls then resolve to the namespaced class)
- If the file has a mix of forwarded and non-forwarded `Horde::` calls: rewrites
  only the forwarded calls to FQCN `\Horde\Core\Horde::method()`
- Skips files that already have `use Horde\Core\Horde;`
- Skips files inside `lib/` (legacy PSR-0 code)
- Skips the Horde class files themselves

**Example (all calls forwarded):**

Before:
```php
<?php
namespace MyApp;

Horde::startBuffer();
$json = Horde::escapeJson($data);
$output = Horde::endBuffer();
```

After:
```php
<?php
namespace MyApp;

use Horde\Core\Horde;

Horde::startBuffer();
$json = Horde::escapeJson($data);
$output = Horde::endBuffer();
```

**Example (mixed calls):**

Before:
```php
<?php
namespace MyApp;

$url = Horde::url('/path');
$json = Horde::escapeJson($data);
```

After:
```php
<?php
namespace MyApp;

$url = Horde::url('/path');
$json = \Horde\Core\Horde::escapeJson($data);
```

**Enable with:** `--allow-risky=yes` and set the rule to `true` in configuration.

## Extending to Other Components

These custom fixers can be reused across other Horde components:

1. Copy the fixer files to the component's `src/PhpCsFixer/` directory
2. Update the component's `.php-cs-fixer.dist.php` to load and register them
3. Add the rules to the rules array

Alternatively, these could be packaged as a separate `horde/php-cs-fixer-rules` package for easier distribution.

## Implementation Notes

- Uses PHP CS Fixer's `AbstractFixer` base class
- Operates on `T_DOC_COMMENT` tokens
- Parses docblock content line by line using regex patterns
- Reconstructs docblocks with modified content
- Maintains all whitespace and formatting for non-modified lines

## Testing

Both fixers have been tested on the entire `src/` and `test/` directories:
- ✅ 101 files successfully processed
- ✅ All PHP syntax valid after fixes
- ✅ All PHPUnit tests pass
- ✅ No unintended side effects
