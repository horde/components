<?php
/**
 * Match an expression against a component.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Component;

/**
 * Match an expression against a component.
 *
 * Copyright 2011-2024 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Matcher
{
    /**
     * Does the component match the given selector?
     *
     * @param string $c_name    The component name.
     * @param string $c_channel The component channel.
     * @param string $selector  The selector.
     *
     * @return bool True if the component matches.
     */
    public static function matches($c_name, $c_channel, $selector): bool
    {
        $selectors = explode(',', $selector);
        if (in_array('ALL', $selectors)) {
            return true;
        }
        foreach ($selectors as $selector) {
            if (empty($selector)) {
                continue;
            }
            if (str_contains($selector, '/')) {
                [$channel, $name] = explode('/', $selector, 2);
                if ($c_channel == $channel && $c_name == $name) {
                    return true;
                }
                continue;
            }
            if (substr($selector, 0, 8) == 'channel:') {
                if ($c_channel == substr($selector, 8)) {
                    return true;
                }
                continue;
            }
            if ($c_name == $selector) {
                return true;
            }
        }
        return false;
    }
}
