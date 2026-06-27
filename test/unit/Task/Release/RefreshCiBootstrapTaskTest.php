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
use Horde\Components\Helper\PlatformResolver;
use Horde\Components\Output;
use Horde\Components\Task\Context;
use Horde\Components\Task\Release\RefreshCiBootstrapTask;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RefreshCiBootstrapTask.
 *
 * The task is filesystem-driven: every code path either reads, writes,
 * or skips an absolute path under the component directory. Tests build a
 * temp component directory, seed it with fixture files, run the task,
 * and assert on resulting file contents, emitted facts, and the
 * commit's `files` option.
 *
 * The shared TemplateRenderer is the real one - it pulls templates from
 * data/ci/ - so assertions exercise the real refresh pipeline end to
 * end. The PlatformResolver is stubbed because the real one hits
 * Packagist; tests must run offline.
 *
 * The fixture seeds a baseline `.horde.yml` and a baseline
 * `.github/workflows/ci.yml` (the cohort marker) so most tests start
 * from "this component has CI." Tests that exercise the "no CI" code
 * path delete the cohort marker explicitly.
 */
#[CoversClass(RefreshCiBootstrapTask::class)]
class RefreshCiBootstrapTaskTest extends TestCase
{
    private string $tmpDir;

    /**
     * The ci-platform block that the stub PlatformResolver returns by
     * default. Identical to what `setUp()` writes into `.horde.yml`, so
     * the platform-refresh step finds no diff and stays a no-op for
     * most tests.
     *
     * @var array<string, list<string|array<string, string>>>
     */
    private array $baselineCiPlatform = [
        '8.2' => [
            ['php' => '^8.2'],
            'ext-mbstring',
        ],
        '8.3' => [
            ['php' => '^8.2'],
            'ext-mbstring',
        ],
    ];

    /**
     * The exact PHAR URL the task's buildConfig will inject when no
     * COMPONENTS_PHAR_URL env var is set. Tests use this same URL when
     * seeding fixtures so the substance-diff sees no extraneous
     * difference.
     */
    private const TEST_PHAR_URL = 'https://example.invalid/horde-components.phar';

    private ?string $originalPharUrl = null;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/horde-refresh-ci-' . uniqid();
        mkdir($this->tmpDir, 0o755, true);

        // Pin the PHAR URL the task will read via getenv() so the
        // substance-diff between fixture-rendered content and task-
        // rendered content does not pick up a config mismatch.
        $this->originalPharUrl = getenv('COMPONENTS_PHAR_URL') ?: null;
        putenv('COMPONENTS_PHAR_URL=' . self::TEST_PHAR_URL);

        // Cohort marker: a baseline ci.yml that the substance-diff will
        // treat as identical to a freshly-rendered one (modulo
        // timestamp / template-version lines). Tests that want to
        // exercise the refresh-on-real-change path overwrite this with
        // substantively-different content; tests that want to exercise
        // the no-CI path delete it via removeCohortMarker().
        $this->seedCurrentWorkflow();

