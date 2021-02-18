<?php
/**
 * Copyright 2017 Horde LLC (http://www.horde.org/)
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
 * Wrapper for the changelog.yml file.
 *
 * @category   Horde
 * @package    Components
 * @author     Jan Schneider <jan@horde.org>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class ChangelogYml extends \ArrayObject implements Wrapper
{
    use WrapperTrait;

    /**
     * Constructor.
     *
     * @param string $docDir  Directory with changelog.yml.
     */
    public function __construct($docDir)
    {
        $this->_file = $docDir . '/changelog.yml';
        if ($this->exists()) {
            try {
                $changelog = \Horde_Yaml::loadFile($this->_file);
            } catch (\Horde_Yaml_Exception $e) {
                throw new Exception($e);
            }
        } else {
            $changelog = array();
        }
        parent::__construct($changelog);
    }

    /**
     * Returns the file contents.
     */
    public function __toString()
    {
        return \Horde_Yaml::dump(
            iterator_to_array($this),
            array('wordwrap' => 0)
        );
    }
}
