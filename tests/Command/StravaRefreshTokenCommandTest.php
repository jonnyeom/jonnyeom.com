<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\StravaRefreshTokenCommand;
use App\Exception\Strava\AccessTokenMissing;
use App\Service\Strava\AthleteGuard;
use App\Service\Strava\StravaTokenManager;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessToken;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

use function time;

final class StravaRefreshTokenCommandTest extends TestCase
{
    /** @var MockObject&StravaTokenManager */
    private MockObject $tokenManager;

    protected function setUp(): void
    {
        $this->tokenManager = $this->createMock(StravaTokenManager::class);
    }

    public function testRefreshesTheAllowedAthletesToken(): void
    {
        $this->tokenManager
            ->expects($this->once())
            ->method('refresh')
            ->with('12345')
            ->willReturn($this->token());
        $this->tokenManager->expects($this->never())->method('getValidToken');

        $tester = $this->runCommand('12345');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('The Strava token for athlete 12345 is valid until', $tester->getDisplay());
        self::assertStringNotContainsString('secret-access', $tester->getDisplay());
        self::assertStringNotContainsString('secret-refresh', $tester->getDisplay());
    }

    public function testIfExpiredOnlyRefreshesWhenNeeded(): void
    {
        $this->tokenManager->expects($this->never())->method('refresh');
        $this->tokenManager
            ->expects($this->once())
            ->method('getValidToken')
            ->with('12345')
            ->willReturn($this->token());

        $tester = $this->runCommand('12345', ['--if-expired' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testFailsWithoutAthleteId(): void
    {
        $this->tokenManager->expects($this->never())->method('refresh');

        $tester = $this->runCommand('');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('STRAVA_ATHLETE_ID is not set', $tester->getDisplay());
    }

    public function testFailsWhenNoTokenIsStored(): void
    {
        $this->tokenManager->method('refresh')->willThrowException(new AccessTokenMissing());

        $tester = $this->runCommand('12345');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('No Strava token is stored', $tester->getDisplay());
    }

    public function testFailsWhenStravaRejectsTheRefresh(): void
    {
        $this->tokenManager
            ->method('refresh')
            ->willThrowException(new IdentityProviderException('Bad Request', 400, []));

        $tester = $this->runCommand('12345');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Strava rejected the refresh', $tester->getDisplay());
    }

    /** @param array<string, mixed> $input */
    private function runCommand(string $allowedAthleteId, array $input = []): CommandTester
    {
        $tester = new CommandTester(new StravaRefreshTokenCommand($this->tokenManager, new AthleteGuard($allowedAthleteId)));
        $tester->execute($input);

        return $tester;
    }

    private function token(): AccessToken
    {
        return new AccessToken([
            'access_token' => 'secret-access',
            'refresh_token' => 'secret-refresh',
            'expires' => time() + 21600,
        ]);
    }
}
