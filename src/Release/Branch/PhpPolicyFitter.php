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

namespace Horde\Components\Release\Branch;

use Horde\HordeYmlFile\Dependencies;
use Horde\HordeYmlFile\DependencySet;
use Horde\HordeYmlFile\HordeYmlFile;
use Horde\Version\ConstraintReconciler;

/**
 * Fit a component's required PHP constraint to a branch's PHP policy.
 *
 * A discrete, pure task: it computes the reconciled constraint (via
 * horde/version's {@see ConstraintReconciler}) and can mutate an
 * already-parsed {@see HordeYmlFile} object in place. It performs no file
 * IO of its own — reading the file and persisting the result ({@see
 * HordeYmlFile::save()}) is the calling command's responsibility.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
final class PhpPolicyFitter
{
    public function __construct(
        private readonly ConstraintReconciler $reconciler = new ConstraintReconciler(),
    ) {}

    /**
     * Compute the PHP constraint required by $policy given the component's
     * current required-PHP constraint.
     *
     * @param BranchPhpPolicy $policy The branch's PHP-version policy.
     * @param string $currentRequiredPhp The component's current
     *                                   dependencies.required.php (may be '').
     * @return string The reconciled constraint ('' only when the policy is
     *                empty and the current value is empty).
     */
    public function target(BranchPhpPolicy $policy, string $currentRequiredPhp): string
    {
        return $this->reconciler->reconcile(
            $currentRequiredPhp,
            $policy->min(),
            $policy->syncsMin(),
            $policy->max(),
            $policy->syncsMax(),
        );
    }

    /**
     * Whether the current required-PHP constraint already satisfies the
     * policy (i.e. reconciling it would change nothing).
     */
    public function complies(BranchPhpPolicy $policy, string $currentRequiredPhp): bool
    {
        return $this->target($policy, $currentRequiredPhp) === $currentRequiredPhp;
    }

    /**
     * Set dependencies.required.php on an already-parsed file to $target.
     *
     * Rebuilds the immutable {@see Dependencies}/{@see DependencySet}
     * value objects, preserving the existing required composer/pear/ext
     * entries and the optional/dev sets. Does NOT save; the caller decides
     * whether to persist.
     *
     * @return bool True when the object changed, false when required.php
     *              already equalled $target.
     */
    public function fit(HordeYmlFile $file, string $target): bool
    {
        $deps = $file->getDependencies();
        $required = $deps?->getRequired();

        if (($required?->getPhp() ?? '') === $target) {
            return false;
        }

        $newRequired = new DependencySet(
            $target,
            $required?->getComposerPackages() ?? [],
            $required?->getPearPackages() ?? [],
            $required?->getExtensions() ?? [],
        );

        $file->setDependencies(new Dependencies(
            $newRequired,
            $deps?->getOptional(),
            $deps?->getDev(),
        ));

        return true;
    }
}
