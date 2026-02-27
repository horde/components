<?php

declare(strict_types=1);

namespace Horde\Components\Test\Unit\Helper;

use Horde\Components\Helper\GitHubChecker;
use Horde\Components\Helper\GitHubReleaseCreator;
use Horde\Components\Output;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(GitHubReleaseCreator::class)]
class GitHubReleaseCreatorTest extends TestCase
{
    public function testGetReleaseTypeLabelMajor(): void
    {
        $label = GitHubReleaseCreator::getReleaseTypeLabel('major');
        $this->assertSame('major', $label);
    }

    public function testGetReleaseTypeLabelMinor(): void
    {
        $label = GitHubReleaseCreator::getReleaseTypeLabel('minor');
        $this->assertSame('minor', $label);
    }

    public function testGetReleaseTypeLabelPatch(): void
    {
        $label = GitHubReleaseCreator::getReleaseTypeLabel('patch');
        $this->assertSame('bugfix', $label);
    }

    public function testGetReleaseTypeLabelSubpatch(): void
    {
        $label = GitHubReleaseCreator::getReleaseTypeLabel('subpatch');
        $this->assertSame('maintenance', $label);
    }

    public function testGetReleaseTypeLabelUnknown(): void
    {
        $label = GitHubReleaseCreator::getReleaseTypeLabel('unknown');
        $this->assertSame('maintenance', $label);
    }

    public function testFormatReleaseNotesWithMajor(): void
    {
        $notes = "Fix bug\nAdd feature";
        $formatted = GitHubReleaseCreator::formatReleaseNotes($notes, 'major');

        $this->assertStringContainsString('This is a **major release**', $formatted);
        $this->assertStringContainsString('⚠️ Contains breaking changes', $formatted);
        $this->assertStringContainsString('Fix bug', $formatted);
        $this->assertStringContainsString('Add feature', $formatted);
    }

    public function testFormatReleaseNotesWithMinor(): void
    {
        $notes = "Add new feature\nImprove performance";
        $formatted = GitHubReleaseCreator::formatReleaseNotes($notes, 'minor');

        $this->assertStringContainsString('This is a **minor release**', $formatted);
        $this->assertStringNotContainsString('breaking changes', $formatted);
        $this->assertStringContainsString('Add new feature', $formatted);
    }

    public function testFormatReleaseNotesWithPatch(): void
    {
        $notes = "Fix critical bug";
        $formatted = GitHubReleaseCreator::formatReleaseNotes($notes, 'patch');

        $this->assertStringContainsString('This is a **bugfix release**', $formatted);
        $this->assertStringContainsString('Fix critical bug', $formatted);
    }

    public function testFormatReleaseNotesWithSubpatch(): void
    {
        $notes = "Update dependencies\nRefactor code";
        $formatted = GitHubReleaseCreator::formatReleaseNotes($notes, 'subpatch');

        $this->assertStringContainsString('This is a **maintenance release**', $formatted);
        $this->assertStringContainsString('Update dependencies', $formatted);
    }

    public function testFormatReleaseNotesTrimWhitespace(): void
    {
        $notes = "\n\n  Some notes with whitespace  \n\n";
        $formatted = GitHubReleaseCreator::formatReleaseNotes($notes, 'minor');

        $this->assertStringStartsWith('This is a **minor release**', $formatted);
        $this->assertStringContainsString('Some notes with whitespace', $formatted);
        // Should not have trailing whitespace from the notes
        $this->assertStringNotContainsString("whitespace  \n\n", $formatted);
    }

    public function testCreateReleaseSkipsNonGitHubRepository(): void
    {
        $githubChecker = $this->createMock(GitHubChecker::class);
        $output = $this->createMock(Output::class);

        $githubChecker->method('isOnGitHub')->willReturn(false);
        $output->expects($this->once())
            ->method('info')
            ->with('Not a GitHub repository, skipping GitHub release creation');

        $creator = new GitHubReleaseCreator($githubChecker, $output);

        $result = $creator->createRelease(
            localDir: '/tmp/test',
            tagName: 'v1.0.0',
            releaseName: 'Release 1.0.0',
            releaseBody: 'Test release',
            prerelease: false
        );

        $this->assertFalse($result);
    }

    public function testCreateReleaseSkipsWhenNoGitHubToken(): void
    {
        // Save current environment
        $originalToken = getenv('GITHUB_TOKEN');

        // Clear GITHUB_TOKEN
        putenv('GITHUB_TOKEN=');

        $githubChecker = $this->createMock(GitHubChecker::class);
        $output = $this->createMock(Output::class);

        $githubChecker->method('isOnGitHub')->willReturn(true);
        $output->expects($this->once())
            ->method('warn')
            ->with('GITHUB_TOKEN environment variable not set, skipping GitHub release creation');
        $output->expects($this->once())
            ->method('help');

        $creator = new GitHubReleaseCreator($githubChecker, $output);

        $result = $creator->createRelease(
            localDir: '/tmp/test',
            tagName: 'v1.0.0',
            releaseName: 'Release 1.0.0',
            releaseBody: 'Test release',
            prerelease: false
        );

        $this->assertFalse($result);

        // Restore original environment
        if ($originalToken !== false) {
            putenv('GITHUB_TOKEN=' . $originalToken);
        }
    }

    public function testCreateReleaseSkipsWhenRepositoryNotDetermined(): void
    {
        // Set a dummy token for the test
        $originalToken = getenv('GITHUB_TOKEN');
        putenv('GITHUB_TOKEN=test_token');

        $githubChecker = $this->createMock(GitHubChecker::class);
        $output = $this->createMock(Output::class);

        $githubChecker->method('isOnGitHub')->willReturn(true);
        $githubChecker->method('getGitHubRepository')->willReturn(null);
        $output->expects($this->once())
            ->method('warn')
            ->with('Could not determine GitHub repository, skipping release creation');

        $creator = new GitHubReleaseCreator($githubChecker, $output);

        $result = $creator->createRelease(
            localDir: '/tmp/test',
            tagName: 'v1.0.0',
            releaseName: 'Release 1.0.0',
            releaseBody: 'Test release',
            prerelease: false
        );

        $this->assertFalse($result);

        // Restore original environment
        if ($originalToken !== false) {
            putenv('GITHUB_TOKEN=' . $originalToken);
        } else {
            putenv('GITHUB_TOKEN=');
        }
    }
}
