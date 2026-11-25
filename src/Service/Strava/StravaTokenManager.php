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
use Psr\Log\LoggerInterface;

use function date;

/**
 * Stores the athlete's Strava token in the database and refreshes it.
 */
class StravaTokenManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StravaTokenRepository $repository,
        private readonly ClientProvider $clientProvider,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function store(int $athleteId, AccessTokenInterface $token): void
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
    public function getValidToken(int $athleteId): AccessToken
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
    public function refresh(int $athleteId): AccessToken
    {
        $stored = $this->getStoredToken($athleteId);

        try {
            $refreshed = $this->clientProvider->refreshAccessToken($stored->getRefreshToken());
        } catch (IdentityProviderException $e) {
            // Logged here so both the stats page and app:strava:refresh-stats leave a trace.
            // Strava's error body does not contain the tokens; never log the tokens themselves.
            $this->logger->error('Strava token refresh failed.', [
                'athlete_id' => $athleteId,
                'error' => $e->getMessage(),
                'response' => $e->getResponseBody(),
            ]);

            throw $e;
        }

        $stored->update($refreshed);
        $this->entityManager->flush();

        $this->logger->info('Strava token refreshed.', [
            'athlete_id' => $athleteId,
            'expires_at' => date('c', $stored->getExpiresAt()),
        ]);

        return $stored->toAccessToken();
    }

    /** @throws AccessTokenMissing */
    private function getStoredToken(int $athleteId): StravaToken
    {
        $stored = $this->repository->findForAthlete($athleteId);
        if (! $stored instanceof StravaToken) {
            throw new AccessTokenMissing();
        }

        return $stored;
    }
}
