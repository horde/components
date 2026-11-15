<?php

/**
 * Copyright 2026 The Horde Project (http://www.horde.org/)
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

namespace Horde\Components\Test\Task\Release;

use Horde\Components\Component;
use Horde\Components\Output;
use Horde\Components\Task\Context;
use Horde\Components\Task\Release\EnforcePhpVersionPolicyTask;
use Horde\Components\Wrapper\HordeYml;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Unit tests for EnforcePhpVersionPolicyTask.
 *
 * Each test builds a temp component directory with a .horde.yml carrying a
 * dependencies.required.php constraint and a temp branches.yml declaring
 * the release branch's PHP-version policy, runs the task, and asserts on
 * the resulting .horde.yml, the Result, and emitted facts.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(EnforcePhpVersionPolicyTask::class)]
class EnforcePhpVersionPolicyTaskTest extends TestCase
{
    private string $tmpDir;

    /** @var list<string> */
    private array $cleanupFiles = [];

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/horde-phppolicy-' . uniqid();
        mkdir($this->tmpDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpDir . '/.horde.yml');
        @rmdir($this->tmpDir);
        foreach ($this->cleanupFiles as $f) {
            @unlink($f);
        }
        $this->cleanupFiles = [];
    }

    public function testRaisesFloorToMinAndWrites(): void
    {
        $this->writeHordeYml('^8.0');
        $roles = $this->rolesFile('FRAMEWORK_6_0', 'min_php_version: "^8.2"');

        $context = $this->makeContext(['roles_file' => $roles]);
        $result = $this->makeTask()->run($context);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('^8.2', $this->requiredPhp());
        $this->assertTrue($context->getFact('php_policy.updated'));
        $this->assertSame('^8.0', $context->getFact('php_policy.previous'));
        $this->assertSame('^8.2', $context->getFact('php_policy.constraint'));
        $this->assertSame('FRAMEWORK_6_0', $context->getFact('php_policy.branch'));
    }

    public function testTightensCeilingWithExplicitMax(): void
    {
        $this->writeHordeYml('^8.1');
        $roles = $this->rolesFile(
            'FRAMEWORK_6_0',
            "min_php_version: \"^8.1\"\n    max_php_version: \"<=8.6\""
        );

        $context = $this->makeContext(['roles_file' => $roles]);
        $result = $this->makeTask()->run($context);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('>=8.1.0 <=8.6.0', $this->requiredPhp());
    }

    public function testSkippedWhenReleaseBranchHasNoPolicy(): void
    {
        $this->writeHordeYml('^8.0');
        // Bare-string release: no policy.
        $roles = tempnam(sys_get_temp_dir(), 'roles-') . '.yml';
        file_put_contents($roles, "release: FRAMEWORK_6_0\nsupported: []\nunstable: null\n");
        $this->cleanupFiles[] = $roles;
        $original = (string) file_get_contents($this->tmpDir . '/.horde.yml');

        $context = $this->makeContext(['roles_file' => $roles]);
        $result = $this->makeTask()->run($context);

        $this->assertTrue($result->isSkipped());
        $this->assertSame($original, (string) file_get_contents($this->tmpDir . '/.horde.yml'));
    }

    public function testPretendReportsButDoesNotWrite(): void
    {
        $this->writeHordeYml('^8.0');
        $roles = $this->rolesFile('FRAMEWORK_6_0', 'min_php_version: "^8.2"');
        $original = (string) file_get_contents($this->tmpDir . '/.horde.yml');

        $context = $this->makeContext(['roles_file' => $roles]);
        $result = $this->makeTask(pretend: true)->run($context);

        $this->assertTrue($result->isSuccess());
        $this->assertSame($original, (string) file_get_contents($this->tmpDir . '/.horde.yml'));
        $this->assertSame('^8.0', $this->requiredPhp());
    }

    public function testAlreadyCompliantDoesNotWrite(): void
    {
        $this->writeHordeYml('^8.2');
        $roles = $this->rolesFile('FRAMEWORK_6_0', 'min_php_version: "^8.2"');
        $original = (string) file_get_contents($this->tmpDir . '/.horde.yml');

        $context = $this->makeContext(['roles_file' => $roles]);
        $result = $this->makeTask()->run($context);

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($context->getFact('php_policy.updated'));
        $this->assertSame($original, (string) file_get_contents($this->tmpDir . '/.horde.yml'));
    }

    public function testPreservesComposerDependencies(): void
    {
        $this->writeHordeYml('^8.0', "    composer:\n      horde/exception: ^3");
        $roles = $this->rolesFile('FRAMEWORK_6_0', 'min_php_version: "^8.2"');

        $context = $this->makeContext(['roles_file' => $roles]);
        $this->makeTask()->run($context);

        $deps = (new HordeYml($this->tmpDir))->getHordeYmlFile()->getDependencies();
        $this->assertSame('^8.2', $deps?->getRequiredPhp());
        $this->assertSame(['horde/exception' => '^3'], $deps?->getRequired()?->getComposerPackages());
    }

    // -------- Helpers ----------------------------------------------------

    private function makeTask(bool $pretend = false): EnforcePhpVersionPolicyTask
    {
        return new EnforcePhpVersionPolicyTask($this->createStub(Output::class), $pretend);
    }

    private function requiredPhp(): string
    {
        return (new HordeYml($this->tmpDir))->getHordeYmlFile()->getRequiredPhp();
    }

    private function writeHordeYml(string $php, string $extraRequired = ''): void
    {
        $required = "    php: \"{$php}\"";
        if ($extraRequired !== '') {
            $required .= "\n" . $extraRequired;
        }
        $yaml = <<<YML
            ---
            id: sbx
            name: Sbx
            full: Sandbox
            description: Sandbox
            list: horde
            type: library
            authors:
              - { name: T, user: t, email: t@t, active: true, role: lead }
            version:
              release: 1.0.0
              api: 1.0.0
            state:
              release: stable
              api: stable
            license: { identifier: LGPL-2.1-only, uri: x }
            dependencies:
              required:
            {$required}
            YML;
        file_put_contents($this->tmpDir . '/.horde.yml', $yaml . "\n");
    }

    private function rolesFile(string $release, string $policyLines): string
    {
        $path = tempnam(sys_get_temp_dir(), 'roles-') . '.yml';
        file_put_contents($path, <<<YAML
            release:
              {$release}:
                {$policyLines}
            supported: []
            unstable: null
            YAML);
        $this->cleanupFiles[] = $path;
        return $path;
    }

    /**
     * @param array<string,mixed> $options
     */
    private function makeContext(array $options = []): Context
    {
        $dir = $this->tmpDir;
        $component = new class ($dir) implements Component {
            public function __construct(private readonly string $dir) {}
            public function getComponentDirectory(): string
            {
                return $this->dir;
            }
            public function getName(): string
            {
                return 'sbx';
            }
            public function getSummary(): string
            {
                return '';
            }
            public function getDescription(): string
            {
                return '';
            }
            public function getVersion(): string
            {
                return '1.0.0';
            }
            public function getPreviousVersion(): string
            {
                return '1.0.0';
            }
            public function getDate(): string
            {
                return '';
            }
            public function getChannel(): string
            {
                return '';
            }
            public function getDependencies(): array
            {
                return [];
            }
            public function getState($key = 'release'): string
            {
                return 'stable';
            }
            public function getLeads()
            {
                return [];
            }
            public function getLicense()
            {
                return 'LGPL';
            }
            public function getLicenseLocation(): string
            {
                return '';
            }
            public function hasLocalPackageXml(): bool
            {
                return false;
            }
            public function getChangelogLink(): string
            {
                return '';
            }
            public function getReleaseNotesPath(): string|bool
            {
                return false;
            }
            public function getDependencyList()
            {
                return null;
            }
            public function getData(): stdClass
            {
                return new stdClass();
            }
            public function getDocumentOrigin(): ?string
            {
                return null;
            }
            public function updatePackage($action, $options): string
            {
                return '';
            }
            public function changed($log, $options): array
            {
                return [];
            }
            public function timestamp($options): string
            {
                return '';
            }
            public function nextVersion($version, $initial_note, $stability_api = null, $stability_release = null, $options = []) {}
            public function currentSentinel($changes, $app, $options): array
            {
                return [];
            }
            public function tag(string $tag, string $message, \Horde\Components\Helper\Commit $commit): string
            {
                return '';
            }
            public function placeArchive(string $destination, $options = []): array
            {
                return [''];
            }
            public function repositoryRoot(\Horde\Components\Helper\Root $helper): string
            {
                return '';
            }
            public function installChannel(\Horde\Components\Pear\Environment $env, $options = []): void {}
            public function install(\Horde\Components\Pear\Environment $env, $options = [], $reason = ''): void {}
        };

        return new Context($component, $options);
    }
}
