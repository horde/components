<?php

declare(strict_types=1);

namespace Horde\Components\ModularCli;
use Psr\EventDispatcher\ListenerProviderInterface;
interface Module extends ListenerProviderInterface
{
    /**
     * Act as a ListenerProvider
     * 
     * @param object $event
     *   An event for which to return the relevant listeners.
     * @return iterable<callable>
     *   An iterable (array, iterator, or generator) of callables.  Each
     *   callable MUST be type-compatible with $event.
     */
    public function getListenersForEvent(object $event) : iterable;
}
