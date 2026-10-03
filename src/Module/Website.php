<?php

/**
 * Website module - Generate dev.horde.org and www.horde.org
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
use Horde\Components\Runner\WwwWebsite as WwwWebsiteRunner;
use Horde\Components\Runner\WwwSiteConfig;
use Horde\Components\Output;
use Horde\GithubApiClient\GithubApiConfig;

/**
 * Website module - Generate dev.horde.org and www.horde.org
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
        return 'Generate dev.horde.org and/or www.horde.org websites';
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
                    'help'   => 'dev.horde.org output directory (default: build/dev.horde.org)',
                ]
            ),
            new Option(
                '--web-templates',
                [
                    'action' => 'store',
                    'help'   => 'dev.horde.org templates directory (default: <checkout.dir>/<org>/dev.horde.org/content/pages)',
                ]
            ),
            new Option(
                '--web-assets',
                [
                    'action' => 'store',
                    'help'   => 'dev.horde.org static assets directory (default: <checkout.dir>/<org>/dev.horde.org/content/assets)',
                ]
            ),
            new Option(
                '--web-components',
                [
                    'action' => 'store',
                    'help'   => 'Component catalog JSON (default: <templates dir>/components.json)',
                ]
            ),
            new Option(
                '--web-redirects',
                [
                    'action' => 'store',
                    'help'   => 'dev.horde.org legacy URL redirects JSON (default: <checkout.dir>/<org>/dev.horde.org/content/redirects.json)',
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
            new Option(
                '--www-templates',
                [
                    'action' => 'store',
                    'help'   => 'www.horde.org content pages directory (default: <checkout.dir>/<org>/horde-web/content/pages)',
                ]
            ),
            new Option(
                '--www-assets',
                [
                    'action' => 'store',
                    'help'   => 'www.horde.org static assets directory (default: <checkout.dir>/<org>/horde-web/content/assets)',
                ]
            ),
            new Option(
                '--www-output',
                [
                    'action' => 'store',
                    'help'   => 'www.horde.org output directory (default: build/www.horde.org)',
                ]
            ),
            new Option(
                '--www-redirects',
                [
                    'action' => 'store',
                    'help'   => 'www.horde.org legacy URL redirects JSON (default: <checkout.dir>/<org>/horde-web/content/redirects.json)',
                ]
            ),
            new Option(
                '--www-components',
                [
                    'action' => 'store',
                    'help'   => 'www.horde.org component catalog JSON (default: dev.horde.org\'s components.json)',
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
        return 'web [dev|www|all|catalog] - Generate dev.horde.org and/or www.horde.org websites';
    }

    /**
     * Get a short one-line description for command listings.
     *
     * @return string The short description.
     */
    public function getShortDescription(): string
    {
        return 'Generate dev.horde.org and/or www.horde.org websites';
    }

    public function getActions(): array
    {
        return ['web', 'web dev', 'web www', 'web all', 'web catalog'];
    }

    public function getHelp($action): string
    {
        return 'Generate dev.horde.org and/or www.horde.org websites, and manage the component catalog

WEBSITE GENERATION:

  horde-components web [dev|www|all]

  With no sub-action (or "all"), generates BOTH dev.horde.org and
  www.horde.org. Use "dev" or "www" to generate just one site.

  dev.horde.org options:
    --web-input <dir>        Webhook JSON directory (default: data/webhooks)
                             Config: devsite.input_dir
    --web-output <dir>       Output directory (default: build/dev.horde.org)
                             Config: devsite.output_dir
    --web-templates <dir>    Templates directory (default: <checkout.dir>/<org>/dev.horde.org/content/pages)
                             Config: devsite.template_dir
    --web-assets <dir>       Static assets directory (default: <checkout.dir>/<org>/dev.horde.org/content/assets)
                             Config: devsite.assets_dir
    --web-components <file>  Component catalog JSON (default: <templates dir>/components.json)
                             Config: devsite.components
    --web-redirects <file>   Legacy URL redirects JSON (default: <checkout.dir>/<org>/dev.horde.org/content/redirects.json)
                             Config: devsite.redirects_file

  www.horde.org options:
    --www-templates <dir>   Content pages directory (default: <checkout.dir>/<org>/horde-web/content/pages)
                             Config: wwwsite.template_dir
    --www-assets <dir>      Static assets directory (default: <checkout.dir>/<org>/horde-web/content/assets)
                             Config: wwwsite.assets_dir
    --www-output <dir>      Output directory (default: build/www.horde.org)
                             Config: wwwsite.output_dir
    --www-redirects <file>  Legacy URL redirects JSON (default: <checkout.dir>/<org>/horde-web/content/redirects.json)
                             Config: wwwsite.redirects_file
    --www-components <file> Component catalog JSON (default: dev.horde.org\'s components.json)
                             Config: wwwsite.components_file

  Examples:
    horde-components web              # generates both sites
    horde-components web dev          # dev.horde.org only
    horde-components web www          # www.horde.org only
    horde-components web all --web-output ~/www/dev --www-output ~/www/main

COMPONENT CATALOG:

  horde-components web catalog [--web-org <org>] [--web-git-dir <dir>]

  Update the component catalog from GitHub API. Both sites read from
  this same catalog file.

  Options:
    --web-components <file>  Catalog output file (default: <templates dir>/components.json)
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

  Note: CLI options --web-*/--www-* map to devsite.*/wwwsite.* config keys.
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
        // Get ConfigProvider
        $effectiveConfig = $this->dependencies->get(ConfigProviderFactory::class)->createDefault();

        $invokedAsWeb = (isset($arguments[0]) && $arguments[0] == 'web') || $effectiveConfig->hasSetting('web');
        if (!$invokedAsWeb) {
            return false;
        }

        // Detect components root for default paths
        $componentsRoot = dirname(__DIR__, 2);

        // Get fallback token from GithubApiConfig (set from GITHUB_TOKEN env)
        $githubApiConfig = $this->dependencies->get(GithubApiConfig::class);
        $fallbackToken = !empty($githubApiConfig->accessToken) ? $githubApiConfig->accessToken : null;

        $output = $this->dependencies->get(Output::class);
        $subAction = $arguments[1] ?? 'all';

        if ($subAction === 'catalog') {
            $websiteConfig = WebsiteConfig::fromConfigProvider($effectiveConfig, $componentsRoot, $fallbackToken);
            $runner = new WebsiteRunner($websiteConfig, $output);
            $runner->runCatalog();
            return true;
        }

        if (!in_array($subAction, ['dev', 'www', 'all'], true)) {
            // Unrecognized sub-action - let other modules/help handle it.
            return false;
        }

        if ($subAction === 'dev' || $subAction === 'all') {
            $websiteConfig = WebsiteConfig::fromConfigProvider($effectiveConfig, $componentsRoot, $fallbackToken);
            (new WebsiteRunner($websiteConfig, $output))->run();
        }

        if ($subAction === 'www' || $subAction === 'all') {
            $wwwConfig = WwwSiteConfig::fromConfigProvider($effectiveConfig, $componentsRoot);
            (new WwwWebsiteRunner($wwwConfig, $output))->run();
        }

        return true;
    }
}
