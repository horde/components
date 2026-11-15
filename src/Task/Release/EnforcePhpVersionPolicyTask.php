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

namespace Horde\Components\Task\Release;

use Horde\Components\Release\Branch\BranchRoleMap;
use Horde\Components\Release\Branch\PhpPolicyFitter;
use Horde\Components\Task\AbstractTask;
use Horde\Components\Task\Context;
use Horde\Components\Task\Result;
use Horde\Components\Wrapper\HordeYml;
use Throwable;

/**
 * Enforce the release branch's PHP-version policy on .horde.yml.
 *
 * Loads the branch-role map (data/branches.yml) and the component's
 * .horde.yml, reconciles the component's dependencies.required.php against
 * the release branch's PHP-version policy (raise the floor to min, tighten
 * the ceiling to max, pin exactly when sync_*), and writes .horde.yml back
 * when it drifts.
 *
 * The reconciliation itself lives in {@see PhpPolicyFitter} (which wraps
 * horde/version's ConstraintReconciler); this task only orchestrates the
 * load/reconcile/dump around it, mirroring the inline-load-and-save
 * convention of {@see UpdateHordeYmlVersionTask} and
 * {@see UpdateComposerJsonTask}.
 *
 * Optional Options:
 * - roles_file (string) - Path to a branch-role YAML file; defaults to the
 *                         bundled data/branches.yml. The CLI long option
 *                         --roles-file arrives as 'roles_file'; the raw
 *                         'roles-file' key is also accepted.
 *
 * Emitted Facts:
 * - php_policy.updated (bool) - True when .horde.yml was rewritten.
 * - php_policy.file_path (string) - Path to .horde.yml.
 * - php_policy.branch (string) - The release branch the policy came from.
 * - php_policy.previous (string) - The prior required-PHP constraint.
 * - php_policy.constraint (string) - The reconciled required-PHP constraint.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
final class EnforcePhpVersionPolicyTask extends AbstractTask
{
    public function getName(): string
    {
        return 'Enforce PHP Version Policy';
    }

    public function run(Context $context): Result
    {
        $componentPath = $context->getComponentPath();

        // Load data/branches.yml (or the override) and take the release
        // branch definition. BranchRoleMap reads both the simple and the
        // enhanced (per-branch policy) formats.
        $rolesFile = $context->getOption('roles_file')
            ?? $context->getOption('roles-file');
        $releaseDef = BranchRoleMap::load(
            is_string($rolesFile) ? $rolesFile : null
        )->release();

        $branch = $releaseDef->name();
        if (!$releaseDef->hasPolicy()) {
            return Result::skipped(
                $branch === ''
                    ? 'No release branch declared; nothing to enforce.'
                    : "No PHP-version policy for release branch {$branch}."
            );
        }

        // Load .horde.yml inline.
        try {
            $yml = new HordeYml($componentPath);
            $file = $yml->getHordeYmlFile();
            $current = $file->getRequiredPhp();
        } catch (Throwable $e) {
            return Result::failure(
                'Could not read .horde.yml PHP requirement: ' . $e->getMessage()
            );
        }

        $fitter = new PhpPolicyFitter();
        $target = $fitter->target($releaseDef->policy(), $current);
        $shownCurrent = $current === '' ? '(none)' : $current;
        $filePath = $componentPath . '/.horde.yml';

        // Already compliant: nothing to write.
        if ($target === $current || $target === '') {
            $context->setFact('php_policy.updated', false);
            $context->setFact('php_policy.file_path', $filePath);
            $context->setFact('php_policy.branch', $branch);
            $context->setFact('php_policy.previous', $current);
            $context->setFact('php_policy.constraint', $current);

            return Result::success(
                "PHP requirement already matches the {$branch} policy ({$shownCurrent}).",
                ['file_path' => $filePath, 'constraint' => $current]
            );
        }

        if ($this->pretend) {
            return $this->pretend(
                "set dependencies.required.php to {$target} (from {$shownCurrent}) per {$branch} policy"
            );
        }

        // Reconcile and dump .horde.yml.
        $fitter->fit($file, $target);
        $yml->save();

        $context->setFact('php_policy.updated', true);
        $context->setFact('php_policy.file_path', $filePath);
        $context->setFact('php_policy.branch', $branch);
        $context->setFact('php_policy.previous', $current);
        $context->setFact('php_policy.constraint', $target);

        return Result::success(
            "Updated dependencies.required.php from {$shownCurrent} to {$target} per {$branch} policy.",
            [
                'file_path' => $filePath,
                'previous' => $current,
                'constraint' => $target,
            ]
        );
    }
}
