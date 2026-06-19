<?php

/**
 * Components_Qc_Task_Unit:: runs the test suite of the component.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Qc\Task;

use Horde\Components\Qc\ToolFinder;
use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\Skipped;
use ReflectionClass;
use ReflectionException;
use Throwable;

/**
 * Components_Qc_Task_Unit:: runs the test suite of the component.
 *
 * Copyright 2011-2026 Horde LLC (http://www.horde.org/)
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
     * Track whether PHPUnit source has been reported.
     */
    private bool $sourceReported = false;

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
        $this->loadPhpUnit($options['tools_dir'] ?? null);

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
     * @param string|null $toolsDir Optional tools directory to check first
     * @return void
     */
    private function loadPhpUnit(?string $toolsDir = null): void
    {
        // Already loaded via Composer autoloader
        if (class_exists('PHPUnit\TextUI\Application')) {
            if (!$this->sourceReported) {
                $this->detectPhpUnitSource();
                $this->sourceReported = true;
            }
            return;
        }

        $componentPath = $this->getPath();

        // Get the actual component path (handles null/empty)
        if (empty($componentPath)) {
            $componentPath = getcwd() ?: '.';
        }

        // Use ToolFinder to locate PHPUnit
        $toolFinder = new ToolFinder($componentPath, $toolsDir);
        $phpunitPath = $toolFinder->findBinary('phpunit');

        if ($phpunitPath === null) {
            // PHPUnit not found - will fail later during run()
            return;
        }

        // Try to load PHPUnit
        if ($toolFinder->loadTool($phpunitPath)) {
            // Check if PHPUnit classes are now available
            if (class_exists('PHPUnit\TextUI\Application')) {
                if (!$this->sourceReported) {
                    $version = $this->getPhpUnitVersion();
                    $versionStr = $version ? ' version ' . $version : '';
                    $type = $toolFinder->isPhar($phpunitPath) ? 'PHAR' : 'Composer';
                    $this->getOutput()->info('Using PHPUnit' . $versionStr . ' from: ' . $phpunitPath . ' (' . $type . ')');
                    $this->sourceReported = true;
                }
                return;
            }
        }

        // If we get here, loading failed - will error during run()
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
        } catch (Throwable $e) {
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
            $reflection = new ReflectionClass('PHPUnit\TextUI\Application');
            $filename = $reflection->getFileName();

            if ($filename === false) {
                $this->getOutput()->info('Using PHPUnit' . $versionStr . ' (native PHP - source unknown)');
                return;
            }

            $componentPath = $this->getPath();

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
        } catch (ReflectionException $e) {
            $this->getOutput()->info('Using PHPUnit' . $versionStr . ' (source detection failed)');
        }
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
        $this->loadPhpUnit($options['tools_dir'] ?? null);

        $componentPath = $this->getPath();
        if (empty($componentPath)) {
            $componentPath = getcwd() ?: '.';
        }

        $testDir = realpath($componentPath . '/test');

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

        // Path for PHPUnit's JUnit XML log. The file is read back below
        // to recover the assertions count, which is not surfaced through
        // the event subscriber API.
        $junitXmlPath = $componentPath . '/build/phpunit-results.xml';
        $buildDir = $componentPath . '/build';

        // Create build directory if it doesn't exist
        if (!is_dir($buildDir)) {
            mkdir($buildDir, 0o755, true);
        }

        $argv[] = '--no-output';
        $argv[] = '--log-junit';
        $argv[] = $junitXmlPath;

        // Use PHPUnit's Application class for modern in-process execution
        $app = new \PHPUnit\TextUI\Application();

        // Capture output to prevent PHPUnit from writing directly
        ob_start();
        $exitCode = $app->run($argv);
        ob_end_clean();

        // Recover assertions from the JUnit XML log (the event subscriber
        // API does not emit per-assertion events; the count lives in the
        // XML's top-level testsuites attribute).
        $this->stats['assertions'] = self::parseAssertionsFromJunit($junitXmlPath);

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
                $message = 'Test results: ' . implode(', ', $parts) . ' ran OK';
                $this->getOutput()->ok($message);
            }
        }
    }

    /**
     * Recover the assertion count from a PHPUnit JUnit XML log.
     *
     * PHPUnit's event-subscriber API does not emit per-assertion events,
     * so the per-test counter we keep in {@see registerEventSubscribers}
     * stays at the count of tests, not assertions. The JUnit XML log
     * carries `assertions` as an attribute on the root `<testsuites>`
     * element (and on each nested `<testsuite>`). Reading it back is the
     * supported, version-stable way to surface the count.
     *
     * @param string $junitXmlPath Path to the JUnit XML file.
     * @return int Assertion count, or 0 when the file is missing/unparseable.
     */
    public static function parseAssertionsFromJunit(string $junitXmlPath): int
    {
        if (!is_file($junitXmlPath) || !is_readable($junitXmlPath)) {
            return 0;
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_file($junitXmlPath);
        } finally {
            libxml_use_internal_errors($previous);
        }
        if ($xml === false) {
            return 0;
        }
        // Root <testsuites> usually carries the aggregated count.
        $assertions = isset($xml['assertions']) ? (int) $xml['assertions'] : 0;
        if ($assertions > 0) {
            return $assertions;
        }
        // Fallback for variants that emit a single <testsuite> root or
        // omit the aggregated attribute: sum the per-suite counts.
        $suites = $xml->getName() === 'testsuites'
            ? $xml->children()
            : [$xml];
        foreach ($suites as $suite) {
            if (isset($suite['assertions'])) {
                $assertions += (int) $suite['assertions'];
            }
        }
        return $assertions;
    }
}
