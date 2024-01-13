<?php
namespace Horde\Components\Composer;

use stdClass;

interface RepositoryDefinition
{
    public function getType(): string;

    public function dumpStdClass();
}