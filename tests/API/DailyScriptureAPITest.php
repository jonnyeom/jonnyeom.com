<?php

declare(strict_types=1);

namespace App\Tests\API;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

use function sprintf;

final class DailyScriptureAPITest extends WebTestCase
{
    #[DataProvider('getPublicUrls')]
    public function testPublicUrls(string $url): void
    {
        $client = self::createClient();
        $client->request('GET', $url);

        $this->assertResponseIsSuccessful(sprintf('The %s public URL loads correctly.', $url));
    }

    public function testADateWithoutAScriptureIsNotFound(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/daily_scriptures/01-01-1990');

        self::assertResponseStatusCodeSame(404);
    }

    /** @return array<array{string}> */
    public static function getPublicUrls(): array
    {
        return [
            ['/api/daily_scriptures/01-01-2023'],
            ['/api/daily_scriptures/11-30-2023'],
        ];
    }
}
