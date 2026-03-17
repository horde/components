<?php

/**
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Module;

use Horde\Components\Component;
use Horde\Components\Component\ComponentDirectory;
use Horde\Components\RuntimeContext\CurrentWorkingDirectory;
use Horde\Components\Output;
use Horde\Components\Helper\Shell as ShellHelper;
use Horde\Components\Helper\GitHubReleaseCreator;
use Horde\Components\Task\Build\BuildPharTask;
use Horde\Components\Task\GitHub\UploadGitHubAssetTask;
use Horde\Components\Task\Context;
use Horde\Argv\Option;
use Exception;

/**
 * Phar module - Build and upload PHAR archives.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Phar extends Base
{
    public function getOptionGroupTitle(): string
    {
        return 'PHAR Management';
    }

    public function getOptionGroupDescription(): string
    {
        return 'Build and upload PHAR archives';
    }

    public function getOptionGroupOptions(): array
    {
        return [
            new Option(
                '--phar-name',
                [
                    'action' => 'store',
                    'help'   => 'Override PHAR filename (default: from box.json.dist)',
                ]
            ),
            new Option(
                '--release-tag',
                [
                    'action' => 'store',
                    'help'   => 'GitHub release tag to upload to (for upload command)',
                ]
            ),
        ];
    }

    public function getTitle(): string
    {
        return 'phar';
    }

    public function getUsage(): string
    {
        return 'phar <build|upload> - Build and manage PHAR archives';
    }

    public function getShortDescription(): string
    {
        return 'Build and upload PHAR archives';
    }

    public function getActions(): array
    {
        return ['phar'];
    }

    public function getHelp($action): string
    {
        return 'Build and upload PHAR archives using Box

USAGE:
    horde-components phar build [--phar-name <name>]
    horde-components phar upload [--release-tag <tag>]

SUBCOMMANDS:
    build     Build a PHAR archive using Box
    upload    Upload PHAR to latest GitHub release

BUILD COMMAND:

    Builds a PHAR archive using the Box utility and box.json.dist configuration.

    Prerequisites:
    - box.json.dist must exist in component directory
    - Box utility must be installed: composer global require humbug/box
    - Component must be in a clean state (all changes committed)

    Options:
        --phar-name <name>    Override PHAR filename (default: from box.json.dist)
        -P, --pretend         Show what would be done without building

    Examples:
        # Build PHAR with default name from box.json.dist
        horde-components phar build

        # Build with custom filename
        horde-components phar build --phar-name my-tool.phar

        # Dry run (show what would happen)
        horde-components --pretend phar build

    Output:
        - PHAR file written to component directory
        - File size and basic validation performed
        - Error if Box utility not found or PHAR invalid

UPLOAD COMMAND:

    Uploads a built PHAR to a GitHub release as an asset.

    Prerequisites:
    - PHAR must be built (run "phar build" first)
    - GitHub token must be configured (GITHUB_TOKEN env var or config)
    - GitHub release must exist for specified tag

    Options:
        --release-tag <tag>   GitHub release tag (default: latest release)
        --phar-name <name>    PHAR filename to upload
        -P, --pretend         Show what would be done without uploading

    Examples:
        # Upload to latest release
        horde-components phar upload

        # Upload to specific release tag
        horde-components phar upload --release-tag v1.0.0

        # Upload specific PHAR file
        horde-components phar upload --phar-name custom.phar --release-tag v1.0.0

        # Dry run
        horde-components --pretend phar upload

    Behavior:
        - Finds latest GitHub release if --release-tag not specified
        - Checks if asset already exists (by name and size)
        - Skips upload if identical asset exists (safe for retries)
        - Errors if asset exists with different size
        - Returns download URL after successful upload

WORKFLOW EXAMPLE:

    # 1. Build PHAR locally
    cd ~/components
    horde-components phar build

    # 2. Test PHAR
    ./build/horde-components.phar version

    # 3. Upload to latest release
    horde-components phar upload

    # Or upload to specific release
    horde-components phar upload --release-tag v1.0.0-alpha37

CONFIGURATION:

    GitHub token:
        GITHUB_TOKEN=ghp_xxxxx horde-components phar upload

    Or set in config:
        horde-components config github.token ghp_xxxxx

SEE ALSO:
    horde-components release h6    Full release workflow (includes PHAR)
    box compile                    Build PHAR manually
';
    }

    public function getContextOptionHelp(): array
    {
        return [];
    }

    public function handle(array $options, array $arguments, ?Component $component = null): bool
    {
        if (!isset($arguments[0]) || $arguments[0] !== 'phar') {
            return false;
        }

        $subcommand = $arguments[1] ?? null;

        if (!in_array($subcommand, ['build', 'upload'])) {
            $output = $this->dependencies->get(Output::class);
            if ($subcommand === null) {
                $output->info('Please specify a subcommand:');
                $output->plain('  horde-components phar build    - Build PHAR archive');
                $output->plain('  horde-components phar upload   - Upload PHAR to GitHub release');
                $output->plain('');
                $output->plain('Run "horde-components help phar" for detailed information.');
            } else {
                $output->warn("Unknown subcommand: {$subcommand}");
                $output->info('Available subcommands: build, upload');
                $output->plain('Run "horde-components help phar" for more information.');
            }
            return true;
        }

        // Resolve component from working directory
        if ($component === null) {
            $componentDirectory = new ComponentDirectory(new CurrentWorkingDirectory());
            $component = $this->dependencies
                ->getComponentFactory()
                ->createSource($componentDirectory);
        }

        $output = $this->dependencies->get(Output::class);
        $pretend = !empty($options['pretend']);

        // Create task context
        $context = new Context(
            $component,
            $options,
            getcwd()
        );

        if ($subcommand === 'build') {
            return $this->handleBuild($context, $output, $pretend);
        }

        if ($subcommand === 'upload') {
            return $this->handleUpload($context, $output, $pretend, $options);
        }

        return false;
    }

    private function handleBuild(Context $context, Output $output, bool $pretend): bool
    {
        $shellHelper = $this->dependencies->get(ShellHelper::class);
        $task = new BuildPharTask($output, $shellHelper, $pretend);

        if ($task->shouldSkip($context)) {
            $output->warn('No box.json.dist found - component does not build PHAR');
            return true;
        }

        try {
            $result = $task->run($context);

            if ($result->isSuccess()) {
                $output->ok($result->message);

                $pharPath = $result->metadata['phar_path'] ?? null;
                $pharSize = $result->metadata['phar_size'] ?? 0;

                if ($pharPath && !$pretend) {
                    $output->info("PHAR location: {$pharPath}");
                    $output->info("File size: " . $this->formatBytes($pharSize));
                }
            } else {
                $output->fail($result->message);
            }
        } catch (Exception $e) {
            $output->fail('Build failed: ' . $e->getMessage());
            return true;
        }

        return true;
    }

    private function handleUpload(Context $context, Output $output, bool $pretend, array $options): bool
    {
        $githubReleaseCreator = $this->dependencies->get(GitHubReleaseCreator::class);

        // Need to set up github.release_id fact
        $releaseTag = $options['release_tag'] ?? null;
        $componentPath = $context->getComponentPath();

        try {
            if ($releaseTag === null) {
                // Find latest release
                $output->info('Finding latest GitHub release...');
                $release = $githubReleaseCreator->getLatestRelease($componentPath);
                $releaseTag = $release->tag_name;
                $output->info("Using release: {$releaseTag}");
            } else {
                // Get specified release
                $release = $githubReleaseCreator->getRelease($componentPath, $releaseTag);
            }

            $context->setFact('github.release_id', $release->id);
            $context->setFact('github.release_tag', $release->tag_name);
        } catch (Exception $e) {
            $output->fail('Failed to find GitHub release: ' . $e->getMessage());
            return true;
        }

        // Find PHAR file
        $pharName = $options['phar_name'] ?? null;

        if ($pharName === null) {
            // Read from box.json.dist
            $boxConfig = $componentPath . '/box.json.dist';
            if (file_exists($boxConfig)) {
                $boxJson = json_decode(file_get_contents($boxConfig), true);
                $pharName = basename($boxJson['output'] ?? 'dist.phar');
            } else {
                $output->fail('No box.json.dist found and --phar-name not specified');
                return true;
            }
        }

        $pharPath = $componentPath . '/' . $pharName;

        if (!file_exists($pharPath)) {
            $output->fail("PHAR not found: {$pharPath}. Run 'phar build' first.");
            return true;
        }

        $context->setFact('phar.file_path', $pharPath);
        $context->setFact('phar.file_name', $pharName);

        // Upload
        $task = new UploadGitHubAssetTask($output, $githubReleaseCreator, $pretend);

        try {
            $result = $task->run($context);

            if ($result->isSuccess()) {
                $output->ok($result->message);

                $assetUrl = $result->metadata['asset_url'] ?? null;
                if ($assetUrl && !$pretend) {
                    $output->info("Download URL: {$assetUrl}");
                }
            } elseif ($result->isSkipped()) {
                $output->info($result->message);
            } else {
                $output->fail($result->message);
            }
        } catch (Exception $e) {
            $output->fail('Upload failed: ' . $e->getMessage());
            return true;
        }

        return true;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
