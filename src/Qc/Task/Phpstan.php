<?php

/**
 * Horde\Components\Qc\Task\Phpstan runs PHPStan static analysis on the component.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Qc\Task;

/**
 * Horde\Components\Qc\Task\Phpstan runs PHPStan static analysis on the component.
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
class Phpstan extends Base
{
    /**
     * Statistics collected during execution.
     */
    private array $stats = [
        'files_analyzed' => 0,
        'errors' => 0,
        'file_errors' => 0,
    ];

    /**
     * Native PHPStan JSON results.
     */
    private ?array $nativeResults = null;

    /**
     * PHPStan configuration path.
     */
    private ?string $configPath = null;

    /**
     * PHPStan level used.
     */
    private int $level = 0;

    /**
     * Get the name of this task.
     *
     * @return string The task name.
     */
    public function getName(): string
    {
        return 'PHPStan static analysis';
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
        $binary = $this->findPhpStanBinary();

        if ($binary === null) {
            return ['PHPStan is not installed!'];
        }

        return [];
    }

    /**
     * Run the task with watermark-based auto-discovery.
     *
     * @param array &$options Additional options.
     *
     * @return integer Number of errors.
     */
    public function run(array &$options = []): int
    {
        $binary = $this->findPhpStanBinary();

        if ($binary === null) {
            $this->getOutput()->warn('PHPStan not found - skipping');
            return 0;
        }

        try {
            $componentPath = $this->getPath();

            if (empty($componentPath)) {
                $componentPath = getcwd();
            }

            $this->getOutput()->info('Running PHPStan with watermark detection...');
            $this->detectVersion($binary);

            // Get current watermark from .horde.yml (default: 1 if missing)
            $watermark = $this->getWatermarkFromHordeYml();

            $this->getOutput()->info('Current watermark: level ' . $watermark);

            // Reset statistics
            $this->stats = [
                'files_analyzed' => 0,
                'errors' => 0,
                'file_errors' => 0,
            ];

            // Run at watermark level (MUST PASS)
            $this->getOutput()->running('Testing watermark level ' . $watermark . '...');
            $watermarkResult = $this->testLevel($binary, $componentPath, $watermark);

            if (!$watermarkResult['passed']) {
                // CODE REGRESSION - fails at watermark!
                $this->getOutput()->regression(
                    'Code fails at watermark level ' . $watermark
                    . ' (' . $watermarkResult['errors'] . ' error'
                    . ($watermarkResult['errors'] !== 1 ? 's' : '') . ')'
                );
                $this->getOutput()->warn('Watermark level MUST pass - fix these errors!');

                // Parse results for output
                $this->nativeResults = $watermarkResult['results'];
                $this->level = $watermark;
                $this->parseResults();
                $this->writeJsonResults($componentPath, $watermarkResult['exit_code'], $watermark);
                $this->outputStatistics();

                return $watermarkResult['errors']; // Non-zero = failure
            }

            // Watermark passed - discover highest passing level
            $this->getOutput()->running('Discovering highest passing level...');

            $highestPassing = $watermark;
            $testLevel = $watermark + 1;

            while ($testLevel <= 9) {
                $this->getOutput()->info('Testing level ' . $testLevel . '...');
                $result = $this->testLevel($binary, $componentPath, $testLevel);

                if ($result['passed']) {
                    $this->getOutput()->ok('✓ Level ' . $testLevel . ' passes');
                    $highestPassing = $testLevel;
                    $testLevel++;
                } else {
                    $this->getOutput()->info(
                        '✗ Level ' . $testLevel . ' fails with ' . $result['errors']
                        . ' error' . ($result['errors'] !== 1 ? 's' : '')
                    );
                    break; // Stop at first failure
                }
            }

            // Check if we found a higher level
            if ($highestPassing > $watermark) {
                // CODE IMPROVED - passes higher level(s)!
                $this->getOutput()->ok(
                    '✅ IMPROVEMENT: Code passes up to level ' . $highestPassing . '!'
                );

                // Auto-raise watermark to highest passing level
                $this->updateWatermarkInHordeYml($highestPassing, $watermark);

                $this->getOutput()->ok(
                    '🎉 Watermark auto-raised: ' . $watermark . ' → ' . $highestPassing
                );
                $this->getOutput()->info('Commit .horde.yml to persist this improvement');

                // Re-run at highest level to get final results
                $finalResult = $this->testLevel($binary, $componentPath, $highestPassing);
                $this->nativeResults = $finalResult['results'];
                $this->level = $highestPassing;
            } elseif ($highestPassing === 9) {
                // Already at maximum level
                $this->getOutput()->ok('✓ Code passes watermark level ' . $watermark . ' (maximum)');
                $this->nativeResults = $watermarkResult['results'];
                $this->level = $watermark;
            } else {
                // At watermark, cannot raise yet
                $nextLevel = $watermark + 1;
                $nextResult = $this->testLevel($binary, $componentPath, $nextLevel);

                $this->getOutput()->ok(
                    '✓ Code passes watermark level ' . $watermark
                );
                $this->getOutput()->info(
                    'Next level (' . $nextLevel . ') has ' . $nextResult['errors']
                    . ' error' . ($nextResult['errors'] !== 1 ? 's' : '') . ' remaining'
                );

                // Use watermark results for output
                $this->nativeResults = $watermarkResult['results'];
                $this->level = $watermark;
            }

            // Parse and output results
            if ($this->nativeResults !== null) {
                $this->parseResults();
                $this->writeJsonResults($componentPath, 0, $this->level);
            }

            $this->outputStatistics();

            // Always return 0 if watermark passes (even if we cannot raise)
            return 0;

        } catch (\Throwable $e) {
            $this->getOutput()->warn('PHPStan execution failed: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * Find PHPStan binary in standard locations.
     *
     * @return string|null Path to binary or null if not found.
     */
    private function findPhpStanBinary(): ?string
    {
        $componentPath = $this->getPath();

        if (empty($componentPath)) {
            $componentPath = getcwd();
        }

        // Order of preference (matching other tasks):
        // 1. vendor/bin (Composer)
        // 2. tools/ (local tools)
        // 3. ~/.phive/ (Phive)
        // 4. system PATH
        $locations = [
            $componentPath . '/vendor/bin/phpstan',
            $componentPath . '/vendor/bin/phpstan.phar',
            $componentPath . '/tools/phpstan',
            $componentPath . '/tools/phpstan.phar',
            $_SERVER['HOME'] . '/.phive/phpstan',
            $_SERVER['HOME'] . '/.phive/phpstan.phar',
            '/usr/local/bin/phpstan',
            '/usr/bin/phpstan',
        ];

        foreach ($locations as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        // Fallback: check PATH
        $which = trim((string) shell_exec('which phpstan 2>/dev/null'));
        if (!empty($which) && file_exists($which)) {
            return $which;
        }

        return null;
    }

    /**
     * Detect and output PHPStan version and source.
     *
     * @param string $binary Path to PHPStan binary.
     *
     * @return void
     */
    private function detectVersion(string $binary): void
    {
        $versionOutput = shell_exec(escapeshellarg($binary) . ' --version 2>&1');

        if ($versionOutput === null) {
            $this->getOutput()->info('Using PHPStan from: ' . $binary);
            return;
        }

        // Parse version from output like "PHPStan - PHP Static Analysis Tool 1.12.9"
        if (preg_match('/PHPStan.*?([0-9]+\.[0-9]+\.[0-9]+)/', $versionOutput, $matches)) {
            $version = $matches[1];
            $this->getOutput()->info('Using PHPStan version ' . $version . ' from: ' . $binary);
        } else {
            $this->getOutput()->info('Using PHPStan from: ' . $binary);
        }
    }

    /**
     * Find PHPStan configuration file.
     *
     * @param string $componentPath Path to component.
     *
     * @return string|null Path to config file or null if not found.
     */
    private function findConfiguration(string $componentPath): ?string
    {
        $possibleConfigs = [
            $componentPath . '/phpstan.neon',
            $componentPath . '/phpstan.neon.dist',
            $componentPath . '/phpstan.dist.neon',
            $componentPath . '/.phpstan.neon',
            $componentPath . '/.phpstan.neon.dist',
        ];

        foreach ($possibleConfigs as $config) {
            if (file_exists($config)) {
                return $config;
            }
        }

        return null;
    }

    /**
     * Get current watermark from .horde.yml.
     * Returns 1 if not set (lowest meaningful level).
     *
     * @return int Watermark level (1-9).
     */
    private function getWatermarkFromHordeYml(): int
    {
        try {
            $component = $this->getComponent();
            $hordeYml = $component->getHordeYml();

            if (isset($hordeYml['quality']['phpstan']['level'])) {
                return (int) $hordeYml['quality']['phpstan']['level'];
            }
        } catch (\Throwable $e) {
            // Fall through to default
        }

        // Default: level 1 (lowest meaningful level)
        return 1;
    }

    /**
     * Update watermark in .horde.yml file.
     *
     * @param int $newLevel New watermark level.
     * @param int $oldLevel Previous watermark level.
     *
     * @return void
     */
    private function updateWatermarkInHordeYml(int $newLevel, int $oldLevel): void
    {
        $componentPath = $this->getPath();

        if (empty($componentPath)) {
            $componentPath = getcwd();
        }

        $hordeYmlPath = $componentPath . '/.horde.yml';

        if (!file_exists($hordeYmlPath)) {
            $this->getOutput()->warn('Cannot auto-raise: .horde.yml not found at ' . $hordeYmlPath);
            return;
        }

        try {
            // Read current content
            $content = file_get_contents($hordeYmlPath);

            // Check if quality.phpstan.level already exists
            if (preg_match('/^(\s*)phpstan:\s*$/m', $content)) {
                // phpstan section exists - check if level exists
                if (preg_match('/^(\s*)level:\s*\d+\s*$/m', $content)) {
                    // Update existing level
                    $content = preg_replace(
                        '/^(\s*)level:\s*\d+\s*$/m',
                        '${1}level: ' . $newLevel,
                        $content
                    );
                } else {
                    // Add level to existing phpstan section
                    $content = preg_replace(
                        '/^(\s*)phpstan:\s*$/m',
                        '${1}phpstan:' . "\n" . '${1}  level: ' . $newLevel,
                        $content
                    );
                }
            } elseif (preg_match('/^(\s*)quality:\s*$/m', $content)) {
                // quality section exists but no phpstan - add it
                $content = preg_replace(
                    '/^(\s*)quality:\s*$/m',
                    '${1}quality:' . "\n" . '${1}  phpstan:' . "\n" . '${1}    level: ' . $newLevel,
                    $content
                );
            } else {
                // No quality section - add at end
                $content = rtrim($content) . "\n\nquality:\n  phpstan:\n    level: " . $newLevel . "\n";
            }

            // Write back
            file_put_contents($hordeYmlPath, $content);

            $this->getOutput()->info('Updated .horde.yml watermark: ' . $oldLevel . ' → ' . $newLevel);

        } catch (\Throwable $e) {
            $this->getOutput()->warn('Failed to update .horde.yml: ' . $e->getMessage());
        }
    }

    /**
     * Test PHPStan at a specific level.
     *
     * @param string $binary Path to PHPStan binary.
     * @param string $componentPath Path to component.
     * @param int $level Level to test (0-9).
     *
     * @return array ['passed' => bool, 'errors' => int, 'exit_code' => int, 'results' => array|null]
     */
    private function testLevel(string $binary, string $componentPath, int $level): array
    {
        $cmd = [
            escapeshellarg($binary),
            'analyse',
            '--level=' . $level,
            '--error-format=json',
            '--no-progress',
            '--no-ansi',
            '--memory-limit=512M',
        ];

        // Do NOT use config file when testing levels
        // Config files may contain level settings that override --level argument
        // Always explicitly specify paths instead
        $configPath = $this->findConfiguration($componentPath);
        if ($configPath !== null) {
            // Parse paths from config, but don't use config file
            $paths = $this->getPathsFromConfig($configPath);
            if (!empty($paths)) {
                foreach ($paths as $path) {
                    if ($path[0] !== '/') {
                        // Relative path
                        $path = $componentPath . '/' . $path;
                    }
                    $cmd[] = escapeshellarg($path);
                }
            } else {
                // Config exists but no paths - use src/
                $cmd[] = escapeshellarg($componentPath . '/src');
            }
        } else {
            // No config - default to src/
            $srcPath = $componentPath . '/src';
            if (is_dir($srcPath)) {
                $cmd[] = escapeshellarg($srcPath);
            } else {
                $cmd[] = escapeshellarg($componentPath);
            }
        }

        $command = implode(' ', $cmd);

        // Execute and capture output
        exec($command . ' 2>&1', $output, $exitCode);

        // Parse JSON output
        $fullOutput = implode("\n", $output);
        $jsonOutput = $this->extractJson($fullOutput);

        $errors = 0;
        $results = null;

        if ($jsonOutput !== null) {
            $results = json_decode($jsonOutput, true);
            if ($results !== null && isset($results['totals'])) {
                // PHPStan 2.x uses 'file_errors' as the main error count
                // 'errors' is often 0 even when there are many file_errors
                if (isset($results['totals']['file_errors'])) {
                    $errors = $results['totals']['file_errors'];
                } elseif (isset($results['totals']['errors'])) {
                    $errors = $results['totals']['errors'];
                }
            }
        }

        return [
            'passed' => ($exitCode === 0 || $errors === 0),
            'errors' => $errors,
            'exit_code' => $exitCode,
            'results' => $results,
        ];
    }

    /**
     * Get paths from PHPStan config file.
     *
     * @param string $configPath Path to config file.
     *
     * @return array List of paths to analyze.
     */
    private function getPathsFromConfig(string $configPath): array
    {
        $content = file_get_contents($configPath);

        if ($content === false) {
            return [];
        }

        $paths = [];

        // Parse NEON format paths section
        // paths:
        //   - src
        //   - test
        if (preg_match('/^\s*paths:\s*$/m', $content, $matches, PREG_OFFSET_CAPTURE)) {
            $pathsStart = $matches[0][1] + strlen($matches[0][0]);
            $remaining = substr($content, $pathsStart);

            // Extract indented paths
            if (preg_match_all('/^\s+-\s+(.+)$/m', $remaining, $pathMatches)) {
                foreach ($pathMatches[1] as $path) {
                    $paths[] = trim($path);
                }
            }
        }

        return $paths;
    }

    /**
     * Extract JSON object from mixed output.
     *
     * PHPStan may output warnings and informational messages before the JSON.
     * This method finds and extracts just the JSON portion.
     *
     * @param string $output The full output from PHPStan.
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
     * Parse results from native PHPStan JSON output.
     *
     * @return void
     */
    private function parseResults(): void
    {
        if ($this->nativeResults === null) {
            return;
        }

        // Extract totals
        // PHPStan 2.x uses 'file_errors' as the main count, 'errors' is often 0
        if (isset($this->nativeResults['totals'])) {
            if (isset($this->nativeResults['totals']['file_errors'])) {
                $this->stats['errors'] = $this->nativeResults['totals']['file_errors'];
                $this->stats['file_errors'] = $this->nativeResults['totals']['file_errors'];
            } elseif (isset($this->nativeResults['totals']['errors'])) {
                $this->stats['errors'] = $this->nativeResults['totals']['errors'];
                $this->stats['file_errors'] = $this->nativeResults['totals']['errors'];
            }
        }

        // Count files analyzed
        if (isset($this->nativeResults['files'])) {
            $this->stats['files_analyzed'] = count($this->nativeResults['files']);
        }
    }

    /**
     * Write JSON results file with statistics and metadata.
     *
     * @param string $componentPath Path to the component.
     * @param int $exitCode The exit code.
     * @param int $level The analysis level used.
     *
     * @return void
     */
    private function writeJsonResults(string $componentPath, int $exitCode, int $level): void
    {
        $buildDir = $componentPath . '/build';

        // Create build directory if it does not exist
        if (!is_dir($buildDir)) {
            mkdir($buildDir, 0o755, true);
        }

        // Write native PHPStan JSON
        $nativeJsonPath = $buildDir . '/phpstan-native.json';
        if ($this->nativeResults !== null) {
            $json = json_encode($this->nativeResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            file_put_contents($nativeJsonPath, $json);
        }

        // Write custom summary JSON
        $summaryJsonPath = $buildDir . '/phpstan-results.json';

        $binary = $this->findPhpStanBinary();
        $version = 'unknown';

        if ($binary) {
            $versionOutput = shell_exec(escapeshellarg($binary) . ' --version 2>&1');
            if ($versionOutput && preg_match('/PHPStan.*?([0-9]+\.[0-9]+\.[0-9]+)/', $versionOutput, $matches)) {
                $version = $matches[1];
            }
        }

        $summary = [
            'timestamp' => date('c'),
            'phpstan_version' => $version,
            'tool_source' => $binary ?: 'unknown',
            'level' => $level,
            'configuration' => $this->configPath ? basename($this->configPath) : null,
            'baseline_used' => file_exists($componentPath . '/phpstan-baseline.neon'),
            'exit_code' => $exitCode,
            'success' => ($exitCode === 0),
            'statistics' => $this->stats,
        ];

        $json = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($summaryJsonPath, $json);

        $this->getOutput()->info('JSON results written to: ' . $summaryJsonPath);
    }

    /**
     * Output statistics summary.
     *
     * @return void
     */
    private function outputStatistics(): void
    {
        $parts = [];

        if ($this->stats['files_analyzed'] > 0) {
            $parts[] = $this->stats['files_analyzed'] . ' file' . ($this->stats['files_analyzed'] !== 1 ? 's' : '') . ' analyzed';
        }

        if ($this->stats['errors'] > 0) {
            $parts[] = $this->stats['errors'] . ' error' . ($this->stats['errors'] !== 1 ? 's' : '');
        }

        if (!empty($parts)) {
            if ($this->stats['errors'] > 0) {
                $message = 'PHPStan results (level ' . $this->level . '): ' . implode(', ', $parts);
                $this->getOutput()->warn($message);
            } else {
                $message = 'No problems found. PHPStan results (level ' . $this->level . '): ' . implode(', ', $parts);
                $this->getOutput()->ok($message);
            }
        }
    }
}
