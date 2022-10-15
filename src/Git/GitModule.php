<?php
declare(strict_types=1);
namespace Horde\Components\Git;

use Horde\Components\ModularCli\CliEvent;
use Horde\Components\ModularCli\Module;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

class GitModule implements Module 
{
    /**
     * Construct the GitModule
     * 
     * TODO: Inject a factory or proxy rather than the bare injector
     *
     * @param ContainerInterface $injector
     */
    public function __construct(ContainerInterface $injector)
    {
        $this->injector = $injector;
    }

    public function getListenersForEvent(object $event): iterable
    {
        if ($event instanceof CliEvent) {
            return [$this->injector->getInstance(GitCommand::class)];
        }
    }
}