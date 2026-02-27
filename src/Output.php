<?php

/**
 * Components_Output:: handles output from the components application.
 *
 * PHP Version 8
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components;

use Horde\Components\Output\Presenter;
use Horde\Components\Output\PresenterFactory;

/**
 * Components_Output:: handles output from the components application.
 *
 * This class acts as a facade for CLI output, delegating formatting
 * to a pluggable Presenter backend. This allows switching between
 * different output styles (classic, CI, unicode) without changing
 * application code.
 *
 * Copyright 2010-2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
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
     * The presentation backend.
     */
    private readonly Presenter $_presenter;

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

        // Create presenter based on options and environment
        $this->_presenter = PresenterFactory::create($_cli, $options);
    }

    /**
     * Output bold text.
     *
     * @param string $text The text to make bold
     */
    public function bold($text): void
    {
        $this->_presenter->bold($text);
    }

    /**
     * Output blue text.
     *
     * @param string $text The text
     */
    public function blue($text): void
    {
        $this->_presenter->blue($text);
    }

    /**
     * Output green text.
     *
     * @param string $text The text
     */
    public function green($text): void
    {
        $this->_presenter->green($text);
    }

    /**
     * Output yellow text.
     *
     * @param string $text The text
     */
    public function yellow($text): void
    {
        $this->_presenter->yellow($text);
    }

    /**
     * Output a success message.
     *
     * Respects --quiet flag.
     *
     * @param string $text The message text
     */
    public function ok($text): void
    {
        if ($this->_quiet) {
            return;
        }
        $this->_presenter->ok($text);
    }

    /**
     * Output a warning message.
     *
     * Respects --quiet flag.
     *
     * @param string $text The message text
     */
    public function warn($text): void
    {
        if ($this->_quiet) {
            return;
        }
        $this->_presenter->warn($text);
    }

    /**
     * Output an informational message.
     *
     * Respects --quiet flag.
     *
     * @param string $text The message text
     */
    public function info($text): void
    {
        if ($this->_quiet) {
            return;
        }
        $this->_presenter->info($text);
    }

    /**
     * Output an error message.
     *
     * ALWAYS shown (ignores --quiet).
     *
     * @param string $text The message text
     */
    public function error($text): void
    {
        $this->_presenter->error($text);
    }

    /**
     * Output a fatal error and throw exception.
     *
     * @param string $text The error message
     */
    public function fail($text): void
    {
        $this->_cli->fatal($text);
    }

    /**
     * Alias for pear() - status logging.
     *
     * @param string $status Status indicator (unused)
     * @param string $text The message text
     */
    public function log($status, $text): void
    {
        $this->pear($text);
    }

    /**
     * Output help text (alias for plain).
     *
     * @param string $text The help text
     */
    public function help($text): void
    {
        $this->plain($text);
    }

    /**
     * Output plain text without formatting.
     *
     * @param string $text The text
     */
    public function plain($text): void
    {
        $this->_presenter->plain($text);
    }

    /**
     * Output verbose-only message with borders.
     *
     * Respects --verbose flag.
     *
     * @param string $text The message text
     */
    public function pear($text): void
    {
        if (!$this->_verbose) {
            return;
        }
        $this->_presenter->pear($text);
    }

    /**
     * Check if verbose output is enabled.
     *
     * @return bool True if --verbose flag set
     */
    public function isVerbose(): bool
    {
        return $this->_verbose;
    }

    /**
     * Check if quiet output is enabled.
     *
     * @return bool True if --quiet flag set
     */
    public function isQuiet(): bool
    {
        return $this->_quiet;
    }

    /**
     * Output a message with semantic category.
     *
     * Semantic categories provide richer meaning than the basic 4 levels
     * (ok/warn/info/error). Different presenters interpret categories
     * with appropriate symbols, colors, and formatting.
     *
     * Always shown: error, regression, validation
     * Respects --quiet: all other categories
     *
     * @param string $category The semantic category
     * @param string $message The message text
     */
    public function semantic(string $category, string $message): void
    {
        // Always show errors and regressions
        $alwaysShow = ['error', 'regression', 'validation'];

        if (!$this->_quiet || in_array($category, $alwaysShow, true)) {
            $this->_presenter->semantic($category, $message);
        }
    }

    /**
     * Output a detection/discovery message.
     *
     * Use for: tool discovery, resource detection, configuration found.
     *
     * @param string $message The message text
     */
    public function detected(string $message): void
    {
        $this->semantic('detected', $message);
    }

    /**
     * Output a process execution message.
     *
     * Use for: subprocess execution, command running.
     *
     * @param string $message The message text
     */
    public function running(string $message): void
    {
        $this->semantic('running', $message);
    }

    /**
     * Output metrics/statistics message.
     *
     * Use for: test results, performance data, summaries.
     *
     * @param string $message The message text
     */
    public function metrics(string $message): void
    {
        $this->semantic('metrics', $message);
    }

    /**
     * Output a quality regression message.
     *
     * Use for: code quality degradation, failing checks.
     * Always shown (ignores --quiet).
     *
     * @param string $message The message text
     */
    public function regression(string $message): void
    {
        $this->semantic('regression', $message);
    }

    /**
     * Output a quality improvement message.
     *
     * Use for: code quality advancement, passing new checks.
     *
     * @param string $message The message text
     */
    public function improvement(string $message): void
    {
        $this->semantic('improvement', $message);
    }

    /**
     * Output a skip/bypass message.
     *
     * Use for: conditional task skips, intentional bypasses.
     *
     * @param string $message The message text
     */
    public function skip(string $message): void
    {
        $this->semantic('skip', $message);
    }

    /**
     * Output an automatic action message.
     *
     * Use for: auto-corrections, automated decisions.
     *
     * @param string $message The message text
     */
    public function auto(string $message): void
    {
        $this->semantic('auto', $message);
    }
}
