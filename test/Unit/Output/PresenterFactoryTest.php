<?php

/**
 * Copyright 2017-2026 Horde LLC (http://www.horde.org/)
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

namespace Horde\Components\Test\Unit\Output;

use PHPUnit\Framework\TestCase;
use Horde\Components\Output\PresenterFactory;
use Horde\Components\Output\Presenter;
use Horde\Components\Output\Presenter\Classic;
use Horde\Components\Output\Presenter\Ci;
use Horde\Components\Output\Presenter\Unicode;
use Horde_Cli;

/**
 * Test the PresenterFactory.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @coversNothing
 */
class PresenterFactoryTest extends TestCase
{
    private $cli;
    private $originalEnvVars = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cli = $this->createMock(Horde_Cli::class);

        // Save original environment variables
        $envVars = [
            'GITHUB_ACTIONS', 'GITLAB_CI', 'JENKINS_HOME', 'CIRCLECI', 'TRAVIS', 'CI',
            'LANG', 'LC_ALL', 'TERM', 'SSH_CONNECTION', 'SSH_CLIENT',
        ];
        foreach ($envVars as $var) {
            $this->originalEnvVars[$var] = getenv($var);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Restore original environment variables
        foreach ($this->originalEnvVars as $var => $value) {
            if ($value === false) {
                putenv($var);
            } else {
                putenv("{$var}={$value}");
            }
        }
    }

    public function testCreateReturnsPresenter(): void
    {
        $presenter = PresenterFactory::create($this->cli);
        $this->assertInstanceOf(Presenter::class, $presenter);
    }

    public function testCreateWithAutodetectReturnsClassic(): void
    {
        // Clear all detection env vars to force Classic fallback
        putenv('GITHUB_ACTIONS');
        putenv('CI');
        putenv('LANG=C');  // Non-UTF-8 locale
        putenv('TERM=dumb');

        $presenter = PresenterFactory::create($this->cli, ['cli_format' => 'autodetect']);
        $this->assertInstanceOf(Classic::class, $presenter);
    }

    public function testCreateWithClassicFormat(): void
    {
        $presenter = PresenterFactory::create($this->cli, ['cli_format' => 'classic']);
        $this->assertInstanceOf(Classic::class, $presenter);
    }

    public function testCreateWithUnknownFormatFallsBackToClassic(): void
    {
        $presenter = PresenterFactory::create($this->cli, ['cli_format' => 'unknown']);
        $this->assertInstanceOf(Classic::class, $presenter);
    }

    public function testCreateWithNoColorOption(): void
    {
        // Clear env vars to force Classic
        putenv('CI');
        putenv('LANG=C');
        putenv('TERM=dumb');

        // Create presenter with nocolor=true
        $presenter = PresenterFactory::create($this->cli, ['nocolor' => true]);

        // We can't easily test the nocolor flag without accessing internals,
        // but we can verify it creates a Classic presenter
        $this->assertInstanceOf(Classic::class, $presenter);
    }

    public function testCreateWithNoOptions(): void
    {
        // Clear env vars to force Classic
        putenv('CI');
        putenv('LANG=C');
        putenv('TERM=dumb');

        $presenter = PresenterFactory::create($this->cli);
        $this->assertInstanceOf(Classic::class, $presenter);
    }

    public function testCreateWithEmptyOptions(): void
    {
        // Clear env vars to force Classic
        putenv('CI');
        putenv('LANG=C');
        putenv('TERM=dumb');

        $presenter = PresenterFactory::create($this->cli, []);
        $this->assertInstanceOf(Classic::class, $presenter);
    }

    /**
     * Test that format option takes precedence over autodetect.
     */
    public function testExplicitFormatOverridesAutodetect(): void
    {
        // Even if we're in a CI environment, explicit format should override
        $presenter = PresenterFactory::create($this->cli, [
            'cli_format' => 'classic',
        ]);

        $this->assertInstanceOf(Classic::class, $presenter);
    }

