<?php

declare(strict_types=1);

namespace App\Tests\Service\Strava;

use App\Service\Strava\AthleteGuard;
use League\OAuth2\Client\Token\AccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

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

    #[DataProvider('invalidAthleteIds')]
    public function testFailsClosedWhenConfiguredWithAnInvalidId(string $invalid): void
    {
        $guard = new AthleteGuard($invalid);

        self::assertNull($guard->getAllowedAthleteId());
        self::assertFalse($guard->isAllowed($invalid));
        self::assertFalse($guard->isAllowed(0));
        self::assertFalse($guard->isAllowed(null));
    }

    #[DataProvider('invalidAthleteIds')]
    public function testAnInvalidIdIsNeverReadAsTheAllowedAthlete(string $invalid): void
    {
        self::assertFalse((new AthleteGuard('12345'))->isAllowed($invalid));
        self::assertNull(AthleteGuard::resolveAthleteId(new AccessToken(['access_token' => 'abc', 'athlete' => ['id' => $invalid]])));
    }

    /** @return array<string, array{string}> */
    public static function invalidAthleteIds(): array
    {
        return [
            'empty' => [''],
            'text' => ['abc'],
            'trailing text' => ['12345abc'],
            'zero' => ['0'],
            'negative' => ['-12345'],
            'signed' => ['+12345'],
            'decimal' => ['12345.0'],
            'exponent' => ['12345e0'],
            'leading zero' => ['012345'],
            'larger than 64 bits' => ['99999999999999999999'],
        ];
    }

    /** Sessions from before the ID was an int. */
    public function testASessionWithTheIdAsAStringStaysConnected(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set(AthleteGuard::SESSION_KEY, '12345');

        self::assertTrue((new AthleteGuard('12345'))->isConnected($session));
    }

    public function testConnectStoresOnlyTheAthleteIdInTheSession(): void
    {
        $guard   = new AthleteGuard('12345');
        $session = new Session(new MockArraySessionStorage());
        $session->set(AthleteGuard::LEGACY_TOKEN_SESSION_KEY, new AccessToken(['access_token' => 'old']));

        self::assertFalse($guard->isConnected($session));

        $guard->connect($session);

        self::assertTrue($guard->isConnected($session));
        self::assertSame(['strava_athlete_id' => 12345], $session->all());
    }

    public function testDisconnectClearsTheSession(): void
    {
        $guard   = new AthleteGuard('12345');
        $session = new Session(new MockArraySessionStorage());
        $guard->connect($session);
        $session->set(AthleteGuard::LEGACY_TOKEN_SESSION_KEY, new AccessToken(['access_token' => 'old']));

        $guard->disconnect($session);

        self::assertFalse($guard->isConnected($session));
        self::assertSame([], $session->all());
    }

    public function testAnotherAthleteInTheSessionIsNotConnected(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set(AthleteGuard::SESSION_KEY, '99999');

        self::assertFalse((new AthleteGuard('12345'))->isConnected($session));
    }

    public function testResolvesAthleteIdFromTokenResponse(): void
    {
        $token = new AccessToken(['access_token' => 'abc', 'athlete' => ['id' => 12345]]);

        $this->assertSame(12345, AthleteGuard::resolveAthleteId($token));
    }

    public function testResolvesAthleteIdFromResourceOwner(): void
    {
        $token = new AccessToken(['access_token' => 'abc', 'resource_owner_id' => 777]);

        $this->assertSame(777, AthleteGuard::resolveAthleteId($token));
    }

    public function testReturnsNullWithoutAthlete(): void
    {
        $token = new AccessToken(['access_token' => 'abc']);

        $this->assertNull(AthleteGuard::resolveAthleteId($token));
    }
}
