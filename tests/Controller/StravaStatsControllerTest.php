<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Exception\Strava\AccessTokenMissing;
use App\Model\Strava\WeeklyStat;
use App\Service\Strava\StravaDataProvider;
use DateTime;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Throwable;

use function json_decode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

final class StravaStatsControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    /** @var MockObject&StravaDataProvider */
    private MockObject $stravaDataProvider;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Keep the mocked provider in the container across requests.
        $this->client->disableReboot();

        $this->stravaDataProvider = $this->createMock(StravaDataProvider::class);
        self::getContainer()->set(StravaDataProvider::class, $this->stravaDataProvider);
    }

    public function testTableRendersTheStoredWeeksFromTheDatabase(): void
    {
        $this->stravaDataProvider
            ->expects($this->once())
            ->method('getStoredWeeklyStats')
            ->with(20)
            ->willReturn($this->buildWeeklyStats(20));
        $this->stravaDataProvider->expects($this->never())->method('refresh');
        $this->stravaDataProvider->expects($this->never())->method('reset');

        $this->client->request('GET', '/strava/stats');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(20, 'tbody tr');
        self::assertSelectorExists('button[data-action="strava-stats#loadMore"]');
    }

    public function testTableWithFewerStoredWeeksThanRequestedHasNoLoadMore(): void
    {
        $this->stravaDataProvider->method('getStoredWeeklyStats')->with(30)->willReturn($this->buildWeeklyStats(3));

        $this->client->request('GET', '/strava/stats?weeks=30');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(3, 'tbody tr');
        self::assertSelectorNotExists('button[data-action="strava-stats#loadMore"]');
        self::assertSelectorTextContains('p.has-text-grey', 'Showing all stored weeks');
    }

    public function testTableWithNothingStoredAsksToRefresh(): void
    {
        $this->stravaDataProvider->method('getStoredWeeklyStats')->willReturn([]);

        $this->client->request('GET', '/strava/stats');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.notification', 'No stored data yet');
    }

    #[DataProvider('weekLimits')]
    public function testTableClampsTheNumberOfWeeks(string $requested, int $expected): void
    {
        $this->stravaDataProvider
            ->expects($this->once())
            ->method('getStoredWeeklyStats')
            ->with($expected)
            ->willReturn([]);

        $this->client->request('GET', '/strava/stats?weeks=' . $requested);

        self::assertResponseIsSuccessful();
    }

    /** @return array<string, array{string, int}> */
    public static function weekLimits(): array
    {
        return [
            'zero' => ['0', 1],
            'negative' => ['-5', 1],
            'too many' => ['100000', 520],
        ];
    }

    public function testTableWhenNotConnected(): void
    {
        $this->stravaDataProvider->method('getStoredWeeklyStats')->willThrowException(new AccessTokenMissing());

        $this->client->request('GET', '/strava/stats');

        self::assertResponseStatusCodeSame(401);
        self::assertSelectorTextContains('.notification.is-danger', 'Please connect to Strava');
    }

    public function testRefreshUpdatesTheLatestWeek(): void
    {
        $this->stravaDataProvider->expects($this->once())->method('refresh')->willReturn([]);
        $this->stravaDataProvider->expects($this->never())->method('reset');

        $this->postAction('/strava/stats/refresh');

        self::assertResponseIsSuccessful();
        self::assertSame(['message' => 'Updated the latest week with fresh Strava data'], $this->json());
    }

    public function testResetReloadsTheWholeHistory(): void
    {
        $this->stravaDataProvider->expects($this->once())->method('reset')->willReturn($this->buildWeeklyStats(52));
        $this->stravaDataProvider->expects($this->never())->method('refresh');

        $this->postAction('/strava/stats/reset');

        self::assertResponseIsSuccessful();
        self::assertSame(['message' => 'Reloaded all 52 weeks from Strava'], $this->json());
    }

    #[DataProvider('actions')]
    public function testActionsRequireTheAjaxHeader(string $url): void
    {
        $this->stravaDataProvider->expects($this->never())->method('refresh');
        $this->stravaDataProvider->expects($this->never())->method('reset');

        $this->client->request('POST', $url);

        self::assertResponseStatusCodeSame(400);
    }

    #[DataProvider('actions')]
    public function testActionsOnlyAcceptPost(string $url): void
    {
        $this->stravaDataProvider->expects($this->never())->method('refresh');
        $this->stravaDataProvider->expects($this->never())->method('reset');

        $this->client->request('GET', $url);

        self::assertResponseStatusCodeSame(405);
    }

    /** @return array<string, array{string}> */
    public static function actions(): array
    {
        return [
            'refresh' => ['/strava/stats/refresh'],
            'reset' => ['/strava/stats/reset'],
        ];
    }

    #[DataProvider('errors')]
    public function testActionErrorsAreReturnedAsJson(Throwable $error, int $status, string $message): void
    {
        $this->stravaDataProvider->method('refresh')->willThrowException($error);

        $this->postAction('/strava/stats/refresh');

        self::assertResponseStatusCodeSame($status);
        self::assertSame(['error' => $message], $this->json());
    }

    /** @return array<string, array{Throwable, int, string}> */
    public static function errors(): array
    {
        return [
            'not connected' => [new AccessTokenMissing(), 401, 'No access token. Please connect to Strava.'],
            'strava rejected the token' => [
                new IdentityProviderException('Bad Request', 400, []),
                502,
                'Strava access error: Bad Request',
            ],
            'unexpected' => [new RuntimeException('database is down'), 500, 'Application error: database is down'],
        ];
    }

    private function postAction(string $url): void
    {
        $this->client->request('POST', $url, server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * Builds $count WeeklyStat objects for consecutive Mondays ending last week, newest first.
     *
     * @return array<int, WeeklyStat>
     */
    private function buildWeeklyStats(int $count): array
    {
        $stats = [];
        for ($i = 0; $i < $count; $i++) {
            $monday = new DateTime('last monday');
            $monday->modify(sprintf('-%d weeks', $i));
            $stats[] = new WeeklyStat($monday->format('Y-m-d'));
        }

        return $stats;
    }
}
