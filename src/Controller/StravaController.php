<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Strava\ClientProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

use function assert;

class StravaController extends AbstractController
{
    public function __construct(private readonly ClientProvider $clientProvider)
    {
    }

    #[Route('/strava', name: 'strava_home')]
    public function stravaData(Request $request): Response
    {
        // Load the access token from the session, and refresh if required
        $accessToken = $request->getSession()->get('access_token');
        if (! $accessToken) {
            return $this->render('strava/home.html.twig');
        }

        assert($accessToken instanceof AccessTokenInterface);

        try {
            if ($accessToken->hasExpired()) {
                $accessToken = $this->clientProvider->refreshAccessToken($accessToken->getRefreshToken());

                // Update the stored access token for next time
                $request->getSession()->set('access_token', $accessToken);
            }
        } catch (IdentityProviderException $e) {
            return $this->render('strava/home.html.twig', [
                'error' => $e->getMessage(),
            ]);
        }

        $apiClient = $this->clientProvider->getAPIClient($accessToken->getToken());
        $athlete   = $apiClient->getAthlete();

        return $this->render('strava/data.html.twig', ['data' => $athlete]);
    }
}
