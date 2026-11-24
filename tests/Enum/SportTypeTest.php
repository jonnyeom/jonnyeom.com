<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\SportType;
use PHPUnit\Framework\TestCase;

final class SportTypeTest extends TestCase
{
    public function testRelativeDistanceWeightsEachSport(): void
    {
        self::assertSame(3000.0, SportType::Run->relativeDistance(3000.0));
        self::assertSame(1000.0, SportType::Bike->relativeDistance(3000.0));
        self::assertSame(12000.0, SportType::Swim->relativeDistance(3000.0));
        self::assertSame(6000.0, SportType::Tennis->relativeDistance(3000.0));
    }

    public function testIcon(): void
    {
        self::assertSame('person-running', SportType::Run->icon());
        self::assertSame('table-tennis-paddle-ball', SportType::Tennis->icon());
    }
}
