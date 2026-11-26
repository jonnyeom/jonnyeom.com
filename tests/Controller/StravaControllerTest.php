<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Model\Strava\WeeklyStat;
use App\Service\Strava\StravaDataProvider;
use DateTime;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StravaControllerTest extends WebTestCase
{
    public function testStatsPageIsShownWithoutAStravaConnection(): void
    {
        $client = self::createClient();
        $client->disableReboot();

        $stravaDataProvider = $this->createMock(StravaDataProvider::class);
        $stravaDataProvider
            ->expects($this->atLeastOnce())
            ->method('getStoredWeeklyStats')
            ->willReturn([new WeeklyStat((new DateTime('monday this week'))->format('Y-m-d'))]);
        // Viewing never calls Strava.
        $stravaDataProvider->expects($this->never())->method('refresh');
        $stravaDataProvider->expects($this->never())->method('reset');
        self::getContainer()->set(StravaDataProvider::class, $stravaDataProvider);

        // No session: not connected to Strava.
        $client->request('GET', '/strava');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('table');
        self::assertSelectorExists('[data-symfony--ux-react--react-component-value="StravaChart"]');

        // The menu offers Connect instead of Logout.
        self::assertSelectorTextContains('.tabs li.is-info a', 'Connect');
        self::assertSelectorNotExists('a[href="/strava/logout"]');
    }
}
