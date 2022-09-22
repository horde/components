<?php
/**
 * ConfigInterface
 *
 * PHP Version 8
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components\Config;

use Horde\Injector\Injector;
use RuntimeException;

class ComposedConfig extends ComposedConfigInterface
{
    public function create(Injector $injector)
    {
        $config = new ComposedConfig();
        $config->addConfigAfter($injector->get(BuiltinDefaultConfig::class), 'builtin');
        $config->addConfigBefore($injector->get(EnvironmentConfig::class), 'environment', 'builtin');
//        $config->addConfigBefore($injector->get(EnvironmentConfig::class), 'environment', 'builtin');      
        return $config;
    }
}
