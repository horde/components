<?php
$potentialDirs = ['/lib', '/src', '/tests'];

$finder = (new PhpCsFixer\Finder());

foreach ($potentialDirs as $dir) {
    $full = __DIR__ . $dir;
    if (is_dir($full)) {
        $finder->in($full);
    }
}


return (new PhpCsFixer\Config())
    ->setRules([
        '@PER-CS' => true,
        '@PHP82Migration' => true,
        'php_unit_test_class_requires_covers' => true,
    ])
    ->setFinder($finder)
;
