<?php

namespace App\DataProvider;

use ApiPlatform\Core\DataProvider\ItemDataProviderInterface;
use ApiPlatform\Core\DataProvider\RestrictedDataProviderInterface;
use ApiPlatform\Core\Exception\InvalidIdentifierException;
use ApiPlatform\Core\Exception\ItemNotFoundException;
use App\Entity\DailyScripture;

final class DailyScriptureDataProvider implements ItemDataProviderInterface, RestrictedDataProviderInterface
{
    /**
     * {@inheritdoc}
     */
    public function getItem(string $resourceClass, $id, string $operationName = null, array $context = [])
    {
        if (null === $id || (\is_string($id) && '' === trim($id))) {
            throw new InvalidIdentifierException('The identifier is either an empty string or null. Pass a date in the format "m-d-Y" or "n-j-Y".');
        }

        if (strlen($id) > 10) {
            throw new InvalidIdentifierException('Invalid Date format. Pass a date in the format "m-d-Y" or "n-j-Y".');
        }

        if (strlen($id) < 10) {
            $dateObj = \DateTime::createFromFormat('n-j-Y', $id);
        } else {
            $dateObj = \DateTime::createFromFormat('m-d-Y', $id);
        }

        if (!$dateObj) {
            throw new InvalidIdentifierException('Invalid Date format. Pass a date in the format "m-d-Y" or "n-j-Y".');
        }

        $date = $dateObj->format('n/j/Y');

        // Todo: Add Cache.
        $content = file_get_contents(__DIR__ . '/../ApiContent/daily-scriptures.json');
        $content = json_decode($content, TRUE);

        if (!array_key_exists($date, $content['2022'])) {
            throw new ItemNotFoundException(sprintf('Unable to find Daily Scripture for the date %s', $date));
        }

        return new DailyScripture($dateObj, $content['2022'][$date]['body'], $content['2022'][$date]['scripture']);
    }

    public function supports(string $resourceClass, string $operationName = null, array $context = []): bool
    {
        return DailyScripture::class === $resourceClass;
    }
}
