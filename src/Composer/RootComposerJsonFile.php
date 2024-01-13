<?php
declare(strict_types=1);
namespace Horde\Components\Composer;

use RuntimeException;
use stdClass;
/**
 * Represents an installation's root composer.json file
 */
class RootComposerJsonFile
{
    private stdClass $content;
    private RepositoryList $repositories;

    public function __construct(stdClass|string $data)
    {
        if (is_string($data)) {
            $this->content = json_decode($data);
        } else {
            $this->content = $data;
        }
        $repositoriesStd = $this->content->repositories ?? [];
        $this->repositories = RepositoryList::fromStdClasses(...$repositoriesStd);
    }

    public function getRepositoryList(): RepositoryList
    {
        return $this->repositories;
    }

    public static function loadFile(string $path): self
    {
       $data = file_get_contents($path);
       if ($data === false) {
          throw new RuntimeException('Could not load root composer.json file');
       }
       return new self($data);
    }

    public function render(): string
    {
        $this->content->repositories = [];
        foreach ($this->repositories as  $id => $repository) {
            $this->content->repositories[$id] = $repository->dumpStdClass();
        }
        return (string) json_encode($this->content, JSON_PRETTY_PRINT);
    }

    public function writeFile(string $path)
    {
        file_put_contents($path, $this->render());
    }
}