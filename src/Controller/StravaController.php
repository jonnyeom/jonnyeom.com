<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\Strava\AccessTokenMissing;
use App\Service\Strava\StravaDataProvider;
use Exception;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

use function array_reverse;

class StravaController extends BaseController
{
    #[Route('/strava', name: 'strava_home')]
    public function stravaHome(StravaDataProvider $stravaDataProvider, LoggerInterface $logger): Response
    {
        $this->setSeoTitle('jonnyeom | Weekly running metrics');
        $this->setSeoDescription('Running metrics based on weekly mileage sourced from Strava');
        $this->setSeoKeywords('strava,running,metrics,mileage');

        try {
            $runsByWeek = $stravaDataProvider->getDataByWeek();
        } catch (AccessTokenMissing|IdentityProviderException|Exception $e) {
            $logger->error($e->getMessage());

            return $this->render('strava/home.html.twig', [
                'error' => $e->getMessage(),
            ]);
        }

        return $this->render('strava/data.html.twig', ['activitiesByWeek' => array_reverse($runsByWeek, true)]);
    }
}
