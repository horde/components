<?php
/**
 * Example class for Skeleton library.
 *
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Skeleton
 * @author   Some Person <some.person@example.com>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Skeleton;

/**
 * Example class for Skeleton library.
 *
 * @category Horde
 * @package  Skeleton
 * @author   Some Person <some.person@example.com>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Example
{
    /**
     * Get a greeting message.
     *
     * @return string Greeting message
     */
    public function greet(): string
    {
        return 'Hello from Skeleton!';
    }

    /**
     * Add two numbers.
     *
     * @param int $a First number
     * @param int $b Second number
     * @return int Sum of the numbers
     */
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }
}
