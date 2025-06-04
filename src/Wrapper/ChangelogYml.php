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

use Horde\Components\Exception;
use Horde\Components\Wrapper;
use Horde\Components\WrapperTrait;
use Horde\Components\ChangelogEntry;
use Horde\Components\Helper\Version;

/**
 * Wrapper for the changelog.yml file.
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
     * Constructor.
     *
     * @param string $docDir  Directory with changelog.yml.
     */
    public function __construct($docDir)
    {
        $this->_file = $docDir . '/changelog.yml';
        if ($this->exists()) {
            try {
                $changelog = \Horde_Yaml::loadFile($this->_file);
            } catch (\Horde_Yaml_Exception $e) {
                throw new Exception($e);
            }
        } else {
            $changelog = [];
        }
        parent::__construct($changelog);
        $this->formatVersions();
    }


    public function hasVersion(Version $version)
    {
        // TODO: Legacy formats
        return isset($this[$version->toFullSemVerV2()]);
    }
    /**
     * Add a new changelog entry or update an existing one.
     */
    public function addChangelogEntry(ChangelogEntry $entry)
    {
        // Constructor already took care of legacy formats so we will never have duplicate versions
        $this[$entry->releaseVersion->toFullSemVerV2()] = $entry->toChangelogEntryArray();
    }

    /**
     * Make old releases look more like semvers
     */
    private function formatVersions()
    {
        foreach ($this->getIterator() as $key => $values) {
            $formattedVersion = Version::fromComposerString($key)->toFullSemVerV2();
            if ($formattedVersion !== $key) {
                $this[$formattedVersion] = $this[$key];
                $this->offsetUnset($key);
                $this[$formattedVersion]['notes'] = 'Original version string was ' . $key . "\n" . $this[$formattedVersion]['notes'];
                // Need to rewind, otherwise we will "skip" versions on upgrade
                $this->formatVersions();
            }
        }
    }
    /**
     * Returns the file contents.
     */
    public function __toString(): string
    {
        $this->uksort(function ($a, $b) { return strnatcmp($b, $a);});
        return \Horde_Yaml::dump(
            iterator_to_array($this),
            ['wordwrap' => 0]
        );
    }
}
