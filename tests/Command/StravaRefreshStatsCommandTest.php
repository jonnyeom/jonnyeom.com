<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\StravaRefreshStatsCommand;
use App\Exception\Strava\AccessTokenMissing;
use App\Model\Strava\WeeklyStat;
use App\Service\Strava\AthleteGuard;
use App\Service\Strava\StravaDataProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class StravaRefreshStatsCommandTest extends TestCase
{
    /** @var MockObject&StravaDataProvider */
    private MockObject $stravaDataProvider;

    protected function setUp(): void
    {
        $this->stravaDataProvider = $this->createMock(StravaDataProvider::class);
    }

    public function testRefreshesTheLatestWeek(): void
    {
        $thisWeek = new WeeklyStat('2026-11-23');
        $thisWeek->addStravaActivity(['sport_type' => 'Run', 'distance' => 16093.4, 'start_date_local' => '2026-11-23T08:00:00Z']);
        $thisWeek->setFiveWeekAverage(8046.7);

        $this->stravaDataProvider
            ->expects($this->once())
            ->method('refreshLatestStats')
            ->willReturn([$thisWeek, new WeeklyStat('2026-11-16')]);

        $tester = $this->runCommand('12345');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Refreshed 2 week(s) of Strava stats for athlete 12345', $tester->getDisplay());
        self::assertStringContainsString('This week: 10 mi', $tester->getDisplay());
        self::assertStringContainsString('5-week average 5 mi', $tester->getDisplay());
    }

    public function testNoActivities(): void
    {
        $this->stravaDataProvider->method('refreshLatestStats')->willReturn([]);

        $tester = $this->runCommand('12345');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Strava returned no activities', $tester->getDisplay());
    }

    public function testFailsWithoutAthleteId(): void
    {
        $this->stravaDataProvider->expects($this->never())->method('refreshLatestStats');

        $tester = $this->runCommand('');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('STRAVA_ATHLETE_ID is not set', $tester->getDisplay());
    }

    public function testFailsWhenNoTokenIsStored(): void
    {
        $this->stravaDataProvider->method('refreshLatestStats')->willThrowException(new AccessTokenMissing());

        $tester = $this->runCommand('12345');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('No Strava token is stored', $tester->getDisplay());
    }

    public function testFailsWhenStravaRejectsTheToken(): void
    {
        $this->stravaDataProvider
            ->method('refreshLatestStats')
            ->willThrowException(new IdentityProviderException('Bad Request', 400, []));

        $tester = $this->runCommand('12345');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Strava rejected the token refresh', $tester->getDisplay());
    }

    /** @param array<string, mixed> $input */
    private function runCommand(string $allowedAthleteId, array $input = []): CommandTester
    {
        $tester = new CommandTester(new StravaRefreshStatsCommand($this->stravaDataProvider, new AthleteGuard($allowedAthleteId)));
        $tester->execute($input);

        return $tester;
    }
}
