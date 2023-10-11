<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Contracts\Cache\ItemInterface;

use function file_get_contents;
use function json_decode;

use const JSON_THROW_ON_ERROR;

class DailyScriptureLoader
{
    /** @return array<mixed> */
    public function getAllScriptures(): array
    {
        $cache = new FilesystemAdapter();
        $cid   = 'daily_scriptures';

        $content = $cache->get($cid, static function (ItemInterface $item) {
            return file_get_contents(__DIR__ . '/../ApiContent/daily-scriptures.json');
        });

        return json_decode((string) $content, true, 512, JSON_THROW_ON_ERROR);
    }
}
