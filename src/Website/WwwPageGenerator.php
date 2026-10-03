<?php

/**
 * Full page generator for www.horde.org
 *
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

declare(strict_types=1);

namespace Horde\Components\Website;

use RuntimeException;
use Horde\HordeYmlFile\HordeYmlFile;
use Exception;

/**
 * Full page generator for www.horde.org.
 *
 * Mirrors dev.horde.org's PageGenerator architecture (plain fragments +
 * PHP heredoc assembly, no templating engine), but for the www.horde.org
 * content repo (horde-web) and its narrower, marketing/support-facing
 * page set: home, /apps (+ per-app detail pages), and generic static
 * content pages (community, support, services, contact, development
 * pointer).
 */
class WwwPageGenerator
{
    /**
     * Static JS asset (copied verbatim from assetsDir, like the CSS
     * stylesheet) that renders the live StatusCake status badge in the
     * footer. See content/assets/status-widget.js.
     */
    public const STATUS_WIDGET_JS_FILENAME = 'status-widget.js';

    private string $templatesDir;
    private string $assetsDir;
    private string $cssFilename;
    private string $componentsFile;
    private ?string $gitDir;
    private string $organization;
    private ?string $sponsorHtml = null;
    private string $legacyLicensesDir;

    /** @var array<int, array<string, mixed>>|null Lazily loaded catalog */
    private ?array $components = null;

    public function __construct(
        string $templatesDir,
        string $assetsDir,
        string $cssFilename,
        string $componentsFile,
        ?string $gitDir = null,
        string $organization = 'horde',
        string $legacyLicensesDir = ''
    ) {
        $this->templatesDir = rtrim($templatesDir, '/');
        $this->assetsDir = rtrim($assetsDir, '/');
        $this->cssFilename = $cssFilename;
        $this->componentsFile = $componentsFile;
        $this->gitDir = $gitDir !== null ? rtrim($gitDir, '/') : null;
        $this->organization = $organization;
        $this->legacyLicensesDir = rtrim($legacyLicensesDir, '/');
    }

    /**
     * Set the rendered sponsor-card HTML for this build's footer
     * sponsor-slot widget (see SponsorRoster/SponsorCardRenderer). If
     * never called (no roster entries), wrapPage()/buildRedirectStub()
     * leave footer.html's own static fallback markup untouched.
     */
    public function setSponsorHtml(string $html): void
    {
        $this->sponsorHtml = $html;
    }

    /**
     * Generate the homepage: topbar + home/index.html content with the
     * live-project-pulse and blog-roll widget placeholders filled in,
     * + footer.
     *
     * @param array{components_tracked: int, releases_90d: int, commits_30d: int, open_prs: int} $pulseStats
     * @param BlogPost[] $blogPosts
     */
    public function generateHomePage(array $pulseStats, array $blogPosts, string $outputFile): void
    {
        $body = $this->loadTemplate('home/index.html');

        foreach ($pulseStats as $key => $value) {
            $body = str_replace('{' . $key . '}', (string) $value, $body);
        }

        $blogRollHtml = (new BlogRollRenderer())->render($blogPosts);
        $body = $this->replaceWidgetBlock($body, 'blog-roll', $blogRollHtml);

        $html = $this->wrapPage('Horde - Free Groupware & Web Applications', $body, '');
        file_put_contents($outputFile, $html);
    }

    /**
     * Generate a generic static content page (community, support,
     * services, contact, development pointer, licenses, ...): load the
     * named content fragment, wrap in chrome, write to $outputFile.
     *
     * $cssPrefix is the relative path back to the site root (e.g. ''
     * at the root, '../' one level deep) so the stylesheet link resolves
     * correctly regardless of output nesting depth.
     */
    public function generateStaticPage(
        string $contentRelPath,
        string $outputFile,
        string $title,
        string $cssPrefix = ''
    ): void {
        $body = $this->loadTemplate($contentRelPath);
        $body = $this->resolveLicenseTextWidgets($body);
        $html = $this->wrapPage($title, $body, $cssPrefix);
        file_put_contents($outputFile, $html);
    }

