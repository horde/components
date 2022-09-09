<?php

namespace Horde\Components\Release\Task;

use Horde\Components\Helper\Composer;
use Horde\Components\Helper\Git;
use Horde\Components\Release\Tasks as ReleaseTasks;
use Horde\Components\Release\Notes as ReleaseNotes;
use Horde\Components\Output;
use Horde\Components\Config\Application as ConfigApplication;
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
        if (!$this->git->checkoutIsClean($this->getComponent()->getComponentDirectory())) {
            $issues[] = 'The target git checkout is not clean.';
        }

        return $issues;
    }

    public function run(&$options): void
    {
        if ($this->getTasks()->pretend()) {
            $this->getOutput()->info(
                'Would try to transpile down to ...'
            );
        }
        $this->getOutput()->info('Transpiler stage');
    }
}
