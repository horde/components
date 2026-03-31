<?php

/**
 * PHPStan rule to ensure immutable Uri objects are used correctly.
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
use PHPStan\Type\ObjectType;

/**
 * Ensure Uri::with*() methods are not called without using the return value.
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
 * @implements Rule<Node\Stmt\Expression>
 */
class RequireImmutableUriUsageRule implements Rule
{
    /**
     * Classes that should be checked for immutability.
     */
    private array $immutableClasses = [
        'Horde\Http\Uri',
        'Psr\Http\Message\UriInterface',
        'GuzzleHttp\Psr7\Uri',
        'Laminas\Diactoros\Uri',
    ];

    /**
     * Method name patterns that indicate immutable operations.
     */
    private array $immutableMethodPatterns = [
        '/^with[A-Z]/',  // withQuery, withPath, withScheme, etc.
    ];

    /**
     * Exception contexts where unused returns are acceptable.
     */
    private array $allowedContexts = [
        // Add specific method names or class contexts that are exceptions
        // Example: 'Horde\Http\Client::send' if it has side effects
    ];

    public function getNodeType(): string
    {
        return Node\Stmt\Expression::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        // Check if the expression is a method call
        $expr = $node->expr;
        if (!$expr instanceof Node\Expr\MethodCall) {
            return [];
        }

        if (!$expr->name instanceof Node\Identifier) {
            return [];
        }

        $methodName = $expr->name->toString();

        // Check if method matches immutable patterns
        $isImmutableMethod = false;
        foreach ($this->immutableMethodPatterns as $pattern) {
            if (preg_match($pattern, $methodName)) {
                $isImmutableMethod = true;
                break;
            }
        }

        if (!$isImmutableMethod) {
            return [];
        }

        // Check if the object is one of our immutable classes
        $callerType = $scope->getType($expr->var);
        $isImmutableClass = false;

        foreach ($this->immutableClasses as $className) {
            if ((new ObjectType($className))->isSuperTypeOf($callerType)->yes()) {
                $isImmutableClass = true;
                break;
            }
        }

        if (!$isImmutableClass) {
            return [];
        }

        // Method called as statement - return value discarded!
        return [
            RuleErrorBuilder::message(
                sprintf(
                    '%s() returns a new immutable instance. Assign the result: $uri = $uri->%s(...)',
                    $methodName,
                    $methodName
                )
            )->tip('Immutable objects must have their return values used')
            ->build()
        ];
    }
}
