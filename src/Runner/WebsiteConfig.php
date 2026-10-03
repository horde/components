<?php

/**
 * Website configuration parameters
 *
 * PHP Version 8.2+
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Runner;

use Horde\Components\ConfigProvider\EffectiveConfigProvider;

/**
 * Website configuration parameters
 *
 * This parameter object encapsulates all website-related configuration
 * settings, providing a clean interface for the WebsiteRunner.
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
readonly class WebsiteConfig
{
    /**
     * Create website configuration from individual values
     *
     * @param string $inputDir Directory containing webhook JSON files
     * @param string|null $outputDir Directory for generated website, or null
     *   when no output location was configured (the runner then aborts with a
     *   clear message rather than writing to a bogus default). Not required by
     *   the catalog sub-action, which does not emit a site.
     * @param string $templatesDir Directory containing templates
     * @param string $componentsFile Path to components.json catalog file
     * @param string $assetsDir Directory containing static assets (CSS etc.)
     * @param string $redirectsFile Path to content/redirects.json (legacy URL stubs)
     * @param string $organization GitHub organization name
     * @param string|null $gitDir Optional local git repository directory
     * @param string|null $token Optional GitHub API token
     */
    public function __construct(
        public string $inputDir,
        public ?string $outputDir,
        public string $templatesDir,
        public string $componentsFile,
        public string $assetsDir,
        public string $redirectsFile,
        public string $organization = 'horde',
        public ?string $gitDir = null,
        public ?string $token = null
    ) {}

    /**
     * Create configuration from ConfigProvider with defaults
     *
     * @param EffectiveConfigProvider $config The configuration provider
     * @param string $componentsRoot The components root directory for relative paths
     * @param string|null $fallbackToken Fallback token if not in config
     * @return self
     */
    public static function fromConfigProvider(
        EffectiveConfigProvider $config,
        string $componentsRoot,
        ?string $fallbackToken = null
    ): self {
        // Get values with defaults relative to components root
        // Try new names first, then fall back to old names for backwards compatibility
        $inputDir = $config->hasSetting('devsite.input_dir')
            ? $config->getSetting('devsite.input_dir')
            : ($config->hasSetting('web_input')
                ? $config->getSetting('web_input')
                : $componentsRoot . '/data/webhooks');

        // Output directory has no derived default: it must come from an
        // explicit --web-output (devsite.output_dir) or the legacy web_output
        // key. When neither is set we leave it null so the runner can abort
        // with a clear message instead of writing to a bogus location (e.g. a
        // read-only path inside the phar).
        $outputDir = $config->hasSetting('devsite.output_dir')
            ? $config->getSetting('devsite.output_dir')
            : ($config->hasSetting('web_output')
                ? $config->getSetting('web_output')
                : null);

        // For organization, prefer repo.org over devsite.org over legacy web_org
        $organization = $config->hasSetting('repo.org')
            ? $config->getSetting('repo.org')
            : ($config->hasSetting('devsite.org')
                ? $config->getSetting('devsite.org')
                : ($config->hasSetting('web_org')
                    ? $config->getSetting('web_org')
                    : 'horde'));

        // For git directory, an explicit CLI --web-git-dir (web_git_dir) or
        // devsite.git_dir must win over checkout.dir: checkout.dir always
        // carries a builtin default ($HOME/git or /srv/git), so checking it
        // first would mask the flag the user actually passed. checkout.dir is
        // the checkout root; the org (default 'horde') and then the repos live
        // below it, so repos resolve as <git-dir>/<org>/<repo>.
        $gitDir = $config->hasSetting('web_git_dir')
            ? $config->getSetting('web_git_dir')
            : ($config->hasSetting('devsite.git_dir')
                ? $config->getSetting('devsite.git_dir')
                : ($config->hasSetting('checkout.dir')
                    ? $config->getSetting('checkout.dir')
                    : null));

        // Templates/content now live in the dev.horde.org content repo
        // itself (content/pages), not bundled inside this tool repo. Default
        // to the checked-out dev.horde.org repo when a git checkout
        // directory is known; otherwise fall back to the (no longer
        // populated) bundled data/website directory for backwards
        // compatibility.
        $templatesDir = $config->hasSetting('devsite.template_dir')
            ? $config->getSetting('devsite.template_dir')
            : ($config->hasSetting('web_templates')
                ? $config->getSetting('web_templates')
                : ($gitDir !== null
                    ? rtrim($gitDir, '/') . '/' . $organization . '/dev.horde.org/content/pages'
                    : $componentsRoot . '/data/website'));

        $componentsFile = $config->hasSetting('devsite.components')
            ? $config->getSetting('devsite.components')
            : ($config->hasSetting('web_components')
                ? $config->getSetting('web_components')
                : $templatesDir . '/components.json');

        // Static assets (CSS, etc.) live in the content repo's sibling
        // content/assets/ directory, not content/pages/ alongside the page
        // templates. Default to that sibling when templatesDir follows the
        // .../content/pages convention; otherwise fall back to templatesDir
        // itself (old bundled data/website layout, where CSS and templates
        // shared one directory).
        $assetsDir = $config->hasSetting('devsite.assets_dir')
            ? $config->getSetting('devsite.assets_dir')
            : ($config->hasSetting('web_assets')
                ? $config->getSetting('web_assets')
                : (str_ends_with(rtrim($templatesDir, '/'), '/content/pages')
                    ? dirname(rtrim($templatesDir, '/')) . '/assets'
                    : $templatesDir));

        // For token, prefer github.token over devsite.token over legacy web_token
        $token = $config->hasSetting('github.token')
            ? $config->getSetting('github.token')
            : ($config->hasSetting('devsite.token')
                ? $config->getSetting('devsite.token')
                : ($config->hasSetting('web_token')
                    ? $config->getSetting('web_token')
                    : $fallbackToken));

        // Legacy URL Policy (IA §6.5): retired/moved pages get a static
        // stub at their old path. The redirect map is authored content,
        // living as a sibling to content/pages/ (not inside it, since it
        // describes paths outside that tree too). Falls back to
        // templatesDir itself under the old bundled data/website layout.
        $redirectsFile = $config->hasSetting('devsite.redirects_file')
            ? $config->getSetting('devsite.redirects_file')
            : ($config->hasSetting('web_redirects')
                ? $config->getSetting('web_redirects')
                : (str_ends_with(rtrim($templatesDir, '/'), '/content/pages')
                    ? dirname(rtrim($templatesDir, '/')) . '/redirects.json'
                    : $templatesDir . '/redirects.json'));

        return new self(
            $inputDir,
            $outputDir,
            $templatesDir,
            $componentsFile,
            $assetsDir,
            $redirectsFile,
            $organization,
            $gitDir,
            $token
        );
    }
}
