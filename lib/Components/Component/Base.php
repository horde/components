<?php
/**
 * Represents base functionality for a component.
 *
 * PHP version 5
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

/**
 * Represents base functionality for a component.
 *
 * Copyright 2011-2017 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
abstract class Components_Component_Base implements Components_Component
{
    /**
     * The configuration for the current job.
     *
     * @var Components_Config
     */
    protected $_config;

    /**
     * The factory for additional helpers.
     *
     * @var Components_Component_Factory
     */
    private $_factory;

    /**
     * Constructor.
     *
     * @param Components_Config            $config  The configuration for the
     *                                              current job.
     * @param Components_Component_Factory $factory Generator for additional
     *                                              helpers.
     */
    public function __construct(
        Components_Config $config,
        Components_Component_Factory $factory
    )
    {
        $this->_config  = $config;
        $this->_factory = $factory;
    }

    /**
     * Return the name of the component.
     *
     * @return string The component name.
     * @throws Components_Exception
     */
    public function getName()
    {
        return $this->getPackageXml()->getName();
    }

    /**
     * Return the component summary.
     *
     * @return string The summary of the component.
     * @throws Components_Exception
     */
    public function getSummary()
    {
        return $this->getPackageXml()->getSummary();
    }

    /**
     * Return the component description.
     *
     * @return string The description of the component.
     * @throws Components_Exception
     */
    public function getDescription()
    {
        return $this->getPackageXml()->getDescription();
    }

    /**
     * Return the version of the component.
     *
     * @return string The component version.
     * @throws Components_Exception
     */
    public function getVersion()
    {
        return $this->getPackageXml()->getVersion();
    }

    /**
     * Returns the previous version of the component.
     *
     * @return string The previous component version.
     * @throws Components_Exception
     */
    public function getPreviousVersion()
    {
        $previousVersion = null;
        $currentVersion = $this->getVersion();
        $currentState = $this->getState();
        $versions = $this->getPackageXml()->getVersions();
        usort(
            $versions,
            function($a, $b) {
                return version_compare($a['version'], $b['version']);
            }
        );
        foreach ($versions as $version) {
            // If this is a stable version we want the previous stable version,
            // otherwise use any previous version.
            if ($currentState == 'stable' &&
                $version['stability'] != 'stable') {
                continue;
            }
            if (version_compare($version['version'], $currentVersion, '>=')) {
                return $previousVersion;
            }
            $previousVersion = $version['version'];
        }
        return $previousVersion;
    }

    /**
     * Return the last release date of the component.
     *
     * @return string The date.
     * @throws Components_Exception
     */
    public function getDate()
    {
        return $this->getPackageXml()->getDate();
    }

    /**
     * Return the channel of the component.
     *
     * @return string The component channel.
     * @throws Components_Exception
     */
    public function getChannel()
    {
        return $this->getPackageXml()->getChannel();
    }

    /**
     * Return the dependencies for the component.
     *
     * @return array The component dependencies.
     * @throws Components_Exception
     */
    public function getDependencies()
    {
        return $this->getPackageXml()->getDependencies();
    }

    /**
     * Return the stability of the release or api.
     *
     * @param string $key "release" or "api"
     *
     * @return string The stability.
     * @throws Components_Exception
     */
    public function getState($key = 'release')
    {
        return $this->getPackageXml()->getState($key);
    }

    /**
     * Return the package lead developers.
     *
     * @return string The package lead developers.
     * @throws Components_Exception
     */
    public function getLeads()
    {
        return $this->getPackageXml()->getLeads();
    }

    /**
     * Return the component license.
     *
     * @return string The component license.
     * @throws Components_Exception
     */
    public function getLicense()
    {
        return $this->getPackageXml()->getLicense();
    }

    /**
     * Return the component license URI.
     *
     * @return string The component license URI.
     * @throws Components_Exception
     */
    public function getLicenseLocation()
    {
        return $this->getPackageXml()->getLicenseLocation();
    }

    /**
     * Return the package notes.
     *
     * @return string The notes for the current release.
     * @throws Components_Exception
     */
    public function getNotes()
    {
        return $this->getPackageXml()->getNotes();
    }

    /**
     * Indicate if the component has a local package.xml.
     *
     * @return boolean True if a package.xml exists.
     */
    public function hasLocalPackageXml()
    {
        return false;
    }

    /**
     * Returns the link to the change log.
     *
     * @return string The link to the change log.
     * @throws Components_Exception
     */
    public function getChangelogLink()
    {
        throw new Components_Exception('Not supported!');
    }

    /**
     * Return a data array with the most relevant information about this
     * component.
     *
     * @return stdClass Information about this component.
     * @throws Components_Exception
     */
    public function getData()
    {
        throw new Components_Exception('Not supported!');
    }

    /**
     * Return the path to the release notes.
     *
     * @return string|boolean The path to the release notes or false.
     */
    public function getReleaseNotesPath()
    {
        return false;
    }

    /**
     * Return the dependency list for the component.
     *
     * @return Components_Component_DependencyList The dependency list.
     */
    public function getDependencyList()
    {
        return $this->_factory->createDependencyList($this);
    }

    /**
     * Return the path to a DOCS_ORIGIN file within the component.
     *
     * @return string|NULL The path name or NULL if there is no DOCS_ORIGIN file.
     */
    public function getDocumentOrigin()
    {
        return null;
    }

    /**
     * Update the package.xml file for this component.
     *
     * @param string $action  The action to perform. Either "update", "diff",
     *                        or "print".
     * @param array $options  Options for this operation.
     *
     * @throws Components_Exception
     */
    public function updatePackage($action, $options)
    {
        throw new Components_Exception(
            'Updating the package.xml is not supported!'
        );
    }

    /**
     * Update the component changelog.
     *
     * @param string $log    The log entry.
     * @param array $options Options for the operation.
     *
     * @return string[] Output messages.
     * @throws Components_Exception
     */
    public function changed($log, $options)
    {
        throw new Components_Exception(
            'Updating the change log is not supported!'
        );
    }

    /**
     * Timestamp the package.xml file with the current time.
     *
     * @param array $options Options for the operation.
     *
     * @return string The success message.
     * @throws Components_Exception
     */
    public function timestampAndSync($options)
    {
        throw new Components_Exception(
            'Timestamping is not supported!'
        );
    }

    /**
     * Add the next version to the package.xml.
     *
     * @param string $version           The new version number.
     * @param string $initial_note      The text for the initial note.
     * @param string $stability_api     The API stability for the next release.
     * @param string $stability_release The stability for the next release.
     * @param array $options            Options for the operation.
     *
     * @throws Components_Exception
     */
    public function nextVersion(
        $version,
        $initial_note,
        $stability_api = null,
        $stability_release = null,
        $options = array()
    )
    {
        throw new Components_Exception(
            'Setting the next version is not supported!'
        );
    }

    /**
     * Replace the current sentinel.
     *
     * @param string $changes New version for the CHANGES file.
     * @param string $app     New version for the Application.php file.
     * @param array $options  Options for the operation.
     *
     * @return string The success message.
     * @throws Components_Exception
     */
    public function currentSentinel($changes, $app, $options)
    {
        throw new Components_Exception(
            'Modifying the sentinel is not supported!'
        );
    }

    /**
     * Tag the component.
     *
     * @param string $tag                      Tag name.
     * @param string $message                  Tag message.
     * @param Components_Helper_Commit $commit The commit helper.
     *
     * @throws Components_Exception
     */
    public function tag($tag, $message, $commit)
    {
        throw new Components_Exception(
            'Tagging is not supported!'
        );
    }

    /**
     * Identify the repository root.
     *
     * @param Components_Helper_Root $helper The root helper.
     *
     * @throws Components_Exception
     */
    public function repositoryRoot(Components_Helper_Root $helper)
    {
        throw new Components_Exception(
            'Identifying the repository root is not supported!'
        );
    }

    /**
     * Install the channel of this component in the environment.
     *
     * @param Components_Pear_Environment $env  The environment to install
     *                                          into.
     * @param array $options                    Install options.
     *
     * @throws Components_Exception
     * @throws Components_Exception_Pear
     */
    public function installChannel(
        Components_Pear_Environment $env, $options = array()
    )
    {
        $channel = $this->getChannel();
        if (!empty($channel)) {
            $env->provideChannel(
                $channel,
                $options,
                sprintf(' [required by %s]', $this->getName())
            );
        }
    }

    /**
     * Return the application options.
     *
     * @return array The options.
     */
    protected function getOptions()
    {
        return $this->_config->getOptions();
    }

    /**
     * Return the factory.
     *
     * @return Components_Component_Factory The factory.
     */
    protected function getFactory()
    {
        return $this->_factory;
    }

    /**
     * Create the specified directory.
     *
     * @param string $destination The destination path.
     */
    protected function createDestination($destination)
    {
        if (!file_exists($destination)) {
            mkdir($destination, 0700, true);
        }
    }

    /**
     * Return a PEAR package representation for the component.
     *
     * @return Horde_Pear_Package_Xml The package representation.
     * @throws Components_Exception
     */
    protected function getPackageXml()
    {
        throw new Components_Exception('Not supported!');
    }

    /**
     * Derive the basic PEAR install options from the current option set.
     *
     * @param array $options The current options.
     *
     * @return array The installation options.
     */
    protected function getBaseInstallationOptions($options)
    {
        $installation_options = array();
        $installation_options['force'] = !empty($options['force']);
        $installation_options['nodeps'] = !empty($options['nodeps']);
        return $installation_options;
    }

    /**
     * Check if the library has a CI job.
     *
     * @return boolean True if a CI job is defined.
     * @throws Components_Exception
     */
    protected function _hasCi()
    {
        if ($this->getChannel() != 'pear.horde.org') {
            return false;
        }
        $client = new Horde_Http_Client(array('request.timeout' => 15));
        try {
            $response = $client->get('http://ci.horde.org/job/' . str_replace('Horde_', '', $this->getName() . '/api/json'));
        } catch (Horde_Http_Exception $e) {
            return false;
        }
        return $response->code != 404;
    }
}
