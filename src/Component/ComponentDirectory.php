<?php
namespace Horde\Components\Component;
use Horde\Components\Component;
use Horde\Components\Component\DependencyList;
use Horde\Components\Helper\Commit as HelperCommit;
use Horde\Components\Helper\Root as HelperRoot;
use Horde\Components\Pear\Environment as PearEnvironment;
use Horde\Components\RuntimeContext\CurrentWorkingDirectory;
use Stringable;

class ComponentDirectory implements Stringable
{
    private string $fullPath;

    public function __construct(CurrentWorkingDirectory|string $dir)
    {
        $this->fullPath = (string) $dir;
    }

    public function hasHordeYml(): bool
    {
        return is_file($this->fullPath . '/.horde.yml');
    }

    public function hasPackageXml(): bool
    {
        return is_file($this->fullPath . '/package.xml');
    }

    public function hasComposerJson(): bool
    {
        return is_file($this->fullPath . '/composer.json');
    }

    public function __toString()
    {
        return $this->fullPath;
    }
}