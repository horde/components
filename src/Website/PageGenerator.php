<?php

/**
 * Full page generator for dev.horde.org
 *
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

declare(strict_types=1);

namespace Horde\Components\Website;

use RuntimeException;

/**
 * Full page generator for dev.horde.org
 */
class PageGenerator
{
    private string $templatesDir;
    private string $cssFilename;
    private string $componentsFile;

    public function __construct(string $templatesDir, string $cssFilename, ?string $componentsFile = null)
    {
        $this->templatesDir = rtrim($templatesDir, '/');
        $this->cssFilename = $cssFilename;
        $this->componentsFile = $componentsFile ?? $this->templatesDir . '/components.json';
    }

    /**
     * Generate complete page
     */
    public function generatePage(
        array $events,
        string $outputFile,
        int $maxEvents = 10,
        int $maxComponentEvents = 2
    ): void {
        // Load templates
        $topbar = $this->loadTemplate('topbar.html');
        $staticSections = $this->loadTemplate('static-sections.html');
        $archiveSection = $this->loadTemplate('archive-section.html');
        $searchScript = $this->loadTemplate('search-script.html');

        // Load component metadata
        $components = $this->loadComponentMetadata();

        // Group events
        $byType = $this->groupByType($events);
        $byComponent = $this->groupByComponent($events);

        // Generate sections
        $timestamp = date('Y-m-d H:i:s');
        $issuesHtml = $this->renderEventSection($byType['issue'] ?? [], $maxEvents);
        $releasesHtml = $this->renderEventSection($byType['release'] ?? [], $maxEvents);
        $pushesHtml = $this->renderEventSection($byType['push'] ?? [], $maxEvents);
        $prsHtml = $this->renderEventSection($byType['pull_request'] ?? [], $maxEvents);
        $componentDirectory = $this->renderComponentDirectory($components, $byComponent, $maxComponentEvents);

        // Build complete page
        $html = $this->buildFullPage(
            $topbar,
            $timestamp,
            $issuesHtml,
            $releasesHtml,
            $pushesHtml,
            $prsHtml,
            $staticSections,
            $archiveSection,
            $componentDirectory,
            $searchScript
        );

        file_put_contents($outputFile, $html);

        // Generate component-specific pages for ALL components in catalog
        $outputDir = dirname($outputFile);
        $this->generateComponentPages($outputDir, $components, $byComponent);
    }

    private function loadTemplate(string $filename): string
    {
        $path = $this->templatesDir . '/' . $filename;
        if (!file_exists($path)) {
            throw new RuntimeException("Template not found: {$path}");
        }
        return file_get_contents($path);
    }

    private function loadComponentMetadata(): array
    {
        if (!file_exists($this->componentsFile)) {
            throw new RuntimeException("Component metadata not found: {$this->componentsFile}");
        }
        $json = file_get_contents($this->componentsFile);
        $data = json_decode($json, true);
        if ($data === null) {
            throw new RuntimeException("Invalid JSON in {$this->componentsFile}");
        }
        return $data;
    }

    private function loadHordeYml(string $componentName): ?array
    {
        // Try to find .horde.yml in git checkout
        // Component name format: "horde/ComponentName"
        $parts = explode('/', $componentName);
        if (count($parts) !== 2) {
            return null;
        }

        $repoName = $parts[1];

        // Try common locations
        $homeDir = getenv('HOME') ?: '/home/i567442';
        $possiblePaths = [
            "{$homeDir}/git/horde/{$repoName}/.horde.yml",
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                try {
                    // Use Horde_Yaml (PEAR-style) to parse
                    if (class_exists('Horde_Yaml')) {
                        return \Horde_Yaml::loadFile($path);
                    }
                    // Fallback: return null if Yaml not available
                    return null;
                } catch (\Exception $e) {
                    // Ignore parse errors, return null
                    return null;
                }
            }
        }

