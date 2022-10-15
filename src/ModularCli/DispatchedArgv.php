<?php

declare(strict_types=1);

namespace Horde\Components\ModularCli;

/**
 * The CLI script's command line sent as an event
 * 
 * Event that represents Argv has been dispatched
 */
class DispatchedArgv implements CliEvent
{
    private array $argv;
    private bool $handled = false;
    public function __construct(array $argv)
    {
        $this->argv = $argv;
    }

    public function getArgv(): array
    {
        return $this->argv;
    }

    public static function fromGlobalArgv(): self
    {
        global $argv;
        return new self($argv);
    }

    /**
     * Has this command been handled?
     * 
     * A handler SHOULD call this if he acted on this.
     * This allows final handlers to react on a command never handled,
     * i.e. displaying the help 
     *
     * @return bool
     */
    public function handled(): bool
    {
        return $this->handled;
    }

    public function flagHandled(): void
    {
        $this->handled = true;
    }
}