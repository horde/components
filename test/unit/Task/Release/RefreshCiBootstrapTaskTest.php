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

namespace Horde\Components\Test\Task\Release;

use Horde\Components\Ci\Template\TemplateLocator;
use Horde\Components\Ci\Template\TemplateRenderer;
use Horde\Components\Component;
use Horde\Components\Output;
use Horde\Components\Task\Context;
use Horde\Components\Task\Release\RefreshCiBootstrapTask;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RefreshCiBootstrapTask.
 *
 * The task is deliberately filesystem-driven: every code path either
 * reads, writes, or skips an absolute path under the component
 * directory. The tests therefore build a temp component directory,
 * seed it with fixture files, run the task, and assert on the
 * resulting file contents and emitted facts.
 *
 * The shared TemplateRenderer is the real one — it pulls templates
 * from data/ci/ — so the assertions exercise the real refresh
 * pipeline end to end rather than mocking around it.
 */
#[CoversClass(RefreshCiBootstrapTask::class)]
class RefreshCiBootstrapTaskTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/horde-refresh-ci-' . uniqid();
        mkdir($this->tmpDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpDir);
    }

    public function testSkipsWhenNoBootstrapFilesPresent(): void
    {
        $task = new RefreshCiBootstrapTask($this->makeOutput());
        $result = $task->run($this->makeContext());

        $this->assertTrue($result->isSuccess());
        $this->assertStringContainsString('nothing to refresh', $result->message);
    }

    public function testSkipsWhenBootstrapFileIsCurrent(): void
    {
        $currentVersion = TemplateRenderer::getTemplateVersion();
        $this->seedBootstrap('github', $currentVersion);

        $task = new RefreshCiBootstrapTask($this->makeOutput());
        $context = $this->makeContext();
        $result = $task->run($context);

        $this->assertTrue($result->isSuccess());
        $this->assertSame([], $context->getFact('ci.refreshed'));
        $this->assertSame(0, $context->getFact('ci.refreshed_count'));
        $this->assertStringContainsString('up to date', $result->message);
    }

    public function testRefreshesOutdatedGithubBootstrap(): void
    {
        $this->seedBootstrap('github', '0.0.1');

        $task = new RefreshCiBootstrapTask($this->makeOutput());
        $context = $this->makeContext();
        $result = $task->run($context);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(['bin/ci-bootstrap.sh'], $context->getFact('ci.refreshed'));

        $refreshed = file_get_contents($this->tmpDir . '/bin/ci-bootstrap.sh');
        $this->assertNotFalse($refreshed);
        // Refreshed content still identifies as the GitHub flavour.
        $this->assertStringContainsString('(GitHub Actions)', $refreshed);
        // And carries the current template version, not the old one.
        $this->assertStringContainsString(
            '# Template version: ' . TemplateRenderer::getTemplateVersion(),
            $refreshed,
        );
        // The file is committed alongside the release.
        $this->assertSame(['bin/ci-bootstrap.sh'], $context->getOption('files'));
    }

    public function testRefreshesOutdatedLocalBootstrap(): void
    {
        $this->seedBootstrap('local', '0.0.1');

        $task = new RefreshCiBootstrapTask($this->makeOutput());
        $context = $this->makeContext();
        $result = $task->run($context);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(['bin/ci-bootstrap.sh'], $context->getFact('ci.refreshed'));

        $refreshed = file_get_contents($this->tmpDir . '/bin/ci-bootstrap.sh');
        $this->assertNotFalse($refreshed);
        // Local-mode bootstrap stays local even on refresh.
        $this->assertStringContainsString('(Local Development)', $refreshed);
        $this->assertStringNotContainsString('(GitHub Actions)', $refreshed);
    }

    public function testRefreshesOutdatedWorkflow(): void
    {
        $this->seedWorkflow('0.0.1');

        $task = new RefreshCiBootstrapTask($this->makeOutput());
        $context = $this->makeContext();
        $result = $task->run($context);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(['.github/workflows/ci.yml'], $context->getFact('ci.refreshed'));

        $refreshed = file_get_contents($this->tmpDir . '/.github/workflows/ci.yml');
        $this->assertNotFalse($refreshed);
        $this->assertStringContainsString(
            '# Template version: ' . TemplateRenderer::getTemplateVersion(),
            $refreshed,
        );
    }

    public function testPretendModeReportsButDoesNotWrite(): void
    {
        $this->seedBootstrap('github', '0.0.1');
        $original = file_get_contents($this->tmpDir . '/bin/ci-bootstrap.sh');

        $task = new RefreshCiBootstrapTask($this->makeOutput(), null, true);
        $context = $this->makeContext();
        $result = $task->run($context);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(['bin/ci-bootstrap.sh'], $context->getFact('ci.refreshed'));

        // File on disk is unchanged.
        $this->assertSame($original, file_get_contents($this->tmpDir . '/bin/ci-bootstrap.sh'));
        // Files commit list was not appended to either, because pretend mode
        // must not affect downstream task behaviour.
        $this->assertNull($context->getOption('files'));
    }

    public function testWarnsAndSkipsWhenBootstrapModeUndetectable(): void
    {
        // A bootstrap file with no recognizable mode marker. The version
        // marker is older than current to make sure mode detection — not
        // version comparison — is what gates the skip.
        $this->writeFile('bin/ci-bootstrap.sh', "#!/bin/bash\n# Some bespoke script\n# Template version: 0.0.1\necho hi\n");

        $task = new RefreshCiBootstrapTask($this->makeOutput());
        $context = $this->makeContext();
        $result = $task->run($context);

        $this->assertTrue($result->isSuccess());
        $this->assertSame([], $context->getFact('ci.refreshed'));
    }

    public function testMissingTemplateVersionMarkerIsTreatedAsOutdated(): void
    {
        // A bootstrap file with the mode marker but no version line at all.
        // TemplateVersion::isOutdated returns true when no marker is found,
        // so the file must be refreshed.
        $this->writeFile(
            'bin/ci-bootstrap.sh',
            "#!/bin/bash\n# Horde CI Bootstrap Script (GitHub Actions)\necho hi\n",
        );

        $task = new RefreshCiBootstrapTask($this->makeOutput());
        $context = $this->makeContext();
        $result = $task->run($context);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(['bin/ci-bootstrap.sh'], $context->getFact('ci.refreshed'));
    }

    // --- Helpers ---

    private function seedBootstrap(string $mode, string $version): void
    {
        $banner = $mode === 'github' ? '(GitHub Actions)' : '(Local Development)';
        $this->writeFile(
            'bin/ci-bootstrap.sh',
            "#!/bin/bash\n# Horde CI Bootstrap Script {$banner}\n# Template version: {$version}\necho hi\n",
        );
    }

    private function seedWorkflow(string $version): void
    {
        $this->writeFile(
            '.github/workflows/ci.yml',
            "# Horde CI Workflow\n# Template version: {$version}\nname: ci\n",
        );
    }

    private function writeFile(string $relativePath, string $content): void
    {
        $abs = $this->tmpDir . '/' . $relativePath;
        $dir = dirname($abs);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
        file_put_contents($abs, $content);
    }

    private function makeOutput(): Output
    {
        $output = $this->createMock(Output::class);

        return $output;
    }

    private function makeContext(): Context
    {
        // Component is an interface that does not declare
        // getComponentDirectory(), but Context::getComponentPath()
        // calls it via duck typing on the concrete implementations.
        // For the test we wrap the interface in an anonymous class
        // that adds the method.
        $tmpDir = $this->tmpDir;
        $component = new class ($tmpDir) implements Component {
            public function __construct(private readonly string $dir) {}
            public function getComponentDirectory(): string { return $this->dir; }
            public function getName(): string { return 'test'; }
            public function getSummary(): string { return ''; }
            public function getDescription(): string { return ''; }
            public function getVersion(): string { return '0.0.0'; }
            public function getPreviousVersion(): string { return '0.0.0'; }
            public function getDate(): string { return ''; }
            public function getChannel(): string { return ''; }
            public function getDependencies(): array { return []; }
            public function getState($key = 'release'): string { return 'stable'; }
            public function getLeads() { return []; }
            public function getLicense() { return 'LGPL'; }
            public function getLicenseLocation(): string { return ''; }
            public function hasLocalPackageXml(): bool { return false; }
            public function getChangelogLink(): string { return ''; }
            public function getReleaseNotesPath(): string|bool { return false; }
            public function getDependencyList() { return null; }
            public function getData(): \stdClass { return new \stdClass(); }
            public function getDocumentOrigin(): ?string { return null; }
            public function updatePackage($action, $options): string { return ''; }
            public function changed($log, $options): array { return []; }
            public function timestamp($options): string { return ''; }
            public function nextVersion($version, $initial_note, $stability_api = null, $stability_release = null, $options = []) {}
            public function currentSentinel($changes, $app, $options): array { return []; }
            public function tag(string $tag, string $message, \Horde\Components\Helper\Commit $commit): string { return ''; }
            public function placeArchive(string $destination, $options = []): array { return ['']; }
            public function repositoryRoot(\Horde\Components\Helper\Root $helper): string { return ''; }
            public function installChannel(\Horde\Components\Pear\Environment $env, $options = []): void {}
            public function install(\Horde\Components\Pear\Environment $env, $options = [], $reason = ''): void {}
        };

        return new Context($component);
    }

    private function rmrf(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $child) {
            if ($child === '.' || $child === '..') {
                continue;
            }
            $this->rmrf($path . '/' . $child);
        }
        @rmdir($path);
    }
}
