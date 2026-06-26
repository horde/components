<?php

/**
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Test\Unit\Helper;

use Horde\Components\Helper\PlatformResolver;
use Horde\Components\Helper\Shell;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PlatformResolver.
 *
 * Covers the deterministic parts of the resolver: PHP constraint
 * parsing, range expansion, the CURRENT_MAX_PHP cap, the platform-key
 * filter, and composer.lock walking. The composer subprocess itself is
 * deliberately not exercised here — that is integration territory and
 * requires network access.
 */
#[CoversClass(PlatformResolver::class)]
class PlatformResolverTest extends TestCase
{
    private PlatformResolver $resolver;

    protected function setUp(): void
    {
        // Shell is unused on the code paths under test; passing a real
        // one keeps the constructor signature honest.
        $this->resolver = new PlatformResolver(new Shell());
    }

    public static function constraintRangeProvider(): array
    {
        $max = PlatformResolver::CURRENT_MAX_PHP;
        return [
            // Lower bound from the constraint, upper from CURRENT_MAX_PHP.
            'caret two-part'      => ['^8.2', ['8.2', '8.3', '8.4', '8.5', '8.6']],
            'caret three-part'    => ['^8.2.0', ['8.2', '8.3', '8.4', '8.5', '8.6']],
            'tilde two-part'      => ['~8.2', ['8.2', '8.3', '8.4', '8.5', '8.6']],
            // Three-part tilde locks the minor.
            'tilde three-part'    => ['~8.2.0', ['8.2']],
            'gte two-part'        => ['>=8.1', ['8.1', '8.2', '8.3', '8.4', '8.5', '8.6']],
            'gte three-part'      => ['>=8.1.0', ['8.1', '8.2', '8.3', '8.4', '8.5', '8.6']],
            'lt'                  => ['<8.5', ['8.0', '8.1', '8.2', '8.3', '8.4']],
            'lte'                 => ['<=8.5', ['8.0', '8.1', '8.2', '8.3', '8.4', '8.5']],
            'compound comma'      => ['>=8.2,<8.5', ['8.2', '8.3', '8.4']],
            'compound space'      => ['>=8.2 <8.5', ['8.2', '8.3', '8.4']],
            'compound comma sp'   => ['>=8.2, <8.5', ['8.2', '8.3', '8.4']],
            'wildcard'            => ['8.2.*', ['8.2']],
            'exact two-part'      => ['8.2', ['8.2']],
            'exact three-part'    => ['8.2.0', ['8.2']],
            'cap kicks in'        => ['^8.5', ['8.5', $max]],
            // Empty constraint and unparseable input fall back to CURRENT_MAX_PHP.
            'empty falls back'    => ['', [$max]],
            'garbage falls back'  => ['nonsense-input', [$max]],
            // OR composition: ^8.2 || ^9.0 — only the 8.x leg matches
            // current candidates because we don't list 9.x yet.
            'or'                  => ['^8.2 || ^9.0', ['8.2', '8.3', '8.4', '8.5', '8.6']],
            // Range syntax (composer "1.0.0 - 2.0.0").
            'range syntax'        => ['8.2.0 - 8.4.99', ['8.2', '8.3', '8.4']],
        ];
    }

    #[DataProvider('constraintRangeProvider')]
    public function testPhpVersionRange(string $constraint, array $expected): void
    {
        $this->assertSame($expected, $this->resolver->phpVersionRange($constraint));
    }

    public function testPhpVersionRangeUpperCappedAtCurrentMax(): void
    {
        // ^8.6 (i.e. at-or-above CURRENT_MAX_PHP) yields just the cap.
        $max = PlatformResolver::CURRENT_MAX_PHP;
        $this->assertSame([$max], $this->resolver->phpVersionRange('^' . $max));
    }

    public function testPhpVersionRangeUnsupportedMajorFallsBack(): void
    {
        // A constraint pinned to a major we don't enumerate (^7.4)
        // returns CURRENT_MAX_PHP rather than an empty list so the
        // caller has at least one PHP version to attempt.
        $this->assertSame(
            [PlatformResolver::CURRENT_MAX_PHP],
            $this->resolver->phpVersionRange('^7.4'),
        );
    }

    public static function laneSetProvider(): array
    {
        $full = PlatformResolver::CANDIDATE_PHP_MINORS;
        return [
            // Real-world Horde constraints. ^8.1 (Date) widens to include 8.1
            // and 8.6; ^8.2 (most libraries) drops 8.0 and 8.1.
            'caret 8.1'           => ['^8.1', ['8.1', '8.2', '8.3', '8.4', '8.5', '8.6']],
            'caret 8.2'           => ['^8.2', ['8.2', '8.3', '8.4', '8.5', '8.6']],
            'caret 8.3'           => ['^8.3', ['8.3', '8.4', '8.5', '8.6']],
            'gte 8.5'             => ['>=8.5', ['8.5', '8.6']],
            'compound range'      => ['>=8.2,<8.5', ['8.2', '8.3', '8.4']],
            'or with future'      => ['^8.2 || ^9.0', ['8.2', '8.3', '8.4', '8.5', '8.6']],
            // Fallback semantics differ from phpVersionRange: lane selection
            // defaults to the FULL candidate list, not just CURRENT_MAX_PHP.
            'empty fallback'      => ['', $full],
            'garbage fallback'    => ['nonsense', $full],
            'unsupported major'   => ['^7.4', $full],
        ];
    }

    #[DataProvider('laneSetProvider')]
    public function testPhpVersionLaneSet(string $constraint, array $expected): void
    {
        $this->assertSame($expected, PlatformResolver::phpVersionLaneSet($constraint));
    }

