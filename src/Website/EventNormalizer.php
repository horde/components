<?php

/**
 * Normalizes raw webhook events to Event objects
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
 * Normalizes raw webhook events to Event objects
 */
class EventNormalizer
{
    /**
     * Normalize a raw event to an Event object
     */
    public function normalize(array $rawEvent): ?Event
    {
        $type = $rawEvent['metadata']['type'];
        $json = $rawEvent['json'];
        $timestamp = $rawEvent['timestamp'];

        return match ($type) {
            'issues' => $this->normalizeIssue($json, $rawEvent['metadata'], $timestamp),
            'release' => $this->normalizeRelease($json, $rawEvent['metadata'], $timestamp),
            'push' => $this->normalizePush($json, $rawEvent['metadata'], $timestamp),
            'issue_comment' => $this->normalizeComment($json, $rawEvent['metadata'], $timestamp),
            'create' => $this->normalizeCreate($json, $rawEvent['metadata'], $timestamp),
            default => null, // Skip ping, label, unknown, etc.
        };
    }

    private function normalizeIssue(array $json, array $meta, DateTime $timestamp): Event
    {
        $issue = $json['issue'] ?? [];
        $repo = $json['repository']['full_name'] ?? ($meta['org'] . '/' . $meta['repo']);

        return new Event(
            type: 'issue',
            action: $meta['action'],
            repo: $repo,
            title: "Issue #{$issue['number']}: {$issue['title']}",
            url: $issue['html_url'] ?? '#',
            timestamp: $timestamp,
            actor: $issue['user']['login'] ?? 'unknown',
            summary: null
        );
    }

    private function normalizeRelease(array $json, array $meta, DateTime $timestamp): Event
    {
        $release = $json['release'] ?? [];
        $repo = $json['repository']['full_name'] ?? ($meta['org'] . '/' . $meta['repo']);

        $summary = null;
        if ($release['prerelease'] ?? false) {
            $summary = 'Pre-release';
        } elseif ($release['draft'] ?? false) {
            $summary = 'Draft';
        }

        return new Event(
            type: 'release',
            action: $meta['action'],
            repo: $repo,
            title: "{$release['name']} ({$release['tag_name']})",
            url: $release['html_url'] ?? '#',
            timestamp: $timestamp,
            actor: $release['author']['login'] ?? 'unknown',
            summary: $summary
        );
    }

    private function normalizePush(array $json, array $meta, DateTime $timestamp): Event
    {
        $repo = $json['repository']['full_name'] ?? ($meta['org'] . '/' . $meta['repo']);
        $branch = str_replace('refs/heads/', '', $json['ref'] ?? 'unknown');
        $commits = $json['commits'] ?? [];
        $commitCount = count($commits);

        $title = match (true) {
            $json['deleted'] ?? false => "Branch {$branch} deleted",
            $json['created'] ?? false => "Branch {$branch} created",
            $commitCount > 0 => "{$commitCount} commit" . ($commitCount > 1 ? 's' : '') . " to {$branch}",
            default => "Push to {$branch}",
        };

        return new Event(
            type: 'push',
            action: 'push',
            repo: $repo,
            title: $title,
            url: $json['compare'] ?? '#',
            timestamp: $timestamp,
            actor: $json['pusher']['name'] ?? 'unknown',
            summary: null
        );
    }

    private function normalizeComment(array $json, array $meta, DateTime $timestamp): Event
    {
        $issue = $json['issue'] ?? [];
        $comment = $json['comment'] ?? [];
        $repo = $json['repository']['full_name'] ?? ($meta['org'] . '/' . $meta['repo']);

        return new Event(
            type: 'comment',
            action: $meta['action'],
            repo: $repo,
            title: "Comment on issue #{$issue['number']}: {$issue['title']}",
            url: $comment['html_url'] ?? $issue['html_url'] ?? '#',
            timestamp: $timestamp,
            actor: $comment['user']['login'] ?? 'unknown',
            summary: null
        );
    }

    private function normalizeCreate(array $json, array $meta, DateTime $timestamp): Event
    {
        $repo = $json['repository']['full_name'] ?? ($meta['org'] . '/' . $meta['repo']);
        $refType = $json['ref_type'] ?? 'ref';
        $ref = $json['ref'] ?? 'unknown';

        return new Event(
            type: 'create',
            action: 'created',
            repo: $repo,
            title: ucfirst($refType) . " {$ref} created",
            url: $json['repository']['html_url'] ?? '#',
            timestamp: $timestamp,
            actor: $json['sender']['login'] ?? 'unknown',
            summary: null
        );
    }
}
