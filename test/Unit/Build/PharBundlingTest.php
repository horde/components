<?php

/**
 * Copyright 2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Test\Unit\Build;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard: PHPUnit/PHPStan/PHP-CS-Fixer must not be
 * bundled inside horde-components.phar.
 *
 * Background. The phar's stub registers itself with a PHAR alias that
 * encodes the patch version (e.g. `phar://phpunit-12.5.30.phar`). When a
 * second phar — horde-components.phar — was built with `phpunit/phpunit`
 * bundled in its `vendor/` directory, the inner PHPUnit class set was
 * autoloaded from inside horde-components.phar *before* the downloaded
 * `phpunit-12.5.phar` from the tools cache could register. SchemaFinder
 * inside PHPUnit looked for schema files at the bundled location, where
 * the XSDs do not exist (box-compile prunes them), and raised
 * "Schema for PHPUnit 12.5 is not available". Tests then never ran.
 *
 * The fix has two parts:
 *
 * 1. `composer.json` does not list `phpunit/phpunit` (or `phpstan`,
 *    `php-cs-fixer`) as a runtime dependency. Local development uses a
 *    globally installed phpunit; CI downloads one to the tools cache.
 *
 * 2. `box.json` excludes `phpunit`, `phpstan`, and `php-cs-fixer` from
 *    the vendor finder. Box's `exclude` matches path components, so the
 *    whole `vendor/phpunit/*` tree is dropped — that catches transitive
 *    deps of phpunit too (php-text-template, php-file-iterator, ...).
 *
 * This test pins both invariants. It only inspects on-disk config files,
 * so it can run without a built phar.
 */
#[Group('build')]
class PharBundlingTest extends TestCase
{
    private const string COMPONENTS_DIR = __DIR__ . '/../../..';

    public function testComposerJsonDoesNotRequirePhpUnit(): void
    {
        $composer = $this->readComposerJson();

        $required = array_merge(
            array_keys($composer['require'] ?? []),
            array_keys($composer['require-dev'] ?? [])
        );

        $forbidden = ['phpunit/phpunit', 'phpstan/phpstan', 'friendsofphp/php-cs-fixer'];
        foreach ($forbidden as $package) {
            $this->assertNotContains(
                $package,
                $required,
                sprintf(
                    "%s must not appear in composer.json (require or require-dev). "
                        . "Tool runners are downloaded to the CI tools cache and run as separate phars; "
                        . "bundling them inside horde-components.phar reintroduces the dual-phar collision "
                        . "(\"Schema for PHPUnit X.Y is not available\").",
                    $package
                )
            );
        }
    }

    public function testBoxConfigExcludesToolDirsFromVendorFinder(): void
    {
        $box = $this->readBoxJson();

        $vendorFinder = $this->findVendorFinder($box);
        $this->assertNotNull(
            $vendorFinder,
            'box.json must declare a finder block with "in": "vendor".'
        );

        $excluded = $vendorFinder['exclude'] ?? [];
        foreach (['phpunit', 'phpstan', 'php-cs-fixer'] as $tool) {
            $this->assertContains(
                $tool,
                $excluded,
                sprintf(
                    'box.json vendor finder must exclude "%s" so the tool '
                        . 'is not bundled into horde-components.phar (regression guard).',
                    $tool
                )
            );
        }
    }

    private function readComposerJson(): array
    {
        $path = self::COMPONENTS_DIR . '/composer.json';
        $this->assertFileExists($path);
        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);
        return $data;
    }

    private function readBoxJson(): array
    {
        $path = self::COMPONENTS_DIR . '/box.json';
        $this->assertFileExists($path);
        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);
        return $data;
    }

    /**
     * Find the finder entry that scans the vendor directory. Box accepts
     * either a single finder object or a list; we have a list in our
     * config but stay defensive.
     */
    private function findVendorFinder(array $box): ?array
    {
        $finders = $box['finder'] ?? null;
        if (!is_array($finders)) {
            return null;
        }
        // Single-finder shape.
        if (isset($finders['in'])) {
            return $finders['in'] === 'vendor' ? $finders : null;
        }
        // List shape.
        foreach ($finders as $f) {
            if (is_array($f) && ($f['in'] ?? null) === 'vendor') {
                return $f;
            }
        }
        return null;
    }
}
