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
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

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
            ->method('getWeeklyStatsPaged')
            ->with(20)  // INITIAL_VISIBLE_WEEKS
            ->willReturn($weeklyStats);

        $component = $this->createLiveComponent('WeeklyStatsTable');
        $rendered  = $component->render();

        $this->assertStringContainsString('Load more', (string) $rendered);
        $this->assertStringNotContainsString('is-danger', (string) $rendered);
    }

    public function testLoadMoreIncreasesLimit(): void
    {
        $component = new WeeklyStatsTable($this->stravaDataProvider);

        $this->assertSame(20, $component->visibleWeeks);

        $component->loadMore();
        $this->assertSame(30, $component->visibleWeeks);

        $component->loadMore();
        $this->assertSame(40, $component->visibleWeeks);
    }

    public function testAccessTokenMissingShowsError(): void
    {
        $this->stravaDataProvider
            ->method('getWeeklyStatsPaged')
            ->willThrowException(new AccessTokenMissing());

        $component = $this->createLiveComponent('WeeklyStatsTable');
        $rendered  = $component->render();

        $this->assertStringContainsString('No access token', (string) $rendered);
        $this->assertStringContainsString('is-danger', (string) $rendered);
    }

    public function testIdentityProviderExceptionShowsError(): void
    {
        $this->stravaDataProvider
            ->method('getWeeklyStatsPaged')
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
            ->method('getWeeklyStatsPaged')
            ->willThrowException(new RuntimeException('something went wrong'));

        $component = $this->createLiveComponent('WeeklyStatsTable');
        $rendered  = $component->render();

        $this->assertStringContainsString('Application error', (string) $rendered);
        $this->assertStringContainsString('something went wrong', (string) $rendered);
        $this->assertStringContainsString('is-danger', (string) $rendered);
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
