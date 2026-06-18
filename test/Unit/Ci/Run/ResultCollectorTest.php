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

namespace Horde\Components\Test\Unit\Ci\Run;

use Horde\Components\Ci\Run\ResultCollector;
use Horde\Components\Output;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ResultCollector — specifically the W2 missing-vs-passed contract.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(ResultCollector::class)]
class ResultCollectorTest extends TestCase
{
    public function testAddMissingMarksLaneAsFailed(): void
    {
        $collector = new ResultCollector($this->mockOutput());
        $collector->addMissing('php8.4-dev', 'phpunit', 'No result file found');

        $this->assertFalse($collector->allPassed());
        $summary = $collector->getSummary();
        $this->assertSame(1, $summary['failed']);
        $this->assertSame(0, $summary['passed']);
        $this->assertSame(['php8.4-dev'], $summary['failed_lanes']);
    }

    public function testMissingResultRecordsExpectedShape(): void
    {
        $collector = new ResultCollector($this->mockOutput());
        $collector->addMissing('php8.4-dev', 'phpunit', 'Script not found');

        $results = $collector->getResults();
        $this->assertSame(
            [
                'success' => false,
                'exit_code' => 1,
                'missing' => true,
                'reason' => 'Script not found',
            ],
            $results['php8.4-dev']['phpunit']
        );
    }

    public function testMissingFromAddResultFromFileWhenJsonAbsent(): void
    {
        $collector = new ResultCollector($this->mockOutput());
        $collector->addResultFromFile('php8.4-dev', 'phpunit', '/nonexistent/path.json');

        $this->assertFalse($collector->allPassed());
        $results = $collector->getResults();
        $this->assertFalse($results['php8.4-dev']['phpunit']['success']);
    }

    public function testAllPassedWhenEveryToolSucceeded(): void
    {
        $collector = new ResultCollector($this->mockOutput());
        $jsonFile = $this->makeResultJson(['success' => true, 'exit_code' => 0]);

        try {
            $collector->addResultFromFile('php8.4-dev', 'phpunit', $jsonFile);
            $this->assertTrue($collector->allPassed());
        } finally {
            @unlink($jsonFile);
        }
    }

    private function mockOutput(): Output
    {
        // Output is only used for displaySummary(); these tests hit
        // the data-shape paths and do not call it.
        return $this->createMock(Output::class);
    }

    private function makeResultJson(array $payload): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rct-');
        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR));
        return $path;
    }
}
