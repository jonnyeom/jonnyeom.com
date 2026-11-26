<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Strava\WeeklyPlanManager;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class StravaController extends BaseController
{
    #[Route('/strava', name: 'strava_metrics')]
    public function stravaMetrics(): Response
    {
        // The stored stats are shown to everyone; only Refresh / Reset need the Strava connection.
        $this->setSeoTitle('jonnyeom | Weekly running metrics');
        $this->setSeoDescription('Running metrics based on weekly mileage sourced from Strava');
        $this->setSeoKeywords('strava,running,metrics,mileage');

        return $this->render('strava/metrics.html.twig', ['currentWeek' => $this->currentWeek()]);
    }

    #[Route('/strava/plan', name: 'strava_plan')]
    public function stravaPlan(): Response
    {
        $this->setSeoTitle('jonnyeom | Weekly mileage plan');
        $this->setSeoDescription('Planned weekly mileage for running, biking, swimming and tennis');
        $this->setSeoKeywords('strava,running,plan,mileage');

        return $this->render('strava/plan.html.twig', ['currentWeek' => $this->currentWeek()]);
    }

    /** The Monday of the current week, for the chart of both pages (the plan API's currentWeek). */
    private function currentWeek(): string
    {
        return WeeklyPlanManager::weekStartOf(new DateTimeImmutable())->format('Y-m-d');
    }
}
