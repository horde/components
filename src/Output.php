<?php
/**
 * Components_Output:: handles output from the components application.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components;

/**
 * Components_Output:: handles output from the components application.
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
class Output
{
    /**
     * Did the user request verbose output?
     */
    private readonly bool $_verbose;

    /**
     * Did the user request quiet output?
     */
    private readonly bool $_quiet;

    /**
     * Did the user request to avoid colored output?
     */
    private readonly bool $_nocolor;

    /**
     * Constructor.
     *
     * @param \Horde_Cli $_cli The CLI handler.
     * @param array     $options The configuration for the current job.
     */
    public function __construct(private readonly \Horde_Cli $_cli, $options)
    {
        $this->_verbose = !empty($options['verbose']);
        $this->_quiet = !empty($options['quiet']);
        $this->_nocolor = !empty($options['nocolor']);
    }

    public function bold($text): void
    {
        if ($this->_nocolor) {
            $this->_cli->writeln($text);
        } else {
            $this->_cli->writeln($this->_cli->bold($text));
        }
    }

    public function blue($text): void
    {
        if ($this->_nocolor) {
            $this->_cli->writeln($text);
        } else {
            $this->_cli->writeln($this->_cli->blue($text));
        }
    }

    public function green($text): void
    {
        if ($this->_nocolor) {
            $this->_cli->writeln($text);
        } else {
            $this->_cli->writeln($this->_cli->green($text));
        }
    }

    public function yellow($text): void
    {
        if ($this->_nocolor) {
            $this->_cli->writeln($text);
        } else {
            $this->_cli->writeln($this->_cli->yellow($text));
        }
    }

    public function ok($text): void
    {
        if ($this->_quiet) {
            return;
        }
        $this->_cli->message(
            $text,
            $this->_getType('cli.success')
        );
    }

    public function warn($text): void
    {
        if ($this->_quiet) {
            return;
        }
        $this->_cli->message(
            $text,
            $this->_getType('cli.warning')
        );
    }

    public function info($text): void
    {
        if ($this->_quiet) {
            return;
        }
        $this->_cli->message(
            $text,
            $this->_getType('cli.message')
        );
    }

    public function fail($text): void
    {
        $this->_cli->fatal($text);
    }

    public function log($status, $text): void
    {
        $this->pear($text);
    }

    public function help($text): void
    {
        $this->plain($text);
    }

    public function plain($text): void
    {
        $this->_cli->writeln($text);
    }

    public function pear($text): void
    {
        if (!$this->_verbose) {
            return;
        }
        $this->_cli->message(
            '-------------------------------------------------',
            $this->_getType('cli.message')
        );
        $this->_cli->message(
            'PEAR output START',
            $this->_getType('cli.message')
        );
        $this->_cli->message(
            '-------------------------------------------------',
            $this->_getType('cli.message')
        );
        $this->_cli->writeln($text);
        $this->_cli->message(
            '-------------------------------------------------',
            $this->_getType('cli.message')
        );
        $this->_cli->message(
            'PEAR output END',
            $this->_getType('cli.message')
        );
        $this->_cli->message(
            '-------------------------------------------------',
            $this->_getType('cli.message')
        );
    }

    /**
     * Modify the type for the --nocolor switch.
     *
     * @param string $type The message to rewrite.
     *
     * @return string The message type that should be used for the output.
     */
    private function _getType($type)
    {
        if ($this->_nocolor) {
            return '';
        } else {
            return $type;
        }
    }

    public function isVerbose(): bool
    {
        return $this->_verbose;
    }

    public function isQuiet(): bool
    {
        return $this->_quiet;
    }
}
