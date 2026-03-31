<?php

/**
 * PHPStan rule to detect deprecated Horde_Util method calls.
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
 * Detect and flag deprecated Horde_Util method calls.
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
 * @implements Rule<Node\Expr\StaticCall>
 */
class NoDeprecatedHordeUtilRule implements Rule
{
    /**
     * Mapping of deprecated Horde_Util methods to their modern replacements.
     */
    private array $deprecatedMethods = [
        'getPathInfo' => 'Use $_SERVER[\'PATH_INFO\'] directly',
        'nonInputVar' => 'Use filter_input() or $_SERVER directly',
        'getFormData' => 'Use PSR-7 ServerRequest or filter_input()',
        'addParameter' => 'Use Horde\Http\Uri::withQuery() or http_build_query()',
        'removeParameter' => 'Use Horde\Http\Uri::withQuery()',
        'bufferOutput' => 'Use output buffering functions directly',
    ];

    public function getNodeType(): string
    {
        return Node\Expr\StaticCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        // Check if this is a Horde_Util static call
        if (!$node->class instanceof Node\Name) {
            return [];
        }

        $className = $node->class->toString();
        if ($className !== 'Horde_Util') {
            return [];
        }

        if (!$node->name instanceof Node\Identifier) {
            return [];
        }

        $methodName = $node->name->toString();

        // Check if method is deprecated
        if (isset($this->deprecatedMethods[$methodName])) {
            return [
                RuleErrorBuilder::message(
                    sprintf(
                        'Horde_Util::%s() is deprecated. %s',
                        $methodName,
                        $this->deprecatedMethods[$methodName]
                    )
                )->build()
            ];
        }

        return [];
    }
}
