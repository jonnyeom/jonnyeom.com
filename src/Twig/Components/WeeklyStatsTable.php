<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Exception\Strava\AccessTokenMissing;
use App\Model\Strava\WeeklyStat;
use App\Service\Strava\StravaDataProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Throwable;

#[AsLiveComponent]
class WeeklyStatsTable extends AbstractController
{
    use DefaultActionTrait;

    private const int INITIAL_LIMIT  = 20;
    private const int LOAD_MORE_SIZE = 10;

    #[LiveProp(writable: true)]
    public int $limit = self::INITIAL_LIMIT;

    private string|null $error = null;

    /** @var array<int, WeeklyStat>|null */
    private array|null $weeklyStatsCache = null;

    private bool $loaded = false;

    public function __construct(private readonly StravaDataProvider $stravaDataProvider)
    {
    }

    #[LiveAction]
    public function loadMore(): void
    {
        $this->limit += self::LOAD_MORE_SIZE;
    }

    /** @return array<int, WeeklyStat> */
    public function getWeeklyStats(): array
    {
        $this->load();

        return $this->weeklyStatsCache ?? [];
    }

    public function hasMore(): bool
    {
        try {
            return $this->limit < $this->stravaDataProvider->getTotalWeekCount();
        } catch (Throwable) {
            return false;
        }
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
            $this->weeklyStatsCache = $this->stravaDataProvider->getWeeklyStatsPaged($this->limit);
        } catch (AccessTokenMissing) {
            $this->error            = 'No access token. Please connect to Strava.';
            $this->weeklyStatsCache = [];
        } catch (IdentityProviderException $e) {
            $this->error            = 'Strava access error: ' . $e->getMessage();
            $this->weeklyStatsCache = [];
        } catch (Throwable $e) {
            $this->error            = 'Application error: ' . $e->getMessage();
            $this->weeklyStatsCache = [];
        }
    }
}
