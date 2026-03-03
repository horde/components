# Horde Components CI Templates

This directory contains templates for generating CI configuration files for Horde components.

## Template Files

### `bootstrap-github.sh.template`
Bootstrap script for GitHub Actions. Sets up environment and downloads horde-components.phar.

### `bootstrap-local.sh.template`
Bootstrap script for local development. Uses local horde-components installation.

### `workflow.yml.template`
GitHub Actions workflow configuration for running CI on pull requests and pushes.

## Template Variables

Templates use `{{VARIABLE_NAME}}` syntax for placeholders. These are replaced during generation:

### User-Provided Variables

- `{{COMPONENT_NAME}}` - Component name (e.g., "Db", "Http")
- `{{COMPONENTS_PHAR_URL}}` - URL to download horde-components.phar
- `{{WORK_DIR}}` - Working directory for CI (e.g., "/tmp/horde-ci")
- `{{PHP_VERSION}}` - Bootstrap PHP version (e.g., "8.4")
- `{{LOCAL_COMPONENTS_PATH}}` - Local path to horde-components (local mode only)
- `{{LOCAL_COMPONENT_PATH}}` - Local path to component (local mode only)

### Automatic Variables

These are added automatically by the template renderer:

- `{{TEMPLATE_VERSION}}` - Template version (e.g., "1.0.0")
- `{{GENERATED_DATE}}` - Generation timestamp
- `{{COMPONENTS_VERSION}}` - horde-components version

## Usage

### Generate CI files for a component

```bash
cd ~/git/horde/Http
horde-components ci init
```

This creates:
- `bin/ci-bootstrap.sh` - Bootstrap script
- `.github/workflows/ci.yml` - GitHub Actions workflow

### Generate for local mode

```bash
cd ~/git/horde/Http
horde-components ci init --mode=local
```

### Check if templates are outdated

```bash
cd ~/git/horde/Http
horde-components ci check
```

### Regenerate with latest templates

```bash
cd ~/git/horde/Http
horde-components ci init --force
```

## Template Versioning

Generated files include a version comment:

```bash
# Template version: 1.0.0
```

This allows detection of outdated generated files. When templates are updated, the version is incremented following semantic versioning:

- **Major version** (X.0.0): Breaking changes to generated files
- **Minor version** (1.X.0): New features, backwards compatible
- **Patch version** (1.0.X): Bug fixes

## Current Version

**Template Version:** 1.0.0

**Changelog:**
- 1.0.0 (2026-03-03): Initial template release

## Customization

Components can customize generated files after generation. However:

- Mark customized sections with comments
- Run `ci init --force` will overwrite customizations
- Consider submitting improvements back to templates

Example:

```bash
#!/bin/bash
# ... (generated code)

# CUSTOMIZATION START - Component-specific environment
export SPECIAL_VAR="value"
# CUSTOMIZATION END

# ... (generated code continues)
```

## Testing Templates

Templates can be tested without generating files:

```bash
# Dry run - show what would be generated
horde-components ci init --dry-run
```

## Adding New Templates

1. Create new `.template` file in this directory
2. Use `{{VARIABLE}}` syntax for placeholders
3. Add variables to `template-variables.md`
4. Update this README
5. Add rendering logic to `InitCommand` if needed

## Template Format

Templates are plain text files with variable substitution. Keep them:

- Simple and readable
- Well-commented
- Executable (for shell scripts)
- Valid syntax (for YAML/JSON)

## Support

For issues with templates or CI setup:

1. Check generated file versions (`ci check`)
2. Try regenerating (`ci init --force`)
3. Report issues: https://github.com/horde/components/issues
