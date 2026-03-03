# CI Template Variables Reference

This document describes all variables used in CI templates.

## Variable Naming Convention

- Variables use UPPER_SNAKE_CASE
- Wrapped in double curly braces: `{{VARIABLE_NAME}}`
- Must be replaced during rendering (unreplaced variables cause errors)

## User-Provided Variables

These must be provided when rendering templates.

### `{{COMPONENT_NAME}}`
**Type:** String
**Example:** `Db`, `Http`, `Imap_Client`
**Description:** Component name (typically directory name)
**Used in:** All templates

### `{{COMPONENTS_PHAR_URL}}`
**Type:** URL
**Example:** `https://dev.horde.org/ci/horde-components.phar`
**Description:** URL to download horde-components.phar
**Used in:** `bootstrap-github.sh`, `workflow.yml`
**Default:** Can be read from config or use built-in default

### `{{WORK_DIR}}`
**Type:** Path
**Example:** `/tmp/horde-ci`
**Description:** Working directory for CI operations
**Used in:** All templates
**Default:** `/tmp/horde-ci`

### `{{PHP_VERSION}}`
**Type:** Version string
**Example:** `8.4`
**Description:** PHP version for bootstrap (should be 8.4)
**Used in:** `bootstrap-github.sh`, `workflow.yml`
**Default:** `8.4`

### `{{LOCAL_COMPONENTS_PATH}}`
**Type:** Path
**Example:** `/home/user/components`
**Description:** Local path to horde-components checkout
**Used in:** `bootstrap-local.sh`
**Required for:** Local mode only

### `{{LOCAL_COMPONENT_PATH}}`
**Type:** Path
**Example:** `/home/user/git/horde/Db`
**Description:** Local path to component being tested
**Used in:** `bootstrap-local.sh`
**Required for:** Local mode only

## Automatic Variables

These are automatically added by TemplateRenderer.

### `{{TEMPLATE_VERSION}}`
**Type:** Semantic version
**Example:** `1.0.0`
**Description:** Template version for tracking updates
**Source:** `TemplateRenderer::TEMPLATE_VERSION`
**Used in:** All templates

### `{{GENERATED_DATE}}`
**Type:** Timestamp
**Example:** `2026-03-03 10:30:00 PST`
**Description:** When the file was generated
**Source:** `date('Y-m-d H:i:s T')`
**Used in:** All templates

### `{{COMPONENTS_VERSION}}`
**Type:** Version string
**Example:** `1.0.0-alpha26`
**Description:** horde-components version that generated the file
**Source:** `.horde.yml` or constant
**Used in:** All templates

## Variable Resolution

Variables are resolved in this order:

1. **Explicitly provided** - Passed to `render()` method
2. **Automatic variables** - Added by TemplateRenderer
3. **Error** - If variable remains unreplaced

## Example Rendering

```php
$renderer = new TemplateRenderer('/path/to/data/ci');

$rendered = $renderer->render('bootstrap-github.sh', [
    '{{COMPONENT_NAME}}' => 'Http',
    '{{COMPONENTS_PHAR_URL}}' => 'https://dev.horde.org/ci/horde-components.phar',
    '{{WORK_DIR}}' => '/tmp/horde-ci',
    '{{PHP_VERSION}}' => '8.4',
]);

// Automatic variables are added:
// {{TEMPLATE_VERSION}} => '1.0.0'
// {{GENERATED_DATE}} => '2026-03-03 10:30:00 PST'
// {{COMPONENTS_VERSION}} => '1.0.0-alpha26'
```

## Validation

The renderer validates that all `{{VARIABLE}}` patterns are replaced:

```php
// This will throw Exception: "Unreplaced template variable: {{FOO}}"
$rendered = $renderer->render('template', []); // Missing required variables
```

## Adding New Variables

When adding a new variable:

1. Update this document
2. Update README.md
3. Update templates that use it
4. Update InitCommand to provide it
5. Add tests

## Variable Groups

### Bootstrap Configuration
- `{{PHP_VERSION}}`
- `{{COMPONENTS_PHAR_URL}}`
- `{{WORK_DIR}}`

### Component Identification
- `{{COMPONENT_NAME}}`

### Local Development
- `{{LOCAL_COMPONENTS_PATH}}`
- `{{LOCAL_COMPONENT_PATH}}`

### Metadata
- `{{TEMPLATE_VERSION}}`
- `{{GENERATED_DATE}}`
- `{{COMPONENTS_VERSION}}`

## Future Variables

Potential variables for future templates:

- `{{MIN_PHP_VERSION}}` - From .horde.yml
- `{{PHPUNIT_VERSION}}` - Explicit version override
- `{{DATABASES}}` - Database list (mysql, pgsql, sqlite)
- `{{EXTENSIONS}}` - Required PHP extensions
- `{{PARALLEL}}` - Enable parallel execution (true/false)
