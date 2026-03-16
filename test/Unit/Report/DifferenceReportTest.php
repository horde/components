<?php

/**
 * Test DifferenceReport value object.
 *
 * PHP Version 8.2+
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Test\Unit\Report;

use Horde\Components\Report\DifferenceReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test DifferenceReport value object.
 *
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(DifferenceReport::class)]
class DifferenceReportTest extends TestCase
{
    public function testConstructor(): void
    {
        $remoteOnly = ['repo1', 'repo2'];
        $localOnly = ['repo3'];
        $inSync = ['repo4', 'repo5', 'repo6'];

        $report = new DifferenceReport($remoteOnly, $localOnly, $inSync);

        $this->assertSame($remoteOnly, $report->remoteOnly);
        $this->assertSame($localOnly, $report->localOnly);
        $this->assertSame($inSync, $report->inSync);
    }

    public function testHasRemoteOnly(): void
    {
        $report = new DifferenceReport(['repo1'], [], []);
        $this->assertTrue($report->hasRemoteOnly());

        $report = new DifferenceReport([], ['repo1'], []);
        $this->assertFalse($report->hasRemoteOnly());
    }

    public function testHasLocalOnly(): void
    {
        $report = new DifferenceReport([], ['repo1'], []);
        $this->assertTrue($report->hasLocalOnly());

        $report = new DifferenceReport(['repo1'], [], []);
        $this->assertFalse($report->hasLocalOnly());
    }

    public function testCountRemote(): void
    {
        $report = new DifferenceReport(['repo1', 'repo2'], [], ['repo3', 'repo4']);
        $this->assertSame(4, $report->countRemote());
    }

    public function testCountLocal(): void
    {
        $report = new DifferenceReport([], ['repo1'], ['repo2', 'repo3', 'repo4']);
        $this->assertSame(4, $report->countLocal());
    }

    public function testCountInSync(): void
    {
        $report = new DifferenceReport(['repo1'], ['repo2'], ['repo3', 'repo4', 'repo5']);
        $this->assertSame(3, $report->countInSync());
    }

    public function testEmptyReport(): void
    {
        $report = new DifferenceReport([], [], []);

        $this->assertFalse($report->hasRemoteOnly());
        $this->assertFalse($report->hasLocalOnly());
        $this->assertSame(0, $report->countRemote());
        $this->assertSame(0, $report->countLocal());
        $this->assertSame(0, $report->countInSync());
    }
}
