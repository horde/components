<?php

/**
 * Copyright 2017-2026 Horde LLC (http://www.horde.org/)
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

namespace Horde\Components\Output\Presenter;

use Horde\Components\Output\Presenter;
use Horde_Cli;

/**
 * Classic presentation style using Horde_Cli formatting.
 *
 * This presenter maintains backward compatibility with the original
 * horde/components output format:
 *
 * Output format:
 *   [   OK   ] Success message (green background, black text)
 *   [  WARN  ] Warning message (brown background, black text)
 *   [  INFO  ] Info message (blue background, lightgray text)
 *   [ ERROR! ] Error message (red background, lightgray text)
 *
 * This is the default presenter and ensures existing scripts and CI
 * pipelines continue to work without modification.
 *
 * Note: This class accepts both Horde_Cli and Horde\Cli\Cli types,
 * which are equivalent. The underscore notation is used for
 * consistency with the Horde framework.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Classic implements Presenter
{
    /**
     * Constructor.
     *
     * @param Horde_Cli $cli The Horde CLI instance
     * @param bool $nocolor Disable color formatting
     */
    public function __construct(
        private readonly Horde_Cli $cli,
        private readonly bool $nocolor = false
    ) {}

    /**
     * Format and output a success message.
     *
     * Displays: [   OK   ] message (green background, black text)
     *
     * @param string $message The message text
     */
    public function ok(string $message): void
    {
        $this->cli->message($message, $this->getType('cli.success'));
    }

    /**
     * Format and output a warning message.
     *
     * Displays: [  WARN  ] message (brown background, black text)
     *
     * @param string $message The message text
     */
    public function warn(string $message): void
    {
        $this->cli->message($message, $this->getType('cli.warning'));
    }

    /**
     * Format and output an informational message.
     *
     * Displays: [  INFO  ] message (blue background, lightgray text)
     *
     * @param string $message The message text
     */
    public function info(string $message): void
    {
        $this->cli->message($message, $this->getType('cli.message'));
    }

    /**
     * Format and output an error message.
     *
     * Displays: [ ERROR! ] message (red background, lightgray text)
     *
     * @param string $message The message text
     */
    public function error(string $message): void
    {
        $this->cli->message($message, 'cli.error');
    }

    /**
     * Format and output bold text.
     *
     * @param string $text The text to make bold
     */
    public function bold(string $text): void
    {
        if ($this->nocolor) {
            $this->cli->writeln($text);
        } else {
            $this->cli->writeln($this->cli->bold($text));
        }
    }

    /**
     * Format and output blue text.
     *
     * @param string $text The text
     */
    public function blue(string $text): void
    {
        if ($this->nocolor) {
            $this->cli->writeln($text);
        } else {
            $this->cli->writeln($this->cli->blue($text));
        }
    }

    /**
     * Format and output green text.
     *
     * @param string $text The text
     */
    public function green(string $text): void
    {
        if ($this->nocolor) {
            $this->cli->writeln($text);
        } else {
            $this->cli->writeln($this->cli->green($text));
        }
    }

    /**
     * Format and output yellow text.
     *
     * @param string $text The text
     */
    public function yellow(string $text): void
    {
        if ($this->nocolor) {
            $this->cli->writeln($text);
        } else {
            $this->cli->writeln($this->cli->yellow($text));
        }
    }

    /**
     * Output plain text without formatting.
     *
     * @param string $text The text
     */
    public function plain(string $text): void
    {
        $this->cli->writeln($text);
    }

    /**
     * Output verbose-only message with borders.
     *
     * Displays multi-line bordered output:
     *   [  INFO  ] -------------------------------------------------
     *   [  INFO  ] PEAR output START
     *   [  INFO  ] -------------------------------------------------
     *   (text content)
     *   [  INFO  ] -------------------------------------------------
     *   [  INFO  ] PEAR output END
     *   [  INFO  ] -------------------------------------------------
     *
     * @param string $text The text
     */
    public function pear(string $text): void
    {
        $type = $this->getType('cli.message');
        $this->cli->message('-------------------------------------------------', $type);
        $this->cli->message('PEAR output START', $type);
        $this->cli->message('-------------------------------------------------', $type);
        $this->cli->writeln($text);
        $this->cli->message('-------------------------------------------------', $type);
        $this->cli->message('PEAR output END', $type);
        $this->cli->message('-------------------------------------------------', $type);
    }

    /**
     * Output a message with semantic category.
     *
     * Maps semantic categories to appropriate bracket labels and colors.
     * Also accepts traditional log levels (ok/warn/info/error) for
     * unified interface.
     *
     * @param string $category The semantic category or log level
     * @param string $message The message text
     */
    public function semantic(string $category, string $message): void
    {
        // Support traditional log levels by delegating to existing methods
        if (in_array($category, ['ok', 'warn', 'info', 'error'], true)) {
            $this->$category($message);
            return;
        }

        // Determine the message type based on category
        $type = match ($category) {
            'regression', 'validation' => 'cli.error',
            'improvement', 'auto', 'created' => 'cli.success',
            'skip', 'deleted' => '',  // No color for neutral operations
            default => 'cli.message',
        };

        $this->cli->message($message, $this->getType($type));
    }

    /**
     * Modify the type for the --nocolor switch.
     *
     * When --nocolor is set, returns empty string to disable colored
     * backgrounds and formatting, but still shows brackets.
     *
     * @param string $type The message type (cli.success, cli.warning, etc.)
     *
     * @return string The message type or empty string if colors disabled
     */
    private function getType(string $type): string
    {
        return $this->nocolor ? '' : $type;
    }
}
