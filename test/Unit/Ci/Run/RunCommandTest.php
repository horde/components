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

use Horde\Components\Ci\Run\RunCommand;
use Horde\Components\Ci\Run\ResultCollector;
use Horde\Components\Output;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for RunCommand - specifically lane script execution.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(RunCommand::class)]
class RunCommandTest extends TestCase
{
    private RunCommand $runCommand;
    private Output $output;
    private ResultCollector $collector;
    private string $tempDir;

    protected function setUp(): void
    {
        // Create mocks
        $this->output = $this->createMock(Output::class);
        $this->collector = $this->createMock(ResultCollector::class);

        // Create temp directory for test lanes
        $this->tempDir = sys_get_temp_dir() . '/horde-ci-run-test-' . uniqid();
        mkdir($this->tempDir, 0o755, true);

        // Create RunCommand
        $this->runCommand = new RunCommand(
            $this->output,
            $this->collector,
            '/usr/bin/horde-components',
            $this->tempDir
        );
    }

    protected function tearDown(): void
    {
        // Cleanup temp directory
        if (is_dir($this->tempDir)) {
            exec('rm -rf ' . escapeshellarg($this->tempDir));
        }
    }

    public function testExecutesLaneScript(): void
    {
        // Create lane structure with script
        $laneDir = $this->tempDir . '/lanes/php8.4-dev';
        $componentDir = $laneDir . '/TestComponent';
        $buildDir = $componentDir . '/build';

        mkdir($buildDir, 0o755, true);

        // Create a test lane script that succeeds
        $scriptPath = $laneDir . '/run-lane.sh';
        file_put_contents(
            $scriptPath,
            <<<'BASH'
                #!/bin/bash
                echo "Test script running"
                exit 0
                BASH
        );
        chmod($scriptPath, 0o755);

        // Mock collector expectations
        $this->collector
            ->expects($this->once())
            ->method('displaySummary');

        $this->collector
            ->expects($this->once())
            ->method('allPassed')
            ->willReturn(true);

        // Execute
        $exitCode = $this->runCommand->execute($this->tempDir);

        $this->assertSame(0, $exitCode, 'Should return 0 on success');
    }

    public function testHandlesMissingLaneScript(): void
    {
        // Create lane structure WITHOUT script
        $laneDir = $this->tempDir . '/lanes/php8.4-dev';
        $componentDir = $laneDir . '/TestComponent';

        mkdir($componentDir, 0o755, true);

        // No script created

        // Mock output to expect error message
        $this->output
            ->expects($this->atLeastOnce())
            ->method('error')
            ->with($this->stringContains('Script not found'));

        // Mock collector
        $this->collector->method('displaySummary');
        $this->collector->method('allPassed')->willReturn(false);

        // Execute
        $exitCode = $this->runCommand->execute($this->tempDir);

        $this->assertSame(1, $exitCode, 'Should return 1 on failure');
    }

    public function testHandlesNonExecutableLaneScript(): void
    {
        // Create lane structure with non-executable script
        $laneDir = $this->tempDir . '/lanes/php8.4-dev';
        $componentDir = $laneDir . '/TestComponent';

        mkdir($componentDir, 0o755, true);

        // Create script but don't make it executable
        $scriptPath = $laneDir . '/run-lane.sh';
        file_put_contents($scriptPath, "#!/bin/bash\necho test\n");
        chmod($scriptPath, 0o644); // Not executable

        // Mock output to expect error message
        $this->output
            ->expects($this->atLeastOnce())
            ->method('error')
            ->with($this->stringContains('not executable'));

        // Mock collector
        $this->collector->method('displaySummary');
        $this->collector->method('allPassed')->willReturn(false);

        // Execute
        $exitCode = $this->runCommand->execute($this->tempDir);

        $this->assertSame(1, $exitCode, 'Should return 1 on failure');
    }

    public function testCapturesLaneScriptOutput(): void
    {
        // Create lane with script that produces output
        $laneDir = $this->tempDir . '/lanes/php8.4-dev';
        $componentDir = $laneDir . '/TestComponent';
        $buildDir = $componentDir . '/build';

        mkdir($buildDir, 0o755, true);

        // Create script with specific output
        $scriptPath = $laneDir . '/run-lane.sh';
        file_put_contents(
            $scriptPath,
            <<<'BASH'
                #!/bin/bash
                echo "=== Running PHPUnit ==="
                echo "Tests: 10, Assertions: 25"
                echo "=== Running PHPStan ==="
                echo "No errors found"
                exit 0
                BASH
        );
        chmod($scriptPath, 0o755);

        // Mock output to capture script output
        $capturedOutput = [];
        $this->output
            ->method('plain')
            ->willReturnCallback(function ($text) use (&$capturedOutput) {
                $capturedOutput[] = $text;
            });

        // Mock collector
        $this->collector->method('displaySummary');
        $this->collector->method('allPassed')->willReturn(true);

        // Execute
        $this->runCommand->execute($this->tempDir);

        // Verify output was captured
        $allOutput = implode("\n", $capturedOutput);
        $this->assertStringContainsString('Running PHPUnit', $allOutput);
        $this->assertStringContainsString('Running PHPStan', $allOutput);
    }

