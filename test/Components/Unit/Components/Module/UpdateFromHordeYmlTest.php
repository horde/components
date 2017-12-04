<?php
/**
 * Copyright 2017 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category   Horde
 * @package    Components
 * @subpackage UnitTests
 * @author     Jan Schneider <jan@horde.org>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

/**
 * Test the Update module updating package.xml from .horde.yml.
 *
 * @category   Horde
 * @package    Components
 * @subpackage UnitTests
 * @author     Jan Schneider <jan@horde.org>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Components_Unit_Components_Module_UpdateFromHordeYmlTest
extends Components_TestCase
{
    public function setUp()
    {
        $this->yamlFile = __DIR__ . '/../../../fixture/horde_yml/.horde.yml';
        $this->yaml = file_get_contents($this->yamlFile);
    }

    public function tearDown()
    {
        file_put_contents($this->yamlFile, $this->yaml);
    }

    public function testNoChangeInPackageXml()
    {
        $this->assertStringEqualsFile(
            __DIR__ . '/../../../fixture/horde_yml/package.xml',
            $this->_update()
        );
    }

    public function testNoChangeInComposerJson()
    {
        $this->assertStringEqualsFile(
            __DIR__ . '/../../../fixture/horde_yml/composer.json',
            $this->_update(true)
        );
    }

    public function testChangesInPackageXml()
    {
        $yaml = $this->_changeYaml();
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $this->_update());
        $xml = new Horde_Pear_Package_Xml($stream);
        fclose($stream);
        $this->assertEquals($yaml['id'], $xml->getName());
        $this->assertEquals($yaml['full'], $xml->getSummary());
        $this->assertEquals($yaml['description'], $xml->getDescription());
        $this->assertEquals($yaml['version']['release'], $xml->getVersion());
        $this->assertEquals(
            $yaml['version']['api'],
            $xml->getNodeText('/p:package/p:version/p:api')
        );
        $this->assertEquals(
            $yaml['state']['release'],
            $xml->getState('release')
        );
        $this->assertEquals(
            $yaml['state']['api'],
            $xml->getState('api')
        );
        $this->assertEquals($yaml['license']['identifier'], $xml->getLicense());
        $this->assertEquals($yaml['license']['uri'], $xml->getLicenseLocation());
        $authors = $xml->getLeads();
        $this->assertCount(2, $authors);
        foreach ($authors as $id => $author) {
            foreach (array('name', 'user', 'email') as $attribute) {
                $this->assertEquals(
                    $yaml['authors'][$id][$attribute],
                    $author[$attribute],
                    $attribute . ' not matching for author ' . $id
                );
            }
            $this->assertEquals(
                $yaml['authors'][$id]['active'],
                $author['active'] == 'yes'
            );
        }

        $dependencies = $xml->getDependencies();
        $this->assertEquals(
            array(
                array(
                    'type' => 'php',
                    'optional' => 'no',
                    'rel' => 'ge',
                    'version' => '5.3.0',
                ),
                array(
                    'type' => 'php',
                    'optional' => 'no',
                    'rel' => 'le',
                    'version' => '8.0.0alpha1',
                ),
                array(
                    'type' => 'pkg',
                    'name' => 'PEAR',
                    'channel' => 'pear.php.net',
                    'optional' => 'no',
                    'rel' => 'ge',
                    'version' => '1.7.0',
                ),
                array(
                    'name' => 'Horde_Core',
                    'channel' => 'pear.horde.org',
                    'type' => 'pkg',
                    'optional' => 'no',
                    'rel' => 'ge',
                    'version' => '2.31.0',
                    'min' => '2.31.0',
                    'max' => '3.0.0alpha1',
                ),
                array(
                    'name' => 'Horde_Core',
                    'channel' => 'pear.horde.org',
                    'type' => 'pkg',
                    'optional' => 'no',
                    'rel' => 'le',
                    'version' => '3.0.0alpha1',
                    'min' => '2.31.0',
                    'max' => '3.0.0alpha1',
                ),
                array(
                    'name' => 'Horde_Date',
                    'channel' => 'pear.horde.org',
                    'type' => 'pkg',
                    'optional' => 'no',
                    'rel' => 'ge',
                    'version' => '2.0.0',
                    'min' => '2.0.0',
                    'max' => '3.0.0alpha1',
                ),
                array(
                    'name' => 'Horde_Date',
                    'channel' => 'pear.horde.org',
                    'type' => 'pkg',
                    'optional' => 'no',
                    'rel' => 'le',
                    'version' => '3.0.0alpha1',
                    'min' => '2.0.0',
                    'max' => '3.0.0alpha1',
                ),
                array(
                    'name' => 'Horde_Form',
                    'channel' => 'pear.horde.org',
                    'type' => 'pkg',
                    'optional' => 'no',
                    'rel' => 'ge',
                    'version' => '2.0.16',
                    'min' => '2.0.16',
                    'max' => '3.0.0alpha1',
                ),
                array(
                    'name' => 'Horde_Form',
                    'channel' => 'pear.horde.org',
                    'type' => 'pkg',
                    'optional' => 'no',
                    'rel' => 'le',
                    'version' => '3.0.0alpha1',
                    'min' => '2.0.16',
                    'max' => '3.0.0alpha1',
                ),
                array(
                    'name' => 'iconv',
                    'type' => 'ext',
                    'optional' => 'yes',
                ),
            ),
            $dependencies
        );
    }

    public function testChangesInComposerJson()
    {
        $yaml = $this->_changeYaml();
        $json = json_decode($this->_update(true), true);
        $this->assertEquals('horde/' . $yaml['id'], $json['name']);
        $this->assertEquals($yaml['full'], $json['description']);
        $this->assertEquals($yaml['version']['release'], $json['version']);
        $this->assertEquals($yaml['license']['identifier'], $json['license']);
        $this->assertCount(2, $json['authors']);
        foreach ($json['authors'] as $id => $author) {
            foreach (array('name', 'role', 'email') as $attribute) {
                $this->assertEquals(
                    $yaml['authors'][$id][$attribute],
                    $author[$attribute],
                    $attribute . ' not matching for author ' . $id
                );
            }
        }
        $this->assertEquals(
            array(
                'php' => '^5.3 || ^7',
                'pear-pear.horde.org/Horde_Core' => '^2.31',
                'pear-pear.horde.org/Horde_Date' => '^2',
                'pear-pear.horde.org/Horde_Form' => '^2.0.16',
            ),
            $json['require']
        );
        $this->assertEquals(
            array(
                'ext-iconv' => '*',
            ),
            $json['suggest']
        );
    }

    public function testSettingNewVersion()
    {
        $this->assertStringEqualsFile(
            __DIR__ . '/../../../fixture/deps/package-new.xml',
            $this->_update(false, 'deps', array('--new-version', '2.32.0'))
        );
    }

    protected function _changeYaml()
    {
        $yaml = Horde_Yaml::load($this->yaml);
        $yaml['id'] = 'horde2';
        $yaml['name'] = 'Horde2';
        $yaml['full'] = 'New Name';
        $yaml['description'] = 'New Description.';
        $yaml['version']['release'] = '1.0.0';
        $yaml['version']['api'] = '1.0.0';
        $yaml['state']['release'] = 'beta';
        $yaml['state']['api'] = 'beta';
        $yaml['license']['uri'] = 'http://www.horde.org/licenses/gpl';
        $yaml['license']['identifier'] = 'GPL';
        $yaml['authors'] = array(
            array(
                'name' => 'Jan Schneider',
                'user' => 'jan',
                'email' => 'jan@horde.org',
                'active' => true,
                'role' => 'lead',
            ),
            array(
                'name' => 'John Doe',
                'user' => 'john',
                'email' => 'john@horde.org',
                'active' => false,
                'role' => 'lead',
            ),
        );
        $yaml['dependencies'] = array(
            'required' => array(
                'php' => '^5.3 || ^7',
                'pear' => array(
                    'pear.horde.org/Horde_Core' => '^2.31',
                    'pear.horde.org/Horde_Date' => '^2',
                    'pear.horde.org/Horde_Form' => '^2.0.16',
                ),
            ),
            'optional' => array(
                'ext' => array(
                    'iconv' => '*'
                ),
            ),
        );

        file_put_contents($this->yamlFile, Horde_Yaml::dump($yaml));

        return $yaml;
    }

    protected function _update($composer = false, $dir = 'horde_yml', $additional = array())
    {
        $_SERVER['argv'] = array_merge(
            array(
                'horde-components',
                '--action=print',
                '--updatexml',
            ),
            $additional,
            array(__DIR__ . '/../../../fixture/' . $dir)
        );
        $result = str_replace(
            date('Y-m-d'),
            '2010-08-22',
            $this->_callStrictComponents()
        );
        if (!preg_match('/(.*<\/package>\n)(\{.*)/ms', $result, $files)) {
            $this->fail("Unexpected result:\n" . $result);
        }
        return $composer ? $files[2] : $files[1];
    }
}