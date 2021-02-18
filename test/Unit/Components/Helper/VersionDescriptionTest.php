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
use Horde\Components\TestCase;
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
class HelperVersionDescriptionTest extends TestCase
{
    public function testAlpha()
    {
        $this->assertEquals(
            '4.0.0 Alpha 1',
            HelperVersion::pearToTicketDescription('4.0.0alpha1')
        );
    }

    public function testBeta()
    {
        $this->assertEquals(
            '4.0.0 Beta 1',
            HelperVersion::pearToTicketDescription('4.0.0beta1')
        );
    }

    public function testRc1()
    {
        $this->assertEquals(
            '4.0.0 Release Candidate 1',
            HelperVersion::pearToTicketDescription('4.0.0RC1')
        );
    }

    public function testRc2()
    {
        $this->assertEquals(
            '4.0.0 Release Candidate 2',
            HelperVersion::pearToTicketDescription('4.0.0RC2')
        );
    }

    public function testFourOh()
    {
        $this->assertEquals(
            '4.0.0 Final',
            HelperVersion::pearToTicketDescription('4.0.0')
        );
    }

    public function testFourOneOh()
    {
        $this->assertEquals(
            '4.1.0 Final',
            HelperVersion::pearToTicketDescription('4.1.0')
        );
    }

    public function testFourOneOhBeta1()
    {
        $this->assertEquals(
            '4.1.0 Beta 1',
            HelperVersion::pearToTicketDescription('4.1.0beta1')
        );
    }

    public function testFiveOh()
    {
        $this->assertEquals(
            '5.0.0 Final',
            HelperVersion::pearToTicketDescription('5.0.0')
        );
    }

    public function testFiveTwoOhRc2()
    {
        $this->assertEquals(
            '5.2.0 Release Candidate 2',
            HelperVersion::pearToTicketDescription('5.2.0RC2')
        );
    }

}
