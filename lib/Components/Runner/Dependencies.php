<?php
/**
 * Components_Runner_Dependencies:: lists a tree of dependencies.
 *
 * PHP version 5
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

/**
 * Components_Runner_Dependencies:: lists a tree of dependencies.
 *
 * Copyright 2010-2017 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Components_Runner_Dependencies
{
    /**
     * The configuration for the current job.
     *
     * @var Components_Config
     */
    private $_config;

    /**
     * The list helper.
     *
     * @var Components_Helper_Dependencies
     */
    private $_dependencies;

    /**
     * Constructor.
     *
     * @param Components_Config              $config       The configuration
     *                                                     for the current job.
     * @param Components_Helper_Dependencies $dependencies The list helper.
     */
    public function __construct(
        Components_Config $config,
        Components_Helper_Dependencies $dependencies
    ) {
        $this->_config       = $config;
        $this->_dependencies = $dependencies;
    }

    public function run()
    {
        $options = $this->_config->getOptions();
        if (!empty($options['no_tree'])) {
            print Horde_Yaml::dump(
                $this->_config->getComponent()->getDependencies()
            );
        } else {
            $this->_dependencies->listTree(
                $this->_config->getComponent(), $options
            );
        }
    }
}
