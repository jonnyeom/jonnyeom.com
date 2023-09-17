<?php

declare(strict_types=1);

namespace App\Tests\API;

use Generator;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

use function sprintf;

final class DailyScriptureAPITest extends WebTestCase
{
    /** @dataProvider getPublicUrls */
    public function testPublicUrls(string $url): void
    {
        $client = static::createClient();
        $client->request('GET', $url);

        $this->assertResponseIsSuccessful(sprintf('The %s public URL loads correctly.', $url));
    }

    public function getPublicUrls(): Generator
    {
        yield ['/api/daily_scriptures/today'];
        yield ['/api/daily_scriptures/01-01-2023'];
        yield ['/api/daily_scriptures/11-30-2023'];
    }
}
