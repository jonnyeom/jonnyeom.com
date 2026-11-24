<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\Strava\InvalidPlan;
use App\Service\Strava\AthleteGuard;
use App\Service\Strava\WeeklyPlanCsv;
use App\Service\Strava\WeeklyPlanManager;
use DateTimeImmutable;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function array_key_exists;
use function is_array;
use function is_string;
use function json_decode;
use function max;
use function min;
use function sprintf;
use function strlen;

use const JSON_THROW_ON_ERROR;

/**
 * JSON API for the weekly mileage plans (Plan page).
 *
 * Reading is public, like the stats page. Writing needs the visitor to be connected to Strava as
 * the allowed athlete (session). Writes only accept a JSON (or, for import, text/csv) body, which a
 * cross-site form can't send, and CORS doesn't allow credentials, so the session cookie can't be
 * used cross-site.
 */
#[Route('/api/strava/plans', name: 'strava_plan_api_', format: 'json')]
class StravaPlanApiController extends AbstractController
{
    private const int DEFAULT_WEEKS = 12;
    private const int MAX_WEEKS     = 52;

    /** Export covers the weeks loaded on the Plan page, which "Load more" can extend. */
    private const int MAX_EXPORT_WEEKS = 156;

    private const int MAX_IMPORT_BYTES = 1_000_000;

    /** Weeks before the current one shown by default, to compare the plan with what was done. */
    private const int DEFAULT_PAST_WEEKS = 4;

    public function __construct(
        private readonly WeeklyPlanManager $planManager,
        private readonly AthleteGuard $athleteGuard,
        private readonly WeeklyPlanCsv $planCsv,
    ) {
    }

    /**
     * GET /api/strava/plans.csv?from=YYYY-MM-DD&weeks=N: the plans as a CSV download, to open in
     * a spreadsheet. Same range parameters as the list (up to 156 weeks).
     */
    #[Route('.csv', name: 'export', methods: ['GET'], format: 'csv')]
    public function export(Request $request): Response
    {
        try {
            $from = $this->fromParameter($request);
        } catch (InvalidPlan $e) {
            return $this->error($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        $weeks    = max(1, min(self::MAX_EXPORT_WEEKS, $request->query->getInt('weeks', self::DEFAULT_WEEKS)));
        $response = new Response($this->planCsv->export($this->planManager->getWeeks($from, $weeks)));
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            sprintf('strava-plan-%s.csv', $from->format('Y-m-d')),
        ));

        return $response;
    }

    /**
     * POST /api/strava/plans/import with a text/csv body (the format of the export): every row
     * replaces that week's plan, an empty row clears it, weeks not in the CSV are left as they are.
     * All rows are validated first and saved in one transaction.
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
            if ($plans === []) {
                return $this->error('The CSV has no weeks.', Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return $this->json(['weeks' => $this->planManager->saveMany($plans)]);
        } catch (InvalidPlan $e) {
            return $this->error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /**
     * GET /api/strava/plans?from=YYYY-MM-DD&weeks=N
     *
     * `from` defaults to 4 weeks before the current week and must be a Monday; `weeks` is 1..52.
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        try {
            $from = $this->fromParameter($request);
        } catch (InvalidPlan $e) {
            return $this->error($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        $weeks = max(1, min(self::MAX_WEEKS, $request->query->getInt('weeks', self::DEFAULT_WEEKS)));

        return $this->json([
            'currentWeek' => WeeklyPlanManager::weekStartOf(new DateTimeImmutable())->format('Y-m-d'),
            'editable' => $this->isConnected($request),
            'weeks' => $this->planManager->getWeeks($from, $weeks),
        ]);
    }

    #[Route('/{weekStart}', name: 'show', methods: ['GET'])]
    public function show(string $weekStart): JsonResponse
    {
        try {
            return $this->json($this->planManager->getWeek(WeeklyPlanManager::parseWeekStart($weekStart)));
        } catch (InvalidPlan $e) {
            return $this->error($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }
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

        try {
            $payload = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->error('Invalid JSON body.', Response::HTTP_BAD_REQUEST);
        }

        if (! is_array($payload)) {
            return $this->error('The body must be a JSON object.', Response::HTTP_BAD_REQUEST);
        }

        $days  = $payload['days'] ?? [];
        $notes = $payload['notes'] ?? null;
        if (! is_array($days) || (array_key_exists('notes', $payload) && $notes !== null && ! is_string($notes))) {
            return $this->error('"days" must be an object and "notes" a string.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $week = WeeklyPlanManager::parseWeekStart($weekStart);
        } catch (InvalidPlan $e) {
            return $this->error($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        try {
            return $this->json($this->planManager->save($week, $days, $notes));
        } catch (InvalidPlan $e) {
            return $this->error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    #[Route('/{weekStart}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $weekStart, Request $request): Response
    {
        if (! $this->isConnected($request)) {
            return $this->error('Connect to Strava to edit the plan.', Response::HTTP_FORBIDDEN);
        }

        try {
            $this->planManager->delete(WeeklyPlanManager::parseWeekStart($weekStart));
        } catch (InvalidPlan $e) {
            return $this->error($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
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
