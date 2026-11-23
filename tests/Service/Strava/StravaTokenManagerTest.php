<?php

declare(strict_types=1);

namespace App\Tests\Service\Strava;

use App\Entity\StravaToken;
use App\Exception\Strava\AccessTokenMissing;
use App\Repository\StravaTokenRepository;
use App\Service\Strava\ClientProvider;
use App\Service\Strava\StravaTokenManager;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessToken;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

use function time;

final class StravaTokenManagerTest extends TestCase
{
    private const string ATHLETE_ID = '12345';

    /** @var MockObject&EntityManagerInterface */
    private MockObject $entityManager;

    /** @var MockObject&StravaTokenRepository */
    private MockObject $repository;

    /** @var MockObject&ClientProvider */
    private MockObject $clientProvider;

    private StravaTokenManager $manager;

    protected function setUp(): void
    {
        $this->entityManager  = $this->createMock(EntityManagerInterface::class);
        $this->repository     = $this->createMock(StravaTokenRepository::class);
        $this->clientProvider = $this->createMock(ClientProvider::class);

        $this->manager = new StravaTokenManager($this->entityManager, $this->repository, $this->clientProvider);
    }

    public function testStoreCreatesATokenForANewAthlete(): void
    {
        $this->repository->method('findForAthlete')->willReturn(null);
        $this->entityManager
            ->expects($this->once())
            ->method('persist')
            ->with($this->callback(static fn (StravaToken $token): bool => $token->getAthleteId() === self::ATHLETE_ID
                && $token->getRefreshToken() === 'refresh'));
        $this->entityManager->expects($this->once())->method('flush');

        $this->manager->store(self::ATHLETE_ID, $this->token('access', 'refresh', time() + 3600));
    }

    public function testStoreUpdatesTheExistingToken(): void
    {
        $existing = new StravaToken(self::ATHLETE_ID, $this->token('old', 'old-refresh', time() - 60));
        $this->repository->method('findForAthlete')->willReturn($existing);
        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $this->manager->store(self::ATHLETE_ID, $this->token('new', 'new-refresh', time() + 3600));

        self::assertSame('new', $existing->toAccessToken()->getToken());
        self::assertSame('new-refresh', $existing->getRefreshToken());
    }

    public function testGetValidTokenReturnsAnUnexpiredTokenWithoutCallingStrava(): void
    {
        $this->repository
            ->method('findForAthlete')
            ->willReturn(new StravaToken(self::ATHLETE_ID, $this->token('access', 'refresh', time() + 3600)));
        $this->clientProvider->expects($this->never())->method('refreshAccessToken');

        self::assertSame('access', $this->manager->getValidToken(self::ATHLETE_ID)->getToken());
    }

    public function testGetValidTokenRefreshesAnExpiredToken(): void
    {
        $stored = new StravaToken(self::ATHLETE_ID, $this->token('expired', 'refresh-me', time() - 60));
        $this->repository->method('findForAthlete')->willReturn($stored);
        $this->clientProvider
            ->expects($this->once())
            ->method('refreshAccessToken')
            ->with('refresh-me')
            ->willReturn($this->token('fresh', 'rotated', time() + 3600));
        $this->entityManager->expects($this->once())->method('flush');

        self::assertSame('fresh', $this->manager->getValidToken(self::ATHLETE_ID)->getToken());
        self::assertSame('rotated', $stored->getRefreshToken());
    }

    public function testRefreshAlwaysCallsStravaAndStoresTheRotatedToken(): void
    {
        $stored = new StravaToken(self::ATHLETE_ID, $this->token('still-valid', 'refresh-me', time() + 3600));
        $this->repository->method('findForAthlete')->willReturn($stored);
        $this->clientProvider
            ->expects($this->once())
            ->method('refreshAccessToken')
            ->with('refresh-me')
            ->willReturn($this->token('fresh', 'rotated', time() + 21600));
        $this->entityManager->expects($this->once())->method('flush');

        $token = $this->manager->refresh(self::ATHLETE_ID);

        self::assertSame('fresh', $token->getToken());
        self::assertSame('rotated', $token->getRefreshToken());
        self::assertSame('rotated', $stored->getRefreshToken());
    }

    public function testRefreshWithoutStoredTokenThrows(): void
    {
        $this->repository->method('findForAthlete')->willReturn(null);
        $this->clientProvider->expects($this->never())->method('refreshAccessToken');

        $this->expectException(AccessTokenMissing::class);

        $this->manager->refresh(self::ATHLETE_ID);
    }

    public function testRejectedRefreshKeepsTheStoredToken(): void
    {
        $stored = new StravaToken(self::ATHLETE_ID, $this->token('access', 'refresh-me', time() - 60));
        $this->repository->method('findForAthlete')->willReturn($stored);
        $this->clientProvider
            ->method('refreshAccessToken')
            ->willThrowException(new IdentityProviderException('Bad Request', 400, []));
        $this->entityManager->expects($this->never())->method('flush');

        try {
            $this->manager->refresh(self::ATHLETE_ID);
            self::fail('Expected IdentityProviderException.');
        } catch (IdentityProviderException) {
            // Expected.
        }

        self::assertSame('refresh-me', $stored->getRefreshToken());
    }

    public function testHasToken(): void
    {
        $this->repository
            ->method('findForAthlete')
            ->willReturnMap([
                [self::ATHLETE_ID, new StravaToken(self::ATHLETE_ID, $this->token('a', 'r', time() + 60))],
                ['99999', null],
            ]);

        self::assertTrue($this->manager->hasToken(self::ATHLETE_ID));
        self::assertFalse($this->manager->hasToken('99999'));
    }

    private function token(string $access, string $refresh, int $expires): AccessToken
    {
        return new AccessToken(['access_token' => $access, 'refresh_token' => $refresh, 'expires' => $expires]);
    }
}
