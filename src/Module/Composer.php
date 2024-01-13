<?php
/**
 * Copyright 2013-2024 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category  Horde
 * @copyright 2013-2024 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Components
 */

namespace Horde\Components\Module;

use Horde\Argv\Option;
use Horde\Components\Component\ComponentDirectory;
use Horde\Components\Component\Source as SourceComponent;
use Horde\Components\Config;
use Horde\Components\Runner\Composer as RunnerComposer;
use Horde\Components\RuntimeContext\CurrentWorkingDirectory;

/**
 * Creates a config file for use with PHP Composer.
 *
 * @author    Michael Slusarz <slusarz@horde.org>
 * @category  Horde
 * @copyright 2013-2024 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Components
 */
class Composer extends Base
{
    /**
     */
    public function getOptionGroupTitle(): string
    {
        return 'PHP Composer configuration';
    }

    /**
     */
    public function getOptionGroupDescription(): string
    {
        return 'This module creates a config file for use with PHP Composer.';
    }

    /**
     */
    public function getOptionGroupOptions(): array
    {
        return [
            new Option(
                '--composer-version',
                [
                    'action' => 'store',
                    'help' => 'A fixed version or branch expression to append after the version from yaml'
                ],
            ),
            new Option(
                '--minimum-stability',
                [
                    'action' => 'store',
                    'dest' => 'minimum-stability',
                    'help' => 'A minimum stability statement (dev, alpha, beta, rc, stable)'
                ]
            )
        ];
    }

    /**
     * Get the usage title for this module.
     *
     * @return string The title.
     */
    public function getTitle(): string
    {
        return 'composer';
    }

    /**
     * Get the usage description for this module.
     *
     * @return string The description.
     */
    public function getUsage(): string
    {
        return 'Create config file for PHP Composer.';
    }

    /**
     * Return the action arguments supported by this module.
     *
     * @return array A list of supported action arguments.
     */
    public function getActions(): array
    {
        return ['composer'];
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
        return 'Creates a composer.json config file to be used with PHP Composer. Usage:

  horde-components composer';
    }

    /**
     * Determine if this module should act. Run all required actions if it has
     * been instructed to do so.
     *
     * @param Config $config The configuration.
     *
     * @return bool True if the module performed some action.
     */
    public function handle(Config $config): bool
    {
        $options = $config->getOptions();
        $arguments = $config->getArguments();
        $componentDirectory = new ComponentDirectory($options['working_dir'] ?? new CurrentWorkingDirectory);
        $component = $this->dependencies
        ->getComponentFactory()
        ->createSource($componentDirectory);
        $config->setComponent($component);
        if (!empty($options['composer'])
            || (isset($arguments[0]) && $arguments[0] == 'composer')) {
            $this->dependencies->get(RunnerComposer::class)->run($config);
            return true;
        }
        return false;
    }
}
