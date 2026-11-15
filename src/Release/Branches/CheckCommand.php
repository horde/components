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

namespace Horde\Components\Release\Branches;

use Horde\Components\Helper\ConventionalCommitHelper;
use Horde\Components\Helper\Git as GitHelper;
use Horde\Components\Output;
use Horde\Components\Release\Branch\BranchDefinition;
use Horde\Components\Release\Branch\BranchRoleMap;
use Horde\Components\Release\Branch\PhpPolicyFitter;
use Horde\Components\Release\BranchReleasePolicy;
use Horde\Components\Wrapper\HordeYml;
use Throwable;

/**
 * Sanity-check a component checkout against the central branch-role rules.
 *
 * Reports the current branch's role, verifies that the declared release
 * branch is the repository default branch, classifies the Conventional
 * Commits since the last tag, and runs BranchReleasePolicy. Violations fail
 * the command unless --force is given, in which case they are reported as
 * warnings.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class CheckCommand
{
    /**
     * Constructor.
     *
     * @param Output $output Output handler.
     * @param GitHelper $git Git helper.
     */
    public function __construct(
        private readonly Output $output,
        private readonly GitHelper $git
    ) {}

    /**
     * Run the branch sanity check.
     *
     * @param string $componentPath Path to the component checkout.
     * @param string|null $rolesFile Optional path to a branch-role YAML
     *                               file; defaults to the bundled map.
     * @param bool $force Report violations as warnings instead of failing.
     * @return bool True when the check passed (or was forced), false when
     *              a violation was found and not forced.
     */
    public function execute(
        string $componentPath,
        ?string $rolesFile = null,
        bool $force = false
    ): bool {
        $this->output->bold('=== Horde Branch Check ===');

        $map = BranchRoleMap::load($rolesFile);
        $roles = $map->toBranchRoles();
        $currentBranch = trim($this->git->getCurrentBranch($componentPath));
        $role = $roles->roleOf($currentBranch);

        $this->output->info("Component path: {$componentPath}");
        $this->output->info("Current branch: {$currentBranch}");
        $this->output->info("Branch role: {$role}");
        $this->output->plain('');

        $issues = [];

        // Sanity check: the declared release branch must be the repository
        // default branch (YAML is authoritative; this flags drift).
        $defaultBranch = $this->git->getDefaultBranch($componentPath);
        if ($defaultBranch !== null && $defaultBranch !== $roles->releaseBranch()) {
            $issues[] = sprintf(
                'Declared release branch "%s" is not the repository default branch "%s".',
                $roles->releaseBranch(),
                $defaultBranch
            );
        }

        // Classify commits since the last tag and run the release policy.
        $severity = $this->topSeverity($componentPath);
        $stability = $this->componentStability($componentPath);
        $this->output->info("Top commit severity since last tag: {$severity}");
        $this->output->info("Component stability: " . ($stability === '' ? 'stable' : $stability));
        $this->output->plain('');

        $policy = new BranchReleasePolicy();
        $decision = $policy->evaluate($role, $severity, $stability);

        $issues = array_merge($issues, $decision->violations());

        // PHP-version policy compliance. This is independent of --force:
        // it is reported and, when the component drifts, fails the command
        // like any other unmet condition. Keyed to the current branch's
        // definition when it has one, else the release branch's.
        $def = $map->definitionFor($currentBranch) ?? $map->release();
        $phpCompliant = $this->checkPhpPolicy($componentPath, $def);

        if ($issues === [] && $phpCompliant) {
            $this->output->ok('Branch check passed.');
            return true;
        }

        foreach ($issues as $issue) {
            if ($force) {
                $this->output->warn($issue);
            } else {
                $this->output->error($issue);
            }
        }

        // Role/release issues are force-overridable; PHP-policy drift is not.
        if ($issues !== [] && $force) {
            $this->output->warn('Proceeding despite branch-role violations (--force).');
        }

        if (!$phpCompliant) {
            $this->output->error('Branch check failed: PHP-version policy is not satisfied (not subject to --force).');
            return false;
        }

        if ($issues !== [] && !$force) {
            $this->output->error('Branch check failed. Re-run with --force to override branch-role violations.');
            return false;
        }

        return true;
    }

    /**
     * Report whether the component's required PHP constraint satisfies the
     * branch's PHP-version policy.
     *
     * No mutation. Returns true (compliant) when there is no policy, and
     * also when .horde.yml cannot be read (cannot assess — do not fail on
     * an unrelated read error).
     *
     * @param BranchDefinition|null $def The branch definition carrying the
     *                                   policy, or null when none applies.
     */
    private function checkPhpPolicy(string $componentPath, ?BranchDefinition $def): bool
    {
        if ($def === null || !$def->hasPolicy()) {
            return true;
        }

        try {
            $current = (new HordeYml($componentPath))->getHordeYmlFile()->getRequiredPhp();
        } catch (Throwable $e) {
            $this->output->warn('Could not read .horde.yml PHP requirement; skipping policy check: ' . $e->getMessage());
            return true;
        }

        $fitter = new PhpPolicyFitter();
        if ($fitter->complies($def->policy(), $current)) {
            $shown = $current === '' ? '(none)' : $current;
            $this->output->ok("PHP policy OK for {$def->name()}: {$shown} satisfies the policy.");
            return true;
        }

        $target = $fitter->target($def->policy(), $current);
        $shown = $current === '' ? '(none)' : $current;
        $this->output->error(sprintf(
            'PHP policy drift for %s: dependencies.required.php is %s but the policy requires %s.',
            $def->name(),
            $shown,
            $target
        ));

        return false;
    }

    /**
     * Determine the top Conventional-Commit severity since the last tag.
     *
     * Reuses ConventionalCommitHelper, which reads the git log of the
     * current working directory. Returns 'patch' when the history cannot
     * be classified (e.g. no commits since the last tag), which is the
     * most permissive bugfix-level default.
     */
    private function topSeverity(string $componentPath): string
    {
        $previousCwd = getcwd();
        $changed = is_string($previousCwd) && $previousCwd !== $componentPath
            && @chdir($componentPath);
        try {
            $helper = new ConventionalCommitHelper($this->git);
            return $helper->commitReader->getTopSeverity();
        } catch (Throwable $e) {
            $this->output->warn('Could not classify commits: ' . $e->getMessage());
            return 'patch';
        } finally {
            if ($changed && is_string($previousCwd)) {
                @chdir($previousCwd);
            }
        }
    }

    /**
     * Read the component's current release stability from .horde.yml.
     *
     * Returns '' (treated as stable) when it cannot be read.
     */
    private function componentStability(string $componentPath): string
    {
        try {
            return (new HordeYml($componentPath))->getComponentStability();
        } catch (Throwable $e) {
            $this->output->warn('Could not read .horde.yml stability: ' . $e->getMessage());
            return '';
        }
    }
}
