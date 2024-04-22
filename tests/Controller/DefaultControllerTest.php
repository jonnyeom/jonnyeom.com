<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Generator;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

use function sprintf;

final class DefaultControllerTest extends WebTestCase
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
        yield ['/'];
        yield ['/projects'];
        yield ['/writing'];
        yield ['/about'];
        yield ['/strava'];
    }
}
