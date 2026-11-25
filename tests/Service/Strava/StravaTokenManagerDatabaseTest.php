<?php

declare(strict_types=1);

namespace App\Tests\Service\Strava;

use App\Repository\StravaTokenRepository;
use App\Service\Strava\StravaTokenManager;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Client\Token\AccessToken;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function time;

/**
 * Integration tests against a real database (Postgres in CI, see .github/workflows/ci.yml).
 */
#[Group('database')]
final class StravaTokenManagerDatabaseTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private StravaTokenRepository $repository;

    private StravaTokenManager $manager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $entityManager = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $repository = $container->get(StravaTokenRepository::class);
        self::assertInstanceOf(StravaTokenRepository::class, $repository);
        $this->repository = $repository;

        $manager = $container->get(StravaTokenManager::class);
        self::assertInstanceOf(StravaTokenManager::class, $manager);
        $this->manager = $manager;

        $this->entityManager->getConnection()->executeStatement('DELETE FROM strava_token');
    }

    public function testStoredTokenIsReadBack(): void
    {
        $expires = time() + 3600;
        $this->manager->store(12345, new AccessToken([
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'expires' => $expires,
        ]));
        $this->entityManager->clear();

        $token = $this->manager->getValidToken(12345);

        self::assertSame('access', $token->getToken());
        self::assertSame('refresh', $token->getRefreshToken());
        self::assertSame($expires, $token->getExpires());
    }

    public function testStoringAgainUpdatesTheSingleRowPerAthlete(): void
    {
        $this->manager->store(12345, new AccessToken(['access_token' => 'first', 'refresh_token' => 'r1', 'expires' => time() + 60]));
        $this->entityManager->clear();
        $this->manager->store(12345, new AccessToken(['access_token' => 'second', 'refresh_token' => 'r2', 'expires' => time() + 3600]));
        $this->manager->store(9876543210, new AccessToken(['access_token' => 'other', 'refresh_token' => 'r3', 'expires' => time() + 3600]));
        $this->entityManager->clear();

        self::assertSame(2, $this->repository->count([]));
        self::assertSame('r2', $this->repository->findForAthlete(12345)?->getRefreshToken());
        self::assertSame('second', $this->manager->getValidToken(12345)->getToken());
    }
}
