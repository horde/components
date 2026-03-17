<?php

/**
 * Branch alias configuration check result.
 *
 * PHP Version 8.2+
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Report;

/**
 * Branch alias configuration check result.
 *
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class BranchAliasCheck
{
    /**
     * Constructor.
     *
     * @param bool $configured Whether branch-alias is configured
     * @param string|null $value The branch-alias value (e.g., "3.x-dev")
     */
    public function __construct(
        public readonly bool $configured,
        public readonly ?string $value
    ) {}
}
