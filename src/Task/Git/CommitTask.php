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

use Horde\Components\Output;
use Horde\Components\Helper\Git as GitHelper;
use Horde\Components\Task\AbstractTask;
use Horde\Components\Task\Context;
use Horde\Components\Task\Result;
use Exception;

/**
 * Create a git commit.
 *
 * This task creates a git commit with the specified message.
 * It supports pretend mode (dry-run) and validates that the
 * directory is a git repository.
 *
 * Facts consumed:
 * - None (self-contained)
 *
 * Facts emitted:
 * - git.commit_sha: SHA of created commit (if successful)
 *
 * Options:
 * - commit_message: Commit message (required)
 * - files: Array of files to stage, or null to use already-staged (optional)
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class CommitTask extends AbstractTask
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
        return 'Git Commit';
    }

    /**
     * Validate preconditions.
     *
     * Checks:
     * - Component path is a git repository
     * - Commit message is provided
     * - If files specified, they exist
     *
     * @param Context $context Execution context
     * @return array<string> Error messages (empty if valid)
     */
    public function validate(Context $context): array
    {
        $errors = [];

        // Check git repository
        $path = $context->getComponentPath();
        if (!is_dir($path . '/.git')) {
            $errors[] = 'Not a git repository';
        }

        // Check commit message
        $message = $context->getOption('commit_message') ?? '';
        if (empty($message)) {
            $errors[] = 'Commit message is required (option: commit_message)';
        }

        // Check files if specified
        $files = $context->getOption('files') ?? null;
        if (is_array($files)) {
            foreach ($files as $file) {
                $fullPath = $path . '/' . ltrim($file, '/');
                if (!file_exists($fullPath)) {
                    $errors[] = "File does not exist: {$file}";
                }
            }
        }

        return $errors;
    }

    /**
     * Execute the task.
     *
     * Creates a git commit. If files are specified, stages them first.
     * In pretend mode, shows what would be committed.
     *
     * @param Context $context Execution context
     * @return Result
     */
    public function run(Context $context): Result
    {
        $path = $context->getComponentPath();
        $message = $context->getOption('commit_message');
        $files = $context->getOption('files');

        try {
            // Pretend mode
            if ($this->pretend) {
                if (is_array($files)) {
                    return $this->pretend(
                        "Stage files: " . implode(', ', $files) . "\n"
                        . "Create commit: {$message}"
                    );
                }
                return $this->pretend("Create commit: {$message}");
            }

            // Stage files if specified
            if (is_array($files)) {
                foreach ($files as $file) {
                    $this->output->info("Staging: {$file}");
                    $this->gitHelper->add($path . '/' . ltrim($file, '/'));
                }
            }

            // Create commit
            $this->output->info("Creating commit...");
            $this->gitHelper->commit($path, $message);

            // Get commit SHA for fact emission
            $commitSha = $this->gitHelper->getLastCommitSha($path);

            // Emit fact for later tasks
            $context->setFact('git.commit_sha', $commitSha);

            $this->output->ok("Created commit: {$commitSha}");

            return Result::success(
                "Commit created: " . substr($commitSha, 0, 7),
                ['commit_sha' => $commitSha]
            );
        } catch (Exception $e) {
            $this->output->error("Commit failed: {$e->getMessage()}");
            return Result::failure("Commit failed: {$e->getMessage()}");
        }
    }
}
