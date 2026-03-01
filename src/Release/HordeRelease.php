<?php

namespace Horde\Components\Release;

use Horde\Components\Helper\Git as GitHelper;
use Horde\Components\Helper\Composer as ComposerHelper;
use Horde\Components\Helper\ConventionalCommitHelper;
use Horde\Components\Helper\Version;
use Horde\Components\Helper\GitHubChecker;
use Horde\Components\Helper\GitHubReleaseCreator;
use Horde\Components\Wrapper\HordeYml;
use Horde\Components\Wrapper\ChangelogYml;
use Horde\Components\Component\ComponentDirectory;
use Horde\Components\Exception;
use Horde\Components\Wrapper\ComposerJson;
use Horde\Components\Output;
use Horde\Components\Config;
use Horde\Components\Qc\Tasks as QcTasks;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Horde\Components\Wrapper\ApplicationPhp;
use Horde\Components\ChangelogEntry;

/**
 * Horde 6 Release pipeline
 *
 * This is an attempt at redesigning from the old H4/H5 style releases
 *
 * Supposed to be run on the release branch after including code (i.e. after PR)
 * but before writing the metadata and tagging.
 *
 * Post release tagging metadata will NOT be updated for "next version".
 *  - Check if we are on release branch
 *  - Read ConventionalCommits & expected next version
 *  - Bail out on inappropriate, i.e. no conventional commits included
 *  - write .horde.yml versions
 *  - move changelog.yml to doc basedir if it is nested into subdirs
 *  - write changelog.yml updates
 *  - write composer.json from changelog and horde.yml
 *  - Remove package.xml and CHANGES file if present.
 *  - Composer validate
 *  - write application.php Sentinel
 *  - Run any document pulls from Wiki or other sources
 *  - commit for release
 *  - tag & push
 *  - Post Tasks, trigger packagist and horde infra apis
 *  - Post release commit if needed.
 *  -
 */
