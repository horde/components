<?php
/**
 * Components_Pear_Factory:: generates PEAR specific handlers.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Pear;

use Horde\Components\Dependencies;
use Horde\Components\Exception;
use Horde\Components\Exception\Pear as ExceptionPear;
use Horde\Components\Pear\Environment as PearEnvironment;

/**
 * Components_Pear_Factory:: generates PEAR specific handlers.
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
class Factory
{
    /**
     * Constructor.
     *
     * @param Dependencies $_dependencies The dependency broker.
     */
    public function __construct(
        /**
         * The dependency broker.
         *
         * @param Dependencies
         */
        private readonly Dependencies $_dependencies
    ) {
    }

    /**
     * Create a representation for a PEAR environment.
     *
     * @param string $environment The path to the PEAR environment.
     * @param string $config_file The path to the configuration file.
     *
     * @return Environment The PEAR environment
     */
    public function createEnvironment($environment, $config_file): \Horde\Components\Pear\Environment
    {
        $instance = $this->_dependencies->createInstance(PearEnvironment::class);
        $instance->setFactory($this);
        $instance->setLocation(
            $environment,
            $config_file
        );
        return $instance;
    }

    /**
     * Create a package representation for a specific PEAR environment.
     *
     * @param string                          $package_file The path of the package XML file.
     * @param Environment $environment  The PEAR environment.
     *
     * @return Package The PEAR package.
     */
    public function createPackageForEnvironment(
        $package_file,
        Environment $environment
    ): \Horde\Components\Pear\Package {
        $package = $this->_createPackage($environment);
        $package->setPackageXml($package_file);
        return $package;
    }

    /**
     * Create a package representation for a specific PEAR environment.
     *
     * @param string $package_file The path of the package XML file.
     * @param string $config_file  The path to the configuration file.
     *
     * @return Package The PEAR package.
     */
    public function createPackageForPearConfig($package_file, $config_file): \Horde\Components\Pear\Package
    {
        return $this->createPackageForEnvironment(
            $package_file,
            $this->createEnvironment(dirname($config_file), $config_file)
        );
    }

    /**
     * Create a package representation for the default PEAR environment.
     *
     * @param string $package_file The path of the package XML file.
     *
     * @return Package The PEAR package.
     */
    public function createPackageForDefaultLocation($package_file): \Horde\Components\Pear\Package
    {
        return $this->createPackageForEnvironment(
            $package_file,
            $this->_dependencies->getInstance(PearEnvironment::class)
        );
    }

    /**
     * Create a package representation for a specific PEAR environment based on a *.tgz archive.
     *
     * @param string                          $package_file The path of the package *.tgz file.
     * @param Environment $environment  The environment for the package file.
     *
     * @return Package The PEAR package.
     */
    public function createTgzPackageForEnvironment(
        $package_file,
        Environment $environment
    ): \Horde\Components\Pear\Package {
        $package = $this->_createPackage($environment);
        $package->setPackageTgz($package_file);
        return $package;
    }

    /**
     * Create a generic package representation for a specific PEAR environment.
     *
     * @param Environment $environment  The PEAR environment.
     *
     * @return Package The generic PEAR package.
     */
    private function _createPackage(Environment $environment): \Horde\Components\Pear\Package
    {
        $package = $this->_dependencies->createInstance(Package::class);
        $package->setFactory($this);
        $package->setEnvironment($environment);
        return $package;
    }

    /**
     * Return the PEAR Package representation.
     *
     * @param string                          $package_xml_path Path to the package.xml file.
     * @param Environment $environment      The PEAR environment.
     */
    public function getPackageFile(
        $package_xml_path,
        Environment $environment
    ): \PEAR_PackageFile_v2 {
        $config = $environment->getPearConfig();
        $pkg = new \PEAR_PackageFile($config);
        return ExceptionPear::catchError(
            $pkg->fromPackageFile($package_xml_path, PEAR_VALIDATE_NORMAL)
        );
    }

    /**
     * Return the PEAR Package representation based on a local *.tgz archive.
     *
     * @param string                          $package_tgz_path Path to the *.tgz file.
     * @param Environment $environment      The PEAR environment.
     */
    public function getPackageFileFromTgz(
        $package_tgz_path,
        Components_Pear_Environment $environment
    ): \PEAR_PackageFile {
        $pkg = new \PEAR_PackageFile($environment->getPearConfig());
        return ExceptionPear::catchError(
            $pkg->fromTgzFile($package_tgz_path, PEAR_VALIDATE_NORMAL)
        );
    }
}
