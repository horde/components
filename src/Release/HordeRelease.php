<?php

namespace Horde\Components\Release;

use Horde\Components\Helper\Composer as ComposerHelper;
use Horde\Components\Helper\Git as GitHelper;
use Horde\Components\Helper\GitHubReleaseCreator;
use Horde\Components\Helper\Shell as ShellHelper;
use Horde\Components\Component\ComponentDirectory;
use Horde\Components\Exception;
use Horde\Components\Output;
use Horde\Components\Component;
use Horde\Components\Qc\Tasks as QcTasks;
use Horde\Components\Task\Context;
use Horde\Components\Task\Result;
use Horde\Components\Task\Version\AnalyzeConventionalCommitsTask;
use Horde\Components\Task\Version\CalculateNextVersionTask;
use Horde\Components\Task\Release\UpdateHordeYmlVersionTask;
use Horde\Components\Task\Release\AddChangelogEntryTask;
use Horde\Components\Task\Release\UpdateComposerJsonTask;
use Horde\Components\Task\Release\UpdateApplicationSentinelTask;
use Horde\Components\Task\Release\CleanupLegacyFilesTask;
use Horde\Components\Task\Release\RefreshCiBootstrapTask;
use Horde\Components\Task\Composer\ComposerValidateTask;
use Horde\Components\Task\Git\CommitTask;
use Horde\Components\Task\Git\TagTask;
use Horde\Components\Task\Git\PushTask;
use Horde\Components\Task\GitHub\CreateGitHubReleaseTask;
use Horde\Components\Task\Build\BuildPharTask;
use Horde\Components\Task\GitHub\UploadGitHubAssetTask;
use ReflectionClass;

