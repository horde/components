<?php

/**
 * Website Runner - Orchestrates www.horde.org generation
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

use Horde\Components\Output;
use Horde\Components\Website\BlogFeedFetcher;
use Horde\Components\Website\EventNormalizer;
use Horde\Components\Website\EventScanner;
use Horde\Components\Website\PulseStatsCalculator;
use Horde\Components\Website\SponsorCardRenderer;
use Horde\Components\Website\SponsorRoster;
use Horde\Components\Website\WwwPageGenerator;
use DirectoryIterator;
use RuntimeException;

/**
 * Website Runner - Orchestrates www.horde.org generation
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
class WwwWebsite
{
    private const CSS_FILENAME = 'www.horde.org.css';

    public function __construct(
        private readonly WwwSiteConfig $config,
        private readonly Output $output
    ) {}

    /**
     * Static pages that get a straight content-fragment-to-chrome
     * wrap, keyed by output-relative path. Each entry maps to the
     * content fragment (relative to templatesDir) and a page title.
     * Development is a thin pointer page (IA §6.2) - the real developer
     * content lives on dev.horde.org.
     */
    private const STATIC_PAGES = [
        'community/index.html' => ['content' => 'community/index.html', 'title' => 'Community - Horde'],
        'community/team/index.html' => ['content' => 'community/team.html', 'title' => 'Core Team - Horde'],
        'community/localization/index.html' => ['content' => 'community/localization.html', 'title' => 'Localization - Horde'],
        'community/mail/index.html' => ['content' => 'community/mail.html', 'title' => 'Mailing Lists - Horde'],
        'community/papers/index.html' => ['content' => 'community/papers.html', 'title' => 'Papers &amp; Presentations - Horde'],
        'community/support/index.html' => ['content' => 'community/support.html', 'title' => 'Community Support - Horde'],
        'support/index.html' => ['content' => 'support/index.html', 'title' => 'Support - Horde'],
        'services/index.html' => ['content' => 'services/index.html', 'title' => 'Commercial Use & Support - Horde'],
        'contact/index.html' => ['content' => 'contact/index.html', 'title' => 'Contact - Horde'],
        'quickstart/index.html' => ['content' => 'quickstart/index.html', 'title' => 'Quickstart: Deploy Horde - Horde'],
        'development/index.html' => ['content' => 'development/index.html', 'title' => 'Development - Horde'],
        'development/licenses/index.html' => ['content' => 'development/licenses.html', 'title' => 'Licenses - Horde'],
        'development/licenses/gpl/index.html' => ['content' => 'licenses/gpl.html', 'title' => 'GPL License - Horde'],
        'development/licenses/lgpl/index.html' => ['content' => 'licenses/lgpl.html', 'title' => 'LGPL License - Horde'],
        'development/licenses/lgpl21/index.html' => ['content' => 'licenses/lgpl21.html', 'title' => 'LGPL-2.1 License - Horde'],
        'development/licenses/bsd/index.html' => ['content' => 'licenses/bsd.html', 'title' => 'BSD-like License - Horde'],
        'development/licenses/apache/index.html' => ['content' => 'licenses/apache.html', 'title' => 'Apache-like License - Horde'],
    ];

    public function run(): void
    {
        $this->output->info("Generating www.horde.org website");
        $this->output->info("  Templates:  {$this->config->templatesDir}");
        $this->output->info("  Assets:     {$this->config->assetsDir}");
        $this->output->info("  Output:     {$this->config->outputDir}");
        $this->output->info("  Components: {$this->config->componentsFile}");
        $this->output->info("  Redirects:  {$this->config->redirectsFile}");

        if (!is_dir($this->config->templatesDir)) {
            throw new RuntimeException("Templates directory not found: {$this->config->templatesDir}");
        }

        // Guard the output location: no derived default, so require an
        // explicit --www-output (wwwsite.output_dir) and refuse to create a
        // directory whose parent is missing. See WebsiteConfig for rationale
        // (avoids writing to a read-only phar:// path under a phar build).
        if ($this->config->outputDir === null) {
            throw new RuntimeException(
                'No output directory configured. Pass --www-output <dir> or set wwwsite.output_dir.'
            );
        }
        $outputParent = dirname($this->config->outputDir);
        if (!is_dir($outputParent)) {
            throw new RuntimeException(
                "Output parent directory does not exist: {$outputParent}; "
                . "refusing to create {$this->config->outputDir}."
            );
        }

        if (!is_dir($this->config->outputDir)) {
            mkdir($this->config->outputDir, 0o755, true);
            $this->output->ok("Created output directory");
        }
        if (!is_dir($this->config->outputDir . '/apps')) {
            mkdir($this->config->outputDir . '/apps', 0o755, true);
        }

        $generator = new WwwPageGenerator(
            $this->config->templatesDir,
            $this->config->assetsDir,
            self::CSS_FILENAME,
            $this->config->componentsFile,
            $this->config->gitDir,
            $this->config->organization,
            $this->config->legacyLicensesDir
        );

        // --- Footer sponsor-slot widget (rotates on rebuild) ---
        $sponsor = (new SponsorRoster())->loadAndSelect($this->config->sponsorsFile);
        if ($sponsor !== null) {
            $generator->setSponsorHtml((new SponsorCardRenderer())->render($sponsor));
            $this->output->plain("Sponsor slot: {$sponsor->name}");
        } else {
            $this->output->plain("Sponsor slot: no roster entries ({$this->config->sponsorsFile}), using static fallback");
        }

        // --- Homepage: pulse stats + blog roll ---
        $pulseStats = $this->computePulseStats();
        $this->output->plain(sprintf(
            "Pulse stats: %d components, %d releases/90d, %d commits/30d, %d open PRs",
            $pulseStats['components_tracked'],
            $pulseStats['releases_90d'],
            $pulseStats['commits_30d'],
            $pulseStats['open_prs']
        ));

        $blogPosts = $this->fetchBlogPosts();
        $this->output->plain(sprintf("Fetched %d blog-roll posts (merged from %d sources).", count($blogPosts), count($this->config->blogFeeds)));

        $generator->generateHomePage($pulseStats, $blogPosts, $this->config->outputDir . '/index.html');
        $this->output->ok("Generated index.html");

        // --- Apps index + per-app detail pages ---
        $generator->generateAppsIndex($this->config->outputDir . '/apps/index.html');
        $this->output->ok("Generated apps/index.html");

        $slugs = $generator->discoverAppSlugs();
        foreach ($slugs as $slug) {
            $appOutputDir = $this->config->outputDir . '/apps/' . $slug;
            if (!is_dir($appOutputDir)) {
                mkdir($appOutputDir, 0o755, true);
            }
            $generator->generateAppPage($slug, $appOutputDir . '/index.html');
        }
        $this->output->ok(sprintf("Generated %d app detail page(s)", count($slugs)));

        // --- Generic static content pages ---
        foreach (self::STATIC_PAGES as $outputRelPath => $page) {
            $outputFile = $this->config->outputDir . '/' . $outputRelPath;
            $outputDir = dirname($outputFile);
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0o755, true);
            }
            $depth = substr_count($outputRelPath, '/');
            $cssPrefix = str_repeat('../', $depth);
            $generator->generateStaticPage($page['content'], $outputFile, $page['title'], $cssPrefix);
        }
        $this->output->ok(sprintf("Generated %d static content page(s)", count(self::STATIC_PAGES)));

        // --- Legacy URL stubs (/libraries, /development/git, ...) ---
        $stubCount = $generator->generateRedirectStubs($this->config->redirectsFile, $this->config->outputDir);
        if ($stubCount > 0) {
            $this->output->ok("Generated {$stubCount} legacy URL redirect stub(s)");
        } else {
            $this->output->plain("No legacy URL redirects to generate ({$this->config->redirectsFile})");
        }

        // --- Static assets ---
        $cssSource = $this->config->assetsDir . '/' . self::CSS_FILENAME;
        $cssDest = $this->config->outputDir . '/' . self::CSS_FILENAME;
        if (file_exists($cssSource)) {
            copy($cssSource, $cssDest);
            $this->output->ok("Copied CSS stylesheet");
        } else {
            $this->output->warn("CSS stylesheet not found, not copied: {$cssSource}");
        }

        $statusJsSource = $this->config->assetsDir . '/' . WwwPageGenerator::STATUS_WIDGET_JS_FILENAME;
        $statusJsDest = $this->config->outputDir . '/' . WwwPageGenerator::STATUS_WIDGET_JS_FILENAME;
        if (file_exists($statusJsSource)) {
            copy($statusJsSource, $statusJsDest);
            $this->output->ok("Copied status-widget.js");
        } else {
            $this->output->warn("status-widget.js not found, not copied: {$statusJsSource}");
        }

        // --- Conference papers archive (verbatim static asset tree) ---
        $this->copyPapersArchive();

        $this->output->ok("Website generated successfully!");
        $this->output->info("  Main page: {$this->config->outputDir}/index.html");
        $this->output->info("  Apps: {$this->config->outputDir}/apps/");
    }

    /**
     * @return array{components_tracked: int, releases_90d: int, commits_30d: int, open_prs: int}
     */
    private function computePulseStats(): array
    {
        $componentsTracked = 0;
        if (file_exists($this->config->componentsFile)) {
            $decoded = json_decode((string) file_get_contents($this->config->componentsFile), true);
            $componentsTracked = is_array($decoded) ? count($decoded) : 0;
        }

        $events = [];
        if (is_dir($this->config->webhooksDir)) {
            $scanner = new EventScanner($this->config->webhooksDir);
            $normalizer = new EventNormalizer();
            foreach ($scanner->scan() as $rawEvent) {
                $normalized = $normalizer->normalize($rawEvent);
                if ($normalized !== null) {
                    $events[] = $normalized;
                }
            }
        }

        return (new PulseStatsCalculator())->calculate($events, $componentsTracked);
    }

    /**
     * @return \Horde\Components\Website\BlogPost[]
     */
    private function fetchBlogPosts(): array
    {
        $fetcher = new BlogFeedFetcher(120);
        $errors = [];
        $posts = $fetcher->fetchMerged($this->config->blogFeeds, 3, $errors);

        foreach ($errors as $url => $message) {
            $this->output->warn("Blog feed fetch failed for {$url}: {$message}");
        }

        return $posts;
    }

    /**
     * Copy the legacy conference-papers archive (PDFs, per-talk static
     * HTML/S5 slideshow subdirectories, screenshots) verbatim into
     * `$outputDir/papers/`. This is legacy static content, not editorial
     * prose - `community/papers/index.html` already links to it with
     * plain root-relative `/papers/...` hrefs, so a straight recursive
     * copy is all that's needed (no templating/generation).
     */
    private function copyPapersArchive(): void
    {
        $source = $this->config->papersDir;
        if ($source === '' || !is_dir($source)) {
            $this->output->warn("Papers archive not found, not copied: {$source}");
            return;
        }

        $dest = $this->config->outputDir . '/papers';
        if (!is_dir($dest)) {
            mkdir($dest, 0o755, true);
        }
        $fileCount = $this->copyDirectoryRecursive($source, $dest);
        $this->output->ok("Copied papers archive ({$fileCount} file(s))");
    }

    /**
     * Recursively copy a directory tree, returning the number of files
     * copied. Mirrors BuildPharTask::copyDirectory()'s approach.
     */
    private function copyDirectoryRecursive(string $source, string $dest): int
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0o755, true);
        }

        $count = 0;
        foreach (new DirectoryIterator($source) as $item) {
            if ($item->isDot()) {
                continue;
            }
            $targetPath = $dest . '/' . $item->getFilename();
            if ($item->isDir()) {
                $count += $this->copyDirectoryRecursive($item->getPathname(), $targetPath);
            } else {
                copy($item->getPathname(), $targetPath);
                $count++;
            }
        }

        return $count;
    }
}
