<?php
namespace Horde\Components\Changelog;

use Iterator;
use IteratorAggregate;
use Traversable;

class VersionLogEntries implements IteratorAggregate
{
    private array $versionLogEntries;
    public function __construct(VersionLogEntry ...$versionLogEntries)
    {
        foreach ($versionLogEntries as $logEntry) {
            $this->addVersionLogEntry($logEntry);
        }
    }

    public function addVersionLogEntry($versionLogEntry)
    {
        ## TODO: Filter for existing version logs and replace them
        $this->versionLogEntries[$versionLogEntry->packageVersion->getOriginal()] = $versionLogEntry;
    }

    public function getIterator(): Traversable
    {
        yield from $this->versionLogEntries;
    }
}