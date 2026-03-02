<?php

declare(strict_types=1);

namespace Horde\Components\Helper;

use Horde\Components\Output;
use Horde\GithubApiClient\GithubApiClient;
use Horde\GithubApiClient\GithubApiConfig;
use Horde\GithubApiClient\GithubRepository;
use Horde\GithubApiClient\CreateReleaseParams;
use Horde\Http\Client\Curl as CurlClient;
use Horde\Http\Client\Options;
use Horde\Http\StreamFactory;
use Horde\Http\RequestFactory;
use Horde\Http\ResponseFactory;

/**
 * Helper for creating GitHub releases
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
class GitHubReleaseCreator
{
    public function __construct(
        private readonly GitHubChecker $githubChecker,
        private readonly Output $output,
        private readonly GithubApiConfig $githubApiConfig
    ) {}

    /**
     * Create a GitHub release for a tag
     *
     * @param string $localDir The local directory path of the component
     * @param string $tagName The tag name for the release (e.g., 'v1.0.0')
     * @param string $releaseName The release name/title
     * @param string $releaseBody The release notes/body
     * @param bool $prerelease Whether this is a prerelease
     * @return \Horde\GithubApiClient\GithubRelease|null The created release object or null if failed/skipped
     */
    public function createRelease(
        string $localDir,
        string $tagName,
        string $releaseName,
        string $releaseBody,
        bool $prerelease = false
    ): ?\Horde\GithubApiClient\GithubRelease {
        // Check if this is a GitHub repository
        if (!$this->githubChecker->isOnGitHub($localDir)) {
            $this->output->info('Not a GitHub repository, skipping GitHub release creation');
            return null;
        }

        // Get GitHub token from DI-provided config
        $githubToken = $this->githubApiConfig->accessToken;
        if ($githubToken === '') {
            $this->output->warn('GitHub token not configured, skipping GitHub release creation');
            $this->output->help("Configure token via:");
            $this->output->help("  1. CLI: --github-token=ghp_xxx");
            $this->output->help("  2. Environment: export GITHUB_TOKEN=ghp_xxx");
            $this->output->help("  3. Config file: \$conf['github.token'] = 'ghp_xxx'");
            return null;
        }

        // Get repository identifier
        $repoFullName = $this->githubChecker->getGitHubRepository($localDir);
        if (!$repoFullName) {
            $this->output->warn('Could not determine GitHub repository, skipping release creation');
            return null;
        }

        try {
            // Initialize GitHub API client
            $httpClient = new CurlClient(new ResponseFactory(), new StreamFactory(), new Options());
            $requestFactory = new RequestFactory();
            $streamFactory = new StreamFactory();
            $config = new GithubApiConfig(accessToken: $githubToken);
            $apiClient = new GithubApiClient($httpClient, $requestFactory, $config, $streamFactory);

            $repo = GithubRepository::fromFullName($repoFullName);

            // Create the release
            $params = new CreateReleaseParams(
                tagName: $tagName,
                name: $releaseName,
                body: $releaseBody,
                draft: false,
                prerelease: $prerelease
            );

            $this->output->info("Creating GitHub release for {$repoFullName} tag {$tagName}...");
            $release = $apiClient->createRelease($repo, $params);

            $this->output->ok("GitHub release created successfully: {$release->htmlUrl}");
            return $release;
        } catch (\Exception $e) {
            $this->output->warn("Failed to create GitHub release: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Upload a PHAR file as a release asset
     *
     * @param \Horde\GithubApiClient\GithubRelease $release The release to upload to
     * @param string $pharPath Path to the PHAR file
     * @param string $assetName Name for the asset (e.g., 'horde-components-v1.0.0.phar')
     * @return bool True if upload was successful
     */
    public function uploadPharAsset(
        \Horde\GithubApiClient\GithubRelease $release,
        string $pharPath,
        string $assetName
    ): bool {
        if (!file_exists($pharPath)) {
            $this->output->warn("PHAR file not found: {$pharPath}");
            return false;
        }

        // Get GitHub token from DI-provided config
        $githubToken = $this->githubApiConfig->accessToken;
        if ($githubToken === '') {
            $this->output->warn('GitHub token not configured, skipping PHAR upload');
            return false;
        }

        try {
            // Initialize GitHub API client
            $httpClient = new CurlClient(new ResponseFactory(), new StreamFactory(), new Options());
            $requestFactory = new RequestFactory();
            $streamFactory = new StreamFactory();
            $config = new GithubApiConfig(accessToken: $githubToken);
            $apiClient = new GithubApiClient($httpClient, $requestFactory, $config, $streamFactory);

            // Read PHAR file content
            $fileContent = file_get_contents($pharPath);
            if ($fileContent === false) {
                $this->output->warn("Failed to read PHAR file: {$pharPath}");
                return false;
            }

            $this->output->info("Uploading PHAR asset: {$assetName} (" . number_format(strlen($fileContent)) . " bytes)");
            $asset = $apiClient->uploadReleaseAsset(
                $release->uploadUrl,
                $assetName,
                $fileContent,
                'application/octet-stream'
            );

            $this->output->ok("PHAR asset uploaded successfully: {$asset->browserDownloadUrl}");
            return true;
        } catch (\Exception $e) {
            $this->output->warn("Failed to upload PHAR asset: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Determine release type label based on severity
     *
     * Maps commit severity to human-readable release type
     *
     * @param string $severity The top severity from conventional commits ('subpatch', 'patch', 'minor', 'major')
     * @return string The release type description
     */
    public static function getReleaseTypeLabel(string $severity): string
    {
        return match ($severity) {
            'major' => 'major',
            'minor' => 'minor',
            'patch' => 'bugfix',
            'subpatch' => 'maintenance',
            default => 'maintenance',
        };
    }

    /**
     * Format release notes with type indicator
     *
     * @param string $notes The raw release notes
     * @param string $severity The top severity from conventional commits
     * @return string Formatted release notes with type indicator
     */
    public static function formatReleaseNotes(string $notes, string $severity): string
    {
        $typeLabel = self::getReleaseTypeLabel($severity);
        $header = "This is a **{$typeLabel} release**.";

        if ($severity === 'major') {
            $header .= " ⚠️ Contains breaking changes.";
        }

        return $header . "\n\n" . trim($notes);
    }
}
