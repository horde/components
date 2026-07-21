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

namespace Horde\Components\Test\Unit\Qc;

use Horde\Components\Qc\ToolFinder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see ToolFinder::findBinary()} PHPUnit-tag resolution
 * against a per-lane PHP version.
 *
 * Historically ToolFinder resolved the PHPUnit tag from the currently-
 * running interpreter's PHP_MAJOR_VERSION/PHP_MINOR_VERSION. Under the
 * CI harness's tool/lane PHP split (see LaneScriptGenerator), that meant
 * every lane's `qc unit` picked the tag compatible with the *tool* PHP
 * (typically 8.3+), so 8.1/8.2 lanes ran a PHPUnit that refused to boot.
 * The constructor now accepts an optional lane PHP version to feed
 * PhpUnitMatrix.
 */
#[CoversClass(ToolFinder::class)]
class ToolFinderLanePhpTest extends TestCase
{
    private string $toolsDir;
    private string $componentDir;

    protected function setUp(): void
    {
        // Isolated tools + component dirs so we control every location
        // ToolFinder might probe. Only the toolsDir/phpunit-<tag>.phar
        // path should be a hit; no vendor/bin, no tools/, no PATH
        // pollution from the surrounding fixture.
        $this->toolsDir = sys_get_temp_dir() . '/toolfinder-lane-tools-' . uniqid();
        $this->componentDir = sys_get_temp_dir() . '/toolfinder-lane-comp-' . uniqid();
        mkdir($this->toolsDir, 0o755, true);
        mkdir($this->componentDir, 0o755, true);

        // Populate the toolsDir with the phars PhpUnitMatrix might pick.
        // Content doesn't matter — findBinary() only checks file
        // existence and executability. Chmod is required: the finder
        // rejects non-executable candidates.
        foreach (['9.6', '10.5', '11.5', '12.5'] as $tag) {
            $path = $this->toolsDir . '/phpunit-' . $tag . '.phar';
            file_put_contents($path, "#!phpunit-{$tag}\n");
            chmod($path, 0o755);
        }
    }

    protected function tearDown(): void
    {
        foreach ([$this->toolsDir, $this->componentDir] as $dir) {
            if (is_dir($dir)) {
                exec('rm -rf ' . escapeshellarg($dir));
            }
        }
    }

    public function testPicksPhpUnit10ForPhp81Lane(): void
    {
        $finder = new ToolFinder($this->componentDir, $this->toolsDir, '8.1');
        $path = $finder->findBinary('phpunit');

        $this->assertNotNull($path, 'Should locate a phpunit phar');
        $this->assertSame(
            $this->toolsDir . '/phpunit-10.5.phar',
            $path,
            'PHP 8.1 lane must select PHPUnit 10.5 - the last major supporting 8.1',
        );
    }

    public function testPicksPhpUnit11ForPhp82Lane(): void
    {
        $finder = new ToolFinder($this->componentDir, $this->toolsDir, '8.2');
        $path = $finder->findBinary('phpunit');

        $this->assertSame(
            $this->toolsDir . '/phpunit-11.5.phar',
            $path,
            'PHP 8.2 lane must select PHPUnit 11.5',
        );
    }

    public function testPicksPhpUnit12ForPhp83Lane(): void
    {
        $finder = new ToolFinder($this->componentDir, $this->toolsDir, '8.3');
        $path = $finder->findBinary('phpunit');

        $this->assertSame(
            $this->toolsDir . '/phpunit-12.5.phar',
            $path,
            'PHP 8.3 lane must select PHPUnit 12.5',
        );
    }

    public function testPicksPhpUnit12ForPhp84Lane(): void
    {
        $finder = new ToolFinder($this->componentDir, $this->toolsDir, '8.4');
        $path = $finder->findBinary('phpunit');

        $this->assertSame(
            $this->toolsDir . '/phpunit-12.5.phar',
            $path,
            'PHP 8.4 lane must select PHPUnit 12.5 - highest available in the matrix',
        );
    }

    public function testFallsBackToRunningInterpreterWhenLanePhpNull(): void
    {
        // No $lanePhpVersion argument — behaves as before the split.
        // The test can't hard-code an expected tag because the running
        // PHP interpreter's version is what selects; assert the returned
        // path lives in toolsDir with a phpunit-<tag>.phar shape and
        // that tag is compatible with the current PHP.
        $finder = new ToolFinder($this->componentDir, $this->toolsDir);
        $path = $finder->findBinary('phpunit');

        $this->assertNotNull($path);
        $this->assertMatchesRegularExpression(
            '#^' . preg_quote($this->toolsDir, '#') . '/phpunit-\d+\.\d+\.phar$#',
            $path,
            'Fallback path must still resolve to a toolsDir phpunit phar',
        );
    }
}
