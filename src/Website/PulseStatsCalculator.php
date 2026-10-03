<?php

/**
 * Computes the www.horde.org homepage "live project pulse" widget stats
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
 * Computes the four rolling-window numbers shown by the "live project
 * pulse" widget on the www.horde.org homepage (see
 * horde-web/content/pages/home/index.html's
 * `data-widget="live-project-pulse"` placeholder), from the same
 * normalized Event[] that dev.horde.org's own dashboard uses (Phase 2
 * unified facts) - no separate live backend call, build-time only.
 */
class PulseStatsCalculator
{
    /**
     * @param Event[] $events Normalized webhook events (dev.horde.org's
     *   EventScanner + EventNormalizer output)
     * @param int $componentsTracked Count of components in the catalog
     * @param DateTimeImmutable|null $now Injectable for tests; defaults to now
     * @return array{components_tracked: int, releases_90d: int, commits_30d: int, open_prs: int}
     */
    public function calculate(array $events, int $componentsTracked, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $cutoff90 = $now->modify('-90 days');
        $cutoff30 = $now->modify('-30 days');

        $releases90d = 0;
        $commits30d = 0;

        // Pull requests: GitHub's webhook history only tells us about
        // state *transitions*, not "is this PR currently open" directly.
        // Approximate "open" by tracking the most recent action per PR
        // URL across all available history, and counting those whose
        // latest known action isn't a close/merge.
        $latestPrActionByUrl = [];

        foreach ($events as $event) {
            $timestamp = DateTimeImmutable::createFromInterface($event->timestamp);

            if ($event->type === 'release' && $timestamp >= $cutoff90) {
                $releases90d++;
            }

            if ($event->type === 'push' && $timestamp >= $cutoff30) {
                // One push event can carry multiple commits, but the
                // normalized Event is one-per-webhook-delivery; treat each
                // push event as one commit-activity unit (a reasonable
                // approximation without re-parsing the raw payload here).
                $commits30d++;
            }

            if ($event->type === 'pull_request') {
                $existing = $latestPrActionByUrl[$event->url] ?? null;
                if ($existing === null || $timestamp > $existing['timestamp']) {
                    $latestPrActionByUrl[$event->url] = [
                        'action' => $event->action,
                        'timestamp' => $timestamp,
                    ];
                }
            }
        }

        $closedActions = ['closed', 'merged'];
        $openPrs = 0;
        foreach ($latestPrActionByUrl as $pr) {
            if (!in_array($pr['action'], $closedActions, true)) {
                $openPrs++;
            }
        }

        return [
            'components_tracked' => $componentsTracked,
            'releases_90d' => $releases90d,
            'commits_30d' => $commits30d,
            'open_prs' => $openPrs,
        ];
    }
}
