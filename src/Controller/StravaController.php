<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\Strava\AccessTokenMissing;
use App\Service\Strava\StravaDataProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Throwable;

use function array_reverse;
use function array_values;

class StravaController extends BaseController
{
    #[Route('/strava', name: 'strava_metrics')]
    public function stravaMetrics(StravaDataProvider $stravaDataProvider, LoggerInterface $logger): Response
    {
        $this->setSeoTitle('jonnyeom | Weekly running metrics');
        $this->setSeoDescription('Running metrics based on weekly mileage sourced from Strava');
        $this->setSeoKeywords('strava,running,metrics,mileage');

        try {
            $runsByWeek = $stravaDataProvider->getDataByWeek();
        } catch (AccessTokenMissing) {
            return $this->render('strava/connect.html.twig', ['error' => 'No Access token :(']);
        } catch (IdentityProviderException | Throwable $e) {
            // something went wrong!
            // probably you should return the reason to the user.
            $logger->error($e->getMessage());

            $this->addFlash(
                'error',
                'Strava access error: ' . $e->getMessage(),
            );

            return $this->redirectToRoute('strava_logout');
        }

        return $this->render('strava/metrics.html.twig', ['activitiesByWeek' => array_values(array_reverse($runsByWeek))]);
    }

    #[Route('/strava/plan', name: 'strava_plan')]
    public function stravaPlan(): Response
    {
        $this->setSeoTitle('jonnyeom | Weekly running metrics');
        $this->setSeoDescription('Running metrics based on weekly mileage sourced from Strava');
        $this->setSeoKeywords('strava,running,metrics,mileage');

        return $this->render('strava/plan.html.twig');
    }
}
