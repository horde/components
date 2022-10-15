<?php
declare(strict_types=1);
namespace Horde\Components\Git;

use Horde\Components\ModularCli\BaseCommand;
use Horde\Components\ModularCli\CliEvent;
use Horde\Components\ModularCli\DispatchedArgv;
use Psr\EventDispatcher\EventDispatcherInterface;

class GitCommand extends BaseCommand
{

    public function __construct(EventDispatcherInterface $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    public function getName(): string
    {
        return 'git clone';
    }
    public function __invoke(CliEvent $event): void
    {
        if ($event instanceof DispatchedArgv) {
            print_r($event->getArgv());
        }
        //$this->dispatcher->dispatch($event);
    }
}