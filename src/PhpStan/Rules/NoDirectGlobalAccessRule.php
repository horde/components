<?php

/**
 * PHPStan rule to disallow direct global variable access.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\PhpStan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Disallow direct global registry access - require dependency injection.
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
 * @implements Rule<Node\Stmt\Global_>
 */
class NoDirectGlobalAccessRule implements Rule
{
    public function getNodeType(): string
    {
        return Node\Stmt\Global_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];

        foreach ($node->vars as $var) {
            if (!$var instanceof Node\Expr\Variable || !is_string($var->name)) {
                continue;
            }

            $globalName = $var->name;

            // Check for forbidden globals
            $forbiddenGlobals = [
                'registry' => 'Use dependency injection to pass Horde_Registry instance',
                'injector' => 'Use dependency injection instead of global $injector',
                'conf' => 'Use dependency injection to pass configuration',
                'notification' => 'Use dependency injection to pass notification service',
                'session' => 'Use dependency injection to pass session handler',
            ];

            if (isset($forbiddenGlobals[$globalName])) {
                $errors[] = RuleErrorBuilder::message(
                    sprintf(
                        'Direct global access to $%s is forbidden. %s',
                        $globalName,
                        $forbiddenGlobals[$globalName]
                    )
                )->build();
            }
        }

        return $errors;
    }
}
