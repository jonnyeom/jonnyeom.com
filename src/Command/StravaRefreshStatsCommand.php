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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function filter_var;
use function round;
use function sprintf;

use const FILTER_VALIDATE_INT;

/**
 * Does what the Refresh button on /strava does, without a browser: fetches the activities from
 * Strava, recalculates the weekly stats and stores them.
 */
#[AsCommand(
    name: 'app:strava:refresh-stats',
    description: 'Fetches activities from Strava and refreshes the stored weekly stats of the allowed athlete.',
)]
final class StravaRefreshStatsCommand extends Command
{
    /** Same as the number of weeks the stats page shows initially. */
    private const int DEFAULT_WEEKS = 20;

    public function __construct(
        private readonly StravaDataProvider $stravaDataProvider,
        private readonly AthleteGuard $athleteGuard,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'weeks',
            null,
            InputOption::VALUE_REQUIRED,
            'Number of most recent weeks to refresh (4 extra weeks are fetched for the 5-week average).',
            (string) self::DEFAULT_WEEKS,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $athleteId = $this->athleteGuard->getAllowedAthleteId();

        if ($athleteId === '') {
            $io->error('STRAVA_ATHLETE_ID is not set.');

            return Command::FAILURE;
        }

        $weeks = filter_var($input->getOption('weeks'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($weeks === false) {
            $io->error('--weeks must be a positive number.');

            return Command::INVALID;
        }

        try {
            $weeklyStats = $this->stravaDataProvider->refreshStoredStats($weeks);
        } catch (AccessTokenMissing) {
            $io->error(sprintf('No Strava token is stored for athlete %s. Connect to Strava at /strava first.', $athleteId));

            return Command::FAILURE;
        } catch (IdentityProviderException $e) {
            $io->error(sprintf('Strava rejected the token refresh: %s. Reconnect to Strava at /strava.', $e->getMessage()));

            return Command::FAILURE;
        }

        if ($weeklyStats === []) {
            $io->success('Strava returned no activities for this period; nothing to store.');

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            'Refreshed %d weeks of Strava stats for athlete %s. This week: %s mi (5-week average %s mi).',
            count($weeklyStats),
            $athleteId,
            $weeklyStats[0]->getTotalMiles(),
            round($weeklyStats[0]->getFiveWeekAverage() / 1609.34, 1),
        ));

        return Command::SUCCESS;
    }
}
