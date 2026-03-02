<?php

/**
 * Horde\Components\Runner\Init:: create new metadata.
 *
 * PHP version 8.2+
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Runner;

use Horde\Components\Exception;
use Horde\Components\Output;

/**
 * Horde\Components\Runner\Pipeline:: Run clean room pipelines
 *
 * Copyright 2018-2026 Horde LLC (http://www.horde.org/)
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
     * @param array $arguments CLI arguments
     * @param array $options CLI options including pipeline configuration
     * @param Output $output The output handler
     */
    public function __construct(
        private readonly array $arguments,
        private readonly array $options,
        private readonly Output $output
    ) {}

    public function run(): void
    {
        // Find out which pipeline
        $pipelineName = $this->arguments[1] ?? '';
        if (empty($pipelineName)) {
            $this->output->error('No pipeline name provided!');
        }
        if (strpos($pipelineName, ':')) {
            $pipelineConfigPath = explode($pipelineName, ':');
        } else {
            $pipelineConfigPath = [$pipelineName];
        }
        $pipelineNames = [];
        foreach ($this->options['pipeline'] as $L1Key => $pipelineL2) {
            if (!empty($pipelineL2) && is_string(array_keys($pipelineL2)[0])) {
                foreach ($pipelineL2 as $L2Key => $L3) {
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
