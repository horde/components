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
 * @author     Ralf Lang <ralf.lang@ralf-lang.de>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Test\Stub;

use Horde\Components\Config;
use Horde\Components\Exception;
use Horde\Components\Output as ComponentsOutput;

class Output extends ComponentsOutput
{
    /**
     * Constructor.
     *
     * @param \Horde_Cli         $cli    The CLI handler.
     * @param Config $config The configuration for the current job.
     */
    public function __construct($options = [])
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
