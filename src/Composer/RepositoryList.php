<?php
declare(strict_types=1);
namespace Horde\Components\Composer;

use Horde\Components\Module\Release;
use IteratorAggregate;
use stdClass;
use Traversable;

class RepositoryList implements IteratorAggregate
{
    private array $repositories;
    public function __construct(RepositoryDefinition ...$repositories)
    {
        $this->repositories = $repositories;
    }

    public function getIterator(): Traversable
    {
        yield from $this->repositories;
    }

    public function ensurePresent(RepositoryDefinition $repository)
    {

    }

    public function ensureAbsent(RepositoryDefinition $repository)
    {

    }

    public static function fromStdClasses(stdClass ...$repositories): RepositoryList
    {
        $promoted = [];
        foreach ($repositories as $repository) {
            $promoted[] = new RepositoryDefinition($repository);
        }
        return new RepositoryList(...$promoted);
    }

}