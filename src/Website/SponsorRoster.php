<?php

/**
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

declare(strict_types=1);

namespace Horde\Components\Website;

use RuntimeException;

/**
 * Loads the sponsor roster (content/sponsors.json) and picks one entry
 * for the current build.
 *
 * The footer's sponsor slot is meant to be "rotated on rebuilds" per the
 * project's request, rather than statically pinned to a single sponsor
 * forever - so with more than one roster entry, a random one is picked
 * each time the generator runs. This is intentionally simple (no
 * persisted rotation state): every regeneration is an independent draw.
 */
final class SponsorRoster
{
    /**
     * @return Sponsor[]
     */
    public function load(string $sponsorsFile): array
    {
        if (!file_exists($sponsorsFile)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($sponsorsFile), true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Invalid sponsors file (expected a JSON array): {$sponsorsFile}");
        }

        $sponsors = [];
        foreach ($decoded as $entry) {
            if (!isset($entry['name'], $entry['url'])) {
                throw new RuntimeException(
                    "Sponsor entry missing required 'name'/'url' keys: " . json_encode($entry)
                );
            }
            $sponsors[] = new Sponsor(
                name: (string) $entry['name'],
                url: (string) $entry['url'],
                logo: isset($entry['logo']) && $entry['logo'] !== null ? (string) $entry['logo'] : null,
                note: (string) ($entry['note'] ?? ''),
            );
        }

        return $sponsors;
    }

    /**
     * Load the roster and pick one entry at random. Returns null if the
     * roster file is missing or empty (caller should fall back to the
     * static placeholder already embedded in footer.html).
     */
    public function loadAndSelect(string $sponsorsFile): ?Sponsor
    {
        $sponsors = $this->load($sponsorsFile);
        if ($sponsors === []) {
            return null;
        }

        return $sponsors[array_rand($sponsors)];
    }
}
