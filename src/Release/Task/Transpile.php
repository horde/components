<?php

namespace Horde\Components\Release\Task;

use Horde\Components\Helper\Git;

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
        protected Composer $composer
    )
    {
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
        // Check if the component checkout is clean
        // Check if composer bin or dependency is present
        // Check if git bin is present
        return [

        ];
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
