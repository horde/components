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

use Horde\Components\Ci\GitHubAnnotations;
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
     * True when the run was scheduled in advisory mode - the lane will
     * not fail on PHPStan findings. Set by {@see resolveAnalysisPlan()}
     * for legacy-only components (no src/, has lib/).
     */
    private bool $advisory = false;

    /**
     * Findings at watermark+1 (or the lowest failing level above the
     * post-raise watermark) when watermark itself passes. Surfaced in
     * the PR comment as a "budget" for the next promotion. Null when
     *
     * - watermark fails (no peek attempted),
     * - watermark is already at the maximum supported PHPStan level so
     *   no level+1 exists,
     * - or the discovery loop reaches the maximum level without any
     *   failure (perfect run).
     *
     * Shape mirrors the testLevel() return value: ['level', 'errors',
     * 'files_with_errors', 'passing'].
     *
     * @var array{level: int, errors: int, files_with_errors: int, passing: bool}|null
     */
    private ?array $advisoryNextLevel = null;

    /**
     * Maximum PHPStan level the discovery loop will probe. PHPStan
     * itself caps at 9; level 10 does not exist. Exposed as a constant
     * so the watermark+1 advisory step can avoid out-of-range probes.
     */
    private const MAX_PHPSTAN_LEVEL = 9;

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

            // Stash the advisory flag from the analysis plan so the
            // write-results path can mark this lane's findings as
            // non-fatal. Legacy-only layouts (no src/, has lib/) get
            // PHPStan run but their lane does not fail on findings.
            $plan = $this->resolveAnalysisPlan($componentPath);
            $this->advisory = $plan['advisory'];
            if ($this->advisory) {
                $this->getOutput()->info(
                    'PHPStan running in advisory mode (legacy-only layout: '
                    . 'lib/ analyzed, findings will not fail the lane).'
                );
            }

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
            // Reset advisory state: it's only populated when watermark
            // passes AND a higher level still has findings. The two
            // null-leaving cases (watermark fails, watermark already at
            // max) just leave this null.
            $this->advisoryNextLevel = null;

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

                    // Annotate each finding on the PR diff for GitHub Actions.
                    $this->emitPhpStanAnnotations(
                        $watermarkResult['results'] ?? null,
                        $componentPath,
                        $watermark
                    );
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
                $this->writeJsonResults($componentPath, $watermarkResult['exit_code'], $watermark, $options);
                $this->outputStatistics();

                // Advisory mode: lane keeps walking. Findings appear in
                // the PR comment, but PHPStan does not vote on whether
                // the lane passed. Modernised components stay on the
                // watermark-MUST-pass contract.
                if ($this->advisory) {
                    return 0;
                }
                return max(1, $watermarkResult['errors']); // Non-zero = failure
            }

            // Watermark passed - discover highest passing level
            $this->getOutput()->running('Discovering highest passing level...');

            $highestPassing = $watermark;
            $testLevel = $watermark + 1;

            while ($testLevel <= self::MAX_PHPSTAN_LEVEL) {
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
                    // Watermark+1 advisory: capture the first failing
                    // level above watermark. The maintainer sees this
                    // as "you'd pass level N if you fixed M things" in
                    // the PR comment, without it failing the lane.
                    // Whether `testLevel` ends up being watermark+1 or
                    // (post-raise) highestPassing+1 depends on how far
                    // the loop walked before failing; either way it's
                    // the right "next budget" for promotion.
                    $this->advisoryNextLevel = [
                        'level' => $testLevel,
                        'errors' => $result['errors'],
                        'files_with_errors' => $result['files_with_errors'] ?? 0,
                        'passing' => false,
                    ];
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
            } elseif ($highestPassing === self::MAX_PHPSTAN_LEVEL) {
                // Already at maximum level - no advisory possible since
                // PHPStan tops out here. The discovery loop may have
                // walked up to here without ever failing, in which case
                // $this->advisoryNextLevel is null (no level+1 to peek
                // at). If the loop never ran (watermark started at
                // MAX), same outcome.
                $this->getOutput()->ok('✓ Code passes watermark level ' . $watermark . ' (maximum)');
                $this->nativeResults = $watermarkResult['results'];
                $this->level = $watermark;
            } else {
                // At watermark, cannot raise yet. The discovery loop's
                // first iteration was watermark+1 and it failed, so
                // $this->advisoryNextLevel already holds the right
                // failing-level data - no need to re-run testLevel.
                $this->getOutput()->ok(
                    '✓ Code passes watermark level ' . $watermark
                );
                if ($this->advisoryNextLevel !== null) {
                    $this->getOutput()->info(sprintf(
                        'Next level (%d) has %d error%s remaining',
                        $this->advisoryNextLevel['level'],
                        $this->advisoryNextLevel['errors'],
                        $this->advisoryNextLevel['errors'] !== 1 ? 's' : '',
                    ));
                }

                // Use watermark results for output
                $this->nativeResults = $watermarkResult['results'];
                $this->level = $watermark;
            }

            // Parse and output results
            if ($this->nativeResults !== null) {
                $this->parseResults();
                $this->writeJsonResults($componentPath, 0, $this->level, $options);
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
        // Conflating the two - old contract was `passed = (exit==0 && errors==0)`
        // - caused tooling failures (e.g. autoload-file unreachable in phar
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
     * Resolve which directories PHPStan should analyze, which it should
     * load for symbols only, and whether its findings should be
     * advisory (non-fatal) for the lane.
     *
     * Decision matrix:
     *
     *   | has src/ | has lib/ | paths:                        | scanDirectories: | advisory |
     *   |----------|----------|-------------------------------|------------------|----------|
     *   | yes      | yes      | src/ (+ migration/)           | lib/             | no       |
     *   | yes      | no       | src/ (+ migration/)           | -                | no       |
     *   | no       | yes      | lib/ (+ migration/)           | -                | **yes**  |
     *   | no       | no       | <componentPath> (+ migration/) | -                | no       |
     *
     * The legacy-only row (no src/, has lib/) runs PHPStan against
     * `lib/` so the report still gets generated, but the resulting
     * findings are marked `mode: advisory` in `phpstan-results.json`
     * and `success: true` so the lane does not fail because of them.
     * Modernised components are held to the watermark; legacy-only
     * components advertise their state without blocking CI.
     *
     * `migration/` joins `paths:` independently whenever present.
     *
     * @param string $componentPath Path to component directory.
     * @return array{paths: list<string>, scanDirectories: list<string>, advisory: bool}
     */
    private function resolveAnalysisPlan(string $componentPath): array
    {
        $srcDir = $componentPath . '/src';
        $libDir = $componentPath . '/lib';
        $migrationDir = $componentPath . '/migration';

        $hasSrc = is_dir($srcDir);
        $hasLib = is_dir($libDir);
        $hasMigration = is_dir($migrationDir);

        $paths = [];
        $scan = [];
        $advisory = false;

        if ($hasSrc) {
            $paths[] = $srcDir;
            if ($hasLib) {
                // src/ is the analyzed surface; lib/ exists only so
                // symbols resolve. Putting lib/ in paths: would drown
                // the report in unfixed-legacy findings the maintainer
                // hasn't volunteered to address yet.
                $scan[] = $libDir;
            }
        } elseif ($hasLib) {
            // Legacy-only component: analyze lib/ but mark the run as
            // advisory so the lane does not fail on findings the
            // maintainer hasn't agreed to fix yet.
            $paths[] = $libDir;
            $advisory = true;
        }

        if ($hasMigration) {
            $paths[] = $migrationDir;
        }

        if ($paths === []) {
            // No conventional layout at all. Hand componentPath to
            // PHPStan so it has something to analyze and the run does
            // not silently no-op.
            $paths[] = $componentPath;
        }

        return [
            'paths' => $paths,
            'scanDirectories' => $scan,
            'advisory' => $advisory,
        ];
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
        $plan = $this->resolveAnalysisPlan($componentPath);

        $paths = array_map(
            static fn (string $p): string => "        - " . $p,
            $plan['paths']
        );
        $pathsYaml = implode("\n", $paths);

        // scanDirectories: PHPStan loads symbols from these for context
        // but never emits findings against them. Used to keep legacy
        // lib/ classes resolvable when src/ is the analyzed surface.
        $scanYaml = '';
        if (!empty($plan['scanDirectories'])) {
            $scanLines = array_map(
                static fn (string $p): string => "        - " . $p,
                $plan['scanDirectories']
            );
            $scanYaml = "    scanDirectories:\n" . implode("\n", $scanLines) . "\n";
        }

        // Check for PHPUnit extension
        $includesSection = '';
        if (file_exists($componentPath . '/vendor/phpstan/phpstan-phpunit/extension.neon')) {
            $includesSection = "includes:\n    - {$componentPath}/vendor/phpstan/phpstan-phpunit/extension.neon\n\n";
        }

        // Custom Horde rules. Most rules apply at every level; a few
        // architectural rules only fire once a project reaches a given
        // level of PHPStan hygiene, so that legacy libraries can pass
        // low-level scans while still tightening as their watermark climbs.
        //
        // Level thresholds:
        //   - NoDirectGlobalAccessRule (registry/injector/conf/notification/
        //     session): level 3+. These globals are ubiquitous in legacy
        //     Horde code and eliminating them is a real refactor. Enforce
        //     them once a package is otherwise clean at level 2.
        //   - RequireImmutableUriUsageRule, NoDeprecatedHordeUtilRule:
        //     every level.
        $customRules = [
            [
                'class' => 'Horde\\Components\\PhpStan\\Rules\\NoDeprecatedHordeUtilRule',
                'minLevel' => 0,
            ],
            [
                'class' => 'Horde\\Components\\PhpStan\\Rules\\RequireImmutableUriUsageRule',
                'minLevel' => 0,
            ],
            [
                'class' => 'Horde\\Components\\PhpStan\\Rules\\NoDirectGlobalAccessRule',
                'minLevel' => 3,
            ],
        ];

        $activeRules = array_filter(
            $customRules,
            static fn (array $rule): bool => $level >= $rule['minLevel']
        );

        $rulesYaml = '';
        $servicesYaml = '';
        if (!empty($activeRules)) {
            $ruleLines = array_map(
                static fn (array $rule): string => '    - ' . $rule['class'],
                $activeRules
            );
            $rulesYaml = "\nrules:\n" . implode("\n", $ruleLines) . "\n";

            $serviceLines = array_map(
                static fn (array $rule): string => "    -\n"
                    . "        class: " . $rule['class'] . "\n"
                    . "        tags:\n"
                    . "            - phpstan.rules.rule",
                $activeRules
            );
            $servicesYaml = "\nservices:\n" . implode("\n", $serviceLines) . "\n";
        }

        // Note: Custom Horde rules are loaded via --autoload-file CLI parameter
        // See testLevel() method where phpstan-bootstrap.php is passed
        $config = <<<NEON
{$includesSection}parameters:
    level: $level
    paths:
$pathsYaml
{$scanYaml}    excludePaths:
        - vendor (?)
        - build (?)
    tmpDir: build/phpstan
    bootstrapFiles:
        - {$componentPath}/vendor/autoload.php
{$rulesYaml}{$servicesYaml}
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
            // files_scanned reflects what PHPStan actually analyzed  - 
            // i.e. the `paths:` set, not `scanDirectories:` (which are
            // loaded for symbols only).
            $plan = $this->resolveAnalysisPlan($componentPath);
            $this->stats['files_scanned'] = $this->countPhpFiles($plan['paths']);
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
    private function writeJsonResults(string $componentPath, int $exitCode, int $level, array $options = []): void
    {
        $buildDir = $componentPath . '/build';

        // Create build directory if it does not exist
        if (!is_dir($buildDir)) {
            mkdir($buildDir, 0o755, true);
        }

        // Write native PHPStan JSON only when the caller explicitly asked
        // for it. The CI lane runner sets --dump-native; local invocations
        // get only the summary so a developer's working tree stays tidy.
        $nativeJsonPath = $buildDir . '/phpstan-native.json';
        if (!empty($options['dump_native']) && $this->nativeResults !== null) {
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
            // Advisory mode: the source of truth is "PHPStan ran, here
            // are findings, but they don't fail the lane." We mark
            // success: true regardless of exit code so downstream
            // accounting (ResultCollector::isLanePassed) treats the
            // lane as green; the `mode` field signals the rendering
            // path to label these findings as advisory.
            'success' => $this->advisory ? true : ($exitCode === 0),
            'mode' => $this->advisory ? 'advisory' : 'enforced',
            'statistics' => $this->stats,
            // Watermark+1 advisory: surfaces the smallest failing level
            // above the post-discovery watermark, so the PR comment can
            // show "you'd pass level N if you fixed M things." Null
            // when watermark fails (no peek attempted), when the
            // discovery loop walks to MAX without a failure, or when
            // the maintainer is already at MAX. The PR comment renderer
            // skips the suffix entirely when this is null.
            'advisory_next_level' => $this->advisoryNextLevel,
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

    /**
     * Emit one GitHub Actions `::error` annotation per PHPStan finding.
     *
     * The native PHPStan JSON has shape:
     *   { files: { "<absolute path>": { errors: int, messages: [
     *       { message, line, identifier, ignorable }, ... ] } } }
     *
     * Paths are absolute on the running system (e.g. inside a CI lane:
     * /tmp/horde-ci/lanes/php8.3-dev/Victim/src/Foo.php). For the runner
     * to anchor an annotation to a diff line, the path must be relative
     * to $GITHUB_WORKSPACE. We strip the component root prefix to get
     * the repo-relative path the diff uses.
     *
     * No-op outside GitHub Actions.
     *
     * @param array<string,mixed>|null $native The decoded PHPStan JSON.
     * @param string $componentPath Component root directory.
     * @param int $level Watermark level (used for the annotation title).
     */
    private function emitPhpStanAnnotations(?array $native, string $componentPath, int $level): void
    {
        if (!GitHubAnnotations::isActive()) {
            return;
        }
        if (!is_array($native) || !isset($native['files']) || !is_array($native['files'])) {
            return;
        }

        foreach ($native['files'] as $absolutePath => $fileEntry) {
            if (!is_string($absolutePath) || !is_array($fileEntry)) {
                continue;
            }
            $relPath = GitHubAnnotations::relativizeForAnnotation($absolutePath, $componentPath);
            $messages = $fileEntry['messages'] ?? [];
            if (!is_array($messages)) {
                continue;
            }
            foreach ($messages as $msg) {
                if (!is_array($msg)) {
                    continue;
                }
                $text = (string) ($msg['message'] ?? '');
                $line = isset($msg['line']) && is_int($msg['line']) && $msg['line'] > 0
                    ? $msg['line']
                    : null;
                $identifier = isset($msg['identifier']) && is_string($msg['identifier'])
                    ? ' [' . $msg['identifier'] . ']'
                    : '';
                GitHubAnnotations::error(
                    $text . $identifier,
                    $relPath,
                    $line,
                    'PHPStan level ' . $level
                );
            }
        }
    }
}
