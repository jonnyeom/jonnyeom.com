<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\Strava\AccessTokenMissing;
use App\Service\Strava\StravaDataProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

use function array_reverse;

class StravaController extends AbstractController
{
    public function __construct(private readonly StravaDataProvider $stravaDataProvider)
    {
    }

    #[Route('/strava', name: 'strava_home')]
    public function stravaHome(): Response
    {
        try {
            $runsByWeek = $this->stravaDataProvider->getDataByWeek('Run');
        } catch (AccessTokenMissing) {
            return $this->render('strava/home.html.twig');
        } catch (IdentityProviderException $e) {
            return $this->render('strava/home.html.twig', [
                'error' => $e->getMessage(),
            ]);
        }

        return $this->render('strava/data.html.twig', ['activitiesByWeek' => array_reverse($runsByWeek, true)]);
    }
}
