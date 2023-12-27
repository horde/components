<?php
declare(strict_types=1);
namespace Horde\Components\Changelog;

class Changelog
{
    public function __construct(
        private VersionLogEntries $versionLogEntries = new VersionLogEntries(),
        private Unreleased $unreleased = new Unreleased()
        )
    {

    }

    public function addVersionLogEntry($versionLogEntry)
    {
        ## TODO: Filter for existing version logs and replace them
        $this->versionLogEntries->addVersionLogEntry($versionLogEntry);
    }
}