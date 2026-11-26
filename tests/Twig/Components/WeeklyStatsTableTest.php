<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components;

use App\Exception\Strava\AccessTokenMissing;
use App\Model\Strava\WeeklyStat;
use App\Service\Strava\StravaDataProvider;
use App\Twig\Components\WeeklyStatsTable;
use DateTime;
use DateTimeImmutable;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;
use Throwable;

use function array_unique;
use function sprintf;

final class WeeklyStatsTableTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    /** @var MockObject&StravaDataProvider */
    private MockObject $stravaDataProvider;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->stravaDataProvider = $this->createMock(StravaDataProvider::class);
        self::getContainer()->set(StravaDataProvider::class, $this->stravaDataProvider);
    }

    public function testInitialRenderShowsWeeklyStats(): void
    {
        $weeklyStats = $this->buildWeeklyStats(3);

        $this->stravaDataProvider
            ->expects($this->once())
            ->method('getStoredWeeklyStats')
            ->with(20)  // INITIAL_VISIBLE_WEEKS
            ->willReturn($weeklyStats);

        $component = $this->createLiveComponent('WeeklyStatsTable');
        $rendered  = $component->render();

        $this->assertStringContainsString('Load more', (string) $rendered);
        $this->assertStringNotContainsString('is-danger', (string) $rendered);
    }

    public function testPageLoadReadsFromDatabaseAndDoesNotCallStrava(): void
    {
        $this->stravaDataProvider
            ->expects($this->once())
            ->method('getStoredWeeklyStats')
            ->with(20)
            ->willReturn($this->buildWeeklyStats(3));
        $this->stravaDataProvider->expects($this->never())->method('refresh');

        $this->createLiveComponent('WeeklyStatsTable')->render();
    }

    public function testEmptyDatabaseAsksToRefresh(): void
    {
        $this->stravaDataProvider
            ->method('getStoredWeeklyStats')
            ->willReturn([]);

        $rendered = (string) $this->createLiveComponent('WeeklyStatsTable')->render();

        $this->assertStringContainsString('No stored data yet', $rendered);
        $this->assertStringNotContainsString('is-danger', $rendered);
    }

    public function testFewerStoredWeeksThanRequestedShowsHint(): void
    {
        $this->stravaDataProvider
            ->method('getStoredWeeklyStats')
            ->willReturn($this->buildWeeklyStats(3));

        $rendered = (string) $this->createLiveComponent('WeeklyStatsTable')->render();

        $this->assertStringContainsString('Showing all stored weeks', $rendered);
    }

    public function testADayHasAMenuOfItsActivities(): void
    {
        $week = $this->weekWithActivities('monday this week', ['Mon' => ['Run']]);
        $this->stravaDataProvider->method('getStoredWeeklyStats')->willReturn([$week]);

        $rendered = $this->createLiveComponent('WeeklyStatsTable')->render();
        $day      = $rendered->crawler()->filter('.dropdown[data-controller="dropdown"]');

        $this->assertCount(1, $day);
        $button = $day->filter('button.dropdown-trigger');
        $this->assertSame('false', $button->attr('aria-expanded'));
        $menu = $day->filter('.dropdown-menu');
        $this->assertSame($button->attr('aria-controls'), $menu->attr('id'));
        $this->assertStringContainsString('5 mi', $menu->text());
        // The icons on phones, under the miles.
        $this->assertCount(1, $day->filter('.strava-day-icons .fa-person-running'));
        $this->assertStringNotContainsString('symfony--ux-chartjs', (string) $rendered);
    }

    public function testTheMenusOpenTowardsTheTable(): void
    {
        // Newest first, like the provider.
        $this->stravaDataProvider->method('getStoredWeeklyStats')->willReturn([
            $this->weekWithActivities('monday this week', ['Mon' => ['Run'], 'Sun' => ['Run', 'Ride']]),
            $this->weekWithActivities('monday this week -1 week', ['Mon' => ['Run']]),
            $this->weekWithActivities('monday this week -2 weeks', ['Mon' => ['Run']]),
        ]);

        $crawler = $this->createLiveComponent('WeeklyStatsTable')->render()->crawler();
        $classes = $crawler->filter('tbody tr')->each(
            static fn (Crawler $row): array => $row->filter('.dropdown')->each(
                static fn (Crawler $day): string => (string) $day->attr('class'),
            ),
        );

        // First row: Mon opens to the right and down; Sun (several activities, hover too) to the left.
        $this->assertStringNotContainsString('is-right', $classes[0][0]);
        $this->assertStringNotContainsString('is-up', $classes[0][0]);
        $this->assertStringNotContainsString('strava-day--several', $classes[0][0]);
        $this->assertStringContainsString('is-right', $classes[0][1]);
        $this->assertStringContainsString('strava-day--several', $classes[0][1]);
        // The last 2 rows open upwards.
        $this->assertStringContainsString('is-up', $classes[1][0]);
        $this->assertStringContainsString('is-up', $classes[2][0]);
        // Unique menus.
        $ids = $crawler->filter('.dropdown-menu')
            ->each(static fn (Crawler $menu): string => (string) $menu->attr('id'));
        $this->assertSame($ids, array_unique($ids));
    }

    public function testRefreshAndResetTellTheChartToReload(): void
    {
        $this->stravaDataProvider->method('refresh')->willReturn([]);
        $this->stravaDataProvider->method('reset')->willReturn([]);
        $this->stravaDataProvider->method('getStoredWeeklyStats')->willReturn($this->buildWeeklyStats(3));

        foreach (['refresh', 'reset'] as $action) {
            $component = $this->createComponentWithMockedProvider()->call($action);
            $this->assertComponentDispatchBrowserEvent($component, WeeklyStatsTable::REFRESHED_EVENT);
        }
    }

    public function testLoadMoreIncreasesLimit(): void
    {
        $component = new WeeklyStatsTable($this->stravaDataProvider, $this->createMock(LoggerInterface::class));

        $this->assertSame(20, $component->visibleWeeks);

        $component->loadMore();
        $this->assertSame(30, $component->visibleWeeks);

        $component->loadMore();
        $this->assertSame(40, $component->visibleWeeks);
    }

    public function testRefreshUpdatesTheLatestWeekThenReloadsFromTheDatabase(): void
    {
        $this->stravaDataProvider
            ->expects($this->once())
            ->method('refresh')
            ->willReturn([]);
        $this->stravaDataProvider->expects($this->never())->method('reset');
        $this->stravaDataProvider
            ->method('getStoredWeeklyStats')
            ->willReturn($this->buildWeeklyStats(3));

        $component = $this->createComponentWithMockedProvider();
        $rendered  = (string) $component->render();

        $this->assertStringContainsString('Refresh', $rendered);
        $this->assertStringContainsString('Reset', $rendered);
        $this->assertStringNotContainsString('Updated the latest week', $rendered);

        $rendered = (string) $component->call('refresh')->render();

        $this->assertStringContainsString('Updated the latest week with fresh Strava data', $rendered);
        $this->assertStringNotContainsString('is-danger', $rendered);
    }

    public function testResetReloadsTheWholeHistory(): void
    {
        $this->stravaDataProvider
            ->expects($this->once())
            ->method('reset')
            ->willReturn($this->buildWeeklyStats(52));
        $this->stravaDataProvider->expects($this->never())->method('refresh');
        $this->stravaDataProvider
            ->method('getStoredWeeklyStats')
            ->willReturn($this->buildWeeklyStats(20));

        $rendered = (string) $this->createComponentWithMockedProvider()
            ->call('reset')
            ->render();

        $this->assertStringContainsString('Reloaded all 52 weeks from Strava', $rendered);
        $this->assertStringNotContainsString('is-danger', $rendered);
    }

    #[DataProvider('stravaConnectionErrors')]
    public function testRefreshOrResetWithoutStravaRedirectsToTheConnectPage(string $action, Throwable $error): void
    {
        $this->stravaDataProvider
            ->expects($this->once())
            ->method($action)
            ->willThrowException($error);
        $this->stravaDataProvider->method('getStoredWeeklyStats')->willReturn($this->buildWeeklyStats(3));

        $response = $this->createComponentWithMockedProvider()
            ->call($action)
            ->response();

        $this->assertStringContainsString('/strava/connect/entry', (string) $response->headers->get('Location'));
    }

    /** @return array<string, array{string, Throwable}> */
    public static function stravaConnectionErrors(): array
    {
        return [
            'refresh, not connected' => ['refresh', new AccessTokenMissing()],
            'refresh, token rejected' => ['refresh', new IdentityProviderException('Bad Request', 400, [])],
            'reset, not connected' => ['reset', new AccessTokenMissing()],
            'reset, token rejected' => ['reset', new IdentityProviderException('Bad Request', 400, [])],
        ];
    }

    public function testOtherRefreshErrorsAreShownOnThePage(): void
    {
        $this->stravaDataProvider
            ->expects($this->once())
            ->method('refresh')
            ->willThrowException(new RuntimeException('database is down'));

        $rendered = (string) $this->createComponentWithMockedProvider()
            ->call('refresh')
            ->render();

        $this->assertStringContainsString('Application error: database is down', $rendered);
        $this->assertStringNotContainsString('Updated the latest week', $rendered);
    }

    public function testAFailedRefreshDoesNotReloadTheChart(): void
    {
        $this->stravaDataProvider->method('refresh')->willThrowException(new RuntimeException('database is down'));

        $component = $this->createComponentWithMockedProvider()->call('refresh');

        $this->assertComponentNotDispatchBrowserEvent($component, WeeklyStatsTable::REFRESHED_EVENT);
    }

    public function testUnexpectedErrorIsLogged(): void
    {
        $this->stravaDataProvider
            ->method('getStoredWeeklyStats')
            ->willThrowException(new RuntimeException('database is down'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->once())
            ->method('error')
            ->with($this->stringContains('database is down'));

        $component = new WeeklyStatsTable($this->stravaDataProvider, $logger);

        $this->assertSame('Application error: database is down', $component->getError());
    }

    public function testNotConnectedAndStravaAuthErrorsAreNotLoggedHere(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');

        foreach ([new AccessTokenMissing(), new IdentityProviderException('Bad Request', 400, [])] as $error) {
            $stravaDataProvider = $this->createMock(StravaDataProvider::class);
            $stravaDataProvider->method('getStoredWeeklyStats')->willThrowException($error);

            $component = new WeeklyStatsTable($stravaDataProvider, $logger);
            $this->assertNotNull($component->getError());
        }
    }

    public function testAccessTokenMissingShowsError(): void
    {
        $this->stravaDataProvider
            ->method('getStoredWeeklyStats')
            ->willThrowException(new AccessTokenMissing());

        $component = $this->createLiveComponent('WeeklyStatsTable');
        $rendered  = $component->render();

        $this->assertStringContainsString('No access token', (string) $rendered);
        $this->assertStringContainsString('is-danger', (string) $rendered);
    }

    public function testIdentityProviderExceptionShowsError(): void
    {
        $this->stravaDataProvider
            ->method('getStoredWeeklyStats')
            ->willThrowException(new IdentityProviderException('token expired', 401, []));

        $component = $this->createLiveComponent('WeeklyStatsTable');
        $rendered  = $component->render();

        $this->assertStringContainsString('Strava access error', (string) $rendered);
        $this->assertStringContainsString('token expired', (string) $rendered);
        $this->assertStringContainsString('is-danger', (string) $rendered);
    }

    public function testGenericExceptionShowsError(): void
    {
        $this->stravaDataProvider
            ->method('getStoredWeeklyStats')
            ->willThrowException(new RuntimeException('something went wrong'));

        $component = $this->createLiveComponent('WeeklyStatsTable');
        $rendered  = $component->render();

        $this->assertStringContainsString('Application error', (string) $rendered);
        $this->assertStringContainsString('something went wrong', (string) $rendered);
        $this->assertStringContainsString('is-danger', (string) $rendered);
    }

    /**
     * Live actions (call()) go through a real HTTP request. By default the test client reboots the
     * kernel before each request, which would drop the mocked StravaDataProvider from the container.
     */
    private function createComponentWithMockedProvider(): TestLiveComponent
    {
        $client = self::getContainer()->get('test.client');
        self::assertInstanceOf(KernelBrowser::class, $client);
        $client->disableReboot();

        return $this->createLiveComponent('WeeklyStatsTable', [], $client);
    }

    /**
     * A week of 5-mile activities, e.g. ['Mon' => ['Run'], 'Sun' => ['Run', 'Ride']].
     *
     * @param array<string, list<string>> $days
     */
    private function weekWithActivities(string $monday, array $days): WeeklyStat
    {
        $start = new DateTimeImmutable($monday);
        $week  = new WeeklyStat($start->format('Y-m-d'));
        foreach ($days as $day => $sports) {
            $date = $start->modify($day === 'Mon' ? '+0 days' : 'next ' . $day);
            foreach ($sports as $sport) {
                $week->addStravaActivity([
                    'sport_type' => $sport,
                    'distance' => 5 * 1609.34,
                    'start_date_local' => $date->format('Y-m-d') . 'T08:00:00Z',
                ]);
            }
        }

        return $week;
    }

    /**
     * Builds $count WeeklyStat objects for consecutive Mondays ending last week.
     *
     * @return array<int, WeeklyStat>
     */
    private function buildWeeklyStats(int $count): array
    {
        $stats  = [];
        $monday = new DateTime('last monday');

        for ($i = $count - 1; $i >= 0; $i--) {
            $date = clone $monday;
            $date->modify(sprintf('-%d weeks', $i));
            $stats[] = new WeeklyStat($date->format('Y-m-d'));
        }

        return $stats;
    }
}
