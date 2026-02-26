<?php

namespace Horde\Components\Release;

use Horde\Components\Helper\Git as GitHelper;
use Horde\Components\Helper\Composer as ComposerHelper;
use Horde\Components\Helper\ConventionalCommitHelper;
use Horde\Components\Wrapper\HordeYml;
use Horde\Components\Wrapper\ChangelogYml;
use Horde\Components\Component\ComponentDirectory;
use Horde\Components\Exception;
use Horde\Components\Wrapper\ComposerJson;
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
    ) {}
    /**
     * Run the release flow. Most steps should be idempotent.
     */
    public function run()
    {
        $currentBranch = $this->gitHelper->getCurrentBranch($this->directory);
        // Check if we are on release branch
        if ($currentBranch !== 'FRAMEWORK_6_0') {
            throw new Exception('Not on release branch. Please switch to the release branch before running this script.');
        }
        // Read ConventionalCommits & expected next version
        $history = new ConventionalCommitHelper($this->gitHelper);
        // Bail out on inappropriate, i.e. no conventional commits included
        if (count($history->commitReader->getLog()) == 0) {
            throw new Exception('No conventional commits found since last tag. Please ensure you have made commits in the correct format.');
        }

        // Precheck: If remotes are configured, check if the next version tag already exists
        if ($this->gitHelper->hasRemotes((string) $this->directory)) {
            $nextVersion = $history->nextVersion;
            $nextTag = $nextVersion->toHordeTag();

            if ($this->gitHelper->remoteTagExists((string) $this->directory, $nextTag)) {
                throw new Exception(
                    sprintf(
                        'Tag "%s" already exists on remote. Cannot release version %s again.',
                        $nextTag,
                        $nextVersion->toFullSemverV2()
                    )
                );
            }
        }

        // write .horde.yml versions and stabilities
        $hordeYml = new HordeYml($this->directory);
        $hordeYml->setReleaseVersionAndStability($history->nextVersion);
        // TODO: Logic on when to set API version and stability
        // $hordeYml->setApiVersionAndStability($history->getNextVersion());
        $hordeYml->save();
        // While apps used to have changelog.yml in doc/, libs had it in doc/long/lib/name - simplify this
        // write changelog.yml
        $splFileInfo = self::findFile((string) $this->directory . '/doc', 'changelog.yml');
        if ($splFileInfo && ($splFileInfo->getPathname() !== $this->directory . '/doc/changelog.yml')) {
            $this->gitHelper->moveFile($splFileInfo->getPathname(), $this->directory . '/doc/changelog.yml');
        }

        $changelog = new ChangelogYml($this->directory . '/doc');
        $logNotes = '';
        foreach ($history->commitReader->getLog() as $commit) {
            // TODO: Nice Format
            $logNotes .= $commit->subject . "\n";
        }
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
        // TODO: commit for release
        $this->gitHelper->add((string) $this->directory . '/lib/Application.php');
        $this->gitHelper->add((string) $this->directory . '/doc/changelog.yml');
        $this->gitHelper->add((string) $this->directory . '/.horde.yml');
        $this->gitHelper->add((string) $this->directory . '/composer.json');
        $releaseMessage = 'Release ' . $hordeYml->getReleaseVersion()->toFullSemverV2() . '  (API Version: ' . $hordeYml->getApiVersion()->toFullSemverV2() . ") \n\n" . $logNotes;
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
        // TODO: Post Tasks, trigger packagist and horde infra apis
        // Post release commit if needed.
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
