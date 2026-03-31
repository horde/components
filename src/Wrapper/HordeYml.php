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

use Horde\Components\Helper\Version;
use Horde\Components\Exception;
use Horde\Components\Wrapper;
use Horde\Components\WrapperTrait;
use Horde\Components\Component\ComponentDirectory;
use Horde\Components\License;
use Horde\HordeYmlFile\HordeYmlFile as LibraryHordeYmlFile;
use Horde\HordeYmlFile\InvalidHordeYmlFileException;
use ArrayObject;
use Stringable;

/**
 * Wrapper for the .horde.yml file.
 *
 * Provides components-specific business logic on top of horde/hordeymlfile library.
 *
 * @category   Horde
 * @package    Components
 * @author     Jan Schneider <jan@horde.org>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class HordeYml extends ArrayObject implements Wrapper, Stringable
{
    use WrapperTrait;

    /**
     * The underlying library object
     */
    private LibraryHordeYmlFile $hordeYmlFile;

    /**
     * Constructor.
     *
     * @param string $baseDir Directory with .horde.yml.
     * @throws Exception
     */
    public function __construct(ComponentDirectory|string $baseDir)
    {
        $this->_file = (string) $baseDir . '/.horde.yml';

        try {
            if ($this->exists()) {
                $this->hordeYmlFile = new LibraryHordeYmlFile($this->_file);
                // Apply graceful defaults for missing fields
                $this->hordeYmlFile->applyGracefulUpdates();
            } else {
                // Create empty file for new components
                // Suppress warning if directory is not writable
                @touch($this->_file);
                if (file_exists($this->_file)) {
                    $this->hordeYmlFile = new LibraryHordeYmlFile($this->_file);
                    // Apply graceful defaults for missing fields
                    $this->hordeYmlFile->applyGracefulUpdates();
                }
            }
        } catch (InvalidHordeYmlFileException $e) {
            throw new Exception("Failed to load .horde.yml: " . $e->getMessage(), 0, $e);
        }

        // Initialize ArrayObject with library data for backward compatibility
        // Only if we successfully created the hordeYmlFile
        if (isset($this->hordeYmlFile)) {
            parent::__construct($this->hordeYmlFile->toArray());
        } else {
            parent::__construct([]);
        }
    }

    /**
     * Set license information
     *
     * @param License $license License object
     */
    public function setLicense(License $license): void
    {
        $this->hordeYmlFile->setLicense(
            $license->getIdentifier(),
            $license->getUri()
        );
        $this->refreshArray();
    }

    /**
     * Get license information
     *
     * @return License License object
     */
    public function getLicense(): License
    {
        $lic = $this->hordeYmlFile->getLicense();
        return new License(
            $lic->identifier ?? '',
            $lic->uri ?? ''
        );
    }

    /**
     * Get release version as Version object
     *
     * @return Version Release version
     */
    public function getReleaseVersion(): Version
    {
        $versionString = $this->hordeYmlFile->getReleaseVersion();
        if (empty($versionString)) {
            throw new \Exception('HordeYml: getReleaseVersion() returned empty string from library');
        }
        return Version::fromComposerString($versionString);
    }

    /**
     * Get API version as Version object
     *
     * @return Version API version
     */
    public function getApiVersion(): Version
    {
        $versionString = $this->hordeYmlFile->getApiVersion();
        if (empty($versionString)) {
            throw new \Exception('HordeYml: getApiVersion() returned empty string from library');
        }
        return Version::fromComposerString($versionString);
    }

    /**
     * Get component stability
     *
     * @return string Stability (alpha, beta, stable)
     */
    public function getComponentStability(): string
    {
        return $this->hordeYmlFile->getReleaseState();
    }

    /**
     * Set release version and stability
     *
     * @param Version $version Version object
     * @return self
     */
    public function setReleaseVersionAndStability(Version $version): self
    {
        $this->hordeYmlFile->setReleaseVersion($version->toFullSemVerV2());
        $this->hordeYmlFile->setReleaseState($version->getStability());

        // Ensure API version exists
        if (!$this->hordeYmlFile->getApiVersion()) {
            $this->setApiVersionAndStability($version);
        }

        $this->refreshArray();
        return $this;
    }

    /**
     * Set API version and stability
     *
     * @param Version $version Version object
     * @return self
     */
    public function setApiVersionAndStability(Version $version): self
    {
        $this->hordeYmlFile->setApiVersion($version->toFullSemVerV2());
        $this->hordeYmlFile->setApiState($version->getStability());

        // Ensure release version exists
        if (!$this->hordeYmlFile->getReleaseVersion()) {
            $this->setReleaseVersionAndStability($version);
        }

        $this->refreshArray();
        return $this;
    }

    /**
     * Get composer package name
     *
     * @return string Composer name (e.g., "horde/components")
     */
    public function getComposerName(): string
    {
        return $this->hordeYmlFile->getComposerName();
    }

    /**
     * Get component name
     *
     * @return string Component name
     */
    public function getName(): string
    {
        $name = $this->hordeYmlFile->getName();
        if (!$name) {
            $name = $this->hordeYmlFile->getId();
        }
        return $name ?: 'unknown';
    }

    /**
     * Get allowed Composer plugins
     *
     * Components-specific logic: auto-adds horde-installer-plugin for components/applications
     *
     * @return array|object Allowed plugins
     */
    public function getAllowedPlugins(): array|object
    {
        $allowedPlugins = $this->hordeYmlFile->getAllowedPlugins();

        // Components-specific: auto-add horde-installer-plugin for components/applications
        $type = $this->hordeYmlFile->getType();
        if (in_array($type, ['component', 'application'])) {
            if (!array_key_exists('horde/horde-installer-plugin', $allowedPlugins)) {
                $allowedPlugins['horde/horde-installer-plugin'] = true;
            }
        }

        return (object) $allowedPlugins;
    }

    /**
     * Get the underlying HordeYmlFile library object
     */
    public function getHordeYmlFile(): LibraryHordeYmlFile
    {
        return $this->hordeYmlFile;
    }

    /**
     * Refresh the ArrayObject data from library
     *
     * Called after modifications to keep ArrayObject in sync
     */
    private function refreshArray(): void
    {
        $this->exchangeArray($this->hordeYmlFile->toArray());
    }

    /**
     * Sync ArrayObject changes back to library before save
     */
    private function syncToLibrary(): void
    {
        // If ArrayObject was modified directly (legacy code path),
        // sync changes back to library
        $currentArray = $this->getArrayCopy();
        foreach ($currentArray as $key => $value) {
            $this->hordeYmlFile->set($key, $value);
        }
    }

    /**
     * Save the file
     */
    public function save(): void
    {
        // NOTE: We don't call syncToLibrary() here because:
        // 1. We're using typed setters (setReleaseVersion, etc.) which update the library directly
        // 2. syncToLibrary() would call $hordeYmlFile->set() which has bugs with nested structures
        // 3. If legacy code modifies the ArrayObject directly, they need to use specific setters
        $this->hordeYmlFile->save();
        $this->refreshArray();
    }

    /**
     * Returns the file contents as YAML
     *
     * @return string YAML representation
     */
    public function __toString(): string
    {
        $this->syncToLibrary();
        return (string) $this->hordeYmlFile;
    }
}
