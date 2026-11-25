<?php

declare(strict_types=1);

namespace App\Service\Strava;

use League\OAuth2\Client\Token\AccessTokenInterface;
use League\OAuth2\Client\Token\ResourceOwnerAccessTokenInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

use function ctype_digit;
use function filter_var;
use function is_array;
use function is_int;
use function is_string;
use function trim;

use const FILTER_VALIDATE_INT;

/**
 * Restricts the Strava integration to a single athlete (the site owner).
 *
 * The allowed athlete ID comes from the STRAVA_ATHLETE_ID environment variable.
 * If it is empty or not an athlete ID, no athlete is allowed (fail closed).
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

    private int|null $allowedAthleteId;

    public function __construct(string $allowedAthleteId)
    {
        $this->allowedAthleteId = self::parseAthleteId($allowedAthleteId);
    }

    public function isAllowed(mixed $athleteId): bool
    {
        // Sessions from before the ID was an int hold it as a string.
        return $this->allowedAthleteId !== null && self::parseAthleteId($athleteId) === $this->allowedAthleteId;
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

    /** Null when no athlete is allowed. */
    public function getAllowedAthleteId(): int|null
    {
        return $this->allowedAthleteId;
    }

    /**
     * Reads the athlete ID from a Strava token.
     *
     * Strava returns the athlete in the authorization_code token response (`athlete.id`),
     * but not on refresh, so this should be called on the initial token exchange.
     */
    public static function resolveAthleteId(AccessTokenInterface $accessToken): int|null
    {
        if ($accessToken instanceof ResourceOwnerAccessTokenInterface) {
            $ownerId = $accessToken->getResourceOwnerId();
            if ($ownerId !== null && $ownerId !== '') {
                return self::parseAthleteId($ownerId);
            }
        }

        $athlete = $accessToken->getValues()['athlete'] ?? null;

        return is_array($athlete) ? self::parseAthleteId($athlete['id'] ?? null) : null;
    }

    /** Strict: only plain digits, so a malformed ID is never read as another athlete's. */
    private static function parseAthleteId(mixed $athleteId): int|null
    {
        if (is_string($athleteId)) {
            $athleteId = trim($athleteId);
            if (! ctype_digit($athleteId)) {
                return null;
            }
        } elseif (! is_int($athleteId)) {
            return null;
        }

        $parsed = filter_var($athleteId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $parsed === false ? null : $parsed;
    }
}
