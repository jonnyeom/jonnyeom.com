<?php

declare(strict_types=1);

namespace App\Service\Strava;

use App\Exception\Strava\AccessTokenMissing;
use App\Exception\Strava\InvalidStat;
use App\Model\Strava\WeeklyStat;
use DateTime;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Strava\API\Exception;
use Symfony\Component\HttpFoundation\RequestStack;

use function array_filter;
use function array_merge;
use function array_values;
use function assert;
use function count;
use function date;
use function min;
use function strtotime;

class StravaDataProvider
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ClientProvider $clientProvider,
    ) {
    }

    /**
     * @return array<int, WeeklyStat>
     *
     * @throws AccessTokenMissing
     * @throws Exception
     * @throws IdentityProviderException|InvalidStat
     */
    public function getDataByWeek(string|null $sportType = null): array
    {
        $accessToken = $this->getApiToken();

        $apiClient = $this->clientProvider->getAPIClient($accessToken->getToken());

        $dataBeginning = (string) strtotime('30 October 2023');

        $page        = 1;
        $activities  = [];
        $response    = $apiClient->getAthleteActivities(null, $dataBeginning, $page);
        $activities += $response;
        while (count($response) === 30) {
            $page++;
            $response   = $apiClient->getAthleteActivities(null, $dataBeginning, $page);
            $activities = array_merge($activities, $response);
        }

        if ($sportType) {
            $activities = array_values(array_filter($activities, static function ($activity) {
                return $activity['sport_type'] === 'Run';
            }));
        }

        $weekOfActivity = new DateTime($activities[0]['start_date_local']);
        $today          = new DateTime();
        $weeklyStats    = [];

        do {
            $firstDayOfWeek = date('Y-m-d', strtotime('monday this week', $weekOfActivity->getTimestamp()));

            $weeklyStats[$weekOfActivity->format('Y.W')] = new WeeklyStat($firstDayOfWeek);
            $weekOfActivity                              = $weekOfActivity->modify('+ 1 week');
        } while (
            $weekOfActivity->format('Y') < $today->format('Y') ||
            ($weekOfActivity->format('Y') === $today->format('Y') &&
                $weekOfActivity->format('W') <= $today->format('W'))
        );

        foreach ($activities as $item) {
            $date = new DateTime($item['start_date_local']);
            $weeklyStats[$date->format('Y.W')]->addStravaActivity($item);
        }

        $this->calculateWeeklyMetrics($weeklyStats);

        return $weeklyStats;
    }

    /** @param WeeklyStat[] $weeklyStats */
    private function calculateWeeklyMetrics(array $weeklyStats): void
    {
        $dekeyedWeeklyStats = array_values($weeklyStats);
        foreach ($dekeyedWeeklyStats as $index => $weeklyStat) {
            $weeksAvailable = min($index, 4) + 1;

            $fiveWeekTotal = 0;
            for ($week = 0; $week < $weeksAvailable; $week++) {
                $fiveWeekTotal += $dekeyedWeeklyStats[$index - $week]->getTotalDistance() / $weeksAvailable;
            }

            $weeklyStat->setFiveWeekAverage($fiveWeekTotal);
        }
    }

    /**
     * @throws AccessTokenMissing
     * @throws IdentityProviderException
     */
    private function getApiToken(): AccessTokenInterface
    {
        $request = $this->requestStack->getCurrentRequest();
        assert($request !== null);

        // Load the access token from the session, and refresh if required
        $accessToken = $request->getSession()->get('access_token');
        if (! $accessToken) {
            throw new AccessTokenMissing();
        }

        assert($accessToken instanceof AccessTokenInterface);

        if ($accessToken->hasExpired()) {
            $accessToken = $this->clientProvider->refreshAccessToken($accessToken->getRefreshToken());

            // Update the stored access token for next time
            $request->getSession()->set('access_token', $accessToken);
        }

        return $accessToken;
    }
}
