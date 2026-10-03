<?php

/**
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

declare(strict_types=1);

namespace Horde\Components\Website;

/**
 * A single entry in the www.horde.org footer sponsor roster
 * (content/sponsors.json).
 */
final class Sponsor
{
    public function __construct(
        public readonly string $name,
        public readonly string $url,
        public readonly ?string $logo,
        public readonly string $note,
    ) {
    }
}
