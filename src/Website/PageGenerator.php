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

        // Also generate component-specific pages
        $this->generateComponentPages($outputFile, $events, $byComponent);
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
                $icon = match($event->type) {
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

    private function generateComponentPages(string $mainOutputFile, array $allEvents, array $byComponent): void
    {
        $outputDir = dirname($mainOutputFile);
        $componentDir = $outputDir . '/components';
        if (!is_dir($componentDir)) {
            mkdir($componentDir, 0755, true);
        }

        foreach ($byComponent as $component => $componentEvents) {
            $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $component);
            $filePath = $componentDir . '/' . $safeName . '.html';

            // Generate simple component page (reuse existing logic if needed)
            $html = $this->renderSimpleComponentPage($component, $componentEvents);
            file_put_contents($filePath, $html);
        }
    }

    private function renderSimpleComponentPage(string $component, array $events): string
    {
        $componentEsc = $this->esc($component);
        $eventsHtml = '';

        $displayEvents = array_slice($events, 0, 20);
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
