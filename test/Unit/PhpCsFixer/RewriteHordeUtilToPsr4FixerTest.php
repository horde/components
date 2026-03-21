<?php

declare(strict_types=1);

/**
 * Tests for RewriteHordeUtilToPsr4Fixer.
 *
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Test\Unit\PhpCsFixer;

use Horde\Components\PhpCsFixer\RewriteHordeUtilToPsr4Fixer;
use Horde\Components\Test\PhpCsFixerLoader;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PhpCsFixer\Tokenizer\Tokens;
use SplFileInfo;

/**
 * Tests for RewriteHordeUtilToPsr4Fixer.
 *
 * These tests require PHP-CS-Fixer to be installed (PHAR in known locations).
 * Tests will be skipped if PHP-CS-Fixer is not available.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(RewriteHordeUtilToPsr4Fixer::class)]
#[Group('phpcsfixer')]
class RewriteHordeUtilToPsr4FixerTest extends TestCase
{
    private RewriteHordeUtilToPsr4Fixer $fixer;

    public static function setUpBeforeClass(): void
    {
        if (!PhpCsFixerLoader::load()) {
            self::markTestSkipped(
                'PHP-CS-Fixer is not available. Install it to run these tests. ' .
                'See: https://cs.symfony.com/doc/installation.html'
            );
        }
    }

    protected function setUp(): void
    {
        $this->fixer = new RewriteHordeUtilToPsr4Fixer();
    }

    public function testFixerName(): void
    {
        $this->assertSame('Horde/rewrite_horde_util_to_psr4', $this->fixer->getName());
    }

    public function testFixerPriority(): void
    {
        $this->assertSame(20, $this->fixer->getPriority());
    }

    public function testFixerIsRisky(): void
    {
        $this->assertTrue($this->fixer->isRisky());
    }

    public function testRewritesSimpleUtilCall(): void
    {
        $input = '<?php
namespace MyApp;

$data = Horde_Util::getFormData("key");';

        $expected = '<?php
namespace MyApp;

use Horde\Util\Util;

$data = Util::getFormData("key");';

        $this->doTest($expected, $input);
    }

    public function testRewritesMultipleCalls(): void
    {
        $input = '<?php
namespace MyApp;

$get = Horde_Util::getGet("key");
$post = Horde_Util::getPost("key");
$temp = Horde_Util::getTempFile();';

        $expected = '<?php
namespace MyApp;

use Horde\Util\Util;

$get = Util::getGet("key");
$post = Util::getPost("key");
$temp = Util::getTempFile();';

        $this->doTest($expected, $input);
    }

    public function testSkipsExistingUseStatement(): void
    {
        $input = '<?php
namespace MyApp;

use Horde\Util\Util;

$data = Horde_Util::getFormData("key");';

        $expected = '<?php
namespace MyApp;

use Horde\Util\Util;

$data = Util::getFormData("key");';

        $this->doTest($expected, $input);
    }

    public function testInsertsUseStatementInAlphabeticalOrder(): void
    {
        $input = '<?php
namespace MyApp;

use Horde\Config\Config;
use Horde\Xml\Parser;

$data = Horde_Util::getFormData("key");';

        $expected = '<?php
namespace MyApp;

use Horde\Config\Config;
use Horde\Util\Util;
use Horde\Xml\Parser;

$data = Util::getFormData("key");';

        $this->doTest($expected, $input);
    }

    public function testHandlesFileWithoutNamespace(): void
    {
        $input = '<?php

$data = Horde_Util::getFormData("key");';

        $expected = '<?php

use Horde\Util\Util;

$data = Util::getFormData("key");';

        $this->doTest($expected, $input);
    }

    public function testAddsWarningForRemovedDispelMagicQuotes(): void
    {
        $input = '<?php
namespace MyApp;

$data = Horde_Util::dispelMagicQuotes($input);';

        $expected = '<?php
namespace MyApp;

/**
 * WARNING: Horde_Util::dispelMagicQuotes() removed in PSR-4 version
 * Magic quotes are obsolete in PHP 8+. Remove this call.
 */
