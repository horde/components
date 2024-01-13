<?php
declare(strict_types=1);

namespace Horde\Components\RuntimeContext;
use GlobIterator;
use Stringable;
/**
 * Represents the supposed root directory of a flat git tree checkout
 */
class GitCheckoutDirectory implements Stringable
{
    public function __construct(private string|Stringable $path)
    {
    }

    public function exists()
    {
        return is_readable((string) $this->path);
    }

    public function __toString()
    {
        return (string) $this->path;
    }

    public function getGitDirs(): GitDirectoryIterator
    {
        return $componentsCount = new GitDirectoryIterator($this->path . '/*/.git');
    }
    public function getHordeYmlDirs(): GitDirectoryIterator
    {
        return $componentsCount = new GitDirectoryIterator($this->path . '/*/.horde.yml');
    }

    public function getComposerJsonPath(): string
    {
        return (string) $this->path . '/composer.json';
    }
}