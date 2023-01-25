<?php

namespace App\Service;

use Symfony\Component\Cache\Adapter\AdapterInterface;

class DailyScriptureLoader
{
    /**
     * @var AdapterInterface
     */
    private AdapterInterface $cache;

    public function __construct(AdapterInterface $cache)
    {
        $this->cache = $cache;
    }

    public function getAllScriptures()
    {
        $cid = 'daily_scriptures';

        $item = $this->cache->getItem($cid);
        if (!$item->isHit()) {
            $content = file_get_contents(__DIR__.'/../ApiContent/daily-scriptures.json');

            $item->set($content);
            $this->cache->save($item);
        }

        $content = $item->get();

        return json_decode($content, true);
    }
}
