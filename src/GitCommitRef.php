<?php

namespace Horde\Components;

use Stringable;

class GitCommitRef implements Stringable
{
    public function __construct(
        public readonly string|Stringable $refString = '',
    ) {}
    public function __toString(): string
    {
        return $this->refString;
    }
    public function isTag(): bool
    {
        return str_starts_with($this->refString, 'tag: refs/tags/');
    }
    public function getTagName(): string
    {
        if ($this->isTag()) {
            return substr($this->refString, strlen('tag: refs/tags/'));
        }
        // TODO this might warrant an exception. Users can call isTag manually to prevent this
        return '';
    }
    public function isLocalBranchHead(): bool
    {
        return str_starts_with($this->refString, 'refs/heads/') or str_starts_with($this->refString, 'HEAD -> refs/heads/');
    }
}
