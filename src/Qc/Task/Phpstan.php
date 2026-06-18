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

use Horde\Components\Qc\PhpStanRuleExtractor;
use Horde\Components\Qc\ToolFinder;
use Throwable;

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
        'files_scanned' => 0,
        'files_with_errors' => 0,
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
        $binary = $this->findPhpStanBinary($options['tools_dir'] ?? null);

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
        $binary = $this->findPhpStanBinary($options['tools_dir'] ?? null);

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
                'files_scanned' => 0,
                'files_with_errors' => 0,
                'errors' => 0,
                'file_errors' => 0,
            ];

            // Run at watermark level (MUST PASS)
            $this->getOutput()->running('Testing watermark level ' . $watermark . '...');
            $watermarkResult = $this->testLevel($binary, $componentPath, $watermark, $options);

            if (!$watermarkResult['passed']) {
                if ($watermarkResult['errors'] > 0) {
                    // CODE REGRESSION - fails at watermark with concrete errors
                    $this->getOutput()->regression(
                        'Code fails at watermark level ' . $watermark
                        . ' (' . $watermarkResult['errors'] . ' error'
                        . ($watermarkResult['errors'] !== 1 ? 's' : '') . ')'
                    );
                    $this->getOutput()->warn('Watermark level MUST pass - fix these errors!');
                } else {
                    // TOOLING FAILURE - PHPStan refused to start (config error,
                    // missing autoload-file, unloadable rule classes, etc.).
                    // Surface the raw output so the caller can diagnose.
                    $this->getOutput()->error(
                        'PHPStan exited with code ' . $watermarkResult['exit_code']
                        . ' before producing results. This is a tooling failure '
                        . 'rather than a code regression.'
                    );
                    $rawOutput = (string) ($watermarkResult['raw_output'] ?? '');
                    if ($rawOutput !== '') {
                        foreach (array_slice(explode("\n", $rawOutput), 0, 20) as $line) {
                            $this->getOutput()->plain($line);
                        }
                    }
                }

                // Parse results for output
                $this->nativeResults = $watermarkResult['results'];
                $this->level = $watermark;
                $this->parseResults();
                $this->writeJsonResults($componentPath, $watermarkResult['exit_code'], $watermark);
                $this->outputStatistics();

                return max(1, $watermarkResult['errors']); // Non-zero = failure
            }

            // Watermark passed - discover highest passing level
            $this->getOutput()->running('Discovering highest passing level...');

            $highestPassing = $watermark;
            $testLevel = $watermark + 1;

            while ($testLevel <= 9) {
                $this->getOutput()->info('Testing level ' . $testLevel . '...');
                $result = $this->testLevel($binary, $componentPath, $testLevel, $options);

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
                $finalResult = $this->testLevel($binary, $componentPath, $highestPassing, $options);
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
                $nextResult = $this->testLevel($binary, $componentPath, $nextLevel, $options);

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

        } catch (Throwable $e) {
            $this->getOutput()->warn('PHPStan execution failed: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * Find PHPStan binary in standard locations.
     *
     * @param string|null $toolsDir Optional tools directory to check first
     * @return string|null Path to binary or null if not found.
     */
    private function findPhpStanBinary(?string $toolsDir = null): ?string
    {
        $componentPath = $this->getPath();

        if (empty($componentPath)) {
            $componentPath = getcwd();
        }

        // Use ToolFinder for consistent tool discovery
        $toolFinder = new ToolFinder($componentPath, $toolsDir);
        return $toolFinder->findBinary('phpstan');
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
     * Supports --prefer-config-from option:
     * - "tool": Use horde-components phpstan.neon
     * - "uut": Only use component's config (no fallback)
     * - path: Use explicit config path
     * - default: Component config first, then horde-components fallback
     *
     * @param string $componentPath Path to component.
     * @param array $options CLI options including prefer_config_from.
     *
     * @return string|null Path to config file or null if not found.
     */
    private function findConfiguration(string $componentPath, array $options = []): ?string
    {
        $preference = $options['prefer_config_from'] ?? null;

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

        // "tool" preference - use horde-components config
        if ($preference === 'tool') {
            $componentsConfig = __DIR__ . '/../../../phpstan.neon';
            if (file_exists($componentsConfig)) {
                $resolvedPath = realpath($componentsConfig);
                $this->getOutput()->info('Using horde-components config (--prefer-config-from=tool)');
                if ($this->getOutput()->isVerbose()) {
                    $this->getOutput()->info('Config path: ' . $resolvedPath);
                }
                return $resolvedPath;
            }
            $this->getOutput()->warn('horde-components phpstan.neon not found');
            return null;
        }

        // "uut" preference - only use component's own config
        if ($preference === 'uut') {
            $componentConfigs = [
                $componentPath . '/phpstan.neon',
                $componentPath . '/phpstan.neon.dist',
                $componentPath . '/phpstan.dist.neon',
                $componentPath . '/.phpstan.neon',
                $componentPath . '/.phpstan.neon.dist',
            ];

            foreach ($componentConfigs as $config) {
                if (file_exists($config)) {
                    if ($this->getOutput()->isVerbose()) {
                        $this->getOutput()->info('Using component config (--prefer-config-from=uut)');
                    }
                    return $config;
                }
            }

            $this->getOutput()->warn('Component has no phpstan.neon - no config will be used');
            return null;
        }

        // Default behavior: component config first
        $possibleConfigs = [
            $componentPath . '/phpstan.neon',
            $componentPath . '/phpstan.neon.dist',
            $componentPath . '/phpstan.dist.neon',
            $componentPath . '/.phpstan.neon',
            $componentPath . '/.phpstan.neon.dist',
        ];

        foreach ($possibleConfigs as $config) {
            if (file_exists($config)) {
                if ($this->getOutput()->isVerbose()) {
                    $this->getOutput()->info('Using component\'s own PHPStan config');
                }
                return $config;
            }
        }

        // No component config - use horde-components config as fallback
        $componentsConfig = __DIR__ . '/../../../phpstan.neon';
        if (file_exists($componentsConfig)) {
            $resolvedPath = realpath($componentsConfig);
            $this->getOutput()->info('Using horde-components PHPStan config (component has no own config)');
            if ($this->getOutput()->isVerbose()) {
                $this->getOutput()->info('Config path: ' . $resolvedPath);
            }
            return $resolvedPath;
        }

        // No config found
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
        } catch (Throwable $e) {
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

        } catch (Throwable $e) {
            $this->getOutput()->warn('Failed to update .horde.yml: ' . $e->getMessage());
        }
    }

    /**
     * Test PHPStan at a specific level.
     *
     * @param string $binary Path to PHPStan binary.
     * @param string $componentPath Path to component.
     * @param int $level Level to test (0-9).
     * @param array $options CLI options.
     *
     * @return array ['passed' => bool, 'errors' => int, 'exit_code' => int, 'results' => array|null]
     */
    private function testLevel(string $binary, string $componentPath, int $level, array $options = []): array
    {
        // Generate temporary config file with auto-detected paths
        $tempConfig = $this->generateTempConfig($componentPath, $level);

        // Materialize the horde-components custom-rules bootstrap on disk.
        // When running from a phar, the bootstrap and rule files have to be
        // copied out before we hand the path to a separate phpstan process.
        $extractor = new PhpStanRuleExtractor();
        $extractCacheDir = $options['tools_dir'] ?? sys_get_temp_dir() . '/horde-components-phpstan';
        try {
            $componentsBootstrap = $extractor->extract($extractCacheDir);
        } catch (Throwable $e) {
            $this->getOutput()->warn('PHPStan rule extraction failed: ' . $e->getMessage());
            $componentsBootstrap = null;
        }

        $cmd = [
            escapeshellarg($binary),
            'analyse',
            '--configuration=' . escapeshellarg($tempConfig),
        ];

        // Add autoload file for custom rules
        if ($componentsBootstrap !== null && file_exists($componentsBootstrap)) {
            $cmd[] = '--autoload-file=' . escapeshellarg($componentsBootstrap);
        }

        $cmd = array_merge($cmd, [
            '--error-format=json',
            '--no-progress',
            '--no-ansi',
            '--memory-limit=512M',
        ]);

        $command = implode(' ', $cmd);

        // Execute and capture output
        exec($command . ' 2>&1', $output, $exitCode);

        // Clean up temp config
        @unlink($tempConfig);

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

        // `passed` reflects only that PHPStan exited cleanly. Code-regression
        // detection (errors > 0 at the watermark level) is the caller's job.
        // Conflating the two — old contract was `passed = (exit==0 && errors==0)`
        // — caused tooling failures (e.g. autoload-file unreachable in phar
        // context) to be reported as code regressions with "0 errors".
        return [
            'passed' => ($exitCode === 0),
            'errors' => $errors,
            'exit_code' => $exitCode,
            'results' => $results,
            'raw_output' => $fullOutput,
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
     * Resolve which directories to analyze for a given component.
     *
     * Mirrors {@see generateTempConfig()} so a separate scanned-file
     * count can be computed without re-parsing the NEON.
     *
     * @param string $componentPath Path to component directory.
     * @return array<string> Absolute directory paths.
     */
    private function resolveAnalysisPaths(string $componentPath): array
    {
        $paths = [];
        foreach (['src', 'migration'] as $candidate) {
            $path = $componentPath . '/' . $candidate;
            if (is_dir($path)) {
                $paths[] = $path;
            }
        }
        if (empty($paths)) {
            $paths[] = $componentPath;
        }
        return $paths;
    }

    /**
     * Count `.php` files under a list of directories, excluding vendor/
     * and build/ to match the NEON excludePaths.
     *
     * @param array<string> $paths Absolute directory paths.
     * @return int Count of `.php` files reachable from those paths.
     */
    private function countPhpFiles(array $paths): int
    {
        $count = 0;
        foreach ($paths as $root) {
            if (!is_dir($root)) {
                if (is_file($root) && str_ends_with($root, '.php')) {
                    $count++;
                }
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $root,
                    \FilesystemIterator::SKIP_DOTS
                )
            );
            foreach ($iterator as $file) {
                $path = (string) $file;
                if (!str_ends_with($path, '.php')) {
                    continue;
                }
                // Match the NEON excludePaths: anything under vendor/ or build/.
                if (str_contains($path, '/vendor/') || str_contains($path, '/build/')) {
                    continue;
                }
                $count++;
            }
        }
        return $count;
    }

    /**
     * Generate temporary PHPStan config with auto-detected paths.
     *
     * @param string $componentPath Path to component directory.
     * @param int    $level         PHPStan level to enforce.
     *
     * @return string Path to temporary config file.
     */
    private function generateTempConfig(string $componentPath, int $level): string
    {
        // Auto-detect paths to analyze (use absolute paths)
        $paths = array_map(
            static fn (string $p): string => "        - " . $p,
            $this->resolveAnalysisPaths($componentPath)
        );

        $pathsYaml = implode("\n", $paths);

        // Check for PHPUnit extension
        $includesSection = '';
        if (file_exists($componentPath . '/vendor/phpstan/phpstan-phpunit/extension.neon')) {
            $includesSection = "includes:\n    - {$componentPath}/vendor/phpstan/phpstan-phpunit/extension.neon\n\n";
        }

        // Note: Custom Horde rules are loaded via --autoload-file CLI parameter
        // See testLevel() method where phpstan-bootstrap.php is passed
        $config = <<<NEON
{$includesSection}parameters:
    level: $level
    paths:
$pathsYaml
    excludePaths:
        - vendor (?)
        - build (?)
    tmpDir: build/phpstan
    bootstrapFiles:
        - {$componentPath}/vendor/autoload.php

rules:
    - Horde\\Components\\PhpStan\\Rules\\NoDirectGlobalAccessRule
    - Horde\\Components\\PhpStan\\Rules\\RequireImmutableUriUsageRule
    - Horde\\Components\\PhpStan\\Rules\\NoDeprecatedHordeUtilRule

services:
    -
        class: Horde\\Components\\PhpStan\\Rules\\NoDirectGlobalAccessRule
        tags:
            - phpstan.rules.rule
    -
        class: Horde\\Components\\PhpStan\\Rules\\RequireImmutableUriUsageRule
        tags:
            - phpstan.rules.rule
    -
        class: Horde\\Components\\PhpStan\\Rules\\NoDeprecatedHordeUtilRule
        tags:
            - phpstan.rules.rule
NEON;

        // Write to temporary file
        $tempFile = $componentPath . '/build/phpstan-temp-' . getmypid() . '.neon';

        // Ensure build directory exists
        $buildDir = $componentPath . '/build';
        if (!is_dir($buildDir)) {
            mkdir($buildDir, 0755, true);
        }

        file_put_contents($tempFile, $config);

        return $tempFile;
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

        // PHPStan's JSON `files` map only lists files that produced errors,
        // not every file scanned. The total scanned count is computed
        // separately via filesystem traversal of the configured paths.
        if (isset($this->nativeResults['files'])) {
            $this->stats['files_with_errors'] = count($this->nativeResults['files']);
        }

        $componentPath = $this->getPath() ?: getcwd();
        if (is_string($componentPath) && $componentPath !== '') {
            $this->stats['files_scanned'] = $this->countPhpFiles(
                $this->resolveAnalysisPaths($componentPath)
            );
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

        if ($this->stats['files_scanned'] > 0) {
            $parts[] = $this->stats['files_scanned']
                . ' file' . ($this->stats['files_scanned'] !== 1 ? 's' : '')
                . ' scanned';
        }

        if ($this->stats['files_with_errors'] > 0) {
            $parts[] = $this->stats['files_with_errors']
                . ' file' . ($this->stats['files_with_errors'] !== 1 ? 's' : '')
                . ' with errors';
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