    public function testPhpVersionLaneSetIsStatic(): void
    {
        // Lane selection is static because the answer only depends on
        // the constraint string and the candidate constant. Callers
        // outside this class (notably SetupCommand) need to call this
        // without constructing the heavier PlatformResolver instance.
        $reflection = new \ReflectionMethod(PlatformResolver::class, 'phpVersionLaneSet');
        $this->assertTrue($reflection->isStatic());
        $this->assertTrue($reflection->isPublic());
    }

    public function testCandidatePhpMinorsIsPublic(): void
    {
        // The candidate constant doubles as the lane-selection fallback
        // and must be reachable from outside this class. A regression
        // to private would silently break the fallback that
        // SetupCommand depends on.
        $reflection = new \ReflectionClassConstant(
            PlatformResolver::class,
            'CANDIDATE_PHP_MINORS',
        );
        $this->assertTrue($reflection->isPublic());
    }

    public static function platformKeyProvider(): array
    {
        return [
            'php'                  => ['php',                  true],
            'php-64bit'            => ['php-64bit',            true],
            'composer-plugin-api'  => ['composer-plugin-api',  true],
            'composer-runtime-api' => ['composer-runtime-api', true],
            'composer bare'        => ['composer',             true],
            'ext-curl'             => ['ext-curl',             true],
            'ext-something-long'   => ['ext-some-long-name',   true],
            'lib-curl'             => ['lib-curl',             true],
            'horde package'        => ['horde/exception',      false],
            'vendor package'       => ['psr/log',              false],
            'extension-like name'  => ['extension-stuff',      false],
        ];
    }

    #[DataProvider('platformKeyProvider')]
    public function testIsPlatformKey(string $name, bool $expected): void
    {
        $this->assertSame($expected, PlatformResolver::isPlatformKey($name));
    }

    public function testExtractFromLockCollectsAcrossPackages(): void
    {
        $lock = json_encode([
            'packages' => [
                [
                    'name' => 'horde/example',
                    'require' => [
                        'php' => '^8.2',
                        'ext-curl' => '*',
                        'ext-json' => '*',
                        'horde/util' => '^3',
                    ],
                ],
                [
                    'name' => 'horde/util',
                    'require' => [
                        'php' => '^8.2',
                        'ext-mbstring' => '*',
                        'composer-plugin-api' => '^2.0',
                    ],
                ],
            ],
            'packages-dev' => [],
        ]);

        $entries = $this->resolver->extractFromLock($lock);

        // Order is deterministic: php-family, then composer-*, then ext-* alpha.
        $this->assertSame([
            ['php',                 '^8.2'],
            ['composer-plugin-api', '^2.0'],
            ['ext-curl',            '*'],
            ['ext-json',            '*'],
            ['ext-mbstring',        '*'],
        ], $entries);
    }

    public function testExtractFromLockDeduplicatesByName(): void
    {
        // Two packages requiring the same extension with differing
        // constraints. The resolver keeps the first occurrence.
        $lock = json_encode([
            'packages' => [
                ['name' => 'a', 'require' => ['ext-curl' => '*']],
                ['name' => 'b', 'require' => ['ext-curl' => '>=1.0']],
            ],
        ]);

        $entries = $this->resolver->extractFromLock($lock);

        $this->assertSame([['ext-curl', '*']], $entries);
    }

    public function testExtractFromLockIgnoresNonPlatformRequires(): void
    {
        $lock = json_encode([
            'packages' => [
                [
                    'name' => 'a',
                    'require' => [
                        'horde/foo'   => '^3',
                        'psr/log'     => '^1.0',
                        'symfony/yaml' => '^6.0',
                    ],
                ],
            ],
        ]);

        $this->assertSame([], $this->resolver->extractFromLock($lock));
    }

    public function testExtractFromLockHandlesEmptyAndMalformedJson(): void
    {
        $this->assertSame([], $this->resolver->extractFromLock(''));
        $this->assertSame([], $this->resolver->extractFromLock('not json'));
        $this->assertSame([], $this->resolver->extractFromLock('{"unexpected": "shape"}'));
    }

    public function testExtractFromLockTrueLeafPackagePhpOnly(): void
    {
        // A leaf package that declares only a PHP constraint — no
        // extensions, no composer meta, no transitive deps. The
        // resolver must emit exactly the php entry and nothing else;
        // this guards against accidental "no extensions => empty list"
        // shortcuts in the lock walker.
        $lock = json_encode([
            'packages' => [
                [
                    'name' => 'horde/leaf-only',
                    'require' => [
                        'php' => '^8.2',
                    ],
                ],
            ],
            'packages-dev' => [],
        ]);

        $this->assertSame(
            [['php', '^8.2']],
            $this->resolver->extractFromLock($lock),
        );
    }

    public function testExtractFromLockLeafWithComposerMetaButNoExtensions(): void
    {
        // Slightly less leaf-y: only php + composer-plugin-api. Confirms
        // composer-* meta entries make it through and order is stable
        // even without any ext-* in the mix.
        $lock = json_encode([
            'packages' => [
                [
                    'name' => 'horde/no-extensions',
                    'require' => [
                        'php' => '^8.2',
                        'composer-runtime-api' => '^2.0',
                    ],
                ],
            ],
        ]);

        $this->assertSame(
            [
                ['php', '^8.2'],
                ['composer-runtime-api', '^2.0'],
            ],
            $this->resolver->extractFromLock($lock),
        );
    }
}
