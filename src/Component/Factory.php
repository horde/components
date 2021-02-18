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
use Horde\Components\Component;
use Horde\Components\Config;
use Horde\Components\Exception;
use Horde\Components\Output;
use Horde\Components\Helper\ChangeLog as HelperChangeLog;
use Horde\Components\Helper\Root as HelperRoot;
use Horde\Components\Pear\Factory as PearFactory;
use Horde\Components\Release\Notes as ReleaseNotes;
/**
 * Generates component instances and helpers.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @author   Jan Schneider <jan@horde.org>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Factory
{
    /**
     * The configuration for the current job.
     *
     * @var Config
     */
    protected $_config;

    /**
     * The factory for PEAR handlers.
     *
     * @var PearFactory
     */
    protected $_factory;

    /**
     * The HTTP client for remote access.
     *
     * @var \Horde_Http_Client
     */
    protected $_client;

    /**
     * The output handler.
     *
     * @var Output
     */
    protected $_output;

    /**
     * The first source component generated
     *
     * @var Component
     */
    protected $_first_source;

    /**
     * The git root path.
     *
     * @var HelperRoot
     */
    protected $_git_root;

    /**
     * The resolver instance.
     *
     * @var Resolver
     */
    protected $_resolver;

    /**
     * The release notes handler.
     *
     * @var ReleaseNotes
     */
    protected $_notes;

    /**
     * Constructor.
     *
     * @param Config       $config  The configuration for the
     *                                         current job.
     * @param PearFactory $factory Generator for all required PEAR
     *                                         components.
     * @param \Horde_Http_Client       $client  The HTTP client for remote
     *                                         access.
     * @param Output        $output  The output handler.
     * @param ReleaseNotes $notes  The release notes.
     */
    public function __construct(
        Config $config,
        PearFactory $factory,
        \Horde_Http_Client $client,
        Output $output,
        ReleaseNotes $notes
    )
    {
        $this->_config  = $config;
        $this->_factory = $factory;
        $this->_client  = $client;
        $this->_output  = $output;
        $this->_notes = $notes;
    }

    /**
     * Create a representation for a source component.
     *
     * @param string  $directory The directory of the component.
     *
     * @return Source The source component.
     */
    public function createSource($directory)
    {
        $component = new Source(
            $directory,
            $this->_config,
            $this->_notes,
            $this
        );
        if ($this->_first_source === null) {
            $this->_first_source = $component;
        }
        return $component;
    }

    /**
     * Create a representation for a component archive.
     *
     * @param string $archive The path to the component archive.
     *
     * @return Archive The archive component.
     */
    public function createArchive($archive)
    {
        $component = new Archive(
            $archive,
            $this->_config,
            $this
        );
        return $component;
    }

    /**
     * Create a representation for a remote component.
     *
     * @param string            $name      The name of the component.
     * @param string            $stability The stability of the component.
     * @param string            $channel   The component channel.
     * @param \Horde_Pear_Remote $remote    The remote server handler.
     *
     * @return Remote The remote component.
     */
    public function createRemote(
        $name, $stability, $channel, \Horde_Pear_Remote $remote
    )
    {
        return new Remote(
            $name,
            $stability,
            $channel,
            $remote,
            $this->_client,
            $this->_config,
            $this
        );
    }

    /**
     * Creates a changelog helper.
     *
     * @param Source $component The component.
     *
     * @return HelperChangeLog  Changelog helper.
     */
    public function createChangelog(Source $component)
    {
        return new HelperChangeLog($this->_config, $component);
    }

    /**
     * Provide access to the PEAR helper factory.
     *
     * @return PearFactory The PEAR factory.
     */
    public function pear()
    {
        return $this->_factory;
    }

    /**
     * Create a component dependency list.
     *
     * @param Component $component The component.
     *
     * @return DependencyList The dependency list.
     */
    public function createDependencyList(Component $component)
    {
        return new DependencyList($component, $this);
    }

    /**
     * Create a component dependency representation.
     *
     * @param array $dependencies The dependency information.
     *
     * @return Dependency The dependency.
     */
    public function createDependency($dependencies)
    {
        return new Dependency($dependencies, $this);
    }

    /**
     * Get the component resolver.
     *
     * @return Resolver The component resolver.
     */
    public function getResolver()
    {
        if (!isset($this->_resolver)) {
            $this->_resolver = $this->createResolver();
        }
        return $this->_resolver;
    }

    /**
     * Create a component resolver.
     *
     * @return Resolver The component resolver.
     */
    public function createResolver()
    {
        return new Resolver(
            $this->getGitRoot(),
            $this
        );
    }

    /**
     * Create a remote PEAR server handler for a specific channel.
     *
     * @param string $channel The channel name.
     *
     * @return \Horde_Pear_Remote The remote handler.
     */
    public function createRemoteChannel($channel)
    {
        return new \Horde_Pear_Remote($channel);
    }

    /**
     * Create the sentinel helper.
     *
     * @param string $directory The directory the sentinel should act in.
     *
     * @return \Horde_Release_Sentinel The sentinel helper.
     */
    public function createSentinel($directory)
    {
        return new \Horde_Release_Sentinel($directory);
    }

    /**
     * Return the repository root helper.
     *
     * @return HelperRoot The helper.
     */
    public function getGitRoot()
    {
        if (!isset($this->_git_root)) {
            $this->_git_root = $this->createGitRoot();
        }
        return $this->_git_root;
    }

    /**
     * Create the repository root helper.
     *
     * @return HelperRoot The helper.
     */
    public function createGitRoot()
    {
        if (isset($this->_first_source)) {
            return new HelperRoot(
                $this->_config->getOptions(),
                $this->_first_source
            );
        } else {
            return new HelperRoot(
                $this->_config->getOptions()
            );
        }
    }

    /**
     * Return the package.xml handler.
     *
     * @param string $package_xml_path Path to the package.xml file.
     *
     * @return \Horde_Pear_Package_Xml
     */
    public function createPackageXml($package_xml_path)
    {
        return new \Horde_Pear_Package_Xml($package_xml_path);
    }

    /**
     * Creates a new package.xml.
     *
     * @param string $package_xml_dir Path to the parent directory of the
     *                                new package.xml file.
     *
     * @throws \Horde_Pear_Exception
     */
    public function createPackageFile($package_xml_dir)
    {
        $type = new \Horde_Pear_Package_Type_HordeSplit($package_xml_dir);
        $type->writePackageXmlDraft();
    }

    /**
     * Creates a new package.xml for a theme.
     *
     * @param string $package_xml_dir Path to the parent directory of the
     *                                new package.xml file.
     *
     * @throws \Horde_Pear_Exception
     */
    public function createThemePackageFile($package_xml_dir)
    {
        $type = new \Horde_Pear_Package_Type_HordeTheme($package_xml_dir);
        $type->writePackageXmlDraft();
    }

    /**
     * Creates a new content listing.
     *
     * @param string $package_xml_dir Path to the parent directory of the
     *                                new package.xml file.
     *
     * @return \Horde_Pear_Package_Contents_List
     * @throws Exception
     */
    public function createContentList($package_xml_dir)
    {
        $type = new \Horde_Pear_Package_Type_HordeSplit(
            $package_xml_dir,
            $this->getGitRoot()->getRoot()
        );
        return new \Horde_Pear_Package_Contents_List($type);
    }

    /**
     * Creates a new content listing for a theme.
     *
     * @param string $package_xml_dir Path to the parent directory of the
     *                                new package.xml file.
     *
     * @return \Horde_Pear_Package_Contents_List
     * @throws Exception
     */
    public function createThemeContentList($package_xml_dir)
    {
        return new \Horde_Pear_Package_Contents_List(
            new \Horde_Pear_Package_Type_HordeTheme(
                $package_xml_dir,
                $this->getGitRoot()->getRoot()
            )
        );
    }
}
