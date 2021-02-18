<?php
/**
 * Horde\Components\Runner\Init:: create new metadata.
 *
 * PHP version 7
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <lang@b1-systems.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components\Runner;
use Horde\Components\Config;
use Horde\Components\Exception;
use Horde\Components\Output;
/**
 * Horde\Components\Runner\Init:: create new metadata.
 *
 * Copyright 2018-2021 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <lang@b1-systems.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Init
{
    /**
     * The configuration for the current job.
     *
     * @var Config
     */
    private $_config;

    /**
     * The output handler.
     *
     * @param Output
     */
    private $_output;

    /**
     * Constructor.
     *
     * @param Config $config  The configuration for the current job.
     * @param Output $output  The output handler.
     */
    public function __construct(
        Config $config,
        Output $output
    )
    {
        $this->_config = $config;
        $this->_output = $output;
    }

    public function run()
    {
        $options = $this->_config->getOptions();
        $arguments = $this->_config->getArguments();

        // Use parameter values or defaults
        $authorName = !empty($options['author']) ? $options['author'] : 'Some Person';
        $authorEmail = !empty($options['email']) ? $options['email'] : 'some.person@example.com';
        $list = 'horde';
        $user = 'tbd';
        $type = $arguments[1] ?: 'library';
        $path = explode('/', getcwd());
        $id = array_pop($path);
        if ($type == 'library') {
            $homepage = 'http://www.horde.org/libraries/Horde_'. $id;
        } elseif ($type == 'application') {
            $homepage = 'http://www.horde.org/apps/' . $id;
        }
        $authors = array(
            array(
                'name' => $authorName,
                'user' => $user,
                'email' => $authorEmail,
                'role' => 'lead',
                'active' => true
            )
        );
        $dt = new \Horde_Date(mktime());
        $version = array(
            'release' => '1.0.0alpha1',
            'api' => '1.0.0'
        );
        $state = array(
            'release' => 'alpha',
            'api' => 'alpha',
        );
        $license = array(
            'identifier' => 'LGP-2.1',
            'uri' => 'http://www.horde.org/licenses/lgpl21'
        );
        $dependencies = array(
            'required' => array(
                'php' => '^5.3 || ^7',
                'pear' => array('pear.horde.org/Horde_Exception' => '^2')
            ),
            'optional' => array(
                'pear' => array('pear.horde.org/Horde_Test' => '^2.1')
            )
        );
        $description = "Long, detailed description of $id which may span multiple lines";
        $summary = "Short headline for $id";
        // First create a .horde.yml
        //$yaml = $this->_config->getComponent()->getWrapper('HordeYml'); 
        // Doesn't currently work, create a plain Horde_Yaml instead
        $yaml = array();
        $yaml['id'] = $id;
        $yaml['name'] = ucfirst($id);
        $yaml['full'] = $summary;
        $yaml['description'] = $description;
        $yaml['list'] = $list;
        $yaml['type'] = $type;
        $yaml['homepage'] = $homepage;
        $yaml['authors'] = $authors;
        $yaml['version'] = $version;
        $yaml['state'] = $state;
        $yaml['license'] = $license;
        $yaml['dependencies'] = $dependencies;
        // $yaml->save();
        file_put_contents('.horde.yml', \Horde_Yaml::dump($yaml));

        /* create a barebone xml
         * We just need to satisfy formal criteria,
         * otherwise Horde_Pear_Package_Xml and Component_Wrapper_Xml
         * will break. The actual content does not matter much,
         * it will be overwritten by the next update
         */
        $changelog = 'Initialize Module';
        /* TODO: Put this into data and use a Horde_View to render?
         * Or refactor into Horde_Pear_Package_Xml::init()?
         */
        $xml = sprintf(
'<?xml version="1.0" encoding="UTF-8"?>
<package packagerversion="1.9.2" version="2.0" xmlns="http://pear.php.net/dtd/package-2.0" xmlns:tasks="http://pear.php.net/dtd/tasks-1.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://pear.php.net/dtd/tasks-1.0 http://pear.php.net/dtd/tasks-1.0.xsd http://pear.php.net/dtd/package-2.0 http://pear.php.net/dtd/package-2.0.xsd">
 <name>%s</name>
 <channel>pear.horde.org</channel>
 <summary>%s</summary>
 <description>%s</description>
 <lead>
  <name>%s</name>
  <user>%s</user>
  <email>%s</email>
  <active>yes</active>
 </lead>
 <date>%s</date>
 <version>
  <release>%s</release>
  <api>%s</api>
 </version>
 <stability>
  <release>%s</release>
  <api>%s</api>
 </stability>
 <license uri="%s">%s</license>
 <notes>
* %s
 </notes>
 <contents>
  <dir baseinstalldir="/" name="/">
   <dir name="doc">
   </dir> <!-- /doc -->
  </dir> <!-- / -->
 </contents>
 <dependencies>
  <required>
   <php>
    <min>5.3.0</min>
    <max>8.0.0alpha1</max>
    <exclude>8.0.0alpha1</exclude>
   </php>
   <pearinstaller>
    <min>1.7.0</min>
   </pearinstaller>
   <package>
    <name>horde</name>
    <channel>pear.horde.org</channel>
    <min>5.0.0</min>
    <max>6.0.0alpha1</max>
    <exclude>6.0.0alpha1</exclude>
   </package>
   <package>
    <name>Horde_Exception</name>
    <channel>pear.horde.org</channel>
    <min>2.0.0</min>
    <max>3.0.0alpha1</max>
    <exclude>3.0.0alpha1</exclude>
   </package>
  </required>
  <optional>
  </optional>
 </dependencies>
 <phprelease>
  <filelist>
  </filelist>
 </phprelease>
 <changelog>
  <release>
   <version>
    <release>%s</release>
    <api>%s</api>
   </version>
   <stability>
    <release>%s</release>
    <api>%s</api>
   </stability>
   <date>%s</date>
   <license uri="%s">%s</license>
   <notes>
* %s
   </notes>
  </release>
 </changelog>
</package>',
            $id,
            $summary,
            $description,
            $authorName,
            $user,
            $authorEmail,
            $dt->format('Y-m-d'),
            $version['release'],
            $version['api'],
            $state['release'],
            $state['api'],
            $license['uri'],
            $license['identifier'],
            $changelog,
            $version['release'],
            $version['api'],
            $state['release'],
            $state['api'],
            $dt->format('Y-m-d'),
            $license['uri'],
            $license['identifier'],
            $changelog
        );

        file_put_contents('package.xml', $xml);

        /* Create appropriate docdir for app or library
         * don't care if it fails because it already exists
         */
        $docdir = 'doc';
        if ($type == 'library') {
            $docdir = 'doc/Horde/' . str_replace('_', '/', $id);
        }
        mkdir($docdir, 0755, true);
        $yaml = $this->_config->getComponent()->getWrapper('ChangelogYml');
        $yaml[$version['release']] = array(
            'api' => $version['api'],
            'state' => $state,
            'date' => $dt->format('Y-m-d'),
            'license' => $license,
            'notes' => $changelog
        );
        $yaml->save();
        $changes = $this->_config->getComponent()->getWrapper('Changes');
        // The changes helper seems to have no option to create a changes file
        $head = str_repeat('-', 12) . "\n";
        $changeEntry = sprintf("%s%s\n%s\n%s\n", 
            $head,
            $version['release'],
            $head,
            $changelog
        );
        file_put_contents($docdir . '/CHANGES', $changeEntry);
    }
}
