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

    private const int INITIAL_VISIBLE_WEEKS = 20;
    private const int LOAD_MORE_SIZE        = 10;

    #[LiveProp(writable: true)]
    public int $visibleWeeks = self::INITIAL_VISIBLE_WEEKS;

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
        $this->visibleWeeks += self::LOAD_MORE_SIZE;
    }

    /** @return array<int, WeeklyStat> */
    public function getWeeklyStats(): array
    {
        $this->load();

        return $this->weeklyStatsCache ?? [];
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
            $this->weeklyStatsCache = $this->stravaDataProvider->getWeeklyStatsPaged($this->visibleWeeks);
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
