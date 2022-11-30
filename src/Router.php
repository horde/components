<?php
namespace Horde\Components;
use Horde_Routes_Mapper;
use Psr\Http\Message\ServerRequestInterface;

class Router
{
    private Horde_Routes_Mapper $mapper;
    public function __construct(Horde_Routes_Mapper $mapper)
    {
        $mapper->connect(
            '/ci/phpunit/coverage/:vendor/:package/:branch',
            [
                'stack' => [
                    AuthCheckMiddleware::class,
                    NeedAuthRejectMiddleware::class,
                    Quality\UnitTestCoverageUpload::class
                ]
            ]
        );

        $mapper->connect(
            '/help',
            [
                'stack' => [
                    AuthCheckMiddleware::class,
                    NeedAuthRejectMiddleware::class,
                    Quality\UnitTestCoverageUpload::class
                ]
            ]
        );
        $this->mapper = $mapper;
    }

    public function matchRoute(ServerRequestInterface $request)
    {
        $this->mapper->environ = ['REQUEST_METHOD' => $request->getMethod()];
        $path = $request->getUri()->getPath();
        $path = strtok($path, '?');
        $match = $this->mapper->match($path);
        $request = $request->withAttribute('route', $match);
        return $request;
    }

}