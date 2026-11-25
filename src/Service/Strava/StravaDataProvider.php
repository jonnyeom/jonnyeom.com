<?php

declare(strict_types=1);

namespace App\Service\Strava;

use App\Entity\StravaWeeklyStat;
use App\Exception\Strava\AccessTokenMissing;
use App\Model\Strava\WeeklyStat;
use App\Repository\StravaWeeklyStatRepository;
use DateTime;
use DateTimeImmutable;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessToken;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Throwable;

use function array_key_exists;
use function array_map;
use function array_merge;
use function array_reverse;
use function array_values;
use function assert;
use function count;
use function date;
use function min;
use function strcmp;
use function strtotime;
use function usort;

/**
 * Reads the weekly stats from the database, and refreshes them from Strava on demand.
 *
 * The database is the source of truth for the stats page. Strava is only called to:
 * - refresh the latest week(s): refresh() (Refresh button) and refreshLatestStats()
 *   (app:strava:refresh-stats command);
 * - reload the whole history: reset() (Reset button).
 */
class StravaDataProvider
{
    /** Strava's maximum page size for GET /athlete/activities. */
    private const int ACTIVITIES_PER_PAGE = 200;

    /** Previous weeks needed for the 5-week average. */
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
     * Returns the newest $limit stored weeks of the allowed athlete (newest first), read from the
     * database only. Never calls Strava, and needs no Strava connection: the stats page is public.
     *
     * @return array<int, WeeklyStat>
     */
    public function getStoredWeeklyStats(int $limit): array
    {
        $athleteId = $this->athleteGuard->getAllowedAthleteId();
        if ($athleteId === null) {
            return [];
        }

        return array_map(
            static fn (StravaWeeklyStat $stat): WeeklyStat => $stat->toWeeklyStat(),
            $this->weeklyStatRepository->findLatestForAthlete($athleteId, $limit),
        );
    }

    /**
     * Refresh button: refreshes the latest week(s) from Strava, for the allowed athlete's session.
     *
     * @return array<int, WeeklyStat> the refreshed weeks, newest first
     *
     * @throws AccessTokenMissing
     * @throws IdentityProviderException
     */
    public function refresh(): array
    {
        $athleteId = $this->getConnectedAthleteId();

        return $this->refreshLatestWeeks($athleteId, $this->getApiToken($athleteId));
    }

    /**
     * Reset button: deletes the stored weeks and reloads the whole history from Strava.
     *
     * @return array<int, WeeklyStat> all stored weeks, newest first
     *
     * @throws AccessTokenMissing
     * @throws IdentityProviderException
     */
    public function reset(): array
    {
        $athleteId = $this->getConnectedAthleteId();

        return $this->reloadAllWeeks($athleteId, $this->getApiToken($athleteId));
    }

    /**
     * Refreshes the latest week(s) without a browser session, for the app:strava:refresh-stats
     * command. Uses the token stored in the database for the allowed athlete (STRAVA_ATHLETE_ID).
     *
     * @return array<int, WeeklyStat> the refreshed weeks, newest first
     *
     * @throws AccessTokenMissing When no athlete is allowed or no token is stored (connect at /strava first).
     * @throws IdentityProviderException
     */
    public function refreshLatestStats(): array
    {
        $athleteId = $this->athleteGuard->getAllowedAthleteId();
        if ($athleteId === null) {
            throw new AccessTokenMissing();
        }

        return $this->refreshLatestWeeks($athleteId, $this->tokenManager->getValidToken($athleteId));
    }

