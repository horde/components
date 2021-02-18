<?php
/**
 * Test instrumented version of the Horde Cli
 *
 * PHP version 7
 *
 * @category   Horde
 * @package    Components
 * @subpackage UnitTests
 * @author     Gunnar Wrobel <wrobel@pardus.de>
 * @author     Ralf Lang <lang@b1-systems.de>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components\Stub;
use Horde\Components\Exception;

class OutputCli extends \Horde_Cli
{
    public $messages = array();

    public function message($message, $type = 'cli.message')
    {
        $this->messages[] = $message;
    }

    public function fatal($text)
    {
        throw new Exception($text);
    }
}
