<?php

/**
 * Website module - Generate dev.horde.org from webhook events
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

use Horde\Components\Config;
use Horde\Components\Runner\Website as WebsiteRunner;
use Horde\Components\RuntimeContext\CurrentWorkingDirectory;

/**
 * Website module - Generate dev.horde.org from webhook events
 *
 * Copyright 2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Website extends Base
{
    public function getOptionGroupTitle(): string
    {
        return 'web';
    }

    public function getOptionGroupDescription(): string
    {
        return 'Generate dev.horde.org website from webhook events';
    }

    public function getOptionGroupOptions(): array
    {
        return [
            new \Horde\Argv\Option(
                '--web-input',
                [
                    'action' => 'store',
                    'help'   => 'Webhook JSON directory (default: data/webhooks)',
                ]
            ),
            new \Horde\Argv\Option(
                '--web-output',
                [
                    'action' => 'store',
                    'help'   => 'Output directory (default: build/dev.horde.org)',
                ]
            ),
            new \Horde\Argv\Option(
                '--web-templates',
                [
                    'action' => 'store',
                    'help'   => 'Templates directory (default: data/website)',
                ]
            ),
            new \Horde\Argv\Option(
                '--web-components',
                [
                    'action' => 'store',
                    'help'   => 'Component catalog JSON (default: data/website/components.json)',
                ]
            ),
            new \Horde\Argv\Option(
                '--web-org',
                [
                    'action' => 'store',
                    'help'   => 'GitHub organization (default: horde)',
                ]
            ),
            new \Horde\Argv\Option(
                '--web-git-dir',
                [
                    'action' => 'store',
                    'help'   => 'Local git repo directory for version info',
                ]
            ),
            new \Horde\Argv\Option(
                '--web-token',
                [
                    'action' => 'store',
                    'help'   => 'GitHub API token (or use GITHUB_TOKEN env var)',
                ]
            ),
        ];
    }

    public function getTitle(): string
    {
        return 'web';
    }

    public function getUsage(): string
    {
        return 'web - Generate dev.horde.org website';
    }

    public function getActions(): array
    {
        return ['web', 'web catalog'];
    }

    public function getHelp($action): string
    {
        return 'Generate dev.horde.org website and manage component catalog

WEBSITE GENERATION:

  horde-components web [--web-input <dir>] [--web-output <dir>]

  Generate the dev.horde.org website from webhook events.

  Options:
    --web-input <dir>        Webhook JSON directory (default: data/webhooks)
    --web-output <dir>       Output directory (default: build/dev.horde.org)
    --web-templates <dir>    Templates directory (default: data/website)
    --web-components <file>  Component catalog JSON (default: data/website/components.json)

  Examples:
    horde-components web
    horde-components web --web-input ~/hook --web-output ~/www/dev

COMPONENT CATALOG:

  horde-components web catalog [--web-org <org>] [--web-git-dir <dir>]

  Update the component catalog from GitHub API.

  Options:
    --web-components <file>  Catalog output file (default: data/website/components.json)
    --web-org <name>         GitHub organization (default: horde)
    --web-git-dir <path>     Local git repos for version info
    --web-token <token>      GitHub API token (or set GITHUB_TOKEN env var)

  Examples:
    horde-components web catalog
    horde-components web catalog --web-org horde --web-git-dir ~/git/horde
    GITHUB_TOKEN=ghp_xxx horde-components web catalog
';
    }

    public function getContextOptionHelp(): array
    {
        return [];
    }

    /**
     * Determine if this module should act.
     */
    public function handle(Config $config): bool
    {
        $options = $config->getOptions();
        $arguments = $config->getArguments();

        // Check for "web catalog" subcommand
        if ((isset($arguments[0]) && $arguments[0] == 'web' && isset($arguments[1]) && $arguments[1] == 'catalog')) {
            $runner = $this->dependencies->get(WebsiteRunner::class);
            $runner->runCatalog($config);
            return true;
        }

        // Check for "web" command
        if (!empty($options['web'])
            || (isset($arguments[0]) && $arguments[0] == 'web')) {

            $runner = $this->dependencies->get(WebsiteRunner::class);
            $runner->run($config);

            return true;
        }

        return false;
    }
}
