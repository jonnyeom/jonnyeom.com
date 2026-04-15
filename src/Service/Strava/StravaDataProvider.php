<?php

declare(strict_types=1);

namespace App\Service\Strava;

use App\Exception\Strava\AccessTokenMissing;
use App\Model\Strava\WeeklyStat;
use DateTime;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessToken;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

use function array_filter;
use function array_key_exists;
use function array_merge;
use function array_reverse;
use function array_slice;
use function array_values;
use function assert;
use function count;
use function date;
use function min;
use function sprintf;
use function strcmp;
use function strtotime;
use function usort;

class StravaDataProvider
{
    private const int CURRENT_WEEK_TTL = 43200;   // 12 hours

    private const int PAST_WEEK_TTL = 2592000;    // 30 days

    /** Number of weeks fetched in a single Strava API call on a past-week cache miss. */
    private const int PREFETCH_WEEKS = 50;

    /**
     * Request-level activity cache keyed by neededWeeks.
     * Prevents redundant processing when multiple methods are called in the same request.
     *
     * @var array<int, array<int, mixed>>
     */
    private array $activitiesCache = [];

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ClientProvider $clientProvider,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * Returns the newest $limit weeks (display order: newest first).
     * Only fetches ($limit + 4) weeks from the Strava API cache, keeping 5-week averages accurate.
     *
     * @return array<int, WeeklyStat>
     *
     * @throws AccessTokenMissing
     * @throws IdentityProviderException
     */
    public function getWeeklyStatsPaged(int $limit): array
    {
        $allActivities = $this->getAllActivities($limit + 4);

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
     * Loads and sorts enough activities to cover $neededWeeks.
     * Activities are cached per individual ISO week, so historical data is never invalidated
     * when the current week changes. On a cache miss the Strava API is called once for a
     * bulk window of PREFETCH_WEEKS weeks, and every week in that window is pre-warmed.
     *
     * @return array<int, mixed>
     *
     * @throws AccessTokenMissing
     * @throws IdentityProviderException
     */
    private function getAllActivities(int $neededWeeks): array
    {
        if (isset($this->activitiesCache[$neededWeeks])) {
            return $this->activitiesCache[$neededWeeks];
        }

        $accessToken    = $this->getApiToken();
        $today          = new DateTime();
        $mondayThisWeek = (int) strtotime('monday this week', $today->getTimestamp());
        $currentWeekKey = $today->format('o.W');
        $athleteId      = (string) $accessToken->getResourceOwnerId();

        $neededWeekKeys = $this->buildNeededWeekKeys($neededWeeks, $mondayThisWeek);

        $activities = [];
        // Iterate newest-first so each cache miss pre-warms the older weeks we still need.
        foreach (array_reverse($neededWeekKeys, true) as $weekKey => $mondayTs) {
            $activities = array_merge(
                $activities,
                $this->getActivitiesForWeek($weekKey, $athleteId, $accessToken, $mondayTs, $currentWeekKey),
            );
        }

        usort($activities, static fn (array $a, array $b): int => strcmp(
            $a['start_date_local'],
            $b['start_date_local'],
        ));

        $this->activitiesCache[$neededWeeks] = $activities;

        return $activities;
    }

    /**
     * Fetches (or returns from Symfony cache) activities for a single ISO week.
     *
     * Cache keys encode the actual week directly, making entries easy to filter and find:
     *   strava_current_{athleteId}_{weekKey}  — current week (TTL 12 h, activities still being recorded)
     *   strava_week_{athleteId}_{weekKey}     — past week    (TTL 30 d, immutable once the week ends)
     *
     * On a cache miss for a past week, fetches PREFETCH_WEEKS weeks in one Strava API call
     * and pre-warms individual week caches so subsequent weeks are served from cache.
     *
     * @return array<int, mixed>
     *
     * @throws AccessTokenMissing
     * @throws IdentityProviderException
     */
    private function getActivitiesForWeek(
        string $weekKey,
        string $athleteId,
        AccessToken $accessToken,
        int $weekMonday,
        string $currentWeekKey,
    ): array {
        if ($weekKey === $currentWeekKey) {
            return $this->cache->get(
                'strava_current_' . $athleteId . '_' . $weekKey,
                function (ItemInterface $item) use ($weekMonday, $accessToken): array {
                    $item->expiresAfter(self::CURRENT_WEEK_TTL);

                    return $this->fetchActivities(null, (string) $weekMonday, $accessToken);
                },
            );
        }

        return $this->cache->get(
            'strava_week_' . $athleteId . '_' . $weekKey,
            function (ItemInterface $item) use ($weekMonday, $athleteId, $accessToken): array {
                $item->expiresAfter(self::PAST_WEEK_TTL);

                // Bulk-fetch this week plus PREFETCH_WEEKS - 1 older weeks in one API call.
                $bulkBefore = $weekMonday + 7 * 24 * 3600;
                $bulkAfter  = (int) strtotime(
                    sprintf('-%d weeks', self::PREFETCH_WEEKS - 1),
                    $weekMonday,
                );

                $allActivities = $this->fetchActivities((string) $bulkBefore, (string) $bulkAfter, $accessToken);

                // Pre-warm per-week caches for the older weeks in the bulk range.
                for ($i = 1; $i < self::PREFETCH_WEEKS; $i++) {
                    $prewarmMonday     = (int) strtotime(sprintf('-%d weeks', $i), $weekMonday);
                    $prewarmNext       = $prewarmMonday + 7 * 24 * 3600;
                    $prewarmWeekKey    = (new DateTime('@' . $prewarmMonday))->format('o.W');
                    $prewarmActivities = $this->filterActivitiesByWindow($allActivities, $prewarmMonday, $prewarmNext);

                    $this->cache->get(
                        'strava_week_' . $athleteId . '_' . $prewarmWeekKey,
                        static function (ItemInterface $prewarmItem) use ($prewarmActivities): array {
                            $prewarmItem->expiresAfter(self::PAST_WEEK_TTL);

                            return $prewarmActivities;
                        },
                    );
                }

                // Return this week's activities.
                return $this->filterActivitiesByWindow($allActivities, $weekMonday, $bulkBefore);
            },
        );
    }

    /**
     * Returns the last $neededWeeks ISO week keys (oldest first), mapped to their Monday timestamp.
     * Keys are ISO week strings in o.W format (e.g. "2026.15"), values are Monday Unix timestamps.
     *
     * @return array<array-key, int>
     */
    private function buildNeededWeekKeys(int $neededWeeks, int $mondayThisWeek): array
    {
        $weekKeys = [];
        for ($i = $neededWeeks - 1; $i >= 0; $i--) {
            $mondayTs           = (int) strtotime(sprintf('-%d weeks', $i), $mondayThisWeek);
            $weekKey            = (new DateTime('@' . $mondayTs))->format('o.W');
            $weekKeys[$weekKey] = $mondayTs;
        }

        return $weekKeys;
    }

    /**
     * Returns activities whose start_date_local falls strictly between $after and $before.
     *
     * @param array<int, mixed> $activities
     *
     * @return array<int, mixed>
     */
    private function filterActivitiesByWindow(array $activities, int $after, int $before): array
    {
        return array_values(array_filter(
            $activities,
            static function (array $activity) use ($after, $before): bool {
                $ts = (int) strtotime($activity['start_date_local']);

                return $ts > $after && $ts < $before;
            },
        ));
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
     * @return array<int, mixed>
     *
     * @throws AccessTokenMissing
     * @throws IdentityProviderException
     */
    private function fetchActivities(string|null $before, string $after, AccessToken $accessToken): array
    {
        $apiClient = $this->clientProvider->getAPIClient($accessToken->getToken());

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
    private function getApiToken(): AccessToken
    {
        $request = $this->requestStack->getCurrentRequest();
        assert($request !== null);

        // Load the access token from the session, and refresh if required
        $accessToken = $request->getSession()->get('access_token');
        if (! $accessToken) {
            throw new AccessTokenMissing();
        }

        assert($accessToken instanceof AccessToken);

        if ($accessToken->hasExpired()) {
            $accessToken = $this->clientProvider->refreshAccessToken($accessToken->getRefreshToken());
            assert($accessToken instanceof AccessToken);

            // Update the stored access token for next time
            $request->getSession()->set('access_token', $accessToken);
        }

        return $accessToken;
    }
}
