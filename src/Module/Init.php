<?php

/**
 * Horde\Components\Module\Init:: initializes component metadata.
 *
 * PHP Version 8.2+
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Module;

use Horde\Components\Component;
use Horde\Components\ConfigProvider\ConfigProviderFactory;
use Horde\Components\Component\Factory as ComponentFactory;
use Horde\Components\Component\ComponentDirectory;
use Horde\Components\RuntimeContext\CurrentWorkingDirectory;
use Horde\Components\Runner\Init as InitRunner;
use Horde\Components\Output;
use Horde\Components\Exception;

/**
 * Horde\Components\Module\Init:: initializes component metadata.
 *
 * Copyright 2018-2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Init extends Base
{
    public function getOptionGroupTitle(): string
    {
        return 'Init';
    }

    public function getOptionGroupDescription(): string
    {
        return 'This module scaffolds new components from templates (application, library, or theme).';
    }

    public function getOptionGroupOptions(): array
    {
        return [
            new \Horde\Argv\Option(
                '',
                '--name',
                ['action' => 'store', 'help' => 'Component name']
            ),
            new \Horde\Argv\Option(
                '',
                '--author',
                ['action' => 'store', 'help' => 'Author\'s full name']
            ),
            new \Horde\Argv\Option(
                '',
                '--email',
                ['action' => 'store', 'help' => 'Author\'s email address']
            ),
            new \Horde\Argv\Option(
                '',
                '--description',
                ['action' => 'store', 'help' => 'Short description']
            ),
            new \Horde\Argv\Option(
                '',
                '--use-license',
                ['action' => 'store', 'help' => 'License identifier (default: LGPL-2.1)']
            ),
            new \Horde\Argv\Option(
                '',
                '--force-overwrite',
                ['action' => 'store_true', 'help' => 'Overwrite existing files']
            ),
        ];
    }

    /**
     * Get the usage title for this module.
     *
     * @return string The title.
     */
    public function getTitle(): string
    {
        return 'init';
    }

    /**
     * Get the usage description for this module.
     *
     * @return string The description.
     */
    public function getUsage(): string
    {
        return 'Scaffold new components from templates';
    }

    /**
     * Get a short one-line description for command listings.
     *
     * @return string The short description.
     */
    public function getShortDescription(): string
    {
        return 'Scaffold new components from templates';
    }

    /**
     * Return the action arguments supported by this module.
     *
     * @return array A list of supported action arguments.
     */
    public function getActions(): array
    {
        return ['init'];
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
        return 'This module scaffolds new Horde components from templates.

Run without arguments for interactive help:
  horde-components init

Create a new library:
  mkdir MyLibrary && cd MyLibrary
  horde-components init library --name="MyLibrary" --author="John Doe" --email="john@example.com"

Create a new application (from horde/skeleton):
  mkdir MyApp && cd MyApp
  horde-components init application --name="MyApp" --author="John Doe" --email="john@example.com"

Create a new theme:
  mkdir MyTheme && cd MyTheme
  horde-components init theme --name="MyTheme" --author="John Doe" --email="john@example.com"';
    }

    /**
     * Return the options that should be explained in the context help.
     *
     * @return array A list of option help texts.
     */
    public function getContextOptionHelp(): array
    {
        return [
            '--name' => 'The component name',
            '--author' => 'The primary author\'s name',
            '--email' => 'The author\'s email address',
            '--description' => 'Short component description',
            '--use-license' => 'License identifier (default: LGPL-2.1)',
            '--force-overwrite' => 'Overwrite existing files',
        ];
    }

    /**
     * Determine if this module should act. Run all required actions if it has
     * been instructed to do so.
     *
     * @param array $options CLI options
     * @param array $arguments CLI arguments
     * @param Component|null $component The selected component (if any)
     *
     * @return bool True if the module performed some action.
     */
    public function handle(array $options, array $arguments, ?Component $component = null): bool
    {
        if (!empty($arguments[0]) && $arguments[0] == 'init') {
            // Get ConfigProvider
            $effectiveConfig = $this->dependencies->get(ConfigProviderFactory::class)->createDefault();

            // Resolve component from current working directory
            $componentDirectory = new ComponentDirectory(new CurrentWorkingDirectory());
            $componentFactory = $this->dependencies->get(ComponentFactory::class);
            $component = $componentFactory->createSource($componentDirectory);

            // Get output
            $output = $this->dependencies->get(Output::class);

            // Instantiate and run InitRunner with explicit dependencies
            $runner = new InitRunner($effectiveConfig, $component, $arguments, $output);
            $runner->run();
            return true;
        }
        return false;
    }
}
