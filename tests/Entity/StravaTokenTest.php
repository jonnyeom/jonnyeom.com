<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\StravaToken;
use League\OAuth2\Client\Token\AccessToken;
use PHPUnit\Framework\TestCase;

use function time;

final class StravaTokenTest extends TestCase
{
    public function testStoresTheTokenAndConvertsBack(): void
    {
        $expires = time() + 3600;
        $stored  = new StravaToken(12345, new AccessToken([
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'expires' => $expires,
        ]));

        $token = $stored->toAccessToken();

        self::assertSame(12345, $stored->getAthleteId());
        self::assertSame('access', $token->getToken());
        self::assertSame('refresh', $token->getRefreshToken());
        self::assertSame($expires, $token->getExpires());
        self::assertSame('12345', $token->getResourceOwnerId());
        self::assertFalse($token->hasExpired());
    }

    public function testUpdateRotatesTheRefreshToken(): void
    {
        $stored = new StravaToken(12345, new AccessToken([
            'access_token' => 'old-access',
            'refresh_token' => 'old-refresh',
            'expires' => time() - 60,
        ]));

        $stored->update(new AccessToken([
            'access_token' => 'new-access',
            'refresh_token' => 'new-refresh',
            'expires' => time() + 3600,
        ]));

        self::assertSame('new-access', $stored->toAccessToken()->getToken());
        self::assertSame('new-refresh', $stored->getRefreshToken());
        self::assertFalse($stored->toAccessToken()->hasExpired());
    }

    public function testUpdateKeepsTheRefreshTokenWhenNoneIsReturned(): void
    {
        $stored = new StravaToken(12345, new AccessToken([
            'access_token' => 'old-access',
            'refresh_token' => 'kept-refresh',
            'expires' => time() + 60,
        ]));

        $stored->update(new AccessToken(['access_token' => 'new-access', 'expires' => time() + 3600]));

        self::assertSame('kept-refresh', $stored->getRefreshToken());
    }

    public function testTokenWithoutExpiryIsTreatedAsExpired(): void
    {
        $stored = new StravaToken(12345, new AccessToken(['access_token' => 'access', 'refresh_token' => 'refresh']));

        self::assertTrue($stored->toAccessToken()->hasExpired());
    }
}
