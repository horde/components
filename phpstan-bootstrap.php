<?php

/**
 * PHPStan bootstrap file for loading Horde custom rules.
 *
 * This file ensures custom rule classes are available before
 * PHPStan tries to instantiate them.
 */

declare(strict_types=1);

$rulesDir = __DIR__ . '/src/PhpStan/Rules';

// Load active rules (guarded by class_exists to handle edge cases)
if (!class_exists('Horde\Components\PhpStan\Rules\NoDirectGlobalAccessRule')) {
    require_once $rulesDir . '/NoDirectGlobalAccessRule.php';
}

if (!class_exists('Horde\Components\PhpStan\Rules\RequireImmutableUriUsageRule')) {
    require_once $rulesDir . '/RequireImmutableUriUsageRule.php';
}

if (!class_exists('Horde\Components\PhpStan\Rules\NoDeprecatedHordeUtilRule')) {
    require_once $rulesDir . '/NoDeprecatedHordeUtilRule.php';
}

// Draft rules (not loaded by default)
// if (!class_exists('Horde\Components\PhpStan\Rules\Draft\NoExitInLibraryCodeRule')) {
//     require_once $rulesDir . '/Draft/NoExitInLibraryCodeRule.php';
// }
// if (!class_exists('Horde\Components\PhpStan\Rules\Draft\RequireCoversClassAttributeRule')) {
//     require_once $rulesDir . '/Draft/RequireCoversClassAttributeRule.php';
// }
// if (!class_exists('Horde\Components\PhpStan\Rules\Draft\RequireDependencyInjectionRule')) {
//     require_once $rulesDir . '/Draft/RequireDependencyInjectionRule.php';
// }
