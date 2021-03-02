<?php
/**
 * Test the Release module.
 *
 * PHP Version 7
 *
 * @category   Horde
 * @package    Components
 * @subpackage UnitTests
 * @author     Gunnar Wrobel <wrobel@pardus.de>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components\Integration\Components\Module;
use Horde\Components\StoryTestCase;

/**
 * Test the Release module.
 *
 * Copyright 2010-2020 Horde LLC (http://www.horde.org/)
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
class ReleaseTest extends StoryTestCase
{
    /**
     * @scenario
     */
    public function testOption()
    {
        $this->given('the default Components setup')
            ->when('calling the package with the help option')
            ->then('the help will contain the option', '-r,\s*--release');
    }
}
