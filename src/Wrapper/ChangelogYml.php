<?php

/**
 * Copyright 2017-2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category   Horde
 * @package    Components
 * @author     Jan Schneider <jan@horde.org>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Wrapper;

use Horde\Components\Exception;
use Horde\Components\Wrapper;
use Horde\Components\WrapperTrait;
use Horde\Components\ChangelogEntry;
use Horde\Components\Helper\Version;
use Horde\HordeYmlFile\ChangelogYmlFile as LibraryChangelogYmlFile;
use Horde\HordeYmlFile\InvalidChangelogFileException;

/**
 * Wrapper for the changelog.yml file.
 *
 * Provides components-specific business logic on top of horde/hordeymlfile library.
 *
 * @category   Horde
 * @package    Components
 * @author     Jan Schneider <jan@horde.org>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class ChangelogYml extends \ArrayObject implements Wrapper, \Stringable
{
    use WrapperTrait;

    /**
     * The underlying library object
     */
    private LibraryChangelogYmlFile $changelogYmlFile;

    /**
     * Constructor.
     *
     * @param string $docDir  Directory with changelog.yml.
     * @throws Exception
     */
    public function __construct($docDir)
    {
        $this->_file = $docDir . '/changelog.yml';

        try {
            if ($this->exists()) {
                $this->changelogYmlFile = new LibraryChangelogYmlFile($this->_file);
                // Initialize ArrayObject with library data for backward compatibility
                parent::__construct($this->changelogYmlFile->toArray());
                $this->formatVersions();
            } else {
                // File doesn't exist - initialize with empty array
                // Will be created when save() is called
                parent::__construct([]);
            }
        } catch (InvalidChangelogFileException $e) {
            throw new Exception("Failed to load changelog.yml: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Check if version exists in changelog
     *
     * @param Version $version Version to check
     * @return bool True if version exists
     */
    public function hasVersion(Version $version): bool
    {
        if (!isset($this->changelogYmlFile)) {
            return false;
        }
        return $this->changelogYmlFile->hasVersion($version->toFullSemVerV2());
    }

    /**
     * Add a new changelog entry or update an existing one.
     *
     * @param ChangelogEntry $entry Changelog entry to add
     */
    public function addChangelogEntry(ChangelogEntry $entry): void
    {
        // Ensure library instance exists
        if (!isset($this->changelogYmlFile)) {
            $dir = dirname($this->_file);
            if (!is_dir($dir)) {
                throw new Exception("Directory does not exist: $dir");
            }
            touch($this->_file);
            file_put_contents($this->_file, "---\n");
            $this->changelogYmlFile = new LibraryChangelogYmlFile($this->_file);
        }

        $versionString = $entry->releaseVersion->toFullSemVerV2();
        $entryData = $entry->toChangelogEntryArray();

        $this->changelogYmlFile->addVersionEntry($versionString, $entryData);
        $this->refreshArray();
    }

    /**
     * Make old releases look more like semvers
     *
     * This is components-specific logic for handling legacy version formats.
     */
    private function formatVersions(): void
    {
        foreach ($this->getIterator() as $key => $values) {
            $formattedVersion = Version::fromComposerString($key)->toFullSemVerV2();
            if ($formattedVersion !== $key) {
                $this[$formattedVersion] = $this[$key];
                $this->offsetUnset($key);
                $this[$formattedVersion]['notes'] = 'Original version string was ' . $key . "\n" . $this[$formattedVersion]['notes'];
                // Need to rewind, otherwise we will "skip" versions on upgrade
                $this->formatVersions();
                break; // formatVersions will be called recursively
            }
        }
    }

    /**
     * Refresh the ArrayObject data from library
     *
     * Called after modifications to keep ArrayObject in sync
     */
    private function refreshArray(): void
    {
        if (isset($this->changelogYmlFile)) {
            $this->exchangeArray($this->changelogYmlFile->toArray());
        }
    }

    /**
     * Sync ArrayObject changes back to library before save
     */
    private function syncToLibrary(): void
    {
        if (!isset($this->changelogYmlFile)) {
            return;
        }

        // If ArrayObject was modified directly (legacy code path),
        // sync changes back to library
        $currentArray = $this->getArrayCopy();

        // Clear and rebuild from ArrayObject
        // Note: This is a bit hacky but maintains backward compatibility
        // The library's addVersionEntry will handle sorting
        foreach ($currentArray as $version => $entry) {
            $this->changelogYmlFile->addVersionEntry($version, $entry);
        }
    }

    /**
     * Save the file
     */
    public function save(): void
    {
        // Create library instance if not already created (for new files)
        if (!isset($this->changelogYmlFile)) {
            $dir = dirname($this->_file);
            if (!is_dir($dir)) {
                throw new Exception("Directory does not exist: $dir");
            }
            touch($this->_file);
            file_put_contents($this->_file, "---\n");
            $this->changelogYmlFile = new LibraryChangelogYmlFile($this->_file);
        }

        $this->syncToLibrary();
        $this->changelogYmlFile->save();
        $this->refreshArray();
    }

    /**
     * Returns the file contents as YAML
     *
     * @return string YAML representation
     */
    public function __toString(): string
    {
        if (!isset($this->changelogYmlFile)) {
            return "---\n";
        }

        // Sort before output (components-specific: newest first)
        $this->uksort(function ($a, $b) {
            return strnatcmp($b, $a);
        });

        $this->syncToLibrary();
        return (string) $this->changelogYmlFile;
    }
}
