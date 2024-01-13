<?php
declare(strict_types=1);
namespace Horde\Components\Runner;

use Horde\Components\Composer\InstallationDirectory;
use Horde\Components\Composer\PathRepositoryDefinition;
use Horde\Components\Config;
use Horde\Components\RuntimeContext\GitCheckoutDirectory;
use Horde\Components\Output;
use Horde\Components\Wrapper\HordeYml;
use stdClass;

class InstallRunner
{
    public function __construct(
        private GitCheckoutDirectory $gitCheckoutDirectory,
        private InstallationDirectory $installationDirectory,
        private readonly Output $output,
    )
    {
    }

    public function run(Config $config)
    {
        if (!$this->gitCheckoutDirectory->exists() || count($this->gitCheckoutDirectory->getGitDirs()) == 0)   {
            $this->output->warn("The developer checkout directory is missing or empty: " . $this->gitCheckoutDirectory);
            $this->output->help("Run horde-components github-clone-org");
            return;
        }
        if (!$this->installationDirectory->exists()) {
            $this->output->info("Installation directory is missing: " . $this->installationDirectory);
            if (mkdir((string) $this->installationDirectory, recursive: true)) {
                $this->output->ok("Created installation directory: " . $this->installationDirectory);
            } else {
                $this->output->fail("Could not create installation directory: " . $this->installationDirectory);
                return;
            }
        }
        if (!$this->installationDirectory->hasComposerJson()) {
            // TODO: Make this more flexbible
            $targetVersion = 'dev-FRAMEWORK_6_0';
            // TODO: Turn this into a proper class
            $repository = new stdClass();
            $repository->url = $this->gitCheckoutDirectory . DIRECTORY_SEPARATOR . 'bundle';
            $repository->type = 'path';
            $repository->options = new stdClass;
            $repository->options->symlink = false;

            $commandString = sprintf(
                "COMPOSER_ALLOW_SUPERUSER=1 composer create-project horde/bundle %s %s --no-install --keep-vcs --repository='%s'",
                $this->installationDirectory,
                $targetVersion,
                json_encode($repository)
            );
            // TODO: Hook into composer instead
            $outputString = $resultCode = null;
            exec($commandString, $outputString, $resultCode);
        }
        // Inject all horde apps as local sources.
        $composerJson = $this->installationDirectory->getComposerJson();
        foreach ($this->gitCheckoutDirectory->getHordeYmlDirs() as $hordeYmlDir) {
            // Load HordeYml to get the ComponentVersion
            $hordeYml = new HordeYml($hordeYmlDir);

            $composerJson->getRepositoryList()->ensurePresent(new PathRepositoryDefinition($hordeYmlDir));
        }
        $composerJson->writeFile($this->installationDirectory->getComposerJsonPath());
        //
    }
}