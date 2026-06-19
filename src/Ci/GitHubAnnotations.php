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

namespace Horde\Components\Ci;

/**
 * Emit GitHub Actions workflow-command annotations on stdout.
 *
 * The runner picks up lines of the form
 *
 *     ::error file=path,line=N,title=T::message text
 *     ::warning file=path,line=N::message text
 *     ::notice file=path::message text
 *
 * and surfaces them as line-anchored annotations on the PR diff plus
 * entries in the run's "Annotations" sidebar. See
 * https://docs.github.com/en/actions/using-workflows/workflow-commands-for-github-actions
 *
 * Path values must be **relative to `$GITHUB_WORKSPACE`** for the runner
 * to anchor an annotation to a diff line. Absolute paths from the lane
 * directory (`/tmp/horde-ci/lanes/.../Victim/src/X.php`) won't anchor;
 * use {@see relativizeForAnnotation()} to strip the lane prefix.
 *
 * Outside GitHub Actions (no `GITHUB_ACTIONS=true` env var) every
 * emit method is a silent no-op so local invocations don't print
 * meaningless `::error::` lines.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class GitHubAnnotations
{
    /**
     * Emit a workflow-command line at error severity.
     *
     * @param string $message Plain message body. Newlines and `%` are
     *                        escaped to GitHub's wire format.
     * @param string|null $file Path relative to $GITHUB_WORKSPACE, or null
     *                          to emit a sidebar-only annotation.
     * @param int|null $line 1-based line number, or null.
     * @param string|null $title Short title shown above the message.
     */
    public static function error(
        string $message,
        ?string $file = null,
        ?int $line = null,
        ?string $title = null
    ): void {
        self::emit('error', $message, $file, $line, $title);
    }

    public static function warning(
        string $message,
        ?string $file = null,
        ?int $line = null,
        ?string $title = null
    ): void {
        self::emit('warning', $message, $file, $line, $title);
    }

    public static function notice(
        string $message,
        ?string $file = null,
        ?int $line = null,
        ?string $title = null
    ): void {
        self::emit('notice', $message, $file, $line, $title);
    }

    /**
     * Strip a lane/component prefix from an absolute path so the runner
     * can anchor the annotation to the PR diff.
     *
     * Example: a finding for
     *   /tmp/horde-ci/lanes/php8.3-dev/Victim/src/ComposerJsonFile.php
     * with componentDir
     *   /tmp/horde-ci/lanes/php8.3-dev/Victim
     * returns "src/ComposerJsonFile.php".
     *
     * If the absolute path is not under componentDir, returns it unchanged.
     */
    public static function relativizeForAnnotation(
        string $absolute,
        string $componentDir
    ): string {
        $componentDir = rtrim($componentDir, '/') . '/';
        if (str_starts_with($absolute, $componentDir)) {
            return substr($absolute, strlen($componentDir));
        }
        return $absolute;
    }

    /**
     * Whether annotations will actually be emitted in the current
     * environment. Useful for callers that want to skip building the
     * data when no one is listening.
     */
    public static function isActive(): bool
    {
        return getenv('GITHUB_ACTIONS') === 'true';
    }

    private static function emit(
        string $severity,
        string $message,
        ?string $file,
        ?int $line,
        ?string $title
    ): void {
        if (!self::isActive()) {
            return;
        }

        $params = [];
        if ($file !== null && $file !== '') {
            $params[] = 'file=' . self::escapeProperty($file);
        }
        if ($line !== null && $line > 0) {
            $params[] = 'line=' . $line;
        }
        if ($title !== null && $title !== '') {
            $params[] = 'title=' . self::escapeProperty($title);
        }

        $paramStr = $params === [] ? '' : ' ' . implode(',', $params);
        echo '::' . $severity . $paramStr . '::' . self::escapeData($message) . PHP_EOL;
    }

    /**
     * Escape per GitHub's workflow-command spec for the message body.
     *
     * Newlines have to be escaped as %0A or the runner will treat
     * the next line as a new command (or, worse, swallow it).
     */
    private static function escapeData(string $value): string
    {
        return strtr($value, [
            '%' => '%25',
            "\r" => '%0D',
            "\n" => '%0A',
        ]);
    }

    /**
     * Escape for property values (file=, line=, title=). Property values
     * also need `:` and `,` escaped because those are field separators.
     */
    private static function escapeProperty(string $value): string
    {
        return strtr($value, [
            '%' => '%25',
            "\r" => '%0D',
            "\n" => '%0A',
            ':' => '%3A',
            ',' => '%2C',
        ]);
    }
}
