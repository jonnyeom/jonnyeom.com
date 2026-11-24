<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Exception\Strava\InvalidPlan;
use App\Service\Strava\AthleteGuard;
use App\Service\Strava\WeeklyPlanManager;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Session\SessionFactoryInterface;

use function explode;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

final class StravaPlanApiControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    private WeeklyPlanManager&MockObject $planManager;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->planManager = $this->createMock(WeeklyPlanManager::class);
        self::getContainer()->set(WeeklyPlanManager::class, $this->planManager);
    }

    public function testListIsPublicAndNotEditableWithoutAConnection(): void
    {
        $this->planManager
            ->expects($this->once())
            ->method('getWeeks')
            ->with(new DateTimeImmutable('2026-11-02'), 3)
            ->willReturn([['weekStart' => '2026-11-02']]);

        $this->client->request('GET', '/api/strava/plans?from=2026-11-02&weeks=3');

        self::assertResponseIsSuccessful();
        $body = $this->responseJson();
        self::assertFalse($body['editable']);
        self::assertSame([['weekStart' => '2026-11-02']], $body['weeks']);
    }

    public function testListIsEditableForTheConnectedAthlete(): void
    {
        $this->planManager->method('getWeeks')->willReturn([]);
        $this->connect();

        $this->client->request('GET', '/api/strava/plans');

        self::assertResponseIsSuccessful();
        self::assertTrue($this->responseJson()['editable']);
    }

    public function testListRejectsAWeekThatDoesNotStartOnMonday(): void
    {
        $this->planManager->expects($this->never())->method('getWeeks');

        $this->client->request('GET', '/api/strava/plans?from=2026-11-03');

        self::assertResponseStatusCodeSame(400);
    }

    public function testUpdateNeedsAStravaConnection(): void
    {
        $this->planManager->expects($this->never())->method('save');

        $this->putJson('/api/strava/plans/2026-11-23', ['days' => ['Mon' => ['Run' => 5]]]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testUpdateSavesThePlan(): void
    {
        $this->connect();
        $this->planManager
            ->expects($this->once())
            ->method('save')
            ->with(new DateTimeImmutable('2026-11-23'), ['Mon' => ['Run' => 5], 'Sat' => ['Bike' => 30]], 'Build week')
            ->willReturn(['weekStart' => '2026-11-23', 'totalMiles' => 15.5]);

        $this->putJson('/api/strava/plans/2026-11-23', [
            'days' => ['Mon' => ['Run' => 5], 'Sat' => ['Bike' => 30]],
            'notes' => 'Build week',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(['weekStart' => '2026-11-23', 'totalMiles' => 15.5], $this->responseJson());
    }

    public function testUpdateOnlyAcceptsJson(): void
    {
        $this->connect();
        $this->planManager->expects($this->never())->method('save');

        $this->client->request('PUT', '/api/strava/plans/2026-11-23', ['days' => []]);

        self::assertResponseStatusCodeSame(415);
    }

    public function testUpdateReportsAnInvalidPlan(): void
    {
        $this->connect();
        $this->planManager->method('save')->willThrowException(new InvalidPlan('Unknown sport type "Hike" on Mon.'));

        $this->putJson('/api/strava/plans/2026-11-23', ['days' => ['Mon' => ['Hike' => 5]]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'Unknown sport type "Hike" on Mon.'], $this->responseJson());
    }

    public function testUpdateRejectsAWeekThatDoesNotStartOnMonday(): void
    {
        $this->connect();
        $this->planManager->expects($this->never())->method('save');

        $this->putJson('/api/strava/plans/2026-11-24', ['days' => []]);

        self::assertResponseStatusCodeSame(400);
    }

    public function testDeleteRemovesThePlan(): void
    {
        $this->connect();
        $this->planManager->expects($this->once())->method('delete')->with(new DateTimeImmutable('2026-11-23'));

        $this->client->request('DELETE', '/api/strava/plans/2026-11-23');

        self::assertResponseStatusCodeSame(204);
    }

    public function testDeleteNeedsAStravaConnection(): void
    {
        $this->planManager->expects($this->never())->method('delete');

        $this->client->request('DELETE', '/api/strava/plans/2026-11-23');

        self::assertResponseStatusCodeSame(403);
    }

    public function testExportIsACsvDownload(): void
    {
        $this->planManager
            ->expects($this->once())
            ->method('getWeeks')
            ->with(new DateTimeImmutable('2026-11-23'), 2)
            ->willReturn([
                [
                    'weekStart' => '2026-11-23',
                    'days' => ['Mon' => ['Run' => 5.0]],
                    'dailyMiles' => [],
                    'sportTotals' => [],
                    'totalMiles' => 5.0,
                    'notes' => 'Easy',
                    'updatedAt' => null,
                    'actual' => null,
                ],
            ]);

        $this->client->request('GET', '/api/strava/plans.csv?from=2026-11-23&weeks=2');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename=strava-plan-2026-11-23.csv');
        $lines = explode("\n", (string) $this->client->getResponse()->getContent());
        self::assertStringStartsWith('Week,"Mon Run"', $lines[0]);
        self::assertStringStartsWith('2026-11-23,5,', $lines[1]);
        self::assertStringEndsWith(',5,,Easy', $lines[1]);
    }

    public function testImportNeedsAStravaConnection(): void
    {
        $this->planManager->expects($this->never())->method('saveMany');

        $this->postCsv("Week,Mon Run\n2026-11-23,5\n");

        self::assertResponseStatusCodeSame(403);
    }

    public function testImportOnlyAcceptsCsv(): void
    {
        $this->connect();
        $this->planManager->expects($this->never())->method('saveMany');

        $this->client->request('POST', '/api/strava/plans/import', ['csv' => 'Week']);

        self::assertResponseStatusCodeSame(415);
    }

    public function testImportSavesEveryWeek(): void
    {
        $this->connect();
        $this->planManager
            ->expects($this->once())
            ->method('saveMany')
            ->with([
                '2026-11-23' => ['days' => ['Mon' => ['Run' => 5.0]], 'notes' => 'Easy'],
                '2026-11-30' => ['days' => [], 'notes' => null],
            ])
            ->willReturn([['weekStart' => '2026-11-23'], ['weekStart' => '2026-11-30']]);

        $this->postCsv("Week,Mon Run,Notes\n2026-11-23,5,Easy\n2026-11-30,,\n");

        self::assertResponseIsSuccessful();
        self::assertSame(['weeks' => [['weekStart' => '2026-11-23'], ['weekStart' => '2026-11-30']]], $this->responseJson());
    }

    public function testImportReportsTheInvalidRow(): void
    {
        $this->connect();
        $this->planManager->expects($this->never())->method('saveMany');

        $this->postCsv("Week,Mon Run\n2026-11-23,5\n2026-11-30,lots\n");

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'Row 3: "Mon Run" must be a number of miles, "lots" was given.'], $this->responseJson());
    }

    private function postCsv(string $csv): void
    {
        $this->client->request(
            'POST',
            '/api/strava/plans/import',
            server: ['CONTENT_TYPE' => 'text/csv'],
            content: $csv,
        );
    }

    private function connect(): void
    {
        $sessionFactory = self::getContainer()->get('session.factory');
        self::assertInstanceOf(SessionFactoryInterface::class, $sessionFactory);

        $session = $sessionFactory->createSession();
        $session->set(AthleteGuard::SESSION_KEY, '12345');
        $session->save();

        $this->client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));
    }

    /** @param array<string, mixed> $body */
    private function putJson(string $uri, array $body): void
    {
        $this->client->request(
            'PUT',
            $uri,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    private function responseJson(): array
    {
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $body;
    }
}
