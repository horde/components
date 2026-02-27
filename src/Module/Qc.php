<?php

/**
 * Components_Module_Qc:: checks the component for quality.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Module;

use Horde\Components\Config;
use Horde\Components\Component\ComponentDirectory;
use Horde\Components\RuntimeContext\CurrentWorkingDirectory;
use Horde\Components\Runner\Qc as RunnerQc;

/**
 * Components_Module_Qc:: checks the component for quality.
 *
 * Copyright 2011-2024 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Qc extends Base
{
    public function getOptionGroupTitle(): string
    {
        return 'Package quality control';
    }

    public function getOptionGroupDescription(): string
    {
        return 'This module runs a quality control check for the specified package.';
    }

    public function getOptionGroupOptions(): array
    {
        return [
            new \Horde\Argv\Option(
                '-Q',
                '--qc',
                ['action' => 'store_true', 'help' => 'Check the package quality.']
            ),
            new \Horde\Argv\Option(
                '--fix-qc-issues',
                ['action' => 'store_true', 'help' => 'Automatically fix QC issues where possible.']
            ),
        ];
    }

    /**
     * Get the usage title for this module.
     *
     * @return string The title.
     */
    public function getTitle(): string
    {
        return 'qc';
    }

    /**
     * Get the usage description for this module.
     *
     * @return string The description.
     */
    public function getUsage(): string
    {
        return 'Check the package quality.';
    }

    /**
     * Return the action arguments supported by this module.
     *
     * @return array A list of supported action arguments.
     */
    public function getActions(): array
    {
        return ['qc'];
    }

    /**
     * Return the help text for the specified action.
     *
     * @param string $action The action.
     *
     * @return string The help text.
     */
    public function getHelp($action): string
    {
        return 'Run quality control checks for the component.

USAGE:
    horde-components qc [TASK1 TASK2 ...]

DESCRIPTION:
    The qc command executes automated quality control checks on the component.
    These checks are similar to those found on ci.horde.org and help ensure
    code quality before releases.

    When run without any task arguments, the DEFAULT pipeline is executed.
    When specific task names are provided as arguments, only those checks run.
    Multiple tasks can be specified to run them in sequence.

DEFAULT PIPELINE:
    When running "horde-components qc" without task names, these checks run:
    - gitignore (VCS configuration)
    - lint (syntax validation)
    - phpcsfixer (code style fixing)
    - unit (test suite)
    - phpstan (static analysis)
    - loc (code metrics)

    Note: The "cs" (PHPCS) and "md" (PHPMD) checks must be explicitly requested.

AVAILABLE CHECKS:
    unit       Run the PHPUnit unit test suite
               Requires: PHPUnit installed globally or in vendor/bin/

    phpstan    Run PHPStan static analysis
               Requires: phpstan command available
               Checks: type errors, dead code, undefined variables, invalid types
               Levels: 0-9 (auto-detected from phpstan.neon or defaults to 4)
               Supports: baseline files for legacy code
               Outputs: JSON results to build/ directory
               Note: Default static analysis tool (PHPMD available via explicit call)

    md         Run PHP Mess Detector (PHPMD) to detect code quality issues
               Requires: phpmd command available
               Detects: unused code, suboptimal code, overcomplicated expressions
               Note: NOT in default pipeline - must be explicitly requested
               (PHPStan is the default static analysis tool)

    cs         Run PHP CodeSniffer (PHPCS) for code style analysis
               Requires: phpcs command available
               Checks: coding standards compliance
               Note: NOT in default pipeline - must be explicitly requested
               (phpcsfixer is the default code style tool)

    lint       Run PHP syntax check (php -l) on all PHP files
               Requires: PHP (always available)
               Checks: syntax errors, parse errors

    phpcsfixer Run PHP CS Fixer for code style fixing
               Requires: php-cs-fixer command available
               Fixes: code style issues automatically
               Supports: --fix-qc-issues to auto-fix (check mode by default)
               Outputs: JSON results to build/ directory

    loc        Run PHPLOC to analyze code size and structure (DEPRECATED)
               Requires: phploc command available
               Reports: lines of code, cyclomatic complexity, dependencies
               Status: Not in default pipeline (opt-in only)
               Note: PHPLOC is unmaintained, use metrics task instead

    metrics    Run PHPMetrics for modern code metrics analysis
               Requires: phpmetrics command available
               Reports: size, complexity, maintainability index, coupling, cohesion
               Outputs: HTML report to build/metrics/, JSON to build/metrics.json
               Status: Not in default pipeline (opt-in only)
               Note: Modern replacement for deprecated LOC task

    gitignore  Check .gitignore file for required entries
               Requires: None (always available)
               Checks: /build/, /vendor/, IDE settings, tool caches
               Ignores: PHPStorm, VSCode, Claude, Cline, PHP-CS-Fixer, PHPStan
               Supports: --fix-qc-issues to auto-fix

BEHAVIOR:
    - Each check validates its requirements before running
    - Missing tools result in a warning and that check is skipped
    - Checks run sequentially in the order specified
    - The command exits after all checks complete
    - Each check reports its own error count

AUTO-FIX MODE:
    Use --fix-qc-issues to automatically fix issues where possible.
    Currently supported by:
    - gitignore: Creates or updates .gitignore with required entries
    - phpcsfixer: Fixes code style issues (runs in check mode without flag)

EXAMPLES:
    # Run default pipeline (gitignore, lint, phpcsfixer, unit, phpstan)
    horde-components qc

    # Run only syntax check (always works, no external tools needed)
    horde-components qc lint

    # Run only unit tests
    horde-components qc unit

    # Run only PHPStan static analysis
    horde-components qc phpstan

    # Run multiple specific checks
    horde-components qc unit lint
    horde-components qc phpstan md

    # Explicitly run PHPMD (not in default pipeline)
    horde-components qc md

    # Explicitly run PHPCS (not in default pipeline)
    horde-components qc cs

    # Explicitly run PHPLOC (not in default pipeline, deprecated)
    horde-components qc loc

    # Run modern metrics analysis (recommended over loc)
    horde-components qc metrics

    # Check code style (will not modify files)
    horde-components qc phpcsfixer

    # Fix code style issues
    horde-components qc phpcsfixer --fix-qc-issues

    # Check and fix .gitignore
    horde-components qc gitignore --fix-qc-issues

    # Run default pipeline with auto-fix
    horde-components qc --fix-qc-issues

    # Alternative: use the -Q flag (runs default pipeline)
    horde-components -Q

WORKING DIRECTORY:
    The qc command operates on the component in your current working directory.
    Make sure you are in a component directory (containing .horde.yml) before
    running quality checks.

EXIT STATUS:
    The command reports errors found by each check but continues running
    subsequent checks. Individual check results are displayed with:
    - [   OK   ] No problems found
    - [  WARN  ] N error(s) found

INSTALLING REQUIRED TOOLS:
    # Install PHPUnit
    composer require --dev phpunit/phpunit

    # Install PHPMD
    composer require --dev phpmd/phpmd

    # Install PHPCS
    composer require --dev squizlabs/php_codesniffer

    # Install PHPLOC (deprecated, use phpmetrics instead)
    composer require --dev phploc/phploc

    # Install PHPMetrics (modern metrics tool)
    composer require --dev phpmetrics/phpmetrics

NOTE:
    The lint check (php -l) always works without additional dependencies
    and is useful for quick syntax validation.';
    }

    /**
     * Determine if this module should act. Run all required actions if it has
     * been instructed to do so.
     *
     * @param Config $config The configuration.
     *
     * @return bool True if the module performed some action.
     */
    public function handle(Config $config): bool
    {
        $options = $config->getOptions();
        $arguments = $config->getArguments();
        if (!empty($options['qc'])
            || (isset($arguments[0]) && $arguments[0] == 'qc')) {
            $componentDirectory = new ComponentDirectory($options['working_dir'] ?? new CurrentWorkingDirectory());
            $component = $this->dependencies
                ->getComponentFactory()
                ->createSource($componentDirectory);
            $config->setComponent($component);
            $this->dependencies->get(RunnerQc::class)->run($config);
            return true;
        }
        return false;
    }
}
