<?php

/**
 * PHPStan rule to require #[CoversClass] attribute on test classes.
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
use PHPStan\Reflection\ReflectionProvider;

/**
 * Require #[CoversClass] attribute on PHPUnit test classes.
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
 * @implements Rule<Node\Stmt\Class_>
 */
class RequireCoversClassAttributeRule implements Rule
{
    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
    ) {}

    public function getNodeType(): string
    {
        return Node\Stmt\Class_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        // Only check test classes
        $file = $scope->getFile();
        if (!str_contains($file, '/test/') && !str_contains($file, '/tests/')) {
            return [];
        }

        // Check if class extends TestCase
        if ($node->extends === null) {
            return [];
        }

        $parentClass = $scope->resolveTypeByName($node->extends);
        $testCaseType = new \PHPStan\Type\ObjectType('PHPUnit\Framework\TestCase');

        if (!$testCaseType->isSuperTypeOf($parentClass)->yes()) {
            return [];
        }

        // Check for CoversClass attribute
        $hasCoversClass = false;
        foreach ($node->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                $attrName = $attr->name->toString();
                if ($attrName === 'CoversClass' || $attrName === 'PHPUnit\Framework\Attributes\CoversClass') {
                    $hasCoversClass = true;
                    break 2;
                }
            }
        }

        if (!$hasCoversClass) {
            $className = $node->name?->toString() ?? 'Unknown';
            return [
                RuleErrorBuilder::message(
                    sprintf(
                        'Test class %s must have #[CoversClass(ClassName::class)] attribute',
                        $className
                    )
                )->tip('PHPUnit test classes should explicitly declare coverage')
                ->build()
            ];
        }

        return [];
    }
}
