<?php

/**
 * Minimal Config interface for Component class compatibility
 *
 * PHP version 8.2+
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components;

/**
 * Minimal Config interface.
 *
 * Component classes still depend on Config interface.
 * This provides the minimal interface definition.
 */
interface Config
{
    public function setOption($key, $value): void;
    public function getOption($option);
    public function getOptions(): array;
    public function shiftArgument();
    public function unshiftArgument($element): void;
    public function getArguments(): array;
    public function setComponent(Component $component): void;
    public function getComponent(): ?Component;
    public function setPath($path): void;
    public function getPath(): ?string;
}
