<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components;

use App\Exception\Strava\AccessTokenMissing;
use App\Model\Strava\WeeklyStat;
use App\Service\Strava\StravaDataProvider;
use App\Twig\Components\WeeklyStatsTable;
use DateTime;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\Chartjs\Builder\ChartBuilder;
use Symfony\UX\Chartjs\Model\Chart;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

use function array_fill;
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

    public function testInitialRenderIncludesTheChart(): void
    {
        $this->stravaDataProvider->method('getStoredWeeklyStats')->willReturn($this->buildWeeklyStats(3));

        $rendered = (string) $this->createLiveComponent('WeeklyStatsTable')->render();

        $this->assertStringContainsString('Weekly mileage, last 3 weeks', $rendered);
        $this->assertStringContainsString('data-controller="symfony--ux-chartjs--chart"', $rendered);
    }

    public function testChartShowsTheLatestTenWeeksOldestFirst(): void
    {
        // Provider order: newest first. Week i (0 = this week) runs i + 1 miles.
        $weeks = [];
        for ($i = 0; $i < 20; $i++) {
            $monday = new DateTime('monday this week');
            $monday->modify(sprintf('-%d weeks', $i));

            $week = new WeeklyStat($monday->format('Y-m-d'));
            $week->addStravaActivity([
                'sport_type' => 'Run',
                'distance' => ($i + 1) * 1609.34,
                'start_date_local' => $monday->format('Y-m-d') . 'T08:00:00Z',
            ]);
            $week->setFiveWeekAverage(2 * 1609.34);
            $weeks[] = $week;
        }

        $this->stravaDataProvider->method('getStoredWeeklyStats')->willReturn($weeks);

        $chart = (new WeeklyStatsTable($this->stravaDataProvider, $this->createMock(LoggerInterface::class), new ChartBuilder()))->getChart();

        $this->assertInstanceOf(Chart::class, $chart);
        $this->assertSame(Chart::TYPE_BAR, $chart->getType());

        $data = $chart->getData();
        $this->assertCount(10, $data['labels']);
        $this->assertSame($weeks[9]->getDate(), $data['labels'][0]);
        $this->assertSame($weeks[0]->getDate(), $data['labels'][9]);

        [$average, $miles] = $data['datasets'];
        $this->assertSame('line', $average['type']);
        $this->assertSame('5-week average', $average['label']);
        $this->assertSame(array_fill(0, 10, 2.0), $average['data']);
        $this->assertSame('Weekly miles', $miles['label']);
        $this->assertSame([10.0, 9.0, 8.0, 7.0, 6.0, 5.0, 4.0, 3.0, 2.0, 1.0], $miles['data']);
    }

    public function testNoChartWithoutStoredWeeks(): void
    {
        $this->stravaDataProvider->method('getStoredWeeklyStats')->willReturn([]);

        $component = new WeeklyStatsTable($this->stravaDataProvider, $this->createMock(LoggerInterface::class), new ChartBuilder());

        $this->assertNull($component->getChart());
    }

    public function testLoadMoreIncreasesLimit(): void
    {
        $component = new WeeklyStatsTable($this->stravaDataProvider, $this->createMock(LoggerInterface::class), new ChartBuilder());

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

    public function testRefreshErrorIsShown(): void
    {
        $this->stravaDataProvider
            ->expects($this->once())
            ->method('refresh')
            ->willThrowException(new AccessTokenMissing());

        $rendered = (string) $this->createComponentWithMockedProvider()
            ->call('refresh')
            ->render();

        $this->assertStringContainsString('No access token', $rendered);
        $this->assertStringNotContainsString('Updated the latest week', $rendered);
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

        $component = new WeeklyStatsTable($this->stravaDataProvider, $logger, new ChartBuilder());

        $this->assertSame('Application error: database is down', $component->getError());
    }

    public function testNotConnectedAndStravaAuthErrorsAreNotLoggedHere(): void
    {
        $this->stravaDataProvider
            ->method('refresh')
            ->willThrowException(new IdentityProviderException('Bad Request', 400, []));
        $this->stravaDataProvider
            ->method('getStoredWeeklyStats')
            ->willThrowException(new AccessTokenMissing());

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');

        $component = new WeeklyStatsTable($this->stravaDataProvider, $logger, new ChartBuilder());
        $component->refresh();
        $this->assertStringContainsString('Strava access error', (string) $component->getError());

        $component = new WeeklyStatsTable($this->stravaDataProvider, $logger, new ChartBuilder());
        $this->assertStringContainsString('No access token', (string) $component->getError());
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
