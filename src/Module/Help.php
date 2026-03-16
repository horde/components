<?php

/**
 * Components_Module_Help:: provides information for a single action.
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

use Horde\Argv\IndentedHelpFormatter;
use Horde\Components\Component;
use Horde\Components\Components;
use Horde\Cli\Modular\ModularCli;
use Horde\Components\Cli\ArgvParserBuilder;
use Horde\Util\HordeString;

/**
 * Components_Module_Help:: provides information for a single action.
 *
 * Copyright 2011-2026 Horde LLC (http://www.horde.org/)
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
     * Get a short one-line description for command listings.
     *
     * @return string The short description.
     */
    public function getShortDescription(): string
    {
        return 'Show help for commands';
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
     * @param array $options CLI options
     * @param array $arguments CLI arguments
     * @param Component|null $component The selected component (if any)
     *
     * @return bool True if the module performed some action.
     */
    public function handle(array $options, array $arguments, ?Component $component = null): bool
    {
        if (isset($arguments[0]) && $arguments[0] == 'help') {
            if (isset($arguments[1])) {
                return $this->handleWithAction($arguments[1]);
            }
            return $this->handleWithoutAction();
        }
        return false;
    }

    public function handleWithAction(string $action)
    {
        $formatter = new IndentedHelpFormatter();
        $modular = $this->dependencies->get(ModularCli::class);
        $module = null;
        $help = '';
        foreach ($modular->getModules() as $module) {
            $element = $module;
            if (in_array($action, $element->getActions())) {
                $title = "ACTION \"" . $action . "\"";
                $sub = str_repeat('-', mb_strlen($title));
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
        // Show command list when "horde-components help" is invoked
        $this->showCommandList();
        return true;
    }

    /**
     * Show brief help (when horde-components is run with no arguments).
     *
     * This is shown via Components.php, not directly by this module.
     */
    public function showBriefHelp(): void
    {
        $green = "\033[32m";
        $reset = "\033[0m";

        echo "Horde Components - Development tool for Horde Framework\n\n";
        echo "USAGE:\n";
        echo "    horde-components <command> [options] [arguments]\n";
        echo "    horde-components {$green}help{$reset} <command>\n\n";

        echo "SINGLE COMPONENT COMMANDS:\n";
        echo "    {$green}release{$reset} <h6|h5>           Release a component\n";
        echo "    {$green}ci{$reset} <subcommand>           Manage continuous integration\n";
        echo "    {$green}qc{$reset}                        Run quality checks\n";
        echo "    {$green}version{$reset}                   Show or update component version\n";
        echo "    {$green}changed{$reset}                   Generate changelog entries\n";
        echo "    {$green}pr{$reset}|{$green}pullrequest{$reset}            Create or manage pull requests\n\n";

        echo "MULTI-REPOSITORY COMMANDS:\n";
        echo "    {$green}github-clone-org{$reset}          Clone all repositories from GitHub organization\n";
        echo "    {$green}git sync-all{$reset}              Fetch, rebase, and analyze all local repositories\n\n";

        echo "SETUP & CONFIGURATION:\n";
        echo "    {$green}config{$reset}                    Configure horde-components\n";
        echo "    {$green}status{$reset}                    Show component and environment status\n";
        echo "    {$green}init{$reset}                      Initialize a new component\n";
        echo "    {$green}install{$reset}                   Install component dependencies\n";
        echo "    {$green}database{$reset}|{$green}db{$reset}              Set up test database\n\n";

        echo "OTHER COMMANDS:\n";
        echo "    {$green}composer{$reset}                  Manage composer.json\n";
        echo "    {$green}git{$reset}                       Git operations (clone, fetch, branch, tag, push)\n";
        echo "    {$green}package{$reset}                   Build packages\n";
        echo "    {$green}web{$reset}                       Start development web server\n\n";

        echo "HELP:\n";
        echo "    {$green}horde-components help{$reset}              List all commands with descriptions\n";
        echo "    {$green}horde-components help <command>{$reset}    Show detailed help for a command\n\n";

        echo "GETTING STARTED:\n";
        echo "    {$green}horde-components status{$reset}            Check your environment\n";
        echo "    {$green}horde-components config{$reset}            Configure the tool\n";
        echo "    {$green}horde-components github-clone-org{$reset}  Clone all Horde repositories\n";
    }

    /**
     * Show command list with short descriptions (horde-components help).
     */
    public function showCommandList(): void
    {
        echo "Horde Components - Available Commands\n\n";

        $categories = [
            'RELEASE & VERSIONING' => ['release', 'version', 'changed'],
            'CI & TESTING' => ['ci', 'database', 'qc'],
            'MULTI-REPOSITORY OPERATIONS' => ['github-clone-org', 'git'],
            'GITHUB INTEGRATION' => ['pullrequest'],
            'PROJECT SETUP' => ['init', 'install', 'config', 'status'],
            'BUILD & PACKAGING' => ['composer', 'package'],
            'UTILITIES' => ['web'],
        ];

        // Build module lookup by action
        $modular = $this->dependencies->get(ModularCli::class);
        $modulesByAction = [];
        foreach ($modular->getModules() as $module) {
            foreach ($module->getActions() as $action) {
                if ($action !== 'help') {  // Skip self-reference
                    $modulesByAction[$action] = $module;
                }
            }
        }

        // Display by category
        foreach ($categories as $categoryName => $actions) {
            echo "$categoryName:\n";
            foreach ($actions as $action) {
                if (isset($modulesByAction[$action])) {
                    $module = $modulesByAction[$action];
                    $description = $module->getShortDescription();

                    // Provide custom descriptions for specific actions
                    if ($action === 'github-clone-org') {
                        $description = 'Clone all repositories from GitHub organization';
                    } elseif ($action === 'git' && $categoryName === 'MULTI-REPOSITORY OPERATIONS') {
                        $description = 'Git operations for multiple repositories';
                    }

                    printf("    %-20s %s\n", $action, $description);
                }
            }
            echo "\n";
        }

        echo "MULTI-REPOSITORY EXAMPLES:\n";
        echo "    horde-components github-clone-org            Clone all Horde repos\n";
        echo "    horde-components github-clone-org --detect-differences\n";
        echo "                                                 Check for missing repos\n";
        echo "    horde-components git sync-all                Sync all repositories\n";
        echo "    horde-components git sync-all --pretend      Preview sync operations\n";
        echo "    horde-components git sync-all --pattern=\"Cli*\"\n";
        echo "                                                 Sync specific repos\n\n";

        echo "Use 'horde-components help <command>' for detailed help on a specific command.\n\n";
        echo "EXAMPLES:\n";
        echo "    horde-components help release     See release command help\n";
        echo "    horde-components help git         See git command help\n";
        echo "    horde-components ci help          Alternative syntax\n";
    }
}
