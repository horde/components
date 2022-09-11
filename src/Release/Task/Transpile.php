<?php

namespace Horde\Components\Release\Task;

use Horde\Components\Helper\Composer;
use Horde\Components\Helper\Git;
use Horde\Components\Release\Tasks as ReleaseTasks;
use Horde\Components\Release\Notes as ReleaseNotes;
use Horde\Components\Output;
use Horde\Components\Config\Application as ConfigApplication;
use Horde\Components\Helper\Version;
use RuntimeException;

/**
 * Transpile a module to a specific version
 *
 * The task assumes that the module is already cloned.
 * This can be a developer checkout or a clone to a clean room.
 * The checkout must be fully committed or stashed.
 * The pipeline around this task has to ensure this.
 *
 * The task needs a parameter to guide which version to transpile down to.
 *
 * The task needs a parameter to guide on which tag or branch to base the work on.
 * By default it will assume to work on the checked-out state.
 *
 * The task will switch to a working branch - by default transpiler-ephemeral
 * The task will install the transpiler and the dev dependencies via composer.
 *
 *
 */
class Transpile extends Base
{
    public function __construct(
        ReleaseTasks $_tasks,
        ReleaseNotes $_notes,
        Output $_output,
        protected Git $git,
        protected Composer $composer,
        private readonly ConfigApplication $configApplication
    ) {
        parent::__construct($_tasks, $_notes, $_output);
    }
    /**
     * Validate the preconditions required for this release task.
     *
     * @param array $options Additional options.
     *
     * @return array An empty array if all preconditions are met and a list of
     *               error messages otherwise.
     */
    public function preValidate($options)
    {
        $issues = [];
        // Ensure we have a target version among the options
        if (empty($options['target_platform']) || !is_string($options['target_platform'])) {
            $issues[] = 'No target platform version was provided.';
        }
        // Check if we have a transpiler template for the target platform
        $templateDir = $this->configApplication->getTemplateDirectory();
        if (empty($templateDir)) {
            $issues[] = 'No data directory configured';
        }

        $transpilerFile = $this->configApplication->getTemplateDirectory() . '/rector/transpile-' . $options['target_platform'] . '.php';
        if (!file_exists($transpilerFile) || !is_readable($transpilerFile)) {
            $issues[] = 'Could not read transpiler config file ' . $transpilerFile;
        }
        // Check if composer bin is present
        try {
            $this->composer->detectComposerBin();
        } catch (RuntimeException $e) {
            $issues[] = $e->getMessage();
        }
        // Check if git bin is present
        try {
            $this->git->detectGitBin();
        } catch (RuntimeException $e) {
            $issues[] = $e->getMessage();
        }
        // Check if the component checkout is clean
        $componentDir = $this->getComponent()->getComponentDirectory();
        if (!$this->git->checkoutIsClean($componentDir)) {
            $issues[] = 'The target git checkout is not clean.';
        }
        // TODO: Make ephemeral branch configurable.
        $ephemeralBranch = 'transpiler-ephemeral';
        if ($this->git->localBranchExists($componentDir, $ephemeralBranch)) {
            $issues[] = 'The target git checkout is not clean.';
        }

        // TODO: Check for existing output branch or tag. Depending on further options fail.

        return $issues;
    }

