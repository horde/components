<?php
/**
 * Components_Module_Fetchdocs:: fetches remote documentation files.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.fsf.org/copyleft/lgpl.html LGPL
 */

namespace Horde\Components\Module;

use Horde\Components\Config;

/**
 * Components_Module_Fetchdocs:: fetches remote documentation files.
 *
 * Copyright 2010-2020 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/lgpl.html.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.fsf.org/copyleft/lgpl.html LGPL
 */
class Fetchdocs extends Base
{
    /**
     * Return the title for the option group representing this module.
     *
     * @return string The group title.
     */
    public function getOptionGroupTitle(): string
    {
        return 'Fetch Documentation';
    }

    /**
     * Return the description for the option group representing this module.
     *
     * @return string The group description.
     */
    public function getOptionGroupDescription(): string
    {
        return 'This module fetches remote documentation files.';
    }

    /**
     * Return the options for this module.
     *
     * @return array The group options.
     */
    public function getOptionGroupOptions(): array
    {
        return [new \Horde_Argv_Option(
            '-F',
            '--fetchdocs',
            ['action' => 'store_true', 'help'   => 'Fetches documentation files from remote locations. The files to fetch and their target location will be determined by a DOCS_ORIGIN file in the "doc" or "docs" folder of the selected component.']
        )];
    }

    /**
     * Get the usage title for this module.
     *
     * @return string The title.
     */
    public function getTitle(): string
    {
        return 'fetchdocs';
    }

    /**
     * Get the usage description for this module.
     *
     * @return string The description.
     */
    public function getUsage(): string
    {
        return 'Fetch remote documentation.';
    }

    /**
     * Return the action arguments supported by this module.
     *
     * @return array A list of supported action arguments.
     */
    public function getActions(): array
    {
        return ['fetchdocs'];
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
        return 'This module fetches documentation files from remote locations. The files to fetch and their target location will be determined by a DOCS_ORIGIN file in the "doc" or "docs" folder of the selected component.';
    }

    /**
     * Return the options that should be explained in the context help.
     *
     * @return array A list of option help texts.
     */
    public function getContextOptionHelp(): array
    {
        return ['--pretend' => ''];
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
        if (!empty($options['fetchdocs'])
            || (isset($arguments[0]) && $arguments[0] == 'fetchdocs')) {
            $this->_dependencies->getRunnerFetchdocs()->run();
            return true;
        }
        return false;
    }
}
