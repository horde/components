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
        $binary = $this->findPhpCsFixerBinary();

        if ($binary === null) {
            $this->getOutput()->warn('PHP CS Fixer not found - skipping');
            return 0;
        }

        $isDryRun = empty($options['fix_qc_issues']);
        $mode = $isDryRun ? 'CHECK' : 'FIX';

        $this->getOutput()->info("Running PHP CS Fixer in $mode mode...");
        $this->detectVersion($binary);

        $componentPath = $this->_config->getPath();

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

        // Execute PHP CS Fixer
        $exitCode = $this->executePhpCsFixer($binary, $componentPath, $isDryRun);

        // Parse and output results
        if ($this->nativeResults !== null) {
            $this->parseResults();
            $this->writeJsonResults($componentPath, $exitCode, $isDryRun);
        }

        $this->outputStatistics($isDryRun);

        // Return number of files with issues as error count
        return $this->stats['files_with_issues'];
    }

    /**
     * Find PHP CS Fixer binary in standard locations.
     *
     * @return string|null Path to binary or null if not found.
     */
    private function findPhpCsFixerBinary(): ?string
    {
        $componentPath = $this->_config->getPath();

        // Order of preference (matching PHPUnit task):
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
        $which = trim((string) shell_exec('which php-cs-fixer 2>/dev/null'));
        if (!empty($which) && file_exists($which)) {
            return $which;
        }

        return null;
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
     *
     * @return int Exit code.
     */
    private function executePhpCsFixer(string $binary, string $componentPath, bool $isDryRun): int
    {
        // Build command
        $cmd = [
            escapeshellarg($binary),
            'fix',
            escapeshellarg($componentPath),
        ];

        if ($isDryRun) {
            $cmd[] = '--dry-run';
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

        foreach ($this->nativeResults['files'] as $file) {
            $this->stats['files_checked']++;

            if (isset($file['appliedFixers']) && is_array($file['appliedFixers']) && count($file['appliedFixers']) > 0) {
                $this->stats['files_with_issues']++;
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
            'success' => ($exitCode === 0),
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
}
