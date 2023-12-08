<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Exception\InvalidIdentifierException;
use ApiPlatform\Metadata\Exception\ItemNotFoundException;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\DailyScripture;
use App\Service\DailyScriptureLoader;
use DateTime;
use DateTimeZone;

use function array_key_exists;
use function is_string;
use function sprintf;
use function strlen;
use function trim;

class DailyScriptureProvider implements ProviderInterface
{
    public function __construct(private readonly DailyScriptureLoader $dsLoader)
    {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * {@inheritDoc}
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): DailyScripture
    {
        $dateParam = $uriVariables['date'] ?? null;

        if (! is_string($dateParam) || trim($dateParam) === '') {
            throw new InvalidIdentifierException('The identifier is either an empty string or null. Pass a date in the format "m-d-Y" or "n-j-Y".');
        }

        if (strlen($dateParam) > 10) {
            throw new InvalidIdentifierException('Invalid Date format. Pass a date in the format "m-d-Y" or "n-j-Y".');
        }

        $dateObj = $this->getDateTime($dateParam);

        if (! $dateObj) {
            throw new InvalidIdentifierException('Invalid Date format. Pass a date in the format "m-d-Y" or "n-j-Y".');
        }

        $content = $this->dsLoader->getAllScriptures();

        $date = $dateObj->format('n/j/Y');
        $year = $dateObj->format('Y');

        if (! array_key_exists($year, $content) || ! array_key_exists($date, $content[$year])) {
            throw new ItemNotFoundException(sprintf('Unable to find Daily Scripture for the date %s', $date));
        }

        return new DailyScripture($dateObj, $content[$year][$date]['scripture'], $content[$year][$date]['body']);
        // Retrieve the state from somewhere
    }

    private function getDateTime(string $dateParam): false|DateTime
    {
        if ($dateParam === 'today') {
            return new DateTime(timezone: new DateTimeZone('America/New_York'));
        }

        if (strlen($dateParam) < 10) {
            return DateTime::createFromFormat('n-j-Y', $dateParam);
        }

        return DateTime::createFromFormat('m-d-Y', $dateParam);
    }
}
