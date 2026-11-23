<?php

declare(strict_types=1);

namespace App\Tests\Service\Strava;

use App\Service\Strava\AthleteGuard;
use League\OAuth2\Client\Token\AccessToken;
use PHPUnit\Framework\TestCase;

final class AthleteGuardTest extends TestCase
{
    public function testAllowsOnlyConfiguredAthlete(): void
    {
        $guard = new AthleteGuard('12345');

        $this->assertTrue($guard->isAllowed('12345'));
        $this->assertTrue($guard->isAllowed(12345));
        $this->assertFalse($guard->isAllowed('99999'));
        $this->assertFalse($guard->isAllowed('123456'));
        $this->assertFalse($guard->isAllowed(null));
        $this->assertFalse($guard->isAllowed(['12345']));
    }

    public function testFailsClosedWhenNotConfigured(): void
    {
        $guard = new AthleteGuard('  ');

        $this->assertFalse($guard->isAllowed(''));
        $this->assertFalse($guard->isAllowed('12345'));
    }

    public function testResolvesAthleteIdFromTokenResponse(): void
    {
        $token = new AccessToken(['access_token' => 'abc', 'athlete' => ['id' => 12345]]);

        $this->assertSame('12345', AthleteGuard::resolveAthleteId($token));
    }

    public function testResolvesAthleteIdFromResourceOwner(): void
    {
        $token = new AccessToken(['access_token' => 'abc', 'resource_owner_id' => 777]);

        $this->assertSame('777', AthleteGuard::resolveAthleteId($token));
    }

    public function testReturnsNullWithoutAthlete(): void
    {
        $token = new AccessToken(['access_token' => 'abc']);

        $this->assertNull(AthleteGuard::resolveAthleteId($token));
    }
}
