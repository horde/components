<?php

/**
 * Test RepositorySyncResult value object.
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

use Horde\Components\Report\BranchAliasCheck;
use Horde\Components\Report\RepositorySyncResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test RepositorySyncResult value object.
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
#[CoversClass(RepositorySyncResult::class)]
class RepositorySyncResultTest extends TestCase
{
    public function testConstructor(): void
    {
        $result = new RepositorySyncResult('/path/to/repo', 'RepoName');

        $this->assertSame('/path/to/repo', $result->path);
        $this->assertSame('RepoName', $result->name);
        $this->assertFalse($result->isClean);
        $this->assertNull($result->currentBranch);
        $this->assertFalse($result->rebaseSuccessful);
        $this->assertFalse($result->rebaseConflict);
        $this->assertNull($result->branchAliasConfig);
        $this->assertNull($result->lastTag);
        $this->assertSame(0, $result->featCount);
        $this->assertSame(0, $result->fixCount);
        $this->assertSame(0, $result->testCount);
        $this->assertSame(0, $result->breakingCount);
        $this->assertNull($result->skipReason);
    }

    public function testSuggestReleaseDirty(): void
    {
        $result = new RepositorySyncResult('/path', 'name', isClean: false);
        $this->assertSame('Clean first', $result->suggestRelease());
    }

    public function testSuggestReleaseConflict(): void
    {
        $result = new RepositorySyncResult(
            '/path',
            'name',
            isClean: true,
            rebaseConflict: true
        );
        $this->assertSame('Resolve conflict', $result->suggestRelease());
    }

    public function testSuggestReleaseMajor(): void
    {
        $result = new RepositorySyncResult(
            '/path',
            'name',
            isClean: true,
            breakingCount: 1
        );
        $this->assertSame('Major', $result->suggestRelease());
    }

    public function testSuggestReleaseMinor(): void
    {
        $result = new RepositorySyncResult(
            '/path',
            'name',
            isClean: true,
            featCount: 2
        );
        $this->assertSame('Minor', $result->suggestRelease());
    }

    public function testSuggestReleasePatch(): void
    {
        $result = new RepositorySyncResult(
            '/path',
            'name',
            isClean: true,
            fixCount: 1
        );
        $this->assertSame('Patch', $result->suggestRelease());
    }

    public function testSuggestReleaseNone(): void
    {
        $result = new RepositorySyncResult(
            '/path',
            'name',
            isClean: true
        );
        $this->assertSame('—', $result->suggestRelease());
    }

    public function testGetCommitCountString(): void
    {
        $result = new RepositorySyncResult(
            '/path',
            'name',
            featCount: 5,
            fixCount: 3,
            testCount: 2
        );
        $this->assertSame('5/3/2', $result->getCommitCountString());
    }

    public function testGetCommitCountStringWithSkipReason(): void
    {
        $result = new RepositorySyncResult(
            '/path',
            'name',
            skipReason: 'Fetch failed'
        );
        $this->assertSame('—', $result->getCommitCountString());
    }

    public function testHasIssuesDirty(): void
    {
        $result = new RepositorySyncResult('/path', 'name', isClean: false);
        $this->assertTrue($result->hasIssues());
    }

    public function testHasIssuesConflict(): void
    {
        $result = new RepositorySyncResult(
            '/path',
            'name',
            isClean: true,
            rebaseConflict: true
        );
        $this->assertTrue($result->hasIssues());
    }

    public function testHasIssuesMissingAlias(): void
    {
        $aliasCheck = new BranchAliasCheck(false, null);
        $result = new RepositorySyncResult(
            '/path',
            'name',
            isClean: true,
            branchAliasConfig: $aliasCheck
        );
        $this->assertTrue($result->hasIssues());
    }

    public function testHasNoIssues(): void
    {
        $aliasCheck = new BranchAliasCheck(true, '3.x-dev');
        $result = new RepositorySyncResult(
            '/path',
            'name',
            isClean: true,
            branchAliasConfig: $aliasCheck
        );
        $this->assertFalse($result->hasIssues());
    }
}
