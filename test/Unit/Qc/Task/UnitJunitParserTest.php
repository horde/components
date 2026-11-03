<?php

/**
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Test\Unit\Qc\Task;

use Horde\Components\Qc\Task\Unit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the JUnit XML assertion-count parser on the Qc Unit task.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(Unit::class)]
class UnitJunitParserTest extends TestCase
{
    private string $tempPath;

    protected function setUp(): void
    {
        $this->tempPath = tempnam(sys_get_temp_dir(), 'unit-junit-');
    }

    protected function tearDown(): void
    {
        @unlink($this->tempPath);
    }

    public function testRootAttributeWins(): void
    {
        file_put_contents($this->tempPath, <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <testsuites tests="54" assertions="256" failures="0" errors="0">
              <testsuite name="default" tests="54" assertions="256" />
            </testsuites>
            XML);

        $this->assertSame(256, Unit::parseAssertionsFromJunit($this->tempPath));
    }

    public function testFallbackToSummingSuitesWhenRootMissing(): void
    {
        file_put_contents($this->tempPath, <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <testsuites>
              <testsuite name="a" tests="10" assertions="40" />
              <testsuite name="b" tests="5" assertions="13" />
            </testsuites>
            XML);

        $this->assertSame(53, Unit::parseAssertionsFromJunit($this->tempPath));
    }

    public function testSingleTestsuiteRoot(): void
    {
        // Some PHPUnit configs emit a bare <testsuite> root instead of
        // <testsuites>. The parser should still find the count.
        file_put_contents($this->tempPath, <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <testsuite name="solo" tests="7" assertions="21" />
            XML);

        $this->assertSame(21, Unit::parseAssertionsFromJunit($this->tempPath));
    }

    public function testMissingFileReturnsZero(): void
    {
        $this->assertSame(0, Unit::parseAssertionsFromJunit('/nonexistent/path.xml'));
    }

    public function testGarbageContentReturnsZero(): void
    {
        file_put_contents($this->tempPath, 'this is not XML');
        $this->assertSame(0, Unit::parseAssertionsFromJunit($this->tempPath));
    }

    public function testMissingAttributeReturnsZero(): void
    {
        file_put_contents($this->tempPath, <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <testsuites>
              <testsuite name="a" tests="3" />
            </testsuites>
            XML);
        $this->assertSame(0, Unit::parseAssertionsFromJunit($this->tempPath));
    }

    public function testCountsFromRootAggregate(): void
    {
        file_put_contents($this->tempPath, <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <testsuites tests="12" assertions="40" failures="1" errors="0" skipped="2">
              <testsuite name="victim" tests="12" failures="1" errors="0" skipped="2" />
            </testsuites>
            XML);

        $this->assertSame(
            ['tests' => 12, 'failures' => 1, 'errors' => 0, 'skipped' => 2],
            Unit::parseCountsFromJunit($this->tempPath),
        );
    }

    public function testCountsFallbackSumsSuitesWhenRootMissing(): void
    {
        file_put_contents($this->tempPath, <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <testsuites>
              <testsuite name="a" tests="3" failures="0" errors="1" skipped="0" />
              <testsuite name="b" tests="4" failures="2" errors="0" skipped="1" />
            </testsuites>
            XML);

        $this->assertSame(
            ['tests' => 7, 'failures' => 2, 'errors' => 1, 'skipped' => 1],
            Unit::parseCountsFromJunit($this->tempPath),
        );
    }

    public function testCountsFromSingleTestsuiteRoot(): void
    {
        file_put_contents($this->tempPath, <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <testsuite name="solo" tests="5" assertions="9" failures="0" errors="0" skipped="0" />
            XML);

        $this->assertSame(
            ['tests' => 5, 'failures' => 0, 'errors' => 0, 'skipped' => 0],
            Unit::parseCountsFromJunit($this->tempPath),
        );
    }

    public function testCountsMissingFileReturnsZeroes(): void
    {
        $this->assertSame(
            ['tests' => 0, 'failures' => 0, 'errors' => 0, 'skipped' => 0],
            Unit::parseCountsFromJunit('/nonexistent/path.xml'),
        );
    }

    public function testCountsGarbageContentReturnsZeroes(): void
    {
        file_put_contents($this->tempPath, 'this is not XML');
        $this->assertSame(
            ['tests' => 0, 'failures' => 0, 'errors' => 0, 'skipped' => 0],
            Unit::parseCountsFromJunit($this->tempPath),
        );
    }

    public function testZeroTestsWithConfigIsFailure(): void
    {
        // test/ dir + phpunit config present, PHPUnit exited clean, but no
        // tests ran -> misconfigured suite, must be forced to a failure.
        $this->assertTrue(Unit::isZeroTestFailure(0, '/c/phpunit.xml.dist', 0));
    }

    public function testTestsRanIsNotFailure(): void
    {
        $this->assertFalse(Unit::isZeroTestFailure(0, '/c/phpunit.xml.dist', 54));
    }

    public function testZeroTestsWithoutConfigIsNotFailure(): void
    {
        // No configuration file: the component opted out of a configured
        // suite. Not forced to fail here.
        $this->assertFalse(Unit::isZeroTestFailure(0, null, 0));
    }

    public function testNonZeroExitIsLeftToPhpUnit(): void
    {
        // PHPUnit already failed; the zero-test gate does not override or
        // double-count it.
        $this->assertFalse(Unit::isZeroTestFailure(1, '/c/phpunit.xml.dist', 0));
    }

    public function testFailureRecordsParsedFromJunit(): void
    {
        file_put_contents($this->tempPath, <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <testsuites>
              <testsuite name="unit" tests="2" failures="1" errors="0">
                <testcase name="testOk" class="Acme\OkTest" file="/tmp/horde-ci/lanes/php8.6-dev/Acme/test/unit/OkTest.php" line="10" />
                <testcase name="testRunsOnAValidatedPhpMinorVersion" class="Acme\MatrixTest" file="/tmp/horde-ci/lanes/php8.6-dev/Acme/test/unit/MatrixTest.php" line="26">
                  <failure type="PHPUnit\Framework\ExpectationFailedException">Acme\MatrixTest::testRunsOnAValidatedPhpMinorVersion
            Test suite is validated up to PHP 8.5; running under 8.6.
            Failed asserting that 6 is equal to 5 or is less than 5.

            /tmp/horde-ci/lanes/php8.6-dev/Acme/test/unit/MatrixTest.php:30</failure>
                </testcase>
              </testsuite>
            </testsuites>
            XML);

        [$failures, $errors] = Unit::parseFailureRecordsFromJunit($this->tempPath);

        $this->assertCount(1, $failures);
        $this->assertSame([], $errors);
        $rec = $failures[0];
        $this->assertSame('failure', $rec['type']);
        $this->assertSame('Acme\\MatrixTest', $rec['test_class']);
        $this->assertSame('testRunsOnAValidatedPhpMinorVersion', $rec['test_method']);
        $this->assertSame(26, $rec['line']);
        $this->assertSame('PHPUnit\\Framework\\ExpectationFailedException', $rec['exception_class']);
        // Identifier line dropped; message is the human text up to the blank line.
        $this->assertStringContainsString('validated up to PHP 8.5; running under 8.6', $rec['message']);
        $this->assertStringContainsString('Failed asserting that 6 is equal to 5', $rec['message']);
        $this->assertStringNotContainsString('Acme\\MatrixTest::testRunsOnAValidatedPhpMinorVersion', $rec['message']);
        // Trace carries the post-blank-line remainder.
        $this->assertStringContainsString('MatrixTest.php:30', $rec['trace']);
    }

    public function testFailureRecordsErrorBucket(): void
    {
        file_put_contents($this->tempPath, <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <testsuites>
              <testsuite name="unit" tests="1" failures="0" errors="1">
                <testcase name="testBoom" class="Acme\BoomTest" file="/x/Acme/test/unit/BoomTest.php" line="5">
                  <error type="RuntimeException">Acme\BoomTest::testBoom
            Kaboom.</error>
                </testcase>
              </testsuite>
            </testsuites>
            XML);

        [$failures, $errors] = Unit::parseFailureRecordsFromJunit($this->tempPath);

        $this->assertSame([], $failures);
        $this->assertCount(1, $errors);
        $this->assertSame('error', $errors[0]['type']);
        $this->assertSame('RuntimeException', $errors[0]['exception_class']);
        $this->assertSame('Kaboom.', $errors[0]['message']);
    }

    public function testFailureRecordsMissingFileReturnsEmptyTuple(): void
    {
        $this->assertSame([[], []], Unit::parseFailureRecordsFromJunit('/nonexistent/path.xml'));
    }
}
