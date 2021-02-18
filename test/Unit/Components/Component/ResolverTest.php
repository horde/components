<?php
/**
 * Test the component resolver.
 *
 * PHP Version 7
 *
 * @category   Horde
 * @package    Components
 * @subpackage UnitTests
 * @author     Gunnar Wrobel <wrobel@pardus.de>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components\Unit\Components\Component;
use Horde\Components\TestCase;
use Horde\Components\Component\Resolver;
use Horde\Components\Helper\Root as HelperRoot;

/**
 * Test the component resolver.
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
class ResolverTest extends TestCase
{
    public function testResolveName()
    {
        $resolver = $this->_getResolver();
        $this->assertInstanceOf(
            'Horde\Components\Component',
            $resolver->resolveName('Install', 'pear.horde.org', array('git'))
        );
    }

    private function _getResolver()
    {
        return new Resolver(
            new HelperRoot(
                null, null, __DIR__ . '/../../../fixture/framework'
            ),
            $this->getComponentFactory()
        );
    }
}
