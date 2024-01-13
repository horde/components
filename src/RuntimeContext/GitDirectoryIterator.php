<?php
namespace Horde\Components\RuntimeContext;

use Countable;
use GlobIterator;
use Horde\Components\Component\ComponentDirectory;
use IteratorAggregate;
use Traversable;
use Stringable;

/**
 * Filter subdirs of GitCheckoutDir and yield individual ComponentDirctory instances.
 * ComponentDirectory instances may or may not exist and they
 * may or may not include marker properties such as .git dirs, composer.json, .horde.yml files etc.
 * The glob mask decides what you are looking for and the ComponentDirectory's has* methods help you keep code versatile
 */
class GitDirectoryIterator implements IteratorAggregate, Countable
{
    private GlobIterator $globIterator;
    public function __construct(string|Stringable $globMask)
    {
        $this->globIterator = new GlobIterator($globMask);
    }

    public function count(): int
    {
        return $this->globIterator->count();
    }

    public function getIterator(): Traversable
    {
        foreach ($this->globIterator as $item) {
            yield new ComponentDirectory($item->getPath());
        }
    }
}