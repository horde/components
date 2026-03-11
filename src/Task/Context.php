<?php

/**
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Task;

use Horde\Components\Component;

/**
 * Execution context passed through task pipeline.
 *
 * Provides access to the component being operated on, user options,
 * and a fact store for inter-task communication.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Context
{
    /**
     * Facts emitted by tasks for use by later tasks.
     *
     * @var array<string,mixed>
     */
    private array $facts = [];

    /**
     * Constructor.
     *
     * @param Component $component Component being operated on
     * @param array<string,mixed> $options User options/settings
     */
    public function __construct(
        public readonly Component $component,
        public readonly array $options = []
    ) {}

    /**
     * Store a fact for later tasks.
     *
     * Facts are transient values computed during pipeline execution
     * (e.g., calculated version, commit SHA, etc.) that later tasks
     * may depend on.
     *
     * @param string $key Fact name
     * @param mixed $value Fact value
     */
    public function setFact(string $key, mixed $value): void
    {
        $this->facts[$key] = $value;
    }

    /**
     * Retrieve a fact from earlier tasks.
     *
     * @param string $key Fact name
     * @param mixed $default Default value if fact not set
     * @return mixed Fact value or default
     */
    public function getFact(string $key, mixed $default = null): mixed
    {
        return $this->facts[$key] ?? $default;
    }

    /**
     * Check if a fact exists.
     *
     * @param string $key Fact name
     * @return bool True if fact exists
     */
    public function hasFact(string $key): bool
    {
        return array_key_exists($key, $this->facts);
    }

    /**
     * Get all facts.
     *
     * @return array<string,mixed> All facts
     */
    public function getFacts(): array
    {
        return $this->facts;
    }

    /**
     * Get component directory path.
     *
     * Convenience method for tasks.
     *
     * @return string Component directory path
     */
    public function getComponentPath(): string
    {
        return (string) $this->component->getComponentDirectory();
    }

    /**
     * Get an option value.
     *
     * Checks facts first (for runtime-set options), then options array.
     *
     * @param string $key Option name
     * @param mixed $default Default value if option not set
     * @return mixed Option value or default
     */
    public function getOption(string $key, mixed $default = null): mixed
    {
        $factKey = "_option.{$key}";
        if ($this->hasFact($factKey)) {
            return $this->getFact($factKey);
        }
        return $this->options[$key] ?? $default;
    }

    /**
     * Set an option value at runtime.
     *
     * Stores in facts with _option prefix to avoid collision.
     *
     * @param string $key Option name
     * @param mixed $value Option value
     */
    public function setOption(string $key, mixed $value): void
    {
        $this->setFact("_option.{$key}", $value);
    }
}