    public function testPropagatesLaneScriptExitCode(): void
    {
        // Create lane with script that fails
        $laneDir = $this->tempDir . '/lanes/php8.4-dev';
        $componentDir = $laneDir . '/TestComponent';
        $buildDir = $componentDir . '/build';

        mkdir($buildDir, 0o755, true);

        // Create script that exits with error
        $scriptPath = $laneDir . '/run-lane.sh';
        file_put_contents(
            $scriptPath,
            <<<'BASH'
                #!/bin/bash
                echo "Tests failed"
                exit 42
                BASH
        );
        chmod($scriptPath, 0o755);

        // Mock output to expect warning about exit code
        $this->output
            ->expects($this->atLeastOnce())
            ->method('error')
            ->with($this->stringContains('exit code'));

        // Mock collector
        $this->collector->method('displaySummary');
        $this->collector->method('allPassed')->willReturn(false);

        // Execute
        $exitCode = $this->runCommand->execute($this->tempDir);

        $this->assertSame(1, $exitCode, 'RunCommand should return 1 when lane fails');
    }

    public function testDiscoversMultipleLanes(): void
    {
        // Create multiple lanes
        $lanes = [
            'php8.2-dev',
            'php8.2-stable',
            'php8.3-dev',
            'php8.3-stable',
        ];

        foreach ($lanes as $laneName) {
            $laneDir = $this->tempDir . '/lanes/' . $laneName;
            $componentDir = $laneDir . '/TestComponent';
            $buildDir = $componentDir . '/build';

            mkdir($buildDir, 0o755, true);

            // Create passing script
            $scriptPath = $laneDir . '/run-lane.sh';
            file_put_contents($scriptPath, "#!/bin/bash\nexit 0\n");
            chmod($scriptPath, 0o755);
        }

        // Mock output to capture lane info messages
        $infoMessages = [];
        $this->output
            ->method('info')
            ->willReturnCallback(function ($text) use (&$infoMessages) {
                $infoMessages[] = $text;
            });

        // Mock collector
        $this->collector->method('displaySummary');
        $this->collector->method('allPassed')->willReturn(true);

        // Execute
        $this->runCommand->execute($this->tempDir);

        // Verify all lanes were discovered
        $allInfo = implode("\n", $infoMessages);
        $this->assertStringContainsString('Found 4 test lanes', $allInfo);
    }

    public function testSkipsInvalidLaneDirectories(): void
    {
        // Create valid lane
        $validLaneDir = $this->tempDir . '/lanes/php8.4-dev';
        $componentDir = $validLaneDir . '/TestComponent';
        mkdir($componentDir, 0o755, true);

        $scriptPath = $validLaneDir . '/run-lane.sh';
        file_put_contents($scriptPath, "#!/bin/bash\nexit 0\n");
        chmod($scriptPath, 0o755);

        // Create invalid lane names that should be skipped
        mkdir($this->tempDir . '/lanes/invalid-name', 0o755, true);
        mkdir($this->tempDir . '/lanes/php-noversion', 0o755, true);
        mkdir($this->tempDir . '/lanes/php8.4', 0o755, true); // Missing stability

        // Mock collector
        $this->collector->method('displaySummary');
        $this->collector->method('allPassed')->willReturn(true);

        // Mock output to capture info
        $infoMessages = [];
        $this->output
            ->method('info')
            ->willReturnCallback(function ($text) use (&$infoMessages) {
                $infoMessages[] = $text;
            });

        // Execute
        $this->runCommand->execute($this->tempDir);

        // Should only find 1 valid lane
        $allInfo = implode("\n", $infoMessages);
        $this->assertStringContainsString('Found 1 test lanes', $allInfo);
    }

