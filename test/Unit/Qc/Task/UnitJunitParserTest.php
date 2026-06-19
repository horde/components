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
}
