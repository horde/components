<?php

/**
 * PHPStan rule to disallow exit() and die() in library code.
 *
 * DRAFT - Not currently active.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\PhpStan\Rules\Draft;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Disallow exit() and die() in library code - throw exceptions instead.
 *
 * Copyright 2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 *
 * @implements Rule<Node\Expr\Exit_>
 */
class NoExitInLibraryCodeRule implements Rule
{
    public function getNodeType(): string
    {
        return Node\Expr\Exit_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        // Allow in CLI scripts (files in bin/)
        $file = $scope->getFile();
        if (str_contains($file, '/bin/') || str_contains($file, '/scripts/')) {
            return [];
        }

        // Disallow in lib/ and src/
        if (str_contains($file, '/lib/') || str_contains($file, '/src/')) {
            return [
                RuleErrorBuilder::message(
                    'exit() and die() are forbidden in library code. Throw an exception instead.'
                )->tip('Library code should not terminate execution')
                ->build()
            ];
        }

        return [];
    }
}