    public function testAggregatesResultsFromMultipleLanes(): void
    {
        // Create lanes with result files
        $lanes = ['php8.4-dev', 'php8.4-stable'];

        foreach ($lanes as $laneName) {
            $laneDir = $this->tempDir . '/lanes/' . $laneName;
            $componentDir = $laneDir . '/TestComponent';
            $buildDir = $componentDir . '/build';

            mkdir($buildDir, 0o755, true);

            // Create script
            $scriptPath = $laneDir . '/run-lane.sh';
            file_put_contents($scriptPath, "#!/bin/bash\nexit 0\n");
            chmod($scriptPath, 0o755);

            // Create mock result files
            file_put_contents($buildDir . '/phpunit-results-summary.json', '{"tests":10,"passed":true}');
            file_put_contents($buildDir . '/phpstan-results.json', '{"errors":0}');
        }

        // Mock collector to expect result aggregation
        $this->collector
            ->expects($this->atLeast(4)) // At least 2 lanes × 2 tools = 4 calls
            ->method('addResultFromFile');

        $this->collector->method('displaySummary');
        $this->collector->method('allPassed')->willReturn(true);

        // Execute
        $this->runCommand->execute($this->tempDir);
    }

    public function testReportsSkippedWhenResultFileMissing(): void
    {
        // Create lane with script but no result files
        $laneDir = $this->tempDir . '/lanes/php8.4-dev';
        $componentDir = $laneDir . '/TestComponent';
        $buildDir = $componentDir . '/build';

        mkdir($buildDir, 0o755, true);

        // Create script
        $scriptPath = $laneDir . '/run-lane.sh';
        file_put_contents($scriptPath, "#!/bin/bash\nexit 0\n");
        chmod($scriptPath, 0o755);

        // No result files created

        // Mock collector to expect missing-result reports
        $this->collector
            ->expects($this->atLeast(2)) // PHPUnit and PHPStan
            ->method('addMissing')
            ->with(
                $this->stringContains('php8.4-dev'),
                $this->anything(),
                $this->stringContains('No result file found')
            );

        $this->collector->method('displaySummary');
        $this->collector->method('allPassed')->willReturn(false);

        // Execute
        $this->runCommand->execute($this->tempDir);
    }

    /**
     * PHPStan watermark+1 advisory: the lane table renders a separate
     * `PHPStan Advisory` column with `+N @ L` cells when any lane
     * captured advisory data. anyLaneHasPhpStanAdvisory gates the
     * column on at least one lane having errors > 0.
     */
    public function testAnyLaneHasPhpStanAdvisoryDetectsCapturedAdvisory(): void
    {
        $method = new \ReflectionMethod(RunCommand::class, 'anyLaneHasPhpStanAdvisory');
        $method->setAccessible(true);

        // Lane with positive advisory count → true.
        $results = [
            'php8.3-dev' => [
                'phpstan' => [
                    'success' => true,
                    'advisory_next_level' => [
                        'level' => 4,
                        'errors' => 12,
                        'passing' => false,
                    ],
                ],
            ],
        ];
        $this->assertTrue($method->invoke($this->runCommand, $results));

        // Lane with advisory block but zero errors (would-be impossible
        // in practice but guard against it) → false.
        $resultsZero = [
            'php8.3-dev' => [
                'phpstan' => [
                    'success' => true,
                    'advisory_next_level' => [
                        'level' => 4,
                        'errors' => 0,
                        'passing' => true,
                    ],
                ],
            ],
        ];
        $this->assertFalse($method->invoke($this->runCommand, $resultsZero));

        // No advisory block at all → false (column suppressed).
        $resultsNone = [
            'php8.3-dev' => [
                'phpstan' => [
                    'success' => true,
                ],
            ],
        ];
        $this->assertFalse($method->invoke($this->runCommand, $resultsNone));
    }

    public function testFormatPhpStanAdvisoryForTableProducesCompactCell(): void
    {
        $method = new \ReflectionMethod(RunCommand::class, 'formatPhpStanAdvisoryForTable');
        $method->setAccessible(true);

        // Positive advisory: "+N @ L"
        $this->assertSame(
            '+12 @ 4',
            $method->invoke($this->runCommand, [
                'advisory_next_level' => [
                    'level' => 4,
                    'errors' => 12,
                    'passing' => false,
                ],
            ]),
        );

        // No advisory block: dash.
        $this->assertSame(
            '-',
            $method->invoke($this->runCommand, ['success' => true]),
        );

        // Null lane result: dash.
        $this->assertSame('-', $method->invoke($this->runCommand, null));

        // Zero errors (defensive case): dash, not "+0 @ N".
        $this->assertSame(
            '-',
            $method->invoke($this->runCommand, [
                'advisory_next_level' => [
                    'level' => 9,
                    'errors' => 0,
                    'passing' => true,
                ],
            ]),
        );
    }
}
