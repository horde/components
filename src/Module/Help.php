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

use Horde\Argv\IndentedHelpFormatter;
use Horde\Components\Config;
use Horde\Components\Components;
use Horde\Cli\Modular\ModularCli;
use Horde\Components\Cli\ArgvParserBuilder;
use Horde\Util\HordeString;

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
                return $this->handleWithAction($arguments[1]);
            }
            return $this->handleWithoutAction();
        }
        return false;
    }

    function handleWithAction(string $action)
    {
        $formatter = new IndentedHelpFormatter();
        $modular = $this->dependencies->get(ModularCli::class);
        foreach ($modular->getModules() as $module) {
            $element = $module;
            if (in_array($action, $element->getActions())) {
                $title = "ACTION \"" . $action . "\"";
                $sub = str_repeat('-', strlen($title));
                $help = "\n"
                    . $formatter->highlightHeading($title . "\n" . $sub)
                    . "\n\n";
                $help .= HordeString::wordwrap(
                    $element->getHelp($action),
                    75,
                    "\n",
                    true
                );
                break;
            }
        }
        $parser = (new ArgvParserBuilder())->withGlobalOptions()->withModuleOptions($module)->build();
        $output = $this->dependencies->getOutput();
        $output->help($help);
        foreach ($parser->optionGroups as $group) {
            foreach ($group->optionList as $option) {
                $output->help((string) $option);
                $output->help($parser->formatter->formatOption($option));
            }
        }
        return true;
    }

    public function handleWithoutAction(): bool
    {
        $modular = $this->dependencies->get(ModularCli::class);
        $modular->getParser()->printUsage();
        return true;
    }
}
