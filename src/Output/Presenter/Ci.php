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

/**
 * CI-optimized presentation without colors.
 *
 * This presenter is designed for Continuous Integration environments
 * where colored output doesn't render well and special annotations
 * are preferred.
 *
 * GitHub Actions format:
 *   ::notice::Success message
 *   ::warning::Warning message
 *   ::error::Error message
 *
 * Plain CI format (GitLab, Jenkins, etc.):
 *   [OK] Success message
 *   [WARN] Warning message
 *   [INFO] Info message
 *   [ERROR] Error message
 *
 * This format is optimized for:
 * - No ANSI color codes (clean logs)
 * - GitHub Actions workflow commands for annotations
 * - Concise brackets without padding
 * - Easy parsing by CI tools
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Ci implements Presenter
{
    /**
     * Whether to use GitHub Actions workflow commands.
     */
    private readonly bool $githubActions;

    /**
     * Output stream (default: STDOUT).
     */
    private readonly mixed $output;

    /**
     * Constructor.
     *
     * Automatically detects GitHub Actions environment.
     *
     * @param resource|null $output Output stream (default: STDOUT)
     */
    public function __construct($output = null)
    {
        // Detect GitHub Actions environment
        $this->githubActions = (bool) getenv('GITHUB_ACTIONS');

        // Use provided output stream or default to STDOUT
        $this->output = $output ?? (defined('STDOUT') ? STDOUT : fopen('php://stdout', 'w'));
    }

    /**
     * Format and output a success message.
     *
     * GitHub Actions: ::notice::{message}
     * Plain CI: [OK] {message}
     *
     * @param string $message The message text
     */
    public function ok(string $message): void
    {
        if ($this->githubActions) {
            $this->writeln("::notice::{$message}");
        } else {
            $this->writeln("[OK] {$message}");
        }
    }

    /**
     * Format and output a warning message.
     *
     * GitHub Actions: ::warning::{message}
     * Plain CI: [WARN] {message}
     *
     * @param string $message The message text
     */
    public function warn(string $message): void
    {
        if ($this->githubActions) {
            $this->writeln("::warning::{$message}");
        } else {
            $this->writeln("[WARN] {$message}");
        }
    }

    /**
     * Format and output an informational message.
     *
     * GitHub Actions: ::notice::{message}
     * Plain CI: [INFO] {message}
     *
     * Note: GitHub Actions doesn't have a dedicated info command,
     * so we use ::notice:: which creates a collapsible annotation.
     *
     * @param string $message The message text
     */
    public function info(string $message): void
    {
        if ($this->githubActions) {
            $this->writeln("::notice::{$message}");
        } else {
            $this->writeln("[INFO] {$message}");
        }
    }

    /**
     * Format and output an error message.
     *
     * GitHub Actions: ::error::{message}
     * Plain CI: [ERROR] {message}
     *
     * @param string $message The message text
     */
    public function error(string $message): void
    {
        if ($this->githubActions) {
            $this->writeln("::error::{$message}");
        } else {
            $this->writeln("[ERROR] {$message}");
        }
    }

    /**
     * Format and output bold text.
     *
     * No bold formatting in CI - outputs plain text.
     *
     * @param string $text The text
     */
    public function bold(string $text): void
    {
        $this->writeln($text);
    }

    /**
     * Format and output blue text.
     *
     * No color formatting in CI - outputs plain text.
     *
     * @param string $text The text
     */
    public function blue(string $text): void
    {
        $this->writeln($text);
    }

    /**
     * Format and output green text.
     *
     * No color formatting in CI - outputs plain text.
     *
     * @param string $text The text
     */
    public function green(string $text): void
    {
        $this->writeln($text);
    }

    /**
     * Format and output yellow text.
     *
     * No color formatting in CI - outputs plain text.
     *
     * @param string $text The text
     */
    public function yellow(string $text): void
    {
        $this->writeln($text);
    }

    /**
     * Output plain text without formatting.
     *
     * @param string $text The text
     */
    public function plain(string $text): void
    {
        $this->writeln($text);
    }

    /**
     * Output verbose-only message with borders.
     *
     * Simplified for CI - just adds dashed lines.
     *
     * @param string $text The text
     */
    public function pear(string $text): void
    {
        $this->writeln('---- PEAR output START ----');
        $this->writeln($text);
        $this->writeln('---- PEAR output END ----');
    }

    /**
     * Output a message with semantic category.
     *
     * Maps semantic categories to GitHub Actions workflow commands
     * or plain text labels for other CI systems. Also accepts
     * traditional log levels (ok/warn/info/error) for unified
     * interface.
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

        if ($this->githubActions) {
            // Map to GitHub Actions workflow commands
            $command = match ($category) {
                'regression', 'validation' => '::error::',
                'improvement', 'auto', 'created' => '::notice::',
                default => '::debug::',  // Most semantic categories are debug-level
            };
            $this->writeln("{$command}{$message}");
        } else {
            // Plain CI format with category labels
            $label = match ($category) {
                'detected' => '[DETECT]',
                'running' => '[RUNNING]',
                'metrics' => '[METRICS]',
                'regression' => '[REGRESS]',
                'improvement' => '[IMPROVE]',
                'skip' => '[SKIP]',
                'auto' => '[AUTO]',
                'created' => '[CREATE]',
                'updated' => '[UPDATE]',
                'deleted' => '[DELETE]',
                'validation' => '[VALIDAT]',
                'config' => '[CONFIG]',
                'release' => '[RELEASE]',
                'branch' => '[BRANCH]',
                'push' => '[PUSH]',
                'pull' => '[PULL]',
                'commit' => '[COMMIT]',
                'tag' => '[TAG]',
                default => '[INFO]',
            };
            $this->writeln("{$label} {$message}");
        }
    }

    /**
     * Write a line to the output stream.
     *
     * @param string $text The text to write
     */
    private function writeln(string $text): void
    {
        fwrite($this->output, $text . PHP_EOL);
    }
}
