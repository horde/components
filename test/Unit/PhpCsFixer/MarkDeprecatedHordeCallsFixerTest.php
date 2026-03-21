<?php

declare(strict_types=1);

/**
 * Tests for MarkDeprecatedHordeCallsFixer.
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

use Horde\Components\PhpCsFixer\MarkDeprecatedHordeCallsFixer;
use Horde\Components\Test\PhpCsFixerLoader;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PhpCsFixer\Tokenizer\Tokens;
use SplFileInfo;

/**
 * Tests for MarkDeprecatedHordeCallsFixer.
 *
 * These tests require PHP-CS-Fixer to be installed (PHAR in known locations).
 * Tests will be skipped if PHP-CS-Fixer is not available.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(MarkDeprecatedHordeCallsFixer::class)]
#[Group('phpcsfixer')]
class MarkDeprecatedHordeCallsFixerTest extends TestCase
{
    private MarkDeprecatedHordeCallsFixer $fixer;

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
        $this->fixer = new MarkDeprecatedHordeCallsFixer();
    }

    public function testFixerName(): void
    {
        $this->assertSame('Horde/mark_deprecated_horde_calls', $this->fixer->getName());
    }

    public function testFixerPriority(): void
    {
        $this->assertSame(15, $this->fixer->getPriority());
    }

    public function testAddsDocblockToSimpleHordeCall(): void
    {
        $input = '<?php
class Foo {
    public function bar() {
        $img = Horde::img("icon.png", "Icon");
    }
}';

        $expected = '<?php
class Foo {
    public function bar() {
        /**
         * ARCHITECTURE VIOLATION: Using deprecated Horde::img()
         * @deprecated Use Horde_Themes_Image::tag() instead
         * @see Horde_Deprecated::img()
         */
        $img = Horde::img("icon.png", "Icon");
    }
}';

        $this->doTest($expected, $input);
    }

    public function testAddsDocblockToConditionalCall(): void
    {
        $input = '<?php
if (Horde::hookExists("hook_name")) {
    echo "exists";
}';

        $expected = '<?php
/**
 * ARCHITECTURE VIOLATION: Using deprecated Horde::hookExists()
 * @deprecated Use $GLOBALS[\'injector\']->getInstance(\'Horde_Core_Hooks\')->hookExists() instead
 * @see Horde_Deprecated::hookExists()
 */
if (Horde::hookExists("hook_name")) {
    echo "exists";
}';

        $this->doTest($expected, $input);
    }

    public function testAddsDocblockToAssignment(): void
    {
        $input = '<?php
$config = Horde::loadConfiguration("config.php");';

        $expected = '<?php
/**
 * ARCHITECTURE VIOLATION: Using deprecated Horde::loadConfiguration()
 * @deprecated Use $registry->loadConfigFile() instead
 * @see Horde_Deprecated::loadConfiguration()
 */
$config = Horde::loadConfiguration("config.php");';

        $this->doTest($expected, $input);
    }

    public function testAddsDocblockToMultipleCalls(): void
    {
        $input = '<?php
class Foo {
    public function bar() {
        $img = Horde::img("icon.png");
        $hook = Horde::callHook("hook_name");
    }
}';

        $expected = '<?php
class Foo {
    public function bar() {
        /**
         * ARCHITECTURE VIOLATION: Using deprecated Horde::img()
         * @deprecated Use Horde_Themes_Image::tag() instead
         * @see Horde_Deprecated::img()
         */
        $img = Horde::img("icon.png");
        /**
         * ARCHITECTURE VIOLATION: Using deprecated Horde::callHook()
         * @deprecated Use $GLOBALS[\'injector\']->getInstance(\'Horde_Core_Hooks\')->callHook() instead
         * @see Horde_Deprecated::callHook()
         */
        $hook = Horde::callHook("hook_name");
    }
}';

        $this->doTest($expected, $input);
    }

    public function testSkipsDeprecatedClassFile(): void
    {
        $input = '<?php
class Horde_Deprecated {
    public static function img($src) {
        return Horde_Themes_Image::tag($src);
    }
}';

        // Should not change - this is the Deprecated class itself
        $file = new SplFileInfo('Deprecated.php');
        $tokens = Tokens::fromCode($input);

        $this->fixer->fix($file, $tokens);

        $this->assertSame($input, $tokens->generateCode());
    }

    public function testSkipsExistingDocblock(): void
    {
        $input = '<?php
/**
 * Existing comment
 */
$img = Horde::img("icon.png");';

        // Should not add another docblock
        $this->doTest($input);
    }

    public function testPreservesIndentation(): void
    {
        $input = '<?php
class Foo {
    public function bar() {
        if (true) {
            $img = Horde::img("icon.png");
        }
    }
}';

        $expected = '<?php
class Foo {
    public function bar() {
        if (true) {
            /**
             * ARCHITECTURE VIOLATION: Using deprecated Horde::img()
             * @deprecated Use Horde_Themes_Image::tag() instead
             * @see Horde_Deprecated::img()
             */
            $img = Horde::img("icon.png");
        }
    }
}';

        $this->doTest($expected, $input);
    }

    public function testCoversAllDeprecatedMethods(): void
    {
        $methods = [
            'img' => 'Horde_Themes_Image::tag()',
            'fullSrcImg' => 'Horde_Themes_Image::tag()',
            'base64ImgData' => 'Horde_Themes_Image::base64ImgData()',
            'callHook' => '$GLOBALS[\'injector\']->getInstance(\'Horde_Core_Hooks\')->callHook()',
            'hookExists' => '$GLOBALS[\'injector\']->getInstance(\'Horde_Core_Hooks\')->hookExists()',
            'loadConfiguration' => '$registry->loadConfigFile()',
            'initMap' => 'Horde_Core_HordeMap::init()',
            'sendHTTPResponse' => '(Refactor to use PSR-7 responses)',
            'prepareResponse' => '(Refactor to use PSR-7 responses)',
            'redirect' => '(Refactor to use PSR-7 responses)',
        ];

        foreach ($methods as $method => $upgrade) {
            $input = sprintf('<?php
Horde::%s();', $method);

            $expected = sprintf('<?php
/**
 * ARCHITECTURE VIOLATION: Using deprecated Horde::%s()
 * @deprecated Use %s instead
 * @see Horde_Deprecated::%s()
 */
Horde::%s();', $method, $upgrade, $method, $method);

            $this->doTest($expected, $input, "Failed for method: {$method}");
        }
    }

    public function testMethodChaining(): void
    {
        $input = '<?php
$url = Horde::img("icon.png")->setAttribute("class", "icon");';

        $expected = '<?php
/**
 * ARCHITECTURE VIOLATION: Using deprecated Horde::img()
 * @deprecated Use Horde_Themes_Image::tag() instead
 * @see Horde_Deprecated::img()
 */
$url = Horde::img("icon.png")->setAttribute("class", "icon");';

        $this->doTest($expected, $input);
    }

    public function testIgnoresNonDeprecatedMethods(): void
    {
        $input = '<?php
$url = Horde::url("index.php");
$log = Horde::log("message");';

        // Should not change - url() and log() are not deprecated
        $this->doTest($input);
    }

    public function testIsCandidateReturnsTrueForHordeCode(): void
    {
        $code = '<?php Horde::img("icon.png");';
        $tokens = Tokens::fromCode($code);

        $this->assertTrue($this->fixer->isCandidate($tokens));
    }

    public function testIsCandidateReturnsFalseForCodeWithoutHorde(): void
    {
        $code = '<?php echo "hello";';
        $tokens = Tokens::fromCode($code);

        $this->assertFalse($this->fixer->isCandidate($tokens));
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
