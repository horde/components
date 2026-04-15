<?php

declare(strict_types=1);

/**
 * Tests for RewriteHordeToPsr4Fixer.
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

use Horde\Components\PhpCsFixer\RewriteHordeToPsr4Fixer;
use Horde\Components\Test\PhpCsFixerLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PhpCsFixer\Tokenizer\Tokens;
use SplFileInfo;

/**
 * Tests for RewriteHordeToPsr4Fixer.
 *
 * These tests require PHP-CS-Fixer to be installed (PHAR in known locations).
 * Tests will be skipped if PHP-CS-Fixer is not available.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(RewriteHordeToPsr4Fixer::class)]
#[Group('phpcsfixer')]
class RewriteHordeToPsr4FixerTest extends TestCase
{
    private RewriteHordeToPsr4Fixer $fixer;

    public static function setUpBeforeClass(): void
    {
        if (!PhpCsFixerLoader::load()) {
            self::markTestSkipped(
                'PHP-CS-Fixer is not available. Install it to run these tests. '
                . 'See: https://cs.symfony.com/doc/installation.html'
            );
        }
    }

    protected function setUp(): void
    {
        $this->fixer = new RewriteHordeToPsr4Fixer();
    }

    public function testFixerName(): void
    {
        $this->assertSame('Horde/rewrite_horde_to_psr4', $this->fixer->getName());
    }

    public function testFixerPriority(): void
    {
        $this->assertSame(25, $this->fixer->getPriority());
    }

    public function testFixerIsRisky(): void
    {
        $this->assertTrue($this->fixer->isRisky());
    }

    public function testAllForwardedCallsGetUseStatement(): void
    {
        $input = '<?php
namespace MyApp;

$json = Horde::escapeJson($data);';

        $expected = '<?php
namespace MyApp;

use Horde\Core\Horde;

$json = Horde::escapeJson($data);';

        $this->doTest($expected, $input);
    }

    public function testMultipleForwardedCallsGetSingleUseStatement(): void
    {
        $input = '<?php
namespace MyApp;

Horde::startBuffer();
$json = Horde::escapeJson($data);
$output = Horde::endBuffer();';

        $expected = '<?php
namespace MyApp;

use Horde\Core\Horde;

Horde::startBuffer();
$json = Horde::escapeJson($data);
$output = Horde::endBuffer();';

        $this->doTest($expected, $input);
    }

    public function testMixedCallsRewriteOnlyForwardedToFqcn(): void
    {
        $input = '<?php
namespace MyApp;

$url = Horde::url("/path");
$json = Horde::escapeJson($data);';

        $expected = '<?php
namespace MyApp;

$url = Horde::url("/path");
$json = \Horde\Core\Horde::escapeJson($data);';

        $this->doTest($expected, $input);
    }

    public function testBareCallsUnchangedWithExistingUseStatement(): void
    {
        $input = '<?php
namespace MyApp;

use Horde\Core\Horde;

$json = Horde::escapeJson($data);';

        // Should not change — bare calls resolve correctly via use statement
        $this->doTest($input);
    }

    public function testSkipsNonForwardedMethods(): void
    {
        $input = '<?php
namespace MyApp;

$url = Horde::url("/path");
$self = Horde::selfUrl();';

        // Should not change — these methods are not forwarded
        $this->doTest($input);
    }

    public function testSkipsHordeClassFile(): void
    {
        $input = '<?php
class Horde
{
    public static function escapeJson($data)
    {
        return \Horde\Core\Horde::escapeJson($data);
    }
}';

        // Should not change — this is the Horde class file itself
        $this->doTest($input, null, '', '/some/path/lib/Horde.php');
    }

    public function testSkipsLibDirectory(): void
    {
        $input = '<?php

$json = Horde::escapeJson($data);';

        // Should not change — file is in lib/
        $this->doTest($input, null, '', '/some/path/lib/Some/File.php');
    }

    public function testIgnoresAlreadyFqcnCalls(): void
    {
        $input = '<?php
namespace MyApp;

$json = \Horde\Core\Horde::escapeJson($data);';

        // Already FQCN — should not change
        $this->doTest($input);
    }

    public function testIgnoresObjectCalls(): void
    {
        $input = '<?php
namespace MyApp;

$horde->escapeJson($data);';

        // Instance method call, not static — should not change
        $this->doTest($input);
    }

    public function testHandlesAllForwardedMethods(): void
    {
        $methods = [
            'signQueryString',
            'verifySignedQueryString',
            'verifySignedUrl',
            'escapeJson',
            'isConnectionSecure',
            'requireSecureConnection',
            'getDriverConfig',
            'assertDriverConfig',
            'externalUrl',
            'link',
            'linkTooltip',
            'widget',
            'getTempDir',
            'getTempFile',
            'webServerID',
            'getAccessKey',
            'stripAccessKey',
            'highlightAccessKey',
            'getAccessKeyAndTitle',
            'label',
            'wrapInlineScript',
            'popupJs',
            'startBuffer',
            'endBuffer',
            'contentSent',
            'sidebar',
            'permissionDeniedError',
        ];

        foreach ($methods as $method) {
            $input = sprintf('<?php
namespace MyApp;

Horde::%s();', $method);

            $expected = sprintf('<?php
namespace MyApp;

use Horde\Core\Horde;

Horde::%s();', $method);

            $this->doTest($expected, $input, "Failed for method: {$method}");
        }
    }

    public function testIdempotencyUseStatementPath(): void
    {
        $input = '<?php
namespace MyApp;

$json = Horde::escapeJson($data);';

        $file = new SplFileInfo('test.php');

        // First run
        $tokens = Tokens::fromCode($input);
        $this->fixer->fix($file, $tokens);
        $firstRun = $tokens->generateCode();

        // Second run
        $tokens = Tokens::fromCode($firstRun);
        $this->fixer->fix($file, $tokens);
        $secondRun = $tokens->generateCode();

        $this->assertSame($firstRun, $secondRun, 'Fixer should be idempotent');
    }

    public function testIdempotencyFqcnPath(): void
    {
        $input = '<?php
namespace MyApp;

$url = Horde::url("/path");
$json = Horde::escapeJson($data);';

        $file = new SplFileInfo('test.php');

        // First run
        $tokens = Tokens::fromCode($input);
        $this->fixer->fix($file, $tokens);
        $firstRun = $tokens->generateCode();

        // Second run
        $tokens = Tokens::fromCode($firstRun);
        $this->fixer->fix($file, $tokens);
        $secondRun = $tokens->generateCode();

        $this->assertSame($firstRun, $secondRun, 'Fixer should be idempotent for FQCN path');
    }

    public function testIsCandidateReturnsTrueForHordeCode(): void
    {
        $tokens = Tokens::fromCode('<?php Horde::escapeJson($data);');
        $this->assertTrue($this->fixer->isCandidate($tokens));
    }

    public function testIsCandidateReturnsFalseForCodeWithoutHorde(): void
    {
        $tokens = Tokens::fromCode('<?php echo "hello";');
        $this->assertFalse($this->fixer->isCandidate($tokens));
    }

    public function testUseStatementInsertedAfterExistingUseStatements(): void
    {
        $input = '<?php
namespace MyApp;

use Some\Other\Class1;
use Some\Other\Class2;

Horde::startBuffer();';

        $expected = '<?php
namespace MyApp;

use Some\Other\Class1;
use Some\Other\Class2;
use Horde\Core\Horde;

Horde::startBuffer();';

        $this->doTest($expected, $input);
    }

    public function testDoesNotTouchSelfCalls(): void
    {
        // self:: should never match
        $input = '<?php
namespace MyApp;

self::escapeJson($data);';

        $this->doTest($input);
    }

    public function testHandlesCallInsideConditional(): void
    {
        $input = '<?php
namespace MyApp;

if (Horde::isConnectionSecure()) {
    echo "secure";
}';

        $expected = '<?php
namespace MyApp;

use Horde\Core\Horde;

if (Horde::isConnectionSecure()) {
    echo "secure";
}';

        $this->doTest($expected, $input);
    }

    public function testHandlesConstantAccess(): void
    {
        // Horde::SSL_ALWAYS is a constant, not a method — should not be touched
        $input = '<?php
namespace MyApp;

$ssl = Horde::SSL_ALWAYS;';

        // SSL_ALWAYS is not followed by () but the fixer checks for T_STRING
        // after :: — constants are T_STRING too. But they're not in the
        // forwarded list, so should be ignored.
        $this->doTest($input);
    }

    public function testFqcnBackslashHordeGetsRewritten(): void
    {
        $input = '<?php
namespace MyApp;

$json = \Horde::escapeJson($data);';

        $expected = '<?php
namespace MyApp;

$json = \Horde\Core\Horde::escapeJson($data);';

        $this->doTest($expected, $input);
    }

    public function testFqcnRewrittenEvenWithExistingUseStatement(): void
    {
        // \Horde:: explicitly references the global class, so it must be
        // rewritten regardless of any use statement.
        $input = '<?php
namespace MyApp;

use Horde\Core\Horde;

$json = \Horde::escapeJson($data);';

        $expected = '<?php
namespace MyApp;

use Horde\Core\Horde;

$json = \Horde\Core\Horde::escapeJson($data);';

        $this->doTest($expected, $input);
    }

    public function testNonForwardedFqcnLeftAlone(): void
    {
        $input = '<?php
namespace MyApp;

$url = \Horde::url("/path");';

        // \Horde::url() is not in the forwarded list — should not change
        $this->doTest($input);
    }

    public function testMixedBareAndFqcnForwardedCalls(): void
    {
        // Both bare and FQCN forwarded calls in the same file:
        // bare calls get use statement, FQCN calls get rewritten.
        $input = '<?php
namespace MyApp;

Horde::startBuffer();
$json = \Horde::escapeJson($data);';

        $expected = '<?php
namespace MyApp;

use Horde\Core\Horde;

Horde::startBuffer();
$json = \Horde\Core\Horde::escapeJson($data);';

        $this->doTest($expected, $input);
    }

    public function testMixedBareNonForwardedAndFqcnForwarded(): void
    {
        // bare non-forwarded call + FQCN forwarded call
        $input = '<?php
namespace MyApp;

$url = Horde::url("/path");
$json = \Horde::escapeJson($data);';

        $expected = '<?php
namespace MyApp;

$url = Horde::url("/path");
$json = \Horde\Core\Horde::escapeJson($data);';

        $this->doTest($expected, $input);
    }

    public function testIdempotencyFqcnBackslashHorde(): void
    {
        $input = '<?php
namespace MyApp;

$json = \Horde::escapeJson($data);';

        $file = new SplFileInfo('test.php');

        $tokens = Tokens::fromCode($input);
        $this->fixer->fix($file, $tokens);
        $firstRun = $tokens->generateCode();

        $tokens = Tokens::fromCode($firstRun);
        $this->fixer->fix($file, $tokens);
        $secondRun = $tokens->generateCode();

        $this->assertSame($firstRun, $secondRun, 'Fixer should be idempotent for \\Horde:: path');
    }

    public function testIdempotencyMixedBareAndFqcn(): void
    {
        $input = '<?php
namespace MyApp;

Horde::startBuffer();
$json = \Horde::escapeJson($data);';

        $file = new SplFileInfo('test.php');

        $tokens = Tokens::fromCode($input);
        $this->fixer->fix($file, $tokens);
        $firstRun = $tokens->generateCode();

        $tokens = Tokens::fromCode($firstRun);
        $this->fixer->fix($file, $tokens);
        $secondRun = $tokens->generateCode();

        $this->assertSame($firstRun, $secondRun, 'Fixer should be idempotent for mixed bare + FQCN');
    }

    /**
     * Test helper to run fixer and compare output.
     *
     * @param string      $expected Expected output
     * @param string|null $input    Input code (null means expect no changes)
     * @param string      $message  Assertion message
     * @param string      $filePath File path for the SplFileInfo
     */
    private function doTest(
        string $expected,
        ?string $input = null,
        string $message = '',
        string $filePath = 'test.php',
    ): void {
        $file = new SplFileInfo($filePath);
        $tokens = Tokens::fromCode($input ?? $expected);

        $this->fixer->fix($file, $tokens);

        $this->assertSame($expected, $tokens->generateCode(), $message);
    }
}
