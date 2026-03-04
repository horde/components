<?php

/**
 * Components_Helper_Commit:: helps with collecting for git commit events.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Helper;

use Horde\Components\Output;
use Horde\Components\Wrapper;
use Horde\Components\Helper\Shell;

/**
 * Components_Helper_Commit:: helps with collecting for git commit events.
 *
 * Copyright 2010-2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Commit
{
    /**
     * Modified paths.
     */
    private array $added = [];

    /**
     * Shell executor for running commands
     */
    private Shell $shell;

    /**
     * Constructor.
     *
     * @param Output $_output The output handler.
     * @param array $_options Application options.
     * @param Shell|null $shell Optional Shell instance for dependency injection (for testing)
     */
    public function __construct(
        /**
         * The output handler.
         *
         * @param Output
         */
        private readonly Output $_output,
        private $_options,
        ?Shell $shell = null
    ) {
        // Use injected Shell or create new one with pretend mode from options
        if ($shell !== null) {
            $this->shell = $shell;
        } else {
            $pretend = !empty($_options['pretend']);
            $this->shell = new Shell($_output, $pretend);
        }
    }

    /**
     * Add a path to be included in the commit and record the working directory
     * for this git operation.
     *
     * @param string $path      The path to the modified file.
     * @param string $directory The working directory.
     */
    public function add($path, $directory): void
    {
        if ($path instanceof Wrapper) {
            $path = $path->getLocalPath($directory);
        }
        $this->added[$path] = $directory;
    }

    /**
     * Add all modified files and commit them.
     *
     * @param string $log The commit message.
     */
    public function commit($log): void
    {
        if (empty($this->added)) {
            return;
        }
        foreach ($this->added as $path => $wd) {
            $this->shell->system('git add ' . $path, $wd);
        }
        $this->shell->system('git commit -m "' . $log . '"', $wd);
        $this->added = [];
    }

    /**
     * Tag the component.
     *
     * @param string $tag       Tag name.
     * @param string $message   Tag message.
     * @param string $directory The working directory.
     */
    public function tag($tag, $message, $directory): void
    {
        $this->shell->system(
            'git tag -f -m "' . $message . '" ' . $tag,
            $directory
        );
    }
}
