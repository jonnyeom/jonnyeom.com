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

        $this->recorder   = $this->createMock(WeeklyStatRecorder::class);
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
        $this->clientProvider->expects($this->never())->method('refreshAccessToken');
        $this->tokenManager->expects($this->never())->method('getValidToken');
        $this->tokenManager->expects($this->never())->method('refresh');
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

    public function testRefreshThrowsWhenNotConnected(): void
    {
        $this->clientProvider->expects($this->never())->method('getAPIClient');
        $this->recorder->expects($this->never())->method('record');

        $this->expectException(AccessTokenMissing::class);

        $this->provider->refresh(2);
    }

    #[DataProvider('disallowedAthletes')]
    public function testRefreshRejectsDisallowedAthleteAndClearsSession(string|null $sessionAthleteId): void
    {
        $this->setSessionAthlete($sessionAthleteId);

        $this->clientProvider->expects($this->never())->method('getAPIClient');
        $this->stravaClient->expects($this->never())->method('getAthleteActivities');
        $this->recorder->expects($this->never())->method('record');

        $this->assertThrowsAccessTokenMissing(fn () => $this->provider->refresh(2));

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

    public function testRefreshCalculatesAndStoresWeeklyStats(): void
    {
        $this->connectAllowedAthlete();
        $this->stubActivities([
            $this->activity('Run', 8000.0, $this->mondayThisWeek - 7 * 86400 + 3600), // Last week.
            $this->activity('Run', 5000.0, $this->mondayThisWeek + 3600),             // This week.
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

        $stats = $this->provider->refresh(2);

        self::assertCount(2, $stats);

        // Newest week first.
        self::assertSame(gmdate('Y-m-d', $this->mondayThisWeek), $stats[0]->getFirstDayOfWeek()->format('Y-m-d'));
        self::assertSame(5000.0, $stats[0]->getTotalDistance());
        self::assertSame(1, $stats[0]->getActivityCount());
        self::assertSame(6500.0, $stats[0]->getFiveWeekAverage()); // (8000 + 5000) / 2

        self::assertSame(gmdate('Y-m-d', $this->mondayThisWeek - 7 * 86400), $stats[1]->getFirstDayOfWeek()->format('Y-m-d'));
        self::assertSame(8000.0, $stats[1]->getTotalDistance());

        // Exactly the returned stats are stored.
        self::assertSame($stats, $stored);
    }

    public function testRefreshFetchesOnlyTheNeededWeeksInOneRequest(): void
    {
        $this->connectAllowedAthlete();

        // 2 visible weeks + 4 weeks of history for the 5-week average = from the Monday 5 weeks ago.
        $expectedAfter = (string) ((int) strtotime('-5 weeks', $this->mondayThisWeek) - 1);

        $this->stravaClient
            ->expects($this->once())
            ->method('getAthleteActivities')
            ->with(null, $expectedAfter, 1, 200)
            ->willReturn([$this->activity('Run', 5000.0, $this->mondayThisWeek + 3600)]);

        $this->provider->refresh(2);
    }

    public function testRefreshFollowsStravaPagination(): void
    {
        $this->connectAllowedAthlete();

        $fullPage = array_fill(0, 200, $this->activity('Run', 10.0, $this->mondayThisWeek + 3600));
        $lastPage = [$this->activity('Run', 10.0, $this->mondayThisWeek + 7200)];

        $this->stravaClient
            ->expects($this->exactly(2))
            ->method('getAthleteActivities')
            ->willReturnCallback(static fn (string|null $before, string|null $after, int $page): array => $page === 1 ? $fullPage : $lastPage);

        $stats = $this->provider->refresh(1);

        self::assertSame(201, $stats[0]->getActivityCount());
    }

    public function testRefreshWithoutActivitiesStoresNothing(): void
    {
        $this->connectAllowedAthlete();
        $this->stubActivities([]);

        $this->recorder
            ->expects($this->once())
            ->method('record')
            ->with(self::ALLOWED_ATHLETE_ID, []);

        self::assertSame([], $this->provider->refresh(2));
    }

    public function testRefreshFailsWhenStoringFails(): void
    {
        $this->connectAllowedAthlete();
        $this->stubActivities([$this->activity('Run', 5000.0, $this->mondayThisWeek + 3600)]);

        $this->recorder->method('record')->willThrowException(new RuntimeException('database is down'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('database is down');

        $this->provider->refresh(1);
    }

    public function testRefreshUsesTheTokenStoredInTheDatabase(): void
    {
        $this->session->set(AthleteGuard::SESSION_KEY, self::ALLOWED_ATHLETE_ID);

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

        $this->provider->refresh(1);

        // The token is never put in the session.
        self::assertFalse($this->session->has(AthleteGuard::LEGACY_TOKEN_SESSION_KEY));
    }

    public function testRefreshWithoutStoredTokenDisconnectsSoYouCanReconnect(): void
    {
        $this->session->set(AthleteGuard::SESSION_KEY, self::ALLOWED_ATHLETE_ID);

        $this->tokenManager->method('getValidToken')->willThrowException(new AccessTokenMissing());
        $this->clientProvider->expects($this->never())->method('getAPIClient');
        $this->recorder->expects($this->never())->method('record');

        $this->assertThrowsAccessTokenMissing(fn () => $this->provider->refresh(1));

        self::assertFalse($this->session->has(AthleteGuard::SESSION_KEY));
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
