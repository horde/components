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
use Horde\Components\Output;
use Horde\Components\Task\AbstractTask;
use Horde\Components\Task\Context;
use Horde\Components\Task\Result;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Exception;

/**
 * Delete legacy H5 files that are no longer needed in H6.
 *
 * Removes package.xml, CHANGES, and .travis.yml files. This task assumes
 * CHANGES has already been migrated to changelog.yml if needed. The runner
 * is responsible for migration before calling this task.
 *
 * Files Removed:
 * - package.xml (PEAR package descriptor, replaced by composer.json)
 * - doc/CHANGES (old changelog format, replaced by changelog.yml)
 * - .travis.yml (Travis CI configuration, replaced by GitHub Actions)
 *
 * Emitted Facts:
 * - files.deleted (array) - List of deleted files
 * - files.deleted_count (int) - Number of files deleted
 * - legacy_files.cleaned (bool) - True if cleanup completed
 *
 * @author    Ralf Lang <ralf.lang@ralf-lang.de>
 * @category  Horde
 * @copyright 2024-2026 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Components
 */
class CleanupLegacyFilesTask extends AbstractTask
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
        return "Cleanu\1 \2egac\1 \2iles";
    }
    public function run(Context $context): Result
    {
        $componentPath = $context->getComponentPath();
        $deleted = [];

        // Delete package.xml if exists
        $packageXml = $componentPath . '/package.xml';
        if (file_exists($packageXml)) {
            if (!$this->pretend) {
                $this->gitHelper->deleteFile($packageXml);
            }
            $deleted[] = 'package.xml';
        }

        // Delete .travis.yml if exists
        $travisYml = $componentPath . '/.travis.yml';
        if (file_exists($travisYml)) {
            if (!$this->pretend) {
                $this->gitHelper->deleteFile($travisYml);
            }
            $deleted[] = '.travis.yml';
        }

        // Search for CHANGES file (may be nested in doc/ subdirectories)
        $changesFile = $this->findChangesFile($componentPath . '/doc');
        if ($changesFile !== null) {
            if (!$this->pretend) {
                $this->gitHelper->deleteFile($changesFile);
            }
            $deleted[] = str_replace($componentPath . '/', '', $changesFile);
        }

        // Emit facts
        $context->setFact('files.deleted', $deleted);
        $context->setFact('files.deleted_count', count($deleted));
        $context->setFact('legacy_files.cleaned', true);

        if (empty($deleted)) {
            return Result::success('No legacy files to clean up', ['deleted' => []]);
        }

        $action = $this->pretend ? 'Would delete' : 'Deleted';
        $filesList = implode(', ', $deleted);

        return Result::success(
            "{$action} legacy files: {$filesList}",
            [
                'deleted' => $deleted,
                'count' => count($deleted),
            ]
        );
    }

    /**
     * Find CHANGES file in doc/ directory or subdirectories.
     *
     * @param string $docPath Path to doc/ directory
     * @return string|null Absolute path to CHANGES file, or null if not found
     */
    private function findChangesFile(string $docPath): ?string
    {
        if (!is_dir($docPath)) {
            return null;
        }

        // Check expected location first
        $expectedPath = $docPath . '/CHANGES';
        if (file_exists($expectedPath)) {
            return $expectedPath;
        }

        // Search subdirectories (e.g., doc/lib/Horde/Component/CHANGES)
        try {
            $iterator = new RecursiveDirectoryIterator($docPath);
            $recursive = new RecursiveIteratorIterator($iterator);

            foreach ($recursive as $file) {
                if ($file->getFilename() === 'CHANGES') {
                    return $file->getPathname();
                }
            }
        } catch (Exception $e) {
            // Directory iteration failed
            return null;
        }

        return null;
    }
}
