<?php

declare(strict_types=1);

namespace Horde\Components\ModularCli;
use \ReflectionClass;
/**
 * A default cli command type to inherit from
 * 
 * A command is an invokable PSR-14 EventListener.
 * A command SHOULD react to DispatchedArgv by trying to parse argv and deciding if it should act.
 * A command SHOULD call flagHandled() on a DispatchedArg event after it has handled it successfully.
 * A command MAY emit events that are handled by other listeners rather than implementing the reaction itself
 */
interface Command
{
    public function getName(): string;

    public function getContext(): string;

    /**
     * React to DispatchedArgv
     *
     * @param CliEvent $event
     * @return void
     */
    public function __invoke(CliEvent $event): void;
}