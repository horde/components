<?php
/**
 * ConfigInterface
 *
 * PHP Version 8
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components\Config;

class ArrayBackedPhpConfigFile implements ConfigInterface
{
    use ConfigTrait;

    public function __construct(string $filename, string $variable)
    {
        require $filename;
        if (isset($$variable) && is_array($$variable)) {
            $this->settings = $$variable;
        } else {
            // TODO: More specific, more useful exception
            throw new \Exception('Could not read file or file did not contain the expected array: ' . $$variable);
        }
    }
}