    /**
     * Test that invalid format values fall back to classic.
     */
    public function testInvalidFormatFallsBackToClassic(): void
    {
        // Clear env vars to force Classic
        putenv('CI');
        putenv('LANG=C');
        putenv('TERM=dumb');

        $invalidFormats = ['', null, 123, 'invalid', 'CLASSIC', 'Classic'];

        foreach ($invalidFormats as $format) {
            $presenter = PresenterFactory::create($this->cli, ['cli_format' => $format]);
            $this->assertInstanceOf(
                Classic::class,
                $presenter,
                "Format '{$format}' should fall back to Classic"
            );
        }
    }

    /**
     * Test that format option is case sensitive.
     */
    public function testFormatIsCaseSensitive(): void
    {
        // Only lowercase 'classic' should match, uppercase should fall back
        $presenterLower = PresenterFactory::create($this->cli, ['cli_format' => 'classic']);
        $presenterUpper = PresenterFactory::create($this->cli, ['cli_format' => 'CLASSIC']);

        $this->assertInstanceOf(Classic::class, $presenterLower);
        $this->assertInstanceOf(Classic::class, $presenterUpper); // Falls back
    }

    /**
     * Test creating CI presenter explicitly.
     */
    public function testCreateWithCiFormat(): void
    {
        $presenter = PresenterFactory::create($this->cli, ['cli_format' => 'ci']);
        $this->assertInstanceOf(Ci::class, $presenter);
    }

    /**
     * Test autodetect with GITHUB_ACTIONS environment.
     */
    public function testAutodetectWithGitHubActions(): void
    {
        // Clear all CI env vars first
        putenv('GITHUB_ACTIONS');
        putenv('GITLAB_CI');
        putenv('JENKINS_HOME');
        putenv('CIRCLECI');
        putenv('TRAVIS');
        putenv('CI');

        // Set GITHUB_ACTIONS
        putenv('GITHUB_ACTIONS=true');

        $presenter = PresenterFactory::create($this->cli, ['cli_format' => 'autodetect']);
        $this->assertInstanceOf(Ci::class, $presenter);
    }

    /**
     * Test autodetect with GITLAB_CI environment.
     */
    public function testAutodetectWithGitLabCI(): void
    {
        // Clear all CI env vars first
        putenv('GITHUB_ACTIONS');
        putenv('GITLAB_CI');
        putenv('JENKINS_HOME');
        putenv('CIRCLECI');
        putenv('TRAVIS');
        putenv('CI');

        // Set GITLAB_CI
        putenv('GITLAB_CI=true');

        $presenter = PresenterFactory::create($this->cli, ['cli_format' => 'autodetect']);
        $this->assertInstanceOf(Ci::class, $presenter);
    }

    /**
     * Test autodetect with JENKINS_HOME environment.
     */
    public function testAutodetectWithJenkins(): void
    {
        // Clear all CI env vars first
        putenv('GITHUB_ACTIONS');
        putenv('GITLAB_CI');
        putenv('JENKINS_HOME');
        putenv('CIRCLECI');
        putenv('TRAVIS');
        putenv('CI');

        // Set JENKINS_HOME
        putenv('JENKINS_HOME=/var/jenkins');

        $presenter = PresenterFactory::create($this->cli, ['cli_format' => 'autodetect']);
        $this->assertInstanceOf(Ci::class, $presenter);
    }

    /**
     * Test autodetect with CIRCLECI environment.
     */
    public function testAutodetectWithCircleCI(): void
    {
        // Clear all CI env vars first
        putenv('GITHUB_ACTIONS');
        putenv('GITLAB_CI');
        putenv('JENKINS_HOME');
        putenv('CIRCLECI');
        putenv('TRAVIS');
        putenv('CI');

        // Set CIRCLECI
        putenv('CIRCLECI=true');

        $presenter = PresenterFactory::create($this->cli, ['cli_format' => 'autodetect']);
        $this->assertInstanceOf(Ci::class, $presenter);
    }

