<?php

declare(strict_types=1);

namespace Horde\Components\RuntimeContext;

use GlobIterator;
use Stringable;
use RuntimeException;
use Horde\Components\Component\ComponentDirectory;

/**
 * Represents the supposed root directory of a flat git tree checkout
 */
class GitCheckoutDirectory implements Stringable
{
    public function __construct(private string|Stringable $path) {}

    public function exists()
    {
        return is_readable((string) $this->path) && is_dir((string) $this->path);
    }

    public function __toString()
    {
        return (string) $this->path;
    }

    public function getGitDir(string $component): ComponentDirectory
    {
        foreach ($this->getGitDirs() as $gitDir) {
            if (str_ends_with(mb_strtolower((string) $gitDir), $component)) {
                return $gitDir;
            }
        }
        throw new RuntimeException('Could not find git directory for component: ' . $component);
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
