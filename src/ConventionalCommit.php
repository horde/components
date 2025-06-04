<?php

namespace Horde\Components;

class ConventionalCommit extends GitCommit
{
    public readonly bool   $breaking;
    public readonly string $severity;
    public readonly string $description;
    public readonly string $scope;
    public readonly string $type;
    public readonly string $stability;

    public function __construct(
        string $commit = '',
        string $abbreviated_commit = '',
        string $tree = '',
        string $abbreviated_tree = '',
        string $parent_commit = '',
        string $abbreviated_parent_commit = '',
        string|iterable $refs = [],
        string $encoding = '',
        string $subject = '',
        string $sanitized_subject = '',
        string $body = '',
        string $raw_body = '',
        string $commit_notes = '',
        string $verification_flag = '',
        string $signer = '',
        string $signer_key = '',
        string $author_name = '',
        string $author_email = '',
        string $author_date = '',
        string $committer_name = '',
        string $committer_email = '',
        string $committer_date = '',
        string $trailers = '',
        array $conventionalAttributes = [],
        string $stability = 'unchanged'
    ) {
        parent::__construct(
            commit: $commit,
            abbreviated_commit: $abbreviated_commit,
            tree: $tree,
            abbreviated_tree: $abbreviated_tree,
            parent_commit: $parent_commit,
            abbreviated_parent_commit: $abbreviated_parent_commit,
            refs: $refs,
            encoding: $encoding,
            subject: $subject,
            sanitized_subject: $sanitized_subject,
            body: $body,
            raw_body: $raw_body,
            commit_notes: $commit_notes,
            verification_flag: $verification_flag,
            signer: $signer,
            signer_key: $signer_key,
            author_name: $author_name,
            author_email: $author_email,
            author_date: $author_date,
            committer_name: $committer_name,
            committer_email: $committer_email,
        );
        $breaking = false;
        $lookupSeverity = [
            'build' => 'patch',
            'chore' => 'patch',
            'ci' => 'patch',
            'docs' => 'subpatch',
            'feat' => 'minor',
            'fix' => 'patch',
            'perf' => 'patch',
            'refactor' => 'patch',
            'revert' => 'patch',
            'style' => 'subpatch',
            'test' => 'subpatch',
            'major' => 'major',
            'breaking' => 'major',
        ];
        $severity = $lookupSeverity[$conventionalAttributes['type']];
        // If it's a breaking change, set severity to major
        if ($conventionalAttributes['breaking'] === '!') {
            $severity = 'major';
        }
        // If the severity is major, a breaking change is implied
        if ($severity === 'major') {
            $breaking = true;
        }
        // Prefer stability from explicit parameter unless it's "unchanged" and conventionalAttributes disagrees.
        if (array_key_exists('stability', $conventionalAttributes) && $stability === 'unchanged') {
            $stability = $conventionalAttributes['stability'];
        }
        $this->stability = $stability;
        $this->breaking = $breaking;
        $this->severity = $severity;
        $this->scope = rtrim(ltrim((string) ($conventionalAttributes['scope'] ?? ''), "("), ")");
        // TODO: Handle unknown types
        $this->type = (string) $conventionalAttributes['type'] ?? '';
        $this->description = (string) $conventionalAttributes['description'] ?? '';
    }

    public static function fromGitCommit(GitCommit $commit): ConventionalCommit|null
    {
        $regex =  '/^(?P<type>build|chore|ci|docs|feat|fix|perf|refactor|revert|style|test){1}(?P<scope>\([\w\-\.]+\))?(?P<breaking>!)?: (?P<description>.*)\s*/u';
        $res = preg_match($regex, $commit->subject, $matches);
        if ($res == 0) {
            return null;
        }
        // Handle BREAKING CHANGE: Description (from ConventionalCommit) and INCOMPATBLE: Description (from AutoSemVer)
        $breakingFooterRegex = '/^\s*(?P<breaking>BREAKING\s+CHANGE|INCOMPATIBLE):\s+(?P<breaking_description>.+)/u';
        $bodyLines = explode("\n", $commit->body);
        foreach ($bodyLines as $line) {
            $res = preg_match($breakingFooterRegex, $line, $breakingMatches);
            if (!empty($breakingMatches['breaking'])) {
                $matches['breaking'] = '!';
                $matches['breaking_description'] = $breakingMatches['breaking_description'];
                break;
            }
        }
        // Handle AutoSemver inspired stability footer
        $stabilityFooterRegex = '/^\s*(?P<stability>STABILITY|STABILITY\s+CHANGE):\s+(?P<new_stability>.+)/u';
        $bodyLines = explode("\n", $commit->body);
        foreach ($bodyLines as $line) {
            $res = preg_match($stabilityFooterRegex, $line, $stabilityMatches);
            if (!empty($stabilityMatches['new_stability'])) {
                $matches['stability'] = $stabilityMatches['new_stability'];
                break;
            }
        }

        return new ConventionalCommit(
            conventionalAttributes: $matches,
            commit: $commit->commit,
            abbreviated_commit: $commit->abbreviated_commit,
            tree: $commit->tree,
            abbreviated_tree: $commit->abbreviated_tree,
            parent_commit: $commit->parent_commit,
            abbreviated_parent_commit: $commit->abbreviated_parent_commit,
            refs: $commit->refs,
            encoding: $commit->encoding,
            subject: $commit->subject,
            sanitized_subject: $commit->sanitized_subject,
            body: $commit->body,
            raw_body: $commit->raw_body,
            commit_notes: $commit->commit_notes,
            verification_flag: $commit->verification_flag,
            signer: $commit->signer,
            signer_key: $commit->signer_key,
            author_name: $commit->author_name,
            author_email: $commit->author_email,
            author_date: $commit->author_date,
            committer_name: $commit->committer_name,
            committer_email: $commit->committer_email,
        );
    }
}
