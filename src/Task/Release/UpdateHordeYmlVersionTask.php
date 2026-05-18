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
use Horde\Components\Wrapper\HordeYml;
use Exception;

/**
 * Update .horde.yml with new release version and API version.
 *
 * Required Facts:
 * - version.next (Version|string) - Next version object or string
 *
 * Optional Options:
 * - api_version (string) - Override API version (default: same as release version)
 *
 * Emitted Facts:
 * - horde_yml.updated (bool) - True if file updated
 * - horde_yml.file_path (string) - Path to .horde.yml
 * - horde_yml.version (string) - Release version set
 * - horde_yml.api_version (string) - API version set
 *
 * @author    Ralf Lang <ralf.lang@ralf-lang.de>
 * @category  Horde
 * @copyright 2024-2026 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Components
 */
class UpdateHordeYmlVersionTask extends AbstractTask
{
    public function __construct(
        Output $output,
        bool $pretend = false
    ) {
        parent::__construct($output, $pretend);
    }


    public function getName(): string
    {
        return "Updat\1 \2ord\1 \2m\1 \2ersion";
    }
    public function run(Context $context): Result
    {
        $componentPath = $context->getComponentPath();

        // Resolve version (accept object or string)
        $version = $this->resolveVersion($context);

        // Get API version (default: same as release version)
        $apiVersion = $context->getOption('api_version')
            ?? $version->toFullSemverV2();

        // Load .horde.yml
        $hordeYml = new HordeYml($componentPath);

        // Update version
        if (!$this->pretend) {
            $hordeYml->setReleaseVersionAndStability($version);
            $hordeYml->save();
        }

        // Emit facts
        $context->setFact('horde_yml.updated', true);
        $context->setFact('horde_yml.file_path', $componentPath . '/.horde.yml');
        $context->setFact('horde_yml.version', $version->toFullSemverV2());
        $context->setFact('horde_yml.api_version', $apiVersion);

        $action = $this->pretend ? 'Would update' : 'Updated';
        return Result::success(
            "{$action} .horde.yml to version {$version->toFullSemverV2()}",
            [
                'file_path' => $componentPath . '/.horde.yml',
                'version' => $version->toFullSemverV2(),
                'api_version' => $apiVersion,
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