        return null;
    }

    private function groupByType(array $events): array
    {
        $grouped = [
            'issue' => [],
            'release' => [],
            'push' => [],
            'pull_request' => [],
        ];

        foreach ($events as $event) {
            $type = $event->type;
            if (isset($grouped[$type])) {
                $grouped[$type][] = $event;
            }
        }

        return $grouped;
    }

    private function groupByComponent(array $events): array
    {
        $grouped = [];

        foreach ($events as $event) {
            $repo = $event->repo;
            if (!isset($grouped[$repo])) {
                $grouped[$repo] = [];
            }
            $grouped[$repo][] = $event;
        }

        ksort($grouped);
        return $grouped;
    }

    private function renderEventSection(array $events, int $maxEvents): string
    {
        if (empty($events)) {
            return "                    <div class=\"event-item empty\">No recent activity found.</div>\n";
        }

        $html = '';
        $displayEvents = array_slice($events, 0, $maxEvents);

        foreach ($displayEvents as $event) {
            $html .= $this->renderEventCard($event);
        }

        return $html;
    }

    private function renderEventCard(Event $event): string
    {
        $titleEsc = $this->esc($event->title);
        $repoEsc = $this->esc($event->repo);
        $actionEsc = $this->esc($event->action);
        $actorEsc = $this->esc($event->actor);
        $urlEsc = $this->esc($event->url);
        $timestampEsc = $this->esc($event->timestamp->format('Y-m-d H:i'));

        // Generate safe filename for component link
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $event->repo);
        $componentLink = "components/{$safeName}.html";

        return <<<HTML
                                <div class="event-item type-{$event->type}">
                                    <div class="event-title">{$titleEsc}</div>
                                    <div class="event-meta">
                                        <a href="{$componentLink}" class="event-repo">{$repoEsc}</a>
                                        <span class="event-badge">{$actionEsc}</span>
                                        <span>{$timestampEsc}</span>
                                        <span>by {$actorEsc}</span>
                                        <a href="{$urlEsc}" target="_blank">view →</a>
                                    </div>
                                </div>

            HTML;
    }

    private function renderComponentDirectory(array $components, array $eventsByComponent, int $maxEvents): string
    {
        $totalCount = count($components);

        $html = <<<HTML
                    <!-- Component Directory Section -->
                    <h2>Component Directory</h2>

                    <div class="component-search">
                        <input type="text" id="componentFilter" placeholder="Search components... (e.g., 'hordectl', 'core', 'mail')" class="search-input">
                        <span class="search-info" id="searchInfo">Showing <strong>{$totalCount}</strong> of <strong>{$totalCount}</strong> components</span>
                    </div>

                    <div class="component-directory" id="componentDirectory">

            HTML;

        foreach ($components as $component) {
            $html .= $this->renderComponentCard($component, $eventsByComponent[$component['name']] ?? [], $maxEvents);
        }

        $html .= "        </div>\n";

        return $html;
    }

    private function renderComponentCard(array $component, array $recentEvents, int $maxEvents): string
    {
        $nameEsc = $this->esc($component['name']);
        $versionEsc = $this->esc($component['version']);
        $descEsc = $this->esc($component['description']);
        $githubUrl = $this->esc($component['github_url']);
        $dataComponent = strtolower($nameEsc);

        // Get last N events
        $displayEvents = array_slice($recentEvents, 0, $maxEvents);

        $activityHtml = '';
        if (empty($displayEvents)) {
            $activityHtml = "                    <div class=\"component-activity-item\">\n";
            $activityHtml .= "                        <span class=\"activity-text\">No recent activity</span>\n";
            $activityHtml .= "                    </div>\n";
        } else {
            foreach ($displayEvents as $event) {
                $icon = match ($event->type) {
                    'issue' => '📝',
                    'release' => '📦',
                    'push' => '🔀',
                    'pull_request' => '🔁',
                    'pull_request_review' => '👁️',
                    'pull_request_review_comment' => '💬',
                    'create' => '🌱',
                    'delete' => '🗑️',
                    'fork' => '🍴',
                    'repository' => '📁',
                    'comment' => '💬',
                    'issue_comment' => '💬',
                    default => '📌'
                };

                $title = $this->esc($this->truncate($event->title, 50));
                $time = $this->esc($event->timestamp->format('Y-m-d'));

                $activityHtml .= "                    <div class=\"component-activity-item\">\n";
                $activityHtml .= "                        <span class=\"activity-icon type-{$event->type}\">{$icon}</span>\n";
                $activityHtml .= "                        <span class=\"activity-text\">{$title}</span>\n";
                $activityHtml .= "                        <span class=\"activity-time\">{$time}</span>\n";
                $activityHtml .= "                    </div>\n";
            }
        }

        // Generate safe filename for links
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $component['name']);

        return <<<HTML
                        <div class="component-card" data-component="{$dataComponent}">
                            <div class="component-header">
                                <h3 class="component-name">{$nameEsc}</h3>
                                <span class="component-version">{$versionEsc}</span>
                            </div>
                            <p class="component-description">{$descEsc}</p>

                            <div class="component-recent">
                                <h4>Recent Activity</h4>
            {$activityHtml}                </div>

                            <div class="component-links">
                                <a href="{$githubUrl}" target="_blank" class="component-link">GitHub →</a>
                                <a href="api/{$safeName}/" class="component-link">API Docs →</a>
                                <a href="components/{$safeName}.html" class="component-link">Full Details →</a>
                            </div>
                        </div>

            HTML;
    }

    private function buildFullPage(
        string $topbar,
        string $timestamp,
        string $issuesHtml,
        string $releasesHtml,
        string $pushesHtml,
        string $prsHtml,
        string $staticSections,
        string $archiveSection,
        string $componentDirectory,
        string $searchScript
    ): string {
        $cssEsc = $this->esc($this->cssFilename);

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Horde Development Resources</title>
                <link rel="stylesheet" type="text/css" href="{$cssEsc}">
            </head>
            <body>
            {$topbar}
                <div class="container">
                    <h1>Horde Development Resources</h1>

                    <!-- Activity Section -->
                    <h2>Activities</h2>
                    <div class="activity-updated">Last updated: {$timestamp}</div>

                    <!-- Responsive Grid: 1 column mobile, 2 columns tablet+, 3/4 columns large screens -->
                    <div class="activity-grid">
                        <div class="activity-section">
                            <h2>📝 Last 10 Issues</h2>
                            <div class="event-list">
            {$issuesHtml}                </div>
                        </div>

                        <div class="activity-section">
                            <h2>📦 Last 10 Releases</h2>
                            <div class="event-list">
            {$releasesHtml}                </div>
                        </div>

                        <div class="activity-section">
                            <h2>🔀 Last 10 Pushes</h2>
                            <div class="event-list">
            {$pushesHtml}                </div>
                        </div>

                        <div class="activity-section">
                            <h2>🔁 Last 10 Pull Requests</h2>
                            <div class="event-list">
            {$prsHtml}                </div>
                        </div>
                    </div>

            {$staticSections}

            {$archiveSection}

            {$componentDirectory}    </div>

            {$searchScript}
            </body>
            </html>
            HTML;
    }

    private function generateComponentPages(string $outputDir, array $components, array $byComponent): void
    {
        $componentDir = $outputDir . '/components';
        if (!is_dir($componentDir)) {
            mkdir($componentDir, 0o755, true);
        }

        // Generate page for EVERY component in catalog, not just those with events
        foreach ($components as $component) {
            $componentName = $component['name'];
            $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $componentName);
            $filePath = $componentDir . '/' . $safeName . '.html';

            // Get events for this component (empty array if none)
            $componentEvents = $byComponent[$componentName] ?? [];

            // Load .horde.yml data if available
            $hordeYml = $this->loadHordeYml($componentName);

            // Generate component page with full details
            $html = $this->renderComponentPage($component, $hordeYml, $componentEvents);
            file_put_contents($filePath, $html);
        }
    }

    private function renderComponentPage(array $componentMeta, ?array $hordeYml, array $events): string
    {
        $componentName = $componentMeta['name'];
        $componentEsc = $this->esc($componentName);

        // Render component details card
        $detailsCard = $this->renderComponentDetailsCard($componentMeta, $hordeYml);

        // Render events
        $eventsHtml = '';
        $displayEvents = array_slice($events, 0, 50); // Show up to 50 events
        foreach ($displayEvents as $event) {
            $eventsHtml .= $this->renderEventCard($event);
        }

        if (empty($eventsHtml)) {
            $eventsHtml = "<div class=\"event-item empty\">No recent activity found.</div>\n";
        }

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>{$componentEsc} - Horde Development</title>
                <link rel="stylesheet" type="text/css" href="../{$this->cssFilename}">
            </head>
            <body>
                <div class="container">
                    <div class="back-link"><a href="../index.html">← Back to dev.horde.org</a></div>
                    <h1>{$componentEsc}</h1>

            {$detailsCard}

                    <div class="activity-section">
                        <h2>Recent Activity</h2>
                        <div class="event-list">
            {$eventsHtml}            </div>
                    </div>
                </div>
            </body>
            </html>
            HTML;
    }

    private function renderComponentDetailsCard(array $componentMeta, ?array $hordeYml): string
    {
        $name = $this->esc($componentMeta['name']);
        $version = $this->esc($componentMeta['version'] ?? 'unknown');
        $description = $this->esc($componentMeta['description'] ?? 'No description available');
        $githubUrl = $this->esc($componentMeta['github_url'] ?? '#');

        // Extract data from .horde.yml if available
        $fullDesc = null;
        $license = null;
        $authors = [];
        $dependencies = [];

        if ($hordeYml !== null) {
            // Use version from .horde.yml if available
            if (isset($hordeYml['version']['release'])) {
                $version = $this->esc($hordeYml['version']['release']);
            }

            // Full description
            if (isset($hordeYml['description'])) {
                $fullDesc = $this->esc($hordeYml['description']);
            } elseif (isset($hordeYml['full'])) {
                $fullDesc = $this->esc($hordeYml['full']);
            }

            // License
            if (isset($hordeYml['license']['identifier'])) {
                $license = $this->esc($hordeYml['license']['identifier']);
                $licenseUri = $hordeYml['license']['uri'] ?? null;
            }

            // Authors
            if (isset($hordeYml['authors']) && is_array($hordeYml['authors'])) {
                foreach ($hordeYml['authors'] as $author) {
                    if (isset($author['name'])) {
                        $authors[] = $this->esc($author['name'])
                            . (isset($author['role']) ? ' (' . $this->esc($author['role']) . ')' : '');
                    }
                }
            }

            // Dependencies
            if (isset($hordeYml['dependencies']['required']['composer'])) {
                foreach ($hordeYml['dependencies']['required']['composer'] as $pkg => $ver) {
                    $dependencies[] = $this->esc($pkg) . ': ' . $this->esc($ver);
                }
            }
        }

        $html = '<div class="component-details-card">' . "\n";
        $html .= '  <div class="component-detail-row">' . "\n";
        $html .= '    <strong>Version:</strong> ' . $version . "\n";
        $html .= '  </div>' . "\n";

        if ($fullDesc) {
            $html .= '  <div class="component-detail-row">' . "\n";
            $html .= '    <strong>Description:</strong> ' . $fullDesc . "\n";
            $html .= '  </div>' . "\n";
        }

        if ($license) {
            $html .= '  <div class="component-detail-row">' . "\n";
            $html .= '    <strong>License:</strong> ' . $license;
            if (isset($licenseUri)) {
                $html .= ' (<a href="' . $this->esc($licenseUri) . '" target="_blank">view</a>)';
            }
            $html .= "\n  </div>\n";
        }

        if (!empty($authors)) {
            $html .= '  <div class="component-detail-row">' . "\n";
            $html .= '    <strong>Authors:</strong> ' . implode(', ', $authors) . "\n";
            $html .= '  </div>' . "\n";
        }

        if (!empty($dependencies)) {
            $html .= '  <div class="component-detail-row">' . "\n";
            $html .= '    <strong>Dependencies:</strong><br>' . "\n";
            $html .= '    <ul class="dependency-list">' . "\n";
            foreach ($dependencies as $dep) {
                $html .= '      <li>' . $dep . '</li>' . "\n";
            }
            $html .= '    </ul>' . "\n";
            $html .= '  </div>' . "\n";
        }

        $html .= '  <div class="component-detail-row">' . "\n";
        $html .= '    <a href="' . $githubUrl . '" target="_blank" class="component-link">View on GitHub →</a>' . "\n";
        $html .= '  </div>' . "\n";
        $html .= '</div>' . "\n\n";

        return $html;
    }

    private function truncate(string $text, int $length): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        return mb_substr($text, 0, $length) . '...';
    }

    private function esc(string $str): string
    {
        return htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
