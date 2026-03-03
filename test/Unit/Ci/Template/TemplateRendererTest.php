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

namespace Horde\Components\Test\Unit\Ci\Template;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Horde\Components\Ci\Template\TemplateRenderer;
use Horde\Components\Exception;

/**
 * Test the CI template renderer.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(TemplateRenderer::class)]
class TemplateRendererTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/horde-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testRendersSimpleTemplate(): void
    {
        $templateContent = 'Hello {{NAME}}!';
        file_put_contents($this->tempDir . '/simple.template', $templateContent);

        $renderer = new TemplateRenderer($this->tempDir);
        $result = $renderer->render('simple', ['{{NAME}}' => 'World']);

        $this->assertStringContainsString('Hello World!', $result);
    }

    public function testAddsAutomaticVariables(): void
    {
        $templateContent = 'Version: {{TEMPLATE_VERSION}}, Generated: {{GENERATED_DATE}}, Components: {{COMPONENTS_VERSION}}';
        file_put_contents($this->tempDir . '/auto.template', $templateContent);

        $renderer = new TemplateRenderer($this->tempDir);
        $result = $renderer->render('auto', []);

        $this->assertStringContainsString('Version: ' . TemplateRenderer::getTemplateVersion(), $result);
        $this->assertStringContainsString('Generated:', $result);
        $this->assertStringContainsString('Components:', $result);
    }

    public function testThrowsExceptionForMissingTemplate(): void
    {
        $renderer = new TemplateRenderer($this->tempDir);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Template not found');

        $renderer->render('nonexistent', []);
    }

    public function testThrowsExceptionForUnreplacedVariable(): void
    {
        $templateContent = 'Hello {{NAME}}, welcome to {{PLACE}}!';
        file_put_contents($this->tempDir . '/incomplete.template', $templateContent);

        $renderer = new TemplateRenderer($this->tempDir);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unreplaced template variable');

        // Only provide NAME, not PLACE
        $renderer->render('incomplete', ['{{NAME}}' => 'Alice']);
    }

    public function testReplacesMultipleVariables(): void
    {
        $templateContent = <<<'TEMPLATE'
Component: {{COMPONENT}}
Version: {{VERSION}}
Path: {{PATH}}
TEMPLATE;
        file_put_contents($this->tempDir . '/multi.template', $templateContent);

        $renderer = new TemplateRenderer($this->tempDir);
        $result = $renderer->render('multi', [
            '{{COMPONENT}}' => 'Db',
            '{{VERSION}}' => '2.0.0',
            '{{PATH}}' => '/tmp/test',
        ]);

        $this->assertStringContainsString('Component: Db', $result);
        $this->assertStringContainsString('Version: 2.0.0', $result);
        $this->assertStringContainsString('Path: /tmp/test', $result);
    }

    public function testAcceptsVariablesWithNumbers(): void
    {
        $templateContent = 'PHP {{PHP_VERSION}} is great!';
        file_put_contents($this->tempDir . '/numbers.template', $templateContent);

        $renderer = new TemplateRenderer($this->tempDir);
        $result = $renderer->render('numbers', ['{{PHP_VERSION}}' => '8.4']);

        $this->assertStringContainsString('PHP 8.4 is great!', $result);
    }

    public function testRejectsLowercaseVariables(): void
    {
        $templateContent = 'Hello {{name}}!'; // lowercase
        file_put_contents($this->tempDir . '/lowercase.template', $templateContent);

        $renderer = new TemplateRenderer($this->tempDir);

        // Lowercase variables are not detected as variables (by design)
        // So this should succeed (no replacement happens)
        $result = $renderer->render('lowercase', []);

        // The {{name}} should remain unreplaced, and won't trigger error
        // because our regex only matches UPPER_CASE
        $this->assertStringContainsString('{{name}}', $result);
    }

    public function testGetTemplateVersionReturnsString(): void
    {
        $version = TemplateRenderer::getTemplateVersion();

        $this->assertIsString($version);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version);
    }

    public function testHandlesEmptyTemplate(): void
    {
        file_put_contents($this->tempDir . '/empty.template', '');

        $renderer = new TemplateRenderer($this->tempDir);
        $result = $renderer->render('empty', []);

        $this->assertSame('', $result);
    }

    public function testHandlesTemplateWithNoVariables(): void
    {
        $templateContent = 'This is just plain text with no variables.';
        file_put_contents($this->tempDir . '/plain.template', $templateContent);

        $renderer = new TemplateRenderer($this->tempDir);
        $result = $renderer->render('plain', []);

        $this->assertStringContainsString('plain text', $result);
    }
}
