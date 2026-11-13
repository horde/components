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
use Horde\Components\Helper\ConventionalCommitHelper;
use Horde\Components\Qc\Tasks as QcTasks;
use Horde\Components\Release\BranchReleasePolicy;
use Horde\Components\Release\BranchRoles;
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
        private BranchRoles $branchRoles,
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
            $role = $this->resolveBranchRole();
            $force = !empty($options['force']);
            $this->enforceBranchPolicy(
                $role,
                $this->topCommitSeverity(),
                $component->getState('release'),
                $force
            );
            $this->runGitignoreQc($component, $options);

            // Determine version (auto-calculate or manual)
            if (!empty($options['next_version'])) {
                $this->output->ok('Using manual version: ' . $options['next_version']);
                $context->setOption('next_version', $options['next_version']);
            } else {
                $this->output->info('Auto-calculating version from conventional commits');
                $this->runTask(new AnalyzeConventionalCommitsTask($this->output, $this->gitHelper, $this->pretend), $context);

                // A supported branch only cuts bugfix releases. The policy
                // gate above already refused any feat/BC change here, so the
                // commits are bugfix-level; force a patch bump so a stray
                // minor classification cannot escalate the release.
                if ($role === BranchRoles::ROLE_SUPPORTED) {
                    $this->output->info('Supported branch: forcing a patch release.');
                    $context->setOption('version_part', 'patch');
                }
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
     * Resolve the current branch's release role and refuse branches that
     * have no role at all.
     *
     * Release, supported and unstable branches may all proceed (the policy
     * gate decides what kind of release each permits). A branch with no
     * declared role cannot release anything.
     *
     * @return string The current branch's role (a BranchRoles::ROLE_* value).
     * @throws Exception When the current branch has no release role.
     */
    private function resolveBranchRole(): string
    {
        $currentBranch = $this->gitHelper->getCurrentBranch((string) $this->directory);
        $role = $this->branchRoles->roleOf($currentBranch);

        if ($role === BranchRoles::ROLE_UNKNOWN) {
            throw new Exception(
                "Current branch '{$currentBranch}' has no release role (not the release, "
                . 'a supported, or the unstable branch). Refusing to release.'
            );
        }

        $this->output->info("On {$role} branch: {$currentBranch}");

        return $role;
    }

    /**
     * Apply the branch release policy and decide whether to proceed.
     *
     * Pure decision helper: all inputs are passed in so it is unit-testable
     * without git or filesystem access. On a policy violation it throws,
     * unless $force downgrades the violations to warnings.
     *
     * @param string $role The current branch role.
     * @param string $severity Top Conventional-Commit severity since the
     *                         last tag (subpatch|patch|minor|major).
     * @param string $stability The component's current release stability.
     * @param bool $force Downgrade violations to warnings and proceed.
     * @throws Exception On a policy violation when $force is false.
     */
    private function enforceBranchPolicy(
        string $role,
        string $severity,
        string $stability,
        bool $force
    ): void {
        $decision = (new BranchReleasePolicy())->evaluate($role, $severity, $stability);
        if ($decision->isAllowed()) {
            return;
        }

        foreach ($decision->violations() as $violation) {
            if ($force) {
                $this->output->warn($violation);
            } else {
                $this->output->error($violation);
            }
        }

        if ($force) {
            $this->output->warn('Proceeding despite branch policy violations (--force).');
            return;
        }

        throw new Exception('Release refused by branch policy. Re-run with --force to override.');
    }

    /**
     * Classify the top Conventional-Commit severity since the last tag.
     *
     * Uses the reader-based classifier (ConventionalCommitHelper) rather
     * than AnalyzeConventionalCommitsTask, because the latter throws when
     * there are no commits since the last tag and only runs on the
     * auto-version path. Returns 'patch' (the most permissive bugfix level)
     * when the history cannot be classified.
     */
    private function topCommitSeverity(): string
    {
        $previousCwd = getcwd();
        $componentPath = (string) $this->directory;
        $changed = is_string($previousCwd) && $previousCwd !== $componentPath
            && @chdir($componentPath);
        try {
            return (new ConventionalCommitHelper($this->gitHelper))
                ->commitReader->getTopSeverity();
        } catch (\Throwable $e) {
            $this->output->warn('Could not classify commits for branch policy: ' . $e->getMessage());
            return 'patch';
        } finally {
            if ($changed && is_string($previousCwd)) {
                @chdir($previousCwd);
            }
        }
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
