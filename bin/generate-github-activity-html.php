#!/usr/bin/env php
<?php
/**
 * GitHub Webhook Activity HTML Generator
 *
 * Generates modern, Material-inspired HTML summaries of GitHub activity.
 * Uses external dev.horde.org.css for styling (no inline styles).
 *
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * Usage:
 *   php generate-github-activity-html.php [options]
 *
 * Options:
 *   --input <dir>       Source directory containing webhook JSON files (required)
 *   --output <dir>      Output directory for HTML files (required)
 *   --max-main <n>      Maximum events per section on main page (default: 10)
 *   --max-component <n> Maximum events per component page (default: 10)
 *   --fragment-main     Generate main index as HTML fragment (no DOCTYPE/html/head)
 *   --fragment-all      Generate all pages as HTML fragments
 *   --help              Show this help message
 *
 * Examples:
 *   # Full HTML pages
 *   php generate-github-activity-html.php --input /horde/json --output /var/www/updates
 *
 *   # Main page as fragment for embedding (expects parent page to have CSS)
 *   php generate-github-activity-html.php --input /horde/json --output /var/www --fragment-main
 *
 *   # All pages as fragments
 *   php generate-github-activity-html.php --input /horde/json --output /var/www --fragment-all
 *
 * Output:
 *   <output>/activities.html - Main activity page
 *   <output>/components/*.html - Per-component pages
 *
 * IMPORTANT: Requires dev.horde.org.css in the output directory
 */

declare(strict_types=1);

/**
 * Normalized event representation
 */
class Event
{
    public function __construct(
        public string $type,           // "issue", "release", "push", "comment"
        public string $action,         // "opened", "published", "closed"
        public string $repo,           // "horde/components"
        public string $title,          // Human-readable title
        public string $url,            // GitHub URL
        public DateTime $timestamp,    // When it happened
        public string $actor,          // Username who triggered it
        public ?string $summary = null // Brief description
    ) {}
}

/**
 * Scans hook directory for webhook events
 */
class EventScanner
{
    private array $errors = [];

    public function __construct(private string $baseDir) {}

    /**
     * Scan directory and return all events
     */
    public function scan(): array
    {
        if (!is_dir($this->baseDir)) {
            throw new RuntimeException("Input directory not found: {$this->baseDir}");
        }

        $events = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->baseDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'json') {
                continue;
            }

            try {
                $event = $this->loadEvent($file->getPathname());
                if ($event !== null) {
                    $events[] = $event;
                }
            } catch (Exception $e) {
                $this->errors[] = "Error loading {$file->getPathname()}: {$e->getMessage()}";
            }
        }

        // Sort by timestamp DESC (newest first)
        usort($events, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);

        return $events;
    }

    /**
     * Load and parse a single event file
     */
    private function loadEvent(string $filePath): ?array
    {
        $contents = file_get_contents($filePath);
        if ($contents === false) {
            return null;
        }

        $json = json_decode($contents, true);
        if ($json === null) {
            throw new RuntimeException("Invalid JSON in file");
        }

        // Extract metadata from path relative to base directory
        $metadata = $this->extractMetadata($filePath);

        // Extract timestamp from filename
        $filename = basename($filePath, '.json');
        $timestamp = $this->parseTimestamp($filename);

        return [
            'json' => $json,
            'metadata' => $metadata,
            'timestamp' => $timestamp,
            'file_path' => $filePath,
        ];
    }

    /**
     * Extract event type, action, org, repo from file path
     *
     * Expected structure relative to base dir:
     *   {type}/{action}/{org}/{repo}/YYYY-MM-DD.HH:MM:SS.json
     *
     * Examples:
     *   issues/opened/horde/components/2026-03-01.08:49:44.json
     *   release/published/horde/hordectl/2026-03-01.07:29:14.json
     *   push/other/horde/components/2026-02-28.09:17:34.json
     */
    private function extractMetadata(string $filePath): array
    {
        // Get path relative to base directory
        $relativePath = str_replace($this->baseDir, '', $filePath);
        $relativePath = ltrim($relativePath, '/');

        $parts = explode('/', $relativePath);

        // We expect at least: type/action/org/repo/file.json (5 parts)
        if (count($parts) < 5) {
            return [
                'type' => 'unknown',
                'action' => 'unknown',
                'org' => 'unknown',
                'repo' => 'unknown',
            ];
        }

        return [
            'type' => $parts[0],     // issues, release, push, etc.
            'action' => $parts[1],   // opened, published, other
            'org' => $parts[2],      // horde
            'repo' => $parts[3],     // components
        ];
    }

    /**
     * Parse timestamp from filename (YYYY-MM-DD.HH:MM:SS)
     */
    private function parseTimestamp(string $filename): DateTime
    {
        // Format: 2026-03-01.08:49:44
        $parts = explode('.', $filename);
        if (count($parts) >= 2) {
            $dateStr = $parts[0] . ' ' . str_replace(':', ':', $parts[1]);
            try {
                return new DateTime($dateStr);
            } catch (Exception $e) {
                // Fall back to current time
            }
        }

        return new DateTime();
    }

    /**
     * Get any errors that occurred during scanning
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}

/**
 * Normalizes raw webhook events to Event objects
 */
