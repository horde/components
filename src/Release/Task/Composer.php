<?php
/**
 * Components_Release_Task_Composer:: Update the composer file
 *
 * PHP version 7
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <lang@b1-systems.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components\Release\Task;
use Horde\Components\Helper\Composer as HelperComposer;

/**
 * Components_Release_Task_Composer:: Update the composer file
 *
 * Copyright 2011-2019 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <lang@b1-systems.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Composer extends Base
{
    /**
     * Run the task.
     *
     * Checkout the wanted branch
     * Supports pretend mode
     *
     * @param array $options Additional options by reference.
     *
     * @return void;
     */
    public function run(&$options)
    {
        $composer = new HelperComposer();
        $options['logger'] = $this->getOutput();

        $composer->generateComposerJson(
            $this->getComponent()->getHordeYml(),
            $options
        );
        return;
    }
}
