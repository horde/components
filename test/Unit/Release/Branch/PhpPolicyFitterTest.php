<?php

declare(strict_types=1);

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

namespace Horde\Components\Test\Unit\Release\Branch;

use Horde\Components\Release\Branch\BranchPhpPolicy;
use Horde\Components\Release\Branch\PhpPolicyFitter;
use Horde\Components\Wrapper\HordeYml;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the pure PHP-policy fit task.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(PhpPolicyFitter::class)]
class PhpPolicyFitterTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            @unlink($dir . '/.horde.yml');
            @rmdir($dir);
        }
        $this->dirs = [];
    }

    public function testTargetDelegatesToReconciler(): void
    {
        $policy = new BranchPhpPolicy('^8.1', false, '^8.6', false);
        $fitter = new PhpPolicyFitter();

        $this->assertSame('^8.1', $fitter->target($policy, '^8.0'));
    }

    public function testCompliesTrueWhenAlreadyAtTarget(): void
    {
        $policy = new BranchPhpPolicy('^8.2', false, null, false);
        $this->assertTrue((new PhpPolicyFitter())->complies($policy, '^8.2'));
    }

    public function testCompliesFalseWhenBelowFloor(): void
    {
        $policy = new BranchPhpPolicy('^8.2', false, null, false);
        $this->assertFalse((new PhpPolicyFitter())->complies($policy, '^8.0'));
    }

    public function testFitRewritesPhpAndPreservesComposer(): void
    {
        $dir = $this->componentDir(<<<YML
            dependencies:
              required:
                php: "^8.0"
                composer:
                  horde/exception: ^3
                ext:
                  - dom
            YML);

        $yml = new HordeYml($dir);
        $file = $yml->getHordeYmlFile();
        $changed = (new PhpPolicyFitter())->fit($file, '^8.2');
        $yml->save();

        $this->assertTrue($changed);

        $reloaded = new HordeYml($dir);
        $deps = $reloaded->getHordeYmlFile()->getDependencies();
        $this->assertSame('^8.2', $deps?->getRequiredPhp());
        $this->assertSame(['horde/exception' => '^3'], $deps?->getRequired()?->getComposerPackages());
        $this->assertSame(['dom'], $deps?->getRequired()?->getExtensions());
    }

    public function testFitReturnsFalseWhenAlreadyAtTarget(): void
    {
        $dir = $this->componentDir(<<<YML
            dependencies:
              required:
                php: "^8.2"
            YML);

        $file = (new HordeYml($dir))->getHordeYmlFile();
        $this->assertFalse((new PhpPolicyFitter())->fit($file, '^8.2'));
    }

    public function testFitCreatesRequiredWhenDependenciesAbsent(): void
    {
        $dir = $this->componentDir('');

        $yml = new HordeYml($dir);
        $file = $yml->getHordeYmlFile();
        $changed = (new PhpPolicyFitter())->fit($file, '^8.2');
        $yml->save();

        $this->assertTrue($changed);
        $this->assertSame('^8.2', (new HordeYml($dir))->getHordeYmlFile()->getRequiredPhp());
    }

    private function componentDir(string $depsBlock): string
    {
        $dir = sys_get_temp_dir() . '/fit-cmp-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o755, true);
        $base = <<<YML
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
            YML;
        $content = $depsBlock === '' ? $base : $base . "\n" . $depsBlock;
        file_put_contents($dir . '/.horde.yml', $content . "\n");
        $this->dirs[] = $dir;
        return $dir;
    }
}
