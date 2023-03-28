<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\DailyScripture;
use DateTime;
use PHPUnit\Framework\TestCase;

class DailyScriptureTest extends TestCase
{
    public function testCanGetAndSetData(): void
    {
        $dailyScripture = new DailyScripture(
            new DateTime('2023-01-01'),
            'Scripture',
            'Scripture Content',
        );

        self::assertSame('Scripture', $dailyScripture->getScripture());
        self::assertSame('Scripture Content', $dailyScripture->getBody());
        self::assertSame('01-01-2023', $dailyScripture->getDate());
    }
}
