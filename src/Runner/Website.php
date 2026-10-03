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

use Horde\Components\Output;
use Horde\Components\Website\CatalogGenerator;
use Horde\Components\Website\EventScanner;
use Horde\Components\Website\EventNormalizer;
use Horde\Components\Website\PageGenerator;
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
        private readonly WebsiteConfig $config,
        private readonly Output $output
    ) {
        // Detect components root directory
        $this->componentsRoot = dirname(__DIR__, 2);
    }

    public function run(): void
    {
        $this->output->info("Generating dev.horde.org website");
        $this->output->info("  Input:      {$this->config->inputDir}");
        $this->output->info("  Output:     {$this->config->outputDir}");
        $this->output->info("  Templates:  {$this->config->templatesDir}");
        $this->output->info("  Assets:     {$this->config->assetsDir}");
        $this->output->info("  Components: {$this->config->componentsFile}");
        $this->output->info("  Redirects:  {$this->config->redirectsFile}");

        // Validate paths
        if (!is_dir($this->config->inputDir)) {
            throw new RuntimeException("Input directory not found: {$this->config->inputDir}");
        }
        if (!is_dir($this->config->templatesDir)) {
            throw new RuntimeException("Templates directory not found: {$this->config->templatesDir}");
        }
        if (!file_exists($this->config->componentsFile)) {
            throw new RuntimeException("Component catalog not found: {$this->config->componentsFile}");
        }

        // Guard the output location. There is no derived default: the user
        // must pass --web-output or set devsite.output_dir. Refuse to invent a
        // path (which under a phar would be a read-only phar:// location) and
        // refuse to create a directory whose parent does not exist, so a typo
        // fails loudly instead of scattering a site into an unexpected tree.
        if ($this->config->outputDir === null) {
            throw new RuntimeException(
                'No output directory configured. Pass --web-output <dir> or set devsite.output_dir.'
            );
        }
        $outputParent = dirname($this->config->outputDir);
        if (!is_dir($outputParent)) {
            throw new RuntimeException(
                "Output parent directory does not exist: {$outputParent}; "
                . "refusing to create {$this->config->outputDir}."
            );
        }

        // Create output directory
        if (!is_dir($this->config->outputDir)) {
            mkdir($this->config->outputDir, 0o755, true);
            $this->output->ok("Created output directory");
        }

        // Scan and normalize webhook events
        $this->output->info("Scanning webhook events from {$this->config->inputDir}...");
        $scanner = new EventScanner($this->config->inputDir);
        $rawEvents = $scanner->scan();
        $this->output->plain(sprintf("Found %d raw events.", count($rawEvents)));

        $normalizer = new EventNormalizer();
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
        $cssFilename = 'dev.horde.org-black.css';
        $generator = new PageGenerator(
            $this->config->templatesDir,
            $cssFilename,
            $this->config->componentsFile,
            $this->config->gitDir,
            $this->config->organization
        );
        $generator->generatePage(
            $events,
            $this->config->outputDir . '/index.html',
            10,  // max events per section
            2    // max events per component card
        );

        // Generate the /contribute and /resources pages
        $generator->generateContributePage($this->config->outputDir . '/contribute.html');
        $this->output->ok("Generated contribute.html");
        $generator->generateResourcesPage($this->config->outputDir . '/resources.html');
        $this->output->ok("Generated resources.html");

        // Generate legacy URL stub pages - a no-op when
        // this repo has no redirects.json or an empty redirect list.
        $stubCount = $generator->generateRedirectStubs(
            $this->config->redirectsFile,
            $this->config->outputDir
        );
        if ($stubCount > 0) {
            $this->output->ok("Generated {$stubCount} legacy URL redirect stub(s)");
        } else {
            $this->output->plain("No legacy URL redirects to generate ({$this->config->redirectsFile})");
        }

        // Copy CSS to output
        $cssSource = $this->config->assetsDir . '/' . $cssFilename;
        $cssDest = $this->config->outputDir . '/' . $cssFilename;

        if (file_exists($cssSource)) {
            copy($cssSource, $cssDest);
            $this->output->ok("Copied CSS stylesheet");
        } else {
            $this->output->warn("CSS stylesheet not found, not copied: {$cssSource}");
        }

        // Copy the shared status-widget script (see footer.html's
        // #horde-status-widget anchor; same asset used by www.horde.org).
        $statusJsSource = $this->config->assetsDir . '/' . PageGenerator::STATUS_WIDGET_JS_FILENAME;
        $statusJsDest = $this->config->outputDir . '/' . PageGenerator::STATUS_WIDGET_JS_FILENAME;

        if (file_exists($statusJsSource)) {
            copy($statusJsSource, $statusJsDest);
            $this->output->ok("Copied status-widget.js");
        } else {
            $this->output->warn("status-widget.js not found, not copied: {$statusJsSource}");
        }

        $this->output->ok("Website generated successfully!");
        $this->output->info("  Main page: {$this->config->outputDir}/index.html");
        $this->output->info("  Contribute: {$this->config->outputDir}/contribute.html");
        $this->output->info("  Resources: {$this->config->outputDir}/resources.html");
        $this->output->info("  Components: {$this->config->outputDir}/components/");
    }

    public function runCatalog(): void
    {
        $this->output->info("Updating component catalog");
        $this->output->info("  Organization: {$this->config->organization}");
        $this->output->info("  Output file: {$this->config->componentsFile}");
        if ($this->config->gitDir) {
            $this->output->info("  Git directory: {$this->config->gitDir}");
        }
        if ($this->config->token !== null) {
            $this->output->info("  Using authenticated GitHub API (higher rate limits)");
        }

        // Ensure output directory exists
        $outputDir = dirname($this->config->componentsFile);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0o755, true);
            $this->output->ok("Created output directory");
        }

        // Generate catalog. getVersionFromGit() resolves each repo as
        // <dir>/<repo>, so it needs the org directory (<git-dir>/<org>), not
        // the checkout root. gitDir is the checkout root per the shared
        // <git-dir>/<org>/<repo> convention.
        $generator = new CatalogGenerator($this->output, $this->config->token);
        $componentCheckoutDir = $this->config->gitDir !== null
            ? rtrim($this->config->gitDir, '/') . '/' . $this->config->organization
            : null;
        $exitCode = $generator->generate(
            $this->config->organization,
            $this->config->componentsFile,
            $componentCheckoutDir
        );

        if ($exitCode !== 0) {
            throw new RuntimeException("Catalog generation failed");
        }
    }
}
