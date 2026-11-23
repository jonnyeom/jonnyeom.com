<?php

declare(strict_types=1);

namespace App\Tests\Service\Strava;

use App\Entity\StravaWeeklyStat;
use App\Model\Strava\WeeklyStat;
use App\Repository\StravaWeeklyStatRepository;
use App\Service\Strava\WeeklyStatRecorder;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests against a real database (Postgres in CI, see .github/workflows/ci.yml).
 * The schema is created by running the migrations before the test suite.
 */
#[Group('database')]
final class WeeklyStatRecorderTest extends KernelTestCase
{
    private const string ATHLETE_ID = '12345';

    private EntityManagerInterface $entityManager;

    private StravaWeeklyStatRepository $repository;

    private WeeklyStatRecorder $recorder;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $entityManager = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $repository = $container->get(StravaWeeklyStatRepository::class);
        self::assertInstanceOf(StravaWeeklyStatRepository::class, $repository);
        $this->repository = $repository;

        $recorder = $container->get(WeeklyStatRecorder::class);
        self::assertInstanceOf(WeeklyStatRecorder::class, $recorder);
        $this->recorder = $recorder;

        $this->entityManager->getConnection()->executeStatement('DELETE FROM strava_weekly_stat');
    }

    public function testRecordsNewWeeks(): void
    {
        $this->recorder->record(self::ATHLETE_ID, [
            $this->weeklyStat('2026-11-16', [['Run', 5000.0, '2026-11-16T08:00:00Z']], 6500.0),
            $this->weeklyStat('2026-11-09', [['Run', 8000.0, '2026-11-11T08:00:00Z']], 8000.0),
        ]);
        $this->entityManager->clear();

        self::assertSame(2, $this->repository->count([]));

        $stat = $this->findWeek(self::ATHLETE_ID, '2026-11-16');
        self::assertSame(5000.0, $stat->getTotalDistance());
        self::assertSame(6500.0, $stat->getFiveWeekAverage());
        self::assertSame(1, $stat->getActivityCount());
        self::assertEquals(5000.0, $stat->getDailyDistances()['Mon']);
        self::assertEquals(0.0, $stat->getDailyDistances()['Tue']);

        self::assertCount(1, $stat->getActivities());
        self::assertSame('Run', $stat->getActivities()[0]['sport_type']);
        self::assertSame('2026-11-16T08:00:00Z', $stat->getActivities()[0]['start_date_local']);

        // The page is rendered from what's stored: rebuilding gives the same stats.
        $rebuilt = $stat->toWeeklyStat();
        self::assertEquals(5000.0, $rebuilt->getTotalDistance());
        self::assertSame(6500.0, $rebuilt->getFiveWeekAverage());
        self::assertSame(1, $rebuilt->getActivityCount());

        $stat = $this->findWeek(self::ATHLETE_ID, '2026-11-09');
        self::assertSame(8000.0, $stat->getTotalDistance());
        self::assertEquals(8000.0, $stat->getDailyDistances()['Wed']);
    }

    public function testRecordingTheSameWeekAgainUpdatesInsteadOfAddingADuplicate(): void
    {
        $this->recorder->record(self::ATHLETE_ID, [
            $this->weeklyStat('2026-11-16', [['Run', 5000.0, '2026-11-16T08:00:00Z']], 5000.0),
        ]);
        $this->entityManager->clear(); // Simulate a new request.

        $this->recorder->record(self::ATHLETE_ID, [
            $this->weeklyStat('2026-11-16', [
                ['Run', 5000.0, '2026-11-16T08:00:00Z'],
                ['Run', 3000.0, '2026-11-18T08:00:00Z'],
            ], 8000.0),
        ]);
        $this->entityManager->clear();

        self::assertSame(1, $this->repository->count([]));

        $stat = $this->findWeek(self::ATHLETE_ID, '2026-11-16');
        self::assertSame(8000.0, $stat->getTotalDistance());
        self::assertSame(8000.0, $stat->getFiveWeekAverage());
        self::assertSame(2, $stat->getActivityCount());
    }

    public function testRecordingOverlappingPagesDoesNotAddDuplicates(): void
    {
        // First page load: 2 weeks. "Load more": 3 weeks, overlapping the first 2.
        $this->recorder->record(self::ATHLETE_ID, [
            $this->weeklyStat('2026-11-16', [], 0.0),
            $this->weeklyStat('2026-11-09', [], 0.0),
        ]);
        $this->entityManager->clear();

        $this->recorder->record(self::ATHLETE_ID, [
            $this->weeklyStat('2026-11-16', [], 0.0),
            $this->weeklyStat('2026-11-09', [], 0.0),
            $this->weeklyStat('2026-11-02', [], 0.0),
        ]);
        $this->entityManager->clear();

        self::assertSame(3, $this->repository->count([]));
    }

    public function testSameWeekForDifferentAthletesIsStoredSeparately(): void
    {
        $this->recorder->record(self::ATHLETE_ID, [
            $this->weeklyStat('2026-11-16', [['Run', 5000.0, '2026-11-16T08:00:00Z']], 5000.0),
        ]);
        $this->recorder->record('67890', [
            $this->weeklyStat('2026-11-16', [['Run', 1000.0, '2026-11-16T08:00:00Z']], 1000.0),
        ]);
        $this->entityManager->clear();

        self::assertSame(2, $this->repository->count([]));
        self::assertSame(5000.0, $this->findWeek(self::ATHLETE_ID, '2026-11-16')->getTotalDistance());
        self::assertSame(1000.0, $this->findWeek('67890', '2026-11-16')->getTotalDistance());
    }

    public function testDatabaseRejectsDuplicateAthleteWeek(): void
    {
        $this->entityManager->persist(new StravaWeeklyStat(self::ATHLETE_ID, new DateTimeImmutable('2026-11-16')));
        $this->entityManager->flush();

        $this->entityManager->persist(new StravaWeeklyStat(self::ATHLETE_ID, new DateTimeImmutable('2026-11-16')));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->entityManager->flush();
    }

    public function testFindLatestForAthleteReturnsNewestFirst(): void
    {
        $this->recorder->record(self::ATHLETE_ID, [
            $this->weeklyStat('2026-11-02', [], 0.0),
            $this->weeklyStat('2026-11-16', [], 0.0),
            $this->weeklyStat('2026-11-09', [], 0.0),
        ]);
        $this->recorder->record('67890', [$this->weeklyStat('2026-11-23', [], 0.0)]);
        $this->entityManager->clear();

        $latest = $this->repository->findLatestForAthlete(self::ATHLETE_ID, 2);

        self::assertCount(2, $latest);
        self::assertSame('2026-11-16', $latest[0]->getWeekStart()->format('Y-m-d'));
        self::assertSame('2026-11-09', $latest[1]->getWeekStart()->format('Y-m-d'));
    }

    public function testReplaceAllDeletesTheAthletesOldWeeksOnly(): void
    {
        $this->recorder->record(self::ATHLETE_ID, [
            $this->weeklyStat('2026-11-02', [], 0.0),
            $this->weeklyStat('2026-11-09', [['Run', 1000.0, '2026-11-09T08:00:00Z']], 1000.0),
        ]);
        $this->recorder->record('67890', [$this->weeklyStat('2026-11-09', [], 0.0)]);
        $this->entityManager->clear();

        $this->recorder->replaceAll(self::ATHLETE_ID, [
            $this->weeklyStat('2026-11-09', [['Run', 9000.0, '2026-11-09T08:00:00Z']], 9000.0),
            $this->weeklyStat('2026-11-16', [], 4500.0),
        ]);
        $this->entityManager->clear();

        $weeks = $this->repository->findLatestForAthlete(self::ATHLETE_ID, 10);
        self::assertCount(2, $weeks);
        self::assertSame('2026-11-16', $weeks[0]->getWeekStart()->format('Y-m-d'));
        self::assertSame('2026-11-09', $weeks[1]->getWeekStart()->format('Y-m-d'));
        self::assertSame(9000.0, $weeks[1]->getTotalDistance());

        // Other athletes are untouched.
        self::assertCount(1, $this->repository->findLatestForAthlete('67890', 10));
    }

    public function testFindBeforeWeekReturnsTheWeeksRightBeforeNewestFirst(): void
    {
        $this->recorder->record(self::ATHLETE_ID, [
            $this->weeklyStat('2026-10-19', [], 0.0),
            $this->weeklyStat('2026-10-26', [], 0.0),
            $this->weeklyStat('2026-11-02', [], 0.0),
            $this->weeklyStat('2026-11-09', [], 0.0),
            $this->weeklyStat('2026-11-16', [], 0.0),
        ]);
        $this->recorder->record('67890', [$this->weeklyStat('2026-11-09', [], 0.0)]);
        $this->entityManager->clear();

        $before = $this->repository->findBeforeWeek(self::ATHLETE_ID, new DateTimeImmutable('2026-11-16'), 3);

        self::assertCount(3, $before);
        self::assertSame('2026-11-09', $before[0]->getWeekStart()->format('Y-m-d'));
        self::assertSame('2026-11-02', $before[1]->getWeekStart()->format('Y-m-d'));
        self::assertSame('2026-10-26', $before[2]->getWeekStart()->format('Y-m-d'));
    }

    private function findWeek(string $athleteId, string $weekStart): StravaWeeklyStat
    {
        $stat = $this->repository->findByAthleteAndWeeks($athleteId, [$weekStart])[$weekStart] ?? null;
        self::assertInstanceOf(StravaWeeklyStat::class, $stat, 'Week ' . $weekStart . ' was not stored.');

        return $stat;
    }

    /** @param array<int, array{string, float, string}> $activities [sport type, distance, start date] */
    private function weeklyStat(string $monday, array $activities, float $fiveWeekAverage): WeeklyStat
    {
        $stat = new WeeklyStat($monday);
        foreach ($activities as [$sportType, $distance, $startDate]) {
            $stat->addStravaActivity(['sport_type' => $sportType, 'distance' => $distance, 'start_date_local' => $startDate]);
        }

        $stat->setFiveWeekAverage($fiveWeekAverage);

        return $stat;
    }
}
