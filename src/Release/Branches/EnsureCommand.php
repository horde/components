<?php

declare(strict_types=1);

/**
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

namespace Horde\Components\Release\Branches;

use Horde\Components\Helper\Git as GitHelper;
use Horde\Components\Helper\GitHubChecker;
use Horde\Components\Helper\Version;
use Horde\Components\Output;
use Horde\Components\Release\Branch\BranchDefinition;
use Horde\Components\Release\Branch\BranchRoleMap;
use Horde\Components\Release\Branch\PhpPolicyFitter;
use Horde\Components\Wrapper\HordeYml;
use Horde\GithubApiClient\GithubApiAccessDeniedException;
use Horde\GithubApiClient\GithubApiClient;
use Horde\GithubApiClient\GithubApiConfig;
use Horde\GithubApiClient\GithubRepository;
use Horde\GithubApiClient\RepositoryUpdate;
use Horde\Http\Client\Curl as CurlClient;
use Horde\Http\Client\Options;
use Horde\Http\RequestFactory;
use Horde\Http\ResponseFactory;
use Horde\Http\StreamFactory;
use Throwable;

/**
 * Ensure a repository's release branch exists locally and remotely and is
 * the repository default branch.
 *
 * Steps:
 * 1. Resolve the release branch from the central branch-role map.
 * 2. If the local branch is missing, create it: from the remote branch of
 *    the same name when that exists, otherwise from the current local
 *    default branch.
 * 3. If the remote branch is missing, push the local branch with upstream
 *    tracking.
 * 4. Make the remote branch the repository default branch via the GitHub
 *    API.
 *
 * 5. Retry once when the API call fails and the active authorization was a GitHub
 *    App token while a Personal Access Token is also available (GITHUB_TOKEN
 *    env or github.token config).
 *
 * When no auth is configured, do the git work and point at the same link.
 * These steps are supposed to be idempotent (rerun-safe) and support pretend mode.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class EnsureCommand
{
    /**
     * Constructor.
     *
     * @param Output $output Output handler.
     * @param GitHelper $git Git helper.
     * @param GitHubChecker $checker GitHub remote/owner resolver.
     * @param GithubApiConfig $apiConfig Resolved GitHub API config (token
     *                                   may be empty when unauthenticated).
     * @param bool $usedGitHubApp True when $apiConfig carries a GitHub App
     *                            installation token (as opposed to a PAT or
     *                            no auth). Gates the PAT fallback below.
     * @param string $fallbackPat A Personal Access Token to retry the
     *                            default-branch change with when the primary
     *                            (GitHub App) attempt fails. Empty when none
     *                            is configured.
     */
    public function __construct(
        private readonly Output $output,
        private readonly GitHelper $git,
        private readonly GitHubChecker $checker,
        private readonly GithubApiConfig $apiConfig,
        private readonly bool $usedGitHubApp = false,
        private readonly string $fallbackPat = ''
    ) {}

    /**
     * Run the ensure workflow.
     *
     * @param string $componentPath Path to the component checkout.
     * @param string|null $rolesFile Optional branch-role YAML override.
     * @param bool $pretend Preview mutations without performing them.
     * @return bool True on success (including the auth-missing path, where
     *              the git work completed and only the manual default-branch
     *              step remains).
     */
    public function execute(
        string $componentPath,
        ?string $rolesFile = null,
        bool $pretend = false
    ): bool {
        $this->output->bold('=== Horde Branch Ensure ===');
        if ($pretend) {
            $this->output->warn('[PRETEND MODE] No changes will be made');
        }

        // The branch-role map reads both the simple (bare string) and the
        // enhanced (per-branch policy) formats. Use it for the release
        // branch name and for the release branch's PHP-version policy.
        $map = BranchRoleMap::load($rolesFile);
        $releaseDef = $map->release();
        $release = $releaseDef->name();
        if ($release === '') {
            $this->output->error('No release branch is declared in the branch-role map.');
            return false;
        }
        $this->output->info("Release branch: {$release}");

        // Refresh remote state so local/remote existence checks are accurate.
        if ($this->git->hasRemotes($componentPath)) {
            $this->git->fetch($componentPath);
        }

        $changed = false;
        $createdFromBranch = null;

        if (!$this->ensureLocalBranch($componentPath, $release, $pretend, $changed, $createdFromBranch)) {
            return false;
        }

        // When the release branch was created from scratch, open the new
        // generation with a version-bump commit on it.
        if ($createdFromBranch !== null) {
            $this->openNewReleaseBranch($componentPath, $createdFromBranch, $release, $pretend);
        }

        $this->ensureRemoteBranch($componentPath, $release, $pretend, $changed);

        // Enforce the release branch's PHP-version policy on .horde.yml.
        // Idempotent: runs whether the branch was just created or already
        // existed.
        $this->enforcePhpPolicy($componentPath, $releaseDef, $pretend, $changed);

        $defaultChanged = $this->ensureDefaultBranch($componentPath, $release, $pretend);
        if ($defaultChanged === null) {
            // The git side is consistent. Only the default-branch step could
            // not be completed. A helpful message was already printed.
            // Treat as success.
            return true;
        }
        $changed = $changed || $defaultChanged;

        if (!$changed) {
            $this->output->ok("Everything is already in order: {$release} exists locally and remotely and is the default branch.");
        } else {
            $this->output->ok("Release branch {$release} is now present and set as the default branch.");
        }

        return true;
    }

    /**
     * Ensure the local release branch exists.
     *
     * @param bool $changed Set to true (by reference) when a branch is created.
     * @param string|null $createdFromBranch Set (by reference) to the former
     *        branch name when the release branch was created from scratch off
     *        the current default branch, else left null. Signals the opening
     *        version-bump step. A branch that already existed locally or was
     *        created tracking an existing remote branch is not "from scratch".
     * @return bool False only on an unrecoverable error.
     */
    private function ensureLocalBranch(
        string $componentPath,
        string $release,
        bool $pretend,
        bool &$changed,
        ?string &$createdFromBranch = null
    ): bool {
        if ($this->git->localBranchExists($componentPath, $release)) {
            $this->output->info("Local branch {$release} already exists.");
            return true;
        }

        if ($this->git->remoteBranchExists($componentPath, $release)) {
            $this->output->info("Local branch {$release} missing; creating it to track origin/{$release}.");
            if (!$pretend) {
                $this->git->createRemoteTrackingBranch($componentPath, $release);
            }
            $changed = true;
            return true;
        }

        // Neither local nor remote exists: Pick the branch to fork from.
        $sourceBranch = trim((string) $this->git->getCurrentBranch($componentPath));
        if (!preg_match('/^FRAMEWORK_\d+_\d+$/', $sourceBranch)) {
            $sourceBranch = trim((string) (
                $this->git->getDefaultBranch($componentPath)
                ?? $sourceBranch
            ));
        }
        if ($sourceBranch === '') {
            $this->output->error('Cannot determine a source branch to create the release branch from.');
            return false;
        }

        $this->output->info("Local and remote branch {$release} missing; creating from {$sourceBranch}.");
        if (!$pretend) {
            $this->git->branchFromLocal($componentPath, $release, $sourceBranch);
        }
        $changed = true;
        $createdFromBranch = $sourceBranch;
        return true;
    }

    /**
     * Ensure the remote release branch exists (push with upstream tracking).
     *
     * @param bool $changed Set to true (by reference) when a push happens.
     */
    private function ensureRemoteBranch(
        string $componentPath,
        string $release,
        bool $pretend,
        bool &$changed
    ): void {
        if ($this->git->remoteBranchExists($componentPath, $release)) {
            $this->output->info("Remote branch origin/{$release} already exists.");
            return;
        }

        $this->output->info("Pushing {$release} to origin with upstream tracking.");
        if (!$pretend) {
            $this->git->push($componentPath, 'origin', $release);
        }
        $changed = true;
    }

    /**
     * Enforce the release branch PHP-version policy on .horde.yml.
     *
     * Reads the component's current dependencies.required.php
     * Then reconciles it against the branch policy.
     * - raise floor to min
     * - tighten ceiling to max
     * - if Sync clause is set, rewrite to the exact values we require.
     * Persists with {@see HordeYml::save()}
     *
     * @param BranchDefinition|null $releaseDef The release branch
     *        definition from the role map or null when the release branch
     *        is absent from the map.
     * @param bool $changed Set to true (by reference) when .horde.yml is
     *        rewritten.
     */
    private function enforcePhpPolicy(
        string $componentPath,
        ?BranchDefinition $releaseDef,
        bool $pretend,
        bool &$changed
    ): void {
        if ($releaseDef === null || !$releaseDef->hasPolicy()) {
            return;
        }

        try {
            $yml = new HordeYml($componentPath);
            $file = $yml->getHordeYmlFile();
            $current = $file->getRequiredPhp();
        } catch (Throwable $e) {
            $this->output->warn('Could not read .horde.yml PHP requirement. Skipping policy: ' . $e->getMessage());
            return;
        }

        $fitter = new PhpPolicyFitter();
        $target = $fitter->target($releaseDef->policy(), $current);
        $shownCurrent = $current === '' ? '(none)' : $current;

        if ($target === $current || $target === '') {
            $this->output->info("PHP requirement already matches the {$releaseDef->name()} policy ({$shownCurrent}).");
            return;
        }

        $this->output->info("Updating dependencies.required.php from {$shownCurrent} to {$target} per {$releaseDef->name()} policy.");
        if ($pretend) {
            $this->output->warn("[PRETEND] Would set dependencies.required.php to {$target}.");
            return;
        }

        if ($fitter->fit($file, $target)) {
            $yml->save();
            $changed = true;
        }
    }

    /**
     * Open a freshly created release branch with a version-bump commit.
     *
     * When the new release branch is a forward generation step from the
     * branch it was created off (same major + higher minor, or a higher
     * major), the component's .horde.yml release version is bumped to the
     * opening pre-release of the new generation (alpha) and committed as the
     * branch's first commit. No-op when the former/new branch names are not a
     * forward FRAMEWORK_A_B step.
     */
    private function openNewReleaseBranch(
        string $componentPath,
        string $formerBranch,
        string $release,
        bool $pretend
    ): void {
        // The branch was created but not checked out; switch to it so the
        // opening commit lands on the new branch.
        if (!$pretend) {
            $this->git->checkoutBranch($componentPath, $release);
        }

        try {
            $currentVersion = (new HordeYml($componentPath))->getReleaseVersion()->toFullSemVerV2();
        } catch (Throwable $e) {
            $this->output->warn('Could not read .horde.yml release version. Skipping the opening version bump: ' . $e->getMessage());
            return;
        }

        $targetVersion = self::openingVersionObjectFor($formerBranch, $release, $currentVersion);
        if ($targetVersion === null) {
            $this->output->info("No generation version bump needed for {$release} (from {$formerBranch}).");
            return;
        }
        $target = $targetVersion->toFullSemVerV2();

        $message = "chore(release): open {$release} at {$target}";
        $this->output->info("Opening {$release} at {$target} (was {$currentVersion}).");
        if ($pretend) {
            $this->output->warn("[PRETEND] Would commit: {$message}");
            return;
        }

        $hordeYml = new HordeYml($componentPath);
        $hordeYml->setReleaseVersionAndStability($targetVersion);
        $hordeYml->save();
        $this->git->add('.horde.yml');
        $this->git->commit($componentPath, $message);
    }

    /**
     * Compute the opening release version string for a fresh release branch.
     *
     * Thin string wrapper over {@see self::openingVersionObjectFor()} for
     * unit testing and display. Returns null when the branch names are not a
     * forward FRAMEWORK_A_B generation step.
     */
    public static function openingVersionFor(
        string $formerBranch,
        string $newReleaseBranch,
        string $currentVersion
    ): ?string {
        return self::openingVersionObjectFor($formerBranch, $newReleaseBranch, $currentVersion)
            ?->toFullSemVerV2();
    }

    /**
     * Compute the opening release version for a fresh release branch.
     *
     * Pure helper (no IO): decides minor-vs-major from the FRAMEWORK_A_B
     * delta between the former branch and the new release branch, bumps the
     * component's current version core accordingly.
     * Opens at alpha stability count 0, i.e. a bare "-alpha").
     *
     * @param string $formerBranch The branch the release branch was made from.
     * @param string $newReleaseBranch The new release branch name.
     * @param string $currentVersion The component's current release version.
     * @return Version|null The opening version, or null when the branch names
     *                      are not a forward FRAMEWORK_A_B generation step.
     */
    private static function openingVersionObjectFor(
        string $formerBranch,
        string $newReleaseBranch,
        string $currentVersion
    ): ?Version {
        if (!preg_match('/^FRAMEWORK_(\d+)_(\d+)$/', $formerBranch, $from)
            || !preg_match('/^FRAMEWORK_(\d+)_(\d+)$/', $newReleaseBranch, $to)) {
            return null;
        }
        [$fromMajor, $fromMinor] = [(int) $from[1], (int) $from[2]];
        [$toMajor, $toMinor] = [(int) $to[1], (int) $to[2]];

        if ($toMajor > $fromMajor) {
            $severity = 'major';
        } elseif ($toMajor === $fromMajor && $toMinor > $fromMinor) {
            $severity = 'minor';
        } else {
            // Not a forward generation step (same, older, or incomparable).
            return null;
        }

        $version = Version::fromComposerString($currentVersion);
        $major = $version->getMajor();
        $minor = $version->getMinor();
        if ($severity === 'major') {
            $major += 1;
            $minor = 0;
        } else {
            $minor += 1;
        }

        // Build directly with stability count 0 so the opening version is a
        // bare "-alpha" (stability count 0).
        return new Version('', '', $major, $minor, 0, 0, 'alpha', 0);
    }

    /**
     * Ensure the remote branch is the repository default branch.
     *
     * @return bool|null True when the default branch was changed, false when
     *                   it was already correct. Null when the step wasn't completed.
     */
    private function ensureDefaultBranch(
        string $componentPath,
        string $release,
        bool $pretend
    ): ?bool {
        if (!$this->checker->isOnGitHub($componentPath)) {
            $this->output->warn('Remote is not on GitHub. Cannot manage the default branch.');
            return null;
        }

        $url = $this->checker->getRemoteUrl($componentPath);
        $parsed = $url !== null ? $this->checker->parseGitHubUrl($url) : null;
        if ($parsed === null) {
            $this->output->warn('Could not parse the GitHub owner/repo from the remote URL.');
            return null;
        }
        $owner = $parsed['owner'];
        $repoName = $parsed['repo'];
        $settingsUrl = "https://github.com/{$owner}/{$repoName}/settings/branches";

        if ($this->apiConfig->accessToken === '' && $this->apiConfig->jwt === '') {
            $this->output->warn('No GitHub authorization configured. Skipping the default-branch change.');
            $this->output->info("Set {$release} as the default branch manually: {$settingsUrl}");
            return null;
        }

        $repo = GithubRepository::fromFullName("{$owner}/{$repoName}");
        $client = $this->buildClient();

        try {
            $current = $client->getRepository($repo);
            $currentDefault = $current->default_branch ?? null;

            if ($currentDefault === $release) {
                $this->output->info("Default branch is already {$release}.");
                return false;
            }

            $this->output->info("Changing default branch from " . ($currentDefault ?? 'unknown') . " to {$release}.");
            if ($pretend) {
                return true;
            }

            $client->updateRepository($repo, new RepositoryUpdate(defaultBranch: $release));
            return true;
        } catch (GithubApiAccessDeniedException $e) {
            $fallback = $this->tryPatFallback($repo, $release, $pretend);
            if ($fallback !== null) {
                return $fallback ? true : null;
            }
            $this->output->error($e->hint);
            $this->output->info("Set {$release} as the default branch manually: {$settingsUrl}");
            return null;
        } catch (Throwable $e) {
            $fallback = $this->tryPatFallback($repo, $release, $pretend);
            if ($fallback !== null) {
                return $fallback ? true : null;
            }
            $this->output->error('GitHub API error while setting the default branch: ' . $e->getMessage());
            $this->output->info("Set {$release} as the default branch manually: {$settingsUrl}");
            return null;
        }
    }

    /**
     * Retry the default-branch change once with a Personal Access Token.
     *
     * Applies only when the primary attempt used a GitHub App token (which
     * commonly lacks administration:write) and a PAT is configured. Reports
     * to the user that the PAT fallback was used and whether it succeeded.
     *
     * @param GithubRepository $repo The target repository.
     * @param string $release The release branch to set as default.
     * @param bool $pretend Preview the retry without performing it.
     * @return bool|null True when the PAT fallback set the default branch
     *                   (or would, under --pretend); false when the fallback
     *                   ran but failed; null when the fallback does not apply
     *                   (not a GitHub App token, or no PAT configured).
     */
    private function tryPatFallback(
        GithubRepository $repo,
        string $release,
        bool $pretend
    ): ?bool {
        if (!$this->usedGitHubApp || trim($this->fallbackPat) === '') {
            return null;
        }

        $this->output->warn(
            'GitHub App token could not set the default branch. Retrying once with the configured PAT.'
        );

        if ($pretend) {
            $this->output->warn('[PRETEND] Would retry the default-branch change with the PAT.');
            return true;
        }

        try {
            $client = $this->buildClient(new GithubApiConfig(accessToken: $this->fallbackPat));
            $client->updateRepository($repo, new RepositoryUpdate(defaultBranch: $release));
            $this->output->ok("PAT fallback succeeded: default branch set to {$release}.");
            return true;
        } catch (Throwable $e) {
            $this->output->error('PAT fallback failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Build a GitHub API client from the resolved config and the Horde HTTP stack
     *
     * @param GithubApiConfig|null $config Config override (e.g. a PAT config for the fallback).
     */
    private function buildClient(?GithubApiConfig $config = null): GithubApiClient
    {
        $httpClient = new CurlClient(new ResponseFactory(), new StreamFactory(), new Options());
        return new GithubApiClient(
            $httpClient,
            new RequestFactory(),
            $config ?? $this->apiConfig,
            new StreamFactory()
        );
    }
}
