<?php

declare(strict_types=1);

/**
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

namespace Horde\Components\Test\Unit\Release\Branches;

use Horde\Components\Release\Branches\EnsureCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests the pure opening-version computation for a fresh release branch.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(EnsureCommand::class)]
class OpeningVersionTest extends TestCase
{
    /**
     * @return array<string,array{0:string,1:string,2:string,3:?string}>
     */
    public static function cases(): array
    {
        return [
            'minor step, stable base' => ['FRAMEWORK_6_0', 'FRAMEWORK_6_1', '2.4.38', '2.5.0-alpha'],
            'major step' => ['FRAMEWORK_5_2', 'FRAMEWORK_6_0', '2.4.38', '3.0.0-alpha'],
            'minor step from a prerelease base' => ['FRAMEWORK_6_0', 'FRAMEWORK_6_1', '2.5.0-alpha', '2.6.0-alpha'],
            'major step crossing two generations' => ['FRAMEWORK_5_2', 'FRAMEWORK_7_0', '1.0.0', '2.0.0-alpha'],
            'backward is skipped' => ['FRAMEWORK_6_1', 'FRAMEWORK_6_0', '2.4.38', null],
            'same branch is skipped' => ['FRAMEWORK_6_1', 'FRAMEWORK_6_1', '2.4.38', null],
            'lower minor same major is skipped' => ['FRAMEWORK_6_2', 'FRAMEWORK_6_1', '2.4.38', null],
            'non-framework former branch is skipped' => ['main', 'FRAMEWORK_6_1', '2.4.38', null],
            'non-framework new branch is skipped' => ['FRAMEWORK_6_0', 'feature/x', '2.4.38', null],
        ];
    }

    #[DataProvider('cases')]
    public function testOpeningVersionFor(string $former, string $new, string $current, ?string $expected): void
    {
        $this->assertSame($expected, EnsureCommand::openingVersionFor($former, $new, $current));
    }
}
