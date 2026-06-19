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

namespace Horde\Components\Test\Unit\Ci;

use Horde\Components\Ci\GitHubAnnotations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the GitHub Actions annotation emitter.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(GitHubAnnotations::class)]
class GitHubAnnotationsTest extends TestCase
{
    private ?string $previousEnv = null;

    protected function setUp(): void
    {
        // Save and clear so each test sets its own state.
        $this->previousEnv = getenv('GITHUB_ACTIONS') ?: null;
        putenv('GITHUB_ACTIONS');
    }

    protected function tearDown(): void
    {
        if ($this->previousEnv === null) {
            putenv('GITHUB_ACTIONS');
        } else {
            putenv('GITHUB_ACTIONS=' . $this->previousEnv);
        }
    }

    public function testNoOpWhenNotInGitHubActions(): void
    {
        ob_start();
        try {
            GitHubAnnotations::error('boom');
            $this->assertSame('', ob_get_contents());
        } finally {
            ob_end_clean();
        }
    }

    public function testNoOpWhenGitHubActionsIsAnythingOtherThanTrue(): void
    {
        putenv('GITHUB_ACTIONS=false');
        ob_start();
        try {
            GitHubAnnotations::warning('hush');
            $this->assertSame('', ob_get_contents());
        } finally {
            ob_end_clean();
        }
    }

    public function testEmitErrorWithFullTriple(): void
    {
        putenv('GITHUB_ACTIONS=true');
        $captured = $this->captureEmit(static function (): void {
            GitHubAnnotations::error(
                'something is wrong',
                'src/Foo.php',
                42,
                'PHPStan level 5'
            );
        });
        $this->assertSame(
            '::error file=src/Foo.php,line=42,title=PHPStan level 5::something is wrong' . PHP_EOL,
            $captured
        );
    }

    public function testEmitWarningWithFileOnly(): void
    {
        putenv('GITHUB_ACTIONS=true');
        $captured = $this->captureEmit(static function (): void {
            GitHubAnnotations::warning('would be modified', 'src/Foo.php');
        });
        $this->assertSame(
            '::warning file=src/Foo.php::would be modified' . PHP_EOL,
            $captured
        );
    }

    public function testEmitNoticeSidebarOnlyWhenAllParamsNull(): void
    {
        putenv('GITHUB_ACTIONS=true');
        $captured = $this->captureEmit(static function (): void {
            GitHubAnnotations::notice('heads up');
        });
        $this->assertSame('::notice::heads up' . PHP_EOL, $captured);
    }

    public function testEscapeMessageNewlines(): void
    {
        putenv('GITHUB_ACTIONS=true');
        $captured = $this->captureEmit(static function (): void {
            GitHubAnnotations::error("line one\nline two");
        });
        $this->assertSame('::error::line one%0Aline two' . PHP_EOL, $captured);
    }

    public function testEscapeMessagePercent(): void
    {
        putenv('GITHUB_ACTIONS=true');
        $captured = $this->captureEmit(static function (): void {
            GitHubAnnotations::error('100% broken');
        });
        $this->assertSame('::error::100%25 broken' . PHP_EOL, $captured);
    }

    public function testEscapePropertyValueColonAndComma(): void
    {
        putenv('GITHUB_ACTIONS=true');
        // Title legitimately containing colons and commas.
        $captured = $this->captureEmit(static function (): void {
            GitHubAnnotations::error(
                'msg',
                null,
                null,
                'Foo: bar, baz'
            );
        });
        $this->assertSame(
            '::error title=Foo%3A bar%2C baz::msg' . PHP_EOL,
            $captured
        );
    }

    public function testRelativizeForAnnotation(): void
    {
        $this->assertSame(
            'src/Foo.php',
            GitHubAnnotations::relativizeForAnnotation(
                '/tmp/horde-ci/lanes/php8.3-dev/Victim/src/Foo.php',
                '/tmp/horde-ci/lanes/php8.3-dev/Victim'
            )
        );
    }

    public function testRelativizeForAnnotationLeavesUnrelatedPathUnchanged(): void
    {
        $this->assertSame(
            '/elsewhere/Foo.php',
            GitHubAnnotations::relativizeForAnnotation(
                '/elsewhere/Foo.php',
                '/tmp/horde-ci/lanes/php8.3-dev/Victim'
            )
        );
    }

    public function testIsActiveTrueOnlyWhenEnvIsExactlyTrue(): void
    {
        putenv('GITHUB_ACTIONS=true');
        $this->assertTrue(GitHubAnnotations::isActive());

        putenv('GITHUB_ACTIONS=1'); // truthy in some shells but not GitHub's contract
        $this->assertFalse(GitHubAnnotations::isActive());

        putenv('GITHUB_ACTIONS');
        $this->assertFalse(GitHubAnnotations::isActive());
    }

    /**
     * Capture stdout produced by the supplied callable.
     */
    private function captureEmit(callable $fn): string
    {
        ob_start();
        try {
            $fn();
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
