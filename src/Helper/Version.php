<?php

declare(strict_types=1);
/**
 * Copyright 2011-2020 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Helper;

use Horde\Components\Exception;

/**
 * Converts between different version schemes.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Version
{
    /**
     * Flip to true if anything was changed from the original string
     *
     * @var bool
     */
    private bool $changed = false;
    // Object methods
    public function __construct(
        private string $original,
        private string $prefix,
        private int $major,
        private int $minor = 0,
        private int $patch = 0,
        private int $subpatch = 0,
        private string $stability = '',
        private int $stabilityVersion = 0,
        private string $buildInfo = '',
        private string $other = ''
    ) {

    }

    // Mark the internal state as changed from the original string
    private function change(): self
    {
        $this->changed = true;
        return $this;
    }

    /**
     * Reveal if the version is still as originally constructed
     *
     * @return bool
     */
    public function changed(): bool
    {
        return $this->changed;
    }

    /**
     * Reconstruct a normalized string representation from parts.
     *
     * Always format to major.minor.patch without leading zero.
     * Only show fourth version part if greater than 0.
     * Append stability with a hyphen unless it is empty or 'stable'
     * Append stability version only if it is 2 or higher and stability != stable
     * Append buildinfo with + if present
     *
     * This will lose any "other" that cannot be parsed to stability and buildinfo
     * This will lose the prefix
     *
     * @return string
     */
    public function normalizeComposerVersion(): string
    {
        $versionString = sprintf('%d.%d.%d', $this->major, $this->minor, $this->patch);
        if ($this->subpatch > 0) {
            $versionString .= '.' . (string) $this->subpatch;
        }
        if ($this->stability && $this->stability != 'stable') {
            $versionString .= '-' . $this->stability;
            if ($this->stabilityVersion > 1) {
                $versionString .= $this->stabilityVersion;
            }
        }
        if ($this->buildInfo) {
            $versionString .= '+' .  $this->buildInfo;
        }
        return $versionString;
    }

    public function getMajor(): int
    {
        return $this->major;
    }
    public function getMinor(): int
    {
        return $this->minor;
    }
    public function getPatch(): int
    {
        return $this->patch;
    }
    public function getSubPatch(): int
    {
        return $this->subpatch;
    }
    public function setSubPatch(int $subpatch): self
    {
        $this->subpatch = $subpatch;
        return $this;
    }

    public function getStability(): string
    {
        return $this->stability;
    }

    public function getStabilityVersion(): int
    {
        return $this->stabilityVersion;
    }

    public function getBuildInfo(): string
    {
        return $this->buildInfo;
    }

    public function getOther(): string
    {
        return $this->other;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function getOriginal(): string
    {
        return $this->original;
    }

    // Static named constructors
    public static function fromComposerString(string $version): Version
    {
        $res = preg_match('/^(\w+)?(\d+)(\.\d+)?(\.\d+)?(\.\d+)?(-?\w+.*)?$/', $version, $match);
        if ($res === false || $res === 0) {
            throw new Exception('Could not parse Composer style version string: ' . $version);
        }

        list($original, $prefix, $major, $minor, $patch, $subpatch, $other) = array_pad($match, 7, null);
        $prefix ??= '';
        $major = (int) $major;
        $minor = (int) ltrim((string) $minor, '.');
        $patch = (int) ltrim((string)$patch, '.');
        $subpatch = (int) ltrim((string)$subpatch, '.');
        $other ??= '';
        // Bisect other string into buildinfo and stability
        $startBuildInfo = stripos($other, '+');
        if ($startBuildInfo === false) {
            $buildInfo = '';
            $stability = $other;
        } else {
            $buildInfo = substr($other, $startBuildInfo + 1);
            $stability = substr($other, 0, $startBuildInfo);
        }
        // Parse stability and stability integer, stripping leading hyphen if any
        ltrim($stability, '-');
        $res = preg_match('/^(\w+)(\d+)?$/', $stability, $stabilityMatch);
        $stability = $stabilityMatch[1] ?? '';
        if ($stability) {
            $stabilityVersion = $stabilityMatch[2] ?? 1;
        } else {
            $stabilityVersion = 0;
        }
        return new Version($original, $prefix, $major, $minor, $patch, $subpatch, $stability, $stabilityVersion, $buildInfo, $other);
    }

    // Old static API
    /**
     * Validates and normalizes a version to be a valid PEAR version.
     *
     * @param string $version  A version string.
     *
     * @return string  The normalized version string.
     *
     * @throws Exception on invalid version string.
     */
    public static function validatePear($version): string
    {
        // Guard: Some older changelogs may contain only two-part versions like
        // Horde 3.3 or Horde 3.3RC1 - These should be re-interpreted as three
        // part versions .0 before going on. Also lowercase ALPHA, BETA and
        // remove hyphens in all the wrong places.
        if (preg_match('/^(\d+\.\d+)(-git|alpha\d*|beta\d*|ALPHA\d*|BETA\d*|-ALPHA\d*|-BETA\d*|-RC\d+|RC\d+)?$/', $version, $match)) {
            if (empty($match[2])) {
                $match[2] = '';
            } else {
                $match[2] = str_replace(
                    [
                        'ALPHA', '-ALPHA', '-alpha',
                        'BETA', '-BETA', '-beta',
                        '-RC'
                    ],
                    [
                        'alpha', 'alpha','alpha',
                        'beta', 'beta', 'beta',
                        'RC'
                    ],
                    (string) $match[2]
                );
            }
            print($match[2]);
            // make bare alpha/beta/rc version 1 each
            if (in_array($match[2], ['alpha', 'beta', 'RC'])) {
                $match[2] = '1';
            }
            $version = $match[1] . '.0' . $match[2];
        }
        // We also had horde version 2.2.6-RC1 - make this 2.2.6RC1
        if (preg_match('/^(\d+\.\d+\.\d+)(-RC\d+)?$/', $version, $match) &&
            !empty($match[2])) {
            $match[2] = substr((string) $match[2], 1);
            $version = $match[1] . $match[2];
        }
        // Now version must be proper or croak
        if (!preg_match('/^(\d+\.\d+\.\d+)(-git|alpha\d*|beta\d*|RC\d+)?$/', $version, $match)) {
            throw new Exception('Invalid version number ' . $version);
        }
        if (!isset($match[2]) || ($match[2] == '-git')) {
            $match[2] = '';
        }
        return $match[1] . $match[2];
    }

    /**
     * Validates the version and release stability tuple.
     *
     * @param string $version   A version string.
     * @param string $stability Release stability information.
     *
     * @throws Exception on invalid version string.
     */
    public static function validateReleaseStability($version, $stability): void
    {
        preg_match('/^(\d+\.\d+\.\d+)(alpha|beta|RC|dev)?\d*$/', $version, $match);
        if (!isset($match[2]) && $stability != 'stable') {
            throw new Exception(
                \sprintf(
                    'Stable version "%s" marked with invalid release stability "%s"!',
                    $version,
                    $stability
                )
            );
        }
        $requires = ['alpha' => 'alpha', 'beta' => 'beta', 'RC' => 'beta', 'dev' => 'devel'];
        foreach ($requires as $m => $s) {
            if (isset($match[2]) && $match[2] == $m && $stability != $s) {
                throw new Exception(
                    \sprintf(
                        '%s version "%s" marked with invalid release stability "%s"!',
                        $s,
                        $version,
                        $stability
                    )
                );
            }
        }
    }

    /**
     * Validates the version and api stability tuple.
     *
     * @param string $version   A version string.
     * @param string $stability Api stability information.
     *
     * @throws Exception on invalid version string.
     */
    public static function validateApiStability($version, $stability): void
    {
        \preg_match('/^(\d+\.\d+\.\d+)(alpha|beta|RC|dev)?\d*$/', $version, $match);
        if (!isset($match[2]) && $stability != 'stable') {
            throw new Exception(
                sprintf(
                    'Stable version "%s" marked with invalid api stability "%s"!',
                    $version,
                    $stability
                )
            );
        }
    }

    /**
     * Converts the PEAR package version number to a descriptive tag used on
     * bugs.horde.org.
     *
     * @param string $version The PEAR package version.
     *
     * @return string The description for bugs.horde.org.
     *
     * @throws Exception on invalid version string.
     */
    public static function pearToTicketDescription($version): string
    {
        $info = self::parsePearVersion($version);
        $version = $info->version;
        if ($info->description) {
            $version .= ' ' . $info->description;
            if ($info->subversion) {
                $version .= ' ' . $info->subversion;
            }
        }
        return $version;
    }

    /**
     * Converts the PEAR package version number to descriptive information.
     *
     * 1.1.0RC2 would become: { version: '1.1.0', description: 'Release
     * Candidate', subversion: '2' }
     *
     * @param string $version The PEAR package version.
     *
     * @return object  An object with the properties:
     *                 - version: The base version string.
     *                 - description: A stability description.
     *                 - subversion: The sub version within the stability level.
     *
     * @throws Exception on invalid version string.
     */
    public static function parsePearVersion($version): \stdClass
    {
        \preg_match('/([.\d]+)(.*)/', $version, $matches);

        $result = new \stdClass();
        $result->version = $matches[1];
        $result->description = '';
        $result->subversion = null;

        if (!empty($matches[2]) && !\preg_match('/^pl\d/', (string) $matches[2])) {
            if (\preg_match('/^RC(\d+)/', (string) $matches[2], $postmatch)) {
                $result->description = 'Release Candidate';
                $result->subversion = $postmatch[1];
            } elseif (\preg_match('/^alpha(\d+)/', (string) $matches[2], $postmatch)) {
                $result->description = 'Alpha';
                $result->subversion = $postmatch[1];
            } elseif (\preg_match('/^beta(\d+)/', (string) $matches[2], $postmatch)) {
                $result->description = 'Beta';
                $result->subversion = $postmatch[1];
            }
        } else {
            $result->description = 'Final';
        }
        $vcomp = \explode('.', (string) $result->version);
        if (\count($vcomp) != 3) {
            throw new Exception('A version number must have 3 parts.');
        }
        return $result;
    }

    /**
     * Convert the PEAR package version number to Horde style and take the
     * branch name into account.
     *
     * @param string $version The PEAR package version.
     * @param string $branch  The Horde branch name.
     *
     * @return string The Horde style version.
     */
    public static function pearToHordeWithBranch($version, $branch): string
    {
        if (empty($branch)) {
            return $version;
        }
        return $branch . ' (' . $version . ')';
    }

    /**
     * Increments the last part of a version number by one.
     *
     * Also attaches -git suffix and increments only if the old version is a
     * stable version.
     *
     * @param string $version  A version number.
     *
     * @return string  The incremented version number.
     *
     * @throws Exception on invalid version string.
     */
    public static function nextVersion($version): string
    {
        if (!\preg_match('/^(\d+\.\d+\.)(\d+)(alpha|beta|RC|dev)?\d*$/', $version, $match)) {
            throw new Exception('Invalid version number ' . $version);
        }
        if (empty($match[3])) {
            $match[2]++;
        }
        return $match[1] . $match[2] . '-git';
    }

    /**
     * Increments the last part of a version number by one.
     *
     * Only increments if the old version is a stable version. Increments the
     * release state suffix instead otherwise.
     *
     * @param string $version  A version number.
     *
     * @return string  The incremented version number.
     *
     * @throws Exception on invalid version string.
     */
    public static function nextPearVersion($version): string
    {
        if (!preg_match('/^(\d+\.\d+\.)(\d+)(alpha|beta|RC|dev)?(\d*)$/', $version, $match)) {
            throw new Exception('Invalid version number ' . $version);
        }
        if (empty($match[3])) {
            $match[2]++;
            $match[3] = '';
        } elseif (empty($match[4])) {
            $match[4] = '';
        } else {
            $match[4]++;
        }
        return $match[1] . $match[2] . $match[3] . $match[4];
    }

    /**
     * Increments the minor version number by one.
     *
     * If there is a release state suffix on the current version, this will be removed on the next version.
     * The patch version will always be set to 0 for the next version.
     *
     * @param string $version  A version number.
     *
     * @return string  The incremented version number.
     *
     * @throws Exception on invalid version string.
     */
    public static function nextMinorVersion($version): string
    {
        if (!preg_match('/^(\d+\.)(\d+)\.(\d+)(alpha|beta|RC|dev)?(\d*)$/', $version, $match)) {
            throw new Exception('Invalid version number ' . $version);
        }

        return $match[1] . ++$match[2] . '.0';
    }

    /**
     * Increments a version part number by one.
     *
     *
     * @param string $version  A version number.
     * @param string $versionPart The part of the version that should be incremented.
     *
     * @return string  The incremented version number.
     *
     * @throws Exception on invalid version string.
     */
    public static function nextVersionByPart($version, $versionPart = 'patch'): string
    {
        if ($versionPart === 'patch') {
            return self::nextPearVersion($version);
        } elseif ($versionPart === 'minor') {
            return self::nextMinorVersion($version);
        }
        throw new Exception('invalid version part. Only "patch" and "minor" are supported for now.');
    }


    /**
     * Converts (a limited set of) Composer version constraints to PEAR version
     * constraints.
     *
     * @param string $version  Version constraints like '*', '^x.y.z', or
     *                         '^x || ^y.z'.
     *
     * @return array  Version constraints with possible keys 'min', 'max', and
     *                'exclude'.
     */
    public static function composerToPear($version): array
    {
        // Shortcut for any version.
        if ($version == '*') {
            return [];
        }

        // Massage versions by splitting at '||', checking for and removing
        // leading '^', and sorting.
        $versions = \explode('||', $version);
        $versions = \array_map('trim', $versions);
        \array_walk(
            $versions,
            function ($v) use ($version, $versions) {
                if ($v[0] != '^' &&
                    (!preg_match('/^\d+\.\d+\.\d+$/', $version) ||
                     count($versions) > 1)) {
                    throw new Exception(
                        'Unsupport Composer version format: ' . $version
                    );
                }
            }
        );
        \usort(
            $versions,
            fn ($a, $b) => \version_compare(\ltrim((string) $a, '^'), \ltrim((string) $b, '^'))
        );

        $constraints = [];
        if ($versions[0][0] == '^') {
            $constraints['min'] = \preg_replace(
                '/^\^(\d+\.\d+\.\d+).*/',
                '$1',
                $versions[0] . '.0.0'
            );
        } else {
            $constraints['min'] = $constraints['max'] = $versions[0];
            return $constraints;
        }
        $max = \array_pop($versions);
        $max = \substr($max, 1, \strpos($max, '.') ?: \strlen($max)) + 1;
        $max .= '.0.0alpha1';
        $constraints['max'] = $constraints['exclude'] = $max;

        return $constraints;
    }
}
