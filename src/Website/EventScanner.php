<?php

/**
 * Scans hook directory for webhook events
 *
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

declare(strict_types=1);

namespace Horde\Components\Website;

use DateTime;
use Exception;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

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
