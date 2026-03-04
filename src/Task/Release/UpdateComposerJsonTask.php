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

namespace Horde\Components\Task\Release;

use Horde\Components\Helper\Composer as ComposerHelper;
use Horde\Components\Helper\Git as GitHelper;
use Horde\Components\Helper\Version;
use Horde\Components\Output;
use Horde\Components\Task\AbstractTask;
use Horde\Components\Task\Context;
use Horde\Components\Task\Result;
use Horde\Components\Wrapper\HordeYml;
use Exception;

/**
 * Update composer.json from .horde.yml metadata.
 *
 * Regenerates composer.json with updated version and dependencies from
 * .horde.yml configuration.
 *
 * Required Facts:
 * - version.next (Version|string) - Next version object or string
 *
 * Optional Options:
 * - composer.version (string) - Composer version string (e.g., 'dev-FRAMEWORK_6_0')
 *   For backwards compatibility, also accepts: composer_version
 *
 * Emitted Facts:
 * - composer_json.updated (bool) - True if file updated
 * - composer_json.file_path (string) - Path to composer.json
 * - composer_json.version (string) - Version set in composer.json
 *
 * @author    Ralf Lang <lang@b1-systems.de>
 * @category  Horde
 * @copyright 2024-2026 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Components
 */
class UpdateComposerJsonTask extends AbstractTask
{
    public function __construct(
        Output $output,
        private readonly ComposerHelper $composerHelper,
        private readonly GitHelper $gitHelper,
        bool $pretend = false
    ) {
        parent::__construct($output, $pretend);
    }


    public function getName(): string
    {
        return "Update Composer Json";
    }
    public function run(Context $context): Result
    {
        $componentPath = $context->getComponentPath();

        // Load .horde.yml
        $hordeYml = new HordeYml($componentPath);

        // Determine composer version
        // Try new name first, then fall back to old name for backwards compatibility
        $composerVersion = $context->getOption('composer.version') ?? $context->getOption('composer_version');

        if ($composerVersion === null) {
            // Use branch-based version (e.g., 'dev-FRAMEWORK_6_0')
            $branch = $this->getCurrentBranch($componentPath);
            $composerVersion = $branch ? "dev-{$branch}" : 'dev-main';
        }

        // Generate composer.json
        if (!$this->pretend) {
            $this->composerHelper->generateComposerJson(
                $hordeYml,
                ['composer.version' => $composerVersion]
            );
        }

        // Emit facts
        $context->setFact('composer_json.updated', true);
        $context->setFact('composer_json.file_path', $componentPath . '/composer.json');
        $context->setFact('composer_json.version', $composerVersion);

        $action = $this->pretend ? 'Would update' : 'Updated';
        return Result::success(
            "{$action} composer.json (version: {$composerVersion})",
            [
                'file_path' => $componentPath . '/composer.json',
                'version' => $composerVersion,
            ]
        );
    }

    /**
     * Get current git branch.
     */
    private function getCurrentBranch(string $componentPath): ?string
    {
        return $this->gitHelper->getCurrentBranch($componentPath);
    }
}
