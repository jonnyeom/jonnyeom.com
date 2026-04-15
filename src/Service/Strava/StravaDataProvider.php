<?php

declare(strict_types=1);

namespace App\Service\Strava;

use App\Exception\Strava\AccessTokenMissing;
use App\Model\Strava\WeeklyStat;
use DateTime;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

use function array_key_exists;
use function array_merge;
use function array_reverse;
use function array_slice;
use function array_values;
use function assert;
use function ceil;
use function count;
use function date;
use function max;
use function min;
use function sprintf;
use function strcmp;
use function strtotime;
use function usort;

class StravaDataProvider
{
    private const int CURRENT_WEEK_TTL = 43200;   // 12 hours

    private const int PAST_WEEK_TTL = 2592000;    // 30 days

    /** Number of weeks per cached chunk. Matches LOAD_MORE_SIZE in the Live Component. */
    private const int CHUNK_SIZE = 10;

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
     * Activities are fetched in 10-week chunks and cached individually, so
     * each "Load More" click only hits the Strava API for the one new chunk.
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

        // Chunk 0 = current week. Past chunks cover CHUNK_SIZE weeks each.
        $pastChunksNeeded = (int) ceil(max(0, $neededWeeks - 1) / self::CHUNK_SIZE);

        $activities = $this->getActivitiesForChunk(0);

        for ($i = 1; $i <= $pastChunksNeeded; $i++) {
            $activities = array_merge($activities, $this->getActivitiesForChunk($i));
        }

        usort($activities, static fn (array $a, array $b): int => strcmp(
            $a['start_date_local'],
            $b['start_date_local'],
        ));

        $this->activitiesCache[$neededWeeks] = $activities;

        return $activities;
    }

    /**
     * Fetches (or returns from Symfony cache) activities for a single 10-week chunk.
     *
     * Chunk 0  : current week only (TTL 12 h — changes as new activities are recorded)
     * Chunk N≥1: weeks [(N-1)*10+1 … N*10] before the current Monday (TTL 30 d)
     *
     * @return array<int, mixed>
     *
     * @throws AccessTokenMissing
     * @throws IdentityProviderException
     */
    private function getActivitiesForChunk(int $chunkIndex): array
    {
        $today          = new DateTime();
        $currentWeekKey = $today->format('o.W');
        $mondayThisWeek = (int) strtotime('monday this week', $today->getTimestamp());

        if ($chunkIndex === 0) {
            return $this->cache->get(
                'strava_current_' . $currentWeekKey,
                function (ItemInterface $item) use ($mondayThisWeek): array {
                    $item->expiresAfter(self::CURRENT_WEEK_TTL);

                    return $this->fetchActivities(null, (string) $mondayThisWeek);
                },
            );
        }

        // Upper bound (exclusive): start of chunk = Monday N*10 weeks ago
        // Lower bound (exclusive): end of chunk   = Monday (N-1)*10 weeks ago
        $chunkEnd   = (int) strtotime(sprintf('-%d weeks', ($chunkIndex - 1) * self::CHUNK_SIZE), $mondayThisWeek);
        $chunkStart = (int) strtotime(sprintf('-%d weeks', $chunkIndex * self::CHUNK_SIZE), $mondayThisWeek);

        return $this->cache->get(
            'strava_chunk_' . $chunkIndex . '_' . $currentWeekKey,
            function (ItemInterface $item) use ($chunkEnd, $chunkStart): array {
                $item->expiresAfter(self::PAST_WEEK_TTL);

                return $this->fetchActivities((string) $chunkEnd, (string) $chunkStart);
            },
        );
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
