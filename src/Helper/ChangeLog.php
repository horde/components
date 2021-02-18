<?php
/**
 * Copyright 2010-2020 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @author   Jan Schneider <jan@horde.org>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components\Helper;
use Horde\Components\Component;
use Horde\Components\Config;
use Horde\Components\Exception;
use Horde\Components\Helper\Version as HelperVersion;

/**
 * Helper for adding entries to the change log(s).
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @author   Jan Schneider <jan@horde.org>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class ChangeLog
{
    /**
     * The path to the component directory.
     *
     * @var string
     */
    protected $_directory;

    /**
     * The component object.
     *
     * @var Component
     */
    protected $_component;

    /**
     * Constructor.
     *
     * @param Config $config        The configuration.
     * @param Component $component  A component object.
     */
    public function __construct(Config $config, Component $component)
    {
        $this->_directory = $config->getPath();
        $this->_component = $component;
    }

    /* changelog.yml methods */

    /**
     * Update changelog.yml file.
     *
     * @param string $log      The log entry.
     * @param array  $options  Additional options.
     *
     * @return string  Path to the updated changelog.yml file.
     */
    public function changelogYml($log, $options)
    {
        if (!strlen($log)) {
            return;
        }

        if ($changelog = $this->changelogFileExists()) {
            if (empty($options['pretend'])) {
                $this->addChangelog($log);
            }
            return $changelog;
        }
    }

    /**
     * Indicates if there is a changelog.yml file for this component.
     *
     * @return string|boolean The path to the changelog.yml file if it exists,
     *                        false otherwise.
     */
    public function changelogFileExists()
    {
        $changelog = $this->_component->getWrapper('ChangelogYml');

        if ($changelog->exists()) {
            return $changelog->getFullPath();
        }
        return false;
    }

    /**
     * Add a change log entry to changelog.yml
     *
     * @param string $entry  Change log entry to add.
     */
    public function addChangelog($entry)
    {
        $hordeInfo = $this->_component->getWrapper('HordeYml');
        if (!isset($hordeInfo['version'])) {
            throw new Exception('.horde.yml is missing a \'version\' entry');
        }
        $version = $hordeInfo['version']['release'];
        $changelog = $this->_component->getWrapper('ChangelogYml');
        $info = $changelog[$version];
        $notes = trim($info['notes']);
        $notes = $notes ? explode("\n", $notes) : array();
        array_unshift($notes, $entry);
        $info['notes'] = implode("\n", $notes) . "\n";
        $changelog[$version] = $info;
    }

    /**
     * Changes the current version number in changelog.yml.
     *
     * It's important to run this method *before* updating the version in
     * .horde.yml, because the old, to-be-replaced version is retrieved from
     * there.
     *
     * @param string $version  The new release version.
     * @param string $api      The new api version.
     */
    public function setVersion($version, $api)
    {
        $hordeInfo = $this->_component->getWrapper('HordeYml');
        if (!isset($hordeInfo['version'])) {
            throw new Exception('.horde.yml is missing a \'version\' entry');
        }
        $oldVersion = $hordeInfo['version']['release'];
        $changelog = $this->_component->getWrapper('ChangelogYml');
        $newChangelog = array();
        array_walk(
            $changelog,
            function($entry, $ver)
                use (&$newChangelog, $oldVersion, $version, $api)
            {
                if ($ver == $oldVersion) {
                    $ver = $version;
                    if ($api) {
                        $entry['api'] = $api;
                    }
                }
                $newChangelog[$ver] = $entry;
            }
        );
        $changelog->exchangeArray($newChangelog);
    }

    /**
     * Timestamps the current version in changelog.yml.
     */
    public function timestamp()
    {
        $hordeInfo = $this->_component->getWrapper('HordeYml');
        if (!isset($hordeInfo['version'])) {
            throw new Exception(
                '.horde.yml is missing a \'version\' entry'
            );
        }
        $version = $hordeInfo['version']['release'];
        $changelog = $this->_component->getWrapper('ChangelogYml');
        if (!isset($changelog[$version])) {
            throw new Exception(
                'changelog.yml is missing the version ' . $version
            );
        }
        $changelog[$version]['date'] = gmdate('Y-m-d');
    }

    /**
     * Builds a changelog.yml from an existing package.xml.
     *
     * @param \Horde_Pear_Package_Xml $xml  The package xml handler.
     */
    public function migrateToChangelogYml($xml)
    {
        $changes = array();

        // Import releases from package.xml.
        foreach ($xml->findNodes('/p:package/p:changelog/p:release') as $release) {
            $version = $xml->getNodeTextRelativeTo(
                'p:version/p:release', $release
            );
            $license = $xml->findNodeRelativeTo('p:license', $release);
            $notes = trim(preg_replace(
                '/^\* /m',
                '',
                $xml->getNodeTextRelativeTo('p:notes', $release)
            ));
            if ($notes) {
                $notes .= "\n";
            }
            $changes[$version] = array(
                'api' => $xml->getNodeTextRelativeTo(
                    'p:version/p:api', $release
                ),
                'state' => array(
                    'release' => $xml->getNodeTextRelativeTo(
                        'p:stability/p:release', $release
                    ),
                    'api' => $xml->getNodeTextRelativeTo(
                        'p:stability/p:api', $release
                    ),
                ),
                'date' => $xml->getNodeTextRelativeTo('p:date', $release),
                'license' => array(
                    'identifier' => $license->textContent,
                    'uri' => $license->getAttribute('uri')
                ),
                'notes' => $notes,
            );
        }
        $changes = \array_reverse($changes);

        // Import releases from CHANGES.
        $changesFile = $this->_component->getWrapper('Changes');
        if ($changesFile->exists()) {
            $inHeader = $maybeHeader = $version = false;
            foreach ($changesFile as $line) {
                if (!strcspn($line, '-')) {
                    if ($inHeader) {
                        $inHeader = false;
                    } else {
                        $maybeHeader = $line;
                    }
                    continue;
                }
                if ($maybeHeader) {
                    if (preg_match('/v([.\d]*?)(-git)?\n/m', $line, $match)) {
                        $inHeader = true;
                        if ($version && !isset($changes[$version])) {
                            $changes[$version] = array(
                                'notes' => trim($notes) . "\n"
                            );
                        }
                        $notes = '';
                        $version = $match[1];
                        $maybeHeader = false;
                        continue;
                    } else {
                        $notes .= $maybeHeader;
                        $maybeHeader = false;
                    }
                }
                if (strpos($line, '      ') === 0) {
                    $line = ltrim($line);
                    $notes = substr($notes, 0, -1) . ' ';
                }
                $notes .= $line;
            }
            if ($version && !isset($changes[$version])) {
                $changes[$version] = array('notes' => trim($notes) . "\n");
            }
        }

        // Create changelog.yml.
        $changelog = $this->_component->getWrapper('ChangelogYml');
        $changelog->exchangeArray($changes);
    }

    /* package.xml methods */

    /**
     * Update package.xml file.
     *
     * @param string                 $log  The log entry.
     * @param \Horde_Pear_Package_Xml $xml  The package xml handler.
     *
     * @return string  Path to the updated package.xml file.
     */
    public function packageXml($log, $xml)
    {
        if ($xml->exists()) {
            $xml->addNote($log);
            return $xml->getFullPath();
        }
    }

    /**
     * Updates package.xml from changelog.yml.
     *
     * @param \Horde_Pear_Package_Xml $xml  The package xml handler.
     *
     * @return string  Path to the updated package.xml file.
     */
    public function updatePackage($xml)
    {
        $allchanges = $this->_component->getWrapper('ChangelogYml');
        if (!$allchanges->exists() || !$xml->exists()) {
            return;
        }

        $changes = array();
        foreach ($allchanges as $version => $info) {
            if ($version == 'extra') {
                continue;
            }
            try {
                $version = HelperVersion::validatePear($version);
                $changes[$version] = $info;
            } catch (Exception $e) {
                break;
            }
        }
        $xml->setNotes(array_reverse($changes));

        return $xml->getFullPath();
    }

    /* CHANGES methods */

    /**
     * Returns the link to the CHANGES file on GitHub.
     *
     * @param string $root  The root of the component in the repository.
     *
     * @return string  The link to the change log.
     */
    public function getChangelogLink($root)
    {
        if ($changes = $this->changesFileExists()) {
            $hordeInfo = $this->_component->getWrapper('HordeYml');
            $blob = trim(
                $this->_systemInDirectory(
                    'git log --format="%H" HEAD^..HEAD',
                    $this->_directory
                )
            );

            // special case of the horde base application
            // @todo better solution for this. Can't change the 'id' attribute
            // since that's also used elsewhere, like in the package.xml
            // generation.
            $id = $hordeInfo['id'] == 'horde' ? 'base' : $hordeInfo['id'];

            $changes = preg_replace('#^' . $this->_directory . '#', '', $changes);

            return 'https://github.com/horde/' . $id . '/blob/'
                . $blob . $changes;
        }
        return '';
    }

    /**
     * Indicates if there is a CHANGES file for this component.
     *
     * @return string|boolean The path to the CHANGES file if it exists, false
     *                        otherwise.
     */
    public function changesFileExists()
    {
        $changes = $this->_component->getWrapper('Changes');
        if ($changes->exists()) {
            return $changes->getFullPath();
        }
        return false;
    }

    /**
     * Updates CHANGES from changelog.yml.
     *
     * @return string|null  Path to the updated CHANGES file.
     */
    public function updateChanges()
    {
        $allchanges = $this->_component->getWrapper('ChangelogYml');
        if (!$allchanges->exists()) {
            return;
        }

        $hordeInfo = $this->_component->getWrapper('HordeYml');
        if (!isset($hordeInfo['version'])) {
            throw new Exception('.horde.yml is missing a \'version\' entry');
        }

        $changes = $this->_component->getWrapper('Changes');
        $changes->clear();

        $started = false;
        foreach ($allchanges as $version => $info) {
            if ($version == 'extra') {
                $changes->add($info);
                continue;
            }
            if (!$started && $version != $hordeInfo['version']['release']) {
                continue;
            }
            if ($started) {
                $changes->add("\n\n");
            }
            $started = true;
            $version = 'v' . $version;
            $lines = str_repeat('-', strlen($version)) . "\n";
            $changes->add($lines . $version . "\n" . $lines);

            if (!$info['notes']) {
                continue;
            }
            $notes = explode("\n", $info['notes']);
            foreach ($notes as $entry) {
                if (preg_match('/^\[.*?\] (.*)$/', $entry, $match, PREG_OFFSET_CAPTURE) ||
                    preg_match('/^[A-Z]{3,}: (.*)$/', $entry, $match, PREG_OFFSET_CAPTURE)) {
                    $indent = $match[1][1];
                } else {
                    $indent = 6;
                }
                $entry = \Horde_String::wrap($entry, 79, "\n" . str_repeat(' ', $indent));
                $changes->add("\n" . $entry);
            }
        }

        return $changes->getFullPath();
    }

    /**
     * Run a system call.
     *
     * @param string $call       The system call to execute.
     * @param string $target_dir Run the command in the provided target path.
     *
     * @return string The command output.
     */
    protected function _systemInDirectory($call, $target_dir)
    {
        $old_dir = getcwd();
        chdir($target_dir);
        $result = exec($call);
        chdir($old_dir);
        return $result;
    }
}
