<?php

declare(strict_types=1);

namespace App\Service\Strava;

use League\OAuth2\Client\Token\AccessTokenInterface;
use League\OAuth2\Client\Token\ResourceOwnerAccessTokenInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

use function hash_equals;
use function is_array;
use function is_int;
use function is_string;
use function trim;

/**
 * Restricts the Strava integration to a single athlete (the site owner).
 *
 * The allowed athlete ID comes from the STRAVA_ATHLETE_ID environment variable.
 * If it is empty, no athlete is allowed (fail closed).
 */
final readonly class AthleteGuard
{
    /**
     * The session only stores the verified athlete ID; the Strava token itself lives in the
     * database (see StravaTokenManager).
     */
    public const string SESSION_KEY = 'strava_athlete_id';

    /** Session key used before the token moved to the database; only ever removed now. */
    public const string LEGACY_TOKEN_SESSION_KEY = 'access_token';

    private string $allowedAthleteId;

    public function __construct(string $allowedAthleteId)
    {
        $this->allowedAthleteId = trim($allowedAthleteId);
    }

    public function isAllowed(mixed $athleteId): bool
    {
        if ($this->allowedAthleteId === '' || ! (is_int($athleteId) || is_string($athleteId))) {
            return false;
        }

        return hash_equals($this->allowedAthleteId, trim((string) $athleteId));
    }

    /** Whether the session belongs to the allowed athlete (who completed the Strava login). */
    public function isConnected(SessionInterface $session): bool
    {
        return $this->isAllowed($session->get(self::SESSION_KEY));
    }

    public function connect(SessionInterface $session): void
    {
        $session->set(self::SESSION_KEY, $this->allowedAthleteId);
        $session->remove(self::LEGACY_TOKEN_SESSION_KEY);
    }

    public function disconnect(SessionInterface $session): void
    {
        $session->remove(self::SESSION_KEY);
        $session->remove(self::LEGACY_TOKEN_SESSION_KEY);
    }

    public function getAllowedAthleteId(): string
    {
        return $this->allowedAthleteId;
    }

    /**
     * Reads the athlete ID from a Strava token.
     *
     * Strava returns the athlete in the authorization_code token response (`athlete.id`),
     * but not on refresh, so this should be called on the initial token exchange.
     */
    public static function resolveAthleteId(AccessTokenInterface $accessToken): string|null
    {
        if ($accessToken instanceof ResourceOwnerAccessTokenInterface) {
            $ownerId = $accessToken->getResourceOwnerId();
            if ($ownerId !== null && $ownerId !== '') {
                return (string) $ownerId;
            }
        }

        $athlete = $accessToken->getValues()['athlete'] ?? null;
        if (is_array($athlete) && isset($athlete['id']) && (is_int($athlete['id']) || is_string($athlete['id']))) {
            return (string) $athlete['id'];
        }

        return null;
    }
}