class EventNormalizer
{
    /**
     * Normalize a raw event to an Event object
     */
    public function normalize(array $rawEvent): ?Event
    {
        $type = $rawEvent['metadata']['type'];
        $json = $rawEvent['json'];
        $timestamp = $rawEvent['timestamp'];

        return match ($type) {
            'issues' => $this->normalizeIssue($json, $rawEvent['metadata'], $timestamp),
            'release' => $this->normalizeRelease($json, $rawEvent['metadata'], $timestamp),
            'push' => $this->normalizePush($json, $rawEvent['metadata'], $timestamp),
            'issue_comment' => $this->normalizeComment($json, $rawEvent['metadata'], $timestamp),
            'create' => $this->normalizeCreate($json, $rawEvent['metadata'], $timestamp),
            default => null, // Skip ping, label, unknown, etc.
        };
    }

    private function normalizeIssue(array $json, array $meta, DateTime $timestamp): Event
    {
        $issue = $json['issue'] ?? [];
        $repo = $json['repository']['full_name'] ?? ($meta['org'] . '/' . $meta['repo']);

        return new Event(
            type: 'issue',
            action: $meta['action'],
            repo: $repo,
            title: "Issue #{$issue['number']}: {$issue['title']}",
            url: $issue['html_url'] ?? '#',
            timestamp: $timestamp,
            actor: $issue['user']['login'] ?? 'unknown',
            summary: null
        );
    }

    private function normalizeRelease(array $json, array $meta, DateTime $timestamp): Event
    {
        $release = $json['release'] ?? [];
        $repo = $json['repository']['full_name'] ?? ($meta['org'] . '/' . $meta['repo']);

        $summary = null;
        if ($release['prerelease'] ?? false) {
            $summary = 'Pre-release';
        } elseif ($release['draft'] ?? false) {
            $summary = 'Draft';
        }

        return new Event(
            type: 'release',
            action: $meta['action'],
            repo: $repo,
            title: "{$release['name']} ({$release['tag_name']})",
            url: $release['html_url'] ?? '#',
            timestamp: $timestamp,
            actor: $release['author']['login'] ?? 'unknown',
            summary: $summary
        );
    }

    private function normalizePush(array $json, array $meta, DateTime $timestamp): Event
    {
        $repo = $json['repository']['full_name'] ?? ($meta['org'] . '/' . $meta['repo']);
        $branch = str_replace('refs/heads/', '', $json['ref'] ?? 'unknown');
        $commits = $json['commits'] ?? [];
        $commitCount = count($commits);

        $title = match (true) {
            $json['deleted'] ?? false => "Branch {$branch} deleted",
            $json['created'] ?? false => "Branch {$branch} created",
            $commitCount > 0 => "{$commitCount} commit" . ($commitCount > 1 ? 's' : '') . " to {$branch}",
            default => "Push to {$branch}",
        };

        return new Event(
            type: 'push',
            action: 'push',
            repo: $repo,
            title: $title,
            url: $json['compare'] ?? '#',
            timestamp: $timestamp,
            actor: $json['pusher']['name'] ?? 'unknown',
            summary: null
        );
    }

    private function normalizeComment(array $json, array $meta, DateTime $timestamp): Event
    {
        $issue = $json['issue'] ?? [];
        $comment = $json['comment'] ?? [];
        $repo = $json['repository']['full_name'] ?? ($meta['org'] . '/' . $meta['repo']);

        return new Event(
            type: 'comment',
            action: $meta['action'],
            repo: $repo,
            title: "Comment on issue #{$issue['number']}: {$issue['title']}",
            url: $comment['html_url'] ?? $issue['html_url'] ?? '#',
            timestamp: $timestamp,
            actor: $comment['user']['login'] ?? 'unknown',
            summary: null
        );
    }

