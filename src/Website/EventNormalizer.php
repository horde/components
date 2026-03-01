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
            'delete' => $this->normalizeDelete($json, $rawEvent['metadata'], $timestamp),
            'pull_request' => $this->normalizePullRequest($json, $rawEvent['metadata'], $timestamp),
            'pull_request_review' => $this->normalizePullRequestReview($json, $rawEvent['metadata'], $timestamp),
            'pull_request_review_comment' => $this->normalizePullRequestReviewComment($json, $rawEvent['metadata'], $timestamp),
            'fork' => $this->normalizeFork($json, $rawEvent['metadata'], $timestamp),
            'repository' => $this->normalizeRepository($json, $rawEvent['metadata'], $timestamp),
            default => null, // Skip ping, label, star, watch, etc.
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

    private function normalizeDelete(array $json, array $meta, DateTime $timestamp): Event
    {
        $repo = $json['repository']['full_name'] ?? ($meta['org'] . '/' . $meta['repo']);
        $refType = $json['ref_type'] ?? 'ref';
        $ref = $json['ref'] ?? 'unknown';

        return new Event(
            type: 'delete',
            action: 'deleted',
            repo: $repo,
            title: ucfirst($refType) . " {$ref} deleted",
            url: $json['repository']['html_url'] ?? '#',
            timestamp: $timestamp,
            actor: $json['sender']['login'] ?? 'unknown',
            summary: null
        );
    }

    private function normalizePullRequest(array $json, array $meta, DateTime $timestamp): Event
    {
        $pr = $json['pull_request'] ?? [];
        $repo = $json['repository']['full_name'] ?? ($meta['org'] . '/' . $meta['repo']);

        $summary = null;
        if ($pr['draft'] ?? false) {
            $summary = 'Draft';
        }
        if ($pr['merged'] ?? false) {
            $summary = 'Merged';
        }

        return new Event(
            type: 'pull_request',
            action: $meta['action'],
            repo: $repo,
            title: "PR #{$pr['number']}: {$pr['title']}",
            url: $pr['html_url'] ?? '#',
            timestamp: $timestamp,
            actor: $pr['user']['login'] ?? 'unknown',
            summary: $summary
        );
    }

    private function normalizePullRequestReview(array $json, array $meta, DateTime $timestamp): Event
    {
        $pr = $json['pull_request'] ?? [];
        $review = $json['review'] ?? [];
        $repo = $json['repository']['full_name'] ?? ($meta['org'] . '/' . $meta['repo']);

        $state = $review['state'] ?? 'commented';
        $summary = match ($state) {
            'approved' => 'Approved',
            'changes_requested' => 'Changes requested',
            'commented' => 'Commented',
            default => ucfirst($state)
        };

        return new Event(
            type: 'pull_request_review',
            action: $meta['action'],
            repo: $repo,
            title: "Review on PR #{$pr['number']}: {$pr['title']}",
            url: $review['html_url'] ?? $pr['html_url'] ?? '#',
            timestamp: $timestamp,
            actor: $review['user']['login'] ?? 'unknown',
            summary: $summary
        );
    }

    private function normalizePullRequestReviewComment(array $json, array $meta, DateTime $timestamp): Event
    {
        $pr = $json['pull_request'] ?? [];
        $comment = $json['comment'] ?? [];
        $repo = $json['repository']['full_name'] ?? ($meta['org'] . '/' . $meta['repo']);

        return new Event(
            type: 'pull_request_review_comment',
            action: $meta['action'],
            repo: $repo,
            title: "Review comment on PR #{$pr['number']}: {$pr['title']}",
            url: $comment['html_url'] ?? $pr['html_url'] ?? '#',
            timestamp: $timestamp,
            actor: $comment['user']['login'] ?? 'unknown',
            summary: null
        );
    }

    private function normalizeFork(array $json, array $meta, DateTime $timestamp): Event
    {
        $repo = $json['repository']['full_name'] ?? ($meta['org'] . '/' . $meta['repo']);
        $forkee = $json['forkee'] ?? [];

        return new Event(
            type: 'fork',
            action: 'forked',
            repo: $repo,
            title: "Forked to {$forkee['full_name']}",
            url: $forkee['html_url'] ?? '#',
            timestamp: $timestamp,
            actor: $forkee['owner']['login'] ?? $json['sender']['login'] ?? 'unknown',
            summary: null
        );
    }

    private function normalizeRepository(array $json, array $meta, DateTime $timestamp): Event
    {
        $repo = $json['repository']['full_name'] ?? ($meta['org'] . '/' . $meta['repo']);
        $action = $meta['action'];

        $title = match ($action) {
            'created' => "Repository created",
            'deleted' => "Repository deleted",
            'archived' => "Repository archived",
            'unarchived' => "Repository unarchived",
            'publicized' => "Repository made public",
            'privatized' => "Repository made private",
            'renamed' => "Repository renamed",
            default => "Repository {$action}"
        };

        return new Event(
            type: 'repository',
            action: $action,
            repo: $repo,
            title: $title,
            url: $json['repository']['html_url'] ?? '#',
            timestamp: $timestamp,
            actor: $json['sender']['login'] ?? 'unknown',
            summary: null
        );
    }
}
