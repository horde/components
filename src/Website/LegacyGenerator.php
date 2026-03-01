<?php

/**
 * Legacy Generator Wrapper - Wraps existing generator code
 *
 * This is a temporary wrapper around the existing generator scripts.
 * TODO: Refactor into proper OOP classes in future iterations.
 *
 * PHP Version 8.2+
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Website;

use Horde\Components\Output;
use RuntimeException;
use DateTime;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

/**
 * Legacy Generator - Temporary wrapper
 *
 * Copyright 2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */
class LegacyGenerator
{
    public function __construct(
        private Output $output,
        private string $templatesDir,
        private string $cssFilename,
        private string $componentsFile
    ) {}

    public function generate(string $inputDir, string $outputDir): void
    {
        // Load and use existing generator logic
        // This preserves the working code while integrating with components

        $this->output->info("Scanning webhook events from $inputDir...");

        // Detect components root
        $componentsRoot = dirname(__DIR__, 2);

        // Include the existing generator classes
        $generatorPath = $componentsRoot . '/bin/generate-github-activity-html.php';

        if (!file_exists($generatorPath)) {
            throw new RuntimeException("Generator script not found: $generatorPath");
        }

        // Source the generator file without executing main block
        $GLOBALS['__SKIP_MAIN__'] = true;
        require_once $generatorPath;

        $scanner = new \EventScanner($inputDir);
        $rawEvents = $scanner->scan();
        $this->output->plain(sprintf("Found %d raw events.", count($rawEvents)));

        $normalizer = new \EventNormalizer();
        $events = [];
        foreach ($rawEvents as $rawEvent) {
            $normalized = $normalizer->normalize($rawEvent);
            if ($normalized !== null) {
                $events[] = $normalized;
            }
        }
        $this->output->plain(sprintf("Normalized %d events.", count($events)));

        $this->output->info("Generating complete dev.horde.org page...");

        // Include the page generator
        $pageGenPath = $componentsRoot . '/bin/generate-dev-horde-org.php';
        if (!file_exists($pageGenPath)) {
            throw new RuntimeException("Page generator not found: $pageGenPath");
        }

        require_once $pageGenPath;

        $generator = new \DevHordeOrgGenerator($this->templatesDir, $this->cssFilename, $this->componentsFile);
        $generator->generatePage(
            $events,
            $outputDir . '/index.html',
            10,  // max events per section
            2    // max events per component card
        );

        // Copy CSS to output
        $cssSource = $this->templatesDir . '/' . $this->cssFilename;
        $cssDest = $outputDir . '/' . $this->cssFilename;

        if (file_exists($cssSource)) {
            copy($cssSource, $cssDest);
            $this->output->ok("Copied CSS stylesheet");
        }
    }
}
