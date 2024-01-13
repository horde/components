<?php
/**
 * Horde\Components\Runner\Init:: create new metadata.
 *
 * PHP version 7
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Runner;

use Horde\Components\Config;
use Horde\Components\Exception;
use Horde\Components\Output;

/**
 * Horde\Components\Runner\Pipeline:: Run clean room pipelines
 *
 * Copyright 2018-2024 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Pipeline
{
    /**
     * Constructor.
     *
     * @param Config $_config The configuration for the current job.
     * @param Output $_output The output handler.
     */
    public function __construct(
        private readonly Config $_config,
        /**
         * The output handler.
         *
         * @param Output
         */
        private readonly Output $output
    ) {
    }

    public function run(): void
    {
        $options = $this->_config->getOptions();
        $arguments = $this->_config->getArguments();
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
        foreach ($options['pipeline'] as $L1Key => $pipelineL2) {
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
