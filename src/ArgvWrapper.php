<?php
declare(strict_types=1);
namespace Horde\Components;

use InvalidArgumentException;
use IteratorAggregate;
use RuntimeException;
use Traversable;

/**
 * Wrap a copy of Argv into a simple, typed object for DI
 */

class ArgvWrapper implements IteratorAggregate
{
    private readonly array $argv;

    public function __construct(array $argv)
    {
        $argvCopy = [];
        // TODO: Check if the array is actually argv-like
        foreach ($argv as $pos => $argument) {
            if (!is_string($argument)) {
                throw new InvalidArgumentException('All members of argv must be strings.');
            }

            $argvCopy[] = $argument;
        }
        $this->argv = $argvCopy;
    }

    public function getIterator(): Traversable
    {
        return new \ArrayIterator($this->argv);
    }

    public static function fromGlobal()
    {
        if (empty($GLOBALS['argv']))
        {
            // Argv always contains at least the binary's name so this indicates a severe error
            throw new RuntimeException("Argv Global is not available or in invalid state");
        }
        return new ArgvWrapper($GLOBALS['argv']);
    }
}