class HordeRelease
{
    public function __construct(
        private ComposerHelper $composerHelper,
        private GitHelper $gitHelper,
        private ComponentDirectory $directory,
        private Output $output,
        private GitHubChecker $githubChecker,
        private GitHubReleaseCreator $githubReleaseCreator,
        private QcTasks $qcTasks,
    ) {}
    /**
     * Run the release flow. Most steps should be idempotent.
     *
     * @param Config $config Configuration object containing options
     */
    public function run(Config $config)
    {
        $options = $config->getOptions();
        $currentBranch = $this->gitHelper->getCurrentBranch($this->directory);
        // Check if we are on release branch
        if ($currentBranch !== 'FRAMEWORK_6_0') {
            throw new Exception('Not on release branch. Please switch to the release branch before running this script.');
        }

        // Early QC: Check and fix .gitignore before starting release process
        $this->output->ok('Running pre-release QC checks...');
        $component = $config->getComponent();
        $gitignoreTask = $this->qcTasks->getTask('gitignore', $component);

        // Enable auto-fix for gitignore during release
        $qcOptions = array_merge($options, ['fix_qc_issues' => true]);

        $errors = $gitignoreTask->validate($qcOptions);
        if (empty($errors)) {
            $gitignoreErrors = $gitignoreTask->run($qcOptions);
            if ($gitignoreErrors > 0) {
                $this->output->warn('Gitignore check found issues but they were auto-fixed');
            }
        } else {
            $this->output->warn('Gitignore task validation failed: ' . implode(', ', $errors));
        }

        // Determine the target version
        $history = null;
        $skipPostReleaseEdits = false;

        if (!empty($options['next_version'])) {
            // User supplied a manual version - normalize it
            $this->output->ok('Explicit target version provided: ' . $options['next_version']);
            $nextVersion = Version::fromComposerString($options['next_version']);
            $nextTag = $nextVersion->toHordeTag();
            $this->output->info('Normalized to tag: ' . $nextTag . ' (SemVer: ' . $nextVersion->toFullSemverV2() . ')');

            // Check if this version/tag already exists locally or remotely
            if ($this->gitHelper->localTagExists((string) $this->directory, $nextTag)) {
                $this->output->info("Tag '{$nextTag}' exists locally. Performing post-release edits only.");
                $skipPostReleaseEdits = true;
            } elseif ($this->gitHelper->hasRemotes((string) $this->directory)
                      && $this->gitHelper->remoteTagExists((string) $this->directory, $nextTag)) {
                throw new Exception(
                    sprintf(
                        'Tag "%s" already exists on remote. Cannot release version %s again.',
                        $nextTag,
                        $nextVersion->toFullSemverV2()
                    )
                );
            }
        } else {
            // Auto-calculate version from conventional commits
            $this->output->info('Auto-calculating version from conventional commits');
            $history = new ConventionalCommitHelper($this->gitHelper);

            // Bail out on inappropriate, i.e. no conventional commits included
            if (count($history->commitReader->getLog()) == 0) {
                throw new Exception('No conventional commits found since last tag. Please ensure you have made commits in the correct format.');
            }

            $nextVersion = $history->nextVersion;
            $nextTag = $nextVersion->toHordeTag();

            // Precheck: If remotes are configured, check if the next version tag already exists
            if ($this->gitHelper->hasRemotes((string) $this->directory)) {
                if ($this->gitHelper->remoteTagExists((string) $this->directory, $nextTag)) {
                    throw new Exception(
                        sprintf(
                            "Tag \"%s\" already exists on remote. Cannot release version %s again.\n"
                            . "Suggestion: Use --next-version to specify a different version (e.g., --next-version %s)",
                            $nextTag,
                            $nextVersion->toFullSemverV2(),
                            $nextVersion->nextVersionObject()->toFullSemverV2()
                        )
                    );
                }
            }
        }

        // If tag exists locally and we're using manual version, only do post-release edits
        if ($skipPostReleaseEdits) {
            $this->output->warn('Skipping main release process - tag already exists locally');
            // TODO: Perform post-release edits here
            return;
        }

        $this->output->ok('Releasing version: ' . $nextVersion->toFullSemverV2() . ' (tag: ' . $nextTag . ')');

        // Read conventional commits for changelog notes (if not using manual version)
        $logNotes = '';
        $topSeverity = 'subpatch'; // Default for manual versions
        if ($history !== null) {
            $topSeverity = $history->commitReader->getTopSeverity();
            foreach ($history->commitReader->getLog() as $commit) {
                // TODO: Nice Format
                $logNotes .= $commit->subject . "\n";
            }
        } else {
            $logNotes = "Release version " . $nextVersion->toFullSemverV2();
        }

        // write .horde.yml versions and stabilities
        $hordeYml = new HordeYml($this->directory);
        $hordeYml->setReleaseVersionAndStability($nextVersion);
        // TODO: Logic on when to set API version and stability
        // $hordeYml->setApiVersionAndStability($nextVersion);
        $hordeYml->save();
        // While apps used to have changelog.yml in doc/, libs had it in doc/long/lib/name - simplify this
        // write changelog.yml
        $splFileInfo = self::findFile((string) $this->directory . '/doc', 'changelog.yml');
        if ($splFileInfo && ($splFileInfo->getPathname() !== $this->directory . '/doc/changelog.yml')) {
            $this->gitHelper->moveFile($splFileInfo->getPathname(), $this->directory . '/doc/changelog.yml');
        }

        $changelog = new ChangelogYml($this->directory . '/doc');
        $entry = new ChangelogEntry(
            releaseVersion: $hordeYml->getReleaseVersion(),
            apiVersion: $hordeYml->getApiVersion(),
            // Date defaults to today
            license: $hordeYml->getLicense(),
            notes: $logNotes
        );
        $changelog->addChangelogEntry($entry);
        $changelog->save();
        // write composer.json from changelog and horde.yml
        $this->composerHelper->generateComposerJson($hordeYml, ['composer_version' => 'dev-' . $currentBranch]);

        // Remove CHANGES file if present
        $splFileInfo = self::findFile((string) $this->directory . '/doc', 'CHANGES');
        if ($splFileInfo && ($splFileInfo->getPathname() !== (string) $this->directory . '/doc')) {
            $this->gitHelper->deleteFile($splFileInfo->getPathname());
        }
        // Remove package.xml file if present
        $packagePath = (string) $this->directory . '/package.xml';
        if (file_exists($packagePath)) {
            $this->gitHelper->deleteFile($packagePath);
        }
        // TODO: Composer validate
        // TODO: write application.php Sentinel
        if (in_array($hordeYml['type'], ['application', 'horde-application'])) {
            $applicationPhp = new ApplicationPhp($this->directory);
            $applicationPhp->setVersion($hordeYml->getReleaseVersion()->toFullSemverV2());
            $applicationPhp->save();
        }
        // TODO: Run any document pulls from Wiki or other sources
        // commit for release using Conventional Commit format
        $this->gitHelper->add((string) $this->directory . '/lib/Application.php');
        $this->gitHelper->add((string) $this->directory . '/doc/changelog.yml');
        $this->gitHelper->add((string) $this->directory . '/.horde.yml');
        $this->gitHelper->add((string) $this->directory . '/composer.json');

        // Add .gitignore if it exists
        $gitignorePath = (string) $this->directory . '/.gitignore';
        if (file_exists($gitignorePath)) {
            $this->gitHelper->add($gitignorePath);
        }

        $releaseVersion = $hordeYml->getReleaseVersion()->toFullSemverV2();
        $apiVersion = $hordeYml->getApiVersion()->toFullSemverV2();

        // Conventional Commit format: chore(release): bump version to X.Y.Z
        $releaseMessage = sprintf(
            "chore(release): bump version to %s\n\nRelease version %s (API Version: %s)\n\n%s",
            $releaseVersion,
            $releaseVersion,
            $apiVersion,
            $logNotes
        );

        $this->gitHelper->commit(
            (string) $this->directory,
            $releaseMessage
        );
        // TODO: tag & push
        $this->gitHelper->tag(
            (string) $this->directory,
            $hordeYml->getReleaseVersion()->toHordeTag(),
            $releaseMessage
        );
        $this->gitHelper->push(
            (string) $this->directory,
            'origin',
            $currentBranch,
            $hordeYml->getReleaseVersion()->toHordeTag()
        );

        // Create GitHub release if this is a GitHub repository
        $releaseTag = $hordeYml->getReleaseVersion()->toHordeTag();
        $releaseName = $hordeYml->getName() . ' ' . $hordeYml->getReleaseVersion()->toFullSemverV2();
        $isPrerelease = in_array($hordeYml->getReleaseVersion()->getStability(), ['alpha', 'beta', 'RC']);

        // Format release notes with severity indicator
        $formattedNotes = GitHubReleaseCreator::formatReleaseNotes($logNotes, $topSeverity);

        $release = $this->githubReleaseCreator->createRelease(
            localDir: (string) $this->directory,
            tagName: $releaseTag,
            releaseName: $releaseName,
            releaseBody: $formattedNotes,
            prerelease: $isPrerelease
        );

        // If release was created and box.json.dist exists, build and upload PHAR
        if ($release !== null) {
            $this->buildAndUploadPhar($release, $hordeYml);
        }

        // TODO: Post Tasks, trigger packagist and horde infra apis
        // Post release commit if needed.
    }

