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

use Horde\Argv\Option;
use Horde\Components\Component;
use Horde\Components\ConfigProvider\ConfigProviderFactory;
use Horde\Components\Runner\Website as WebsiteRunner;
use Horde\Components\Runner\WebsiteConfig;
use Horde\Components\Output;
use Horde\GithubApiClient\GithubApiConfig;

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
            new Option(
                '--web-input',
                [
                    'action' => 'store',
                    'help'   => 'Webhook JSON directory (default: data/webhooks)',
                ]
            ),
            new Option(
                '--web-output',
                [
                    'action' => 'store',
                    'help'   => 'Output directory (default: build/dev.horde.org)',
                ]
            ),
            new Option(
                '--web-templates',
                [
                    'action' => 'store',
                    'help'   => 'Templates directory (default: data/website)',
                ]
            ),
            new Option(
                '--web-components',
                [
                    'action' => 'store',
                    'help'   => 'Component catalog JSON (default: data/website/components.json)',
                ]
            ),
            new Option(
                '--web-org',
                [
                    'action' => 'store',
                    'help'   => 'GitHub organization (default: horde)',
                ]
            ),
            new Option(
                '--web-git-dir',
                [
                    'action' => 'store',
                    'help'   => 'Local git repo directory for version info',
                ]
            ),
            new Option(
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

    /**
     * Get a short one-line description for command listings.
     *
     * @return string The short description.
     */
    public function getShortDescription(): string
    {
        return 'Generate dev.horde.org website';
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
                             Config: devsite.input_dir
    --web-output <dir>       Output directory (default: build/dev.horde.org)
                             Config: devsite.output_dir
    --web-templates <dir>    Templates directory (default: data/website)
                             Config: devsite.template_dir
    --web-components <file>  Component catalog JSON (default: data/website/components.json)
                             Config: devsite.components

  Examples:
    horde-components web
    horde-components web --web-input ~/hook --web-output ~/www/dev

COMPONENT CATALOG:

  horde-components web catalog [--web-org <org>] [--web-git-dir <dir>]

  Update the component catalog from GitHub API.

  Options:
    --web-components <file>  Catalog output file (default: data/website/components.json)
                             Config: devsite.components
    --web-org <name>         GitHub organization (default: horde)
                             Config: repo.org (shared) or devsite.org
    --web-git-dir <path>     Local git repos for version info
                             Config: checkout.dir (shared) or devsite.git_dir
    --web-token <token>      GitHub API token (or set GITHUB_TOKEN env var)
                             Config: github.token (shared) or devsite.token

  Examples:
    horde-components web catalog
    horde-components web catalog --web-org horde --web-git-dir ~/git
    GITHUB_TOKEN=ghp_xxx horde-components web catalog

  Note: CLI options --web-* map to devsite.* config keys for consistency.
';
    }

    public function getContextOptionHelp(): array
    {
        return [];
    }

    /**
     * Determine if this module should act.
     *
     * @param array $options CLI options
     * @param array $arguments CLI arguments
     * @param Component|null $component The selected component (if any)
     *
     * @return bool True if the module performed some action.
     */
    public function handle(array $options, array $arguments, ?Component $component = null): bool
    {
        // Detect components root for default paths
        $componentsRoot = dirname(__DIR__, 2);

        // Get ConfigProvider
        $effectiveConfig = $this->dependencies->get(ConfigProviderFactory::class)->createDefault();

        // Get fallback token from GithubApiConfig (set from GITHUB_TOKEN env)
        $githubApiConfig = $this->dependencies->get(GithubApiConfig::class);
        $fallbackToken = !empty($githubApiConfig->accessToken) ? $githubApiConfig->accessToken : null;

        // Create website configuration from ConfigProvider
        $websiteConfig = WebsiteConfig::fromConfigProvider(
            $effectiveConfig,
            $componentsRoot,
            $fallbackToken
        );

        // Get output for runner
        $output = $this->dependencies->get(Output::class);

        // Check for "web catalog" subcommand
        if ((isset($arguments[0]) && $arguments[0] == 'web' && isset($arguments[1]) && $arguments[1] == 'catalog')) {
            $runner = new WebsiteRunner($websiteConfig, $output);
            $runner->runCatalog();
            return true;
        }

        // Check for "web" command
        if ($effectiveConfig->hasSetting('web')
            || (isset($arguments[0]) && $arguments[0] == 'web')) {

            $runner = new WebsiteRunner($websiteConfig, $output);
            $runner->run();

            return true;
        }

        return false;
    }
}
