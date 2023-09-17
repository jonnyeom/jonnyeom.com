<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Core\Exception\InvalidIdentifierException;
use ApiPlatform\Core\Exception\ItemNotFoundException;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\DailyScripture;
use App\Service\DailyScriptureLoader;
use DateTime;

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
    public function provide(Operation $operation, array $uriVariables = [], array $context = [])
    {
        $dateParam = $uriVariables['date'] ?? null;

        if (! is_string($dateParam) || trim($dateParam) === '') {
            throw new InvalidIdentifierException('The identifier is either an empty string or null. Pass a date in the format "m-d-Y" or "n-j-Y".');
        }

        if (strlen($dateParam) > 10) {
            throw new InvalidIdentifierException('Invalid Date format. Pass a date in the format "m-d-Y" or "n-j-Y".');
        }

        if (strlen($dateParam) < 10) {
            $dateObj = DateTime::createFromFormat('n-j-Y', $dateParam);
        } else {
            $dateObj = DateTime::createFromFormat('m-d-Y', $dateParam);
        }

        if (! $dateObj) {
            throw new InvalidIdentifierException('Invalid Date format. Pass a date in the format "m-d-Y" or "n-j-Y".');
        }

        $content = $this->dsLoader->getAllScriptures();

        $date = $dateObj->format('n/j/Y');
        if (! array_key_exists($date, $content['2023'])) {
            throw new ItemNotFoundException(sprintf('Unable to find Daily Scripture for the date %s', $date));
        }

        return new DailyScripture($dateObj, $content['2023'][$date]['scripture'], $content['2023'][$date]['body']);
        // Retrieve the state from somewhere
    }
}
