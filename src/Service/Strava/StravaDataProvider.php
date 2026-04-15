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
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

use function array_key_exists;
use function array_merge;
use function array_reverse;
use function array_slice;
use function array_values;
use function assert;
use function count;
use function date;
use function min;
use function strcmp;
use function strtotime;
use function usort;

class StravaDataProvider
{
    private const int CURRENT_WEEK_TTL = 43200;   // 12 hours

    private const int PAST_WEEK_TTL = 2592000;    // 30 days

    /** @var array<int, mixed>|null Request-level cache so getWeeklyStatsPaged and getTotalWeekCount share one load. */
    private array|null $cachedActivities = null;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ClientProvider $clientProvider,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * Returns the newest $limit weeks (display order: newest first).
     * Only processes ($limit + 4) weeks, keeping 5-week averages accurate.
     *
     * @return array<int, WeeklyStat>
     *
     * @throws AccessTokenMissing
     * @throws IdentityProviderException
     */
    public function getWeeklyStatsPaged(int $limit): array
    {
        $allActivities = $this->getAllActivities();

        if ($allActivities === []) {
            return [];
        }

        $allWeekKeys = $this->buildAllWeekKeys($allActivities);
        $totalWeeks  = count($allWeekKeys);

        // Extra 4 weeks of history so the oldest displayed week has a full 5-week average.
        $needed   = min($totalWeeks, $limit + 4);
        $startIdx = $totalWeeks - $needed;

        $selectedKeys = array_slice($allWeekKeys, $startIdx, null, true);

        $weeklyStats = [];
        foreach ($selectedKeys as $weekKey => $firstDayOfWeek) {
            $weeklyStats[$weekKey] = new WeeklyStat($firstDayOfWeek);
        }

        foreach ($allActivities as $item) {
            $date    = new DateTime($item['start_date_local']);
            $weekKey = $date->format('o.W');
            if (! array_key_exists($weekKey, $weeklyStats)) {
                continue;
            }

            $weeklyStats[$weekKey]->addStravaActivity($item);
        }

        $this->calculateWeeklyMetrics($weeklyStats);

        // Reverse to newest-first, then take the requested slice.
        return array_slice(array_reverse(array_values($weeklyStats)), 0, $limit);
    }

    /**
     * Total number of weeks from the first activity to today.
     * Used by the Live Component to determine whether more pages exist.
     *
     * @throws AccessTokenMissing
     * @throws IdentityProviderException
     */
    public function getTotalWeekCount(): int
    {
        $allActivities = $this->getAllActivities();

        if ($allActivities === []) {
            return 0;
        }

        return count($this->buildAllWeekKeys($allActivities));
    }

    /**
     * @return array<int|string, WeeklyStat>
     *
     * @throws AccessTokenMissing
     * @throws Exception
     * @throws IdentityProviderException|InvalidStat
     */
    public function getDataByWeek(string|null $sportType = null): array
    {
        $allActivities = $this->getAllActivities();
        $weeklyStats   = $this->generateWeeklyStats($allActivities);
        $this->calculateWeeklyMetrics($weeklyStats);

        return $weeklyStats;
    }

    /**
     * @throws AccessTokenMissing
     * @throws IdentityProviderException
     */
    public function confirmConnection(): bool
    {
        $this->getApiToken();

        return true;
    }

    /**
     * @param array<int, mixed> $activities
     *
     * @return array<int|string, WeeklyStat>
     */
    public static function generateWeeklyStats(array $activities): array
    {
        $weekOfActivity = new DateTime($activities[0]['start_date_local']);
        $today          = new DateTime();
        $weeklyStats    = [];

        do {
            $firstDayOfWeek = date('Y-m-d', strtotime('monday this week', $weekOfActivity->getTimestamp()));

            $weeklyStats[$weekOfActivity->format('o.W')] = new WeeklyStat($firstDayOfWeek);
            $weekOfActivity                              = $weekOfActivity->modify('+ 1 week');
        } while (
            $weekOfActivity->format('o') < $today->format('o') ||
            ($weekOfActivity->format('o') === $today->format('o') &&
                $weekOfActivity->format('W') <= $today->format('W'))
        );

        foreach ($activities as $item) {
            $date = new DateTime($item['start_date_local']);
            $weeklyStats[$date->format('o.W')]->addStravaActivity($item);
        }

        return $weeklyStats;
    }

    /** @param WeeklyStat[] $weeklyStats */
    public static function calculateWeeklyMetrics(array $weeklyStats): void
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
     * All week keys in chronological order (oldest first), mapped to their Monday date.
     *
     * @param array<int, mixed> $activities
     *
     * @return array<array-key, string> weekKey => firstDayOfWeek
     */
    private function buildAllWeekKeys(array $activities): array
    {
        $weekOfActivity = new DateTime($activities[0]['start_date_local']);
        $today          = new DateTime();
        $weekKeys       = [];

        do {
            $weekKey        = $weekOfActivity->format('o.W');
            $firstDayOfWeek = date('Y-m-d', strtotime('monday this week', $weekOfActivity->getTimestamp()));

            $weekKeys[$weekKey] = $firstDayOfWeek;
            $weekOfActivity     = $weekOfActivity->modify('+ 1 week');
        } while (
            $weekOfActivity->format('o') < $today->format('o') ||
            ($weekOfActivity->format('o') === $today->format('o') &&
                $weekOfActivity->format('W') <= $today->format('W'))
        );

        return $weekKeys;
    }

    /**
     * Loads and sorts all activities from the Symfony cache (or Strava API on cache miss).
     * Cached at the request level so multiple callers within one request pay the cost once.
     *
     * @return array<int, mixed>
     *
     * @throws AccessTokenMissing
     * @throws IdentityProviderException
     */
    private function getAllActivities(): array
    {
        if ($this->cachedActivities !== null) {
            return $this->cachedActivities;
        }

        $today          = new DateTime();
        $currentWeekKey = $today->format('o.W');
        $mondayThisWeek = (string) strtotime('monday this week', $today->getTimestamp());
        $dataBeginning  = (string) strtotime('30 April 2025');

        $pastActivities = $this->cache->get(
            'strava_past_' . $currentWeekKey,
            function (ItemInterface $item) use ($mondayThisWeek, $dataBeginning): array {
                $item->expiresAfter(self::PAST_WEEK_TTL);

                return $this->fetchActivities($mondayThisWeek, $dataBeginning);
            },
        );

        $currentActivities = $this->cache->get(
            'strava_current_' . $currentWeekKey,
            function (ItemInterface $item) use ($mondayThisWeek): array {
                $item->expiresAfter(self::CURRENT_WEEK_TTL);

                return $this->fetchActivities(null, $mondayThisWeek);
            },
        );

        $allActivities = array_merge($pastActivities, $currentActivities);
        usort($allActivities, static fn (array $a, array $b): int => strcmp(
            $a['start_date_local'],
            $b['start_date_local'],
        ));

        $this->cachedActivities = $allActivities;

        return $this->cachedActivities;
    }

    /**
     * @return array<int, mixed>
     *
     * @throws AccessTokenMissing
     * @throws IdentityProviderException
     */
    private function fetchActivities(string|null $before, string $after): array
    {
        $accessToken = $this->getApiToken();
        $apiClient   = $this->clientProvider->getAPIClient($accessToken->getToken());

        $page        = 1;
        $activities  = [];
        $response    = $apiClient->getAthleteActivities($before, $after, $page);
        $activities += $response;
        while (count($response) === 30) {
            $page++;
            $response   = $apiClient->getAthleteActivities($before, $after, $page);
            $activities = array_merge($activities, $response);
        }

        return $activities;
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
