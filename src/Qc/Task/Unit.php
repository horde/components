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
use PHPUnit\Event\Code\Test as PhpUnitTest;
use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Code\Throwable as PhpUnitThrowable;
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
     * Per-failure detail captured from PHPUnit's Failed event.
     *
     * @var list<array{type:string,test_class:string,test_method:string,file:string,line:int,exception_class:string,message:string,trace:string}>
     */
    private array $failureRecords = [];

    /**
     * Per-error detail captured from PHPUnit's Errored event.
     *
     * @var list<array{type:string,test_class:string,test_method:string,file:string,line:int,exception_class:string,message:string,trace:string}>
     */
    private array $errorRecords = [];

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

        $phpunitPath = $this->resolvePhpUnitPath($toolsDir);
        if ($phpunitPath === null) {
            // PHPUnit not found - will fail later during run()
            return;
        }

        // Rebuild ToolFinder to reuse its loader; resolvePhpUnitPath
        // already located the same phar via the same finder, so the
        // second instantiation is cheap and keeps the discovery and
        // loading code paths symmetric.
        $componentPath = $this->getPath() ?: (getcwd() ?: '.');
        $toolFinder = new ToolFinder($componentPath, $toolsDir);
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
     * Locate the PHPUnit phar (or Composer-installed binary) without
     * loading it. Shared by the in-process loadPhpUnit() and the
     * subprocess runPhpUnitSubprocess() paths so both agree on which
     * phar is authoritative.
     *
     * @param string|null $toolsDir Optional tools directory to check first
     * @param string|null $lanePhpVersion Optional PHP version (e.g. "8.2")
     *                    of the interpreter that will actually execute the
     *                    phar. When set, {@see ToolFinder::resolvePhpUnitTag()}
     *                    picks the highest PHPUnit tag compatible with that
     *                    lane rather than the interpreter running horde-
     *                    components itself. Callers on the subprocess path
     *                    should pass this; in-process callers can omit it.
     * @return string|null Absolute path to the PHPUnit binary, or null
     *                    if none was found.
     */
    private function resolvePhpUnitPath(
        ?string $toolsDir,
        ?string $lanePhpVersion = null,
    ): ?string {
        $componentPath = $this->getPath() ?: (getcwd() ?: '.');
        $toolFinder = new ToolFinder($componentPath, $toolsDir, $lanePhpVersion);
        return $toolFinder->findBinary('phpunit');
    }

    /**
     * Run PHPUnit as a subprocess under an explicit PHP binary.
     *
     * The subprocess path is engaged when the caller passes `--php`
     * (typically the CI lane script). Its purpose is to decouple the
     * PHP running horde-components itself from the PHP under which the
     * component's tests must be exercised - the lane PHP may be older
     * than horde-components' own composer platform requirement.
     *
     * Contract with the in-process path:
     *  - Junit XML written to the same $componentPath/build/phpunit-results.xml
     *    via the `--log-junit` flag already present in $argv, so the
     *    downstream {@see self::parseAssertionsFromJunit()} continues
     *    to work unchanged.
     *  - Exit code is PHPUnit's own (0 success / 1 test failures /
     *    2 errors), returned by-reference from exec().
     *  - Output is captured and discarded so callers see the same
     *    "no chatter" behavior as the in-process ob_start/ob_end_clean.
     *
     * @param string      $php           Absolute path to a PHP binary.
     * @param array       $argv          Fully-assembled PHPUnit argv,
     *                                   with 'phpunit' as element 0
     *                                   (dropped before exec - the phar
     *                                   path becomes argv[0] instead).
     * @param string      $componentPath Component directory; the
     *                                   subprocess `cd`s here so
     *                                   config file lookups resolve.
     * @param string|null $toolsDir      Optional --tools-dir; forwarded
     *                                   to {@see self::resolvePhpUnitPath()}
     *                                   so we locate the same phar the
     *                                   in-process path would have.
     *
     * @return int PHPUnit-style exit code, or 2 on setup failure.
     */
    private function runPhpUnitSubprocess(
        string $php,
        array $argv,
        string $componentPath,
        ?string $toolsDir,
    ): int {
        // Probe the lane PHP's version so ToolFinder can pick a PHPUnit
        // tag that actually runs under it. Without this, resolvePhpUnitTag
        // would fall back to PHP_MAJOR_VERSION.PHP_MINOR_VERSION - the
        // tool PHP - and select a PHPUnit newer than the lane PHP can
        // execute (e.g. PHPUnit 12.5 chosen for a PHP 8.1 lane).
        $lanePhpVersion = $this->probePhpVersion($php);
        $phpunitPath = $this->resolvePhpUnitPath($toolsDir, $lanePhpVersion);
        if ($phpunitPath === null) {
            $this->getOutput()->error(
                'PHPUnit binary not found for subprocess execution'
            );
            return 2;
        }
        if (!is_file($php) || !is_executable($php)) {
            $this->getOutput()->error("PHP binary not executable: {$php}");
            return 2;
        }

        // argv[0] is the phpunit command name in the in-process world;
        // drop it - the real argv[0] is the phar path itself.
        $args = array_slice($argv, 1);
        $parts = [escapeshellarg($php), escapeshellarg($phpunitPath)];
        foreach ($args as $arg) {
            $parts[] = escapeshellarg((string) $arg);
        }
        $command = 'cd ' . escapeshellarg($componentPath) . ' && '
                 . implode(' ', $parts);

        $out = [];
        $exitCode = 0;
        ob_start();
        exec($command . ' 2>&1', $out, $exitCode);
        ob_end_clean();

        return $exitCode;
    }

    /**
     * Probe a PHP binary for its major.minor version.
     *
     * Parses the first line of `php -v` output, which begins with
     * "PHP X.Y..." on every mainstream build. Returns null when the
     * probe fails so callers can fall back cleanly rather than pinning
     * on a wrong version.
     *
     * Local copy of the same regex used by {@see \Horde\Components\Ci\Setup\PhpInstaller::probeVersion()};
     * that helper is protected and not reachable without inheritance,
     * and Task\Unit has no injected PhpInstaller. A shared utility
     * belongs in a later refactor once more sites need it.
     *
     * @param string $php Absolute path to a PHP binary.
     * @return string|null "<major>.<minor>" (e.g. "8.1"), or null.
     */
    private function probePhpVersion(string $php): ?string
    {
        if (!is_file($php) || !is_executable($php)) {
            return null;
        }
        $out = @shell_exec(escapeshellarg($php) . ' -v 2>/dev/null');
        if (!is_string($out) || !preg_match('/^PHP\s+(\d+\.\d+)/', $out, $m)) {
            return null;
        }
        return $m[1];
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
        $task = $this; // captured by the closures below

        // Subscribe to test finished events to count tests
        $facade->registerSubscriber(
            new class ($task) implements \PHPUnit\Event\Test\FinishedSubscriber {
                public function __construct(private Unit $task) {}

                public function notify(Finished $event): void
                {
                    $this->task->onTestFinished($event);
                }
            }
        );

        // Subscribe to test failed events: count and capture detail.
        $facade->registerSubscriber(
            new class ($task) implements \PHPUnit\Event\Test\FailedSubscriber {
                public function __construct(private Unit $task) {}

                public function notify(Failed $event): void
                {
                    $this->task->onTestFailed($event);
                }
            }
        );

        // Subscribe to test errored events: count and capture detail.
        $facade->registerSubscriber(
            new class ($task) implements \PHPUnit\Event\Test\ErroredSubscriber {
                public function __construct(private Unit $task) {}

                public function notify(Errored $event): void
                {
                    $this->task->onTestErrored($event);
                }
            }
        );

        // Subscribe to test skipped events
        $facade->registerSubscriber(
            new class ($task) implements \PHPUnit\Event\Test\SkippedSubscriber {
                public function __construct(private Unit $task) {}

                public function notify(Skipped $event): void
                {
                    $this->task->onTestSkipped($event);
                }
            }
        );
    }

    /**
     * Tally a finished test.
     *
     * @internal Only PHPUnit event subscribers should call this.
     */
    public function onTestFinished(Finished $event): void
    {
        $this->stats['tests']++;
    }

    /**
     * Tally a failed test and capture its detail.
     *
     * The detail (test name, throwable info, source location) goes into
     * `phpunit-results-summary.json` so the PR comment and Step Summary
     * can name which tests failed instead of only showing aggregate
     * counts. Truncation happens at render time, not here - the JSON
     * carries the authoritative data.
     *
     * @internal Only PHPUnit event subscribers should call this.
     */
    public function onTestFailed(Failed $event): void
    {
        $this->stats['failures']++;
        $this->failureRecords[] = $this->captureRecord('failure', $event->test(), $event->throwable());
    }

    /**
     * Tally an errored test and capture its detail.
     *
     * @internal Only PHPUnit event subscribers should call this.
     */
    public function onTestErrored(Errored $event): void
    {
        $this->stats['errors']++;
        $this->errorRecords[] = $this->captureRecord('error', $event->test(), $event->throwable());
    }

    /**
     * Tally a skipped test.
     *
     * @internal Only PHPUnit event subscribers should call this.
     */
    public function onTestSkipped(Skipped $event): void
    {
        $this->stats['skipped']++;
    }

    /**
     * Build one record describing a failed or errored test.
     *
     * Best-effort accessors: PHPUnit's `Test` parent class only guarantees
     * `name()`; `TestMethod` adds `className()`, `methodName()`, `file()`,
     * `line()`. We default missing fields to empty rather than throwing -
     * a degraded record is more useful than a broken Unit task.
     *
     * @return array{type:string,test_class:string,test_method:string,file:string,line:int,exception_class:string,message:string,trace:string}
     */
    private function captureRecord(string $type, PhpUnitTest $test, PhpUnitThrowable $throwable): array
    {
        if ($test instanceof TestMethod) {
            $class = $test->className();
            $method = $test->methodName();
            $file = $test->file();
            // TestMethod::line() exists; some PHPUnit edges return 0
            // when the test is generated from a data provider without
            // a concrete location. Coerce to int defensively.
            $line = (int) $test->line();
        } else {
            // Fallback: split "ClassName::methodName" out of name().
            // Some PHPUnit edge cases (test-double tests, etc.) emit
            // non-TestMethod Test values. Better a degraded record
            // than no record.
            $name = $test->name();
            $sep = strrpos($name, '::');
            $class = $sep !== false ? substr($name, 0, $sep) : $name;
            $method = $sep !== false ? substr($name, $sep + 2) : '';
            $file = '';
            $line = 0;
        }

        return [
            'type' => $type,
            'test_class' => $class,
            'test_method' => $method,
            'file' => $file,
            'line' => $line,
            'exception_class' => $throwable->className(),
            'message' => $throwable->message(),
            'trace' => $throwable->stackTrace(),
        ];
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
        // Ensure PHPUnit is loaded (handles PHAR installations). Skip
        // the in-process load when --php was set: the subprocess path
        // below invokes the phar directly, so we do not need PHPUnit
        // classes in this process.
        $php = $options['php'] ?? null;
        $useSubprocess = ($php !== null && $php !== '');
        if (!$useSubprocess) {
            $this->loadPhpUnit($options['tools_dir'] ?? null);
        }

        $componentPath = $this->getPath();
        if (empty($componentPath)) {
            $componentPath = getcwd() ?: '.';
        }

        $testDir = realpath($componentPath . '/test');

        if (!$testDir || !is_dir($testDir)) {
            // Distinguish "this library deliberately has
            // no PHPUnit tests" from "PHPUnit crashed before producing
            // results". The former is fine - many Horde data packages
            // ship no tests; running the lane should not surface as a failure.
            // Write a summary marking the run with mode: 'no_test_suite'
            // so PrCommentReporter renders a skip indicator rather than the missing/
            // failed shapes.
            $this->writeNoTestSuiteResults($componentPath);
            return 0;
        }

        // Reset statistics and failure capture for this run.
        $this->stats = [
            'tests' => 0,
            'assertions' => 0,
            'failures' => 0,
            'errors' => 0,
            'skipped' => 0,
        ];
        $this->failureRecords = [];
        $this->errorRecords = [];

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

        if ($useSubprocess) {
            $exitCode = $this->runPhpUnitSubprocess(
                (string) $php,
                $argv,
                $componentPath,
                $options['tools_dir'] ?? null,
            );
        } else {
            // Use PHPUnit's Application class for modern in-process execution
            $app = new \PHPUnit\TextUI\Application();

            // Capture output to prevent PHPUnit from writing directly
            ob_start();
            $exitCode = $app->run($argv);
            ob_end_clean();
        }

        // Recover assertions from the JUnit XML log (the event subscriber
        // API does not emit per-assertion events; the count lives in the
        // XML's top-level testsuites attribute).
        $this->stats['assertions'] = self::parseAssertionsFromJunit($junitXmlPath);

        // The JUnit XML is the authoritative record of how many tests ran,
        // independent of execution path. We read its counts here both to
        // populate the subprocess-path statistics (see below) and to gate
        // the "config present but zero tests" failure further down.
        $junitCounts = self::parseCountsFromJunit($junitXmlPath);

        // On the subprocess path (--php set, e.g. CI lanes) PHPUnit runs in
        // a separate process, so the in-process event subscribers registered
        // above never fire and the test/failure/error/skip counts stay zero.
        // Recover them from the same JUnit XML we already wrote, so lane logs
        // and the JSON summary report real numbers instead of silence.
        if ($useSubprocess) {
            $this->stats['tests'] = $junitCounts['tests'];
            $this->stats['failures'] = $junitCounts['failures'];
            $this->stats['errors'] = $junitCounts['errors'];
            $this->stats['skipped'] = $junitCounts['skipped'];

            // Recover per-failure detail too, so the aggregated PR comment /
            // Step Summary can name which tests failed. Without this the
            // subprocess path writes empty failures/errors arrays and the
            // downstream "which tests failed" table never renders - the
            // maintainer is left with only an artifact zip.
            [$this->failureRecords, $this->errorRecords]
                = self::parseFailureRecordsFromJunit($junitXmlPath);
        }

        // Policy: a component that ships a test/ directory AND a PHPUnit
        // configuration file, yet runs zero tests, is misconfigured - the
        // suite exists but collected nothing (bad testsuite path, empty
        // directory, discovery failure). PHPUnit itself exits 0 in that
        // case, so without this gate the lane would report a false "passed".
        //
        // This does NOT fire for libraries that are simply out of a PHP
        // version's scope: those lanes are marked incompatible at setup time
        // (build/skip.json) and qc unit is never invoked for them. Nor does
        // it fire for packages with no test/ directory at all - that path
        // returned early above as a deliberate no_test_suite pass.
        if (self::isZeroTestFailure($exitCode, $configFile, $junitCounts['tests'])) {
            $this->getOutput()->error(
                'Test configuration present (' . basename((string) $configFile)
                . ') but zero tests ran. Treating as failure.'
            );
            $this->writeJsonResults($componentPath, 2);
            $this->outputStatistics();

            return 2;
        }

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
            // 'enforced' matches PHPStan's convention so downstream
            // mode-aware rendering treats both tools the same way.
            'mode' => 'enforced',
            'statistics' => [
                'tests' => $this->stats['tests'],
                'assertions' => $this->stats['assertions'],
                'failures' => $this->stats['failures'],
                'errors' => $this->stats['errors'],
                'skipped' => $this->stats['skipped'],
            ],
            // Per-test detail captured from PHPUnit events. Two arrays
            // even though renderers may merge them, so the JSON's grain
            // matches PHPUnit's own classification. Truncation is a
            // rendering concern - the JSON carries the authoritative
            // data so an artifact-download workflow gets the full
            // story.
            'failures' => $this->failureRecords,
            'errors' => $this->errorRecords,
        ];

        $json = json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($jsonPath, $json);

        $this->getOutput()->info('JSON results written to: ' . $jsonPath);
    }

    /**
     * Write a summary marking the PHPUnit run as "no test suite present".
     *
     * Many Horde data packages and metapackages ship no PHPUnit tests.
     * Without an explicit signal, RunCommand sees the absent
     * phpunit-results-summary.json and routes the lane through
     * `addMissing` -> failure - surfacing as a CI failure for a component
     * that's working as designed.
     *
     * The marker uses `success: true` (the run completed cleanly - there
     * was nothing to run) and `mode: 'no_test_suite'` so
     * PrCommentReporter can render a skip indicator instead of a failure. Statistics stay at
     * zero; downstream aggregation excludes no-test-suite lanes from
     * lanes_run the same way it excludes missing/error/skipped lanes.
     *
     * @param string $componentPath Path to the component being tested.
     */
    private function writeNoTestSuiteResults(string $componentPath): void
    {
        $buildDir = $componentPath . '/build';
        if (!is_dir($buildDir) && !mkdir($buildDir, 0o755, true) && !is_dir($buildDir)) {
            // Should not happen in practice - LaneCopier creates the
            // component dir long before this runs - but the failure is
            // not fatal: callers see "no JSON written" and the lane
            // surfaces as missing, the worst-case fallback.
            return;
        }

        $jsonPath = $buildDir . '/phpunit-results-summary.json';
        $results = [
            'timestamp' => date('c'),
            'phpunit_version' => $this->getPhpUnitVersion(),
            'exit_code' => 0,
            'success' => true,
            'mode' => 'no_test_suite',
            'statistics' => [
                'tests' => 0,
                'assertions' => 0,
                'failures' => 0,
                'errors' => 0,
                'skipped' => 0,
            ],
            // Empty for schema stability: consumers (FindingsAggregator,
            // ResultCollector) can rely on the keys always being present.
            'failures' => [],
            'errors' => [],
        ];

        file_put_contents(
            $jsonPath,
            (string) json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $this->getOutput()->info('No test/ directory; recorded no_test_suite at ' . $jsonPath);
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
                $this->outputFailureDetails();
            } else {
                $message = 'Test results: ' . implode(', ', $parts) . ' ran OK';
                $this->getOutput()->ok($message);
            }
        }
    }

    /**
     * Print one line per failed / errored test so CI logs name the offenders
     * instead of just an aggregate count. Called from outputStatistics()
     * whenever at least one test failed or errored.
     */
    private function outputFailureDetails(): void
    {
        $output = $this->getOutput();

        foreach ($this->failureRecords as $record) {
            $output->warn($this->formatRecord($record));
        }
        foreach ($this->errorRecords as $record) {
            $output->warn($this->formatRecord($record));
        }
    }

    /**
     * Format one failure/error record into a single-line summary followed by
     * the exception message. Skips absent fields (data-provider tests can
     * emit records with no file/line).
     *
     * @param array{type:string,test_class:string,test_method:string,file:string,line:int,exception_class:string,message:string,trace:string} $record
     */
    private function formatRecord(array $record): string
    {
        $label = strtoupper($record['type']);
        $target = $record['test_class'];
        if ($record['test_method'] !== '') {
            $target .= '::' . $record['test_method'];
        }

        $location = '';
        if ($record['file'] !== '' && $record['line'] > 0) {
            $location = ' (' . $record['file'] . ':' . $record['line'] . ')';
        }

        // Keep message on the same visual block but on its own line so long
        // messages don't wrap the summary line. Trim aggressive whitespace.
        $message = trim($record['message']);
        $suffix = $message !== '' ? "\n    " . str_replace("\n", "\n    ", $message) : '';

        return sprintf('  [%s] %s%s%s', $label, $target, $location, $suffix);
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

    /**
     * Recover the test/failure/error/skip counts from a JUnit XML log.
     *
     * The in-process event subscribers only fire when PHPUnit runs inside
     * this process. On the subprocess path (CI lanes, --php set) the counts
     * must instead be read back from the `--log-junit` output, whose
     * `<testsuites>`/`<testsuite>` elements carry `tests`, `failures`,
     * `errors` and `skipped` attributes.
     *
     * @param string $junitXmlPath Path to the JUnit XML file.
     * @return array{tests:int,failures:int,errors:int,skipped:int} Counts,
     *         all zero when the file is missing or unparseable.
     */
    public static function parseCountsFromJunit(string $junitXmlPath): array
    {
        $zero = ['tests' => 0, 'failures' => 0, 'errors' => 0, 'skipped' => 0];
        if (!is_file($junitXmlPath) || !is_readable($junitXmlPath)) {
            return $zero;
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_file($junitXmlPath);
        } finally {
            libxml_use_internal_errors($previous);
        }
        if ($xml === false) {
            return $zero;
        }
        // Root <testsuites> usually carries the aggregated counts. Trust it
        // when it reports a positive test count; summing nested suites in
        // that case would double-count.
        if ($xml->getName() === 'testsuites' && isset($xml['tests']) && (int) $xml['tests'] > 0) {
            return [
                'tests' => (int) $xml['tests'],
                'failures' => isset($xml['failures']) ? (int) $xml['failures'] : 0,
                'errors' => isset($xml['errors']) ? (int) $xml['errors'] : 0,
                'skipped' => isset($xml['skipped']) ? (int) $xml['skipped'] : 0,
            ];
        }
        // Fallback: a single <testsuite> root, or a <testsuites> that omits
        // the aggregate attributes. Sum the direct child suites.
        $counts = $zero;
        $suites = $xml->getName() === 'testsuites' ? $xml->children() : [$xml];
        foreach ($suites as $suite) {
            $counts['tests'] += isset($suite['tests']) ? (int) $suite['tests'] : 0;
            $counts['failures'] += isset($suite['failures']) ? (int) $suite['failures'] : 0;
            $counts['errors'] += isset($suite['errors']) ? (int) $suite['errors'] : 0;
            $counts['skipped'] += isset($suite['skipped']) ? (int) $suite['skipped'] : 0;
        }
        return $counts;
    }

    /**
     * Decide whether a clean PHPUnit run that collected zero tests should be
     * treated as a failure.
     *
     * A component that ships a PHPUnit configuration file but runs no tests
     * is misconfigured (empty testsuite, wrong path, discovery failure).
     * PHPUnit exits 0 in that case, so the lane would otherwise report a
     * false pass. When no configuration file is present the component has
     * opted out of a configured suite, and a non-zero PHPUnit exit already
     * speaks for itself - neither is forced to fail here.
     *
     * @param int $exitCode PHPUnit's exit code (0 = clean).
     * @param string|null $configFile Resolved phpunit.xml[.dist] path, or null.
     * @param int $testCount Authoritative test count from the JUnit XML.
     * @return bool True when the run must be forced to a failure.
     */
    public static function isZeroTestFailure(
        int $exitCode,
        ?string $configFile,
        int $testCount
    ): bool {
        return $exitCode === 0 && $configFile !== null && $testCount === 0;
    }

    /**
     * Recover per-failure and per-error records from a JUnit XML log.
     *
     * On the subprocess path the in-process event subscribers never fire,
     * so the detailed failure records (which downstream renderers use to
     * name the failing tests) must be rebuilt from the `--log-junit` output.
     * Each failing `<testcase>` carries a `<failure>` or `<error>` child
     * whose `type` attribute is the exception class and whose text is the
     * message followed by the stack trace.
     *
     * The returned records match the shape produced in-process by
     * {@see self::captureRecord()} so {@see FindingsAggregator::aggregatePhpUnit()}
     * can consume either source identically.
     *
     * @param string $junitXmlPath Path to the JUnit XML file.
     * @return array{0:list<array{type:string,test_class:string,test_method:string,file:string,line:int,exception_class:string,message:string,trace:string}>,1:list<array{type:string,test_class:string,test_method:string,file:string,line:int,exception_class:string,message:string,trace:string}>}
     *         Tuple of [failureRecords, errorRecords]; both empty when the
     *         file is missing or unparseable.
     */
    public static function parseFailureRecordsFromJunit(string $junitXmlPath): array
    {
        $failures = [];
        $errors = [];
        if (!is_file($junitXmlPath) || !is_readable($junitXmlPath)) {
            return [$failures, $errors];
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_file($junitXmlPath);
        } finally {
            libxml_use_internal_errors($previous);
        }
        if ($xml === false) {
            return [$failures, $errors];
        }

        foreach ($xml->xpath('//testcase') ?: [] as $testcase) {
            foreach (['failure' => &$failures, 'error' => &$errors] as $tag => &$bucket) {
                if (!isset($testcase->{$tag})) {
                    continue;
                }
                $node = $testcase->{$tag};
                $raw = trim((string) $node);
                // JUnit text is "<Class::method>\n<message>\n\n<trace>".
                // The message is the content before the first blank line
                // (minus the leading test-identifier line PHPUnit prepends);
                // the remainder is the stack trace.
                [$message, $trace] = self::splitJunitFailureText(
                    $raw,
                    (string) ($testcase['class'] ?? ''),
                    (string) ($testcase['name'] ?? ''),
                );
                $bucket[] = [
                    'type' => $tag === 'failure' ? 'failure' : 'error',
                    'test_class' => (string) ($testcase['class'] ?? ''),
                    'test_method' => (string) ($testcase['name'] ?? ''),
                    'file' => (string) ($testcase['file'] ?? ''),
                    'line' => (int) ($testcase['line'] ?? 0),
                    'exception_class' => (string) ($node['type'] ?? ''),
                    'message' => $message,
                    'trace' => $trace,
                ];
            }
            unset($bucket);
        }

        return [$failures, $errors];
    }

    /**
     * Split a JUnit failure/error node's text into (message, trace).
     *
     * PHPUnit prepends a "<Class>::<method>" identifier line, then the
     * human message, a blank line, and the stack trace. We drop the
     * identifier line (the record already carries class/method as fields)
     * and treat everything up to the first blank line as the message.
     *
     * @return array{0:string,1:string} [message, trace]
     */
    private static function splitJunitFailureText(
        string $raw,
        string $class,
        string $method
    ): array {
        if ($raw === '') {
            return ['', ''];
        }
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [$raw];
        // Drop a leading "<Class>::<method>" identifier line if present.
        $identifier = $class !== '' && $method !== '' ? $class . '::' . $method : '';
        if ($identifier !== '' && isset($lines[0]) && trim($lines[0]) === $identifier) {
            array_shift($lines);
        }
        // Message = lines up to the first blank line; trace = the rest.
        $messageLines = [];
        $i = 0;
        $count = count($lines);
        for (; $i < $count; $i++) {
            if (trim($lines[$i]) === '') {
                break;
            }
            $messageLines[] = $lines[$i];
        }
        $traceLines = array_slice($lines, $i);
        return [
            trim(implode("\n", $messageLines)),
            trim(implode("\n", $traceLines)),
        ];
    }
}
