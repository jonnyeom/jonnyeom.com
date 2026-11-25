<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\Strava\InvalidPlan;
use App\Service\Strava\AthleteGuard;
use App\Service\Strava\WeeklyPlanCsv;
use App\Service\Strava\WeeklyPlanManager;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function is_array;
use function is_string;
use function max;
use function min;
use function sprintf;
use function strlen;

/**
 * JSON API for the weekly mileage plans (Plan page).
 *
 * Reading is public, like the stats page. Writing needs the session to be connected to Strava as the
 * allowed athlete; the session cookie is SameSite=Lax, and writes only accept JSON / CSV bodies.
 */
#[Route('/api/strava/plans', name: 'strava_plan_api_', format: 'json')]
class StravaPlanApiController extends AbstractController
{
    private const int DEFAULT_WEEKS = 12;

    /** Covers the weeks "Load more" can add to the Plan page. */
    private const int MAX_WEEKS = 156;

    /** Weeks before the current one shown by default, to compare the plan with what was done. */
    private const int DEFAULT_PAST_WEEKS = 4;

    private const int MAX_IMPORT_BYTES = 1_000_000;

    public function __construct(
        private readonly WeeklyPlanManager $planManager,
        private readonly AthleteGuard $athleteGuard,
        private readonly WeeklyPlanCsv $planCsv,
    ) {
    }

    /**
     * GET /api/strava/plans?from=YYYY-MM-DD&weeks=N
     *
     * `from` defaults to 4 weeks before the current week and must be a Monday.
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        try {
            $from = $this->fromParameter($request);
        } catch (InvalidPlan $e) {
            return $this->error($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        return $this->json([
            'currentWeek' => WeeklyPlanManager::weekStartOf(new DateTimeImmutable())->format('Y-m-d'),
            'editable' => $this->isConnected($request),
            'weeks' => $this->planManager->getWeeks($from, $this->weeksParameter($request)),
        ]);
    }

    /** GET /api/strava/plans.csv?from=YYYY-MM-DD&weeks=N: the plans as a CSV download, to open in a spreadsheet. */
    #[Route('.csv', name: 'export', methods: ['GET'], format: 'csv')]
    public function export(Request $request): Response
    {
        try {
            $from = $this->fromParameter($request);
        } catch (InvalidPlan $e) {
            return $this->error($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        $response = new Response($this->planCsv->export($this->planManager->getWeeks($from, $this->weeksParameter($request))));
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            sprintf('strava-plan-%s.csv', $from->format('Y-m-d')),
        ));

        return $response;
    }

    /**
     * PUT /api/strava/plans/{weekStart}: creates or replaces the plan of the week.
     *
     * Body: {"days": {"Mon": {"Run": 5, "Bike": 20}, "Wed": {"Swim": 1}}, "notes": "Easy week"}
     * Distances are miles of the sport itself; omitted days and sports are 0. An empty plan
     * removes the week's plan.
     */
    #[Route('/{weekStart}', name: 'update', methods: ['PUT'])]
    public function update(string $weekStart, Request $request): JsonResponse
    {
        if (! $this->isConnected($request)) {
            return $this->error('Connect to Strava to edit the plan.', Response::HTTP_FORBIDDEN);
        }

        if ($request->getContentTypeFormat() !== 'json') {
            return $this->error('Send the plan as application/json.', Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $payload = $request->toArray(); // Invalid JSON is a 400.
        $days    = $payload['days'] ?? [];
        $notes   = $payload['notes'] ?? null;
        if (! is_array($days) || ($notes !== null && ! is_string($notes))) {
            return $this->error('"days" must be an object and "notes" a string.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            return $this->json($this->planManager->save(WeeklyPlanManager::parseWeekStart($weekStart), $days, $notes));
        } catch (InvalidPlan $e) {
            return $this->error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /**
     * POST /api/strava/plans/import with a text/csv body (the format of the export): every row
     * replaces that week's plan, an empty row clears it, weeks not in the CSV are left as they are.
     * All rows are validated before anything is saved.
     */
    #[Route('/import', name: 'import', methods: ['POST'])]
    public function import(Request $request): JsonResponse
    {
        if (! $this->isConnected($request)) {
            return $this->error('Connect to Strava to edit the plan.', Response::HTTP_FORBIDDEN);
        }

        if ($request->getContentTypeFormat() !== 'csv') {
            return $this->error('Send the plan as text/csv.', Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $csv = $request->getContent();
        if (strlen($csv) > self::MAX_IMPORT_BYTES) {
            return $this->error('The CSV is too large.', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        try {
            $plans = $this->planCsv->parse($csv);
        } catch (InvalidPlan $e) {
            return $this->error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($plans === []) {
            return $this->error('The CSV has no weeks.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['imported' => $this->planManager->saveMany($plans)]);
    }

    /**
     * The `from` query parameter (a Monday), by default 4 weeks before the current week.
     *
     * @throws InvalidPlan
     */
    private function fromParameter(Request $request): DateTimeImmutable
    {
        if ($request->query->has('from')) {
            return WeeklyPlanManager::parseWeekStart($request->query->getString('from'));
        }

        return WeeklyPlanManager::weekStartOf(new DateTimeImmutable())->modify('-' . self::DEFAULT_PAST_WEEKS . ' weeks');
    }

    private function weeksParameter(Request $request): int
    {
        return max(1, min(self::MAX_WEEKS, $request->query->getInt('weeks', self::DEFAULT_WEEKS)));
    }

    private function isConnected(Request $request): bool
    {
        // Without a session cookie the visitor can't be connected; don't start a session for them.
        return $request->hasPreviousSession() && $this->athleteGuard->isConnected($request->getSession());
    }

    private function error(string $message, int $status): JsonResponse
    {
        return $this->json(['error' => $message], $status);
    }
}
