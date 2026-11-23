<?php

declare(strict_types=1);

namespace App\Service\Strava;

use App\Entity\StravaWeeklyStat;
use App\Exception\Strava\AccessTokenMissing;
use App\Model\Strava\WeeklyStat;
use App\Repository\StravaWeeklyStatRepository;
use DateTime;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessToken;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

use function array_key_exists;
use function array_map;
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

/**
 * Reads the weekly stats from the database, and refreshes them from Strava on demand.
 *
 * The database is the source of truth for the stats page: Strava is only called by refresh()
 * (the Refresh button) and refreshStoredStats() (the app:strava:refresh-stats command).
 */
class StravaDataProvider
{
    /** Strava's maximum page size for GET /athlete/activities. */
    private const int ACTIVITIES_PER_PAGE = 200;

    /** Extra weeks fetched so the oldest displayed week has a full 5-week average. */
    private const int HISTORY_WEEKS = 4;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ClientProvider $clientProvider,
        private readonly AthleteGuard $athleteGuard,
        private readonly WeeklyStatRecorder $weeklyStatRecorder,
        private readonly StravaWeeklyStatRepository $weeklyStatRepository,
        private readonly StravaTokenManager $tokenManager,
    ) {
    }

    /**
     * Returns the newest $limit stored weeks (newest first), read from the database only.
     * Never calls Strava; use refresh() to fetch fresh data.
     *
     * @return array<int, WeeklyStat>
     *
     * @throws AccessTokenMissing
     */
    public function getStoredWeeklyStats(int $limit): array
    {
        $this->assertConnectedAthlete();

        return array_map(
            static fn (StravaWeeklyStat $stat): WeeklyStat => $stat->toWeeklyStat(),
            $this->weeklyStatRepository->findLatestForAthlete($this->athleteGuard->getAllowedAthleteId(), $limit),
        );
    }

    /**
     * Refreshes the stats from the stats page: only for the allowed athlete's browser session.
     *
     * @return array<int, WeeklyStat> the stored weeks, newest first
     *
     * @throws AccessTokenMissing
     * @throws IdentityProviderException
     */
    public function refresh(int $limit): array
    {
        return $this->fetchAndStoreWeeklyStats($this->getApiToken(), $limit);
    }

    /**
     * Refreshes the stats without a browser session, for the app:strava:refresh-stats command.
     * Uses the token stored in the database for the allowed athlete (STRAVA_ATHLETE_ID).
     *
     * @return array<int, WeeklyStat> the stored weeks, newest first
     *
     * @throws AccessTokenMissing When no token is stored (connect at /strava first).
     * @throws IdentityProviderException
     */
    public function refreshStoredStats(int $limit): array
    {
        $accessToken = $this->tokenManager->getValidToken($this->athleteGuard->getAllowedAthleteId());

        return $this->fetchAndStoreWeeklyStats($accessToken, $limit);
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
     * Fetches the newest $limit weeks (plus HISTORY_WEEKS for the 5-week average) from Strava,
     * recalculates the stats and stores them in the database. The only place that calls Strava.
     *
     * @return array<int, WeeklyStat> the stored weeks, newest first
     */
    private function fetchAndStoreWeeklyStats(AccessToken $accessToken, int $limit): array
    {
        $mondayThisWeek = (int) strtotime('monday this week', (new DateTime())->getTimestamp());
        $oldestMonday   = (int) strtotime(sprintf('-%d weeks', $limit + self::HISTORY_WEEKS - 1), $mondayThisWeek);

        // Strava's "after" is exclusive, so start one second before the oldest Monday.
        $activities = $this->fetchActivities((string) ($oldestMonday - 1), $accessToken);

        $weeklyStats = $this->calculateWeeklyStats($activities, $limit);

        // The database is what the page shows, so a failed save must surface here.
        $this->weeklyStatRecorder->record($this->athleteGuard->getAllowedAthleteId(), $weeklyStats);

        return $weeklyStats;
    }

    /**
     * Groups the activities into weeks and calculates the metrics.
     * Returns the newest $limit weeks, newest first.
     *
     * @param array<int, mixed> $activities
     *
     * @return array<int, WeeklyStat>
     */
    private function calculateWeeklyStats(array $activities, int $limit): array
    {
        if ($activities === []) {
            return [];
        }

        usort($activities, static fn (array $a, array $b): int => strcmp(
            (string) $a['start_date_local'],
            (string) $b['start_date_local'],
        ));

        $allWeekKeys = $this->buildAllWeekKeys($activities);
        $totalWeeks  = count($allWeekKeys);

        // Extra weeks of history so the oldest displayed week has a full 5-week average.
        $needed   = min($totalWeeks, $limit + self::HISTORY_WEEKS);
        $startIdx = $totalWeeks - $needed;

        $selectedKeys = array_slice($allWeekKeys, $startIdx, null, true);

        $weeklyStats = [];
        foreach ($selectedKeys as $weekKey => $firstDayOfWeek) {
            $weeklyStats[$weekKey] = new WeeklyStat($firstDayOfWeek);
        }

        foreach ($activities as $item) {
            $date    = new DateTime($item['start_date_local']);
            $weekKey = $date->format('o.W');
            if (! array_key_exists($weekKey, $weeklyStats)) {
                continue;
            }

            $weeklyStats[$weekKey]->addStravaActivity($item);
        }

        static::calculateWeeklyMetrics($weeklyStats);

        // Reverse to newest-first, then take the requested slice.
        return array_slice(array_reverse(array_values($weeklyStats)), 0, $limit);
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
     * Fetches all activities after the given Unix timestamp, following Strava's pagination.
     *
     * @return array<int, mixed>
     *
     * @throws AccessTokenMissing
     * @throws IdentityProviderException
     */
    private function fetchActivities(string $after, AccessToken $accessToken): array
    {
        $apiClient = $this->clientProvider->getAPIClient($accessToken->getToken());

        $page       = 1;
        $activities = [];
        do {
            $response   = $apiClient->getAthleteActivities(null, $after, $page, self::ACTIVITIES_PER_PAGE);
            $activities = array_merge($activities, $response);
            $page++;
        } while (count($response) === self::ACTIVITIES_PER_PAGE);

        return $activities;
    }

    /**
     * Checks the session belongs to the allowed, connected athlete, without calling Strava.
     *
     * @throws AccessTokenMissing
     */
    private function assertConnectedAthlete(): void
    {
        $session = $this->getSession();

        // Only the configured athlete (STRAVA_ATHLETE_ID) may use the integration.
        if (! $this->athleteGuard->isConnected($session)) {
            $this->athleteGuard->disconnect($session);

            throw new AccessTokenMissing();
        }
    }

    private function getSession(): SessionInterface
    {
        $request = $this->requestStack->getCurrentRequest();
        assert($request instanceof Request);

        return $request->getSession();
    }

    /**
     * Returns a valid Strava token for the connected athlete.
     *
     * The token only lives in the database (also used by app:strava:refresh-stats): Strava
     * rotates refresh tokens, so a single copy is kept.
     *
     * @throws AccessTokenMissing
     * @throws IdentityProviderException
     */
    private function getApiToken(): AccessToken
    {
        $this->assertConnectedAthlete();

        try {
            return $this->tokenManager->getValidToken($this->athleteGuard->getAllowedAthleteId());
        } catch (AccessTokenMissing $e) {
            // Connected, but no token is stored: disconnect so the connect page offers a new login.
            $this->athleteGuard->disconnect($this->getSession());

            throw $e;
        }
    }
}
