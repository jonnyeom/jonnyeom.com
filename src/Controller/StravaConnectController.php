<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Strava\AthleteGuard;
use App\Service\Strava\ClientProvider;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

class StravaConnectController extends AbstractController
{
    public function __construct(
        private readonly ClientProvider $clientProvider,
        private readonly AthleteGuard $athleteGuard,
    ) {
    }

    #[Route('/strava/connect/entry', name: 'strava_connect_entry')]
    public function connectEntry(Request $request): Response
    {
        // If we already have an access token, no need to get another one.
        if ($request->getSession()->get('access_token')) {
            return $this->redirectToRoute('strava_metrics');
        }

        return $this->render('strava/connect.html.twig', ['error' => 'No Access token :(']);
    }

    #[Route('/strava/connect', name: 'strava_connect_start')]
    public function connectAction(Request $request): Response
    {
        // If we already have an access token, no need to get another one.
        if ($request->getSession()->get('access_token')) {
            return $this->redirectToRoute('strava_metrics');
        }

        $oauth = $this->clientProvider->getOAuthClient();

        return new RedirectResponse($oauth->getAuthorizationUrl([
            'scope' => ['read', 'activity:read'],
        ]));
    }

    #[Route('/strava/connect/check', name: 'strava_connect_check')]
    public function connectCheckAction(Request $request, LoggerInterface $logger): Response
    {
        // ** if you want to *authenticate* the user, then
        // leave this method blank and create a Guard authenticator
        // (read below)

        // @Todo: Add state?

        // If we already have an access token, no need to get another one.
        if ($request->getSession()->get('access_token')) {
            return $this->redirectToRoute('strava_metrics');
        }

        if (! $code = $request->query->get('code')) {
            return $this->redirectToRoute('strava_metrics');
        }

        try {
            $accessToken = $this->clientProvider->getAccessToken($code);
        } catch (Throwable $e) {
            // something went wrong!
            // probably you should return the reason to the user.
            $logger->error($e->getMessage());

            $this->addFlash(
                'error',
                'Strava access error: ' . $e->getMessage(),
            );

            return $this->redirectToRoute('strava_logout');
        }

        // Only the site owner's Strava account (STRAVA_ATHLETE_ID) may connect.
        $athleteId = AthleteGuard::resolveAthleteId($accessToken);
        if (! $this->athleteGuard->isAllowed($athleteId)) {
            $logger->warning('Rejected Strava connection from non-allowed athlete.', [
                'athlete_id' => $athleteId,
                'allowed_athlete_configured' => $this->athleteGuard->isConfigured(),
            ]);

            $this->addFlash('error', 'This Strava account is not allowed to connect to this site.');

            return $this->redirectToRoute('strava_connect_entry');
        }

        // Store the verified AccessToken and athlete.
        $request->getSession()->set('access_token', $accessToken);
        $request->getSession()->set(AthleteGuard::SESSION_KEY, $athleteId);

        return $this->redirectToRoute('strava_metrics');
    }

    #[Route('/strava/logout', name: 'strava_logout')]
    public function logoutAction(Request $request): Response
    {
        $request->getSession()->remove('access_token');
        $request->getSession()->remove(AthleteGuard::SESSION_KEY);

        return $this->redirectToRoute('strava_metrics');
    }
}
