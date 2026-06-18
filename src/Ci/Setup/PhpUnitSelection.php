<?php

/**
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Ci\Setup;

/**
 * Outcome of {@see PhpUnitMatrix::pickWithSource()}.
 *
 * Either:
 * - `$tag` is a "major.minor" string and `$skipReason` is null (the lane
 *   should download/run that PHPUnit), OR
 * - `$tag` is null and `$skipReason` carries the human-readable reason for
 *   skipping the lane (no PHPUnit satisfies the intersection).
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
final class PhpUnitSelection
{
    public function __construct(
        public readonly ?string $tag,
        public readonly ?string $skipReason
    ) {}

    public function isSatisfied(): bool
    {
        return $this->tag !== null;
    }
}
