<?php

declare(strict_types=1);

namespace Horde\Components\Middleware;

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
class AuthCheck implements MiddlewareInterface
{

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Check if request HAS an auth header
        if (!$request->hasHeader('Authorization')) {
            $request = $request->withAttribute('NO_AUTH_HEADER', true);
            return $handler->handle($request);
        }
        $config = $request->getAttribute('Horde\Components\ComposedConfig');
        if (empty($config)) {
            throw new Exception('Cannot run AuthCheckMiddleware without a Horde\Components\ComposedConfig attribute');
        }

        return $handler->handle($this->checkAuthentication($config, $request));
    }

    public function checkAuthentication($config, ServerRequestInterface $request): ServerRequestInterface
    {
        // Move this part to a subroutine
        $configuredScheme = ucfirst(strtolower($config->get('api_auth_schema')));
        $configuredValue = $config->get('api_auth_key');
        $headerValues = $request->getHeader('Authorization');
        foreach ($headerValues as $headerValue) {
            // Split Auth Scheme from actual value
            [$receivedScheme, $receivedValue] = explode(' ', $headerValue);
            if (($configuredScheme === $receivedScheme) && ($configuredValue === $receivedValue)) {
                $request = $request->withAttribute('CI_AUTHENTICATION_PASSED', true);
                $request = $request->withAttribute('CI_AUTHENTICATION_SCHEMA', $receivedScheme);
                return $request;
            }
        }
        return $request;
    }
}