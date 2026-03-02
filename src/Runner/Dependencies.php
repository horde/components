<?php

/**
 * Components_Runner_Dependencies:: lists a tree of dependencies.
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
use Horde\Components\Helper\Dependencies as HelperDependencies;

/**
 * Horde\Components\Runner\Dependencies:: lists a tree of dependencies.
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
class Dependencies
{
    /**
     * Constructor.
     *
     * @param Component $component The component
     * @param array $options CLI options
     * @param HelperDependencies $dependenciesHelper The list helper
     */
    public function __construct(
        private readonly Component $component,
        private readonly array $options,
        private readonly HelperDependencies $dependenciesHelper
    ) {}

    public function run(): void
    {
        if (!empty($this->options['no_tree'])) {
            print \Horde_Yaml::dump(
                $this->component->getDependencies()
            );
        } else {
            $this->dependenciesHelper->listTree(
                $this->component,
                $this->options
            );
        }
    }
}