    private function normalizeCreate(array $json, array $meta, DateTime $timestamp): Event
    {
        $repo = $json['repository']['full_name'] ?? ($meta['org'] . '/' . $meta['repo']);
        $refType = $json['ref_type'] ?? 'ref';
        $ref = $json['ref'] ?? 'unknown';

        return new Event(
            type: 'create',
            action: 'created',
            repo: $repo,
            title: ucfirst($refType) . " {$ref} created",
            url: $json['repository']['html_url'] ?? '#',
            timestamp: $timestamp,
            actor: $json['sender']['login'] ?? 'unknown',
            summary: null
        );
    }
}

/**
 * Generates HTML pages from normalized events
 */
class HtmlGenerator
{
    public function __construct(
        private string $outputDir,
        private bool $fragmentMain = false,
        private bool $fragmentComponents = false
    ) {}

    /**
     * Generate main index page
     */
    public function generateMainIndex(array $events, int $maxEvents): void
    {
        // Group events by type
        $byType = $this->groupByType($events);

        $html = $this->renderMainPage($byType, $maxEvents);

        $this->ensureOutputDir();
        file_put_contents($this->outputDir . '/activities.html', $html);
    }

    /**
     * Generate per-component pages
     */
    public function generateComponentPages(array $events, int $maxEvents): void
    {
        // Group events by component
        $byComponent = $this->groupByComponent($events);

        $this->ensureOutputDir();
        $componentDir = $this->outputDir . '/components';
        if (!is_dir($componentDir)) {
            mkdir($componentDir, 0755, true);
        }

        foreach ($byComponent as $component => $componentEvents) {
            $html = $this->renderComponentPage($component, $componentEvents, $maxEvents);
            $safeName = $this->safeFilename($component);
            file_put_contents($componentDir . '/' . $safeName . '.html', $html);
        }
    }

    private function groupByType(array $events): array
    {
        $grouped = [
            'issue' => [],
            'release' => [],
            'push' => [],
            'comment' => [],
            'create' => [],
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

        // Sort by repo name
        ksort($grouped);

        return $grouped;
    }

    private function renderMainPage(array $byType, int $maxEvents): string
    {
        $timestamp = date('Y-m-d H:i:s');

        $issuesHtml = $this->renderEventSection($byType['issue'], $maxEvents);
        $releasesHtml = $this->renderEventSection($byType['release'], $maxEvents);
        $pushesHtml = $this->renderEventSection($byType['push'], $maxEvents);

        // Get unique repositories for component list
        $allEvents = array_merge(...array_values($byType));
        $repos = array_unique(array_map(fn($e) => $e->repo, $allEvents));
        sort($repos);

        $repoListHtml = '';
        foreach ($repos as $repo) {
            $safeName = $this->safeFilename($repo);
            $repoListHtml .= "<li><a href=\"components/{$safeName}.html\">{$this->esc($repo)}</a></li>\n";
        }

        $contentHtml = <<<CONTENT
<div class="activity-updated">Last updated: {$timestamp}</div>

<div class="activity-section">
<h2>📝 Last {$maxEvents} Issues</h2>
<div class="event-list">
{$issuesHtml}
</div>
</div>

<div class="activity-section">
<h2>📦 Last {$maxEvents} Releases</h2>
<div class="event-list">
{$releasesHtml}
</div>
</div>

<div class="activity-section">
<h2>🔀 Last {$maxEvents} Pushes</h2>
<div class="event-list">
{$pushesHtml}
</div>
</div>

<div class="activity-section">
<h2>📂 Activity by Component</h2>
<ul class="component-grid">
{$repoListHtml}
</ul>
</div>
CONTENT;

        if ($this->fragmentMain) {
            // Fragment mode: just return content (expects external CSS)
            return $contentHtml;
        }

        // Full HTML page with link to external CSS
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Horde Development Resources</title>
    <link rel="stylesheet" type="text/css" href="dev.horde.org.css">
</head>
<body>
    <div class="container">
        <h1>Horde Development Resources</h1>
{$contentHtml}
    </div>
</body>
</html>
HTML;
    }

    private function renderComponentPage(string $component, array $events, int $maxEvents): string
    {
        $timestamp = date('Y-m-d H:i:s');
        $componentEsc = $this->esc($component);

        // Take only the first N events
        $displayEvents = array_slice($events, 0, $maxEvents);

        $eventsHtml = '';
        if (empty($displayEvents)) {
            $eventsHtml = "<div class=\"event-item empty\">No recent activity found.</div>\n";
        } else {
            foreach ($displayEvents as $event) {
                $eventsHtml .= $this->renderEventCard($event);
            }
        }

        $contentHtml = <<<CONTENT
<div class="back-link"><a href="../activities.html">← Back to activity feed</a></div>
<h1>{$componentEsc}</h1>
<div class="activity-updated">Last updated: {$timestamp}</div>

<div class="activity-section">
<h2>Recent Activity (Last {$maxEvents})</h2>
<div class="event-list">
{$eventsHtml}
</div>
</div>
CONTENT;

        if ($this->fragmentComponents) {
            return $contentHtml;
        }

        // Full HTML page with link to external CSS
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$componentEsc} - Activity</title>
    <link rel="stylesheet" type="text/css" href="../dev.horde.org.css">
</head>
<body>
    <div class="container">
{$contentHtml}
    </div>
</body>
</html>
HTML;
    }

