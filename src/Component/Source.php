<?php
/**
 * Copyright 2011-2020 Horde LLC (http://www.horde.org/)
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
namespace Horde\Components\Component;
use Horde\Components\Config;
use Horde\Components\Exception;
use Horde\Components\Pear\Package as PearPackage;
use Horde\Components\Pear\Environment as PearEnvironment;
use Horde\Components\Exception\Pear as ExceptionPear;
use Horde\Components\Helper\Version as HelperVersion;
use Horde\Components\Helper\Root as HelperRoot;
use Horde\Components\Helper\Composer as HelperComposer;
use Horde\Components\Release\Notes as ReleaseNotes;
use Horde\Components\Wrapper;
use Horde\Components\Wrapper\ApplicationPhp as WrapperApplicationPhp;
use Horde\Components\Wrapper\ChangelogYml as WrapperChangelogYml;
use Horde\Components\Wrapper\Changes as WrapperChanges;
use Horde\Components\Wrapper\ComposerJson as WrapperComposerJson;
use Horde\Components\Wrapper\HordeYml as WrapperHordeYml;
use Horde\Components\Wrapper\PackageXml as WrapperPackageXml;


/**
 * Represents a source component.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @author   Jan Schneider <jan@horde.org>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Source extends Base
{
    /**
     * Path to the source directory.
     *
     * @var string
     */
    protected $_directory;

    /**
     * The release notes handler.
     *
     * @var ReleaseNotes
     */
    protected $_notes;

    /**
     * The PEAR package file representing the component.
     *
     * @var \PEAR_PackageFile
     */
    protected $_package_file;

    /**
     * Cached file wrappers.
     *
     * @var WrapperApplicationPhp|WrapperChangelogYml|WrapperChanges|WrapperComposerJson|WrapperHordeYml|WrapperPackageXml[]
     */
    protected $_wrappers = array();

    /**
     * Constructor.
     *
     * @param string $directory                     Path to the source
     *                                              directory.
     * @param Config $config             The configuration for the
     *                                              current job.
     * @param ReleaseNotes $notes       The release notes.
     * @param Horde\Components\Component\Factory $factory Generator for additional
     *                                              helpers.
     */
    public function __construct(
        $directory,
        Config $config,
        ReleaseNotes $notes,
        Factory $factory
    )
    {
        $this->_directory = realpath($directory);
        $this->_notes = $notes;
        parent::__construct($config, $factory);
    }

    /**
     * @inheritdoc
     */
    public function getName()
    {
        return $this->getHordeYml()['id'];
    }

    /**
     * @inheritdoc
     */
    public function getSummary()
    {
        return $this->getHordeYml()['full'];
    }

    /**
     * @inheritdoc
     */
    public function getDescription()
    {
        return $this->getHordeYml()['description'];
    }

    /**
     * @inheritdoc
     */
    public function getVersion()
    {
        return $this->getHordeYml()['version']['release'];
    }

    /**
     * @inheritdoc
     */
    public function getPreviousVersion()
    {
        /** @var WrapperChangelogYml $changelog */
        $changelog = $this->getWrapper('ChangelogYml');
        if (!$changelog->exists()) {
            return parent::getPreviousVersion();
        }

        $previousVersion = null;
        $currentVersion = $this->getVersion();
        $currentState = $this->getState();
        $found = false;
        foreach ($changelog as $version => $info) {
            if ($found) {
                // If this is a stable version we want the previous stable version,
                // otherwise use any previous version.

                // Some older changelog entries may not have the state
                // attribute, this may give index errors
                if ($currentState == 'stable' &&
                    !empty($info['state']) &&
                    !empty($info['state']['release']) &&
                    $info['state']['release'] != 'stable') {
                    continue;
                }
                if (\version_compare($version, $currentVersion, '>=')) {
                    return $previousVersion;
                }
                $previousVersion = $version;
            } elseif ($version == $currentVersion) {
                $found = true;
            }
        }

        return $previousVersion;
    }

    /**
     * @inheritdoc
     */
    public function getDate()
    {
        /** @var WrapperChangelogYml $changelog */
        $changelog = $this->getWrapper('ChangelogYml');
        if ($changelog->exists()) {
            $version = $this->getVersion();
            if (!isset($changelog[$version])) {
                throw new Exception(sprintf(
                    '%s doesn\'t have an entry for version %s.',
                    $changelog->getFullPath(),
                    $version
                ));
            }
            return $changelog[$version]['date'];
        }

        return parent::getDate();
    }

    /**
     * @inheritdoc
     */
    public function getChannel()
    {
        return 'pear.horde.org';
    }

    /**
     * @inheritdoc
     */
    public function getDependencies()
    {
        return parent::getDependencies(); // TODO: Change the autogenerated stub
    }

    /**
     * @inheritdoc
     */
    public function getState($key = 'release')
    {
        return $this->getHordeYml()['state'][$key];
    }

    /**
     * @inheritdoc
     */
    public function getLeads()
    {
        $result = array();
        foreach ($this->getHordeYml()['authors'] as $author) {
            if ($author['role'] != 'lead') {
                continue;
            }
            $result[] = $author;
        }
        return $result;
    }

    /**
     * @inheritdoc
     */
    public function getLicense()
    {
        return $this->getHordeYml()['license']['identifier'];
    }

    /**
     * @inheritdoc
     */
    public function getLicenseLocation()
    {
        return $this->getHordeYml()['license']['uri'];
    }

    /**
     * Return a data array with the most relevant information about this
     * component.
     *
     * @return \stdClass Information about this component.
     * @throws Exception
     */
    public function getData()
    {
        $data = new \stdClass;

        /** @var WrapperHordeYml $package */
        $data->name = $this->getName();
        $data->summary = $this->getSummary();
        $data->description = $this->getDescription();
        $data->version = $this->getVersion();
        $data->releaseDate = $this->getDate();

        $data->download = sprintf(
            'https://pear.horde.org/get/%s-%s.tgz',
            $data->name,
            $data->version
        );
        $data->hasCi = $this->_hasCi();

        return $data;
    }

    /**
     * Indicate if the component has a local package.xml.
     *
     * @return boolean True if a package.xml exists.
     * @throws Exception
     */
    public function hasLocalPackageXml()
    {
        return $this->getPackageXml()->exists();
    }

    /**
     * Returns the link to the change log.
     *
     * @return string The link to the change log.
     * @throws Exception
     */
    public function getChangelogLink()
    {
        $base = $this->getFactory()->getGitRoot()->getRoot();
        return $this->getFactory()->createChangelog($this)->getChangelogLink(
            preg_replace(
                '#^' . $base . '#', '', $this->_directory
            )
        );
    }

    /**
     * Return the path to the release notes.
     *
     * @return string|boolean The path to the release notes or false.
     */
    public function getReleaseNotesPath()
    {
        foreach (array('release.yml', 'RELEASE_NOTES') as $file) {
            foreach (array('docs', 'doc') as $directory) {
                $path = $this->_directory . '/' . $directory . '/' . $file;
                if (file_exists($path)) {
                    return $path;
                }
            }
        }
        return false;
    }

    /**
     * Return the path to a DOCS_ORIGIN file within the component.
     *
     * @return array|null An array containing the path name and the component
     *                    base directory or NULL if there is no DOCS_ORIGIN
     *                    file.
     */
    public function getDocumentOrigin()
    {
        foreach (array('doc', 'docs') as $doc_dir) {
            $path = $this->_directory . '/' . $doc_dir;
            if (!is_dir($path)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path)) as $file) {
                if ($file->isFile() &&
                    $file->getFilename() == 'DOCS_ORIGIN') {
                    return array($file->getPathname(), $this->_directory);
                }
            }
        }
        return null;
    }

    /**
     * Updates the information files for this component.
     *
     * @param string $action The action to perform. Either "update", "diff",
     *                       or "print".
     * @param array $options Options for this operation.
     *
     * @return string|boolean  The result of the action.
     * @throws Exception
     * @throws \Horde_Pear_Exception
     * @throws \Horde_Exception_NotFound
     */
    public function updatePackage($action, $options)
    {
        if (!$this->getPackageXml()->exists()) {
            if (!empty($options['theme'])) {
                $this->getFactory()->createThemePackageFile($this->_directory);
            } else {
                $this->getFactory()->createPackageFile($this->_directory);
            }
            unset($this->_wrappers['PackageXml']);
        }

        $oldWrappers = $this->cloneWrappers();
        $package_xml = $this->updatePackageFromHordeYml();

        /* Skip updating if this is a PECL package. */
        if (!$package_xml->findNode('/p:package/p:providesextension')) {
            $package_xml->updateContents(
                !empty($options['theme'])
                    ? $this->getFactory()->createThemeContentList($this->_directory)
                    : $this->getFactory()->createContentList($this->_directory),
                $options
            );
            $this->updateComposerFromHordeYml();
        }

        switch($action) {
        case 'print':
            return implode("===\n", $this->_wrappers);
        case 'diff':
            return $this->getWrappersDiff($oldWrappers);
        default:
            foreach ($this->_wrappers as $wrapper) {
                $wrapper->save();
                if (!empty($options['commit'])) {
                    $options['commit']->add($wrapper, $this->_directory);
                }
            }
            return true;
        }
    }

    /**
     * Rebuilds the basic information in a package.xml file from the .horde.yml
     * definition.
     *
     * @return WrapperPackageXml  The updated package.xml handler.
     * @throws Exception
     * @throws \Horde_Pear_Exception
     * @throws \Horde_Exception_NotFound
     */
    public function updatePackageFromHordeYml()
    {
        $xml = $this->getPackageXml();
        $yaml = $this->getHordeYml();

        // Update texts.
        $name = $yaml['id'];
        if ($yaml['type'] == 'library') {
            $name = 'Horde_' . $name;
        }
        $xml->replaceTextNode('/p:package/p:name', $name);
        $xml->replaceTextNode('/p:package/p:summary', $yaml['full']);
        $xml->replaceTextNode('/p:package/p:description', $yaml['description']);

        // Update versions.
        $xml->setVersion(
            HelperVersion::validatePear($yaml['version']['release']),
            HelperVersion::validatePear($yaml['version']['api'])
        );
        $xml->setState($yaml['state']['release'], $yaml['state']['api']);

        // Update date.
        $changelog = $this->getFactory()->createChangelog($this);
        if ($changelog->changelogFileExists()) {
            $changelogYml = $this->getWrapper('ChangelogYml');
            if (!isset($changelogYml[$yaml['version']['release']])) {
                throw new Exception(sprintf(
                    'Version %s not found in %s',
                    $yaml['version']['release'],
                    $changelogYml->getLocalPath($this->_directory)
                ));
            }
            $xml->replaceTextNode(
                '/p:package/p:date',
                $changelogYml[$yaml['version']['release']]['date']
            );
        }

        // Update license.
        $xml->replaceTextNode(
            '/p:package/p:license',
            $yaml['license']['identifier']
        );
        if ($yaml['license']['uri']) {
            /** @var \DOMElement $node */
            $node = $xml->findNode('/p:package/p:license');
            $node->setAttribute('uri', $yaml['license']['uri']);
        }

        // Update authors.
        while ($node = $xml->findNode('/p:package/p:lead|p:developer')) {
            $xml->removeWhitespace($node->previousSibling);
            $node->parentNode->removeChild($node);
        }
        if (!empty($yaml['authors'])) {
            foreach ($yaml['authors'] as $author) {
                $xml->addAuthor(
                    $author['name'],
                    $author['user'],
                    $author['email'],
                    $author['active'],
                    $author['role']
                );
            }
        }

        // Update dependencies.
        if (!empty($yaml['dependencies'])) {
            $this->_updateDependencies($xml, $yaml['dependencies']);
        }

        return $xml;
    }

    /**
     * Update dependencies.
     *
     * @param \Horde_Pear_Package_Xml $xml A package.xml handler.
     * @param array $dependencies         A list of dependencies.
     *
     * @throws Exception
     */
    protected function _updateDependencies($xml, $dependencies)
    {
        foreach (array('package', 'extension') as $type) {
            while ($node = $xml->findNode('/p:package/p:dependencies/p:required/p:' . $type)) {
                $xml->removeWhitespace($node->previousSibling->previousSibling);
                $xml->removeWhitespace($node->previousSibling);
                $node->parentNode->removeChild($node);
            }
        }
        if ($node = $xml->findNode('/p:package/p:dependencies/p:optional')) {
            $xml->removeWhitespace($node->previousSibling->previousSibling);
            $xml->removeWhitespace($node->previousSibling);
            $node->parentNode->removeChild($node);
        }
        $php = HelperVersion::composerToPear(
            $dependencies['required']['php']
        );
        foreach ($php as $tag => $version) {
            $xml->replaceTextNode(
                '/p:package/p:dependencies/p:required/p:php/p:' . $tag,
                $version
            );
        }
        foreach ($dependencies as $required => $dependencyTypes) {
            foreach (array('pear', 'ext') as $type) {
                if (isset($dependencyTypes[$type])) {
                    $this->_addDependency($xml, $required, $type, $dependencyTypes[$type]);
                }
            }
        }
    }

    /**
     * Adds a number of dependencies of the same kind.
     *
     * @param \Horde_Pear_Package_Xml $xml  A package.xml handler.
     * @param string $required             A required dependency? Either
     *                                     'required' or 'optional'.
     * @param string $type                 A dependency type from .horde.yml.
     * @param array $dependencies          A list of dependency names and
     *                                     versions.
     *
     * @throws Exception
     */
    protected function _addDependency($xml, $required, $type, $dependencies)
    {
        switch ($type) {
        case 'php':
            return;
        case 'pear':
            $type = 'package';
            break;
        case 'ext':
            $type = 'extension';
            break;
        default:
            throw new Exception(
                'Unknown dependency type: ' . $type
            );
        }
        foreach ($dependencies as $dependency => $version) {
            if (is_array($version)) {
                $constraints = $version;
                unset($constraints['version']);
                $version = $version['version'];
            } else {
                $constraints = array();
            }
            switch ($type) {
            case 'package':
                list($channel, $name) = explode('/', $dependency);
                $constraints = array_merge(
                    array('name' => $name, 'channel' => $channel),
                    HelperVersion::composerToPear($version),
                    $constraints
                );
                break;
            case 'extension':
                $constraints = array_merge(
                    array('name' => $dependency),
                    HelperVersion::composerToPear($version),
                    $constraints
                );
                break;
            }
            $xml->addDependency($required, $type, $constraints);
        }
    }

    /**
     * Rebuilds the basic information in a composer.json file from the
     * .horde.yml definition.
     *
     * @return WrapperComposerJson  The updated composer.json content.
     * @throws Exception
     */
    public function updateComposerFromHordeYml()
    {
        $yaml = $this->getHordeYml();
        $options = $this->_config->getOptions();
        $composer = new HelperComposer();
        $json = $composer->generateComposerJson($yaml, $options);
        $wrapper = $this->getWrapper('ComposerJson');
        $wrapper->exchangeArray(json_decode($json));
        return $wrapper;
    }

    /**
     * Update the component changelog.
     *
     * @param string $log    The log entry.
     * @param array $options Options for the operation.
     *
     * @return string[]  Output messages.
     * @throws Exception
     */
    public function changed($log, $options)
    {
        $output = array();

        // Create changelog.yml
        $helper = $this->getFactory()->createChangelog($this);
        if (!$helper->changelogFileExists() &&
            $this->getPackageXml()->exists()) {
            $helper->migrateToChangelogYml($this->getPackageXml());
            if (empty($options['pretend'])) {
                $output[] = sprintf(
                    'Created %s.',
                    $helper->changelogFileExists()
                );
            } else {
                $output[] = sprintf(
                    'Would create %s now.',
                    $helper->changelogFileExists()
                );
            }
        }

        // Update changelog.yml
        $file = $helper->changelogYml($log, $options);
        if ($file) {
            if (empty($options['pretend'])) {
                $this->getWrapper('ChangelogYml')->save();
                $output[] = sprintf(
                    'Added new note to version %s of %s.',
                    $this->getHordeYml()['version']['release'],
                    $file
                );
            } else {
                $output[] = sprintf(
                    'Would add change log entry to %s now.',
                    $file
                );
            }
            if (!empty($options['commit'])) {
                $options['commit']->add($file, $this->_directory);
            }
        }

        return $output;
    }

    /**
     * Timestamps changelog.yml with the current time.
     *
     * @param array $options Options for the operation.
     *
     * @return string The success message.
     * @throws Exception
     */
    public function timestamp($options)
    {
        $helper = $this->getFactory()->createChangelog($this);
        $helper->timestamp();
        if (empty($options['pretend'])) {
            $this->getWrapper('ChangelogYml')->save();
            if (!empty($options['commit'])) {
                $options['commit']->add(
                    $helper->changelogFileExists(), $this->_directory
                );
            }
            $result = sprintf(
                'Marked %s with current timestamp.',
                $helper->changelogFileExists()
            );
        } else {
            $result = sprintf(
                'Would timestamp %s now.',
                $helper->changelogFileExists()
            );
        }
        return $result;
    }

    /**
     * Synchronizes CHANGES and package.xml with changelog.yml.
     *
     * @param array $options Options for the operation.
     *
     * @return string The success message.
     * @throws Exception
     * @throws \Horde_Pear_Exception
     */
    public function sync($options)
    {
        $helper = $this->getFactory()->createChangelog($this);
        $changes = $this->getWrapper('Changes');
        $this->updatePackageFromHordeYml();
        $xml = $this->getPackageXml();
        $xml->syncCurrentVersion();
        $helper->updatePackage($xml);
        if (empty($options['pretend'])) {
            $xml->save();
            if (!empty($options['commit'])) {
                $options['commit']->add($xml, $this->_directory);
            }
            $result = sprintf(
                'Synchronized %s with %s.',
                $this->getPackageXmlPath(),
                $helper->changelogFileExists()
            );
            if ($path = $helper->updateChanges()) {
                $changes->save();
                if (!empty($options['commit'])) {
                    $options['commit']->add($changes, $this->_directory);
                }
                $result .= "\n" . sprintf(
                    'Synchronized %s with %s.',
                    $path,
                    $helper->changelogFileExists()
                );
            }
        } else {
            $result = sprintf(
                'Would synchronize %s with %s now.',
                $this->getPackageXmlPath(),
                $helper->changelogFileExists()
            );
            if ($changes->exists()) {
                $result .= "\n" . sprintf(
                    'Would synchronize %s with %s now.',
                    $this->getPackageXmlPath(),
                    $changes->getFullPath()
                );
            }
        }
        return $result;
    }

    /**
     * Sets the version in the component.
     *
     * @param string $rel_version The new release version number.
     * @param string $api_version The new api version number.
     * @param array $options      Options for the operation.
     *
     * @return string  Result message.
     * @throws Exception
     * @throws \Horde_Pear_Exception
     */
    public function setVersion(
        $rel_version = null, $api_version = null, $options = array()
    )
    {
        $changelog = $this->getWrapper('ChangelogYml');
        $updated = array();
        if ($changelog->exists()) {
            $this->getFactory()
                ->createChangelog($this)
                ->setVersion($rel_version, $api_version);
            $updated[] = $changelog;
        }
        $updated = array_merge(
            $updated,
            $this->_setVersion($rel_version, $api_version)
        );

        if (!empty($options['commit'])) {
            foreach ($updated as $wrapper) {
                $options['commit']->add($wrapper, $this->_directory);
            }
        }
        $list = $this->_getWrapperNames($updated);
        if (empty($options['pretend'])) {
            $result = sprintf(
                'Set release version "%s" and api version "%s" in %s.',
                $rel_version,
                $api_version,
                $list
            );
        } else {
            $result = sprintf(
                'Would set release version "%s" and api version "%s" in %s now.',
                $rel_version,
                $api_version,
                $list
            );
        }

        return $result;
    }

    /**
     * Sets the version in all files.
     *
     * @param string $rel_version The new release version number.
     * @param string $api_version The new api version number.
     *
     * @return Wrapper[]  Wrappers of updated files.
     * @throws Exception
     * @throws \Horde_Pear_Exception
     */
    public function _setVersion($rel_version = null, $api_version = null)
    {
        // Update .horde.yml.
        $yaml = $this->getHordeYml();
        if ($rel_version) {
            $yaml['version']['release'] = $rel_version;
        }
        if ($api_version) {
            $yaml['version']['api'] = $api_version;
        }
        $updated = array($yaml);

        // Update package.xml
        $package_xml = $this->updatePackageFromHordeYml();
        $updated[] = $package_xml;

        // Update composer.json
        $updated[] = $this->updateComposerFromHordeYml();

        // Update CHANGES.
        $changes = $this->getWrapper('Changes');
        if ($changes->exists()) {
            $this->getFactory()
                ->createChangelog($this)
                ->updateChanges();
            $updated[] = $changes;
        }

        // Update Application.php/Bundle.php.
        /** @var WrapperApplicationPhp $application */
        $application = $this->getWrapper('ApplicationPhp');
        if ($application->exists()) {
            $application->setVersion(
                HelperVersion::pearToHordeWithBranch(
                    $rel_version,
                    $this->_notes->getBranch()
                )
            );
            $updated[] = $application;
        }

        return $updated;
    }

    /**
     * Sets the state in the package.xml
     *
     * @param string $rel_state The new release state.
     * @param string $api_state The new api state.
     * @param array $options
     *
     * @return string The success message.
     * @throws Exception
     * @throws \Horde_Pear_Exception
     */
    public function setState(
        $rel_state = null, $api_state = null, $options = array()
    )
    {
        $package = $this->getPackageXml();
        $package->setState($rel_state, $api_state);
        if (empty($options['pretend'])) {
            if (!empty($options['commit'])) {
                $options['commit']->add($package, $this->_directory);
            }
            $result = sprintf(
                'Set release state "%s" and api state "%s" in %s.',
                $rel_state,
                $api_state,
                $this->getPackageXmlPath()
            );
        } else {
            $result = sprintf(
                'Would set release state "%s" and api state "%s" in %s now.',
                $rel_state,
                $api_state,
                $this->getPackageXmlPath()
            );
        }
        return $result;
    }

    /**
     * Add the next version to the component files.
     *
     * @param string $version           The new version number.
     * @param string $initial_note      The text for the initial note.
     * @param string $stability_api     The API stability for the next release.
     * @param string $stability_release The stability for the next release.
     * @param array $options            Options for the operation.
     *
     * @return string The success message.
     * @throws Exception
     * @throws \Horde_Pear_Exception
     */
    public function nextVersion(
        $version,
        $initial_note,
        $stability_api = null,
        $stability_release = null,
        $options = array()
    )
    {
        /** @var WrapperChangelogYml $changelog */
        $changelog = $this->getWrapper('ChangelogYml');
        $currentVersion = $this->getHordeYml()['version']['release'];
        if (!isset($changelog[$currentVersion])) {
            throw new Exception(
                sprintf(
                    'Current version %s not found in %s',
                    $currentVersion,
                    $changelog->getFileName()
                )
            );
        }
        $nextVersion = $changelog[$currentVersion];
        $nextVersion['notes'] = "\n" . $initial_note;
        if ($stability_release) {
            $nextVersion['state']['release'] = $stability_release;
        }
        if ($stability_api) {
            $nextVersion['state']['api'] = $stability_api;
        }
        $changelog[$version] = $nextVersion;
        $changelog->uksort(
            function($a, $b)
            {
                return \version_compare($b, $a);
            }
        );

        $updated = $this->_setVersion($version);
        $updated[] = $changelog;

        $helper = $this->getFactory()->createChangelog($this);
        /** @var WrapperPackageXml $packageXml */
        $packageXml = $this->getWrapper('PackageXml');
        $helper->updatePackage($packageXml);

        if (!empty($options['commit'])) {
            foreach ($updated as $wrapper) {
                $options['commit']->add($wrapper, $this->_directory);
            }
        }

        $list = $this->_getWrapperNames($updated);
        if (empty($options['pretend'])) {
            foreach ($updated as $wrapper) {
                $wrapper->save();
            }
            $result = sprintf(
                'Added next version "%s" with the initial note "%s" to %s.',
                $version,
                $initial_note,
                $list
            );
        } else {
            $result = sprintf(
                'Would add next version "%s" with the initial note "%s" to %s now.',
                $version,
                $initial_note,
                $list
            );
        }
        if ($stability_release !== null) {
            $result .= ' Release stability: "' . $stability_release . '".';
        }
        if ($stability_api !== null) {
            $result .= ' API stability: "' . $stability_api . '".';
        }

        return $result;
    }

    /**
     * Replace the current sentinel.
     *
     * @param string $changes New version for the CHANGES file.
     * @param string $app     New version for the Application.php file.
     * @param array $options  Options for the operation.
     *
     * @return array The success message.
     */
    public function currentSentinel($changes, $app, $options)
    {
        /** @var \Horde_Release_Sentinel $sentinel */
        $sentinel = $this->getFactory()->createSentinel($this->_directory);
        if (empty($options['pretend'])) {
            $sentinel->replaceChanges($changes);
            $sentinel->updateApplication($app);
            $action = 'Did';
        } else {
            $action = 'Would';
        }
        $files = array(
            'changes' => $sentinel->changesFileExists(),
            'app'     => $sentinel->applicationFileExists(),
            'bundle'  => $sentinel->bundleFileExists()
        );
        $result = array();
        foreach ($files as $key => $file) {
            if (empty($file)) {
                continue;
            }
            if (!empty($options['commit'])) {
                $options['commit']->add($file, $this->_directory);
            }
            $version = ($key == 'changes') ? $changes : $app;
            $result[] = sprintf(
                '%s replace sentinel in %s with "%s" now.',
                $action,
                $file,
                $version
            );
        }
        return $result;
    }

    /**
     * Tag the component.
     *
     * @param string                   $tag     Tag name.
     * @param string                   $message Tag message.
     * @param HelperCommit $commit  The commit helper.
     */
    public function tag($tag, $message, $commit)
    {
        $commit->tag($tag, $message, $this->_directory);
    }

    /**
     * Place the component source archive at the specified location.
     *
     * @param string $destination The path to write the archive to.
     * @param array $options      Options for the operation.
     *
     * @return array An array with at least [0] the path to the resulting
     *               archive, optionally [1] an array of error strings, and [2]
     *               PEAR output.
     * @throws Exception
     */
    public function placeArchive($destination, $options = array())
    {
        if (!$this->getPackageXml()->exists()) {
            throw new Exception(
                sprintf(
                    'The component "%s" still lacks a package.xml file at "%s"!',
                    $this->getName(),
                    $this->getPackageXmlPath()
                )
            );
        }

        if (empty($options['keep_version'])) {
            $version = preg_replace(
                '/([.0-9]+).*/',
                '\1dev' . strftime('%Y%m%d%H%M'),
                $this->getVersion()
            );
        } else {
            $version = $this->getVersion();
        }

        $this->createDestination($destination);

        $package = $this->_getPackageFile();
        $pkg = $this->getFactory()->pear()->getPackageFile(
            $this->getPackageXmlPath(),
            $package->getEnvironment()
        );
        $pkg->_packageInfo['version']['release'] = $version;
        $pkg->setDate(date('Y-m-d'));
        $pkg->setTime(date('H:i:s'));
        if (isset($options['logger'])) {
            $pkg->setLogger($options['logger']);
        }
        $errors = array();
        ob_start();
        $old_dir = getcwd();
        chdir($destination);
        try {
            $pear_common = new \PEAR_Common();
            $result = ExceptionPear::catchError(
                $pkg->getDefaultGenerator()->toTgz($pear_common)
            );
        } catch (ExceptionPear $e) {
            $errors[] = $e->getMessage();
            $errors[] = '';
            $result = false;
            foreach ($pkg->getValidationWarnings() as $error) {
                $errors[] = isset($error['message']) ? $error['message'] : 'Unknown Error';
            }
        }
        chdir($old_dir);
        $output = array($result, $errors);
        $output[] = ob_get_clean();
        return $output;
    }

    /**
     * Identify the repository root.
     *
     * @param HelperRoot $helper The root helper.
     *
     * @return string  The repository root.
     * @throws Exception
     */
    public function repositoryRoot(HelperRoot $helper)
    {
        if (($result = $helper->traverseHierarchy($this->_directory)) === false) {
            throw new Exception(sprintf(
                'Unable to determine Horde repository root from component path "%s"!',
                $this->_directory
            ));
        }
        return $result;
    }

    /**
     * Install a component.
     *
     * @param PearEnvironment $env The environment to install
     *                                         into.
     * @param array $options                   Install options.
     * @param string $reason                   Optional reason for adding the
     *                                         package.
     *
     * @throws Exception
     * @throws ExceptionPear
     */
    public function install(PearEnvironment $env, $options = array(), $reason = ''
    )
    {
        $this->installChannel($env, $options);
        if (!empty($options['symlink'])) {
            $env->linkPackageFromSource(
                $this->getPackageXmlPath(), $reason
            );
        } else {
            $env->addComponent(
                $this->getName(),
                array($this->getPackageXmlPath()),
                $this->getBaseInstallationOptions($options),
                ' from source in ' . dirname($this->getPackageXmlPath()),
                $reason
            );
        }
    }

    /**
     * Returns a .horde.yml definition for the component.
     *
     * @return WrapperHordeYml
     * @throws Exception
     * @throws \Horde_Exception_NotFound
     */
    public function getHordeYml()
    {
        return $this->getWrapper('HordeYml');
    }

    /**
     * Return a PEAR package representation for the component.
     *
     * @return WrapperPackageXml The package representation.
     * @throws Exception
     * @throws \Horde_Exception_NotFound
     */
    protected function getPackageXml()
    {
        /** @var WrapperPackageXml $packageXml */
        $packageXml = $this->getWrapper('PackageXml');
        return $packageXml;
    }

    /**
     * Return a PEAR PackageFile representation for the component.
     *
     * @return PearPackage The package representation.
     * @throws Exception
     */
    private function _getPackageFile()
    {
        $options = $this->getOptions();
        if (isset($options['pearrc'])) {
            return $this->getFactory()->pear()
                ->createPackageForPearConfig(
                    $this->getPackageXmlPath(), $options['pearrc']
                );
        }
        return $this->getFactory()->pear()
            ->createPackageForDefaultLocation(
                $this->getPackageXmlPath()
            );
    }

    /**
     * Return the path to the package.xml file of the component.
     *
     * @return string The path to the package.xml file.
     * @throws Exception
     */
    public function getPackageXmlPath()
    {
        return $this->getPackageXml()->getFullPath();
    }

    /**
     * Returns the path to the documentation directory.
     *
     * @return string  The directory name.
     * @throws Exception
     */
    public function getDocDirectory()
    {
        if (is_dir($this->_directory . '/doc')) {
            $dir = $this->_directory . '/doc';
        } elseif (is_dir($this->_directory . '/docs')) {
            $dir = $this->_directory . '/docs';
        } else {
            $dir = $this->_directory . '/doc';
        }
        try {
            $info = $this->getHordeYml();
            if ($info['type'] == 'library') {
                $dir .= '/Horde/' . str_replace('_', '/', $info['id']);
            }
        } catch (\Horde_Exception_NotFound $exception) {
        }
        return $dir;
    }

    /**
     * Returns the path to the package's top directory.
     *
     * This is useful to determine the package dir from inside tasks
     *
     * @return string  The directory name.
     */
    public function getComponentDirectory()
    {
        return $this->_directory;
    }

    /**
     * Returns a file wrapper.
     *
     * @param string $file File wrapper to return.
     *
     * @return WrapperApplicationPhp|WrapperChangelogYml|
     *         WrapperChanges|WrapperComposerJson|
     *         WrapperHordeYml|WrapperPackageXml
     *         The requested file
     *                                                                                                                                                                                                 wrapper.
     * @throws Exception
     * @throws \Horde_Exception_NotFound
     */
    public function getWrapper($file)
    {
        if (!isset($this->_wrappers[$file])) {
            switch ($file) {
            case 'HordeYml':
                $this->_wrappers[$file] = new WrapperHordeYml(
                    $this->_directory
                );
                if (!$this->_wrappers[$file]->exists()) {
                    throw new \Horde_Exception_NotFound(
                        $this->_wrappers[$file]->getFileName() . ' is missing.'
                    );
                }
                break;
            case 'ComposerJson':
                $this->_wrappers[$file] = new WrapperComposerJson(
                    $this->_directory
                );
                break;
            case 'PackageXml':
                $this->_wrappers[$file] = new WrapperPackageXml(
                    $this->_directory
                );
                break;
            case 'ChangelogYml':
                $this->_wrappers[$file] = new WrapperChangelogYml(
                    $this->getDocDirectory()
                );
                break;
            case 'Changes':
                $this->_wrappers[$file] = new WrapperChanges(
                    $this->getDocDirectory()
                );
                break;
            case 'ApplicationPhp':
                $this->_wrappers[$file] = new WrapperApplicationPhp(
                    $this->_directory
                );
                break;
            default:
                throw new \InvalidArgumentException(
                    $file . ' is not a supported file wrapper'
                );
            }
        }
        return $this->_wrappers[$file];
    }

    /**
     * Returns a concatenated diff of all file wrappers.
     *
     * @param Wrapper[]|null $oldWrappers
     *
     * @return string
     */
    public function getWrappersDiff($oldWrappers = null)
    {
        if (!$oldWrappers) {
            $oldWrappers = array();
        }
        $diff = '';
        foreach ($this->_wrappers as $wrapper) {
            $current = null;
            foreach ($oldWrappers as $oldWrapper) {
                if (get_class($oldWrapper) == get_class($wrapper)) {
                    $current = $oldWrapper;
                    break;
                }
            }
            if (($wrapper->exists() || strlen($wrapper)) &&
                strlen($wrapperDiff = $this->_createDiff($wrapper, $current))) {
                $diff .= "\n" . $wrapperDiff;
            }
        }
        return substr($diff, 1);
    }

    /**
     * Saves all loaded file wrappers.
     */
    public function saveWrappers()
    {
        foreach ($this->_wrappers as $wrapper) {
            $wrapper->save();
        }
    }

    /**
     * @return array
     */
    public function cloneWrappers()
    {
        $oldWrappers = array();
        foreach ($this->_wrappers as $name => $wrapper) {
            $oldWrappers[$name] = clone $wrapper;
        }
        return $oldWrappers;
    }

    /**
     * Converts a list of wrappers to a file list.
     *
     * @param Wrapper[] $wrappers  A list of wrappers.
     *
     * @return string  A comma-separated file list.
     */
    protected function _getWrapperNames($wrappers)
    {
        return implode(
            ', ',
            array_map(
                function(Wrapper $wrapper)
                {
                    return $wrapper->getLocalPath($this->_directory);
                },
                $wrappers
            )
        );
    }

    /**
     * @param Wrapper      $wrapper
     * @param Wrapper|null $oldWrapper
     *
     * @return string
     */
    protected function _createDiff(Wrapper $wrapper, Wrapper $oldWrapper = null)
    {
        $diff = $wrapper->diff($oldWrapper);
        if (!empty($diff)) {
            $path = $wrapper->getLocalPath($this->_directory);
            return '--- a/' . $path . "\n"
                . '--- b/' . $path . "\n"
                . $diff;
        }
        return '';
    }
}
