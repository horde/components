<?php

/**
 * Components_Runner_Change:: adds a new change log entry.
 *
 * PHP Version 8.2+
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Runner;

use Horde\Components\Component;
use Horde\Components\Helper\Commit as HelperCommit;
use Horde\Components\Output;

/**
 * Components_Runner_Change:: adds a new change log entry.
 *
 * Copyright 2011-2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Change
{
    /**
     * Constructor.
     *
     * @param Component $component The component
     * @param array $arguments CLI arguments
     * @param array $options CLI options
     * @param Output $output The output handler
     */
    public function __construct(
        private readonly Component $component,
        private readonly array $arguments,
        private readonly array $options,
        private readonly Output $output
    ) {}

    public function run(): void
    {
        if (count($this->arguments) > 1 && $this->arguments[0] == 'changed') {
            $log = $this->arguments[1];
        } else {
            $log = null;
        }

        $options = $this->options;
        if ($log && !empty($options['commit'])) {
            $options['commit'] = new HelperCommit(
                $this->output,
                $options
            );
        }
        $output = $this->component->changed($log, $options);
        if ($log && !empty($options['commit'])) {
            $options['commit']->commit($log);
        }
        foreach ($output as $message) {
            $this->output->plain($message);
        }
    }
}
