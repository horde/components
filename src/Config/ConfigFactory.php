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

class ConfigFactory
{
    public function create(Injector $injector)
    {
        $config = new ComposedConfig();
        $config->addConfigAfter($injector->get(BuiltinDefaultConfig::class), 'builtin');
        $config->addConfigBefore($injector->get(EnvironmentConfig::class), 'environment', 'builtin');
        $configDir = $config->get('config_dir');
        $defaultConfigFile = $configDir . '/conf.php';
        if (is_readable($defaultConfigFile)) {
            $config->addConfigBefore(new ArrayBackedPhpConfigFile($defaultConfigFile, 'conf'), $defaultConfigFile, 'builtin');
        }
        return $config;
    }

}
