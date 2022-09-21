<?php
/**
 * Delay instantiating the runner until it is called
 * 
 * PHP Version 8
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components;

use Horde\Components\Component\Identify;
use Horde\Components\Config\Cli as ConfigCli;
use Horde\Components\Config\File as ConfigFile;
use Horde\Components\Dependencies\Injector;
use Horde\Injector\TopLevel;

class RunnerProxy
{
    public function __construct(private ContainerInterface $injector)
    {

    }
}