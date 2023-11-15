<?php

declare(strict_types=1);

namespace Horde\Components\Application;

use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use OutOfBoundsException;
use Traversable;

/**
 * Simple OO wrapper for env
 */
class ShellEnvironment implements IteratorAggregate, Countable
{
    private array $shellEnv;
    /**
     * Constructor
     *
     * Explicitly no magic default here
     */
    public function __construct(iterable $env)
    {
        $this->shellEnv = [];
        // Sanity check environment: Keys must be strings, values must be strings. No exceptions.
        foreach ($env as $varName => $varValue) {
            if (!is_string($varName) || !is_string($varValue)) {
                throw new InvalidArgumentException('Shell Environment must only have string keys and values');
            }
            $this->shellEnv[$varName] = $varValue;
        }
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->shellEnv);
    }

    public function get(string $key): string
    {
        return $this->shellEnv[$key] ?? throw new OutOfBoundsException('Shell variable not set: ' . $key);
    }

    public function getOrDefault(string $key, string $default): string
    {
        return $this->shellEnv[$key] ?? $default;
    }

    public function count(): int
    {
        return count($this->shellEnv);
    }

    public function getIterator(): Traversable
    {
        foreach ($this->shellEnv as $key => $value) {
            yield $value;
        }
    }
    /**
     * Trivial factory
     */
    public static function fromGetEnv(): ShellEnvironment
    {
        return new self(getenv());
    }
}