    /**
     * @throws AccessTokenMissing
     * @throws IdentityProviderException
     */
    public function confirmConnection(): bool
    {
        $this->getApiToken($this->getConnectedAthleteId());

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
     * Refreshes every week from the latest stored week up to this week: usually just this week,
     * but also any week that ended since the last refresh (e.g. a Sunday run before a Monday
     * refresh). The 5-week averages use the stored weeks before them.
     *
     * Nothing stored yet: loads the whole history instead.
     *
     * @return array<int, WeeklyStat> the refreshed weeks, newest first
     *
     * @throws IdentityProviderException
     */
    private function refreshLatestWeeks(int $athleteId, AccessToken $accessToken): array
    {
        $latestStored = $this->weeklyStatRepository->findLatestForAthlete($athleteId, 1)[0] ?? null;
        if (! $latestStored instanceof StravaWeeklyStat) {
            return $this->reloadAllWeeks($athleteId, $accessToken);
        }

        $firstMonday = $latestStored->getWeekStart();

        // Strava's "after" is exclusive, so start one second before that Monday.
        $activities = $this->fetchActivities((string) ($this->toTimestamp($firstMonday) - 1), $accessToken);

        $history = array_map(
            static fn (StravaWeeklyStat $stat): WeeklyStat => $stat->toWeeklyStat(),
            array_reverse($this->weeklyStatRepository->findBeforeWeek($athleteId, $firstMonday, self::HISTORY_WEEKS)),
        );

        $weeklyStats = $this->calculateWeeklyStats($activities, $firstMonday, $history);

        // The database is what the page shows, so a failed save must surface here.
        $this->weeklyStatRecorder->record($athleteId, $weeklyStats);

        return $weeklyStats;
    }

    /**
     * Fetches all activities from Strava and replaces every stored week.
     *
     * @return array<int, WeeklyStat> all weeks, newest first
     *
     * @throws IdentityProviderException
     */
    private function reloadAllWeeks(int $athleteId, AccessToken $accessToken): array
    {
        $activities = $this->sortByStartDate($this->fetchActivities('0', $accessToken));

        $weeklyStats = $activities === []
            ? []
            : $this->calculateWeeklyStats($activities, $this->mondayOf((string) $activities[0]['start_date_local']), []);

        $this->weeklyStatRecorder->replaceAll($athleteId, $weeklyStats);

        return $weeklyStats;
    }

    /**
     * Groups the activities into every week from $firstMonday up to this week (weeks without
     * activities included) and calculates the metrics. $history holds the stored weeks right
     * before $firstMonday (oldest first) and is only used for the 5-week averages.
     *
     * @param array<int, mixed> $activities
     * @param WeeklyStat[]      $history
     *
     * @return array<int, WeeklyStat> the weeks from $firstMonday, newest first
     */
    private function calculateWeeklyStats(array $activities, DateTimeImmutable $firstMonday, array $history): array
    {
        $weeklyStats = [];
        $monday      = $firstMonday;
        $thisMonday  = $this->mondayOf('now');
        while ($monday <= $thisMonday) {
            $weeklyStats[$monday->format('o.W')] = new WeeklyStat($monday->format('Y-m-d'));
            $monday                              = $monday->modify('+1 week');
        }

        foreach ($activities as $item) {
            $weekKey = (new DateTime($item['start_date_local']))->format('o.W');
            if (! array_key_exists($weekKey, $weeklyStats)) {
                continue;
            }

            $weeklyStats[$weekKey]->addStravaActivity($item);
        }

        $weeklyStats = array_values($weeklyStats);

        static::calculateWeeklyMetrics(array_merge($history, $weeklyStats));

        return array_reverse($weeklyStats);
    }

    /**
     * @param array<int, mixed> $activities
     *
     * @return array<int, mixed>
     */
    private function sortByStartDate(array $activities): array
    {
        usort($activities, static fn (array $a, array $b): int => strcmp(
            (string) $a['start_date_local'],
            (string) $b['start_date_local'],
        ));

        return $activities;
    }

    private function mondayOf(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable(date('Y-m-d', (int) strtotime('monday this week', (int) strtotime($date))));
    }

    private function toTimestamp(DateTimeImmutable $date): int
    {
        // Week starts are stored as dates; interpret them in the default timezone, like the weeks.
        return (int) strtotime($date->format('Y-m-d'));
    }

    /**
     * Fetches all activities after the given Unix timestamp, following Strava's pagination.
     *
     * @return array<int, mixed>
     *
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
     * Checks the session belongs to the allowed, connected athlete, without calling Strava, and
     * returns that athlete's ID.
     *
     * @throws AccessTokenMissing
     */
    private function getConnectedAthleteId(): int
    {
        $session   = $this->getSession();
        $athleteId = $this->athleteGuard->getAllowedAthleteId();

        // Only the configured athlete (STRAVA_ATHLETE_ID) may use the integration.
        if ($athleteId === null || ! $this->athleteGuard->isConnected($session)) {
            $this->athleteGuard->disconnect($session);

            throw new AccessTokenMissing();
        }

        return $athleteId;
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
    private function getApiToken(int $athleteId): AccessToken
    {
        try {
            return $this->tokenManager->getValidToken($athleteId);
        } catch (Throwable $e) {
            // Connected, but no token is stored or Strava rejected it: disconnect so the connect
            // page offers a new login instead of redirecting back.
            if ($e instanceof AccessTokenMissing || $e instanceof IdentityProviderException) {
                $this->athleteGuard->disconnect($this->getSession());
            }

            throw $e;
        }
    }
}
