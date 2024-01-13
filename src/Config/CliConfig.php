<?php
/**
 * Components_Config_Cli:: class provides central options for the command line
 * configuration of the components tool.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Config;

use Horde\Argv\Parser;
use Horde\Components\ArgvWrapper;
use Horde\Components\Cli\ArgvParserBuilder;
use Horde\Components\Constants;
use Horde\Components\Module;

/**
 * Config\Cli:: class provides central options for the command line
 * configuration of the components tool.
 *
 * Copyright 2009-2024 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class CliConfig extends Base
{
    /**
     * Constructor.
     *
     */
    private Parser $parser;
    public function __construct(
        private ArgvWrapper $argv,
        Module|null $module
    ) {
        $builder = (new ArgvParserBuilder)->withGlobalOptions();
        if ($module) {
            $builder->withModuleOptions($module);
        }
        $this->parser = $builder->build();
        [$this->_options, $this->_arguments] = $this->parser->parseArgs();
    }
}
