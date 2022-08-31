<?php
/**
 * Components_Helper_Commit:: helps with collecting for git commit events.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Helper;

use Horde\Components\Output;
use Horde\Components\Wrapper;

/**
 * Components_Helper_Commit:: helps with collecting for git commit events.
 *
 * Copyright 2010-2020 Horde LLC (http://www.horde.org/)
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
    private array $_added = [];

    /**
     * Constructor.
     *
     * @param Output $_output The output handler.
     * @param array $_options Application options.
     */
    public function __construct(
        /**
         * The output handler.
         *
         * @param Output
         */
        private readonly Output $_output,
        private $_options
    ) {
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
        $this->_added[$path] = $directory;
    }

    /**
     * Add all modified files and commit them.
     *
     * @param string $log The commit message.
     */
    public function commit($log): void
    {
        if (empty($this->_added)) {
            return;
        }
        foreach ($this->_added as $path => $wd) {
            $this->systemInDirectory('git add ' . $path, $wd);
        }
        $this->systemInDirectory('git commit -m "' . $log . '"', $wd);
        $this->_added = [];
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
        $this->systemInDirectory(
            'git tag -f -m "' . $message . '" ' . $tag,
            $directory
        );
    }

    /**
     * Run a system call.
     *
     * @param string $call The system call to execute.
     *
     * @return string The command output.
     */
    protected function system($call)
    {
        if (empty($this->_options['pretend'])) {
            //@todo Error handling
            return \system($call);
        } else {
            $this->_output->info(\sprintf('Would run "%s" now.', $call));
        }
    }

    /**
     * Run a system call.
     *
     * @param string $call       The system call to execute.
     * @param string $target_dir Run the command in the provided target path.
     *
     * @return string The command output.
     */
    protected function systemInDirectory($call, $target_dir): string
    {
        $old_dir = null;
        if (empty($this->_options['pretend'])) {
            $old_dir = getcwd();
            chdir($target_dir);
        }
        $result = $this->system($call);
        if (empty($this->_options['pretend'])) {
            chdir($old_dir);
        }
        return $result;
    }
}
