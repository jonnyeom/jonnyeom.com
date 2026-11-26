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
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Throwable;

use function count;
use function sprintf;

#[AsLiveComponent]
class WeeklyStatsTable extends AbstractController
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    private const int INITIAL_VISIBLE_WEEKS = 20;
    private const int LOAD_MORE_SIZE        = 10;

    /** Browser event after Refresh / Reset: the chart (StravaChart, outside this component) reloads. */
    public const string REFRESHED_EVENT = 'strava:refreshed';

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
            $this->dispatchBrowserEvent(self::REFRESHED_EVENT);
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
            $this->dispatchBrowserEvent(self::REFRESHED_EVENT);
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
