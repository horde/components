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
 * Renders a single Sponsor into the footer's `.sponsor-card` markup.
 */
final class SponsorCardRenderer
{
    public function render(Sponsor $sponsor): string
    {
        $name = $this->esc($sponsor->name);
        $url = $this->esc($sponsor->url);
        $note = $this->esc($sponsor->note);

        $logo = $sponsor->logo !== null
            ? '<img class="sponsor-logo" src="' . $this->esc($sponsor->logo) . '" alt="' . $name . ' logo">'
            : '<div class="sponsor-logo">' . $name . '</div>';

        return <<<HTML
            <div class="sponsor-card">
              {$logo}
              <a class="sponsor-link" href="{$url}" target="_blank" rel="noopener">{$name}</a>
              <p>{$note}</p>
            </div>
            HTML;
    }

    private function esc(string $str): string
    {
        return htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