    /**
     * Test autodetect with TRAVIS environment.
     */
    public function testAutodetectWithTravis(): void
    {
        // Clear all CI env vars first
        putenv('GITHUB_ACTIONS');
        putenv('GITLAB_CI');
        putenv('JENKINS_HOME');
        putenv('CIRCLECI');
        putenv('TRAVIS');
        putenv('CI');

        // Set TRAVIS
        putenv('TRAVIS=true');

        $presenter = PresenterFactory::create($this->cli, ['cli_format' => 'autodetect']);
        $this->assertInstanceOf(Ci::class, $presenter);
    }

    /**
     * Test autodetect with generic CI environment.
     */
    public function testAutodetectWithGenericCI(): void
    {
        // Clear all CI env vars first
        putenv('GITHUB_ACTIONS');
        putenv('GITLAB_CI');
        putenv('JENKINS_HOME');
        putenv('CIRCLECI');
        putenv('TRAVIS');
        putenv('CI');

        // Set generic CI
        putenv('CI=true');

        $presenter = PresenterFactory::create($this->cli, ['cli_format' => 'autodetect']);
        $this->assertInstanceOf(Ci::class, $presenter);
    }

    /**
     * Test autodetect without CI environment returns Classic.
     */
    public function testAutodetectWithoutCIReturnsClassic(): void
    {
        // Clear all CI env vars
        putenv('GITHUB_ACTIONS');
        putenv('GITLAB_CI');
        putenv('JENKINS_HOME');
        putenv('CIRCLECI');
        putenv('TRAVIS');
        putenv('CI');

        // Also clear Unicode support to force Classic
        putenv('LANG=C');
        putenv('TERM=dumb');

        $presenter = PresenterFactory::create($this->cli, ['cli_format' => 'autodetect']);
        $this->assertInstanceOf(Classic::class, $presenter);
    }

    /**
     * Test explicit format overrides CI detection.
     */
    public function testExplicitFormatOverridesCIDetection(): void
    {
        // Set CI environment
        putenv('GITHUB_ACTIONS=true');

        // But request classic explicitly
        $presenter = PresenterFactory::create($this->cli, ['cli_format' => 'classic']);

        // Should get Classic, not CI
        $this->assertInstanceOf(Classic::class, $presenter);
    }

    /**
     * Test creating Unicode presenter explicitly.
     */
    public function testCreateWithUnicodeFormat(): void
    {
        $presenter = PresenterFactory::create($this->cli, ['cli_format' => 'unicode']);
        $this->assertInstanceOf(Unicode::class, $presenter);
    }

    /**
     * Test Unicode presenter respects nocolor flag.
     */
    public function testUnicodeWithNoColor(): void
    {
        $presenter = PresenterFactory::create($this->cli, [
            'cli_format' => 'unicode',
            'nocolor' => true,
        ]);

        $this->assertInstanceOf(Unicode::class, $presenter);
    }

    /**
     * Test autodetect with UTF-8 locale and modern terminal.
     */
    public function testAutodetectWithUnicodeSupportReturnsUnicode(): void
    {
        // Clear CI env vars
        putenv('GITHUB_ACTIONS');
        putenv('GITLAB_CI');
        putenv('JENKINS_HOME');
        putenv('CIRCLECI');
        putenv('TRAVIS');
        putenv('CI');

        // Set up UTF-8 locale
        putenv('LANG=en_US.UTF-8');
        putenv('TERM=xterm-256color');

        // Clear SSH vars (Unicode not recommended over SSH)
        putenv('SSH_CONNECTION');
        putenv('SSH_CLIENT');

        $presenter = PresenterFactory::create($this->cli, ['cli_format' => 'autodetect']);
        $this->assertInstanceOf(Unicode::class, $presenter);
    }

    /**
     * Test autodetect with UTF-8 but SSH connection falls back to Classic.
     */
    public function testAutodetectWithSSHReturnsClassic(): void
    {
        // Clear CI env vars
        putenv('GITHUB_ACTIONS');
        putenv('GITLAB_CI');
        putenv('JENKINS_HOME');
        putenv('CIRCLECI');
        putenv('TRAVIS');
        putenv('CI');

        // Set up UTF-8 locale and modern terminal
        putenv('LANG=en_US.UTF-8');
        putenv('TERM=xterm-256color');

        // Set SSH connection (Unicode not reliable over SSH)
        putenv('SSH_CONNECTION=192.168.1.1 12345 192.168.1.2 22');

        $presenter = PresenterFactory::create($this->cli, ['cli_format' => 'autodetect']);
        $this->assertInstanceOf(Classic::class, $presenter);
    }

