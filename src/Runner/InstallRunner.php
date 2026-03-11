<?php

declare(strict_types=1);

namespace Horde\Components\Runner;

use Horde\Components\Composer\InstallationDirectory;
use Horde\Components\Composer\PathRepositoryDefinition;
use Horde\Components\RuntimeContext\GitCheckoutDirectory;
use Horde\Components\Output;
use Horde\Components\Wrapper\HordeYml;
use Horde\Composer\RecursiveCopy;
use stdClass;
use Exception;

class InstallRunner
{
    public function __construct(
        private GitCheckoutDirectory $gitCheckoutDirectory,
        private InstallationDirectory $installationDirectory,
        private readonly Output $output,
    ) {}

    public function run()
    {

        // TODO: Make this more flexbible
        $targetVersion = 'dev-FRAMEWORK_6_0';
        $baseComponent = 'horde/bundle';
        if (!$this->gitCheckoutDirectory->exists() || $this->gitCheckoutDirectory->getGitDirs()->count() == 0) {
            $this->output->warn("The developer checkout directory is missing or empty: " . $this->gitCheckoutDirectory);
            $this->output->help("Run horde-components github-clone-org");
            return;
        }
        $baseComponentGitDir = $this->gitCheckoutDirectory->getGitDir($baseComponent);
        $this->output->OK("Using Git Checkout Directory: " . $this->gitCheckoutDirectory);
        if (!$this->installationDirectory->exists()) {
            $this->output->info("Installation directory is missing: " . $this->installationDirectory);
            if (mkdir((string) $this->installationDirectory, recursive: true)) {
                $this->output->ok("Created installation directory: " . $this->installationDirectory);
            } else {
                $this->output->fail("Could not create installation directory: " . $this->installationDirectory);
                return;
            }
        }
        $this->output->OK("Using Web Tree Directory: " . $this->installationDirectory);
        if (!$this->installationDirectory->hasComposerJson()) {

            $repository = new PathRepositoryDefinition(
                $this->gitCheckoutDirectory,
                (object) [
                    'url' => $this->gitCheckoutDirectory . DIRECTORY_SEPARATOR . $baseComponent,
                    'options' => (object) [
                        'symlink' => false,
                        'versions' => [
                            $baseComponent => $targetVersion,
                        ],
                    ],
                ]
            );
            /*
            $commandString = sprintf(
                "COMPOSER_ALLOW_SUPERUSER=1 composer create-project horde/bundle %s %s --no-install --keep-vcs --repository='%s'",
                $this->installationDirectory,
                $targetVersion,
                json_encode($repository->dumpStdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR, 512) // TODO: Check if this is needed
            );
            // TODO: Hook into composer instead
            $outputString = $resultCode = null;
            print($commandString);
            exec($commandString, $outputString, $resultCode);
            */

        }
        // TODO: If the root bundle component from the git dir was already "installed" in situ, it might contain symlink garbage under /var or /web mixed with genuine package content
        $copyHelper = new RecursiveCopy(
            (string) $baseComponentGitDir,
            (string) $this->installationDirectory,
            filter: [
                'vendor',
                'composer.lock',
            ],
        );
        $copyHelper->copy();
        // Inject all horde apps as local sources.
        try {
            $composerJson = $this->installationDirectory->getComposerJson();
        } catch (Exception $e) {
            $this->output->fail('Could not read composer.json file from installation directory: ' . $this->installationDirectory);
            return;
        }
        foreach ($this->gitCheckoutDirectory->getHordeYmlDirs() as $hordeYmlDir) {
            // Load HordeYml to get the ComponentVersion
            $hordeYml = new HordeYml($hordeYmlDir);
            $pathRepositoryOptions = ['versions' => [$hordeYml->getComposerName() => $hordeYml->getReleaseVersion()->toHordeTag()]];
            $composerJson->getRepositoryList()->ensurePresent(new PathRepositoryDefinition($hordeYmlDir, (object) $pathRepositoryOptions));
        }
        $composerJson->setPreferStable()->setMinimumStability('dev');
        $composerJson->writeFile($this->installationDirectory->getComposerJsonPath());
        $this->output->OK("Packages from git dir are set as local repositories. Only foreign packages are installed via packagist.");
        // composer install
        // Place a default horde config file in the installation directory
    }
}
