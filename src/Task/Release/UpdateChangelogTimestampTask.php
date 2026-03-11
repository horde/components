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

namespace Horde\Components\Task\Release;

use Horde\Components\Task\AbstractTask;
use Horde\Components\Task\Context;
use Horde\Components\Task\Result;
use Horde\Components\Output;
use Horde\Components\Helper\ChangeLog as ChangeLogHelper;
use Horde\Components\Exception;

/**
 * Update changelog.yml timestamp for the current release version.
 *
 * This task sets the release date in changelog.yml to today's date.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class UpdateChangelogTimestampTask extends AbstractTask
{
    /**
     * Constructor.
     *
     * @param Output $output Output handler
     * @param bool $pretend Pretend (dry-run) mode
     */
    public function __construct(
        Output $output,
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
        return 'Update Changelog Timestamp';
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

        $component = $context->component;

        // Check if changelog.yml exists
        try {
            if (!$component->getWrapper('ChangelogYml')->exists()) {
                $errors[] = 'The component lacks a changelog.yml!';
            }
        } catch (\Exception $e) {
            $errors[] = 'Failed to check changelog.yml: ' . $e->getMessage();
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
        $component = $context->component;

        try {
            // Get the changelog helper
            $helper = $component->getFactory()->createChangelog($component);

            // Get the changelog file path
            $changelogFile = $helper->changelogFileExists();

            if ($this->pretend) {
                return $this->pretend("Would timestamp {$changelogFile} now");
            }

            // Update the timestamp
            $helper->timestamp();

            // Save the changelog
            $component->getWrapper('ChangelogYml')->save();

            $message = "Marked {$changelogFile} with current timestamp.";
            $this->output->ok($message);

            // Store the changelog file path as a fact
            $context->setFact('changelog.file', $changelogFile);
            $context->setFact('changelog.timestamped', true);

            return Result::success($message, [
                'file' => $changelogFile,
                'date' => gmdate('Y-m-d'),
            ]);

        } catch (Exception $e) {
            $message = "Timestamp update failed: {$e->getMessage()}";
            $this->output->error($message);
            return Result::failure($message);
        } catch (\Exception $e) {
            $message = "Timestamp update failed: {$e->getMessage()}";
            $this->output->error($message);
            return Result::failure($message);
        }
    }
}
