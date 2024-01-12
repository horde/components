<?php
declare(strict_types=1);

namespace Horde\Components\Composer;

use Stringable;
/**
 * Represents the supposed root directory of a composer based horde installation
 */
class InstallationDirectory implements Stringable
{
    public function __construct(private string|Stringable $installDir)
    {
    }

    public function exists()
    {
        return is_readable((string) $this->installDir);
    }

    public function __toString()
    {
        return (string) $this->installDir;
    }

    public function hasComposerJson(): bool
    {
        return is_readable($this->getComposerJsonPath());
    }

    public function getComposerJson(): RootComposerJsonFile
    {
        return RootComposerJsonFile::loadFile($this->getComposerJsonPath());
    }

    public function getComposerJsonPath(): string
    {
        return (string) $this->installDir . '/composer.json';
    }
}