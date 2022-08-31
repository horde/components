<?php
/**
 * Test the package release task.
 *
 * PHP Version 7
 *
 * @category   Horde
 * @package    Components
 * @subpackage UnitTests
 * @author     Gunnar Wrobel <wrobel@pardus.de>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Unit\Components\Release\Task;

use Horde\Components\Test\TestCase;

/**
 * Test the package release task.
 *
 * Copyright 2011-2020 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category   Horde
 * @package    Components
 * @subpackage UnitTests
 * @author     Gunnar Wrobel <wrobel@pardus.de>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class PackageTest extends TestCase
{
    public function testPreValidateSucceeds()
    {
        $package = $this->_getPackage();
        $task = $this->getReleaseTask('Package', $package);
        $this->assertEquals(
            [],
            $task->preValidate(['releaseserver' => 'pear.horde.org', 'releasedir' => 'B'])
        );
    }

    public function testNoReleaseServer()
    {
        $package = $this->_getPackage();
        $task = $this->getReleaseTask('Package', $package);
        $this->assertEquals(
            ['The "releaseserver" option has no value. Where should the release be uploaded?'],
            $task->preValidate(['releasedir' => 'B'])
        );
    }

    public function testNoReleaseDir()
    {
        $package = $this->_getPackage();
        $task = $this->getReleaseTask('Package', $package);
        $this->assertEquals(
            ['The "releasedir" option has no value. Where is the remote pirum install located?'],
            $task->preValidate(['releaseserver' => 'A'])
        );
    }

    public function testRunTaskWithoutUpload()
    {
        $package = $this->_getPackage();
        $package->expects($this->once())
            ->method('placeArchive')
            ->willReturn(['/some/path/to/package.xml']);
        $this->getReleaseTasks()->run(
            ['Package'],
            $package,
            ['releaseserver' => 'pear.horde.org', 'releasedir' => 'B']
        );
    }

    public function testPretend()
    {
        $package = $this->_getPackage();
        $package->expects($this->any())
            ->method('getName')
            ->will($this->returnValue('NAME'));
        $this->getReleaseTasks()->run(
            ['Package'],
            $package,
            [
                'releaseserver' => 'pear.horde.org',
                'releasedir' => 'B',
                'pretend' => true,
                'upload' => true
            ]
        );
        $this->assertEquals(
            [
                'Would package NAME now.',
                'Would run "scp [PATH TO RESULTING]/[PACKAGE.TGZ - PRETEND MODE] pear.horde.org:~/" now.',
                'Would run "ssh pear.horde.org "umask 0002 && pirum add B ~/[PACKAGE.TGZ - PRETEND MODE] && rm [PACKAGE.TGZ - PRETEND MODE]"" now.'
            ],
            $this->_output->getOutput()
        );
    }

    private function _getPackage()
    {
        $package = $this->getMockBuilder('Horde\Components\Component\Source')
        ->disableOriginalConstructor()
        ->getMock();
        $package->expects($this->any())
            ->method('getState')
            ->will($this->returnValue('stable'));
        $package->expects($this->any())
            ->method('getVersion')
            ->will($this->returnValue('1.0.0'));
        return $package;
    }
}
