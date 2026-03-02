<?php

/**
 * Minimal Config implementation for Component class compatibility
 *
 * PHP version 8.2+
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Config;

use Horde\Components\Component;
use Horde\Components\Config;

/**
 * Minimal Config implementation for runtime use.
 *
 * Component classes still depend on Config but it's being phased out.
 * This provides the minimal implementation needed for Components to work.
 */
class MinimalConfig implements Config
{
    private array $options;
    private array $arguments;
    private ?Component $component = null;
    private ?string $path = null;

    public function __construct(array $options = [], array $arguments = [])
    {
        $this->options = $options;
        $this->arguments = $arguments;
    }

    public function setOption($key, $value): void
    {
        $this->options[$key] = $value;
    }

    public function getOption($option)
    {
        return $this->options[$option] ?? null;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function shiftArgument()
    {
        return array_shift($this->arguments);
    }

    public function unshiftArgument($element): void
    {
        array_unshift($this->arguments, $element);
    }

    public function getArguments(): array
    {
        return $this->arguments;
    }

    public function setComponent(Component $component): void
    {
        $this->component = $component;
    }

    public function getComponent(): ?Component
    {
        return $this->component;
    }

    public function setPath($path): void
    {
        $this->path = $path;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }
}
