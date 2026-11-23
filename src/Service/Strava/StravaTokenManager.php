<?php

declare(strict_types=1);

namespace App\Service\Strava;

use App\Entity\StravaToken;
use App\Exception\Strava\AccessTokenMissing;
use App\Repository\StravaTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Token\AccessTokenInterface;

/**
 * Stores the athlete's Strava token in the database and refreshes it.
 */
class StravaTokenManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StravaTokenRepository $repository,
        private readonly ClientProvider $clientProvider,
    ) {
    }

    public function store(string $athleteId, AccessTokenInterface $token): void
    {
        $stored = $this->repository->findForAthlete($athleteId);
        if ($stored instanceof StravaToken) {
            $stored->update($token);
        } else {
            $this->entityManager->persist(new StravaToken($athleteId, $token));
        }

        $this->entityManager->flush();
    }

    /**
     * Returns a usable access token, refreshing it first if it has expired.
     *
     * @throws AccessTokenMissing
     * @throws IdentityProviderException
     */
    public function getValidToken(string $athleteId): AccessToken
    {
        $token = $this->getStoredToken($athleteId)->toAccessToken();

        return $token->hasExpired() ? $this->refresh($athleteId) : $token;
    }

    /**
     * Exchanges the stored refresh token for a new access token (and rotated refresh token)
     * and stores them.
     *
     * @throws AccessTokenMissing
     * @throws IdentityProviderException
     */
    public function refresh(string $athleteId): AccessToken
    {
        $stored = $this->getStoredToken($athleteId);

        $stored->update($this->clientProvider->refreshAccessToken($stored->getRefreshToken()));
        $this->entityManager->flush();

        return $stored->toAccessToken();
    }

    /** @throws AccessTokenMissing */
    private function getStoredToken(string $athleteId): StravaToken
    {
        $stored = $this->repository->findForAthlete($athleteId);
        if (! $stored instanceof StravaToken) {
            throw new AccessTokenMissing();
        }

        return $stored;
    }
}
