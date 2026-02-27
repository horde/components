<?php

/**
 * Components_Module_Change:: records a change log entry.
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
use Horde\Components\Dependencies;
use Horde\Components\Component\ComponentDirectory;
use Horde\Components\Dependencies\GitCheckoutDirectoryFactory;
use Horde\Components\RuntimeContext\CurrentWorkingDirectory;
use Horde\Components\Runner\Status as RunnerStatus;

/**
 * Components_Module_Change:: records a change log entry.
 *
 * Copyright 2011-2024 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Status extends Base
{
    public function getOptionGroupTitle(): string
    {
        return 'status';
    }

    public function getOptionGroupDescription(): string
    {
        return 'This module prints out status';
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
        return 'status';
    }

    /**
     * Get the usage description for this module.
     *
     * @return string The description.
     */
    public function getUsage(): string
    {
        return 'Show status';
    }

    /**
     * Return the action arguments supported by this module.
     *
     * @return array A list of supported action arguments.
     */
    public function getActions(): array
    {
        return ['status'];
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
        return "Display the current status of the horde-components development environment

USAGE:
    horde-components status

DESCRIPTION:
    The status command provides a comprehensive overview of your Horde development
    environment configuration and validates that all necessary directories and files
    are in place. It checks multiple aspects of your setup:

    1. Configuration File
       - Verifies the config file location
       - Checks if the file exists and is readable
       - Reports the effective config file path being used

    2. Git Tree (Source Checkout Directory)
       - Validates the git checkout directory exists
       - Counts how many Horde repositories are checked out
       - Counts how many Horde components (.horde.yml files) are present
       - Reports if the directory is empty or missing

    3. Installation Directory
       - Checks if the Horde installation directory exists
       - Verifies the presence of a root composer.json file
       - Confirms the directory structure is valid

OUTPUT LEVELS:
    [  INFO  ]    Informational message (shows paths being checked)
    [   OK   ]    Check passed successfully
    [  WARN  ]    Potential issue detected (may require action)

WHEN TO USE:
    - After initial setup to verify your environment is configured correctly
    - Before running other commands to ensure prerequisites are met
    - When troubleshooting issues to identify missing or misconfigured components
    - After updating your configuration to confirm changes took effect

EXAMPLES:
    # Check current environment status
    horde-components status

    # Example output when fully configured:
    horde-components status -- minding any CLI switches, current working directory and config file content
    [  INFO  ] Config file path: /home/user/.config/horde/components.php
    [   OK   ] Config file exists and is readable.
    [  INFO  ] Git Tree root path: /srv/git/horde
    [   OK   ] Git Tree dir exists and has 185 repos checked out (172 components)
    [  INFO  ] Install Base path: /srv/www/horde-dev
    [   OK   ] Install dir exists.
    [   OK   ] Root composer.json file exists.

CONFIGURATION DEPENDENCIES:
    The status command respects these configuration settings:

    checkout.dir      The git checkout directory to check
                      (default: ~/git/horde or /srv/git/horde)

    install.dir       The Horde installation directory to check
                      (default: derived from runtime context)

COMMON WARNINGS AND FIXES:
    \"Config file does not exist or is not readable\"
      Fix: Run 'horde-components config init' to create a default config file

    \"Git Tree dir exists but no components are checked out\"
      Fix: Run 'horde-components github-clone-org' to clone all Horde repositories

    \"Git Tree dir does not exist or is not readable\"
      Fix: Create the directory or update checkout.dir config setting
           mkdir -p ~/git/horde
           horde-components config checkout.dir ~/git/horde

    \"Install dir does not exist or is not readable\"
      Fix: Create a Horde installation using composer
           composer create-project horde/bundle /srv/www/horde-dev

NOTES:
    - The status command is read-only and makes no changes to your system
    - All paths shown respect CLI switches and configuration file settings
    - Component counting includes only directories with .horde.yml files
    - Repository counting includes all directories with .git subdirectories
    - Color coding helps quickly identify issues (green=OK, yellow=WARN, blue=INFO)

SEE ALSO:
    - config init              Create initial configuration file
    - github-clone-org         Clone all Horde repositories
    - help                     Show available commands
";
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

        if (!empty($options['status'])
            || (isset($arguments[0]) && $arguments[0] == 'status')) {
            $componentDirectory = new ComponentDirectory($options['working_dir'] ?? new CurrentWorkingDirectory());
            $component = $this->dependencies
            ->getComponentFactory()
            ->createSource($componentDirectory);
            $config->setComponent($component);
            $this->dependencies->get(RunnerStatus::class)->run($config);
            return true;
        }
        return false;
    }
}
