<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Exception\Strava\AccessTokenMissing;
use App\Model\Strava\WeeklyStat;
use App\Service\Strava\StravaDataProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Throwable;

use function array_map;
use function array_reverse;
use function array_slice;
use function count;
use function round;
use function sprintf;

#[AsLiveComponent]
class WeeklyStatsTable extends AbstractController
{
    use DefaultActionTrait;

    private const int INITIAL_VISIBLE_WEEKS = 20;
    private const int LOAD_MORE_SIZE        = 10;
    private const int CHART_WEEKS           = 10;

    private const float METERS_PER_MILE = 1609.34;

    // Validated for contrast and colorblind separation on both the light and dark page backgrounds.
    private const string COLOR_MILES   = '#3987e5';
    private const string COLOR_AVERAGE = '#d95926';
    private const string COLOR_MUTED   = '#898781';
    private const string COLOR_GRID    = 'rgba(137, 135, 129, 0.25)';

    #[LiveProp(writable: true)]
    public int $visibleWeeks = self::INITIAL_VISIBLE_WEEKS;

    private string|null $error = null;

    /** @var array<int, WeeklyStat>|null */
    private array|null $weeklyStatsCache = null;

    private bool $loaded = false;

    /** Confirmation shown after a successful refresh or reset. */
    private string|null $notice = null;

    public function __construct(
        private readonly StravaDataProvider $stravaDataProvider,
        private readonly LoggerInterface $logger,
        private readonly ChartBuilderInterface $chartBuilder,
    ) {
    }

    #[LiveAction]
    public function loadMore(): void
    {
        $this->visibleWeeks += self::LOAD_MORE_SIZE;
    }

    /**
     * Fetches the latest week(s) from Strava and stores them. The re-render then reads the
     * stats from the database.
     */
    #[LiveAction]
    public function refresh(): RedirectResponse|null
    {
        try {
            $this->stravaDataProvider->refresh();
            $this->notice = 'Updated the latest week with fresh Strava data';
        } catch (Throwable $e) {
            return $this->handleStravaActionError($e);
        }

        return null;
    }

    /**
     * Deletes the stored weeks and reloads the whole history from Strava.
     */
    #[LiveAction]
    public function reset(): RedirectResponse|null
    {
        try {
            $weeks        = count($this->stravaDataProvider->reset());
            $this->notice = sprintf('Reloaded all %d weeks from Strava', $weeks);
        } catch (Throwable $e) {
            return $this->handleStravaActionError($e);
        }

        return null;
    }

    public function getNotice(): string|null
    {
        $this->load();

        return $this->error === null ? $this->notice : null;
    }

    /** @return array<int, WeeklyStat> */
    public function getWeeklyStats(): array
    {
        $this->load();

        return $this->weeklyStatsCache ?? [];
    }

    /**
     * Weekly miles (columns) and the 5-week average (line) for the latest CHART_WEEKS weeks,
     * oldest first. Rendered with Symfony UX Chart.js; re-rendered with the component.
     */
    public function getChart(): Chart|null
    {
        $weeks = array_reverse(array_slice($this->getWeeklyStats(), 0, self::CHART_WEEKS));
        if ($weeks === []) {
            return null;
        }

        $chart = $this->chartBuilder->createChart(Chart::TYPE_BAR);
        $chart->setData([
            'labels' => array_map(static fn (WeeklyStat $week): string => $week->getDate(), $weeks),
            'datasets' => [
                [
                    'type' => 'line',
                    'label' => '5-week average',
                    'data' => array_map(
                        static fn (WeeklyStat $week): float => round($week->getFiveWeekAverage() / self::METERS_PER_MILE, 1),
                        $weeks,
                    ),
                    'borderColor' => self::COLOR_AVERAGE,
                    'backgroundColor' => self::COLOR_AVERAGE,
                    'borderWidth' => 2,
                    'pointRadius' => 4,
                    'pointHoverRadius' => 5,
                    'tension' => 0,
                    'order' => 1,
                ],
                [
                    'label' => 'Weekly miles',
                    'data' => array_map(static fn (WeeklyStat $week): float => $week->getTotalMiles(), $weeks),
                    'backgroundColor' => self::COLOR_MILES,
                    'borderRadius' => ['topLeft' => 4, 'topRight' => 4],
                    'borderSkipped' => 'start',
                    'maxBarThickness' => 24,
                    'order' => 2,
                ],
            ],
        ]);
        $chart->setOptions([
            'responsive' => true,
            'maintainAspectRatio' => false,
            // One tooltip listing both series for the hovered week.
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'plugins' => [
                'legend' => [
                    'position' => 'top',
                    'align' => 'end',
                    'labels' => ['color' => self::COLOR_MUTED, 'boxWidth' => 12, 'boxHeight' => 12],
                ],
            ],
            'scales' => [
                'x' => [
                    'grid' => ['display' => false],
                    'ticks' => ['color' => self::COLOR_MUTED],
                ],
                'y' => [
                    'beginAtZero' => true,
                    'grid' => ['color' => self::COLOR_GRID],
                    'border' => ['display' => false],
                    'ticks' => ['color' => self::COLOR_MUTED, 'maxTicksLimit' => 5],
                    'title' => ['display' => true, 'text' => 'miles', 'color' => self::COLOR_MUTED],
                ],
            ],
        ]);

        return $chart;
    }

    /**
     * True when fewer weeks are stored than requested (e.g. after "Load more" past the stored history).
     */
    public function hasMissingWeeks(): bool
    {
        return $this->error === null && count($this->getWeeklyStats()) < $this->visibleWeeks;
    }

    public function getError(): string|null
    {
        $this->load();

        return $this->error;
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        try {
            // Read from the database only; Strava is called on refresh().
            $this->weeklyStatsCache = $this->stravaDataProvider->getStoredWeeklyStats($this->visibleWeeks);
        } catch (Throwable $e) {
            $this->handleError($e);
        }
    }

    /**
     * Refresh / Reset could not reach Strava (not connected, no stored token, or the token was
     * rejected): send the visitor to the connect page. Other errors are shown on the page.
     */
    private function handleStravaActionError(Throwable $e): RedirectResponse|null
    {
        if ($e instanceof AccessTokenMissing) {
            $this->addFlash('error', 'Connect to Strava to refresh the stats.');

            return $this->redirectToRoute('strava_connect_entry');
        }

        if ($e instanceof IdentityProviderException) {
            $this->addFlash('error', 'Strava access error: ' . $e->getMessage() . '. Please reconnect to Strava.');

            return $this->redirectToRoute('strava_connect_entry');
        }

        $this->handleError($e);

        return null;
    }

    private function handleError(Throwable $e): void
    {
        // Not connected is expected; token refresh failures are logged by StravaTokenManager.
        if (! $e instanceof AccessTokenMissing && ! $e instanceof IdentityProviderException) {
            $this->logger->error('Strava stats error: ' . $e->getMessage(), ['exception' => $e]);
        }

        $this->error = match (true) {
            $e instanceof AccessTokenMissing => 'No access token. Please connect to Strava.',
            $e instanceof IdentityProviderException => 'Strava access error: ' . $e->getMessage(),
            default => 'Application error: ' . $e->getMessage(),
        };

        $this->weeklyStatsCache = [];
        $this->loaded           = true;
    }
}