        // Baseline .horde.yml carrying a ci-platform block. The stub
        // PlatformResolver returns the same block, so the platform-
        // refresh step finds no diff. Tests that want to exercise the
        // diff overwrite either side.
        $this->seedHordeYmlWithBaselineCiPlatform();
    }

    protected function tearDown(): void
    {
        if ($this->originalPharUrl === null) {
            putenv('COMPONENTS_PHAR_URL');
        } else {
            putenv('COMPONENTS_PHAR_URL=' . $this->originalPharUrl);
        }
        $this->rmrf($this->tmpDir);
    }

    // -------- Cohort gate ------------------------------------------------

    public function testNoOpWhenNoCiYmlExists(): void
    {
        $this->removeCohortMarker();

        $task = $this->makeTask();
        $context = $this->makeContext();
        $result = $task->run($context);

        $this->assertTrue($result->isSuccess());
        $this->assertStringContainsString('skipped', $result->message);
        // Bootstrap script was not generated; ci-platform was not
        // touched; nothing was staged.
        $this->assertFileDoesNotExist($this->tmpDir . '/.github/bin/ci-bootstrap.sh');
        $this->assertNull($context->getOption('files'));
    }

    public function testNoOpWhenSkipCiRefreshOptionIsTrue(): void
    {
        $task = $this->makeTask();
        $context = $this->makeContext();
        $context->setOption('skip_ci_refresh', true);
        $result = $task->run($context);

        $this->assertTrue($result->isSuccess());
        $this->assertStringContainsString('skip_ci_refresh', $result->message);
        $this->assertNull($context->getOption('files'));
    }

    // -------- Substance-diff behaviour -----------------------------------

    public function testSubstantivelyEqualWorkflowProducesNoRefresh(): void
    {
        // setUp() seeded a baseline ci.yml that matches the current
        // template's substance. The workflow itself should observe
        // equality (after metadata-line stripping) and stage nothing.
        // The bootstrap script, however, is missing on disk - a Group C
        // component's first release. The task will write the bootstrap
        // for the first time and stage it; what the test asserts here
        // is that ci.yml does NOT show up in the refresh list because
        // it was already current.
        $task = $this->makeTask();
        $context = $this->makeContext();
        $task->run($context);

        $refreshed = $context->getFact('ci.refreshed');
        $this->assertNotContains(
            '.github/workflows/ci.yml',
            $refreshed,
            'A substantively-equal workflow must not be marked refreshed.',
        );
        $this->assertContains(
            '.github/bin/ci-bootstrap.sh',
            $refreshed,
            'Missing bootstrap is a substantive absence; rendering it counts as a refresh.',
        );

        $files = $context->getOption('files') ?? [];
        $this->assertNotContains('.github/workflows/ci.yml', $files);
    }

    public function testNoRefreshWhenBothBootstrapAndWorkflowAreSubstantivelyCurrent(): void
    {
        // Seed both targets with content that the substance-diff treats
        // as identical to a freshly-rendered one. Now the task has
        // nothing to do; the files option remains untouched.
        $renderer = new TemplateRenderer(TemplateLocator::getTemplateDir());
        $this->writeFile(
            '.github/bin/ci-bootstrap.sh',
            $renderer->render('bootstrap-github.sh', $this->bootstrapConfig()),
        );

        $task = $this->makeTask();
        $context = $this->makeContext();
        $task->run($context);

        $this->assertSame([], $context->getFact('ci.refreshed'));
        $this->assertNull(
            $context->getOption('files'),
            'No file should be staged when nothing material changed.',
        );
    }

    public function testSubstantivelyDifferentWorkflowTriggersRefresh(): void
    {
        // Replace the cohort marker with content that differs from the
        // current template in substance (not just timestamp). The
        // refresh loop must rewrite it.
        $this->writeFile(
            '.github/workflows/ci.yml',
            "name: Old CI\non: [push]\njobs: { test: { runs-on: ubuntu-latest, steps: [] } }\n",
        );

        $task = $this->makeTask();
        $context = $this->makeContext();
        $task->run($context);

        $this->assertContains(
            '.github/workflows/ci.yml',
            $context->getFact('ci.refreshed'),
        );
        $files = $context->getOption('files');
        $this->assertIsArray($files);
        $this->assertContains('.github/workflows/ci.yml', $files);

        $newCi = (string) file_get_contents($this->tmpDir . '/.github/workflows/ci.yml');
        $this->assertStringContainsString(
            '# Template version: ' . TemplateRenderer::getTemplateVersion(),
            $newCi,
        );
        $this->assertStringNotContainsString('Old CI', $newCi);
    }

    public function testTimestampOnlyDiffIsNotRefreshed(): void
    {
        // Seed a workflow file that's substantively identical to what
        // the renderer would produce but with a different
        // "# Generated: ..." timestamp. The substance-diff must
        // consider them equal and skip the refresh.
        $renderer = new TemplateRenderer(TemplateLocator::getTemplateDir());
        $currentContent = $renderer->render('workflow.yml', $this->workflowConfig());

        // Replace just the generated-date line to simulate a stale
        // timestamp.
        $stale = preg_replace(
            '/# Generated:.*$/m',
            '# Generated: 2020-01-01 00:00:00 UTC',
            $currentContent,
        );
        $this->writeFile('.github/workflows/ci.yml', (string) $stale);

        $task = $this->makeTask();
        $context = $this->makeContext();
        $task->run($context);

        $files = $context->getOption('files') ?? [];
        $this->assertNotContains(
            '.github/workflows/ci.yml',
            $files,
            'Timestamp-only diff must NOT cause a refresh',
        );

        // The on-disk file is unchanged (the stale timestamp survives).
        $this->assertStringContainsString(
            '# Generated: 2020-01-01 00:00:00 UTC',
            (string) file_get_contents($this->tmpDir . '/.github/workflows/ci.yml'),
        );
    }

    public function testTemplateVersionOnlyDiffIsNotRefreshed(): void
    {
        // Same idea for the # Template version line: a stale version
        // number with otherwise-identical content must not refresh.
        $renderer = new TemplateRenderer(TemplateLocator::getTemplateDir());
        $currentContent = $renderer->render('workflow.yml', $this->workflowConfig());

        $stale = preg_replace(
            '/# Template version:.*$/m',
            '# Template version: 0.0.1',
            $currentContent,
        );
        $this->writeFile('.github/workflows/ci.yml', (string) $stale);

        $task = $this->makeTask();
        $context = $this->makeContext();
        $task->run($context);

        $files = $context->getOption('files') ?? [];
        $this->assertNotContains('.github/workflows/ci.yml', $files);
    }

    // -------- Pretend mode -----------------------------------------------

    public function testPretendModeReportsButDoesNotWrite(): void
    {
        // Seed a substantively-stale workflow so the task wants to
        // refresh it.
        $this->writeFile(
            '.github/workflows/ci.yml',
            "name: Old CI\non: [push]\njobs: {}\n",
        );
        $original = (string) file_get_contents($this->tmpDir . '/.github/workflows/ci.yml');

        $task = $this->makeTask(pretend: true);
        $context = $this->makeContext();
        $task->run($context);

        // File on disk is unchanged in pretend mode.
        $this->assertSame(
            $original,
            (string) file_get_contents($this->tmpDir . '/.github/workflows/ci.yml'),
        );
        // No files staged in pretend mode either.
        $this->assertNull($context->getOption('files'));
    }

    // -------- Group B: bin/ -> .github/bin/ migration --------------------

    public function testMigratesLegacyBootstrapPath(): void
    {
        // Group B component: modern pipeline ci.yml exists, bootstrap
        // is at the pre-1.5.1 location.
        $this->writeFile(
            'bin/ci-bootstrap.sh',
            "#!/bin/bash\n# Horde CI Bootstrap Script (GitHub Actions)\n# Template version: 0.0.1\necho legacy\n",
        );

        $task = $this->makeTask();
        $context = $this->makeContext();
        $task->run($context);

        $this->assertTrue(
            $context->getFact('ci.legacy_path_migrated'),
            'Migration fact must be true when the legacy path was renamed.',
        );
        $this->assertFileDoesNotExist($this->tmpDir . '/bin/ci-bootstrap.sh');
        $this->assertFileExists($this->tmpDir . '/.github/bin/ci-bootstrap.sh');

        $files = $context->getOption('files');
        $this->assertIsArray($files);
        $this->assertContains('.github/bin/ci-bootstrap.sh', $files);
        $this->assertContains('bin/ci-bootstrap.sh', $files);
    }

    public function testLegacyMigrationDoesNotClobberExistingNewPath(): void
    {
        // Both paths exist: refuse to clobber. Migration is a no-op
        // success; the maintainer cleans up by hand.
        $this->writeFile(
            'bin/ci-bootstrap.sh',
            "#!/bin/bash\n# Horde CI Bootstrap Script (GitHub Actions)\n# Template version: 0.0.1\necho legacy\n",
        );
        $renderer = new TemplateRenderer(TemplateLocator::getTemplateDir());
        $this->writeFile(
            '.github/bin/ci-bootstrap.sh',
            $renderer->render('bootstrap-github.sh', $this->bootstrapConfig()),
        );

        $task = $this->makeTask();
        $context = $this->makeContext();
        $task->run($context);

        $this->assertFileExists($this->tmpDir . '/bin/ci-bootstrap.sh');
        $this->assertFileExists($this->tmpDir . '/.github/bin/ci-bootstrap.sh');
        $this->assertFalse($context->getFact('ci.legacy_path_migrated'));
    }

    // -------- Group C: legacy GitHub-managed-matrix layout ---------------

    public function testReplacesGithubManagedMatrixAndDeletesObsoleteSiblings(): void
    {
        $this->writeFile(
            '.github/workflows/ci.yml',
            "name: CI\non: [push, pull_request]\njobs:\n  test:\n    runs-on: ubuntu-latest\n    strategy:\n      matrix:\n        php: ['8.2', '8.3']\n    steps:\n      - uses: actions/checkout@v3\n      - uses: shivammathur/setup-php@v2\n        with: { php-version: \${{ matrix.php }} }\n      - run: vendor/bin/phpunit\n",
        );
        $this->writeFile(
            '.github/workflows/release.yml',
            "name: Release\non: { push: { tags: 'v*' } }\njobs: { release: { runs-on: ubuntu-latest, steps: [] } }\n",
        );
        $this->writeFile(
            '.github/workflows/update-satis.yml',
            "name: Update Satis\non: { push: { tags: 'v*' } }\njobs: { satis: { runs-on: ubuntu-latest, steps: [] } }\n",
        );
        $this->writeFile(
            '.github/workflows/phpdoc.yml',
            "name: phpDocumentor\non: { push: { branches: main } }\njobs: { docs: { runs-on: ubuntu-latest, steps: [] } }\n",
        );

        $task = $this->makeTask();
        $context = $this->makeContext();
        $result = $task->run($context);

        $this->assertTrue($result->isSuccess(), $result->message);

        $this->assertFileDoesNotExist($this->tmpDir . '/.github/workflows/release.yml');
        $this->assertFileDoesNotExist($this->tmpDir . '/.github/workflows/update-satis.yml');
        $this->assertFileExists(
            $this->tmpDir . '/.github/workflows/phpdoc.yml',
            'phpdoc.yml is orthogonal; the migration must not touch it',
        );

        $newCi = (string) file_get_contents($this->tmpDir . '/.github/workflows/ci.yml');
        $this->assertStringContainsString(
            '# Template version: ' . TemplateRenderer::getTemplateVersion(),
            $newCi,
        );
        $this->assertStringNotContainsString('shivammathur', $newCi);
        $this->assertStringContainsString('.github/bin/ci-bootstrap.sh', $newCi);

        $files = $context->getOption('files');
        $this->assertIsArray($files);
        $this->assertContains('.github/workflows/ci.yml', $files);
        $this->assertContains('.github/workflows/release.yml', $files);
        $this->assertContains('.github/workflows/update-satis.yml', $files);
        $this->assertNotContains('.github/workflows/phpdoc.yml', $files);

        $this->assertSame(
            ['.github/workflows/release.yml', '.github/workflows/update-satis.yml'],
            $context->getFact('ci.obsolete_siblings_deleted'),
        );
    }

    // -------- ci-platform refresh ----------------------------------------

    public function testCiPlatformBlockIsRefreshedWhenDifferent(): void
    {
        // Baseline .horde.yml has the original block; the stub resolver
        // returns a different one. The platform-refresh step must
        // rewrite .horde.yml and stage it.
        $changedPlatform = [
            '8.2' => [
                ['php' => '^8.2'],
                'ext-mbstring',
                'ext-curl',     // <- new extension
            ],
            '8.3' => [
                ['php' => '^8.2'],
                'ext-mbstring',
            ],
        ];

        $task = $this->makeTask(platformOverride: $changedPlatform);
        $context = $this->makeContext();
        $task->run($context);

        $this->assertTrue($context->getFact('ci.platform_refreshed'));

        $files = $context->getOption('files') ?? [];
        $this->assertContains('.horde.yml', $files);

        $yaml = (string) file_get_contents($this->tmpDir . '/.horde.yml');
        $this->assertStringContainsString('ext-curl', $yaml);
    }

    public function testCiPlatformBlockNotTouchedWhenIdentical(): void
    {
        // Default stub returns the same baseline that's already on
        // disk; the platform-refresh step finds no diff and stays a
        // no-op.
        $task = $this->makeTask();
        $context = $this->makeContext();
        $task->run($context);

        $this->assertFalse($context->getFact('ci.platform_refreshed'));
        $files = $context->getOption('files') ?? [];
        $this->assertNotContains('.horde.yml', $files);
    }

    // -------- Helpers ----------------------------------------------------

    /**
     * Build the task with a PlatformResolver stub that returns either
     * the baseline ci-platform block (default) or the override. The
     * stub never touches the network.
     *
     * @param array<string, mixed>|null $platformOverride The ci-platform
     *                                                     block to return.
     *                                                     Defaults to the
     *                                                     test's baseline.
     * @param array<string, bool>|null $flagsOverride The ci-platform-flags
     *                                                  block to return.
     *                                                  Defaults to all
     *                                                  flags false.
     */
    private function makeTask(
        bool $pretend = false,
        ?array $platformOverride = null,
        ?array $flagsOverride = null,
    ): RefreshCiBootstrapTask {
        $platform = $platformOverride ?? $this->baselineCiPlatform;
        $flags = $flagsOverride ?? ['needs_installer_plugin' => false];
        $resolver = $this->createStub(PlatformResolver::class);
        $resolver->method('resolveAndShapeForCiPlatform')->willReturn([
            'ci-platform' => $platform,
            'ci-platform-flags' => $flags,
        ]);

        return new RefreshCiBootstrapTask(
            $this->makeOutput(),
            null,
            $pretend,
            $resolver,
        );
    }

    /**
     * Seed `.github/workflows/ci.yml` with content that the
     * substance-diff will treat as identical to a freshly-rendered one
     * (timestamp / template-version lines aside). Used by setUp() so
     * most tests start from "cohort gate met, no workflow refresh
     * needed."
     */
    private function seedCurrentWorkflow(): void
    {
        $renderer = new TemplateRenderer(TemplateLocator::getTemplateDir());
        $this->writeFile(
            '.github/workflows/ci.yml',
            $renderer->render('workflow.yml', $this->workflowConfig()),
        );
    }

    /**
     * Seed `.horde.yml` carrying the baseline ci-platform and
     * ci-platform-flags blocks plus the minimal set of fields the
     * release pipeline reads (id, name, type, version.release,
     * state.release, dependencies.required.php).
     */
    private function seedHordeYmlWithBaselineCiPlatform(): void
    {
        $yaml = <<<'YAML'
            ---
            id: test
            name: Test
            type: library
            version:
              release: 1.0.0
              api: 1.0.0
            state:
              release: stable
              api: stable
            dependencies:
              required:
                php: ^8.2
            ci-platform:
              '8.2':
                - php: ^8.2
                - ext-mbstring
              '8.3':
                - php: ^8.2
                - ext-mbstring
            ci-platform-flags:
              needs_installer_plugin: false
            YAML;
        $this->writeFile('.horde.yml', $yaml);
    }

    /**
     * Delete the cohort marker so the task short-circuits as
     * "component has no CI." Used by the testNoOpWhenNoCiYmlExists
     * scenario.
     */
    private function removeCohortMarker(): void
    {
        @unlink($this->tmpDir . '/.github/workflows/ci.yml');
    }

    /**
     * Template config for workflow.yml.
     *
     * @return array<string, string>
     */
    private function workflowConfig(): array
    {
        return [
            '{{COMPONENT_NAME}}' => basename($this->tmpDir),
            '{{WORK_DIR}}' => '/tmp/horde-ci',
            '{{COMPONENTS_PHAR_URL}}' => self::TEST_PHAR_URL,
        ];
    }

    /**
     * Template config for bootstrap-github.sh.
     *
     * @return array<string, string>
     */
    private function bootstrapConfig(): array
    {
        return [
            '{{COMPONENT_NAME}}' => basename($this->tmpDir),
            '{{WORK_DIR}}' => '/tmp/horde-ci',
            '{{COMPONENTS_PHAR_URL}}' => self::TEST_PHAR_URL,
        ];
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
        return $this->createStub(Output::class);
    }

    private function makeContext(): Context
    {
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
