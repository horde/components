<?php

/**
 * www.horde.org website configuration parameters
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
 * www.horde.org website configuration parameters
 *
 * Mirrors WebsiteConfig's structure/fallback-chain conventions, but for
 * the www.horde.org content repo (horde-web) rather than dev.horde.org.
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
readonly class WwwSiteConfig
{
    /**
     * @param string $templatesDir Directory containing horde-web/content/pages
     * @param string $outputDir Directory for generated website
     * @param string $assetsDir Directory containing static assets (CSS etc.)
     * @param string $redirectsFile Path to content/redirects.json (legacy URL stubs)
     * @param string $sponsorsFile Path to content/sponsors.json (rotating sponsor slot)
     * @param string $componentsFile Path to the dev.horde.org component catalog JSON
     *   (shared Phase 2 unified facts - www.horde.org does not maintain its own copy)
     * @param string $webhooksDir Directory containing webhook JSON files (for pulse stats)
     * @param array<int, array{url: string, label: string}> $blogFeeds Blog-roll RSS sources
     * @param string|null $gitDir Local checkout root (checkout.dir) for per-app .horde.yml lookups
     * @param string $organization GitHub organization name (subdirectory under $gitDir)
     * @param string $legacyLicensesDir Directory containing the verbatim legacy license text
     *   files (COPYING, LGPL, LGPL-2.1, LICENSE, COPYRIGHT), read at build time by the
     *   `<!-- WIDGET: license-text source=... -->` placeholder
     * @param string $papersDir Directory containing the legacy conference-papers archive
     *   (PDFs, per-talk static HTML/S5 slideshow subdirectories, images), copied verbatim
     *   into the build output's /papers/ - see WwwWebsite::copyPapersArchive()
     */
    public function __construct(
        public string $templatesDir,
        public string $outputDir,
        public string $assetsDir,
        public string $redirectsFile,
        public string $sponsorsFile,
        public string $componentsFile,
        public string $webhooksDir,
        public array $blogFeeds,
        public ?string $gitDir = null,
        public string $organization = 'horde',
        public string $legacyLicensesDir = '',
        public string $papersDir = '',
    ) {
    }

    /**
     * Default blog-roll RSS sources, per project decision: merge the
     * official Horde News channel with community blog posts tagged for
     * Horde. See BlogFeedFetcher/BlogRollRenderer.
     *
     * @return array<int, array{url: string, label: string}>
     */
    public static function defaultBlogFeeds(): array
    {
        return [
            [
                'url' => 'https://dev.horde.org/horde/jonah/delivery/rss.php?channel_id=1',
                'label' => 'Horde News',
            ],
            [
                'url' => 'https://www.ralf-lang.de/category/horde-it/feed/',
                'label' => 'Community: ralf-lang.de',
            ],
        ];
    }

    public static function fromConfigProvider(
        EffectiveConfigProvider $config,
        string $componentsRoot
    ): self {
        $organization = $config->hasSetting('repo.org')
            ? $config->getSetting('repo.org')
            : ($config->hasSetting('wwwsite.org')
                ? $config->getSetting('wwwsite.org')
                : 'horde');

        $gitDir = $config->hasSetting('checkout.dir')
            ? $config->getSetting('checkout.dir')
            : ($config->hasSetting('wwwsite.git_dir')
                ? $config->getSetting('wwwsite.git_dir')
                : null);

        // Content lives in the horde-web repo's content/pages directory,
        // mirroring dev.horde.org's own content/pages convention.
        $templatesDir = $config->hasSetting('wwwsite.template_dir')
            ? $config->getSetting('wwwsite.template_dir')
            : ($gitDir !== null
                ? rtrim($gitDir, '/') . '/' . $organization . '/horde-web/content/pages'
                : $componentsRoot . '/data/www');

        $outputDir = $config->hasSetting('wwwsite.output_dir')
            ? $config->getSetting('wwwsite.output_dir')
            : $componentsRoot . '/build/www.horde.org';

        $assetsDir = $config->hasSetting('wwwsite.assets_dir')
            ? $config->getSetting('wwwsite.assets_dir')
            : (str_ends_with(rtrim($templatesDir, '/'), '/content/pages')
                ? dirname(rtrim($templatesDir, '/')) . '/assets'
                : $templatesDir);

        $redirectsFile = $config->hasSetting('wwwsite.redirects_file')
            ? $config->getSetting('wwwsite.redirects_file')
            : (str_ends_with(rtrim($templatesDir, '/'), '/content/pages')
                ? dirname(rtrim($templatesDir, '/')) . '/redirects.json'
                : $templatesDir . '/redirects.json');

        $sponsorsFile = $config->hasSetting('wwwsite.sponsors_file')
            ? $config->getSetting('wwwsite.sponsors_file')
            : (str_ends_with(rtrim($templatesDir, '/'), '/content/pages')
                ? dirname(rtrim($templatesDir, '/')) . '/sponsors.json'
                : $templatesDir . '/sponsors.json');

        // The verbatim license texts still live in their original legacy
        // location (app/views/Licenses/*) rather than being duplicated
        // into content/pages - they are unchanging legal text, not
        // editorial content, so re-harvesting them would just be a
        // pointless copy. See WwwPageGenerator's license-text widget.
        $legacyLicensesDir = $config->hasSetting('wwwsite.legacy_licenses_dir')
            ? $config->getSetting('wwwsite.legacy_licenses_dir')
            : (str_ends_with(rtrim($templatesDir, '/'), '/content/pages')
                ? dirname(dirname(rtrim($templatesDir, '/'))) . '/app/views/Licenses'
                : $templatesDir . '/../app/views/Licenses');

        // The conference-papers archive (PDFs, per-talk static HTML/S5
        // slideshow subdirectories, images) is likewise a self-contained
        // legacy static asset tree, not editorial content to re-author -
        // it is copied verbatim into the build output. See
        // WwwWebsite::copyPapersArchive().
        $papersDir = $config->hasSetting('wwwsite.papers_dir')
            ? $config->getSetting('wwwsite.papers_dir')
            : (str_ends_with(rtrim($templatesDir, '/'), '/content/pages')
                ? dirname(dirname(rtrim($templatesDir, '/'))) . '/papers'
                : $templatesDir . '/../papers');

        // The component facts catalog is dev.horde.org's own Phase 2
        // unified facts file (components.json), not a separate copy -
        // www.horde.org's /apps pages read from the same source of truth,
        // filtered down to the apps that actually have www content.
        $componentsFile = $config->hasSetting('wwwsite.components_file')
            ? $config->getSetting('wwwsite.components_file')
            : ($config->hasSetting('devsite.components')
                ? $config->getSetting('devsite.components')
                : ($gitDir !== null
                    ? rtrim($gitDir, '/') . '/' . $organization . '/dev.horde.org/content/pages/components.json'
                    : $componentsRoot . '/data/website/components.json'));

        $webhooksDir = $config->hasSetting('wwwsite.webhooks_dir')
            ? $config->getSetting('wwwsite.webhooks_dir')
            : ($config->hasSetting('devsite.input_dir')
                ? $config->getSetting('devsite.input_dir')
                : $componentsRoot . '/data/webhooks');

        $blogFeeds = $config->hasSetting('wwwsite.blog_feeds')
            ? json_decode($config->getSetting('wwwsite.blog_feeds'), true)
            : self::defaultBlogFeeds();

        return new self(
            $templatesDir,
            $outputDir,
            $assetsDir,
            $redirectsFile,
            $sponsorsFile,
            $componentsFile,
            $webhooksDir,
            $blogFeeds,
            $gitDir,
            $organization,
            $legacyLicensesDir,
            $papersDir
        );
    }
}
