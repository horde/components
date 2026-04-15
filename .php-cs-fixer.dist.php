<?php

// Load custom fixers
require_once __DIR__ . '/src/PhpCsFixer/RemovePhpVersionCommentFixer.php';
require_once __DIR__ . '/src/PhpCsFixer/UpdateCopyrightYearFixer.php';
require_once __DIR__ . '/src/PhpCsFixer/MarkDeprecatedHordeCallsFixer.php';
require_once __DIR__ . '/src/PhpCsFixer/RewriteHordeUtilToPsr4Fixer.php';
require_once __DIR__ . '/src/PhpCsFixer/RewriteHordeToPsr4Fixer.php';

// When invoked by horde-components, HORDE_COMPONENT_PATH points to the target
// component.  Fall back to __DIR__ for standalone / direct invocation.
$basePath = getenv('HORDE_COMPONENT_PATH') ?: __DIR__;

$potentialDirs = ['/lib', '/src', '/bin', '/app', '/templates', '/migration', '/test', '/tests'];

$finder = (new PhpCsFixer\Finder());
foreach ($potentialDirs as $dir) {
    $full = $basePath . $dir;
    if (is_dir($full)) {
        $finder->in($full);
    }
}

$finder->exclude(['fixtures']);

$config = (new PhpCsFixer\Config())
    ->setRules([
        '@PER-CS' => true,
        '@PHP8x2Migration' => true,
        'php_unit_test_class_requires_covers' => true,
        'nullable_type_declaration_for_default_null_value' => true,
	'fully_qualified_strict_types' => true,
        'global_namespace_import' => [
            'import_classes' => true,
            'import_constants' => true,
            'import_functions' => true,
        ],
        'Horde/remove_php_version_comment' => true,
        'Horde/update_copyright_year' => true,
        'Horde/mark_deprecated_horde_calls' => true,
        'Horde/rewrite_horde_util_to_psr4' => false, // RISKY - disabled by default
        'Horde/rewrite_horde_to_psr4' => false, // RISKY - disabled by default
    ])
    ->setFinder($finder)
;

// Register custom fixers
$config->registerCustomFixers([
    new \Horde\Components\PhpCsFixer\RemovePhpVersionCommentFixer(),
    new \Horde\Components\PhpCsFixer\UpdateCopyrightYearFixer(),
    new \Horde\Components\PhpCsFixer\MarkDeprecatedHordeCallsFixer(),
    new \Horde\Components\PhpCsFixer\RewriteHordeUtilToPsr4Fixer(),
    new \Horde\Components\PhpCsFixer\RewriteHordeToPsr4Fixer(),
]);

return $config;
