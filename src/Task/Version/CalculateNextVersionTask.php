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

namespace Horde\Components\Task\Version;

use Horde\Components\Helper\Git as GitHelper;
use Horde\Components\Helper\Version;
use Horde\Components\Output;
use Horde\Components\Task\AbstractTask;
use Horde\Components\Task\Context;
use Horde\Components\Task\Result;
use Exception;

/**
 * Calculate or normalize the next semantic version with reality check.
 *
 * Accepts manual version override via next_version option, or calculates
 * from conventional commits. Both paths verify that the tag doesn't already
 * exist locally or remotely.
 *
 * Required Facts:
 * - version.top_severity (string) - Only if no manual version provided
 *
 * Optional Facts:
 * - current_version (string) - Base version (default: from .horde.yml)
 *
 * Options:
 * - next_version (string) - Manual version override
 * - version_part (string) - Force bump type: 'minor' or 'patch'
 * - allow_existing_tag (bool) - Skip tag check (default: false)
 *
 * Emitted Facts:
 * - version.next (Version) - Version object
 * - version.next_string (string) - Full SemVer string
 * - version.tag_name (string) - Git tag format
 * - version.stability (string) - Stability level
 * - version.is_prerelease (bool) - Is prerelease version
 * - version.source (string) - How determined: 'manual', 'calculated', 'forced'
 * - version.tag_checked (bool) - Reality check passed
 *
 * @author    Ralf Lang <lang@b1-systems.de>
 * @category  Horde
 * @copyright 2024-2026 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Components
 */
class CalculateNextVersionTask extends AbstractTask
{
    public function __construct(
        Output $output,
        private readonly GitHelper $gitHelper,
        bool $pretend = false
    ) {
        parent::__construct($output, $pretend);
    }

    public function getName(): string
    {
        return 'Calculate Next Version';
    }

    public function run(Context $context): Result
    {
        $componentPath = $context->getComponentPath();

        // Determine version source and calculate
        $manualVersion = $context->getOption('next_version');
        $versionPart = $context->getOption('version_part');

        if ($manualVersion !== null) {
            // Path A: Manual version
            $version = Version::fromComposerString($manualVersion);
            $source = 'manual';
        } elseif ($versionPart !== null) {
            // Path C: Force version part bump
            $version = $this->bumpVersionPart($context, $versionPart);
            $source = 'forced';
        } else {
            // Path B: Calculate from commits
            $version = $this->calculateFromCommits($context);
            $source = 'calculated';
        }

        $tagName = $version->toHordeTag();

        // Reality check: tag must not already exist
        $allowExisting = (bool) $context->getOption('allow_existing_tag');

        if (!$allowExisting) {
            $this->checkTagDoesNotExist($componentPath, $tagName, $version);
        }

        // Emit facts
        $context->setFact('version.next', $version);
        $context->setFact('version.next_string', $version->toFullSemverV2());
        $context->setFact('version.tag_name', $tagName);
        $context->setFact('version.stability', $version->getStability());
        $context->setFact('version.is_prerelease', in_array($version->getStability(), ['alpha', 'beta', 'RC']));
        $context->setFact('version.source', $source);
        $context->setFact('version.tag_checked', true);

        return Result::success(
            "Version: {$version->toFullSemverV2()} (tag: {$tagName}, source: {$source})",
            [
                'version' => $version,
                'version_string' => $version->toFullSemverV2(),
                'tag_name' => $tagName,
                'stability' => $version->getStability(),
                'is_prerelease' => in_array($version->getStability(), ['alpha', 'beta', 'RC']),
                'source' => $source,
                'tag_check_passed' => true,
            ]
        );
    }

    /**
     * Calculate version from conventional commits.
     */
    private function calculateFromCommits(Context $context): Version
    {
        $topSeverity = $context->getFact('version.top_severity');

        if ($topSeverity === null) {
            throw new Exception(
                'version.top_severity fact not found. Run AnalyzeConventionalCommitsTask first.'
            );
        }

        $currentVersionStr = $context->getOption('current_version')
            ?? $context->component->getVersion();

        $currentVersion = Version::fromComposerString($currentVersionStr);

        // Bump based on severity
        return $currentVersion->nextVersionObject($topSeverity);
    }

    /**
     * Bump specific version part.
     */
    private function bumpVersionPart(Context $context, string $part): Version
    {
        if (!in_array($part, ['minor', 'patch'])) {
            throw new Exception("Invalid version_part: {$part}. Must be 'minor' or 'patch'.");
        }

        $currentVersionStr = $context->getOption('current_version')
            ?? $context->component->getVersion();

        $currentVersion = Version::fromComposerString($currentVersionStr);

        return $currentVersion->nextVersionObject($part);
    }

    /**
     * Check that tag doesn't already exist locally or remotely.
     *
     * @throws Exception if tag exists
     */
    private function checkTagDoesNotExist(string $componentPath, string $tagName, Version $version): void
    {
        // Check local tag
        if ($this->gitHelper->localTagExists($componentPath, $tagName)) {
            throw new Exception(
                "Tag '{$tagName}' already exists locally. Cannot release version {$version->toFullSemverV2()} again."
            );
        }

        // Check remote tag (if remotes configured)
        if ($this->gitHelper->hasRemotes($componentPath)) {
            if ($this->gitHelper->remoteTagExists($componentPath, $tagName)) {
                $nextSuggestion = $version->nextVersionObject('patch')->toFullSemverV2();
                throw new Exception(
                    "Tag '{$tagName}' already exists on remote. Cannot release version {$version->toFullSemverV2()} again.\n"
                    . "Suggestion: Use --next-version to specify a different version (e.g., --next-version {$nextSuggestion})"
                );
            }
        }
    }
}
