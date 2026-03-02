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
        $this->output->info("  Components: {$this->config->componentsFile}");

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

        // Create output directory
        if (!is_dir($this->config->outputDir)) {
            mkdir($this->config->outputDir, 0o755, true);
            $this->output->ok("Created output directory");
        }

        // Scan and normalize webhook events
        $this->output->info("Scanning webhook events from {$this->config->inputDir}...");
        $scanner = new \Horde\Components\Website\EventScanner($this->config->inputDir);
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
        $cssFilename = 'dev.horde.org-black.css';
        $generator = new \Horde\Components\Website\PageGenerator(
            $this->config->templatesDir,
            $cssFilename,
            $this->config->componentsFile
        );
        $generator->generatePage(
            $events,
            $this->config->outputDir . '/index.html',
            10,  // max events per section
            2    // max events per component card
        );

        // Copy CSS to output
        $cssSource = $this->config->templatesDir . '/' . $cssFilename;
        $cssDest = $this->config->outputDir . '/' . $cssFilename;

        if (file_exists($cssSource)) {
            copy($cssSource, $cssDest);
            $this->output->ok("Copied CSS stylesheet");
        }

        $this->output->ok("Website generated successfully!");
        $this->output->info("  Main page: {$this->config->outputDir}/index.html");
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

        // Generate catalog
        $generator = new CatalogGenerator($this->output, $this->config->token);
        $exitCode = $generator->generate(
            $this->config->organization,
            $this->config->componentsFile,
            $this->config->gitDir
        );

        if ($exitCode !== 0) {
            throw new RuntimeException("Catalog generation failed");
        }
    }
}
