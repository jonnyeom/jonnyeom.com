<?php

declare(strict_types=1);

namespace App\Command;

use App\Exception\Strava\AccessTokenMissing;
use App\Service\Strava\AthleteGuard;
use App\Service\Strava\StravaTokenManager;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function date;
use function sprintf;

#[AsCommand(
    name: 'app:strava:refresh-token',
    description: 'Refreshes the stored Strava token of the allowed athlete (STRAVA_ATHLETE_ID).',
)]
final class StravaRefreshTokenCommand extends Command
{
    public function __construct(
        private readonly StravaTokenManager $tokenManager,
        private readonly AthleteGuard $athleteGuard,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'if-expired',
            null,
            InputOption::VALUE_NONE,
            'Only refresh when the access token has expired (useful on a schedule).',
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

        try {
            $token = $input->getOption('if-expired')
                ? $this->tokenManager->getValidToken($athleteId)
                : $this->tokenManager->refresh($athleteId);
        } catch (AccessTokenMissing) {
            $io->error(sprintf('No Strava token is stored for athlete %s. Connect to Strava at /strava first.', $athleteId));

            return Command::FAILURE;
        } catch (IdentityProviderException $e) {
            $io->error(sprintf('Strava rejected the refresh: %s. Reconnect to Strava at /strava.', $e->getMessage()));

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'The Strava token for athlete %s is valid until %s.',
            $athleteId,
            date('Y-m-d H:i:s T', (int) $token->getExpires()),
        ));

        return Command::SUCCESS;
    }
}
