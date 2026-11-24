<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\Strava\AccessTokenMissing;
use App\Service\Strava\StravaDataProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

use function count;
use function max;
use function min;
use function sprintf;

/**
 * Endpoints used by the strava-stats Stimulus controller (assets/controllers/strava_stats_controller.js).
 */
#[Route('/strava/stats')]
class StravaStatsController extends AbstractController
{
    public const int DEFAULT_WEEKS = 20;

    private const int MAX_WEEKS = 520;

    public function __construct(
        private readonly StravaDataProvider $stravaDataProvider,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The weekly stats table (HTML fragment), read from the database only.
     */
    #[Route('', name: 'strava_stats_table', methods: ['GET'])]
    public function table(Request $request): Response
    {
        $weeks = max(1, min(self::MAX_WEEKS, $request->query->getInt('weeks', self::DEFAULT_WEEKS)));

        try {
            $weeklyStats = $this->stravaDataProvider->getStoredWeeklyStats($weeks);
        } catch (Throwable $e) {
            [$message, $status] = $this->describeError($e);

            return $this->render('strava/_weekly_stats_error.html.twig', ['message' => $message], new Response(status: $status));
        }

        return $this->render('strava/_weekly_stats_table.html.twig', [
            'weeklyStats' => $weeklyStats,
            'weeks' => $weeks,
            // Fewer stored weeks than requested: the whole stored history is shown.
            'hasMore' => count($weeklyStats) >= $weeks,
        ]);
    }

    /**
     * Fetches the latest week(s) from Strava and stores them.
     */
    #[Route('/refresh', name: 'strava_stats_refresh', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        return $this->runStravaAction($request, function (): string {
            $this->stravaDataProvider->refresh();

            return 'Updated the latest week with fresh Strava data';
        });
    }

    /**
     * Deletes the stored weeks and reloads the whole history from Strava.
     */
    #[Route('/reset', name: 'strava_stats_reset', methods: ['POST'])]
    public function reset(Request $request): JsonResponse
    {
        return $this->runStravaAction($request, function (): string {
            return sprintf('Reloaded all %d weeks from Strava', count($this->stravaDataProvider->reset()));
        });
    }

    /** @param callable(): string $action */
    private function runStravaAction(Request $request, callable $action): JsonResponse
    {
        // Only the page's own fetch() sends this header; a cross-site form post cannot.
        if ($request->headers->get('X-Requested-With') !== 'XMLHttpRequest') {
            return new JsonResponse(['error' => 'Bad request.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            return new JsonResponse(['message' => $action()]);
        } catch (Throwable $e) {
            [$message, $status] = $this->describeError($e);

            return new JsonResponse(['error' => $message], $status);
        }
    }

    /** @return array{string, int} */
    private function describeError(Throwable $e): array
    {
        if ($e instanceof AccessTokenMissing) {
            return ['No access token. Please connect to Strava.', Response::HTTP_UNAUTHORIZED];
        }

        // Token refresh failures are logged by StravaTokenManager.
        if ($e instanceof IdentityProviderException) {
            return ['Strava access error: ' . $e->getMessage(), Response::HTTP_BAD_GATEWAY];
        }

        $this->logger->error('Strava stats error: ' . $e->getMessage(), ['exception' => $e]);

        return ['Application error: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR];
    }
}