    /**
     * Build and upload PHAR file if box.json.dist exists and box is available
     *
     * @param \Horde\GithubApiClient\GithubRelease $release The GitHub release to upload to
     * @param HordeYml $hordeYml The component metadata
     */
    private function buildAndUploadPhar(\Horde\GithubApiClient\GithubRelease $release, HordeYml $hordeYml): void
    {
        $boxConfig = $this->directory . '/box.json.dist';

        // Check if box.json.dist exists
        if (!file_exists($boxConfig)) {
            $this->output->info('No box.json.dist found, skipping PHAR build');
            return;
        }

        // Check if box utility is available
        $boxPath = trim((string) shell_exec('which box 2>/dev/null'));
        if (empty($boxPath)) {
            $this->output->warn('Box utility not found in PATH, skipping PHAR build');
            $this->output->help('Install box from https://github.com/box-project/box');
            return;
        }

        $this->output->info('Building PHAR with Box...');

        // Ensure build directory exists
        $buildDir = $this->directory . '/build';
        if (!is_dir($buildDir)) {
            $this->output->info('Creating build/ directory');
            mkdir($buildDir, 0755, true);
        }

        // Build the PHAR
        $buildCommand = sprintf(
            'cd %s && %s compile --config=%s 2>&1',
            escapeshellarg((string) $this->directory),
            escapeshellarg($boxPath),
            escapeshellarg('box.json.dist')
        );

        $buildOutput = [];
        $returnCode = 0;
        exec($buildCommand, $buildOutput, $returnCode);

        if ($returnCode !== 0) {
            $this->output->warn('Failed to build PHAR:');
            $this->output->plain(implode("\n", $buildOutput));
            return;
        }

        $this->output->ok('PHAR built successfully');

        // Look for the PHAR file in build/ subdirectory
        $buildDir = $this->directory . '/build';
        if (!is_dir($buildDir)) {
            $this->output->warn("Build directory not found: {$buildDir}");
            return;
        }

        // Find PHAR files in build directory
        $pharFiles = glob($buildDir . '/*.phar');
        if (empty($pharFiles)) {
            $this->output->warn('No PHAR files found in build/ directory');
            return;
        }

        // Use the first PHAR file found
        $pharPath = $pharFiles[0];
        $this->output->info("Found PHAR: " . basename($pharPath));

        // Create versioned asset name
        // Extract basename without .phar extension, then add version and .phar
        $baseName = basename($pharPath, '.phar');
        $version = $hordeYml->getReleaseVersion()->toFullSemverV2();
        $assetName = "{$baseName}-{$version}.phar";

        // Upload the PHAR as a release asset
        $uploadSuccess = $this->githubReleaseCreator->uploadPharAsset(
            $release,
            $pharPath,
            $assetName
        );

        // Rename the local PHAR file to match the versioned name
        if ($uploadSuccess) {
            $versionedPath = dirname($pharPath) . '/' . $assetName;
            if (rename($pharPath, $versionedPath)) {
                $this->output->ok("Renamed local PHAR to: {$assetName}");
            } else {
                $this->output->warn("Failed to rename local PHAR file");
            }
        }
    }


    public static function findFile(
        string $sourceRootDir,
        string $sourceFilename,
    ): ?SplFileInfo {
        $recDirIterator = new RecursiveDirectoryIterator($sourceRootDir);
        $recIteratorIterator = new RecursiveIteratorIterator($recDirIterator);
        foreach ($recIteratorIterator as $splFileInfo) {
            if ($splFileInfo->getFilename() === $sourceFilename) {
                return $splFileInfo;
            }
        }
        return null;
    }

}
