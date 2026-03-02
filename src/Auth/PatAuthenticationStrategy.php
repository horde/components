<?php

declare(strict_types=1);

namespace Horde\Components\Auth;

use Horde\GithubApiClient\GithubApiClient;
use Horde\GithubApiClient\GithubApiConfig;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;

/**
 * Personal Access Token authentication strategy
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
class PatAuthenticationStrategy implements AuthenticationStrategyInterface
{
    /**
     * @param string $accessToken Personal Access Token
     * @param ClientInterface $httpClient HTTP client for API calls
     * @param RequestFactoryInterface $requestFactory PSR-17 request factory
     * @param StreamFactoryInterface|null $streamFactory PSR-17 stream factory (optional)
     */
    public function __construct(
        private readonly string $accessToken,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly ?StreamFactoryInterface $streamFactory = null
    ) {
        if (trim($this->accessToken) === '') {
            throw new RuntimeException('Personal Access Token cannot be empty');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function authenticate(): GithubApiClient
    {
        $config = new GithubApiConfig(accessToken: $this->accessToken);

        return new GithubApiClient(
            $this->httpClient,
            $this->requestFactory,
            $config,
            $this->streamFactory
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getDescription(): string
    {
        return 'Personal Access Token';
    }
}
