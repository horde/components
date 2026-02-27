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

use Horde_Cli;
use Horde\Components\Output\Presenter\Classic;
use Horde\Components\Output\Presenter\Ci;
use Horde\Components\Output\Presenter\Unicode;

/**
 * Factory for creating CLI presentation backends.
 *
 * The factory selects an appropriate presenter based on:
 * - Explicit user choice via --format option
 * - Auto-detection of environment (CI, terminal capabilities)
 * - Safe fallback to Classic mode
 *
 * Supported formats:
 * - classic: Traditional colored brackets (backward compatible)
 * - ci: GitHub Actions/GitLab CI optimized (future)
 * - unicode: Modern emoji/symbols (future)
 * - autodetect: Smart selection based on environment (default)
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class PresenterFactory
{
    /**
     * Create a presenter based on environment and options.
     *
     * @param Horde_Cli $cli The CLI instance
     * @param array $options Configuration options
     *   - 'cli_format': Explicit format ('classic', 'ci', 'unicode', 'autodetect')
     *   - 'nocolor': Disable colors
     *
     * @return Presenter The selected presenter
     */
    public static function create(Horde_Cli $cli, array $options = []): Presenter
    {
        $format = $options['cli_format'] ?? 'autodetect';
        $nocolor = !empty($options['nocolor']);

        if ($format === 'autodetect') {
            $format = self::detectFormat();
        }

        return match ($format) {
            'ci' => new Ci(),
            'unicode' => new Unicode($cli, !$nocolor),
            'classic' => new Classic($cli, $nocolor),
            default => new Classic($cli, $nocolor),
        };
    }

    /**
     * Auto-detect the best presentation format.
     *
     * Detection logic:
     * 1. If GITHUB_ACTIONS or CI env var → 'ci'
     * 2. If terminal supports Unicode and not SSH → 'unicode'
     * 3. Otherwise → 'classic'
     *
     * @return string The detected format ('classic', 'ci', or 'unicode')
     */
    private static function detectFormat(): string
    {
        // Check for CI environment first
        if (self::isCI()) {
            return 'ci';
        }

        // Check for Unicode support
        if (self::supportsUnicode()) {
            return 'unicode';
        }

        // Default fallback
        return 'classic';
    }

    /**
     * Detect if running in a CI environment.
     *
     * Checks for common CI environment variables:
     * - GITHUB_ACTIONS (GitHub Actions)
     * - GITLAB_CI (GitLab CI)
     * - JENKINS_HOME (Jenkins)
     * - CIRCLECI (Circle CI)
     * - TRAVIS (Travis CI)
     * - CI (generic CI indicator)
     *
     * @return bool True if running in CI
     */
    private static function isCI(): bool
    {
        return (
            getenv('GITHUB_ACTIONS') !== false
            || getenv('GITLAB_CI') !== false
            || getenv('JENKINS_HOME') !== false
            || getenv('CIRCLECI') !== false
            || getenv('TRAVIS') !== false
            || getenv('CI') !== false
        );
    }

    /**
     * Detect if terminal supports Unicode.
     *
     * Detection heuristics:
     * 1. Check LANG/LC_ALL for UTF-8 encoding
     * 2. Check TERM for Unicode-capable terminals
     * 3. Avoid Unicode over SSH (can be unreliable)
     * 4. Check if terminal is modern (xterm-256color, etc)
     *
     * @return bool True if Unicode is likely supported
     */
    private static function supportsUnicode(): bool
    {
        // Check locale for UTF-8 support
        $lang = getenv('LANG') ?: getenv('LC_ALL') ?: '';
        $hasUtf8 = (
            stripos($lang, 'UTF-8') !== false
            || stripos($lang, 'UTF8') !== false
        );

        if (!$hasUtf8) {
            return false;
        }

        // Avoid Unicode over SSH (can be unreliable)
        if (getenv('SSH_CONNECTION') !== false || getenv('SSH_CLIENT') !== false) {
            return false;
        }

        // Check TERM for Unicode-capable terminals
        $term = getenv('TERM') ?: '';
        $modernTerms = [
            'xterm-256color',
            'screen-256color',
            'tmux-256color',
            'rxvt-unicode',
            'alacritty',
            'kitty',
            'iTerm',
        ];

        foreach ($modernTerms as $modernTerm) {
            if (stripos($term, $modernTerm) !== false) {
                return true;
            }
        }

        // Generic xterm/linux/vt100 with UTF-8 is probably fine
        if ($hasUtf8 && preg_match('/^(xterm|linux|vt[0-9]+)/', $term)) {
            return true;
        }

        return false;
    }
}
