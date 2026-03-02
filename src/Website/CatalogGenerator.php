<?php

/**
 * Component Catalog Generator - Fetches components from GitHub API
 *
 * PHP Version 8.2+
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Website;

use Horde\Components\Output;
use RuntimeException;
use Exception;

/**
 * Component Catalog Generator
 *
 * Copyright 2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */
class CatalogGenerator
{
    private string $baseUrl = 'https://api.github.com';
    private ?string $token;

    public function __construct(
        private Output $output,
        ?string $token = null
    ) {
        $this->token = $token ?? getenv('GITHUB_TOKEN') ?: null;
    }

    /**
     * Generate component catalog from GitHub API
     */
    public function generate(
        string $org,
        string $outputFile,
        ?string $gitRepoDir = null
    ): int {
        $this->output->info("Horde Component Catalog Generator (GitHub API)");

        if ($this->token === null) {
            $this->output->warn("No GitHub token provided. Using unauthenticated access.");
            $this->output->warn("Rate limit: 60 requests/hour. For higher limits, set GITHUB_TOKEN.");
        }

        // Fetch repositories from GitHub
        $repos = $this->fetchOrgRepositories($org);

        if (empty($repos)) {
            $this->output->fail("No repositories found for organization: $org");
            return 1;
        }

        // Build catalog
        $components = $this->buildCatalog($repos, $gitRepoDir);

        if (empty($components)) {
            $this->output->fail("No active components found");
            return 1;
        }

        // Sort and simplify
        $components = $this->sortComponents($components);
        $components = $this->simplifyForOutput($components);

        // Write to file
        $json = json_encode($components, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($outputFile, $json);

        $this->output->ok("Successfully generated component catalog from GitHub API!");
        $this->output->info("  Organization: $org");
        $this->output->info("  Components: " . count($components));
        $this->output->info("  Output: $outputFile");

        // Show sample
        $this->output->plain("\nFirst 10 components:");
        foreach (array_slice($components, 0, 10) as $component) {
            $version = $component['version'] !== 'unknown'
                ? " ({$component['version']})"
                : '';
            $this->output->plain("  - {$component['name']}$version");
        }

        if (count($components) > 10) {
            $this->output->plain("  ... and " . (count($components) - 10) . " more.");
        }

        return 0;
    }

    /**
     * Fetch all repositories for an organization
     */
    private function fetchOrgRepositories(string $org): array
    {
        $repos = [];
        $page = 1;
        $perPage = 100;

        $this->output->info("Fetching repositories from GitHub API...");

        while (true) {
            $url = "{$this->baseUrl}/orgs/{$org}/repos?type=public&per_page={$perPage}&page={$page}&sort=name";

            $this->output->plain("  Fetching page $page...");

            $data = $this->makeRequest($url);

            if (empty($data)) {
                break;
            }

            $repos = array_merge($repos, $data);

            // GitHub returns empty array when no more pages
            if (count($data) < $perPage) {
                break;
            }

            $page++;

            // Rate limiting: be nice to GitHub
            usleep(100000); // 0.1 second delay
        }

        $this->output->plain("  Total repositories fetched: " . count($repos));

        return $repos;
    }

    /**
     * Make HTTP request to GitHub API
     */
    private function makeRequest(string $url): ?array
    {
        $headers = [
            'User-Agent: Horde-Component-Catalog-Generator',
            'Accept: application/vnd.github+json',
        ];

        if ($this->token !== null) {
            $headers[] = "Authorization: Bearer {$this->token}";
            $headers[] = 'X-GitHub-Api-Version: 2022-11-28';
        }

        $context = stream_context_create([
            'http' => [
                'header' => implode("\r\n", $headers),
                'timeout' => 30,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $error = error_get_last();
            $this->output->warn("Failed to fetch {$url}: {$error['message']}");
            $this->checkRateLimit();
            return null;
        }

        $data = json_decode($response, true);

        if ($data === null) {
            $this->output->warn("Invalid JSON response from {$url}");
            return null;
        }

        return $data;
    }

    /**
     * Check GitHub API rate limit
     */
    private function checkRateLimit(): void
    {
        $url = "{$this->baseUrl}/rate_limit";
        $data = $this->makeRequest($url);

        if ($data && isset($data['rate'])) {
            $rate = $data['rate'];
            $this->output->warn("\nRate Limit Status:");
            $this->output->warn("  Limit: {$rate['limit']}");
            $this->output->warn("  Remaining: {$rate['remaining']}");
            $this->output->warn("  Reset: " . date('Y-m-d H:i:s', $rate['reset']));

            if ($rate['remaining'] < 10) {
                $this->output->fail("GitHub API rate limit nearly exhausted!");
                $this->output->fail("Consider using a GITHUB_TOKEN for higher limits.");
            }
        }
    }

    /**
     * Build component catalog from GitHub API data
     */
    private function buildCatalog(array $repos, ?string $gitRepoDir = null): array
    {
        $components = [];

        $this->output->plain("\nProcessing " . count($repos) . " repositories...");

        foreach ($repos as $repo) {
            if ($repo['archived'] ?? false) {
                $this->output->plain("  Skipping archived: {$repo['name']}");
                continue;
            }

            if ($repo['disabled'] ?? false) {
                $this->output->plain("  Skipping disabled: {$repo['name']}");
                continue;
            }

            $component = [
                'name' => $repo['full_name'],
                'description' => $repo['description'] ?? 'No description available',
                'github_url' => $repo['html_url'],
            ];

            // Try to get version from local git checkout
            if ($gitRepoDir !== null) {
                $version = $this->getVersionFromGit($gitRepoDir, $repo['name']);
                $component['version'] = $version ?? 'unknown';
            } else {
                $component['version'] = 'unknown';
            }

            $components[] = $component;
        }

        $this->output->plain("Processed " . count($components) . " active components");

        return $components;
    }

    /**
     * Get version from local git repository
     */
    private function getVersionFromGit(string $gitRepoDir, string $repoName): ?string
    {
        $componentDir = $gitRepoDir . '/' . $repoName;

        if (!is_dir($componentDir)) {
            return null;
        }

        // Try .horde.yml
        $hordeYml = $componentDir . '/.horde.yml';
        if (file_exists($hordeYml)) {
            $version = $this->extractVersionFromHordeYml($hordeYml);
            if ($version) {
                return $version;
            }
        }

        // Try composer.json
        $composerJson = $componentDir . '/composer.json';
        if (file_exists($composerJson)) {
            $version = $this->extractVersionFromComposer($composerJson);
            if ($version) {
                return $version;
            }
        }

        return 'dev-master';
    }

    private function extractVersionFromHordeYml(string $path): ?string
    {
        try {
            $contents = file_get_contents($path);
            if (preg_match('/^version:\\s*(.+)$/m', $contents, $matches)) {
                return trim($matches[1]);
            }
        } catch (Exception $e) {
            // Ignore
        }
        return null;
    }

    private function extractVersionFromComposer(string $path): ?string
    {
        try {
            $contents = file_get_contents($path);
            $json = json_decode($contents, true);
            if (isset($json['version'])) {
                return $json['version'];
            }
        } catch (Exception $e) {
            // Ignore
        }
        return null;
    }

    /**
     * Simplify component data for output
     */
    private function simplifyForOutput(array $components): array
    {
        return array_map(function ($component) {
            return [
                'name' => $component['name'],
                'version' => $component['version'],
                'description' => $component['description'],
                'github_url' => $component['github_url'],
            ];
        }, $components);
    }

    /**
     * Sort components by name
     */
    private function sortComponents(array $components): array
    {
        usort($components, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        return $components;
    }
}
