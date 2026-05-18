<?php

/**
 * Copyright 2024-2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @category Horde
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package  Components
 */

declare(strict_types=1);

namespace Horde\Components\Task\Release;

use Horde\Components\Helper\Git as GitHelper;
use Horde\Components\Helper\Version;
use Horde\Components\License;
use Horde\Components\Output;
use Horde\Components\Task\AbstractTask;
use Horde\Components\Task\Context;
use Horde\Components\Task\Result;
use Horde\Components\Wrapper\ChangelogYml;
use Horde\Components\ChangelogEntry;
use Horde\HordeYmlFile\HordeYmlFile;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Exception;

/**
 * Add a changelog entry to changelog.yml.
 *
 * Finds existing changelog.yml in doc/ tree, migrates to doc/changelog.yml
 * if in nested location, adds new version entry. Fails if changelog.yml
 * doesn't exist (runner must handle upgrade/init scenarios).
 *
 * Required Facts:
 * - version.next (Version|string) - Next version object or string
 *
 * Required Options:
 * - release_notes (string) - Formatted release notes
 *
 * Optional Options:
 * - license (string) - Override license (default: from .horde.yml)
 *
 * Emitted Facts:
 * - changelog.updated (bool) - True if entry added
 * - changelog.migrated (bool) - True if file moved to standard location
 * - changelog.file_path (string) - Path to changelog.yml
 * - changelog.version (string) - Version string added
 *
 * @author    Ralf Lang <ralf.lang@ralf-lang.de>
 * @category  Horde
 * @copyright 2024-2026 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Components
 */
class AddChangelogEntryTask extends AbstractTask
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
        return "Ad\1 \2hangelo\1 \2ntry";
    }
    public function run(Context $context): Result
    {
        $componentPath = $context->getComponentPath();
        $docPath = $componentPath . '/doc';

        // Validate doc/ directory exists
        if (!is_dir($docPath)) {
            throw new Exception("Component missing doc/ directory: {$docPath}");
        }

        // Find changelog.yml
        $changelogPath = $this->findChangelogYml($docPath);

        if ($changelogPath === null) {
            throw new Exception(
                "No changelog.yml found in doc/ directory. Cannot add changelog entry.\n"
                . "This component may need to be upgraded from H5 format first.\n\n"
                . "If this component has a CHANGES file, run:\n"
                . "  horde-components upgrade changelog\n\n"
                . "Otherwise, create doc/changelog.yml manually."
            );
        }

        // Migrate to H6 location if needed
        $expectedPath = $componentPath . '/doc/changelog.yml';
        $migrated = false;
        $movedFrom = null;

        if ($changelogPath !== $expectedPath) {
            if (!$this->pretend) {
                $this->gitHelper->moveFile($changelogPath, $expectedPath);
            }
            $migrated = true;
            $movedFrom = $changelogPath;
            $changelogPath = $expectedPath;
        }

        // Resolve version
        $version = $this->resolveVersion($context);

        // Get release notes
        $releaseNotes = $context->getOption('release_notes');

        if ($releaseNotes === null) {
            throw new Exception('release_notes option required');
        }

        // Get license
        $license = $context->getOption('license');

        if ($license === null) {
            // Default from .horde.yml
            $hordeYmlPath = $componentPath . '/.horde.yml';
            if (file_exists($hordeYmlPath)) {
                $hordeYml = new HordeYmlFile($hordeYmlPath);
                $licenseObj = $hordeYml->getLicense();
                $license = $licenseObj->identifier ?? 'LGPL-2.1';
            } else {
                $license = 'LGPL-2.1';
            }
        }

        // Add changelog entry
        if (!$this->pretend) {
            $changelog = new ChangelogYml($componentPath . '/doc');

            $entry = new ChangelogEntry(
                releaseVersion: $version,
                apiVersion: $version,
                license: License::fromIdentifier($license),
                notes: $releaseNotes
            );

            $changelog->addChangelogEntry($entry);
            $changelog->save();
        }

        // Emit facts
        $context->setFact('changelog.updated', true);
        $context->setFact('changelog.migrated', $migrated);
        $context->setFact('changelog.file_path', $expectedPath);
        $context->setFact('changelog.version', $version->toFullSemverV2());

        // Build message
        $action = $this->pretend ? 'Would add' : 'Added';
        $message = "{$action} changelog entry for version {$version->toFullSemverV2()}";

        if ($migrated && !$this->pretend) {
            $message .= " (migrated from {$movedFrom})";
        } elseif ($migrated && $this->pretend) {
            $message .= " (would migrate from {$movedFrom})";
        }

        return Result::success($message, [
            'file' => 'doc/changelog.yml',
            'version' => $version->toFullSemverV2(),
            'migrated' => $migrated,
            'migrated_from' => $movedFrom,
            'entry_added' => !$this->pretend,
        ]);
    }

    /**
     * Find changelog.yml in doc/ directory or subdirectories.
     *
     * @param string $docPath Path to doc/ directory
     * @return string|null Absolute path to changelog.yml, or null if not found
     */
    private function findChangelogYml(string $docPath): ?string
    {
        // Check expected location first (fast path)
        $expectedPath = $docPath . '/changelog.yml';
        if (file_exists($expectedPath)) {
            return $expectedPath;
        }

        // Search subdirectories (e.g., doc/lib/Horde/Component/changelog.yml)
        try {
            $iterator = new RecursiveDirectoryIterator($docPath);
            $recursive = new RecursiveIteratorIterator($iterator);

            foreach ($recursive as $file) {
                if ($file->getFilename() === 'changelog.yml') {
                    return $file->getPathname();
                }
            }
        } catch (Exception $e) {
            // Directory iteration failed
            return null;
        }

        return null;
    }

    /**
     * Resolve version from context (object or string).
     */
    private function resolveVersion(Context $context): Version
    {
        // Try object fact first (from pipeline)
        $versionObj = $context->getFact('version.next');

        if ($versionObj instanceof Version) {
            return $versionObj;
        }

        // Try string fact or option (from CLI)
        $versionStr = $context->getOption('version')
            ?? $context->getFact('version.next_string');

        if ($versionStr !== null && is_string($versionStr)) {
            return Version::fromComposerString($versionStr);
        }

        throw new Exception(
            'version.next fact or --version option required. Run CalculateNextVersionTask first.'
        );
    }
}