/**
 * Horde 6 Release pipeline using modernized task architecture
 *
 * Copyright 2020-2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class HordeRelease
{
    public function __construct(
        private ComposerHelper $composerHelper,
        private GitHelper $gitHelper,
        private ComponentDirectory $directory,
        private Output $output,
        private GitHubReleaseCreator $githubReleaseCreator,
        private QcTasks $qcTasks,
        private ShellHelper $shellHelper,
        private bool $pretend = false
    ) {}

    /**
     * Run the release flow using modernized tasks.
     *
     * @param Component $component The component to release
     * @param array $options Options array
     */
    public function run(Component $component, array $options = [])
    {
        $this->output->warn('H6 Release Pipeline');

        if ($this->pretend) {
            $this->output->warn('[PRETEND MODE] No changes will be made');
        }

        // Create context for task execution
        $context = new Context($component, $options);

        try {
            // Pre-flight checks
            $this->validateReleaseBranch();
            $this->runGitignoreQc($component, $options);

            // Determine version (auto-calculate or manual)
            if (!empty($options['next_version'])) {
                $this->output->ok('Using manual version: ' . $options['next_version']);
                $context->setOption('next_version', $options['next_version']);
            } else {
                $this->output->info('Auto-calculating version from conventional commits');
                $this->runTask(new AnalyzeConventionalCommitsTask($this->output, $this->gitHelper, $this->pretend), $context);
            }

            // Calculate/normalize version with reality check
            $this->runTask(new CalculateNextVersionTask($this->output, $this->gitHelper, $this->pretend), $context);

            // Get calculated version for display
            $nextVersion = $context->getFact('version.next');
            $this->output->ok('Releasing version: ' . $nextVersion->toFullSemverV2());

            // Update metadata files
            $this->runTask(new UpdateHordeYmlVersionTask($this->output, $this->pretend), $context);

            // Prepare release notes for changelog
            $releaseNotes = $this->formatReleaseNotes($context, $options);
            $context->setOption('release_notes', $releaseNotes);

            $this->runTask(new AddChangelogEntryTask($this->output, $this->gitHelper, $this->pretend), $context);
            $this->runTask(new UpdateComposerJsonTask($this->output, $this->composerHelper, $this->gitHelper, $this->pretend), $context);
            $this->runTask(new UpdateApplicationSentinelTask($this->output, $this->pretend), $context);

            // Clean up legacy files
            $this->runTask(new CleanupLegacyFilesTask($this->output, $this->gitHelper, $this->pretend), $context);

            // Refresh CI bootstrap files if their template version is outdated.
            // The bootstrap script and workflow embed a generation timestamp,
            // so they always textually diff against the templates; the
            // embedded `# Template version:` marker is the only meaningful
            // signal of whether they need rewriting.
            $this->runTask(new RefreshCiBootstrapTask($this->output, null, $this->pretend), $context);

            // Validate composer.json
            $this->runTask(new ComposerValidateTask($this->output, $this->shellHelper, $this->pretend), $context);

            // Git operations: commit, tag, push
            $this->prepareCommitMessage($context);
            $this->runTask(new CommitTask($this->output, $this->gitHelper, $this->pretend), $context);
            $this->runTask(new TagTask($this->output, $this->gitHelper, $this->pretend), $context);
            $this->runTask(new PushTask($this->output, $this->gitHelper, $this->pretend), $context);

            // GitHub release
            $this->runTask(new CreateGitHubReleaseTask($this->output, $this->githubReleaseCreator, $this->gitHelper, $this->pretend), $context);

            // Build and upload PHAR (if applicable)
            $buildTask = new BuildPharTask($this->output, $this->shellHelper, $this->pretend);
            if (!$buildTask->shouldSkip($context)) {
                $this->runTask($buildTask, $context);

                $uploadTask = new UploadGitHubAssetTask($this->output, $this->githubReleaseCreator, $this->pretend);
                if (!$uploadTask->shouldSkip($context)) {
                    $this->runTask($uploadTask, $context);
                }
            }

            // Success!
            if ($this->pretend) {
                $this->output->ok('[PRETEND MODE] Release pipeline completed successfully (no changes made)');
            } else {
                $this->output->ok('Release ' . $nextVersion->toFullSemverV2() . ' completed successfully!');
            }
        } catch (\Exception $e) {
            $this->output->fail('Release failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Validate that we're on the correct release branch.
     *
     * @throws Exception if not on release branch
     */
    private function validateReleaseBranch(): void
    {
        $currentBranch = $this->gitHelper->getCurrentBranch((string) $this->directory);

        if ($currentBranch !== 'FRAMEWORK_6_0') {
            throw new Exception(
                'Not on release branch. Please switch to FRAMEWORK_6_0 branch before running release.'
            );
        }

        $this->output->info('On release branch: ' . $currentBranch);
    }

    /**
     * Run gitignore QC task with auto-fix enabled.
     *
     * @param Component $component The component
     * @param array $options Options array
     */
    private function runGitignoreQc(Component $component, array $options): void
    {
        $this->output->info('Running pre-release QC checks...');

        $gitignoreTask = $this->qcTasks->getTask('gitignore', $component);

        // Enable auto-fix for gitignore during release
        $qcOptions = array_merge($options, ['fix_qc_issues' => true]);

        $errors = $gitignoreTask->validate($qcOptions);
        if (empty($errors)) {
            $gitignoreErrors = $gitignoreTask->run($qcOptions);
            if ($gitignoreErrors > 0) {
                $this->output->warn('Gitignore check found issues but they were auto-fixed');
            } else {
                $this->output->ok('Gitignore check passed');
            }
        } else {
            $this->output->warn('Gitignore task validation failed: ' . implode(', ', $errors));
        }
    }

    /**
     * Format release notes from commits or manual version.
     *
     * @param Context $context Task context
     * @param array $options CLI options
     * @return string Formatted release notes
     */
    private function formatReleaseNotes(Context $context, array $options): string
    {
        $nextVersion = $context->getFact('version.next');

        // If we have formatted notes from commits, use them
        if ($context->hasFact('notes.formatted')) {
            return $context->getFact('notes.formatted');
        }

        // Manual version - use simple message
        return "Release version " . $nextVersion->toFullSemverV2();
    }

    /**
     * Prepare commit message for release commit.
     *
     * @param Context $context Task context
     */
    private function prepareCommitMessage(Context $context): void
    {
        $nextVersion = $context->getFact('version.next');
        $releaseNotes = $context->getOption('release_notes');
        $tagName = $context->getFact('version.tag_name');

        // Conventional Commit format: chore(release): bump version to X.Y.Z
        $commitMessage = sprintf(
            "chore(release): bump version to %s\n\nRelease version %s\n\n%s",
            $nextVersion->toFullSemverV2(),
            $nextVersion->toFullSemverV2(),
            $releaseNotes
        );

        // Baseline files always touched by the release tasks above. Earlier
        // tasks (RefreshCiBootstrapTask, future contributors) may have
        // already appended their own paths to `files` via Context — we MUST
        // preserve those entries, otherwise the bootstrap refresh, deleted
        // sibling workflows, and platform-block updates land on disk but
        // never make it into the release commit. The leftover working tree
        // after a release run is the symptom of clobbering this list.
        $existingFiles = $context->getOption('files');
        $filesToCommit = is_array($existingFiles) ? $existingFiles : [];

        foreach ([
            '.gitignore',
            '.horde.yml',
            'doc/changelog.yml',
            'composer.json',
        ] as $baseline) {
            if (!in_array($baseline, $filesToCommit, true)) {
                $filesToCommit[] = $baseline;
            }
        }

        // Add application sentinel files if they exist
        $componentPath = $context->getComponentPath();
        if (
            file_exists($componentPath . '/lib/Application.php')
            && !in_array('lib/Application.php', $filesToCommit, true)
        ) {
            $filesToCommit[] = 'lib/Application.php';
        }

        $context->setOption('commit_message', $commitMessage);
        $context->setOption('files', $filesToCommit);
        $context->setOption('tag', $tagName);
        $context->setOption('message', $commitMessage);
    }

    /**
     * Run a task and handle its result.
     *
     * @param object $task The task to run
     * @param Context $context Task context
     * @throws Exception if task fails
     */
    private function runTask(object $task, Context $context): void
    {
        $taskName = (new ReflectionClass($task))->getShortName();

        // Check if task should be skipped
        if (method_exists($task, 'shouldSkip') && $task->shouldSkip($context)) {
            $this->output->info("Skipping {$taskName} (preconditions not met)");
            return;
        }

        $this->output->plain('');
        $this->output->info("Running {$taskName}...");

        $result = $task->run($context);

        if ($result->isSuccess()) {
            $this->output->ok('  ✓ ' . $result->message);
        } elseif ($result->isSkipped()) {
            $this->output->info('  ⊘ ' . $result->message);
        } else {
            $this->output->fail('  ✗ ' . $result->message);
            throw new Exception("Task {$taskName} failed: " . $result->message);
        }
    }
}
