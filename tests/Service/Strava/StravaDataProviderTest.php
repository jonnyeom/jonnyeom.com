<?php

declare(strict_types=1);

namespace App\Tests\Service\Strava;

use App\Entity\StravaWeeklyStat;
use App\Exception\Strava\AccessTokenMissing;
use App\Model\Strava\WeeklyStat;
use App\Repository\StravaWeeklyStatRepository;
use App\Service\Strava\AthleteGuard;
use App\Service\Strava\ClientProvider;
use App\Service\Strava\StravaDataProvider;
use App\Service\Strava\StravaTokenManager;
use App\Service\Strava\WeeklyStatRecorder;
use DateTimeImmutable;
use League\OAuth2\Client\Token\AccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Strava\API\Client;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

use function array_fill;
use function array_filter;
use function array_values;
use function date_default_timezone_get;
use function date_default_timezone_set;
use function gmdate;
use function strtotime;
use function time;

final class StravaDataProviderTest extends TestCase
{
    private const string ALLOWED_ATHLETE_ID = '12345';

    private const int WEEK = 7 * 86400;

    private Session $session;

    /** @var MockObject&ClientProvider */
    private MockObject $clientProvider;

    /** @var MockObject&Client */
    private MockObject $stravaClient;

    /** @var MockObject&WeeklyStatRecorder */
    private MockObject $recorder;

    /** @var MockObject&StravaWeeklyStatRepository */
    private MockObject $repository;

    /** @var MockObject&StravaTokenManager */
    private MockObject $tokenManager;

    private StravaDataProvider $provider;

    private string $originalTimezone;

    private int $mondayThisWeek;

