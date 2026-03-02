<?php

declare(strict_types=1);

namespace Horde\Components\Dependencies;

use Horde\Components\Auth\AuthenticationFactory;
use Horde\Components\ConfigProvider\ConfigProvider;
use Horde\Injector\Injector;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Factory for creating AuthenticationFactory instances
 *
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class AuthenticationFactoryFactory
{
    public function __construct(private readonly Injector $injector) {}

    /**
     * Create AuthenticationFactory instance
     *
     * @return AuthenticationFactory
     */
    public function __invoke(): AuthenticationFactory
    {
        return new AuthenticationFactory(
            $this->injector->getInstance(ConfigProvider::class),
            $this->injector->getInstance(ClientInterface::class),
            $this->injector->getInstance(RequestFactoryInterface::class),
            $this->injector->getInstance(StreamFactoryInterface::class)
        );
    }
}
