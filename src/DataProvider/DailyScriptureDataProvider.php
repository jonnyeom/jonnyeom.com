<?php

declare(strict_types=1);

namespace App\DataProvider;

use ApiPlatform\Core\DataProvider\ItemDataProviderInterface;
use ApiPlatform\Core\DataProvider\RestrictedDataProviderInterface;
use ApiPlatform\Core\Exception\InvalidIdentifierException;
use ApiPlatform\Core\Exception\ItemNotFoundException;
use App\Entity\DailyScripture;
use App\Service\DailyScriptureLoader;
use DateTime;

use function array_key_exists;
use function is_string;
use function sprintf;
use function strlen;
use function trim;

final class DailyScriptureDataProvider implements ItemDataProviderInterface, RestrictedDataProviderInterface
{
    private DailyScriptureLoader $dsLoader;

    /** Setter Injection for our DS Loader. */
    public function setDsLoader(DailyScriptureLoader $dsLoader): void
    {
        $this->dsLoader = $dsLoader;
    }

    /**
     * {@inheritdoc}
     */
    public function getItem(string $resourceClass, $id, string|null $operationName = null, array $context = [])
    {
        if ($id === null || (is_string($id) && trim($id) === '')) {
            throw new InvalidIdentifierException('The identifier is either an empty string or null. Pass a date in the format "m-d-Y" or "n-j-Y".');
        }

        if (strlen($id) > 10) {
            throw new InvalidIdentifierException('Invalid Date format. Pass a date in the format "m-d-Y" or "n-j-Y".');
        }

        if (strlen($id) < 10) {
            $dateObj = DateTime::createFromFormat('n-j-Y', $id);
        } else {
            $dateObj = DateTime::createFromFormat('m-d-Y', $id);
        }

        if (! $dateObj) {
            throw new InvalidIdentifierException('Invalid Date format. Pass a date in the format "m-d-Y" or "n-j-Y".');
        }

        $content = $this->dsLoader->getAllScriptures();

        $date = $dateObj->format('n/j/Y');
        if (! array_key_exists($date, $content['2022'])) {
            throw new ItemNotFoundException(sprintf('Unable to find Daily Scripture for the date %s', $date));
        }

        return new DailyScripture($dateObj, $content['2022'][$date]['scripture'], $content['2022'][$date]['body']);
    }

    /**
     * {@inheritdoc}
     */
    public function supports(string $resourceClass, string|null $operationName = null, array $context = []): bool
    {
        return $resourceClass === DailyScripture::class;
    }
}
