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
use App\Service\Strava\WeeklyStatRecorder;
use DateTimeImmutable;
use League\OAuth2\Client\Token\AccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Strava\API\Client;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

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

    /** @var MockObject&LoggerInterface */
    private MockObject $logger;

    /** @var MockObject&StravaWeeklyStatRepository */
    private MockObject $repository;

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
        $this->logger     = $this->createMock(LoggerInterface::class);
        $this->repository = $this->createMock(StravaWeeklyStatRepository::class);

        $this->provider = new StravaDataProvider(
            $requestStack,
            $this->clientProvider,
            new ArrayAdapter(),
            new AthleteGuard(self::ALLOWED_ATHLETE_ID),
            $this->recorder,
            $this->logger,
            $this->repository,
        );
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTimezone);
    }

    public function testThrowsWhenNoAccessTokenInSession(): void
    {
        $this->clientProvider->expects($this->never())->method('getAPIClient');
        $this->recorder->expects($this->never())->method('record');

        $this->expectException(AccessTokenMissing::class);

        $this->provider->getWeeklyStatsPaged(2);
    }

    #[DataProvider('disallowedAthletes')]
    public function testRejectsDisallowedAthleteAndClearsSession(string|null $sessionAthleteId): void
    {
        $this->session->set('access_token', $this->validToken());
        if ($sessionAthleteId !== null) {
            $this->session->set(AthleteGuard::SESSION_KEY, $sessionAthleteId);
        }

        $this->clientProvider->expects($this->never())->method('getAPIClient');
        $this->stravaClient->expects($this->never())->method('getAthleteActivities');
        $this->recorder->expects($this->never())->method('record');

        try {
            $this->provider->getWeeklyStatsPaged(2);
            self::fail('Expected AccessTokenMissing to be thrown.');
        } catch (AccessTokenMissing) {
            // Expected.
        }

        self::assertFalse($this->session->has('access_token'));
        self::assertFalse($this->session->has(AthleteGuard::SESSION_KEY));
    }

    /** @return array<string, array{string|null}> */
    public static function disallowedAthletes(): array
    {
        return [
            'another athlete' => ['99999'],
            'session without a verified athlete' => [null],
        ];
    }

    public function testReturnsWeeklyStatsForAllowedAthleteAndStoresThem(): void
    {
        $this->connectAllowedAthlete();
        $this->stubActivities([
            $this->activity('Run', 8000.0, $this->mondayThisWeek - 7 * 86400 + 3600), // Last week.
            $this->activity('Run', 5000.0, $this->mondayThisWeek + 3600),             // This week.
        ]);

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

        $stats = $this->provider->getWeeklyStatsPaged(2);

        self::assertCount(2, $stats);

        // Newest week first.
        self::assertSame(gmdate('Y-m-d', $this->mondayThisWeek), $stats[0]->getFirstDayOfWeek()->format('Y-m-d'));
        self::assertSame(5000.0, $stats[0]->getTotalDistance());
        self::assertSame(1, $stats[0]->getActivityCount());
        self::assertSame(6500.0, $stats[0]->getFiveWeekAverage()); // (8000 + 5000) / 2

        self::assertSame(gmdate('Y-m-d', $this->mondayThisWeek - 7 * 86400), $stats[1]->getFirstDayOfWeek()->format('Y-m-d'));
        self::assertSame(8000.0, $stats[1]->getTotalDistance());

        // Exactly the returned stats are persisted.
        self::assertSame($stats, $stored);
    }

    public function testStorageFailureIsLoggedAndStatsAreStillReturned(): void
    {
        $this->connectAllowedAthlete();
        $this->stubActivities([$this->activity('Run', 5000.0, $this->mondayThisWeek + 3600)]);

        $this->recorder->method('record')->willThrowException(new RuntimeException('database is down'));
        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with($this->stringContains('database is down'), $this->anything());

        $stats = $this->provider->getWeeklyStatsPaged(1);

        self::assertCount(1, $stats);
        self::assertSame(5000.0, $stats[0]->getTotalDistance());
    }

    public function testReturnsEmptyAndStoresNothingWithoutActivities(): void
    {
        $this->connectAllowedAthlete();
        $this->stubActivities([]);

        $this->recorder->expects($this->never())->method('record');

        self::assertSame([], $this->provider->getWeeklyStatsPaged(2));
    }

    public function testRefreshesExpiredTokenAndUsesTheNewOne(): void
    {
        $expired = new AccessToken([
            'access_token' => 'expired-token',
            'refresh_token' => 'refresh-me',
            'expires' => time() - 60,
        ]);

        $refreshed = $this->validToken('fresh-token');

        $this->session->set('access_token', $expired);
        $this->session->set(AthleteGuard::SESSION_KEY, self::ALLOWED_ATHLETE_ID);

        $this->clientProvider
            ->expects($this->once())
            ->method('refreshAccessToken')
            ->with('refresh-me')
            ->willReturn($refreshed);
        $this->clientProvider
            ->expects($this->atLeastOnce())
            ->method('getAPIClient')
            ->with('fresh-token')
            ->willReturn($this->stravaClient);
        $this->stubActivities([$this->activity('Run', 5000.0, $this->mondayThisWeek + 3600)]);

        $this->provider->getWeeklyStatsPaged(1);

        self::assertSame($refreshed, $this->session->get('access_token'));
    }

    public function testRefreshFetchesFreshDataInsteadOfCachedDataAndStoresIt(): void
    {
        $this->connectAllowedAthlete();

        $activities = [$this->activity('Run', 5000.0, $this->mondayThisWeek + 3600)];
        $this->stravaClient
            ->method('getAthleteActivities')
            ->willReturnCallback(static function () use (&$activities): array {
                return $activities;
            });

        /** @var WeeklyStat[] $recorded */
        $recorded = [];
        $this->recorder
            ->method('record')
            ->willReturnCallback(
                /** @param WeeklyStat[] $stats */
                static function (string $athleteId, array $stats) use (&$recorded): void {
                    $recorded = $stats;
                },
            );

        self::assertSame(5000.0, $this->provider->getWeeklyStatsPaged(1)[0]->getTotalDistance());

        // A new activity is recorded on Strava.
        $activities[] = $this->activity('Run', 3000.0, $this->mondayThisWeek + 7200);

        // Without a refresh, the cached data is served.
        self::assertSame(5000.0, $this->provider->getWeeklyStatsPaged(1)[0]->getTotalDistance());

        $this->provider->refresh(1);

        // The refresh stored the fresh stats.
        self::assertCount(1, $recorded);
        self::assertSame(8000.0, $recorded[0]->getTotalDistance());
        self::assertSame(2, $recorded[0]->getActivityCount());
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

    public function testStoredStatsAreReadFromTheDatabaseWithoutCallingStrava(): void
    {
        // Even an expired token must not trigger a call to Strava when only reading.
        $this->session->set('access_token', new AccessToken([
            'access_token' => 'expired-token',
            'refresh_token' => 'refresh-me',
            'expires' => time() - 60,
        ]));
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
        $this->recorder->expects($this->never())->method('record');

        $stats = $this->provider->getStoredWeeklyStats(20);

        self::assertCount(1, $stats);
        self::assertSame('2026-11-16', $stats[0]->getFirstDayOfWeek()->format('Y-m-d'));
        self::assertSame(5000.0, $stats[0]->getTotalDistance());
        self::assertSame(6500.0, $stats[0]->getFiveWeekAverage());
        self::assertSame(1, $stats[0]->getActivityCount());
    }

    #[DataProvider('disallowedAthletes')]
    public function testStoredStatsRequireAllowedAthlete(string|null $sessionAthleteId): void
    {
        $this->session->set('access_token', $this->validToken());
        if ($sessionAthleteId !== null) {
            $this->session->set(AthleteGuard::SESSION_KEY, $sessionAthleteId);
        }

        $this->repository->expects($this->never())->method('findLatestForAthlete');

        $this->expectException(AccessTokenMissing::class);

        $this->provider->getStoredWeeklyStats(20);
    }

    public function testRefreshRequiresAllowedAthlete(): void
    {
        $this->session->set('access_token', $this->validToken());
        $this->session->set(AthleteGuard::SESSION_KEY, '99999');

        $this->expectException(AccessTokenMissing::class);

        $this->provider->refresh(1);
    }

    public function testConfirmConnectionForAllowedAthlete(): void
    {
        $this->connectAllowedAthlete();

        self::assertTrue($this->provider->confirmConnection());
    }

    private function connectAllowedAthlete(): void
    {
        $this->session->set('access_token', $this->validToken());
        $this->session->set(AthleteGuard::SESSION_KEY, self::ALLOWED_ATHLETE_ID);
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