    /**
     * Generate /apps/index.html: the existing harvested app-list content
     * (real static prose, one section per app/bundle) with its
     * `<!-- WIDGET: download-icon app=X -->` placeholders resolved
     * against the component catalog.
     */
    public function generateAppsIndex(string $outputFile): void
    {
        $body = $this->loadTemplate('apps/index.html');
        $body = $this->resolveDownloadWidgets($body);
        $html = $this->wrapPage('Applications - Horde', $body, '../');
        file_put_contents($outputFile, $html);
    }

    /**
     * Generate one /apps/<slug>/index.html detail page: the app's
     * harvested body content (apps/<slug>/<slug>.html, plus an
     * apps/<slug>/approadmap.html section when the app has one, and an
     * apps/<slug>/appfaq.html "Can I do X?" cookbook section when the
     * app has one - IA §11), an optional "App facts" panel sourced from
     * the component catalog + the app's own .horde.yml (version,
     * authors, license, GitHub link) when available, wrapped in chrome.
     *
     * Facts are opportunistic, not required - several app slugs
     * (bundles like "sork", "webmail", meta-packages like "horde") have
     * no matching standalone repo/catalog entry, and are rendered with
     * just their harvested prose. Likewise, the FAQ section is only
     * authored for a handful of apps so far - see IA §11.
     */
    public function generateAppPage(string $slug, string $outputFile): void
    {
        $body = $this->loadTemplate("apps/{$slug}/{$slug}.html");
        $body = $this->resolveDownloadWidgets($body);

        $roadmapPath = "apps/{$slug}/approadmap.html";
        if (file_exists($this->templatesDir . '/' . $roadmapPath)) {
            $roadmap = $this->resolveDownloadWidgets($this->loadTemplate($roadmapPath));
            $body .= "\n\n<h2>Roadmap</h2>\n" . $roadmap;
        }

        $faqPath = "apps/{$slug}/appfaq.html";
        if (file_exists($this->templatesDir . '/' . $faqPath)) {
            $faq = $this->resolveDownloadWidgets($this->loadTemplate($faqPath));
            $body .= "\n\n<h2 id=\"faq\">FAQ</h2>\n" . $faq;
        }

        $componentMeta = $this->findComponent($slug);
        $hordeYml = $this->loadHordeYml($slug);
        $factsHtml = $this->renderAppFacts($componentMeta, $hordeYml);

        $title = ucfirst($slug) . ' - Horde Applications';
        $html = $this->wrapPage($title, $factsHtml . $body, '../../');
        file_put_contents($outputFile, $html);
    }

    /**
     * Discover app slugs from the content repo's apps/ subdirectory
     * listing (one directory per app, containing at least
     * apps/<slug>/<slug>.html) rather than the full component catalog,
     * since not every catalog entry is a user-facing www.horde.org app
     * (and not every app slug is a standalone catalog entry either -
     * see generateAppPage()'s docblock).
     *
     * @return string[]
     */
    public function discoverAppSlugs(): array
    {
        $appsDir = $this->templatesDir . '/apps';
        if (!is_dir($appsDir)) {
            return [];
        }

        $slugs = [];
        foreach (scandir($appsDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $entryPath = $appsDir . '/' . $entry;
            if (is_dir($entryPath) && file_exists($entryPath . '/' . $entry . '.html')) {
                $slugs[] = $entry;
            }
        }

        sort($slugs);
        return $slugs;
    }

    /**
     * Generate static stub pages for retired/moved URLs (Legacy URL
     * Policy, IA §6.5) - /libraries, /libraries/<name>,
     * /development/git, /development/documentation all move to
     * dev.horde.org. Same mechanism as dev.horde.org's own
     * PageGenerator::generateRedirectStubs(), duplicated here rather
     * than shared because the two generators' chrome/CSS-path
     * conventions differ.
     */
    public function generateRedirectStubs(string $redirectsFile, string $outputDir): int
    {
        if (!file_exists($redirectsFile)) {
            return 0;
        }

        $decoded = json_decode((string) file_get_contents($redirectsFile), true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Invalid redirects file (expected a JSON array): {$redirectsFile}");
        }
        if ($decoded === []) {
            return 0;
        }

        $count = 0;
        foreach ($decoded as $redirect) {
            if (!isset($redirect['from'], $redirect['to'])) {
                throw new RuntimeException(
                    "Redirect entry missing required 'from'/'to' keys: " . json_encode($redirect)
                );
            }

            $from = ltrim((string) $redirect['from'], '/');
            $to = (string) $redirect['to'];
            $reason = (string) ($redirect['reason'] ?? 'This content has moved.');

            $targetPath = $outputDir . '/' . $from;
            $targetDir = dirname($targetPath);
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0o755, true);
            }

            $depth = substr_count($from, '/');
            $cssPrefix = str_repeat('../', $depth);

            file_put_contents($targetPath, $this->buildRedirectStub($to, $reason, $cssPrefix));
            $count++;
        }

