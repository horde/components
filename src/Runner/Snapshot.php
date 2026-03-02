<?php

/**
 * Components_Runner_Snapshot:: packages a snapshot.
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
use Horde\Components\Output;

/**
 * Components_Runner_Snapshot:: packages a snapshot.
 *
 * Copyright 2010-2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Snapshot
{
    /**
     * Constructor.
     *
     * @param Component $component The component
     * @param array $options CLI options
     * @param Output $output The output handler
     */
    public function __construct(
        private readonly Component $component,
        private readonly array $options,
        private readonly Output $output
    ) {}

    public function run(): void
    {
        if (!empty($this->options['destination'])) {
            $archivedir = $this->options['destination'];
        } else {
            $archivedir = getcwd();
        }
        $options = $this->options;
        $options['logger'] = $this->output;
        $result = $this->component->placeArchive(
            $archivedir,
            $options
        );
        if (isset($result[2])) {
            $this->output->pear($result[2]);
        }
        if (!empty($result[1])) {
            $this->output->fail(
                'Generating snapshot failed with:' . "\n\n" . join("\n", $result[1])
            );
        } else {
            $this->output->ok('Generated snapshot ' . $result[0]);
        }
    }
}