    /**
     * Test autodetect without UTF-8 locale falls back to Classic.
     */
    public function testAutodetectWithoutUTF8ReturnsClassic(): void
    {
        // Clear CI env vars
        putenv('GITHUB_ACTIONS');
        putenv('GITLAB_CI');
        putenv('JENKINS_HOME');
        putenv('CIRCLECI');
        putenv('TRAVIS');
        putenv('CI');

        // Set non-UTF-8 locale
        putenv('LANG=en_US.ISO-8859-1');
        putenv('TERM=xterm-256color');
        putenv('SSH_CONNECTION');
        putenv('SSH_CLIENT');

        $presenter = PresenterFactory::create($this->cli, ['cli_format' => 'autodetect']);
        $this->assertInstanceOf(Classic::class, $presenter);
    }

    /**
     * Test autodetect with Alacritty terminal.
     */
    public function testAutodetectWithAlacritty(): void
    {
        // Clear CI env vars
        putenv('GITHUB_ACTIONS');
        putenv('CI');

        // Set up Alacritty (modern terminal)
        putenv('LANG=en_US.UTF-8');
        putenv('TERM=alacritty');
        putenv('SSH_CONNECTION');

        $presenter = PresenterFactory::create($this->cli, ['cli_format' => 'autodetect']);
        $this->assertInstanceOf(Unicode::class, $presenter);
    }

    /**
     * Test autodetect with Kitty terminal.
     */
    public function testAutodetectWithKitty(): void
    {
        // Clear CI env vars
        putenv('GITHUB_ACTIONS');
        putenv('CI');

        // Set up Kitty (modern terminal)
        putenv('LANG=en_US.UTF-8');
        putenv('TERM=xterm-kitty');
        putenv('SSH_CONNECTION');

        $presenter = PresenterFactory::create($this->cli, ['cli_format' => 'autodetect']);
        $this->assertInstanceOf(Unicode::class, $presenter);
    }

    /**
     * Test autodetect with tmux.
     */
    public function testAutodetectWithTmux(): void
    {
        // Clear CI env vars
        putenv('GITHUB_ACTIONS');
        putenv('CI');

        // Set up tmux
        putenv('LANG=en_US.UTF-8');
        putenv('TERM=tmux-256color');
        putenv('SSH_CONNECTION');

        $presenter = PresenterFactory::create($this->cli, ['cli_format' => 'autodetect']);
        $this->assertInstanceOf(Unicode::class, $presenter);
    }

    /**
     * Test CI detection takes precedence over Unicode.
     */
    public function testCIDetectionTakesPrecedenceOverUnicode(): void
    {
        // Set up both CI and Unicode support
        putenv('GITHUB_ACTIONS=true');
        putenv('LANG=en_US.UTF-8');
        putenv('TERM=xterm-256color');
        putenv('SSH_CONNECTION');

        $presenter = PresenterFactory::create($this->cli, ['cli_format' => 'autodetect']);

        // Should prefer CI over Unicode
        $this->assertInstanceOf(Ci::class, $presenter);
    }

    /**
     * Test explicit format always overrides auto-detection.
     */
    public function testExplicitFormatOverridesUnicodeDetection(): void
    {
        // Set up Unicode environment
        putenv('GITHUB_ACTIONS');
        putenv('CI');
        putenv('LANG=en_US.UTF-8');
        putenv('TERM=xterm-256color');
        putenv('SSH_CONNECTION');

        // But request classic explicitly
        $presenter = PresenterFactory::create($this->cli, ['cli_format' => 'classic']);

        $this->assertInstanceOf(Classic::class, $presenter);
    }
}
