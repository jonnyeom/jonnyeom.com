<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Cache\Adapter\AdapterInterface;

use function file_get_contents;
use function json_decode;

use const JSON_THROW_ON_ERROR;

class DailyScriptureLoader
{
    public function __construct(private AdapterInterface $cache)
    {
    }

    /** @return array<mixed> */
    public function getAllScriptures(): array
    {
        $cid = 'daily_scriptures';

        $item = $this->cache->getItem($cid);
        if (! $item->isHit()) {
            $content = file_get_contents(__DIR__ . '/../ApiContent/daily-scriptures.json');

            $item->set($content);
            $this->cache->save($item);
        }

        $content = $item->get();

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }
}
