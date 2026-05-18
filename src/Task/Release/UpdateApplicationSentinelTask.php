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

use Horde\Components\Helper\Version;
use Horde\Components\Output;
use Horde\Components\Task\AbstractTask;
use Horde\Components\Task\Context;
use Horde\Components\Task\Result;
use Horde\Components\Wrapper\ApplicationPhp;
use Exception;

/**
 * Update version sentinel in Application.php for Horde applications.
 *
 * Updates the version constant in lib/Application.php and/or src/Application.php
 * for Horde applications. Handles both PSR-0 and PSR-4 locations gracefully.
 *
 * Required Facts:
 * - version.next (Version|string) - Next version object or string
 *
 * Emitted Facts:
 * - application.sentinel_updated (bool) - True if any sentinel updated
 * - application.files_updated (array) - List of files updated
 * - application.version (string) - Version set
 *
 * @author    Ralf Lang <ralf.lang@ralf-lang.de>
 * @category  Horde
 * @copyright 2024-2026 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Components
 */
class UpdateApplicationSentinelTask extends AbstractTask
{
    public function __construct(
        Output $output,
        bool $pretend = false
    ) {
        parent::__construct($output, $pretend);
    }

    public function shouldSkip(Context $context): bool
    {
        // Skip if this is a library (no Application.php in either location)
        $componentPath = $context->getComponentPath();
        $legacyPath = $componentPath . '/lib/Application.php';
        $modernPath = $componentPath . '/src/Application.php';

        return !file_exists($legacyPath) && !file_exists($modernPath);
    }


    public function getName(): string
    {
        return "Updat\1 \2pplicatio\1 \2entinel";
    }
    public function run(Context $context): Result
    {
        $componentPath = $context->getComponentPath();

        // Resolve version
        $version = $this->resolveVersion($context);
        $versionString = $version->toFullSemverV2();

        $legacyPath = $componentPath . '/lib/Application.php';
        $modernPath = $componentPath . '/src/Application.php';

        $updatedFiles = [];

        // Update lib/Application.php if exists (PSR-0 or mid-migration)
        if (file_exists($legacyPath)) {
            if (!$this->pretend) {
                $applicationPhp = new ApplicationPhp($componentPath, 'lib');
                $applicationPhp->setVersion($versionString);
                $applicationPhp->save();
            }
            $updatedFiles[] = 'lib/Application.php';
        }

        // Update src/Application.php if exists (PSR-4 or mid-migration)
        if (file_exists($modernPath)) {
            if (!$this->pretend) {
                $applicationPhp = new ApplicationPhp($componentPath, 'src');
                $applicationPhp->setVersion($versionString);
                $applicationPhp->save();
            }
            $updatedFiles[] = 'src/Application.php';
        }

        // This should never happen due to shouldSkip(), but defensive programming
        if (empty($updatedFiles)) {
            throw new Exception('No Application.php found in lib/ or src/');
        }

        // Emit facts
        $context->setFact('application.sentinel_updated', true);
        $context->setFact('application.files_updated', $updatedFiles);
        $context->setFact('application.version', $versionString);

        $action = $this->pretend ? 'Would update' : 'Updated';
        $filesList = implode(', ', $updatedFiles);

        return Result::success(
            "{$action} application sentinel: {$filesList} to version {$versionString}",
            [
                'files' => $updatedFiles,
                'version' => $versionString,
            ]
        );
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
