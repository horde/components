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

    /**
     * The installer-plugin flag fires for packages declared with
     * type horde-library. Any one such package in the resolved tree
     * is enough; the maintainer's component needs the plugin opted
     * in via config.allow-plugins or composer 2.2+ silently disables
     * it.
     */
    public function testExtractFlagsFromLockTrueForHordeLibraryType(): void
    {
        $lock = json_encode([
            'packages' => [
                [
                    'name' => 'horde/exception',
                    'type' => 'horde-library',
                    'require' => ['php' => '^8.2'],
                ],
            ],
        ]);

        $this->assertSame(
            ['needs_installer_plugin' => true],
            $this->resolver->extractFlagsFromLock((string) $lock),
        );
    }

    public function testExtractFlagsFromLockTrueForHordeApplicationType(): void
    {
        $lock = json_encode([
            'packages' => [
                [
                    'name' => 'horde/mnemo',
                    'type' => 'horde-application',
                    'require' => ['php' => '^8.2'],
                ],
            ],
        ]);

        $this->assertTrue(
            $this->resolver->extractFlagsFromLock((string) $lock)['needs_installer_plugin']
        );
    }

    public function testExtractFlagsFromLockTrueForDirectInstallerPluginRequire(): void
    {
        // A package that requires horde/horde-installer-plugin directly
        // (even with a non-horde type) still triggers the flag - the
        // plugin needs to run for that package to be installed
        // correctly.
        $lock = json_encode([
            'packages' => [
                [
                    'name' => 'someone/who-uses-it',
                    'type' => 'library',
                    'require' => [
                        'php' => '^8.2',
                        'horde/horde-installer-plugin' => '^2.0',
                    ],
                ],
            ],
        ]);

        $this->assertTrue(
            $this->resolver->extractFlagsFromLock((string) $lock)['needs_installer_plugin']
        );
    }

    public function testExtractFlagsFromLockFalseForPlainLibraryTree(): void
    {
        // No horde-library, no horde-application, no plugin require:
        // the flag stays false. Components that happen to be standalone
        // PHP libraries should not have the plugin opted in
        // unnecessarily.
        $lock = json_encode([
            'packages' => [
                [
                    'name' => 'psr/log',
                    'type' => 'library',
                    'require' => ['php' => '^8.0'],
                ],
            ],
        ]);

        $this->assertSame(
            ['needs_installer_plugin' => false],
            $this->resolver->extractFlagsFromLock((string) $lock),
        );
    }

    public function testExtractFlagsFromLockWalksPackagesDev(): void
    {
        // A horde-library in packages-dev (rare but possible if a
        // transitive plugin pulls dev things in) still triggers the
        // flag - the resolver's defensive walk over packages-dev is
        // exercised here.
        $lock = json_encode([
            'packages' => [
                ['name' => 'psr/log', 'type' => 'library', 'require' => []],
            ],
            'packages-dev' => [
                [
                    'name' => 'horde/test',
                    'type' => 'horde-library',
                    'require' => [],
                ],
            ],
        ]);

        $this->assertTrue(
            $this->resolver->extractFlagsFromLock((string) $lock)['needs_installer_plugin']
        );
    }

    public function testExtractFlagsFromLockHandlesMalformedJson(): void
    {
        $this->assertSame(
            ['needs_installer_plugin' => false],
            $this->resolver->extractFlagsFromLock('not even close to JSON'),
        );
        $this->assertSame(
            ['needs_installer_plugin' => false],
            $this->resolver->extractFlagsFromLock(''),
        );
    }

    // -----------------------------------------------------------------
    // rewritePhpEntryForLane
    //
    // The `php` entry in a ci-platform lane comes verbatim from a
    // transitive `require` in composer.lock and is typically broader
    // than the lane it lands under (a legacy dep declaring
    // `^7.4 || ^8` is the common case). The rewriter narrows the
    // entry to `^<lane>` when the lane sits inside the allowed range;
    // it leaves the entry alone when the lane is unreachable (a
    // transitive floor sits above the lane) or when the constraint
    // cannot be parsed.
    // -----------------------------------------------------------------

    public function testRewritePhpEntryForLaneNarrowsBroadOrConstraint(): void
    {
        // Classic case: a legacy horde/* dep contributes `^7.4 || ^8`.
        // Every lane satisfies the constraint, so every lane should
        // narrow to `^<lane>`.
        $list = [
            ['php' => '^7.4 || ^8'],
            'ext-dom',
        ];

        $this->assertSame(
            [['php' => '^8.3'], 'ext-dom'],
            PlatformResolver::rewritePhpEntryForLane($list, '8.3'),
        );
    }

    public function testRewritePhpEntryForLaneNarrowsCaretConstraint(): void
    {
        // Lane above the constraint's floor: narrow to `^<lane>`.
        $list = [['php' => '^8.1'], 'ext-mbstring'];

        $this->assertSame(
            [['php' => '^8.5'], 'ext-mbstring'],
            PlatformResolver::rewritePhpEntryForLane($list, '8.5'),
        );
    }

    public function testRewritePhpEntryForLaneLeavesUnreachableLaneAlone(): void
    {
        // Constraint floor `8.5` above lane `8.3`. The lane cannot
        // install the component; leaving the anomaly in the block
        // surfaces the mismatch to the maintainer.
        $list = [['php' => '^8.5'], 'ext-json'];

        $this->assertSame(
            [['php' => '^8.5'], 'ext-json'],
            PlatformResolver::rewritePhpEntryForLane($list, '8.3'),
        );
    }

    public function testRewritePhpEntryForLaneIsIdempotentOnExactLaneFloor(): void
    {
        // `^8.3` on lane `8.3` still satisfies the constraint. The
        // rewrite is idempotent - the output matches the input.
        $list = [['php' => '^8.3'], 'ext-curl'];

        $this->assertSame(
            [['php' => '^8.3'], 'ext-curl'],
            PlatformResolver::rewritePhpEntryForLane($list, '8.3'),
        );
    }

    public function testRewritePhpEntryForLaneWithoutPhpEntryIsANoop(): void
    {
        // A lane with only ext-* entries (no php require made it
        // through the lock walk). Nothing to rewrite.
        $list = ['ext-mbstring', 'ext-json'];

        $this->assertSame(
            ['ext-mbstring', 'ext-json'],
            PlatformResolver::rewritePhpEntryForLane($list, '8.3'),
        );
    }

    public function testRewritePhpEntryForLaneLeavesEmptyConstraintAlone(): void
    {
        // Defensive: a php entry with an empty string constraint (the
        // `*` fallback path in the shaping loop uses `*`, not empty;
        // this guards against future writers that might produce '').
        $list = [['php' => '']];

        $this->assertSame(
            [['php' => '']],
            PlatformResolver::rewritePhpEntryForLane($list, '8.3'),
        );
    }

    public function testRewritePhpEntryForLaneLeavesUnparseableConstraintAlone(): void
    {
        // Defensive: composer.lock should never carry an unparseable
        // constraint, but if it did, keep the raw value rather than
        // silently swap in `^<lane>`.
        $list = [['php' => 'this is not a constraint']];

        $this->assertSame(
            [['php' => 'this is not a constraint']],
            PlatformResolver::rewritePhpEntryForLane($list, '8.3'),
        );
    }

    // -----------------------------------------------------------------
    // shapeFromResolvedRange
    //
    // Extracted from resolveAndShapeForCiPlatform so the shaping loop
    // is testable without spawning composer. Exercises the interaction
    // between the platform-list writer, the flags writer, and the
    // per-lane php rewrite.
    // -----------------------------------------------------------------

    public function testShapeFromResolvedRangeRewritesPhpPerLane(): void
    {
        // Same lock string reused across lanes; the shaper narrows
        // the php entry per lane while ext-* / lib-* pass through
        // unchanged.
        $lock = (string) json_encode([
            'packages' => [
                ['name' => 'horde/test', 'type' => 'library', 'require' => []],
            ],
        ]);
        $resolved = [
            '8.2' => [
                'platform' => [
                    ['php', '^7.4 || ^8'],
                    ['ext-dom', '*'],
                ],
                'lock' => $lock,
            ],
            '8.3' => [
                'platform' => [
                    ['php', '^7.4 || ^8'],
                    ['ext-dom', '*'],
                ],
                'lock' => $lock,
            ],
        ];

        $shaped = $this->resolver->shapeFromResolvedRange($resolved);

        $this->assertSame(
            [
                '8.2' => [['php' => '^8.2'], 'ext-dom'],
                '8.3' => [['php' => '^8.3'], 'ext-dom'],
            ],
            $shaped['ci-platform'],
        );
        $this->assertSame(
            ['needs_installer_plugin' => false],
            $shaped['ci-platform-flags'],
        );
    }

    public function testShapeFromResolvedRangePassesThroughNotResolvable(): void
    {
        // A minor that composer could not resolve is preserved as the
        // sentinel string under its lane key. No rewrite applies.
        $resolved = [
            '8.2' => PlatformResolver::NOT_RESOLVABLE,
        ];

        $shaped = $this->resolver->shapeFromResolvedRange($resolved);

        $this->assertSame(
            ['8.2' => PlatformResolver::NOT_RESOLVABLE],
            $shaped['ci-platform'],
        );
        $this->assertSame(
            ['needs_installer_plugin' => false],
            $shaped['ci-platform-flags'],
        );
    }

    public function testShapeFromResolvedRangeSurfacesInstallerPluginFlag(): void
    {
        // A lane whose lock contains a horde-library type triggers the
        // needs_installer_plugin flag. The flag is a union across all
        // minors; a single positive lane is enough.
        $lockWithHordeLibrary = (string) json_encode([
            'packages' => [
                [
                    'name' => 'horde/util',
                    'type' => 'horde-library',
                    'require' => [],
                ],
            ],
        ]);
        $resolved = [
            '8.3' => [
                'platform' => [['php', '^8.1']],
                'lock' => $lockWithHordeLibrary,
            ],
        ];

        $shaped = $this->resolver->shapeFromResolvedRange($resolved);

        $this->assertTrue($shaped['ci-platform-flags']['needs_installer_plugin']);
        $this->assertSame(
            ['8.3' => [['php' => '^8.3']]],
            $shaped['ci-platform'],
        );
    }
}
