<?php
namespace Horde\Components;
use Horde_Routes_Mapper;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Horde\Components\Middleware\NeedAuthReject;
use Horde\Components\Middleware\AuthCheck;
use Horde\Components\Middleware\UnitTestCoverageUpload;

class Router
{
    private Horde_Routes_Mapper $mapper;
    public function __construct(Horde_Routes_Mapper $mapper)
    {
        $mapper->connect(
            '/ci/phpunit/:vendor/:package/:branch/:php',
            [
                'stack' => [
                    AuthCheck::class,
                    NeedAuthReject::class,
                    UnitTestCoverageUpload::class
                ]
            ]
        );
        $mapper->connect(
            '/ci/phpdoc/:vendor/:package/:branch',
            [
                'stack' => [
                    AuthCheck::class,
                    NeedAuthReject::class,
                    PhpdocUpload::class
                ]
            ]
        );
        $mapper->connect(
            '/ci/help',
            [
                'stack' => [
                    AuthCheck::class,
                    NeedAuthReject::class,
                    UnitTestCoverageUpload::class
                ]
            ]
        );

        $mapper->connect(
            '/help',
            [
                'stack' => [
                    AuthCheck::class,
                    NeedAuthReject::class,
                    UnitTestCoverageUpload::class
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