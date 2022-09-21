<?php
/**
 * ConfigInterface with setter
 *
 * PHP Version 8
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components;

interface WritableConfigInterface extends ConfigInterface
{
    public function set(string $id, string $value);
}
