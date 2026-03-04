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

namespace Horde\Components\Helper;

use Horde\Components\Output;
use Horde\Components\Component\Task\SystemCallResult;

/**
 * Universal shell command executor.
 *
 * Provides a unified interface for executing shell commands across all
 * components code (tasks, helpers, CI code). Supports optional pretend mode
 * for dry-run scenarios.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Shell
{
    /**
     * Constructor.
     *
     * @param Output|null $output Output handler (null disables pretend mode messages)
     * @param bool $pretend Enable pretend/dry-run mode (shows commands without executing)
     */
    public function __construct(
        private readonly ?Output $output = null,
        private readonly bool $pretend = false
    ) {}

    /**
     * Execute a command with exec() and return structured result.
     *
     * @param string $command Command to execute
     * @param string|null $workingDir Optional working directory (null = current dir)
     * @return SystemCallResult Result object with output array and exit code
     */
    public function exec(string $command, ?string $workingDir = null): SystemCallResult
    {
        if ($this->pretend) {
            if ($this->output) {
                $dir = $workingDir ? " (in {$workingDir})" : '';
                $this->output->info(\sprintf('Would run: "%s"%s', $command, $dir));
            }
            return new SystemCallResult([], 0);
        }

        $oldDir = null;
        if ($workingDir !== null) {
            $oldDir = getcwd();
            chdir($workingDir);
        }

        \exec($command, $output, $exitCode);

        if ($oldDir !== null) {
            chdir($oldDir);
        }

        return new SystemCallResult($output, $exitCode);
    }

    /**
     * Execute a command with system() and return string output.
     *
     * @param string $command Command to execute
     * @param string|null $workingDir Optional working directory (null = current dir)
     * @return string Command output
     */
    public function system(string $command, ?string $workingDir = null): string
    {
        if ($this->pretend) {
            if ($this->output) {
                $dir = $workingDir ? " (in {$workingDir})" : '';
                $this->output->info(\sprintf('Would run: "%s"%s', $command, $dir));
            }
            return '';
        }

        $oldDir = null;
        if ($workingDir !== null) {
            $oldDir = getcwd();
            chdir($workingDir);
        }

        $result = \system($command);

        if ($oldDir !== null) {
            chdir($oldDir);
        }

        return $result !== false ? $result : '';
    }

    /**
     * Execute a command with shell_exec() and return string output.
     *
     * Useful for simple command output capture where exit code isn't needed.
     *
     * @param string $command Command to execute
     * @param string|null $workingDir Optional working directory (null = current dir)
     * @return string Command output (empty string on failure or in pretend mode)
     */
    public function shellExec(string $command, ?string $workingDir = null): string
    {
        if ($this->pretend) {
            if ($this->output) {
                $dir = $workingDir ? " (in {$workingDir})" : '';
                $this->output->info(\sprintf('Would run: "%s"%s', $command, $dir));
            }
            return '';
        }

        $oldDir = null;
        if ($workingDir !== null) {
            $oldDir = getcwd();
            chdir($workingDir);
        }

        $result = \shell_exec($command);

        if ($oldDir !== null) {
            chdir($oldDir);
        }

        return $result !== null ? $result : '';
    }

    /**
     * Static convenience method for simple exec() calls without pretend mode.
     *
     * Example: Shell::run('composer install', '/path/to/project')
     *
     * @param string $command Command to execute
     * @param string|null $workingDir Optional working directory (null = current dir)
     * @return SystemCallResult Result object with output array and exit code
     */
    public static function run(string $command, ?string $workingDir = null): SystemCallResult
    {
        return (new self())->exec($command, $workingDir);
    }

    /**
     * Static convenience method for simple system() calls without pretend mode.
     *
     * Example: Shell::runSystem('git status', '/path/to/repo')
     *
     * @param string $command Command to execute
     * @param string|null $workingDir Optional working directory (null = current dir)
     * @return string Command output
     */
    public static function runSystem(string $command, ?string $workingDir = null): string
    {
        return (new self())->system($command, $workingDir);
    }

    /**
     * Static convenience method for simple shell_exec() calls without pretend mode.
     *
     * Example: $which = Shell::capture('which composer')
     *
     * @param string $command Command to execute
     * @param string|null $workingDir Optional working directory (null = current dir)
     * @return string Command output
     */
    public static function capture(string $command, ?string $workingDir = null): string
    {
        return (new self())->shellExec($command, $workingDir);
    }

    /**
     * Check if pretend mode is enabled.
     *
     * @return bool True if in pretend/dry-run mode
     */
    public function isPretend(): bool
    {
        return $this->pretend;
    }
}
