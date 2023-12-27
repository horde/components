<?php

declare(strict_types=1);

namespace Horde\Components\Cli;

use Horde\Injector\Injector;
use Psr\Container\ContainerInterface;
use Horde\Components\Module\Help;
use Horde\Components\Module\Git;
use Horde\Components\Module\Config as ConfigModule;
use Horde\Cli\Modular\ModuleProvider as CliModuleProvider;
use Horde\Cli\Modular\Module;
use Horde\Cli\Modular\Modules;
use Horde\Components\Module\Composer;
use Horde\Components\Module\Package;

/**
 * Components tool specific, context aware module provider
 *
 * Override the default module provider's naive approach of always loading all modules and injecting injector
 */
class ModuleProvider implements CliModuleProvider
{
    protected array $modules;

    public function __construct(private ContainerInterface $injector)
    {
        // Modules we will always load unconditionally
    }

    public function getModule(string $module): Module
    {
        if ($module == 'help') {
            $this->modules['help'] = $this->injector->get(Help::class);
        }
        return $this->modules[$module];
    }

    public function getModules(): Modules
    {
        return new Modules([
            $this->injector->get(ConfigModule::class),
            $this->injector->get(Composer::class),
            $this->injector->get(Git::class),
            $this->injector->get(Help::class),
            $this->injector->get(Package::class)
        ]);
    }
}
