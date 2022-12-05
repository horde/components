<?php

declare(strict_types=1);

namespace Horde\Components\Middleware;

use Exception;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Reject middleware
 *
 * Purpose: Deny Access to rest of stack if no auth header is set
 *
 * The full expected auth string is pulled from the config file.
 * This check will always fail if no auth string is configured.
 *
 * Reads Attributes:
 * - CI_AUTHENTICATION_PASSED
 *
 */
class NeedAuthRejectMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getAttribute('CI_AUTHENTICATION_PASSED')) {
            return $handler->handle($request);
        }

        // TODO: Different responses according to request content type
        $body = $this->streamFactory->createStream('<html><head><title>401</title></head><body><h1>401</h1>Unauthorized: No credentials or wrong credentials</body></html>');
        return $this->responseFactory->createResponse(401)->withBody($body);    
    }
}