<?php

/**
 * Test Stub for the Output interface
 *
 * PHP version 8
 *
 * @category   Horde
 * @package    Components
 * @subpackage UnitTests
 * @author     Gunnar Wrobel <wrobel@pardus.de>
 * @author     Ralf Lang <ralf.lang@ralf-lang.de>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Test\Stub;

use Horde\Components\Config;
use Horde\Components\Exception;
use Horde\Components\Output as ComponentsOutput;

class Output extends ComponentsOutput
{
    /**
     * Captured messages
     */
    private array $messages = [];

    /**
     * Constructor.
     *
     * @param array $options Configuration options
     */
    public function __construct($options = [])
    {
        // Create a stub CLI handler
        $cli = new OutputCli();

        // Call parent constructor
        parent::__construct($cli, $options);
    }

    /**
     * Get captured output messages
     *
     * @return array Array of captured messages
     */
    public function getOutput(): array
    {
        return $this->messages;
    }

    // Override all output methods to capture messages

    public function ok($text): void
    {
        $this->messages[] = $text;
    }

    public function warn($text): void
    {
        $this->messages[] = $text;
    }

    public function info($text): void
    {
        $this->messages[] = $text;
    }

    public function error($text): void
    {
        $this->messages[] = $text;
    }

    public function bold($text): void
    {
        $this->messages[] = $text;
    }

    public function blue($text): void
    {
        $this->messages[] = $text;
    }

    public function green($text): void
    {
        $this->messages[] = $text;
    }

    public function yellow($text): void
    {
        $this->messages[] = $text;
    }

    public function plain($text): void
    {
        $this->messages[] = $text;
    }

    public function pear($text): void
    {
        $this->messages[] = $text;
    }
}


