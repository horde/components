<?php

/**
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

declare(strict_types=1);

namespace Horde\Components\Test\Unit\Ci\Setup;

use Horde\Components\Ci\Setup\ExtensionInstaller;
use Horde\Components\Output;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the per-PHP-minor extension resolution path.
 *
 * Covers the read-side: how ExtensionInstaller turns a `.horde.yml`
 * ci-platform block into per-lane extension sets. The actual apt-get
 * install path (installExtension / isExtensionInstalled / SudoHelper)
 * shells out and is intentionally not covered here — those paths need
 * an integration test with a real PHP install.
 */
#[CoversClass(ExtensionInstaller::class)]
class ExtensionInstallerTest extends TestCase
{
    private string $tmpDir = '';
    private Output $output;
    private ExtensionInstaller $installer;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/horde-ext-installer-test-' . uniqid();
        mkdir($this->tmpDir, 0o755, true);
        $this->output = $this->createMock(Output::class);
        $this->installer = new ExtensionInstaller($this->output);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            exec('rm -rf ' . escapeshellarg($this->tmpDir));
        }
    }

    public function testDetectExtensionsPerVersionFallsBackWhenNoHordeYml(): void
    {
        // No .horde.yml — every version gets the baseline (plus
        // composer.json if present, which it isn't either).
        $result = $this->installer->detectExtensionsPerVersion(
            $this->tmpDir,
            'AnyComponent',
            ['8.3', '8.4']
        );
        $this->assertSame(['8.3', '8.4'], array_keys($result));
        // Baseline contains intl, mbstring, etc. — the sentinel we check
        // for is one of the always-present values.
        $this->assertContains('intl', $result['8.3']);
        $this->assertContains('intl', $result['8.4']);
        // Both versions get the same flat fallback set.
        $this->assertSame($result['8.3'], $result['8.4']);
    }

    public function testDetectExtensionsPerVersionReadsCiPlatformList(): void
    {
        $this->writeHordeYml(<<<YAML
            id: Sample
            name: Sample
            type: library
            version:
              release: 1.0.0
            state:
              release: stable
            dependencies:
              required:
                php: "^8.2"
            ci-platform:
              "8.3":
                - { php: "^8.2" }
                - { composer-runtime-api: "^2" }
                - ext-bcmath
                - ext-curl
              "8.4":
                - { php: "^8.2" }
                - ext-bcmath
                - ext-sodium
            YAML);

        $result = $this->installer->detectExtensionsPerVersion(
            $this->tmpDir,
            'Sample',
            ['8.3', '8.4']
        );

        // Each minor must contain the ci-platform-resolved exts ON TOP
        // of the baseline — the baseline covers what the resolver does
        // not surface (php built-ins consumers implicitly rely on).
        $this->assertContains('bcmath', $result['8.3']);
        $this->assertContains('curl', $result['8.3']);
        $this->assertNotContains('sodium', $result['8.3'], '8.3 must not pick up 8.4-only extensions');

        $this->assertContains('bcmath', $result['8.4']);
        $this->assertContains('sodium', $result['8.4']);
        // curl is in BASELINE_EXTENSIONS, so both lanes carry it
        // regardless of what ci-platform listed for that lane. We only
        // care that 8.4 got its specific ci-platform addition (sodium)
        // and 8.3 did not.
        $this->assertContains('curl', $result['8.4'], 'curl comes from baseline');
        $this->assertNotContains('sodium', $result['8.3'], '8.3 must not pick up 8.4-only ci-platform exts');

        // Composer-meta and php keys must NOT appear as installable
        // extensions — they are not ext-* and don't apt-get install.
        $this->assertNotContains('composer-runtime-api', $result['8.3']);
        $this->assertNotContains('php', $result['8.3']);
    }

    public function testDetectExtensionsPerVersionTreatsNotResolvableAsFallback(): void
    {
        $this->writeHordeYml(<<<YAML
            id: Sample
            name: Sample
            type: library
            version:
              release: 1.0.0
            state:
              release: stable
            dependencies:
              required:
                php: "^8.2"
            ci-platform:
              "8.3":
                - ext-bcmath
              "8.4": "not resolvable"
            YAML);

        $result = $this->installer->detectExtensionsPerVersion(
            $this->tmpDir,
            'Sample',
            ['8.3', '8.4']
        );

        // 8.3 uses ci-platform: bcmath plus baseline
        $this->assertContains('bcmath', $result['8.3']);

        // 8.4 falls back to flat detection because the resolver couldn't
        // produce a lock. The fallback set equals the baseline (plus any
        // composer.json hits). The maintainer fix is to chase down the
        // resolver failure; CI keeps running on baseline meanwhile.
        $this->assertNotContains('bcmath', $result['8.4']);
        $this->assertContains('intl', $result['8.4']);
    }

    public function testDetectExtensionsPerVersionRespectsMinorAbsentFromCiPlatform(): void
    {
        $this->writeHordeYml(<<<YAML
            id: Sample
            name: Sample
            type: library
            version:
              release: 1.0.0
            state:
              release: stable
            dependencies:
              required:
                php: "^8.2"
            ci-platform:
              "8.3":
                - ext-bcmath
            YAML);

        // Asking for 8.4 too: it's not in ci-platform, so it falls back.
        $result = $this->installer->detectExtensionsPerVersion(
            $this->tmpDir,
            'Sample',
            ['8.3', '8.4']
        );

        $this->assertContains('bcmath', $result['8.3']);
        $this->assertNotContains('bcmath', $result['8.4']);
    }

    public function testInstallPerVersionWithEmptyMapIsNoop(): void
    {
        // No exception, no install attempt. info() is allowed.
        // installPerVersion now returns a failure map (php version
        // -> failed ext names). Empty in -> empty out.
        $this->assertSame([], $this->installer->installPerVersion([]));
    }

    private function writeHordeYml(string $contents): void
    {
        file_put_contents($this->tmpDir . '/.horde.yml', $contents);
    }
}
