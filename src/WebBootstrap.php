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
use Horde\Components\Config\ComposedConfigInterface;
use Horde\Components\Quality\UnitTestCoverageUpload;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Bootstrap the Components HTTP endpoint
 *
 *
 */
class WebBootstrap
{
    public static function run()
    {
        $injector = Kernel::buildInjector();
        $requestFactory = new RequestFactory();
        $streamFactory = new StreamFactory();
        $uriFactory = new UriFactory();
        $responseFactory = new ResponseFactory();
        $injector->setInstance(StreamFactoryInterface::class, $streamFactory);
        $injector->setInstance(ResponseFactoryInterface::class, $responseFactory);
        $config = $injector->get(ComposedConfigInterface::class);

        // Build the request from server variables.
        // The RequestBuilder could easily be autowired by a DI container.
        $requestBuilder = new RequestBuilder($requestFactory, $streamFactory, $uriFactory);
        $request = $requestBuilder->withGlobalVariables()->build();
        $request = $request->withAttribute('Horde\Components\ComposedConfig', $config);
//        print_r($request->getAttribute('Horde\Components\ComposedConfig')->get('api_auth_key'));

        $router = $injector->get(Router::class);
        $request = $router->matchRoute($request);
        $middlewareKeys = $request->getAttribute('route')['stack'] ?? [];
        $middlewares = [];

        foreach ($middlewareKeys as $middleware)
        {
            $middlewares[] = $injector->get($middleware);
        }

        $handler = new RampageRequestHandler($responseFactory, $streamFactory, $middlewares);
        $runner = new Runner($handler, new ResponseWriterWeb());
        $runner->run($request);
    }
}