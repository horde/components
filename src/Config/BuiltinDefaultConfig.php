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

class BuiltinDefaultConfig implements ConfigInterface
{
    use ConfigTrait;

    public function __construct()
    {
        $this->settings['data_dir'] = dirname(__FILE__, 3) . '/data';
        $this->settings['config_dir'] = dirname(__FILE__, 3) . '/config';
        $this->settings['output_dir'] = dirname(__FILE__, 3) . '/output';
        $this->settings['verbosity'] = '0';
        $this->settings['api_auth_schema'] = 'Bearer';
        // Ensure the API is effectively blocked unless configured
        $this->settings['api_auth_key'] = bin2hex(random_bytes(64));
        
    }
}