    private function renderEventSection(array $events, int $maxEvents): string
    {
        if (empty($events)) {
            return "<div class=\"event-item empty\">No recent activity found.</div>\n";
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

        return <<<HTML
<div class="event-item type-{$event->type}">
    <div class="event-title">{$titleEsc}</div>
    <div class="event-meta">
        <span class="event-repo">{$repoEsc}</span>
        <span class="event-badge">{$actionEsc}</span>
        <span>{$timestampEsc}</span>
        <span>by {$actorEsc}</span>
        <a href="{$urlEsc}" target="_blank">view →</a>
    </div>
</div>

HTML;
    }

    private function ensureOutputDir(): void
    {
        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, true);
        }
    }

    private function safeFilename(string $name): string
    {
        // Replace slashes and special chars
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
    }

    private function esc(string $str): string
    {
        return htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

/**
 * Parse command-line arguments
 */
function parseArgs(array $argv): array
{
    $config = [
        'input' => null,
        'output' => null,
        'max_main' => 10,
        'max_component' => 10,
        'fragment_main' => false,
        'fragment_all' => false,
        'help' => false,
    ];

    for ($i = 1; $i < count($argv); $i++) {
        switch ($argv[$i]) {
            case '--input':
                if (!isset($argv[$i + 1])) {
                    fwrite(STDERR, "ERROR: --input requires a directory argument\n");
                    exit(1);
                }
                $config['input'] = rtrim($argv[$i + 1], '/');
                $i++;
                break;

            case '--output':
                if (!isset($argv[$i + 1])) {
                    fwrite(STDERR, "ERROR: --output requires a directory argument\n");
                    exit(1);
                }
                $config['output'] = rtrim($argv[$i + 1], '/');
                $i++;
                break;

            case '--max-main':
                if (!isset($argv[$i + 1]) || !is_numeric($argv[$i + 1])) {
                    fwrite(STDERR, "ERROR: --max-main requires a numeric argument\n");
                    exit(1);
                }
                $config['max_main'] = (int)$argv[$i + 1];
                $i++;
                break;

            case '--max-component':
                if (!isset($argv[$i + 1]) || !is_numeric($argv[$i + 1])) {
                    fwrite(STDERR, "ERROR: --max-component requires a numeric argument\n");
                    exit(1);
                }
                $config['max_component'] = (int)$argv[$i + 1];
                $i++;
                break;

            case '--fragment-main':
                $config['fragment_main'] = true;
                break;

            case '--fragment-all':
                $config['fragment_all'] = true;
                $config['fragment_main'] = true;
                break;

            case '--help':
            case '-h':
                $config['help'] = true;
                break;

            default:
                fwrite(STDERR, "ERROR: Unknown option: {$argv[$i]}\n");
                exit(1);
        }
    }

    return $config;
}

/**
 * Show usage information
 */
function showHelp(): void
{
    echo <<<HELP
GitHub Webhook Activity HTML Generator

Generates modern, Material-inspired HTML using external CSS (dev.horde.org.css).
No inline styles, no preprocessors, no build pipelines.

Usage:
  php generate-github-activity-html.php [options]

Options:
  --input <dir>           Source directory containing webhook JSON files (required)
  --output <dir>          Output directory for HTML files (required)
  --max-main <n>          Maximum events per section on main page (default: 10)
  --max-component <n>     Maximum events per component page (default: 10)
  --fragment-main         Generate main index as fragment (no DOCTYPE/html/head)
  --fragment-all          Generate all pages as fragments
  --help, -h              Show this help message

Examples:
  # Full HTML pages (expects dev.horde.org.css in output directory)
  php generate-github-activity-html.php --input /horde/json --output /var/www/updates

  # Main page as fragment for embedding (expects parent page to load CSS)
  php generate-github-activity-html.php --input /horde/json --output /var/www --fragment-main

  # All pages as fragments
  php generate-github-activity-html.php --input /horde/json --output /var/www --fragment-all

Design Philosophy:
  - Material-inspired with subtle shadows and cards
  - Respects Horde red (#990000) as primary accent
  - Pure CSS with custom properties (no preprocessors needed)
  - Responsive with CSS Grid (no media query complexity)
  - Progressive enhancement (works without JavaScript)

Directory Structure:
  The input directory should contain webhook JSON files organized as:
    {type}/{action}/{org}/{repo}/YYYY-MM-DD.HH:MM:SS.json

  Examples:
    issues/opened/horde/components/2026-03-01.08:49:44.json
    release/published/horde/hordectl/2026-03-01.07:29:14.json
    push/other/horde/components/2026-02-28.09:17:34.json

Output:
  <output>/activities.html         - Main activity dashboard
  <output>/components/*.html       - Per-component activity pages

IMPORTANT:
  Place dev.horde.org.css in the output directory for full HTML mode.
  Fragment mode expects the parent page to load the CSS.

HELP;
}

// Main execution - only run if this file is executed directly
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
try {
    $config = parseArgs($argv);

    if ($config['help']) {
        showHelp();
        exit(0);
    }

    if ($config['input'] === null) {
        fwrite(STDERR, "ERROR: --input directory is required\n\n");
        showHelp();
        exit(1);
    }

    if ($config['output'] === null) {
        fwrite(STDERR, "ERROR: --output directory is required\n\n");
        showHelp();
        exit(1);
    }

    echo "Scanning webhook events from {$config['input']}...\n";

    $scanner = new EventScanner($config['input']);
    $rawEvents = $scanner->scan();

    echo "Found " . count($rawEvents) . " events.\n";

    if ($errors = $scanner->getErrors()) {
        echo "Encountered " . count($errors) . " errors during scanning:\n";
        foreach (array_slice($errors, 0, 5) as $error) {
            echo "  - $error\n";
        }
        if (count($errors) > 5) {
            echo "  ... and " . (count($errors) - 5) . " more.\n";
        }
    }

    echo "Normalizing events...\n";

    $normalizer = new EventNormalizer();
    $events = [];
    foreach ($rawEvents as $rawEvent) {
        $normalized = $normalizer->normalize($rawEvent);
        if ($normalized !== null) {
            $events[] = $normalized;
        }
    }

    echo "Normalized " . count($events) . " events.\n";

    $mode = 'full HTML pages';
    if ($config['fragment_all']) {
        $mode = 'all as fragments';
    } elseif ($config['fragment_main']) {
        $mode = 'main as fragment, components as full HTML';
    }
    echo "Generating {$mode}...\n";

    $generator = new HtmlGenerator(
        $config['output'],
        $config['fragment_main'],
        $config['fragment_all']
    );
    $generator->generateMainIndex($events, $config['max_main']);
    $generator->generateComponentPages($events, $config['max_component']);

    echo "\n✓ Successfully generated HTML summaries!\n";
    echo "  Main index: {$config['output']}/activities.html";
    if ($config['fragment_main']) {
        echo " (fragment mode - expects external CSS)";
    }
    echo "\n";
    echo "  Component pages: {$config['output']}/components/";
    if ($config['fragment_all']) {
        echo " (fragment mode)";
    }
    echo "\n";
    if (!$config['fragment_main']) {
        echo "\nIMPORTANT: Make sure dev.horde.org.css is in {$config['output']}/\n";
    }
    echo "\n";

} catch (Exception $e) {
    fwrite(STDERR, "ERROR: {$e->getMessage()}\n");
    fwrite(STDERR, "Stack trace:\n{$e->getTraceAsString()}\n");
    exit(1);
}
}
