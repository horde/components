<?php

namespace Horde\Components;

class GitCommit
{
    public readonly string $commit;
    public readonly string $abbreviated_commit;
    public readonly string $tree;
    public readonly string $abbreviated_tree;
    public readonly string $parent_commit;
    public readonly string $abbreviated_parent_commit;
    public readonly iterable $refs;
    public readonly string $encoding;
    public readonly string $subject;
    public readonly string $sanitized_subject;
    public readonly string $body;
    public readonly string $raw_body;
    public readonly string $commit_notes;
    public readonly string $verification_flag;
    public readonly string $signer;
    public readonly string $signer_key;
    public readonly string $author_name;
    public readonly string $author_email;
    public readonly string $author_date;
    public readonly string $committer_name;
    public readonly string $committer_email;
    public readonly string $committer_date;
    public readonly string $trailers;

    public function hasTags(): bool
    {
        foreach ($this->refs as $ref) {
            if ($ref->isTag()) {
                return true;
            }
        }
        return false;
    }

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
        string $trailers = ''
    ) {
        $this->commit = $commit;
        // TODO: Derive from commit if empty
        $this->abbreviated_commit = $abbreviated_commit;
        $this->tree = $tree;
        // TODO: Derive from commit if empty
        $this->abbreviated_tree = $abbreviated_tree;
        $this->parent_commit = $parent_commit;
        // TODO: Derive from commit if empty
        $this->abbreviated_parent_commit = $abbreviated_parent_commit;
        if (is_string($refs)) {
            $a_refs = explode(', ', $refs);
        } else {
            $a_refs = $refs;
        }
        // TODO Use a container type for refs
        $o_refs = [];
        foreach ($a_refs as $ref) {
            if (is_string($ref)) {
                $o_refs[] = new GitCommitRef($ref);
            } else {
                // TODO: Tertium?
                $o_refs[] = $ref;
            }
        }
        $this->refs = $o_refs;
        $this->encoding = $encoding;
        $this->subject = $subject;
        $this->sanitized_subject = $sanitized_subject;
        $this->body = $body;
        $this->raw_body = $raw_body;
        $this->commit_notes = $commit_notes;
        $this->verification_flag = $verification_flag;
        $this->signer = $signer;
        $this->signer_key = $signer_key;
        // Rethink: Should author be a structure rather than a bag of attributes?
        $this->author_name = $author_name;
        $this->author_email = $author_email;
        $this->author_date = $author_date;
        // Rethink: Should committer be a structure rather than a bag of attributes?
        $this->committer_name = $committer_name;
        $this->committer_email = $committer_email;
        $this->committer_date = $committer_date;
        $this->trailers = $trailers;
    }
    public function isTagged(): bool
    {
        foreach ($this->refs as $ref) {
            if ($ref->isTag()) {
                return true;
            }
        }
        return false;
    }
    // This will only work for the first tag found in the refs array.
    public function getTagName(): string
    {
        foreach ($this->refs as $ref) {
            if ($ref->isTag()) {
                return $ref->getTagName();
            }
        }
        return '';
    }
}
