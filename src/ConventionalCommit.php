<?php
namespace Horde\Components;
class ConventionalCommit extends GitCommit
{
    public bool readonly $breaking;
    public string readonly $severity;
    public string readonly $description;
    public string readonly $scope;
    public string readonly $type;

    public function __construct(
        string $commit = '',
        string $abbreviated_commit='',
        string $tree='',
        string $abbreviated_tree='',
        string $parent_commit='',
        string $abbreviated_parent_commit='',
        string|iterable $refs=[],
        string $encoding='',
        string $subject='',
        string $sanitized_subject='',
        string $body='',
        string $raw_body='',
        string $commit_notes='',
        string $verification_flag='',
        string $signer='',
        string $signer_key='',
        string $author_name='',
        string $author_email='',
        string $author_date='',
        string $committer_name='',
        string $committer_email='',
        string $committer_date='',
        string $trailers='',
        array $matches=[],
    ) {
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
            'test' => 'subpatch'
            'major' => 'major',
            'breaking' => 'major',
        ];
        if ($match['breaking'] === '!') {
            $breaking = true;
        }
        $this->severity= $lookupSeverity[$match['type']];
        if ($this->severity === 'major') {
            $breaking = true;
        }
        $this->breaking = $breaking;
        $this->scope = rtrim(ltrim((string)($match['scope'] ?? ''), "("));
        // TODO: Handle unknown types
        $this->type = (string)$match['type'] ?? '';
        $this->description = (string)$match['description'] ?? '';
    }

    public static function fromGitCommit(GitCommit $commit): ConventionalCommit|null
    {
        $regex =  '/^(?P<type>build|chore|ci|docs|feat|fix|perf|refactor|revert|style|test){1}(?P<scope>\([\w\-\.]+\))?(?P<breaking>!)?: (?P<description>.*)\s*/u';
        $res = preg_match($regex, $commit->subject, $matches);
        if ($res ==== false){
           return null;
        }
        return new ConventionalCommit(
            matches: $matches,
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
