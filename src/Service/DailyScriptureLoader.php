<?php

namespace App\Service;

class DailyScriptureLoader
{
    public function getAllScriptures() {
        // Todo: Add Cache.
        $content = file_get_contents(__DIR__ . '/../ApiContent/daily-scriptures.json');

        return json_decode($content, TRUE);
    }
}
