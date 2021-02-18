<?php
/**
 * Components_Runner_Dependencies:: lists a tree of dependencies.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components\Runner;
use Horde\Components\Config;
use Horde\Components\Output;
use Horde\Components\Helper\Dependencies as HelperDependencies;

/**
 * Horde\Components\Runner\Dependencies:: lists a tree of dependencies.
 *
 * Copyright 2010-2020 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Dependencies
{
    /**
     * The configuration for the current job.
     *
     * @var Config
     */
    private $_config;

    /**
     * The list helper.
     *
     * @var HelperDependencies
     */
    private $_dependencies;

    /**
     * Constructor.
     *
     * @param Config             $config       The configuration for the current job.
     * @param HelperDependencies $dependencies The list helper.
     */
    public function __construct(
        Config $config,
        HelperDependencies $dependencies
    ) {
        $this->_config       = $config;
        $this->_dependencies = $dependencies;
    }

    public function run()
    {
        $options = $this->_config->getOptions();
        if (!empty($options['no_tree'])) {
            print \Horde_Yaml::dump(
                $this->_config->getComponent()->getDependencies()
            );
        } else {
            $this->_dependencies->listTree(
                $this->_config->getComponent(), $options
            );
        }
    }
}
