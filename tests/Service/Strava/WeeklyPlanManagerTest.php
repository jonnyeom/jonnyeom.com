<?php

declare(strict_types=1);

namespace App\Tests\Service\Strava;

use App\Entity\StravaWeeklyStat;
use App\Exception\Strava\InvalidPlan;
use App\Repository\StravaWeeklyPlanRepository;
use App\Service\Strava\WeeklyPlanManager;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function array_column;

/** Integration tests against a real database (Postgres in CI, see .github/workflows/ci.yml). */
#[Group('database')]
final class WeeklyPlanManagerTest extends KernelTestCase
{
    private const string ATHLETE_ID = '12345';

    private EntityManagerInterface $entityManager;

    private StravaWeeklyPlanRepository $repository;

    private WeeklyPlanManager $manager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $entityManager = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $repository = $container->get(StravaWeeklyPlanRepository::class);
        self::assertInstanceOf(StravaWeeklyPlanRepository::class, $repository);
        $this->repository = $repository;

        $manager = $container->get(WeeklyPlanManager::class);
        self::assertInstanceOf(WeeklyPlanManager::class, $manager);
        $this->manager = $manager;

        $this->entityManager->getConnection()->executeStatement('DELETE FROM strava_weekly_plan');
        $this->entityManager->getConnection()->executeStatement('DELETE FROM strava_weekly_stat');
    }

    public function testSaveCreatesThenReplacesTheWeeksPlan(): void
    {
        $week = new DateTimeImmutable('2026-11-23');

        $this->manager->save($week, ['Mon' => ['Run' => 5], 'Sat' => ['Bike' => 30]], 'Build week');
        $view = $this->manager->save($week, ['Tue' => ['Swim' => 1]], null);
        $this->entityManager->clear();

        $plan = $this->repository->findOneByAthleteAndWeek(self::ATHLETE_ID, $week);
        self::assertNotNull($plan);
        self::assertSame(['Tue' => ['Swim' => 1.0]], $plan->getDays());
        self::assertNull($plan->getNotes());
        self::assertSame('2026-11-23', $view['weekStart']);
        self::assertSame(['Tue' => ['Swim' => 1.0]], $view['days']);
        self::assertCount(1, $this->repository->findAll());
    }

    public function testSavingAnEmptyPlanRemovesIt(): void
    {
        $week = new DateTimeImmutable('2026-11-23');
        $this->manager->save($week, ['Mon' => ['Run' => 5]], null);

        $this->manager->save($week, [], '');
        $this->entityManager->clear();

        self::assertNull($this->repository->findOneByAthleteAndWeek(self::ATHLETE_ID, $week));
    }

    public function testInvalidPlanIsNotStored(): void
    {
        try {
            $this->manager->save(new DateTimeImmutable('2026-11-23'), ['Mon' => ['Run' => -5]], null);
            self::fail('Expected an InvalidPlan exception');
        } catch (InvalidPlan) {
        }

        self::assertSame([], $this->repository->findAll());
    }

    public function testGetWeeksListsEveryWeekWithItsPlanAndActualMileage(): void
    {
        $this->manager->save(new DateTimeImmutable('2026-11-23'), ['Mon' => ['Run' => 5]], 'Easy');

        $stat = new StravaWeeklyStat(self::ATHLETE_ID, new DateTimeImmutable('2026-11-16'));
        $stat->update(16093.4, 8046.7, ['Mon' => 16093.4], 1);
        $this->entityManager->persist($stat);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $weeks = $this->manager->getWeeks(new DateTimeImmutable('2026-11-16'), 3);

        self::assertSame(['2026-11-16', '2026-11-23', '2026-11-30'], array_column($weeks, 'weekStart'));

        // Actual mileage, no plan.
        self::assertSame([], $weeks[0]['days']);
        self::assertSame(['totalMiles' => 10.0, 'fiveWeekAverage' => 5.0], $weeks[0]['actual']);

        // Plan, no actual mileage yet.
        self::assertSame(['Mon' => ['Run' => 5.0]], $weeks[1]['days']);
        self::assertSame('Easy', $weeks[1]['notes']);
        self::assertNull($weeks[1]['actual']);

        // Neither.
        self::assertSame([], $weeks[2]['days']);
        self::assertNull($weeks[2]['actual']);
    }

    public function testSaveManySavesEveryWeek(): void
    {
        $this->manager->save(new DateTimeImmutable('2026-11-30'), ['Mon' => ['Run' => 5]], null);

        $saved = $this->manager->saveMany([
            '2026-11-30' => ['days' => [], 'notes' => null],
            '2026-11-23' => ['days' => ['Sat' => ['Run' => 10.0]], 'notes' => 'Long run'],
        ]);
        $this->entityManager->clear();

        self::assertSame(2, $saved);
        $plans = $this->repository->findAll();
        self::assertCount(1, $plans); // 11.30 was cleared.
        self::assertSame('2026-11-23', $plans[0]->getWeekStart()->format('Y-m-d'));
        self::assertSame('Long run', $plans[0]->getNotes());
    }

    public function testParseWeekStart(): void
    {
        self::assertSame('2026-11-23', WeeklyPlanManager::parseWeekStart('2026-11-23')->format('Y-m-d'));

        $this->expectException(InvalidPlan::class);
        WeeklyPlanManager::parseWeekStart('2026-02-30');
    }
}
