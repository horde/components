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

namespace Horde\Components\Module;

use Horde\Argv\Option;
use Horde\Components\Component;
use Horde\Components\ConfigProvider\ConfigProvider;
use Horde\Components\Exception;
use Horde\Components\Helper\Git as GitHelper;
use Horde\Components\Helper\GitHubChecker;
use Horde\Components\Output;
use Horde\Components\Release\Branches\CheckCommand;
use Horde\Components\Release\Branches\EnsureCommand;
use Horde\GithubApiClient\GithubApiConfig;

/**
 * Manage and validate git branch roles (release, supported, unstable).
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Branches extends Base
{
    public function getOptionGroupTitle(): string
    {
        return 'Branches';
    }

    public function getOptionGroupDescription(): string
    {
        return 'This module manages and validates git branch roles.';
    }

    public function getOptionGroupOptions(): array
    {
        return [
            new Option(
                '--roles-file',
                [
                    'action' => 'store',
                    'help' => 'Path to a branch-role YAML file (default: bundled data/branches.yml)',
                ]
            ),
            // --force is declared globally (also used by ci); branches
            // check reads it from the parsed options in handle().
        ];
    }

    public function getTitle(): string
    {
        return 'branches';
    }

    public function getUsage(): string
    {
        return 'Validate git branch roles.';
    }

    public function getShortDescription(): string
    {
        return 'Validate git branch roles';
    }

    public function getActions(): array
    {
        return ['branches'];
    }

    public function getHelp($action): string
    {
        return 'Manage and validate git branch roles for Horde components.

USAGE:
    horde-components branches <subcommand> [OPTIONS]

SUBCOMMANDS:
    check      Validate the current checkout against the branch-role rules
    ensure     Create the release branch and make it the repository default

DESCRIPTION:
    Horde uses synchronized branch names across every repository, so branch
    roles are managed centrally in a bundled map (data/branches.yml):

    - release:   the release branch; also the repository default branch.
                 Accepts feature and bugfix releases.
    - supported: maintenance branches; bugfix releases only.
    - unstable:  optional branch for BC-breaking work.

BRANCHES CHECK:
    Reports the current branch role, verifies the declared release branch is
    the repository default branch, classifies the Conventional Commits since
    the last tag, and applies the release policy.

    # Check the current checkout
    horde-components branches check

    # Use a custom role map
    horde-components branches check --roles-file /path/to/branches.yml

    # Report violations as warnings instead of failing
    horde-components branches check --force

BRANCHES ENSURE:
    Makes reality match the declared release branch: creates the local
    branch (from the remote branch of the same name, or from the current
    default branch), pushes it with upstream tracking, and sets it as the
    repository default branch via the GitHub API. Prints the manual settings
    link when the API authorization is missing or insufficient.

    # Ensure the release branch exists and is the default
    horde-components branches ensure

    # Preview every create/push/API action without doing it
    horde-components branches ensure --pretend
';
    }

    public function handle(array $options, array $arguments, ?Component $component = null): bool
    {
        if (!isset($arguments[0]) || $arguments[0] !== 'branches') {
            return false;
        }

        $subcommand = $arguments[1] ?? '';
        $output = $this->dependencies->get(Output::class);

        if ($subcommand === '') {
            $output->bold('Horde Components Branch Management');
            $output->plain('');
            $output->plain('Usage: horde-components branches <subcommand> [OPTIONS]');
            $output->plain('');
            $output->plain('Subcommands:');
            $output->plain('  check      Validate the checkout against branch-role rules');
            $output->plain('  ensure     Create the release branch and make it the default branch');
            $output->plain('');
            $output->plain('For detailed help: horde-components help branches');
            return true;
        }

        // Branch-role file precedence: the module's own --roles-file wins,
        // then the branches.roles_file setting (which the global
        // --branches-file option also populates, via CliConfigProvider),
        // then null => the bundled default inside BranchRoles::load().
        // Horde\Argv normalizes long-option dests, replacing '-' with '_',
        // so --roles-file arrives as the key 'roles_file'.
        $componentPath = $options['roles-component-path'] ?? getcwd();
        $rolesFile = $options['roles_file'] ?? null;
        if ($rolesFile === null) {
            $config = $this->dependencies->get(ConfigProvider::class);
            if ($config->hasSetting('branches.roles_file')) {
                $rolesFile = $config->getSetting('branches.roles_file');
            }
        }
        $force = isset($options['force']) && $options['force'];
        $pretend = isset($options['pretend']) && $options['pretend'];

        switch ($subcommand) {
            case 'check':
                $checkCommand = new CheckCommand(
                    $output,
                    $this->dependencies->get(GitHelper::class)
                );
                if (!$checkCommand->execute($componentPath, $rolesFile, $force)) {
                    throw new Exception('branches check: policy violation', 1);
                }
                return true;

            case 'ensure':
                $ensureCommand = new EnsureCommand(
                    $output,
                    $this->dependencies->get(GitHelper::class),
                    $this->dependencies->get(GitHubChecker::class),
                    $this->dependencies->get(GithubApiConfig::class)
                );
                if (!$ensureCommand->execute($componentPath, $rolesFile, $pretend)) {
                    throw new Exception('branches ensure failed', 1);
                }
                return true;

            default:
                $output->error("Unknown subcommand: {$subcommand}");
                $output->info('Valid subcommands: check, ensure');
                throw new Exception("Unknown branches subcommand: {$subcommand}", 2);
        }
    }
}
