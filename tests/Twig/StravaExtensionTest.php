<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Service\Strava\AthleteGuard;
use App\Twig\StravaExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class StravaExtensionTest extends TestCase
{
    public function testConnectedAsTheAllowedAthlete(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set(AthleteGuard::SESSION_KEY, '12345');

        self::assertTrue($this->extension($this->requestWithSession($session))->isConnected());
    }

    public function testAnotherAthleteIsNotConnected(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set(AthleteGuard::SESSION_KEY, '99999');

        self::assertFalse($this->extension($this->requestWithSession($session))->isConnected());
    }

    public function testVisitorWithoutASessionCookieIsNotConnectedAndNoSessionIsStarted(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);

        self::assertFalse($this->extension($request)->isConnected());
        self::assertFalse($session->isStarted());
    }

    public function testNoRequest(): void
    {
        self::assertFalse((new StravaExtension(new AthleteGuard('12345'), new RequestStack()))->isConnected());
    }

    private function requestWithSession(Session $session): Request
    {
        $request = new Request();
        $request->setSession($session);
        $request->cookies->set($session->getName(), 'session-id');

        return $request;
    }

    private function extension(Request $request): StravaExtension
    {
        $requestStack = new RequestStack();
        $requestStack->push($request);

        return new StravaExtension(new AthleteGuard('12345'), $requestStack);
    }
}
