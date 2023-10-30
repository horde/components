<?php
/**
 * Represents a remote component.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Component;

use Horde\Components\Config;
use Horde\Components\Exception;
use stdClass;
use Horde\Components\Pear\Environment as PearEnvironment;
use Horde\Components\Wrapper\PackageXml;

/**
 * Represents a remote component.
 *
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
class Remote extends Base
{
    /**
     * Component version.
     *
     * @var string
     */
    private $_version;

    /**
     * Download location for the component.
     */
    private ?string $_uri = null;

    /**
     * The package file representing the component.
     */
    private ?\Horde_Pear_Package_Xml $_package = null;

    /**
    * Constructor.
    *
     * @param string $_name Component name.
     * @param string $_stability Component stability.
     * @param string $_channel Component channel.
     * @param \Horde_Pear_Remote $_remote Remote channel handler.
     * @param \Horde_Http_Client $_client The HTTP client for remote
                                         access.
    * @param Config       $config    The configuration for the
    *                                           current job.
    * @param Horde\Components\Component\Factory $factory Generator for additional
    *                                              helpers.
    */
    public function __construct(
        private $_name,
        private $_stability,
        private $_channel,
        private readonly \Horde_Pear_Remote $_remote,
        private readonly \Horde_Http_Client $_client,
        Config $config,
        Factory $factory
    ) {
        parent::__construct($config, $factory);
    }

    /**
     * Return the name of the component.
     *
     * @return string The component name.
     */
    public function getName(): string
    {
        return $this->_name;
    }

    /**
     * Return the version of the component.
     *
     * @return string The component version.
     */
    public function getVersion(): string
    {
        if (!isset($this->_version)) {
            $this->_version = $this->_remote->getLatestRelease($this->_name, $this->_stability);
        }
        return $this->_version;
    }

    /**
     * Returns the previous version of the component.
     *
     * @return string The previous component version.
     */
    public function getPreviousVersion(): string
    {
        $previousVersion = null;
        $currentVersion = $this->getVersion();
        $currentState = $this->getState();
        $releases = $this->_remote->getReleases();
        $versions = $releases->listReleases();
        usort($versions, 'version_compare');
        foreach ($versions as $version) {
            // If this is a stable version we want the previous stable version,
            // otherwise use any previous version.
            if ($currentState == 'stable' &&
                $releases->getReleaseStability($version) != 'stable') {
                continue;
            }
            if (version_compare($version, $currentVersion, '>=')) {
                return $previousVersion;
            }
            $previousVersion = $version;
        }
        return $previousVersion;
    }

    /**
     * Return the channel of the component.
     *
     * @return string The component channel.
     */
    public function getChannel(): string
    {
        return $this->_channel;
    }

    /**
     * Return the dependencies for the component.
     *
     * @return array The component dependencies.
     */
    public function getDependencies(): array
    {
        return $this->_remote->getDependencies(
            $this->getName(),
            $this->getVersion()
        );
    }

    /**
     * Return a data array with the most relevant information about this
     * component.
     *
     * @return stdClass Information about this component.
     */
    public function getData(): stdClass
    {
        $data = new \stdClass();
        $release = $this->_remote->getLatestDetails($this->_name, null);
        $data->name = $this->_name;
        $data->summary = $release->getSummary();
        $data->description = $release->getDescription();
        $data->version = $release->getVersion();
        $data->releaseDate = (string)$release->da;
        $data->download = $release->getDownloadUri();
        $data->hasCi = $this->_hasCi();
        return $data;
    }

    /**
     * Place the component source archive at the specified location.
     *
     * @param string $destination The path to write the archive to.
     * @param array  $options     Options for the operation.
     *
     * @return array An array with at least [0] the path to the resulting
     *               archive, optionally [1] an array of error strings, and [2]
     *               PEAR output.
     */
    public function placeArchive(string $destination, $options = []): array
    {
        $this->createDestination($destination);
        $this->_client->{'request.timeout'} = 60;
        file_put_contents(
            $destination . '/' . basename($this->_getDownloadUri()),
            $this->_client->get($this->_getDownloadUri())->getStream()
        );
        return [$destination . '/' . basename($this->_getDownloadUri())];
    }

    /**
     * Return the download URI of the component.
     *
     * @return string The download URI.
     */
    private function _getDownloadUri(): string
    {
        if (!isset($this->_uri)) {
            $this->_uri = $this->_remote->getLatestDownloadUri(
                $this->_name,
                $this->_stability
            );
        }
        return $this->_uri;
    }

    /**
     * Install a component.
     *
     * @param PearEnvironment $env The environment to install
     *                                         into.
     * @param array                 $options   Install options.
     * @param string                $reason    Optional reason for adding the
     *                                         package.
     */
    public function install(
        PearEnvironment $env,
        $options = [],
        $reason = ''
    ): void {
        if (empty($options['allow_remote'])) {
            throw new Exception(
                sprintf(
                    'Cannot add component "%s". Remote access has been disabled (activate with --allow-remote)!',
                    $this->getName()
                )
            );
        }

        $this->installChannel($env, $options);

        $installation_options = $this->getBaseInstallationOptions($options);
        $installation_options['channel'] = $this->getChannel();
        $env->addComponent(
            $this->getName(),
            ['channel://' . $this->getChannel() . '/' . $this->getName()],
            $installation_options,
            ' via remote channel ' . $this->getChannel(),
            $reason,
            [sprintf(
                'Adding component %s/%s via network.',
                $this->getChannel(),
                $this->getName()
            )]
        );
    }

    /**
     * Return a PEAR package representation for the component.
     *
     * @return PackageXml The package representation.
     */
    protected function getPackageXml(): PackageXml
    {
        if (!isset($this->_package)) {
            $this->_package = $this->_remote->getPackageXml(
                $this->getName(),
                $this->getVersion()
            );
        }
        return $this->_package;
    }
}
