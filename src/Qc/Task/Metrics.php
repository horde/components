<?php

/**
 * Copyright 2024-2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category  Horde
 * @copyright 2024-2026 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Components
 */

namespace Horde\Components\Qc\Task;

/**
 * Measure code metrics using PHPMetrics.
 *
 * PHPMetrics provides comprehensive code metrics including:
 * - Size metrics (LOC, CLOC, NCLOC, LLOC)
 * - Maintainability Index (0-100 scale)
 * - Cyclomatic Complexity per method/class
 * - Coupling metrics (afferent/efferent coupling)
 * - Cohesion metrics (LCOM)
 * - Halstead complexity metrics
 * - Object-oriented metrics
 *
 * This is a modern replacement for the deprecated PHPLOC task.
 *
 * @author    Ralf Lang <ralf.lang@ralf-lang.de>
 * @category  Horde
 * @copyright 2024-2026 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Components
 */
class Metrics extends Base
{
    /**
     * Get the name of this task.
     *
     * @return string The task name.
     */
    public function getName(): string
    {
        return 'code metrics analysis using PHPMetrics';
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
        // Check for phpmetrics binary
        $phpmetrics = $this->findPhpMetrics();

        if (!$phpmetrics) {
            return [
                'PHPMetrics is not available!',
                'Install with: composer require --dev phpmetrics/phpmetrics',
            ];
        }

        return [];
    }

    /**
     * Run the task.
     *
     * @param array &$options Additional options.
     *
     * @return int Number of errors.
     */
    public function run(array &$options = []): int
    {
        $path = $this->_config->getPath();
        if ($path === null || $path === '') {
            $this->getOutput()->error('Component path not configured');
            return 1;
        }

        $componentDir = realpath($path);
        if ($componentDir === false) {
            $this->getOutput()->error('Component path does not exist: ' . $path);
            return 1;
        }

        $buildDir = $componentDir . DIRECTORY_SEPARATOR . 'build';
        $phpmetrics = $this->findPhpMetrics();

        if (!$phpmetrics) {
            $this->getOutput()->error('PHPMetrics not found');
            return 1;
        }

        // Ensure build directory exists
        if (!is_dir($buildDir)) {
            mkdir($buildDir, 0755, true);
        }

        // Detect source directories
        $srcDirs = $this->detectSourceDirectories($componentDir);
        if (empty($srcDirs)) {
            $this->getOutput()->warn('No source directories found');
            return 1;
        }

        $this->getOutput()->detected("Found PHPMetrics at: {$phpmetrics}");

        // For now, analyze only the first (primary) source directory
        // PHPMetrics v2.x doesn't handle multiple directories well
        $primarySrcDir = $srcDirs[0];
        $this->getOutput()->running("Analyzing code metrics in: " . basename($primarySrcDir) . '/');
        $this->runPhpMetricsAnalysis($phpmetrics, $primarySrcDir, $buildDir);

        // Display summary
        $jsonOutput = $buildDir . '/metrics.json';
        // Display summary
        $jsonOutput = $buildDir . '/metrics.json';
        $htmlOutput = $buildDir . '/metrics';

        if (file_exists($jsonOutput)) {
            $this->displaySummary($jsonOutput);
        }

        $this->getOutput()->ok("HTML report generated: {$htmlOutput}/index.html");
        $this->getOutput()->ok("JSON data saved: {$jsonOutput}");

        return 0;
    }

    /**
     * Run PHPMetrics analysis on a directory.
     *
     * @param string $phpmetrics Path to phpmetrics binary.
     * @param string $targetDir Directory to analyze.
     * @param string $buildDir Build directory for output.
     */
    private function runPhpMetricsAnalysis(
        string $phpmetrics,
        string $targetDir,
        string $buildDir
    ): void {
        $jsonOutput = $buildDir . '/metrics.json';
        $htmlOutput = $buildDir . '/metrics';

        $command = sprintf(
            '%s --report-json=%s --report-html=%s %s',
            escapeshellarg($phpmetrics),
            escapeshellarg($jsonOutput),
            escapeshellarg($htmlOutput),
            escapeshellarg($targetDir)
        );

        $this->system($command);
    }

