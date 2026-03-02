<?php

// Load custom fixers
require_once __DIR__ . '/src/PhpCsFixer/RemovePhpVersionCommentFixer.php';
require_once __DIR__ . '/src/PhpCsFixer/UpdateCopyrightYearFixer.php';

// Only format src/ and test/ directories (exclude lib/ for legacy PSR-0 code)
$potentialDirs = ['/src', '/test', '/tests'];

$finder = (new PhpCsFixer\Finder());
foreach ($potentialDirs as $dir) {
    $full = __DIR__ . $dir;
    if (is_dir($full)) {
        $finder->in($full);
    }
}

$finder->exclude(['fixtures']);

$config = (new PhpCsFixer\Config())
    ->setRules([
        '@PER-CS' => true,
        '@PHP84Migration' => true,
        'php_unit_test_class_requires_covers' => true,
        'nullable_type_declaration_for_default_null_value' => true,
        'Horde/remove_php_version_comment' => true,
        'Horde/update_copyright_year' => true,
    ])
    ->setFinder($finder)
;

// Register custom fixers
$config->registerCustomFixers([
    new \Horde\Components\PhpCsFixer\RemovePhpVersionCommentFixer(),
    new \Horde\Components\PhpCsFixer\UpdateCopyrightYearFixer(),
]);

return $config;
