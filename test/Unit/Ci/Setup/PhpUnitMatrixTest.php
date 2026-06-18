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

namespace Horde\Components\Test\Unit\Ci\Setup;

use Horde\Components\Ci\Setup\PhpUnitMatrix;
use Horde\Components\Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the PHPUnit version selector.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(PhpUnitMatrix::class)]
class PhpUnitMatrixTest extends TestCase
{
    public function testCaret12OnPhp82IsUnsatisfiable(): void
    {
        $this->assertNull((new PhpUnitMatrix())->pick('^12', '8.2'));
    }

    public function testCaret12OnPhp83Picks125(): void
    {
        $this->assertSame('12.5', (new PhpUnitMatrix())->pick('^12', '8.3'));
    }

    public function testCaret12OnPhp84Picks125(): void
    {
        $this->assertSame('12.5', (new PhpUnitMatrix())->pick('^12', '8.4'));
    }

    public function testCaret11Or12OnPhp82Picks115(): void
    {
        $this->assertSame('11.5', (new PhpUnitMatrix())->pick('^11 || ^12', '8.2'));
    }

    public function testCaret11Or12OnPhp84Picks125(): void
    {
        // Highest-satisfying wins.
        $this->assertSame('12.5', (new PhpUnitMatrix())->pick('^11 || ^12', '8.4'));
    }

    public function testNullConstraintFallsBackToHighestForPhpVersion(): void
    {
        $matrix = new PhpUnitMatrix();
        $this->assertSame('12.5', $matrix->pick(null, '8.4'));
        $this->assertSame('11.5', $matrix->pick(null, '8.2'));
    }

    public function testEmptyConstraintTreatedAsNull(): void
    {
        $this->assertSame('12.5', (new PhpUnitMatrix())->pick('', '8.4'));
    }

    public function testInvalidConstraintFallsBackToHighestForPhpVersion(): void
    {
        // Garbage that the parser cannot understand should not crash;
        // it falls through to the PHP-only branch.
        $this->assertSame('11.5', (new PhpUnitMatrix())->pick('totally bogus', '8.2'));
    }

    public function testPickWithSourceReadsRequireDev(): void
    {
        $tmp = $this->writeComposerJson([
            'name' => 'horde/example',
            'require-dev' => ['phpunit/phpunit' => '^12'],
        ]);

        try {
            $sel = (new PhpUnitMatrix())->pickWithSource($tmp, '8.4');
            $this->assertTrue($sel->isSatisfied());
            $this->assertSame('12.5', $sel->tag);
            $this->assertNull($sel->skipReason);
        } finally {
            @unlink($tmp);
        }
    }

    public function testPickWithSourceFallsBackToRequire(): void
    {
        $tmp = $this->writeComposerJson([
            'name' => 'horde/example',
            'require' => ['phpunit/phpunit' => '^11'],
        ]);

        try {
            $sel = (new PhpUnitMatrix())->pickWithSource($tmp, '8.2');
            $this->assertSame('11.5', $sel->tag);
        } finally {
            @unlink($tmp);
        }
    }

    public function testPickWithSourceReturnsSkipReasonOnEmptyIntersection(): void
    {
        $tmp = $this->writeComposerJson([
            'name' => 'horde/example',
            'require-dev' => ['phpunit/phpunit' => '^12'],
        ]);

        try {
            $sel = (new PhpUnitMatrix())->pickWithSource($tmp, '8.2');
            $this->assertFalse($sel->isSatisfied());
            $this->assertNull($sel->tag);
            $this->assertNotNull($sel->skipReason);
            $this->assertStringContainsString('^12', $sel->skipReason);
            $this->assertStringContainsString('8.2', $sel->skipReason);
        } finally {
            @unlink($tmp);
        }
    }

    public function testReadConstraintMissingFile(): void
    {
        $this->expectException(Exception::class);
        (new PhpUnitMatrix())->readConstraint('/nonexistent/composer.json');
    }

    public function testReadConstraintInvalidJson(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'phpunitmatrix-');
        file_put_contents($tmp, '{ not valid json');

        try {
            $this->expectException(Exception::class);
            (new PhpUnitMatrix())->readConstraint($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function testReadConstraintReturnsNullWhenAbsent(): void
    {
        $tmp = $this->writeComposerJson(['name' => 'horde/example']);

        try {
            $this->assertNull((new PhpUnitMatrix())->readConstraint($tmp));
        } finally {
            @unlink($tmp);
        }
    }

    private function writeComposerJson(array $payload): string
    {
        $path = tempnam(sys_get_temp_dir(), 'phpunitmatrix-');
        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR));
        return $path;
    }
}
