<?php
/**
 * Represents a component archive.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @author   Fabien Potencier <fabien.potencier@symfony-project.org>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components\Component;
use Horde\Components\Exception;
use Horde\Components\Config;
use Horde\Components\Pear\Environment as PearEnvironment;

/**
 * Represents a component archive.
 *
 * Copyright 2011-2020 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @author   Fabien Potencier <fabien.potencier@symfony-project.org>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Archive extends Base
{
    /**
     * Path to the archive.
     *
     * @var string
     */
    private $_archive;

    /**
     * Constructor.
     *
     * @param string                  $directory Path to the source directory.
     * @param boolean                 $shift     Did identification of the
     *                                           component consume an argument?
     * @param Config       $config    The configuration for the
     *                                           current job.
     * @param Factory $factory Generator for additional
     *                                              helpers.
     */
    public function __construct(
        string $archive,
        Config $config,
        Factory $factory
    )
    {
        $this->_archive = $archive;
        parent::__construct($config, $factory);
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
    public function placeArchive(string $destination, $options = array())
    {
        copy($this->_archive, $destination . '/' . basename($this->_archive));
        return array($destination . '/' . basename($this->_archive));
    }

    /**
     * Install a component.
     *
     * @param PearEnvironment $env The environment to install
     *                                         into.
     * @param array                 $options   Install options.
     * @param string                $reason    Optional reason for adding the
     *                                         package.
     *
     * @return void
     */
    public function install(
        PearEnvironment $env, $options = array(), $reason = ''
    )
    {
        $this->installChannel($env, $options);

        $installation_options = array();
        $installation_options['force'] = !empty($options['force']);
        $installation_options['nodeps'] = !empty($options['nodeps']);
        $installation_options['offline'] = true;

        $env->addComponent(
            $this->getName(),
            array($this->_archive),
            $installation_options,
            ' from the archive ' . $this->_archive,
            $reason
        );
    }

    /**
     * Return a PEAR package representation for the component.
     *
     * @return \Horde_Pear_Package_Xml The package representation.
     */
    protected function getPackageXml()
    {
        if (!isset($this->_package)) {
            $this->_package = $this->getFactory()->createPackageXml(
                $this->_loadPackageFromArchive()
            );
        }
        return $this->_package;
    }

    /**
     * Return the package.xml file from the archive.
     *
     * Function copied from Pirum.
     *
     * (c) 2009 - 2011 Fabien Potencier
     *
     * @return string The path to the package.xml file.
     */
    private function _loadPackageFromArchive()
    {
        if (!function_exists('gzopen')) {
            $tmpDir = \Horde_Util::createTempDir();
            copy($this->_archive, $tmpDir . '/archive.tgz');
            system('cd ' . $tmpDir . ' && tar zxpf archive.tgz');
            if (!is_file($tmpDir . '/package.xml')) {
                throw new Exception(
                    sprintf('Found no package.xml in "%s"!', $this->_archive)
                );
            }
            return $tmpDir . '/package.xml';
        }

        $gz = gzopen($this->_archive, 'r');
        if ($gz === false) {
            throw new Exception(
                sprintf('Failed extracting archive "%s"!', $this->_archive)
            );
        }
        $tar = '';
        while (!gzeof($gz)) {
            $tar .= gzread($gz, 10000);
        }
        gzclose($gz);

        while (strlen($tar)) {
            $filename = rtrim(substr($tar, 0, 100), chr(0));
            $filesize = octdec(rtrim(substr($tar, 124, 12), chr(0)));

            if ($filename != 'package.xml') {
                $offset = $filesize % 512 == 0 ? $filesize : $filesize + (512 - $filesize % 512);
                $tar = substr($tar, 512 + $offset);

                continue;
            }

            $checksum = octdec(rtrim(substr($tar, 148, 8), chr(0)));
            $cchecksum = 0;
            $tar = substr_replace($tar, '        ', 148, 8);
            for ($i = 0; $i < 512; $i++) {
                $cchecksum += ord($tar[$i]);
            }

            if ($checksum != $cchecksum) {
                throw new Exception(
                    sprintf('Invalid archive "%s"!', $this->_archive)
                );
            }

            $package = substr($tar, 512, $filesize);
            $tmpFile = \Horde_Util::getTempFile();
            file_put_contents($tmpFile, $package);
            return $tmpFile;
        }
        throw new Exception(
            sprintf('Found no package.xml in "%s"!', $this->_archive)
        );
    }
}