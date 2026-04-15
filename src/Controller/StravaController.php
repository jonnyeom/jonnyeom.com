<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class StravaController extends BaseController
{
    #[Route('/strava', name: 'strava_metrics')]
    public function stravaMetrics(Request $request): Response
    {
        if (! $request->getSession()->get('access_token')) {
            return $this->redirectToRoute('strava_connect_entry');
        }

        $this->setSeoTitle('jonnyeom | Weekly running metrics');
        $this->setSeoDescription('Running metrics based on weekly mileage sourced from Strava');
        $this->setSeoKeywords('strava,running,metrics,mileage');

        return $this->render('strava/metrics.html.twig');
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
