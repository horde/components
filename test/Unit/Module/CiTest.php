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

namespace Horde\Components\Test\Unit\Module;

use Horde\Components\Module\Ci;
use Horde\Components\Dependencies\Injector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test the CI module.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(Ci::class)]
class CiTest extends TestCase
{
    private Ci $ci;

    protected function setUp(): void
    {
        $injector = new Injector();
        $this->ci = new Ci($injector);
    }

    /**
     * Test that CI module defines multiple options.
     *
     * This test verifies that the CI module provides command-line options.
     * It specifically documents that Horde\Argv converts dashes to underscores
     * in option keys (e.g., --work-dir becomes work_dir in the $options array).
     *
     * This caused a bug where Module/Ci.php was checking for 'work-dir' instead
     * of 'work_dir', preventing the --work-dir option from working.
     */
    public function testCiModuleDefinesOptions(): void
    {
        $options = $this->ci->getOptionGroupOptions();

        // Verify we have options defined
        $this->assertGreaterThan(0, count($options), 'CI module should define options');

        // Verify options are Horde\Argv\Option instances
        foreach ($options as $option) {
            $this->assertInstanceOf(\Horde\Argv\Option::class, $option);
        }
    }
}
