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

namespace Horde\Components\Test\Unit\Ci\Setup;

use Horde\Components\Ci\Setup\ComposerInstaller;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the composer-error classifier.
 *
 * The install path itself shells out to composer and is not unit-tested
 * here; the classifier is the only part of ComposerInstaller that's
 * worth pinning down to specific composer error strings, because
 * downstream PR-comment rendering hinges on these category labels.
 */
#[CoversClass(ComposerInstaller::class)]
class ComposerInstallerTest extends TestCase
{
    public function testClassifyStabilityGate(): void
    {
        $msg = 'horde/mime[v3.0.0alpha4] require horde/listheaders ^2 '
            . '-> found horde/listheaders[2.0.0alpha1] but it does not '
            . 'match your minimum-stability.';
        $this->assertSame('stability_gate', ComposerInstaller::classifyError($msg));
    }

    public function testClassifyPlatformMissingPreResolution(): void
    {
        // Composer's pre-resolution rejection: a require key flagged
        // missing before the dependency tree is even examined.
        $msg = 'horde/mapi require ext-bcmath * -> it is missing from your system.';
        $this->assertSame('platform_missing', ComposerInstaller::classifyError($msg));
    }

    public function testClassifyPlatformMissingPostResolution(): void
    {
        // The longer post-resolution variant that suggests polyfills.
        $msg = "PHP's bcmath extension is missing from your system. "
            . "Install or enable PHP's bcmath extension.";
        $this->assertSame('platform_missing', ComposerInstaller::classifyError($msg));
    }

    public function testClassifyPhpVersionConflict(): void
    {
        $msg = 'horde/mapi[2.0.0alpha1] require php ^7 -> '
            . 'your php version (8.2.31) does not satisfy that requirement.';
        $this->assertSame('php_version', ComposerInstaller::classifyError($msg));
    }

    public function testClassifyUnknownFallsThrough(): void
    {
        $msg = 'No idea what this error means; some random failure.';
        $this->assertSame('unknown', ComposerInstaller::classifyError($msg));
    }

    public function testClassifyHandlesWorkflowCommandEscapedNewlines(): void
    {
        // GitHub-Actions-escaped newlines arrive as %0A.
        $msg = 'horde/listheaders -> found at alpha%0Abut it does not '
            . 'match your minimum-stability.';
        $this->assertSame('stability_gate', ComposerInstaller::classifyError($msg));
    }
}
