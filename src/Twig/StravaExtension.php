<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Strava\AthleteGuard;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Attribute\AsTwigFunction;

final class StravaExtension
{
    public function __construct(
        private readonly AthleteGuard $athleteGuard,
        private readonly RequestStack $requestStack,
    ) {
    }

    /** Whether the visitor is connected to Strava as the allowed athlete. */
    #[AsTwigFunction('strava_connected')]
    public function isConnected(): bool
    {
        $request = $this->requestStack->getCurrentRequest();

        // Without a session cookie the visitor can't be connected; don't start a session for them.
        if ($request === null || ! $request->hasPreviousSession()) {
            return false;
        }

        return $this->athleteGuard->isConnected($request->getSession());
    }
}
