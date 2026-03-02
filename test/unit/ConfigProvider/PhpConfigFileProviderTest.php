<?php

declare(strict_types=1);

namespace Horde\Components\Test\Unit\ConfigProvider;

use Horde\Components\ConfigProvider\PhpConfigFileProvider;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Exception;

/**
 * Tests for PhpConfigFileProvider
 *
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(PhpConfigFileProvider::class)]
class PhpConfigFileProviderTest extends TestCase
{
    private string $tempDir;
    private string $tempFile;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/horde_components_test_' . uniqid();
        $this->tempFile = $this->tempDir . '/test_config.php';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function testConstructorCreatesDirectoryIfNotExists(): void
    {
        $this->assertFalse(is_dir($this->tempDir));

        new PhpConfigFileProvider($this->tempFile);

        $this->assertTrue(is_dir($this->tempDir));
    }

    public function testConstructorCreatesEmptyFileIfNotExists(): void
    {
        $this->assertFalse(file_exists($this->tempFile));

        new PhpConfigFileProvider($this->tempFile);

        $this->assertTrue(file_exists($this->tempFile));
        $content = file_get_contents($this->tempFile);
        $this->assertStringContainsString('<?php', $content);
        $this->assertStringContainsString('$conf = [];', $content);
    }

    public function testConstructorLoadsExistingFile(): void
    {
        // Create a config file manually
        mkdir($this->tempDir, 0o700, true);
        file_put_contents($this->tempFile, "<?php\n\$conf = [];\n\$conf['key1'] = 'value1';\n\$conf['key2'] = 'value2';\n");

        $provider = new PhpConfigFileProvider($this->tempFile);

        $this->assertTrue($provider->hasSetting('key1'));
        $this->assertTrue($provider->hasSetting('key2'));
        $this->assertSame('value1', $provider->getSetting('key1'));
        $this->assertSame('value2', $provider->getSetting('key2'));
    }

    public function testConstructorHandlesNullValuesAsUnset(): void
    {
        // Create a config file with null values
        mkdir($this->tempDir, 0o700, true);
        file_put_contents($this->tempFile, "<?php\n\$conf = [];\n\$conf['key1'] = 'value1';\n\$conf['key2'] = null;\n");

        $provider = new PhpConfigFileProvider($this->tempFile);

        $this->assertTrue($provider->hasSetting('key1'));
        $this->assertFalse($provider->hasSetting('key2')); // null = unset
        $this->assertTrue($provider->isUnset('key2'));
    }

    public function testHasSettingReturnsTrueForExistingKey(): void
    {
        mkdir($this->tempDir, 0o700, true);
        file_put_contents($this->tempFile, "<?php\n\$conf = [];\n\$conf['checkout.dir'] = '/path';\n");

        $provider = new PhpConfigFileProvider($this->tempFile);

        $this->assertTrue($provider->hasSetting('checkout.dir'));
    }

    public function testHasSettingReturnsFalseForNonExistingKey(): void
    {
        mkdir($this->tempDir, 0o700, true);
        file_put_contents($this->tempFile, "<?php\n\$conf = [];\n\$conf['key1'] = 'value1';\n");

        $provider = new PhpConfigFileProvider($this->tempFile);

        $this->assertFalse($provider->hasSetting('nonexistent'));
    }

    public function testGetSettingReturnsCorrectValue(): void
    {
        mkdir($this->tempDir, 0o700, true);
        file_put_contents($this->tempFile, "<?php\n\$conf = [];\n\$conf['github.token'] = 'ghp_test';\n");

        $provider = new PhpConfigFileProvider($this->tempFile);

        $this->assertSame('ghp_test', $provider->getSetting('github.token'));
    }

    public function testGetSettingThrowsExceptionForNonExistingKey(): void
    {
        $provider = new PhpConfigFileProvider($this->tempFile);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Setting 'nonexistent' not found in PhpConfigFileProvider");

        $provider->getSetting('nonexistent');
    }

    public function testIsUnsetReturnsTrueForNullValues(): void
    {
        mkdir($this->tempDir, 0o700, true);
        file_put_contents($this->tempFile, "<?php\n\$conf = [];\n\$conf['key1'] = 'value';\n\$conf['key2'] = null;\n");

        $provider = new PhpConfigFileProvider($this->tempFile);

        $this->assertFalse($provider->isUnset('key1'));
        $this->assertTrue($provider->isUnset('key2'));
    }

    public function testIsUnsetReturnsFalseForNonExistingKey(): void
    {
        $provider = new PhpConfigFileProvider($this->tempFile);

        $this->assertFalse($provider->isUnset('nonexistent'));
    }

    public function testGetAvailableKeysReturnsAllKeys(): void
    {
        mkdir($this->tempDir, 0o700, true);
        file_put_contents($this->tempFile, "<?php\n\$conf = [];\n\$conf['key1'] = 'value1';\n\$conf['key2'] = null;\n");

        $provider = new PhpConfigFileProvider($this->tempFile);

        $keys = $provider->getAvailableKeys();

        $this->assertCount(2, $keys);
        $this->assertContains('key1', $keys);
        $this->assertContains('key2', $keys); // Includes unset keys
    }

    public function testIsAvailableReturnsTrueForExistingFile(): void
    {
        $provider = new PhpConfigFileProvider($this->tempFile);

        $this->assertTrue($provider->isAvailable());
    }

    public function testSetSettingAddsNewValue(): void
    {
        $provider = new PhpConfigFileProvider($this->tempFile);

        $provider->setSetting('newkey', 'newvalue');

        $this->assertTrue($provider->hasSetting('newkey'));
        $this->assertSame('newvalue', $provider->getSetting('newkey'));
    }

    public function testSetSettingUpdatesExistingValue(): void
    {
        mkdir($this->tempDir, 0o700, true);
        file_put_contents($this->tempFile, "<?php\n\$conf = [];\n\$conf['key'] = 'oldvalue';\n");

        $provider = new PhpConfigFileProvider($this->tempFile);
        $provider->setSetting('key', 'newvalue');

        $this->assertSame('newvalue', $provider->getSetting('key'));
    }

    public function testSetSettingWithNullUnsetsValue(): void
    {
        mkdir($this->tempDir, 0o700, true);
        file_put_contents($this->tempFile, "<?php\n\$conf = [];\n\$conf['key'] = 'value';\n");

        $provider = new PhpConfigFileProvider($this->tempFile);
        $provider->setSetting('key', null);

        $this->assertFalse($provider->hasSetting('key'));
        $this->assertTrue($provider->isUnset('key'));
    }

    public function testSetSettingRemovesFromUnsetListWhenSetToValue(): void
    {
        mkdir($this->tempDir, 0o700, true);
        file_put_contents($this->tempFile, "<?php\n\$conf = [];\n\$conf['key'] = null;\n");

        $provider = new PhpConfigFileProvider($this->tempFile);
        $this->assertTrue($provider->isUnset('key'));

        $provider->setSetting('key', 'newvalue');

        $this->assertFalse($provider->isUnset('key'));
        $this->assertTrue($provider->hasSetting('key'));
        $this->assertSame('newvalue', $provider->getSetting('key'));
    }

    public function testWriteToDiskSavesSettings(): void
    {
        $provider = new PhpConfigFileProvider($this->tempFile);
        $provider->setSetting('key1', 'value1');
        $provider->setSetting('key2', 'value2');

        $provider->writeToDisk();

        // Read file directly
        $content = file_get_contents($this->tempFile);
        $this->assertStringContainsString('$conf["key1"] = "value1";', $content);
        $this->assertStringContainsString('$conf["key2"] = "value2";', $content);
    }

    public function testWriteToDiskSavesUnsetKeys(): void
    {
        $provider = new PhpConfigFileProvider($this->tempFile);
        $provider->setSetting('key1', 'value1');
        $provider->setSetting('key2', null); // Unset

        $provider->writeToDisk();

        // Read file directly
        $content = file_get_contents($this->tempFile);
        $this->assertStringContainsString('$conf["key1"] = "value1";', $content);
        $this->assertStringContainsString('$conf["key2"] = null;', $content);
        $this->assertStringContainsString('// Explicitly unset - blocks cascade', $content);
    }

    public function testWriteToDiskAndReload(): void
    {
        $provider = new PhpConfigFileProvider($this->tempFile);
        $provider->setSetting('key1', 'value1');
        $provider->setSetting('key2', null);
        $provider->writeToDisk();

        // Create new provider instance to reload
        $reloadedProvider = new PhpConfigFileProvider($this->tempFile);

        $this->assertTrue($reloadedProvider->hasSetting('key1'));
        $this->assertSame('value1', $reloadedProvider->getSetting('key1'));
        $this->assertFalse($reloadedProvider->hasSetting('key2'));
        $this->assertTrue($reloadedProvider->isUnset('key2'));
    }

    public function testHandlesEmptyStringValue(): void
    {
        $provider = new PhpConfigFileProvider($this->tempFile);
        $provider->setSetting('empty', '');

        $this->assertTrue($provider->hasSetting('empty'));
        $this->assertSame('', $provider->getSetting('empty'));
    }

    public function testHandlesSpecialCharactersInValue(): void
    {
        mkdir($this->tempDir, 0o700, true);
        file_put_contents($this->tempFile, "<?php\n\$conf = [];\n\$conf['url'] = 'https://example.com/path?query=value&foo=bar';\n");

        $provider = new PhpConfigFileProvider($this->tempFile);

        $this->assertSame('https://example.com/path?query=value&foo=bar', $provider->getSetting('url'));
    }
}
