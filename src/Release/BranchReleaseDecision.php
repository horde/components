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

/**
 * Immutable result of a BranchReleasePolicy evaluation.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class BranchReleaseDecision
{
    /** @var list<string> */
    private readonly array $violations;

    /**
     * @param list<string> $violations Human-readable violation messages.
     *                                 Empty when the release is allowed.
     */
    public function __construct(array $violations)
    {
        $this->violations = array_values($violations);
    }

    /**
     * Whether the release is permitted.
     */
    public function isAllowed(): bool
    {
        return $this->violations === [];
    }

    /**
     * The violation messages. Empty when allowed.
     *
     * @return list<string>
     */
    public function violations(): array
    {
        return $this->violations;
    }
}
