<?php

/**
 * Test Stub Presenter for capturing output messages
 *
 * PHP version 8
 *
 * @category   Horde
 * @package    Components
 * @subpackage UnitTests
 * @author     Ralf Lang <ralf.lang@ralf-lang.de>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Test\Stub;

use Horde\Components\Output\Presenter;

/**
 * Test stub presenter that captures all output messages
 * for assertion in unit tests.
 *
 * @category   Horde
 * @package    Components
 * @subpackage UnitTests
 * @author     Ralf Lang <ralf.lang@ralf-lang.de>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class PresenterStub implements Presenter
{
    /**
     * Captured messages
     */
    public array $messages = [];

    public function ok(string $message): void
    {
        $this->messages[] = $message;
    }

    public function warn(string $message): void
    {
        $this->messages[] = $message;
    }

    public function info(string $message): void
    {
        $this->messages[] = $message;
    }

    public function error(string $message): void
    {
        $this->messages[] = $message;
    }

    public function bold(string $text): void
    {
        $this->messages[] = $text;
    }

    public function blue(string $text): void
    {
        $this->messages[] = $text;
    }

    public function green(string $text): void
    {
        $this->messages[] = $text;
    }

    public function yellow(string $text): void
    {
        $this->messages[] = $text;
    }

    public function plain(string $text): void
    {
        $this->messages[] = $text;
    }

    public function pear(string $text): void
    {
        $this->messages[] = $text;
    }

    public function semantic(string $category, string $message): void
    {
        $this->messages[] = $message;
    }
}
