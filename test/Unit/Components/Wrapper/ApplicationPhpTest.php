<?php

/**
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Test\Unit\Components\Wrapper;

use Horde\Components\Wrapper\ApplicationPhp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the ApplicationPhp wrapper.
 *
 * Pins the two-location semantics that horde/timeobjects exposed as a
 * regression: components with only src/Application.php (PSR-4) must
 * have their version updated at release time, not only components with
 * lib/Application.php.
 */
#[CoversClass(ApplicationPhp::class)]
class ApplicationPhpTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/horde-wrapper-applicationphp-' . uniqid();
        mkdir($this->tmpDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpDir);
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    }

    /**
     * Legacy layout: lib/Application.php only. Default constructor
     * argument keeps this working the way it always did.
     */
    public function testDefaultLocationReadsLibApplicationPhp(): void
    {
        mkdir($this->tmpDir . '/lib', 0o755, true);
        file_put_contents(
            $this->tmpDir . '/lib/Application.php',
            "<?php\nclass X { public \$version = '1.2.3'; }\n"
        );

        $w = new ApplicationPhp($this->tmpDir);
        self::assertTrue($w->exists());
        self::assertSame('1.2.3', $w->getVersion());
    }

    /**
     * Modern layout: src/Application.php only. Passing 'src' as the
     * location argument makes the wrapper target that file.
     */
    public function testSrcLocationReadsSrcApplicationPhp(): void
    {
        mkdir($this->tmpDir . '/src', 0o755, true);
        file_put_contents(
            $this->tmpDir . '/src/Application.php',
            "<?php\nclass X { public \$version = '3.0.0-beta2'; }\n"
        );

        $w = new ApplicationPhp($this->tmpDir, 'src');
        self::assertTrue($w->exists());
        self::assertSame('3.0.0-beta2', $w->getVersion());
    }

    public function testSetVersionWritesToLibByDefault(): void
    {
        mkdir($this->tmpDir . '/lib', 0o755, true);
        file_put_contents(
            $this->tmpDir . '/lib/Application.php',
            "<?php\nclass X { public \$version = '1.0.0'; }\n"
        );

        $w = new ApplicationPhp($this->tmpDir);
        $w->setVersion('1.0.1');
        $w->save();

        self::assertStringContainsString(
            "public \$version = '1.0.1';",
            file_get_contents($this->tmpDir . '/lib/Application.php')
        );
    }

    /**
     * Regression for horde/timeobjects: a release-time version bump
     * has to update src/Application.php (not only lib/) or the
     * running app reports a stale version.
     */
    public function testSetVersionWritesToSrcWhenLocationIsSrc(): void
    {
        mkdir($this->tmpDir . '/src', 0o755, true);
        file_put_contents(
            $this->tmpDir . '/src/Application.php',
            "<?php\nclass X { public \$version = '3.0.0-beta2'; }\n"
        );

        $w = new ApplicationPhp($this->tmpDir, 'src');
        $w->setVersion('3.0.0-beta4');
        $w->save();

        self::assertStringContainsString(
            "public \$version = '3.0.0-beta4';",
            file_get_contents($this->tmpDir . '/src/Application.php')
        );
    }

    /**
     * With only src/Application.php present, the default (lib)
     * location correctly reports no file and does not accidentally
     * open the src copy. The caller in Source::_setVersion iterates
     * both locations; each wrapper is scoped to its own directory.
     */
    public function testDefaultLocationDoesNotFallBackToSrc(): void
    {
        mkdir($this->tmpDir . '/src', 0o755, true);
        file_put_contents(
            $this->tmpDir . '/src/Application.php',
            "<?php\nclass X { public \$version = '3.0.0-beta2'; }\n"
        );

        $w = new ApplicationPhp($this->tmpDir);
        self::assertFalse($w->exists());
    }

    public function testBundlePhpTakesPrecedenceOverApplicationPhp(): void
    {
        mkdir($this->tmpDir . '/lib', 0o755, true);
        file_put_contents(
            $this->tmpDir . '/lib/Bundle.php',
            "<?php\nclass X { const VERSION = '5.0.0'; }\n"
        );
        file_put_contents(
            $this->tmpDir . '/lib/Application.php',
            "<?php\nclass X { public \$version = '1.0.0'; }\n"
        );

        $w = new ApplicationPhp($this->tmpDir);
        self::assertTrue($w->isBundle());
        self::assertSame('5.0.0', $w->getVersion());
    }
}
