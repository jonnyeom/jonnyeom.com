<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\StravaTokenRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Token\AccessTokenInterface;

use function time;

/**
 * The Strava OAuth token of an athlete.
 *
 * Strava rotates refresh tokens (the previous one stops working once a new one is issued), so this
 * row is the single source of truth, shared by the web app and the console command.
 */
#[ORM\Entity(repositoryClass: StravaTokenRepository::class)]
#[ORM\Table(name: 'strava_token')]
#[ORM\UniqueConstraint(name: 'uniq_strava_token_athlete', columns: ['athlete_id'])]
class StravaToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private int|null $id = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $accessToken;

    #[ORM\Column(type: Types::TEXT)]
    private string $refreshToken;

    /** Unix timestamp at which the access token expires. */
    #[ORM\Column(type: Types::INTEGER)]
    private int $expiresAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    /** An int, as DBAL reads BIGINT columns: a string would look changed on every flush. */
    #[ORM\Column(type: Types::BIGINT)]
    private int $athleteId;

    public function __construct(
        string $athleteId,
        AccessTokenInterface $token,
    ) {
        $this->athleteId    = (int) $athleteId;
        $this->refreshToken = '';
        $this->update($token);
    }

    public function update(AccessTokenInterface $token): void
    {
        $this->accessToken = $token->getToken();

        // Keep the current refresh token if Strava did not send a new one.
        $this->refreshToken = $token->getRefreshToken() ?? $this->refreshToken;

        // Without an expiry, treat the access token as expired so it gets refreshed.
        $this->expiresAt = $token->getExpires() ?? time() - 1;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function toAccessToken(): AccessToken
    {
        return new AccessToken([
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'expires' => $this->expiresAt,
            'resource_owner_id' => $this->getAthleteId(),
        ]);
    }

    public function getId(): int|null
    {
        return $this->id;
    }

    public function getAthleteId(): string
    {
        return (string) $this->athleteId;
    }

    public function getRefreshToken(): string
    {
        return $this->refreshToken;
    }

    public function getExpiresAt(): int
    {
        return $this->expiresAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
