<?php

/**
 * Polyfill for mb_ucfirst() function (available in PHP 8.4+)
 *
 * Copyright 2013-2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category  Horde
 * @copyright 2013-2026 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Components
 */

namespace Horde\Components\Helper;

if (!function_exists('mb_ucfirst')) {
    /**
     * Make the first character of a string uppercase
     *
     * @param string $string The input string
     * @param string|null $encoding The character encoding. If null, uses internal encoding
     * @return string The string with the first character uppercased
     */
    function mb_ucfirst(string $string, ?string $encoding = null): string
    {
        $encoding = $encoding ?? mb_internal_encoding();

        if (empty($string)) {
            return $string;
        }

        $firstChar = mb_substr($string, 0, 1, $encoding);
        $rest = mb_substr($string, 1, null, $encoding);

        return mb_strtoupper($firstChar, $encoding) . $rest;
    }
}
