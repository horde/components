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
 * Modern Unicode presentation with emoji and symbols.
 *
 * This presenter uses modern Unicode characters and emoji for a
 * contemporary CLI experience, inspired by tools like:
 * - Rust's cargo
 * - Node's npm/pnpm
 * - GitHub CLI (gh)
 * - Deno
 * - Claude Code
 *
 * Output format:
 *   ✅ Success message (or 🎉 for celebrations)
 *   ⚠️  Warning message
 *   ℹ️  Info message
 *   ❌ Error message
 *
 * Features:
 * - Unicode emoji and symbols
 * - Optional color support (can be disabled)
 * - Modern box drawing characters for borders
 * - Clean, minimal aesthetic
 *
 * Terminal requirements:
 * - UTF-8 locale support
 * - Modern terminal emulator (iTerm2, Alacritty, Kitty, etc.)
 * - Not recommended over SSH (can be unreliable)
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
class Unicode implements Presenter
{
    /**
     * The Horde CLI instance for color support.
     */
    private readonly Horde_Cli $cli;

    /**
     * Whether to use colors.
     */
    private readonly bool $useColors;

    /**
     * Constructor.
     *
     * @param Horde_Cli $cli The Horde CLI instance
     * @param bool $useColors Whether to use colors (default: true)
     */
    public function __construct(Horde_Cli $cli, bool $useColors = true)
    {
        $this->cli = $cli;
        $this->useColors = $useColors;
    }

    /**
     * Format and output a success message.
     *
     * Displays: ✅ message (green if colors enabled)
     *
     * @param string $message The message text
     */
    public function ok(string $message): void
    {
        $symbol = '✅';

        if ($this->useColors) {
            $symbol = $this->cli->green($symbol);
        }

        $this->cli->writeln("{$symbol} {$message}");
    }

    /**
     * Format and output a warning message.
     *
     * Displays: ⚠️  message (yellow if colors enabled)
     *
     * @param string $message The message text
     */
    public function warn(string $message): void
    {
        $symbol = '⚠️ ';

        if ($this->useColors) {
            $symbol = $this->cli->yellow($symbol);
        }

        $this->cli->writeln("{$symbol} {$message}");
    }

    /**
     * Format and output an informational message.
     *
     * Displays: ℹ️  message (blue if colors enabled)
     *
     * @param string $message The message text
     */
    public function info(string $message): void
    {
        $symbol = 'ℹ️ ';

        if ($this->useColors) {
            $symbol = $this->cli->blue($symbol);
        }

        $this->cli->writeln("{$symbol} {$message}");
    }

    /**
     * Format and output an error message.
     *
     * Displays: ❌ message (red if colors enabled)
     *
     * @param string $message The message text
     */
    public function error(string $message): void
    {
        $symbol = '❌';

        if ($this->useColors) {
            $symbol = $this->cli->red($symbol);
        }

        $this->cli->writeln("{$symbol} {$message}");
    }

    /**
     * Format and output bold text.
     *
     * @param string $text The text to make bold
     */
    public function bold(string $text): void
    {
        if ($this->useColors) {
            $this->cli->writeln($this->cli->bold($text));
        } else {
            $this->cli->writeln($text);
        }
    }

    /**
     * Format and output blue text.
     *
     * @param string $text The text
     */
    public function blue(string $text): void
    {
        if ($this->useColors) {
            $this->cli->writeln($this->cli->blue($text));
        } else {
            $this->cli->writeln($text);
        }
    }

    /**
     * Format and output green text.
     *
     * @param string $text The text
     */
    public function green(string $text): void
    {
        if ($this->useColors) {
            $this->cli->writeln($this->cli->green($text));
        } else {
            $this->cli->writeln($text);
        }
    }

    /**
     * Format and output yellow text.
     *
     * @param string $text The text
     */
    public function yellow(string $text): void
    {
        if ($this->useColors) {
            $this->cli->writeln($this->cli->yellow($text));
        } else {
            $this->cli->writeln($text);
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
     * Output verbose-only message with Unicode borders.
     *
     * Displays bordered output using box-drawing characters:
     *   ━━━━ PEAR output START ━━━━
     *   (content)
     *   ━━━━ PEAR output END ━━━━
     *
     * @param string $text The text
     */
    public function pear(string $text): void
    {
        $this->cli->writeln('━━━━ PEAR output START ━━━━');
        $this->cli->writeln($text);
        $this->cli->writeln('━━━━ PEAR output END ━━━━');
    }

    /**
     * Output a message with semantic category using Unicode symbols.
     *
     * Maps semantic categories to appropriate emoji/symbols with
     * optional color support. Also accepts traditional log levels
     * (ok/warn/info/error) for unified interface.
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

        // Map category to Unicode symbol
        $symbol = match ($category) {
            'detected' => '🔍',
            'running' => '▶️ ',
            'metrics' => '📊',
            'regression' => '📉',
            'improvement' => '📈',
            'skip' => '⏭️ ',
            'auto' => '🤖',
            'created' => '📝',
            'updated' => '🔄',
            'deleted' => '🗑️ ',
            'validation' => '✗',
            'config' => '⚙️ ',
            'release' => '📦',
            'branch' => '🌿',
            'push' => '📤',
            'pull' => '📥',
            'commit' => '💬',
            'tag' => '🏷️ ',
            default => 'ℹ️ ',  // Fallback to info
        };

        // Apply color if enabled
        if ($this->useColors) {
            $colorMethod = match ($category) {
                'regression', 'validation', 'deleted' => 'red',
                'improvement', 'auto', 'created' => 'green',
                'detected', 'config', 'running' => 'blue',
                'metrics', 'release' => 'yellow',
                default => null,
            };

            if ($colorMethod !== null) {
                $symbol = $this->cli->$colorMethod($symbol);
            }
        }

        $this->cli->writeln("{$symbol} {$message}");
    }
}
