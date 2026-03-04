<?php

/**
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Task\Git;

use Horde\Components\Task\AbstractTask;
use Horde\Components\Task\Context;
use Horde\Components\Task\Result;
use Horde\Components\Output;
use Horde\Components\Helper\Git as GitHelper;
use Exception;

/**
 * Push commits and tags to remote repository.
 *
 * Options:
 * - remote: Remote name (default: 'origin')
 * - branch: Branch name (default: current tracking branch)
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class PushTask extends AbstractTask
{
    /**
     * Constructor.
     *
     * @param Output $output Output handler
     * @param GitHelper $gitHelper Git operations helper
     * @param bool $pretend Pretend (dry-run) mode
     */
    public function __construct(
        Output $output,
        private readonly GitHelper $gitHelper,
        bool $pretend = false
    ) {
        parent::__construct($output, $pretend);
    }

    /**
     * Get task name.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Git Push';
    }

    /**
     * Validate preconditions.
     *
     * @param Context $context Execution context
     * @return array<string> Error messages (empty if valid)
     */
    public function validate(Context $context): array
    {
        $errors = [];

        $path = $context->getComponentPath();
        if (!is_dir($path . '/.git')) {
            $errors[] = 'Not a git repository';
        }

        return $errors;
    }

    /**
     * Execute the task.
     *
     * @param Context $context Execution context
     * @return Result
     */
    public function run(Context $context): Result
    {
        $remote = $context->getOption('remote') ?? 'origin';
        $branch = $context->getOption('branch') ?? '';
        $path = $context->getComponentPath();

        if ($this->pretend) {
            return $this->pretend("Push to remote '{$remote}'" . ($branch ? " branch '{$branch}'" : ''));
        }

        try {
            $this->output->info("Pushing to remote '{$remote}'...");

            // Push branch and tags
            $this->gitHelper->push($path, $remote, $branch ?: null, false, true);

            $this->output->ok("Pushed to '{$remote}'");

            return Result::success("Pushed to remote '{$remote}'");

        } catch (Exception $e) {
            $message = "Push failed: {$e->getMessage()}";
            $this->output->error($message);
            return Result::failure($message);
        }
    }
}
