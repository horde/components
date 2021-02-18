<?php
/**
 * Test Stub for the Output interface
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

use Horde\Components\Output as ComponentsOutput;
use Horde\Components\Exception;
use Horde\Components\Config;

class Output extends ComponentsOutput
{
    /**
     * Constructor.
     *
     * @param \Horde_Cli         $cli    The CLI handler.
     * @param Config $config The configuration for the current job.
     */
    public function __construct($options = array())
    {
        $this->output = new OutputCli();

        parent::__construct(
            $this->output,
            $options
        );
    }

    public function getOutput()
    {
        return $this->output->messages;
    }
}
