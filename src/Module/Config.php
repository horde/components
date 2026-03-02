<?php

/**
 * Components\Module\Change:: Read and Manipulate the Config File
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

use Horde\Cli\Cli;
use Horde\Components\Component;
use Horde\Components\ConfigProvider\BuiltinConfigProvider;
use Horde\Components\ConfigProvider\PhpConfigFileProvider;

/**
 * Components\Module\Change:: Read and Manipulate the Config File
 *
 * Copyright 2023-2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Config extends Base
{
    public function getOptionGroupTitle(): string
    {
        return 'Configuration';
    }

    public function getOptionGroupDescription(): string
    {
        return 'This module reads and writes config files';
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
        return 'config';
    }

    /**
     * Get the usage description for this module.
     *
     * @return string The description.
     */
    public function getUsage(): string
    {
        return 'configure the tool';
    }

    /**
     * Return the action arguments supported by this module.
     *
     * @return array A list of supported action arguments.
     */
    public function getActions(): array
    {
        return ['config'];
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
        return "This module manages the horde/components tool's configuration file

USAGE:
    horde-components config [SUBCOMMAND|KEY [VALUE]]

SUBCOMMANDS:
    init              Initialize config file with built-in default values
                      Creates ~/.config/horde/components.php if it doesn't exist
                      Overwrites existing values with defaults if file exists

    KEY               Read and display the value of configuration key KEY
    
    KEY VALUE         Set configuration key KEY to VALUE
                      Creates the key if it doesn't exist
                      Updates the key if it already exists

CONFIGURATION FILE LOCATION:
    The config file is stored at:
        ~/.config/horde/components.php
    
    This is a PHP file containing a \$conf array with key-value pairs.
    The file is created automatically when you first write a value.

COMMON CONFIGURATION KEYS:
    checkout.dir      Directory where Horde repositories are checked out
                      Default: ~/git/horde or /srv/git/horde
                      Example: /home/user/projects/horde

    repo.org          GitHub organization or user name for repositories
                      Default: horde
                      Example: mycompany

    scm.domain        Base URL of the source control hosting service
                      Default: https://github.com
                      Example: https://gitlab.example.com

    scm.type          Type of source control system
                      Default: github
                      Example: gitlab

    github.token      GitHub personal access token for API operations
                      Required scopes: repo, read:org
                      Generate at: https://github.com/settings/tokens
                      Can also be set via GITHUB_TOKEN environment variable
                      Precedence: CLI args > GITHUB_TOKEN env > config file
                      Default: (empty - GitHub features disabled)

EXAMPLES:
    # Initialize config file with defaults
    horde-components config init

    # Read a single configuration value
    horde-components config checkout.dir
    horde-components config repo.org

    # Set a configuration value
    horde-components config checkout.dir /home/user/horde
    horde-components config repo.org mycompany
    horde-components config scm.domain https://github.example.com
    horde-components config github.token ghp_your_token_here

    # Update an existing value
    horde-components config repo.org updated-company

CONFIGURATION PRECEDENCE:
    Settings can come from multiple sources with this precedence order:

    1. CLI arguments (highest)  --github-token=ghp_xxx
    2. Environment variables    GITHUB_TOKEN=ghp_xxx
    3. User config file         ~/.config/horde/components.php
    4. Legacy config file       config/conf.php (if exists)
    5. Builtin defaults (lowest)

    Higher precedence sources override lower ones. Use 'null' in config
    files to explicitly unset values and prevent fallback.

NOTES:
    - Configuration values are stored as PHP strings in a \$conf array
    - Special characters in values (quotes, backslashes) are automatically escaped
    - URLs and paths are supported in values
    - The init command will overwrite existing values with built-in defaults
    - If a key doesn't exist when reading, an error will be displayed

SECURITY:
    - The config file should only be readable by your user
    - IMPORTANT: github.token grants access to your GitHub repositories
    - Never commit config files containing tokens to version control
    - Consider using GITHUB_TOKEN environment variable instead
    - For CI/CD, use encrypted secrets, not config files
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
     * @param array $options CLI options
     * @param array $arguments CLI arguments
     * @param Component|null $component The selected component (if any)
     *
     * @return bool True if the module performed some action.
     */
    public function handle(array $options, array $arguments, ?Component $component = null): bool
    {
        if ((isset($arguments[0]) && $arguments[0] == 'config')) {
            $this->_handle($arguments);
            return true;
        }
        return false;
    }

    private function _handle(array $arguments)
    {
        $builtin = $this->dependencies->get(BuiltinConfigProvider::class);
        $phpFile = $this->dependencies->get(PhpConfigFileProvider::class);
        $cli = $this->dependencies->get(Cli::class);
        if (isset($arguments[1]) && $arguments[1] == 'init') {
            foreach ($builtin->dumpSettings() as $id => $value) {
                $phpFile->setSetting($id, $value);
            }
            $phpFile->writeToDisk();
        } elseif (isset($arguments[1]) && isset($arguments[2])) {
            $phpFile->setSetting($arguments[1], $arguments[2]);
            $phpFile->writeToDisk();
        } elseif (isset($arguments[1])) {
            $cli->writeln(sprintf("The value of %s is:", $arguments[1]));
            if ($phpFile->hasSetting($arguments[1])) {
                $cli->writeln($phpFile->getSetting($arguments[1]));
            } else {
                $cli->writeln("(not set)");
            }
        } else {
            $cli->writeln($this->getHelp(""));
        }
    }
}
