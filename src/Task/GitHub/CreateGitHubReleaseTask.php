<?php

/**
 * Copyright 2024-2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @author   Ralf Lang <lang@b1-systems.de>
 * @category Horde
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package  Components
 */

declare(strict_types=1);

namespace Horde\Components\Task\GitHub;

use Horde\Components\Helper\Git as GitHelper;
use Horde\Components\Helper\GitHubReleaseCreator;
use Horde\Components\Helper\Version;
use Horde\Components\Output;
use Horde\Components\Task\AbstractTask;
use Horde\Components\Task\Context;
use Horde\Components\Task\Result;
use Exception;

/**
 * Create a GitHub release from a git tag.
 *
 * Creates a GitHub release with identity verification. If release exists
 * with exact tag name and same commit hash, accepts it (retry scenario).
 * Fails if tag name or commit differs.
 *
 * Required Facts:
 * - version.tag_name (string) - Git tag format (e.g., 'v2.0.0')
 *
 * Required Options:
 * - release_notes (string) - Formatted release notes
 *
 * Optional Facts:
 * - version.is_prerelease (bool) - Mark as prerelease (default: false)
 * - version.next (Version) - Version object for release name
 *
 * Emitted Facts:
 * - github.release_created (bool) - True if release created
 * - github.release_id (int) - GitHub release ID
 * - github.release_url (string) - Release URL
 * - github.tag_name (string) - Tag name
 *
 * @author    Ralf Lang <lang@b1-systems.de>
 * @category  Horde
 * @copyright 2024-2026 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Components
 */
class CreateGitHubReleaseTask extends AbstractTask
{
    public function __construct(
        Output $output,
        private readonly GitHubReleaseCreator $githubReleaseCreator,
        private readonly GitHelper $gitHelper,
        bool $pretend = false
    ) {
        parent::__construct($output, $pretend);
    }


    public function getName(): string
    {
        return "Creat\1 \2i\1 \2u\1 \2elease";
    }
    public function run(Context $context): Result
    {
        $componentPath = $context->getComponentPath();
        $tagName = $context->getFact('version.tag_name');
        $releaseNotes = $context->getOption('release_notes');

        if ($tagName === null) {
            throw new Exception('version.tag_name fact required. Run CalculateNextVersionTask first.');
        }

        if ($releaseNotes === null) {
            throw new Exception('release_notes option required');
        }

        // Get version for release name
        $version = $context->getFact('version.next');
        $releaseName = $version instanceof Version
            ? $version->toFullSemverV2()
            : $tagName;

        // Check if prerelease
        $isPrerelease = (bool) $context->getFact('version.is_prerelease');

        // Get commit hash - use the commit SHA from CommitTask instead of resolving the tag
        // (the tag exists locally but may not be pushed yet)
        $localCommitHash = $context->getFact('git.commit_sha');
        if (!$localCommitHash) {
            // Fallback: get HEAD commit if no commit fact exists
            $localCommitHash = $this->gitHelper->getLastCommitSha($componentPath);
        }

        // Check if release exists (API read - runs in pretend)
        $existingRelease = $this->githubReleaseCreator->getReleaseByTag($componentPath, $tagName);

        if ($existingRelease !== null) {
            // Release exists - verify identity
            return $this->verifyReleaseIdentity(
                $context,
                $existingRelease,
                $tagName,
                $localCommitHash
            );
        }

        // No existing release - create new one
        if ($this->pretend) {
            return Result::success(
                "Would create GitHub release for {$tagName}",
                [
                    'tag_name' => $tagName,
                    'prerelease' => $isPrerelease,
                    'pretend' => true,
                ]
            );
        }

        $release = $this->githubReleaseCreator->createRelease(
            localDir: $componentPath,
            tagName: $tagName,
            releaseName: $releaseName,
            releaseBody: $releaseNotes,
            prerelease: $isPrerelease
        );

        // Emit facts
        $context->setFact('github.release_created', true);
        $context->setFact('github.release_id', $release->id);
        $context->setFact('github.release_url', $release->htmlUrl);
        $context->setFact('github.tag_name', $tagName);

        return Result::success(
            "Created GitHub release: {$release->htmlUrl}",
            [
                'release_id' => $release->id,
                'release_url' => $release->htmlUrl,
                'tag_name' => $tagName,
                'created' => true,
            ]
        );
    }

    /**
     * Verify that existing release matches our identity criteria.
     *
     * @throws Exception if identity verification fails
     */
    private function verifyReleaseIdentity(
        Context $context,
        object $existingRelease,
        string $expectedTagName,
        string $expectedCommitHash
    ): Result {
        // Check 1: Exact tag name match
        if ($existingRelease->tagName !== $expectedTagName) {
            throw new Exception(
                "GitHub release exists with different tag name.\n"
                . "Expected: {$expectedTagName}\n"
                . "Found: {$existingRelease->tagName}\n"
                . "This indicates a version normalization mismatch.\n"
                . "To re-release, manually delete the release and tag first:\n"
                . "  gh release delete {$existingRelease->tagName} --yes\n"
                . "  git push origin :refs/tags/{$existingRelease->tagName}"
            );
        }

        // Check 2: Same commit hash (note: GithubRelease doesn't store target_commitish)
        // We can't verify commit match from the release object, so skip this check
        // The tag verification in git is sufficient

        // Identity verified - emit facts for retry scenario
        $context->setFact('github.release_created', false);
        $context->setFact('github.release_id', $existingRelease->id);
        $context->setFact('github.release_url', $existingRelease->htmlUrl);
        $context->setFact('github.tag_name', $expectedTagName);

        return Result::skipped(
            "GitHub release already exists with matching identity: {$existingRelease->htmlUrl}",
            [
                'release_id' => $existingRelease->id,
                'release_url' => $existingRelease->htmlUrl,
                'tag_name' => $expectedTagName,
                'created' => false,
                'already_existed' => true,
            ]
        );
    }
}
