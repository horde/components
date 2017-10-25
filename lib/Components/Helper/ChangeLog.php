<?php
/**
 * Components_Helper_ChangeLog:: helps with adding entries to the change log(s).
 *
 * PHP version 5
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

/**
 * Components_Helper_ChangeLog:: helps with adding entries to the change log(s).
 *
 * Copyright 2010-2017 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Components_Helper_ChangeLog
{
    /** Path to the .horde.yml file. */
    const HORDE_INFO = '/.horde.yml';

    /** Path to the changelog.yml file. */
    const CHANGELOG = '/changelog.yml';

    /** Path to the CHANGES file. */
    const CHANGES = '/CHANGES';

    /**
     * The output handler.
     *
     * @var Component_Output
     */
    protected $_output;

    /**
     * The path to the component directory.
     *
     * @var string
     */
    protected $_directory;

    /**
     * Constructor.
     *
     * @param Component_Output $output   The output handler.
     * @param Components_Config $config  The configuration.
     */
    public function __construct(
        Components_Output $output, Components_Config $config
    )
    {
        $this->_output = $output;
        $this->_directory = $config->getPath();
    }

    /* changelog.yml methods */

    /**
     * Update changelog.yml file.
     *
     * @param string $log         The log entry.
     * @param array  $options     Additional options.
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
                $version = $this->addChangelog($log);
                $this->_output->ok(
                    sprintf(
                        'Added new note to version %s of %s.',
                        $version,
                        $changelog
                    )
                );
            } else {
                $this->_output->info(
                    sprintf(
                        'Would add change log entry to %s now.',
                        $changelog
                    )
                );
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
        $changes = $this->_getDocDirectory() . self::CHANGELOG;
        if (file_exists($changes)) {
            return $changes;
        }
        return false;
    }

    /**
     * Add a change log entry to changelog.yml
     *
     * @param string $entry  Change log entry to add.
     *
     * @returns string  The updated version.
     */
    public function addChangelog($entry)
    {
        $hordeInfo = $this->_getHordeInfo();
        if (!isset($hordeInfo['version'])) {
            throw new Components_Exception('.horde.yml is missing a \'version\' entry');
        }
        $version = $hordeInfo['version']['release'];
        $changelog = Horde_Yaml::loadFile(
            $this->_getDocDirectory() . self::CHANGELOG
        );
        $info = $changelog[$version];
        $notes = explode("\n", trim($info['notes']));
        array_unshift($notes, $entry);
        $info['notes'] = implode("\n", $notes) . "\n";
        $changelog[$version] = $info;
        file_put_contents(
            $this->_getDocDirectory() . self::CHANGELOG,
            Horde_Yaml::dump($changelog, array('wordwrap' => 0))
        );
        return $version;
    }

    /**
     * Builds a changelog.yml from an existing package.xml.
     *
     * @param Horde_Pear_Package_Xml $xml  The package xml handler.
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
        $changes = array_reverse($changes);

        // Import releases from CHANGES.
        if ($changesFile = $this->changesFileExists()) {
            $fp = fopen($changesFile, 'r');
            $inHeader = $version = false;
            while ($line = fgets($fp)) {
                if (!strcspn($line, '-')) {
                    $inHeader = !$inHeader;
                    continue;
                }
                if (!trim($line)) {
                    continue;
                }
                if ($inHeader) {
                    if ($version && !isset($changes[$version])) {
                        $changes[$version] = array('notes' => $notes);
                    }
                    $notes = '';
                    $version = preg_replace('/v(.*?)(-git)?\n/m', '$1', $line);
                    continue;
                }
                if (strpos($line, '      ') === 0) {
                    $line = ltrim($line);
                    $notes = substr($notes, 0, -1) . ' ';
                }
                $notes .= $line;
            }
            if ($version && !isset($changes[$version])) {
                $changes[$version] = array('notes' => $notes);
            }
            fclose($fp);
        }

        // Create changelog.yml.
        $changelog = $this->_getDocDirectory(true) . self::CHANGELOG;
        file_put_contents(
            $changelog,
            Horde_Yaml::dump($changes, array('wordwrap' => 0))
        );
        $this->_output->ok(sprintf('Created %s.', $changelog));
    }

    /* package.xml methods */

    /**
     * Update package.xml file.
     *
     * @param string                 $log     The log entry.
     * @param Horde_Pear_Package_Xml $xml     The package xml handler.
     * @param string                 $file    Path to the package.xml.
     * @param array                  $options Additional options.
     *
     * @return string  Path to the updated package.xml file.
     */
    public function packageXml($log, $xml, $file, $options)
    {
        if (file_exists($file)) {
            if (empty($options['pretend'])) {
                $xml->addNote($log);
                file_put_contents($file, (string)$xml);
                $this->_output->ok(
                    'Added new note to version ' . $xml->getVersion() . ' of ' . $file . '.'
                );
            } else {
                $this->_output->info(
                    sprintf(
                        'Would add change log entry to %s now.',
                        $file
                    )
                );
            }
            return $file;
        }
    }

    /**
     * Updates package.xml from changelog.yml.
     *
     * @param Horde_Pear_Package_Xml $xml     The package xml handler.
     * @param string                 $file    Path to the package.xml.
     * @param array                  $options Additional options.
     *
     * @return string  Path to the updated package.xml file.
     */
    public function updatePackage($xml, $file, $options)
    {
        $changelog = $this->changelogFileExists();
        if (!$changelog || !file_exists($file)) {
            return;
        }

        if (empty($options['pretend'])) {
            $allchanges = Horde_Yaml::loadFile($changelog);
            $xml->setNotes($allchanges);
            file_put_contents($file, (string)$xml);
            $this->_output->ok(sprintf('Updated %s.', $file));
        } else {
            $this->_output->info(sprintf('Would update %s now.', $file));
        }

        return $file;
    }

    /* CHANGES methods */

    /**
     * Update CHANGES file.
     *
     * @param string $log         The log entry.
     * @param array  $options     Additional options.
     *
     * @return string  Path to the updated CHANGES file.
     */
    public function changes($log, $options)
    {
        if ($changes = $this->changesFileExists()) {
            if (empty($options['pretend'])) {
                $this->addChange($log, $changes);
                $this->_output->ok(
                    sprintf(
                        'Added new note to %s.',
                        $changes
                    )
                );
            } else {
                $this->_output->info(
                    sprintf(
                        'Would add change log entry to %s now.',
                        $changes
                    )
                );
            }
            return $changes;
        }
    }

    /**
     * Returns the link to the CHANGES file on GitHub.
     *
     * @param string $root  The root of the component in the repository.
     *
     * @return string  The link to the change log.
     */
    public function getChangelog($root)
    {
        if ($changes = $this->changesFileExists()) {
            $blob = trim(
                $this->_systemInDirectory(
                    'git log --format="%H" HEAD^..HEAD',
                    $this->_directory,
                    array()
                )
            );
            $changes = preg_replace('#^' . $this->_directory . '#', '', $changes);
            return 'https://github.com/horde/horde/blob/' . $blob . $root . $changes;
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
        $changes = $this->_getDocDirectory() . self::CHANGES;
        if (file_exists($changes)) {
            return $changes;
        }
        return false;
    }

    /**
     * Add a change log entry to CHANGES
     *
     * @param string $entry   Change log entry to add.
     * @param string $changes Path to the CHANGES file.
     */
    public function addChange($entry, $changes)
    {
        $tmp = Horde_Util::getTempFile();
        $entry = Horde_String::wrap($entry, 79, "\n      ");

        $oldfp = fopen($changes, 'r');
        $newfp = fopen($tmp, 'w');
        $counter = 0;
        while ($line = fgets($oldfp)) {
            if ($counter == 4) {
                fwrite($newfp, $entry . "\n");
            }
            $counter++;
            fwrite($newfp, $line);
        }
        fclose($oldfp);
        fclose($newfp);
        system("mv -f $tmp $changes");
    }

    /**
     * Updates CHANGES from changelog.yml.
     *
     * @param array $options  Additional options.
     *
     * @return string  Path to the updated CHANGES file.
     */
    public function updateChanges($options)
    {
        $changelog = $this->changelogFileExists();
        if (!$changelog) {
            return;
        }

        $hordeInfo = $this->_getHordeInfo();
        if (!isset($hordeInfo['version'])) {
            throw new Components_Exception('.horde.yml is missing a \'version\' entry');
        }

        $changes = $this->changesFileExists();
        if (!$changes) {
            $changes = $this->_getDocDirectory(true) . self::CHANGES;
        }

        $allchanges = Horde_Yaml::loadFile($changelog);

        if (empty($options['pretend'])) {
            $changesfp = fopen($changes, 'w');
            $started = false;

            foreach ($allchanges as $version => $info) {
                if (!$started && $version != $hordeInfo['version']['release']) {
                    continue;
                }
                if (!$started) {
                    $version .= '-git';
                } else {
                    fwrite($changesfp, "\n\n");
                }
                $started = true;
                $version = 'v' . $version;
                $lines = str_repeat('-', strlen($version)) . "\n";
                fwrite($changesfp, $lines . $version . "\n" . $lines);

                $notes = explode("\n", $info['notes']);
                foreach ($notes as $entry) {
                    $entry = Horde_String::wrap($entry, 79, "\n      ");
                    fwrite($changesfp, "\n" . $entry);
                }
            }
            fclose($changesfp);
            $this->_output->ok(
                sprintf(
                    'Updated %s.',
                    $changes
                )
            );
        } else {
            $this->_output->info(
                sprintf(
                    'Would update %s now.',
                    $changes
                )
            );
        }

        return $changes;
    }

    /**
     * Returns the parsed information from the .horde.yml file.
     *
     * @return array  A Horde component information hash.
     */
    protected function _getHordeInfo()
    {
        $path = $this->_directory . self::HORDE_INFO;
        if (!file_exists($path)) {
            throw new Components_Exception($path . ' not found.');
        }
        return Horde_Yaml::loadFile($path);
    }

    /**
     * Returns the path to the documenation directory, if it exists.
     *
     * @param boolean $mkdir  Create the directory if it doesn't exist?
     *
     * @return string|boolean  The directory name or false if not found and not
     *                         created.
     */
    protected function _getDocDirectory($mkdir = false)
    {
        if (is_dir($this->_directory . '/doc')) {
            $dir = $this->_directory . '/doc';
        } elseif (is_dir($this->_directory . '/docs')) {
            $dir = $this->_directory . '/docs';
        } elseif ($mkdir) {
            $dir = $this->_directory . '/doc';
        } else {
            return false;
        }
        $info = $this->_getHordeInfo();
        if ($info['type'] == 'library') {
            $dir .= '/Horde/' . str_replace('_', '/', $info['id']);
        }
        if (!is_dir($dir)) {
            if (!$mkdir) {
                return false;
            }
            mkdir($dir, 0777, true);
        }
        return $dir;
    }

    /**
     * Run a system call.
     *
     * @param string $call       The system call to execute.
     * @param string $target_dir Run the command in the provided target path.
     * @param array  $options    Additional options.
     *
     * @return string The command output.
     */
    protected function _systemInDirectory($call, $target_dir, $options)
    {
        $old_dir = getcwd();
        chdir($target_dir);
        $result = $this->_system($call, $options);
        chdir($old_dir);
        return $result;
    }

    /**
     * Run a system call.
     *
     * @param string $call    The system call to execute.
     * @param array  $options Additional options.
     *
     * @return string The command output.
     */
    protected function _system($call, $options)
    {
        if (empty($options['pretend'])) {
            //@todo Error handling
            return exec($call);
        } else {
            $this->_output->info(sprintf('Would run "%s" now.', $call));
        }
    }
}