    protected function setUp(): void
    {
        // Week boundaries are computed in the default timezone; pin it so the tests are deterministic.
        $this->originalTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        $this->mondayThisWeek = (int) strtotime('monday this week');

        $this->session = new Session(new MockArraySessionStorage());
        $request       = new Request();
        $request->setSession($this->session);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $this->stravaClient   = $this->createMock(Client::class);
        $this->clientProvider = $this->createMock(ClientProvider::class);
        $this->clientProvider->method('getAPIClient')->willReturn($this->stravaClient);

        $this->recorder     = $this->createMock(WeeklyStatRecorder::class);
        $this->repository   = $this->createMock(StravaWeeklyStatRepository::class);
        $this->tokenManager = $this->createMock(StravaTokenManager::class);

        $this->provider = new StravaDataProvider(
            $requestStack,
            $this->clientProvider,
            new AthleteGuard(self::ALLOWED_ATHLETE_ID),
            $this->recorder,
            $this->repository,
            $this->tokenManager,
        );
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTimezone);
    }

    public function testStoredStatsAreReadFromTheDatabaseWithoutCallingStrava(): void
    {
        $this->session->set(AthleteGuard::SESSION_KEY, self::ALLOWED_ATHLETE_ID);

        $stored = new StravaWeeklyStat(self::ALLOWED_ATHLETE_ID, new DateTimeImmutable('2026-11-16'));
        $stored->update(5000.0, 6500.0, ['Mon' => 5000.0], 1, [
            ['sport_type' => 'Run', 'distance' => 5000.0, 'start_date_local' => '2026-11-16T08:00:00Z'],
        ]);

        $this->repository
            ->expects($this->once())
            ->method('findLatestForAthlete')
            ->with(self::ALLOWED_ATHLETE_ID, 20)
            ->willReturn([$stored]);
        $this->clientProvider->expects($this->never())->method('getAPIClient');
        $this->tokenManager->expects($this->never())->method('getValidToken');
        $this->recorder->expects($this->never())->method('record');

        $stats = $this->provider->getStoredWeeklyStats(20);

        self::assertCount(1, $stats);
        self::assertSame('2026-11-16', $stats[0]->getFirstDayOfWeek()->format('Y-m-d'));
        self::assertSame(5000.0, $stats[0]->getTotalDistance());
        self::assertSame(6500.0, $stats[0]->getFiveWeekAverage());
        self::assertSame(1, $stats[0]->getActivityCount());
    }

    public function testStoredStatsThrowWhenNotConnected(): void
    {
        $this->repository->expects($this->never())->method('findLatestForAthlete');

        $this->expectException(AccessTokenMissing::class);

        $this->provider->getStoredWeeklyStats(20);
    }

    #[DataProvider('disallowedAthletes')]
    public function testStoredStatsRejectDisallowedAthleteAndClearSession(string|null $sessionAthleteId): void
    {
        $this->setSessionAthlete($sessionAthleteId);

        $this->repository->expects($this->never())->method('findLatestForAthlete');

        $this->assertThrowsAccessTokenMissing(fn () => $this->provider->getStoredWeeklyStats(20));

        self::assertFalse($this->session->has(AthleteGuard::SESSION_KEY));
        self::assertFalse($this->session->has(AthleteGuard::LEGACY_TOKEN_SESSION_KEY));
    }

    #[DataProvider('stravaActions')]
    public function testStravaActionsThrowWhenNotConnected(string $action): void
    {
        $this->clientProvider->expects($this->never())->method('getAPIClient');
        $this->recorder->expects($this->never())->method('record');
        $this->recorder->expects($this->never())->method('replaceAll');

        $this->expectException(AccessTokenMissing::class);

        $this->provider->{$action}();
    }

    #[DataProvider('stravaActionsForDisallowedAthletes')]
    public function testStravaActionsRejectDisallowedAthleteAndClearSession(string $action, string|null $sessionAthleteId): void
    {
        $this->setSessionAthlete($sessionAthleteId);

        $this->clientProvider->expects($this->never())->method('getAPIClient');
        $this->stravaClient->expects($this->never())->method('getAthleteActivities');
        $this->recorder->expects($this->never())->method('record');
        $this->recorder->expects($this->never())->method('replaceAll');

        $this->assertThrowsAccessTokenMissing(fn () => $this->provider->{$action}());

        self::assertFalse($this->session->has(AthleteGuard::SESSION_KEY));
        self::assertFalse($this->session->has(AthleteGuard::LEGACY_TOKEN_SESSION_KEY));
    }

    /** @return array<string, array{string|null}> */
    public static function disallowedAthletes(): array
    {
        return [
            'another athlete' => ['99999'],
            'legacy session with only a token' => [null],
        ];
    }

    /** @return array<string, array{string}> */
    public static function stravaActions(): array
    {
        return ['refresh' => ['refresh'], 'reset' => ['reset']];
    }

    /** @return array<string, array{string, string|null}> */
    public static function stravaActionsForDisallowedAthletes(): array
    {
        $cases = [];
        foreach (self::stravaActions() as $name => [$action]) {
            foreach (self::disallowedAthletes() as $case => [$athleteId]) {
                $cases[$name . ', ' . $case] = [$action, $athleteId];
            }
        }

        return $cases;
    }

    public function testRefreshOnlyFetchesFromTheLatestStoredWeek(): void
    {
        $this->connectAllowedAthlete();
        $lastMonday = $this->mondayThisWeek - self::WEEK;

        // Last week was stored before its Sunday run; this week has a new run.
        $this->repository
            ->method('findLatestForAthlete')
            ->with(self::ALLOWED_ATHLETE_ID, 1)
            ->willReturn([$this->storedWeek($lastMonday, 3000.0)]);
        $this->repository
            ->expects($this->once())
            ->method('findBeforeWeek')
            ->with(self::ALLOWED_ATHLETE_ID, $this->isInstanceOf(DateTimeImmutable::class), 4)
            ->willReturn([ // Newest first, like the repository.
                $this->storedWeek($lastMonday - self::WEEK, 10000.0),
                $this->storedWeek($lastMonday - 2 * self::WEEK, 10000.0),
                $this->storedWeek($lastMonday - 3 * self::WEEK, 10000.0),
                $this->storedWeek($lastMonday - 4 * self::WEEK, 10000.0),
            ]);

        $this->stravaClient
            ->expects($this->once())
            ->method('getAthleteActivities')
            ->with(null, (string) ($lastMonday - 1), 1, 200)
            ->willReturn([
                $this->activity('Run', 3000.0, $lastMonday + 3600),
                $this->activity('Run', 5000.0, $lastMonday + 6 * 86400 + 3600), // Sunday.
                $this->activity('Run', 6000.0, $this->mondayThisWeek + 3600),
            ]);

        /** @var WeeklyStat[]|null $stored */
        $stored = null;
        $this->recorder
            ->expects($this->once())
            ->method('record')
            ->with(self::ALLOWED_ATHLETE_ID, $this->anything())
            ->willReturnCallback(
                /** @param WeeklyStat[] $stats */
                static function (string $athleteId, array $stats) use (&$stored): void {
                    $stored = $stats;
                },
            );
        $this->recorder->expects($this->never())->method('replaceAll');

        $stats = $this->provider->refresh();

        // Only last week (completed with its Sunday run) and this week, newest first.
        self::assertCount(2, $stats);
        self::assertSame(gmdate('Y-m-d', $this->mondayThisWeek), $stats[0]->getFirstDayOfWeek()->format('Y-m-d'));
        self::assertSame(6000.0, $stats[0]->getTotalDistance());
        self::assertSame(gmdate('Y-m-d', $lastMonday), $stats[1]->getFirstDayOfWeek()->format('Y-m-d'));
        self::assertSame(8000.0, $stats[1]->getTotalDistance());

        // 5-week averages use the stored weeks before them.
        self::assertEqualsWithDelta(9600.0, $stats[1]->getFiveWeekAverage(), 0.001); // (4 * 10000 + 8000) / 5
        self::assertEqualsWithDelta(8800.0, $stats[0]->getFiveWeekAverage(), 0.001); // (3 * 10000 + 8000 + 6000) / 5

        self::assertSame($stats, $stored);
    }

    public function testRefreshWhenThisWeekIsStoredOnlyRefreshesThisWeek(): void
    {
        $this->connectAllowedAthlete();

        $this->repository->method('findLatestForAthlete')->willReturn([$this->storedWeek($this->mondayThisWeek, 1000.0)]);
        $this->repository->method('findBeforeWeek')->willReturn([]);
        $this->stravaClient
            ->expects($this->once())
            ->method('getAthleteActivities')
            ->with(null, (string) ($this->mondayThisWeek - 1), 1, 200)
            ->willReturn([$this->activity('Run', 5000.0, $this->mondayThisWeek + 3600)]);

        $stats = $this->provider->refresh();

        self::assertCount(1, $stats);
        self::assertSame(5000.0, $stats[0]->getTotalDistance());
        self::assertSame(5000.0, $stats[0]->getFiveWeekAverage());
    }

    public function testRefreshWithNothingStoredLoadsTheWholeHistory(): void
    {
        $this->connectAllowedAthlete();
        $this->repository->method('findLatestForAthlete')->willReturn([]);
        $this->repository->expects($this->never())->method('findBeforeWeek');

        $this->stravaClient
            ->expects($this->once())
            ->method('getAthleteActivities')
            ->with(null, '0', 1, 200)
            ->willReturn([
                $this->activity('Run', 5000.0, $this->mondayThisWeek + 3600),
                $this->activity('Run', 8000.0, $this->mondayThisWeek - 2 * self::WEEK + 3600),
            ]);

        $this->recorder->expects($this->never())->method('record');
        $this->recorder
            ->expects($this->once())
            ->method('replaceAll')
            ->with(self::ALLOWED_ATHLETE_ID, $this->countOf(3));

        $stats = $this->provider->refresh();

        // From the first activity's week to this week, including the empty week in between.
        self::assertCount(3, $stats);
        self::assertSame(5000.0, $stats[0]->getTotalDistance());
        self::assertSame(0.0, $stats[1]->getTotalDistance());
        self::assertSame(8000.0, $stats[2]->getTotalDistance());
        self::assertEqualsWithDelta((8000.0 + 0.0 + 5000.0) / 3, $stats[0]->getFiveWeekAverage(), 0.001);
    }

    public function testResetReloadsTheWholeHistoryAndReplacesTheStoredWeeks(): void
    {
        $this->connectAllowedAthlete();

        // Reset ignores what is stored.
        $this->repository->expects($this->never())->method('findLatestForAthlete');

        $this->stravaClient
            ->expects($this->once())
            ->method('getAthleteActivities')
            ->with(null, '0', 1, 200)
            ->willReturn([
                $this->activity('Run', 8000.0, $this->mondayThisWeek - self::WEEK + 3600),
                $this->activity('Run', 5000.0, $this->mondayThisWeek + 3600),
            ]);

        /** @var WeeklyStat[]|null $replaced */
        $replaced = null;
        $this->recorder
            ->expects($this->once())
            ->method('replaceAll')
            ->willReturnCallback(
                /** @param WeeklyStat[] $stats */
                static function (string $athleteId, array $stats) use (&$replaced): void {
                    $replaced = $stats;
                },
            );
        $this->recorder->expects($this->never())->method('record');

        $stats = $this->provider->reset();

        self::assertCount(2, $stats);
        self::assertSame(5000.0, $stats[0]->getTotalDistance());
        self::assertSame(6500.0, $stats[0]->getFiveWeekAverage());
        self::assertSame(8000.0, $stats[1]->getTotalDistance());
        self::assertSame($stats, $replaced);
    }

    public function testResetFollowsStravaPagination(): void
    {
        $this->connectAllowedAthlete();

        $fullPage = array_fill(0, 200, $this->activity('Run', 10.0, $this->mondayThisWeek + 3600));
        $lastPage = [$this->activity('Run', 10.0, $this->mondayThisWeek + 7200)];

        $this->stravaClient
            ->expects($this->exactly(2))
            ->method('getAthleteActivities')
            ->willReturnCallback(static fn (string|null $before, string|null $after, int $page): array => $page === 1 ? $fullPage : $lastPage);

        $stats = $this->provider->reset();

        self::assertSame(201, $stats[0]->getActivityCount());
    }

    public function testResetWithoutActivitiesClearsTheStoredWeeks(): void
    {
        $this->connectAllowedAthlete();
        $this->stubActivities([]);

        $this->recorder
            ->expects($this->once())
            ->method('replaceAll')
            ->with(self::ALLOWED_ATHLETE_ID, []);

        self::assertSame([], $this->provider->reset());
    }

    public function testRefreshFailsWhenStoringFails(): void
    {
        $this->connectAllowedAthlete();
        $this->repository->method('findLatestForAthlete')->willReturn([$this->storedWeek($this->mondayThisWeek, 0.0)]);
        $this->repository->method('findBeforeWeek')->willReturn([]);
        $this->stubActivities([$this->activity('Run', 5000.0, $this->mondayThisWeek + 3600)]);

        $this->recorder->method('record')->willThrowException(new RuntimeException('database is down'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('database is down');

        $this->provider->refresh();
    }

    public function testRefreshUsesTheTokenStoredInTheDatabase(): void
    {
        $this->session->set(AthleteGuard::SESSION_KEY, self::ALLOWED_ATHLETE_ID);
        $this->repository->method('findLatestForAthlete')->willReturn([]);

        $this->tokenManager
            ->expects($this->once())
            ->method('getValidToken')
            ->with(self::ALLOWED_ATHLETE_ID)
            ->willReturn($this->validToken('database-token'));
        $this->clientProvider
            ->expects($this->atLeastOnce())
            ->method('getAPIClient')
            ->with('database-token')
            ->willReturn($this->stravaClient);
        $this->stubActivities([$this->activity('Run', 5000.0, $this->mondayThisWeek + 3600)]);

        $this->provider->refresh();

        // The token is never put in the session.
        self::assertFalse($this->session->has(AthleteGuard::LEGACY_TOKEN_SESSION_KEY));
    }

    public function testRefreshWithoutStoredTokenDisconnectsSoYouCanReconnect(): void
    {
        $this->session->set(AthleteGuard::SESSION_KEY, self::ALLOWED_ATHLETE_ID);

        $this->tokenManager->method('getValidToken')->willThrowException(new AccessTokenMissing());
        $this->clientProvider->expects($this->never())->method('getAPIClient');
        $this->recorder->expects($this->never())->method('record');

        $this->assertThrowsAccessTokenMissing(fn () => $this->provider->refresh());

        self::assertFalse($this->session->has(AthleteGuard::SESSION_KEY));
    }

    public function testRefreshLatestStatsWorksWithoutABrowserSession(): void
    {
        // Console: nothing in the session, the token comes from the database.
        $this->tokenManager
            ->expects($this->once())
            ->method('getValidToken')
            ->with(self::ALLOWED_ATHLETE_ID)
            ->willReturn($this->validToken('database-token'));
        $this->clientProvider
            ->expects($this->atLeastOnce())
            ->method('getAPIClient')
            ->with('database-token')
            ->willReturn($this->stravaClient);
        $this->repository->method('findLatestForAthlete')->willReturn([$this->storedWeek($this->mondayThisWeek, 0.0)]);
        $this->repository->method('findBeforeWeek')->willReturn([]);
        $this->stubActivities([$this->activity('Run', 5000.0, $this->mondayThisWeek + 3600)]);
        $this->recorder
            ->expects($this->once())
            ->method('record')
            ->with(self::ALLOWED_ATHLETE_ID, $this->countOf(1));

        $stats = $this->provider->refreshLatestStats();

        self::assertCount(1, $stats);
        self::assertSame(5000.0, $stats[0]->getTotalDistance());
        self::assertSame([], $this->session->all());
    }

    public function testRefreshLatestStatsWithoutStoredTokenThrows(): void
    {
        $this->tokenManager->method('getValidToken')->willThrowException(new AccessTokenMissing());
        $this->clientProvider->expects($this->never())->method('getAPIClient');
        $this->recorder->expects($this->never())->method('record');

        $this->expectException(AccessTokenMissing::class);

        $this->provider->refreshLatestStats();
    }

    public function testConfirmConnectionForAllowedAthlete(): void
    {
        $this->connectAllowedAthlete();

        self::assertTrue($this->provider->confirmConnection());
    }

    private function connectAllowedAthlete(): void
    {
        $this->session->set(AthleteGuard::SESSION_KEY, self::ALLOWED_ATHLETE_ID);

        $this->tokenManager->method('getValidToken')->willReturn($this->validToken());
    }

    /** A null athlete is a session from before #379, which only held the Strava token. */
    private function setSessionAthlete(string|null $athleteId): void
    {
        if ($athleteId === null) {
            $this->session->set(AthleteGuard::LEGACY_TOKEN_SESSION_KEY, $this->validToken());

            return;
        }

        $this->session->set(AthleteGuard::SESSION_KEY, $athleteId);
    }

    private function assertThrowsAccessTokenMissing(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected AccessTokenMissing to be thrown.');
        } catch (AccessTokenMissing) {
            // Expected.
        }
    }

    private function storedWeek(int $monday, float $runDistance): StravaWeeklyStat
    {
        $stat = new StravaWeeklyStat(self::ALLOWED_ATHLETE_ID, new DateTimeImmutable(gmdate('Y-m-d', $monday)));
        $stat->update($runDistance, 0.0, [], 1, [$this->activity('Run', $runDistance, $monday + 3600)]);

        return $stat;
    }

    private function validToken(string $token = 'valid-token'): AccessToken
    {
        return new AccessToken([
            'access_token' => $token,
            'refresh_token' => 'refresh-token',
            'expires' => time() + 3600,
        ]);
    }

    /**
     * Makes the mocked Strava client return the given activities that fall in the requested window,
     * like the real `GET /athlete/activities?before=&after=` endpoint.
     *
     * @param array<int, array{sport_type: string, distance: float, start_date_local: string}> $activities
     */
    private function stubActivities(array $activities): void
    {
        $this->stravaClient
            ->method('getAthleteActivities')
            ->willReturnCallback(static fn (string|null $before, string|null $after): array => array_values(array_filter(
                $activities,
                /** @param array{sport_type: string, distance: float, start_date_local: string} $activity */
                static function (array $activity) use ($before, $after): bool {
                    $ts = (int) strtotime($activity['start_date_local']);

                    return ($after === null || $ts > (int) $after) && ($before === null || $ts < (int) $before);
                },
            )));
    }

    /** @return array{sport_type: string, distance: float, start_date_local: string} */
    private function activity(string $sportType, float $distance, int $timestamp): array
    {
        return [
            'sport_type' => $sportType,
            'distance' => $distance,
            'start_date_local' => gmdate('Y-m-d\TH:i:s\Z', $timestamp),
        ];
    }
}
