<?php
/**
 * Horde\Components\Component\Task\SystemCallResult:: Holds Output, Return code etc
 *
 * PHP version 7
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <lang@b1-systems.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */


namespace Horde\Components\Component\Task;
/**
 * Components\Component\Task\SystemCallResult:: Holds Output, Return code etc
 *
 * Copyright 2011-2019 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <lang@b1-systems.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class SystemCallResult
{
    protected $_fields = [];

    public function __construct(array $stdout, $retval)
    {
        $this->_fields['stdout'] = $stdout;
        $this->_fields['retval'] = $retval;        
    }

    public function getReturnValue()
    {
        return $this->_fields['retval'];
    }

    /**
     * Return multiline command output as single string
     * 
     * @return string Command output as a multiline string
     */
    public function getOutputString()
    {
        return implode("\n", $this->_fields['stdout']);
    }

    /**
     * Return multiline command output as array of strings
     * 
     * @return string[] Command output as an array per line
     */
    public function getOutputArray()
    {
        return $this->_fields['stdout'];
    }

    /**
     * Return the output string when used as string
     * 
     * @return string The string value
     */
    public function __toString()
    {
        return $this->getOutputString();
    }
}