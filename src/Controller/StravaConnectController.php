<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Strava\ClientProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class StravaConnectController extends AbstractController
{
    public function __construct(private readonly ClientProvider $clientProvider)
    {
    }

    #[Route('/strava/connect', name: 'strava_connect_start')]
    public function connectAction(): Response
    {
        $oauth = $this->clientProvider->getOAuthClient();

        return new RedirectResponse($oauth->getAuthorizationUrl([
            'scope' => ['read_all', 'profile:read_all', 'activity:read_all'],
        ]));
    }

    #[Route('/strava/connect/check', name: 'strava_connect_check')]
    public function connectCheckAction(Request $request, LoggerInterface $logger): Response
    {
        // ** if you want to *authenticate* the user, then
        // leave this method blank and create a Guard authenticator
        // (read below)

        // @Todo: Add state?

        if (! $code = $request->get('code')) {
            return $this->redirectToRoute('strava_home');
        }

        try {
            $accessToken = $this->clientProvider->getAccessToken($code);

            // Fetch and store the AccessToken.
            $request->getSession()->set('access_token', $accessToken);
        } catch (IdentityProviderException $e) {
            // something went wrong!
            // probably you should return the reason to the user.
            $logger->error('Strava access error: ' . $e->getMessage());

            return $this->render('strava/home.html.twig', [
                'error' => $e->getMessage(),
            ]);
        }

        return $this->redirectToRoute('strava_home');
    }

    #[Route('/strava/logout', name: 'strava_connect_end')]
    public function logoutAction(Request $request): Response
    {
        $request->getSession()->set('access_token', null);

        return $this->redirectToRoute('strava_home');
    }
}
