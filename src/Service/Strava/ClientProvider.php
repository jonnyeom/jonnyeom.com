<?php

declare(strict_types=1);

namespace App\Service\Strava;

use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Strava\API\Client;
use Strava\API\Factory;
use Strava\API\OAuth;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function array_merge;

class ClientProvider
{
    private Factory $factory;
    private OAuth|null $oauthClient = null;
    private Client|null $apiClient  = null;

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly UrlGeneratorInterface $router,
    ) {
        $this->factory = new Factory();
    }

    public function getOauthClient(): OAuth
    {
        if (! $this->oauthClient) {
            $this->oauthClient = $this->factory->getOAuthClient(
                $this->clientId,
                $this->clientSecret,
                $this->router->generate('strava_connect_check', [], UrlGeneratorInterface::ABSOLUTE_URL),
            );
        }

        return $this->oauthClient;
    }

    public function getAPIClient(string $accessToken): Client
    {
        if (! $this->apiClient) {
            $this->apiClient = $this->factory->getAPIClient($accessToken);
        }

        return $this->apiClient;
    }

    /** @throws IdentityProviderException */
    public function getAccessToken(string $code): AccessTokenInterface
    {
        return $this->getOauthClient()->getAccessToken(
            'authorization_code',
            ['code' => $code],
        );
    }

    /** @throws IdentityProviderException */
    public function refreshAccessToken(string|null $refreshToken): AccessTokenInterface
    {
        return $this->getOauthClient()->getAccessToken(
            'refresh_token',
            array_merge(['refresh_token' => $refreshToken]),
        );
    }
}
