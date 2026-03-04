<?php

/**
 * InstallModule:: Setup a horde installation from a git checkout
 *
 * PHP version 8.2+
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Module;

use Horde\Components\Component;
use Horde\Components\Dependencies;
use Horde\Components\Composer\InstallationDirectory;
use Horde\Components\Output;
use Horde\Components\Runner\InstallRunner;
use Horde\Components\RuntimeContext\GitCheckoutDirectory;

/**
 * InstallModule:: Setup a horde installation from a git checkout
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
class InstallModule extends Base
{
    public function getOptionGroupTitle(): string
    {
        return 'install';
    }

    public function getOptionGroupDescription(): string
    {
        return 'Install a git checkout into a web tree';
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
        return 'install';
    }

    /**
     * Get the usage description for this module.
     *
     * @return string The description.
     */
    public function getUsage(): string
    {
        return <<<'HELP'
            Install Horde from a git checkout into a web-accessible directory.

            This command sets up a complete Horde development environment by:
            1. Copying the horde/bundle component from your git checkout
            2. Configuring composer.json to use local path repositories for all Horde components
            3. Setting up the installation directory ready for composer install

            USAGE:
                horde-components install

            CONFIGURATION:
                This command uses two configuration settings:

                checkout.dir      Your Horde git checkout directory (parent of vendor directories)
                                  Components are located at: checkout.dir/horde/component
                                  (default: ~/git or /srv/git)
                                  Set with: horde-components config checkout.dir /path/to/git

                install.dir       Target installation directory (web tree)
                                  (default: ~/www/horde-dev or /srv/www/horde-dev)
                                  Set with: horde-components config install.dir /srv/www/horde
                                  Or set HORDE_INSTALL_DIR environment variable

            PREREQUISITES:
                1. Complete git checkout with Horde components
                   Run: horde-components github clone-org horde

                2. Empty or non-existent installation directory
                   The command will create it if needed

            WORKFLOW:
                1. Copies horde/bundle from git checkout to installation directory
                2. Adds all Horde components as composer path repositories
                3. Configures composer to prefer stable packages
                4. You then run: composer install (in the installation directory)

            EXAMPLE:
                # Set up directories
                horde-components config checkout.dir ~/git
                horde-components config install.dir /srv/www/horde-dev

                # Clone all Horde repositories (if not done yet)
                horde-components github clone-org horde

                # Install Horde
                horde-components install

                # Complete the installation
                cd /srv/www/horde-dev
                composer install

            SEE ALSO:
                horde-components github clone-org    Clone all Horde repositories
                horde-components status              Check directory configuration
            HELP;
    }

    /**
     * Get a short one-line description for command listings.
     *
     * @return string The short description.
     */
    public function getShortDescription(): string
    {
        return 'Install Horde from git checkout to web directory';
    }

    /**
     * Return the action arguments supported by this module.
     *
     * @return array A list of supported action arguments.
     */
    public function getActions(): array
    {
        return ['install'];
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
        return $this->getUsage();
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
        if (!empty($options['install'])
            || (isset($arguments[0]) && $arguments[0] == 'install')) {

            // Get dependencies
            $output = $this->dependencies->get(Output::class);
            $gitCheckoutDir = $this->dependencies->get(GitCheckoutDirectory::class);
            $installDir = $this->dependencies->get(InstallationDirectory::class);

            // Instantiate and run runner
            $runner = new InstallRunner(
                $gitCheckoutDir,
                $installDir,
                $output
            );
            $runner->run();

            return true;
        }
        return false;
    }
}
