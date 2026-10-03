<?php

/**
 * Value object for a single blog-roll entry (a normalized RSS/Atom item)
 *
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

declare(strict_types=1);

namespace Horde\Components\Website;

use DateTimeImmutable;

/**
 * A single normalized blog-roll entry, merged from one of the configured
 * feed sources (the official dev.horde.org Jonah "Horde News" channel,
 * community blogs, etc.) for the www.horde.org homepage's "From the
 * Blog" widget (IA §7 / Phase 6).
 */
class BlogPost
{
    public function __construct(
        public string $title,
        public string $url,
        public DateTimeImmutable $date,
        public string $excerpt,
        public string $sourceLabel, // e.g. "Horde News", "Community: ralf-lang.de"
    ) {}
}
