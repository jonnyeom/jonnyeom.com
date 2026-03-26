<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

use function sprintf;

final class DefaultControllerTest extends WebTestCase
{
    #[DataProvider('getPublicUrls')]
    public function testPublicUrls(string $url): void
    {
        $client = static::createClient();
        $client->request('GET', $url);

        $this->assertResponseIsSuccessful(sprintf('The %s public URL loads correctly.', $url));
    }

    /** @return array<array{string}> */
    public static function getPublicUrls(): array
    {
        return [
            ['/'],
            ['/projects'],
            ['/writing'],
            ['/about'],
            ['/experiments'],
            ['/medical'],
            ['/strava'],
        ];
    }
}
