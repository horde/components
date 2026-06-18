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

namespace Horde\Components\Ci\Setup;

use Horde\Components\Exception;
use Horde\Version\ConstraintParser;
use Horde\Version\InvalidVersionException;
use Horde\Version\RelaxedSemanticVersion;

/**
 * Pick a PHPUnit version that satisfies both a component's constraint and a
 * lane's PHP version.
 *
 * Single source of truth for two questions, both of which were previously
 * answered by hardcoded {php_version => phpunit_major} tables in
 * ToolCache and ToolFinder:
 *
 *   1. Which PHPUnit PHAR should the cache download for a given lane?
 *   2. Which cached PHAR should the run-lane script invoke?
 *
 * The supported-PHP-range table is hardcoded here; bump it when PHPUnit
 * drops or adds a major.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class PhpUnitMatrix
{
    /**
     * Available PHPUnit majors with their authoritative tag and minimum PHP.
     *
     * Ordered newest-first so {@see pick()} can scan top-down for the
     * highest satisfying entry.
     *
     * Keys are the major version. `tag` is the major.minor we actually
     * download (the highest stable minor of that major). `minPhp` is the
     * minimum PHP version per phpunit.de.
     *
     * @var array<int,array{tag: string, minPhp: string}>
     */
    private const MAJORS = [
        12 => ['tag' => '12.5', 'minPhp' => '8.3'],
        11 => ['tag' => '11.5', 'minPhp' => '8.2'],
        10 => ['tag' => '10.5', 'minPhp' => '8.1'],
        9  => ['tag' => '9.6',  'minPhp' => '7.3'],
    ];

    /**
     * Pick the highest PHPUnit tag satisfying both the component's constraint
     * and the lane's PHP version.
     *
     * @param string|null $constraint Composer-style constraint from the
     *                                component's composer.json `require-dev`,
     *                                or null when the component does not
     *                                declare a PHPUnit constraint.
     * @param string $phpVersion Lane PHP version, e.g. "8.2", "8.3.6".
     * @return string|null Tag like "12.5", or null when no major satisfies
     *                    both bounds.
     */
    public function pick(?string $constraint, string $phpVersion): ?string
    {
        $parser = new ConstraintParser();
        $parsed = null;

        if ($constraint !== null && $constraint !== '') {
            try {
                $parsed = $parser->parse($constraint);
            } catch (InvalidVersionException) {
                // Unparseable constraints fall through to "highest compatible
                // with PHP version" — preserves behaviour for components
                // whose constraints we don't yet recognise.
                $parsed = null;
            }
        }

        foreach (self::MAJORS as $major => $row) {
            if (!$this->phpSupports($phpVersion, $row['minPhp'])) {
                continue;
            }
            if ($parsed === null) {
                return $row['tag'];
            }
            // Probe the major with its `tag.0` representative; that's
            // enough for caret/tilde/range constraints to give a
            // deterministic answer per major.
            $probe = new RelaxedSemanticVersion($row['tag'] . '.0');
            if ($parsed->isSatisfiedBy($probe)) {
                return $row['tag'];
            }
        }

        return null;
    }

    /**
     * Convenience: read the constraint from a component's composer.json and
     * pick a tag for the given PHP version.
     *
     * @param string $componentComposerJsonPath Path to the component's
     *                                          composer.json file.
     * @param string $phpVersion Lane PHP version.
     * @return PhpUnitSelection Either a satisfying tag or a deliberate-skip
     *                          with a human-readable reason.
     * @throws Exception When composer.json is unreadable or malformed.
     */
    public function pickWithSource(
        string $componentComposerJsonPath,
        string $phpVersion
    ): PhpUnitSelection {
        $constraint = $this->readConstraint($componentComposerJsonPath);
        $tag = $this->pick($constraint, $phpVersion);

        if ($tag !== null) {
            return new PhpUnitSelection($tag, null);
        }

        $reason = $constraint === null
            ? sprintf('No PHPUnit major supports PHP %s', $phpVersion)
            : sprintf(
                'PHPUnit constraint "%s" not satisfiable on PHP %s',
                $constraint,
                $phpVersion
            );
        return new PhpUnitSelection(null, $reason);
    }

    /**
     * Read the phpunit/phpunit constraint from a composer.json file.
     *
     * Looks in `require-dev` first, then `require`. Returns null when the
     * file does not declare phpunit.
     *
     * @param string $path Path to composer.json.
     * @return string|null Constraint string or null if not declared.
     * @throws Exception If the file is missing or not valid JSON.
     */
    public function readConstraint(string $path): ?string
    {
        if (!is_file($path)) {
            throw new Exception("composer.json not found: {$path}");
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new Exception("Failed to read composer.json: {$path}");
        }
        try {
            /** @var array<string,mixed> $data */
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new Exception("Invalid JSON in {$path}: " . $e->getMessage(), 0, $e);
        }

        foreach (['require-dev', 'require'] as $section) {
            if (isset($data[$section]['phpunit/phpunit'])
                && is_string($data[$section]['phpunit/phpunit'])) {
                return $data[$section]['phpunit/phpunit'];
            }
        }
        return null;
    }

    /**
     * Whether $phpVersion satisfies the >= $minPhp bound.
     *
     * Both arguments accept any number of dotted components; comparison
     * uses PHP's native version_compare.
     *
     * @param string $phpVersion e.g. "8.2", "8.3.6"
     * @param string $minPhp e.g. "8.3"
     */
    private function phpSupports(string $phpVersion, string $minPhp): bool
    {
        return version_compare($phpVersion, $minPhp, '>=');
    }
}
