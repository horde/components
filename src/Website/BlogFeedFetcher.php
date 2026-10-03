<?php

/**
 * Fetches and normalizes RSS feeds for the www.horde.org "From the Blog" widget
 *
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

declare(strict_types=1);

namespace Horde\Components\Website;

use DateTimeImmutable;
use RuntimeException;
use SimpleXMLElement;

/**
 * Fetches RSS 2.0 feeds and normalizes their items into BlogPost objects
 * for the homepage's "From the Blog" widget (build-time embed, Phase 6 -
 * no client-side fetch at request time).
 *
 * Known source feeds (configured by the caller, not hardcoded here):
 * - https://dev.horde.org/horde/jonah/delivery/rss.php?channel_id=1
 *   (official "Horde News" announcements channel - sparse/historical)
 * - https://www.ralf-lang.de/category/horde-it/feed/
 *   (community blog, WordPress RSS with HTML content:encoded bodies)
 *
 * dev.horde.org in particular is known to be slow/under heavy load, so
 * fetches default to a long timeout rather than failing fast.
 */
class BlogFeedFetcher
{
    public function __construct(
        private readonly int $timeoutSeconds = 60
    ) {
    }

    /**
     * Fetch and parse one RSS feed into an array of BlogPost objects.
     *
     * Never throws on network failure - a single slow/unreachable feed
     * (e.g. a temporarily overloaded dev.horde.org) must not break the
     * whole site build. Returns an empty array instead, and the caller
     * is expected to log/report via $error (passed by reference).
     */
    public function fetch(string $url, string $sourceLabel, ?string &$error = null): array
    {
        $error = null;

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeoutSeconds,
                'header' => "User-Agent: horde-components website generator\r\n",
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            $error = "Failed to fetch feed: {$url}";
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($raw);
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            $error = "Failed to parse feed XML: {$url}";
            return [];
        }

        try {
            return $this->extractItems($xml, $sourceLabel);
        } catch (RuntimeException $e) {
            $error = $e->getMessage() . " ({$url})";
            return [];
        }
    }

    /**
     * Fetch multiple feeds, merge all items, sort newest-first, and
     * return the top $limit posts. Per-feed errors are collected into
     * $errors (keyed by URL) rather than aborting the whole merge.
     *
     * @param array<int, array{url: string, label: string}> $sources
     * @param array<string, string> $errors Output: url => error message
     * @return BlogPost[]
     */
    public function fetchMerged(array $sources, int $limit, array &$errors = []): array
    {
        $errors = [];
        $all = [];

        foreach ($sources as $source) {
            $error = null;
            $posts = $this->fetch($source['url'], $source['label'], $error);
            if ($error !== null) {
                $errors[$source['url']] = $error;
            }
            array_push($all, ...$posts);
        }

        usort($all, static fn(BlogPost $a, BlogPost $b): int => $b->date <=> $a->date);

        return array_slice($all, 0, $limit);
    }

    /**
     * @return BlogPost[]
     */
    private function extractItems(SimpleXMLElement $xml, string $sourceLabel): array
    {
        $items = $xml->channel->item ?? null;
        if ($items === null) {
            throw new RuntimeException('Not an RSS 2.0 <channel><item> feed');
        }

        $posts = [];
        foreach ($items as $item) {
            $title = trim((string) $item->title);
            $link = trim((string) $item->link);
            $pubDate = trim((string) $item->pubDate);

            if ($title === '' || $link === '') {
                continue;
            }

            $date = $pubDate !== ''
                ? (DateTimeImmutable::createFromFormat(DATE_RSS, $pubDate) ?: null)
                : null;
            if ($date === null) {
                // Fall back to "now" rather than dropping the item outright -
                // an undated item still deserves to show up, just without
                // reliable sort placement.
                $date = new DateTimeImmutable();
            }

            $rawDescription = (string) $item->description;
            if ($rawDescription === '') {
                // WordPress-style feeds may only populate content:encoded.
                $encoded = $item->children('content', true)->encoded ?? null;
                $rawDescription = $encoded !== null ? (string) $encoded : '';
            }
            $excerpt = $this->toExcerpt($rawDescription);

            $posts[] = new BlogPost($title, $link, $date, $excerpt, $sourceLabel);
        }

        return $posts;
    }

    private function toExcerpt(string $html, int $maxLength = 160): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength) . '…';
    }
}
