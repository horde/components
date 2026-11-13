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
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Dependencies;

use Horde\Components\ConfigProvider\ConfigProvider;
use Horde\Components\Release\BranchRoles;
use Horde\Injector\Injector;

/**
 * Factory for the shared BranchRoles instance.
 *
 * Owns the single place where the branch-role map file is resolved. The
 * bundled data/branches.yml is a read-only default shipped in the phar; a
 * user never edits it, they point at their own file instead. Precedence is
 * provided by the config-provider cascade (CLI > env > user config >
 * builtin): the global --branches-file option is registered with the
 * dotted dest "branches.roles_file", so both the CLI switch and the
 * ~/.config/horde/components.php setting of that name surface through the
 * same getSetting() lookup, CLI winning. When neither is set, the bundled
 * default is used.
 *
 * Bound lazily (bindFactory) and cached by the injector, so the YAML is
 * read at most once per process and only when something needs it.
 *
 * @category Horde
 * @package  Components
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class BranchRolesFactory
{
    public function __construct(private readonly Injector $injector) {}

    public function __invoke(): BranchRoles
    {
        $config = $this->injector->getInstance(ConfigProvider::class);
        $file = $config->hasSetting('branches.roles_file')
            ? $config->getSetting('branches.roles_file')
            : null;

        return BranchRoles::load($file);
    }
}
