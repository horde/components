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

namespace Horde\Components\Output;

/**
 * Interface for CLI presentation backends.
 *
 * Backends control how semantic messages (ok, warn, info, error)
 * are formatted and displayed to the user.
 *
 * Different presenters can provide:
 * - Classic: Colored output with brackets (backward compatible)
 * - CI: GitHub Actions/GitLab CI optimized format
 * - Unicode: Modern CLI style with emoji and symbols
 * - JSON: Machine-readable structured output
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
interface Presenter
{
    /**
     * Format and output a success message.
     *
     * Typical use: operation completed successfully, tests passed, etc.
     *
     * @param string $message The message text
     */
    public function ok(string $message): void;

    /**
     * Format and output a warning message.
     *
     * Typical use: non-fatal issues, deprecations, minor problems.
     *
     * @param string $message The message text
     */
    public function warn(string $message): void;

    /**
     * Format and output an informational message.
     *
     * Typical use: progress updates, status information, general messages.
     *
     * @param string $message The message text
     */
    public function info(string $message): void;

    /**
     * Format and output an error message.
     *
     * Typical use: operation failures, validation errors, critical issues.
     *
     * @param string $message The message text
     */
    public function error(string $message): void;

    /**
     * Format and output bold text.
     *
     * Used for emphasis, headings, important information.
     *
     * @param string $text The text to make bold
     */
    public function bold(string $text): void;

    /**
     * Format and output blue text.
     *
     * Used for informational coloring.
     *
     * @param string $text The text
     */
    public function blue(string $text): void;

    /**
     * Format and output green text.
     *
     * Used for success coloring.
     *
     * @param string $text The text
     */
    public function green(string $text): void;

    /**
     * Format and output yellow text.
     *
     * Used for warning coloring.
     *
     * @param string $text The text
     */
    public function yellow(string $text): void;

    /**
     * Output plain text without formatting.
     *
     * Used for regular output, help text, etc.
     *
     * @param string $text The text
     */
    public function plain(string $text): void;

    /**
     * Output verbose-only message with borders.
     *
     * Used for detailed logging, debug output, etc.
     * Typically only shown with --verbose flag.
     *
     * @param string $text The text
     */
    public function pear(string $text): void;

    /**
     * Output a message with semantic category.
     *
     * This method provides a unified interface for expressing semantic meaning
     * beyond basic formatting. It accepts both semantic categories and traditional
     * log levels, allowing developers to use one method for all output needs.
     *
     * Different presenters interpret categories appropriately:
     * - Unicode: Maps to emoji symbols (🔍, ▶️, 📊, etc.)
     * - Classic: Maps to Horde_Cli message types with brackets
     * - CI: Maps to GitHub Actions commands or labeled output
     *
     * Supported semantic categories:
     * - detected: Tool/resource discovery (🔍)
     * - running: Process execution (▶️)
     * - metrics: Statistics/measurements (📊)
     * - regression: Quality degradation (📉)
     * - improvement: Quality advancement (📈)
     * - skip: Conditional skip (⏭️)
     * - auto: Automatic action (🤖)
     * - created: File created (📝)
     * - updated: File updated (🔄)
     * - deleted: File deleted (🗑️)
     * - validation: Pre-flight check failure (✗)
     * - config: Configuration change (⚙️)
     * - release: Release operation (📦)
     * - branch: Git branch operation (🌿)
     * - push: Git push operation (📤)
     * - pull: Git pull operation (📥)
     * - commit: Git commit operation (💬)
     * - tag: Git tag operation (🏷️)
     *
     * Traditional log levels (unified interface):
     * - ok: Success message (proxied to ok() method)
     * - warn: Warning message (proxied to warn() method)
     * - info: Informational message (proxied to info() method)
     * - error: Error message (proxied to error() method)
     *
     * Example usage:
     *   $output->semantic('detected', 'Found PHPStan v1.12.9');
     *   $output->semantic('running', 'Testing watermark level 5');
     *   $output->semantic('ok', 'All tests passed');  // Traditional level
     *
     * @param string $category The semantic category or traditional log level
     * @param string $message The message text
     */
    public function semantic(string $category, string $message): void;
}
