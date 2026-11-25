<?php

declare(strict_types=1);

namespace App\Command;

use App\Exception\Strava\AccessTokenMissing;
use App\Service\Strava\AthleteGuard;
use App\Service\Strava\StravaDataProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function round;
use function sprintf;

/**
 * Does what the Refresh button on /strava does, without a browser: fetches the latest week(s)
 * from Strava, recalculates their stats and stores them.
 */
#[AsCommand(
    name: 'app:strava:refresh-stats',
    description: 'Refreshes the latest week of Strava stats for the allowed athlete.',
)]
final class StravaRefreshStatsCommand extends Command
{
    public function __construct(
        private readonly StravaDataProvider $stravaDataProvider,
        private readonly AthleteGuard $athleteGuard,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $athleteId = $this->athleteGuard->getAllowedAthleteId();

        if ($athleteId === null) {
            $io->error('STRAVA_ATHLETE_ID is not set to an athlete ID.');

            return Command::FAILURE;
        }

        try {
            $weeklyStats = $this->stravaDataProvider->refreshLatestStats();
        } catch (AccessTokenMissing) {
            $io->error(sprintf('No Strava token is stored for athlete %d. Connect to Strava at /strava first.', $athleteId));

            return Command::FAILURE;
        } catch (IdentityProviderException $e) {
            $io->error(sprintf('Strava rejected the token refresh: %s. Reconnect to Strava at /strava.', $e->getMessage()));

            return Command::FAILURE;
        }

        if ($weeklyStats === []) {
            $io->success('Strava returned no activities; nothing to store.');

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            'Refreshed %d week(s) of Strava stats for athlete %d. This week: %s mi (5-week average %s mi).',
            count($weeklyStats),
            $athleteId,
            $weeklyStats[0]->getTotalMiles(),
            round($weeklyStats[0]->getFiveWeekAverage() / 1609.34, 1),
        ));

        return Command::SUCCESS;
    }
}