    /**
     * Find PHPMetrics binary.
     *
     * @return string|null Path to phpmetrics or null if not found.
     */
    private function findPhpMetrics(): ?string
    {
        $path = $this->_config->getPath();
        $componentDir = ($path !== null && $path !== '') ? realpath($path) : false;

        // Search order: local vendor, global composer, system paths, PATH
        $possibleLocations = [
            // 1. Local vendor (project-specific)
            $componentDir ? $componentDir . '/vendor/bin/phpmetrics' : null,
            // 2. Horde monorepo vendor
            $componentDir ? $componentDir . '/../../../vendor/bin/phpmetrics' : null,
            // 3. Global Composer (Linux/macOS)
            getenv('HOME') . '/.composer/vendor/bin/phpmetrics',
            getenv('HOME') . '/.config/composer/vendor/bin/phpmetrics',
            // 4. Global Composer (Windows)
            getenv('APPDATA') . '/Composer/vendor/bin/phpmetrics',
            getenv('APPDATA') . '/Composer/vendor/bin/phpmetrics.bat',
            // 5. System installations
            '/usr/local/bin/phpmetrics',
            '/usr/bin/phpmetrics',
        ];

        foreach ($possibleLocations as $path) {
            if ($path && file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        // Try PATH as fallback
        $result = shell_exec('which phpmetrics 2>/dev/null');
        if ($result && trim($result)) {
            return trim($result);
        }

        return null;
    }

    /**
     * Detect source directories to analyze.
     *
     * @param string $componentDir Component root directory.
     *
     * @return array Array of source directory paths.
     */
    private function detectSourceDirectories(string $componentDir): array
    {
        $candidates = ['src', 'lib', 'app'];
        $dirs = [];

        foreach ($candidates as $candidate) {
            $path = $componentDir . DIRECTORY_SEPARATOR . $candidate;
            if (is_dir($path)) {
                $dirs[] = $path;
            }
        }

        return $dirs;
    }

    /**
     * Display metrics summary from JSON output.
     *
     * @param string $jsonFile Path to JSON metrics file.
     */
    private function displaySummary(string $jsonFile): void
    {
        $data = json_decode(file_get_contents($jsonFile), true);
        if (!$data) {
            return;
        }

        $this->getOutput()->plain('');
        $this->getOutput()->bold('=== Code Metrics Summary ===');
        $this->getOutput()->plain('');

        // Extract summary metrics
        if (isset($data['files'])) {
            $totalFiles = count($data['files']);
            $totalLoc = 0;
            $totalCloc = 0;
            $totalComplexity = 0;
            $miScores = [];
            $violations = 0;

            foreach ($data['files'] as $file) {
                $totalLoc += $file['loc'] ?? 0;
                $totalCloc += $file['cloc'] ?? 0;
                $totalComplexity += $file['ccn'] ?? 0;

                if (isset($file['mi'])) {
                    $miScores[] = $file['mi'];
                }

                // Count violations (high complexity, low MI)
                if (($file['ccn'] ?? 0) > 10 || ($file['mi'] ?? 100) < 50) {
                    $violations++;
                }
            }

            $avgMi = !empty($miScores) ? array_sum($miScores) / count($miScores) : 0;
            $ncloc = $totalLoc - $totalCloc;

            printf("Files:                           %10d\n", $totalFiles);
            printf("Lines of Code (LOC):             %10d\n", $totalLoc);
            printf("Comment Lines (CLOC):            %10d (%.1f%%)\n", $totalCloc, $totalLoc > 0 ? ($totalCloc / $totalLoc) * 100 : 0);
            printf("Non-Comment Lines (NCLOC):       %10d (%.1f%%)\n", $ncloc, $totalLoc > 0 ? ($ncloc / $totalLoc) * 100 : 0);
            printf("Cyclomatic Complexity:           %10d\n", $totalComplexity);
            printf("Average Maintainability Index:   %10.1f ", $avgMi);

            // Color-code MI score
            if ($avgMi >= 85) {
                $this->getOutput()->green('(Excellent)');
            } elseif ($avgMi >= 70) {
                $this->getOutput()->ok('(Good)');
            } elseif ($avgMi >= 50) {
                $this->getOutput()->warn('(Fair)');
            } else {
                $this->getOutput()->error('(Poor)');
            }

            if ($violations > 0) {
                $this->getOutput()->plain('');
                $this->getOutput()->warn("Quality Issues: {$violations} file(s) with high complexity or low maintainability");
            }
        }

        $this->getOutput()->plain('');
    }
}
