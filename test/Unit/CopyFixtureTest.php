<?php

/**
 * Test the copyFixture functionality.
 *
 * @category   Horde
 * @package    Components
 * @subpackage UnitTests
 */

namespace Horde\Components\Unit;

use Horde\Components\Test\TestCase;
use RuntimeException;

/**
 * Test copyFixture infrastructure.
 *
 * @category   Horde
 * @package    Components
 * @subpackage UnitTests
 * @coversNothing
 */
class CopyFixtureTest extends TestCase
{
    public function testCopyFixtureCreatesIsolatedCopy()
    {
        $tempFixture = $this->copyFixture('simple');

        // Temp fixture should exist
        $this->assertDirectoryExists($tempFixture);

        // Should contain fixture files
        $this->assertFileExists($tempFixture . '/.horde.yml');
        $this->assertFileExists($tempFixture . '/composer.json');

        // Should be different path from original
        $originalFixture = __DIR__ . '/../fixtures/simple';
        $this->assertNotEquals(realpath($originalFixture), realpath($tempFixture));
    }

    public function testModifyingCopyDoesNotAffectOriginal()
    {
        $tempFixture = $this->copyFixture('simple');
        $originalFixture = __DIR__ . '/../fixtures/simple';

        // Modify file in temp copy
        $testFile = $tempFixture . '/test-modification.txt';
        file_put_contents($testFile, 'test content');

        // Temp should have the file
        $this->assertFileExists($testFile);

        // Original should not
        $this->assertFileDoesNotExist($originalFixture . '/test-modification.txt');
    }

    public function testCleanupRemovesTempFixture()
    {
        $tempFixture = $this->copyFixture('simple');
        $tempPath = $tempFixture;

        // Fixture exists during test
        $this->assertDirectoryExists($tempPath);

        // Manually call tearDown to test cleanup
        $this->tearDown();

        // Fixture should be removed
        $this->assertDirectoryDoesNotExist($tempPath);
    }

    public function testMultipleCopiesAreIndependent()
    {
        $fixture1 = $this->copyFixture('simple');
        $fixture2 = $this->copyFixture('simple');

        // Should be different paths
        $this->assertNotEquals($fixture1, $fixture2);

        // Modify one
        file_put_contents($fixture1 . '/test1.txt', 'first');

        // Other should not have it
        $this->assertFileDoesNotExist($fixture2 . '/test1.txt');
    }

    public function testInvalidFixtureThrowsException()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Fixture not found');

        $this->copyFixture('nonexistent-fixture');
    }
}
