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
        return 'This module initializes .horde.yml, doc/changelog.yml and package.xml (and doc/CHANGES for apps).';
    }

    public function getOptionGroupOptions(): array
    {
        return [new \Horde\Argv\Option(
            '',
            '--author',
            ['action' => 'store', 'help'   => 'First author\'s name']
        ), new \Horde\Argv\Option(
            '',
            '--email',
            ['action' => 'store', 'help'   => 'Author\'s email']
        ), new \Horde\Argv\Option(
            '',
            '--license',
            ['action' => 'store', 'help'   => 'License']
        )];
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
        return 'Initialize metadata and dirs';
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
        return 'This module creates doc/changelog.yml, package.xml, and
doc/CHANGES. It will also create the .horde.yml metadata.

Move into the directory of the component you wish to record a change for
and run

  horde-components init application --author "Some Guy" --email "foo@bar.com"
or
  horde-components init library --author "Some Guy" --email "foo@bar.com"';
    }

    /**
     * Return the options that should be explained in the context help.
     *
     * @return array A list of option help texts.
     */
    public function getContextOptionHelp(): array
    {
        return ['--author' => 'The primary author\'s name', '--email' => 'Your Email Address'];
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
            switch ($arguments[1] ?? null) {
                case 'application':
                case 'library':
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

                default:
                    return false;
            }
        }
        return false;
    }
}
