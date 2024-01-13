<?php
namespace Horde\Components\Composer;

use stdClass;
/**
 * Create RepositoryDefinition implementations from stdClasses
 */
class RepositoryDefinitionFactory
{
    public static function create(stdClass $input): RepositoryDefinition
    {
        if ($input->type == 'path') {
            return new PathRepositoryDefinition($input->url, $input->options ?? new stdClass);
        }
    }
}