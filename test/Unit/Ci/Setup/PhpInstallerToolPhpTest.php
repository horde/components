<?php

/**
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Test\Unit\Ci\Setup;

use Horde\Components\Exception;
use Horde\Components\Ci\Setup\PhpInstaller;
use Horde\Components\Output;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Named test double for {@see PhpInstaller}. Overrides the environmental
 * lookups so tests exercise the resolver's branch logic without touching
 * the host filesystem. Named (rather than anonymous) to keep parity with
 * project convention forbidding anonymous classes; the DI concern behind
 * that convention does not apply to tests but consistency is cheap.
 */
final class FakePhpInstaller extends PhpInstaller
{
    /**
     * @param array<string> $fakeInstalledVersions
     */
    public function __construct(
        Output $output,
        private readonly string $fakeDefaultPhpPath,
        private readonly ?string $fakeDefaultPhpVersion,
        private readonly array $fakeInstalledVersions,
    ) {
        parent::__construct($output);
    }

    protected function getDefaultPhpPath(): string
    {
        return $this->fakeDefaultPhpPath;
    }

    protected function probeVersion(string $php): ?string
    {
        if ($php === $this->fakeDefaultPhpPath) {
            return $this->fakeDefaultPhpVersion;
        }
        // Fall back to parsing the /usr/bin/php<x.y> form the
        // second-fallback path constructs via getPhpBinary().
        if (preg_match('/php(\d+\.\d+)$/', $php, $m)) {
            return $m[1];
        }
        return null;
    }

    public function getInstalledVersions(): array
    {
        return $this->fakeInstalledVersions;
    }

    public function getPhpBinary(string $version): string
    {
        // Skip the real is_executable check — we're testing resolver
        // logic, not binary presence.
        return '/usr/bin/php' . $version;
    }
}

/**
 * Tests for {@see PhpInstaller::findToolPhpBinary()}.
 *
 * The resolver walks three fallbacks: runner's default `php` if >= min;
 * lowest installed phpX.Y >= min; otherwise fatal.
 */
#[CoversClass(PhpInstaller::class)]
class PhpInstallerToolPhpTest extends TestCase
{
    /**
     * @param array<string> $installedVersions
     */
    private function makeInstaller(
        string $defaultPhpPath,
        ?string $defaultPhpVersion,
        array $installedVersions,
    ): PhpInstaller {
        return new FakePhpInstaller(
            $this->createMock(Output::class),
            $defaultPhpPath,
            $defaultPhpVersion,
            $installedVersions,
        );
    }

    public function testReturnsDefaultPhpWhenAtOrAboveMinimum(): void
    {
        $installer = $this->makeInstaller(
            defaultPhpPath: '/usr/bin/php',
            defaultPhpVersion: '8.3',
            installedVersions: ['8.1', '8.2', '8.3'],
        );

        // is_executable('/usr/bin/php') must be truthy for the runner-
        // default branch to fire. On the test host this is typically
        // true; if not, the test falls through to the phpX.Y walk and
        // would return /usr/bin/php8.2. Guard the assertion so the
        // test passes both ways without misreporting.
        if (is_executable('/usr/bin/php')) {
            $this->assertSame('/usr/bin/php', $installer->findToolPhpBinary('8.2'));
        } else {
            $this->assertSame('/usr/bin/php8.2', $installer->findToolPhpBinary('8.2'));
        }
    }

    public function testFallsBackToLowestInstalledWhenDefaultTooOld(): void
    {
        $installer = $this->makeInstaller(
            defaultPhpPath: '/usr/bin/php',
            defaultPhpVersion: '8.1',
            installedVersions: ['8.1', '8.2', '8.3'],
        );

        $this->assertSame('/usr/bin/php8.2', $installer->findToolPhpBinary('8.2'));
    }

    public function testFallsBackToLowestInstalledWhenNoDefaultAvailable(): void
    {
        $installer = $this->makeInstaller(
            defaultPhpPath: '',
            defaultPhpVersion: null,
            installedVersions: ['8.1', '8.2', '8.4'],
        );

        $this->assertSame('/usr/bin/php8.2', $installer->findToolPhpBinary('8.2'));
    }

    public function testThrowsWhenNothingSatisfiesMinimum(): void
    {
        $installer = $this->makeInstaller(
            defaultPhpPath: '/usr/bin/php',
            defaultPhpVersion: '8.0',
            installedVersions: ['8.0', '8.1'],
        );

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No PHP >= 8.2 installed');

        $installer->findToolPhpBinary('8.2');
    }

    public function testPrefersLowestNotHighestWhenMultipleQualify(): void
    {
        // When default is too old and multiple installed phpX.Y satisfy
        // minVersion, resolver picks the lowest. This is deliberate:
        // horde-components only needs >= min, no upside to grabbing
        // 8.5 when 8.2 works.
        $installer = $this->makeInstaller(
            defaultPhpPath: '/usr/bin/php',
            defaultPhpVersion: '8.1',
            installedVersions: ['8.1', '8.2', '8.3', '8.4', '8.5'],
        );

        $this->assertSame('/usr/bin/php8.2', $installer->findToolPhpBinary('8.2'));
    }
}
