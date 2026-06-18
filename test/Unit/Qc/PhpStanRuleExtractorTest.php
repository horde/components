<?php

/**
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
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

namespace Horde\Components\Test\Unit\Qc;

use Horde\Components\Qc\PhpStanRuleExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PhpStanRuleExtractor.
 *
 * Phar-context extraction is structurally awkward to test in unit tests
 * (would require constructing an in-memory phar with the right layout) and
 * is exercised by the Victim smoke loop instead. The unit tests cover the
 * developer-checkout branch and the idempotency contract.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(PhpStanRuleExtractor::class)]
class PhpStanRuleExtractorTest extends TestCase
{
    public function testCheckoutShortCircuitsAndReturnsRepoBootstrap(): void
    {
        $extractor = new PhpStanRuleExtractor();
        $bootstrap = $extractor->extract(null);

        $this->assertFileExists($bootstrap);
        $this->assertSame('phpstan-bootstrap.php', basename($bootstrap));
        // Repo-level bootstrap sits next to a src/ directory.
        $this->assertDirectoryExists(dirname($bootstrap) . '/src/PhpStan/Rules');
    }

    public function testCheckoutIgnoresCacheDirArgument(): void
    {
        $extractor = new PhpStanRuleExtractor();
        $cacheDir = sys_get_temp_dir() . '/phpstan-extractor-test-' . bin2hex(random_bytes(4));

        try {
            $bootstrap = $extractor->extract($cacheDir);

            // We're in a developer checkout, so the cache dir should NOT
            // have been touched.
            $this->assertDirectoryDoesNotExist($cacheDir);
            $this->assertFileExists($bootstrap);
        } finally {
            if (is_dir($cacheDir)) {
                $this->recursiveRm($cacheDir);
            }
        }
    }

    private function recursiveRm(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->recursiveRm($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