        return $count;
    }

    /**
     * Wrap arbitrary page-body HTML in the shared site chrome
     * (topbar.html + footer.html + stylesheet link).
     */
    private function wrapPage(string $title, string $body, string $cssPrefix): string
    {
        $topbar = $this->loadTemplate('topbar.html');
        $footer = $this->resolveFooterWidgets($this->loadTemplate('footer.html'));
        $titleEsc = $this->esc($title);
        $cssEsc = $this->esc($cssPrefix . $this->cssFilename);
        $statusJsEsc = $this->esc($cssPrefix . self::STATUS_WIDGET_JS_FILENAME);

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>{$titleEsc}</title>
                <link rel="stylesheet" type="text/css" href="{$cssEsc}">
            </head>
            <body>
            {$topbar}
            {$body}
            {$footer}
            <script src="{$statusJsEsc}" defer></script>
            </body>
            </html>
            HTML;
    }

    private function buildRedirectStub(string $to, string $reason, string $cssPrefix): string
    {
        $topbar = $this->loadTemplate('topbar.html');
        $footer = $this->resolveFooterWidgets($this->loadTemplate('footer.html'));
        $toEsc = $this->esc($to);
        $reasonEsc = $this->esc($reason);
        $cssEsc = $this->esc($cssPrefix . $this->cssFilename);
        $statusJsEsc = $this->esc($cssPrefix . self::STATUS_WIDGET_JS_FILENAME);

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <meta http-equiv="refresh" content="5; url={$toEsc}">
                <title>Page Moved - Horde</title>
                <link rel="stylesheet" type="text/css" href="{$cssEsc}">
            </head>
            <body>
            {$topbar}
                <div class="container">
                    <h1>This page has moved</h1>
                    <p>{$reasonEsc}</p>
                    <p>
                        You will be redirected automatically in a few seconds.
                        If not, please go to
                        <a href="{$toEsc}">{$toEsc}</a> directly.
                    </p>
                </div>
            {$footer}
            <script src="{$statusJsEsc}" defer></script>
            </body>
            </html>
            HTML;
    }

    /**
     * Resolve the footer's sponsor-slot widget with this build's
     * selected sponsor, if any (see setSponsorHtml()). Leaves the
     * footer's static fallback markup untouched when no sponsor was
     * selected (empty/missing roster).
     */
    private function resolveFooterWidgets(string $footer): string
    {
        if ($this->sponsorHtml === null) {
            return $footer;
        }
        return $this->replaceWidgetBlock($footer, 'sponsor-slot', $this->sponsorHtml);
    }

    /**
     * Replace a `<!-- WIDGET: download-icon app=SLUG --> ` comment with a
     * live "current release" link/icon sourced from the component
     * catalog, if a matching repo entry exists. Apps/bundles with no
     * standalone repo (e.g. "sork", "webmail") silently keep no badge.
     */
    private function resolveDownloadWidgets(string $html): string
    {
        return preg_replace_callback(
            '/<!--\s*WIDGET:\s*download-icon\s+app=([a-zA-Z0-9_-]+)\s*-->/',
            function (array $matches): string {
                $slug = $matches[1];
                $component = $this->findComponent($slug);
                if ($component === null) {
                    return '';
                }
                $version = (string) ($component['version'] ?? 'unknown');
                $hordeYml = $this->loadHordeYml($slug);
                if ($hordeYml !== null) {
                    $releaseVersion = $hordeYml->getReleaseVersion();
                    if ($releaseVersion) {
                        $version = $releaseVersion;
                    }
                }
                $versionEsc = $this->esc($version);
                $url = $this->esc((string) ($component['github_url'] ?? '#')) . '/releases';
                return "<a class=\"download-badge\" href=\"{$url}\" target=\"_blank\">Download {$versionEsc} →</a>";
            },
            $html
        ) ?? $html;
    }

    /**
     * Replace a `<!-- WIDGET: license-text source=RELATIVE/PATH --> `
     * comment with the verbatim, HTML-escaped contents of a legacy
     * license text file (COPYING, LGPL, LGPL-2.1, LICENSE, COPYRIGHT -
     * see content/pages/licenses/*.html). The source path is relative
     * to the horde-web repo root (e.g. "app/views/Licenses/COPYING"),
     * matching where these files have always lived - they are
     * unchanging legal text, not editorial content, so there's no
     * reason to duplicate them into content/pages.
     */
    private function resolveLicenseTextWidgets(string $html): string
    {
        return preg_replace_callback(
            '/<!--\s*WIDGET:\s*license-text\s+source=app\/views\/Licenses\/([A-Za-z0-9._-]+)\s*-->/',
            function (array $matches): string {
                $filename = $matches[1];
                $path = $this->legacyLicensesDir . '/' . $filename;
                if ($this->legacyLicensesDir === '' || !is_file($path)) {
                    return '[license text unavailable: ' . $this->esc($filename) . ']';
                }
                return $this->esc((string) file_get_contents($path));
            },
            $html
        ) ?? $html;
    }

    private function renderAppFacts(?array $componentMeta, ?HordeYmlFile $hordeYml): string
    {
        if ($componentMeta === null && $hordeYml === null) {
            return '';
        }

        $rows = '';

        if ($componentMeta !== null) {
            $version = (string) ($componentMeta['version'] ?? 'unknown');
            if ($hordeYml !== null) {
                $releaseVersion = $hordeYml->getReleaseVersion();
                if ($releaseVersion) {
                    $version = $releaseVersion;
                }
            }
            $versionEsc = $this->esc($version);
            $rows .= "    <div class=\"app-fact-row\"><strong>Current version:</strong> {$versionEsc}</div>\n";
            if (!empty($componentMeta['github_url'])) {
                $url = $this->esc((string) $componentMeta['github_url']);
                $rows .= "    <div class=\"app-fact-row\"><a href=\"{$url}\" target=\"_blank\">"
                    . "View source on GitHub →</a></div>\n";
            }
        }

        if ($hordeYml !== null) {
            $authors = [];
            foreach ($hordeYml->getAuthors() as $author) {
                if (isset($author['name'])) {
                    $authors[] = $this->esc($author['name'])
                        . (isset($author['role']) ? ' (' . $this->esc($author['role']) . ')' : '');
                }
            }
            if (!empty($authors)) {
                $rows .= '    <div class="app-fact-row"><strong>Authors:</strong> '
                    . implode(', ', $authors) . "</div>\n";
            }

            $licenseObj = $hordeYml->getLicense();
            if ($licenseObj && isset($licenseObj->identifier)) {
                $rows .= '    <div class="app-fact-row"><strong>License:</strong> '
                    . $this->esc($licenseObj->identifier) . "</div>\n";
            }
        }

        if ($rows === '') {
            return '';
        }

        return "<div class=\"app-facts\">\n{$rows}</div>\n\n";
    }

    private function findComponent(string $slug): ?array
    {
        $components = $this->loadComponentMetadata();
        $needle = strtolower($slug);
        foreach ($components as $component) {
            $parts = explode('/', (string) $component['name']);
            $repoName = strtolower(end($parts));
            if ($repoName === $needle) {
                return $component;
            }
        }
        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadComponentMetadata(): array
    {
        if ($this->components !== null) {
            return $this->components;
        }
        if (!file_exists($this->componentsFile)) {
            $this->components = [];
            return $this->components;
        }
        $json = file_get_contents($this->componentsFile);
        $data = json_decode((string) $json, true);
        $this->components = is_array($data) ? $data : [];
        return $this->components;
    }

    private function loadHordeYml(string $repoName): ?HordeYmlFile
    {
        if ($this->gitDir === null) {
            return null;
        }
        $path = "{$this->gitDir}/{$this->organization}/{$repoName}/.horde.yml";
        if (file_exists($path)) {
            try {
                return new HordeYmlFile($path);
            } catch (Exception $e) {
                return null;
            }
        }
        return null;
    }

    private function loadTemplate(string $relativePath): string
    {
        $path = $this->templatesDir . '/' . $relativePath;
        if (!file_exists($path)) {
            throw new RuntimeException("Template not found: {$path}");
        }
        return file_get_contents($path);
    }

    /**
     * Replace a `<div ... data-widget="$name" ...> ... </div>` block's
     * inner content with $replacement, leaving the wrapping element and
     * its attributes intact (so CSS classes/data attributes are
     * preserved for styling, only the placeholder markup inside changes).
     */
    /**
     * Replace a `<div ... data-widget="$name" ...> ... </div>` block's
     * inner content with $replacement, leaving the wrapping element and
     * its attributes intact (so CSS classes/data attributes are
     * preserved for styling, only the placeholder markup inside changes).
     *
     * Nesting-aware: the fallback content inside a widget block may
     * itself contain nested `<div>`s (e.g. the sponsor-slot's
     * `.sponsor-card`/`.sponsor-logo` fallback), so a naive non-greedy
     * regex up to the *first* `</div>` would truncate mid-block. This
     * scans forward counting div open/close tags to find the actual
     * matching closing tag for the widget's own wrapping div.
     */
    private function replaceWidgetBlock(string $html, string $widgetName, string $replacement): string
    {
        if (!preg_match(
            '/<div[^>]*data-widget="' . preg_quote($widgetName, '/') . '"[^>]*>/',
            $html,
            $openMatch,
            PREG_OFFSET_CAPTURE
        )) {
            return $html;
        }

        $openTag = $openMatch[0][0];
        $openTagStart = $openMatch[0][1];
        $contentStart = $openTagStart + strlen($openTag);

        if (!preg_match_all('/<div\b[^>]*>|<\/div>/i', $html, $tagMatches, PREG_OFFSET_CAPTURE, $contentStart)) {
            return $html;
        }

        $depth = 1;
        $closeTagStart = null;
        $closeTagEnd = null;
        foreach ($tagMatches[0] as [$tag, $offset]) {
            if (stripos($tag, '</div>') === 0) {
                $depth--;
                if ($depth === 0) {
                    $closeTagStart = $offset;
                    $closeTagEnd = $offset + strlen($tag);
                    break;
                }
            } else {
                $depth++;
            }
        }

        if ($closeTagStart === null) {
            // Unbalanced markup - bail out rather than corrupt the page.
            return $html;
        }

        return substr($html, 0, $contentStart)
            . "\n" . $replacement . "\n"
            . substr($html, $closeTagStart, $closeTagEnd - $closeTagStart)
            . substr($html, $closeTagEnd);
    }

    private function esc(string $str): string
    {
        return htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
