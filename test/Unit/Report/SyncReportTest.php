<?php

/**
 * Unit tests for SyncReport
 *
 * PHP version 8.2+
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Test\Unit\Report;

use Horde\Components\Report\BranchAliasCheck;
use Horde\Components\Report\RepositorySyncResult;
use Horde\Components\Report\SyncReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SyncReport::class)]
class SyncReportTest extends TestCase
{
    public function testEmptyReport(): void
    {
        $report = new SyncReport([]);

        $this->assertEquals(0, $report->getDirtyCount());
        $this->assertEquals(0, $report->getConflictCount());
        $this->assertEquals(0, $report->getMissingAliasCount());
        $this->assertEquals(0, $report->getPatchReadyCount());
        $this->assertEquals(0, $report->getMinorReadyCount());
        $this->assertEquals(0, $report->getMajorReadyCount());
        $this->assertEmpty($report->getResults());
    }

    public function testReportWithDirtyRepo(): void
    {
        $result = new RepositorySyncResult(path: '/path/to/repo', name: 'TestRepo');
        $result->isClean = false;

        $report = new SyncReport([$result]);

        $this->assertEquals(1, $report->getDirtyCount());
        $this->assertEquals(0, $report->getConflictCount());
        $this->assertCount(1, $report->getResults());
    }

    public function testReportWithConflict(): void
    {
        $result = new RepositorySyncResult(path: '/path/to/repo', name: 'TestRepo');
        $result->isClean = true;
        $result->rebaseConflict = true;

        $report = new SyncReport([$result]);

        $this->assertEquals(0, $report->getDirtyCount());
        $this->assertEquals(1, $report->getConflictCount());
    }

    public function testReportWithMissingBranchAlias(): void
    {
        $result = new RepositorySyncResult(path: '/path/to/repo', name: 'TestRepo');
        $result->isClean = true;
        $result->rebaseSuccessful = true;
        $result->branchAliasConfig = new BranchAliasCheck(configured: false, value: null);

        $report = new SyncReport([$result]);

        $this->assertEquals(1, $report->getMissingAliasCount());
        $this->assertEquals(0, $report->getDirtyCount());
        $this->assertEquals(0, $report->getConflictCount());
    }

    public function testReportWithPatchReadyRepo(): void
    {
        $result = new RepositorySyncResult(path: '/path/to/repo', name: 'TestRepo');
        $result->isClean = true;
        $result->rebaseSuccessful = true;
        $result->branchAliasConfig = new BranchAliasCheck(configured: true, value: '1.x-dev');
        $result->lastTag = 'v1.0.0';
        $result->fixCount = 2;

        $report = new SyncReport([$result]);

        $this->assertEquals(1, $report->getPatchReadyCount());
        $this->assertEquals(0, $report->getMinorReadyCount());
        $this->assertEquals(0, $report->getMajorReadyCount());
    }

    public function testReportWithMinorReadyRepo(): void
    {
        $result = new RepositorySyncResult(path: '/path/to/repo', name: 'TestRepo');
        $result->isClean = true;
        $result->rebaseSuccessful = true;
        $result->branchAliasConfig = new BranchAliasCheck(configured: true, value: '1.x-dev');
        $result->lastTag = 'v1.0.0';
        $result->featCount = 3;

        $report = new SyncReport([$result]);

        $this->assertEquals(0, $report->getPatchReadyCount());
        $this->assertEquals(1, $report->getMinorReadyCount());
        $this->assertEquals(0, $report->getMajorReadyCount());
    }

    public function testReportWithMajorReadyRepo(): void
    {
        $result = new RepositorySyncResult(path: '/path/to/repo', name: 'TestRepo');
        $result->isClean = true;
        $result->rebaseSuccessful = true;
        $result->branchAliasConfig = new BranchAliasCheck(configured: true, value: '1.x-dev');
        $result->lastTag = 'v1.0.0';
        $result->breakingCount = 1;

        $report = new SyncReport([$result]);

        $this->assertEquals(0, $report->getPatchReadyCount());
        $this->assertEquals(0, $report->getMinorReadyCount());
        $this->assertEquals(1, $report->getMajorReadyCount());
    }

    public function testReportWithMultipleRepos(): void
    {
        $dirty = new RepositorySyncResult(path: '/path/to/dirty', name: 'Dirty');
        $dirty->isClean = false;

        $conflict = new RepositorySyncResult(path: '/path/to/conflict', name: 'Conflict');
        $conflict->isClean = true;
        $conflict->rebaseConflict = true;

        $clean = new RepositorySyncResult(path: '/path/to/clean', name: 'Clean');
        $clean->isClean = true;
        $clean->rebaseSuccessful = true;
        $clean->branchAliasConfig = new BranchAliasCheck(configured: true, value: '1.x-dev');

        $report = new SyncReport([$dirty, $conflict, $clean]);

        $this->assertEquals(1, $report->getDirtyCount());
        $this->assertEquals(1, $report->getConflictCount());
        $this->assertCount(3, $report->getResults());
    }
}
