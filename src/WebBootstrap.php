<?php

namespace Horde\Components;

use Horde\Core\Middleware\AppFinder;
use Horde\Core\Middleware\AppRouter;
use Horde\Http\RequestFactory;
use Horde\Http\UriFactory;
use Horde\Http\ResponseFactory;
use Horde\Http\Server\RampageRequestHandler;
use Horde\Http\Server\RequestBuilder;
use Horde\Http\Server\ResponseWriterWeb;
use Horde\Http\Server\Runner;
use Horde\Http\Server\Middleware\Responder;
use Horde\Http\StreamFactory;
use Horde\Injector\Injector;
use Horde\Injector\TopLevel;

/**
 * Bootstrap the Components HTTP endpoint
 *
 *
 */
class WebBootstrap
{
    public static function run()
    {
        $requestFactory = new RequestFactory();
        $streamFactory = new StreamFactory();
        $uriFactory = new UriFactory();
        $responseFactory = new ResponseFactory();

        // Build the request from server variables.
        // The RequestBuilder could easily be autowired by a DI container.
        $requestBuilder = new RequestBuilder($requestFactory, $streamFactory, $uriFactory);
        $request = $requestBuilder->withGlobalVariables()->build();
        $injector = Kernel::buildInjector();
        $middlewares = [];

        $handler = new RampageRequestHandler($responseFactory, $streamFactory, $middlewares);
        $runner = new Runner($handler, new ResponseWriterWeb());
        $runner->run($request);
    }
}