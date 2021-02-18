<?php
/**
 * Components_Pear_Package:: provides package handling mechanisms.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components\Pear;
use Horde\Components\Exception;
use Horde\Components\Output;

/**
 * Components_Pear_Package:: provides package handling mechanisms.
 *
 * Copyright 2010-2020 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Package
{
    /**
     * The output handler.
     *
     * @param Output
     */
    private $_output;

    /**
     * The PEAR environment for the package.
     *
     * @param Environment
     */
    private $_environment;

    /**
     * The factory for PEAR class instances.
     *
     * @param Factory
     */
    private $_factory;

    /**
     * The path to the package XML file.
     *
     * @param string
     */
    private $_package_xml_path;

    /**
     * The path to the package *.tgz file.
     *
     * @param string
     */
    private $_package_tgz_path;

    /**
     * The package representation.
     *
     * @param \PEAR_PackageFile_v2
     */
    private $_package_file;

    /**
     * Constructor.
     *
     * @param Output $output The output handler.
     */
    public function __construct(Output $output)
    {
        $this->_output = $output;
    }

    /**
     * Define the surrounding PEAR environment for the package.
     *
     * @param Environment
     *
     * @return void
     */
    public function setEnvironment(Environment $environment)
    {
        $this->_environment = $environment;
    }

    /**
     * Define the factory that creates our PEAR dependencies.
     *
     * @param Factory
     *
     * @return null
     */
    public function setFactory(Factory $factory)
    {
        $this->_factory = $factory;
    }

    /**
     * Return the PEAR environment for this package.
     *
     * @return Environment
     */
    public function getEnvironment()
    {
        if ($this->_environment === null) {
            throw new Exception('You need to set the environment first!');
        }
        return $this->_environment;
    }

    /**
     * Define the package to work on.
     *
     * @param string $package_xml_path Path to the package.xml file.
     *
     * @return void
     */
    public function setPackageXml($package_xml_path)
    {
        $this->_package_xml_path = $package_xml_path;
    }

    /**
     * Return the path to the package.xml.
     *
     * @return string
     */
    public function getPackageXml()
    {
        if ($this->_package_xml_path === null) {
            throw new Exception('You need to set the package.xml path first!');
        }
        return $this->_package_xml_path;
    }

    /**
     * Return the base directory of the component.
     *
     * @return string
     */
    public function getComponentDirectory()
    {
        return dirname($this->getPackageXml());
    }

    /**
     * Define the package to work on.
     *
     * @param string $package_tgz_path Path to the *.tgz file.
     *
     * @return void
     */
    public function setPackageTgz($package_tgz_path)
    {
        $this->_package_tgz_path = $package_tgz_path;
    }

    /**
     * Return the package.xml handler.
     *
     * @return \Horde_Pear_Package_Xml
     */
    private function _getPackageXml()
    {
        return $this->_factory->getPackageXml(
            $this->_package_xml_path
        );
    }

    /**
     * Return the PEAR Package representation.
     *
     * @return \PEAR_PackageFile
     */
    private function _getPackageFile()
    {
        $this->_checkSetup();
        if ($this->_package_file === null) {
            if (!empty($this->_package_xml_path)) {
                $this->_package_file = $this->_factory->getPackageFile(
                    $this->_package_xml_path,
                    $this->getEnvironment()
                );
            } else {
                $this->_package_file = $this->_factory->getPackageFileFromTgz(
                    $this->_package_tgz_path,
                    $this->getEnvironment()
                );
            }
        }
        return $this->_package_file;
    }

    /**
     * Validate that the required parameters for providing the package definition are set.
     *
     * @return void
     *
     * @throws Exception In case some settings are missing.
     */
    private function _checkSetup()
    {
        if ($this->_environment === null
            || ($this->_package_xml_path === null
                && $this->_package_tgz_path === null)
            || $this->_factory === null) {
            throw new Exception('You need to set the factory, the environment and the path to the package file first!');
        }
    }

    /**
     * Return the name for this package.
     *
     * @return string The package name.
     */
    public function getName()
    {
        return $this->_getPackageFile()->getName();
    }

    /**
     * Return the channel for the package.
     *
     * @return string The package channel.
     */
    public function getChannel()
    {
        return $this->_getPackageFile()->getChannel();
    }

    /**
     * Return the description for this package.
     *
     * @return string The package description.
     */
    public function getDescription()
    {
        return $this->_getPackageFile()->getDescription();
    }

    /**
     * Return the version for this package.
     *
     * @return string The package version.
     */
    public function getVersion()
    {
        return $this->_getPackageFile()->getVersion();
    }

    /**
     * Return the license for this package.
     *
     * @return string The package license.
     */
    public function getLicense()
    {
        return $this->_getPackageFile()->getLicense();
    }

    /**
     * Return the license location for this package.
     *
     * @return string The package license location.
     */
    public function getLicenseLocation()
    {
        return $this->_getPackageFile()->getLicenseLocation();
    }

    /**
     * Return the summary for this package.
     *
     * @return string The package summary.
     */
    public function getSummary()
    {
        return $this->_getPackageFile()->getSummary();
    }

    /**
     * Return the leads for this package.
     *
     * @return string The package leads.
     */
    public function getLeads()
    {
        return $this->_getPackageFile()->getLeads();
    }

    /**
     * Return the maintainers for this package.
     *
     * @return string The package maintainers.
     */
    public function getMaintainers()
    {
        return $this->_getPackageFile()->getMaintainers();
    }

    /**
     * Return the list of files that should be installed for this package.
     *
     * @return array The file list.
     */
    public function getInstallationFilelist()
    {
        return $this->_getPackageFile()->getInstallationFilelist();
    }

    /**
     * Return the dependencies for the package.
     *
     * @return array The list of dependencies.
     */
    public function getDependencies()
    {
        return $this->_getPackageFile()->getDeps();
    }

}
