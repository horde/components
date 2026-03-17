<?php

namespace Horde\Components\Composer;

use stdClass;
use InvalidArgumentException;

/**
 * Create RepositoryDefinition implementations from stdClasses
 */
class RepositoryDefinitionFactory
{
    public static function create(stdClass $input): RepositoryDefinition
    {
        if ($input->type == 'path') {
            return new PathRepositoryDefinition($input->url, $input->options ?? new stdClass());
        }
        throw new InvalidArgumentException('Unsupported repository type: ' . ($input->type ?? 'unknown'));
    }
}
