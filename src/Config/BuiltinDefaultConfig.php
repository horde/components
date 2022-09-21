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
namespace Horde\Components;

class BuiltinDefaultConfig implements ConfigInterface
{
    use ConfigTrait;

    public function __construct()
    {
        $this->settings['dataDir'] = dirname(__FILE__, 2) . '/data';
        $this->settings['verbosity'] = '0';
    }
}
