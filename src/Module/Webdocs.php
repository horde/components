<?php
/**
 * Components_Module_Webdocs:: generates the www.horde.org data for a component.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Module;

use Horde\Components\Config;

/**
 * Webdocs:: generates the www.horde.org data for a component.
 *
 * Copyright 2010-2020 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Webdocs extends Base
{
    /**
     * Return the title for the option group representing this module.
     *
     * @return string The group title.
     */
    public function getOptionGroupTitle(): string
    {
        return 'Generate website documentation';
    }

    /**
     * Return the description for the option group representing this module.
     *
     * @return string The group description.
     */
    public function getOptionGroupDescription(): string
    {
        return 'This module generates the www.horde.org data for the component.';
    }

    /**
     * Return the options for this module.
     *
     * @return array The group options.
     */
    public function getOptionGroupOptions(): array
    {
        return [new \Horde_Argv_Option(
            '-W',
            '--webdocs',
            ['action' => 'store_true', 'help'   => 'Generate the documentation for the component in the specified DESTINATION or WEBSOURCE location.']
        ), new \Horde_Argv_Option(
            '--html-generator',
            ['action' => 'store', 'help'   => 'Path to the Python docutils HTML generator script.']
        )];
    }

    /**
     * Get the usage title for this module.
     *
     * @return string The title.
     */
    public function getTitle(): string
    {
        return 'webdocs';
    }

    /**
     * Get the usage description for this module.
     *
     * @return string The description.
     */
    public function getUsage(): string
    {
        return 'Generate documentation for www.horde.org.';
    }

    /**
     * Return the action arguments supported by this module.
     *
     * @return array A list of supported action arguments.
     */
    public function getActions(): array
    {
        return ['webdocs'];
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
        $formatter = new \Horde_Argv_IndentedHelpFormatter();
        return 'This module generates the required set of data to publish information about this component on www.horde.org. The operation will only work with an already relased package! Make sure you enter the name of the package on the PEAR server rather than using a local path and ensure you added the "' . $formatter->highlightOption('--allow-remote') . '" flag as well.';
    }

    /**
     * Return the options that should be explained in the context help.
     *
     * @return array A list of option help texts.
     */
    public function getContextOptionHelp(): array
    {
        return ['--destination' => 'The documentation for the component will be written to the location specified as DESTINATION. The module will assume DESTINATION is a checkout of the "horde-web" git repository.', '--html-generator' => '', '--pretend' => '', '--allow-remote' => ''];
    }

    /**
     * Determine if this module should act. Run all required actions if it has
     * been instructed to do so.
     *
     * @param Config $config The configuration.
     *
     * @return boolean True if the module performed some action.
     */
    public function handle(Config $config)
    {
        $options = $config->getOptions();
        $arguments = $config->getArguments();
        if (!empty($options['webdocs'])
            || (isset($arguments[0]) && $arguments[0] == 'webdocs')) {
            $this->_dependencies->getRunnerWebdocs()->run();
            return true;
        }
    }
}
