<?php

/**
 * Renders BlogPost objects into the "From the Blog" widget markup
 *
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

declare(strict_types=1);

namespace Horde\Components\Website;

/**
 * Renders the "From the Blog" homepage widget (see
 * horde-web/content/pages/home/index.html's `data-widget="blog-roll"`
 * placeholder) from a merged, sorted list of BlogPost entries. Pure
 * rendering - fetching/merging is BlogFeedFetcher's job.
 */
class BlogRollRenderer
{
    public function render(array $posts): string
    {
        if (empty($posts)) {
            return '                <div class="blog-empty">No recent posts found.</div>' . "\n";
        }

        $html = '';
        foreach ($posts as $post) {
            $html .= $this->renderCard($post);
        }

        return $html;
    }

    private function renderCard(BlogPost $post): string
    {
        $titleEsc = $this->esc($post->title);
        $urlEsc = $this->esc($post->url);
        $excerptEsc = $this->esc($post->excerpt);
        $sourceEsc = $this->esc($post->sourceLabel);
        $dateEsc = $this->esc($post->date->format('Y-m-d'));

        return <<<HTML
                <article class="blog-card">
                    <span class="blog-date">{$dateEsc}</span>
                    <h3><a href="{$urlEsc}">{$titleEsc}</a></h3>
                    <p>{$excerptEsc}</p>
                    <span class="blog-source">{$sourceEsc}</span>
                </article>

            HTML;
    }

    private function esc(string $str): string
    {
        return htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
