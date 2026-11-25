<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\SportType;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** @return iterable<string, array{string, SportType|null}> */
    public static function stravaSportTypes(): iterable
    {
        yield 'run' => ['Run', SportType::Run];
        yield 'trail run' => ['TrailRun', SportType::Run];
        yield 'virtual run' => ['VirtualRun', SportType::Run];
        yield 'ride' => ['Ride', SportType::Bike];
        yield 'gravel ride' => ['GravelRide', SportType::Bike];
        yield 'mountain bike ride' => ['MountainBikeRide', SportType::Bike];
        yield 'virtual ride' => ['VirtualRide', SportType::Bike];
        yield 'swim' => ['Swim', SportType::Swim];
        yield 'tennis' => ['Tennis', SportType::Tennis];
        yield 'e-bike ride' => ['EBikeRide', null];
        yield 'e-mountain bike ride' => ['EMountainBikeRide', null];
        yield 'walk' => ['Walk', null];
    }

    #[DataProvider('stravaSportTypes')]
    public function testFromStrava(string $stravaSportType, SportType|null $expected): void
    {
        self::assertSame($expected, SportType::fromStrava($stravaSportType));
    }

    public function testIcon(): void
    {
        self::assertSame('person-running', SportType::Run->icon());
        self::assertSame('table-tennis-paddle-ball', SportType::Tennis->icon());
    }
}
