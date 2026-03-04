<?php

/**
 * Components_Module_Base:: provides core functionality for the
 * different modules.
 *
 * PHP version 8.2+
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Module;

use Horde\Components\Dependencies;
use Horde\Injector\Injector;
use Horde\Components\Module;
use Horde_Cli_Modular_ModuleUsage;

/**
 * Components_Module_Base:: provides core functionality for the
 * different modules.
 *
 * Copyright 2010-2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
abstract class Base implements Module, Horde_Cli_Modular_ModuleUsage
{
    /**
     * Constructor.
     *
     * TODO: Refactor: We want to have individual constructor signatures injecting what is needed (or proxies thereof) rather than injector propagation hell.
     *
     * @param Injector $dependencies The dependency provider.
     */
    public function __construct(protected Injector $dependencies) {}

    /**
     * Get the usage title for this module.
     *
     * @return string The title.
     */
    public function getTitle(): string
    {
        return '';
    }

    /**
     * Get the usage description for this module.
     *
     * @return string The description.
     */
    public function getUsage(): string
    {
        return '';
    }

    /**
     * Get a short one-line description for command listings.
     *
     * This is used in the main help output to keep it concise.
     * Override this in subclasses to provide a brief description.
     *
     * @return string The short description.
     */
    public function getShortDescription(): string
    {
        // Default: return the title (subclasses should override)
        return $this->getTitle() . ' command';
    }

    /**
     * Get a set of base options that this module adds to the CLI argument
     * parser.
     *
     * @return array The options.
     */
    public function getBaseOptions(): array
    {
        return [];
    }

    /**
     * Indicate if the module provides an option group.
     *
     * @return bool True if an option group should be added.
     */
    public function hasOptionGroup(): bool
    {
        return true;
    }

    /**
     * Return the action arguments supported by this module.
     *
     * @return array A list of supported action arguments.
     */
    public function getActions(): array
    {
        return [];
    }

    /**
     * Return the options that should be explained in the context help.
     *
     * @return array A list of option help texts.
     */
    public function getContextOptionHelp(): array
    {
        return [];
    }

    /**
     * Return the help text for the specified action.
     *
     * @param string $action The action.
     *
     * @return string The help text.
     */
    public function getHelp($action): string
    {
        return '';
    }
}
