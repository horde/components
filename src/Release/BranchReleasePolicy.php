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

namespace Horde\Components\Release;

use Horde\Components\Helper\Version;

/**
 * Answers if the considered branch with the commits since last tag is allowed to release.
 *
 * A branch may be "supported", "release", "unstable" or "unknown".
 *
 * Rules:
 * - supported branch: Bugfix severity only (subpatch/patch). A feat
 *   (minor) or BC break (major) is a violation.
 * - release branch: Bugfix or feature always allowed. A BC break (major)
 *   is allowed only while the component is pre-stable.
 * - unstable branch: Any kind of commit allowed but no "stable" releases.
 * - unknown branch: This branch cannot release anything.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class BranchReleasePolicy
{
    /**
     * Evaluate the policy.
     *
     * @param string $role One of the BranchRoles::ROLE_* constants.
     * @param string $severity Top Conventional-Commit severity since the
     *                         last tag: subpatch|patch|minor|major.
     * @param string $stability Current release stability from .horde.yml
     *                          (e.g. stable, beta, alpha, or '' for a bare
     *                          stable SemVer).
     * @return BranchReleaseDecision The decision plus any violations.
     */
    public function evaluate(
        string $role,
        string $severity,
        string $stability
    ): BranchReleaseDecision {
        $violations = [];

        switch ($role) {
            case BranchRoles::ROLE_SUPPORTED:
                if (!$this->isBugfixSeverity($severity)) {
                    $violations[] = sprintf(
                        'Supported branches take bugfix releases only; '
                            . 'refusing a "%s" change (no feat or BC break on a supported branch).',
                        $severity
                    );
                }
                break;

            case BranchRoles::ROLE_RELEASE:
                if ($severity === 'major' && Version::isStable($stability)) {
                    $violations[] =
                        'BC-breaking change on the release branch is not allowed '
                        . 'once the component is stable. Cut it on the unstable branch.';
                }
                break;

            case BranchRoles::ROLE_UNSTABLE:
                // BC-breaking work is the purpose of the unstable branch.
                break;

            default:
                $violations[] =
                    'Current branch has no declared role (release, supported, '
                    . 'or unstable). Refusing to release.';
                break;
        }

        return new BranchReleaseDecision($violations);
    }

    /**
     * Whether a severity is bugfix level.
     */
    private function isBugfixSeverity(string $severity): bool
    {
        return $severity === 'patch' || $severity === 'subpatch';
    }
}
