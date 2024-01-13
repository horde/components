<?php
declare(strict_types=1);
namespace Horde\Components\Composer;

use stdClass;
use Stringable;

class PathRepositoryDefinition implements RepositoryDefinition
{
    public readonly string $path;

    public function __construct(string|Stringable $path, public readonly stdClass $repositoryOptions = new stdClass)
    {
        // Cast to string once rather than everywhere.
        $this->path = (string) $path;
    }

    public function getType(): string
    {
        return 'path';
    }

    public function getUrl(): string
    {
        return $this->path;
    }

    public function dumpStdClass()
    {
        return (object) [
            'type' => 'path',
            'options' => $this->repositoryOptions,
            'url' => $this->path
        ];
    }
}