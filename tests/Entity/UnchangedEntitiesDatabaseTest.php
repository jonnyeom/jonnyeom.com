<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\StravaToken;
use App\Entity\StravaWeeklyPlan;
use App\Entity\StravaWeeklyStat;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Client\Token\AccessToken;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Entities that are only read must not be written back: their properties have to match the types Doctrine reads.
 */
#[Group('database')]
final class UnchangedEntitiesDatabaseTest extends KernelTestCase
{
    public function testReadingEntitiesDoesNotChangeThem(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $monday = new DateTimeImmutable('2035-01-01');
            $stat   = new StravaWeeklyStat('9876543210', $monday);
            $stat->update(1.5, 2.5, ['2035-01-01' => 1.5], 1);

            $entities = [
                new StravaToken('9876543210', new AccessToken(['access_token' => 'a', 'refresh_token' => 'r', 'expires' => 1])),
                new StravaWeeklyPlan('9876543210', $monday),
                $stat,
            ];
            foreach ($entities as $entity) {
                $entityManager->persist($entity);
            }

            $entityManager->flush();
            $entityManager->clear();

            $unitOfWork = $entityManager->getUnitOfWork();
            foreach ($entities as $entity) {
                $read = $entityManager->find($entity::class, $entity->getId());
                self::assertNotNull($read);
                self::assertSame('9876543210', $read->getAthleteId());

                $unitOfWork->computeChangeSets();
                self::assertSame([], $unitOfWork->getEntityChangeSet($read), $entity::class);
            }
        } finally {
            $connection->rollBack();
        }
    }
}
