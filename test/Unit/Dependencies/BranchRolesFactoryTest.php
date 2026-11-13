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

namespace Horde\Components\Test\Unit\Dependencies;

use Horde\Components\ConfigProvider\ConfigProvider;
use Horde\Components\Dependencies\BranchRolesFactory;
use Horde\Components\Release\BranchRoles;
use Horde\Injector\Injector;
use Horde\Injector\TopLevel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the branch-role map file-precedence resolution.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(BranchRolesFactory::class)]
class BranchRolesFactoryTest extends TestCase
{
    private ?string $tempPath = null;

    protected function tearDown(): void
    {
        if ($this->tempPath !== null) {
            @unlink($this->tempPath);
            $this->tempPath = null;
        }
    }

    private function injectorWithConfig(ConfigProvider $config): Injector
    {
        $injector = new Injector(new TopLevel());
        $injector->setInstance(ConfigProvider::class, $config);
        return $injector;
    }

    public function testUsesConfiguredRolesFile(): void
    {
        $this->tempPath = tempnam(sys_get_temp_dir(), 'roles-') . '.yml';
        file_put_contents($this->tempPath, "release: CUSTOM_RELEASE\nsupported: []\nunstable: null\n");

        $path = $this->tempPath;
        $config = new class ($path) implements ConfigProvider {
            public function __construct(private string $path) {}
            public function hasSetting(string $id): bool
            {
                return $id === 'branches.roles_file';
            }
            public function getSetting(string $id): string
            {
                return $this->path;
            }
            public function isUnset(string $id): bool
            {
                return false;
            }
            public function getAvailableKeys(): array
            {
                return ['branches.roles_file'];
            }
            public function isAvailable(): bool
            {
                return true;
            }
        };

        $roles = (new BranchRolesFactory($this->injectorWithConfig($config)))();

        $this->assertInstanceOf(BranchRoles::class, $roles);
        $this->assertSame('CUSTOM_RELEASE', $roles->releaseBranch());
    }

    public function testFallsBackToBundledDefaultWhenUnset(): void
    {
        $config = new class implements ConfigProvider {
            public function hasSetting(string $id): bool
            {
                return false;
            }
            public function getSetting(string $id): string
            {
                return '';
            }
            public function isUnset(string $id): bool
            {
                return false;
            }
            public function getAvailableKeys(): array
            {
                return [];
            }
            public function isAvailable(): bool
            {
                return true;
            }
        };

        $roles = (new BranchRolesFactory($this->injectorWithConfig($config)))();

        // The bundled data/branches.yml ships FRAMEWORK_6_0 as the release.
        $this->assertSame('FRAMEWORK_6_0', $roles->releaseBranch());
    }
}
