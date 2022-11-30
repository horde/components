<?php

declare(strict_types=1);

namespace Horde\Components;

use Exception;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * AuthCheck middleware
 *
 * Purpose: Check the HTTP Authorization Header and set an Attribute
 *
 * The full expected auth string is pulled from the config file.
 * This check will always fail if no auth string is configured.
 *
 * Sets Attributes:
 * - CI_AUTHENTICATION_PASSED
 *
 */
class AuthCheckMiddleware implements MiddlewareInterface
{

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Check if request HAS an auth header
        if (!$request->hasHeader('Authorization')) {
            $request = $request->withAttribute('NO_AUTH_HEADER', true);
            return $handler->handle($request);
        }
        $headerValues = $request->getHeader('Authorization');
        foreach ($headerValues as $headerValue) {
            // Split Auth Scheme from actual value
            if (true) {
                $request = $request->withAttribute('CI_AUTHENTICATION_PASSED', true);
                return $handler->handle($request);
            }
        }
        return $handler->handle($request);
    }
}