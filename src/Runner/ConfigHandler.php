<?php
/**
 * A NOOP handler. Useful in places which require a handler argument.
 *
 * PHP Version 8
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Runner;
use Horde\Components\Task\HandlerInterface;
use Horde\Components\Task\InputInterface;
use Horde\Components\Task\Result;
use Horde\Components\Task\ResultInterface;
use Horde\Components\Output;
/**
 * Configuration change handler
 *
 * Copyright 2011-2022 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * Inspired by PSR-15 HandlerInterface
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class ConfigHandler implements HandlerInterface
{
    public function __construct(protected Output $output)
    {
    }
    /**
     * Handles a TaskInput and produces a Result.
     *
     * May call other collaborating code to generate the result.
     */
    public function handle(InputInterface $input): ResultInterface
    {
        $configFile = $input->getApplicationConfig()->getOption('config');
        if (!file_exists($configFile)) {
            $this->output->warn('Config File Missing:' . $configFile);
            // Initialize config in user home from a Defaults mechanism
        }
        return new Result();
    }
}
