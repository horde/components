<?php

declare(strict_types=1);

namespace Horde\Components\Helper;

/**
 * Helper for checking if a component is hosted on GitHub
 *
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class GitHubChecker
{
    public function __construct(
        private readonly Git $git
    ) {}

    /**
     * Check if the component's git remote is on github.com
     *
     * @param string $localDir The local directory path of the component
     * @param string $remote The remote name to check (default: 'origin')
     * @return bool True if the remote URL is on github.com
     */
    public function isOnGitHub(string $localDir, string $remote = 'origin'): bool
    {
        if (!$this->git->hasRemotes($localDir)) {
            return false;
        }

        $remoteUrl = $this->getRemoteUrl($localDir, $remote);
        if ($remoteUrl === null) {
            return false;
        }

        return $this->isGitHubUrl($remoteUrl);
    }

    /**
     * Get the remote URL for a repository
     *
     * @param string $localDir The local directory path
     * @param string $remote The remote name
     * @return string|null The remote URL or null if not found
     */
    public function getRemoteUrl(string $localDir, string $remote = 'origin'): ?string
    {
        return $this->git->getRemoteUrl($localDir, $remote);
    }

    /**
     * Check if a URL is a GitHub URL
     *
     * Supports various GitHub URL formats:
     * - https://github.com/owner/repo
     * - https://github.com/owner/repo.git
     * - git@github.com:owner/repo.git
     * - git://github.com/owner/repo.git
     *
     * @param string $url The URL to check
     * @return bool True if it's a GitHub URL
     */
    public function isGitHubUrl(string $url): bool
    {
        // Normalize the URL for easier checking
        $url = strtolower(trim($url));

        // Check for github.com in various URL formats
        return str_contains($url, 'github.com');
    }

    /**
     * Extract owner and repository name from a GitHub URL
     *
     * @param string $url The GitHub URL
     * @return array{owner: string, repo: string}|null Array with 'owner' and 'repo' keys, or null if invalid
     */
    public function parseGitHubUrl(string $url): ?array
    {
        if (!$this->isGitHubUrl($url)) {
            return null;
        }

        // Remove .git suffix if present
        $url = preg_replace('/\.git$/', '', $url);

        // Handle different URL formats
        if (preg_match('#github\.com[:/]([^/]+)/([^/\s]+)#i', $url, $matches)) {
            return [
                'owner' => $matches[1],
                'repo' => $matches[2],
            ];
        }

        return null;
    }

    /**
     * Get the GitHub repository identifier (owner/repo) for a component
     *
     * @param string $localDir The local directory path
     * @param string $remote The remote name (default: 'origin')
     * @return string|null The repository identifier (e.g., 'horde/components') or null
     */
    public function getGitHubRepository(string $localDir, string $remote = 'origin'): ?string
    {
        $url = $this->getRemoteUrl($localDir, $remote);
        if ($url === null) {
            return null;
        }

        $parsed = $this->parseGitHubUrl($url);
        if ($parsed === null) {
            return null;
        }

        return $parsed['owner'] . '/' . $parsed['repo'];
    }
}
