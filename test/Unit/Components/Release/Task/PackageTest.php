<?php

/**
 * Test the package release task.
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
 * Copyright 2011-2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category   Horde
 * @package    Components
 * @subpackage UnitTests
 * @author     Gunnar Wrobel <wrobel@pardus.de>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @coversNothing
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
        $this->getReleaseTasks()->run(
            ['Package'],
            $package,
            [
                'releaseserver' => 'pear.horde.org',
                'releasedir' => 'B',
                'pretend' => true,
                'upload' => true,
            ]
        );
        $output = $this->_output->getOutput();

        // Check that package message is present
        $this->assertEquals('Would package NAME now.', $output[0]);

        // Check git commands (format changed to include colon, removed "now.")
        $this->assertStringStartsWith('Would run: "scp [PATH TO RESULTING]/[PACKAGE.TGZ - PRETEND MODE] pear.horde.org:~/"', $output[1]);
        $this->assertStringStartsWith('Would run: "ssh pear.horde.org "umask 0002 && pirum add B ~/[PACKAGE.TGZ - PRETEND MODE] && rm [PACKAGE.TGZ - PRETEND MODE]""', $output[2]);
    }

    private function _getPackage()
    {
        $package = $this->getMockBuilder('Horde\Components\Component\Source')
        ->disableOriginalConstructor()
        ->getMock();
        $package->method('getState')
            ->willReturn('stable');
        $package->method('getVersion')
            ->willReturn('1.0.0');
        $package->method('getName')
            ->willReturn('NAME');
        return $package;
    }
}
