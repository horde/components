<?php

/**
 * Test the version helper.
 *
 * PHP Version 7
 *
 * @category   Horde
 * @package    Components
 * @subpackage UnitTests
 * @author     Gunnar Wrobel <wrobel@pardus.de>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Unit\Components\Helper;

use Exception;
use Horde\Components\Test\TestCase;
use Horde\Components\Helper\Version as HelperVersion;

/**
 * Test the version helper.
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
class VersionTest extends TestCase
{
    public function testNextVersion()
    {
        $this->assertEquals(
            '5.0.1-git',
            HelperVersion::nextVersion('5.0.0')
        );
        $this->assertEquals(
            '5.0.0-git',
            HelperVersion::nextVersion('5.0.0RC1')
        );
        $this->assertEquals(
            '5.0.0-git',
            HelperVersion::nextVersion('5.0.0alpha1')
        );
    }

    public function testNextPearVersion()
    {
        $this->assertEquals(
            '5.0.1',
            HelperVersion::nextPearVersion('5.0.0')
        );
        $this->assertEquals(
            '5.0.0RC2',
            HelperVersion::nextPearVersion('5.0.0RC1')
        );
        $this->assertEquals(
            '5.0.0alpha2',
            HelperVersion::nextPearVersion('5.0.0alpha1')
        );
    }

    public function testComposerToPear()
    {
        $this->assertEquals(
            array(),
            HelperVersion::composerToPear('*')
        );
        $this->assertEquals(
            array(
                'min' => '2.0.0',
                'max' => '3.0.0alpha1',
                'exclude' => '3.0.0alpha1'
            ),
            HelperVersion::composerToPear('^2')
        );
        $this->assertEquals(
            array(
                'min' => '2.1.0',
                'max' => '3.0.0alpha1',
                'exclude' => '3.0.0alpha1'
            ),
            HelperVersion::composerToPear('^2.1')
        );
        $this->assertEquals(
            array(
                'min' => '2.1.3',
                'max' => '3.0.0alpha1',
                'exclude' => '3.0.0alpha1'
            ),
            HelperVersion::composerToPear('^2.1.3')
        );
        $this->assertEquals(
            array(
                'min' => '5.3.0',
                'max' => '8.0.0alpha1',
                'exclude' => '8.0.0alpha1'
            ),
            HelperVersion::composerToPear('^5.3 || ^7')
        );
    }

    public function testNextMinorVersion()
    {
        $map = [
            '3.0.5' => '3.1.0',
            '0.10.1' => '0.11.0',
            '0.0.0' => '0.1.0',
            '1.4.4alpha' => '1.5.0',
            '1.4.4dev5' => '1.5.0',
        ];
        foreach ($map as $input => $expected) {
            $this->assertEquals(
                $expected,
                HelperVersion::nextMinorVersion($input)
            );
        }
    }

    public function testNextVersionByPartMinor()
    {
        $map = [
            '3.0.5' => '3.1.0',
            '0.10.22' => '0.11.0',
            '0.0.0' => '0.1.0',
            '1.4.4alpha' => '1.5.0',
            '1.4.4dev5' => '1.5.0',
        ];
        foreach ($map as $input => $expected) {
            $this->assertEquals(
                $expected,
                HelperVersion::nextVersionByPart($input, 'minor')
            );
        }
    }
    public function testNextVersionByPartPatch()
    {
        $map = [
            '3.0.5' => '3.0.6',
            '0.10.22' => '0.10.23',
            '0.0.0' => '0.0.1',
            '1.4.4alpha' => '1.4.4alpha',
            '1.4.4dev5' => '1.4.4dev6',
        ];
        foreach ($map as $input => $expected) {
            $this->assertEquals(
                $expected,
                HelperVersion::nextVersionByPart($input, 'patch')
            );
        }
    }

    public function testNextVersionByPartInvalidPart()
    {
        $this->expectExceptionObject(new Exception('invalid version part. Only "patch" and "minor" are supported for now.'));
        HelperVersion::nextVersionByPart('3.0.1', 'invalid');
    }
}
