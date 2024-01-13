<?php
/**
 * Copyright 2011-2024 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Release;

use Horde\Components\Component;
use Horde\Components\Helper\Version as HelperVersion;
use Horde\Components\Output;
use Horde\Components\Exception;

/**
 * This class deals with the information associated to a release.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Notes
{
    /**
     * The release information.
     *
     * @var array
     */
    protected $_notes = [];

    /**
     * The component that should be released
     *
     * @var Component
     */
    protected $_component;

    /**
     * Constructor.
     *
     * @param Output $_output Accepts output.
     */
    public function __construct(protected Output $_output)
    {
    }

    /**
     * Set the component this task should act upon.
     *
     * @param Component $component The component to be released.
     */
    public function setComponent(Component $component): void
    {
        $this->_component = $component;
        $this->_setReleaseNotes();
    }

    /**
     * Populates the release information for the current component.
     */
    protected function _setReleaseNotes(): void
    {
        $prerelease = null;
        if (!($file = $this->_component->getReleaseNotesPath())) {
            return;
        }
        if (basename($file) == 'release.yml') {
            $version = HelperVersion::parsePearVersion(
                $this->_component->getVersion()
            );
            $description = \Horde_String::lower($version->description);
            if (!str_contains($description, 'release')) {
                $description .= ' release';
            }
            $info = $this->_component->getWrapper('HordeYml');
            $this->_notes['name'] = $info['name'];
            if (isset($info['list'])) {
                $this->_notes['list'] = $info['list'];
            }
            try {
                $release = \Horde_Yaml::loadFile($file);
            } catch (\Horde_Yaml_Exception $e) {
                throw new Exception($e);
            }
            if (isset($release['branch'])) {
                $this->_notes['branch'] = $release['branch'];
            }
            $this->_notes['security'] = $release['security'];
            if (is_array($release['changes'])) {
                if (!is_array(reset($release['changes']))) {
                    $release['changes'] = [$release['changes']];
                }
            } else {
                $release['changes'] = [];
            }
            $currentSection = null;
            $changes = '';
            foreach ($release['changes'] as $section => $sectionChanges) {
                if ($section != $currentSection) {
                    $changes .= "\n\n" . $section . ':';
                    $currentSection = $section;
                }
                foreach ($sectionChanges as $change) {
                    $changes .= "\n    * " . $change;
                }
            }
            switch ($version->description) {
                case 'Final':
                    $prerelease = '';
                    break;
                case 'Alpha':
                case 'Beta':
                    $prerelease = '
This is a preview version that should not be used on production systems. This version is considered feature complete but there might still be a few bugs. You should not use this preview version over existing production data.

We encourage widespread testing and feedback via the mailing lists or our bug tracking system. Updated translations are very welcome, though some strings might still change before the final release.
';
                    break;
                case 'Release Candidate':
                    $prerelease = sprintf(
                        '
Barring any problems, this code will be released as %s %s.
Testing is requested and comments are encouraged. Updated translations would also be great.
',
                        $info['name'],
                        $version->version
                    );
                    break;
            }
            $this->_notes['changes'] = sprintf(
                'The Horde Team is pleased to announce the %s%s of the %s version %s.

%s
%s
For upgrading instructions, please see
http://www.horde.org/apps/%s/docs/UPGRADING

For detailed installation and configuration instructions, please see
http://www.horde.org/apps/%s/docs/INSTALL
%s
The major changes compared to the %s version %s are:%s',
                $version->subversion
                    ? \NumberFormatter::create('en_US', \NumberFormatter::ORDINAL)
                        ->format($version->subversion) . ' '
                    : '',
                $description,
                $info['full'],
                $version->version,
                $info['description'],
                $prerelease,
                $info['id'],
                $info['id'],
                !empty($release['additional'])
                    ? "\n" . implode("\n\n", $release['additional']) . "\n"
                    : '',
                $info['name'],
                $this->_component->getPreviousVersion(),
                $changes
            );
        } else {
            $this->_notes = include $file;
        }
    }

    /**
     * The branch information for this component. This is empty for framework
     * components and the Horde base application and has a value like "H3",
     * "H4", etc. for applications.
     *
     * @return string The branch name.
     */
    public function getBranch(): string
    {
        if (!empty($this->_notes['branch']) &&
            $this->_notes['name'] != \Horde::class) {
            return strtr($this->_notes['branch'], ['Horde ' => 'H']);
        }
        return '';
    }

    /**
     * Returns the release name.
     *
     * @return string The release name.
     */
    public function getName()
    {
        if (isset($this->_notes['name'])) {
            return $this->_notes['name'];
        }
        return $this->_component->getName();
    }

    /**
     * Returns the specific mailing list that the release announcement for this
     * component should be sent to.
     *
     * @return string|null The mailing list.
     */
    public function getList()
    {
        if (isset($this->_notes['list'])) {
            return $this->_notes['list'];
        }
    }

    /**
     * Returns whether the release is a security release.
     *
     * @return bool  A security release?
     */
    public function getSecurity(): bool
    {
        return !empty($this->_notes['security']);
    }

    /**
     * Return the announcement text.
     *
     * @return string The text.
     */
    public function getAnnouncement()
    {
        if (isset($this->_notes['changes'])) {
            return $this->_notes['changes'];
        }
        return '';
    }

    /**
     * Does the current component come with release notes?
     *
     * @return bool True if release notes are available.
     */
    public function hasNotes(): bool
    {
        return !empty($this->_notes['changes']);
    }
}
