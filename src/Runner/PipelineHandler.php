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
class PipelineHandler implements HandlerInterface
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
        $options = $input->getApplicationConfig()->getOptions();
        $arguments = $input->getApplicationConfig()->getArguments();
        // Find out which pipeline
        $pipelineName = $arguments[1] ?? '';
        if (empty($pipelineName)) {
            $this->output->error('No pipeline name provided!');
        }
        if (strpos($pipelineName, ':')) {
            $pipelineConfigPath = explode($pipelineName, ':');
        } else {
            $pipelineConfigPath = [$pipelineName];
        }
        $pipelineNames = [];
        foreach($options['pipeline'] as $L1Key => $pipelineL2) {
            if (!empty($pipelineL2) && is_string(array_keys($pipelineL2)[0])) {
                foreach ($pipelineL2  as $L2Key => $L3) {
                    $pipelineNames[] = "$L1Key:$L2Key";
                }
            } else {
                $pipelineNames[] = $L1Key;
            }
        }
        // Check if such a pipeline exists.
        if (in_array($pipelineName, $pipelineNames, true)) {
            $this->output->info('Checking Pipeline: ' . $pipelineName);
        } else {
            $this->output->error('Pipeline not found');
            $this->output->bold('Choose any of:');
            foreach ($pipelineNames as $available) {
                $this->output->bold($available);
            }
        }

    }
}