$data = Horde_Util::dispelMagicQuotes($input);';

        // Should NOT add use statement
        // Should NOT rewrite call
        $this->doTest($expected, $input);
    }

    public function testAddsWarningForRemovedLoadExtension(): void
    {
        $input = '<?php
namespace MyApp;

Horde_Util::loadExtension("mbstring");';

        $expected = '<?php
namespace MyApp;

/**
 * WARNING: Horde_Util::loadExtension() removed in PSR-4 version
 * No direct equivalent. Check extension availability differently.
 */
Horde_Util::loadExtension("mbstring");';

        $this->doTest($expected, $input);
    }

    public function testSupportsAll14Methods(): void
    {
        $methods = [
            'getFormData',
            'getGet',
            'getPost',
            'getPathInfo',
            'getTempFile',
            'getTempDir',
            'getTempFileWithExtension',
            'createTempDir',
            'realPath',
            'deleteAtShutdown',
            'nonInputVar',
            'extensionExists',
            'formInput',
            'pformInput',
        ];

        foreach ($methods as $method) {
            $input = sprintf('<?php
namespace MyApp;

Horde_Util::%s();', $method);

            $expected = sprintf('<?php
namespace MyApp;

use Horde\Util\Util;

Util::%s();', $method);

            $this->doTest($expected, $input, "Failed for method: {$method}");
        }
    }

    public function testHandlesMethodInConditional(): void
    {
        $input = '<?php
namespace MyApp;

if (Horde_Util::extensionExists("mbstring")) {
    echo "exists";
}';

        $expected = '<?php
namespace MyApp;

use Horde\Util\Util;

if (Util::extensionExists("mbstring")) {
    echo "exists";
}';

        $this->doTest($expected, $input);
    }

    public function testHandlesMethodInAssignment(): void
    {
        $input = '<?php
namespace MyApp;

$temp = Horde_Util::getTempDir();';

        $expected = '<?php
namespace MyApp;

use Horde\Util\Util;

$temp = Util::getTempDir();';

        $this->doTest($expected, $input);
    }

    public function testPreservesIndentationInWarning(): void
    {
        $input = '<?php
namespace MyApp;

class Foo {
    public function bar() {
        $data = Horde_Util::dispelMagicQuotes($input);
    }
}';

        $expected = '<?php
namespace MyApp;

class Foo {
    public function bar() {
        /**
         * WARNING: Horde_Util::dispelMagicQuotes() removed in PSR-4 version
         * Magic quotes are obsolete in PHP 8+. Remove this call.
         */
        $data = Horde_Util::dispelMagicQuotes($input);
    }
}';

        $this->doTest($expected, $input);
    }

    public function testMixedSupportedAndRemovedMethods(): void
    {
        $input = '<?php
namespace MyApp;

$data = Horde_Util::getFormData("key");
$magic = Horde_Util::dispelMagicQuotes($input);';

        $expected = '<?php
namespace MyApp;

use Horde\Util\Util;

$data = Util::getFormData("key");
/**
 * WARNING: Horde_Util::dispelMagicQuotes() removed in PSR-4 version
 * Magic quotes are obsolete in PHP 8+. Remove this call.
 */
$magic = Horde_Util::dispelMagicQuotes($input);';

        $this->doTest($expected, $input);
    }

    public function testIsCandidateReturnsTrueForHordeUtilCode(): void
    {
        $code = '<?php Horde_Util::getFormData("key");';
        $tokens = Tokens::fromCode($code);

        $this->assertTrue($this->fixer->isCandidate($tokens));
    }

    public function testIsCandidateReturnsFalseForCodeWithoutHordeUtil(): void
    {
        $code = '<?php echo "hello";';
        $tokens = Tokens::fromCode($code);

        $this->assertFalse($this->fixer->isCandidate($tokens));
    }

    public function testIgnoresOtherClasses(): void
    {
        $input = '<?php
namespace MyApp;

$data = MyUtil::getData("key");
$other = Some_Other_Class::method();';

        // Should not change
        $this->doTest($input);
    }

    public function testSkipsExistingWarningComment(): void
    {
        $input = '<?php
namespace MyApp;

/**
 * Some existing comment
 */
$data = Horde_Util::dispelMagicQuotes($input);';

        // Should not add another warning
        $this->doTest($input);
    }

    /**
     * Test helper to run fixer and compare output.
     *
     * @param string      $expected Expected output
     * @param string|null $input    Input code (null means expect no changes)
     * @param string      $message  Assertion message
     */
    private function doTest(string $expected, ?string $input = null, string $message = ''): void
    {
        $file = new SplFileInfo('test.php');
        $tokens = Tokens::fromCode($input ?? $expected);

        $this->fixer->fix($file, $tokens);

        $this->assertSame($expected, $tokens->generateCode(), $message);
    }
}
