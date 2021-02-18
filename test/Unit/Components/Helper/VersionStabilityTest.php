<?php
/**
 * Test the version/stability check.
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
use Horde\Components\TestCase;
use Horde\Components\Helper\Version as HelperVersion;
use Horde\Components\Exception;
/**
 * Test the version/stability check.
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
class HelperVersionStabilityTest extends TestCase
{
    public function testStable()
    {
        $this->assertNull(
            HelperVersion::validateReleaseStability(
                '4.0.0', 'stable'
            )
        );
    }

    public function testInvalidStable()
    {
        try {
            HelperVersion::validateReleaseStability(
                '4.0.0', 'beta'
            );
            $this->fail('No exception!');
        } catch (Exception $e) {
            $this->assertEquals(
                'Stable version "4.0.0" marked with invalid release stability "beta"!',
                $e->getMessage()
            );
        }
    }

    public function testAlpha()
    {
        $this->assertNull(
            HelperVersion::validateReleaseStability(
                '4.0.0alpha1', 'alpha'
            )
        );
    }

    public function testInvalidAlpha()
    {
        try {
            HelperVersion::validateReleaseStability(
                '4.0.0alpha1', 'stable'
            );
            $this->fail('No exception!');
        } catch (Exception $e) {
            $this->assertEquals(
                'alpha version "4.0.0alpha1" marked with invalid release stability "stable"!',
                $e->getMessage()
            );
        }
    }

    public function testBeta()
    {
        $this->assertNull(
            HelperVersion::validateReleaseStability(
                '4.0.0beta1', 'beta'
            )
        );
    }

    public function testInvalidBeta()
    {
        try {
            HelperVersion::validateReleaseStability(
                '4.0.0beta1', 'stable'
            );
            $this->fail('No exception!');
        } catch (Exception $e) {
            $this->assertEquals(
                'beta version "4.0.0beta1" marked with invalid release stability "stable"!',
                $e->getMessage()
            );
        }
    }

    public function testRc()
    {
        $this->assertNull(
            HelperVersion::validateReleaseStability(
                '4.0.0RC1', 'beta'
            )
        );
    }

    public function testInvalidRc()
    {
        try {
            HelperVersion::validateReleaseStability(
                '4.0.0RC1', 'stable'
            );
            $this->fail('No exception!');
        } catch (Exception $e) {
            $this->assertEquals(
                'beta version "4.0.0RC1" marked with invalid release stability "stable"!',
                $e->getMessage()
            );
        }
    }

    public function testDev()
    {
        $this->assertNull(
            HelperVersion::validateReleaseStability(
                '4.0.0dev1', 'devel'
            )
        );
    }

    public function testInvalidDev()
    {
        try {
            HelperVersion::validateReleaseStability(
                '4.0.0dev1', 'stable'
            );
            $this->fail('No exception!');
        } catch (Exception $e) {
            $this->assertEquals(
                'devel version "4.0.0dev1" marked with invalid release stability "stable"!',
                $e->getMessage()
            );
        }
    }

    public function testApiRc()
    {
        $this->assertNull(
            HelperVersion::validateApiStability(
                '4.0.0RC1', 'beta'
            )
        );
    }

    public function testApiStable()
    {
        $this->assertNull(
            HelperVersion::validateApiStability(
                '4.0.0', 'stable'
            )
        );
    }

    public function testInvalidApiStable()
    {
        try {
            HelperVersion::validateApiStability(
                '4.0.0', 'beta'
            );
            $this->fail('No exception!');
        } catch (Exception $e) {
            $this->assertEquals(
                'Stable version "4.0.0" marked with invalid api stability "beta"!',
                $e->getMessage()
            );
        }
    }
}
