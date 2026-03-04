<?php
/**
 * Horde\Components\Runner\Init:: scaffold new components from templates.
 *
 * Copyright 2018-2026 Horde LLC (http://www.horde.org/)
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

namespace Horde\Components\Runner;

use Horde\Components\Component;
use Horde\Components\ConfigProvider\EffectiveConfigProvider;
use Horde\Components\Exception;
use Horde\Components\Output;
use Horde\Components\Scaffolding\SkeletonLocator;
use Horde\Components\Scaffolding\ReplacementBuilder;
use Horde\Components\Scaffolding\TemplateProcessor;

/**
 * Scaffold new components from templates.
 *
 * Supports three component types:
 * - application: Full Horde application (from horde/skeleton repository)
 * - library: PSR-4 library with minimal structure
 * - theme: Theme with CSS and graphics
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Init
{
    /**
     * Constructor.
     *
     * @param EffectiveConfigProvider $config The configuration provider
     * @param Component $component The component to initialize
     * @param array $arguments CLI arguments
     * @param Output $output The output handler
     */
    public function __construct(
        private readonly EffectiveConfigProvider $config,
        private readonly Component $component,
        private readonly array $arguments,
        private readonly Output $output
    ) {}

    /**
     * Run init command.
     *
     * @throws Exception If initialization fails
     */
    public function run(): void
    {
        // If no arguments, show interactive prompt
        if (empty($this->arguments[1])) {
            $this->showInteractivePrompt();
            return;
        }

        // Get component type
        $type = $this->arguments[1];
        if (!in_array($type, ['application', 'library', 'theme'])) {
            throw new Exception(
                "Invalid component type: {$type}\n" .
                "Must be one of: application, library, theme\n\n" .
                "Run 'horde-components init' (no arguments) for interactive mode."
            );
        }

        // Build configuration
        $componentConfig = $this->buildConfig($type);

        // Validate configuration
        $this->validateConfig($componentConfig);

        // Locate skeleton template
        $this->output->info("Locating {$type} template...");
        $skeletonPath = SkeletonLocator::locate($type);
        $this->output->ok("Found template at: {$skeletonPath}");

        // Build replacement map
        $replacements = ReplacementBuilder::build($componentConfig);

        // Process template
        $processor = new TemplateProcessor($this->output);
        $targetPath = getcwd();
        $force = $this->config->hasSetting('force_overwrite') && $this->config->getSetting('force_overwrite');

        $this->output->plain('');
        $this->output->bold("=== Scaffolding {$type} component ===");
        $processor->process($skeletonPath, $targetPath, $replacements, $force);

        // Success message
        $this->output->plain('');
        $this->output->bold('=== Component initialized successfully! ===');
        $this->output->ok("Component type: {$type}");
        $this->output->ok("Component name: {$componentConfig['name']}");
        $this->output->plain('');
        $this->output->info('Next steps:');
        $this->output->info('  1. Review generated files');
        $this->output->info('  2. Run: composer install');
        $this->output->info('  3. Run: vendor/bin/phpunit');
        $this->output->info('  4. Customize for your needs');
    }

    /**
     * Show interactive prompt to guide user.
     */
    private function showInteractivePrompt(): void
    {
        $this->output->bold('=== Horde Component Init ===');
        $this->output->plain('');
        $this->output->info('This command scaffolds a new Horde component from a template.');
        $this->output->plain('');
        $this->output->bold('Usage:');
        $this->output->plain('  horde-components init <type> [options]');
        $this->output->plain('');
        $this->output->bold('Component Types:');
        $this->output->plain('  application  - Full Horde application with UI (from horde/skeleton)');
        $this->output->plain('  library      - PSR-4 library with minimal structure');
        $this->output->plain('  theme        - Theme with CSS and graphics');
        $this->output->plain('');
        $this->output->bold('Options:');
        $this->output->plain('  --name=NAME          Component name (e.g., "MyApp")');
        $this->output->plain('  --author=NAME        Author\'s full name');
        $this->output->plain('  --email=EMAIL        Author\'s email address');
        $this->output->plain('  --description=TEXT   Short description');
        $this->output->plain('  --use-license=LICENSE    License identifier (default: LGPL-2.1)');
        $this->output->plain('  --force-overwrite        Overwrite existing files');
        $this->output->plain('');
        $this->output->bold('Examples:');
        $this->output->plain('');
        $this->output->info('Create a new library:');
        $this->output->plain('  mkdir MyLibrary && cd MyLibrary');
        $this->output->plain('  horde-components init library \\');
        $this->output->plain('    --name="MyLibrary" \\');
        $this->output->plain('    --author="John Doe" \\');
        $this->output->plain('    --email="john@example.com"');
        $this->output->plain('');
        $this->output->info('Create a new application:');
        $this->output->plain('  mkdir MyApp && cd MyApp');
        $this->output->plain('  horde-components init application \\');
        $this->output->plain('    --name="MyApp" \\');
        $this->output->plain('    --author="John Doe" \\');
        $this->output->plain('    --email="john@example.com" \\');
        $this->output->plain('    --description="My cool application"');
        $this->output->plain('');
        $this->output->info('For more information, see:');
        $this->output->plain('  https://wiki.horde.org/CreatingYourFirstModule');
    }

    /**
     * Build configuration array from CLI options.
     *
     * @param string $type Component type
     * @return array Configuration array
     */
    private function buildConfig(string $type): array
    {
        // Get directory name as default component name
        $dirName = basename(getcwd());

        return [
            'name' => $this->config->hasSetting('name') ? $this->config->getSetting('name') : $dirName,
            'type' => $type,
            'author_name' => $this->config->hasSetting('author') ? $this->config->getSetting('author') : 'Unknown Author',
            'author_email' => $this->config->hasSetting('email') ? $this->config->getSetting('email') : 'unknown@example.com',
            'description' => $this->config->hasSetting('description') ? $this->config->getSetting('description') : "A Horde {$type}",
            'license_id' => $this->config->hasSetting('use_license') ? $this->config->getSetting('use_license') : 'LGPL-2.1',
        ];
    }

    /**
     * Validate configuration.
     *
     * @param array $config Configuration array
     * @throws Exception If configuration is invalid
     */
    private function validateConfig(array $config): void
    {
        $errors = [];

        // Validate name
        if (empty($config['name'])) {
            $errors[] = 'Component name is required (use --name=NAME)';
        }

        // Validate author
        if (empty($config['author_name']) || $config['author_name'] === 'Unknown Author') {
            $errors[] = 'Author name is required (use --author=NAME)';
        }

        // Validate email
        if (empty($config['author_email']) || $config['author_email'] === 'unknown@example.com') {
            $errors[] = 'Author email is required (use --email=EMAIL)';
        } elseif (!filter_var($config['author_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email address: ' . $config['author_email'];
        }

        if (!empty($errors)) {
            throw new Exception(
                "Configuration errors:\n  - " . implode("\n  - ", $errors) . "\n\n" .
                "Run 'horde-components init' (no arguments) for usage information."
            );
        }
    }
}
