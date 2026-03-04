<?php

declare(strict_types=1);

namespace Horde\Components\RuntimeContext;

use GlobIterator;
use Stringable;
use RuntimeException;
use Horde\Components\Component\ComponentDirectory;

/**
 * Represents the supposed root directory of a git checkout tree
 *
 * The checkout directory contains vendor subdirectories (e.g., horde/)
 * with components nested inside: checkout.dir/vendor/component/
 * Example: /home/user/git/horde/ActiveSync
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
        // Component can be "horde/bundle" or just "bundle"
        // If no vendor prefix, assume "horde" for backwards compatibility
        if (!str_contains($component, '/')) {
            $component = 'horde/' . $component;
        }

        foreach ($this->getGitDirs() as $gitDir) {
            // Match against full path ending with vendor/component
            if (str_ends_with(mb_strtolower((string) $gitDir), mb_strtolower($component))) {
                return $gitDir;
            }
        }
        throw new RuntimeException('Could not find git directory for component: ' . $component);
    }

    public function getGitDirs(): GitDirectoryIterator
    {
        return $componentsCount = new GitDirectoryIterator($this->path . '/*/*/.git');
    }
    public function getHordeYmlDirs(): GitDirectoryIterator
    {
        return $componentsCount = new GitDirectoryIterator($this->path . '/*/*/.horde.yml');
    }

    public function getComposerJsonPath(): string
    {
        return (string) $this->path . '/composer.json';
    }
}
