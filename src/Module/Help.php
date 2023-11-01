<?php
/**
 * Components_Module_Help:: provides information for a single action.
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
use Horde\Cli\Modular\ModularCli;

/**
 * Components_Module_Help:: provides information for a single action.
 *
 * Copyright 2011-2020 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Help extends Base
{
    /**
     * Indicate if the module provides an option group.
     *
     * @return bool True if an option group should be added.
     */
    public function hasOptionGroup(): bool
    {
        return false;
    }

    public function getOptionGroupTitle(): string
    {
        return '';
    }

    public function getOptionGroupDescription(): string
    {
        return '';
    }

    public function getOptionGroupOptions(): array
    {
        return [];
    }

    /**
     * Get the usage title for this module.
     *
     * @return string The title.
     */
    public function getTitle(): string
    {
        return 'help ACTION';
    }

    /**
     * Get the usage description for this module.
     *
     * @return string The description.
     */
    public function getUsage(): string
    {
        return 'Provide information about the specified ACTION.';
    }

    /**
     * Return the action arguments supported by this module.
     *
     * @return array A list of supported action arguments.
     */
    public function getActions(): array
    {
        return ['help'];
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
        $arguments = $config->getArguments();
        if (isset($arguments[0]) && $arguments[0] == 'help') {
            if (isset($arguments[1])) {
                $action = $arguments[1];
            } else {
                $action = '';
            }
            $formatter = new \Horde_Argv_IndentedHelpFormatter();
            $modules = $this->_dependencies->get(ModularCli::class)->getModules();
            foreach ($modules as $module) {
                $element = $module;
                if (in_array($action, $element->getActions())) {
                    $title = "ACTION \"" . $action . "\"";
                    $sub = str_repeat('-', strlen($title));
                    $help = "\n"
                        . $formatter->highlightHeading($title . "\n" . $sub)
                        . "\n\n";
                    $help .= \Horde_String::wordwrap(
                        $element->getHelp($action),
                        75,
                        "\n",
                        true
                    );
                    $options = $element->getContextOptionHelp();
                    if (!empty($options)) {
                        $parser = $this->_dependencies->getParser();
                        $title = "OPTIONS for \"" . $action . "\"";
                        $sub = str_repeat('-', strlen($title));
                        $help .= "\n\n\n"
                            . $formatter->highlightHeading($title . "\n" . $sub);
                        foreach ($options as $option => $help_text) {
                            $argv_option = $parser->getOption($option);
                            $help .= "\n\n    " . $formatter->highlightOption($formatter->formatOptionStrings($argv_option)) . "\n\n      ";
                            if (empty($help_text)) {
                                $help .= \Horde_String::wordwrap(
                                    $argv_option->help,
                                    75,
                                    "\n      ",
                                    true
                                );
                            } else {
                                $help .= \Horde_String::wordwrap(
                                    $help_text,
                                    75,
                                    "\n      ",
                                    true
                                );
                            }
                        }
                    }
                    $help .= "\n";
                    $this->_dependencies->getOutput()->help(
                        $help
                    );
                    return true;
                }
            }
            return false;
        }
        return false;
    }
}
