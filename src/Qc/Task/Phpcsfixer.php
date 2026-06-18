<?php

/**
 * Horde\Components\Qc\Task\Phpcsfixer runs PHP CS Fixer on the component.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Qc\Task;

use Horde\Components\Qc\ToolFinder;
use Phar;

/**
 * Horde\Components\Qc\Task\Phpcsfixer runs PHP CS Fixer on the component.
 *
 * Copyright 2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Phpcsfixer extends Base
{
    /**
     * Statistics collected during execution.
     */
    private array $stats = [
        'files_checked' => 0,
        'files_with_issues' => 0,
        'files_fixed' => 0,
        'files_invalid' => 0,
        'files_skipped' => 0,
    ];

    /**
     * Native PHP CS Fixer JSON results.
     */
    private ?array $nativeResults = null;

    /**
     * Temporary directory created for PHAR config extraction.
     */
    private ?string $tempConfigDir = null;

    /**
     * Get the name of this task.
     *
     * @return string The task name.
     */
    public function getName(): string
    {
        return 'PHP CS Fixer';
    }

    /**
     * Validate the preconditions required for this task.
     *
     * @param array $options Additional options.
     *
     * @return array An empty array if all preconditions are met and a list of
     *               error messages otherwise.
     */
    public function validate(array $options = []): array
    {
        $binary = $this->findPhpCsFixerBinary();

        if ($binary === null) {
            return ['PHP CS Fixer is not installed!'];
        }

        return [];
    }

    /**
     * Run the task.
     *
     * @param array &$options Additional options.
     *
     * @return integer Number of errors.
     */
    public function run(array &$options = []): int
    {
        $binary = $this->findPhpCsFixerBinary($options['tools_dir'] ?? null);

        if ($binary === null) {
            $this->getOutput()->warn('PHP CS Fixer not found - skipping');
            return 0;
        }

        $isDryRun = empty($options['fix_qc_issues']);
        $mode = $isDryRun ? 'CHECK' : 'FIX';

        $this->getOutput()->info("Running PHP CS Fixer in $mode mode...");
        $this->detectVersion($binary);

        $componentPath = $this->getPath();

        if (empty($componentPath)) {
            $componentPath = getcwd();
        }

        // Reset statistics
        $this->stats = [
            'files_checked' => 0,
            'files_with_issues' => 0,
            'files_fixed' => 0,
            'files_invalid' => 0,
            'files_skipped' => 0,
        ];

        // Expose component path to the config file so it can build the Finder
        // against the target component instead of __DIR__ (which points at
        // horde-components' own tree when using the tool config).
        putenv('HORDE_COMPONENT_PATH=' . $componentPath);

        // Setup config extraction for PHAR context or resolve config based on options.
        // In fix mode, risky rewrite fixers are enabled via a wrapper config.
        $configPath = $this->setupConfigForPhar($componentPath, $options, $isDryRun);

        // Execute PHP CS Fixer
        $exitCode = $this->executePhpCsFixer($binary, $componentPath, $isDryRun, $configPath);

        // Parse and output results
        if ($this->nativeResults !== null) {
            $this->parseResults();
            $this->writeJsonResults($componentPath, $exitCode, $isDryRun);
        }

        $this->outputStatistics($isDryRun);

        // Cleanup temp config if created
        $this->cleanupTempConfig();
        putenv('HORDE_COMPONENT_PATH');

        // Return number of files with issues as error count
        return $this->stats['files_with_issues'];
    }

    /**
     * Destructor - ensure temp config is cleaned up.
     */
    public function __destruct()
    {
        $this->cleanupTempConfig();
    }

    /**
     * Find PHP CS Fixer binary in standard locations.
     *
     * @return string|null Path to binary or null if not found.
     */
    private function findPhpCsFixerBinary(?string $toolsDir = null): ?string
    {
        $componentPath = $this->getPath();

        if (empty($componentPath)) {
            $componentPath = getcwd();
        }

        // Use ToolFinder for consistent tool discovery
        $toolFinder = new ToolFinder($componentPath, $toolsDir);
        return $toolFinder->findBinary('php-cs-fixer');
    }

    /**
     * Detect and output PHP CS Fixer version and source.
     *
     * @param string $binary Path to PHP CS Fixer binary.
     *
     * @return void
     */
    private function detectVersion(string $binary): void
    {
        $versionOutput = shell_exec(escapeshellarg($binary) . ' --version 2>&1');

        if ($versionOutput === null) {
            $this->getOutput()->info('Using PHP CS Fixer from: ' . $binary);
            return;
        }

        // Parse version from output like "PHP CS Fixer 3.94.2 by Fabien Potencier..."
        if (preg_match('/PHP CS Fixer ([0-9.]+)/', $versionOutput, $matches)) {
            $version = $matches[1];
            $this->getOutput()->info('Using PHP CS Fixer version ' . $version . ' from: ' . $binary);
        } else {
            $this->getOutput()->info('Using PHP CS Fixer from: ' . $binary);
        }
    }

    /**
     * Execute PHP CS Fixer via CLI.
     *
     * @param string $binary Path to PHP CS Fixer binary.
     * @param string $componentPath Path to component.
     * @param bool $isDryRun Whether to run in check mode.
     * @param string|null $configPath Optional path to config file.
     *
     * @return int Exit code.
     */
    private function executePhpCsFixer(string $binary, string $componentPath, bool $isDryRun, ?string $configPath = null): int
    {
        // First, get total file count using list-files
        $this->stats['files_checked'] = $this->getTotalFileCount($binary, $componentPath, $configPath);

        // Build command — invoke from inside the component dir so relative
        // config paths resolve correctly. We deliberately do NOT pass a
        // positional path argument: passing one would override the config's
        // Finder includes/excludes (PHP-CS-Fixer treats positional paths as
        // an alternative to the Finder, not an intersection by default), and
        // would pull in transient directories such as build/ that the config
        // explicitly excludes.
        $cmd = [
            'cd',
            escapeshellarg($componentPath),
            '&&',
            escapeshellarg($binary),
            'fix',
        ];

        if ($isDryRun) {
            $cmd[] = '--dry-run';
        }

        // Use explicit config if provided (for PHAR context)
        if ($configPath !== null) {
            $cmd[] = '--config=' . escapeshellarg($configPath);
        }

        // Use JSON format for machine-readable output
        $cmd[] = '--format=json';

        // Disable cache for consistent results
        $cmd[] = '--using-cache=no';

        // Allow risky rules (may be needed for some Horde rules)
        $cmd[] = '--allow-risky=yes';

        // Disable interactive prompts (CI/automation mode)
        $cmd[] = '--no-interaction';

        // Show progress if verbose
        if ($this->getOutput()->isVerbose()) {
            $cmd[] = '--verbose';
        }

        $command = implode(' ', $cmd);

        // Execute and capture output
        exec($command . ' 2>&1', $output, $exitCode);

        // Parse JSON output - PHP CS Fixer may output warnings before JSON
        $fullOutput = implode("\n", $output);

        // Extract JSON portion (find the JSON object in the output)
        $jsonOutput = $this->extractJson($fullOutput);

        if ($jsonOutput !== null) {
            $this->nativeResults = json_decode($jsonOutput, true);

            if ($this->nativeResults === null && json_last_error() !== JSON_ERROR_NONE) {
                $this->getOutput()->warn('Failed to parse PHP CS Fixer JSON output');
                if ($this->getOutput()->isVerbose()) {
                    $this->getOutput()->plain('JSON portion: ' . $jsonOutput);
                }
            }
        } else {
            // No JSON found in output
            if ($this->getOutput()->isVerbose()) {
                $this->getOutput()->plain('Full output: ' . $fullOutput);
            }
        }

        return $exitCode;
    }

    /**
     * Get total file count that PHP CS Fixer will check.
     *
     * @param string $binary Path to PHP CS Fixer binary.
     * @param string $componentPath Path to component.
     *
     * @return int Total number of files to be checked.
     */
    private function getTotalFileCount(string $binary, string $componentPath, ?string $configPath = null): int
    {
        $cmd = [
            'cd',
            escapeshellarg($componentPath),
            '&&',
            escapeshellarg($binary),
            'list-files',
        ];

        if ($configPath !== null) {
            $cmd[] = '--config=' . escapeshellarg($configPath);
        }

        $cmd[] = '2>/dev/null';

        $command = implode(' ', $cmd);
        $output = shell_exec($command);

        if ($output === null || $output === '') {
            return 0;
        }

        // Count non-empty lines in output
        $lines = explode("\n", trim($output));
        return count(array_filter($lines, fn($line) => !empty(trim($line))));
    }

    /**
     * Extract JSON object from mixed output.
     *
     * PHP CS Fixer may output warnings and informational messages before the JSON.
     * This method finds and extracts just the JSON portion.
     *
     * @param string $output The full output from PHP CS Fixer.
     *
     * @return string|null The extracted JSON string, or null if not found.
     */
    private function extractJson(string $output): ?string
    {
        // Find the first opening brace that starts a JSON object
        $start = strpos($output, '{');
        if ($start === false) {
            return null;
        }

        // Find the matching closing brace by counting braces
        $braceCount = 0;
        $inString = false;
        $escapeNext = false;
        $length = strlen($output);

        for ($i = $start; $i < $length; $i++) {
            $char = $output[$i];

            if ($escapeNext) {
                $escapeNext = false;
                continue;
            }

            if ($char === '\\') {
                $escapeNext = true;
                continue;
            }

            if ($char === '"') {
                $inString = !$inString;
                continue;
            }

            if (!$inString) {
                if ($char === '{') {
                    $braceCount++;
                } elseif ($char === '}') {
                    $braceCount--;
                    if ($braceCount === 0) {
                        // Found matching closing brace
                        return substr($output, $start, $i - $start + 1);
                    }
                }
            }
        }

        return null;
    }

    /**
     * Parse results from native PHP CS Fixer JSON output.
     *
     * @return void
     */
    private function parseResults(): void
    {
        if (!isset($this->nativeResults['files']) || !is_array($this->nativeResults['files'])) {
            return;
        }

        // PHP-CS-Fixer's JSON output for `--dry-run` lists files in `files`
        // by name only (no `appliedFixers` key) — they are files that
        // *would* be modified. The fix-mode JSON adds `appliedFixers` per
        // file. Count both shapes as files_with_issues; only fix-mode
        // entries are counted as files_fixed.
        foreach ($this->nativeResults['files'] as $file) {
            $isModified = isset($file['appliedFixers'])
                && is_array($file['appliedFixers'])
                && count($file['appliedFixers']) > 0;
            $isDryRunHit = !isset($file['appliedFixers']) && isset($file['name']);

            if ($isModified || $isDryRunHit) {
                $this->stats['files_with_issues']++;
            }
            if ($isModified) {
                $this->stats['files_fixed']++;
            }
        }
    }

    /**
     * Write JSON results file with statistics and metadata.
     *
     * @param string $componentPath Path to the component.
     * @param int $exitCode The exit code.
     * @param bool $isDryRun Whether this was a dry run.
     *
     * @return void
     */
    private function writeJsonResults(string $componentPath, int $exitCode, bool $isDryRun): void
    {
        $buildDir = $componentPath . '/build';

        // Create build directory if it doesn't exist
        if (!is_dir($buildDir)) {
            mkdir($buildDir, 0o755, true);
        }

        // Write native PHP CS Fixer JSON
        $nativeJsonPath = $buildDir . '/php-cs-fixer-native.json';
        if ($this->nativeResults !== null) {
            $json = json_encode($this->nativeResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            file_put_contents($nativeJsonPath, $json);
        }

        // Write custom summary JSON
        $summaryJsonPath = $buildDir . '/php-cs-fixer-results.json';

        $binary = $this->findPhpCsFixerBinary();
        $version = 'unknown';

        if ($binary) {
            $versionOutput = shell_exec(escapeshellarg($binary) . ' --version 2>&1');
            if ($versionOutput && preg_match('/PHP CS Fixer ([0-9.]+)/', $versionOutput, $matches)) {
                $version = $matches[1];
            }
        }

        $summary = [
            'timestamp' => date('c'),
            'php_cs_fixer_version' => $version,
            'tool_source' => $binary ?: 'unknown',
            'mode' => $isDryRun ? 'check' : 'fix',
            'exit_code' => $exitCode,
            'success' => $this->isSuccessExitCode($exitCode, $isDryRun),
            'tooling_error' => $this->isToolingErrorExitCode($exitCode),
            'statistics' => $this->stats,
        ];

        // Add timing and memory if available from native results
        if (isset($this->nativeResults['time']['total'])) {
            $summary['time_seconds'] = $this->nativeResults['time']['total'];
        }

        if (isset($this->nativeResults['memory'])) {
            $summary['memory_mb'] = $this->nativeResults['memory'];
        }

        $json = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($summaryJsonPath, $json);

        $this->getOutput()->info('JSON results written to: ' . $summaryJsonPath);
    }

    /**
     * Output statistics summary.
     *
     * @param bool $isDryRun Whether this was a dry run.
     *
     * @return void
     */
    private function outputStatistics(bool $isDryRun): void
    {
        $parts = [];

        if ($this->stats['files_checked'] > 0) {
            $parts[] = $this->stats['files_checked'] . ' file' . ($this->stats['files_checked'] !== 1 ? 's' : '') . ' checked';
        }

        if ($this->stats['files_with_issues'] > 0) {
            $parts[] = $this->stats['files_with_issues'] . ' with issues';
        }

        if ($this->stats['files_fixed'] > 0 && !$isDryRun) {
            $parts[] = $this->stats['files_fixed'] . ' fixed';
        }

        if ($this->stats['files_invalid'] > 0) {
            $parts[] = $this->stats['files_invalid'] . ' invalid';
        }

        if ($this->stats['files_skipped'] > 0) {
            $parts[] = $this->stats['files_skipped'] . ' skipped';
        }

        if (!empty($parts)) {
            if ($this->stats['files_with_issues'] > 0) {
                if ($isDryRun) {
                    $message = 'Issues found. PHP CS Fixer results: ' . implode(', ', $parts);
                    $message .= ' (use --fix-qc-issues to auto-fix)';
                    $this->getOutput()->warn($message);
                } else {
                    $message = 'Issues fixed. PHP CS Fixer results: ' . implode(', ', $parts);
                    $this->getOutput()->ok($message);
                }
            } else {
                $message = 'No problems found. PHP CS Fixer results: ' . implode(', ', $parts);
                $this->getOutput()->ok($message);
            }
        }
    }

    /**
     * Resolve configuration file path based on --prefer-config-from option.
     *
     * @param string $componentPath Path to component being checked.
     * @param array $options CLI options including prefer_config_from.
     *
     * @return string|null Path to config file (null to use default discovery).
     */
    private function resolveConfigPath(string $componentPath, array $options): ?string
    {
        $preference = $options['prefer_config_from'] ?? 'tool';

        // If explicit path provided, use it
        if ($preference !== null && $preference !== 'tool' && $preference !== 'uut') {
            $explicitPath = $preference;
            if (file_exists($explicitPath)) {
                $resolvedPath = realpath($explicitPath);
                $this->getOutput()->info("Using explicit config: {$resolvedPath}");
                return $resolvedPath;
            }
            $this->getOutput()->warn("Config path not found: {$explicitPath} - falling back to default");
            $preference = null; // Fall through to default behavior
        }

        // "tool" preference - always use horde-components config
        if ($preference === 'tool') {
            $componentsConfig = __DIR__ . '/../../../.php-cs-fixer.dist.php';
            if (file_exists($componentsConfig)) {
                $resolvedPath = realpath($componentsConfig);
                $this->getOutput()->info('Using horde-components config (--prefer-config-from=tool)');
                if ($this->getOutput()->isVerbose()) {
                    $this->getOutput()->info('Config path: ' . $resolvedPath);
                }
                return $resolvedPath;
            }
            $this->getOutput()->warn('horde-components config not found');
            return null;
        }

        // "uut" preference - only use component's own config
        if ($preference === 'uut') {
            $componentConfig = $componentPath . '/.php-cs-fixer.dist.php';
            if (file_exists($componentConfig)) {
                if ($this->getOutput()->isVerbose()) {
                    $this->getOutput()->info('Using component config (--prefer-config-from=uut)');
                }
                return null; // Let PHP CS Fixer discover it
            }
            $this->getOutput()->warn('Component has no .php-cs-fixer.dist.php - no config will be used');
            return null;
        }

        // Default behavior: component config first, then horde-components as fallback
        $componentConfig = $componentPath . '/.php-cs-fixer.dist.php';
        if (file_exists($componentConfig)) {
            // Component has own config - let PHP CS Fixer discover it
            if ($this->getOutput()->isVerbose()) {
                $this->getOutput()->info('Using component\'s own PHP CS Fixer config');
            }
            return null;
        }

        // No component config - use horde-components config as fallback
        $componentsConfig = __DIR__ . '/../../../.php-cs-fixer.dist.php';
        if (file_exists($componentsConfig)) {
            $resolvedPath = realpath($componentsConfig);
            $this->getOutput()->info('Using horde-components PHP CS Fixer config (component has no own config)');
            if ($this->getOutput()->isVerbose()) {
                $this->getOutput()->info('Config path: ' . $resolvedPath);
            }
            return $resolvedPath;
        }

        // No config found anywhere - let PHP CS Fixer use defaults
        $this->getOutput()->warn('No PHP CS Fixer config found - using defaults');
        return null;
    }

    /**
     * Create a wrapper config that enables risky rewrite fixers for fix mode.
     *
     * The wrapper requires the base config file, then overrides the risky
     * rewrite rules to `true` so they run during `--fix-qc-issues`.
     *
     * @param string $baseConfigPath Absolute path to the base config file.
     *
     * @return string|null Path to the wrapper config, or null on failure.
     */
    private function createFixModeConfig(string $baseConfigPath): ?string
    {
        if ($this->tempConfigDir === null) {
            $tempDir = sys_get_temp_dir() . '/horde-cs-fixer-' . uniqid();
            if (!mkdir($tempDir, 0o755, true)) {
                $this->getOutput()->warn('Failed to create temp directory for fix-mode config');
                return $baseConfigPath;
            }
            $this->tempConfigDir = $tempDir;
        }

        $wrapperPath = $this->tempConfigDir . '/.php-cs-fixer.fix-mode.php';
        $escapedBase = addslashes($baseConfigPath);

        $content = "<?php\n"
            . "// Generated wrapper — enables risky rewrite fixers for fix mode.\n"
            . "\$config = require '{$escapedBase}';\n"
            . "\$rules = \$config->getRules();\n"
            . "\$rules['Horde/rewrite_horde_util_to_psr4'] = true;\n"
            // rewrite_horde_to_psr4 is kept disabled until the interaction
            // with global_namespace_import in mixed files is resolved.
            // See: Horde:: vs \Horde\Core\Horde:: ambiguity when both
            // forwarded and non-forwarded calls coexist.
            // . "\$rules['Horde/rewrite_horde_to_psr4'] = true;\n"
            . "\$config->setRules(\$rules);\n"
            . "return \$config;\n";

        if (file_put_contents($wrapperPath, $content) === false) {
            $this->getOutput()->warn('Failed to write fix-mode config wrapper');
            return $baseConfigPath;
        }

        $this->getOutput()->info('Fix mode: risky rewrite fixers enabled');

        return $wrapperPath;
    }

    /**
     * Setup config for PHAR context by extracting config and custom fixers.
     *
     * When running in fix mode ($isDryRun = false), risky rewrite fixers are
     * enabled via a wrapper config that overrides the base config's rules.
     *
     * @param string $componentPath Path to component being checked.
     * @param array $options CLI options including prefer_config_from.
     * @param bool $isDryRun Whether running in check mode (true) or fix mode (false).
     *
     * @return string|null Path to config file (null to use default discovery).
     */
    private function setupConfigForPhar(string $componentPath, array $options = [], bool $isDryRun = true): ?string
    {
        $pharPath = Phar::running(false);

        // Not running from PHAR - resolve config path based on options
        if ($pharPath === '') {
            $configPath = $this->resolveConfigPath($componentPath, $options);

            if (!$isDryRun && $configPath !== null) {
                return $this->createFixModeConfig($configPath);
            }

            return $configPath;
        }

        $this->getOutput()->info('Running from PHAR - extracting custom fixers...');

        // Create temp directory
        $tempDir = sys_get_temp_dir() . '/horde-cs-fixer-' . uniqid();
        if (!mkdir($tempDir, 0o755, true)) {
            $this->getOutput()->warn('Failed to create temp directory for config extraction');
            return null;
        }

        $this->tempConfigDir = $tempDir;

        // Create subdirectory for custom fixers
        $fixerDir = $tempDir . '/src/PhpCsFixer';
        if (!mkdir($fixerDir, 0o755, true)) {
            $this->getOutput()->warn('Failed to create fixer directory');
            $this->cleanupTempConfig();
            return null;
        }

        // Extract custom fixers
        $fixerFiles = [
            'RemovePhpVersionCommentFixer.php',
            'UpdateCopyrightYearFixer.php',
            'MarkDeprecatedHordeCallsFixer.php',
            'RewriteHordeUtilToPsr4Fixer.php',
            'RewriteHordeToPsr4Fixer.php',
        ];

        foreach ($fixerFiles as $file) {
            $source = 'phar://' . $pharPath . '/src/PhpCsFixer/' . $file;
            $dest = $fixerDir . '/' . $file;

            if (!copy($source, $dest)) {
                $this->getOutput()->warn("Failed to extract fixer: $file");
                $this->cleanupTempConfig();
                return null;
            }
        }

        // Extract config file
        $configSource = 'phar://' . $pharPath . '/.php-cs-fixer.dist.php';
        $configDest = $tempDir . '/.php-cs-fixer.dist.php';

        if (!copy($configSource, $configDest)) {
            $this->getOutput()->warn('Failed to extract config file');
            $this->cleanupTempConfig();
            return null;
        }

        if ($this->getOutput()->isVerbose()) {
            $this->getOutput()->info('Extracted config to: ' . $configDest);
        }

        if (!$isDryRun) {
            return $this->createFixModeConfig($configDest);
        }

        return $configDest;
    }

    /**
     * Cleanup temporary config directory.
     *
     * @return void
     */
    private function cleanupTempConfig(): void
    {
        if ($this->tempConfigDir === null || !is_dir($this->tempConfigDir)) {
            return;
        }

        // Recursively remove directory
        $this->recursiveRemoveDirectory($this->tempConfigDir);
        $this->tempConfigDir = null;
    }

    /**
     * Recursively remove a directory and its contents.
     *
     * @param string $dir Directory to remove.
     *
     * @return bool Success status.
     */
    private function recursiveRemoveDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $items = scandir($dir);
        if ($items === false) {
            return false;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path)) {
                $this->recursiveRemoveDirectory($path);
            } else {
                unlink($path);
            }
        }

        return rmdir($dir);
    }

    /**
     * Decide whether a PHP-CS-Fixer exit code means "style is clean".
     *
     * PHP-CS-Fixer 3.x exit codes (combinable bit flags):
     *   0   OK
     *   1   General error
     *   4   Some files have invalid syntax
     *   8   In dry-run/check mode: some files would be modified
     *   16  Configuration error
     *   32  Configuration of a fixer is invalid
     *   64  Exception raised within the application
     *
     * For CI gating "is style clean":
     *   exit 0   → success
     *   exit 8 in dry-run → real style issues → failure
     *   anything else → failure (covers tooling errors and unknowns)
     *
     * Note that exit code 1 is reserved by PHP-CS-Fixer for general errors;
     * we treat it as a non-success too because we cannot tell otherwise.
     */
    private function isSuccessExitCode(int $exitCode, bool $isDryRun): bool
    {
        return $exitCode === 0;
    }

    /**
     * Whether an exit code indicates a tooling failure (as opposed to
     * a real style finding). Used by reporters to distinguish "code has
     * style issues" from "the fixer itself blew up".
     *
     * Tooling errors per PHP-CS-Fixer 3.x: 16 (config), 32 (fixer config),
     * 64 (uncaught exception). Bits may be combined with other flags.
     */
    private function isToolingErrorExitCode(int $exitCode): bool
    {
        return ($exitCode & (16 | 32 | 64)) !== 0;
    }
}