    public function run(&$options): void
    {
        if ($this->getTasks()->pretend()) {
            $this->getOutput()->info(
                'Would try to transpile down to ...'
            );
        }
        $componentDir = $this->getComponent()->getComponentDirectory();
        $currentRef = $this->git->getCurrentRefName($componentDir);
        // Get current ref or consume from options
        $refType = 'auto';
        if (!empty($options['from_version'])) {
            $from_version = $options['from_version'];
            $offset = strpos($from_version, ':');
            if ($offset === false) {
                $sourceRef = $from_version;
            } else {
                $sourceRef = substr($from_version, $offset + 1, );
                $refType = substr($from_version, 0, $offset);
            }
        }
        $sourceRef = $sourceRef ?? $currentRef;
        if ($refType != 'auto') {
            // TBD
        }
        elseif ($this->git->localBranchExists($componentDir, $sourceRef)) {
            $refType = 'branch';
        } elseif ($this->git->localTagExists($componentDir, $sourceRef)) {
            $refType = 'tag';
        }
        $this->getOutput()->info('We are transpiling: ' . $refType);
        // TODO: Make ephemeral branch configurable.
        $ephemeralBranch = 'transpiler-ephemeral';
        if ($this->git->localBranchExists($componentDir, $ephemeralBranch)) {
            $this->getOutput()->error('Ephemeral Transpiler Branch already exists: ' . $ephemeralBranch);
            return;
        }
        $this->getOutput()->info('Transpiler stage');
        $this->git->branchFromLocal($componentDir, $ephemeralBranch, $sourceRef);
        $this->git->checkoutBranch($componentDir, $ephemeralBranch);
        $this->getOutput()->info('Checked out ephemeral branch: ' . $ephemeralBranch);
        $this->composer->setDependency($componentDir, 'rector/rector', '*', 'dev');
        $this->composer->update($componentDir);
        $this->getOutput()->info('Installed Rector Transpiler');
        $transpilerFile = $this->configApplication->getTemplateDirectory() . '/rector/transpile-' . $options['target_platform'] . '.php';
        copy($transpilerFile, $componentDir . '/rector-transpile.php');
        $transpileCmd = sprintf('%s/vendor/bin/rector -c %s --clear-cache process', $componentDir, 'rector-transpile.php');
        $this->execInDirectory($transpileCmd, $componentDir);
        // checkout composer.json version from before
        $checkoutCmd = $this->git->detectGitBin() . ' checkout composer.json';
        $this->execInDirectory($checkoutCmd, $componentDir);
        // Configure target php version
        $this->composer->setDependency($componentDir, 'php', '^' . $options['target_platform']);
        // delete any leftover composer.lock
        $deleteComposerLock = $this->git->detectGitBin() . ' rm composer.lock --force';
        $this->execInDirectory($deleteComposerLock, $componentDir);
        // delete vendor dir
        $this->execInDirectory('rm -rf ./vendor', $componentDir);
        unlink($componentDir . '/rector-transpile.php');
        // check in changes
        $addChangesCmd = $this->git->detectGitBin() . ' add src/ test/ composer.json';
        $this->execInDirectory($addChangesCmd, $componentDir);
        $this->git->commit($componentDir, 'Commit transpiled version for php ' . $options['target_platform']);
        $this->getOutput()->info('Created transpiled version');
        // if target is a branch
        if ($refType == 'branch') {
            // Check if exists
            $targetRef = $targetBranch = $sourceRef . '-php' . $options['target_platform'];
            if ($this->git->localBranchExists($componentDir, $targetBranch)) {
                if (!empty($options['delete_local_target'])) {
                    $this->git->deleteLocalBranch($componentDir, $targetBranch);
                } else {
                    $this->getOutput()->fail('The intended target branch already exists. ' . $targetBranch);
                    return;
                }
            }
            $this->git->branchFromLocal($componentDir, $targetBranch, $ephemeralBranch);
            $this->getOutput()->info('Created local branch ' . $targetBranch);
        }
        if ($refType == 'tag' || $refType == 'auto') {
            // Check out if the ref reads like a version number
            $platformVersion = Version::fromComposerString($options['target_platform']);
            $platformInteger = $platformVersion->getMajor() * 10000 + $platformVersion->getMinor() * 100;
            $version = Version::fromComposerString($sourceRef);
            $targetRef = $tag = 'v' . $version->setSubPatch($platformInteger)->normalizeComposerVersion();
            if ($this->git->localTagExists($componentDir, $tag) && empty($options['delete_local_target'])) {
                $this->getOutput()->fail('The intended target tag already exists. ' . $tag);
                return;
            }
            $this->git->tag($componentDir, $tag, 'Transpiled release for ' .  $options['target_platform'], true);
        }
        // Check if we should push
        if (!empty($options['push_remote'])) {
            $remote = $options['push_remote'];
            $force = (bool) $options['force_push'] ?? false;
            $this->git->push($componentDir, $remote, $targetRef, $force);
            $this->getOutput()->info('Pushed ' . $targetRef . ' to remote ' . $remote );
        }

        // checkout original position
        $this->git->checkoutBranch($componentDir, $sourceRef);
        // delete ephemeral branch
        $this->git->deleteLocalBranch($componentDir, $ephemeralBranch);
    }
}
