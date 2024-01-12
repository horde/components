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
        $this->repositories = new RepositoryList($this->content?->repositories);
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
        // $this->content->repositories =
        return (string) json_encode($this->content);
    }

    public function writeFile(string $path)
    {
        file_put_contents($path, $this->render());
    }
}