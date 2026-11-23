<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Strava\AthleteGuard;
use App\Service\Strava\ClientProvider;
use App\Service\Strava\StravaTokenManager;
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
        private readonly StravaTokenManager $tokenManager,
    ) {
    }

    #[Route('/strava/connect/entry', name: 'strava_connect_entry')]
    public function connectEntry(Request $request): Response
    {
        // Already connected as the allowed athlete: no need to connect again.
        if ($this->athleteGuard->isConnected($request->getSession())) {
            return $this->redirectToRoute('strava_metrics');
        }

        return $this->render('strava/connect.html.twig', ['error' => 'No Access token :(']);
    }

    #[Route('/strava/connect', name: 'strava_connect_start')]
    public function connectAction(Request $request): Response
    {
        // Already connected as the allowed athlete: no need to connect again.
        if ($this->athleteGuard->isConnected($request->getSession())) {
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

        // Already connected as the allowed athlete: no need to connect again.
        if ($this->athleteGuard->isConnected($request->getSession())) {
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
            $logger->warning('Rejected Strava connection from non-allowed athlete.', ['athlete_id' => $athleteId]);

            $this->addFlash('error', 'This Strava account is not allowed to connect to this site.');

            return $this->redirectToRoute('strava_connect_entry');
        }

        // The token is only stored in the database (shared with app:strava:refresh-token);
        // the session just remembers that the allowed athlete is connected.
        $this->tokenManager->store($this->athleteGuard->getAllowedAthleteId(), $accessToken);
        $this->athleteGuard->connect($request->getSession());

        return $this->redirectToRoute('strava_metrics');
    }

    #[Route('/strava/logout', name: 'strava_logout')]
    public function logoutAction(Request $request): Response
    {
        $this->athleteGuard->disconnect($request->getSession());

        return $this->redirectToRoute('strava_metrics');
    }
}
