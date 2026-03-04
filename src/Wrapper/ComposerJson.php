<?php

/**
 * Copyright 2017-2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category   Horde
 * @package    Components
 * @author     Jan Schneider <jan@horde.org>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Wrapper;

use Horde\Components\Exception;
use Horde\Components\Wrapper;
use Horde\Components\WrapperTrait;

/**
 * Wrapper for the composer.json file.
 *
 * @category   Horde
 * @package    Components
 * @author     Jan Schneider <jan@horde.org>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class ComposerJson extends \ArrayObject implements Wrapper, \Stringable
{
    use WrapperTrait;

    /**
     * Constructor.
     *
     * @param string $baseDir  Directory with composer.json.
     */
    public function __construct($baseDir)
    {
        $this->_file = $baseDir . '/composer.json';
        if ($this->exists()) {
            $content = file_get_contents($this->_file);
            if ($content === false) {
                throw new Exception("Failed to read {$this->_file}");
            }
            $horde = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Invalid JSON in {$this->_file}: " . json_last_error_msg());
            }
        } else {
            $horde = [];
        }
        parent::__construct($horde);
    }

    /**
     * Returns the file contents.
     */
    public function __toString(): string
    {
        return (string) json_encode(
            iterator_to_array($this),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
    }
}
