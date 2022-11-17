
<?php

declare(strict_types=1);

namespace Horde\Components\Quality;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

class UnitTestCoverageUpload implements MiddlewareInterface
{
    public function __construct(
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        Injector $injector,
    ) {
        $this->responseFactory = $responseFactory;
        $this->streamFactory = $streamFactory;
        $this->injector = $injector;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {

        // Bisect URL for parameters

        // Extract uploaded file from FILES

        // Groom metadata

        // Happy Path, give some useful response
        $result = [];
        $stream = $this->streamFactory->createStream(json_encode($result)));
        return $this->responseFactory->createResponse(201)->withBody($stream);
    }
}