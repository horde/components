<?php

/**
 * Copyright 2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

declare(strict_types=1);

namespace Horde\Components\Test\Unit\Cli;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class StartupTest extends TestCase
{
    public function testMissingCurlReportsInstallationHint(): void
    {
        $process = proc_open(
            [PHP_BINARY, '-n', __DIR__ . '/../../../bin/horde-components', '--help'],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );
        $this->assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(1, $exitCode);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('Install php-curl', $stderr);
        $this->assertStringContainsString('CLI configuration', $stderr);
        $this->assertStringNotContainsString('Fatal error', $stderr);
    }
}
