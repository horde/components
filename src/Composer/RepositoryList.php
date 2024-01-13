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
        // TODO: Ensure all members are RepositoryDefinition objects
        $this->repositories = $repositories;
    }

    public function getIterator(): Traversable
    {
        yield from $this->repositories;
    }

    /**
     * Add an entry to the repository list or update an existing entry
     */
    public function ensurePresent(RepositoryDefinition $repository)
    {
        foreach ($this->repositories as $position => $existingRepository) {
            if ($repository->getType() == $existingRepository->getType()) {
                // TODO: This only works for path and web repositories but not for some others
                if ($repository->getUrl() == $existingRepository->getUrl()) {
                    $this->repositories[$position] = $repository;
                    return;
                }
            }
        }
        $this->repositories[] = $repository;
    }

    public function ensureAbsent(RepositoryDefinition $repository)
    {

    }

    public static function fromStdClasses(stdClass ...$repositories): RepositoryList
    {
        $promoted = [];
        foreach ($repositories as $repository) {
            $promoted[] = RepositoryDefinitionFactory::create($repository);
        }
        return new RepositoryList(...$promoted);
    }

}