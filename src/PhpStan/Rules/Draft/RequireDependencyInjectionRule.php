<?php

/**
 * PHPStan rule to require dependency injection for service classes.
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
 * Disallow 'new ServiceClass()' in controllers - require dependency injection.
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
 * @implements Rule<Node\Expr\New_>
 */
class RequireDependencyInjectionRule implements Rule
{
    /**
     * Service class patterns that should be injected, not instantiated.
     */
    private array $servicePatterns = [
        '/Repository$/',
        '/Service$/',
        '/Handler$/',
        '/Manager$/',
        '/Factory$/',
        '/Client$/',
        '/^Horde_Registry$/',
        '/^Horde_.*_Driver/',
    ];

    /**
     * Classes allowed to be instantiated directly (value objects, DTOs).
     */
    private array $allowedClasses = [
        'DateTime',
        'DateTimeImmutable',
        'Exception',
        '/^Horde\\\Http\\\Uri$/',
        '/^.*Exception$/',
        '/^.*Request$/',
        '/^.*Response$/',
    ];

    public function getNodeType(): string
    {
        return Node\Expr\New_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        // Only check in controller/application layer
        $file = $scope->getFile();
        if (!str_contains($file, '/Controller/') && !str_contains($file, '/Application/')) {
            return [];
        }

        // Get the class being instantiated
        if (!$node->class instanceof Node\Name) {
            return [];
        }

        $className = $node->class->toString();

        // Check if class is in allowed list
        foreach ($this->allowedClasses as $allowed) {
            if (str_starts_with($allowed, '/') && preg_match($allowed, $className)) {
                return [];
            }
            if ($className === $allowed) {
                return [];
            }
        }

        // Check if class matches service patterns
        foreach ($this->servicePatterns as $pattern) {
            if (preg_match($pattern, $className)) {
                return [
                    RuleErrorBuilder::message(
                        sprintf(
                            'Service class %s should be injected, not instantiated directly',
                            $className
                        )
                    )->tip('Use constructor injection for services')
                    ->build()
                ];
            }
        }

        return [];
    }
}
