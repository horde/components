<?php

/**
 * Normalized event representation
 *
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

declare(strict_types=1);

namespace Horde\Components\Website;

use DateTime;

/**
 * Normalized event representation
 */
class Event
{
    public function __construct(
        public string $type,           // "issue", "release", "push", "comment"
        public string $action,         // "opened", "published", "closed"
        public string $repo,           // "horde/components"
        public string $title,          // Human-readable title
        public string $url,            // GitHub URL
        public DateTime $timestamp,    // When it happened
        public string $actor,          // Username who triggered it
        public ?string $summary = null // Brief description
    ) {}
}
