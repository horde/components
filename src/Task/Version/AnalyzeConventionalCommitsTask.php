<?php

/**
 * Copyright 2024-2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @author   Ralf Lang <lang@b1-systems.de>
 * @category Horde
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package  Components
 */

declare(strict_types=1);

namespace Horde\Components\Task\Version;

use Horde\Components\Helper\Git as GitHelper;
use Horde\Components\Output;
use Horde\Components\Task\AbstractTask;
use Horde\Components\Task\Context;
use Horde\Components\Task\Result;
use Exception;

/**
 * Analyze conventional commits to determine version bump severity.
 *
 * Parses git commits since the last tag to determine the highest severity
 * change (BREAKING, feat, fix) for semantic versioning.
 *
 * Required Facts: None
 *
 * Emitted Facts:
 * - version.top_severity (string) - Highest severity: 'major', 'minor', 'patch'
 * - commits.analyzed_count (int) - Number of commits analyzed
 * - commits.analyzed (bool) - True if analysis completed
 * - notes.formatted (string) - Formatted release notes from commits
 *
 * @author    Ralf Lang <lang@b1-systems.de>
 * @category  Horde
 * @copyright 2024-2026 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Components
 */
class AnalyzeConventionalCommitsTask extends AbstractTask
{
    public function __construct(
        Output $output,
        private readonly GitHelper $gitHelper,
        bool $pretend = false
    ) {
        parent::__construct($output, $pretend);
    }

    public function getName(): string
    {
        return 'Analyze Conventional Commits';
    }

    public function run(Context $context): Result
    {
        $componentPath = $context->getComponentPath();

        // Get commits since last tag
        $lastTag = $this->gitHelper->getLastTag($componentPath);
        $commitRange = $lastTag ? "{$lastTag}..HEAD" : 'HEAD';

        $result = $this->gitHelper->run(
            "log {$commitRange} --pretty=format:'%H|||%s|||%b'",
            $componentPath
        );

        if ($result->getReturnValue() !== 0) {
            throw new Exception('Failed to get git commits: ' . $result->getOutputString());
        }

        $commitLines = array_filter(explode("\n", trim($result->getOutputString())));

        if (empty($commitLines)) {
            throw new Exception(
                'No conventional commits found since last tag. Cannot determine version bump.'
            );
        }

        // Parse commits
        $commits = [];
        $topSeverity = 'patch';
        $formattedNotes = [];

        foreach ($commitLines as $line) {
            $parts = explode('|||', $line);
            if (count($parts) < 2) {
                continue;
            }

            $hash = $parts[0];
            $subject = $parts[1];
            $body = $parts[2] ?? '';

            // Parse conventional commit format
            $severity = $this->parseCommitSeverity($subject, $body);

            // Track highest severity
            if ($severity === 'major' || ($severity === 'minor' && $topSeverity === 'patch')) {
                $topSeverity = $severity;
            } elseif ($severity === 'minor') {
                $topSeverity = 'minor';
            }

            $commits[] = [
                'hash' => $hash,
                'subject' => $subject,
                'severity' => $severity,
            ];

            // Add to formatted notes
            $formattedNotes[] = $subject;
        }

        $notes = implode("\n", $formattedNotes);

        // Emit facts
        $context->setFact('version.top_severity', $topSeverity);
        $context->setFact('commits.analyzed_count', count($commits));
        $context->setFact('commits.analyzed', true);
        $context->setFact('notes.formatted', $notes);

        return Result::success(
            "Analyzed " . count($commits) . " commits: {$topSeverity} bump",
            [
                'count' => count($commits),
                'top_severity' => $topSeverity,
                'commits' => $commits,
            ]
        );
    }

    /**
     * Parse commit subject and body to determine severity.
     *
     * @param string $subject Commit subject line
     * @param string $body Commit body
     * @return string Severity: 'major', 'minor', or 'patch'
     */
    private function parseCommitSeverity(string $subject, string $body): string
    {
        // Check for BREAKING CHANGE in body
        if (str_contains($body, 'BREAKING CHANGE:') || str_contains($body, 'BREAKING-CHANGE:')) {
            return 'major';
        }

        // Check for breaking change indicator in subject (!)
        if (preg_match('/^[a-z]+(\([^)]+\))?!:/', $subject)) {
            return 'major';
        }

        // Check for feat:
        if (preg_match('/^feat(\([^)]+\))?:/', $subject)) {
            return 'minor';
        }

        // Everything else is patch (fix, chore, docs, etc.)
        return 'patch';
    }
}
