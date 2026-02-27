<?php

/**
 * Components_Qc_Task_Unit:: runs the test suite of the component.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Qc\Task;

use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\Skipped;

/**
 * Components_Qc_Task_Unit:: runs the test suite of the component.
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
class Unit extends Base
{
    /**
     * Test statistics collected during test run.
     */
    private array $stats = [
        'tests' => 0,
        'assertions' => 0,
        'failures' => 0,
        'errors' => 0,
        'skipped' => 0,
    ];

    /**
     * Get the name of this task.
     *
     * @return string The task name.
     */
    public function getName(): string
    {
        return 'PHPUnit testsuite';
    }

    /**
     * Validate the preconditions required for this release task.
     *
     * @param array $options Additional options.
     *
     * @return array An empty array if all preconditions are met and a list of
     *               error messages otherwise.
     */
    public function validate(array $options = []): array
    {
        // Try to load PHPUnit if not already available
        $this->loadPhpUnit();

        if (!class_exists('PHPUnit\TextUI\Application')) {
            return ['PHPUnit is not installed!'];
        }
        return [];
    }

    /**
     * Attempt to load PHPUnit from various possible locations.
     *
     * Outputs information about the detected PHPUnit installation.
     *
     * @return void
     */
    private function loadPhpUnit(): void
    {
        // Already loaded via Composer autoloader
        if (class_exists('PHPUnit\TextUI\Application')) {
            $this->detectPhpUnitSource();
            return;
        }

        $componentPath = $this->_config->getPath();

        // Order of preference as requested:
        // 1. vendor/ dir of target module
        // 2. tools/ dir of target module
        // 3. global phive tools dir for current user
        // 4. system search path
        $possibleLocations = [
            // 1. Vendor directory (Composer installed)
            $componentPath . '/vendor/bin/phpunit.phar',
            $componentPath . '/vendor/phpunit/phpunit/phpunit',
            // 2. Tools directory of target module
            $componentPath . '/tools/phpunit.phar',
            $componentPath . '/tools/phpunit',
            // 3. Global Phive tools directory for current user
            $_SERVER['HOME'] . '/.phive/phpunit.phar',
            $_SERVER['HOME'] . '/.phive/phpunit',
            // 4. System search path
            '/usr/local/bin/phpunit.phar',
            '/usr/local/bin/phpunit',
            '/usr/bin/phpunit.phar',
            '/usr/bin/phpunit',
        ];

        foreach ($possibleLocations as $pharPath) {
            if (file_exists($pharPath) && is_readable($pharPath)) {
                // Check if it's actually a PHAR
                if (str_ends_with($pharPath, '.phar') || $this->isPhar($pharPath)) {
                    try {
                        require_once 'phar://' . $pharPath . '/vendor/autoload.php';
                        $version = $this->getPhpUnitVersion();
                        $versionStr = $version ? ' version ' . $version : '';
                        $this->getOutput()->info('Using PHPUnit' . $versionStr . ' from: ' . $pharPath . ' (PHAR)');
                        return;
                    } catch (\Throwable $e) {
                        // Continue trying other locations
                    }
                }
            }
        }
    }

    /**
     * Get PHPUnit version string.
     *
     * @return string The version string or empty if not available.
     */
    private function getPhpUnitVersion(): string
    {
        try {
            if (class_exists('PHPUnit\Runner\Version')) {
                return \PHPUnit\Runner\Version::id();
            }
        } catch (\Throwable $e) {
            // Ignore
        }
        return '';
    }

    /**
     * Detect the source of an already-loaded PHPUnit installation.
     *
     * @return void
     */
    private function detectPhpUnitSource(): void
    {
        $version = $this->getPhpUnitVersion();
        $versionStr = $version ? ' version ' . $version : '';

        try {
            $reflection = new \ReflectionClass('PHPUnit\TextUI\Application');
            $filename = $reflection->getFileName();

            if ($filename === false) {
                $this->getOutput()->info('Using PHPUnit' . $versionStr . ' (native PHP - source unknown)');
                return;
            }

            $componentPath = $this->_config->getPath();

            // Determine which location category this belongs to
            if (str_contains($filename, $componentPath . '/vendor/')) {
                $this->getOutput()->info('Using PHPUnit' . $versionStr . ' from: ' . $componentPath . '/vendor/ (native PHP - Composer)');
            } elseif (str_contains($filename, $componentPath . '/tools/')) {
                $this->getOutput()->info('Using PHPUnit' . $versionStr . ' from: ' . $componentPath . '/tools/ (native PHP)');
            } elseif (str_contains($filename, 'phar://')) {
                // Extract PHAR path
                preg_match('#phar://([^/]+\.phar)#', $filename, $matches);
                $pharPath = $matches[1] ?? 'unknown';

                if (str_contains($pharPath, $_SERVER['HOME'] . '/.phive/')) {
                    $this->getOutput()->info('Using PHPUnit' . $versionStr . ' from: ' . $pharPath . ' (PHAR - Phive)');
                } elseif (str_contains($pharPath, $componentPath . '/vendor/')) {
                    $this->getOutput()->info('Using PHPUnit' . $versionStr . ' from: ' . $pharPath . ' (PHAR - Composer)');
                } elseif (str_contains($pharPath, $componentPath . '/tools/')) {
                    $this->getOutput()->info('Using PHPUnit' . $versionStr . ' from: ' . $pharPath . ' (PHAR - tools/)');
                } else {
                    $this->getOutput()->info('Using PHPUnit' . $versionStr . ' from: ' . $pharPath . ' (PHAR - system)');
                }
            } elseif (str_contains($filename, $_SERVER['HOME'] . '/.phive/')) {
                $this->getOutput()->info('Using PHPUnit' . $versionStr . ' from: ' . dirname($filename) . ' (Phive)');
            } else {
                $this->getOutput()->info('Using PHPUnit' . $versionStr . ' from: ' . dirname($filename) . ' (system)');
            }
        } catch (\ReflectionException $e) {
            $this->getOutput()->info('Using PHPUnit' . $versionStr . ' (source detection failed)');
        }
    }

    /**
     * Check if a file is a PHAR archive.
     *
     * @param string $path Path to the file.
     *
     * @return bool True if the file is a PHAR.
     */
    private function isPhar(string $path): bool
    {
        if (!is_readable($path)) {
            return false;
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return false;
        }

        // PHAR files start with a stub that contains "<?php" and "__HALT_COMPILER();"
        $header = fread($handle, 4096);
        fclose($handle);

        return str_contains($header, '__HALT_COMPILER');
    }

    /**
     * Register event subscribers to collect test statistics.
     *
     * @return void
     */
    private function registerEventSubscribers(): void
    {
        $facade = \PHPUnit\Event\Facade::instance();

        // Subscribe to test finished events to count tests
        $facade->registerSubscriber(
            new class ($this->stats) implements \PHPUnit\Event\Test\FinishedSubscriber {
                public function __construct(private array &$stats) {}

                public function notify(Finished $event): void
                {
                    $this->stats['tests']++;
                }
            }
        );

        // Subscribe to test failed events
        $facade->registerSubscriber(
            new class ($this->stats) implements \PHPUnit\Event\Test\FailedSubscriber {
                public function __construct(private array &$stats) {}

                public function notify(Failed $event): void
                {
                    $this->stats['failures']++;
                }
            }
        );

        // Subscribe to test errored events
        $facade->registerSubscriber(
            new class ($this->stats) implements \PHPUnit\Event\Test\ErroredSubscriber {
                public function __construct(private array &$stats) {}

                public function notify(Errored $event): void
                {
                    $this->stats['errors']++;
                }
            }
        );

        // Subscribe to test skipped events
        $facade->registerSubscriber(
            new class ($this->stats) implements \PHPUnit\Event\Test\SkippedSubscriber {
                public function __construct(private array &$stats) {}

                public function notify(Skipped $event): void
                {
                    $this->stats['skipped']++;
                }
            }
        );
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
        // Ensure PHPUnit is loaded (handles PHAR installations)
        $this->loadPhpUnit();

        $testDir = realpath($this->_config->getPath() . '/test');

        if (!$testDir || !is_dir($testDir)) {
            return 0; // No test directory, no errors
        }

        // Reset statistics
        $this->stats = [
            'tests' => 0,
            'assertions' => 0,
            'failures' => 0,
            'errors' => 0,
            'skipped' => 0,
        ];

        // Register event subscribers to collect statistics
        $this->registerEventSubscribers();

        // Look for phpunit.xml or phpunit.xml.dist in component root
        $componentPath = $this->_config->getPath();
        $configFile = null;

        if (file_exists($componentPath . '/phpunit.xml')) {
            $configFile = $componentPath . '/phpunit.xml';
        } elseif (file_exists($componentPath . '/phpunit.xml.dist')) {
            $configFile = $componentPath . '/phpunit.xml.dist';
        }

        // Build PHPUnit command arguments
        $argv = ['phpunit'];

        if ($configFile) {
            $argv[] = '--configuration';
            $argv[] = $configFile;
        } else {
            // No config file, just run the test directory
            $argv[] = $testDir;
        }

        // Add JSON log output to build/phpunit-results.json
        $jsonLogPath = $componentPath . '/build/phpunit-results.json';
        $buildDir = $componentPath . '/build';

        // Create build directory if it doesn't exist
        if (!is_dir($buildDir)) {
            mkdir($buildDir, 0o755, true);
        }

        $argv[] = '--log-junit';
        $argv[] = $jsonLogPath;

        // Use PHPUnit's Application class for modern in-process execution
        $app = new \PHPUnit\TextUI\Application();

        // Capture output to prevent PHPUnit from writing directly
        ob_start();
        $exitCode = $app->run($argv);
        ob_end_clean();

        // Write our own JSON results file with additional metadata
        $this->writeJsonResults($componentPath, $exitCode);

        // Output statistics
        $this->outputStatistics();

        // PHPUnit exit codes: 0 = success, 1 = test failures, 2 = errors/exceptions
        // Return non-zero if there were failures or errors
        return $exitCode;
    }

    /**
     * Write JSON results file with test statistics and metadata.
     *
     * @param string $componentPath Path to the component.
     * @param int $exitCode The PHPUnit exit code.
     *
     * @return void
     */
    private function writeJsonResults(string $componentPath, int $exitCode): void
    {
        $jsonPath = $componentPath . '/build/phpunit-results-summary.json';

        $results = [
            'timestamp' => date('c'),
            'phpunit_version' => $this->getPhpUnitVersion(),
            'exit_code' => $exitCode,
            'success' => ($exitCode === 0),
            'statistics' => [
                'tests' => $this->stats['tests'],
                'assertions' => $this->stats['assertions'],
                'failures' => $this->stats['failures'],
                'errors' => $this->stats['errors'],
                'skipped' => $this->stats['skipped'],
            ],
        ];

        $json = json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($jsonPath, $json);

        $this->getOutput()->info('JSON results written to: ' . $jsonPath);
    }

    /**
     * Output test statistics.
     *
     * @return void
     */
    private function outputStatistics(): void
    {
        $parts = [];
        $hasIssues = ($this->stats['failures'] > 0 || $this->stats['errors'] > 0);

        if ($this->stats['tests'] > 0) {
            $parts[] = $this->stats['tests'] . ' test' . ($this->stats['tests'] !== 1 ? 's' : '');
        }

        if ($this->stats['failures'] > 0) {
            $parts[] = $this->stats['failures'] . ' failed';
        }

        if ($this->stats['errors'] > 0) {
            $parts[] = $this->stats['errors'] . ' error' . ($this->stats['errors'] !== 1 ? 's' : '');
        }

        if ($this->stats['skipped'] > 0) {
            $parts[] = $this->stats['skipped'] . ' skipped';
        }

        if (!empty($parts)) {
            if ($hasIssues) {
                $message = 'There were issues. Test results: ' . implode(', ', $parts);
                $this->getOutput()->warn($message);
            } else {
                $message = 'No problems found. Test results: ' . implode(', ', $parts) . ' ran OK';
                $this->getOutput()->ok($message);
            }
        }
    }
}
