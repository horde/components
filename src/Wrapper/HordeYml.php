<?php
/**
 * Copyright 2017 Horde LLC (http://www.horde.org/)
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

/**
 * Wrapper for the .horde.yml file.
 *
 * @category   Horde
 * @package    Components
 * @author     Jan Schneider <jan@horde.org>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class HordeYml extends \ArrayObject implements Wrapper, \Stringable
{
    use WrapperTrait;

    /**
     * Constructor.
     *
     * @param string $baseDir Directory with .horde.yml.
     * @throws Exception
     */
    public function __construct(ComponentDirectory|string $baseDir)
    {
        $this->_file = (string) $baseDir . '/.horde.yml';
        if ($this->exists()) {
            try {
                $horde = \Horde_Yaml::loadFile($this->_file);
            } catch (\Horde_Yaml_Exception $e) {
                throw new \Exception($e);
            }
        } else {
            $horde = [];
        }
        parent::__construct($horde);
    }
    
    public function setLicense(License $license)
    {
        $this['license'] = $license->toArray();
    }

    public function getLicense(): License
    {
        // TODO: If missing?
        return new License($this['license']['identifier'] ?? '', $this['license']['uri'] ?? '');
    }

    public function getReleaseVersion(): Version
    {
        return Version::fromComposerString($this['version']['release']);
    }
    public function getApiVersion(): Version
    {
        return Version::fromComposerString($this['version']['api']);
    }

    public function getComponentStability(): string
    {
        return (string) $this['state']['release'];
    }
    public function setReleaseVersionAndStability(Version $version)
    {
        if (empty($this['version']) || empty($this['version']['release'])) {
            $this['version'] = ['release' => $version->toFullSemVerV2()];
        }
        $this['version']['release'] = $version->toFullSemVerV2();
        // Ensure API version exists
        if (empty($this['version']['api'])) {
            $this->setApiVersionAndStability($version);
        }
        return $this;
    }

    public function setApiVersionAndStability(Version $version)
    {
        if (empty($this['version']) || empty($this['version']['api'])) {
            $this['version'] = ['api' => $version->toFullSemVerV2()];
        }
        $this['version']['api'] = $version->toFullSemVerV2();
        // Ensure release version exists
        if (empty($this['version']['release'])) {
            $this->setReleaseVersionAndStability($version);
        }
        return $this;
    }

    public function getComposerName(): string
    {
        $vendor = $this['vendor'] ?? 'horde';
        $package = $this['name'] ?? $this['id'];
        return mb_strtolower($vendor . '/' . $package);
    }

    public function getAllowedPlugins(): array|object
    {
        $allowedPlugins = [];
        if (!empty($this['allow-plugins'])) {
            if ($this['allow-plugins'] === true) {
                $allowedPlugins = ['all' => true];
            } else {
                foreach ($this['allow-plugins'] as $plugin => $bool) {
                    print_r($plugin);
                    if (is_string($plugin)) {
                        $allowedPlugins[$plugin] = (bool) $bool;
                    }
                }
            }
        }
        if (in_array($this['type'], ['component', 'application'])) {
            if (!array_key_exists('horde/horde-installer-plugin', $allowedPlugins)) {
                $allowedPlugins['horde/horde-installer-plugin'] = true;
            }
        }
        return (object)$allowedPlugins;
    }
    /**
     * Returns the file contents.
     */
    public function __toString(): string
    {
        return \Horde_Yaml::dump(
            iterator_to_array($this),
            ['wordwrap' => 78]
        );
    }
}
