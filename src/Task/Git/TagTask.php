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
 * Create a git tag.
 *
 * Options:
 * - tag: Tag name (required)
 * - message: Tag message (required)
 * - force: Force tag creation (default: false)
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class TagTask extends AbstractTask
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
        return 'Git Tag';
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

        if (empty($context->getOption('tag'))) {
            $errors[] = 'Tag name is required';
        }

        if (empty($context->getOption('message'))) {
            $errors[] = 'Tag message is required';
        }

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
        $tag = $context->getOption('tag');
        $message = $context->getOption('message');
        $force = $context->getOption('force') ?? false;
        $path = $context->getComponentPath();

        if ($this->pretend) {
            return $this->pretend("Create tag '{$tag}' with message '{$message}'");
        }

        try {
            $this->output->info("Creating tag '{$tag}'...");

            $this->gitHelper->tag($path, $tag, $message, $force);

            // Store tag name as a fact for other tasks
            $context->setFact('git.tag', $tag);

            return Result::success("Tag '{$tag}' created", ['tag' => $tag]);

        } catch (Exception $e) {
            $message = "Tag creation failed: {$e->getMessage()}";
            $this->output->error($message);
            return Result::failure($message);
        }
    }
}
