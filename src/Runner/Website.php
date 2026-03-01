<?php

/**
 * Website Runner - Orchestrates dev.horde.org generation
 *
 * PHP Version 8.2+
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Runner;

use Horde\Components\Config;
use Horde\Components\Output;
use Horde\Components\Website\CatalogGenerator;
use Horde\GithubApiClient\GithubApiConfig;
use Horde\Injector\Injector;
use RuntimeException;

/**
 * Website Runner - Orchestrates dev.horde.org generation
 *
 * Copyright 2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Website
{
    private string $componentsRoot;

    public function __construct(
        private Injector $injector,
        private Output $output
    ) {
        // Detect components root directory
        $this->componentsRoot = dirname(__DIR__, 2);
    }

    public function run(Config $config): void
    {
        $options = $config->getOptions();

        // Resolve paths relative to components root
        $inputDir = $options['web_input'] ?? $this->componentsRoot . '/data/webhooks';
        $outputDir = $options['web_output'] ?? $this->componentsRoot . '/build/dev.horde.org';
        $templatesDir = $options['web_templates'] ?? $this->componentsRoot . '/data/website';
        $componentsFile = $options['web_components'] ?? $templatesDir . '/components.json';
        $cssFilename = 'dev.horde.org-black.css';

        $this->output->info("Generating dev.horde.org website");
        $this->output->info("  Input:      $inputDir");
        $this->output->info("  Output:     $outputDir");
        $this->output->info("  Templates:  $templatesDir");
        $this->output->info("  Components: $componentsFile");

        // Validate paths
        if (!is_dir($inputDir)) {
            throw new RuntimeException("Input directory not found: $inputDir");
        }
        if (!is_dir($templatesDir)) {
            throw new RuntimeException("Templates directory not found: $templatesDir");
        }
        if (!file_exists($componentsFile)) {
            throw new RuntimeException("Component catalog not found: $componentsFile");
        }

        // Create output directory
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
            $this->output->ok("Created output directory");
        }

        // Scan and normalize webhook events
        $this->output->info("Scanning webhook events from $inputDir...");
        $scanner = new \Horde\Components\Website\EventScanner($inputDir);
        $rawEvents = $scanner->scan();
        $this->output->plain(sprintf("Found %d raw events.", count($rawEvents)));

        $normalizer = new \Horde\Components\Website\EventNormalizer();
        $events = [];
        foreach ($rawEvents as $rawEvent) {
            $normalized = $normalizer->normalize($rawEvent);
            if ($normalized !== null) {
                $events[] = $normalized;
            }
        }
        $this->output->plain(sprintf("Normalized %d events.", count($events)));

        // Generate website
        $this->output->info("Generating complete dev.horde.org page...");
        $generator = new \Horde\Components\Website\PageGenerator(
            $templatesDir,
            $cssFilename,
            $componentsFile
        );
        $generator->generatePage(
            $events,
            $outputDir . '/index.html',
            10,  // max events per section
            2    // max events per component card
        );

        // Copy CSS to output
        $cssSource = $templatesDir . '/' . $cssFilename;
        $cssDest = $outputDir . '/' . $cssFilename;

        if (file_exists($cssSource)) {
            copy($cssSource, $cssDest);
            $this->output->ok("Copied CSS stylesheet");
        }

        $this->output->ok("Website generated successfully!");
        $this->output->info("  Main page: $outputDir/index.html");
        $this->output->info("  Components: $outputDir/components/");
    }

    public function runCatalog(Config $config): void
    {
        $options = $config->getOptions();

        // Resolve paths relative to components root
        $componentsFile = $options['web_components'] ?? $this->componentsRoot . '/data/website/components.json';
        $org = $options['web_org'] ?? 'horde';
        $gitRepoDir = $options['web_git_dir'] ?? null;

        // Token priority: --web-token > GITHUB_TOKEN env > injector config
        $token = $options['web_token'] ?? null;
        if ($token === null) {
            // Try to get from injector (already set from GITHUB_TOKEN env)
            $githubConfig = $this->injector->get(GithubApiConfig::class);
            $token = !empty($githubConfig->accessToken) ? $githubConfig->accessToken : null;
        }

        $this->output->info("Updating component catalog");
        $this->output->info("  Organization: $org");
        $this->output->info("  Output file: $componentsFile");
        if ($gitRepoDir) {
            $this->output->info("  Git directory: $gitRepoDir");
        }
        if ($token !== null) {
            $this->output->info("  Using authenticated GitHub API (higher rate limits)");
        }

        // Ensure output directory exists
        $outputDir = dirname($componentsFile);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
            $this->output->ok("Created output directory");
        }

        // Generate catalog
        $generator = new CatalogGenerator($this->output, $token);
        $exitCode = $generator->generate($org, $componentsFile, $gitRepoDir);

        if ($exitCode !== 0) {
            throw new RuntimeException("Catalog generation failed");
        }
    }
}
