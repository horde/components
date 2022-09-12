<?php
/**
 * Task\InputInterface - Necessary state for running a CLI task.
 *
 * PHP Version 8
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Task;

use Horde\Components\Config;
use ValueError;

/**
 * Task\InputInterface - Necessary state for running a CLI task.
 *
 * Copyright 2011-2022 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * Inspired by PSR-7 ServerRequestInterface
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Input implements InputInterface
{
    public function __construct(
        private Config $config,
        private bool $pretend = false,
        private bool $interactive = true,
        private array $env = [],
        private array $attributes = []
    ) {
        foreach ($attributes as $key => $value) {
            if (!is_string($key) || strlen($key) < 1) {
                throw new ValueError('Attribute keys must be non-null strings');
            }
        }
    }
    /**
     * Should the task be dry-run?
     *
     * @return bool
     */
    public function pretend(): bool
    {
        return $this->pretend;
    }

    /**
     * Can Tasks request further input?
     *
     * @return bool
     */
    public function isInteractive(): bool
    {
        return $this->interactive;
    }

    /**
     * Supposedly read-only access to the application configuration
     *
     * Application config may be derived from config files, builtin defaults,
     * commandline or circumstances like the current directory.
     *
     * Any task or handler specific overrides should go to "attributes".
     * These attributes can be handed over to individual tasks and enriched
     * in pre, run and post phases.
     *
     * It is valid for a task to rely on previous runs' output and refuse to run otherwise.
     * Tasks may be highly application or handler specific or well-reusable with little dependency.
     *
     * @return Config
     */
    public function getApplicationConfig(): Config
    {
        return $this->config;
    }

    /**
     * Supposedly readonly access to environment variables
     *
     * @return array
     */
    public function getEnvironment(): array
    {
        return $this->env;
    }

    /**
     * Retrieve attributes derived from the request.
     *
     * The request "attributes" may be used to allow injection of any
     * parameters derived from the request: e.g., the results of path
     * match operations; the results of decrypting cookies; the results of
     * deserializing non-form-encoded message bodies; etc. Attributes
     * will be application and request specific, and CAN be mutable.
     *
     * @return mixed[] Attributes derived from the request.
     */
    public function getAttributes(): array
    {
        return array_keys($this->attributes);
    }

    /**
     * Retrieve a single derived request attribute.
     *
     * Retrieves a single derived request attribute as described in
     * getAttributes(). If the attribute has not been previously set, returns
     * the default value as provided.
     *
     * This method obviates the need for a hasAttribute() method, as it allows
     * specifying a default value to return if the attribute is not found.
     *
     * @see getAttributes()
     * @param string $name The attribute name.
     * @param mixed $default Default value to return if the attribute does not exist.
     * @return mixed
     */
    public function getAttribute(string $name, $default = null)
    {
        return $this->attributes[$name] ?? $default;
    }

    /**
     * Return an instance with the specified derived request attribute.
     *
     * This method allows setting a single derived request attribute as
     * described in getAttributes().
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return an instance that has the
     * updated attribute.
     *
     * @see getAttributes()
     * @param string $name The attribute name.
     * @param mixed $value The value of the attribute.
     * @return static
     */
    public function withAttribute(string $name, $value): Input
    {
        return new Input(
            $this->getApplicationConfig(),
            $this->pretend,
            $this->isInteractive(),
            $this->getEnvironment(),
            ($this->getAttributes())[$name] = $value
        );
    }

    /**
     * Return an instance that removes the specified derived request attribute.
     *
     * This method allows removing a single derived request attribute as
     * described in getAttributes().
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return an instance that removes
     * the attribute.
     *
     * @see getAttributes()
     * @param string $name The attribute name.
     * @return static
     */
    public function withoutAttribute(string $name): Input
    {
        $newAttributes = $this->getAttributes();
        unset($newAttributes[$name]);
        return new Input(
            $this->getApplicationConfig(),
            $this->pretend,
            $this->isInteractive(),
            $this->getEnvironment(),
            $newAttributes
        );
    }
}
